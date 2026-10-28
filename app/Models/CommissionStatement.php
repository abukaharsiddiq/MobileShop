<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ekjon staff er ek somoyer commission. draft (abar hishab kora jay) -> approved (journal, ar bodlay na) -> paid.
 * draft cancel hole entry gulo chhere deya hoy (porer statement e jay). Muchha jay na.
 */
class CommissionStatement extends Model
{
    const DRAFT = 'draft';
    const APPROVED = 'approved';
    const PAID = 'paid';
    const CANCELLED = 'cancelled';

    const LABELS = [self::DRAFT => 'Draft', self::APPROVED => 'Approved (payable)', self::PAID => 'Paid', self::CANCELLED => 'Cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'approved_at' => 'datetime', 'paid_at' => 'datetime',
            'earned' => 'decimal:2', 'reversed' => 'decimal:2', 'bonus' => 'decimal:2', 'net' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Commission statements cannot be deleted. Cancel the draft instead.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function entries()
    {
        return $this->hasMany(CommissionEntry::class)->orderBy('entry_date')->orderBy('id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::DRAFT    => 'secondary',
            self::APPROVED => 'warning',
            self::PAID     => 'success',
            default        => 'dark',
        };
    }
}
