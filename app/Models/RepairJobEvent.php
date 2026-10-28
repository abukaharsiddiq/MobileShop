<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Repair job er itihash (status, technician, part, taka). Bodlano/muchha jay na.
class RepairJobEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['repair_job_id', 'event', 'note', 'user_id'];

    // status chara onno ghotona
    const OTHER = [
        'assigned'       => 'Technician assigned',
        'diagnosis'      => 'Diagnosis',
        'part_requested' => 'Part requested',
        'part_approved'  => 'Part approved',
        'part_rejected'  => 'Part rejected',
        'part_returned'  => 'Part returned to stock',
        'qc_failed'      => 'QC failed',
        'payment'        => 'Payment',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Repair history cannot be edited.'));
        static::deleting(fn () => throw new \LogicException('Repair history cannot be deleted.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return RepairJob::LABELS[$this->event] ?? self::OTHER[$this->event] ?? ucfirst(str_replace('_', ' ', $this->event));
    }
}
