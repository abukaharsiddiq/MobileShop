<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    const TYPE_IMEI_REGISTRATION = 'imei_registration';
    const TYPE_QUANTITY_CORRECTION = 'quantity_correction';

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'adjustment_no', 'type', 'main_stock_id', 'branch_id', 'quantity', 'imeis',
        'verified_model_number', 'reason', 'status', 'stock_before', 'stock_after',
        'requested_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'imeis' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function typeLabel()
    {
        return $this->type === self::TYPE_IMEI_REGISTRATION ? 'IMEI Registration' : 'Quantity Correction';
    }
}
