<?php

namespace App\Services;

use App\Models\MainStock;
use App\Models\PriceHistory;
use App\Models\ProductImei;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetail;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Supplier ke mal ferot: stock kome, IMEI "returned to supplier", baki stock er average cost thik hoy,
 * supplier ledger e debit (dena kome / agrim), ar chaile supplier er ferot deya taka (refund) -
 * shob ekta transaction e (DB transaction er bhitore dakte hobe).
 *
 * Niyom: stock er beshi na; invoice dile oi invoice er baki qty r beshi na ar IMEI oi invoice er;
 * ferot er dam kena dam (landed cost) er beshi na; refund supplier er kache thaka agrim er beshi na.
 */
class PurchaseReturnService
{
    public function __construct(
        private ImeiService $imeis,
        private StockService $stock,
        private SupplierAccountService $accounts,
    ) {}

    /**
     * $input: supplier_id, return_date, return_no?, purchase_id?, note?, stock_ids[], qtys[], prices[], imeis[][]?,
     *         refund_amount?, refund_method?
     */
    public function create(array $input, User $user): PurchaseReturn
    {
        $supplier = Supplier::lockForUpdate()->findOrFail($input['supplier_id']);

        $returnNo = trim((string) ($input['return_no'] ?? '')) ?: null;
        if ($returnNo && PurchaseReturn::where('return_no', $returnNo)->exists()) {
            throw new \Exception("Return number {$returnNo} is already used.");
        }

        $purchase = null;
        if (!empty($input['purchase_id'])) {
            $purchase = Purchase::lockForUpdate()->find($input['purchase_id']);
            if (!$purchase || (int) $purchase->supplier_id !== (int) $supplier->id) {
                throw new \Exception('The selected invoice does not belong to ' . $supplier->name . '.');
            }
            if (!$purchase->isApproved()) {
                throw new \Exception("Invoice {$purchase->invoice_no} is {$purchase->statusLabel()}; only approved purchases can be returned.");
            }
            if ($input['return_date'] < substr((string) $purchase->purchase_date, 0, 10)) {
                throw new \Exception("The return date cannot be before the purchase date of {$purchase->invoice_no}.");
            }
        }

        $return = PurchaseReturn::create([
            'supplier_id'  => $supplier->id,
            'purchase_id'  => $purchase?->id,
            'return_no'    => $returnNo ?? 'PRN-' . Str::uuid(),
            'return_date'  => $input['return_date'],
            'total_amount' => 0,
            'note'         => $input['note'] ?? null,
            'created_by'   => $user->id,
        ]);
        if (!$returnNo) {
            $return->update(['return_no' => 'PRN-' . str_pad((string) $return->id, 6, '0', STR_PAD_LEFT)]);
        }

        $total = 0;
        $stockIds = (array) ($input['stock_ids'] ?? []);
        if (!$stockIds) {
            throw new \Exception('Add at least one product to return.');
        }

        foreach ($stockIds as $key => $stockId) {
            $qty = (int) ($input['qtys'][$key] ?? 0);
            $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($stockId);
            $label = $stock->variantLabel();

            if ($qty < 1) {
                throw new \Exception("{$label}: return quantity must be at least 1.");
            }
            if ($qty > $stock->quantity) {
                throw new \Exception("Not enough stock for {$label}. Available: {$stock->quantity}, return: {$qty}.");
            }

            $devices = $this->devices($input['imeis'][$key] ?? [], $stock, $qty, $purchase, $user, $label);

            // kon kena line theke: invoice dile oi invoice er line; IMEI hole tar line
            $source = $purchase ? $this->purchaseLine($purchase, $stock, $qty, $label) : null;
            $cap = $this->costCap($source, $devices, $stock);

            $price = round((float) ($input['prices'][$key] ?? 0), 2);
            if ($price < 0) {
                throw new \Exception("{$label}: return price cannot be negative.");
            }
            if ($cap > 0 && $price > $cap + 0.005) {
                throw new \Exception("{$label}: return price " . number_format($price, 2) . ' is more than its cost ' . number_format($cap, 2) . '.');
            }

            foreach ($devices as $imei) {
                $this->imeis->returnToSupplier($imei, $return, (int) $supplier->id);
            }

            $lineTotal = round($qty * $price, 2);
            $total += $lineTotal;

            $returnDetail = PurchaseReturnDetail::create([
                'purchase_return_id' => $return->id,
                'product_id'         => $stock->product_id,
                'main_stock_id'      => $stock->id,
                'purchase_detail_id' => $source?->id ?? ($devices->pluck('purchase_detail_id')->filter()->unique()->count() === 1 ? $devices->first()->purchase_detail_id : null),
                'ram_id'             => $stock->ram_id,
                'rom_id'             => $stock->rom_id,
                'color_id'           => $stock->color_id,
                'quantity'           => $qty,
                'purchase_price'     => $price,
                'unit_total'         => $lineTotal,
            ]);

            // baki stock er average: (mot dam - ferot er dam) / baki qty; ferot er dam average theke alada hole
            $remaining = $stock->quantity - $qty;
            if ($remaining > 0) {
                $oldAvg = (float) $stock->average_purchase_price;
                $stock->average_purchase_price = max(0, round(($stock->quantity * $oldAvg - $qty * $price) / $remaining, 2));
                PriceHistory::record($stock->product, $stock, PriceHistory::AVERAGE_COST, $oldAvg, $stock->average_purchase_price, 'purchase_return', $return);
            }

            // phone er branch theke (IMEI chara hole user er branch); ekoi line duibar na
            $branches = $devices->pluck('branch_id')->filter()->unique();
            $this->stock->move($stock, -$qty, StockService::PURCHASE_RETURN, $return, null,
                $branches->count() === 1 ? (int) $branches->first() : null, 'purchase_return:' . $returnDetail->id);
        }

        $total = round($total, 2);
        if ($total <= 0) {
            throw new \Exception('Return total must be greater than zero.');
        }
        $return->update(['total_amount' => $total]);

        // supplier ledger: dena kome; payable er beshi hole agrim
        $this->accounts->recordReturn($return);

        // supplier sathe sathe taka ferot dile (shudhu agrim theke)
        $refund = round((float) ($input['refund_amount'] ?? 0), 2);
        if ($refund > 0) {
            $this->accounts->receiveRefund($supplier, [
                'amount'         => $refund,
                'payment_date'   => $return->return_date,
                'payment_method' => ($input['refund_method'] ?? null) ?: 'Cash',
                'reference_no'   => $return->return_no,
                'note'           => 'Refund for return ' . $return->return_no,
            ]);
        }

        AuditLogger::log('purchase_return.created', $return, null,
            $return->only(['return_no', 'supplier_id', 'purchase_id', 'total_amount']) + ['refund' => $refund]);

        return $return;
    }

    // IMEI wala variant hole kon phone gulo; sonkhya = qty, ei variant er, branch access, invoice dile oi invoice er
    private function devices($ids, MainStock $stock, int $qty, ?Purchase $purchase, User $user, string $label)
    {
        $ids = array_values(array_unique(array_filter((array) $ids)));
        $hasImeiInStock = ProductImei::where('main_stock_id', $stock->id)->owned()->exists();

        if (($stock->product->requiresImei() || $hasImeiInStock) && count($ids) != $qty) {
            throw new \Exception("{$label}: please select exactly {$qty} IMEI to return.");
        }
        if (!$ids) {
            return collect();
        }

        $imeis = ProductImei::whereIn('id', $ids)->where('main_stock_id', $stock->id)->lockForUpdate()->get();
        if ($imeis->count() != count($ids)) {
            throw new \Exception("{$label}: one or more selected IMEI do not belong to this product variant.");
        }

        // onno branch e thaka phone ei user supplier ke ferot dite parbe na
        $otherBranch = $imeis->reject(fn ($i) => $user->canAccessBranch($i->branch_id));
        if ($otherBranch->isNotEmpty()) {
            throw new \Exception("{$label}: these IMEI are in another branch: " . $otherBranch->pluck('imei_no')->implode(', '));
        }

        if ($purchase) {
            $other = $imeis->reject(fn ($i) => (int) $i->purchase_id === (int) $purchase->id);
            if ($other->isNotEmpty()) {
                throw new \Exception("{$label}: these IMEI were not bought on {$purchase->invoice_no}: " . $other->pluck('imei_no')->implode(', '));
            }
        }

        return $imeis;
    }

    // Invoice er oi variant er line; ager return bad diye baki qty
    private function purchaseLine(Purchase $purchase, MainStock $stock, int $qty, string $label): PurchaseDetail
    {
        $lines = PurchaseDetail::where('purchase_id', $purchase->id)->where('main_stock_id', $stock->id)->orderBy('id')->get();
        if ($lines->isEmpty()) {
            throw new \Exception("{$label} is not on invoice {$purchase->invoice_no}.");
        }

        $bought = (int) $lines->sum('quantity');
        $returned = (int) PurchaseReturnDetail::whereIn('purchase_detail_id', $lines->pluck('id'))->sum('quantity');
        if ($qty > $bought - $returned) {
            throw new \Exception("{$label}: only " . ($bought - $returned) . " left to return on {$purchase->invoice_no} (bought {$bought}, returned {$returned}).");
        }

        return $lines->first();
    }

    // Ferot er shorbochcho dam: kena line er landed cost; na jana gele variant er average/shesh kena dam
    private function costCap(?PurchaseDetail $source, $devices, MainStock $stock): float
    {
        if ($source) {
            return $source->landedUnitCost();
        }

        $lines = PurchaseDetail::whereIn('id', $devices->pluck('purchase_detail_id')->filter())->get();
        if ($lines->isNotEmpty()) {
            return (float) $lines->map->landedUnitCost()->max();
        }

        return max((float) $stock->average_purchase_price, (float) $stock->last_purchase_price);
    }
}
