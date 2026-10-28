<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\UsedPhone;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Models\WarrantyClaimEvent;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Warranty: sale complete hole protiti device (IMEI) ba accessory line er shop ar brand warranty toiri hoy
 * (sale er tarikh theke product er months). Return/cancel e batil, exchange e notun device er warranty
 * (policy: new/remaining), claim e replacement hole notun device e (policy: remaining/new).
 *
 * Claim: received -> inspected (covered: in_repair / sent_to_brand; na hole rejected) -> ready -> delivered.
 * Shob status server e jachai; claim/resolve DB transaction ar row lock e.
 */
class WarrantyService
{
    public function __construct(
        private ImeiService $imeis,
        private StockService $stock,
    ) {}

    // ---- issue / void --------------------------------------------------------------------------

    // Sale er shob line er warranty (ager theke thakle bad - bar bar chalano nirapod). Fere koyta toiri holo.
    public function issueForSale(Sale $sale): int
    {
        $created = 0;
        $details = SaleDetail::with('product')->where('sale_id', $sale->id)->where('quantity', '>', 0)->get();
        $devices = ProductImei::whereIn('sale_detail_id', $details->pluck('id'))->where('status', ProductImei::STATUS_SOLD)
            ->get()->groupBy('sale_detail_id');

        foreach ($details as $detail) {
            $lineDevices = $devices[$detail->id] ?? collect();
            $units = $lineDevices->isEmpty() ? [null] : $lineDevices->all();

            // protiti device er shop tarpor brand
            foreach ($units as $device) {
                foreach ($this->durations($detail->product, $device) as $type => $months) {
                    $created += $this->create($sale, $detail, $type, $months, Carbon::parse($sale->sale_date), $device,
                        $device ? 1 : (int) $detail->quantity) ? 1 : 0;
                }
            }
        }

        return $created;
    }

    /**
     * @return array<string, int> [type => months] - 0/null hole oi warranty nei.
     * Used/refurbished phone e intake er nijer warranty policy (thakle) product er default er bodole.
     */
    public function durations(Product $product, ?ProductImei $device = null): array
    {
        $shop = $product->warranty_months ?? (config('warranty.shop_defaults')[$product->type] ?? null);
        if ($device && $product->isSecondHand()) {
            $policy = UsedPhone::where('product_imei_id', $device->id)->whereNotNull('warranty_months')->value('warranty_months');
            $shop = $policy ?? $shop;
        }

        return array_filter([Warranty::SHOP => (int) $shop, Warranty::BRAND => (int) $product->brand_warranty_months]);
    }

    public function voidForSale(Sale $sale, string $reason): int
    {
        return $this->void(Warranty::active()->where('sale_id', $sale->id), $reason);
    }

    /**
     * Return complete hole: ferot device er warranty batil; IMEI chara line e quantity kome (0 hole batil).
     * Exchange hole policy 'remaining' e notun sale er warranty ferot device er meyad porjonto.
     */
    public function applyReturn(SaleReturn $return, ?Sale $exchangeSale = null): void
    {
        $reason = ($return->type === SaleReturn::TYPE_EXCHANGE ? 'Exchanged' : 'Returned') . " ({$return->return_no})";
        $ended = collect();

        foreach (SaleReturnItem::where('sale_return_id', $return->id)->get() as $item) {
            if ($item->product_imei_id) {
                $query = Warranty::active()->where('sale_detail_id', $item->sale_detail_id)->where('product_imei_id', $item->product_imei_id);
                $ended = $ended->merge((clone $query)->get());
                $this->void($query, $reason);
                continue;
            }

            foreach (Warranty::active()->where('sale_detail_id', $item->sale_detail_id)->whereNull('product_imei_id')->lockForUpdate()->get() as $warranty) {
                $ended->push($warranty);
                $left = max(0, (int) $warranty->quantity - (int) $item->quantity);
                $left > 0
                    ? $warranty->update(['quantity' => $left])
                    : $this->void(Warranty::whereKey($warranty->id), $reason);
            }
        }

        if ($exchangeSale && config('warranty.exchange_policy') === 'remaining') {
            foreach (Warranty::active()->where('sale_id', $exchangeSale->id)->get() as $warranty) {
                $previous = $ended->where('type', $warranty->type)->max(fn ($w) => $w->end_date->toDateString());
                if ($previous && $previous < $warranty->end_date->toDateString()) {
                    $warranty->update(['end_date' => $previous]);
                }
            }
        }
    }

    // ---- lookup -----------------------------------------------------------------------------

    /**
     * Invoice no, IMEI 1/IMEI 2 (puro ba shesh 6+ digit), warranty no ba claim no diye. Batil/purono o dekhay.
     */
    public function lookup(string $query): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        $saleIds = Sale::where('invoice_no', $query)->pluck('id');
        $deviceIds = ProductImei::where(fn ($q) => $q->where('imei_no', $query)->orWhere('imei_no_2', $query)
            ->when(ctype_digit($query) && strlen($query) >= 6, fn ($w) => $w->orWhere('imei_no', 'like', "%{$query}")->orWhere('imei_no_2', 'like', "%{$query}")))
            ->pluck('id');
        $claimWarranty = WarrantyClaim::where('claim_no', $query)->value('warranty_id');

        return Warranty::with(['sale:id,invoice_no,sale_date', 'customer:id,name,phone', 'product:id,product_name,type', 'device', 'openClaim',
                'claims' => fn ($q) => $q->orderByDesc('id')])
            ->where(fn ($q) => $q->whereIn('sale_id', $saleIds)
                ->orWhereIn('product_imei_id', $deviceIds)
                ->orWhere('imei_no', $query)
                ->orWhere('warranty_no', $query)
                ->orWhere('id', $claimWarranty ?? 0))
            ->orderByDesc('start_date')->orderBy('id')
            ->get();
    }

    // ---- claim ------------------------------------------------------------------------------

    /**
     * $data: reported_on, reason, description, device_condition?, request_token?
     */
    public function openClaim(Warranty $warranty, array $data, User $user): WarrantyClaim
    {
        $token = $data['request_token'] ?? null;
        if ($token && ($done = WarrantyClaim::where('request_token', $token)->first())) {
            return $done;
        }

        return DB::transaction(function () use ($warranty, $data, $user, $token) {
            $warranty = Warranty::lockForUpdate()->findOrFail($warranty->id);
            $reported = Carbon::parse($data['reported_on'] ?? now())->toDateString();

            if ($reported > now()->toDateString()) {
                throw new \Exception('The reported date cannot be in the future.');
            }
            if (!isset(config('warranty.claim_reasons')[$data['reason'] ?? ''])) {
                throw new \Exception('Choose a claim reason.');
            }
            if ($warranty->status !== Warranty::ACTIVE) {
                throw new \Exception("Warranty {$warranty->warranty_no} is {$warranty->status}" . ($warranty->void_reason ? " ({$warranty->void_reason})" : '') . '.');
            }
            if (!$warranty->isClaimable($reported)) {
                throw new \Exception("Warranty {$warranty->warranty_no} expired on {$warranty->end_date->format('d-m-Y')}.");
            }
            if ($open = $warranty->openClaim()->first()) {
                throw new \Exception("Warranty {$warranty->warranty_no} already has an open claim ({$open->claim_no}).");
            }
            // device ekhono customer er (ei sale e bikri, ferot na)
            $device = $warranty->product_imei_id ? ProductImei::lockForUpdate()->find($warranty->product_imei_id) : null;
            if ($warranty->product_imei_id && (!$device || (int) $device->status !== ProductImei::STATUS_SOLD || (int) $device->sale_id !== (int) $warranty->sale_id)) {
                throw new \Exception("Device {$warranty->imei_no} is no longer sold on this invoice.");
            }

            try {
                $claim = WarrantyClaim::create([
                    'claim_no'         => 'TMP-' . \Illuminate\Support\Str::random(20),
                    'warranty_id'      => $warranty->id,
                    'customer_id'      => $warranty->customer_id,
                    'branch_id'        => $user->branch_id ?? Branch::currentId(),
                    'reported_on'      => $reported,
                    'reason'           => $data['reason'],
                    'description'      => trim((string) ($data['description'] ?? '')) ?: config('warranty.claim_reasons')[$data['reason']],
                    'device_condition' => $data['device_condition'] ?? null,
                    'status'           => WarrantyClaim::RECEIVED,
                    'request_token'    => $token,
                    'received_by'      => $user->id,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'warranty_claims_one_open_unique')) {
                    throw new \Exception("Warranty {$warranty->warranty_no} already has an open claim.");
                }
                throw $e;
            }
            $claim->update(['claim_no' => 'WC-' . str_pad((string) $claim->id, 6, '0', STR_PAD_LEFT)]);

            $this->event($claim, WarrantyClaim::RECEIVED, "{$claim->reasonLabel()}: {$claim->description}" . ($claim->device_condition ? " | Condition: {$claim->device_condition}" : ''));
            if ($device) {
                $this->imeis->record($device, ImeiService::WARRANTY_CLAIM, $claim, "Warranty claim {$claim->claim_no}: {$claim->reasonLabel()}", null, $warranty->customer_id);
            }
            AuditLogger::log('warranty_claim.received', $claim, null, $claim->only(['claim_no', 'warranty_id', 'reason', 'reported_on']));

            return $claim;
        });
    }

    /**
     * Inspection: covered hole $next (in_repair ba sent_to_brand); na hole rejected (device ferot).
     */
    public function inspect(WarrantyClaim $claim, bool $covered, string $note, string $next = WarrantyClaim::IN_REPAIR, float $serviceCharge = 0): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $covered, $note, $next, $serviceCharge) {
            $claim = $this->lockClaim($claim, [WarrantyClaim::RECEIVED]);
            if ($covered && !in_array($next, [WarrantyClaim::IN_REPAIR, WarrantyClaim::SENT_TO_BRAND], true)) {
                throw new \Exception('Choose repair in shop or send to brand service.');
            }
            if (trim($note) === '') {
                throw new \Exception('Write the inspection finding.');
            }

            $status = $covered ? $next : WarrantyClaim::REJECTED;
            $claim->update([
                'status'            => $status,
                'inspection_result' => $covered ? 'covered' : 'not_covered',
                'inspection_note'   => $note,
                'resolution'        => $covered ? null : 'rejected',
                'service_charge'    => round(max(0, $serviceCharge), 2),
                'delivered_at'      => $covered ? null : now(),
            ]);
            $this->event($claim, WarrantyClaim::INSPECTED, ($covered ? 'Covered: ' : 'Not covered: ') . $note);
            if ($covered) {
                $this->event($claim, $status, null);
            } else {
                $this->returnDevice($claim, 'Claim rejected: ' . $note);
            }
            AuditLogger::log('warranty_claim.inspected', $claim, ['status' => WarrantyClaim::RECEIVED], ['status' => $status, 'covered' => $covered, 'note' => $note]);

            return $claim;
        });
    }

    // in_repair <-> sent_to_brand
    public function move(WarrantyClaim $claim, string $to, string $note): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $to, $note) {
            $claim = WarrantyClaim::lockForUpdate()->findOrFail($claim->id);
            if (!in_array($to, WarrantyClaim::TRANSITIONS[$claim->status] ?? [], true)) {
                throw new \Exception("Claim {$claim->claim_no} cannot go from {$claim->statusLabel()} to " . (WarrantyClaim::LABELS[$to] ?? $to) . '.');
            }
            $from = $claim->status;
            $claim->update(['status' => $to]);
            $this->event($claim, $to, $note);
            AuditLogger::log('warranty_claim.moved', $claim, ['status' => $from], ['status' => $to, 'note' => $note]);

            return $claim;
        });
    }

    /**
     * Kaj shesh: repaired / no_fault / replaced. Replacement e ei variant er available ekta notun phone (IMEI chara
     * hole stock theke ekta unit) customer ke jay, nosto ta dokane "damaged"; warranty notun device e (policy).
     */
    public function resolve(WarrantyClaim $claim, string $resolution, string $note, ?int $replacementImeiId = null): WarrantyClaim
    {
        if (!isset(WarrantyClaim::RESOLUTIONS[$resolution])) {
            throw new \Exception('Choose how the claim was resolved.');
        }

        return DB::transaction(function () use ($claim, $resolution, $note, $replacementImeiId) {
            $claim = $this->lockClaim($claim, [WarrantyClaim::IN_REPAIR, WarrantyClaim::SENT_TO_BRAND]);
            $replacement = $resolution === 'replaced' ? $this->replace($claim, $replacementImeiId, $note) : null;

            $claim->update(['status' => WarrantyClaim::READY, 'resolution' => $resolution, 'replacement_imei_id' => $replacement?->id]);
            $this->event($claim, WarrantyClaim::READY, WarrantyClaim::RESOLUTIONS[$resolution] . ($replacement ? " ({$replacement->imei_no})" : '') . ($note ? ": {$note}" : ''));
            AuditLogger::log('warranty_claim.resolved', $claim, null, ['resolution' => $resolution, 'replacement' => $replacement?->imei_no, 'note' => $note]);

            return $claim;
        });
    }

    public function deliver(WarrantyClaim $claim, ?string $note = null): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $note) {
            $claim = $this->lockClaim($claim, [WarrantyClaim::READY]);
            $claim->update(['status' => WarrantyClaim::DELIVERED, 'delivered_at' => now()]);
            $this->event($claim, WarrantyClaim::DELIVERED, $note);
            $this->returnDevice($claim, 'Handed back after claim ' . $claim->claim_no);
            AuditLogger::log('warranty_claim.delivered', $claim, ['status' => WarrantyClaim::READY], ['status' => WarrantyClaim::DELIVERED]);

            return $claim;
        });
    }

    // ---- reminder / report ----------------------------------------------------------------------

    // Meyad shesh hote $days din baki (aj theke) - ajke reminder na deya
    public function expiring(?int $days = null)
    {
        $days ??= (int) config('warranty.expiring_soon_days', 30);

        return Warranty::with(['customer:id,name,phone', 'product:id,product_name', 'sale:id,invoice_no'])
            ->inForce()
            ->where('end_date', '<=', now()->addDays($days)->toDateString())
            ->whereDoesntHave('openClaim')
            ->orderBy('end_date')->get();
    }

    public function markReminded(Warranty $warranty, string $channel): void
    {
        $warranty->update(['last_reminded_at' => now(), 'reminder_count' => $warranty->reminder_count + 1]);
        AuditLogger::log('warranty.reminded', $warranty, null, ['channel' => $channel]);
    }

    public static function reminderMessage(Warranty $warranty): string
    {
        return "Dear {$warranty->customer->name}, the " . ($warranty->type === Warranty::BRAND ? 'brand' : 'shop')
            . " warranty of your {$warranty->product->product_name}" . ($warranty->imei_no ? " (IMEI {$warranty->imei_no})" : '')
            . " from invoice {$warranty->sale->invoice_no} ends on {$warranty->end_date->format('d-m-Y')}. "
            . 'If there is any problem, please bring it to us before that date.';
    }

    public static function whatsappLink(Warranty $warranty): ?string
    {
        $phone = Customer::normalizePhone($warranty->customer->phone ?? '');

        return $phone ? 'https://wa.me/88' . $phone . '?text=' . rawurlencode(self::reminderMessage($warranty)) : null;
    }

    /**
     * Report: warranty status onujayi sonkhya (sale er tarikh range), claim status/karon/resolution.
     */
    public function report(string $from, string $to): array
    {
        $warranties = Warranty::with('openClaim')->whereBetween('start_date', [$from, $to])->get();
        $claims = WarrantyClaim::whereBetween('reported_on', [$from, $to])->get();

        return [
            'by_status'     => $warranties->groupBy(fn ($w) => $w->currentStatus())->map->count()->all(),
            'by_type'       => $warranties->groupBy('type')->map->count()->all(),
            'claims'        => $claims->count(),
            'by_claim'      => $claims->groupBy('status')->map->count()->all(),
            'by_reason'     => $claims->groupBy('reason')->map->count()->sortDesc()->all(),
            'by_resolution' => $claims->whereNotNull('resolution')->groupBy('resolution')->map->count()->all(),
            'claim_rate'    => $warranties->count() ? round($claims->pluck('warranty_id')->unique()->count() * 100 / $warranties->count(), 1) : 0,
        ];
    }

    // ---- internals ----------------------------------------------------------------------------

    private function create(Sale $sale, SaleDetail $detail, string $type, int $months, Carbon $start, ?ProductImei $device, int $quantity,
                            ?int $replacedFrom = null, ?Carbon $end = null): bool
    {
        $live = Warranty::active()->where('sale_detail_id', $detail->id)->where('type', $type)
            ->when($device, fn ($q) => $q->where('product_imei_id', $device->id), fn ($q) => $q->whereNull('product_imei_id'))->exists();
        if ($live || $quantity < 1) {
            return false;
        }

        $warranty = Warranty::create([
            'warranty_no'      => 'TMP-' . \Illuminate\Support\Str::random(20),
            'sale_id'          => $sale->id,
            'sale_detail_id'   => $detail->id,
            'customer_id'      => $sale->customer_id,
            'product_id'       => $detail->product_id,
            'main_stock_id'    => $device?->main_stock_id ?? $detail->main_stock_id,
            'product_imei_id'  => $device?->id,
            'imei_no'          => $device?->imei_no,
            'type'             => $type,
            'quantity'         => $quantity,
            'months'           => $months,
            'start_date'       => $start->toDateString(),
            'end_date'         => ($end ?? $start->copy()->addMonthsNoOverflow($months))->toDateString(),
            'status'           => Warranty::ACTIVE,
            'replaced_from_id' => $replacedFrom,
        ]);
        $warranty->update(['warranty_no' => 'WR-' . str_pad((string) $warranty->id, 6, '0', STR_PAD_LEFT)]);

        return true;
    }

    private function void($query, string $reason, string $status = Warranty::VOID): int
    {
        return (clone $query)->update(['status' => $status, 'void_reason' => $reason, 'voided_at' => now()]);
    }

    private function replace(WarrantyClaim $claim, ?int $replacementImeiId, string $note): ?ProductImei
    {
        $warranty = Warranty::lockForUpdate()->findOrFail($claim->warranty_id);
        $detail = SaleDetail::findOrFail($warranty->sale_detail_id);
        $sale = Sale::findOrFail($warranty->sale_id);
        $branchId = $claim->branch_id ?? Branch::currentId();

        if (!$warranty->product_imei_id) {
            // accessory: stock theke ekta notun unit (nosto ta fela - khoroch Warranty Replacement Cost e)
            $stock = MainStock::lockForUpdate()->findOrFail($warranty->main_stock_id ?? $detail->main_stock_id);
            $this->stock->move($stock, -1, StockService::WARRANTY_OUT, $claim, "Replacement for {$claim->claim_no}", $branchId, "warranty_out:{$claim->id}");

            return null;
        }

        $old = ProductImei::lockForUpdate()->findOrFail($warranty->product_imei_id);
        $new = $replacementImeiId ? ProductImei::lockForUpdate()->find($replacementImeiId) : null;
        if (!$new || (int) $new->main_stock_id !== (int) $old->main_stock_id) {
            throw new \Exception('Choose an available phone of the same model and variant as the replacement.');
        }
        if ((int) $new->status !== ProductImei::STATUS_AVAILABLE) {
            throw new \Exception("IMEI {$new->imei_no} is {$new->statusText()} and cannot be given as a replacement.");
        }

        $oldStock = MainStock::lockForUpdate()->findOrFail($old->main_stock_id);
        // nosto phone dokane (damaged, stock e gona), notun ta customer er (ekoi invoice line e)
        $this->imeis->releaseFromSale($old, $claim, ProductImei::STATUS_DAMAGED, $warranty->customer_id,
            "Replaced under warranty claim {$claim->claim_no}", ImeiService::WARRANTY_REPLACED);
        $this->stock->move($oldStock, 1, StockService::WARRANTY_IN, $claim, "Faulty unit from {$claim->claim_no}", $branchId, "warranty_in:{$claim->id}");

        $this->imeis->sell($new, $sale, $detail->id, $warranty->customer_id);
        $this->imeis->record($new, ImeiService::WARRANTY_REPLACEMENT, $claim, "Replacement for {$old->imei_no} ({$claim->claim_no})", null, $warranty->customer_id);
        $this->stock->move($oldStock, -1, StockService::WARRANTY_OUT, $claim, "Replacement unit for {$claim->claim_no}", $branchId, "warranty_out:{$claim->id}");

        // ei device er shob active warranty (shop + brand) notun device e
        $remaining = config('warranty.replacement_policy') !== 'new';
        foreach (Warranty::active()->where('sale_detail_id', $detail->id)->where('product_imei_id', $old->id)->lockForUpdate()->get() as $previous) {
            $this->void(Warranty::whereKey($previous->id), "Replaced by {$new->imei_no} ({$claim->claim_no})", Warranty::REPLACED);
            $this->create($sale, $detail, $previous->type, $previous->months, now(), $new, 1, $previous->id,
                $remaining ? $previous->end_date : null);
        }

        return $new;
    }

    // device customer ke ferot (claim e device er record e itihash)
    private function returnDevice(WarrantyClaim $claim, string $note): void
    {
        $warranty = Warranty::find($claim->warranty_id);
        $deviceId = $claim->replacement_imei_id ?? $warranty?->product_imei_id;
        if ($deviceId && ($device = ProductImei::find($deviceId))) {
            $this->imeis->record($device, ImeiService::WARRANTY_RETURNED, $claim, $note, null, $claim->customer_id);
        }
    }

    private function lockClaim(WarrantyClaim $claim, array $from): WarrantyClaim
    {
        $claim = WarrantyClaim::lockForUpdate()->findOrFail($claim->id);
        if (!in_array($claim->status, $from, true)) {
            throw new \Exception("Claim {$claim->claim_no} is {$claim->statusLabel()}.");
        }

        return $claim;
    }

    private function event(WarrantyClaim $claim, string $status, ?string $note): void
    {
        WarrantyClaimEvent::create(['warranty_claim_id' => $claim->id, 'status' => $status, 'note' => $note, 'user_id' => auth()->id()]);
    }
}
