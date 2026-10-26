<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit', 'credit', 'party_type', 'party_id', 'memo',
        'reconciliation_id', 'cleared_date',
    ];

    protected function casts(): array
    {
        return [
            'cleared_date' => 'date',
        ];
    }

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
