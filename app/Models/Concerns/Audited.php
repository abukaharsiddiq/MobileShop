<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;

/**
 * Je model er controller/service e alada audit nei, tar create/update/delete nije audit log e (shudhu bodlano
 * field, age/pore). Gopon field AuditLogger/Redactor muche. $auditExclude e bad dewa field (jemon hisab kora
 * quantity - tar itihash stock_movements e) dhora hoy na.
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(function ($model) {
            AuditLogger::log(self::auditName($model) . '.created', $model, null, $model->auditable($model->getAttributes()));
        });
        static::updated(function ($model) {
            $changed = $model->auditable($model->getChanges());
            if (!$changed) {
                return;
            }
            $old = array_intersect_key($model->getOriginal(), $changed);
            AuditLogger::log(self::auditName($model) . '.updated', $model, $model->auditable($old), $changed);
        });
        static::deleted(function ($model) {
            AuditLogger::log(self::auditName($model) . '.deleted', $model, $model->auditable($model->getAttributes()), null);
        });
    }

    private function auditable(array $values): array
    {
        $skip = array_merge(['created_at', 'updated_at', 'deleted_at', 'remember_token', 'password'], $this->auditExclude ?? []);

        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, array_diff_key($values, array_flip($skip)));
    }

    private static function auditName($model): string
    {
        return \Illuminate\Support\Str::snake(class_basename($model));
    }
}
