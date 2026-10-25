<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Variant: ekta product er ekta RAM + ROM + color combination ar tar stock.
 * Quantity shudhu StockService::move() diye bodlay (purchase, sale, return, transfer, adjustment);
 * onno kothao theke bodlate gele exception.
 */
class MainStock extends Model
{
    protected $fillable = [
        'category_id',
        'product_id',
        'ram_id',
        'rom_id',
        'color_id',
        'sku',
        'barcode',
        'quantity',
        'average_purchase_price',
        'last_purchase_price',
        'sale_price',
        'status',
        'low_stock_threshold',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'low_stock_threshold' => 'integer',
        ];
    }

    private static bool $quantityUnlocked = false;

    protected static function booted(): void
    {
        static::saving(function (MainStock $stock) {
            if (self::$quantityUnlocked) {
                return;
            }

            $changed = $stock->exists ? $stock->isDirty('quantity') : (int) $stock->quantity !== 0;
            if ($changed) {
                throw new \LogicException('Stock quantity can only change through purchase, sale, return, transfer or adjustment (StockService).');
            }
        });
    }

    // StockService er bhitore quantity bodlano jay
    public static function withQuantityUnlocked(callable $callback)
    {
        $previous = self::$quantityUnlocked;
        self::$quantityUnlocked = true;

        try {
            return $callback();
        } finally {
            self::$quantityUnlocked = $previous;
        }
    }

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

    public function priceHistories()
    {
        return $this->hasMany(PriceHistory::class);
    }

    // shudhu active variant jar product o active
    public function scopeActive($query)
    {
        return $query->where('main_stocks.status', 1)->whereHas('product', fn ($q) => $q->where('status', 1));
    }

    // variant -> product -> config default
    public function lowStockThreshold(): int
    {
        return $this->low_stock_threshold
            ?? $this->product?->low_stock_threshold
            ?? (int) config('inventory.low_stock_threshold');
    }

    // quantity threshold er soman/kom (0 o low; report e "Out of Stock" alada dekhay)
    public function isLowStock(): bool
    {
        return $this->quantity <= $this->lowStockThreshold();
    }

    // SQL e ekoi niyom (dashboard/report er filter); products join lage na
    public static function thresholdSql(): string
    {
        return 'COALESCE(main_stocks.low_stock_threshold, (SELECT p.low_stock_threshold FROM products p WHERE p.id = main_stocks.product_id), '
            . (int) config('inventory.low_stock_threshold') . ')';
    }

    // 1 theke threshold porjonto (0 = out of stock alada)
    public function scopeLowStock($query)
    {
        return $query->where('main_stocks.quantity', '>', 0)->whereRaw('main_stocks.quantity <= ' . self::thresholdSql());
    }

    public function scopeAboveThreshold($query)
    {
        return $query->whereRaw('main_stocks.quantity > ' . self::thresholdSql());
    }

    public function isSellable(): bool
    {
        return $this->status && $this->product?->status;
    }

    // "RAM / ROM / Color"
    public function attributeLabel(): string
    {
        return implode(' / ', array_filter([
            $this->ram->ram_name ?? null,
            $this->rom->rom_name ?? null,
            $this->color->color_name ?? null,
        ])) ?: 'Standard';
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
