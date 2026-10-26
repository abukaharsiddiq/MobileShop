<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\MainStock;
use App\Models\Branch;
use App\Models\CustomerLedger;
use App\Models\ProductImei;
use App\Services\AuditLogger;
use App\Services\ImeiService;
use App\Services\InvoiceNumberService;
use App\Services\LedgerService;
use App\Services\SalePaymentService;
use App\Services\StockService;
use DB;
use Auth;
use Brian2694\Toastr\Facades\Toastr;

class SaleController extends Controller
{
    private $heading;
    public function __construct(
        private ImeiService $imeiService,
        private StockService $stockService,
        private InvoiceNumberService $invoiceNumbers,
        private LedgerService $ledger,
        private SalePaymentService $payments,
    )
    {
        $this->heading = 'Sale';
    }

public function index(){

    $name = 'Sale List';
    $title = 'Manage Sales';
    
    $sales = Sale::with('customer')
                 ->orderBy('id', 'desc')
                 ->get();

    return view('back-end.sale.index', compact('sales', 'name', 'title'));
}

public function show($id)
{
    $name = 'Sale Details';
    $title = 'Invoice #' . $id;

    $sale = Sale::with([
        'customer', 
        'items.product', 
        'items.ram', 
        'items.rom', 
        'items.color',
        'items.imeis'
    ])->findOrFail($id);

    return view('back-end.sale.show', compact('sale', 'name', 'title'));
}

public function updatePayment(Request $request)
{
    $request->validate([
        'sale_id' => 'required|exists:sales,id',
        'paid_amount' => 'required|numeric|min:0.01',
        'payment_date' => 'required|date',
    ]);

    try {
        DB::beginTransaction();

        // due er beshi neya jay na; kisti thakle kisti te bhag hoy; ledger e entry
        $this->payments->receive(Sale::findOrFail($request->sale_id), (float) $request->paid_amount,
            $request->payment_date, $request->payment_method, $request->note);

        DB::commit();
        Toastr::success('Payment updated successfully!');
        return back();

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }
}

    public function create(){
        $name= $this->heading;
        $title = $this->heading;
        // shudhu dekhanor jonno; asol number save er somoy toiri hoy
        $SaleinvoiceNo = $this->invoiceNumbers->previewSale();
        $customers = Customer::where('status', 1)->get();
        // shudhu active product er active variant (inactive bikri hoy na)
        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])->where('quantity', '>', 0)->active()->get();
        return view('back-end.sale.create',compact('name','title','customers','stocks','SaleinvoiceNo'));
    }


public function store(Request $request)
{
    $request->validate([
        'customer_id' => 'required|exists:customers,id',
        'sale_date'   => 'required|date',
        'stock_ids'   => 'required|array|min:1',
        'stock_ids.*' => 'required|exists:main_stocks,id',
        'qtys'        => 'required|array',
        'qtys.*'      => 'required|integer|min:1',
        'sale_prices'   => 'required|array',
        'sale_prices.*' => 'required|numeric|min:0',
        'item_discounts.*' => 'nullable|numeric|min:0',
        'imeis'       => 'nullable|array',
        'imeis.*'     => 'nullable|array',
        'discount'    => 'nullable|numeric|min:0',
        'paid_amount' => 'required|numeric|min:0',
        'is_installment'        => 'nullable|in:0,1',
        'total_installments'    => 'required_if:is_installment,1|nullable|integer|min:1|max:60',
        'next_installment_date' => 'nullable|date',
    ]);

    try {
        DB::beginTransaction();

        $canSellBelowCost = $request->user()->can('sell below cost');

        // inactive customer ke notun bikri na (ager due/ferot cholbe)
        $customer = Customer::lockForUpdate()->findOrFail($request->customer_id);
        if ((int) $customer->status !== 1) {
            throw new \Exception("{$customer->name} is inactive. Activate the customer before selling.");
        }

        $sale = new Sale();
        $sale->invoice_no      = $this->invoiceNumbers->nextSale();
        $sale->customer_id     = $request->customer_id;
        $sale->sale_date       = $request->sale_date;
        $sale->subtotal        = 0;
        $sale->discount        = 0;
        $sale->grand_total     = 0;
        $sale->paid_amount     = 0;
        $sale->due_amount      = 0;
        $sale->payment_method  = $request->payment_method;
        $sale->payment_account = $request->payment_account;
        $sale->created_by      = auth()->id();
        $sale->save();

        $subtotal = 0;
        $totalCost = 0;
        $belowCost = [];

        foreach ($request->stock_ids as $key => $stock_id) {
            $qty = (int) ($request->qtys[$key] ?? 0);

            // Variant (RAM/ROM/Color) main_stock_id diye; lock kore jate eki somoy duijon eki stock bikri na kore
            $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($stock_id);
            $label = $stock->variantLabel();

            if (!$stock->isSellable()) {
                throw new \Exception("{$label} is inactive and cannot be sold.");
            }

            // stock row lock kora (upore); ei mot stock er beshi na (config dile IMEI chara product e negative jete pare)
            if ($qty > $stock->quantity && !StockService::allowsNegative($stock)) {
                throw new \Exception("Not enough stock for {$label}. Available: {$stock->quantity}, requested: {$qty}." . StockService::negativeHint($stock));
            }

            $imeiIds = array_values(array_unique(array_filter((array) ($request->imeis[$key] ?? []))));

            // smartphone: registered IMEI chara bikri hobe na; accessory: IMEI na
            $this->imeiService->assertProductRules($stock->product, $qty, $imeiIds, $label);

            $imeis = collect();
            if (count($imeiIds) > 0) {
                // IMEI ta ei variant er kina (lock shoho, jate duijon ekshathe ekoi phone bikri na kore)
                $imeis = ProductImei::whereIn('id', $imeiIds)
                    ->where('main_stock_id', $stock->id)
                    ->lockForUpdate()
                    ->get();

                if ($imeis->count() != count($imeiIds)) {
                    throw new \Exception("{$label}: one or more selected IMEI do not belong to this product variant.");
                }

                // available hote hobe (ba ei customer er jonno reserved); sold/repair/damaged na
                $notSellable = $imeis->reject(fn ($i) => $i->isSellableTo((int) $request->customer_id));
                if ($notSellable->isNotEmpty()) {
                    throw new \Exception("{$label}: these IMEI cannot be sold: "
                        . $notSellable->map(fn ($i) => "{$i->imei_no} ({$i->statusText()})")->implode(', '));
                }

                // onno branch e thaka phone ei user bikri korte parbe na
                $otherBranch = $imeis->reject(fn ($i) => $request->user()->canAccessBranch($i->branch_id));
                if ($otherBranch->isNotEmpty()) {
                    throw new \Exception("{$label}: these IMEI are in another branch: " . $otherBranch->pluck('imei_no')->implode(', '));
                }
            }

            // line er hishab server e (browser er total bishwas kora hoy na)
            $price = round((float) $request->sale_prices[$key], 2);
            $itemDiscount = round((float) ($request->item_discounts[$key] ?? 0));
            $lineGross = round($qty * $price, 2);
            if ($itemDiscount > $lineGross) {
                throw new \Exception("{$label}: discount cannot be more than the line total.");
            }
            $lineTotal = round($lineGross - $itemDiscount, 2);
            $cost = (float) ($stock->average_purchase_price ?? 0);

            if ($lineTotal / $qty < $cost) {
                $belowCost[] = "{$label}: selling at " . number_format($lineTotal / $qty, 2) . ', cost ' . number_format($cost, 2);
            }

            $item = new SaleDetail();
            $item->sale_id        = $sale->id;
            $item->product_id     = $stock->product_id;
            $item->ram_id         = $stock->ram_id;
            $item->rom_id         = $stock->rom_id;
            $item->color_id       = $stock->color_id;
            $item->quantity       = $qty;
            $item->purchase_price = $cost;
            $item->sale_price     = $price;
            $item->item_discount  = $itemDiscount;
            $item->unit_total     = $lineTotal;
            $item->save();

            $subtotal += $lineTotal;
            $totalCost += $qty * $cost;

            foreach ($imeis as $imei) {
                $this->imeiService->sell($imei, $sale, $item->id, (int) $sale->customer_id);
            }

            // phone je branch e chilo sei branch theke; IMEI chara hole user er branch. Ekoi line duibar na (key)
            $branchId = $imeis->pluck('branch_id')->filter()->unique()->count() === 1 ? (int) $imeis->first()->branch_id : Branch::currentId();
            $this->stockService->move($stock, -$qty, StockService::SALE, $sale, null, $branchId, 'sale:' . $item->id);
        }

        // invoice er hishab
        $subtotal = round($subtotal, 2);
        $discount = round((float) ($request->discount ?? 0), 2);
        if ($discount > $subtotal) {
            throw new \Exception('Invoice discount cannot be more than the subtotal.');
        }
        $grandTotal = round($subtotal - $discount, 2);
        if ($grandTotal < round($totalCost, 2)) {
            $belowCost[] = 'Invoice total ' . number_format($grandTotal, 2) . ' is below cost ' . number_format($totalCost, 2);
        }

        // kroymuller niche bikri shudhu jader permission ache (audit log e thake)
        if ($belowCost) {
            if (!$canSellBelowCost) {
                throw new \Exception('Sale price is below purchase cost. ' . implode('; ', $belowCost));
            }
            AuditLogger::log('sale.below_cost', $sale, null, ['lines' => $belowCost]);
        }

        $paid = round((float) $request->paid_amount, 2);
        if ($paid > $grandTotal) {
            throw new \Exception('Paid amount (' . number_format($paid, 2) . ') cannot be more than the grand total (' . number_format($grandTotal, 2) . ').');
        }

        $sale->subtotal    = $subtotal;
        $sale->discount    = $discount;
        $sale->grand_total = $grandTotal;
        $sale->paid_amount = $paid;
        $sale->due_amount  = round($grandTotal - $paid, 2);
        $sale->save();

        if ($request->is_installment == 1) {
            if ($sale->due_amount <= 0) {
                throw new \Exception('Nothing is due, so an installment plan is not needed.');
            }
            $firstDate = \Carbon\Carbon::parse($request->next_installment_date ?? now()->addMonth());
            $this->payments->createSchedule($sale, (int) $request->total_installments, $firstDate);
        }

        $this->ledger->customerEntry([
            'customer_id'      => $sale->customer_id,
            'date'             => $sale->sale_date,
            'transaction_type' => 'Sale',
            'sale_id'          => $sale->id,
            'reference_no'     => $sale->invoice_no,
            'debit'            => $grandTotal,
            'credit'           => $paid,
            'note'             => 'Sale Invoice: ' . $sale->invoice_no,
        ]);

        DB::commit();
        Toastr::success('Sale Added Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('sale.index')->with([
            'print_sale_id' => $sale->id,
            'auto_print' => true
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e))->withInput();
    }
}

public function getAvailableImeis($stock_id)
{
    // shudhu ei variant er available (ar reserved - shudhu oi customer ke bikri jay) IMEI, user er branch er
    $imeis = ProductImei::with('reservedCustomer')
                ->where('main_stock_id', $stock_id)
                ->whereIn('status', [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_RESERVED])
                ->accessibleBy(request()->user())
                ->orderBy('status')
                ->get()
                ->map(fn ($i) => [
                    'id'      => $i->id,
                    'imei_no' => $i->imei_no,
                    'label'   => $i->numbersLabel() . ((int) $i->status === ProductImei::STATUS_RESERVED
                        ? ' (reserved: ' . ($i->reservedCustomer->name ?? 'customer') . ')' : ''),
                ]);

    return response()->json($imeis);
}

public function printInvoice($id)
{

    $sale = Sale::with(['customer', 'saleDetails.product'])
                ->findOrFail($id);

    $setting = \DB::table('settings')->first(); 

    foreach ($sale->saleDetails as $item) {
        $item->imeis = \DB::table('product_imeis')
                          ->where('sale_detail_id', $item->id)
                          ->pluck('imei_no')
                          ->implode(', ');
    }

    return view('back-end.sale.print', compact('sale', 'setting'));
}

private function bnMoney($number) {
    $bn_digits = [
        0 => '', 1 => 'এক', 2 => 'দুই', 3 => 'তিন', 4 => 'চার', 5 => 'পাঁচ', 6 => 'ছয়', 7 => 'সাত', 8 => 'আট', 9 => 'নয়',
        10 => 'দশ', 11 => 'এগারো', 12 => 'বারো', 13 => 'তেরো', 14 => 'চৌদ্দ', 15 => 'পনেরো', 16 => 'ষোলো', 17 => 'সতেরো', 18 => 'আঠারো', 19 => 'উনিশ',
        20 => 'বিশ', 21 => 'একুশ', 22 => 'বাইশ', 23 => 'তেইশ', 24 => 'চব্বিশ', 25 => 'পঁচিশ', 26 => 'ছাব্বিশ', 27 => 'সাতাশ', 28 => 'আটাশ', 29 => 'উনত্রিশ',
        30 => 'ত্রিশ', 31 => 'একত্রিশ', 32 => 'বত্রিশ', 33 => 'তেত্রিশ', 34 => 'চৌত্রিশ', 35 => 'পঁয়ত্রিশ', 36 => 'ছত্রিশ', 37 => 'সাঁইত্রিশ', 38 => 'আটত্রিশ', 39 => 'উনচল্লিশ',
        40 => 'চল্লিশ', 41 => 'একচল্লিশ', 42 => 'বিয়াল্লিশ', 43 => 'তেতাল্লিশ', 44 => 'চুয়াল্লিশ', 45 => 'পঁয়তাল্লিশ', 46 => 'ছেচল্লিশ', 47 => 'সাতচল্লিশ', 48 => 'আটচল্লিশ', 49 => 'উনপঞ্চাশ',
        50 => 'পঞ্চাশ', 51 => 'একান্ন', 52 => 'বায়ান্ন', 53 => 'তিপ্পান্ন', 54 => 'চুয়ান্ন', 55 => 'পঞ্চান্ন', 56 => 'ছাপ্পান্ন', 57 => 'সাতান্ন', 58 => 'আটান্ন', 59 => 'উনষাট',
        60 => 'ষাট', 61 => 'একষট্টি', 62 => 'বাষট্টি', 63 => 'তেষট্টি', 64 => 'চৌষট্টি', 65 => 'পঁয়ষট্টি', 66 => 'ছেষট্টি', 67 => 'সাতষট্টি', 68 => 'আটষট্টি', 69 => 'উনসত্তর',
        70 => 'সত্তর', 71 => 'একাত্তর', 72 => 'বাহাত্তর', 73 => 'তেয়াত্তর', 74 => 'চুয়াত্তর', 75 => 'পঁচাত্তর', 76 => 'ছিয়াত্তর', 77 => 'সাতাত্তর', 78 => 'আটাত্তর', 79 => 'উনআশি',
        80 => 'আশি', 81 => 'একাশি', 82 => 'বিরাশি', 83 => 'তিরাশি', 84 => 'চুরাশি', 85 => 'পঁচাশী', 86 => 'ছিয়াশি', 87 => 'সাতাশি', 88 => 'অষ্টাশি', 89 => 'উননব্বই',
        90 => 'নব্বই', 91 => 'একানব্বই', 92 => 'বিরানব্বই', 93 => 'তিরানব্বই', 94 => 'চুরানব্বই', 95 => 'পঁচানব্বই', 96 => 'ছিয়ানব্বই', 97 => 'সাতানব্বই', 98 => 'আটানব্বই', 99 => 'নিরানব্বই'
    ];

    $number = (int)$number;
    if ($number == 0) return '';

    $output = '';

    // কোটি
    if ($number >= 10000000) {
        $output .= $this->bnMoney(floor($number / 10000000)) . ' কোটি ';
        $number %= 10000000;
    }

    // লাখ
    if ($number >= 100000) {
        $lakh = floor($number / 100000);
        $output .= $bn_digits[$lakh] . ' লাখ ';
        $number %= 100000;
    }

    // হাজার
    if ($number >= 1000) {
        $thousand = floor($number / 1000);
        $output .= $bn_digits[$thousand] . ' হাজার ';
        $number %= 1000;
    }

    // শতক
    if ($number >= 100) {
        $hundred = floor($number / 100);
        $output .= $bn_digits[$hundred] . ' শত ';
        $number %= 100;
    }

    // অবশিষ্ট (১-৯৯)
    if ($number > 0) {
        $output .= $bn_digits[$number];
    }

    return trim($output);
}

private function amountInWords($number) {
    $hyphen      = '-';
    $conjunction = ' and ';
    $separator   = ', ';
    $negative    = 'negative ';
    $decimal     = ' point ';
    $dictionary  = array(
        0                   => 'zero',
        1                   => 'one',
        2                   => 'two',
        3                   => 'three',
        4                   => 'four',
        5                   => 'five',
        6                   => 'six',
        7                   => 'seven',
        8                   => 'eight',
        9                   => 'nine',
        10                  => 'ten',
        11                  => 'eleven',
        12                  => 'twelve',
        13                  => 'thirteen',
        14                  => 'fourteen',
        15                  => 'fifteen',
        16                  => 'sixteen',
        17                  => 'seventeen',
        18                  => 'eighteen',
        19                  => 'nineteen',
        20                  => 'twenty',
        30                  => 'thirty',
        40                  => 'fourty',
        50                  => 'fifty',
        60                  => 'sixty',
        70                  => 'seventy',
        80                  => 'eighty',
        90                  => 'ninety',
        100                 => 'hundred',
        1000                => 'thousand',
        1000000             => 'million',
        1000000000          => 'billion'
    );

    if (!is_numeric($number)) return false;

    if ($number < 0) return $negative . $this->amountInWords(abs($number));

    $string = $fraction = null;

    if (strpos($number, '.') !== false) {
        list($number, $fraction) = explode('.', $number);
    }

    switch (true) {
        case $number < 21:
            $string = $dictionary[$number];
            break;
        case $number < 100:
            $tens   = ((int) ($number / 10)) * 10;
            $units  = $number % 10;
            $string = $dictionary[$tens];
            if ($units) $string .= $hyphen . $dictionary[$units];
            break;
        case $number < 1000:
            $hundreds  = $number / 100;
            $remainder = $number % 100;
            $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
            if ($remainder) $string .= $conjunction . $this->amountInWords($remainder);
            break;
        default:
            $baseUnit = pow(1000, floor(log($number, 1000)));
            $numBaseUnits = (int) ($number / $baseUnit);
            $remainder = $number % $baseUnit;
            $string = $this->amountInWords($numBaseUnits) . ' ' . $dictionary[$baseUnit];
            if ($remainder) {
                $string .= $remainder < 100 ? $conjunction : $separator;
                $string .= $this->amountInWords($remainder);
            }
            break;
    }

    return $string;
}

public function InvoicePrintSystem($id) {
    $sale = Sale::with(['customer', 'saleDetails.product', 'saleDetails.imeis','saleDetails.ram','saleDetails.rom','saleDetails.color'])->findOrFail($id);
    
    $totalInWords = $this->amountInWords($sale->grand_total);
    
    return view('back-end.sale.english-invoice-print', compact('sale', 'totalInWords'));
}

public function findByImei($imei_no)
{
    // আপনার স্টোক টেবিল থেকে IMEI টি খুঁজুন
    // নিশ্চিত করুন আপনার Imei মডেলে product_id আছে
    // IMEI 1 ba IMEI 2 je kono ta scan korle
    $imei = ProductImei::whereNumber($imei_no)
                ->whereIn('status', [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_RESERVED]) // bikri hote pare emon
                ->whereNotNull('main_stock_id')
                ->accessibleBy(request()->user())
                ->latest('id')
                ->first();

    if ($imei) {
        return response()->json([
            'success' => true,
            'stock_id' => $imei->main_stock_id,
            'product_id' => $imei->product_id,
            'imei_id' => $imei->id,
            'price' => $imei->product->sale_price // যদি প্রাইস পাঠাতে চান
        ]);
    }

    // IMEI na hole variant er barcode/SKU (accessory scan kora)
    $stock = MainStock::where(fn ($q) => $q->where('barcode', $imei_no)->orWhere('sku', strtoupper($imei_no)))
        ->where('quantity', '>', 0)
        ->active()
        ->first();
    if ($stock) {
        return response()->json([
            'success'  => true,
            'stock_id' => $stock->id,
            'imei_id'  => null,
            'price'    => $stock->sale_price,
        ]);
    }

    return response()->json(['success' => false]);
}


}
