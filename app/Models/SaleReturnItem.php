<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'sale_return_id',
        'sale_detail_id',
        'quantity',
        'product_id',
        'imei_no',
        'refund_price',
    ];

     public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
}
