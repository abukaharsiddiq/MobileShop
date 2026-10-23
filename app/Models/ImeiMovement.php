<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Ekta IMEI er jiboner protiti ghotona (purchase, sale, return, transfer, repair, registration ...)
class ImeiMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'imei_no', 'product_imei_id', 'product_id', 'main_stock_id', 'branch_id', 'event',
        'status_after', 'reference_type', 'reference_id', 'reference_no', 'user_id', 'note',
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
}
