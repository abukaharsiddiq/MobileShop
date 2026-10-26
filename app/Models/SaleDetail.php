<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleDetail extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'main_stock_id',
        'ram_id',
        'rom_id',
        'color_id',
        'quantity',
        'sale_price',
        'purchase_price',
        'item_discount',
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

    // public function imeis() {
    //    return $this->hasMany(ProductImei::class, 'product_id', 'product_id')
    //             ->where('sale_id', $this->sale_id);
    // }

public function imeis123() {
    // শুধুমাত্র product_id দিয়ে কানেক্ট করুন
    return $this->hasMany(ProductImei::class, 'product_id', 'product_id');
}
     public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

// ইনভয়েসে দেখানোর জন্য কমা দিয়ে আলাদা করা IMEI স্ট্রিং
public function getImeiStringAttribute() {
    return $this->imeis()->get()->pluck('imei_no')->implode(', ');
}

public function imeis() {
    return $this->hasMany(ProductImei::class, 'sale_detail_id', 'id');
}


}
