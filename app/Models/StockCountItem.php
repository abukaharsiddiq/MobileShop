<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountItem extends Model
{
    protected $fillable = [
        'stock_count_id', 'main_stock_id', 'uses_imei', 'system_qty', 'counted_qty', 'variance',
        'scanned_imeis', 'missing_imeis', 'unexpected_imeis', 'note',
    ];

    protected function casts(): array
    {
        return [
            'uses_imei'        => 'boolean',
            'scanned_imeis'    => 'array',
            'missing_imeis'    => 'array',
            'unexpected_imeis' => 'array',
        ];
    }

    public function stockCount()
    {
        return $this->belongsTo(StockCount::class);
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }
}
