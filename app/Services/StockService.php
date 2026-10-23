<?php

namespace App\Services;

use App\Models\MainStock;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;

class StockService
{
    const PURCHASE = 'purchase';
    const PURCHASE_DELETED = 'purchase_deleted';
    const PURCHASE_RETURN = 'purchase_return';
    const SALE = 'sale';
    const SALE_RETURN = 'sale_return';
    const ADJUSTMENT = 'adjustment';
    const OPENING = 'opening';

    /**
     * main_stocks.quantity bodlay ar stock_movements e ledger entry rakhe.
     * Caller ke age stock row lockForUpdate kore nite hobe ar DB transaction er bhitore dakte hobe.
     * Stock e ager kono unsaved poriborton (jemon average price) thakle setao ekshathe save hoy.
     */
    public function move(MainStock $stock, int $quantity, string $type, ?Model $reference = null, ?string $note = null, ?int $branchId = null): StockMovement
    {
        $balance = $stock->quantity + $quantity;

        if ($balance < 0) {
            throw new \Exception("Stock cannot go below zero for {$stock->variantLabel()} (current {$stock->quantity}, change {$quantity}).");
        }

        $stock->quantity = $balance;
        $stock->save();

        return StockMovement::create([
            'main_stock_id'  => $stock->id,
            'product_id'     => $stock->product_id,
            'branch_id'      => $branchId,
            'type'           => $type,
            'quantity'       => $quantity,
            'balance_after'  => $balance,
            'reference_type' => $reference ? class_basename($reference) : null,
            'reference_id'   => $reference?->getKey(),
            'reference_no'   => self::referenceNo($reference),
            'user_id'        => auth()->id(),
            'note'           => $note,
        ]);
    }

    public static function referenceNo(?Model $reference): ?string
    {
        if (!$reference) {
            return null;
        }

        return $reference->invoice_no ?? $reference->return_no ?? $reference->adjustment_no ?? null;
    }
}
