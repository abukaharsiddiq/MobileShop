<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Services\BankReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankReconciliationController extends Controller
{
    public function __construct(private BankReconciliationService $service) {}

    public function index()
    {
        return view('back-end.finance.reconciliation.index', [
            'name'            => 'Reconciliation',
            'title'           => 'Cash / Bank Reconciliation',
            'reconciliations' => BankReconciliation::with('account')->orderByDesc('statement_date')->orderByDesc('id')->get(),
            'accounts'        => Account::money()->active()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'account_id'        => 'required|exists:accounts,id',
            'statement_date'    => 'required|date',
            'statement_balance' => 'required|numeric',
        ]);

        try {
            $reconciliation = DB::transaction(fn () => $this->service->start(Account::findOrFail($request->account_id),
                $request->statement_date, (float) $request->statement_balance, $request->note));
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('finance.reconciliation.show', $reconciliation->id);
    }

    public function show($id)
    {
        $reconciliation = BankReconciliation::with('account')->findOrFail($id);

        return view('back-end.finance.reconciliation.show', [
            'name'           => 'Reconciliation',
            'title'          => $reconciliation->account->name,
            'reconciliation' => $reconciliation,
            'lines'          => $reconciliation->status === BankReconciliation::DRAFT
                ? $this->service->openLines($reconciliation)
                : $reconciliation->lines()->with('entry')->get(),
        ]);
    }

    // cleared line save; "complete" button dile save er por complete
    public function update(Request $request, $id)
    {
        $request->validate(['lines' => 'nullable|array', 'lines.*' => 'integer']);

        try {
            DB::transaction(function () use ($request, $id) {
                $reconciliation = $this->service->setCleared(BankReconciliation::findOrFail($id), (array) $request->lines);
                if ($request->action === 'complete') {
                    $this->service->complete($reconciliation);
                }
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return redirect()->route('finance.reconciliation.show', $id)
            ->with('success', $request->action === 'complete' ? 'Reconciliation completed.' : 'Cleared items saved.');
    }

    public function discard($id)
    {
        try {
            DB::transaction(fn () => $this->service->discard(BankReconciliation::findOrFail($id)));
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return redirect()->route('finance.reconciliation.index')->with('success', 'Draft reconciliation discarded.');
    }
}
