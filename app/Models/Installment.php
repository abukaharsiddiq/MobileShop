<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Installment extends Model
{
    protected $fillable = [
        'sale_id',
        'customer_id',
        'installment_no',
        'amount',
        'paid_amount',
        'due_date',
        'status',
        'payment_date',
        'payment_method',
        'note'
    ];

    // সম্পর্কের জন্য (Optional but recommended)
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
