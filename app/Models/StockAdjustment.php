<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    const TYPE_IMEI_REGISTRATION = 'imei_registration';
    const TYPE_QUANTITY_CORRECTION = 'quantity_correction';
    const TYPE_IMEI_WRITE_OFF = 'imei_write_off';      // haralo/churi/nosto phone stock theke bad

    const TYPES = [
        self::TYPE_IMEI_REGISTRATION   => 'IMEI Registration',
        self::TYPE_QUANTITY_CORRECTION => 'Quantity Correction',
        self::TYPE_IMEI_WRITE_OFF      => 'IMEI Write-off',
    ];

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
        return self::TYPES[$this->type] ?? $this->type;
    }
}
