<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Scheduler, queue worker, backup, integrity check er shesh shomoy (ar fol).
class SystemHeartbeat extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['beat_at' => 'datetime', 'meta' => 'array'];
    }

    public static function beat(string $name, ?array $meta = null): void
    {
        static::updateOrCreate(['name' => $name], ['beat_at' => now()] + ($meta !== null ? ['meta' => $meta] : []));
    }

    public static function last(string $name): ?\Illuminate\Support\Carbon
    {
        return static::where('name', $name)->first()?->beat_at;
    }

    public static function meta(string $name): ?array
    {
        return static::where('name', $name)->first()?->meta;
    }
}
