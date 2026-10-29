<?php

namespace App\Services\Monitoring;

use App\Models\SystemError;
use App\Support\Redactor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Report kora protiti exception (Laravel er log er pashapashi) system_errors e: ekoi class+file+line ekta row
 * (koto bar, shesh kokhon). Message theke password/token/SQL er man muche. Nije kokhono error chhore na
 * (database bondho thakleo log e to jay).
 */
class ErrorRecorder
{
    private static bool $recording = false;

    public function record(\Throwable $e): void
    {
        if (self::$recording) {
            return;   // record korte giye abar error - ghure na
        }
        self::$recording = true;
        try {
            $message = $e instanceof QueryException
                ? 'SQL error ' . $e->getCode() . ': ' . $e->getSql() . ' - ' . preg_replace('/\(Connection:.*$/s', '', (string) $e->getPrevious()?->getMessage())
                : $e->getMessage();
            $message = mb_substr(Redactor::text(trim($message)) ?: '(no message)', 0, 2000);
            $file = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $e->getFile());
            $fingerprint = hash('sha256', get_class($e) . '|' . $file . '|' . $e->getLine());
            $request = app()->bound('request') && !(app()->runningInConsole() && !app()->runningUnitTests()) ? request() : null;
            $now = now();

            $updated = DB::table('system_errors')->where('fingerprint', $fingerprint)->update([
                'message' => $message, 'occurrences' => DB::raw('occurrences + 1'), 'last_seen_at' => $now, 'updated_at' => $now,
                'resolved_at' => null, 'resolved_by' => null,   // abar holo - khola
                'url' => Redactor::url($request?->path() ? '/' . ltrim($request->path(), '/') : null) ?? DB::raw('url'),
            ]);
            if (!$updated) {
                SystemError::insertOrIgnore([
                    'fingerprint' => $fingerprint, 'level' => 'error', 'exception' => mb_substr(get_class($e), 0, 190), 'message' => $message,
                    'file' => mb_substr($file, 0, 255), 'line' => $e->getLine(),
                    'url' => Redactor::url($request?->path() ? '/' . ltrim($request->path(), '/') : null),
                    'user_id' => auth()->check() ? auth()->id() : null, 'occurrences' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        } catch (\Throwable) {
            // database e lekha gelo na - Laravel er log e to ache
        } finally {
            self::$recording = false;
        }
    }
}
