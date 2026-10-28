<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Branch;
use App\Models\ProductImei;
use App\Services\DashboardService;

class AdminController extends Controller
{
    // Somoy (range) ar branch er filter; shob card/chart/table ekoi filter e (DashboardService)
    public function index(Request $request, DashboardService $dashboard)
    {
        $request->validate([
            'range'     => ['nullable', Rule::in(array_keys(DashboardService::RANGES))],
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);
        $branchId = $request->integer('branch_id') ?: null;

        return view('back-end.admin.dashboard', [
            'd'        => $dashboard->build($request->input('range', '30d'), $branchId),
            'ranges'   => DashboardService::RANGES,
            'branches' => Branch::orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'type', 'is_default']),
            'branchId' => $branchId,
        ]);
    }


	public function imeiSearch(Request $request)
{
    $imei = $request->imei;

    // Ekta IMEI ekadhikbar thakte pare (bikri howa phone abar kena hole); shesh record tai ekhonkar obostha
    // IMEI 1 ba IMEI 2
    $item = ProductImei::with(['product', 'mainStock.ram', 'mainStock.rom', 'mainStock.color', 'sale'])
        ->whereNumber((string) $imei)
        ->latest('id')
        ->first();

    if ($item) {
        $stock = $item->mainStock;
        $variant = $stock
            ? implode(' / ', array_filter([$stock->ram->ram_name ?? null, $stock->rom->rom_name ?? null, $stock->color->color_name ?? null]))
            : 'N/A';

        $saleInfo = match ((int) $item->status) {
            ProductImei::STATUS_SOLD => 'Invoice: ' . ($item->sale->invoice_no ?? ('#' . $item->sale_id)),
            ProductImei::STATUS_AVAILABLE => 'Ready to Sell',
            default => $item->statusText() . ($item->status_note ? ': ' . $item->status_note : ''),
        };

        return response()->json([
            'status' => 'success',
            'data' => [
                'product_name' => $item->product->product_name ?? 'N/A',
                'variant'      => $variant,
                'current_status' => (int) $item->status, // ProductImei::STATUSES
                'status_text'  => $item->statusText(),
                'badge'        => $item->statusBadge(),
                'sale_info'    => $saleInfo,
            ]
        ]);
    }

    return response()->json(['status' => 'error', 'message' => 'IMEI not found!']);
}

}
