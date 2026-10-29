<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

// Nijer theke backup er niyom (ekta-i row).
class BackupSchedule extends Model
{
    public const FREQUENCIES = [
        'every_6_hours'  => 'Every 6 hours',
        'every_12_hours' => 'Every 12 hours',
        'daily'          => 'Every day',
        'weekly'         => 'Every week',
    ];

    public const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'weekday' => 'integer', 'keep' => 'integer'];
    }

    public static function current(): self
    {
        return static::first() ?? new static(['enabled' => false, 'frequency' => 'daily', 'time' => '02:30', 'weekday' => 5, 'keep' => 7]);
    }

    public function intervalHours(): int
    {
        return ['every_6_hours' => 6, 'every_12_hours' => 12, 'daily' => 24, 'weekly' => 168][$this->frequency] ?? 24;
    }

    // ekhon porjonto shesh je shomoye backup howar kotha
    public function lastSlot(?Carbon $now = null): Carbon
    {
        $now ??= now();
        [$h, $m] = array_map('intval', explode(':', $this->time ?: '02:30'));
        $slot = $now->copy()->setTime($h, $m);
        if ($this->frequency === 'weekly') {
            while ($slot->dayOfWeek !== $this->weekday || $slot->gt($now)) {
                $slot->subDay();
            }

            return $slot;
        }
        $step = $this->intervalHours();
        while ($slot->gt($now)) {
            $slot->subHours($step);
        }
        while ($slot->copy()->addHours($step)->lte($now)) {
            $slot->addHours($step);
        }

        return $slot;
    }

    public function nextSlot(?Carbon $now = null): Carbon
    {
        return $this->frequency === 'weekly' ? $this->lastSlot($now)->addWeek() : $this->lastSlot($now)->addHours($this->intervalHours());
    }

    public function describe(): string
    {
        $at = Carbon::createFromFormat('H:i', $this->time ?: '02:30')->format('h:i A');

        return match ($this->frequency) {
            'weekly' => 'Every ' . self::WEEKDAYS[$this->weekday] . ' at ' . $at,
            'daily'  => 'Every day at ' . $at,
            default  => self::FREQUENCIES[$this->frequency] . ' from ' . $at,
        };
    }
}
