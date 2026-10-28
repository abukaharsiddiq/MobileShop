<?php

namespace App\Http\Controllers;

use App\Services\BranchReportService;
use Illuminate\Http\Request;

/**
 * Branch onujayi purchase, sales, expense, labh ar stock. User shudhu nijer branch gulo dekhe
 * (shob branch er access thakle shob ar "In Transit").
 */
class BranchReportController extends Controller
{
    public function __construct(private BranchReportService $reports) {}

    public function summary(Request $request)
    {
        $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date|after_or_equal:from_date']);
        $from = $request->from_date ?: now()->startOfMonth()->toDateString();
        $to = $request->to_date ?: now()->toDateString();

        return view('back-end.branch.report', [
            'name'   => 'Branch',
            'title'  => 'Branch Report',
            'from'   => $from,
            'to'     => $to,
            'report' => $this->reports->summary($from, $to, $request->user()->allowedBranchIds()),
        ]);
    }

    public function stock(Request $request)
    {
        $request->validate(['q' => 'nullable|string|max:100']);

        return view('back-end.branch.stock', [
            'name'   => 'Branch',
            'title'  => 'Branch-wise Stock',
            'matrix' => $this->reports->stockMatrix($request->user()->allowedBranchIds(), trim((string) $request->q) ?: null, !$request->boolean('all')),
        ]);
    }
}
