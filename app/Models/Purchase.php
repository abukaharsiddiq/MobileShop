<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = [
        'supplier_id',
        'invoice_no',
        'purchase_date',
        'subtotal',
        'discount_type',
        'discount',
        'grand_total',
        'payment_method',
        'payment_account',
        'paid_amount',
        'return_amount',
        'due_amount',
        'payment_status',
        'note',
    ];

    public function purchaseDetails()
    {
        return $this->hasMany(PurchaseDetail::class);
    }

    public function supplier() {
    return $this->belongsTo(Supplier::class, 'supplier_id');
}

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function product_imeis()
{
    return $this->hasMany(ProductImei::class, 'purchase_id', 'id');
}

}
