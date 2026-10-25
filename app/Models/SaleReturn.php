<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    protected $fillable = [
        'sale_id',
        'customer_id',
        'total_refund',
        'due_adjusted',
        'cash_refund',
        'refund_method',
        'note',
        'return_date',
        'created_by',
    ];

    public function sale() {
    return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function customer() {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function returnItems() {
        return $this->hasMany(SaleReturnItem::class, 'sale_return_id');
    }

}
