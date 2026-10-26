<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Chart of accounts. Cash/bank/mobile banking account (money account) ar hishaber account (receivable,
 * sales, expense ...). System account (system_key) code theke khoja hoy, tai segulo muchha ba type bodlano jay na.
 *
 * Normal balance: asset/expense = debit - credit, liability/equity/income = credit - debit.
 */
class Account extends Model
{
    const ASSET = 'asset';
    const LIABILITY = 'liability';
    const EQUITY = 'equity';
    const INCOME = 'income';
    const EXPENSE = 'expense';

    const MONEY_SUBTYPES = ['cash', 'bank', 'mobile_banking'];

    // form er payment method -> kon subtype er default account
    const METHOD_ALIASES = ['cheque' => 'bank', 'bank transfer' => 'bank', 'card' => 'bank'];

    protected $fillable = [
        'code', 'name', 'type', 'subtype', 'system_key', 'payment_method', 'bank_name', 'account_number',
        'branch_id', 'is_system', 'status', 'note',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'status'    => 'boolean',
        ];
    }

    public static function types(): array
    {
        return [self::ASSET => 'Asset', self::LIABILITY => 'Liability', self::EQUITY => 'Equity',
            self::INCOME => 'Income', self::EXPENSE => 'Expense'];
    }

    public static function moneySubtypes(): array
    {
        return ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_banking' => 'Mobile Banking'];
    }

    public static function system(string $key): self
    {
        $account = static::where('system_key', $key)->first();
        if (!$account) {
            throw new \Exception("System account \"{$key}\" is missing. Run the migrations again.");
        }

        return $account;
    }

    /**
     * Taka kon cash/bank account e jabe/theke ashbe: form e account bachai thakle seta, na hole payment method
     * (Cash/Bkash/Bank ...) er default account, tao na pele Cash in Hand.
     */
    public static function moneyFor(?int $accountId = null, ?string $method = null): self
    {
        if ($accountId) {
            $account = static::money()->find($accountId);
            if (!$account || !$account->status) {
                throw new \Exception('Select an active cash, bank or mobile banking account.');
            }

            return $account;
        }

        $method = strtolower(trim((string) $method));
        if ($method !== '') {
            $account = static::money()->active()->whereRaw('LOWER(payment_method) = ?', [$method])->orderBy('id')->first();
            if ($account) {
                return $account;
            }
            if (isset(self::METHOD_ALIASES[$method])) {
                $account = static::money()->active()->where('subtype', self::METHOD_ALIASES[$method])->orderBy('id')->first();
                if ($account) {
                    return $account;
                }
            }
        }

        return static::system('cash');
    }

    public function scopeMoney($query)
    {
        return $query->whereIn('subtype', self::MONEY_SUBTYPES);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function isMoney(): bool
    {
        return in_array($this->subtype, self::MONEY_SUBTYPES, true);
    }

    // Debit side e bare (asset, expense)
    public function isDebitNormal(): bool
    {
        return in_array($this->type, [self::ASSET, self::EXPENSE], true);
    }

    // AR/AP/Inventory: subledger ba stock er sathe mil rakhte shudhu system posting e bodlay
    public function isControl(): bool
    {
        return in_array($this->subtype, ['receivable', 'payable', 'inventory'], true);
    }

    public function label(): string
    {
        return "{$this->code} - {$this->name}";
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // Reverse entry nijei ulta line, tai posted/reversed shob line er jogfol-i asol balance
    public function balance(?string $asOf = null): float
    {
        $query = JournalLine::where('account_id', $this->id);
        if ($asOf) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '<=', $asOf));
        }
        $net = (float) $query->sum(DB::raw('debit - credit'));

        return round($this->isDebitNormal() ? $net : -$net, 2);
    }
}
