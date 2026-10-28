<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Repair er taka: advance (job e), payment (delivery/pore), refund (cancel ba beshi deya). Bodlano/muchha jay na.
class RepairPayment extends Model
{
    const UPDATED_AT = null;

    const ADVANCE = 'advance';
    const PAYMENT = 'payment';
    const REFUND = 'refund';

    const LABELS = [self::ADVANCE => 'Advance', self::PAYMENT => 'Payment', self::REFUND => 'Refund'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(function (RepairPayment $payment) {
            // shudhu journal er account bosano (FinancePostingService) chara bodlano jay na
            if (array_diff(array_keys($payment->getDirty()), ['account_id', 'customer_ledger_id'])) {
                throw new \LogicException('Repair payments cannot be edited. Record a refund instead.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Repair payments cannot be deleted.'));
    }

    public function job()
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isRefund(): bool
    {
        return $this->type === self::REFUND;
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }
}
