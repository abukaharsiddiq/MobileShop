<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Branch/warehouse er moddhe stock transfer.
 *  requested -> approved -> in_transit -> (partially_received ->) received | discrepancy
 *  requested/approved -> rejected | cancelled (stock chhoy na)
 * discrepancy = bhanga ba na pouchhano (missing) maal chilo. Status shudhu StockTransferService bodlay.
 */
class StockTransfer extends Model
{
    const REQUESTED = 'requested';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    const CANCELLED = 'cancelled';
    const IN_TRANSIT = 'in_transit';
    const PARTIAL = 'partially_received';
    const RECEIVED = 'received';
    const DISCREPANCY = 'discrepancy';

    const LABELS = [
        self::REQUESTED   => 'Requested',
        self::APPROVED    => 'Approved - ready to dispatch',
        self::REJECTED    => 'Rejected',
        self::CANCELLED   => 'Cancelled',
        self::IN_TRANSIT  => 'In transit',
        self::PARTIAL     => 'Partially received',
        self::RECEIVED    => 'Received',
        self::DISCREPANCY => 'Received with discrepancy',
    ];

    // pothe maal ache (receive baki)
    const OPEN_TRANSIT = [self::IN_TRANSIT, self::PARTIAL];
    const CLOSED = [self::REJECTED, self::CANCELLED, self::RECEIVED, self::DISCREPANCY];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'approved_at'   => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at'   => 'datetime',
            'closed_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Stock transfers cannot be deleted. Cancel or reject them instead.'));
    }

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(StockTransferEvent::class)->orderBy('id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatcher()
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // user source ba destination er kono ekta dhorte parle dekhte pare
    public function scopeAccessibleBy($query, User $user)
    {
        $ids = $user->allowedBranchIds();

        return $ids === null ? $query : $query->where(fn ($q) => $q->whereIn('from_branch_id', $ids)->orWhereIn('to_branch_id', $ids));
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? ucfirst($this->status);
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::REQUESTED   => 'secondary',
            self::APPROVED    => 'primary',
            self::IN_TRANSIT, self::PARTIAL => 'info',
            self::RECEIVED    => 'success',
            self::DISCREPANCY => 'warning',
            default           => 'dark',
        };
    }

    public function inTransitQty(): int
    {
        return (int) $this->items->sum(fn (StockTransferItem $i) => $i->inTransit());
    }
}
