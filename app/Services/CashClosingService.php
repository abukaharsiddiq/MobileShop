<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\CashSessionAdjustment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Daily cash closing. Expected cash ledger (journal) theke: opening count + ei session e jukto cash line er
 * (debit - credit). Bank/mobile er taka drawer e gona hoy na - alada dekhano hoy.
 *
 *  open -> (cash neya/deya) -> close (gona) -> manager approve (parthokko Cash Over/Short e post) -> bondho
 *          manager: recount (abar gona) ba reopen (ajker session e abar lenden)
 *  approve er pore: shudhu approve kora adjustment.
 *
 * Shob method nijer DB transaction e; session row lock + version (form er copy purono hole atkay).
 */
class CashClosingService
{
    // drawer e asa/jawa na (gona r parthokko) - expected e dhora hoy na
    const COUNT_EVENTS = ['cash_count_difference', 'cash_count_adjustment'];

    public function __construct(private AccountingService $accounting) {}

    public function open(User $user, float $opening, ?int $branchId = null, ?string $note = null): CashSession
    {
        $opening = round($opening, 2);
        if ($opening < 0) {
            throw new \Exception('Opening cash cannot be negative.');
        }
        $branchId = $branchId ?: ($user->branch_id ?? Branch::currentId());
        if (!$branchId || !Branch::where('status', 1)->whereKey($branchId)->exists()) {
            throw new \Exception('Select an active branch for the cash session.');
        }
        if (!$user->canAccessBranch($branchId)) {
            throw new \Exception('You cannot open a cash session for another branch.');
        }

        return DB::transaction(function () use ($user, $opening, $branchId, $note) {
            // ekoi cashier er session gulo ekshathe (duita tab theke duibar open na)
            User::whereKey($user->id)->lockForUpdate()->first();
            $today = now()->toDateString();

            if ($open = CashSession::where('user_id', $user->id)->where('status', CashSession::OPEN)->first()) {
                throw new \Exception("You already have an open cash session ({$open->session_no} of {$open->business_date->format('d-m-Y')}). Close it first.");
            }
            if ($same = CashSession::where('user_id', $user->id)->where('branch_id', $branchId)->where('business_date', $today)->first()) {
                throw new \Exception("Your cash session for today already exists ({$same->session_no}, {$same->statusLabel()}).");
            }

            $previous = CashSession::where('user_id', $user->id)->where('branch_id', $branchId)->whereNotNull('counted_balance')
                ->orderByDesc('business_date')->value('counted_balance');

            try {
                $session = CashSession::create([
                    'session_no'       => 'TMP-' . \Illuminate\Support\Str::random(20),
                    'branch_id'        => $branchId,
                    'user_id'          => $user->id,
                    'business_date'    => $today,
                    'status'           => CashSession::OPEN,
                    'opening_balance'  => $opening,
                    'previous_closing' => $previous,
                    'opening_note'     => $note,
                    'opened_at'        => now(),
                ]);
            } catch (QueryException $e) {
                // duita request ekshathe: database er unique index (cashier+branch+din, ekta open)
                if (str_contains($e->getMessage(), 'cash_sessions_')) {
                    throw new \Exception('A cash session for you already exists for today.');
                }
                throw $e;
            }
            $session->update(['session_no' => 'CS-' . now()->format('ymd') . '-' . str_pad((string) $session->id, 4, '0', STR_PAD_LEFT)]);

            AuditLogger::log('cash_session.opened', $session, null, $session->only(['session_no', 'branch_id', 'business_date', 'opening_balance', 'previous_closing']));

            return $session;
        });
    }

    /**
     * Drawer e taka dhukano/ber kora (sale/expense chara): owner er float, bank theke ana, owner nilo ...
     * $direction in|out; $counterAccountId: kon account er sathe (capital, drawings, bank ...).
     */
    public function movement(CashSession $session, User $user, string $direction, float $amount, int $counterAccountId, string $note): JournalEntry
    {
        return DB::transaction(function () use ($session, $user, $direction, $amount, $counterAccountId, $note) {
            $session = $this->lockOwnOpen($session, $user);
            $amount = round($amount, 2);
            if ($amount <= 0 || !in_array($direction, ['in', 'out'], true)) {
                throw new \Exception('Enter an amount and choose cash in or cash out.');
            }
            $counter = Account::active()->findOrFail($counterAccountId);
            if ($counter->subtype === 'cash' || $counter->isControl()) {
                throw new \Exception('Choose a bank, capital, drawings, income or expense account for the other side.');
            }
            $in = $direction === 'in';

            $entry = $this->accounting->post([
                'event'           => 'cash_movement',
                'source'          => $session,
                'posting_key'     => 'CashSession:' . $session->id . ':cash_movement:' . (JournalEntry::where('source_type', 'CashSession')->where('source_id', $session->id)->where('event', 'cash_movement')->count() + 1),
                'reference_no'    => $session->session_no,
                'narration'       => ($in ? 'Cash in: ' : 'Cash out: ') . $note,
                'branch_id'       => $session->branch_id,
                'cash_session_id' => $session->id,
            ], [
                ['account' => $this->cashAccount($session), 'debit' => $in ? $amount : 0, 'credit' => $in ? 0 : $amount],
                ['account' => $counter, 'debit' => $in ? 0 : $amount, 'credit' => $in ? $amount : 0, 'memo' => $note],
            ]);

            AuditLogger::log('cash_session.movement', $session, null, ['entry' => $entry->entry_no, 'direction' => $direction, 'amount' => $amount, 'account' => $counter->label()]);

            return $entry;
        });
    }

    /**
     * Gona joma (close). Open hole bondho hoy (ar lenden na); recount hole notun gona. Expected ledger theke tokhon-i.
     * $count: denominations [note => sonkhya] dile tar jogfol, na hole counted_balance.
     */
    public function close(CashSession $session, User $user, array $count, ?string $note, int $version): CashSession
    {
        return DB::transaction(function () use ($session, $user, $count, $note, $version) {
            $session = CashSession::lockForUpdate()->findOrFail($session->id);
            if ((int) $session->user_id !== (int) $user->id) {
                throw new \Exception('Only the cashier of this session can count and close it.');
            }
            if (!in_array($session->status, [CashSession::OPEN, CashSession::RECOUNT], true)) {
                throw new \Exception("Session {$session->session_no} is {$session->statusLabel()} and cannot be counted again.");
            }
            $this->assertVersion($session, $version);

            [$counted, $denominations] = $this->counted($count);
            $expected = $this->expected($session);
            $from = $session->status;

            $session->update([
                'status'           => CashSession::CLOSED,
                'expected_balance' => $expected,
                'counted_balance'  => $counted,
                'difference'       => round($counted - $expected, 2),
                'denominations'    => $denominations,
                'closing_note'     => $note,
                'closed_at'        => now(),
                'version'          => $session->version + 1,
            ]);

            AuditLogger::log('cash_session.closed', $session, ['status' => $from],
                $session->only(['status', 'expected_balance', 'counted_balance', 'difference', 'closing_note']));

            return $session;
        });
    }

    // Manager: gona thik - parthokko post, session shesh
    public function approve(CashSession $session, User $reviewer, ?string $note, int $version): CashSession
    {
        return DB::transaction(function () use ($session, $reviewer, $note, $version) {
            $session = $this->lockForReview($session, $reviewer, [CashSession::CLOSED]);
            $this->assertVersion($session, $version);
            $difference = round((float) $session->difference, 2);

            if ($difference != 0 && trim((string) ($note ?? '')) === '') {
                throw new \Exception('Write a note explaining the ' . ($difference < 0 ? 'shortage' : 'overage') . ' of ' . number_format(abs($difference), 2) . '.');
            }

            $entry = $difference == 0 ? null : $this->postDifference($session, $difference, 'cash_count_difference', $session->business_date->toDateString(),
                ($difference < 0 ? 'Cash shortage' : 'Cash overage') . " at closing {$session->session_no}" . ($note ? ": {$note}" : ''));

            $session->update([
                'status'              => CashSession::APPROVED,
                'reviewed_by'         => $reviewer->id,
                'reviewed_at'         => now(),
                'review_note'         => $note,
                'difference_entry_id' => $entry?->id,
                'version'             => $session->version + 1,
            ]);

            AuditLogger::log('cash_session.approved', $session, ['status' => CashSession::CLOSED],
                ['status' => CashSession::APPROVED, 'difference' => $difference, 'entry' => $entry?->entry_no, 'note' => $note]);

            return $session;
        });
    }

    // Manager: abar gunte bolo (lenden na)
    public function recount(CashSession $session, User $reviewer, string $note): CashSession
    {
        return $this->reviewStatus($session, $reviewer, [CashSession::CLOSED], CashSession::RECOUNT, $note, 'cash_session.recount');
    }

    // Manager: ajker session abar open (bondho korar pore lenden lagle)
    public function reopen(CashSession $session, User $reviewer, string $note): CashSession
    {
        if ($session->business_date->toDateString() !== now()->toDateString()) {
            throw new \Exception('Only today\'s session can be reopened. Correct an earlier day with an adjustment after approval.');
        }

        try {
            return $this->reviewStatus($session, $reviewer, [CashSession::CLOSED, CashSession::RECOUNT], CashSession::OPEN, $note, 'cash_session.reopened');
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'cash_sessions_one_open_unique')) {
                throw new \Exception('The cashier already has another open session.');
            }
            throw $e;
        }
    }

    public function requestAdjustment(CashSession $session, User $user, float $amount, string $reason): CashSessionAdjustment
    {
        return DB::transaction(function () use ($session, $user, $amount, $reason) {
            $session = CashSession::lockForUpdate()->findOrFail($session->id);
            if ($session->status !== CashSession::APPROVED) {
                throw new \Exception('Adjustments are only for approved closings. Ask for a recount before approval instead.');
            }
            $amount = round($amount, 2);
            if ($amount == 0 || trim($reason) === '') {
                throw new \Exception('Enter a non-zero amount and the reason for the adjustment.');
            }
            if (CashSessionAdjustment::where('cash_session_id', $session->id)->where('status', CashSessionAdjustment::PENDING)->exists()) {
                throw new \Exception("Session {$session->session_no} already has a pending adjustment.");
            }

            $adjustment = CashSessionAdjustment::create([
                'cash_session_id' => $session->id, 'amount' => $amount, 'reason' => $reason,
                'status' => CashSessionAdjustment::PENDING, 'requested_by' => $user->id,
            ]);
            AuditLogger::log('cash_session.adjustment_requested', $adjustment, null, $adjustment->only(['cash_session_id', 'amount', 'reason']));

            return $adjustment;
        });
    }

    public function reviewAdjustment(CashSessionAdjustment $adjustment, User $reviewer, bool $approve, ?string $note): CashSessionAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reviewer, $approve, $note) {
            $adjustment = CashSessionAdjustment::lockForUpdate()->findOrFail($adjustment->id);
            $session = CashSession::lockForUpdate()->findOrFail($adjustment->cash_session_id);
            $this->assertReviewer($session, $reviewer);
            if ($adjustment->status !== CashSessionAdjustment::PENDING) {
                throw new \Exception('This adjustment is already ' . $adjustment->status . '.');
            }
            if ((int) $adjustment->requested_by === (int) $reviewer->id && !$reviewer->isAdmin()) {
                throw new \Exception('You cannot approve or reject your own adjustment request.');
            }

            // ajker tarikhe (approve kora diner hishab bodlay na)
            $entry = $approve ? $this->postDifference($session, (float) $adjustment->amount, 'cash_count_adjustment', now()->toDateString(),
                "Closing adjustment for {$session->session_no}: {$adjustment->reason}", 'CashSessionAdjustment:' . $adjustment->id) : null;

            $adjustment->update([
                'status'           => $approve ? CashSessionAdjustment::APPROVED : CashSessionAdjustment::REJECTED,
                'reviewed_by'      => $reviewer->id,
                'reviewed_at'      => now(),
                'review_note'      => $note,
                'journal_entry_id' => $entry?->id,
            ]);
            AuditLogger::log('cash_session.adjustment_' . $adjustment->status, $adjustment, ['status' => CashSessionAdjustment::PENDING],
                ['status' => $adjustment->status, 'entry' => $entry?->entry_no, 'note' => $note]);

            return $adjustment;
        });
    }

    // ---- hishab ------------------------------------------------------------------------------

    // opening + ei session er cash line (gonar parthokko bad)
    public function expected(CashSession $session): float
    {
        return round((float) $session->opening_balance + $this->cashLines($session)->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 2);
    }

    /**
     * Report: kon dhoroner taka koto (cash sale, collection, expense, refund ...), protita entry, ar bank/mobile (alada).
     */
    public function summary(CashSession $session): array
    {
        $lines = $this->cashLines($session)
            ->select('journal_lines.debit', 'journal_lines.credit', 'e.id as entry_id', 'e.entry_no', 'e.event', 'e.source_type', 'e.source_id',
                'e.reference_no', 'e.narration', 'e.created_at')
            ->orderBy('e.id')->get();

        $saleTypes = SalePayment::whereIn('id', $lines->where('source_type', 'SalePayment')->pluck('source_id'))->pluck('type', 'id');
        $rows = [];
        foreach ($lines as $line) {
            $line->category = $this->category($line, $saleTypes);
            $row = &$rows[$line->category];
            $row ??= ['category' => $line->category, 'in' => 0.0, 'out' => 0.0, 'count' => 0];
            $row['in'] = round($row['in'] + (float) $line->debit, 2);
            $row['out'] = round($row['out'] + (float) $line->credit, 2);
            $row['count']++;
            unset($row);
        }
        $order = array_flip(['Cash sales', 'Collections', 'Other income', 'Cash in / out', 'Refunds', 'Expenses', 'Supplier payments', 'Supplier refunds', 'Transfers', 'Reversals', 'Other']);
        uksort($rows, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        $in = round(array_sum(array_column($rows, 'in')), 2);
        $out = round(array_sum(array_column($rows, 'out')), 2);
        $expected = round((float) $session->opening_balance + $in - $out, 2);

        $adjustments = round((float) $session->adjustments()->where('status', CashSessionAdjustment::APPROVED)->sum('amount'), 2);

        return [
            'rows'        => array_values($rows),
            'lines'       => $lines,
            'cash_in'     => $in,
            'cash_out'    => $out,
            'expected'    => $session->isOpen() ? $expected : (float) ($session->expected_balance ?? $expected),
            'live'        => $expected,
            'non_cash'    => $this->nonCash($session),
            'adjustments' => $adjustments,
        ];
    }

    // Cashier er session er somoy bank/mobile e neya/deya - drawer e nei, tai expected e na
    public function nonCash(CashSession $session)
    {
        return JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->whereIn('a.subtype', ['bank', 'mobile_banking'])
            ->where('e.created_by', $session->user_id)
            ->where('e.created_at', '>=', $session->opened_at)
            ->when($session->closed_at && !$session->isOpen(), fn ($q) => $q->where('e.created_at', '<=', $session->closed_at))
            ->groupBy('a.id', 'a.name')
            ->selectRaw('a.name, SUM(journal_lines.debit) as received, SUM(journal_lines.credit) as paid')
            ->get();
    }

    private function cashLines(CashSession $session)
    {
        return JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->where('e.cash_session_id', $session->id)
            ->where('a.subtype', 'cash')
            ->whereNotIn('e.event', self::COUNT_EVENTS);
    }

    private function category(object $line, $saleTypes): string
    {
        return match ($line->event) {
            'customer_receipt' => ($saleTypes[$line->source_id] ?? null) === SalePayment::TYPE_SALE ? 'Cash sales' : 'Collections',
            'customer_refund'  => 'Refunds',
            'expense'          => 'Expenses',
            'supplier_payment' => 'Supplier payments',
            'supplier_refund'  => 'Supplier refunds',
            'income'           => 'Other income',
            'transfer'         => 'Transfers',
            'cash_movement'    => 'Cash in / out',
            'reversal'         => 'Reversals',
            default            => 'Other',
        };
    }

    // [mot, denominations] - note gula dile tar jogfol, na hole sorasori taka
    private function counted(array $count): array
    {
        $notes = array_filter((array) ($count['denominations'] ?? []), fn ($n) => $n !== null && $n !== '' && (int) $n !== 0);
        if ($notes) {
            $allowed = config('cash.denominations', []);
            $total = 0;
            foreach ($notes as $note => $pieces) {
                if (!in_array((int) $note, $allowed, true) || (int) $pieces < 0) {
                    throw new \Exception("Invalid count for {$note} taka notes.");
                }
                $total += (int) $note * (int) $pieces;
            }

            return [round($total + (float) ($count['coins'] ?? 0), 2), array_map('intval', $notes) + ['coins' => round((float) ($count['coins'] ?? 0), 2)]];
        }

        if (!isset($count['counted_balance']) || $count['counted_balance'] === '' || (float) $count['counted_balance'] < 0) {
            throw new \Exception('Enter the cash counted in the drawer.');
        }

        return [round((float) $count['counted_balance'], 2), null];
    }

    // Shortage: Dr Cash Over/Short, Cr Cash; overage ulta. Ei session e (expected e dhora hoy na).
    private function postDifference(CashSession $session, float $amount, string $event, string $date, string $narration, ?string $key = null): JournalEntry
    {
        $value = abs(round($amount, 2));
        $over = $amount > 0;

        return $this->accounting->post([
            'date'            => $date,
            'event'           => $event,
            'source'          => $session,
            'posting_key'     => $key,
            'reference_no'    => $session->session_no,
            'narration'       => $narration,
            'branch_id'       => $session->branch_id,
            'cash_session_id' => $session->id,
        ], [
            ['account' => $this->cashAccount($session), 'debit' => $over ? $value : 0, 'credit' => $over ? 0 : $value],
            ['account' => 'cash_over_short', 'debit' => $over ? 0 : $value, 'credit' => $over ? $value : 0],
        ]);
    }

    // Branch er nijer cash account thakle seta, na hole Cash in Hand
    private function cashAccount(CashSession $session): Account
    {
        return Account::active()->where('subtype', 'cash')->where('branch_id', $session->branch_id)->orderBy('id')->first()
            ?? Account::system('cash');
    }

    private function lockOwnOpen(CashSession $session, User $user): CashSession
    {
        $session = CashSession::lockForUpdate()->findOrFail($session->id);
        if ((int) $session->user_id !== (int) $user->id) {
            throw new \Exception('This is not your cash session.');
        }
        if (!$session->isOpen() || $session->business_date->toDateString() !== now()->toDateString()) {
            throw new \Exception("Session {$session->session_no} is not open for today.");
        }

        return $session;
    }

    private function lockForReview(CashSession $session, User $reviewer, array $from): CashSession
    {
        $session = CashSession::lockForUpdate()->findOrFail($session->id);
        $this->assertReviewer($session, $reviewer);
        if (!in_array($session->status, $from, true)) {
            throw new \Exception("Session {$session->session_no} is {$session->statusLabel()}.");
        }

        return $session;
    }

    private function assertReviewer(CashSession $session, User $reviewer): void
    {
        if (!$reviewer->can('approve cash closing')) {
            throw new \Exception('You are not allowed to review cash closings.');
        }
        if (!$reviewer->canAccessBranch($session->branch_id)) {
            throw new \Exception('You can only review cash closings of your own branch.');
        }
        // nijer gona nije approve shudhu admin (ek-maliker dokan); audit log e thake
        if ((int) $session->user_id === (int) $reviewer->id && !$reviewer->isAdmin()) {
            throw new \Exception('You cannot review your own cash closing.');
        }
    }

    private function assertVersion(CashSession $session, int $version): void
    {
        if ((int) $session->version !== $version) {
            throw new \Exception("Session {$session->session_no} was changed by someone else. Reload the page and check the figures again.");
        }
    }

    private function reviewStatus(CashSession $session, User $reviewer, array $from, string $to, string $note, string $action): CashSession
    {
        if (trim($note) === '') {
            throw new \Exception('A note is required.');
        }

        return DB::transaction(function () use ($session, $reviewer, $from, $to, $note, $action) {
            $session = $this->lockForReview($session, $reviewer, $from);
            $old = $session->status;
            $session->update(['status' => $to, 'review_note' => $note, 'version' => $session->version + 1]);
            AuditLogger::log($action, $session, ['status' => $old], ['status' => $to, 'note' => $note, 'by' => $reviewer->id]);

            return $session;
        });
    }
}
