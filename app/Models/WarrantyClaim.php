<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Warranty claim: jama (received) -> inspection (covered / not covered) -> repair ba brand service -> ready
 * (repaired / replaced / no fault) -> customer ke ferot (delivered). Not covered hole rejected.
 */
class WarrantyClaim extends Model
{
    const RECEIVED = 'received';
    const INSPECTED = 'inspected';
    const IN_REPAIR = 'in_repair';
    const SENT_TO_BRAND = 'sent_to_brand';
    const READY = 'ready';
    const DELIVERED = 'delivered';
    const REJECTED = 'rejected';

    const CLOSED = [self::DELIVERED, self::REJECTED];

    const LABELS = [
        self::RECEIVED      => 'Received',
        self::INSPECTED     => 'Inspected',
        self::IN_REPAIR     => 'In repair',
        self::SENT_TO_BRAND => 'Sent to brand service',
        self::READY         => 'Ready for pickup',
        self::DELIVERED     => 'Delivered',
        self::REJECTED      => 'Rejected',
    ];

    // kon status theke kothay jawa jay (inspection/resolve er nijer method)
    const TRANSITIONS = [
        self::INSPECTED     => [self::IN_REPAIR, self::SENT_TO_BRAND],
        self::IN_REPAIR     => [self::SENT_TO_BRAND],
        self::SENT_TO_BRAND => [self::IN_REPAIR],
    ];

    const RESOLUTIONS = ['repaired' => 'Repaired', 'replaced' => 'Replaced with a new unit', 'no_fault' => 'No fault found'];

    protected $guarded = ['id', 'open_warranty'];

    protected function casts(): array
    {
        return ['reported_on' => 'date', 'delivered_at' => 'datetime'];
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function reasonLabel(): string
    {
        return config('warranty.claim_reasons')[$this->reason] ?? $this->reason;
    }

    public function isOpen(): bool
    {
        return !in_array($this->status, self::CLOSED, true);
    }

    public function warranty()
    {
        return $this->belongsTo(Warranty::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function events()
    {
        return $this->hasMany(WarrantyClaimEvent::class)->orderBy('id');
    }

    public function replacement()
    {
        return $this->belongsTo(ProductImei::class, 'replacement_imei_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
