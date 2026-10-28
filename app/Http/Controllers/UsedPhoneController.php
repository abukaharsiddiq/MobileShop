<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\ImeiMovement;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Supplier;
use App\Models\UsedPhone;
use App\Services\UsedPhoneService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Used & refurbished phone: intake (seller, IMEI, condition, inspection), approval, refurbishment, resale dam,
 * ar puro IMEI itihash (ager kena/bikri/ferot shoho). Niyom UsedPhoneService e (server side).
 */
class UsedPhoneController extends Controller
{
    // list filter: intake er status ba device er resale obostha
    const FILTERS = [
        'pending'      => 'Waiting for approval',
        'refurbishing' => 'In refurbishment',
        'available'    => 'Available for resale',
        'reserved'     => 'Reserved',
        'damaged'      => 'Damaged',
        'sold'         => 'Sold',
        'rejected'     => 'Rejected',
    ];

    public function __construct(private UsedPhoneService $phones) {}

    public function index(Request $request)
    {
        $request->validate([
            'status'    => ['nullable', Rule::in(array_keys(self::FILTERS))],
            'from_date' => 'nullable|date',
            'to_date'   => 'nullable|date',
        ]);

        $query = $this->filtered($request);
        $all = (clone $query)->get();

        return view('back-end.used-phone.index', [
            'name'    => 'Used Phones',
            'title'   => 'Used & Refurbished Phones',
            'phones'  => $query->paginate(30)->withQueryString(),
            'summary' => $this->summary($all),
        ]);
    }

    public function create()
    {
        // used/refurbished model er variant (form e product bachai korle filter)
        $variants = MainStock::with(['product', 'ram', 'rom', 'color'])
            ->whereHas('product', fn ($q) => $q->whereIn('type', Product::SECOND_HAND_TYPES)->where('status', 1))
            ->where('status', 1)->orderBy('product_id')->get();

        return view('back-end.used-phone.create', [
            'name'      => 'Used Phones',
            'title'     => 'Buy Used Phone',
            'products'  => $variants->pluck('product')->unique('id')->values(),
            'variants'  => $variants->map(fn ($v) => ['id' => $v->id, 'product_id' => $v->product_id, 'label' => $v->variantLabel(),
                'dual_sim' => (bool) $v->product->dual_sim, 'sale_price' => $v->sale_price])->values(),
            'customers' => Customer::where('status', 1)->orderBy('name')->get(['id', 'name', 'phone']),
            'suppliers' => Supplier::where('status', 1)->orderBy('name')->get(['id', 'name', 'phone']),
            'accounts'  => Account::money()->active()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'source'           => ['required', Rule::in(array_keys(config('used_phones.sources')))],
            'customer_id'      => 'nullable|integer|exists:customers,id',
            'supplier_id'      => 'nullable|integer|exists:suppliers,id',
            'seller_name'      => 'nullable|string|max:255',
            'seller_phone'     => 'nullable|string|max:30',
            'seller_document'  => 'nullable|string|max:60',
            'seller_address'   => 'nullable|string|max:255',
            'product_id'       => 'required|integer|exists:products,id',
            'main_stock_id'    => 'required|integer',
            'imei_no'          => 'required|string|max:20',
            'imei_no_2'        => 'nullable|string|max:20',
            'condition_grade'  => ['required', Rule::in(array_keys(config('used_phones.grades')))],
            'battery_health'   => 'nullable|integer|min:1|max:100',
            'accessories'      => 'nullable|array',
            'checklist'        => 'nullable|array',
            'inspection_notes' => 'nullable|string|max:2000',
            'purchase_cost'    => 'required|numeric|gt:0',
            'resale_price'     => 'nullable|numeric|gt:0',
            'warranty_months'  => 'nullable|integer|min:0|max:36',
            'warranty_terms'   => 'nullable|string|max:255',
            'payment_method'   => 'nullable|string|max:30',
            'account_id'       => 'nullable|integer',
            'note'             => 'nullable|string|max:2000',
            'request_token'    => 'nullable|string|max:64',
            'approve_as'       => ['nullable', Rule::in([UsedPhoneService::NEXT_STOCK, UsedPhoneService::NEXT_REFURBISH])],
        ]);
        if ($request->approve_as) {
            abort_unless($request->user()->can('approve used phone'), 403);
        }

        try {
            $phone = $this->phones->intake($request->except('approve_as'), $request->user(), $request->approve_as ?: null);
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('used-phone.show', $phone->id)->with('success', "Intake {$phone->intake_no} saved ({$phone->statusLabel()}).");
    }

    public function show(Request $request, $id)
    {
        $phone = $this->find($request, $id);
        $phone->load(['product', 'mainStock.ram', 'mainStock.rom', 'mainStock.color', 'device.saleDetail', 'device.sale.customer',
            'branch', 'customer', 'supplier', 'account', 'creator', 'approver', 'events.user', 'events.account']);

        // ei IMEI er shob jibon (ager kena/bikri/ferot, onno intake shoho)
        $numbers = array_values(array_filter([$phone->imei_no, $phone->imei_no_2]));
        $history = ImeiMovement::with(['user', 'branch', 'supplier', 'customer'])
            ->where(fn ($q) => $q->whereIn('imei_no', $numbers)->orWhereIn('imei_no_2', $numbers))
            ->orderBy('id')->get();

        return view('back-end.used-phone.show', [
            'name'     => 'Used Phones',
            'title'    => $phone->intake_no,
            'phone'    => $phone,
            'history'  => $history,
            'records'  => ProductImei::with('product')->where(fn ($q) => $q->whereIn('imei_no', $numbers)->orWhereIn('imei_no_2', $numbers))->orderBy('id')->get(),
            'intakes'  => UsedPhone::where('id', '!=', $phone->id)
                ->where(fn ($q) => $q->whereIn('imei_no', $numbers)->orWhereIn('imei_no_2', $numbers))->orderBy('id')->get(),
            'accounts' => Account::money()->active()->orderBy('code')->get(),
        ]);
    }

    // form theke IMEI jachai (save er age)
    public function checkImei(Request $request)
    {
        $request->validate(['product_id' => 'required|integer|exists:products,id', 'imei_no' => 'required|string|max:20', 'imei_no_2' => 'nullable|string|max:20']);
        $numbers = array_values(array_filter([trim($request->imei_no), trim((string) $request->imei_no_2)]));

        $previous = ProductImei::with('sale:id,invoice_no')->where(fn ($q) => $q->whereIn('imei_no', $numbers)->orWhereIn('imei_no_2', $numbers))
            ->orderBy('id')->get()
            ->map(fn ($d) => $d->numbersLabel() . ': ' . $d->statusText() . ($d->sale ? " ({$d->sale->invoice_no})" : ''));

        return response()->json([
            'errors'   => $this->phones->checkImeis(Product::findOrFail($request->product_id), $request->imei_no, $request->imei_no_2),
            'previous' => $previous,
        ]);
    }

    public function approve(Request $request, $id)
    {
        $request->validate(['next' => ['required', Rule::in([UsedPhoneService::NEXT_STOCK, UsedPhoneService::NEXT_REFURBISH])], 'note' => 'nullable|string|max:2000']);

        $phone = $this->find($request, $id);

        return $this->attempt(fn () => $this->phones->approve($phone, $request->user(), $request->next, $request->note),
            'Purchase approved and added to stock.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:255']);

        $phone = $this->find($request, $id);

        return $this->attempt(fn () => $this->phones->reject($phone, $request->user(), $request->reason), 'Intake rejected.');
    }

    public function refurbish(Request $request, $id)
    {
        $request->validate(['note' => 'required|string|max:2000']);

        $phone = $this->find($request, $id);

        return $this->attempt(fn () => $this->phones->startRefurbishment($phone, $request->user(), $request->note),
            'Phone sent to refurbishment.');
    }

    public function cost(Request $request, $id)
    {
        $request->validate([
            'amount'         => 'required|numeric|gt:0',
            'note'           => 'required|string|max:255',
            'account_id'     => 'nullable|integer',
            'payment_method' => 'nullable|string|max:30',
            'request_token'  => 'nullable|string|max:64',
        ]);

        $phone = $this->find($request, $id);

        return $this->attempt(fn () => $this->phones->addCost($phone, $request->user(), (float) $request->amount, $request->note,
            $request->integer('account_id') ?: null, $request->payment_method ?: 'Cash', $request->request_token), 'Refurbishment cost added.');
    }

    public function qc(Request $request, $id)
    {
        $request->validate([
            'passed'          => 'required|boolean',
            'note'            => 'required|string|max:2000',
            'condition_grade' => ['nullable', Rule::in(array_keys(config('used_phones.grades')))],
            'battery_health'  => 'nullable|integer|min:1|max:100',
            'resale_price'    => 'nullable|numeric|gt:0',
            'warranty_months' => 'nullable|integer|min:0|max:36',
            'warranty_terms'  => 'nullable|string|max:255',
        ]);

        $phone = $this->find($request, $id);

        return $this->attempt(fn () => $this->phones->completeRefurbishment($phone, $request->user(), $request->boolean('passed'),
            $request->note, $request->only('condition_grade', 'battery_health', 'resale_price', 'warranty_months', 'warranty_terms')), 'Refurbishment finished.');
    }

    public function pricing(Request $request, $id)
    {
        $request->validate([
            'resale_price'    => 'nullable|numeric|gt:0',
            'warranty_months' => 'nullable|integer|min:0|max:36',
            'warranty_terms'  => 'nullable|string|max:255',
        ]);

        $phone = $this->find($request, $id);

        return $this->attempt(fn () => $this->phones->updatePricing($phone, $request->user(),
            $request->only('resale_price', 'warranty_months', 'warranty_terms')), 'Resale price and warranty saved.');
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function find(Request $request, $id): UsedPhone
    {
        $phone = UsedPhone::findOrFail($id);
        abort_unless($request->user()->canAccessBranch($phone->branch_id), 403);

        return $phone;
    }

    private function filtered(Request $request)
    {
        $device = fn (int $status) => fn ($q) => $q->where('status', UsedPhone::IN_STOCK)->whereHas('device', fn ($d) => $d->where('status', $status));
        $term = trim((string) $request->q);

        return UsedPhone::with(['product:id,product_name,type', 'mainStock.ram', 'mainStock.rom', 'mainStock.color', 'device.saleDetail', 'branch:id,name'])
            ->accessibleBy($request->user())
            ->when($request->status, fn ($q, $status) => match ($status) {
                'available' => $device(ProductImei::STATUS_AVAILABLE)($q),
                'reserved'  => $device(ProductImei::STATUS_RESERVED)($q),
                'damaged'   => $device(ProductImei::STATUS_DAMAGED)($q),
                'sold'      => $device(ProductImei::STATUS_SOLD)($q),
                default     => $q->where('status', $status),
            })
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('intake_no', $term)->orWhere('seller_phone', $term)
                ->orWhere('imei_no', 'like', "%{$term}")->orWhere('imei_no_2', 'like', "%{$term}")))
            ->when($request->from_date, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to_date, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderByDesc('id');
    }

    // stock er phone er dam ar margin, bikri howa phone er asol margin
    private function summary($phones): array
    {
        $inStock = $phones->filter(fn ($p) => $p->isApproved() && $p->device && $p->device->isOwned());
        $sold = $phones->filter(fn ($p) => $p->soldPrice() !== null);

        return [
            'pending'         => $phones->where('status', UsedPhone::PENDING)->count(),
            'refurbishing'    => $phones->where('status', UsedPhone::REFURBISHING)->count(),
            'in_stock'        => $inStock->count(),
            'stock_cost'      => round($inStock->sum(fn ($p) => $p->totalCost()), 2),
            'expected_margin' => round($inStock->sum(fn ($p) => (float) $p->expectedMargin()), 2),
            'sold'            => $sold->count(),
            'sold_value'      => round($sold->sum(fn ($p) => $p->soldPrice()), 2),
            'realized_margin' => round($sold->sum(fn ($p) => $p->realizedMargin()), 2),
        ];
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
