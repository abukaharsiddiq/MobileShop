<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Used phone er itihash (entry, approval, refurbishment khoroch, QC, dam bodlano). Bodlano/muchha jay na.
class UsedPhoneEvent extends Model
{
    const UPDATED_AT = null;

    const ENTERED = 'entered';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    const REFURB_STARTED = 'refurb_started';
    const COST = 'cost';
    const REFURB_PASSED = 'refurb_passed';
    const REFURB_FAILED = 'refurb_failed';
    const PRICING = 'pricing';

    const LABELS = [
        self::ENTERED        => 'Entered & inspected',
        self::APPROVED       => 'Purchase approved',
        self::REJECTED       => 'Rejected',
        self::REFURB_STARTED => 'Sent to refurbishment',
        self::COST           => 'Refurbishment cost',
        self::REFURB_PASSED  => 'Refurbishment passed QC',
        self::REFURB_FAILED  => 'Refurbishment failed QC',
        self::PRICING        => 'Resale price / warranty',
    ];

    protected $fillable = ['used_phone_id', 'event', 'amount', 'account_id', 'note', 'request_token', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Used phone history cannot be edited.'));
        static::deleting(fn () => throw new \LogicException('Used phone history cannot be deleted.'));
    }

    public function usedPhone()
    {
        return $this->belongsTo(UsedPhone::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->event] ?? ucfirst(str_replace('_', ' ', $this->event));
    }
}
