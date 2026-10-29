<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * audit_logs append-only: database trigger e update/delete bondho (shudhu hash na thaka row e hash bosano jay).
 * Migration e ar restore er por (table notun kore banano hole trigger o chole jay) bosano hoy.
 * Hosting e trigger er onumoti na thakle chain (audit:verify) tobuo bodlano dhore.
 */
class AuditLogProtection
{
    private const COLUMNS = ['user_id', 'user_name', 'branch_id', 'action', 'auditable_type', 'auditable_id', 'reference', 'old_values', 'new_values',
        'method', 'url', 'ip_address', 'user_agent', 'created_at'];

    public static function install(?Connection $db = null): bool
    {
        $db ??= DB::connection();
        if ($db->getDriverName() !== 'mysql') {
            return false;
        }
        $same = collect(self::COLUMNS)->map(fn ($c) => "NEW.`{$c}` <=> OLD.`{$c}`")->implode(' AND ');
        try {
            $db->unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            $db->unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
            $db->unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
                BEGIN
                    IF OLD.hash IS NOT NULL OR NOT ({$same}) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
                    END IF;
                END");
            $db->unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not create audit_logs protection triggers (missing TRIGGER privilege?). The hash chain still detects changes.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public static function remove(?Connection $db = null): void
    {
        $db ??= DB::connection();
        if ($db->getDriverName() === 'mysql') {
            $db->unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            $db->unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        }
    }
}
