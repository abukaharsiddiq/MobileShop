<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'customer_id',
        'branch_id',
        'salesperson_id',
        'invoice_no',
        'status',
        'sale_date',
        'subtotal',
        'discount_type',
        'discount',
        'tax_rate',
        'tax_amount',
        'additional_charge',
        'additional_charge_note',
        'grand_total',
        'payment_method',
        'payment_account',
        'paid_amount',
        'due_amount',
        'is_installment',
        'total_installments',
        'installment_per_month',
        'total_paid_installments',
        'note',
        'created_by',
        'idempotency_key',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return ['cancelled_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(SaleDetail::class);
    }

    public function user() // কে সেল করেছে
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function salesperson()
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

public function saleDetails()
{
    return $this->hasMany(SaleDetail::class);
}

public function returns() {
    return $this->hasMany(SaleReturn::class, 'sale_id');
}

    // report/hishab e: cancel kora sale bad
    public function scopeCompleted($query)
    {
        return $query->where('sales.status', self::STATUS_COMPLETED);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    // jinish er dam (tax ar additional charge chara) = subtotal - discount
    public function goodsTotal(): float
    {
        return round((float) $this->grand_total - (float) $this->tax_amount - (float) $this->additional_charge, 2);
    }

    public function paymentStatus(): string
    {
        if ($this->isCancelled()) {
            return 'Cancelled';
        }
        if ((float) $this->due_amount <= 0) {
            return 'Paid';
        }

        return (float) $this->paid_amount > 0 ? 'Partial' : 'Due';
    }
}
