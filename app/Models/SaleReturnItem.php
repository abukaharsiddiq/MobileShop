<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturnItem extends Model
{
    // inspection: good = abar bikri jogyo (available); damaged; needs_repair (repair e)
    const CONDITIONS = [
        'good'         => 'Good - back to stock',
        'damaged'      => 'Damaged - not for sale',
        'needs_repair' => 'Needs repair',
    ];

    protected $fillable = [
        'sale_return_id',
        'sale_detail_id',
        'quantity',
        'product_id',
        'main_stock_id',
        'imei_no',
        'product_imei_id',
        'refund_price',
        'condition',
        'resale_eligible',
        'inspection_note',
    ];

    protected function casts(): array
    {
        return ['resale_eligible' => 'boolean'];
    }

     public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleDetail()
    {
        return $this->belongsTo(SaleDetail::class);
    }

    public function device()
    {
        return $this->belongsTo(ProductImei::class, 'product_imei_id');
    }

    public function conditionLabel(): string
    {
        return self::CONDITIONS[$this->condition] ?? $this->condition;
    }
}
