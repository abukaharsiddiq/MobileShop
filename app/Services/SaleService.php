<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\MainStock;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\User;
use Carbon\Carbon;

/**
 * POS sale ek jaygay. Shob DB transaction er bhitore dakte hobe (controller e).
 *
 * - Total server e: line (qty x dam - line discount) -> subtotal - invoice discount + tax(%) + additional charge.
 *   Browser er total/stock bishwas kora hoy na; form je total dekhiyechilo (expected_total) na mille atkay.
 * - Stock row gulo id er kromanushare lock (ekshathe duita sale e deadlock na), tarpor IMEI row lock -
 *   ekoi stock/IMEI duibar bikri hoy na. IMEI wala product e available IMEI bachai badhyotamulok.
 * - Payment: ekadhik method (cash/bank/mobile) e bhag; mot payable er beshi na; bank/mobile e reference;
 *   ekoi reference duibar na. Form token (sale_token) duibar ashle ekta sale.
 * - Invoice number Setting row lock kore (InvoiceNumberService) + unique index.
 */
class SaleService
{
    public function __construct(
        private ImeiService $imeis,
        private StockService $stock,
        private InvoiceNumberService $numbers,
        private LedgerService $ledger,
        private SalePaymentService $payments,
        private FinancePostingService $finance,
        private WarrantyService $warranties,
    ) {}

    public function checkout(array $input, User $user): Sale
    {
        $token = trim((string) ($input['sale_token'] ?? '')) ?: null;
        if ($token && ($existing = Sale::where('idempotency_key', $token)->first())) {
            return $existing;
        }

        $customer = Customer::lockForUpdate()->findOrFail($input['customer_id']);
        if (!$customer->isActive()) {
            throw new \Exception("{$customer->name} is inactive. Activate the customer before selling.");
        }
        $salesperson = $this->salesperson($input['salesperson_id'] ?? null, $user);

        $stockIds = array_values((array) ($input['stock_ids'] ?? []));
        if (!$stockIds) {
            throw new \Exception('Add at least one product to the cart.');
        }
        // deadlock na hoy: shob stock row ekbar e, id er kromanushare
        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])->whereIn('id', $stockIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $sale = Sale::create([
            'invoice_no'      => $this->numbers->nextSale(),
            'status'          => Sale::STATUS_COMPLETED,
            'customer_id'     => $customer->id,
            'salesperson_id'  => $salesperson->id,
            'sale_date'       => $input['sale_date'],
            'subtotal'        => 0, 'discount' => 0, 'grand_total' => 0, 'paid_amount' => 0, 'due_amount' => 0,
            'note'            => $input['note'] ?? null,
            'created_by'      => $user->id,
            'idempotency_key' => $token,
        ]);

        $canSellBelowCost = $user->can('sell below cost');
        $belowCost = [];
        $subtotal = 0;
        $totalCost = 0;
        $branches = [];
        $usedImeis = [];
        $lines = [];

        foreach ((array) $input['stock_ids'] as $key => $stockId) {
            $stock = $stocks[$stockId] ?? null;
            if (!$stock) {
                throw new \Exception('A selected product variant was not found.');
            }
            $label = $stock->variantLabel();
            $qty = (int) ($input['qtys'][$key] ?? 0);
            if ($qty < 1) {
                throw new \Exception("{$label}: quantity must be at least 1.");
            }
            if (!$stock->isSellable()) {
                throw new \Exception("{$label} is inactive and cannot be sold.");
            }

            // ekoi variant duita line e thakle mot qty dhore jachai (stock row agei lock)
            $already = array_sum(array_map(fn ($l) => $l['stock']->id === $stock->id ? $l['qty'] : 0, $lines));
            if ($already + $qty > $stock->quantity && !StockService::allowsNegative($stock)) {
                throw new \Exception("Not enough stock for {$label}. Available: " . ($stock->quantity - $already) . ", requested: {$qty}." . StockService::negativeHint($stock));
            }

            $imeiIds = array_values(array_unique(array_filter((array) ($input['imeis'][$key] ?? []))));
            // smartphone: registered IMEI chara bikri hobe na; accessory: IMEI na
            $this->imeis->assertProductRules($stock->product, $qty, $imeiIds, $label);

            $devices = collect();
            if ($imeiIds) {
                if (array_intersect($imeiIds, $usedImeis)) {
                    throw new \Exception("{$label}: the same IMEI is selected on two lines.");
                }
                $usedImeis = array_merge($usedImeis, $imeiIds);

                // IMEI ta ei variant er kina (lock shoho, jate duijon ekshathe ekoi phone bikri na kore)
                $devices = ProductImei::whereIn('id', $imeiIds)->where('main_stock_id', $stock->id)->lockForUpdate()->get();
                if ($devices->count() != count($imeiIds)) {
                    throw new \Exception("{$label}: one or more selected IMEI do not belong to this product variant.");
                }
                // available hote hobe (ba ei customer er jonno reserved); sold/repair/damaged na
                $notSellable = $devices->reject(fn ($i) => $i->isSellableTo($customer->id));
                if ($notSellable->isNotEmpty()) {
                    throw new \Exception("{$label}: these IMEI cannot be sold: " . $notSellable->map(fn ($i) => "{$i->imei_no} ({$i->statusText()})")->implode(', '));
                }
                // onno branch e thaka phone ei user bikri korte parbe na
                $otherBranch = $devices->reject(fn ($i) => $user->canAccessBranch($i->branch_id));
                if ($otherBranch->isNotEmpty()) {
                    throw new \Exception("{$label}: these IMEI are in another branch: " . $otherBranch->pluck('imei_no')->implode(', '));
                }
                $branches = array_merge($branches, $devices->pluck('branch_id')->filter()->all());
            }

            // dam form theke (dokane dam kom-beshi hoy), na dile variant er sale price; total server e
            $price = isset($input['sale_prices'][$key]) && $input['sale_prices'][$key] !== ''
                ? round((float) $input['sale_prices'][$key], 2) : round((float) ($stock->sale_price ?? $stock->product->sale_price), 2);
            if ($price < 0) {
                throw new \Exception("{$label}: price cannot be negative.");
            }
            $gross = round($qty * $price, 2);
            $itemDiscount = round((float) ($input['item_discounts'][$key] ?? 0), 2);
            if ($itemDiscount < 0 || $itemDiscount > $gross) {
                throw new \Exception("{$label}: discount must be between 0 and the line total.");
            }
            $lineTotal = round($gross - $itemDiscount, 2);
            $cost = (float) ($stock->average_purchase_price ?? 0);
            if ($lineTotal / $qty < $cost) {
                $belowCost[] = "{$label}: selling at " . number_format($lineTotal / $qty, 2) . ', cost ' . number_format($cost, 2);
            }

            $subtotal += $lineTotal;
            $totalCost += $qty * $cost;
            $lines[] = compact('stock', 'qty', 'price', 'itemDiscount', 'lineTotal', 'cost', 'devices');
        }

        // invoice er hishab
        $subtotal = round($subtotal, 2);
        $discount = round((float) ($input['discount'] ?? 0), 2);
        if ($discount < 0 || $discount > $subtotal) {
            throw new \Exception('Invoice discount must be between 0 and the subtotal (' . number_format($subtotal, 2) . ').');
        }
        $taxRate = round((float) ($input['tax_rate'] ?? 0), 2);
        if ($taxRate < 0 || $taxRate > 100) {
            throw new \Exception('Tax rate must be between 0 and 100%.');
        }
        $tax = round(($subtotal - $discount) * $taxRate / 100, 2);
        $charge = round((float) ($input['additional_charge'] ?? 0), 2);
        if ($charge < 0) {
            throw new \Exception('Additional charge cannot be negative.');
        }
        $grandTotal = round($subtotal - $discount + $tax + $charge, 2);

        if (isset($input['expected_total']) && $input['expected_total'] !== '' && abs(round((float) $input['expected_total'], 2) - $grandTotal) > 0.01) {
            throw new \Exception('The total on the screen (' . number_format((float) $input['expected_total'], 2) . ') does not match the calculated total ('
                . number_format($grandTotal, 2) . '). Please check the cart and try again.');
        }

        // kroymuller niche bikri shudhu jader permission ache (jinish er dam, tax/charge chara)
        if (round($subtotal - $discount, 2) < round($totalCost, 2)) {
            $belowCost[] = 'Invoice goods total ' . number_format($subtotal - $discount, 2) . ' is below cost ' . number_format($totalCost, 2);
        }
        if ($belowCost && !$canSellBelowCost) {
            throw new \Exception('Sale price is below purchase cost. ' . implode('; ', $belowCost));
        }

        $payments = $this->paymentLines($input, $grandTotal, $customer->id);
        $paid = round(array_sum(array_column($payments, 'amount')), 2);
        // store credit notun taka na (return e agei ledger e credit hoyeche), tai ledger e shudhu baki ta
        $creditUsed = round(array_sum(array_column(array_filter($payments, fn ($p) => $p['channel'] === 'credit'), 'amount')), 2);

        $branchId = count(array_unique($branches)) === 1 ? (int) reset($branches) : Branch::currentId();
        $this->assertBranchStock($lines, $branchId);
        $sale->fill([
            'branch_id'              => $branchId,
            'subtotal'               => $subtotal,
            'discount'               => $discount,
            'tax_rate'               => $taxRate,
            'tax_amount'             => $tax,
            'additional_charge'      => $charge,
            'additional_charge_note' => $input['additional_charge_note'] ?? null,
            'grand_total'            => $grandTotal,
            'paid_amount'            => $paid,
            'due_amount'             => round($grandTotal - $paid, 2),
            'payment_method'         => count($payments) > 1 ? 'Split' : ($payments[0]['method'] ?? ($input['payment_method'] ?? 'Cash')),
            'payment_account'        => count($payments) === 1 ? ($payments[0]['account'] ?? null) : null,
        ])->save();

        foreach ($lines as $line) {
            $stock = $line['stock'];
            $item = SaleDetail::create([
                'sale_id'        => $sale->id,
                'product_id'     => $stock->product_id,
                'main_stock_id'  => $stock->id,
                'ram_id'         => $stock->ram_id,
                'rom_id'         => $stock->rom_id,
                'color_id'       => $stock->color_id,
                'quantity'       => $line['qty'],
                'purchase_price' => $line['cost'],   // bikrir somoyer average cost (profit er jonno thake)
                'sale_price'     => $line['price'],
                'item_discount'  => $line['itemDiscount'],
                'unit_total'     => $line['lineTotal'],
            ]);

            foreach ($line['devices'] as $imei) {
                $this->imeis->sell($imei, $sale, $item->id, $customer->id);
            }

            // phone je branch e chilo sei branch theke; IMEI chara hole user er branch. Ekoi line duibar na (key)
            $lineBranch = $line['devices']->pluck('branch_id')->filter()->unique();
            $this->stock->move($stock, -$line['qty'], StockService::SALE, $sale, null,
                $lineBranch->count() === 1 ? (int) $lineBranch->first() : $branchId, 'sale:' . $item->id);
        }

        if (!empty($input['is_installment'])) {
            if ($sale->due_amount <= 0) {
                throw new \Exception('Nothing is due, so an installment plan is not needed.');
            }
            // down payment = POS e ja deya holo; baki ta kisti te
            $count = (int) ($input['total_installments'] ?? 0);
            if ($count < 1 || $count > 60) {
                throw new \Exception('Number of installments must be between 1 and 60.');
            }
            $frequency = $input['installment_frequency'] ?? 'monthly';
            $firstDue = Carbon::parse(($input['next_installment_date'] ?? null) ?: SalePaymentService::dueDate(Carbon::parse($sale->sale_date), 1, $frequency));
            if ($firstDue->lt(Carbon::parse($sale->sale_date)->startOfDay())) {
                throw new \Exception('First installment date cannot be before the sale date.');
            }
            $this->payments->createSchedule($sale, $count, $firstDue, $frequency);
        }

        $entry = $this->ledger->customerEntry([
            'customer_id'      => $customer->id,
            'date'             => $sale->sale_date,
            'transaction_type' => 'Sale',
            'sale_id'          => $sale->id,
            'reference_no'     => $sale->invoice_no,
            'debit'            => $grandTotal,
            'credit'           => round($paid - $creditUsed, 2),
            'note'             => 'Sale Invoice: ' . $sale->invoice_no . ($creditUsed > 0 ? ' (store credit ' . number_format($creditUsed, 2) . ')' : ''),
        ]);
        // journal: Dr Receivable + Sales Discount, Cr Sales + Tax + Charge; Dr COGS, Cr Inventory
        $this->finance->postSale($sale);
        foreach ($payments as $payment) {
            // protita method er taka: Dr Cash/Bank/Mobile, Cr Receivable (store credit e entry nei)
            $this->finance->postSalePayment(SalePayment::create($payment + [
                'sale_id'            => $sale->id,
                'customer_id'        => $customer->id,
                'type'               => SalePayment::TYPE_SALE,
                'payment_date'       => $sale->sale_date,
                'received_by'        => $user->id,
                'customer_ledger_id' => $entry->id,
            ]));
        }

        // protiti device/line er shop ar brand warranty (sale er tarikh theke)
        $this->warranties->issueForSale($sale);

        if ($belowCost) {
            AuditLogger::log('sale.below_cost', $sale, null, ['lines' => $belowCost]);
        }
        AuditLogger::log('sale.created', $sale, null, $sale->only(['invoice_no', 'customer_id', 'grand_total', 'paid_amount', 'branch_id', 'salesperson_id']));

        // customer ke SMS/WhatsApp (commit er por queue e; ekoi sale e ekbar)
        app(NotificationService::class)->notify('sale_confirmation', $customer, $sale, [
            'invoice' => $sale->invoice_no, 'total' => number_format((float) $sale->grand_total, 2), 'paid' => number_format((float) $sale->paid_amount, 2),
            'due' => number_format((float) $sale->due_amount, 2), 'date' => Carbon::parse($sale->sale_date)->format('d M Y'),
        ]);

        return $sale;
    }

    /**
     * Pura sale batil: phone gulo abar available, stock ferot, kisti bad, ledger e ulto line ar je taka
     * neya hoyechilo ta ferot (refund line). Record thake (muchhe na). Return hoye thakle cancel na - return korun.
     */
    public function cancel(Sale $sale, string $reason, User $user, string $refundMethod = 'Cash'): Sale
    {
        $sale = Sale::lockForUpdate()->findOrFail($sale->id);
        if ($sale->isCancelled()) {
            throw new \Exception("Invoice {$sale->invoice_no} is already cancelled.");
        }
        if (SaleReturn::where('sale_id', $sale->id)->exists()) {
            throw new \Exception("Invoice {$sale->invoice_no} has returns; use Sales Return for the remaining items instead of cancelling.");
        }
        $refundChannel = SalePaymentService::assertMethod($refundMethod, null);

        $details = SaleDetail::where('sale_id', $sale->id)->get();
        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])->whereIn('id', $details->pluck('main_stock_id')->filter())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach (ProductImei::where('sale_id', $sale->id)->where('status', ProductImei::STATUS_SOLD)->lockForUpdate()->get() as $imei) {
            $this->imeis->releaseFromSale($imei, $sale, ProductImei::STATUS_AVAILABLE, (int) $sale->customer_id,
                'Sale cancelled: ' . $reason, ImeiService::SALE_CANCELLED);
        }

        foreach ($details->where('quantity', '>', 0) as $detail) {
            $stock = $stocks[$detail->main_stock_id] ?? MainStock::where(['product_id' => $detail->product_id, 'ram_id' => $detail->ram_id,
                'rom_id' => $detail->rom_id, 'color_id' => $detail->color_id])->lockForUpdate()->first();
            if (!$stock) {
                throw new \Exception("Stock record for {$sale->invoice_no} line #{$detail->id} was not found.");
            }
            $this->stock->move($stock, (int) $detail->quantity, StockService::SALE_CANCELLED, $sale, 'Sale cancelled: ' . $reason,
                $sale->branch_id, 'sale_cancel:' . $detail->id);
        }

        $this->payments->cancelSchedule($sale, $reason);

        // store credit diye deya ongsho abar credit hoye jay (sale bad, tai "used" e gona hoy na); nogod tuku ferot
        $creditUsed = round((float) SalePayment::where('sale_id', $sale->id)->where('type', SalePayment::TYPE_SALE)->where('channel', 'credit')->sum('amount'), 2);
        $paid = round((float) $sale->paid_amount - $creditUsed, 2);
        $this->ledger->customerEntry([
            'customer_id'      => $sale->customer_id,
            'date'             => now()->toDateString(),
            'transaction_type' => 'Sale Cancelled',
            'sale_id'          => $sale->id,
            'reference_no'     => $sale->invoice_no,
            'credit'           => $sale->grand_total,
            'note'             => 'Sale cancelled: ' . $reason . ($creditUsed > 0 ? ' (store credit ' . number_format($creditUsed, 2) . ' restored)' : ''),
        ]);
        if ($paid > 0) {
            $refund = $this->ledger->customerEntry([
                'customer_id'      => $sale->customer_id,
                'date'             => now()->toDateString(),
                'transaction_type' => 'Refund Paid',
                'sale_id'          => $sale->id,
                'reference_no'     => $sale->invoice_no,
                'debit'            => $paid,
                'note'             => "Refund for cancelled sale ({$refundMethod})",
            ]);
            $this->finance->postSalePayment(SalePayment::create([
                'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'type' => SalePayment::TYPE_REFUND,
                'method' => $refundMethod, 'channel' => $refundChannel, 'amount' => $paid, 'payment_date' => now()->toDateString(),
                'received_by' => $user->id, 'customer_ledger_id' => $refund->id, 'note' => 'Sale cancelled: ' . $reason,
            ]));
        }
        // sale er journal ulta (ajker tarikhe): revenue, tax, COGS sob ferot
        $this->finance->cancelSale($sale, $reason);
        $this->warranties->voidForSale($sale, 'Sale cancelled: ' . $reason);

        $sale->update([
            'status'                  => Sale::STATUS_CANCELLED,
            'paid_amount'             => 0,
            'due_amount'              => 0,
            'is_installment'          => 0,
            'total_installments'      => 0,
            'total_paid_installments' => 0,
            'cancelled_at'            => now(),
            'cancelled_by'            => $user->id,
            'cancel_reason'           => $reason,
        ]);
        AuditLogger::log('sale.cancelled', $sale, ['status' => Sale::STATUS_COMPLETED, 'paid' => $paid],
            ['status' => Sale::STATUS_CANCELLED, 'reason' => $reason, 'refund' => $paid, 'refund_method' => $refundMethod]);

        return $sale;
    }

    /**
     * Warehouse theke POS e bikri na (transfer kore branch e); IMEI chara line e oi branch er ledger e
     * jotota ache tar beshi na (config inventory.enforce_branch_stock; negative stock chalu thakle na).
     */
    private function assertBranchStock(array $lines, ?int $saleBranchId): void
    {
        $branchOf = fn (array $line) => ($b = $line['devices']->pluck('branch_id')->filter()->unique())->count() === 1 ? (int) $b->first() : $saleBranchId;

        $used = array_filter(array_unique(array_map($branchOf, $lines)));
        $warehouse = Branch::whereIn('id', $used ?: [0])->where('type', Branch::WAREHOUSE)->value('name');
        if ($warehouse) {
            throw new \Exception("{$warehouse} is a warehouse; transfer the stock to a branch before selling it.");
        }
        if (!config('inventory.enforce_branch_stock') || !$saleBranchId) {
            return;
        }

        $need = [];
        foreach ($lines as $line) {
            if ($line['devices']->isEmpty() && !StockService::allowsNegative($line['stock'])) {
                $need[$line['stock']->id] = ($need[$line['stock']->id] ?? 0) + $line['qty'];
            }
        }
        $balances = $need ? StockService::branchBalances(array_keys($need), $saleBranchId) : collect();
        foreach ($need as $stockId => $qty) {
            $here = (int) $balances->get($stockId . ':' . $saleBranchId, 0);
            if ($qty > $here) {
                $line = collect($lines)->first(fn ($l) => $l['stock']->id === $stockId);
                throw new \Exception("Not enough stock of {$line['stock']->variantLabel()} in this branch. Available here: {$here}, requested: {$qty}. Transfer stock from another branch first.");
            }
        }
    }

    /**
     * Payment line: payments[] (method, amount, reference, account) - ba purono form er paid_amount + payment_method.
     * Mot payable er beshi na; protita line > 0; bank/mobile e reference; ekoi reference duibar na.
     */
    private function paymentLines(array $input, float $grandTotal, int $customerId): array
    {
        $rows = array_values(array_filter((array) ($input['payments'] ?? []), fn ($p) => is_array($p) && round((float) ($p['amount'] ?? 0), 2) != 0));
        if (!$rows && round((float) ($input['paid_amount'] ?? 0), 2) > 0) {
            $rows = [['method' => $input['payment_method'] ?? 'Cash', 'amount' => $input['paid_amount'], 'account' => $input['payment_account'] ?? null,
                'reference' => $input['payment_reference'] ?? null]];
            $legacy = true;
        }

        $lines = [];
        $seen = [];
        foreach ($rows as $row) {
            $amount = round((float) $row['amount'], 2);
            if ($amount <= 0) {
                throw new \Exception('Each payment amount must be greater than zero.');
            }
            $method = (string) ($row['method'] ?? 'Cash');
            $reference = trim((string) ($row['reference'] ?? ''));
            // purono form e reference field nei, tai shekhane lage na
            $channel = SalePaymentService::assertMethod($method, $reference, empty($legacy));
            if ($reference !== '') {
                if (isset($seen[$method . '|' . $reference])) {
                    throw new \Exception("Duplicate payment: {$method} reference {$reference} is entered twice.");
                }
                $seen[$method . '|' . $reference] = true;
            }
            $lines[] = ['method' => $method, 'channel' => $channel, 'amount' => $amount, 'reference' => $reference ?: null,
                'account' => $row['account'] ?? null];
        }

        // exchange: ferot er dam store credit hisebe, notun sale e joto lage totota
        $apply = round((float) ($input['apply_store_credit'] ?? 0), 2);
        if ($apply > 0) {
            $room = round($grandTotal - array_sum(array_column($lines, 'amount')), 2);
            if (min($apply, $room) > 0) {
                $lines[] = ['method' => 'Store Credit', 'channel' => 'credit', 'amount' => round(min($apply, $room), 2), 'reference' => null, 'account' => null];
            }
        }

        $paid = round(array_sum(array_column($lines, 'amount')), 2);
        if ($paid > $grandTotal) {
            throw new \Exception('Paid amount (' . number_format($paid, 2) . ') cannot be more than the grand total (' . number_format($grandTotal, 2) . ').');
        }

        // store credit: customer er jotota ache tar beshi na
        $credit = round(array_sum(array_column(array_filter($lines, fn ($l) => $l['channel'] === 'credit'), 'amount')), 2);
        if ($credit > 0) {
            $available = SalePaymentService::availableCredit($customerId);
            if ($credit > $available) {
                throw new \Exception('Store credit used (' . number_format($credit, 2) . ') is more than the customer has (' . number_format($available, 2) . ').');
            }
        }

        return $lines;
    }

    // ke bikri korlo: na dile je banacche; dile active staff/admin hote hobe
    private function salesperson($id, User $user): User
    {
        if (!$id || (int) $id === (int) $user->id) {
            return $user;
        }

        $salesperson = User::where('status', 1)->find($id);
        if (!$salesperson) {
            throw new \Exception('The selected salesperson was not found or is inactive.');
        }

        return $salesperson;
    }
}
