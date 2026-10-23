<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseDetail extends Model
{
     protected $fillable = [
        'purchase_id',
        'product_id',
        'ram_id',
        'rom_id',
        'color_id',
        'quantity',
        'purchase_price',
        'unit_discount',
        'unit_total',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

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

    public function product_imeis()
{
    return $this->hasMany(ProductImei::class, 'purchase_id', 'id');
}

}
