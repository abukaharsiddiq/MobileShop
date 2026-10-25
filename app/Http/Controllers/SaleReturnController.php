<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\ProductImei;
use App\Models\MainStock;
use App\Models\CustomerLedger;
use App\Services\ImeiService;
use App\Services\LedgerService;
use App\Services\SalePaymentService;
use App\Services\StockService;
use DB;
use Auth;

class SaleReturnController extends Controller
{

    private $heading;
    public function __construct(
        private ImeiService $imeiService,
        private StockService $stockService,
        private LedgerService $ledger,
        private SalePaymentService $payments,
    )
    {
        $this->heading = 'Sale Return';
    }

    public function index()
{
    $returns = SaleReturn::with(['customer', 'sale'])
                ->orderBy('id', 'desc')
                ->get();
                
    return view('back-end.sale-return.index', compact('returns'));
}

public function show($id)
{
    $return = SaleReturn::with(['customer', 'sale', 'returnItems.product'])->findOrFail($id);
    return view('back-end.sale-return.details_modal', compact('return'));
}

   public function create() {
        $name = $this->heading;
        $title = $this->heading;
        return view('back-end.sale-return.create', compact('name', 'title'));
    }

public function findInvoiceForReturn(Request $request)
{
    $sale = Sale::with(['customer', 'items.product', 'items.ram', 'items.rom', 'items.color'])
        ->where('invoice_no', $request->invoice_no)
        ->first();

    if ($sale) {
        $factor = $this->invoiceDiscountFactor($sale, $sale->items);

        foreach ($sale->items as $item) {
            // ei line e bikri howa (ekhono ferot na) IMEI
            $item->imeis123 = ProductImei::where('sale_detail_id', $item->id)
                ->where('sale_id', $sale->id)
                ->where('status', ProductImei::STATUS_SOLD)
                ->get(['id', 'imei_no']);

            $item->imei_tracked = $item->imeis123->isNotEmpty() || $item->product->requiresImei();
            // ekta unit ferot dile koto taka ferot (item discount ar invoice discount er bhag bad diye)
            $item->refund_unit_price = $item->quantity > 0 ? round($item->unit_total / $item->quantity * $factor, 2) : 0;
        }

        return response()->json(['status' => 'success', 'sale' => $sale]);
    }

    return response()->json(['status' => 'error', 'message' => 'Invoice not found']);
}


public function store(Request $request)
{
    $request->validate([
        'sale_id'       => 'required|exists:sales,id',
        'imei_nos'      => 'nullable|array',
        'items'         => 'nullable|array',      // [sale_detail_id => qty] IMEI chara item (accessory)
        'items.*'       => 'nullable|integer|min:0',
        'refund_method' => 'nullable|string|max:50',
        'note'          => 'nullable|string',
    ]);

    DB::beginTransaction();
    try {
        $sale = Sale::lockForUpdate()->findOrFail($request->sale_id);
        $details = SaleDetail::with('product')->where('sale_id', $sale->id)->lockForUpdate()->get()->keyBy('id');
        $factor = $this->invoiceDiscountFactor($sale, $details);

        // ১. kon line theke koyta ferot
        $lines = []; // detail_id => ['qty' => k, 'imeis' => [...]]

        foreach ((array) $request->imei_nos as $imei_no) {
            // shudhu ei invoice e bikri howa IMEI ferot neya jabe
            $imei = ProductImei::where('imei_no', $imei_no)
                ->where('sale_id', $sale->id)
                ->where('status', ProductImei::STATUS_SOLD)
                ->lockForUpdate()
                ->first();

            if (!$imei || !$details->has($imei->sale_detail_id)) {
                throw new \Exception("IMEI {$imei_no} was not sold in invoice {$sale->invoice_no}.");
            }

            $lines[$imei->sale_detail_id]['imeis'][] = $imei;
            $lines[$imei->sale_detail_id]['qty'] = ($lines[$imei->sale_detail_id]['qty'] ?? 0) + 1;
        }

        foreach ((array) $request->items as $detailId => $qty) {
            if ((int) $qty <= 0) {
                continue;
            }
            $detail = $details->get($detailId);
            if (!$detail) {
                throw new \Exception("Item #{$detailId} is not part of invoice {$sale->invoice_no}.");
            }
            $hasImei = ProductImei::where('sale_detail_id', $detail->id)->where('status', ProductImei::STATUS_SOLD)->exists();
            if ($hasImei || $detail->product->requiresImei()) {
                throw new \Exception("{$detail->product->product_name}: select the IMEI of the phone being returned.");
            }
            $lines[$detail->id]['imeis'] = [];
            $lines[$detail->id]['qty'] = (int) $qty;
        }

        if (!$lines) {
            throw new \Exception('Select at least one item to return.');
        }

        // ২. রিটার্ন মাস্টার রেকর্ড (taka niche hishab hoye update hoy)
        $saleReturn = new SaleReturn();
        $saleReturn->sale_id = $sale->id;
        $saleReturn->customer_id = $sale->customer_id;
        $saleReturn->total_refund = 0;
        $saleReturn->refund_method = $request->refund_method;
        $saleReturn->note = $request->note;
        $saleReturn->return_date = now();
        $saleReturn->created_by = auth()->id();
        $saleReturn->save();

        $totalRefund = 0;
        $subtotalReduction = 0;

        // ৩. আইটেম এবং স্টক প্রসেসিং
        foreach ($lines as $detailId => $line) {
            $detail = $details->get($detailId);
            $k = $line['qty'];

            if ($k > $detail->quantity) {
                throw new \Exception("{$detail->product->product_name}: only {$detail->quantity} left on this invoice to return.");
            }

            // protiti line er nijer dam (item discount shoho) ar invoice discount er bhag
            $lineReduction = round($detail->unit_total / $detail->quantity * $k, 2);
            $refund = round($lineReduction * $factor, 2);
            $totalRefund += $refund;
            $subtotalReduction += $lineReduction;

            // ডিটেইল টেবিল থেকে কমানো
            $newQty = $detail->quantity - $k;
            $detail->item_discount = $detail->quantity > 0 ? round($detail->item_discount * $newQty / $detail->quantity) : 0;
            $detail->unit_total = $newQty > 0 ? round($detail->unit_total - $lineReduction, 2) : 0;
            $detail->quantity = $newQty;
            $detail->save();

            // মেইন স্টক বাড়ানো (ভ্যারিয়েন্ট অনুযায়ী) + stock ledger
            $stock = MainStock::where('product_id', $detail->product_id)
                ->where('ram_id', $detail->ram_id)
                ->where('rom_id', $detail->rom_id)
                ->where('color_id', $detail->color_id)
                ->lockForUpdate()
                ->first();
            if (!$stock) {
                throw new \Exception("Stock record for {$detail->product->product_name} was not found.");
            }
            $this->stockService->move($stock, $k, StockService::SALE_RETURN, $saleReturn);

            if ($line['imeis']) {
                $perUnit = round($refund / $k, 2);
                foreach ($line['imeis'] as $i => $imei) {
                    // shesh unit e rounding er baki tuku
                    $unitRefund = $i == $k - 1 ? round($refund - $perUnit * ($k - 1), 2) : $perUnit;

                    SaleReturnItem::create([
                        'sale_return_id' => $saleReturn->id,
                        'sale_detail_id' => $detail->id,
                        'product_id'     => $detail->product_id,
                        'quantity'       => 1,
                        'imei_no'        => $imei->imei_no,
                        'refund_price'   => $unitRefund,
                    ]);

                    // ekoi IMEI onno kono record e active thakle (clone/bhul entry) ferot neya jabe na
                    $this->imeiService->assertNotActive([$imei->imei_no]);

                    // IMEI স্ট্যাটাস রিসেট (purono sale er tottho imei_movements e thake)
                    $imei->update([
                        'status'         => ProductImei::STATUS_IN_STOCK,
                        'sale_id'        => null,
                        'sale_detail_id' => null
                    ]);
                    $this->imeiService->record($imei, ImeiService::SALE_RETURN, $saleReturn,
                        "Returned by customer from invoice {$sale->invoice_no}");
                }
            } else {
                SaleReturnItem::create([
                    'sale_return_id' => $saleReturn->id,
                    'sale_detail_id' => $detail->id,
                    'product_id'     => $detail->product_id,
                    'quantity'       => $k,
                    'imei_no'        => null,
                    'refund_price'   => $refund,
                ]);
            }
        }

        // ৪. সেলের হিসাব: refund age due theke kate, baki ta nogod ferot
        $totalRefund = round($totalRefund, 2);
        $dueBefore = round((float) $sale->due_amount, 2);
        $dueAdjusted = min($totalRefund, $dueBefore);
        $cashRefund = round($totalRefund - $dueAdjusted, 2);

        $sale->grand_total = round($sale->grand_total - $totalRefund, 2);
        $sale->subtotal = round($sale->subtotal - $subtotalReduction, 2);
        $sale->discount = max(0, round($sale->subtotal - $sale->grand_total, 2));
        $sale->paid_amount = round($sale->paid_amount - $cashRefund, 2);
        $sale->due_amount = round($dueBefore - $dueAdjusted, 2);
        $sale->save();

        $saleReturn->update([
            'total_refund' => $totalRefund,
            'due_adjusted' => $dueAdjusted,
            'cash_refund'  => $cashRefund,
        ]);

        // ৫. লেজার: ferot er dam customer er pawna theke kome; nogod ferot dile seta alada entry
        $this->ledger->customerEntry([
            'customer_id'      => $sale->customer_id,
            'date'             => now()->toDateString(),
            'transaction_type' => 'Return',
            'sale_id'          => $sale->id,
            'reference_no'     => 'RET-' . $saleReturn->id,
            'credit'           => $totalRefund,
            'note'             => 'Sale Return. Total Refund: ' . number_format($totalRefund, 2),
        ]);
        if ($cashRefund > 0) {
            $this->ledger->customerEntry([
                'customer_id'      => $sale->customer_id,
                'date'             => now()->toDateString(),
                'transaction_type' => 'Refund Paid',
                'sale_id'          => $sale->id,
                'reference_no'     => 'RET-' . $saleReturn->id,
                'debit'            => $cashRefund,
                'note'             => 'Cash refunded to customer' . ($request->refund_method ? ' (' . $request->refund_method . ')' : ''),
            ]);
        }

        // kisti thakle bakir sathe milano
        $this->payments->syncScheduleToDue($sale);

        DB::commit();
        $message = 'Return processed and stock updated! Refund ' . number_format($totalRefund, 2)
            . ($cashRefund > 0 ? ', give ' . number_format($cashRefund, 2) . ' back to the customer.' : ' adjusted against due.');
        return redirect()->route('sale.index')->with('success', $message);

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }
}

// Invoice discount er bhag: grand_total / (line total er jogfol). Discount na thakle 1.
private function invoiceDiscountFactor(Sale $sale, $details)
{
    $lineTotal = $details->sum('unit_total');

    return $lineTotal > 0 ? min(1, max(0, $sale->grand_total / $lineTotal)) : 1;
}

}
