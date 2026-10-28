<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MainStock;
use App\Models\ProductImei;
use App\Models\StockTransfer;
use App\Models\StockTransferDevice;
use App\Models\StockTransferEvent;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Branch/warehouse stock transfer.
 *
 *  request -> approve (qty kom kora jay) -> dispatch: source -> "In Transit" (source stock kome, transit e track)
 *    -> receive (ekbar ba bhenge bhenge): transit -> destination; bhanga maal: IMEI phone damaged hoye dhoke,
 *       accessory dhukei damaged hisebe bad (journal) -> shob pouchhale received / discrepancy
 *    -> pouchhay ni: missing (transit theke LOST, journal), status discrepancy
 *
 * - Mot stock (main_stocks.quantity) dispatch/receive e bodlay na (shudhu branch ledger); bhanga/missing e kome.
 * - Ekoi transfer duibar dispatch/receive na: transfer row lock + status, stock movement idempotency key,
 *   receive form er request_token.
 * - Source ba destination branch er access chara dispatch/receive na (User::canAccessBranch).
 * Shob public method nijer DB transaction e.
 */
class StockTransferService
{
    public function __construct(
        private StockService $stock,
        private ImeiService $imeis,
    ) {}

    /**
     * $data: from_branch_id, to_branch_id, items[] (main_stock_id, qty), note?, request_token?
     */
    public function request(array $data, User $user): StockTransfer
    {
        $token = trim((string) ($data['request_token'] ?? '')) ?: null;
        if ($token && ($done = StockTransfer::where('request_token', $token)->first())) {
            return $done;
        }

        return DB::transaction(function () use ($data, $user, $token) {
            [$from, $to] = $this->branches((int) ($data['from_branch_id'] ?? 0), (int) ($data['to_branch_id'] ?? 0));
            if (!$user->canAccessBranch($from->id) && !$user->canAccessBranch($to->id)) {
                throw new \Exception('You can only request transfers for your own branches.');
            }

            $lines = [];
            foreach (array_values((array) ($data['items'] ?? [])) as $row) {
                $qty = (int) ($row['qty'] ?? 0);
                $stockId = (int) ($row['main_stock_id'] ?? 0);
                if (!$stockId && !$qty) {
                    continue;
                }
                $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->findOrFail($stockId);
                if ($qty < 1) {
                    throw new \Exception("{$stock->variantLabel()}: quantity must be at least 1.");
                }
                if (isset($lines[$stock->id])) {
                    throw new \Exception("{$stock->variantLabel()} is added twice.");
                }
                if (!$stock->status || !$stock->product?->isActive()) {
                    throw new \Exception("{$stock->variantLabel()} is inactive.");
                }
                $available = $this->available($stock, $from->id);
                if ($qty > $available) {
                    throw new \Exception("{$stock->variantLabel()}: only {$available} available at {$from->name}.");
                }
                $lines[$stock->id] = ['main_stock_id' => $stock->id, 'product_id' => $stock->product_id, 'requested_qty' => $qty];
            }
            if (!$lines) {
                throw new \Exception('Add at least one product to transfer.');
            }

            $transfer = StockTransfer::create([
                'transfer_no'    => 'TMP-' . Str::random(20),
                'from_branch_id' => $from->id,
                'to_branch_id'   => $to->id,
                'status'         => StockTransfer::REQUESTED,
                'note'           => trim((string) ($data['note'] ?? '')) ?: null,
                'request_token'  => $token,
                'requested_by'   => $user->id,
            ]);
            $transfer->update(['transfer_no' => 'TR-' . str_pad((string) $transfer->id, 6, '0', STR_PAD_LEFT)]);
            foreach ($lines as $line) {
                StockTransferItem::create($line + ['stock_transfer_id' => $transfer->id]);
            }

            $this->event($transfer, 'requested', $user, "{$from->name} -> {$to->name}");
            AuditLogger::log('transfer.requested', $transfer, null, $transfer->only(['transfer_no', 'from_branch_id', 'to_branch_id']) + ['items' => array_values($lines)]);

            return $transfer;
        });
    }

    // Approve: source branch er manager; protiti line er qty kom kora jay (0 = pathabe na)
    public function approve(StockTransfer $transfer, User $user, array $quantities = [], ?string $note = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $quantities, $note) {
            $transfer = $this->lock($transfer, [StockTransfer::REQUESTED]);
            $this->assertAccess($user, $transfer->from_branch_id, 'approve transfers from');

            $total = 0;
            foreach ($transfer->items()->lockForUpdate()->get() as $item) {
                $qty = array_key_exists($item->id, $quantities) && $quantities[$item->id] !== null && $quantities[$item->id] !== ''
                    ? (int) $quantities[$item->id] : $item->requested_qty;
                if ($qty < 0 || $qty > $item->requested_qty) {
                    throw new \Exception('Approved quantity must be between 0 and the requested quantity.');
                }
                $item->update(['approved_qty' => $qty]);
                $total += $qty;
            }
            if ($total < 1) {
                throw new \Exception('Approve at least one unit, or reject the request.');
            }

            $transfer->update(['status' => StockTransfer::APPROVED, 'approved_by' => $user->id, 'approved_at' => now()]);
            $this->event($transfer, 'approved', $user, $note);

            return $transfer;
        });
    }

    public function reject(StockTransfer $transfer, User $user, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $reason) {
            $transfer = $this->lock($transfer, [StockTransfer::REQUESTED]);
            $this->assertAccess($user, $transfer->from_branch_id, 'reject transfers from');

            $transfer->update(['status' => StockTransfer::REJECTED, 'rejection_reason' => $reason, 'approved_by' => $user->id, 'closed_at' => now()]);
            $this->event($transfer, 'rejected', $user, $reason);

            return $transfer;
        });
    }

    // Dispatch er age: je chailo ba je approve korte pare
    public function cancel(StockTransfer $transfer, User $user, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $reason) {
            $transfer = $this->lock($transfer, [StockTransfer::REQUESTED, StockTransfer::APPROVED]);
            if ((int) $transfer->requested_by !== $user->id && !$user->can('approve transfer')) {
                throw new \Exception('Only the requester or an approver can cancel this transfer.');
            }

            $transfer->update(['status' => StockTransfer::CANCELLED, 'rejection_reason' => $reason, 'closed_at' => now()]);
            $this->event($transfer, 'cancelled', $user, $reason);

            return $transfer;
        });
    }

    /**
     * Dispatch: source theke "In Transit". $devices: [item_id => [product_imei_id, ...]] (IMEI wala line e qty er soman).
     */
    public function dispatch(StockTransfer $transfer, User $user, array $devices = [], ?string $note = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $devices, $note) {
            $transfer = $this->lock($transfer, [StockTransfer::APPROVED]);
            $this->assertAccess($user, $transfer->from_branch_id, 'dispatch from');
            $this->branches($transfer->from_branch_id, $transfer->to_branch_id);
            $transit = Branch::transitId();

            // stock row id kromanushare lock (ekshathe duita transfer e deadlock na)
            $items = $transfer->items()->with('product')->lockForUpdate()->get()->filter(fn ($i) => $i->plannedQty() > 0)->sortBy('main_stock_id');
            foreach ($items as $item) {
                $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($item->main_stock_id);
                $qty = $item->plannedQty();
                $key = "transfer:{$transfer->id}:dispatch:{$item->id}";

                // smartphone e IMEI lagbe; button phone e dile IMEI shoho
                if ($item->product->requiresImei() || !empty($devices[$item->id])) {
                    $picked = $this->pickDevices($stock, $transfer->from_branch_id, (array) ($devices[$item->id] ?? []), $qty);
                    foreach ($picked as $device) {
                        $sent = $this->imeis->sendInTransit($device, $transit, $transfer, "Transfer {$transfer->transfer_no} to {$transfer->toBranch->name}");
                        StockTransferDevice::create(['stock_transfer_item_id' => $item->id, 'product_imei_id' => $device->id, 'sent_status' => $sent]);
                    }
                    $this->stock->transfer($stock, $qty, $transfer->from_branch_id, $transit, $transfer, "Dispatched to {$transfer->toBranch->name}", $key, false);
                } else {
                    // source branch e jotota ache tar beshi na (StockService lock shoho dekhe)
                    $this->stock->transfer($stock, $qty, $transfer->from_branch_id, $transit, $transfer, "Dispatched to {$transfer->toBranch->name}", $key);
                }

                $item->update(['dispatched_qty' => $qty, 'unit_cost' => round((float) $stock->average_purchase_price, 2)]);
            }

            $transfer->update(['status' => StockTransfer::IN_TRANSIT, 'dispatched_by' => $user->id, 'dispatched_at' => now()]);
            $this->event($transfer, 'dispatched', $user, $note, $items->mapWithKeys(fn ($i) => [$i->id => ['dispatched' => $i->plannedQty()]])->all());
            AuditLogger::log('transfer.dispatched', $transfer, ['status' => StockTransfer::APPROVED], ['status' => StockTransfer::IN_TRANSIT]);

            return $transfer->refresh();
        });
    }

    /**
     * Receive (ekbar ba bhenge): $lines[item_id] = ['received' => n, 'damaged' => n] (accessory) ba
     * ['devices' => [product_imei_id => 'received'|'damaged']] (IMEI). Ekoi form (request_token) duibar ashle ekbar.
     */
    public function receive(StockTransfer $transfer, User $user, array $lines, ?string $token = null, ?string $note = null): StockTransfer
    {
        if ($token && StockTransferEvent::where('request_token', $token)->exists()) {
            return $transfer->refresh();
        }

        return DB::transaction(function () use ($transfer, $user, $lines, $token, $note) {
            $transfer = $this->lock($transfer, StockTransfer::OPEN_TRANSIT);
            $this->assertAccess($user, $transfer->to_branch_id, 'receive at');
            $transit = Branch::transitId();

            // koto number receive (transfer lock e, tai kromik) - stock movement er key te
            $receipt = $transfer->events()->where('event', 'received')->count() + 1;
            $done = [];
            $items = $transfer->items()->with('product')->lockForUpdate()->get()->sortBy('main_stock_id');

            foreach ($items as $item) {
                $line = $lines[$item->id] ?? null;
                if (!$line || $item->inTransit() < 1) {
                    continue;
                }
                $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($item->main_stock_id);
                $label = $stock->variantLabel();
                $hasDevices = $item->devices()->exists();

                if ($hasDevices) {
                    [$good, $damaged] = $this->receiveDevices($transfer, $item, (array) ($line['devices'] ?? []));
                } else {
                    $good = (int) ($line['received'] ?? 0);
                    $damaged = (int) ($line['damaged'] ?? 0);
                    if ($good < 0 || $damaged < 0) {
                        throw new \Exception("{$label}: quantities cannot be negative.");
                    }
                }
                if ($good + $damaged === 0) {
                    continue;
                }
                if ($good + $damaged > $item->inTransit()) {
                    throw new \Exception("{$label}: only {$item->inTransit()} still in transit, cannot receive " . ($good + $damaged) . '.');
                }

                $this->stock->transfer($stock, $good + $damaged, $transit, $transfer->to_branch_id, $transfer, "Received from {$transfer->fromBranch->name}",
                    "transfer:{$transfer->id}:receive:{$receipt}:{$item->id}", !$hasDevices);

                // accessory bhanga pouchhale stock theke bad (Dr Inventory Adjustment, Cr Inventory)
                if ($damaged > 0 && !$hasDevices) {
                    $this->stock->move($stock, -$damaged, StockService::DAMAGED, $transfer, "Arrived damaged ({$transfer->transfer_no})",
                        $transfer->to_branch_id, "transfer:{$transfer->id}:damaged:{$receipt}:{$item->id}");
                }

                $item->update(['received_qty' => $item->received_qty + $good, 'damaged_qty' => $item->damaged_qty + $damaged]);
                $done[$item->id] = ['received' => $good, 'damaged' => $damaged];
            }

            if (!$done) {
                throw new \Exception('Enter what arrived (received or damaged quantity).');
            }
            $this->event($transfer, 'received', $user, $note, $done, $token);

            $this->settle($transfer->refresh(), $user);

            return $transfer->refresh();
        });
    }

    // Baki pothe thaka maal pouchhay ni: transit theke bad (LOST, journal), status discrepancy
    public function markMissing(StockTransfer $transfer, User $user, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $reason) {
            $transfer = $this->lock($transfer, StockTransfer::OPEN_TRANSIT);
            if (!$user->canAccessBranch($transfer->to_branch_id) && !$user->canAccessBranch($transfer->from_branch_id)) {
                throw new \Exception('You cannot close transfers of other branches.');
            }
            if (trim($reason) === '') {
                throw new \Exception('Write what happened to the missing stock.');
            }
            $transit = Branch::transitId();
            $lines = [];

            foreach ($transfer->items()->lockForUpdate()->get()->sortBy('main_stock_id') as $item) {
                $left = $item->inTransit();
                if ($left < 1) {
                    continue;
                }
                $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($item->main_stock_id);
                foreach ($item->devices()->where('status', StockTransferDevice::IN_TRANSIT)->lockForUpdate()->get() as $row) {
                    $this->imeis->lostInTransit(ProductImei::lockForUpdate()->findOrFail($row->product_imei_id), $transfer, "Lost in transit ({$transfer->transfer_no}): {$reason}");
                    $row->update(['status' => StockTransferDevice::MISSING]);
                }
                $this->stock->move($stock, -$left, StockService::LOST, $transfer, "Lost in transit ({$transfer->transfer_no}): {$reason}", $transit,
                    "transfer:{$transfer->id}:missing:{$item->id}");
                $item->update(['missing_qty' => $item->missing_qty + $left]);
                $lines[$item->id] = ['missing' => $left];
            }

            $this->event($transfer, 'missing', $user, $reason, $lines);
            $this->settle($transfer->refresh(), $user);
            AuditLogger::log('transfer.missing', $transfer, null, ['reason' => $reason, 'lines' => $lines]);

            return $transfer->refresh();
        });
    }

    // Source branch e transfer korar moto koyta (IMEI: available phone, accessory: ledger)
    public function available(MainStock $stock, int $branchId): int
    {
        if ($stock->product?->requiresImei()) {
            return $this->devicesAt($stock, $branchId)->where('status', ProductImei::STATUS_AVAILABLE)->count();
        }

        return StockService::branchBalance($stock->id, $branchId);
    }

    // ---- helpers ------------------------------------------------------------------------------

    // shob line e pothe kichu na thakle: bhanga/missing thakle discrepancy, na hole received
    private function settle(StockTransfer $transfer, User $user): void
    {
        $items = $transfer->items()->get();
        $open = $items->sum(fn ($i) => $i->inTransit());
        $problem = $items->sum(fn ($i) => $i->damaged_qty + $i->missing_qty) > 0;

        $transfer->update([
            'status'      => $open > 0 ? StockTransfer::PARTIAL : ($problem ? StockTransfer::DISCREPANCY : StockTransfer::RECEIVED),
            'received_by' => $user->id,
            'received_at' => now(),
            'closed_at'   => $open > 0 ? null : now(),
        ]);
    }

    private function receiveDevices(StockTransfer $transfer, StockTransferItem $item, array $marks): array
    {
        $good = $damaged = 0;
        foreach ($marks as $deviceId => $mark) {
            if (!in_array($mark, ['received', 'damaged'], true)) {
                continue;
            }
            $row = $item->devices()->where('product_imei_id', (int) $deviceId)->lockForUpdate()->first();
            if (!$row) {
                throw new \Exception('A selected phone is not part of this transfer.');
            }
            if ($row->status !== StockTransferDevice::IN_TRANSIT) {
                throw new \Exception("Phone #{$row->product_imei_id} was already {$row->status}.");
            }
            $device = ProductImei::lockForUpdate()->findOrFail($row->product_imei_id);
            // bhanga obosthay pathano phone bhanga-i pouchhay
            $isDamaged = $mark === 'damaged' || (int) $row->sent_status === ProductImei::STATUS_DAMAGED;
            $this->imeis->receiveFromTransit($device, $transfer->to_branch_id, $isDamaged ? ProductImei::STATUS_DAMAGED : ProductImei::STATUS_AVAILABLE,
                $transfer, "Received from {$transfer->fromBranch->name} ({$transfer->transfer_no})" . ($isDamaged ? ', damaged' : ''));
            $row->update(['status' => $isDamaged ? StockTransferDevice::DAMAGED : StockTransferDevice::RECEIVED]);
            $isDamaged ? $damaged++ : $good++;
        }

        return [$good, $damaged];
    }

    // dispatch e IMEI: oi variant er, source branch e, available/damaged; qty er soman
    private function pickDevices(MainStock $stock, int $branchId, array $ids, int $qty)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (count($ids) !== $qty) {
            throw new \Exception("{$stock->variantLabel()}: select {$qty} phone(s) to dispatch (" . count($ids) . ' selected).');
        }

        $devices = $this->devicesAt($stock, $branchId)->whereIn('id', $ids)->lockForUpdate()->get();
        if ($devices->count() !== $qty) {
            throw new \Exception("{$stock->variantLabel()}: some selected phones are not in the source branch stock.");
        }

        return $devices;
    }

    // ei branch e thaka (branch chara purono record default branch er) oi variant er dokhole phone
    private function devicesAt(MainStock $stock, int $branchId)
    {
        $default = (int) Branch::where('is_default', 1)->value('id');

        return ProductImei::where('main_stock_id', $stock->id)
            ->whereIn('status', [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_DAMAGED])
            ->where(fn ($q) => $q->where('branch_id', $branchId)->when($branchId === $default, fn ($w) => $w->orWhereNull('branch_id')));
    }

    private function branches(int $fromId, int $toId): array
    {
        $from = Branch::find($fromId);
        $to = Branch::find($toId);
        if (!$from || !$to) {
            throw new \Exception('Select the source and destination branch.');
        }
        if ($from->id === $to->id) {
            throw new \Exception('Source and destination must be different.');
        }
        foreach ([$from, $to] as $branch) {
            if (!$branch->status) {
                throw new \Exception("{$branch->name} is inactive.");
            }
        }

        return [$from, $to];
    }

    private function assertAccess(User $user, int $branchId, string $action): void
    {
        if (!$user->canAccessBranch($branchId)) {
            $name = Branch::whereKey($branchId)->value('name') ?? "branch #{$branchId}";
            throw new \Exception("You cannot {$action} {$name}.");
        }
    }

    private function lock(StockTransfer $transfer, array $allowed): StockTransfer
    {
        $transfer = StockTransfer::with(['fromBranch', 'toBranch'])->lockForUpdate()->findOrFail($transfer->id);
        if (!in_array($transfer->status, $allowed, true)) {
            throw new \Exception("Transfer {$transfer->transfer_no} is {$transfer->statusLabel()}; this step is not allowed now.");
        }

        return $transfer;
    }

    private function event(StockTransfer $transfer, string $event, User $user, ?string $note = null, ?array $lines = null, ?string $token = null): StockTransferEvent
    {
        return StockTransferEvent::create(['stock_transfer_id' => $transfer->id, 'event' => $event, 'note' => $note, 'lines' => $lines,
            'request_token' => $token, 'user_id' => $user->id]);
    }
}
