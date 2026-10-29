<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'expense_no',
        'category_id',
        'expense_date',
        'amount',
        'payment_method',
        'account_id',
        'branch_id',
        'note',
        'request_token',
        'status',
        'journal_entry_id',
        'created_by',
        'reversed_at',
        'reverse_reason',
    ];

    protected function casts(): array
    {
        return [
            'reversed_at' => 'datetime',
        ];
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    // Report e shudhu bortoman (reverse na kora) expense
    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }
}
