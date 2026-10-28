<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Repair e lagano part. Requested -> approved (stock kome) | rejected; approved -> returned (stock ferot).
class RepairJobPart extends Model
{
    const REQUESTED = 'requested';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    const RETURNED = 'returned';

    const LABELS = [
        self::REQUESTED => 'Waiting for approval',
        self::APPROVED  => 'Approved (stock deducted)',
        self::REJECTED  => 'Rejected',
        self::RETURNED  => 'Returned to stock',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity'    => 'integer',
            'unit_price'  => 'decimal:2',
            'unit_cost'   => 'decimal:2',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function job()
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lineTotal(): float
    {
        return round($this->quantity * (float) $this->unit_price, 2);
    }

    public function lineCost(): float
    {
        return round($this->quantity * (float) $this->unit_cost, 2);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
