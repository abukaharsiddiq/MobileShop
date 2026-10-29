<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Redactor;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    // record chena jay emon field (prothom ja pay)
    private const REFERENCE_FIELDS = ['invoice_no', 'purchase_no', 'return_no', 'entry_no', 'job_no', 'transfer_no', 'adjustment_no', 'count_no',
        'claim_no', 'warranty_no', 'statement_no', 'voucher_no', 'imei_no', 'imei', 'sku', 'product_name', 'name', 'email'];

    // Ke, kokhon, kon record e ki korlo (age/pore). Password/secret/token/NID ityadi kokhono lekha hoy na.
    public static function log(string $action, ?Model $model = null, ?array $old = null, ?array $new = null): AuditLog
    {
        // artisan/queue worker e asol request nei (test e ache)
        $console = app()->runningInConsole() && !app()->runningUnitTests();
        $request = app()->bound('request') && !$console ? request() : null;
        $user = auth()->user();

        return AuditLog::create([
            'user_id'        => $user?->getKey(),
            'user_name'      => $user ? mb_substr((string) $user->name, 0, 120) : ($console ? 'system (console)' : null),
            'branch_id'      => $user?->branch_id,
            'action'         => mb_substr($action, 0, 80),
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id'   => $model?->getKey(),
            'reference'      => $model ? self::reference($model) : null,
            'old_values'     => Redactor::array($old),
            'new_values'     => Redactor::array($new),
            'method'         => $request?->method(),
            'url'            => Redactor::url($request?->path() ? '/' . ltrim($request->path(), '/') : null),
            'ip_address'     => $request?->ip(),
            'user_agent'     => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    public static function reference(Model $model): ?string
    {
        foreach (self::REFERENCE_FIELDS as $field) {
            $value = $model->getAttribute($field);
            if (is_scalar($value) && (string) $value !== '') {
                return mb_substr((string) $value, 0, 120);
            }
        }

        return null;
    }
}
