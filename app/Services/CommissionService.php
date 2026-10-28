<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\CommissionStatement;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SalesTarget;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sales commission - shob hishab server e, salesperson er completed sale theke.
 *
 * - Line: mul bikri (return er age) er net (dam - line discount) er upor shobcheye nirdishto niyom
 *   (product > category > type, employee > role > shobai; tarikh e chalu). Invoice niyom (per_sale) invoice e ekbar.
 * - Return (config commission.reverse_on_return): ferot qty er anupate ulto entry; puro invoice ferot hole invoice niyom o.
 * - Cancel: oi sale er baki commission ulto. Ulto entry jei statement e pore (ager ta paid holeo) sekhane kete jay.
 * - Target: mash shesh hole oi maser net bikri target chhuile bonus (ekbar).
 * - Entry gulor uniq_key unique - bar bar hishab korleo ekoi jinish duibar na.
 *
 * Statement: draft (abar hishab) -> approved (Dr Commission Expense, Cr Commission Payable) -> paid (Dr Payable, Cr Cash/Bank).
 */
class CommissionService
{
    public function __construct(private AccountingService $accounting) {}

    // ---- hishab ---------------------------------------------------------------------------------

    // [$from, $to] er sale, ar $to porjonto return/cancel/target. Fere notun entry koyta.
    public function calculate(User $staff, string $from, string $to): int
    {
        $created = 0;
        $rules = $this->rulesFor($staff);

        $sales = Sale::with('saleDetails.product')
            ->where('status', Sale::STATUS_COMPLETED)
            ->whereBetween('sale_date', [$from, $to])
            ->where(fn ($q) => $q->where('salesperson_id', $staff->id)->orWhere(fn ($w) => $w->whereNull('salesperson_id')->where('created_by', $staff->id)))
            ->orderBy('id')->get();

        foreach ($sales as $sale) {
            $date = Carbon::parse($sale->sale_date)->toDateString();
            foreach ($sale->saleDetails as $detail) {
                $created += $this->earnLine($staff, $sale, $detail, $rules, $date) ? 1 : 0;
            }
            $created += $this->earnInvoice($staff, $sale, $rules, $date) ? 1 : 0;
        }

        if (config('commission.reverse_on_return', true)) {
            $created += $this->reverseReturns($staff, $to);
        }
        $created += $this->reverseCancelled($staff, $to);
        $created += $this->targetBonuses($staff, $to);

        return $created;
    }

    // Ei employee er (bikri) net bikri: completed sale er line (return er por) jogfol
    public function netSales(User $staff, string $from, string $to): float
    {
        return round((float) SaleDetail::join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->whereBetween('sales.sale_date', [$from, $to])
            ->where(fn ($q) => $q->where('sales.salesperson_id', $staff->id)->orWhere(fn ($w) => $w->whereNull('sales.salesperson_id')->where('sales.created_by', $staff->id)))
            ->sum('sale_details.unit_total'), 2);
    }

    // ---- statement ------------------------------------------------------------------------------

    public function generate(User $staff, string $from, string $to, User $by, ?string $note = null): CommissionStatement
    {
        return DB::transaction(function () use ($staff, $from, $to, $by, $note) {
            // ekoi employee er duita statement ekshathe na
            User::whereKey($staff->id)->lockForUpdate()->first();
            [$from, $to] = $this->period($from, $to);

            $overlap = CommissionStatement::where('user_id', $staff->id)->where('status', '!=', CommissionStatement::CANCELLED)
                ->where('period_start', '<=', $to)->where('period_end', '>=', $from)->value('statement_no');
            if ($overlap) {
                throw new \Exception("{$staff->name} already has statement {$overlap} for this period.");
            }

            $this->calculate($staff, $from, $to);

            $statement = CommissionStatement::create([
                'statement_no' => 'TMP-' . Str::random(20),
                'user_id'      => $staff->id,
                'branch_id'    => $staff->branch_id,
                'period_start' => $from,
                'period_end'   => $to,
                'status'       => CommissionStatement::DRAFT,
                'note'         => $note,
                'created_by'   => $by->id,
            ]);
            $statement->update(['statement_no' => 'CS-' . str_pad((string) $statement->id, 6, '0', STR_PAD_LEFT)]);
            $this->attach($statement);
            AuditLogger::log('commission.statement_created', $statement, null, $statement->only(['statement_no', 'user_id', 'period_start', 'period_end', 'net']));

            return $statement->refresh();
        });
    }

    // Draft e notun sale/return er hishab jog
    public function refresh(CommissionStatement $statement): CommissionStatement
    {
        return DB::transaction(function () use ($statement) {
            $statement = $this->lock($statement, [CommissionStatement::DRAFT]);
            $staff = User::findOrFail($statement->user_id);
            $this->calculate($staff, $statement->period_start->toDateString(), $statement->period_end->toDateString());
            $this->attach($statement);

            return $statement->refresh();
        });
    }

    public function cancel(CommissionStatement $statement, User $by, string $reason): CommissionStatement
    {
        return DB::transaction(function () use ($statement, $by, $reason) {
            $statement = $this->lock($statement, [CommissionStatement::DRAFT]);
            // entry gulo chhere deya (porer statement e jabe)
            CommissionEntry::where('commission_statement_id', $statement->id)->get()->each->update(['commission_statement_id' => null]);
            $statement->update(['status' => CommissionStatement::CANCELLED, 'cancel_reason' => $reason]);
            AuditLogger::log('commission.statement_cancelled', $statement, ['status' => CommissionStatement::DRAFT], ['status' => CommissionStatement::CANCELLED, 'reason' => $reason, 'by' => $by->id]);

            return $statement;
        });
    }

    // Approve: nijer statement nije na (admin chara); net > 0; Dr Commission Expense, Cr Commission Payable
    public function approve(CommissionStatement $statement, User $by): CommissionStatement
    {
        return DB::transaction(function () use ($statement, $by) {
            $statement = $this->lock($statement, [CommissionStatement::DRAFT]);
            if ((int) $statement->user_id === (int) $by->id && !$by->isAdmin()) {
                throw new \Exception('You cannot approve your own commission.');
            }
            $this->totals($statement);
            $net = round((float) $statement->net, 2);
            if ($net <= 0) {
                throw new \Exception('Net commission is ' . number_format($net, 2) . '; there is nothing to pay. Cancel the draft so the entries carry to the next statement.');
            }

            $staff = User::findOrFail($statement->user_id);
            $this->accounting->post([
                'date'         => now()->toDateString(),
                'event'        => 'commission_accrual',
                'source'       => $statement,
                'reference_no' => $statement->statement_no,
                'narration'    => "Sales commission {$statement->statement_no} - {$staff->name} ({$statement->period_start->format('d-m-Y')} to {$statement->period_end->format('d-m-Y')})",
                'branch_id'    => $statement->branch_id,
            ], [
                ['account' => 'commission_expense', 'debit' => $net],
                ['account' => 'commission_payable', 'credit' => $net, 'memo' => $staff->name],
            ]);

            $statement->update(['status' => CommissionStatement::APPROVED, 'approved_by' => $by->id, 'approved_at' => now()]);
            AuditLogger::log('commission.statement_approved', $statement, ['status' => CommissionStatement::DRAFT], ['status' => CommissionStatement::APPROVED, 'net' => $net]);

            return $statement;
        });
    }

    // Pay: Dr Commission Payable, Cr Cash/Bank (cash hole cashier er session)
    public function pay(CommissionStatement $statement, User $by, string $method = 'Cash', ?int $accountId = null): CommissionStatement
    {
        return DB::transaction(function () use ($statement, $by, $method, $accountId) {
            $statement = $this->lock($statement, [CommissionStatement::APPROVED]);
            $money = Account::moneyFor($accountId, $method);
            $net = round((float) $statement->net, 2);
            $staff = User::findOrFail($statement->user_id);

            $this->accounting->post([
                'date'         => now()->toDateString(),
                'event'        => 'commission_payment',
                'source'       => $statement,
                'reference_no' => $statement->statement_no,
                'narration'    => "Commission paid to {$staff->name} ({$statement->statement_no}, {$money->name})",
                'branch_id'    => $statement->branch_id,
            ], [
                ['account' => 'commission_payable', 'debit' => $net, 'memo' => $staff->name],
                ['account' => $money, 'credit' => $net],
            ]);

            $statement->update(['status' => CommissionStatement::PAID, 'paid_by' => $by->id, 'paid_at' => now(),
                'payment_method' => $method, 'account_id' => $money->id]);
            AuditLogger::log('commission.statement_paid', $statement, ['status' => CommissionStatement::APPROVED], ['status' => CommissionStatement::PAID, 'net' => $net, 'account' => $money->name]);

            return $statement;
        });
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function rulesFor(User $staff): Collection
    {
        return CommissionRule::where('status', 1)
            ->where(fn ($q) => $q->where('user_id', $staff->id)->orWhereNull('user_id'))
            ->where(fn ($q) => $q->where('role', $staff->role)->orWhereNull('role'))
            ->get();
    }

    // tarikh e chalu, ar line er product er sathe mile
    private function best(Collection $rules, array $bases, string $date, ?SaleDetail $detail = null, ?Collection $details = null): ?CommissionRule
    {
        $matches = fn (CommissionRule $r, SaleDetail $d) => (!$r->product_id || (int) $r->product_id === (int) $d->product_id)
            && (!$r->category_id || (int) $r->category_id === (int) $d->product?->category_id)
            && (!$r->product_type || $r->product_type === $d->product?->type);

        return $rules
            ->filter(fn (CommissionRule $r) => in_array($r->basis, $bases, true)
                && (!$r->effective_from || $r->effective_from->toDateString() <= $date)
                && (!$r->effective_to || $r->effective_to->toDateString() >= $date)
                && ($detail ? $matches($r, $detail) : $details->contains(fn ($d) => $matches($r, $d))))
            ->sortBy([fn ($a, $b) => $b->specificity() <=> $a->specificity(), fn ($a, $b) => $b->id <=> $a->id])
            ->first();
    }

    private function earnLine(User $staff, Sale $sale, SaleDetail $detail, Collection $rules, string $date): bool
    {
        $qty = (int) $detail->quantity + (int) $detail->returned_quantity;   // mul bikri (return alada ulto hoy)
        if ($qty < 1) {
            return false;
        }
        $rule = $this->best($rules, [CommissionRule::PERCENT, CommissionRule::PER_UNIT], $date, $detail);
        if (!$rule) {
            return false;
        }

        $base = round(($qty * (float) $detail->sale_price) - (float) $detail->item_discount, 2);
        $amount = $rule->basis === CommissionRule::PERCENT ? round($base * (float) $rule->rate / 100, 2) : round($qty * (float) $rule->rate, 2);

        return $this->entry("earned:{$detail->id}", [
            'user_id' => $staff->id, 'type' => CommissionEntry::EARNED, 'entry_date' => $date, 'sale_id' => $sale->id, 'sale_detail_id' => $detail->id,
            'commission_rule_id' => $rule->id, 'qty' => $qty, 'base_amount' => $base, 'rate' => $rule->rate, 'amount' => $amount,
            'description' => "{$sale->invoice_no}: {$qty} x " . ($detail->product?->product_name ?? 'item') . " ({$rule->name})",
        ]);
    }

    private function earnInvoice(User $staff, Sale $sale, Collection $rules, string $date): bool
    {
        $rule = $this->best($rules, [CommissionRule::PER_SALE], $date, null, $sale->saleDetails);
        if (!$rule || $sale->saleDetails->sum(fn ($d) => (int) $d->quantity + (int) $d->returned_quantity) < 1) {
            return false;
        }

        return $this->entry("earned_sale:{$sale->id}", [
            'user_id' => $staff->id, 'type' => CommissionEntry::EARNED, 'entry_date' => $date, 'sale_id' => $sale->id,
            'commission_rule_id' => $rule->id, 'qty' => 1, 'base_amount' => $sale->grand_total, 'rate' => $rule->rate, 'amount' => round((float) $rule->rate, 2),
            'description' => "{$sale->invoice_no}: per invoice ({$rule->name})",
        ]);
    }

    // completed return er protiti item: oi line er commission er anupate ulto
    private function reverseReturns(User $staff, string $to): int
    {
        $created = 0;
        $earned = CommissionEntry::where('user_id', $staff->id)->where('type', CommissionEntry::EARNED)->get();
        $byDetail = $earned->whereNotNull('sale_detail_id')->keyBy('sale_detail_id');
        if ($earned->isEmpty()) {
            return 0;
        }

        $items = SaleReturnItem::with('saleReturn')
            ->whereIn('sale_detail_id', $byDetail->keys())
            ->whereHas('saleReturn', fn ($q) => $q->where('status', SaleReturn::STATUS_COMPLETED))
            ->get();

        foreach ($items as $item) {
            $date = Carbon::parse($item->saleReturn->approved_at ?? $item->saleReturn->return_date ?? $item->saleReturn->created_at)->toDateString();
            if ($date > $to) {
                continue;
            }
            $line = $byDetail[$item->sale_detail_id];
            $share = (int) $item->quantity / max(1, (int) $line->qty);
            $created += $this->entry("return:{$item->id}", [
                'user_id' => $staff->id, 'type' => CommissionEntry::RETURN, 'entry_date' => $date, 'sale_id' => $line->sale_id,
                'sale_detail_id' => $line->sale_detail_id, 'sale_return_item_id' => $item->id, 'commission_rule_id' => $line->commission_rule_id,
                'qty' => -(int) $item->quantity, 'base_amount' => -round((float) $line->base_amount * $share, 2), 'rate' => $line->rate,
                'amount' => -round((float) $line->amount * $share, 2), 'description' => "Return {$item->saleReturn->return_no}: {$item->quantity} unit(s)",
            ]) ? 1 : 0;
        }

        // puro invoice ferot: invoice er commission o ulto
        foreach ($earned->whereNull('sale_detail_id') as $invoice) {
            $sale = Sale::with('saleDetails')->find($invoice->sale_id);
            $lastReturn = SaleReturn::where('sale_id', $invoice->sale_id)->where('status', SaleReturn::STATUS_COMPLETED)->latest('id')->first();
            if (!$sale || !$lastReturn || $sale->saleDetails->sum('quantity') > 0) {
                continue;
            }
            $date = Carbon::parse($lastReturn->approved_at ?? $lastReturn->return_date ?? $lastReturn->created_at)->toDateString();
            if ($date <= $to) {
                $created += $this->entry("return_sale:{$sale->id}", [
                    'user_id' => $staff->id, 'type' => CommissionEntry::RETURN, 'entry_date' => $date, 'sale_id' => $sale->id,
                    'commission_rule_id' => $invoice->commission_rule_id, 'qty' => -1, 'base_amount' => -(float) $invoice->base_amount,
                    'rate' => $invoice->rate, 'amount' => -(float) $invoice->amount, 'description' => "{$sale->invoice_no} fully returned",
                ]) ? 1 : 0;
            }
        }

        return $created;
    }

    // batil sale: protiti earned entry er baki (return er por) ulto
    private function reverseCancelled(User $staff, string $to): int
    {
        $created = 0;
        $entries = CommissionEntry::where('user_id', $staff->id)->where('type', CommissionEntry::EARNED)
            ->whereIn('sale_id', Sale::where('status', Sale::STATUS_CANCELLED)->whereDate('cancelled_at', '<=', $to)->select('id'))
            ->get();

        foreach ($entries as $earned) {
            $sale = Sale::find($earned->sale_id);
            $already = (float) CommissionEntry::where('user_id', $staff->id)->where('type', CommissionEntry::RETURN)
                ->where('sale_id', $earned->sale_id)->where('sale_detail_id', $earned->sale_detail_id)->sum('amount');
            $left = round((float) $earned->amount + $already, 2);
            if ($left <= 0) {
                continue;
            }
            $created += $this->entry("cancel:{$earned->id}", [
                'user_id' => $staff->id, 'type' => CommissionEntry::CANCEL, 'entry_date' => Carbon::parse($sale->cancelled_at)->toDateString(),
                'sale_id' => $earned->sale_id, 'sale_detail_id' => $earned->sale_detail_id, 'commission_rule_id' => $earned->commission_rule_id,
                'qty' => -(int) $earned->qty, 'base_amount' => -(float) $earned->base_amount, 'rate' => $earned->rate, 'amount' => -$left,
                'description' => "{$sale->invoice_no} cancelled" . ($sale->cancel_reason ? ": {$sale->cancel_reason}" : ''),
            ]) ? 1 : 0;
        }

        return $created;
    }

    // shesh hoye jawa mash (<= $to) er target: net bikri target chhuile bonus
    private function targetBonuses(User $staff, string $to): int
    {
        $created = 0;
        foreach (SalesTarget::where('user_id', $staff->id)->get() as $target) {
            $start = $target->month->copy()->startOfMonth();
            $end = $start->copy()->endOfMonth()->toDateString();
            if ($end > $to) {
                continue;
            }
            $achieved = $this->netSales($staff, $start->toDateString(), $end);
            $bonus = $target->bonusFor($achieved);
            if ($bonus <= 0) {
                continue;
            }
            $created += $this->entry("bonus:{$target->id}", [
                'user_id' => $staff->id, 'type' => CommissionEntry::BONUS, 'entry_date' => $end, 'sales_target_id' => $target->id,
                'base_amount' => $achieved, 'rate' => $target->bonus_value, 'amount' => $bonus,
                'description' => 'Target ' . $start->format('M Y') . ': achieved ' . number_format($achieved, 2) . ' of ' . number_format((float) $target->target_amount, 2),
            ]) ? 1 : 0;
        }

        return $created;
    }

    // ekoi uniq_key duibar na (ekshathe duita hishab holeo database atkay)
    private function entry(string $key, array $data): bool
    {
        if ((float) $data['amount'] == 0.0 || CommissionEntry::where('uniq_key', $key)->exists()) {
            return false;
        }
        try {
            CommissionEntry::create($data + ['uniq_key' => $key]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'uniq_key')) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    // employee er statement chara entry (period shesh porjonto) ei statement e
    private function attach(CommissionStatement $statement): void
    {
        CommissionEntry::where('user_id', $statement->user_id)->whereNull('commission_statement_id')
            ->whereDate('entry_date', '<=', $statement->period_end)->lockForUpdate()->get()
            ->each->update(['commission_statement_id' => $statement->id]);
        $this->totals($statement);
    }

    private function totals(CommissionStatement $statement): void
    {
        $sums = CommissionEntry::where('commission_statement_id', $statement->id)->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');
        $earned = round((float) ($sums[CommissionEntry::EARNED] ?? 0), 2);
        $reversed = round((float) ($sums[CommissionEntry::RETURN] ?? 0) + (float) ($sums[CommissionEntry::CANCEL] ?? 0), 2);
        $bonus = round((float) ($sums[CommissionEntry::BONUS] ?? 0), 2);

        $statement->update(['earned' => $earned, 'reversed' => $reversed, 'bonus' => $bonus, 'net' => round($earned + $reversed + $bonus, 2)]);
    }

    private function period(string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        if ($from > $to) {
            throw new \Exception('The period start must be before its end.');
        }
        if ($to > now()->toDateString()) {
            throw new \Exception('A statement cannot end in the future.');
        }

        return [$from, $to];
    }

    private function lock(CommissionStatement $statement, array $allowed): CommissionStatement
    {
        $statement = CommissionStatement::lockForUpdate()->findOrFail($statement->id);
        if (!in_array($statement->status, $allowed, true)) {
            throw new \Exception("Statement {$statement->statement_no} is {$statement->statusLabel()}; this step is not allowed now.");
        }

        return $statement;
    }
}
