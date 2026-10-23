<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MainStock extends Model
{
    protected $fillable = [
        'category_id',
        'product_id',
        'ram_id',
        'rom_id',
        'color_id',
        'quantity',
        'average_purchase_price',
        'last_purchase_price',
        'sale_price',
    ];

    /**
     * Relation: MainStock belongs to Product
     */
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
