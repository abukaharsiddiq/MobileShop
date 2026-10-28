<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Transfer er itihash (request, approve, dispatch, protiti receive, missing). Bodlano/muchha jay na.
class StockTransferEvent extends Model
{
    const UPDATED_AT = null;

    const LABELS = [
        'requested'  => 'Requested',
        'approved'   => 'Approved',
        'rejected'   => 'Rejected',
        'cancelled'  => 'Cancelled',
        'dispatched' => 'Dispatched (in transit)',
        'received'   => 'Received',
        'missing'    => 'Marked missing (lost in transit)',
    ];

    protected $fillable = ['stock_transfer_id', 'event', 'note', 'lines', 'request_token', 'user_id'];

    protected function casts(): array
    {
        return ['lines' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Transfer history cannot be edited.'));
        static::deleting(fn () => throw new \LogicException('Transfer history cannot be deleted.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->event] ?? ucfirst($this->event);
    }
}
