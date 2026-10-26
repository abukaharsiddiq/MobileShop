<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalePayment;
use App\Models\MainStock;
use App\Models\Branch;
use App\Models\ProductImei;
use App\Models\User;
use App\Services\InvoiceNumberService;
use App\Services\SalePaymentService;
use App\Services\SaleService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use DB;
use Auth;
use Brian2694\Toastr\Facades\Toastr;

class SaleController extends Controller
{
    private $heading;
    public function __construct(
        private InvoiceNumberService $invoiceNumbers,
        private SalePaymentService $payments,
        private SaleService $sales,
    )
    {
        $this->heading = 'Sale';
    }

// Sales history: invoice/customer/IMEI khoja, tarikh, salesperson, branch, status, due; filter er mot
public function index(Request $request){
    $request->validate([
        'from_date' => 'nullable|date',
        'to_date'   => 'nullable|date',
        'status'    => 'nullable|in:completed,cancelled',
        'payment'   => 'nullable|in:due,paid',
    ]);
    $name = 'Sale List';
    $title = 'Manage Sales';

    $query = Sale::query()
        ->when($request->user()->allowedBranchIds(), fn ($q, $ids) => $q->where(fn ($w) => $w->whereIn('branch_id', $ids)->orWhereNull('branch_id')))
        ->when($request->q, function ($q, $term) {
            $phone = Customer::normalizePhone($term);
            $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")
                    ->when($phone && strlen($phone) >= 4, fn ($p) => $p->orWhere('phone_normalized', 'like', "%{$phone}%")))
                ->orWhereIn('id', ProductImei::where('imei_no', $term)->orWhere('imei_no_2', $term)->select('sale_id'))
                ->orWhereIn('id', \App\Models\ImeiMovement::where('event', 'sale')->where(fn ($m) => $m->where('imei_no', $term)->orWhere('imei_no_2', $term))
                    ->where('reference_type', 'Sale')->select('reference_id')));
        })
        ->when($request->from_date, fn ($q, $d) => $q->whereDate('sale_date', '>=', $d))
        ->when($request->to_date, fn ($q, $d) => $q->whereDate('sale_date', '<=', $d))
        ->when($request->salesperson_id, fn ($q, $id) => $q->where('salesperson_id', $id))
        ->when($request->branch_id, fn ($q, $id) => $q->where('branch_id', $id))
        ->when($request->status, fn ($q, $s) => $q->where('status', $s))
        ->when($request->payment === 'due', fn ($q) => $q->where('due_amount', '>', 0))
        ->when($request->payment === 'paid', fn ($q) => $q->where('due_amount', '<=', 0)->completed());

    $totals = (clone $query)->completed()->selectRaw('COUNT(*) as invoices, COALESCE(SUM(grand_total), 0) as total,
        COALESCE(SUM(paid_amount), 0) as paid, COALESCE(SUM(due_amount), 0) as due')->first();
    $sales = $query->with(['customer', 'salesperson:id,name', 'branch:id,name'])
        ->orderByDesc('id')->paginate(25)->withQueryString();

    $salespeople = User::whereIn('id', Sale::whereNotNull('salesperson_id')->distinct()->select('salesperson_id'))->orderBy('name')->get(['id', 'name']);
    $branches = Branch::orderBy('name')->get(['id', 'name']);
    $methods = array_keys(SalePayment::methods());

    return view('back-end.sale.index', compact('sales', 'name', 'title', 'totals', 'salespeople', 'branches', 'methods'));
}

public function show($id)
{
    $name = 'Sale Details';

    $sale = Sale::with([
        'customer', 'salesperson:id,name', 'branch:id,name', 'canceller:id,name', 'user:id,name',
        'items.product', 'items.ram', 'items.rom', 'items.color', 'items.imeis',
        'payments.receiver:id,name', 'returns',
    ])->findOrFail($id);
    abort_unless(request()->user()->canAccessBranch($sale->branch_id), 403);
    $title = 'Invoice #' . $sale->invoice_no;
    $methods = array_keys(SalePayment::methods());

    return view('back-end.sale.show', compact('sale', 'name', 'title', 'methods'));
}

public function updatePayment(Request $request)
{
    $request->validate([
        'sale_id'        => 'required|exists:sales,id',
        'paid_amount'    => 'required|numeric|min:0.01',
        'payment_date'   => 'required|date',
        'payment_method' => 'nullable|string|max:30',
        'reference'      => 'nullable|string|max:100',
        'request_token'  => 'nullable|string|max:64',
    ]);

    try {
        DB::beginTransaction();

        // due er beshi neya jay na; kisti thakle kisti te bhag hoy; ledger e entry; ekoi reference duibar na
        $this->payments->receive(Sale::findOrFail($request->sale_id), (float) $request->paid_amount,
            $request->payment_date, $request->payment_method, $request->note, 'Received', null, $request->reference,
            null, $request->request_token);   // ekoi form duibar submit hole ekbar-i

        DB::commit();
        Toastr::success('Payment updated successfully!');
        return back();

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }
}

    // POS: customer, salesperson, payment method; product AJAX khoja ba variant list theke
    public function create(){
        $name= $this->heading;
        $title = $this->heading;
        // shudhu dekhanor jonno; asol number save er somoy toiri hoy
        $SaleinvoiceNo = $this->invoiceNumbers->previewSale();
        $customers = Customer::where('status', 1)->orderBy('name')->get(['id', 'name', 'phone']);
        // shudhu active product er active variant (inactive bikri hoy na)
        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])->where('quantity', '>', 0)->active()->get();
        $salespeople = User::where('status', 1)->whereIn('role', array_merge(['admin'], User::staffRoles()))->orderBy('name')->get(['id', 'name']);
        $methods = SalePayment::methods();
        $token = (string) Str::uuid();
        return view('back-end.sale.create',compact('name','title','customers','stocks','SaleinvoiceNo','salespeople','methods','token'));
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
        'sale_prices'   => 'nullable|array',
        'sale_prices.*' => 'nullable|numeric|min:0',
        'item_discounts.*' => 'nullable|numeric|min:0',
        'imeis'       => 'nullable|array',
        'imeis.*'     => 'nullable|array',
        'discount'    => 'nullable|numeric|min:0',
        'tax_rate'    => 'nullable|numeric|min:0|max:100',
        'additional_charge'      => 'nullable|numeric|min:0',
        'additional_charge_note' => 'nullable|string|max:255',
        'paid_amount' => 'nullable|numeric|min:0',
        'payment_method' => 'nullable|string|max:30',
        'payments'             => 'nullable|array|max:10',
        'payments.*.method'    => 'nullable|string|max:30',
        'payments.*.amount'    => 'nullable|numeric|min:0',
        'payments.*.reference' => 'nullable|string|max:100',
        'payments.*.account'   => 'nullable|string|max:255',
        'salesperson_id' => 'nullable|exists:users,id',
        'sale_token'     => 'nullable|string|max:64',
        'expected_total' => 'nullable|numeric',
        'is_installment'        => 'nullable|in:0,1',
        'total_installments'    => 'required_if:is_installment,1|nullable|integer|min:1|max:60',
        'next_installment_date' => 'nullable|date',
        'installment_frequency' => 'nullable|in:' . implode(',', array_keys(config('installments.frequencies'))),
        'note'         => 'nullable|string|max:2000',
    ]);

    try {
        DB::beginTransaction();
        $sale = $this->sales->checkout($request->all(), $request->user());
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e))->withInput();
    }

    if (!$sale->wasRecentlyCreated) {
        return redirect()->route('sale.show', $sale->id)->with('success', "Invoice {$sale->invoice_no} was already saved; nothing was added.");
    }

    Toastr::success('Sale Added Successfully', 'Success', ["positionClass" => "toast-top-right"]);
    return redirect()->route('sale.index')->with([
        'print_sale_id' => $sale->id,
        'auto_print' => true
    ]);
}

// Pura sale batil (stock, IMEI, ledger ulto, taka ferot); record thake
public function cancel(Request $request, $id)
{
    $request->validate([
        'reason'        => 'required|string|min:5|max:255',
        'refund_method' => 'nullable|string|max:30',
    ]);
    $sale = Sale::findOrFail($id);
    abort_unless($request->user()->canAccessBranch($sale->branch_id), 403);

    try {
        DB::beginTransaction();
        $this->sales->cancel($sale, $request->reason, $request->user(), $request->refund_method ?: 'Cash');
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }

    return back()->with('success', "Invoice {$sale->invoice_no} cancelled; stock and payments reversed.");
}

// purono "Delete" button: sale muchhe na, cancel hoy
public function delete(Request $request, $id)
{
    abort_unless($request->user()->can('cancel sale'), 403);
    $request->merge(['reason' => $request->input('reason') ?: 'Deleted from the sales list']);

    return $this->cancel($request, $id);
}

// POS: customer bachai korle tar store credit (sale return theke)
public function customerCredit($id)
{
    return response()->json(['store_credit' => SalePaymentService::availableCredit((int) Customer::findOrFail($id)->id)]);
}

// POS khoja: barcode, SKU, product naam/model ba IMEI (IMEI hole oi phone ta sathe)
public function searchProducts(Request $request)
{
    $request->validate(['q' => 'required|string|min:2|max:100']);
    $term = trim($request->q);

    $imei = ProductImei::whereNumber($term)
        ->whereIn('status', [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_RESERVED])
        ->whereNotNull('main_stock_id')->accessibleBy($request->user())->latest('id')->first();

    $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])->active()->where('quantity', '>', 0)
        ->where(function ($q) use ($term, $imei) {
            $q->where('barcode', $term)->orWhere('sku', 'like', '%' . strtoupper($term) . '%')
                ->orWhereHas('product', fn ($p) => $p->where('product_name', 'like', "%{$term}%")->orWhere('model_number', 'like', "%{$term}%"));
            if ($imei) {
                $q->orWhere('main_stocks.id', $imei->main_stock_id);
            }
        })
        ->limit(20)->get();

    return response()->json($stocks->map(fn ($s) => [
        'stock_id'  => $s->id,
        'label'     => $s->variantLabel(),
        'sku'       => $s->sku,
        'price'     => (float) ($s->sale_price ?? $s->product->sale_price),
        'stock'     => (int) $s->quantity,
        'uses_imei' => $s->product->requiresImei() || ProductImei::where('main_stock_id', $s->id)->owned()->exists(),
        'imei_id'   => $imei && $imei->main_stock_id === $s->id ? $imei->id : null,
        'exact'     => $s->barcode === $term || $s->sku === strtoupper($term) || ($imei && $imei->main_stock_id === $s->id),
    ])->sortByDesc('exact')->values());
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

    $sale = Sale::with(['customer', 'saleDetails.product', 'saleDetails.ram', 'saleDetails.rom', 'saleDetails.color',
                    'payments', 'salesperson:id,name', 'branch:id,name'])
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
