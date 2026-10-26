<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseDetail extends Model
{
     protected $fillable = [
        'purchase_id',
        'product_id',
        'main_stock_id',
        'ram_id',
        'rom_id',
        'color_id',
        'quantity',
        'purchase_price',
        'unit_discount',   // line er mot discount (taka)
        'unit_total',      // qty x price - discount
        'tax_amount',      // invoice tax er ei line er bhag
        'additional_cost', // invoice discount + additional cost er ei line er bhag (- hole kom)
        'landed_cost',     // protiti unit er asol kena dam (average cost er jonno)
        'imei_list',       // draft/pending: ek line e ek phone ("IMEI1" ba "IMEI1/IMEI2")
    ];

    protected function casts(): array
    {
        return ['landed_cost' => 'decimal:4'];
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
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

    // ei line e kena phone (approve er por)
    public function devices()
    {
        return $this->hasMany(ProductImei::class, 'purchase_detail_id');
    }

    // purono naam (view e use hoto) - ager relation bhul column e milato
    public function product_imeis()
    {
        return $this->devices();
    }

    public function returnDetails()
    {
        return $this->hasMany(PurchaseReturnDetail::class);
    }

    // draft/pending er IMEI token, ba approve er por kena phone gulo
    public function imeiTokens(): array
    {
        if ($this->relationLoaded('devices') ? $this->devices->isNotEmpty() : $this->devices()->exists()) {
            return $this->devices->map(fn ($d) => $d->imei_no_2 ? "{$d->imei_no}/{$d->imei_no_2}" : $d->imei_no)->all();
        }

        return array_values(array_filter(preg_split('/\R/', (string) $this->imei_list)));
    }

    public function landedUnitCost(): float
    {
        return round((float) ($this->landed_cost ?? $this->purchase_price), 4);
    }
}
