<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'opening_balance',
        'status',
        'note',
    ];

    public function ledgers() {
        return $this->hasMany(SupplierLedger::class)->orderBy('date', 'asc')->orderBy('id', 'asc');
    }

    public function purchases() {
        return $this->hasMany(Purchase::class);
    }

    public function purchaseReturns() {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function payments() {
        return $this->hasMany(SupplierPayment::class);
    }

    // dhonatmok = due (amra dhari), rinatmok = advance
    public function currentBalance(): float {
        return \App\Services\LedgerService::supplierBalance($this->id);
    }

    public function isActive(): bool {
        return (int) $this->status === 1;
    }

    // list/report e protita supplier er balance ek query te (ledger_balance column)
    public function scopeWithBalance($query) {
        return $query->addSelect(['ledger_balance' => SupplierLedger::selectRaw('COALESCE(SUM(credit - debit), 0)')
            ->whereColumn('supplier_id', 'suppliers.id')]);
    }

    public function scopeActive($query) {
        return $query->where('status', 1);
    }

    public static function dueOf(float $balance): float {
        return max(0, round($balance, 2));
    }

    public static function advanceOf(float $balance): float {
        return max(0, round(-$balance, 2));
    }
}
