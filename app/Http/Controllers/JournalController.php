<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FinancialYear;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\FinanceReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Journal (day book): shob posted entry, source document shoho. Manual adjustment entry ar reverse.
 * Document (sale, purchase, expense, voucher) er entry tar nijer page theke reverse hoy, jate document er
 * hishab (due, status) o mile; ekhane shudhu manual entry reverse kora jay.
 */
class JournalController extends Controller
{
    public function __construct(
        private AccountingService $accounting,
        private FinanceReportService $reports,
    ) {}

    public function index(Request $request)
    {
        [$from, $to, $year] = $this->reports->period($request);

        $entries = JournalEntry::with('lines.account')
            ->whereBetween('entry_date', [$from, $to])
            ->when($request->event, fn ($q) => $q->where('event', $request->event))
            ->when($request->search, fn ($q) => $q->where(fn ($q) => $q->where('entry_no', 'like', "%{$request->search}%")
                ->orWhere('reference_no', 'like', "%{$request->search}%")))
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('back-end.finance.journal.index', [
            'name'    => 'Journal',
            'title'   => 'Journal / Day Book',
            'entries' => $entries,
            'events'  => JournalEntry::distinct()->orderBy('event')->pluck('event'),
            'years'   => FinancialYear::orderByDesc('start_date')->get(),
            'from'    => $from,
            'to'      => $to,
            'year'    => $year,
        ]);
    }

    public function show($id)
    {
        return view('back-end.finance.journal.show', [
            'name'  => 'Journal Entry',
            'title' => 'Journal Entry',
            'entry' => JournalEntry::with(['lines.account', 'reversalOf', 'reversedBy', 'creator'])->findOrFail($id),
        ]);
    }

    public function create()
    {
        return view('back-end.finance.journal.create', [
            'name'     => 'Journal',
            'title'    => 'Adjustment Entry',
            'accounts' => $this->manualAccounts(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'entry_date'       => 'required|date',
            'narration'        => 'required|string|max:1000',
            'lines'            => 'required|array|min:2',
            'lines.*.account_id' => 'nullable|integer',
            'lines.*.debit'    => 'nullable|numeric|min:0',
            'lines.*.credit'   => 'nullable|numeric|min:0',
            'lines.*.memo'     => 'nullable|string|max:255',
        ]);

        try {
            $entry = DB::transaction(function () use ($request) {
                $allowed = $this->manualAccounts()->keyBy('id');
                $lines = [];
                foreach ($request->lines as $line) {
                    if (empty($line['account_id']) && empty($line['debit']) && empty($line['credit'])) {
                        continue;
                    }
                    // AR/AP/Inventory subledger/stock er sathe milte hobe, tai manual entry te na
                    if (!$allowed->has((int) ($line['account_id'] ?? 0))) {
                        throw new \Exception('Receivable, payable and inventory accounts can only be changed by their documents. Pick another account.');
                    }
                    $lines[] = ['account' => (int) $line['account_id'], 'debit' => $line['debit'] ?? 0,
                        'credit' => $line['credit'] ?? 0, 'memo' => $line['memo'] ?? null];
                }

                return $this->accounting->post([
                    'date'         => $request->entry_date,
                    'event'        => 'adjustment',
                    'reference_no' => $request->reference_no,
                    'narration'    => $request->narration,
                    'posting_key'  => $request->request_token ? 'manual:' . $request->request_token : null,
                ], $lines);
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('finance.journal.show', $entry->id)->with('success', "Entry {$entry->entry_no} posted.");
    }

    public function reverse(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:500', 'reverse_date' => 'nullable|date']);

        try {
            $reversal = DB::transaction(function () use ($request, $id) {
                $entry = JournalEntry::findOrFail($id);
                // reversal entry hole AccountingService nijei thik karon bole
                if ($entry->event !== 'adjustment' && !$entry->reversal_of_id) {
                    throw new \Exception('This entry belongs to a document. Reverse it from the document (expense, voucher, income or transfer) so its balance also changes.');
                }

                return $this->accounting->reverse($entry, $request->reason, $request->reverse_date);
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return redirect()->route('finance.journal.show', $reversal->id)->with('success', "Reversed by {$reversal->entry_no}.");
    }

    private function manualAccounts()
    {
        return Account::active()->whereNotIn('subtype', ['receivable', 'payable', 'inventory'])->orderBy('code')->get();
    }
}
