<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Transfer er ekta variant: chaoa -> approve -> pathano -> (bhalo + bhanga + missing) = pathano hole shesh
class StockTransferItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_qty'  => 'integer',
            'approved_qty'   => 'integer',
            'dispatched_qty' => 'integer',
            'received_qty'   => 'integer',
            'damaged_qty'    => 'integer',
            'missing_qty'    => 'integer',
            'unit_cost'      => 'decimal:2',
        ];
    }

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function devices()
    {
        return $this->hasMany(StockTransferDevice::class)->orderBy('id');
    }

    // pathano hoyechhe kintu ekhono pouchhay ni / missing dhora hoy ni
    public function inTransit(): int
    {
        return max(0, $this->dispatched_qty - $this->received_qty - $this->damaged_qty - $this->missing_qty);
    }

    public function plannedQty(): int
    {
        return (int) ($this->approved_qty ?? $this->requested_qty);
    }
}
