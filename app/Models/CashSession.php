<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cashier er ek diner cash drawer. Status: open (cash neya/deya hoy) -> closed (gona joma, review er opekkhay)
 * -> approved (shesh; parthokko post hoy). Manager "recount" e pathale shudhu abar gona jay; "reopen" e abar open.
 */
class CashSession extends Model
{
    const OPEN = 'open';
    const CLOSED = 'closed';
    const RECOUNT = 'recount';
    const APPROVED = 'approved';

    // Ei event gulo boi er hishab, drawer e taka asa-jawa na (cash account er opening, closing er parthokko ...)
    const NON_DRAWER_EVENTS = ['opening_balance', 'cash_count_difference', 'cash_count_adjustment', 'legacy_adjustment'];

    // open_user database e hishab hoy (generated column)
    protected $guarded = ['id', 'open_user'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opened_at'     => 'datetime',
            'closed_at'     => 'datetime',
            'reviewed_at'   => 'datetime',
            'denominations' => 'array',
        ];
    }

    public static function statuses(): array
    {
        return [self::OPEN => 'Open', self::CLOSED => 'Closed - awaiting review', self::RECOUNT => 'Recount requested', self::APPROVED => 'Approved'];
    }

    /**
     * Cash account e posting kon session e jabe (null = kono session na). Niyom:
     *  - cashier er ajker open session -> oi session
     *  - ager diner session ekhono open -> atkay (age seta close)
     *  - ajker session bondho (closed/recount/approved) -> atkay (manager reopen korte pare)
     *  - session nei: config('cash.require_open_session') true hole atkay, na hole null
     * Session row lock kora hoy, tai close ar posting ekshathe hole ekta age, onnota pore (bondho dekhe atke).
     */
    public static function forCashPosting(User $user): ?self
    {
        $today = now()->toDateString();

        $open = static::where('user_id', $user->id)->where('status', self::OPEN)->lockForUpdate()->first();
        if ($open) {
            if ($open->business_date->toDateString() !== $today) {
                throw new \Exception("Your cash session {$open->session_no} of {$open->business_date->format('d-m-Y')} is still open. Close it before taking or paying cash.");
            }

            return $open;
        }

        $todays = static::where('user_id', $user->id)->where('business_date', $today)->lockForUpdate()->first();
        if ($todays) {
            throw new \Exception("Your cash session {$todays->session_no} for today is {$todays->statusLabel()}. Ask a manager to reopen it before taking or paying cash.");
        }

        if (config('cash.require_open_session')) {
            throw new \Exception('Open your cash session (opening cash count) before taking or paying cash.');
        }

        return null;
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function entries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function adjustments()
    {
        return $this->hasMany(CashSessionAdjustment::class);
    }

    // Cashier nije, ba 'read cash closing' thaka (branch access shoho)
    public function visibleTo(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id
            || ($user->can('read cash closing') && $user->canAccessBranch($this->branch_id));
    }
}
