<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Transfer e pathano IMEI wala phone: in_transit -> received | damaged | missing
class StockTransferDevice extends Model
{
    const IN_TRANSIT = 'in_transit';
    const RECEIVED = 'received';
    const DAMAGED = 'damaged';
    const MISSING = 'missing';

    protected $guarded = ['id'];

    public function item()
    {
        return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id');
    }

    public function device()
    {
        return $this->belongsTo(ProductImei::class, 'product_imei_id');
    }
}
