<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'address',
        'opening_balance',
    ];

    public function ledgers() {
        return $this->hasMany(SupplierLedger::class)->orderBy('date', 'asc');
    }

    public function currentBalance() {
        return $this->ledgers()->latest()->first()->balance ?? 0;
    }


}
