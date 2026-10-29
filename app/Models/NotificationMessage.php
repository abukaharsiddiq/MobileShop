<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ekta pathano (ba pathanor chesta) message er itihash. dedupe_key unique: ekoi event/channel/reference e duibar na.
 * queued -> sending -> sent (provider nilo) -> delivered/read (provider er webhook) | failed (abar pathano jay);
 * skipped = shommoti nei / number thik na (pathano hoy ni).
 */
class NotificationMessage extends Model
{
    const QUEUED = 'queued';
    const SENDING = 'sending';
    const SENT = 'sent';
    const DELIVERED = 'delivered';
    const READ = 'read';
    const FAILED = 'failed';
    const SKIPPED = 'skipped';

    const LABELS = [
        self::QUEUED => 'Queued', self::SENDING => 'Sending', self::SENT => 'Sent', self::DELIVERED => 'Delivered',
        self::READ => 'Read', self::FAILED => 'Failed', self::SKIPPED => 'Skipped',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function provider()
    {
        return $this->belongsTo(NotificationProvider::class);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::SENT => 'info',
            self::DELIVERED, self::READ => 'success',
            self::FAILED => 'danger',
            self::SKIPPED => 'secondary',
            default => 'warning',
        };
    }

    public function eventLabel(): string
    {
        return config("notifications.events.{$this->event}.label", $this->event);
    }
}
