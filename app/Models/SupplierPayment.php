<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Supplier ke deya taka (payment) ba supplier er ferot deya agrim (refund).
 * Toirir por taka/tarikh bodlano jay na; bhul hole void (ledger e ulto entry) kora hoy.
 */
class SupplierPayment extends Model
{
    const TYPE_PAYMENT = 'payment';
    const TYPE_REFUND = 'refund';

    const SOURCE_PURCHASE = 'purchase';  // purchase form e deya
    const SOURCE_INVOICE = 'invoice';    // pore invoice er due
    const SOURCE_SUPPLIER = 'supplier';  // supplier er hishab e (FIFO te purono invoice e bose)

    // void chara ar kichu bodlano jay na
    const MUTABLE = ['payment_no', 'voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $fillable = [
        'supplier_id', 'payment_no', 'type', 'source', 'payment_date', 'amount', 'payment_method', 'payment_account',
        'reference_no', 'purchase_id', 'idempotency_key', 'note', 'created_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'voided_at'    => 'datetime',
            'amount'       => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierPayment $payment) {
            $changed = array_diff(array_keys($payment->getDirty()), self::MUTABLE);
            if ($changed) {
                throw new \LogicException('A supplier payment cannot be edited (' . implode(', ', $changed) . '). Void it and record a new one.');
            }
        });
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isRefund(): bool
    {
        return $this->type === self::TYPE_REFUND;
    }
}
