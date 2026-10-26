<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'account_id', 'status'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function expenses()
{
    return $this->hasMany(Expense::class, 'category_id');
}

}
