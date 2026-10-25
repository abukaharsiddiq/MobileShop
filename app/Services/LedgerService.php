<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Supplier;
use App\Models\SupplierLedger;

/**
 * Customer ar supplier ledger e entry. Balance "ager shesh line" theke na niye
 * tarikh (date, tarpor id) onujayi puro running balance abar hishab hoy, ar
 * customer/supplier row lock kora hoy - tai ekshathe duijon entry dile ba
 * pechoner tarikhe entry dileo balance bhul hoy na.
 *
 * Customer: balance = debit - credit (customer amader kache koto pay/dhare)
 * Supplier: balance = credit - debit (amra supplier ke koto dhari)
 *
 * DB transaction er bhitore dakte hobe.
 */
class LedgerService
{
    public function customerEntry(array $data): CustomerLedger
    {
        Customer::whereKey($data['customer_id'])->lockForUpdate()->firstOrFail();

        $entry = CustomerLedger::create($data + ['debit' => 0, 'credit' => 0, 'balance' => 0]);
        $this->recalculateCustomer($data['customer_id']);

        return $entry->refresh();
    }

    public function supplierEntry(array $data): SupplierLedger
    {
        Supplier::whereKey($data['supplier_id'])->lockForUpdate()->firstOrFail();

        $entry = SupplierLedger::create($data + ['debit' => 0, 'credit' => 0, 'balance' => 0]);
        $this->recalculateSupplier($data['supplier_id']);

        return $entry->refresh();
    }

    // Kono row er balance bodlale return kore kotogulo bodlalo
    public function recalculateCustomer(int $customerId, bool $apply = true): int
    {
        return $this->recalculate(CustomerLedger::where('customer_id', $customerId), fn ($l) => $l->debit - $l->credit, $apply);
    }

    public function recalculateSupplier(int $supplierId, bool $apply = true): int
    {
        return $this->recalculate(SupplierLedger::where('supplier_id', $supplierId), fn ($l) => $l->credit - $l->debit, $apply);
    }

    public static function customerBalance(int $customerId): float
    {
        return (float) CustomerLedger::where('customer_id', $customerId)->sum(\DB::raw('debit - credit'));
    }

    public static function supplierBalance(int $supplierId): float
    {
        return (float) SupplierLedger::where('supplier_id', $supplierId)->sum(\DB::raw('credit - debit'));
    }

    private function recalculate($query, callable $change, bool $apply): int
    {
        $running = 0;
        $changed = 0;

        foreach ($query->orderBy('date')->orderBy('id')->get() as $ledger) {
            $running = round($running + $change($ledger), 2);

            if (round((float) $ledger->balance, 2) !== $running) {
                $changed++;
                if ($apply) {
                    $ledger->balance = $running;
                    $ledger->save();
                }
            }
        }

        return $changed;
    }
}
