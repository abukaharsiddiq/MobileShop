<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    const DRAFT = 'draft';
    const COMPLETED = 'completed';

    protected $fillable = [
        'account_id', 'statement_date', 'statement_balance', 'cleared_balance', 'book_balance', 'difference',
        'status', 'note', 'created_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'completed_at'   => 'datetime',
        ];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'reconciliation_id');
    }
}
