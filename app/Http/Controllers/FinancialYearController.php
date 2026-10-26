<?php

namespace App\Http\Controllers;

use App\Models\FinancialYear;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * Financial year: report er filter ar closing. Close korle oi bochorer tarikhe kono entry/reverse hoy na.
 */
class FinancialYearController extends Controller
{
    public function index()
    {
        return view('back-end.finance.financial-years.index', [
            'name'  => 'Financial Year',
            'title' => 'Financial Years',
            'years' => FinancialYear::orderByDesc('start_date')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'       => 'required|string|max:60',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after:start_date',
        ]);

        $overlap = FinancialYear::where('start_date', '<=', $request->end_date)->where('end_date', '>=', $request->start_date)->first();
        if ($overlap) {
            return back()->with('error', "The dates overlap with {$overlap->name}.")->withInput();
        }

        $year = FinancialYear::create($request->only('name', 'start_date', 'end_date'));
        AuditLogger::log('finance.year_created', $year, null, $year->only(['name', 'start_date', 'end_date']));

        return back()->with('success', 'Financial year created.');
    }

    // Close/reopen; kara korlo audit log e thake
    public function toggle($id)
    {
        $year = FinancialYear::findOrFail($id);
        $year->update($year->is_closed
            ? ['is_closed' => false, 'closed_at' => null, 'closed_by' => null]
            : ['is_closed' => true, 'closed_at' => now(), 'closed_by' => auth()->id()]);

        AuditLogger::log($year->is_closed ? 'finance.year_closed' : 'finance.year_reopened', $year);

        return back()->with('success', "{$year->name} is now " . ($year->is_closed ? 'closed' : 'open') . '.');
    }
}
