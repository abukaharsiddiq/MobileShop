<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Commission niyom. Basis: percent (line er net bikrir %), per_unit (protiti piece e taka), per_sale (invoice e taka).
 * Line e shobcheye nirdishto niyom: product > category > product type, tarpor employee > role > shobai.
 */
class CommissionRule extends Model
{
    const PERCENT = 'percent';
    const PER_UNIT = 'per_unit';
    const PER_SALE = 'per_sale';

    const BASES = [
        self::PERCENT  => '% of line sale (after line discount)',
        self::PER_UNIT => 'Fixed amount per unit',
        self::PER_SALE => 'Fixed amount per invoice',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'status' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    protected static function booted(): void
    {
        // hishab hoye geche emon niyom bodlano jay na (purono hishab er bhitti) - notun niyom banan, purono ta bondho
        static::updating(function (CommissionRule $rule) {
            $changed = array_diff(array_keys($rule->getDirty()), ['status', 'effective_to', 'updated_at']);
            if ($changed && CommissionEntry::where('commission_rule_id', $rule->id)->exists()) {
                throw new \Exception('This rule is already used in commission entries. Set an end date and create a new rule instead.');
            }
        });
        static::deleting(function (CommissionRule $rule) {
            if (CommissionEntry::where('commission_rule_id', $rule->id)->exists()) {
                throw new \Exception('This rule is already used in commission entries and cannot be deleted. Deactivate it instead.');
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // kon line e khatbe tar nirdishtota (beshi = age)
    public function specificity(): int
    {
        return ($this->product_id ? 100 : 0) + ($this->category_id ? 50 : 0) + ($this->product_type ? 25 : 0)
            + ($this->user_id ? 10 : 0) + ($this->role ? 5 : 0);
    }

    public function basisLabel(): string
    {
        return self::BASES[$this->basis] ?? $this->basis;
    }

    public function rateLabel(): string
    {
        return $this->basis === self::PERCENT ? rtrim(rtrim(number_format((float) $this->rate, 2), '0'), '.') . '%' : number_format((float) $this->rate, 2);
    }

    public function scopeLabel(): string
    {
        $parts = array_filter([
            $this->user?->name, $this->role ? ucfirst($this->role) . 's' : null,
            $this->product?->product_name, $this->category?->name, $this->product_type ? (Product::TYPES[$this->product_type] ?? $this->product_type) : null,
        ]);

        return $parts ? implode(', ', $parts) : 'Everyone, all products';
    }
}
