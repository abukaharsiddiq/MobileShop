<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Installment;
use App\Models\InstallmentPayment;
use App\Models\SalePayment;
use App\Services\InstallmentService;
use Toastr;


class InstallmentCollectionController extends Controller
{

    private $heading;

    public function __construct(private InstallmentService $installments){
        $this->heading = "Installment Collection";
    }

    // shob kisti: status (paid / partial / due / overdue / cancelled), customer, invoice, tarikh diye khoja
    public function index(Request $request)
    {
        $name = $this->heading;
        $title = $this->heading;
        $today = now()->toDateString();

        $query = $this->scoped($request)
            ->with(['customer:id,name,phone', 'sale:id,invoice_no'])
            ->when($request->customer_id, fn ($q, $id) => $q->where('installments.customer_id', $id))
            ->when($request->invoice, fn ($q, $no) => $q->whereHas('sale', fn ($s) => $s->where('invoice_no', 'like', '%' . trim($no) . '%')))
            ->when($request->from_date, fn ($q, $d) => $q->where('due_date', '>=', $d))
            ->when($request->to_date, fn ($q, $d) => $q->where('due_date', '<=', $d));

        match ($request->status) {
            'paid', 'cancelled' => $query->where('status', $request->status),
            'partial'           => $query->where('status', Installment::STATUS_PARTIAL)->where('due_date', '>=', $today),
            'due'               => $query->where('status', Installment::STATUS_PENDING)->where('due_date', '>=', $today),
            'overdue'           => $query->overdue(),
            default             => null,
        };

        $totals = (clone $query)->where('status', '!=', Installment::STATUS_CANCELLED)
            ->selectRaw('COALESCE(SUM(amount + late_fee), 0) as payable, COALESCE(SUM(paid_amount), 0) as paid')->first();
        $installments = $query->orderBy('due_date')->orderBy('id')->paginate(25)->withQueryString();
        $customers = Customer::orderBy('name')->get(['id', 'name', 'phone']);

        return view('back-end.installment.index', compact('installments', 'customers', 'totals', 'name', 'title'));
    }

    public function create(Request $request){
        $name = $this->heading;
        $title = $this->heading;
        $customers = Customer::where('status', 1)->orderBy('name')->get(['id', 'name', 'phone']);
        $methods = collect(SalePayment::methods())->reject(fn ($c) => $c === 'credit');
        $token = (string) Str::uuid();
        $selected = $request->customer_id;
        return view('back-end.installment.collection', compact('name', 'title', 'customers', 'methods', 'token', 'selected'));
    }

    // customer er baki kisti (aday er page e)
    public function getCustomerInstallments(Request $request, $id)
    {
        $rows = $this->scoped($request)->with('sale:id,invoice_no')->where('installments.customer_id', $id)->open()
            ->orderBy('due_date')->orderBy('installment_no')->get()
            ->map(fn ($i) => [
                'id'             => $i->id,
                'sale_id'        => $i->sale_id,
                'invoice_no'     => $i->sale->invoice_no ?? '',
                'installment_no' => $i->installment_no,
                'due_date'       => $i->due_date,
                'amount'         => (float) $i->amount,
                'late_fee'       => (float) $i->late_fee,
                'paid_amount'    => (float) $i->paid_amount,
                'open_amount'    => $i->openAmount(),
                'status'         => $i->displayStatus(),
                'days_overdue'   => $i->daysOverdue(),
                'statement_url'  => route('installments.sale', $i->sale_id),
            ]);

        return response()->json($rows);
    }

    // কিস্তি পেমেন্ট স্টোর করার মেথড
    public function store(Request $request)
    {
        $request->validate([
            'installment_id' => 'required|exists:installments,id',
            'paid_amount'    => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|max:30',
            'reference'      => 'nullable|string|max:100',
            'note'           => 'nullable|string|max:255',
            'request_token'  => 'required|string|max:64',
        ]);

        try {
            DB::beginTransaction();

            $installment = Installment::with('sale')->findOrFail($request->installment_id);
            abort_unless($request->user()->canAccessBranch($installment->sale->branch_id), 403);

            // ansik payment hoy; beshi dile porer kisti te jay; sale er due er beshi neya jay na; ekoi form duibar na
            $payment = $this->installments->collect($installment, (float) $request->paid_amount, $request->payment_method,
                $request->reference, $request->note, $request->request_token);

            DB::commit();
            $payment->wasRecentlyCreated
                ? Toastr::success('Installment payment received.', 'Success')
                : Toastr::info('This payment was already saved.', 'Info');
            return redirect()->route('installments.sale', $installment->sale_id)->with('receipt_id', $payment->id);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', errorMessage($e));
        }
    }

    // ekta installment sale er schedule, payment itihash, jachai, early settlement
    public function show(Request $request, Sale $sale)
    {
        abort_unless($request->user()->canAccessBranch($sale->branch_id), 403);
        $sale->load('customer');
        $schedule = Installment::with('allocations')->where('sale_id', $sale->id)->orderBy('installment_no')->get();
        abort_if($schedule->isEmpty(), 404);

        $payments = SalePayment::with('receiver:id,name')->where('sale_id', $sale->id)->orderBy('payment_date')->orderBy('id')->get();
        $issues = $this->installments->verify($sale);
        $quote = $this->installments->settlementQuote($sale);
        $methods = collect(SalePayment::methods())->reject(fn ($c) => $c === 'credit');
        $token = (string) Str::uuid();
        $canManage = $request->user()->can('manage installment');

        return view('back-end.installment.show', compact('sale', 'schedule', 'payments', 'issues', 'quote', 'methods', 'token', 'canManage'));
    }

    // payment er rosid: kon kisti te koto bosechhe
    public function receipt(Request $request, SalePayment $payment)
    {
        $payment->load(['sale.customer', 'receiver:id,name']);
        abort_unless($request->user()->canAccessBranch($payment->sale->branch_id), 403);
        $lines = InstallmentPayment::with('installment')->where('sale_payment_id', $payment->id)->get();
        $setting = DB::table('settings')->first();

        return view('back-end.installment.receipt', compact('payment', 'lines', 'setting'));
    }

    // customer er sob installment sale er statement (print)
    public function customerStatement(Request $request, Customer $customer)
    {
        $sales = Sale::where('customer_id', $customer->id)
            ->when($request->user()->allowedBranchIds(), fn ($q, $ids) => $q->where(fn ($w) => $w->whereIn('branch_id', $ids)->orWhereNull('branch_id')))
            ->whereHas('installments')->with(['installments' => fn ($q) => $q->orderBy('installment_no')])
            ->orderBy('sale_date')->get();
        $setting = DB::table('settings')->first();

        return view('back-end.installment.statement', compact('customer', 'sales', 'setting'));
    }

    public function settle(Request $request, Sale $sale)
    {
        $request->validate([
            'discount'       => 'nullable|numeric|min:0',
            'payment_method' => 'required|string|max:30',
            'reference'      => 'nullable|string|max:100',
            'note'           => 'nullable|string|max:255',
            'request_token'  => 'required|string|max:64',
        ]);
        abort_unless($request->user()->canAccessBranch($sale->branch_id), 403);

        try {
            DB::beginTransaction();
            $payment = $this->installments->settle($sale, (float) $request->discount, $request->payment_method, $request->reference,
                $request->note, $request->request_token);
            DB::commit();
            Toastr::success('Installment plan settled.', 'Success');
            return redirect()->route('installments.sale', $sale->id)->with('receipt_id', $payment?->id);
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', errorMessage($e));
        }
    }

    public function waiveLateFee(Request $request, Installment $installment)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        abort_unless($request->user()->canAccessBranch($installment->sale->branch_id), 403);

        try {
            DB::beginTransaction();
            $waived = $this->installments->waiveLateFee($installment, $request->reason);
            DB::commit();
            Toastr::success('Late fee ' . number_format($waived, 2) . ' waived.', 'Success');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', errorMessage($e));
        }

        return back();
    }

    // config er niyom e late fee bosano (command o ache: installments:apply-late-fees)
    public function applyLateFees()
    {
        try {
            DB::beginTransaction();
            $applied = $this->installments->assessLateFees();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', errorMessage($e));
        }

        $applied
            ? Toastr::success(count($applied) . ' late fee(s) added, total ' . number_format(array_sum(array_column($applied, 'fee')), 2) . '.', 'Success')
            : Toastr::info('No new late fee is due.', 'Info');

        return back();
    }

    public function markReminded(Request $request, Installment $installment)
    {
        $request->validate(['channel' => 'nullable|in:call,sms,whatsapp,visit']);
        abort_unless($request->user()->canAccessBranch($installment->sale->branch_id), 403);

        try {
            DB::beginTransaction();
            $this->installments->markReminded($installment, $request->channel ?: 'call');
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $request->expectsJson() ? response()->json(['message' => errorMessage($e)], 422) : back()->with('error', errorMessage($e));
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    // reminder: ajker/samner koyek diner ar overdue kisti, message shoho
    public function dueList(Request $request)
    {
        $until = now()->addDays(max(0, (int) config('installments.reminder_days_before')))->toDateString();
        $installments = $this->scoped($request)->with(['customer:id,name,phone', 'sale:id,invoice_no'])->open()
            ->where('due_date', '<=', $until)
            ->orderBy('due_date')->paginate(30);

        return view('back-end.installment.due_list', compact('installments', 'until'));
    }

    // overdue report: koto din dhore baki (aging), customer onujayi
    public function overdue(Request $request)
    {
        $rows = $this->scoped($request)->with(['customer:id,name,phone', 'sale:id,invoice_no'])->overdue()
            ->when($request->customer_id, fn ($q, $id) => $q->where('installments.customer_id', $id))
            ->orderBy('due_date')->get();

        $buckets = ['1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($rows as $row) {
            $days = $row->daysOverdue();
            $key = $days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'));
            $buckets[$key] = round($buckets[$key] + $row->openAmount(), 2);
        }
        $byCustomer = $rows->groupBy('customer_id')->map(fn ($list) => [
            'customer' => $list->first()->customer,
            'count'    => $list->count(),
            'amount'   => round($list->sum(fn ($i) => $i->openAmount()), 2),
            'oldest'   => $list->max(fn ($i) => $i->daysOverdue()),
        ])->sortByDesc('amount');
        $customers = Customer::orderBy('name')->get(['id', 'name', 'phone']);
        $lateFeePending = $this->installments->lateFeeCandidates()->count();
        $lateFeeRule = config('installments.late_fee');

        return view('back-end.installment.overdue', compact('rows', 'buckets', 'byCustomer', 'customers', 'lateFeePending', 'lateFeeRule'));
    }

    // user er branch er sale er kisti
    private function scoped(Request $request)
    {
        return Installment::query()->when($request->user()->allowedBranchIds(),
            fn ($q, $ids) => $q->whereHas('sale', fn ($s) => $s->where(fn ($w) => $w->whereIn('branch_id', $ids)->orWhereNull('branch_id'))));
    }

}
