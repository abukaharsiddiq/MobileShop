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

    public function imeis()
    {
        return $this->hasMany(ProductImei::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    // "Galaxy A15 (6GB / 128GB / Black)"
    public function variantLabel()
    {
        $parts = array_filter([
            $this->ram->ram_name ?? null,
            $this->rom->rom_name ?? null,
            $this->color->color_name ?? null,
        ]);

        $name = $this->product->product_name ?? '';

        return $parts ? $name . ' (' . implode(' / ', $parts) . ')' : $name;
    }

}
