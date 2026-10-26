<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sale er taka: sale e deya (ekadhik method e bhag), pore deya due/kisti, ar ferot (return/cancel).
 * Customer ledger e mot hishab; ekhane method/reference shoho bhag.
 */
class SalePayment extends Model
{
    const TYPE_SALE = 'sale';
    const TYPE_DUE = 'due';
    const TYPE_REFUND = 'refund';

    protected $fillable = [
        'sale_id', 'customer_id', 'type', 'method', 'channel', 'amount', 'account', 'reference',
        'payment_date', 'received_by', 'customer_ledger_id', 'note', 'request_token', 'account_id',
        'reversed_at', 'reversed_by', 'reverse_reason',
    ];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public static function methods(): array
    {
        return config('pos.payment_methods', ['Cash' => 'cash']);
    }

    public static function channelOf(string $method): ?string
    {
        return self::methods()[$method] ?? null;
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    // Shudhu pore deya due/kisti er taka batil kora jay (POS er taka: sale cancel/return)
    public function isReversible(): bool
    {
        return $this->type === self::TYPE_DUE && !$this->reversed_at && $this->channel !== 'credit';
    }

    public function isRefund(): bool
    {
        return $this->type === self::TYPE_REFUND;
    }
}
