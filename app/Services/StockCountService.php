<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MainStock;
use App\Models\ProductImei;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Physical stock count ar reconciliation (ek branch):
 *  1. create: variant gulor sheet (category dile shudhu oi category)
 *  2. record: gona qty, IMEI wala hole scan kora IMEI
 *  3. submit: system er hishab snapshot - IMEI chara: branch er ledger; IMEI: branch e dokhole thaka phone
 *     (available/reserved/damaged; repair e thaka phone dokane nei tai bad). Variance = gona - system;
 *     IMEI hole missing (stock e ache, paoa jay ni) ar unexpected (paoa geche, ei branch er stock e nei).
 *  4. approve: variance stock ledger e (stock count line), missing phone "lost" hisebe write off.
 *     Unexpected IMEI nije kichu kore na - registration/transfer/purchase diye thik korte hoy.
 * Snapshot er por bikri holeo variance thik thake (gona - tokhoner hishab), tai approve e oi variance e bose.
 */
class StockCountService
{
    // gona jay emon phone (repair e thaka phone dokane nei)
    const COUNTABLE = [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_RESERVED, ProductImei::STATUS_DAMAGED];

    public function __construct(private StockService $stock, private ImeiService $imeis) {}

    public function create(int $branchId, ?int $categoryId, ?string $note, User $user): StockCount
    {
        return DB::transaction(function () use ($branchId, $categoryId, $note, $user) {
            $branch = Branch::where('status', 1)->findOrFail($branchId);
            $last = StockCount::lockForUpdate()->latest('id')->value('id') ?? 0;

            $count = StockCount::create([
                'count_no'    => 'CNT-' . str_pad($last + 1, 5, '0', STR_PAD_LEFT),
                'branch_id'   => $branch->id,
                'category_id' => $categoryId,
                'status'      => StockCount::STATUS_COUNTING,
                'note'        => $note,
                'created_by'  => $user->id,
            ]);

            $stocks = MainStock::with('product')
                ->whereHas('product', fn ($q) => $q->when($categoryId, fn ($w) => $w->where('category_id', $categoryId)))
                ->withCount(['imeis as owned_count' => fn ($q) => $q->owned()])
                ->orderBy('product_id')->orderBy('id')->get();
            if ($stocks->isEmpty()) {
                throw new \Exception('No products found to count.');
            }

            foreach ($stocks as $stock) {
                StockCountItem::create([
                    'stock_count_id' => $count->id,
                    'main_stock_id'  => $stock->id,
                    'uses_imei'      => $stock->product->requiresImei() || $stock->owned_count > 0,
                ]);
            }

            AuditLogger::log('stock_count.created', $count, null, $count->only(['count_no', 'branch_id', 'category_id']) + ['items' => $stocks->count()]);

            return $count;
        });
    }

    /**
     * $counts: [item_id => ['qty' => int|null, 'imeis' => text|null]]; khali rakha line "gona hoy ni" (submit e bad).
     */
    public function record(StockCount $count, array $counts): StockCount
    {
        return DB::transaction(function () use ($count, $counts) {
            $count = StockCount::lockForUpdate()->findOrFail($count->id);
            if ($count->status !== StockCount::STATUS_COUNTING) {
                throw new \Exception("Count {$count->count_no} is {$count->statusLabel()} and cannot be changed.");
            }

            foreach ($count->items()->with('mainStock.product')->get() as $item) {
                $input = $counts[$item->id] ?? null;
                if ($input === null) {
                    continue;
                }

                if ($item->uses_imei) {
                    $text = trim((string) ($input['imeis'] ?? ''));
                    $numbers = array_values(array_unique(ImeiService::numbers(ProductImei::parseDevices($text))));
                    $invalid = array_filter($numbers, fn ($n) => !ProductImei::isValidFormat($n));
                    if ($invalid) {
                        throw new \Exception("{$item->mainStock->variantLabel()}: invalid IMEI " . implode(', ', $invalid));
                    }
                    $item->update([
                        'scanned_imeis' => $text === '' && !($input['counted'] ?? false) ? null : $numbers,
                        'counted_qty'   => $text === '' && !($input['counted'] ?? false) ? null : count($numbers),
                        'note'          => $input['note'] ?? $item->note,
                    ]);
                } else {
                    $qty = $input['qty'] ?? null;
                    if ($qty !== null && $qty !== '' && (!is_numeric($qty) || (int) $qty < 0)) {
                        throw new \Exception("{$item->mainStock->variantLabel()}: counted quantity must be 0 or more.");
                    }
                    $item->update([
                        'counted_qty' => $qty === null || $qty === '' ? null : (int) $qty,
                        'note'        => $input['note'] ?? $item->note,
                    ]);
                }
            }

            return $count;
        });
    }

    public function submit(StockCount $count, User $user): StockCount
    {
        return DB::transaction(function () use ($count, $user) {
            $count = StockCount::lockForUpdate()->findOrFail($count->id);
            if ($count->status !== StockCount::STATUS_COUNTING) {
                throw new \Exception("Count {$count->count_no} is already {$count->statusLabel()}.");
            }

            $items = $count->items()->whereNotNull('counted_qty')->with('mainStock')->get();
            if ($items->isEmpty()) {
                throw new \Exception('Enter the counted quantity for at least one product.');
            }

            $balances = StockService::branchBalances($items->pluck('main_stock_id')->all(), $count->branch_id);
            foreach ($items as $item) {
                if ($item->uses_imei) {
                    $expected = $this->branchDevices($item->main_stock_id, $count->branch_id)->get();
                    $scanned = (array) $item->scanned_imeis;
                    $matched = $expected->filter(fn ($d) => in_array($d->imei_no, $scanned, true) || ($d->imei_no_2 && in_array($d->imei_no_2, $scanned, true)));
                    $known = $matched->flatMap(fn ($d) => [$d->imei_no, $d->imei_no_2])->filter()->all();
                    $missing = $expected->diff($matched)->pluck('imei_no')->values()->all();
                    // ekta phone er IMEI 1 ar 2 duitai scan holeo ekbar
                    $unexpected = array_values(array_diff($scanned, $known));

                    $item->update([
                        'system_qty'       => $expected->count(),
                        'counted_qty'      => $matched->count() + count($unexpected),
                        'variance'         => -count($missing),
                        'missing_imeis'    => $missing,
                        'unexpected_imeis' => $unexpected,
                    ]);
                } else {
                    $system = (int) $balances->get($item->main_stock_id . ':' . $count->branch_id, 0);
                    $item->update(['system_qty' => $system, 'variance' => $item->counted_qty - $system]);
                }
            }

            $count->update(['status' => StockCount::STATUS_SUBMITTED, 'submitted_at' => now(), 'submitted_by' => $user->id]);
            AuditLogger::log('stock_count.submitted', $count, ['status' => StockCount::STATUS_COUNTING], [
                'status'    => StockCount::STATUS_SUBMITTED,
                'variances' => $items->filter(fn ($i) => $i->variance)->mapWithKeys(fn ($i) => [$i->main_stock_id => $i->variance])->all(),
            ]);

            return $count;
        });
    }

    public function approve(StockCount $count, User $reviewer, ?string $note = null): StockCount
    {
        $this->assertCanReview($count, $reviewer);

        return DB::transaction(function () use ($count, $reviewer, $note) {
            $count = StockCount::lockForUpdate()->findOrFail($count->id);
            if ($count->status !== StockCount::STATUS_SUBMITTED) {
                throw new \Exception("Count {$count->count_no} is {$count->statusLabel()}; only a submitted count can be approved.");
            }

            $applied = [];
            foreach ($count->items()->where('variance', '!=', 0)->get() as $item) {
                $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($item->main_stock_id);
                $label = $stock->variantLabel();
                $key = 'count:' . $item->id;

                if ($item->uses_imei) {
                    // count er por bikri/ferot hoye gele ar missing na; reserved phone write off hoy na
                    $devices = $this->branchDevices($stock->id, $count->branch_id)
                        ->whereIn('imei_no', (array) $item->missing_imeis)
                        ->where('status', '!=', ProductImei::STATUS_RESERVED)
                        ->lockForUpdate()->get();
                    foreach ($devices as $device) {
                        $this->imeis->writeOff($device, $count, "Missing in stock count {$count->count_no}");
                    }
                    if ($devices->isNotEmpty()) {
                        $this->stock->move($stock, -$devices->count(), StockService::LOST, $count, "Missing in stock count {$count->count_no}", $count->branch_id, $key);
                        $applied[$stock->id] = -$devices->count();
                    }
                    continue;
                }

                $branchNow = StockService::branchBalance($stock->id, $count->branch_id);
                if ($branchNow + $item->variance < 0 && !StockService::allowsNegative($stock)) {
                    throw new \Exception("{$label}: the branch has {$branchNow} now; applying {$item->variance} would make it negative. Recount this item.");
                }
                $this->stock->move($stock, (int) $item->variance, StockService::STOCK_COUNT, $count, "Stock count {$count->count_no}", $count->branch_id, $key);
                $applied[$stock->id] = (int) $item->variance;
            }

            $count->update([
                'status'      => StockCount::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
            AuditLogger::log('stock_count.approved', $count, ['status' => StockCount::STATUS_SUBMITTED], [
                'status' => StockCount::STATUS_APPROVED, 'applied' => $applied, 'review_note' => $note,
            ]);

            return $count;
        });
    }

    public function cancel(StockCount $count, User $user, string $reason): StockCount
    {
        return DB::transaction(function () use ($count, $user, $reason) {
            $count = StockCount::lockForUpdate()->findOrFail($count->id);
            if (!in_array($count->status, [StockCount::STATUS_COUNTING, StockCount::STATUS_SUBMITTED], true)) {
                throw new \Exception("Count {$count->count_no} is already {$count->statusLabel()}.");
            }

            $from = $count->status;
            $count->update(['status' => StockCount::STATUS_CANCELLED, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => $reason]);
            AuditLogger::log('stock_count.cancelled', $count, ['status' => $from], ['status' => StockCount::STATUS_CANCELLED, 'reason' => $reason]);

            return $count;
        });
    }

    // ei branch e (branch chara purono phone default branch e) gona jay emon phone
    private function branchDevices(int $stockId, int $branchId)
    {
        $isDefault = (int) Branch::where('is_default', 1)->value('id') === $branchId;

        return ProductImei::where('main_stock_id', $stockId)
            ->whereIn('status', self::COUNTABLE)
            ->where(fn ($q) => $q->where('branch_id', $branchId)->when($isDefault, fn ($w) => $w->orWhereNull('branch_id')));
    }

    private function assertCanReview(StockCount $count, User $reviewer): void
    {
        if (!$reviewer->can('approve stock adjustment')) {
            throw new \Exception('You are not allowed to approve stock counts.');
        }
        if (!$reviewer->canAccessBranch($count->branch_id)) {
            throw new \Exception('You can only approve stock counts of your own branch.');
        }
        // nijer submit kora count nije approve shudhu admin (ek-maliker dokan)
        if ((int) $count->submitted_by === (int) $reviewer->id && $reviewer->role !== 'admin') {
            throw new \Exception('You cannot approve a count you submitted yourself.');
        }
    }
}
