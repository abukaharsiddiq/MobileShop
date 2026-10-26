<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Purchase invoice. Draft/pending e stock, IMEI, average cost ar supplier ledger chhoy na;
 * approve hole PurchaseService shob ekshathe kore. Approved invoice bodlano jay na - cancel hoy.
 */
class Purchase extends Model
{
    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';       // approval er opekkhay
    const STATUS_APPROVED = 'approved';
    const STATUS_CANCELLED = 'cancelled';

    const STATUSES = [
        self::STATUS_DRAFT     => 'Draft',
        self::STATUS_PENDING   => 'Pending Approval',
        self::STATUS_APPROVED  => 'Approved',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'supplier_id',
        'branch_id',
        'invoice_no',
        'status',
        'supplier_invoice_no',
        'purchase_date',
        'subtotal',
        'discount_type',
        'discount',
        'tax_rate',
        'tax_amount',
        'additional_cost',
        'additional_cost_note',
        'grand_total',
        'payment_method',
        'payment_account',
        'initial_payment',
        'paid_amount',
        'return_amount',
        'due_amount',
        'payment_status',
        'note',
        'idempotency_key',
        'created_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at'  => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function purchaseDetails()
    {
        return $this->hasMany(PurchaseDetail::class);
    }

    public function supplier() {
    return $this->belongsTo(Supplier::class, 'supplier_id');
}

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function returns()
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function product_imeis()
{
    return $this->hasMany(ProductImei::class, 'purchase_id', 'id');
}

    public function scopeApproved($query)
    {
        return $query->where('purchases.status', self::STATUS_APPROVED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    // draft/pending: edit, approve, muchha jay
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function statusBadge(): string
    {
        return [
            self::STATUS_DRAFT     => 'badge-secondary',
            self::STATUS_PENDING   => 'badge-warning',
            self::STATUS_APPROVED  => 'badge-success',
            self::STATUS_CANCELLED => 'badge-dark',
        ][$this->status] ?? 'badge-light';
    }
}
