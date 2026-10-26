<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    protected $fillable = [
        'income_no', 'income_date', 'income_account_id', 'account_id', 'amount', 'received_from', 'note',
        'request_token', 'status', 'journal_entry_id', 'created_by', 'reversed_at', 'reverse_reason',
    ];

    protected function casts(): array
    {
        return [
            'income_date' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    public function incomeAccount()
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
