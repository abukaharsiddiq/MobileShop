<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MainStock;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Stock er ekmatro dorja: main_stocks.quantity bodlay ar stock_movements e ledger line rakhe.
 *
 * - Protiti movement er reference document lage (purchase, sale, return, adjustment, transfer, count).
 * - Stock row database theke lock kore abar pora hoy - caller er object purono holeo bhul hishab hoy na
 *   (ekshathe duita sale eki stock ke kombe na).
 * - Negative stock hoy na, jodi na config('inventory.allow_negative_stock') true ar product IMEI chara hoy.
 * - $key (idempotency): ekoi kaj abar process hole notun movement na, agerta ferot (quantity bodlay na).
 * - Ledger line bodlano/muchha jay na (StockMovement model).
 *
 * DB transaction er bhitore dakte hobe.
 */
class StockService
{
    const OPENING = 'opening';
    const PURCHASE = 'purchase';
    const PURCHASE_DELETED = 'purchase_deleted';   // approved purchase cancel
    const PURCHASE_RETURN = 'purchase_return';
    const SALE = 'sale';
    const SALE_RETURN = 'sale_return';
    const EXCHANGE_IN = 'exchange_in';             // sale exchange: customer er ferot phone (Sales module)
    const EXCHANGE_OUT = 'exchange_out';           // sale exchange: notun phone
    const TRANSFER_OUT = 'transfer_out';
    const TRANSFER_IN = 'transfer_in';
    const ADJUSTMENT = 'adjustment';
    const DAMAGED = 'damaged';
    const LOST = 'lost';
    const STOCK_COUNT = 'stock_count';

    const LABELS = [
        self::OPENING          => 'Opening Stock',
        self::PURCHASE         => 'Purchase',
        self::PURCHASE_DELETED => 'Purchase Cancelled',
        self::PURCHASE_RETURN  => 'Purchase Return',
        self::SALE             => 'Sale',
        self::SALE_RETURN      => 'Sales Return',
        self::EXCHANGE_IN      => 'Exchange In',
        self::EXCHANGE_OUT     => 'Exchange Out',
        self::TRANSFER_OUT     => 'Transfer Out',
        self::TRANSFER_IN      => 'Transfer In',
        self::ADJUSTMENT       => 'Adjustment',
        self::DAMAGED          => 'Damaged',
        self::LOST             => 'Lost',
        self::STOCK_COUNT      => 'Stock Count',
    ];

    public function move(MainStock $stock, int $quantity, string $type, ?Model $reference = null, ?string $note = null,
                         ?int $branchId = null, ?string $key = null): StockMovement
    {
        if (!$reference || !$reference->getKey()) {
            throw new \LogicException("Stock movement ({$type}) needs a reference document.");
        }
        if ($quantity === 0) {
            throw new \LogicException('A stock movement must change the quantity.');
        }
        if ($key && ($existing = StockMovement::where('idempotency_key', $key)->first())) {
            return $existing;
        }

        // database er man, lock shoho (object purono ba bhul holeo)
        $current = (int) MainStock::whereKey($stock->id)->lockForUpdate()->value('quantity');
        $balance = $current + $quantity;

        if ($balance < 0 && !self::allowsNegative($stock)) {
            throw new \Exception("Stock cannot go below zero for {$stock->variantLabel()} (current {$current}, change {$quantity})." . self::negativeHint($stock));
        }

        // shudhu quantity database er man e milano; ager unsaved poriborton (average price) o save hoy
        $stock->quantity = $current;
        $stock->syncOriginalAttribute('quantity');
        $stock->quantity = $balance;
        MainStock::withQuantityUnlocked(fn () => $stock->save());

        return StockMovement::create([
            'main_stock_id'   => $stock->id,
            'product_id'      => $stock->product_id,
            'branch_id'       => $branchId ?? Branch::currentId(),
            'type'            => $type,
            'quantity'        => $quantity,
            'balance_after'   => $balance,
            'reference_type'  => class_basename($reference),
            'reference_id'    => $reference->getKey(),
            'reference_no'    => self::referenceNo($reference),
            'user_id'         => auth()->id(),
            'note'            => $note,
            'idempotency_key' => $key,
        ]);
    }

    /**
     * Ek branch theke arek branch: mot stock bodlay na, duita line (out -, in +) jate branch er hishab mele.
     * Source branch e jotota ache tar beshi na ($checkBalance false: IMEI wala phone - phone er branch e hishab, caller jachai kore).
     */
    public function transfer(MainStock $stock, int $quantity, int $fromBranchId, int $toBranchId, Model $reference, ?string $note = null,
                             ?string $key = null, bool $checkBalance = true): array
    {
        if ($quantity <= 0) {
            throw new \Exception('Transfer quantity must be at least 1.');
        }
        if ($fromBranchId === $toBranchId) {
            throw new \Exception('Choose a different branch to transfer to.');
        }
        if (!$reference->getKey()) {
            throw new \LogicException('Stock transfer needs a reference document.');
        }
        if ($key && StockMovement::where('idempotency_key', "{$key}:out")->exists()) {
            return StockMovement::whereIn('idempotency_key', ["{$key}:out", "{$key}:in"])->orderBy('id')->get()->all();
        }

        $total = (int) MainStock::whereKey($stock->id)->lockForUpdate()->value('quantity');
        $available = self::branchBalance($stock->id, $fromBranchId);
        if ($checkBalance && $quantity > $available) {
            throw new \Exception("{$stock->variantLabel()}: only {$available} in the source branch, cannot transfer {$quantity}.");
        }

        $line = fn (int $qty, int $branch, string $type, string $suffix) => StockMovement::create([
            'main_stock_id'   => $stock->id,
            'product_id'      => $stock->product_id,
            'branch_id'       => $branch,
            'type'            => $type,
            'quantity'        => $qty,
            'balance_after'   => $total,   // mot stock (branch er na)
            'reference_type'  => class_basename($reference),
            'reference_id'    => $reference->getKey(),
            'reference_no'    => self::referenceNo($reference),
            'user_id'         => auth()->id(),
            'note'            => $note,
            'idempotency_key' => $key ? "{$key}:{$suffix}" : null,
        ]);

        return [$line(-$quantity, $fromBranchId, self::TRANSFER_OUT, 'out'), $line($quantity, $toBranchId, self::TRANSFER_IN, 'in')];
    }

    const NEGATIVE_CONSTRAINT = 'main_stocks_quantity_non_negative';

    private static ?bool $constraintActive = null;

    /**
     * IMEI chara product e negative stock: config('inventory.allow_negative_stock') ar database er
     * CHECK constraint bondho (php artisan inventory:negative-stock enable) - duitai lage.
     * IMEI wala phone kokhono na.
     */
    public static function allowsNegative(MainStock $stock): bool
    {
        return (bool) config('inventory.allow_negative_stock') && $stock->product && !$stock->product->allowsImei()
            && !self::negativeConstraintActive();
    }

    // config chalu kintu database ekhono atkay - user ke ki korte hobe
    public static function negativeHint(MainStock $stock): string
    {
        return config('inventory.allow_negative_stock') && $stock->product && !$stock->product->allowsImei() && self::negativeConstraintActive()
            ? ' Negative stock is enabled in settings but the database still blocks it: run php artisan inventory:negative-stock enable.'
            : '';
    }

    public static function negativeConstraintActive(bool $fresh = false): bool
    {
        if ($fresh || self::$constraintActive === null) {
            self::$constraintActive = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'main_stocks')
                ->where('CONSTRAINT_NAME', self::NEGATIVE_CONSTRAINT)
                ->exists();
        }

        return self::$constraintActive;
    }

    /**
     * Branch er stock = oi branch er ledger line er jogfol. Branch chara purono line default branch er.
     */
    public static function branchBalance(int $stockId, int $branchId): int
    {
        return (int) self::branchBalances([$stockId])->get($stockId . ':' . $branchId, 0);
    }

    /** @return \Illuminate\Support\Collection<string, int> "stockId:branchId" => quantity */
    public static function branchBalances(?array $stockIds = null, ?int $branchId = null)
    {
        $default = (int) Branch::where('is_default', 1)->value('id');
        $branch = DB::raw("COALESCE(branch_id, {$default})");

        return StockMovement::query()
            ->when($stockIds !== null, fn ($q) => $q->whereIn('main_stock_id', $stockIds ?: [0]))
            ->when($branchId, fn ($q) => $q->whereRaw("COALESCE(branch_id, {$default}) = ?", [$branchId]))
            ->groupBy('main_stock_id', $branch)
            ->select('main_stock_id', DB::raw("COALESCE(branch_id, {$default}) as branch_key"), DB::raw('SUM(quantity) as qty'))
            ->get()
            ->mapWithKeys(fn ($r) => [$r->main_stock_id . ':' . $r->branch_key => (int) $r->qty]);
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    public static function referenceNo(?Model $reference): ?string
    {
        if (!$reference) {
            return null;
        }

        return $reference->invoice_no ?? $reference->return_no ?? $reference->adjustment_no ?? $reference->count_no ?? null;
    }
}
