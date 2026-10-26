<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Customer er ferot (ba exchange). Pending e kichu bodlay na; approve hole stock, IMEI, invoice, ledger
 * ar taka (refund / store credit) ekshathe - SaleReturnService.
 */
class SaleReturn extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED = 'rejected';

    const TYPE_RETURN = 'return';
    const TYPE_EXCHANGE = 'exchange';

    const SETTLE_REFUND = 'refund';
    const SETTLE_CREDIT = 'store_credit';

    const REASONS = [
        'defective'      => 'Defective / not working',
        'damaged'        => 'Damaged on delivery',
        'wrong_item'     => 'Wrong item / variant',
        'not_as_described' => 'Not as described',
        'changed_mind'   => 'Customer changed mind',
        'upgrade'        => 'Upgrade / exchange',
        'other'          => 'Other',
    ];

    protected $fillable = [
        'return_no',
        'status',
        'type',
        'sale_id',
        'customer_id',
        'branch_id',
        'total_refund',
        'due_adjusted',
        'cash_refund',
        'store_credit',
        'refund_method',
        'settlement',
        'refund_reference',
        'exchange_sale_id',
        'note',
        'reason',
        'return_date',
        'created_by',
        'request_token',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function sale() {
    return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function customer() {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function returnItems() {
        return $this->hasMany(SaleReturnItem::class, 'sale_return_id');
    }

    public function exchangeSale()
    {
        return $this->belongsTo(Sale::class, 'exchange_sale_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function scopeCompleted($query)
    {
        return $query->where('sale_returns.status', self::STATUS_COMPLETED);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function reasonLabel(): ?string
    {
        return $this->reason ? (self::REASONS[$this->reason] ?? $this->reason) : null;
    }

    public function statusBadge(): string
    {
        return [self::STATUS_PENDING => 'badge-warning', self::STATUS_COMPLETED => 'badge-success', self::STATUS_REJECTED => 'badge-dark'][$this->status] ?? 'badge-light';
    }
}
