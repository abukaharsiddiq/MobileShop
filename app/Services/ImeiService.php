<?php

namespace App\Services;

use App\Models\ImeiMovement;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Model;

/**
 * IMEI er shob niyom ek jaygay, jate purchase, sale, return ar reconciliation ekoi niyom mane.
 */
class ImeiService
{
    const PURCHASE = 'purchase';
    const PURCHASE_DELETED = 'purchase_deleted';
    const PURCHASE_RETURN = 'purchase_return';
    const SALE = 'sale';
    const SALE_RETURN = 'sale_return';
    const REGISTRATION = 'registration';
    const TRANSFER = 'transfer';
    const REPAIR_OUT = 'repair_out';
    const REPAIR_IN = 'repair_in';

    /**
     * Product type onujayi IMEI lagbe kina, ar qty r sathe sonkhya mile kina.
     */
    public function assertProductRules(Product $product, int $qty, array $imeis, string $label): void
    {
        if (!$product->allowsImei() && count($imeis) > 0) {
            throw new \Exception("{$label} is an accessory and cannot have IMEI.");
        }
        if ($product->requiresImei() && count($imeis) == 0) {
            throw new \Exception("IMEI is required for {$label}.");
        }
        if (count($imeis) > 0 && count($imeis) != $qty) {
            throw new \Exception("{$label}: quantity is {$qty} but " . count($imeis) . ' IMEI given.');
        }
    }

    /**
     * Notun IMEI number (purchase/registration): 15 digit, Luhn thik, list e duibar na.
     */
    public function assertValidNewImeis(array $imeis): void
    {
        $invalid = array_filter($imeis, fn ($imei) => !ProductImei::isValidFormat($imei));
        if ($invalid) {
            throw new \Exception('Invalid IMEI (must be 15 digits with a valid check digit): ' . implode(', ', $invalid));
        }

        $duplicates = array_unique(array_diff_assoc($imeis, array_unique($imeis)));
        if ($duplicates) {
            throw new \Exception('Same IMEI entered more than once: ' . implode(', ', $duplicates));
        }
    }

    /**
     * Shob branch mile IMEI ta ekhon active stock e ache kina (lock shoho).
     * Database er unique(active_imei) constraint shesh pahara; eta porishkar error message er jonno.
     */
    public function assertNotActive(array $imeis): void
    {
        if (!$imeis) {
            return;
        }

        $active = ProductImei::with('branch')
            ->whereIn('imei_no', $imeis)
            ->where('status', ProductImei::STATUS_IN_STOCK)
            ->lockForUpdate()
            ->get();

        if ($active->isNotEmpty()) {
            $list = $active->map(fn ($i) => $i->imei_no . ($i->branch ? " ({$i->branch->name})" : ''))->implode(', ');
            throw new \Exception('IMEI already in active stock: ' . $list);
        }
    }

    /**
     * Age bikri kora IMEI abar kena jay shudhu used/refurbished product hisebe.
     */
    public function assertRepurchaseAllowed(Product $product, array $imeis): void
    {
        $previouslySold = $this->lastRecords($imeis)
            ->filter(fn ($i) => $i->status == ProductImei::STATUS_SOLD)
            ->pluck('imei_no');

        if ($previouslySold->isNotEmpty() && !$product->isSecondHand()) {
            throw new \Exception('These IMEI were sold before and can only be purchased again as a Used/Refurbished phone: '
                . $previouslySold->implode(', '));
        }
    }

    /**
     * Stock reconciliation e registration: IMEI ta amader record e age bikri/ferot hoye thakle
     * seta "puraton unregistered stock" hote pare na.
     */
    public function assertNeverLeftStock(array $imeis): void
    {
        $known = $this->lastRecords($imeis)->filter(fn ($i) => $i->status != ProductImei::STATUS_IN_STOCK);

        if ($known->isNotEmpty()) {
            throw new \Exception('These IMEI already have a history in the system (sold or returned to supplier) and cannot be registered as existing stock: '
                . $known->pluck('imei_no')->implode(', ') . '. Use Sale Return or a Used phone purchase instead.');
        }
    }

    /**
     * Onno kono pending registration e eki IMEI thakle duijon eki phone register korte parbe na.
     */
    public function assertNotPendingRegistration(array $imeis, ?int $exceptAdjustmentId = null): void
    {
        $pending = StockAdjustment::where('type', StockAdjustment::TYPE_IMEI_REGISTRATION)
            ->where('status', StockAdjustment::STATUS_PENDING)
            ->when($exceptAdjustmentId, fn ($q) => $q->where('id', '!=', $exceptAdjustmentId))
            ->where(function ($q) use ($imeis) {
                foreach ($imeis as $imei) {
                    $q->orWhereJsonContains('imeis', $imei);
                }
            })
            ->pluck('adjustment_no');

        if ($pending->isNotEmpty()) {
            throw new \Exception('These IMEI are already waiting for approval in: ' . $pending->implode(', '));
        }
    }

    public function record(ProductImei $imei, string $event, ?Model $reference = null, ?string $note = null): ImeiMovement
    {
        return ImeiMovement::create([
            'imei_no'         => $imei->imei_no,
            'product_imei_id' => $imei->id,
            'product_id'      => $imei->product_id,
            'main_stock_id'   => $imei->main_stock_id,
            'branch_id'       => $imei->branch_id,
            'event'           => $event,
            'status_after'    => $imei->status,
            'reference_type'  => $reference ? class_basename($reference) : null,
            'reference_id'    => $reference?->getKey(),
            'reference_no'    => StockService::referenceNo($reference),
            'user_id'         => auth()->id(),
            'note'            => $note,
        ]);
    }

    // protiti IMEI er shesh (sobcheye notun) record
    private function lastRecords(array $imeis)
    {
        if (!$imeis) {
            return collect();
        }

        return ProductImei::whereIn('imei_no', $imeis)
            ->orderBy('id')
            ->get()
            ->keyBy('imei_no')
            ->values();
    }
}
