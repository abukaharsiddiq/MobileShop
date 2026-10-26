<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Ekta bikri howa device (IMEI) ba accessory line er shop/brand warranty. Status server e hishab hoy:
 * void/replaced (record e) > claimed (open claim) > expired > expiring_soon > valid.
 */
class Warranty extends Model
{
    const SHOP = 'shop';
    const BRAND = 'brand';

    const ACTIVE = 'active';
    const VOID = 'void';
    const REPLACED = 'replaced';

    const STATUS_LABELS = [
        'valid'         => 'Valid',
        'expiring_soon' => 'Expiring soon',
        'expired'       => 'Expired',
        'claimed'       => 'Claimed',
        'void'          => 'Void',
        'replaced'      => 'Replaced',
    ];

    protected $guarded = ['id', 'live_key'];

    protected function casts(): array
    {
        return [
            'start_date'       => 'date',
            'end_date'         => 'date',
            'voided_at'        => 'datetime',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function currentStatus(?Carbon $on = null): string
    {
        if ($this->status !== self::ACTIVE) {
            return $this->status;
        }
        if ($this->relationLoaded('openClaim') ? $this->openClaim : $this->openClaim()->exists()) {
            return 'claimed';
        }
        $on = ($on ?? now())->copy()->startOfDay();
        if ($this->end_date->lt($on)) {
            return 'expired';
        }

        return $this->end_date->lte($on->copy()->addDays((int) config('warranty.expiring_soon_days', 30))) ? 'expiring_soon' : 'valid';
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->currentStatus()] ?? $this->status;
    }

    // claim neya jay: active, meyad shesh hoyni (reported tarikhe), ar kono open claim nei
    public function isClaimable(?string $on = null): bool
    {
        $on = $on ? Carbon::parse($on) : now();

        return $this->status === self::ACTIVE && !$this->end_date->lt($on->copy()->startOfDay()) && !$this->start_date->gt($on);
    }

    public function daysLeft(): int
    {
        return max(0, (int) now()->startOfDay()->diffInDays($this->end_date, false));
    }

    public function scopeActive($query)
    {
        return $query->where('warranties.status', self::ACTIVE);
    }

    // meyad ache (ajke porjonto)
    public function scopeInForce($query, ?string $on = null)
    {
        return $query->active()->where('end_date', '>=', $on ?? now()->toDateString());
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function detail()
    {
        return $this->belongsTo(SaleDetail::class, 'sale_detail_id');
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

    public function claims()
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    public function openClaim()
    {
        return $this->hasOne(WarrantyClaim::class)->whereNotIn('status', WarrantyClaim::CLOSED);
    }

    public function replacedFrom()
    {
        return $this->belongsTo(self::class, 'replaced_from_id');
    }
}
