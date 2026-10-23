<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'opening_balance',
        'status',
    ];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }


public function ledgers()
{
    return $this->hasMany(CustomerLedger::class);
}

}
