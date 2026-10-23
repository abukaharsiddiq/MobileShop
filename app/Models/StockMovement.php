<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Stock ledger: main_stocks.quantity er protiti poriborton
class StockMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'main_stock_id', 'product_id', 'branch_id', 'type', 'quantity', 'balance_after',
        'reference_type', 'reference_id', 'reference_no', 'user_id', 'note',
    ];

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
