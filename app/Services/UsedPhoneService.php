<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MainStock;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Supplier;
use App\Models\UsedPhone;
use App\Models\UsedPhoneEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Used & refurbished phone kena, inspection, refurbishment ar resale.
 *
 *  intake (pending) -> approve: stock e (in_stock, IMEI available) ba refurbishment e (refurbishing, IMEI in repair)
 *                   -> reject (stock/IMEI/journal chhoy na, record thake)
 *  refurbishing: khoroch jog (Dr Inventory, Cr Cash/Bank; variant er average cost bare) -> QC pass (available) / fail (damaged)
 *  in_stock: POS e sadharon bikri; ferot SaleReturnService; abar refurbishment e pathano jay (available/damaged hole)
 *
 * IMEI er niyom ImeiService er (format, dual-SIM, active stock e duibar na, bikri howa IMEI shudhu used product e
 * abar kena). Approve e stock +1, IMEI register, average cost, journal - ekta transaction e, na hole kichui na.
 * Shob public method nijer DB transaction e (row lock shoho).
 */
class UsedPhoneService
{
    const NEXT_STOCK = 'stock';
    const NEXT_REFURBISH = 'refurbish';

    const CHECK_RESULTS = ['ok', 'fault', 'na'];

    public function __construct(
        private ImeiService $imeis,
        private StockService $stock,
        private FinancePostingService $finance,
    ) {}

    /**
     * $data: source, customer_id?, supplier_id?, seller_name?, seller_phone?, seller_document?, seller_address?,
     *        product_id, main_stock_id, imei_no, imei_no_2?, condition_grade, battery_health?, accessories[]?,
     *        checklist[item => ok|fault|na]?, inspection_notes?, purchase_cost, resale_price?, warranty_months?,
     *        warranty_terms?, payment_method?, account_id?, note?, request_token?
     * $approveAs (stock | refurbish): approve permission thakle entry r sathe approve.
     */
    public function intake(array $data, User $user, ?string $approveAs = null): UsedPhone
    {
        $token = trim((string) ($data['request_token'] ?? '')) ?: null;
        if ($token && ($done = UsedPhone::where('request_token', $token)->first())) {
            return $done;
        }

        return DB::transaction(function () use ($data, $user, $token, $approveAs) {
            $product = Product::findOrFail($data['product_id']);
            $stock = $this->variant($product, (int) ($data['main_stock_id'] ?? 0));
            [$imei1, $imei2] = $this->assertDevice($product, (string) ($data['imei_no'] ?? ''), $data['imei_no_2'] ?? null);

            $cost = round((float) ($data['purchase_cost'] ?? 0), 2);
            if ($cost <= 0) {
                throw new \Exception('Enter the price paid to the seller.');
            }
            $money = Account::moneyFor(($data['account_id'] ?? null) ? (int) $data['account_id'] : null, $data['payment_method'] ?? 'Cash');

            try {
                $phone = UsedPhone::create($this->seller($data) + $this->inspection($data) + $this->pricing($data, $cost, $user) + [
                    'intake_no'       => 'TMP-' . Str::random(20),
                    'branch_id'       => $user->branch_id ?? Branch::currentId(),
                    'product_id'      => $product->id,
                    'main_stock_id'   => $stock->id,
                    'imei_no'         => $imei1,
                    'imei_no_2'       => $imei2,
                    'purchase_date'   => now()->toDateString(),
                    'purchase_cost'   => $cost,
                    'payment_method'  => $money->payment_method ?: ($data['payment_method'] ?? 'Cash'),
                    'account_id'      => $money->id,
                    'status'          => UsedPhone::PENDING,
                    'note'            => trim((string) ($data['note'] ?? '')) ?: null,
                    'request_token'   => $token,
                    'created_by'      => $user->id,
                ]);
            } catch (QueryException $e) {
                // duita request ekshathe ekoi IMEI: database er unique (pending_imei)
                if (str_contains($e->getMessage(), 'used_phones_pending_imei_unique')) {
                    throw new \Exception("IMEI {$imei1} is already on a used phone intake waiting for approval.");
                }
                throw $e;
            }
            $phone->update(['intake_no' => 'UP-' . str_pad((string) $phone->id, 6, '0', STR_PAD_LEFT)]);

            $this->event($phone, UsedPhoneEvent::ENTERED, $user, "Grade {$phone->condition_grade}"
                . ($phone->battery_health !== null ? ", battery {$phone->battery_health}%" : '')
                . ($phone->faults() ? ', faults: ' . implode(', ', $phone->faults()) : '')
                . ', offered ' . number_format($cost, 2));
            AuditLogger::log('used_phone.entered', $phone, null,
                $phone->only(['intake_no', 'source', 'seller_name', 'imei_no', 'imei_no_2', 'purchase_cost', 'condition_grade']));

            if ($approveAs) {
                $this->approve($phone, $user, $approveAs);
            }

            return $phone->refresh();
        });
    }

    /**
     * Inspection approve: ekta transaction e stock +1, IMEI register (available ba in repair), average cost,
     * journal (Dr Inventory, Cr Cash/Bank) - IMEI abar jachai hoy (entry r por obostha bodlate pare).
     */
    public function approve(UsedPhone $phone, User $user, string $next, ?string $note = null): UsedPhone
    {
        return DB::transaction(function () use ($phone, $user, $next, $note) {
            $phone = UsedPhone::lockForUpdate()->findOrFail($phone->id);
            if ($phone->status !== UsedPhone::PENDING) {
                throw new \Exception("Intake {$phone->intake_no} is {$phone->statusLabel()}, not waiting for approval.");
            }
            if (!in_array($next, [self::NEXT_STOCK, self::NEXT_REFURBISH], true)) {
                throw new \Exception('Choose whether the phone goes to stock for resale or to refurbishment.');
            }
            if ($next === self::NEXT_STOCK && $phone->resale_price === null) {
                throw new \Exception('Set the resale price before putting the phone in stock, or send it to refurbishment.');
            }

            $product = Product::findOrFail($phone->product_id);
            $stock = $this->variant($product, (int) $phone->main_stock_id);
            // shesh pahara: lock shoho active stock / open purchase / registration / onno intake
            $this->assertDevice($product, $phone->imei_no, $phone->imei_no_2, $phone->id);

            $branchId = $phone->branch_id ?? Branch::currentId();
            $cost = (float) $phone->purchase_cost;

            // weighted average (purchase er moto): (ager stock x ager average + ei phone er dam) / (stock + 1)
            $current = (int) $stock->quantity;
            $oldAvg = (float) ($stock->average_purchase_price ?? 0);
            $oldLast = $stock->last_purchase_price;
            $newAvg = round((($current * $oldAvg) + $cost) / ($current + 1), 2);
            $stock->average_purchase_price = $newAvg;
            $stock->last_purchase_price = $cost;
            PriceHistory::record($product, $stock, PriceHistory::LAST_COST, $oldLast, $cost, 'used_phone', $phone);
            PriceHistory::record($product, $stock, PriceHistory::AVERAGE_COST, $oldAvg, $newAvg, 'used_phone', $phone);

            $this->stock->move($stock, 1, StockService::USED_PURCHASE, $phone, "Bought from {$phone->seller_name}", $branchId, 'used_phone:' . $phone->id);

            $device = $this->imeis->register($product, $stock->id, $branchId, $phone->imei_no, $phone->imei_no_2, ImeiService::USED_PURCHASE, $phone,
                "Bought as {$product->typeLabel()} from {$phone->seller_name} ({$phone->sourceLabel()}), grade {$phone->condition_grade}"
                . ($phone->battery_health !== null ? ", battery {$phone->battery_health}%" : ''),
                $phone->supplier_id, null, null, $phone->customer_id);

            if ($next === self::NEXT_REFURBISH) {
                $this->imeis->changeStatus($device, ProductImei::STATUS_IN_REPAIR, "Refurbishment ({$phone->intake_no})" . ($note ? ": {$note}" : ''), null, $phone);
            }

            $phone->update([
                'status'          => $next === self::NEXT_REFURBISH ? UsedPhone::REFURBISHING : UsedPhone::IN_STOCK,
                'product_imei_id' => $device->id,
                'branch_id'       => $branchId,
                'purchase_date'   => now()->toDateString(),   // taka deya holo approve e
                'approved_by'     => $user->id,
                'approved_at'     => now(),
            ]);

            // Dr Inventory, Cr Cash/Bank (cash hole cashier er session e)
            $this->finance->postUsedPhonePurchase($phone);

            $this->event($phone, UsedPhoneEvent::APPROVED, $user, $note);
            if ($next === self::NEXT_REFURBISH) {
                $this->event($phone, UsedPhoneEvent::REFURB_STARTED, $user, $note);
            }
            AuditLogger::log('used_phone.approved', $phone, ['status' => UsedPhone::PENDING],
                ['status' => $phone->status, 'product_imei_id' => $device->id, 'cost' => $cost]);

            return $phone->refresh();
        });
    }

    public function reject(UsedPhone $phone, User $user, string $reason): UsedPhone
    {
        return DB::transaction(function () use ($phone, $user, $reason) {
            $phone = UsedPhone::lockForUpdate()->findOrFail($phone->id);
            if ($phone->status !== UsedPhone::PENDING) {
                throw new \Exception("Intake {$phone->intake_no} is {$phone->statusLabel()} and cannot be rejected.");
            }
            if (trim($reason) === '') {
                throw new \Exception('Write why the phone is not being bought.');
            }

            $phone->update(['status' => UsedPhone::REJECTED, 'rejection_reason' => $reason, 'approved_by' => $user->id, 'approved_at' => now()]);
            $this->event($phone, UsedPhoneEvent::REJECTED, $user, $reason);
            AuditLogger::log('used_phone.rejected', $phone, ['status' => UsedPhone::PENDING], ['status' => UsedPhone::REJECTED, 'reason' => $reason]);

            return $phone;
        });
    }

    // Stock er phone (available/damaged, ba sale return e in repair) abar refurbishment e
    public function startRefurbishment(UsedPhone $phone, User $user, string $note): UsedPhone
    {
        return DB::transaction(function () use ($phone, $user, $note) {
            $phone = UsedPhone::lockForUpdate()->findOrFail($phone->id);
            if ($phone->status !== UsedPhone::IN_STOCK) {
                throw new \Exception("Intake {$phone->intake_no} is {$phone->statusLabel()}; only a phone in stock can go to refurbishment.");
            }
            $device = ProductImei::lockForUpdate()->findOrFail($phone->product_imei_id);
            $status = (int) $device->status;
            if (!in_array($status, [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_DAMAGED, ProductImei::STATUS_IN_REPAIR], true)) {
                throw new \Exception("IMEI {$device->imei_no} is {$device->statusText()} and cannot go to refurbishment.");
            }

            if ($status !== ProductImei::STATUS_IN_REPAIR) {
                $this->imeis->changeStatus($device, ProductImei::STATUS_IN_REPAIR, "Refurbishment ({$phone->intake_no}): {$note}", null, $phone);
            }
            $phone->update(['status' => UsedPhone::REFURBISHING, 'qc_by' => null, 'refurbished_at' => null]);
            $this->event($phone, UsedPhoneEvent::REFURB_STARTED, $user, $note);

            return $phone;
        });
    }

    /**
     * Refurbishment er khoroch (part, kaj): phone er kena dam e jog, variant er average cost bare,
     * journal Dr Inventory, Cr Cash/Bank. Ekoi form (request_token) duibar ashle ekbar-i.
     */
    public function addCost(UsedPhone $phone, User $user, float $amount, string $note, ?int $accountId = null,
                            ?string $method = 'Cash', ?string $token = null): UsedPhoneEvent
    {
        if ($token && ($done = UsedPhoneEvent::where('request_token', $token)->first())) {
            return $done;
        }

        return DB::transaction(function () use ($phone, $user, $amount, $note, $accountId, $method, $token) {
            $phone = UsedPhone::lockForUpdate()->findOrFail($phone->id);
            if ($phone->status !== UsedPhone::REFURBISHING) {
                throw new \Exception('Refurbishment costs can only be added while the phone is in refurbishment.');
            }
            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw new \Exception('Refurbishment cost must be greater than zero.');
            }
            if (trim($note) === '') {
                throw new \Exception('Write what the cost was for (part or work).');
            }
            $money = Account::moneyFor($accountId, $method);

            $stock = MainStock::with('product')->lockForUpdate()->findOrFail($phone->main_stock_id);
            $qty = (int) $stock->quantity;
            if ($qty < 1) {
                throw new \Exception("{$stock->variantLabel()} has no stock, so the cost cannot be added to it.");
            }
            // stock er mot dam + khoroch, qty bodlay na
            $oldAvg = (float) $stock->average_purchase_price;
            $stock->average_purchase_price = round((($qty * $oldAvg) + $amount) / $qty, 2);
            $stock->save();
            PriceHistory::record($stock->product, $stock, PriceHistory::AVERAGE_COST, $oldAvg, $stock->average_purchase_price, 'refurbishment', $phone);

            $phone->update(['refurbishment_cost' => round((float) $phone->refurbishment_cost + $amount, 2)]);
            $cost = $this->event($phone, UsedPhoneEvent::COST, $user, $note, $amount, $money->id, $token);
            $this->finance->postUsedPhoneRefurbishment($phone, $cost, $money);
            AuditLogger::log('used_phone.cost', $phone, null, ['amount' => $amount, 'note' => $note, 'account' => $money->name]);

            return $cost;
        });
    }

    /**
     * QC: pass hole IMEI available (resale dam lagbe), fail hole damaged. $data: condition_grade?, battery_health?,
     * resale_price?, warranty_months?, warranty_terms?
     */
    public function completeRefurbishment(UsedPhone $phone, User $user, bool $passed, string $note, array $data = []): UsedPhone
    {
        return DB::transaction(function () use ($phone, $user, $passed, $note, $data) {
            $phone = UsedPhone::lockForUpdate()->findOrFail($phone->id);
            if ($phone->status !== UsedPhone::REFURBISHING) {
                throw new \Exception("Intake {$phone->intake_no} is {$phone->statusLabel()}, not in refurbishment.");
            }
            if (trim($note) === '') {
                throw new \Exception('Write the QC result.');
            }
            $device = ProductImei::lockForUpdate()->findOrFail($phone->product_imei_id);
            if ((int) $device->status !== ProductImei::STATUS_IN_REPAIR) {
                throw new \Exception("IMEI {$device->imei_no} is {$device->statusText()}; it must be In Repair to finish refurbishment.");
            }

            $changes = [];
            if (($data['condition_grade'] ?? '') !== '') {
                $changes += array_intersect_key($this->inspection(['condition_grade' => $data['condition_grade'],
                    'battery_health' => $data['battery_health'] ?? $phone->battery_health]), array_flip(['condition_grade', 'battery_health']));
            }
            if ($passed) {
                $changes += $this->pricing(array_merge($phone->only(['resale_price', 'warranty_months', 'warranty_terms']),
                    array_filter($data, fn ($v, $k) => in_array($k, ['resale_price', 'warranty_months', 'warranty_terms'], true) && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH)),
                    $phone->totalCost(), $user);
                if ($changes['resale_price'] === null) {
                    throw new \Exception('Set the resale price before the phone goes back to stock.');
                }
            }

            $this->imeis->changeStatus($device, $passed ? ProductImei::STATUS_AVAILABLE : ProductImei::STATUS_DAMAGED,
                ($passed ? 'Refurbishment passed QC' : 'Refurbishment failed QC') . " ({$phone->intake_no}): {$note}", null, $phone);

            $phone->update($changes + ['status' => UsedPhone::IN_STOCK, 'qc_by' => $user->id, 'refurbished_at' => now()]);
            $this->event($phone, $passed ? UsedPhoneEvent::REFURB_PASSED : UsedPhoneEvent::REFURB_FAILED, $user,
                $note . ' (total cost ' . number_format($phone->totalCost(), 2) . ')');

            return $phone;
        });
    }

    // Resale dam ar warranty policy (bikri howar age porjonto)
    public function updatePricing(UsedPhone $phone, User $user, array $data): UsedPhone
    {
        return DB::transaction(function () use ($phone, $user, $data) {
            $phone = UsedPhone::lockForUpdate()->findOrFail($phone->id);
            if ($phone->status === UsedPhone::REJECTED) {
                throw new \Exception("Intake {$phone->intake_no} was rejected.");
            }
            $device = $phone->product_imei_id ? ProductImei::lockForUpdate()->find($phone->product_imei_id) : null;
            if ($device && (int) $device->status === ProductImei::STATUS_SOLD) {
                throw new \Exception("IMEI {$device->imei_no} is sold; its price is part of the sale.");
            }

            $old = $phone->only(['resale_price', 'warranty_months', 'warranty_terms']);
            $new = $this->pricing($data, $phone->totalCost(), $user);
            if ($phone->status === UsedPhone::IN_STOCK && $new['resale_price'] === null) {
                throw new \Exception('A phone in stock needs a resale price.');
            }
            $phone->update($new);

            $this->event($phone, UsedPhoneEvent::PRICING, $user,
                'Resale ' . ($old['resale_price'] !== null ? number_format((float) $old['resale_price'], 2) : '-') . ' -> '
                . ($new['resale_price'] !== null ? number_format($new['resale_price'], 2) : '-')
                . '; warranty ' . ($new['warranty_months'] === null ? 'product default' : $new['warranty_months'] . ' month(s)')
                . ($new['warranty_terms'] ? " ({$new['warranty_terms']})" : ''));
            AuditLogger::log('used_phone.pricing', $phone, $old, $new);

            return $phone;
        });
    }

    /**
     * Form theke IMEI jachai (save er age). @return string[] somossa (khali = kena jay)
     */
    public function checkImeis(Product $product, string $imei1, ?string $imei2): array
    {
        try {
            if (!$product->isSecondHand()) {
                throw new \Exception("{$product->product_name} is a {$product->typeLabel()}. Choose a Used or Refurbished phone model.");
            }
            $this->assertDevice($product, $imei1, $imei2);
        } catch (\Exception $e) {
            if (get_class($e) !== \Exception::class) {
                throw $e;
            }
            return [$e->getMessage()];
        }

        return [];
    }

    // ---- helpers ------------------------------------------------------------------------------

    // product used/refurbished type er, active; variant oi product er, active (lock shoho)
    private function variant(Product $product, int $stockId): MainStock
    {
        if (!$product->isSecondHand()) {
            throw new \Exception("{$product->product_name} is a {$product->typeLabel()}. Choose a Used or Refurbished phone model.");
        }
        if (!$product->isActive()) {
            throw new \Exception("{$product->product_name} is inactive.");
        }
        $stock = MainStock::where('product_id', $product->id)->lockForUpdate()->find($stockId);
        if (!$stock) {
            throw new \Exception("{$product->product_name}: select a variant of this model.");
        }
        if (!$stock->status) {
            throw new \Exception("{$stock->variantLabel()} is inactive.");
        }

        return $stock;
    }

    /**
     * IMEI 1 (+ dual-SIM e IMEI 2): ImeiService er niyom - format/Luhn, dual-SIM, bikri howa IMEI shudhu used product e,
     * active stock e na, onno open purchase/registration/intake e na. Fere [IMEI 1, IMEI 2].
     */
    private function assertDevice(Product $product, string $imei1, ?string $imei2, ?int $exceptUsedPhoneId = null): array
    {
        $imei1 = trim($imei1);
        $imei2 = trim((string) $imei2) ?: null;
        if ($imei1 === '') {
            throw new \Exception('Enter IMEI 1.');
        }

        $numbers = $this->imeis->assertNewDevices($product, [[$imei1, $imei2, $imei2 ? 2 : 1]], $product->product_name);
        $this->imeis->assertRepurchaseAllowed($product, $numbers);
        $this->imeis->assertNotActive($numbers);
        $this->imeis->assertNotInOpenPurchase($numbers);
        $this->imeis->assertNotPendingRegistration($numbers);
        $this->imeis->assertNotPendingUsedIntake($numbers, $exceptUsedPhoneId);

        return [$imei1, $imei2];
    }

    private function seller(array $data): array
    {
        $source = (string) ($data['source'] ?? '');
        if (!isset(config('used_phones.sources')[$source])) {
            throw new \Exception('Choose where the phone was bought from.');
        }

        $customer = $supplier = null;
        if ($source === 'customer') {
            $customer = Customer::find($data['customer_id'] ?? 0);
            if (!$customer) {
                throw new \Exception('Select the customer who is selling the phone.');
            }
        }
        if ($source === 'supplier') {
            $supplier = Supplier::find($data['supplier_id'] ?? 0);
            if (!$supplier) {
                throw new \Exception('Select the supplier.');
            }
            if (!$supplier->isActive()) {
                throw new \Exception("{$supplier->name} is inactive.");
            }
        }

        $name = trim((string) ($data['seller_name'] ?? '')) ?: ($customer->name ?? $supplier->name ?? '');
        $phone = trim((string) ($data['seller_phone'] ?? '')) ?: ($customer->phone ?? $supplier->phone ?? null);
        $document = trim((string) ($data['seller_document'] ?? '')) ?: null;
        if ($name === '') {
            throw new \Exception('Enter the seller name.');
        }
        if ($source === 'walk_in') {
            if (!$phone) {
                throw new \Exception('Enter the seller phone number.');
            }
            if (!$document && config('used_phones.require_seller_document')) {
                throw new \Exception('Enter the seller NID / passport number (required for walk-in sellers).');
            }
        }

        return [
            'source'          => $source,
            'customer_id'     => $customer?->id,
            'supplier_id'     => $supplier?->id,
            'seller_name'     => $name,
            'seller_phone'    => $phone,
            'seller_document' => $document,
            'seller_address'  => trim((string) ($data['seller_address'] ?? '')) ?: null,
        ];
    }

    private function inspection(array $data): array
    {
        $grade = (string) ($data['condition_grade'] ?? '');
        if (!isset(config('used_phones.grades')[$grade])) {
            throw new \Exception('Choose the condition grade.');
        }

        $battery = ($data['battery_health'] ?? null) === null || $data['battery_health'] === '' ? null : (int) $data['battery_health'];
        if ($battery !== null && ($battery < 1 || $battery > 100)) {
            throw new \Exception('Battery health must be between 1 and 100%.');
        }

        $accessories = array_values(array_filter((array) ($data['accessories'] ?? [])));
        if ($unknown = array_diff($accessories, array_keys(config('used_phones.accessories')))) {
            throw new \Exception('Unknown accessory: ' . implode(', ', $unknown));
        }

        $checklist = [];
        foreach ((array) ($data['checklist'] ?? []) as $item => $result) {
            if (!isset(config('used_phones.checklist')[$item]) || !in_array($result, self::CHECK_RESULTS, true)) {
                throw new \Exception("Unknown inspection result for {$item}.");
            }
            $checklist[$item] = $result;
        }

        $notes = trim((string) ($data['inspection_notes'] ?? '')) ?: null;
        if (in_array('fault', $checklist, true) && !$notes) {
            throw new \Exception('Describe the faults found in the inspection notes.');
        }

        return [
            'condition_grade'  => $grade,
            'battery_health'   => $battery,
            'accessories'      => array_values(array_unique($accessories)),
            'checklist'        => $checklist,
            'inspection_notes' => $notes,
        ];
    }

    // resale dam (kena + refurbishment er niche shudhu 'sell below cost' permission e) ar warranty
    private function pricing(array $data, float $totalCost, User $user): array
    {
        $price = ($data['resale_price'] ?? null) === null || $data['resale_price'] === '' ? null : round((float) $data['resale_price'], 2);
        if ($price !== null && $price <= 0) {
            throw new \Exception('Resale price must be greater than zero.');
        }
        if ($price !== null && $price < $totalCost && !$user->can('sell below cost')) {
            throw new \Exception('Resale price ' . number_format($price, 2) . ' is below the cost ' . number_format($totalCost, 2)
                . '. Only users allowed to sell below cost can set it.');
        }

        $months = ($data['warranty_months'] ?? null) === null || $data['warranty_months'] === '' ? null : (int) $data['warranty_months'];
        if ($months !== null && ($months < 0 || $months > 36)) {
            throw new \Exception('Warranty must be between 0 and 36 months.');
        }

        return [
            'resale_price'    => $price,
            'warranty_months' => $months,
            'warranty_terms'  => trim((string) ($data['warranty_terms'] ?? '')) ?: null,
        ];
    }

    private function event(UsedPhone $phone, string $event, User $user, ?string $note = null, ?float $amount = null,
                           ?int $accountId = null, ?string $token = null): UsedPhoneEvent
    {
        return UsedPhoneEvent::create([
            'used_phone_id' => $phone->id,
            'event'         => $event,
            'amount'        => $amount,
            'account_id'    => $accountId,
            'note'          => $note,
            'request_token' => $token,
            'user_id'       => $user->id,
        ]);
    }
}
