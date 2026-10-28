<?php

namespace App\Models;

use App\Services\StockService;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock ledger: main_stocks.quantity er protiti poriborton. Shudhu StockService likhe;
 * line bodlano ba muchha jay na (bhul hole ulto line - adjustment/return/cancel).
 */
class StockMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'main_stock_id', 'product_id', 'branch_id', 'type', 'quantity', 'balance_after',
        'reference_type', 'reference_id', 'reference_no', 'user_id', 'note', 'idempotency_key',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stock history cannot be edited. Record a correcting movement instead.'));
        static::deleting(fn () => throw new \LogicException('Stock history cannot be deleted.'));
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return StockService::label($this->type);
    }

    // reference document er page (thakle)
    public function referenceUrl(): ?string
    {
        $routes = [
            'Purchase'        => 'purchase.show',
            'Sale'            => 'sale.show',
            'PurchaseReturn'  => 'purchase-return.show',
            'SaleReturn'      => 'sale-return.show',
            'StockAdjustment' => 'stock-reconciliation.show',
            'StockCount'      => 'stock-count.show',
            'UsedPhone'       => 'used-phone.show',
            'RepairJob'       => 'repair.show',
        ];
        $route = $routes[$this->reference_type] ?? null;

        return $route && $this->reference_id && \Route::has($route) ? route($route, $this->reference_id) : null;
    }
}
