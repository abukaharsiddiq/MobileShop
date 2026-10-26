<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Income;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\FinancePostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Sale chara onno ay (service charge, commission ...): Dr Cash/Bank, Cr Income account
class IncomeController extends Controller
{
    public function __construct(
        private FinancePostingService $finance,
        private AccountingService $accounting,
    ) {}

    public function index()
    {
        return view('back-end.finance.income.index', [
            'name'    => 'Income',
            'title'   => 'Other Income',
            'incomes' => Income::with(['incomeAccount', 'account'])->orderByDesc('income_date')->orderByDesc('id')->get(),
        ]);
    }

    public function create()
    {
        return view('back-end.finance.income.create', [
            'name'           => 'Income',
            'title'          => 'Add Income',
            'incomeAccounts' => Account::active()->where('type', Account::INCOME)->where('subtype', 'other_income')->orderBy('code')->get(),
            'accounts'       => Account::money()->active()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'income_date'       => 'required|date',
            'income_account_id' => 'required|exists:accounts,id',
            'account_id'        => 'required|exists:accounts,id',
            'amount'            => 'required|numeric|min:0.01',
            'received_from'     => 'nullable|string|max:255',
        ]);

        try {
            DB::transaction(function () use ($request) {
                AccountingService::assertNewToken($request->request_token, Income::class);

                $incomeAccount = Account::active()->where('type', Account::INCOME)->findOrFail($request->income_account_id);
                if (in_array($incomeAccount->subtype, ['sales', 'sales_return', 'sales_discount'], true)) {
                    throw new \Exception('Sales are recorded from the sale invoice, not as other income.');
                }
                $money = Account::moneyFor((int) $request->account_id);

                $income = Income::create([
                    'income_no'         => 'TMP-' . \Illuminate\Support\Str::random(20),
                    'income_date'       => $request->income_date,
                    'income_account_id' => $incomeAccount->id,
                    'account_id'        => $money->id,
                    'amount'            => round((float) $request->amount, 2),
                    'received_from'     => $request->received_from,
                    'note'              => $request->note,
                    'request_token'     => $request->request_token,
                    'status'            => 'posted',
                    'created_by'        => auth()->id(),
                ]);
                $income->income_no = 'INC-' . str_pad($income->id, 6, '0', STR_PAD_LEFT);
                $income->journal_entry_id = $this->finance->postIncome($income)->id;
                $income->save();
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('finance.income.index')->with('success', 'Income recorded.');
    }

    public function reverse(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:500']);

        try {
            DB::transaction(function () use ($request, $id) {
                $income = Income::lockForUpdate()->findOrFail($id);
                if ($income->status !== 'posted') {
                    throw new \Exception("Income {$income->income_no} is already reversed.");
                }
                $this->accounting->reverse(JournalEntry::findOrFail($income->journal_entry_id), $request->reason);
                $income->update(['status' => 'reversed', 'reversed_at' => now(), 'reverse_reason' => $request->reason]);
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return back()->with('success', 'Income reversed.');
    }
}
