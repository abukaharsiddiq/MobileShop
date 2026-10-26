<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Supplier er hishab. balance = credit - debit: dhonatmok = amra dhari (due), rinatmok = agrim (advance).
 * Credit: opening payable, purchase, supplier er refund. Debit: payment, purchase return, opening advance.
 */
class SupplierLedger extends Model
{
    const OPENING = 'Opening Balance';
    const PURCHASE = 'Purchase';
    const PURCHASE_CANCEL = 'Purchase Cancelled';   // approve kora purchase cancel: bill er ulto (debit)
    const PAYMENT = 'Payment';
    const PURCHASE_RETURN = 'Purchase Return';
    const REFUND = 'Supplier Refund';
    const PAYMENT_VOID = 'Payment Void';
    const REFUND_VOID = 'Refund Void';

    // Opening chara kono row er taka/tarikh bodlano jay na (shudhu running balance)
    const LOCKED = ['supplier_id', 'date', 'transaction_type', 'debit', 'credit', 'purchase_id', 'supplier_payment_id', 'purchase_return_id'];

    protected $fillable = [
        'supplier_id',
        'date',
        'transaction_type',
        'purchase_id',
        'supplier_payment_id',
        'purchase_return_id',
        'reference_no',
        'debit',
        'credit',
        'balance',
        'note',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierLedger $ledger) {
            $ledger->created_by ??= auth()->id();
        });

        static::updating(function (SupplierLedger $ledger) {
            if ($ledger->getOriginal('transaction_type') === self::OPENING) {
                return;
            }
            $changed = array_intersect(array_keys($ledger->getDirty()), self::LOCKED);
            if ($changed) {
                throw new \LogicException('Supplier ledger entries cannot be edited (' . implode(', ', $changed) . ').');
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

    public function payment()
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }
}
