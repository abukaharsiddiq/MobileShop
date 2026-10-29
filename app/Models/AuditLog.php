<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Append-only: toiri hoy, kokhono bodlay na/muche na (model e atkano, database e trigger, ar hash chain).
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'user_name', 'branch_id', 'action', 'auditable_type', 'auditable_id', 'reference', 'old_values', 'new_values',
        'method', 'url', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new \LogicException('Audit log entries cannot be deleted.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // "App\Models\Sale" -> "Sale"
    public function recordType(): ?string
    {
        return $this->auditable_type ? class_basename($this->auditable_type) : null;
    }

    // age/pore er tulona: [field => [age, pore]] (shudhu ja bodleche; ek dik faka holeo)
    public function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $rows = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;
            if (!$this->old_values || !$this->new_values || $before != $after) {
                $rows[$key] = [$before, $after];
            }
        }

        return $rows;
    }

    public function area(): string
    {
        return explode('.', $this->action)[0];
    }
}
