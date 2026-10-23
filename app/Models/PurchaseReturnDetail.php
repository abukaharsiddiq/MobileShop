<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnDetail extends Model
{
    protected $fillable = [
        'purchase_return_id',
        'product_id',
        'ram_id',
        'rom_id',
        'color_id',
        'quantity',
        'purchase_price',
        'unit_total',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function ram()
    {
        return $this->belongsTo(Ram::class);
    }

    public function rom()
    {
        return $this->belongsTo(Rom::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }
}
