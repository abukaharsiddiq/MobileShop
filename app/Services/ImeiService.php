<?php

namespace App\Services;

use App\Models\ImeiMovement;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\ProductImeiNumber;
use App\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Model;

/**
 * IMEI er shob niyom ek jaygay, jate purchase, sale, return, reconciliation ar status change
 * ekoi niyom mane. Shob method DB transaction er bhitore dakte hobe (row lock thake).
 *
 * Status: available -> (reserved | in repair | damaged) -> sold / returned to supplier / written off.
 * "Owned" (available, reserved, in repair, damaged) = dokaner dokhole, stock e gona hoy.
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
    const RESERVED = 'reserved';
    const UNRESERVED = 'unreserved';
    const MARKED_DAMAGED = 'marked_damaged';
    const RESTORED = 'restored';
    const WRITTEN_OFF = 'written_off';

    // hate status bodlano (permission: manage imei): [from => [to => event]]
    const TRANSITIONS = [
        ProductImei::STATUS_AVAILABLE => [
            ProductImei::STATUS_RESERVED  => self::RESERVED,
            ProductImei::STATUS_IN_REPAIR => self::REPAIR_OUT,
            ProductImei::STATUS_DAMAGED   => self::MARKED_DAMAGED,
        ],
        ProductImei::STATUS_RESERVED => [
            ProductImei::STATUS_AVAILABLE => self::UNRESERVED,
        ],
        ProductImei::STATUS_IN_REPAIR => [
            ProductImei::STATUS_AVAILABLE => self::REPAIR_IN,
            ProductImei::STATUS_DAMAGED   => self::MARKED_DAMAGED,
        ],
        ProductImei::STATUS_DAMAGED => [
            ProductImei::STATUS_AVAILABLE => self::RESTORED,
            ProductImei::STATUS_IN_REPAIR => self::REPAIR_OUT,
        ],
    ];

    // ---- niyom (validation) -------------------------------------------------

    /**
     * Product type onujayi IMEI lagbe kina, ar qty r sathe sonkhya mile kina.
     * $items: device list (parseDevices), IMEI number ba IMEI record id - shudhu gona hoy.
     */
    public function assertProductRules(Product $product, int $qty, array $items, string $label): void
    {
        if (!$product->allowsImei() && count($items) > 0) {
            throw new \Exception("{$label} is an accessory and cannot have IMEI.");
        }
        if ($product->requiresImei() && count($items) == 0) {
            throw new \Exception("IMEI is required for {$label}.");
        }
        if (count($items) > 0 && count($items) != $qty) {
            throw new \Exception("{$label}: quantity is {$qty} but " . count($items) . ' IMEI given.');
        }
    }

    /**
     * Notun device (purchase/registration): dual-SIM model e IMEI 1/IMEI 2 duitai, single SIM e ekta.
     * Shob number 15 digit + Luhn, ekoi list e duibar na. Fere: shob number er flat list.
     */
    public function assertNewDevices(Product $product, array $devices, string $label): array
    {
        foreach ($devices as [$first, $second, $count]) {
            if ($count > 2) {
                throw new \Exception("{$label}: a phone can have at most two IMEI (IMEI 1/IMEI 2).");
            }
            if ($product->requiresSecondImei() && !$second) {
                throw new \Exception("{$label} is a dual-SIM model: enter IMEI 1/IMEI 2 for {$first}.");
            }
            if (!$product->requiresSecondImei() && $second) {
                throw new \Exception("{$label} is not marked as dual-SIM, so IMEI 2 ({$second}) cannot be entered. Mark the product as dual-SIM first.");
            }
        }

        $numbers = self::numbers($devices);
        $this->assertValidNewImeis($numbers);

        return $numbers;
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
     * Number ta (IMEI 1 ba IMEI 2) ekhon kono dokhole thaka phone e ache kina (shob branch, lock shoho).
     * Database er unique(product_imei_numbers.active_imei) shesh pahara; eta porishkar message er jonno.
     */
    public function assertNotActive(array $imeis, ?int $exceptDeviceId = null): void
    {
        $imeis = array_values(array_filter($imeis));
        if (!$imeis) {
            return;
        }

        // registry lock (dui transaction ekoi number e ekshathe kaj korte pare na)
        ProductImeiNumber::whereIn('imei_no', $imeis)->lockForUpdate()->get();

        $active = ProductImei::with('branch')
            ->owned()
            ->where(fn ($q) => $q->whereIn('imei_no', $imeis)->orWhereIn('imei_no_2', $imeis))
            ->when($exceptDeviceId, fn ($q) => $q->where('id', '!=', $exceptDeviceId))
            ->lockForUpdate()
            ->get();

        if ($active->isNotEmpty()) {
            $list = $active->map(fn ($i) => $i->numbersLabel() . ' (' . $i->statusText() . ($i->branch ? ", {$i->branch->name}" : '') . ')')->implode(', ');
            throw new \Exception('IMEI already in active stock: ' . $list);
        }
    }

    /**
     * Age bikri (ba write off) hoye dokan theke chole jawa IMEI abar kena jay shudhu used/refurbished
     * product hisebe; purono record ar itihash thake, notun record toiri hoy.
     */
    public function assertRepurchaseAllowed(Product $product, array $imeis): void
    {
        $leftAsSold = $this->lastRecords($imeis)
            ->filter(fn ($i) => in_array((int) $i->status, [ProductImei::STATUS_SOLD, ProductImei::STATUS_WRITTEN_OFF], true))
            ->map(fn ($i) => $i->numbersLabel());

        if ($leftAsSold->isNotEmpty() && !$product->isSecondHand()) {
            throw new \Exception('These IMEI were sold before and can only be purchased again as a Used/Refurbished phone: '
                . $leftAsSold->implode(', '));
        }
    }

    /**
     * Stock reconciliation e registration: IMEI ta amader record e age bikri/ferot hoye thakle
     * seta "puraton unregistered stock" hote pare na.
     */
    public function assertNeverLeftStock(array $imeis): void
    {
        $known = $this->lastRecords($imeis)->reject(fn ($i) => $i->isOwned());

        if ($known->isNotEmpty()) {
            throw new \Exception('These IMEI already have a history in the system (sold or returned to supplier) and cannot be registered as existing stock: '
                . $known->map(fn ($i) => $i->numbersLabel())->implode(', ') . '. Use Sale Return or a Used phone purchase instead.');
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
            ->get()
            // imeis e "IMEI1/IMEI2" token thakte pare, tai number gulo bhenge tulona
            ->filter(fn ($a) => array_intersect(self::numbers(ProductImei::parseDevices(implode(',', (array) $a->imeis))), $imeis))
            ->pluck('adjustment_no');

        if ($pending->isNotEmpty()) {
            throw new \Exception('These IMEI are already waiting for approval in: ' . $pending->implode(', '));
        }
    }

    // ---- jiboner ghotona (state change) -----------------------------------------

    // Purchase/registration: notun device stock e (available)
    public function register(Product $product, int $mainStockId, ?int $branchId, string $imei1, ?string $imei2, string $event,
                             ?Model $reference = null, ?string $note = null, ?int $supplierId = null, ?int $purchaseId = null,
                             ?int $purchaseDetailId = null): ProductImei
    {
        $imei = ProductImei::create([
            'product_id'        => $product->id,
            'main_stock_id'     => $mainStockId,
            'branch_id'         => $branchId,
            'purchase_id'       => $purchaseId,
            'purchase_detail_id' => $purchaseDetailId,
            'imei_no'           => $imei1,
            'imei_no_2'         => $imei2,
            'status'            => ProductImei::STATUS_AVAILABLE,
            'status_changed_at' => now(),
        ]);
        $this->record($imei, $event, $reference, $note, $supplierId);

        return $imei;
    }

    // Bikri: available, ba ei customer er jonno reserved hote hobe (caller lock kore)
    public function sell(ProductImei $imei, Model $sale, int $saleDetailId, int $customerId): void
    {
        if (!$imei->isSellableTo($customerId)) {
            throw new \Exception("IMEI {$imei->imei_no} is {$imei->statusText()} and cannot be sold"
                . ((int) $imei->status === ProductImei::STATUS_RESERVED ? ' to this customer.' : '.'));
        }

        $imei->update([
            'status'               => ProductImei::STATUS_SOLD,
            'sale_id'              => $sale->getKey(),
            'sale_detail_id'       => $saleDetailId,
            'reserved_customer_id' => null,
            'status_note'          => null,
            'status_changed_at'    => now(),
        ]);
        $this->record($imei, self::SALE, $sale, 'Sold to customer #' . $customerId, null, $customerId);
    }

    /**
     * Bikri howa phone dokane ferot (sale return; pore sale cancel/exchange o eta use korbe).
     * $condition: available (abar bikri jay) ba damaged (check/repair lagbe).
     */
    public function releaseFromSale(ProductImei $imei, Model $reference, int $condition, ?int $customerId, ?string $note = null, string $event = self::SALE_RETURN): void
    {
        if ((int) $imei->status !== ProductImei::STATUS_SOLD) {
            throw new \Exception("IMEI {$imei->imei_no} is {$imei->statusText()}, not sold, so it cannot come back from a sale.");
        }
        if (!in_array($condition, [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_DAMAGED], true)) {
            throw new \Exception('A returned phone can only come back as Available or Damaged.');
        }

        // ekoi IMEI onno kono record e dokhole thakle (used phone hisebe abar kena) ferot neya jabe na
        $this->assertNotActive(array_filter([$imei->imei_no, $imei->imei_no_2]), $imei->id);

        // purono sale er tottho imei_movements e thake
        $imei->update([
            'status'            => $condition,
            'sale_id'           => null,
            'sale_detail_id'    => null,
            'status_note'       => $condition === ProductImei::STATUS_DAMAGED ? 'Returned faulty by customer' : null,
            'status_changed_at' => now(),
        ]);
        $this->record($imei, $event, $reference, $note, null, $customerId);
    }

    /**
     * Supplier ke ferot: dokhole thakte hobe (reserved na), ar je supplier theke kena tar kachei.
     */
    public function returnToSupplier(ProductImei $imei, Model $purchaseReturn, int $supplierId): void
    {
        if (!$imei->isOwned() || (int) $imei->status === ProductImei::STATUS_RESERVED) {
            throw new \Exception("IMEI {$imei->imei_no} is {$imei->statusText()} and cannot be returned to the supplier.");
        }

        $boughtFrom = $imei->purchase_id ? $imei->purchase()->value('supplier_id') : null;
        if ($boughtFrom && (int) $boughtFrom !== $supplierId) {
            throw new \Exception("IMEI {$imei->imei_no} was bought from another supplier and cannot be returned to this one.");
        }

        $imei->update(['status' => ProductImei::STATUS_RETURNED_TO_SUPPLIER, 'reserved_customer_id' => null, 'status_changed_at' => now()]);
        $this->record($imei, self::PURCHASE_RETURN, $purchaseReturn, 'Returned to supplier #' . $supplierId, $supplierId);
    }

    // Haralo/churi/nosto: stock adjustment approve hole (stock o kome)
    public function writeOff(ProductImei $imei, Model $adjustment, string $reason): void
    {
        if (!in_array((int) $imei->status, [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_IN_REPAIR, ProductImei::STATUS_DAMAGED], true)) {
            throw new \Exception("IMEI {$imei->imei_no} is {$imei->statusText()} and cannot be written off.");
        }

        $imei->update(['status' => ProductImei::STATUS_WRITTEN_OFF, 'status_note' => $reason, 'reserved_customer_id' => null, 'status_changed_at' => now()]);
        $this->record($imei, self::WRITTEN_OFF, $adjustment, $reason);
    }

    /**
     * Hate status bodlano: reserve, repair e pathano/ferot, damaged, thik hoye available.
     */
    public function changeStatus(ProductImei $imei, int $to, string $note, ?int $customerId = null): ProductImei
    {
        $imei = ProductImei::lockForUpdate()->findOrFail($imei->id);
        $from = (int) $imei->status;
        $event = self::TRANSITIONS[$from][$to] ?? null;

        if (!$event) {
            throw new \Exception("IMEI {$imei->imei_no} cannot change from {$imei->statusText()} to " . ProductImei::statusLabel($to) . '.');
        }
        if ($to === ProductImei::STATUS_RESERVED && !$customerId) {
            throw new \Exception('Select the customer this phone is reserved for.');
        }

        $imei->update([
            'status'               => $to,
            'reserved_customer_id' => $to === ProductImei::STATUS_RESERVED ? $customerId : null,
            'status_note'          => $to === ProductImei::STATUS_AVAILABLE ? null : $note,
            'status_changed_at'    => now(),
        ]);
        $this->record($imei, $event, null, $note, null, $to === ProductImei::STATUS_RESERVED ? $customerId : null);

        return $imei;
    }

    public function record(ProductImei $imei, string $event, ?Model $reference = null, ?string $note = null,
                           ?int $supplierId = null, ?int $customerId = null): ImeiMovement
    {
        return ImeiMovement::create([
            'imei_no'         => $imei->imei_no,
            'imei_no_2'       => $imei->imei_no_2,
            'product_imei_id' => $imei->id,
            'product_id'      => $imei->product_id,
            'main_stock_id'   => $imei->main_stock_id,
            'branch_id'       => $imei->branch_id,
            'supplier_id'     => $supplierId,
            'customer_id'     => $customerId,
            'event'           => $event,
            'status_after'    => $imei->status,
            'reference_type'  => $reference ? class_basename($reference) : null,
            'reference_id'    => $reference?->getKey(),
            'reference_no'    => StockService::referenceNo($reference),
            'user_id'         => auth()->id(),
            'note'            => $note,
        ]);
    }

    // [[IMEI1, IMEI2, n], ...] -> ['IMEI1', 'IMEI2', ...]
    public static function numbers(array $devices): array
    {
        $numbers = [];
        foreach ($devices as $device) {
            foreach ([$device[0] ?? null, $device[1] ?? null] as $number) {
                if ($number) {
                    $numbers[] = $number;
                }
            }
        }

        return $numbers;
    }

    // protiti number er shesh (sobcheye notun) record (IMEI 1 ba IMEI 2 je slot e thakuk)
    private function lastRecords(array $imeis)
    {
        if (!$imeis) {
            return collect();
        }

        $records = ProductImei::where(fn ($q) => $q->whereIn('imei_no', $imeis)->orWhereIn('imei_no_2', $imeis))
            ->orderBy('id')
            ->get();

        $latest = [];
        foreach ($records as $record) {
            foreach ([$record->imei_no, $record->imei_no_2] as $number) {
                if ($number && in_array($number, $imeis, true)) {
                    $latest[$number] = $record;
                }
            }
        }

        return collect($latest)->unique('id')->values();
    }
}
