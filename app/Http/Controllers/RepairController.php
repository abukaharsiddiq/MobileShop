<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\RepairJob;
use App\Models\RepairJobPart;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Warranty;
use App\Services\RepairService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Repair & service center: job card, technician, status, parts (approval), taka, delivery, print, report.
 * Niyom RepairService e (server side); ekhane shudhu input jachai ar branch access.
 */
class RepairController extends Controller
{
    public function __construct(private RepairService $repairs) {}

    public function index(Request $request)
    {
        $request->validate([
            'status'        => ['nullable', Rule::in(array_merge(array_keys(RepairJob::LABELS), ['open']))],
            'technician_id' => 'nullable|integer',
            'from_date'     => 'nullable|date',
            'to_date'       => 'nullable|date',
        ]);
        $term = trim((string) $request->q);
        $user = $request->user();

        $jobs = RepairJob::with(['customer:id,name,phone', 'technician:id,name'])->withCount(['parts as pending_parts' => fn ($q) => $q->where('status', RepairJobPart::REQUESTED)])
            ->accessibleBy($user)
            ->when($request->status === 'open', fn ($q) => $q->open())
            ->when($request->status && $request->status !== 'open', fn ($q) => $q->where('status', $request->status))
            // shudhu technician (approve na): nijer ar kauke na deya job
            ->when($request->mine || (!$user->can('approve repair') && !$user->can('create repair') && $user->can('work repair')),
                fn ($q) => $q->where(fn ($w) => $w->where('technician_id', $user->id)->orWhereNull('technician_id')))
            ->when($request->technician_id, fn ($q, $id) => $q->where('technician_id', $id))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('job_no', $term)->orWhere('contact_phone', $term)
                ->orWhere('imei_no', 'like', "%{$term}")->orWhere('imei_no_2', 'like', "%{$term}")
                ->orWhereHas('customer', fn ($c) => $c->where('phone', $term)->orWhere('name', 'like', "%{$term}%"))))
            ->when($request->from_date, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to_date, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('back-end.repair.index', [
            'name'        => 'Repair',
            'title'       => 'Repair Jobs',
            'jobs'        => $jobs,
            'technicians' => $this->repairs->technicians(),
            'counts'      => RepairJob::accessibleBy($user)->open()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    // Technician panel: nijer kaj er board (login korle technician ekhane ashe)
    public function board(Request $request)
    {
        return view('back-end.repair.board', [
            'name'  => 'Repair',
            'title' => 'My Repair Board',
            'board' => $this->repairs->board($request->user()),
        ]);
    }

    public function create()
    {
        return view('back-end.repair.create', [
            'name'        => 'Repair',
            'title'       => 'New Repair Job',
            'customers'   => Customer::where('status', 1)->orderBy('name')->get(['id', 'name', 'phone']),
            'models'      => Product::whereNotIn('type', Product::NON_DEVICE_TYPES)->where('status', 1)->orderBy('product_name')->get(['id', 'product_name', 'model_number']),
            'technicians' => $this->repairs->technicians(),
            'accounts'    => Account::money()->active()->orderBy('code')->get(),
            'methods'     => $this->methods(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id'         => 'nullable|integer|exists:customers,id',
            'new_customer_name'   => 'nullable|string|max:255',
            'new_customer_phone'  => 'nullable|string|max:20',
            'contact_phone'       => 'nullable|string|max:30',
            'alt_phone'           => 'nullable|string|max:30',
            'product_id'          => 'nullable|integer|exists:products,id',
            'device_model'        => 'nullable|string|max:255',
            'device_color'        => 'nullable|string|max:50',
            'imei_no'             => 'nullable|string|max:20',
            'imei_no_2'           => 'nullable|string|max:20',
            'imei_missing_reason' => 'nullable|string|max:255',
            'ownership_note'      => 'nullable|string|max:255',
            'complaint'           => 'required|string|max:2000',
            'physical_condition'  => 'nullable|string|max:2000',
            'accessories'         => 'nullable|array',
            'estimated_cost'      => 'nullable|numeric|min:0',
            'estimated_delivery'  => 'nullable|date',
            'technician_id'       => 'nullable|integer',
            'advance'             => 'nullable|numeric|min:0',
            'payment_method'      => 'nullable|string|max:30',
            'payment_reference'   => 'nullable|string|max:100',
            'account_id'          => 'nullable|integer',
            'request_token'       => 'nullable|string|max:64',
        ]);

        try {
            $job = $this->repairs->open($data, $request->user());
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('repair.show', $job->id)->with('success', "Job card {$job->job_no} opened.");
    }

    // form theke device/IMEI jachai (save er age)
    public function checkDevice(Request $request)
    {
        $request->validate(['customer_id' => 'required|integer|exists:customers,id', 'imei_no' => 'required|string|max:20',
            'imei_no_2' => 'nullable|string|max:20', 'ownership_note' => 'nullable|string|max:255']);

        try {
            $result = $this->repairs->verifyDevice(Customer::findOrFail($request->customer_id), $request->imei_no, $request->imei_no_2, '', (string) $request->ownership_note);
        } catch (\Exception $e) {
            if (get_class($e) !== \Exception::class) {
                throw $e;
            }
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        $previous = $result['previous_job_id'] ? RepairJob::find($result['previous_job_id']) : null;

        return response()->json(['ok' => true, 'ownership' => RepairJob::OWNERSHIP[$result['ownership']], 'imei_no_2' => $result['imei_no_2'],
            'warranty' => $previous ? "Under repair warranty of {$previous->job_no} until {$previous->warranty_until->format('d-m-Y')}" : null]);
    }

    public function show(Request $request, $id)
    {
        $job = $this->find($request, $id);
        $job->load(['customer', 'product', 'device', 'sale', 'branch', 'technician', 'receiver', 'previousJob',
            'parts.mainStock.product', 'parts.mainStock.ram', 'parts.mainStock.rom', 'parts.mainStock.color', 'parts.requester', 'parts.approver',
            'payments.receiver', 'payments.account', 'events.user']);

        return view('back-end.repair.show', [
            'name'        => 'Repair',
            'title'       => $job->job_no,
            'job'         => $job,
            // dokaner bikri kora phone er product warranty (thakle warranty claim e jete hobe)
            'warranties'  => $job->product_imei_id ? Warranty::active()->where('product_imei_id', $job->product_imei_id)->get() : collect(),
            'history'     => $job->imei_no ? RepairJob::where('id', '!=', $job->id)->where(fn ($q) => $q->where('imei_no', $job->imei_no)
                ->orWhere('imei_no_2', $job->imei_no))->orderByDesc('id')->get() : collect(),
            'parts'       => MainStock::with(['product', 'ram', 'rom', 'color'])->active()
                ->whereHas('product', fn ($q) => $q->whereIn('type', Product::NON_DEVICE_TYPES)->where('status', 1))->orderBy('product_id')->get(),
            'technicians' => $this->repairs->technicians(),
            'accounts'    => Account::money()->active()->orderBy('code')->get(),
            'methods'     => $this->methods(),
        ]);
    }

    // type: job (customer er job card/receipt) | invoice (delivery invoice + warranty)
    public function print(Request $request, $id)
    {
        $job = $this->find($request, $id);
        $job->load(['customer', 'technician', 'receiver', 'branch', 'parts.mainStock.product', 'payments']);

        return view('back-end.repair.print', [
            'job'     => $job,
            'type'    => $request->type === 'invoice' && $job->status === RepairJob::DELIVERED ? 'invoice' : 'job',
            'setting' => Setting::first(),
        ]);
    }

    public function report(Request $request)
    {
        $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date|after_or_equal:from_date']);
        $from = $request->from_date ?: now()->startOfMonth()->toDateString();
        $to = $request->to_date ?: now()->toDateString();

        return view('back-end.repair.report', [
            'name'   => 'Repair',
            'title'  => 'Repair Report',
            'from'   => $from,
            'to'     => $to,
            'report' => $this->repairs->report($from, $to),
        ]);
    }

    // ---- workflow ------------------------------------------------------------------------------

    public function assign(Request $request, $id)
    {
        $request->validate(['technician_id' => 'required|integer']);
        $job = $this->find($request, $id);

        return $this->attempt(fn () => $this->repairs->assign($job, $request->user(), (int) $request->technician_id), 'Technician assigned.');
    }

    public function start(Request $request, $id)
    {
        $request->validate(['diagnosis' => 'nullable|string|max:2000', 'estimated_cost' => 'nullable|numeric|min:0']);
        $job = $this->find($request, $id);

        return $this->attempt(fn () => $this->repairs->start($job, $request->user(), $request->diagnosis,
            $request->filled('estimated_cost') ? (float) $request->estimated_cost : null), 'Repair started.');
    }

    public function waiting(Request $request, $id)
    {
        $request->validate(['waiting' => 'required|boolean', 'note' => 'required|string|max:255']);
        $job = $this->find($request, $id);

        return $this->attempt(fn () => $this->repairs->waitForParts($job, $request->user(), $request->boolean('waiting'), $request->note), 'Status updated.');
    }

    public function requestPart(Request $request, $id)
    {
        $request->validate(['main_stock_id' => 'required|integer|exists:main_stocks,id', 'quantity' => 'required|integer|min:1|max:100',
            'unit_price' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:255']);
        $job = $this->find($request, $id);

        return $this->attempt(fn () => $this->repairs->requestPart($job, $request->user(), (int) $request->main_stock_id, (int) $request->quantity,
            $request->filled('unit_price') ? (float) $request->unit_price : null, $request->note), 'Part requested. It needs approval before stock is used.');
    }

    public function approvePart(Request $request, $partId)
    {
        $part = $this->part($request, $partId);

        return $this->attempt(fn () => $this->repairs->approvePart($part, $request->user()), 'Part approved and taken from stock.');
    }

    public function rejectPart(Request $request, $partId)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        $part = $this->part($request, $partId);

        return $this->attempt(fn () => $this->repairs->rejectPart($part, $request->user(), $request->reason), 'Part request rejected.');
    }

    public function returnPart(Request $request, $partId)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        $part = $this->part($request, $partId);

        return $this->attempt(fn () => $this->repairs->returnPart($part, $request->user(), $request->reason), 'Part returned to stock.');
    }

    public function complete(Request $request, $id)
    {
        // service charge technician dey na (QC/delivery te)
        $request->validate(['work_done' => 'required|string|max:2000']);
        $job = $this->find($request, $id);

        return $this->attempt(fn () => $this->repairs->complete($job, $request->user(), $request->work_done), 'Repair completed. It now waits for the quality check.');
    }

    public function qc(Request $request, $id)
    {
        $request->validate(['passed' => 'required|boolean', 'note' => 'required|string|max:2000', 'service_charge' => 'nullable|numeric|min:0']);
        $job = $this->find($request, $id);

        return $this->attempt(fn () => $this->repairs->qualityCheck($job, $request->user(), $request->boolean('passed'), $request->note,
            $request->filled('service_charge') ? (float) $request->service_charge : null), 'Quality check saved.');
    }

    public function payment(Request $request, $id)
    {
        $request->validate(['amount' => 'required|numeric|gt:0', 'payment_method' => 'required|string|max:30', 'payment_reference' => 'nullable|string|max:100',
            'account_id' => 'nullable|integer', 'request_token' => 'nullable|string|max:64']);
        $job = $this->find($request, $id);

        return $this->attempt(fn () => $this->repairs->collect($job, $request->user(), (float) $request->amount, $request->payment_method,
            $request->payment_reference, $request->integer('account_id') ?: null, $request->request_token), 'Payment received.');
    }

    public function deliver(Request $request, $id)
    {
        $data = $request->validate(['service_charge' => 'nullable|numeric|min:0', 'discount' => 'nullable|numeric|min:0', 'warranty_days' => 'nullable|integer|min:0|max:365',
            'amount' => 'nullable|numeric|min:0', 'payment_method' => 'nullable|string|max:30', 'payment_reference' => 'nullable|string|max:100',
            'account_id' => 'nullable|integer', 'collected_by' => 'nullable|string|max:255']);
        $job = $this->find($request, $id);

        return $this->attempt(fn () => $this->repairs->deliver($job, $request->user(), $data), 'Phone delivered. Print the invoice for the customer.');
    }

    public function cancel(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:255', 'charge' => 'nullable|numeric|min:0', 'refund_method' => 'nullable|string|max:30']);
        $job = $this->find($request, $id);

        return $this->attempt(fn () => $this->repairs->cancel($job, $request->user(), $request->reason, (float) $request->charge,
            $request->refund_method ?: 'Cash'), 'Job cancelled. Parts and money were reversed.');
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function find(Request $request, $id): RepairJob
    {
        $job = RepairJob::findOrFail($id);
        abort_unless($request->user()->canAccessBranch($job->branch_id), 403);

        return $job;
    }

    private function part(Request $request, $id): RepairJobPart
    {
        $part = RepairJobPart::findOrFail($id);
        $this->find($request, $part->repair_job_id);

        return $part;
    }

    // store credit chara (repair e notun taka lage)
    private function methods(): array
    {
        return array_keys(array_filter(SalePayment::methods(), fn ($channel) => $channel !== 'credit'));
    }

    private function attempt(callable $action, string $message)
    {
        try {
            $action();
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return back()->with('success', $message);
    }
}
