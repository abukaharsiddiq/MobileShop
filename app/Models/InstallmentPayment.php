<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Kon payment (sale_payments) kon kisti te koto bosechhe; sale_payment_id null = upgrade er ager taka
class InstallmentPayment extends Model
{
    protected $fillable = ['installment_id', 'sale_payment_id', 'amount'];

    public function installment()
    {
        return $this->belongsTo(Installment::class);
    }

    public function payment()
    {
        return $this->belongsTo(SalePayment::class, 'sale_payment_id');
    }
}
