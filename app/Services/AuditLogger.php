<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    // Ke, kokhon, kon record e ki korlo
    public static function log(string $action, ?Model $model = null, ?array $old = null, ?array $new = null): AuditLog
    {
        $request = app()->bound('request') ? request() : null;

        return AuditLog::create([
            'user_id'        => auth()->id(),
            'action'         => $action,
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id'   => $model?->getKey(),
            'old_values'     => $old,
            'new_values'     => $new,
            'ip_address'     => $request?->ip(),
            'user_agent'     => $request?->userAgent(),
        ]);
    }
}
