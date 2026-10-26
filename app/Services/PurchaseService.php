<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MainStock;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;

/**
 * Purchase er shob kaj ek jaygay. Workflow: draft -> pending (approval er opekkhay) -> approved, ba cancelled.
 *
 * - Save (draft/pending): line, IMEI ar total jachai hoy, kintu stock/IMEI/average cost/supplier ledger chhoy na.
 * - Approve: ekta transaction e variant stock bare, IMEI register, landed cost diye weighted average cost,
 *   supplier ledger e bill ar form er payment. IMEI abar jachai hoy (save er por obostha bodlate pare).
 * - Approved invoice edit hoy na; cancel e stock/IMEI/average cost ulto, ledger e ulto line (itihash thake).
 * - Total server e: line (qty x cost - discount) -> subtotal - discount + tax + additional cost.
 *   Discount/tax/additional cost line gulor upor bhag hoye landed cost (protiti unit er asol kena dam).
 *
 * Shob public method DB transaction er bhitore dakte hobe.
 */
class PurchaseService
{
    const ACTION_DRAFT = 'draft';
    const ACTION_SUBMIT = 'submit';
    const ACTION_APPROVE = 'approve';

    public function __construct(
        private ImeiService $imeis,
        private StockService $stock,
        private InvoiceNumberService $numbers,
        private VariantService $variants,
        private SupplierAccountService $accounts,
    ) {}

    // config('inventory.purchase_requires_approval') false hole purchase banate parle approve o (ager moto)
    public static function canApprove(User $user): bool
    {
        return $user->can('approve purchase')
            || (!config('inventory.purchase_requires_approval') && $user->can('create purchase'));
    }

    /**
     * $input: supplier_id, purchase_date, supplier_invoice_no?, note?, items[] (product_id, main_stock_id | ram_id/rom_id/color_id,
     *         qty, price, unit_discount?, imeis?), discount?, tax_rate?, additional_cost?, additional_cost_note?,
     *         paid_amount?, payment_method?, payment_account?, payment_token?, expected_total? (form er total - milte hobe)
     * Notun purchase e ekoi payment_token age eshe thakle agerta ferot dey (wasRecentlyCreated = false).
     */
    public function save(array $input, string $action, ?Purchase $purchase = null): Purchase
    {
        if ($purchase) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            if (!$purchase->isOpen()) {
                throw new \Exception("Invoice {$purchase->invoice_no} is {$purchase->statusLabel()} and cannot be edited.");
            }
        } elseif ($existing = $this->alreadySaved($input)) {
            return $existing;
        }

        $supplier = Supplier::lockForUpdate()->findOrFail($input['supplier_id']);
        if (!$supplier->isActive()) {
            throw new \Exception("{$supplier->name} is inactive. Activate the supplier before purchasing.");
        }
        $supplierInvoice = trim((string) ($input['supplier_invoice_no'] ?? '')) ?: null;
        $this->assertNewSupplierInvoice($supplier, $supplierInvoice, $purchase?->id);

        $lines = $this->buildLines($input['items'] ?? [], $purchase?->id);
        $totals = $this->totals($lines, $input);

        $isNew = !$purchase;
        $old = $purchase?->only(['supplier_id', 'purchase_date', 'grand_total', 'status']);
        $purchase ??= new Purchase([
            'invoice_no'      => $this->numbers->nextPurchase(),
            'created_by'      => auth()->id(),
            'branch_id'       => Branch::currentId(),
            'idempotency_key' => trim((string) ($input['payment_token'] ?? '')) ?: null,
        ]);

        $purchase->fill([
            'supplier_id'          => $supplier->id,
            'supplier_invoice_no'  => $supplierInvoice,
            'purchase_date'        => $input['purchase_date'],
            'status'               => $action === self::ACTION_DRAFT ? Purchase::STATUS_DRAFT : Purchase::STATUS_PENDING,
            'submitted_at'         => $action === self::ACTION_DRAFT ? null : now(),
            'subtotal'             => $totals['subtotal'],
            'discount'             => $totals['discount'],
            'tax_rate'             => $totals['tax_rate'],
            'tax_amount'           => $totals['tax'],
            'additional_cost'      => $totals['additional'],
            'additional_cost_note' => $input['additional_cost_note'] ?? null,
            'grand_total'          => $totals['grand'],
            'payment_method'       => ($input['payment_method'] ?? null) ?: 'Cash',
            'payment_account'      => $input['payment_account'] ?? null,
            'initial_payment'      => $totals['paid'],
            // paid/due approve er por supplier ledger theke (settle)
            'paid_amount'          => 0,
            'return_amount'        => 0,
            'due_amount'           => $totals['grand'],
            'payment_status'       => 'Due',
            'note'                 => $input['note'] ?? null,
        ]);
        $purchase->save();

        $purchase->purchaseDetails()->delete();
        foreach ($lines as $line) {
            PurchaseDetail::create($line['detail'] + ['purchase_id' => $purchase->id]);
        }

        AuditLogger::log($isNew ? 'purchase.created' : 'purchase.updated', $purchase, $old,
            $purchase->only(['invoice_no', 'supplier_id', 'purchase_date', 'grand_total', 'status']));

        if ($action === self::ACTION_APPROVE) {
            $this->approve($purchase);
        }

        return $purchase->refresh();
    }

    public function submit(Purchase $purchase): Purchase
    {
        $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
        if (!$purchase->isDraft()) {
            throw new \Exception("Invoice {$purchase->invoice_no} is {$purchase->statusLabel()}; only a draft can be submitted.");
        }

        $purchase->update(['status' => Purchase::STATUS_PENDING, 'submitted_at' => now()]);
        AuditLogger::log('purchase.submitted', $purchase, ['status' => Purchase::STATUS_DRAFT], ['status' => Purchase::STATUS_PENDING]);

        return $purchase;
    }

    /**
     * Stock bare, IMEI register, average cost, supplier ledger - shob ekshathe, na hole kichui na.
     */
    public function approve(Purchase $purchase): Purchase
    {
        $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
        if (!$purchase->isOpen()) {
            throw new \Exception("Invoice {$purchase->invoice_no} is already {$purchase->statusLabel()}.");
        }

        $supplier = Supplier::lockForUpdate()->findOrFail($purchase->supplier_id);
        if (!$supplier->isActive()) {
            throw new \Exception("{$supplier->name} is inactive. Activate the supplier before approving.");
        }

        $details = PurchaseDetail::with('product')->where('purchase_id', $purchase->id)->orderBy('id')->get();
        if ($details->isEmpty()) {
            throw new \Exception("Invoice {$purchase->invoice_no} has no products.");
        }

        // save er por onno invoice e eki IMEI dhuke thakte pare, tai abar jachai (lock shoho)
        $plans = [];
        $all = [];
        foreach ($details as $detail) {
            $product = $detail->product;
            if (!$product->isActive()) {
                throw new \Exception("{$product->product_name} is inactive and cannot be purchased.");
            }
            $stock = MainStock::where('product_id', $product->id)->lockForUpdate()->find($detail->main_stock_id);
            if (!$stock || !$stock->status) {
                throw new \Exception("{$product->product_name}: the variant is missing or inactive.");
            }

            $devices = ProductImei::parseDevices($detail->imei_list);
            $this->imeis->assertProductRules($product, (int) $detail->quantity, $devices, $product->product_name);
            $numbers = $this->imeis->assertNewDevices($product, $devices, $product->product_name);
            $this->imeis->assertRepurchaseAllowed($product, $numbers);
            $all = array_merge($all, $numbers);
            $plans[] = [$detail, $product, $stock, $devices];
        }
        $this->imeis->assertValidNewImeis($all);
        $this->imeis->assertNotActive($all);
        $this->assertNotInOpenPurchase($all, $purchase->id);

        $branchId = $purchase->branch_id ?? Branch::currentId();
        $from = $purchase->status;

        foreach ($plans as [$detail, $product, $stock, $devices]) {
            $qty = (int) $detail->quantity;
            $landed = $detail->landedUnitCost();

            // weighted average: (ager stock x ager average + notun qty x landed cost) / mot
            $oldAvg = (float) ($stock->average_purchase_price ?? 0);
            $oldLast = $stock->last_purchase_price;
            $current = (int) $stock->quantity;
            $newAvg = round((($current * $oldAvg) + ($qty * $landed)) / ($current + $qty), 2);

            $stock->average_purchase_price = $newAvg;
            $stock->last_purchase_price = $detail->purchase_price; // supplier er dam (porer form er default)
            PriceHistory::record($product, $stock, PriceHistory::LAST_COST, $oldLast, $detail->purchase_price, 'purchase', $purchase);
            PriceHistory::record($product, $stock, PriceHistory::AVERAGE_COST, $oldAvg, $newAvg, 'purchase', $purchase);

            $this->stock->move($stock, $qty, StockService::PURCHASE, $purchase, null, $branchId);

            foreach ($devices as [$imei1, $imei2]) {
                $this->imeis->register($product, $stock->id, $branchId, $imei1, $imei2, ImeiService::PURCHASE, $purchase,
                    $product->isSecondHand() ? 'Bought as ' . $product->typeLabel() : null,
                    (int) $purchase->supplier_id, $purchase->id, $detail->id);
            }
        }

        $purchase->update([
            'status'      => Purchase::STATUS_APPROVED,
            'branch_id'   => $branchId,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'paid_amount' => 0,
            'due_amount'  => $purchase->grand_total,
        ]);

        // bill + form e deya taka (supplier er agrim thakle settle e bose)
        $this->accounts->recordPurchase($purchase, (float) $purchase->initial_payment);

        AuditLogger::log('purchase.approved', $purchase, ['status' => $from], ['status' => Purchase::STATUS_APPROVED]);

        return $purchase->refresh();
    }

    /**
     * Draft/pending: shudhu status. Approved: stock, IMEI, average cost ulto ar ledger e ulto line.
     * Bikri/ferot/repair hoye jawa IMEI, purchase return ba pore deya payment thakle hoy na.
     */
    public function cancel(Purchase $purchase, string $reason): Purchase
    {
        $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
        if ($purchase->isCancelled()) {
            throw new \Exception("Invoice {$purchase->invoice_no} is already cancelled.");
        }
        $from = $purchase->status;

        if ($purchase->isApproved()) {
            $this->reverseStock($purchase);
        }

        $purchase->update([
            'status'        => Purchase::STATUS_CANCELLED,
            'cancelled_by'  => auth()->id(),
            'cancelled_at'  => now(),
            'cancel_reason' => $reason,
        ]);

        if ($from === Purchase::STATUS_APPROVED) {
            $this->accounts->cancelPurchase($purchase, $reason);
            // ledger theke bad, tai ar keu dhare na
            $purchase->update(['paid_amount' => 0, 'return_amount' => 0, 'due_amount' => 0, 'payment_status' => 'Cancelled']);
        }

        AuditLogger::log('purchase.cancelled', $purchase, ['status' => $from], ['status' => Purchase::STATUS_CANCELLED, 'reason' => $reason]);

        return $purchase;
    }

    /**
     * Shudhu kokhono approve na howa invoice muchha jay. Approved hole cancel hoy (itihash thake).
     * Return kore: true = muchhe gelo, false = cancel holo.
     */
    public function delete(Purchase $purchase, string $reason): bool
    {
        $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);

        if ($purchase->isApproved()) {
            $this->cancel($purchase, $reason);
            return false;
        }
        if ($purchase->approved_at) {
            throw new \Exception("Invoice {$purchase->invoice_no} was approved before; the cancelled record is kept for history.");
        }

        AuditLogger::log('purchase.deleted', $purchase, $purchase->only(['invoice_no', 'supplier_id', 'grand_total', 'status']), null);
        $purchase->purchaseDetails()->delete();
        $purchase->delete();

        return true;
    }

    /**
     * IMEI form theke jachai (save er age): format, dual-SIM, qty, invoice e duibar, stock e ache, onno open invoice e ache.
     * @return string[] somossa (khali = thik)
     */
    public function checkImeis(Product $product, int $qty, string $text, ?int $exceptPurchaseId = null): array
    {
        $devices = ProductImei::parseDevices($text);
        try {
            $this->imeis->assertProductRules($product, $qty, $devices, $product->product_name);
            $numbers = $this->imeis->assertNewDevices($product, $devices, $product->product_name);
            $this->imeis->assertNotActive($numbers);
            $this->imeis->assertRepurchaseAllowed($product, $numbers);
            $this->assertNotInOpenPurchase($numbers, $exceptPurchaseId);
        } catch (\Exception $e) {
            if (get_class($e) !== \Exception::class) {
                throw $e;
            }
            return [$e->getMessage()];
        }

        return [];
    }

    // ---- helpers --------------------------------------------------------------------------

    private function alreadySaved(array $input): ?Purchase
    {
        $key = trim((string) ($input['payment_token'] ?? ''));

        return $key === '' ? null : Purchase::where('idempotency_key', $key)->first();
    }

    // Supplier er nijer invoice/bill number ekoi supplier e duibar na (cancel kora bad)
    private function assertNewSupplierInvoice(Supplier $supplier, ?string $number, ?int $exceptId): void
    {
        if (!$number) {
            return;
        }

        $duplicate = Purchase::where('supplier_id', $supplier->id)->where('supplier_invoice_no', $number)
            ->where('status', '!=', Purchase::STATUS_CANCELLED)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->value('invoice_no');
        if ($duplicate) {
            throw new \Exception("Duplicate invoice: {$supplier->name}'s invoice {$number} is already entered as {$duplicate}.");
        }
    }

    // Onno draft/pending invoice e eki IMEI thakle duijon eki phone kinte pare na
    private function assertNotInOpenPurchase(array $numbers, ?int $exceptPurchaseId): void
    {
        if (!$numbers) {
            return;
        }

        $found = PurchaseDetail::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
            ->whereIn('purchases.status', [Purchase::STATUS_DRAFT, Purchase::STATUS_PENDING])
            ->when($exceptPurchaseId, fn ($q) => $q->where('purchases.id', '!=', $exceptPurchaseId))
            ->whereNotNull('purchase_details.imei_list')
            ->select('purchase_details.imei_list', 'purchases.invoice_no')
            ->get()
            ->filter(fn ($row) => array_intersect(ImeiService::numbers(ProductImei::parseDevices($row->imei_list)), $numbers))
            ->pluck('invoice_no')->unique();

        if ($found->isNotEmpty()) {
            throw new \Exception('These IMEI are already on another open purchase: ' . $found->implode(', '));
        }
    }

    private function buildLines(array $items, ?int $exceptPurchaseId): array
    {
        if (!$items) {
            throw new \Exception('Add at least one product.');
        }

        $lines = [];
        $all = [];
        foreach (array_values($items) as $item) {
            $product = Product::findOrFail($item['product_id']);
            $label = $product->product_name;
            $qty = (int) ($item['qty'] ?? 0);
            if ($qty < 1) {
                throw new \Exception("{$label}: quantity must be at least 1.");
            }
            if (!$product->isActive()) {
                throw new \Exception("{$label} is inactive and cannot be purchased.");
            }

            // Variant: form theke bacha variant (main_stock_id), na thakle RAM/ROM/color (purono form) -
            // oi variant na thakle notun variant (stock 0) toiri hoy, SKU shoho
            if (!empty($item['main_stock_id'])) {
                $stock = MainStock::where('product_id', $product->id)->find($item['main_stock_id']);
                if (!$stock) {
                    throw new \Exception("{$label}: the selected variant does not belong to this product.");
                }
            } else {
                $stock = $this->variants->findOrCreate($product,
                    ($item['ram_id'] ?? null) ?: null, ($item['rom_id'] ?? null) ?: null, ($item['color_id'] ?? null) ?: null);
            }
            if (!$stock->status) {
                throw new \Exception("{$stock->variantLabel()} is inactive and cannot be purchased.");
            }

            // protiti phone ekta token: "IMEI1" ba dual-SIM "IMEI1/IMEI2"; sonkhya = qty
            $devices = ProductImei::parseDevices($item['imeis'] ?? '');
            $this->imeis->assertProductRules($product, $qty, $devices, $label);
            $numbers = $this->imeis->assertNewDevices($product, $devices, $label);
            // puro invoice e (shob line mile) ekoi number duibar na
            $this->imeis->assertValidNewImeis(array_merge($all, $numbers));
            $this->imeis->assertRepurchaseAllowed($product, $numbers);
            $all = array_merge($all, $numbers);

            $price = round((float) ($item['price'] ?? 0), 2);
            if ($price < 0) {
                throw new \Exception("{$label}: unit cost cannot be negative.");
            }
            $gross = round($qty * $price, 2);
            $discount = round((float) ($item['unit_discount'] ?? 0), 2);
            if ($discount < 0 || $discount > $gross) {
                throw new \Exception("{$label}: discount must be between 0 and the line total.");
            }

            $lines[] = [
                'qty'    => $qty,
                'total'  => round($gross - $discount, 2),
                'detail' => [
                    'product_id'     => $product->id,
                    'main_stock_id'  => $stock->id,
                    // line er variant stock row theke (form er value na), jate stock ar line mele
                    'ram_id'         => $stock->ram_id,
                    'rom_id'         => $stock->rom_id,
                    'color_id'       => $stock->color_id,
                    'quantity'       => $qty,
                    'purchase_price' => $price,
                    'unit_discount'  => $discount,
                    'unit_total'     => round($gross - $discount, 2),
                    'imei_list'      => $devices ? implode("\n", array_map(fn ($d) => $d[1] ? "{$d[0]}/{$d[1]}" : $d[0], $devices)) : null,
                ],
            ];
        }

        $this->imeis->assertNotActive($all);
        $this->assertNotInOpenPurchase($all, $exceptPurchaseId);

        return $lines;
    }

    /**
     * Invoice er total ar protiti line er landed cost. Browser er total bishwas kora hoy na;
     * form grand_total pathale ar server er sathe na mille atkay (dam bodle gele bhul total save hoy na).
     */
    private function totals(array &$lines, array $input): array
    {
        $subtotal = round(array_sum(array_column($lines, 'total')), 2);

        $discount = round((float) ($input['discount'] ?? 0), 2);
        if ($discount < 0 || $discount > $subtotal) {
            throw new \Exception('Overall discount must be between 0 and the subtotal (' . number_format($subtotal, 2) . ').');
        }
        $rate = round((float) ($input['tax_rate'] ?? 0), 2);
        if ($rate < 0 || $rate > 100) {
            throw new \Exception('Tax rate must be between 0 and 100%.');
        }
        $tax = round(($subtotal - $discount) * $rate / 100, 2);
        $additional = round((float) ($input['additional_cost'] ?? 0), 2);
        if ($additional < 0) {
            throw new \Exception('Additional cost cannot be negative.');
        }
        $grand = round($subtotal - $discount + $tax + $additional, 2);

        $paid = round((float) ($input['paid_amount'] ?? 0), 2);
        if ($paid < 0) {
            throw new \Exception('Paid amount cannot be negative.');
        }
        if ($paid > $grand) {
            throw new \Exception('Paid amount (' . number_format($paid, 2) . ') cannot be more than the grand total (' . number_format($grand, 2) . ').');
        }

        // form je total dekhiyechilo (expected_total); purono form er grand_total bishwas/tulona kora hoy na
        if (isset($input['expected_total']) && $input['expected_total'] !== '' && abs(round((float) $input['expected_total'], 2) - $grand) > 0.01) {
            throw new \Exception('The grand total on the form (' . number_format((float) $input['expected_total'], 2)
                . ') does not match the calculated total (' . number_format($grand, 2) . '). Please check the lines and try again.');
        }

        // line er bhag: line total onujayi (shob line 0 hole qty onujayi); rounding er baki shesh line e
        $weights = $subtotal > 0 ? array_column($lines, 'total') : array_column($lines, 'qty');
        $taxShares = self::split($tax, $weights);
        $extraShares = self::split(round($additional - $discount, 2), $weights);
        foreach ($lines as $i => &$line) {
            $line['detail']['tax_amount'] = $taxShares[$i];
            $line['detail']['additional_cost'] = $extraShares[$i];
            $line['detail']['landed_cost'] = round(($line['total'] + $taxShares[$i] + $extraShares[$i]) / $line['qty'], 4);
        }
        unset($line);

        return compact('subtotal', 'discount', 'tax', 'additional', 'grand', 'paid') + ['tax_rate' => $rate];
    }

    private static function split(float $amount, array $weights): array
    {
        $total = array_sum($weights);
        $shares = [];
        $left = $amount;
        $last = array_key_last($weights);
        foreach ($weights as $i => $weight) {
            $shares[$i] = $i === $last ? round($left, 2) : ($total > 0 ? round($amount * $weight / $total, 2) : 0.0);
            $left = round($left - $shares[$i], 2);
        }

        return $shares;
    }

    // Approved purchase cancel: IMEI dokhole, stock ache, return nei - tarpor sob ulto
    private function reverseStock(Purchase $purchase): void
    {
        if (PurchaseReturn::where('purchase_id', $purchase->id)->exists()) {
            throw new \Exception("Cannot cancel! Items from invoice {$purchase->invoice_no} were returned to the supplier.");
        }
        $this->accounts->assertCancellable($purchase);

        // ei purchase er IMEI gulo bikri/ferot/reserve/repair hoye gele hoy na (shudhu available)
        $used = ProductImei::where('purchase_id', $purchase->id)
            ->where('status', '!=', ProductImei::STATUS_AVAILABLE)
            ->lockForUpdate()->get()
            ->map(fn ($i) => $i->imei_no . ' (' . $i->statusText() . ')');
        if ($used->isNotEmpty()) {
            throw new \Exception('Cannot cancel! These IMEI from this purchase are no longer simply in stock: ' . $used->implode(', '));
        }

        foreach (PurchaseDetail::with('product')->where('purchase_id', $purchase->id)->get() as $detail) {
            $stock = MainStock::lockForUpdate()->find($detail->main_stock_id)
                ?? MainStock::where(['product_id' => $detail->product_id, 'ram_id' => $detail->ram_id,
                    'rom_id' => $detail->rom_id, 'color_id' => $detail->color_id])->lockForUpdate()->first();
            if (!$stock) {
                continue;
            }

            $remaining = $stock->quantity - $detail->quantity;
            if ($remaining < 0) {
                throw new \Exception("Cannot cancel! Some products from this purchase are already sold ({$stock->variantLabel()}).");
            }

            // baki stock er average: (mot dam - ei line er landed dam) / baki qty
            if ($remaining > 0) {
                $oldAvg = $stock->average_purchase_price;
                $value = ($stock->quantity * $stock->average_purchase_price) - ($detail->quantity * $detail->landedUnitCost());
                $stock->average_purchase_price = max(0, round($value / $remaining, 2));
                PriceHistory::record($detail->product, $stock, PriceHistory::AVERAGE_COST, $oldAvg, $stock->average_purchase_price, 'purchase_cancelled', $purchase);
            }

            $this->stock->move($stock, -$detail->quantity, StockService::PURCHASE_DELETED, $purchase, 'Purchase cancelled');
        }

        // IMEI record muchhar age itihash e rakha (line er imei_list e number thake)
        foreach (ProductImei::where('purchase_id', $purchase->id)->lockForUpdate()->get() as $imei) {
            $this->imeis->record($imei, ImeiService::PURCHASE_DELETED, $purchase, 'Purchase cancelled');
        }
        ProductImei::where('purchase_id', $purchase->id)->delete();
    }
}
