<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CustomerLedger;
use App\Models\JournalLine;
use App\Models\MainStock;
use App\Models\SupplierLedger;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: journal ar subledger/document er moddhe ki mil nei. Kono data bodlay na.
 * Format InventoryAuditService er moto: [severity, title, rows].
 */
class FinanceAuditService
{
    public function run(): array
    {
        return [
            $this->unbalancedEntries(),
            $this->partyMismatch('customer', 'receivable', CustomerLedger::class, 'customer_id', 'debit - credit'),
            $this->partyMismatch('supplier', 'payable', SupplierLedger::class, 'supplier_id', 'credit - debit'),
            $this->unposted(),
            $this->inventoryDifference(),
        ];
    }

    private function unbalancedEntries(): array
    {
        $rows = DB::table('journal_entries as e')
            ->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->groupBy('e.id', 'e.entry_no', 'e.amount')
            ->havingRaw('ROUND(SUM(l.debit), 2) <> ROUND(SUM(l.credit), 2) OR ROUND(SUM(l.debit), 2) <> ROUND(e.amount, 2)')
            ->selectRaw('e.entry_no, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get()
            ->map(fn ($r) => "{$r->entry_no}: debit {$r->debit}, credit {$r->credit}")
            ->all();

        return ['severity' => 'error', 'title' => 'Journal entries that do not balance', 'rows' => $rows];
    }

    // Control account (party onujayi) ar customer/supplier ledger er jogfol
    private function partyMismatch(string $party, string $subtype, string $ledgerModel, string $column, string $expression): array
    {
        $sign = $party === 'customer' ? 1 : -1;
        $control = JournalLine::query()
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->where('a.subtype', $subtype)
            ->where('journal_lines.party_type', $party)
            ->groupBy('journal_lines.party_id')
            ->selectRaw('journal_lines.party_id, SUM(journal_lines.debit - journal_lines.credit) as net')
            ->pluck('net', 'party_id');

        $ledger = $ledgerModel::groupBy($column)
            ->selectRaw("{$column} as party_id, SUM({$expression}) as net")
            ->pluck('net', 'party_id');

        $rows = [];
        foreach ($control->keys()->merge($ledger->keys())->unique() as $id) {
            $gl = round($sign * (float) ($control[$id] ?? 0), 2);
            $sub = round((float) ($ledger[$id] ?? 0), 2);
            if ($gl !== $sub) {
                $rows[] = ucfirst($party) . " #{$id}: {$subtype} account {$gl}, ledger {$sub}";
            }
        }

        return ['severity' => 'error', 'title' => ucfirst($party) . " ledger different from the {$subtype} account (run finance:backfill for old data)", 'rows' => $rows];
    }

    // Journal e na otha document (upgrade er ager data - finance:backfill)
    private function unposted(): array
    {
        $missing = function (string $table, string $type, array $events, string $label, string $no, ?callable $filter = null) {
            return DB::table($table)
                ->when($filter, $filter)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries as e')
                    ->where('e.source_type', $type)->whereColumn('e.source_id', "{$table}.id")->whereIn('e.event', $events))
                ->pluck($no)->map(fn ($n) => "{$label} {$n}");
        };

        $rows = $missing('sales', 'Sale', ['sale'], 'Sale', 'invoice_no', fn ($q) => $q->where('status', 'completed'))
            ->merge($missing('purchases', 'Purchase', ['purchase'], 'Purchase', 'invoice_no', fn ($q) => $q->where('status', 'approved')))
            ->merge($missing('sale_payments', 'SalePayment', ['customer_receipt', 'customer_refund'], 'Sale payment', 'id', fn ($q) => $q->where('channel', '!=', 'credit')))
            ->merge($missing('supplier_payments', 'SupplierPayment', ['supplier_payment', 'supplier_refund'], 'Supplier payment', 'payment_no'))
            ->merge($missing('sale_returns', 'SaleReturn', ['sale_return'], 'Sale return', 'return_no', fn ($q) => $q->where('status', 'completed')))
            ->merge($missing('purchase_returns', 'PurchaseReturn', ['purchase_return'], 'Purchase return', 'return_no'))
            ->merge($missing('expenses', 'Expense', ['expense'], 'Expense', 'id', fn ($q) => $q->where('status', 'posted')))
            ->merge($missing('used_phones', 'UsedPhone', ['used_phone_purchase'], 'Used phone', 'intake_no', fn ($q) => $q->whereIn('status', ['refurbishing', 'in_stock'])))
            ->merge($missing('used_phone_events', 'UsedPhoneEvent', ['used_phone_refurbishment'], 'Refurbishment cost', 'id', fn ($q) => $q->where('event', 'cost')))
            ->merge($missing('repair_payments', 'RepairPayment', ['repair_receipt', 'repair_refund'], 'Repair payment', 'id'))
            ->merge($missing('repair_jobs', 'RepairJob', ['repair_invoice'], 'Repair invoice', 'job_no', fn ($q) => $q->where('total', '>', 0)))
            ->merge($missing('repair_job_parts', 'RepairJobPart', ['repair_part'], 'Repair part', 'id', fn ($q) => $q->whereIn('status', ['approved', 'returned'])->where('unit_cost', '>', 0)))
            ->all();

        return ['severity' => 'warning', 'title' => 'Documents not posted to the journal (run finance:backfill)', 'rows' => $rows];
    }

    // Gor kena dam er rounding/sale return er dam er jonno ektu parthokko thakte pare - shudhu jananor jonno
    private function inventoryDifference(): array
    {
        $book = Account::system('inventory')->balance();
        $stock = round((float) MainStock::sum(DB::raw('quantity * average_purchase_price')), 2);
        $diff = round($book - $stock, 2);

        return ['severity' => 'info', 'title' => 'Inventory account vs stock valuation',
            'rows' => $diff != 0 ? ["Inventory account {$book}, stock at average cost {$stock}, difference {$diff}"] : []];
    }
}
