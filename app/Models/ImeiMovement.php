<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Ekta IMEI er jiboner protiti ghotona (purchase, sale, return, transfer, repair, registration ...).
// Itihash bodlano/muchha jay na (StockMovement er moto).
class ImeiMovement extends Model
{
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('IMEI history cannot be edited.'));
        static::deleting(fn () => throw new \LogicException('IMEI history cannot be deleted.'));
    }

    protected $fillable = [
        'imei_no', 'imei_no_2', 'product_imei_id', 'product_id', 'main_stock_id', 'branch_id',
        'supplier_id', 'customer_id', 'event', 'status_after', 'reference_type', 'reference_id',
        'reference_no', 'user_id', 'note',
        'created_at', // backfill e purono tarikh rakhar jonno
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
