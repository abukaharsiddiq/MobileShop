<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\FinancePostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Cash <-> bank <-> mobile banking: Dr to, Cr from (ay-byay na)
class AccountTransferController extends Controller
{
    public function __construct(
        private FinancePostingService $finance,
        private AccountingService $accounting,
    ) {}

    public function index()
    {
        return view('back-end.finance.transfers.index', [
            'name'      => 'Fund Transfer',
            'title'     => 'Cash / Bank Transfer',
            'transfers' => AccountTransfer::with(['fromAccount', 'toAccount'])->orderByDesc('transfer_date')->orderByDesc('id')->get(),
        ]);
    }

    public function create()
    {
        $accounts = Account::money()->active()->orderBy('code')->get();
        foreach ($accounts as $account) {
            $account->current_balance = $account->balance();
        }

        return view('back-end.finance.transfers.create', [
            'name'     => 'Fund Transfer',
            'title'    => 'New Transfer',
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'transfer_date'   => 'required|date',
            'from_account_id' => 'required|exists:accounts,id',
            'to_account_id'   => 'required|exists:accounts,id|different:from_account_id',
            'amount'          => 'required|numeric|min:0.01',
            'reference'       => 'nullable|string|max:255',
        ]);

        try {
            DB::transaction(function () use ($request) {
                AccountingService::assertNewToken($request->request_token, AccountTransfer::class);

                $from = Account::moneyFor((int) $request->from_account_id);
                $to = Account::moneyFor((int) $request->to_account_id);
                $amount = round((float) $request->amount, 2);

                // je account e taka nei sekhan theke pathano jay na
                Account::whereKey($from->id)->lockForUpdate()->first();
                $available = $from->balance($request->transfer_date);
                if ($amount > $available) {
                    throw new \Exception("{$from->name} has only " . number_format($available, 2) . " on {$request->transfer_date}.");
                }

                $transfer = AccountTransfer::create([
                    'transfer_no'     => 'TMP-' . \Illuminate\Support\Str::random(20),
                    'transfer_date'   => $request->transfer_date,
                    'from_account_id' => $from->id,
                    'to_account_id'   => $to->id,
                    'amount'          => $amount,
                    'reference'       => $request->reference,
                    'note'            => $request->note,
                    'request_token'   => $request->request_token,
                    'status'          => 'posted',
                    'created_by'      => auth()->id(),
                ]);
                $transfer->transfer_no = 'TRF-' . str_pad($transfer->id, 6, '0', STR_PAD_LEFT);
                $transfer->journal_entry_id = $this->finance->postTransfer($transfer)->id;
                $transfer->save();
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('finance.transfers.index')->with('success', 'Transfer recorded.');
    }

    public function reverse(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:500']);

        try {
            DB::transaction(function () use ($request, $id) {
                $transfer = AccountTransfer::lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'posted') {
                    throw new \Exception("Transfer {$transfer->transfer_no} is already reversed.");
                }
                $this->accounting->reverse(JournalEntry::findOrFail($transfer->journal_entry_id), $request->reason);
                $transfer->update(['status' => 'reversed', 'reversed_at' => now(), 'reverse_reason' => $request->reason]);
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return back()->with('success', 'Transfer reversed.');
    }
}
