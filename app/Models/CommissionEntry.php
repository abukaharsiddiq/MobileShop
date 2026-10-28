<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Commission er ekta hishab line: earned (bikri), return/cancel (ulto, negative), bonus (target).
 * Bodlano/muchha jay na - shudhu kon statement e gelo (commission_statement_id) bosano/sorano jay.
 */
class CommissionEntry extends Model
{
    const UPDATED_AT = null;

    const EARNED = 'earned';
    const RETURN = 'return';
    const CANCEL = 'cancel';
    const BONUS = 'bonus';

    const LABELS = [self::EARNED => 'Sale', self::RETURN => 'Return', self::CANCEL => 'Cancelled sale', self::BONUS => 'Target bonus'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'base_amount' => 'decimal:2', 'rate' => 'decimal:2', 'amount' => 'decimal:2', 'qty' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (CommissionEntry $entry) {
            if (array_diff(array_keys($entry->getDirty()), ['commission_statement_id'])) {
                throw new \LogicException('Commission entries cannot be edited.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Commission entries cannot be deleted.'));
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function rule()
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function statement()
    {
        return $this->belongsTo(CommissionStatement::class, 'commission_statement_id');
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }
}
