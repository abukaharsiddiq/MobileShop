<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\FinancialYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Double-entry journal. Shob financial entry ekhan diye server e toiri hoy:
 *  - protiti entry balanced (mot debit = mot credit), amount > 0, account active
 *  - source document (source_type/source_id) ar reference_no thake
 *  - ekoi posting_key ekbar-i live thake (database unique) - duplicate posting hoy na
 *  - closed financial year e kono entry/reverse hoy na
 *  - posted entry muchha/bodlano jay na: reverse() ulta entry diye batil kore
 *  - receivable/payable line e customer/supplier (party) thake; customer/supplier ledger (subledger) age moto-i
 *    LedgerService likhe, ar FinanceAuditService party onujayi duita milay
 *
 * DB transaction er bhitore dakte hobe.
 */
class AccountingService
{
    /**
     * $entry: date, event, source (Model) ba source_type/source_id, reference_no, narration, posting_key
     * $lines: [['account' => Account|system_key|id, 'debit' => x, 'credit' => y, 'party_type', 'party_id', 'memo']]
     */
    public function post(array $entry, array $lines): JournalEntry
    {
        $date = $this->date($entry['date'] ?? null);
        $this->assertOpenPeriod($date);

        $lines = $this->normalize($lines);
        $total = round(array_sum(array_column($lines, 'debit')), 2);

        $source = $entry['source'] ?? null;
        $sourceType = $source ? class_basename($source) : ($entry['source_type'] ?? null);
        $sourceId = $source ? $source->getKey() : ($entry['source_id'] ?? null);

        $postingKey = $entry['posting_key']
            ?? ($sourceType && $sourceId ? "{$sourceType}:{$sourceId}:{$entry['event']}" : 'manual:' . Str::uuid());

        if (JournalEntry::where('live_key', $postingKey)->exists()) {
            throw new \Exception("This transaction is already posted ({$postingKey}). Reverse it first if it must be posted again.");
        }

        try {
            $journal = JournalEntry::create([
                'entry_no'       => 'TMP-' . Str::random(20),
                'entry_date'     => $date,
                'event'          => $entry['event'],
                'source_type'    => $sourceType,
                'source_id'      => $sourceId,
                'reference_no'   => $entry['reference_no'] ?? ($source ? StockService::referenceNo($source) : null),
                'narration'      => $entry['narration'] ?? null,
                'amount'         => $total,
                'status'         => JournalEntry::POSTED,
                'posting_key'    => $postingKey,
                'reversal_of_id' => $entry['reversal_of_id'] ?? null,
                'branch_id'      => $entry['branch_id'] ?? Branch::currentId(),
                'created_by'     => auth()->id(),
            ]);
        } catch (QueryException $e) {
            // duita request ekshathe ekoi jinish post korle database er unique index dhore
            if (str_contains($e->getMessage(), 'journal_entries_live_key_unique')) {
                throw new \Exception("This transaction is already posted ({$postingKey}).");
            }
            throw $e;
        }

        $journal->update(['entry_no' => 'JV-' . str_pad($journal->id, 6, '0', STR_PAD_LEFT)]);

        foreach ($lines as $line) {
            JournalLine::create($line + ['journal_entry_id' => $journal->id]);
        }

        return $journal;
    }

    /**
     * Posted entry ke ulta entry diye batil kora (original muchhe na). Ekbar-i reverse hoy.
     */
    public function reverse(JournalEntry $journal, string $reason, ?string $date = null): JournalEntry
    {
        $journal = JournalEntry::with('lines')->lockForUpdate()->findOrFail($journal->id);
        $reason = trim($reason);

        if ($reason === '') {
            throw new \Exception('A reason is required to reverse a transaction.');
        }
        if ($journal->reversal_of_id) {
            throw new \Exception("{$journal->entry_no} is itself a reversal and cannot be reversed.");
        }
        if ($journal->status !== JournalEntry::POSTED) {
            throw new \Exception("{$journal->entry_no} is already reversed.");
        }

        $date = $this->date($date);
        if ($date < $journal->entry_date->toDateString()) {
            throw new \Exception('A reversal cannot be dated before the original entry (' . $journal->entry_date->toDateString() . ').');
        }

        $reversal = $this->post([
            'date'           => $date,
            'event'          => 'reversal',
            'source_type'    => $journal->source_type,
            'source_id'      => $journal->source_id,
            'reference_no'   => $journal->reference_no,
            'narration'      => "Reversal of {$journal->entry_no}: {$reason}",
            'posting_key'    => 'reversal:' . $journal->id,
            'reversal_of_id' => $journal->id,
            'branch_id'      => $journal->branch_id,
        ], $journal->lines->map(fn (JournalLine $line) => [
            'account'    => $line->account_id,
            'debit'      => $line->credit,
            'credit'     => $line->debit,
            'party_type' => $line->party_type,
            'party_id'   => $line->party_id,
            'memo'       => $line->memo,
        ])->all());

        $journal->update(['status' => JournalEntry::REVERSED, 'reversed_by_id' => $reversal->id]);

        AuditLogger::log('finance.reversed', $journal, ['status' => JournalEntry::POSTED],
            ['status' => JournalEntry::REVERSED, 'reversal' => $reversal->entry_no, 'reason' => $reason]);

        return $reversal;
    }

    // Ekta document er live (reverse na kora) entry
    public function liveEntry(string $sourceType, int $sourceId, string $event): ?JournalEntry
    {
        return JournalEntry::where('live_key', "{$sourceType}:{$sourceId}:{$event}")->first();
    }

    // Form er token (request_token) age save hoye thakle - ekoi form duibar submit
    public static function assertNewToken(?string $token, string $model): void
    {
        if ($token && $model::where('request_token', $token)->exists()) {
            throw new \Exception('This entry was already saved (the form was submitted twice).');
        }
    }

    public function assertOpenPeriod(string $date): void
    {
        $year = FinancialYear::forDate($date);
        if ($year && $year->is_closed) {
            throw new \Exception("Financial year {$year->name} is closed. Nothing can be posted on {$date}.");
        }
    }

    private function date(?string $date): string
    {
        return $date ? \Carbon\Carbon::parse($date)->toDateString() : now()->toDateString();
    }

    private function normalize(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit < 0 || $credit < 0) {
                throw new \Exception('Debit and credit amounts cannot be negative.');
            }
            if ($debit > 0 && $credit > 0) {
                throw new \Exception('A journal line must be either a debit or a credit, not both.');
            }
            if ($debit == 0 && $credit == 0) {
                continue;
            }

            $account = $this->account($line['account']);
            if (!$account->status) {
                throw new \Exception("Account {$account->label()} is inactive.");
            }

            $partyType = $line['party_type'] ?? null;
            if ($account->subtype === 'receivable' && ($partyType !== 'customer' || empty($line['party_id']))) {
                throw new \Exception('Accounts receivable lines need a customer.');
            }
            if ($account->subtype === 'payable' && ($partyType !== 'supplier' || empty($line['party_id']))) {
                throw new \Exception('Accounts payable lines need a supplier.');
            }

            $rows[] = [
                'account_id' => $account->id,
                'debit'      => $debit,
                'credit'     => $credit,
                'party_type' => $partyType,
                'party_id'   => $line['party_id'] ?? null,
                'memo'       => $line['memo'] ?? null,
            ];
        }

        $debits = round(array_sum(array_column($rows, 'debit')), 2);
        $credits = round(array_sum(array_column($rows, 'credit')), 2);

        if (count($rows) < 2 || $debits <= 0) {
            throw new \Exception('A journal entry needs at least one debit and one credit with an amount.');
        }
        if ($debits !== $credits) {
            throw new \Exception('Journal entry is not balanced: debit ' . number_format($debits, 2) . ', credit ' . number_format($credits, 2) . '.');
        }

        return $rows;
    }

    private function account(Account|string|int $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }

        return is_int($account) || ctype_digit((string) $account)
            ? Account::findOrFail((int) $account)
            : Account::system($account);
    }
}
