<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Monitoring er shotorkota: check fail korle khole, thik hole somadhan (resolved).
class SystemAlert extends Model
{
    public const OPEN = 'open';
    public const RESOLVED = 'resolved';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'notified_at' => 'datetime', 'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime'];
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::OPEN);
    }
}
