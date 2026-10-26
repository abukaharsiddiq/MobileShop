<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Physical stock count: ek branch er variant gulo gona (IMEI hole scan), submit e system er hishab
 * snapshot ar variance; approve hole variance stock e (missing phone write off) - StockCountService.
 */
class StockCount extends Model
{
    const STATUS_COUNTING = 'counting';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_CANCELLED = 'cancelled';

    const STATUSES = [
        self::STATUS_COUNTING  => 'Counting',
        self::STATUS_SUBMITTED => 'Submitted',
        self::STATUS_APPROVED  => 'Approved',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'count_no', 'branch_id', 'category_id', 'status', 'note', 'created_by', 'submitted_at', 'submitted_by',
        'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusBadge(): string
    {
        return [
            self::STATUS_COUNTING  => 'badge-info',
            self::STATUS_SUBMITTED => 'badge-warning',
            self::STATUS_APPROVED  => 'badge-success',
            self::STATUS_CANCELLED => 'badge-dark',
        ][$this->status] ?? 'badge-light';
    }
}
