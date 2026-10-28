<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ImeiMovement;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\ImeiService;
use Brian2694\Toastr\Facades\Toastr;

/**
 * IMEI khoja (IMEI 1/IMEI 2 ba shesh kichu digit), puro itihash (kena, bikri, ferot, malikana),
 * hate status bodlano (reserve, repair, damaged) ar status onujayi IMEI stock list.
 */
class ImeiController extends Controller
{
    public function __construct(private ImeiService $imeiService) {}

    public function search(Request $request)
    {
        $name = 'IMEI Tracking Report';
        $title = 'Search IMEI';
        $imei_no = trim((string) $request->imei_no);
        $devices = collect();
        $history = collect();

        if ($imei_no !== '') {
            $query = ProductImei::with(['product', 'mainStock.ram', 'mainStock.rom', 'mainStock.color', 'branch',
                'sale.customer', 'purchase.supplier', 'reservedCustomer']);

            // purno IMEI hole hubohu; 4-14 digit hole shesh digit diye (counter e chhoto kore khoja)
            if (preg_match('/^\d{4,14}$/', $imei_no)) {
                $query->where(fn ($q) => $q->where('imei_no', 'like', '%' . $imei_no)->orWhere('imei_no_2', 'like', '%' . $imei_no));
            } else {
                $query->whereNumber($imei_no);
            }
            $devices = $query->orderByDesc('id')->limit(20)->get();

            if ($devices->isNotEmpty()) {
                $numbers = $devices->flatMap(fn ($d) => [$d->imei_no, $d->imei_no_2])->filter()->unique()->values()->all();
                $history = ImeiMovement::with(['user', 'branch', 'supplier', 'customer', 'product'])
                    ->where(fn ($q) => $q->whereIn('imei_no', $numbers)->orWhereIn('imei_no_2', $numbers)
                        ->orWhereIn('product_imei_id', $devices->pluck('id')))
                    ->orderBy('id')
                    ->get();
                $this->fillParties($history);
            }
        }

        $customers = $request->user()->can('manage imei') ? Customer::where('status', 1)->orderBy('name')->get(['id', 'name', 'phone']) : collect();

        return view('back-end.reports.imei_report', compact('name', 'title', 'imei_no', 'devices', 'history', 'customers'));
    }

    // status onujayi IMEI list (default: dokhole thaka shob)
    public function index(Request $request)
    {
        $name = 'IMEI Stock List';
        $title = 'IMEI Stock List';

        $request->validate([
            'status'     => ['nullable', Rule::in(array_merge(['owned', 'all'], array_map('strval', array_keys(ProductImei::STATUSES))))],
            'product_id' => 'nullable|integer',
            'branch_id'  => 'nullable|integer',
        ]);
        $status = $request->input('status', 'owned');

        $imeis = ProductImei::with(['product', 'mainStock.ram', 'mainStock.rom', 'mainStock.color', 'branch', 'reservedCustomer'])
            ->when($status === 'owned', fn ($q) => $q->owned())
            ->when(is_numeric($status), fn ($q) => $q->where('status', (int) $status))
            ->when($request->product_id, fn ($q, $id) => $q->where('product_id', $id))
            ->when($request->branch_id, fn ($q, $id) => $q->where('branch_id', $id))
            ->accessibleBy($request->user())
            ->orderBy('product_id')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $counts = ProductImei::select('status', DB::raw('COUNT(*) as total'))
            ->accessibleBy($request->user())
            ->groupBy('status')
            ->pluck('total', 'status');
        $products = Product::whereNotIn('type', Product::NON_DEVICE_TYPES)->orderBy('product_name')->get(['id', 'product_name', 'model_number']);
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('back-end.reports.imei_stock', compact('name', 'title', 'imeis', 'counts', 'products', 'branches', 'status'));
    }

    public function changeStatus(Request $request, $id)
    {
        $imei = ProductImei::findOrFail($id);
        abort_unless($request->user()->canAccessBranch($imei->branch_id), 403);

        $request->validate([
            'status'      => ['required', 'integer', Rule::in(array_keys(ImeiService::TRANSITIONS[(int) $imei->status] ?? []))],
            'note'        => 'required|string|min:3|max:255',
            'customer_id' => ['nullable', 'required_if:status,' . ProductImei::STATUS_RESERVED, 'exists:customers,id'],
        ], [
            'status.in' => 'This status change is not allowed for a phone that is ' . $imei->statusText() . '.',
        ]);

        // refurbishment e thaka used phone er status Used Phones theke (QC) bodlay
        if (\App\Models\UsedPhone::where('product_imei_id', $imei->id)->where('status', \App\Models\UsedPhone::REFURBISHING)->exists()) {
            return back()->with('error', "IMEI {$imei->imei_no} is in used phone refurbishment. Finish it from Used Phones (QC).");
        }

        try {
            DB::transaction(fn () => $this->imeiService->changeStatus($imei, (int) $request->status, $request->note,
                $request->customer_id ? (int) $request->customer_id : null));
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        Toastr::success('IMEI status updated');
        return redirect()->route('reports.imei', ['imei_no' => $imei->imei_no]);
    }

    // purono itihash e supplier/customer column chilo na: reference (Purchase/Sale) theke
    private function fillParties($history): void
    {
        $purchaseIds = $history->whereNull('supplier_id')->where('reference_type', 'Purchase')->pluck('reference_id')->filter()->unique();
        $saleIds = $history->whereNull('customer_id')->where('reference_type', 'Sale')->pluck('reference_id')->filter()->unique();

        $purchases = $purchaseIds->isEmpty() ? collect() : Purchase::with('supplier')->whereIn('id', $purchaseIds)->get()->keyBy('id');
        $sales = $saleIds->isEmpty() ? collect() : Sale::with('customer')->whereIn('id', $saleIds)->get()->keyBy('id');

        foreach ($history as $move) {
            $move->party = $move->supplier?->name ? 'Supplier: ' . $move->supplier->name
                : ($move->customer?->name ? 'Customer: ' . $move->customer->name
                : ($move->reference_type === 'Purchase' && isset($purchases[$move->reference_id]) ? 'Supplier: ' . ($purchases[$move->reference_id]->supplier->name ?? '-')
                : ($move->reference_type === 'Sale' && isset($sales[$move->reference_id]) ? 'Customer: ' . ($sales[$move->reference_id]->customer->name ?? '-') : null)));
        }
    }
}
