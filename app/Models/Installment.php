<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ekta kisti. Stored status: pending (due), partial, paid, cancelled; "overdue" hishab kore
 * (due date par hoye gele ar paid/cancelled na). Baki = amount + late_fee - paid_amount.
 */
class Installment extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PARTIAL = 'partial';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'sale_id',
        'customer_id',
        'installment_no',
        'amount',
        'paid_amount',
        'late_fee',
        'late_fee_assessed_at',
        'due_date',
        'status',
        'payment_date',
        'payment_method',
        'note',
        'received_by',
        'last_reminded_at',
        'reminder_count',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'late_fee_assessed_at' => 'datetime',
            'last_reminded_at'     => 'datetime',
            'cancelled_at'         => 'datetime',
        ];
    }

    // সম্পর্কের জন্য (Optional but recommended)
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations()
    {
        return $this->hasMany(InstallmentPayment::class);
    }

    // baki ache emon (paid/cancelled na)
    public function scopeOpen($query)
    {
        return $query->whereNotIn('installments.status', [self::STATUS_PAID, self::STATUS_CANCELLED]);
    }

    public function scopeOverdue($query)
    {
        return $query->open()->where('installments.due_date', '<', now()->toDateString());
    }

    public function totalDue(): float
    {
        return round((float) $this->amount + (float) $this->late_fee, 2);
    }

    public function openAmount(): float
    {
        return round(max(0, $this->totalDue() - (float) $this->paid_amount), 2);
    }

    public function isOverdue(): bool
    {
        return !in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED], true) && $this->due_date < now()->toDateString();
    }

    public function daysOverdue(): int
    {
        return $this->isOverdue() ? (int) \Carbon\Carbon::parse($this->due_date)->diffInDays(now()->startOfDay()) : 0;
    }

    // dekhanor status: paid, partial, overdue, due, cancelled
    public function displayStatus(): string
    {
        if (in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED], true)) {
            return $this->status;
        }
        if ($this->isOverdue()) {
            return 'overdue';
        }

        return $this->status === self::STATUS_PARTIAL ? 'partial' : 'due';
    }

    public function statusBadge(): string
    {
        return ['paid' => 'badge-success', 'partial' => 'badge-info', 'overdue' => 'badge-danger', 'due' => 'badge-warning',
            'cancelled' => 'badge-secondary'][$this->displayStatus()];
    }
}
