<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'customer_id',
        'invoice_no',
        'sale_date',
        'subtotal',
        'discount_type',
        'discount',
        'grand_total',
        'payment_method',
        'payment_account',
        'paid_amount',
        'due_amount',
        'is_installment',
        'total_installments',
        'installment_per_month',
        'total_paid_installments',
        'note',
        'created_by',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(SaleDetail::class);
    }

    public function user() // কে সেল করেছে
    {
        return $this->belongsTo(User::class, 'created_by');
    }


public function saleDetails()
{
    return $this->hasMany(SaleDetail::class);
}

public function returns() {
    return $this->hasMany(SaleReturn::class, 'sale_id');
}




}
