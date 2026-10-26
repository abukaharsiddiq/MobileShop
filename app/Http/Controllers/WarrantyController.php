<?php

namespace App\Http\Controllers;

use App\Models\ImeiMovement;
use App\Models\ProductImei;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\WarrantyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Warranty lookup (invoice/IMEI), list ar report, expiry reminder, claim (jama, inspection, repair, ferot).
 * Status ar niyom WarrantyService/Warranty model e (server side).
 */
class WarrantyController extends Controller
{
    public function __construct(private WarrantyService $warranties) {}

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Warranty::STATUS_LABELS))],
            'type'   => ['nullable', Rule::in([Warranty::SHOP, Warranty::BRAND])],
        ]);
        $query = trim((string) $request->q);

        return view('back-end.warranty.index', [
            'name'       => 'Warranty',
            'title'      => 'Warranty Lookup',
            'query'      => $query,
            'results'    => $query !== '' ? $this->warranties->lookup($query) : null,
            'warranties' => $query === '' ? $this->filtered($request)->paginate(30)->withQueryString() : null,
        ]);
    }

    public function show($id)
    {
        $warranty = Warranty::with(['sale', 'customer', 'product', 'device', 'replacedFrom', 'openClaim',
            'claims' => fn ($q) => $q->with(['events.user', 'replacement'])->orderByDesc('id')])->findOrFail($id);

        return view('back-end.warranty.show', [
            'name'      => 'Warranty',
            'title'     => $warranty->warranty_no,
            'warranty'  => $warranty,
            'siblings'  => Warranty::where('sale_detail_id', $warranty->sale_detail_id)->where('id', '!=', $warranty->id)
                ->where(fn ($q) => $q->where('product_imei_id', $warranty->product_imei_id)->orWhere('replaced_from_id', $warranty->id))->get(),
            'history'   => $warranty->product_imei_id
                ? ImeiMovement::where('product_imei_id', $warranty->product_imei_id)->orderBy('id')->get() : collect(),
            'reasons'   => config('warranty.claim_reasons'),
        ]);
    }

    // ---- claim -----------------------------------------------------------------------------

    public function claims(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(array_merge(array_keys(WarrantyClaim::LABELS), ['open']))]]);

        $claims = WarrantyClaim::with(['warranty.product:id,product_name', 'customer:id,name,phone'])
            ->when($request->status === 'open', fn ($q) => $q->whereNotIn('status', WarrantyClaim::CLOSED))
            ->when($request->status && $request->status !== 'open', fn ($q) => $q->where('status', $request->status))
            ->when($request->q, fn ($q) => $q->where(fn ($w) => $w->where('claim_no', $request->q)
                ->orWhereHas('warranty', fn ($x) => $x->where('imei_no', $request->q)->orWhere('warranty_no', $request->q))))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('back-end.warranty.claims', ['name' => 'Warranty', 'title' => 'Warranty Claims', 'claims' => $claims]);
    }

    public function storeClaim(Request $request, $id)
    {
        $request->validate([
            'reported_on'      => 'required|date|before_or_equal:today',
            'reason'           => ['required', Rule::in(array_keys(config('warranty.claim_reasons')))],
            'description'      => 'required|string|max:2000',
            'device_condition' => 'nullable|string|max:255',
            'request_token'    => 'nullable|string|max:64',
        ]);

        try {
            $claim = $this->warranties->openClaim(Warranty::findOrFail($id),
                $request->only('reported_on', 'reason', 'description', 'device_condition', 'request_token'), $request->user());
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('warranty.claims.show', $claim->id)->with('success', "Claim {$claim->claim_no} received.");
    }

    public function showClaim($id)
    {
        $claim = WarrantyClaim::with(['warranty.sale', 'warranty.product', 'warranty.device', 'customer', 'events.user', 'replacement', 'receiver'])->findOrFail($id);

        return view('back-end.warranty.claim', [
            'name'         => 'Warranty',
            'title'        => $claim->claim_no,
            'claim'        => $claim,
            // replacement: ei variant er available phone (user er branch)
            'replacements' => $claim->warranty->product_imei_id
                ? ProductImei::where('main_stock_id', $claim->warranty->device->main_stock_id ?? 0)->where('status', ProductImei::STATUS_AVAILABLE)
                    ->accessibleBy(request()->user())->orderBy('id')->get(['id', 'imei_no', 'imei_no_2'])
                : collect(),
        ]);
    }

    public function inspect(Request $request, $id)
    {
        $request->validate([
            'covered'        => 'required|boolean',
            'next'           => ['nullable', Rule::in([WarrantyClaim::IN_REPAIR, WarrantyClaim::SENT_TO_BRAND])],
            'note'           => 'required|string|max:2000',
            'service_charge' => 'nullable|numeric|min:0',
        ]);

        return $this->attempt(fn () => $this->warranties->inspect(WarrantyClaim::findOrFail($id), $request->boolean('covered'), $request->note,
            $request->next ?: WarrantyClaim::IN_REPAIR, (float) $request->service_charge), 'Inspection saved.');
    }

    public function move(Request $request, $id)
    {
        $request->validate(['to' => ['required', Rule::in([WarrantyClaim::IN_REPAIR, WarrantyClaim::SENT_TO_BRAND])], 'note' => 'required|string|max:2000']);

        return $this->attempt(fn () => $this->warranties->move(WarrantyClaim::findOrFail($id), $request->to, $request->note), 'Claim updated.');
    }

    public function resolve(Request $request, $id)
    {
        $request->validate([
            'resolution'          => ['required', Rule::in(array_keys(WarrantyClaim::RESOLUTIONS))],
            'note'                => 'nullable|string|max:2000',
            'replacement_imei_id' => 'nullable|integer|exists:product_imeis,id',
        ]);
        if ($request->replacement_imei_id) {
            abort_unless($request->user()->canAccessBranch(ProductImei::find($request->replacement_imei_id)?->branch_id), 403);
        }

        return $this->attempt(fn () => $this->warranties->resolve(WarrantyClaim::findOrFail($id), $request->resolution, (string) $request->note,
            $request->integer('replacement_imei_id') ?: null), 'Claim is ready for the customer.');
    }

    public function deliver(Request $request, $id)
    {
        $request->validate(['note' => 'nullable|string|max:2000']);

        return $this->attempt(fn () => $this->warranties->deliver(WarrantyClaim::findOrFail($id), $request->note), 'Device handed back to the customer.');
    }

    // ---- reminder / report ----------------------------------------------------------------------

    public function expiring(Request $request)
    {
        $request->validate(['days' => 'nullable|integer|min:1|max:365']);
        $days = $request->integer('days') ?: (int) config('warranty.expiring_soon_days');

        return view('back-end.warranty.expiring', [
            'name'       => 'Warranty',
            'title'      => 'Expiring Warranties',
            'days'       => $days,
            'warranties' => $this->warranties->expiring($days),
        ]);
    }

    public function reminded(Request $request, $id)
    {
        $request->validate(['channel' => 'required|in:whatsapp,call,sms']);
        $this->warranties->markReminded(Warranty::findOrFail($id), $request->channel);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Reminder noted.');
    }

    public function report(Request $request)
    {
        $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date|after_or_equal:from_date']);
        $from = $request->from_date ?: now()->startOfYear()->toDateString();
        $to = $request->to_date ?: now()->toDateString();

        return view('back-end.warranty.report', [
            'name'   => 'Warranty',
            'title'  => 'Warranty Report',
            'from'   => $from,
            'to'     => $to,
            'report' => $this->warranties->report($from, $to),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(Warranty::STATUS_LABELS))]]);
        $rows = $this->filtered($request)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Warranty', 'Type', 'Invoice', 'Customer', 'Phone', 'Product', 'IMEI', 'Qty', 'Start', 'End', 'Status', 'Claims']);
            foreach ($rows as $w) {
                fputcsv($out, [$w->warranty_no, $w->type, $w->sale->invoice_no ?? '', $w->customer->name ?? '', $w->customer->phone ?? '',
                    $w->product->product_name ?? '', $w->imei_no, $w->quantity, $w->start_date->toDateString(), $w->end_date->toDateString(),
                    $w->statusLabel(), $w->claims_count]);
            }
            fclose($out);
        }, 'warranties-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    // ---- helpers ------------------------------------------------------------------------------

    // Status database e na - meyad/claim theke; tai filter o sei hishabe
    private function filtered(Request $request)
    {
        $today = now()->toDateString();
        $soon = now()->addDays((int) config('warranty.expiring_soon_days'))->toDateString();
        $open = fn ($q) => $q->whereNotIn('status', WarrantyClaim::CLOSED);

        return Warranty::with(['sale:id,invoice_no', 'customer:id,name,phone', 'product:id,product_name', 'openClaim'])->withCount('claims')
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->from_date, fn ($q) => $q->where('start_date', '>=', $request->from_date))
            ->when($request->to_date, fn ($q) => $q->where('start_date', '<=', $request->to_date))
            ->when($request->status, fn ($q) => match ($request->status) {
                'void', 'replaced' => $q->where('status', $request->status),
                'claimed'          => $q->active()->whereHas('claims', $open),
                'expired'          => $q->active()->whereDoesntHave('claims', $open)->where('end_date', '<', $today),
                'expiring_soon'    => $q->active()->whereDoesntHave('claims', $open)->whereBetween('end_date', [$today, $soon]),
                'valid'            => $q->active()->whereDoesntHave('claims', $open)->where('end_date', '>', $soon),
            })
            ->orderByDesc('start_date')->orderByDesc('id');
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
