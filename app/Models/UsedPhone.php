<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ekta kena purono (used/refurbished) phone er intake. Status: pending -> (rejected | refurbishing | in_stock),
 * refurbishing <-> in_stock. in_stock howar por resale obostha device (ProductImei) er status theke
 * (available, reserved, sold, damaged ...). UsedPhoneService chara status bodlano jay na.
 */
class UsedPhone extends Model
{
    const PENDING = 'pending';
    const REJECTED = 'rejected';
    const REFURBISHING = 'refurbishing';
    const IN_STOCK = 'in_stock';

    const LABELS = [
        self::PENDING      => 'Waiting for approval',
        self::REJECTED     => 'Rejected',
        self::REFURBISHING => 'In refurbishment',
        self::IN_STOCK     => 'In stock',
    ];

    // muchha jay na; seller/IMEI/cost approve er por bodlay na
    protected $guarded = ['id', 'pending_imei'];

    protected function casts(): array
    {
        return [
            'accessories'        => 'array',
            'checklist'          => 'array',
            'purchase_date'      => 'date',
            'purchase_cost'      => 'decimal:2',
            'refurbishment_cost' => 'decimal:2',
            'resale_price'       => 'decimal:2',
            'battery_health'     => 'integer',
            'warranty_months'    => 'integer',
            'approved_at'        => 'datetime',
            'refurbished_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Used phone records cannot be deleted. Reject the intake instead.'));
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function mainStock()
    {
        return $this->belongsTo(MainStock::class);
    }

    public function device()
    {
        return $this->belongsTo(ProductImei::class, 'product_imei_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function events()
    {
        return $this->hasMany(UsedPhoneEvent::class)->orderBy('id');
    }

    // User je branch er intake dhorte pare
    public function scopeAccessibleBy($query, User $user)
    {
        $branchIds = $user->allowedBranchIds();

        return $branchIds === null ? $query : $query->where(fn ($q) => $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id'));
    }

    public function totalCost(): float
    {
        return round((float) $this->purchase_cost + (float) $this->refurbishment_cost, 2);
    }

    public function expectedMargin(): ?float
    {
        return $this->resale_price === null ? null : round((float) $this->resale_price - $this->totalCost(), 2);
    }

    // bikri hole oi invoice line er unit dam (line discount bad)
    public function soldPrice(): ?float
    {
        $device = $this->device;
        if (!$device || (int) $device->status !== ProductImei::STATUS_SOLD || !$device->saleDetail) {
            return null;
        }
        $detail = $device->saleDetail;

        return round((float) $detail->unit_total / max(1, (int) $detail->quantity), 2);
    }

    public function realizedMargin(): ?float
    {
        $price = $this->soldPrice();

        return $price === null ? null : round($price - $this->totalCost(), 2);
    }

    public function isApproved(): bool
    {
        return in_array($this->status, [self::REFURBISHING, self::IN_STOCK], true);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? ucfirst($this->status);
    }

    // in_stock hole device er obostha (Available, Sold ...), na hole intake er
    public function resaleStatus(): string
    {
        if ($this->status === self::IN_STOCK && $this->device) {
            return $this->device->statusText();
        }

        return $this->statusLabel();
    }

    public function resaleBadge(): string
    {
        return match ($this->status) {
            self::PENDING      => 'warning',
            self::REJECTED     => 'secondary',
            self::REFURBISHING => 'info',
            default            => $this->device ? $this->device->statusBadge() : 'secondary',
        };
    }

    public function sourceLabel(): string
    {
        return config('used_phones.sources')[$this->source] ?? ucfirst($this->source);
    }

    public function gradeLabel(): string
    {
        return config('used_phones.grades')[$this->condition_grade] ?? $this->condition_grade;
    }

    public function batteryIsLow(): bool
    {
        return $this->battery_health !== null && $this->battery_health < (int) config('used_phones.battery_warning_below');
    }

    public function numbersLabel(): string
    {
        return $this->imei_no . ($this->imei_no_2 ? ' / ' . $this->imei_no_2 : '');
    }

    // checklist e fault gulo
    public function faults(): array
    {
        $labels = config('used_phones.checklist');

        return collect((array) $this->checklist)->filter(fn ($v) => $v === 'fault')
            ->keys()->map(fn ($k) => $labels[$k] ?? $k)->values()->all();
    }
}
