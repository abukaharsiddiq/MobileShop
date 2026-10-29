<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// SMS / WhatsApp er main switch. Bondho channel e kono message toiri hoy na.
class NotificationChannel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /** @return array<int, string> chalu channel gulo */
    public static function enabled(): array
    {
        return static::where('is_enabled', true)->pluck('channel')->all();
    }

    public static function isEnabled(string $channel): bool
    {
        return static::where('channel', $channel)->where('is_enabled', true)->exists();
    }
}
