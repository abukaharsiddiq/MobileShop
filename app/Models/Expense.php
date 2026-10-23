<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
    'category_id',
    'expense_date',
    'amount',
    'payment_method',
    'note',
    'created_by',
];


    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }
    
}
