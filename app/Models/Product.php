<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'type',           // mobile / accessory
        'product_name',
        'model_number',
        'barcode',
        'category_id',
        'brand_id',
        'ram_id',
        'rom_id',
        'color_id',
        'purchase_price',
        'sale_price',
        'unit',
        'opening_quantity',
        'status',
    ];


    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
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

    public function imeis()
{
    return $this->hasMany(ProductImei::class, 'product_id', 'id');
}
}
