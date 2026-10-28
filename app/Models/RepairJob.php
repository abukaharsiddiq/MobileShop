<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Repair job card. Status: received -> in_repair <-> waiting_parts -> completed -> qc_passed -> delivered;
 * delivery r age jekono jaygay cancelled (parts ferot, taka ferot). QC fail hole completed -> in_repair.
 * Status shudhu RepairService bodlay; job muchha jay na.
 */
class RepairJob extends Model
{
    const RECEIVED = 'received';
    const IN_REPAIR = 'in_repair';
    const WAITING_PARTS = 'waiting_parts';
    const COMPLETED = 'completed';
    const QC_PASSED = 'qc_passed';
    const DELIVERED = 'delivered';
    const CANCELLED = 'cancelled';

    const LABELS = [
        self::RECEIVED      => 'Received',
        self::IN_REPAIR     => 'In repair',
        self::WAITING_PARTS => 'Waiting for parts',
        self::COMPLETED     => 'Repair completed',
        self::QC_PASSED     => 'Quality checked',
        self::DELIVERED     => 'Delivered',
        self::CANCELLED     => 'Cancelled',
    ];

    const CLOSED = [self::DELIVERED, self::CANCELLED];
    const WORKING = [self::IN_REPAIR, self::WAITING_PARTS];

    // device kar: amader kach theke ei customer kinechhe | customer er ghoshona | onno customer er kena, proof dekhiyechhe
    const OWNERSHIP = [
        'shop_sale' => 'Bought from this shop',
        'declared'  => 'Declared by customer',
        'proof'     => 'Proof checked (bought from another owner)',
    ];

    protected $guarded = ['id', 'open_imei'];

    protected function casts(): array
    {
        return [
            'accessories'         => 'array',
            'under_warranty'      => 'boolean',
            'estimated_cost'      => 'decimal:2',
            'service_charge'      => 'decimal:2',
            'parts_total'         => 'decimal:2',
            'parts_cost'          => 'decimal:2',
            'discount'            => 'decimal:2',
            'total'               => 'decimal:2',
            'paid_amount'         => 'decimal:2',
            'cancellation_charge' => 'decimal:2',
            'estimated_delivery'  => 'date',
            'warranty_until'      => 'date',
            'qc_at'               => 'datetime',
            'completed_at'        => 'datetime',
            'delivered_at'        => 'datetime',
            'cancelled_at'        => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Repair jobs cannot be deleted. Cancel the job instead.'));
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function device()
    {
        return $this->belongsTo(ProductImei::class, 'product_imei_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function previousJob()
    {
        return $this->belongsTo(RepairJob::class, 'previous_job_id');
    }

    public function parts()
    {
        return $this->hasMany(RepairJobPart::class)->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(RepairPayment::class)->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(RepairJobEvent::class)->orderBy('id');
    }

    public function scopeAccessibleBy($query, User $user)
    {
        $branchIds = $user->allowedBranchIds();

        return $branchIds === null ? $query : $query->where(fn ($q) => $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id'));
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', self::CLOSED);
    }

    public function isOpen(): bool
    {
        return !in_array($this->status, self::CLOSED, true);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? ucfirst($this->status);
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::RECEIVED      => 'secondary',
            self::IN_REPAIR, self::WAITING_PARTS => 'info',
            self::COMPLETED     => 'primary',
            self::QC_PASSED     => 'success',
            self::DELIVERED     => 'dark',
            default             => 'danger',
        };
    }

    public function ownershipLabel(): string
    {
        return self::OWNERSHIP[$this->ownership] ?? $this->ownership;
    }

    // ekhon porjonto bill (delivery r age: service charge + approved parts - discount)
    public function billTotal(): float
    {
        return in_array($this->status, self::CLOSED, true)
            ? (float) $this->total
            : round((float) $this->service_charge + (float) $this->parts_total - (float) $this->discount, 2);
    }

    public function due(): float
    {
        return round($this->billTotal() - (float) $this->paid_amount, 2);
    }

    public function partsMargin(): float
    {
        return round((float) $this->parts_total - (float) $this->parts_cost, 2);
    }

    public function numbersLabel(): string
    {
        return $this->imei_no ? $this->imei_no . ($this->imei_no_2 ? ' / ' . $this->imei_no_2 : '') : 'No IMEI (' . $this->imei_missing_reason . ')';
    }

    public function warrantyActive(?string $on = null): bool
    {
        return $this->status === self::DELIVERED && $this->warranty_until && $this->warranty_until->toDateString() >= ($on ?? now()->toDateString());
    }
}
