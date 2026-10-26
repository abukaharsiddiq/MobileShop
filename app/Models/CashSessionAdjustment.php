<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Approve kora closing er pore bhul dhora porle: approve hole tobei hishab (journal) bodlay
class CashSessionAdjustment extends Model
{
    const PENDING = 'pending';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';

    protected $fillable = [
        'cash_session_id', 'amount', 'reason', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function session()
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
