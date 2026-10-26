<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountTransfer extends Model
{
    protected $fillable = [
        'transfer_no', 'transfer_date', 'from_account_id', 'to_account_id', 'amount', 'reference', 'note',
        'request_token', 'status', 'journal_entry_id', 'created_by', 'reversed_at', 'reverse_reason',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'reversed_at'   => 'datetime',
        ];
    }

    public function fromAccount()
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }
}
