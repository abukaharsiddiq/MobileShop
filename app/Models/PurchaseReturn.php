<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    protected $fillable = [
        'supplier_id',
        'purchase_id',
        'return_no',
        'return_date',
        'total_amount',
        'note',
        'created_by',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    // kon invoice er mal ferot (na thakle supplier er je kono kena)
    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function details()
    {
        return $this->hasMany(PurchaseReturnDetail::class);
    }

    // ei return er sathe supplier er ferot deya taka (refund)
    public function refunds()
    {
        return $this->hasMany(SupplierPayment::class, 'reference_no', 'return_no')->where('type', SupplierPayment::TYPE_REFUND);
    }
}
