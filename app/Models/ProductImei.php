<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImei extends Model
{
    protected $fillable = [
        'purchase_id',
        'sale_id',
        'sale_detail_id',
        'product_id',
        'imei_no',
        'status',
    ];

    // Relationship to Product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function saleDetail() {
       return $this->belongsTo(SaleDetail::class, 'sale_detail_id');
    }

}
