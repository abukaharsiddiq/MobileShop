<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\CashSessionAdjustment;
use App\Models\User;
use App\Services\CashClosingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daily cash closing. Cashier nijer session dekhe/chalay; 'read cash closing' thaka user (nijer branch er) shob dekhe;
 * 'approve cash closing' review kore. Hishab CashClosingService e.
 */
class CashClosingController extends Controller
{
    public function __construct(private CashClosingService $closing) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $all = $user->can('read cash closing');

        $sessions = $this->filtered($request, $all)->with(['cashier:id,name', 'branch:id,name', 'reviewer:id,name'])
            ->orderByDesc('business_date')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('back-end.cash-closing.index', [
            'name'      => 'Cash Closing',
            'title'     => 'Daily Cash Closing',
            'sessions'  => $sessions,
            'mine'      => CashSession::where('user_id', $user->id)->whereIn('status', [CashSession::OPEN, CashSession::RECOUNT])->orderByDesc('id')->first(),
            'todayDone' => CashSession::where('user_id', $user->id)->where('business_date', now()->toDateString())->first(),
            'all'       => $all,
            'branches'  => Branch::where('status', 1)->get()->filter(fn ($b) => $user->canAccessBranch($b->id)),
            'cashiers'  => $all ? User::whereIn('id', CashSession::select('user_id'))->orderBy('name')->get(['id', 'name']) : collect(),
            'pending'   => $user->can('approve cash closing') ? CashSessionAdjustment::with('session')->where('status', 'pending')->get() : collect(),
        ]);
    }

    public function open(Request $request)
    {
        $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'branch_id'       => 'nullable|integer|exists:branches,id',
            'opening_note'    => 'nullable|string|max:1000',
        ]);

        try {
            $session = $this->closing->open($request->user(), (float) $request->opening_balance, $request->integer('branch_id') ?: null, $request->opening_note);
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('cash-closing.show', $session->id)->with('success', "Cash session {$session->session_no} opened.");
    }

    public function show(Request $request, $id)
    {
        $session = $this->visible($request, $id);

        return view('back-end.cash-closing.show', [
            'name'     => 'Cash Closing',
            'title'    => $session->session_no,
            'session'  => $session->load(['cashier', 'branch', 'reviewer', 'adjustments.requester', 'adjustments.reviewer']),
            'summary'  => $this->closing->summary($session),
            'notes'    => config('cash.denominations'),
            'accounts' => Account::active()->where('subtype', '!=', 'cash')->whereNotIn('subtype', ['receivable', 'payable', 'inventory'])
                ->whereIn('type', [Account::ASSET, Account::EQUITY, Account::LIABILITY, Account::INCOME, Account::EXPENSE])->orderBy('code')->get(),
        ]);
    }

    public function movement(Request $request, $id)
    {
        $request->validate([
            'direction'  => 'required|in:in,out',
            'amount'     => 'required|numeric|min:0.01',
            'account_id' => 'required|integer|exists:accounts,id',
            'note'       => 'required|string|max:255',
        ]);

        return $this->attempt(fn () => $this->closing->movement(CashSession::findOrFail($id), $request->user(), $request->direction,
            (float) $request->amount, (int) $request->account_id, $request->note), 'Cash movement recorded.');
    }

    public function close(Request $request, $id)
    {
        $request->validate([
            'counted_balance' => 'nullable|numeric|min:0',
            'denominations'   => 'nullable|array',
            'denominations.*' => 'nullable|integer|min:0',
            'coins'           => 'nullable|numeric|min:0',
            'closing_note'    => 'nullable|string|max:1000',
            'version'         => 'required|integer',
        ]);

        return $this->attempt(fn () => $this->closing->close(CashSession::findOrFail($id), $request->user(),
            $request->only('counted_balance', 'denominations', 'coins'), $request->closing_note, (int) $request->version),
            'Cash counted and session closed. A manager will review it.');
    }

    public function approve(Request $request, $id)
    {
        $request->validate(['review_note' => 'nullable|string|max:1000', 'version' => 'required|integer']);

        $session = $this->reviewable($request, $id);   // 403 (try er baire)

        return $this->attempt(fn () => $this->closing->approve($session, $request->user(), $request->review_note, (int) $request->version),
            'Cash closing approved.');
    }

    public function recount(Request $request, $id)
    {
        $request->validate(['review_note' => 'required|string|max:1000']);

        $session = $this->reviewable($request, $id);   // 403 (try er baire)

        return $this->attempt(fn () => $this->closing->recount($session, $request->user(), $request->review_note),
            'Sent back to the cashier for a recount.');
    }

    public function reopen(Request $request, $id)
    {
        $request->validate(['review_note' => 'required|string|max:1000']);

        $session = $this->reviewable($request, $id);   // 403 (try er baire)

        return $this->attempt(fn () => $this->closing->reopen($session, $request->user(), $request->review_note),
            'Session reopened for today.');
    }

    public function requestAdjustment(Request $request, $id)
    {
        $request->validate(['amount' => 'required|numeric|not_in:0', 'reason' => 'required|string|max:1000']);
        $session = $this->visible($request, $id);

        return $this->attempt(fn () => $this->closing->requestAdjustment($session, $request->user(), (float) $request->amount, $request->reason),
            'Adjustment requested; it changes the books only after approval.');
    }

    public function reviewAdjustment(Request $request, $adjustmentId)
    {
        $request->validate(['decision' => 'required|in:approve,reject', 'review_note' => 'nullable|string|max:1000']);
        $adjustment = CashSessionAdjustment::findOrFail($adjustmentId);
        $this->reviewable($request, $adjustment->cash_session_id);

        return $this->attempt(fn () => $this->closing->reviewAdjustment($adjustment, $request->user(), $request->decision === 'approve', $request->review_note),
            $request->decision === 'approve' ? 'Adjustment approved and posted.' : 'Adjustment rejected.');
    }

    public function print(Request $request, $id)
    {
        $session = $this->visible($request, $id);

        return view('back-end.cash-closing.print', [
            'session' => $session->load(['cashier', 'branch', 'reviewer', 'adjustments']),
            'summary' => $this->closing->summary($session),
        ]);
    }

    // Filter kora session gulo CSV (Excel e khole)
    public function export(Request $request): StreamedResponse
    {
        $sessions = $this->filtered($request, $request->user()->can('read cash closing'))->with(['cashier:id,name', 'branch:id,name', 'reviewer:id,name'])
            ->orderBy('business_date')->orderBy('id')->get();

        return response()->streamDownload(function () use ($sessions) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Session', 'Date', 'Branch', 'Cashier', 'Status', 'Opening', 'Expected', 'Counted', 'Difference', 'Approved by', 'Approved at', 'Note']);
            foreach ($sessions as $s) {
                fputcsv($out, [$s->session_no, $s->business_date->toDateString(), $s->branch->name ?? '', $s->cashier->name ?? '', $s->statusLabel(),
                    $s->opening_balance, $s->expected_balance, $s->counted_balance, $s->difference, $s->reviewer->name ?? '',
                    $s->reviewed_at?->toDateTimeString(), $s->review_note ?: $s->closing_note]);
            }
            fclose($out);
        }, 'cash-closing-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function filtered(Request $request, bool $all)
    {
        $user = $request->user();

        return CashSession::query()
            ->when(!$all, fn ($q) => $q->where('user_id', $user->id))
            // shob dekhar permission thakleo shudhu nijer branch (shob branch access na thakle)
            ->when($all && !$user->canAccessAllBranches() && $user->branch_id, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $user->branch_id)->orWhere('user_id', $user->id)))
            ->when($request->from_date, fn ($q) => $q->where('business_date', '>=', $request->from_date))
            ->when($request->to_date, fn ($q) => $q->where('business_date', '<=', $request->to_date))
            ->when($request->branch_id, fn ($q) => $q->where('branch_id', $request->branch_id))
            ->when($all && $request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status));
    }

    private function visible(Request $request, $id): CashSession
    {
        $session = CashSession::findOrFail($id);
        abort_unless($session->visibleTo($request->user()), 403);

        return $session;
    }

    private function reviewable(Request $request, $id): CashSession
    {
        $session = CashSession::findOrFail($id);
        abort_unless($request->user()->can('approve cash closing') && $request->user()->canAccessBranch($session->branch_id), 403);

        return $session;
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
