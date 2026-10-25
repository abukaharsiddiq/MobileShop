<?php

namespace App\Models;

use App\Services\StockService;
use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    const UPDATED_AT = null;

    const SALE_PRICE = 'sale_price';
    const PURCHASE_PRICE = 'purchase_price';   // product er default kena dam
    const AVERAGE_COST = 'average_cost';       // variant er average kena dam (purchase e bodlay)
    const LAST_COST = 'last_cost';             // variant er shesh kena dam

    protected $fillable = [
        'product_id', 'main_stock_id', 'price_type', 'old_price', 'new_price',
        'source', 'reference_type', 'reference_id', 'reference_no', 'user_id',
    ];

    protected function casts(): array
    {
        return ['old_price' => 'decimal:2', 'new_price' => 'decimal:2'];
    }

    /**
     * Dam bodlale ekta line; ekoi dam hole kichu likhe na.
     */
    public static function record(Product $product, ?MainStock $stock, string $type, $old, $new, string $source, ?Model $reference = null): ?self
    {
        if ($old !== null && round((float) $old, 2) === round((float) $new, 2)) {
            return null;
        }

        return static::create([
            'product_id'     => $product->id,
            'main_stock_id'  => $stock?->id,
            'price_type'     => $type,
            'old_price'      => $old,
            'new_price'      => $new,
            'source'         => $source,
            'reference_type' => $reference ? class_basename($reference) : null,
            'reference_id'   => $reference?->getKey(),
            'reference_no'   => StockService::referenceNo($reference),
            'user_id'        => auth()->id(),
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }
}
