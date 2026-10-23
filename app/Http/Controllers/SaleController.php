<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\MainStock;
use App\Models\CustomerLedger;
use App\Models\ProductImei;
use DB;
use Auth;
use Brian2694\Toastr\Facades\Toastr;

class SaleController extends Controller
{
    private $heading;
    public function __construct()
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
        'paid_amount' => 'required|numeric|min:1',
        'payment_date' => 'required|date',
    ]);

    try {
        DB::beginTransaction();

        $sale = Sale::findOrFail($request->sale_id);

        $sale->paid_amount += $request->paid_amount;
        $sale->due_amount -= $request->paid_amount;
        $sale->save();

        $lastLedger = CustomerLedger::where('customer_id', $sale->customer_id)->latest('id')->first();
        $previousBalance = $lastLedger ? $lastLedger->balance : 0;

        $ledger = new CustomerLedger();
        $ledger->customer_id = $sale->customer_id;
        $ledger->date = $request->payment_date;
        $ledger->transaction_type = 'Received';
        $ledger->sale_id = $sale->id;
        $ledger->reference_no = $sale->invoice_no;
        $ledger->debit = 0;
        $ledger->credit = $request->paid_amount;
        $ledger->balance = $previousBalance - $request->paid_amount;
        $ledger->note = $request->note ?? 'Due payment for invoice: ' . $sale->invoice_no;
        $ledger->save();

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
        $SaleinvoiceNo = $this->generateSaleInvoiceNo();;
        $customers = Customer::where('status', 1)->get();
        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])->where('quantity', '>', 0)->get();
        return view('back-end.sale.create',compact('name','title','customers','stocks','SaleinvoiceNo'));
    }


public function store(Request $request)
{

    // dd($request->all());

    $request->validate([
        'customer_id' => 'required',
        'sale_date'   => 'required|date',
        'products'    => 'required|array|min:1',
        'paid_amount' => 'required|numeric|min:0',
    ]);

    try {
        DB::beginTransaction();

        $sale = new Sale();
        $sale->invoice_no      = $request->invoice_no;
        $sale->customer_id     = $request->customer_id;
        $sale->sale_date       = $request->sale_date;
        $sale->subtotal        = $request->subtotal;
        $sale->discount        = $request->discount ?? 0;
        $sale->grand_total     = $request->grand_total;
        $sale->paid_amount     = $request->paid_amount;
        $sale->due_amount      = $request->due_amount;

        $sale->is_installment = $request->is_installment ?? 0;
        $sale->total_installments = $request->total_installments ?? 0;
        $sale->installment_per_month = $request->installment_per_month ?? 0;
        $sale->total_paid_installments = 0;

        $sale->payment_method  = $request->payment_method;
        $sale->payment_account = $request->payment_account;
        $sale->created_by      = auth()->id();
        $sale->save();

        if ($sale->is_installment == 1 && $sale->total_installments > 0) {
            $nextDate = \Carbon\Carbon::parse($request->next_installment_date ?? now()->addMonth());
            
            for ($i = 1; $i <= $sale->total_installments; $i++) {
                DB::table('installments')->insert([
                    'sale_id'         => $sale->id,
                    'customer_id'     => $sale->customer_id,
                    'installment_no'  => $i,
                    'amount'          => $sale->installment_per_month,
                    'paid_amount'     => 0,
                    'due_date'        => $nextDate->copy()->addMonths($i - 1),
                    'status'          => 'pending',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }


        foreach ($request->products as $key => $product_id) {
            $qty = $request->qtys[$key] ?? 0;
            
            $item = new SaleDetail();
            $item->sale_id        = $sale->id;
            $item->product_id     = $product_id;
  
            $stockInfo = MainStock::where('product_id', $product_id)->first();
            
            $item->ram_id         = $stockInfo->ram_id ?? null;
            $item->rom_id         = $stockInfo->rom_id ?? null;
            $item->color_id       = $stockInfo->color_id ?? null;
            
            $item->quantity       = $qty;
            $item->purchase_price = $stockInfo->average_purchase_price ?? 0;
            $item->sale_price     = $request->sale_prices[$key] ?? 0;
            $item->item_discount  = $request->item_discounts[$key] ?? 0;
            $item->unit_total     = ($qty * $item->sale_price) - $item->item_discount;
            $item->save();

          
            if (isset($request->imeis[$key]) && is_array($request->imeis[$key])) {
                foreach ($request->imeis[$key] as $imei_id) {
                    DB::table('product_imeis')->where('id', $imei_id)->update([
                        'status'  => 0,
                        'sale_id' => $sale->id,
                        'sale_detail_id' => $item->id,
                    ]);
                }
            }

            MainStock::where('product_id', $product_id)
                ->where('ram_id', $item->ram_id)
                ->where('rom_id', $item->rom_id)
                ->where('color_id', $item->color_id)
                ->decrement('quantity', $qty);
        }

        $lastLedger = CustomerLedger::where('customer_id', $request->customer_id)
                                    ->orderBy('id', 'desc')
                                    ->first();
        
        $previousBalance = $lastLedger ? $lastLedger->balance : 0;
        $currentBalance  = $previousBalance + ($request->grand_total - $request->paid_amount);

        $ledger = new CustomerLedger();
        $ledger->customer_id      = $request->customer_id;
        $ledger->date             = $request->sale_date;
        $ledger->transaction_type = 'Sale';
        $ledger->sale_id          = $sale->id;
        $ledger->reference_no     = $sale->invoice_no;
        $ledger->debit            = $request->grand_total;
        $ledger->credit           = $request->paid_amount;
        $ledger->balance          = $currentBalance;
        $ledger->note             = 'Sale Invoice: ' . $sale->invoice_no;
        $ledger->save();

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


    private function generateSaleInvoiceNo()
{
    return DB::transaction(function () {

        $setting = Setting::lockForUpdate()->first();

        $prefix = $setting->sale_invoice_prefix;
        $startInvoiceNo = $setting->sale_invoice_number;

        $latestSale = Sale::lockForUpdate()->latest('id')->first();

        if (!$latestSale || !$latestSale->invoice_no) {
            return $prefix . '-' . $startInvoiceNo;
        }

        $numberPart = str_replace($prefix . '-', '', $latestSale->invoice_no);
        $newNumber = (int)$numberPart + 1;

        return $prefix . '-' . str_pad(
            $newNumber,
            strlen($startInvoiceNo),
            '0',
            STR_PAD_LEFT
        );
    });
}

public function getAvailableImeis($product_id)
{

    $imeis = DB::table('product_imeis')
                ->where('product_id', $product_id)
                ->where('status',1)
                ->get(['id', 'imei_no']);
    
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
    $imei = ProductImei::where('imei_no', $imei_no)
                ->where('status', 1) // শুধু স্টোকে থাকা আইটেম
                ->first();

    if ($imei) {
        return response()->json([
            'success' => true,
            'product_id' => $imei->product_id,
            'imei_id' => $imei->id,
            'price' => $imei->product->sale_price // যদি প্রাইস পাঠাতে চান
        ]);
    }

    return response()->json(['success' => false]);
}


}
