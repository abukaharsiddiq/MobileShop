<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Employee er maser bikrir target; puron hole bonus (fixed ba achieved bikrir %)
class SalesTarget extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'target_amount' => 'decimal:2', 'bonus_value' => 'decimal:2'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bonusFor(float $achieved): float
    {
        if ($achieved < (float) $this->target_amount) {
            return 0.0;
        }

        return round($this->bonus_type === 'percent' ? $achieved * (float) $this->bonus_value / 100 : (float) $this->bonus_value, 2);
    }
}
