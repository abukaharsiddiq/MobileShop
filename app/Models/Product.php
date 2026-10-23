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

    const TYPES = [
        'smart_phone'       => 'Smart Phone',
        'used_phone'        => 'Used Smart Phone',
        'refurbished_phone' => 'Refurbished Smart Phone',
        'button_phone'      => 'Button Phone',
        'accessory'         => 'Accessories',
    ];

    // IMEI chara kena-becha hobe na
    const IMEI_REQUIRED_TYPES = ['smart_phone', 'used_phone', 'refurbished_phone'];

    // Bikri hoye jawa IMEI abar kena jay shudhu ei type e (purono phone hisebe)
    const SECOND_HAND_TYPES = ['used_phone', 'refurbished_phone'];

    public function typeLabel()
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    // Smart phone (notun, used, refurbished) kena-becha IMEI chara hobe na
    public function requiresImei()
    {
        return in_array($this->type, self::IMEI_REQUIRED_TYPES);
    }

    public function isSecondHand()
    {
        return in_array($this->type, self::SECOND_HAND_TYPES);
    }

    // Accessories er IMEI thake na; button phone er IMEI oichchhik
    public function allowsImei()
    {
        return $this->type !== 'accessory';
    }
}
