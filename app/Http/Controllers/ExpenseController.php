<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\AuditLogger;
use App\Services\FinancePostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

/**
 * Expense: Dr expense (category er account), Cr cash/bank. Posted expense muchha hoy na:
 * delete = reverse (ulta entry), edit = purono entry reverse kore notun revision post.
 */
class ExpenseController extends Controller
{
    public function __construct(
        private FinancePostingService $finance,
        private AccountingService $accounting,
    ) {}

    public function index()
    {
        $expenses = Expense::with(['category', 'account'])->orderBy('expense_date', 'desc')->orderBy('id', 'desc')->get();
        return view('back-end.expense.expense.index', compact('expenses'));
    }

    public function create()
    {
        $categories = ExpenseCategory::where('status', 1)->get();
        $accounts = Account::money()->active()->orderBy('code')->get();
        return view('back-end.expense.expense.create', compact('categories', 'accounts'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        try {
            DB::transaction(function () use ($data, $request) {
                AccountingService::assertNewToken($request->request_token, Expense::class);

                $expense = Expense::create($data + [
                    'created_by'    => Auth::id(),
                    'request_token' => $request->request_token,
                    'status'        => 'posted',
                ]);
                $expense->update(['expense_no' => 'EXP-' . str_pad($expense->id, 6, '0', STR_PAD_LEFT)]);

                $journal = $this->finance->postExpense($expense);
                $expense->update(['journal_entry_id' => $journal->id]);
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('expense.index')->with('success', 'Expense recorded successfully!');
    }

    public function edit($id)
    {
        $expense = Expense::findOrFail($id);
        $categories = ExpenseCategory::all();
        $accounts = Account::money()->active()->orderBy('code')->get();
        return view('back-end.expense.expense.edit', compact('expense', 'categories', 'accounts'));
    }

    // Adjustment: purono journal reverse, ekoi expense er notun revision post (itihash thake)
    public function update(Request $request, $id)
    {
        $data = $this->validated($request);

        try {
            DB::transaction(function () use ($data, $id) {
                $expense = Expense::lockForUpdate()->findOrFail($id);
                if ($expense->status !== 'posted') {
                    throw new \Exception('A reversed expense cannot be edited.');
                }
                $old = $expense->only(['category_id', 'expense_date', 'amount', 'account_id', 'note']);

                if ($expense->journal_entry_id) {
                    $this->accounting->reverse(JournalEntry::findOrFail($expense->journal_entry_id), 'Expense ' . $expense->expense_no . ' edited');
                }

                $expense->update($data);
                $revision = JournalEntry::where('source_type', 'Expense')->where('source_id', $expense->id)->where('event', 'expense')->count() + 1;
                $journal = $this->finance->postExpense($expense, $revision);
                $expense->update(['journal_entry_id' => $journal->id]);

                AuditLogger::log('expense.adjusted', $expense, $old, $expense->only(array_keys($old)));
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('expense.index')->with('success', 'Expense updated successfully!');
    }

    // Delete = reverse: expense record ar dui journal entry (original + ulta) thake
    public function delete(Request $request, $id)
    {
        try {
            DB::transaction(function () use ($request, $id) {
                $expense = Expense::lockForUpdate()->findOrFail($id);
                if ($expense->status !== 'posted') {
                    throw new \Exception("Expense {$expense->expense_no} is already reversed.");
                }

                $reason = trim((string) $request->reason) ?: 'Expense cancelled';
                if ($expense->journal_entry_id) {
                    $this->accounting->reverse(JournalEntry::findOrFail($expense->journal_entry_id), $reason);
                }
                $expense->update(['status' => 'reversed', 'reversed_at' => now(), 'reverse_reason' => $reason]);
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return redirect()->back()->with('success', 'Expense reversed. The original and the reversal stay in the books.');
    }

    private function validated(Request $request): array
    {
        $request->validate([
            'category_id'  => 'required|exists:expense_categories,id',
            'expense_date' => 'required|date',
            'amount'       => 'required|numeric|min:0.01',
            'account_id'   => 'nullable|integer|exists:accounts,id',
        ]);

        $account = Account::moneyFor($request->account_id ? (int) $request->account_id : null, $request->payment_method);

        return [
            'category_id'    => $request->category_id,
            'expense_date'   => $request->expense_date,
            'amount'         => round((float) $request->amount, 2),
            'payment_method' => $request->payment_method ?: ($account->payment_method ?? $account->name),
            'account_id'     => $account->id,
            'note'           => $request->note,
        ];
    }
}
