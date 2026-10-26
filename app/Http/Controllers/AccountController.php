<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Branch;
use App\Models\JournalLine;
use App\Services\AuditLogger;
use App\Services\FinancePostingService;
use App\Services\FinanceReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Chart of accounts: cash/bank/mobile banking account ar income/expense/asset/liability account.
 * System account (receivable, sales ...) er naam bodlano jay, type/subtype na; journal e line thakle type bodlay na.
 */
class AccountController extends Controller
{
    public function __construct(
        private FinancePostingService $finance,
        private FinanceReportService $reports,
    ) {}

    public function index()
    {
        $accounts = Account::orderBy('code')->get();
        $balances = JournalLine::groupBy('account_id')->selectRaw('account_id, SUM(debit) as debit, SUM(credit) as credit')->get()->keyBy('account_id');

        foreach ($accounts as $account) {
            $row = $balances->get($account->id);
            $net = $row ? $row->debit - $row->credit : 0;
            $account->current_balance = round($account->isDebitNormal() ? $net : -$net, 2);
        }

        return view('back-end.finance.accounts.index', [
            'name'     => 'Chart of Accounts',
            'title'    => 'Chart of Accounts',
            'accounts' => $accounts,
            'money'    => $accounts->filter->isMoney(),
        ]);
    }

    public function create()
    {
        return view('back-end.finance.accounts.form', $this->formData(new Account(['type' => Account::ASSET, 'subtype' => 'bank', 'status' => 1])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        try {
            DB::transaction(function () use ($data, $request) {
                // cash/bank/mobile 10xx e (Cash in Hand, Bank er pashe); baki type er series e
                $series = in_array($data['subtype'], Account::MONEY_SUBTYPES, true) ? '10' : $this->series($data['type']);
                $data['code'] = $data['code'] ?: $this->finance->nextCode($series, (int) str_pad($series, 4, '0'));
                $account = Account::create($data);

                // shuru r balance (cash/bank e hate thaka taka): Dr account, Cr Opening Balance Equity
                if ($account->isMoney() && round((float) $request->opening_balance, 2) != 0) {
                    $this->finance->postAccountOpening($account, (float) $request->opening_balance, $request->opening_date ?: now()->toDateString());
                }

                AuditLogger::log('finance.account_created', $account, null, $account->only(['code', 'name', 'type', 'subtype']));
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('finance.accounts.index')->with('success', 'Account created.');
    }

    public function edit($id)
    {
        return view('back-end.finance.accounts.form', $this->formData(Account::findOrFail($id)));
    }

    public function update(Request $request, $id)
    {
        $account = Account::findOrFail($id);
        $data = $this->validated($request, $account);

        try {
            $hasLines = JournalLine::where('account_id', $account->id)->exists();
            if (($account->is_system || $hasLines) && ($data['type'] !== $account->type || $data['subtype'] !== $account->subtype)) {
                throw new \Exception('The type of a system account or an account with transactions cannot be changed.');
            }
            if ($account->is_system && !$data['status']) {
                throw new \Exception('System accounts cannot be deactivated.');
            }

            $data['code'] = $data['code'] ?: $account->code;   // khali rakhle ager code thake
            $old = $account->only(array_keys($data));
            $account->update($data);
            AuditLogger::log('finance.account_updated', $account, $old, $account->only(array_keys($data)));
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('finance.accounts.index')->with('success', 'Account updated.');
    }

    /**
     * Cash/bank er shuru r balance. Age deya thakle purono ta reverse hoye notun ta post hoy (itihash thake).
     */
    public function opening(Request $request, $id)
    {
        $request->validate(['opening_balance' => 'required|numeric', 'opening_date' => 'required|date']);

        try {
            DB::transaction(function () use ($request, $id) {
                $account = Account::lockForUpdate()->findOrFail($id);
                if (!$account->isMoney()) {
                    throw new \Exception('Opening balance here is only for cash, bank and mobile banking accounts.');
                }

                // age deya thakle seta reverse hoye notun ta post hoy
                $this->finance->postAccountOpening($account, (float) $request->opening_balance, $request->opening_date);
            });
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return back()->with('success', 'Opening balance saved.');
    }

    public function statement(Request $request, $id)
    {
        $account = Account::findOrFail($id);
        [$from, $to, $year] = $this->reports->period($request);

        return view('back-end.finance.accounts.statement', [
            'name'      => 'Account Statement',
            'title'     => $account->name,
            'statement' => $this->reports->accountStatement($account, $from, $to),
            'from'      => $from,
            'to'        => $to,
            'year'      => $year,
            'years'     => \App\Models\FinancialYear::orderByDesc('start_date')->get(),
        ]);
    }

    private function formData(Account $account): array
    {
        return [
            'name'     => $account->exists ? 'Edit Account' : 'New Account',
            'title'    => 'Chart of Accounts',
            'account'  => $account,
            'branches' => Branch::where('status', 1)->get(),
            'subtypes' => [
                Account::ASSET     => ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_banking' => 'Mobile Banking', 'other_asset' => 'Other Asset', 'fixed_asset' => 'Fixed Asset'],
                Account::LIABILITY => ['other_liability' => 'Other Liability', 'loan' => 'Loan'],
                Account::EQUITY    => ['capital' => 'Capital', 'drawings' => 'Drawings'],
                Account::INCOME    => ['other_income' => 'Other Income'],
                Account::EXPENSE   => ['operating_expense' => 'Operating Expense'],
            ],
        ];
    }

    private function validated(Request $request, ?Account $account = null): array
    {
        $request->validate([
            'code'            => ['nullable', 'string', 'max:20', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'name'            => 'required|string|max:255',
            'type'            => ['required', Rule::in(array_keys(Account::types()))],
            'subtype'         => 'required|string|max:40',
            'payment_method'  => 'nullable|string|max:40',
            'bank_name'       => 'nullable|string|max:255',
            'account_number'  => 'nullable|string|max:255',
            'branch_id'       => 'nullable|exists:branches,id',
            'opening_balance' => 'nullable|numeric',
            'opening_date'    => 'nullable|date',
        ]);

        // receivable/payable/inventory/sales er moto system subtype hate banano jay na
        $reserved = ['receivable', 'payable', 'inventory', 'sales', 'sales_return', 'sales_discount', 'cogs', 'opening_balance', 'retained_earnings'];
        if (in_array($request->subtype, $reserved, true) && $request->subtype !== $account?->subtype) {
            throw ValidationException::withMessages(['subtype' => 'That account kind is managed by the system.']);
        }

        return [
            'code'           => $request->code,
            'name'           => $request->name,
            'type'           => $request->type,
            'subtype'        => $request->subtype,
            'payment_method' => $request->payment_method,
            'bank_name'      => $request->bank_name,
            'account_number' => $request->account_number,
            'branch_id'      => $request->branch_id,
            'status'         => $request->boolean('status'),
            'note'           => $request->note,
        ];
    }

    private function series(string $type): string
    {
        return match ($type) {
            Account::ASSET     => '1',
            Account::LIABILITY => '2',
            Account::EQUITY    => '3',
            Account::INCOME    => '4',
            default            => '6',
        };
    }
}
