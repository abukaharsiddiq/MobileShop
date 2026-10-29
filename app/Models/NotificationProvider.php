<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SMS/WhatsApp provider. Credential APP_KEY diye encrypted (database e plain thake na, code e o na);
 * page/JSON e dekhay na (hidden). Ek channel e ekta-i active (NotificationService::activate).
 */
class NotificationProvider extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean', 'last_tested_at' => 'datetime'];
    }

    public function credential(string $key, $default = null)
    {
        return ($this->credentials ?? [])[$key] ?? $default;
    }

    public function driverLabel(): string
    {
        return config("notifications.drivers.{$this->driver}.label", $this->driver);
    }

    // form e: gopon field er shudhu shesh 4 okkhor
    public function masked(string $key): ?string
    {
        $value = (string) $this->credential($key, '');

        return $value === '' ? null : str_repeat('•', 6) . substr($value, -4);
    }
}
