<?php

namespace App\Models;

use App\Services\StockService;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    const TYPE_IMEI_REGISTRATION = 'imei_registration';
    const TYPE_QUANTITY_CORRECTION = 'quantity_correction';
    const TYPE_IMEI_WRITE_OFF = 'imei_write_off';      // haralo/churi/nosto phone stock theke bad
    const TYPE_OPENING_STOCK = 'opening_stock';        // notun dokan/variant er shuru r stock (kena dam shoho)
    const TYPE_TRANSFER = 'transfer';                  // ek branch theke arek branch

    const TYPES = [
        self::TYPE_IMEI_REGISTRATION   => 'IMEI Registration',
        self::TYPE_QUANTITY_CORRECTION => 'Quantity Correction',
        self::TYPE_IMEI_WRITE_OFF      => 'IMEI Write-off',
        self::TYPE_OPENING_STOCK       => 'Opening Stock',
        self::TYPE_TRANSFER            => 'Branch Transfer',
    ];

    // correction/write-off er karon (report e damaged/lost alada dekhay)
    const REASONS = [
        'damaged'     => 'Damaged',
        'lost'        => 'Lost / Missing',
        'stolen'      => 'Stolen',
        'expired'     => 'Expired / Unusable',
        'found'       => 'Found (extra stock)',
        'count_error' => 'Counting / entry error',
        'other'       => 'Other',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'adjustment_no', 'type', 'main_stock_id', 'branch_id', 'to_branch_id', 'quantity', 'unit_cost', 'imeis',
        'verified_model_number', 'reason', 'reason_type', 'request_token', 'stock_count_id', 'status', 'stock_before', 'stock_after',
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

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
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

    public function reasonLabel(): ?string
    {
        return $this->reason_type ? (self::REASONS[$this->reason_type] ?? $this->reason_type) : null;
    }

    // ledger e kon dhoroner line (damaged/lost alada, jate report e dekha jay)
    public function movementType(): string
    {
        if ($this->type === self::TYPE_OPENING_STOCK) {
            return StockService::OPENING;
        }

        return match ($this->reason_type) {
            'damaged', 'expired' => StockService::DAMAGED,
            'lost', 'stolen'     => StockService::LOST,
            default              => StockService::ADJUSTMENT,
        };
    }
}
