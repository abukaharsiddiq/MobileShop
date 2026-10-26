<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\JournalLine;
use Illuminate\Support\Collection;

/**
 * Cash/bank reconciliation: bank statement (ba gona nogod) er balance er sathe boi er line mela.
 *  - statement e asha line gulo "cleared" kora hoy (reconciliation_id + cleared_date)
 *  - cleared balance = ager shob reconciliation e mela line + ei bar mela line
 *  - difference = statement balance - cleared balance; 0 holei complete kora jay, tarpor line gulo atkano
 * DB transaction er bhitore dakte hobe.
 */
class BankReconciliationService
{
    public function start(Account $account, string $statementDate, float $statementBalance, ?string $note = null): BankReconciliation
    {
        if (!$account->isMoney()) {
            throw new \Exception('Only cash, bank or mobile banking accounts can be reconciled.');
        }
        if (BankReconciliation::where('account_id', $account->id)->where('status', BankReconciliation::DRAFT)->exists()) {
            throw new \Exception("{$account->name} already has an unfinished reconciliation. Complete it first.");
        }
        $last = BankReconciliation::where('account_id', $account->id)->where('status', BankReconciliation::COMPLETED)->max('statement_date');
        if ($last && $statementDate <= \Carbon\Carbon::parse($last)->toDateString()) {
            throw new \Exception("{$account->name} is already reconciled up to {$last}. Use a later statement date.");
        }

        $reconciliation = BankReconciliation::create([
            'account_id'        => $account->id,
            'statement_date'    => $statementDate,
            'statement_balance' => round($statementBalance, 2),
            'status'            => BankReconciliation::DRAFT,
            'note'              => $note,
            'created_by'        => auth()->id(),
        ]);

        return $this->refresh($reconciliation);
    }

    /**
     * Statement e je line gulo ache (cleared) seta set kora; baki gulo ei reconciliation theke sore jay.
     */
    public function setCleared(BankReconciliation $reconciliation, array $lineIds): BankReconciliation
    {
        $reconciliation = BankReconciliation::lockForUpdate()->findOrFail($reconciliation->id);
        $this->assertDraft($reconciliation);

        $lineIds = array_map('intval', $lineIds);
        $allowed = $this->openLines($reconciliation)->pluck('id')->all();
        $invalid = array_diff($lineIds, $allowed);
        if ($invalid) {
            throw new \Exception('Some selected lines do not belong to this account, are after the statement date or are already reconciled.');
        }

        JournalLine::where('reconciliation_id', $reconciliation->id)->whereNotIn('id', $lineIds ?: [0])
            ->update(['reconciliation_id' => null, 'cleared_date' => null]);
        JournalLine::whereIn('id', $lineIds ?: [0])->whereNull('reconciliation_id')
            ->update(['reconciliation_id' => $reconciliation->id, 'cleared_date' => $reconciliation->statement_date]);

        return $this->refresh($reconciliation);
    }

    public function complete(BankReconciliation $reconciliation): BankReconciliation
    {
        $reconciliation = $this->refresh(BankReconciliation::lockForUpdate()->findOrFail($reconciliation->id));
        $this->assertDraft($reconciliation);

        if (round((float) $reconciliation->difference, 2) != 0) {
            throw new \Exception('Statement balance and cleared balance differ by ' . number_format($reconciliation->difference, 2)
                . '. Clear the matching lines, or record the missing bank charge/interest first.');
        }

        $reconciliation->update(['status' => BankReconciliation::COMPLETED, 'completed_at' => now()]);
        AuditLogger::log('finance.reconciled', $reconciliation, null, $reconciliation->only([
            'account_id', 'statement_date', 'statement_balance', 'cleared_balance', 'book_balance',
        ]));

        return $reconciliation;
    }

    public function discard(BankReconciliation $reconciliation): void
    {
        $reconciliation = BankReconciliation::lockForUpdate()->findOrFail($reconciliation->id);
        $this->assertDraft($reconciliation);

        JournalLine::where('reconciliation_id', $reconciliation->id)->update(['reconciliation_id' => null, 'cleared_date' => null]);
        $reconciliation->delete();
    }

    // Statement tarikh porjonto ekhono mela hoyni (ba ei draft e mela) emon line
    public function openLines(BankReconciliation $reconciliation): Collection
    {
        return JournalLine::with('entry')
            ->select('journal_lines.*')
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $reconciliation->account_id)
            ->where('e.entry_date', '<=', $reconciliation->statement_date)
            ->where(fn ($q) => $q->whereNull('journal_lines.reconciliation_id')->orWhere('journal_lines.reconciliation_id', $reconciliation->id))
            ->orderBy('e.entry_date')->orderBy('e.id')
            ->get();
    }

    private function refresh(BankReconciliation $reconciliation): BankReconciliation
    {
        $account = Account::findOrFail($reconciliation->account_id);
        $date = $reconciliation->statement_date->toDateString();

        $cleared = (float) JournalLine::where('account_id', $account->id)
            ->whereNotNull('reconciliation_id')
            ->whereIn('reconciliation_id', BankReconciliation::where('account_id', $account->id)
                ->where(fn ($q) => $q->where('status', BankReconciliation::COMPLETED)->orWhere('id', $reconciliation->id))
                ->select('id'))
            ->sum(\DB::raw('debit - credit'));

        $reconciliation->update([
            'cleared_balance' => round($cleared, 2),
            'book_balance'    => $account->balance($date),
            'difference'      => round($reconciliation->statement_balance - $cleared, 2),
        ]);

        return $reconciliation;
    }

    private function assertDraft(BankReconciliation $reconciliation): void
    {
        if ($reconciliation->status !== BankReconciliation::DRAFT) {
            throw new \Exception('This reconciliation is already completed and cannot be changed.');
        }
    }
}
