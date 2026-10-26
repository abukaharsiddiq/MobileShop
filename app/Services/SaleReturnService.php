<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MainStock;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;

/**
 * Sales return & exchange. DB transaction er bhitore dakte hobe.
 *
 * - Shudhu oi invoice e bikri howa line/IMEI; qty = line e baki (pending return o dhora) er beshi na.
 *   IMEI wala line e IMEI diye, accessory te qty diye (IMEI lage na).
 * - Refund = line er asol bikri dam (line discount bad) / qty x ferot qty x invoice factor
 *   (invoice discount kome, tax er bhag jog; additional charge ferot hoy na).
 * - Inspection: good (abar bikri jogyo, available), damaged, needs_repair - IMEI status ar accessory stock sei moto.
 * - Settlement: prothome oi invoice er due theke kate; baki ta refund (cash/bank/mobile) ba store credit.
 *   Exchange: baki ta notun sale e store credit hisebe; notun sale beshi hole baki taka ney, kom hole ferot/credit.
 * - Approval: canApprove() hole sathe sathe complete; na hole pending (kichu bodlay na) - approve/reject.
 * - Ekoi form token duibar ashle ekta return; stock line key; status lock - ekoi return duibar process hoy na.
 */
class SaleReturnService
{
    // inspection -> IMEI status
    const CONDITIONS = ['good', 'damaged', 'needs_repair'];

    public function __construct(
        private ImeiService $imeis,
        private StockService $stock,
        private LedgerService $ledger,
        private SalePaymentService $payments,
        private SaleService $sales,
        private FinancePostingService $finance,
        private WarrantyService $warranties,
    ) {}

    public static function canApprove(User $user): bool
    {
        return $user->can('approve return')
            || (!config('pos.return_requires_approval') && $user->can('create return'));
    }

    /**
     * $input: sale_id, imei_nos[], imei_condition[imei], items[detail_id] = qty, item_condition[detail_id], reason, note,
     *         settlement (refund|store_credit), refund_method, refund_reference, request_token,
     *         exchange[stock_ids, qtys, sale_prices, item_discounts, imeis, discount, payments[]]
     */
    public function create(array $input, User $user): SaleReturn
    {
        $token = trim((string) ($input['request_token'] ?? '')) ?: null;
        if ($token && ($existing = SaleReturn::where('request_token', $token)->first())) {
            return $existing;
        }

        $sale = Sale::lockForUpdate()->findOrFail($input['sale_id']);
        if ($sale->isCancelled()) {
            throw new \Exception("Invoice {$sale->invoice_no} is cancelled; nothing can be returned.");
        }
        $details = SaleDetail::with('product')->where('sale_id', $sale->id)->lockForUpdate()->get()->keyBy('id');
        $lines = $this->collectLines($sale, $details, $input);

        $isExchange = !empty($input['exchange']['stock_ids']);
        $canApprove = self::canApprove($user);
        if ($isExchange && !$canApprove) {
            throw new \Exception('An exchange gives out a new product, so it must be done by a user who can approve returns.');
        }
        $settlement = ($input['settlement'] ?? SaleReturn::SETTLE_REFUND) === SaleReturn::SETTLE_CREDIT ? SaleReturn::SETTLE_CREDIT : SaleReturn::SETTLE_REFUND;
        $refundMethod = ($input['refund_method'] ?? null) ?: 'Cash';
        $channel = SalePaymentService::assertMethod($refundMethod, null);
        if ($channel === 'credit') {
            throw new \Exception('Choose how the money is paid back (cash, bank or mobile), or keep it as store credit.');
        }

        $return = SaleReturn::create([
            'return_no'        => 'SRN-TMP-' . uniqid(),
            'status'           => SaleReturn::STATUS_PENDING,
            'type'             => $isExchange ? SaleReturn::TYPE_EXCHANGE : SaleReturn::TYPE_RETURN,
            'sale_id'          => $sale->id,
            'customer_id'      => $sale->customer_id,
            'branch_id'        => $sale->branch_id ?? Branch::currentId(),
            'total_refund'     => 0,
            'refund_method'    => $refundMethod,
            'settlement'       => $settlement,
            'refund_reference' => trim((string) ($input['refund_reference'] ?? '')) ?: null,
            'reason'           => isset(SaleReturn::REASONS[$input['reason'] ?? '']) ? $input['reason'] : null,
            'note'             => $input['note'] ?? null,
            'return_date'      => now()->toDateString(),
            'created_by'       => $user->id,
            'request_token'    => $token,
        ]);
        $return->update(['return_no' => 'SRN-' . str_pad((string) $return->id, 6, '0', STR_PAD_LEFT)]);

        $factor = $this->factor($sale, $details);
        foreach ($lines as $line) {
            $detail = $details[$line['detail_id']];
            $unitRefund = round($detail->unit_total / $detail->quantity * $factor, 2);
            foreach ($line['devices'] as $device) {
                SaleReturnItem::create([
                    'sale_return_id'  => $return->id,
                    'sale_detail_id'  => $detail->id,
                    'product_id'      => $detail->product_id,
                    'main_stock_id'   => $device->main_stock_id,
                    'quantity'        => 1,
                    'imei_no'         => $device->imei_no,
                    'product_imei_id' => $device->id,
                    'refund_price'    => $unitRefund,
                    'condition'       => $line['conditions'][$device->id],
                    'resale_eligible' => $line['conditions'][$device->id] === 'good',
                    'inspection_note' => $line['note'],
                ]);
            }
            if (!$line['devices']) {
                SaleReturnItem::create([
                    'sale_return_id'  => $return->id,
                    'sale_detail_id'  => $detail->id,
                    'product_id'      => $detail->product_id,
                    'main_stock_id'   => $detail->main_stock_id,
                    'quantity'        => $line['qty'],
                    'refund_price'    => round($unitRefund * $line['qty'], 2),
                    'condition'       => $line['condition'],
                    'resale_eligible' => $line['condition'] === 'good',
                    'inspection_note' => $line['note'],
                ]);
            }
        }

        AuditLogger::log('sale_return.requested', $return, null, $return->only(['return_no', 'sale_id', 'type', 'settlement', 'reason']));

        if ($canApprove) {
            $this->complete($return, $user, $input['exchange'] ?? null, null);
        }

        return $return->refresh();
    }

    public function approve(SaleReturn $return, User $user, ?string $note = null): SaleReturn
    {
        $return = SaleReturn::lockForUpdate()->findOrFail($return->id);
        if (!$return->isPending()) {
            throw new \Exception("Return {$return->return_no} is already {$return->status}.");
        }
        if (!$user->can('approve return')) {
            throw new \Exception('You are not allowed to approve returns.');
        }

        $this->complete($return, $user, null, $note);

        return $return->refresh();
    }

    public function reject(SaleReturn $return, User $user, string $reason): SaleReturn
    {
        $return = SaleReturn::lockForUpdate()->findOrFail($return->id);
        if (!$return->isPending()) {
            throw new \Exception("Return {$return->return_no} is already {$return->status}.");
        }

        $return->update(['status' => SaleReturn::STATUS_REJECTED, 'rejected_by' => $user->id, 'rejected_at' => now(), 'reject_reason' => $reason]);
        AuditLogger::log('sale_return.rejected', $return, ['status' => SaleReturn::STATUS_PENDING], ['status' => SaleReturn::STATUS_REJECTED, 'reason' => $reason]);

        return $return;
    }

    /**
     * Stock, IMEI, invoice, ledger ar taka - shob ekhane, return er line theke (pending er por obostha bodlate pare, tai abar jachai).
     */
    private function complete(SaleReturn $return, User $user, ?array $exchange, ?string $note): void
    {
        $sale = Sale::lockForUpdate()->findOrFail($return->sale_id);
        if ($sale->isCancelled()) {
            throw new \Exception("Invoice {$sale->invoice_no} is cancelled; the return cannot be completed.");
        }
        $details = SaleDetail::with('product')->where('sale_id', $sale->id)->lockForUpdate()->get()->keyBy('id');
        $items = SaleReturnItem::where('sale_return_id', $return->id)->get();
        $factor = $this->factor($sale, $details);
        $lineSum = round((float) $details->sum('unit_total'), 2);

        $totalRefund = 0;
        $subtotalReduction = 0;
        $returnCost = 0;
        foreach ($items->groupBy('sale_detail_id') as $detailId => $lineItems) {
            $detail = $details->get($detailId);
            $k = (int) $lineItems->sum('quantity');
            if (!$detail || $k > $detail->quantity) {
                throw new \Exception(($detail?->product->product_name ?? "Line #{$detailId}") . ': only ' . ($detail->quantity ?? 0) . ' left on this invoice to return.');
            }

            // line er nijer dam (item discount shoho) ar invoice discount/tax er bhag
            $lineReduction = round($detail->unit_total / $detail->quantity * $k, 2);
            $refund = round($lineReduction * $factor, 2);
            $totalRefund += $refund;
            $subtotalReduction += $lineReduction;
            $returnCost += $k * (float) $detail->purchase_price;   // bikrir somoyer kena dam (COGS ferot)

            // line er refund protita item e bhag (shesh ta te rounding er baki)
            $perUnit = round($refund / $k, 2);
            $left = $refund;
            foreach ($lineItems->values() as $i => $item) {
                $amount = $i === $lineItems->count() - 1 ? round($left, 2) : round($perUnit * $item->quantity, 2);
                $left = round($left - $amount, 2);
                $item->update(['refund_price' => $amount]);
            }

            $newQty = $detail->quantity - $k;
            $detail->item_discount = round($detail->item_discount * $newQty / $detail->quantity, 2);
            $detail->unit_total = $newQty > 0 ? round($detail->unit_total - $lineReduction, 2) : 0;
            $detail->quantity = $newQty;
            $detail->returned_quantity = (int) $detail->returned_quantity + $k;
            $detail->save();

            $this->restock($return, $sale, $detail, $lineItems, $k, $user);
        }

        // invoice er hishab: discount ar tax line er anupate kome; additional charge (service) ferot hoy na
        $totalRefund = round($totalRefund, 2);
        $share = $lineSum > 0 ? $subtotalReduction / $lineSum : 0;
        $taxBefore = round((float) $sale->tax_amount, 2);
        $dueBefore = round((float) $sale->due_amount, 2);
        $dueAdjusted = min($totalRefund, $dueBefore);
        $remainder = round($totalRefund - $dueAdjusted, 2);

        $sale->grand_total = round($sale->grand_total - $totalRefund, 2);
        $sale->subtotal = round($sale->subtotal - $subtotalReduction, 2);
        $sale->tax_amount = round((float) $sale->tax_amount * (1 - $share), 2);
        $sale->discount = max(0, round($sale->subtotal + (float) $sale->tax_amount + (float) $sale->additional_charge - $sale->grand_total, 2));
        // baki ta invoice theke ber hoye jay (ferot ba store credit)
        $sale->paid_amount = round($sale->paid_amount - $remainder, 2);
        $sale->due_amount = round($dueBefore - $dueAdjusted, 2);
        $sale->save();

        $this->ledger->customerEntry([
            'customer_id'      => $sale->customer_id,
            'date'             => now()->toDateString(),
            'transaction_type' => 'Return',
            'sale_id'          => $sale->id,
            'reference_no'     => $return->return_no,
            'credit'           => $totalRefund,
            'note'             => ($return->type === SaleReturn::TYPE_EXCHANGE ? 'Exchange return ' : 'Sale return ') . $return->return_no,
        ]);

        // store credit hisebe dhore nei (exchange e notun sale e lage), na hole sathe sathe ferot
        $return->update([
            'status'       => SaleReturn::STATUS_COMPLETED,
            'total_refund' => $totalRefund,
            'due_adjusted' => $dueAdjusted,
            'store_credit' => $remainder,
            'cash_refund'  => 0,
            'approved_by'  => $user->id,
            'approved_at'  => now(),
        ]);
        // journal: Dr Sales Returns + Tax Payable (tax er bhag), Cr Receivable; Dr Inventory, Cr COGS
        $this->finance->postSaleReturn($return->setRelation('sale', $sale), round($taxBefore - (float) $sale->tax_amount, 2), $returnCost);

        $exchangeSale = null;
        if ($exchange) {
            $exchangeSale = $this->sales->checkout([
                'customer_id'        => $sale->customer_id,
                'sale_date'          => now()->toDateString(),
                'stock_ids'          => $exchange['stock_ids'],
                'qtys'               => $exchange['qtys'] ?? [],
                'sale_prices'        => $exchange['sale_prices'] ?? [],
                'item_discounts'     => $exchange['item_discounts'] ?? [],
                'imeis'              => $exchange['imeis'] ?? [],
                'discount'           => $exchange['discount'] ?? 0,
                'payments'           => $exchange['payments'] ?? [],
                'apply_store_credit' => $remainder,
                'salesperson_id'     => $exchange['salesperson_id'] ?? null,
                'expected_total'     => $exchange['expected_total'] ?? null,
                'note'               => "Exchange for {$return->return_no} (invoice {$sale->invoice_no})",
            ], $user);
        }

        $creditUsed = $exchangeSale
            ? round((float) SalePayment::where('sale_id', $exchangeSale->id)->where('channel', 'credit')->sum('amount'), 2) : 0.0;
        $leftover = round($remainder - $creditUsed, 2);

        $cashRefund = 0.0;
        if ($leftover > 0 && $return->settlement === SaleReturn::SETTLE_REFUND) {
            $cashRefund = $leftover;
            $method = $return->refund_method ?: 'Cash';
            $channel = SalePaymentService::assertMethod($method, null);
            $entry = $this->ledger->customerEntry([
                'customer_id'      => $sale->customer_id,
                'date'             => now()->toDateString(),
                'transaction_type' => 'Refund Paid',
                'sale_id'          => $sale->id,
                'reference_no'     => $return->return_no,
                'debit'            => $cashRefund,
                'note'             => "Refund for {$return->return_no} ({$method})",
            ]);
            $this->finance->postSalePayment(SalePayment::create([
                'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'type' => SalePayment::TYPE_REFUND,
                'method' => $method, 'channel' => $channel, 'amount' => $cashRefund, 'reference' => $return->refund_reference,
                'payment_date' => now()->toDateString(), 'received_by' => $user->id, 'customer_ledger_id' => $entry->id,
                'note' => 'Return ' . $return->return_no,
            ]));
        }

        $return->update([
            'cash_refund'      => $cashRefund,
            'store_credit'     => round($remainder - $cashRefund, 2),   // exchange e khoroch hoyeche + ja credit e thaklo
            'exchange_sale_id' => $exchangeSale?->id,
        ]);

        // ferot device/qty er warranty batil; exchange e notun device er warranty policy onujayi
        $this->warranties->applyReturn($return, $exchangeSale);

        // kisti thakle bakir sathe milano
        $this->payments->syncScheduleToDue($sale);

        AuditLogger::log('sale_return.completed', $return, ['status' => SaleReturn::STATUS_PENDING], [
            'status' => SaleReturn::STATUS_COMPLETED, 'refund' => $totalRefund, 'due_adjusted' => $dueAdjusted, 'cash_refund' => $cashRefund,
            'store_credit' => round($remainder - $cashRefund - $creditUsed, 2), 'exchange_sale' => $exchangeSale?->invoice_no, 'note' => $note,
        ]);
    }

    // Stock ferot + IMEI (inspection onujayi); accessory nosto hole stock e ese "damaged" hisebe ber
    private function restock(SaleReturn $return, Sale $sale, SaleDetail $detail, $items, int $k, User $user): void
    {
        $stock = MainStock::lockForUpdate()->find($detail->main_stock_id)
            ?? MainStock::where(['product_id' => $detail->product_id, 'ram_id' => $detail->ram_id, 'rom_id' => $detail->rom_id, 'color_id' => $detail->color_id])
                ->lockForUpdate()->first();
        if (!$stock) {
            throw new \Exception("Stock record for {$detail->product->product_name} was not found.");
        }

        $devices = ProductImei::whereIn('id', $items->pluck('product_imei_id')->filter())->lockForUpdate()->get()->keyBy('id');
        $branches = $devices->pluck('branch_id')->filter()->unique();
        $branchId = $branches->count() === 1 ? (int) $branches->first() : ($sale->branch_id ?? Branch::currentId());
        $this->stock->move($stock, $k, StockService::SALE_RETURN, $return, null, $branchId, "sale_return:{$return->id}:{$detail->id}");

        foreach ($items as $item) {
            if ($item->product_imei_id) {
                $device = $devices[$item->product_imei_id] ?? null;
                // pending er somoy onno kothao gele (ba agei ferot) ar hoy na
                if (!$device || (int) $device->status !== ProductImei::STATUS_SOLD || (int) $device->sale_id !== (int) $sale->id) {
                    throw new \Exception("IMEI {$item->imei_no} is no longer sold on invoice {$sale->invoice_no}.");
                }
                $status = $item->condition === 'good' ? ProductImei::STATUS_AVAILABLE : ProductImei::STATUS_DAMAGED;
                $this->imeis->releaseFromSale($device, $return, $status, (int) $sale->customer_id,
                    "Returned by customer from invoice {$sale->invoice_no} ({$item->condition})" . ($item->inspection_note ? ': ' . $item->inspection_note : ''));
                if ($item->condition === 'needs_repair') {
                    $this->imeis->changeStatus($device, ProductImei::STATUS_IN_REPAIR, 'Customer return ' . $return->return_no . ' needs repair');
                }
            } elseif ($item->condition !== 'good') {
                // accessory nosto: stock e ese sathe sathe damaged (bikri jogyo na)
                $this->stock->move($stock, -(int) $item->quantity, StockService::DAMAGED, $return, 'Returned damaged by customer',
                    $branchId, "sale_return_damaged:{$return->id}:{$detail->id}");
            }
        }
    }

    /**
     * Form theke line: IMEI (oi invoice e bikri, ekhono sold, onno pending return e na) ar IMEI chara qty
     * (line e baki - pending return er qty er beshi na). Fere [detail_id, qty, devices[], conditions, condition, note].
     */
    private function collectLines(Sale $sale, $details, array $input): array
    {
        $lines = [];
        $pendingDevices = SaleReturnItem::whereNotNull('product_imei_id')
            ->whereHas('saleReturn', fn ($q) => $q->where('status', SaleReturn::STATUS_PENDING))->pluck('product_imei_id')->flip();

        $seen = [];
        foreach (array_unique(array_filter((array) ($input['imei_nos'] ?? []))) as $number) {
            // shudhu ei invoice e bikri howa IMEI ferot neya jabe (IMEI 1 ba IMEI 2)
            $device = ProductImei::whereNumber((string) $number)->where('sale_id', $sale->id)->where('status', ProductImei::STATUS_SOLD)
                ->lockForUpdate()->first();
            if (!$device || !$details->has($device->sale_detail_id)) {
                throw new \Exception("IMEI {$number} was not sold in invoice {$sale->invoice_no}.");
            }
            if (isset($pendingDevices[$device->id])) {
                throw new \Exception("IMEI {$number} is already on a pending return.");
            }
            // ekoi phone er IMEI 1 ar IMEI 2 duitai dile ekbar-i ferot
            if (isset($seen[$device->id])) {
                continue;
            }
            $seen[$device->id] = true;

            $raw = $input['imei_condition'][$number] ?? $input['imei_condition'][$device->imei_no] ?? 'good';
            $condition = $raw === 'available' ? 'good' : $raw;   // purono form: available/damaged
            if (!in_array($condition, self::CONDITIONS, true)) {
                throw new \Exception("Unknown condition for IMEI {$number}.");
            }

            $id = $device->sale_detail_id;
            $lines[$id] ??= ['detail_id' => $id, 'qty' => 0, 'devices' => [], 'conditions' => [], 'condition' => 'good',
                'note' => $input['inspection_note'][$id] ?? null];
            $lines[$id]['devices'][] = $device;
            $lines[$id]['conditions'][$device->id] = $condition;
            $lines[$id]['qty']++;
        }

        foreach ((array) ($input['items'] ?? []) as $detailId => $qty) {
            if ((int) $qty <= 0) {
                continue;
            }
            $detail = $details->get($detailId);
            if (!$detail) {
                throw new \Exception("Item #{$detailId} is not part of invoice {$sale->invoice_no}.");
            }
            // IMEI wala line e IMEI diye ferot (accessory te IMEI lage na)
            $hasImei = ProductImei::where('sale_detail_id', $detail->id)->where('status', ProductImei::STATUS_SOLD)->exists();
            if ($hasImei || $detail->product->requiresImei()) {
                throw new \Exception("{$detail->product->product_name}: select the IMEI of the phone being returned.");
            }
            $condition = $input['item_condition'][$detailId] ?? 'good';
            if (!in_array($condition, self::CONDITIONS, true)) {
                throw new \Exception("{$detail->product->product_name}: unknown condition.");
            }
            $lines[$detail->id] = ['detail_id' => $detail->id, 'qty' => (int) $qty, 'devices' => [], 'conditions' => [],
                'condition' => $condition, 'note' => $input['inspection_note'][$detailId] ?? null];
        }

        if (!$lines) {
            throw new \Exception('Select at least one item to return.');
        }

        // bikri qty r beshi na (onno pending return er qty o dhore)
        foreach ($lines as $id => $line) {
            $detail = $details[$id];
            $pending = (int) SaleReturnItem::where('sale_detail_id', $id)
                ->whereHas('saleReturn', fn ($q) => $q->where('status', SaleReturn::STATUS_PENDING))->sum('quantity');
            if ($line['qty'] > $detail->quantity - $pending) {
                throw new \Exception("{$detail->product->product_name}: only " . max(0, $detail->quantity - $pending)
                    . " left on invoice {$sale->invoice_no} to return" . ($pending ? " ({$pending} on a pending return)" : '') . '.');
            }
        }

        return $lines;
    }

    // Line er dam theke ferot: (grand_total - additional charge) / line total er jogfol
    private function factor(Sale $sale, $details): float
    {
        $lineTotal = (float) $details->sum('unit_total');

        return $lineTotal > 0 ? max(0, ((float) $sale->grand_total - (float) $sale->additional_charge) / $lineTotal) : 1;
    }
}
