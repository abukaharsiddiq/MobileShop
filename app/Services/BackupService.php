<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Services\Backup\BackupCrypto;
use App\Services\Backup\SqlDumper;
use App\Services\Backup\SqlRestorer;
use App\Support\Redactor;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Database backup ar restore (PHP diye, mysqldump lage na).
 *
 * - run(): consistent snapshot -> SQL -> gzip -> (key thakle) AES-256-GCM -> sha256 + .json manifest (row er hisab).
 * - upload(): .sql / .sql.gz (ei page er ba phpMyAdmin er) - protiti statement jachai (shudhu table ar row), tarpor
 *   talikay; upload e kichu restore hoy na.
 * - verify(): checksum + statement jachai + alada faka database e restore kore row milano, tarpor sei database muche.
 * - restore(): age "before restore" backup, tarpor live database e (login session, queue, backup talika chhoya hoy na),
 *   tarpor migrate (purono backup er gothon notun code er moto).
 * - Ekbare ekta-i backup/restore (lock).
 */
class BackupService
{
    private const SNAPSHOT = 'backup_snapshot';
    private const TARGET = 'backup_target';
    private const CHECK = 'backup_check';

    public function __construct(private SqlDumper $dumper, private SqlRestorer $restorer) {}

    public function run(string $type = 'manual', ?User $by = null, ?string $note = null): Backup
    {
        return $this->locked(fn () => $this->create($type, $by, $note));
    }

    // nijer theke (scheduler protii minute dake): shomoy holei ekbar
    public function runScheduled(): ?Backup
    {
        $schedule = BackupSchedule::current();
        if (!$schedule->enabled) {
            return null;
        }
        // computer bondho thakle chalu hoyar por (kintu niyom chalur ager shomoyer jonno na)
        $slot = $schedule->lastSlot();
        if (($schedule->updated_at && $slot->lt($schedule->updated_at))
            || Backup::where('trigger', 'automatic')->where('started_at', '>=', $slot)->exists()) {
            return null;
        }

        return $this->run('automatic');
    }

    public function upload(UploadedFile $file, ?User $by = null, ?string $note = null): Backup
    {
        return $this->register($file->getRealPath(), $file->getClientOriginalName(), 'uploaded', $by, $note);
    }

    // disk e thaka file (offsite copy) talikay - backup:import
    public function import(string $path, ?User $by = null): Backup
    {
        return $this->register($path, basename($path), 'uploaded', $by, 'Imported from ' . basename($path));
    }

    /**
     * @return array{status: string, notes: array<int, string>}
     */
    public function verify(Backup $backup, ?User $by = null): array
    {
        $notes = [];
        $status = 'passed';
        [$sql, $temp] = [null, false];
        try {
            if (!$backup->exists()) {
                throw new \RuntimeException('The backup file is missing.');
            }
            if (!hash_equals((string) $backup->sha256, hash_file('sha256', $backup->path()))) {
                throw new \RuntimeException('Checksum mismatch - the file is damaged or was changed.');
            }
            $notes[] = 'Checksum (SHA-256) matches.';

            [$sql, $temp] = $this->readable($backup);
            $plan = $this->restorer->check($sql);
            $notes[] = "All {$plan['statements']} statements are allowed (" . count($plan['tables']) . ' tables).';

            $restored = $this->restoreCheck($sql);
            if ($backup->row_counts) {
                $diff = [];
                foreach ($backup->row_counts as $table => $count) {
                    if (!array_key_exists($table, $restored)) {
                        throw new \RuntimeException("Table {$table} is missing after the test restore.");
                    }
                    if ((int) $restored[$table] !== (int) $count) {
                        $diff[] = "{$table}: {$count} -> {$restored[$table]}";
                    }
                }
                if ($diff) {
                    $status = 'warning';
                    $notes[] = 'Test restore worked, but row counts differ: ' . implode('; ', array_slice($diff, 0, 10));
                } else {
                    $notes[] = 'Test restore into a separate database worked; every table and row count matches.';
                }
            } else {
                $backup->row_counts = $restored;
                $backup->table_count = count($restored);
                $notes[] = 'Test restore into a separate database worked (' . number_format(array_sum($restored)) . ' rows).';
            }
            $backup->restore_tested_at = now();
        } catch (\Throwable $e) {
            $status = 'failed';
            $notes[] = Redactor::text($e->getMessage());
        } finally {
            $temp && $sql && @unlink($sql);
        }

        $backup->fill(['verified_at' => now(), 'verify_status' => $status, 'verify_note' => mb_substr(implode(PHP_EOL, $notes), 0, 5000)])->save();
        SystemHeartbeat::beat('backup_verify', ['backup_id' => $backup->id, 'status' => $status]);
        AuditLogger::log('backup.verified', $backup, null, ['status' => $status]);

        return ['status' => $status, 'notes' => $notes];
    }

    /**
     * Live database e restore. @return array{safety: Backup, statements: int, tables: int}
     */
    public function restore(Backup $backup, ?User $by = null): array
    {
        return $this->locked(function () use ($backup, $by) {
            if (!$backup->exists()) {
                throw new \RuntimeException('The backup file is missing.');
            }
            if ($backup->sha256 && !hash_equals($backup->sha256, hash_file('sha256', $backup->path()))) {
                throw new \RuntimeException('Checksum mismatch - the file is damaged or was changed. It was not restored.');
            }
            [$sql, $temp] = $this->readable($backup);
            try {
                $plan = $this->restorer->check($sql);
                $this->assertWholeDatabase($plan['tables']);

                // age bortoman obosthar backup
                $safety = $this->create('before_restore', $by, 'Before restoring #' . $backup->id . ' (' . ($backup->completed_at?->format('d M Y H:i') ?? '') . ')');
                if ($safety->status !== Backup::COMPLETED) {
                    throw new \RuntimeException('Could not take the "before restore" backup, so nothing was changed: ' . $safety->error);
                }

                try {
                    $result = $this->restorer->restore($this->clone(self::TARGET), $sql, config('backup.keep_on_restore'), true);
                } catch (\Throwable $e) {
                    AuditLogger::log('backup.restore_failed', $backup, null, ['error' => Redactor::text($e->getMessage()), 'safety_backup' => $safety->id]);
                    throw new \RuntimeException('Restore stopped: ' . Redactor::text($e->getMessage())
                        . " The database may be half restored - restore the \"Before restore\" backup #{$safety->id} to go back.", 0, $e);
                }
                $this->afterRestore();
            } finally {
                $temp && @unlink($sql);
            }

            $backup->forceFill(['restored_at' => now()])->save();
            try {
                AuditLogger::log('backup.restored', $backup, null, ['filename' => $backup->filename, 'statements' => $result['statements'],
                    'tables' => $result['tables'], 'safety_backup' => $safety->filename]);
            } catch (\Throwable $e) {
                // purono backup e ei user na thakle (foreign key) - Laravel log e to thake
                \Illuminate\Support\Facades\Log::warning('Backup restored; audit entry could not be written.', ['backup' => $backup->id, 'error' => $e->getMessage()]);
            }

            return ['safety' => $safety, 'statements' => $result['statements'], 'tables' => $result['tables']];
        });
    }

    public function delete(Backup $backup, ?User $by = null): void
    {
        @unlink($backup->path());
        @unlink($backup->path() . '.json');
        AuditLogger::log('backup.deleted', $backup, ['filename' => $backup->filename, 'type' => $backup->trigger, 'sha256' => $backup->sha256], null);
        $backup->delete();
    }

    // shudhu automatic: shesh N ta rakhe; hate newa/upload/"before restore" muche na
    public function applyRetention(): int
    {
        $keep = max(1, BackupSchedule::current()->keep);
        $old = Backup::where('trigger', 'automatic')->where('status', Backup::COMPLETED)->orderByDesc('completed_at')->orderByDesc('id')->skip($keep)->take(1000)->get();
        foreach ($old as $backup) {
            @unlink($backup->path());
            @unlink($backup->path() . '.json');
            $backup->delete();
        }
        // byartho automatic gulor shudhu shesh 10 ta
        Backup::where('trigger', 'automatic')->where('status', Backup::FAILED)->orderByDesc('id')->skip(10)->take(1000)->get()->each->delete();
        if ($old->count()) {
            AuditLogger::log('backup.pruned', null, null, ['count' => $old->count(), 'keep' => $keep]);
        }

        return $old->count();
    }

    public function stats(): array
    {
        $db = $this->liveDatabase();
        $size = DB::connection($this->liveConnection())->selectOne('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS size, COUNT(*) AS tables
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$db]);
        $stored = Backup::where('status', Backup::COMPLETED);

        return [
            'database'    => $db,
            'db_size'     => (int) $size->size,
            'tables'      => (int) $size->tables,
            'last'        => Backup::where('status', Backup::COMPLETED)->latest('completed_at')->first(),
            'stored'      => (clone $stored)->count(),
            'stored_size' => (int) (clone $stored)->sum('size'),
        ];
    }

    public function directory(): string
    {
        $dir = rtrim(config('backup.path'), '/\\');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        // web server folder e rakha hole-o bhitore dhoka bondho
        if (!is_file($dir . DIRECTORY_SEPARATOR . '.htaccess')) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\nDeny from all\n");
        }

        return $dir;
    }

    public static function uploadLimit(): int
    {
        $bytes = function (string $v): int {
            $v = trim($v);
            $n = (int) $v;

            return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
        };
        $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);

        return $limits ? min($limits) : 2097152;
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function create(string $type, ?User $by, ?string $note): Backup
    {
        $database = $this->liveDatabase();
        $key = BackupCrypto::key(config('backup.encryption_key'));
        $dir = $this->directory();
        $name = 'backup-' . $database . '-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql.gz' . ($key ? '.enc' : '');

        $backup = Backup::create(['filename' => $name, 'status' => Backup::RUNNING, 'trigger' => $type, 'note' => $note ? mb_substr($note, 0, 190) : null,
            'encrypted' => (bool) $key, 'started_at' => now(), 'created_by' => $by?->id]);
        $gz = $dir . DIRECTORY_SEPARATOR . $name . '.gz.tmp';

        try {
            @set_time_limit((int) config('backup.timeout_seconds'));
            $handle = gzopen($gz, 'wb6');
            try {
                $counts = $this->dumper->dump($this->clone(self::SNAPSHOT), $database, fn (string $s) => gzwrite($handle, $s), config('backup.exclude_tables'));
            } finally {
                gzclose($handle);
                DB::purge(self::SNAPSHOT);
            }
            $this->finishFile($gz, $backup->path(), $key);

            $backup->update(['status' => Backup::COMPLETED, 'size' => filesize($backup->path()), 'sha256' => hash_file('sha256', $backup->path()),
                'table_count' => count($counts), 'row_counts' => $counts, 'completed_at' => now()]);
            $this->writeManifest($backup, $database);
            SystemHeartbeat::beat('backup', ['ok' => true, 'backup_id' => $backup->id, 'size' => $backup->size]);
            AuditLogger::log('backup.created', $backup, null, ['filename' => $name, 'type' => $type, 'size' => $backup->size,
                'encrypted' => $backup->encrypted, 'tables' => count($counts)]);
        } catch (\Throwable $e) {
            @unlink($gz);
            @unlink($backup->path());
            $backup->update(['status' => Backup::FAILED, 'error' => mb_substr(Redactor::text($e->getMessage()), 0, 490), 'completed_at' => now()]);
            SystemHeartbeat::beat('backup', ['ok' => false, 'backup_id' => $backup->id, 'error' => $backup->error]);
            AuditLogger::log('backup.failed', $backup, null, ['filename' => $name, 'error' => $backup->error]);

            return $backup;
        }

        if ($type === 'automatic') {
            $this->applyRetention();
        }

        return $backup->fresh();
    }

    private function register(string $source, string $originalName, string $type, ?User $by, ?string $note): Backup
    {
        $lower = strtolower($originalName);
        if (!preg_match('/\.(sql|sql\.gz|gz|enc)$/', $lower)) {
            throw new \RuntimeException('Only .sql or .sql.gz files (or encrypted backups from this page) can be uploaded.');
        }
        $key = BackupCrypto::key(config('backup.encryption_key'));
        $dir = $this->directory();
        $work = $dir . DIRECTORY_SEPARATOR . 'upload-' . bin2hex(random_bytes(4));
        $plain = $source;
        try {
            if (str_ends_with($lower, '.enc')) {
                if (!$key) {
                    throw new \RuntimeException('This file is encrypted but BACKUP_ENCRYPTION_KEY is not set.');
                }
                BackupCrypto::decryptFile($source, $plain = $work . '.gz', $key);
            }
            $plan = $this->restorer->check($plain);   // protiti statement jachai - bhul hole kichui rakha hoy na
            if (!array_intersect(['users', 'migrations'], $plan['tables'])) {
                throw new \RuntimeException('This file does not look like a backup of this software (no users or migrations table).');
            }

            // amader format: gzip (+ key thakle encrypted)
            $gz = $work . '.sql.gz';
            if (self::isGzip($plain)) {
                copy($plain, $gz);
            } else {
                $in = fopen($plain, 'rb');
                $out = gzopen($gz, 'wb6');
                while (!feof($in)) {
                    gzwrite($out, (string) fread($in, 1048576));
                }
                fclose($in);
                gzclose($out);
            }
            $name = 'upload-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql.gz' . ($key ? '.enc' : '');
            $backup = new Backup(['filename' => $name]);
            $this->finishFile($gz, $backup->path(), $key);

            $backup->fill(['status' => Backup::COMPLETED, 'trigger' => $type, 'note' => $note ? mb_substr($note, 0, 190) : null,
                'original_name' => mb_substr($originalName, 0, 190), 'encrypted' => (bool) $key, 'size' => filesize($backup->path()),
                'sha256' => hash_file('sha256', $backup->path()), 'table_count' => count($plan['tables']), 'started_at' => now(), 'completed_at' => now(),
                'created_by' => $by?->id])->save();
            $this->writeManifest($backup, $this->liveDatabase());
            AuditLogger::log('backup.uploaded', $backup, null, ['original_name' => $originalName, 'size' => $backup->size, 'statements' => $plan['statements']]);

            return $backup;
        } finally {
            foreach ([$work . '.gz', $work . '.sql.gz'] as $file) {
                @unlink($file);
            }
        }
    }

    // gz -> chudanto file (encrypt ba sorasori)
    private function finishFile(string $gz, string $final, ?string $key): void
    {
        if ($key) {
            BackupCrypto::encryptFile($gz, $final, $key);
            @unlink($gz);
        } else {
            rename($gz, $final);
        }
        @chmod($final, 0600);
    }

    // SqlReader porte pare emon path (encrypted hole temp e khule); [path, temp kina]
    private function readable(Backup $backup): array
    {
        if (!$backup->encrypted) {
            return [$backup->path(), false];
        }
        $key = BackupCrypto::key(config('backup.encryption_key'));
        if (!$key) {
            throw new \RuntimeException('This backup is encrypted but BACKUP_ENCRYPTION_KEY is not set.');
        }
        $tmp = $this->directory() . DIRECTORY_SEPARATOR . 'read-' . $backup->id . '-' . bin2hex(random_bytes(4)) . '.gz';
        BackupCrypto::decryptFile($backup->path(), $tmp, $key);

        return [$tmp, true];
    }

    // alada faka database e restore kore row gona; tarpor database muche
    private function restoreCheck(string $sql): array
    {
        $live = $this->liveDatabase();
        $target = config('backup.verify_database') ?: $live . '_restore_check';
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $target) || $target === $live) {
            throw new \RuntimeException('The check database name is invalid or is the live database.');
        }
        $admin = $this->clone(self::TARGET);
        $admin->statement("DROP DATABASE IF EXISTS `{$target}`");
        $admin->statement("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $check = $this->clone(self::CHECK, $target);
            $this->restorer->restore($check, $sql);
            $counts = [];
            foreach ($check->select("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$target]) as $row) {
                $counts[$row->t] = (int) $check->selectOne("SELECT COUNT(*) AS n FROM `{$row->t}`")->n;
            }
            ksort($counts);

            return $counts;
        } finally {
            DB::purge(self::CHECK);
            $admin->statement("DROP DATABASE IF EXISTS `{$target}`");
            DB::purge(self::TARGET);
        }
    }

    // ardhek table er kom hole bhul file (onno software/adhura export) - restore na
    private function assertWholeDatabase(array $tables): void
    {
        if (!in_array('users', $tables, true) || !in_array('migrations', $tables, true)) {
            throw new \RuntimeException('This file does not contain the users and migrations tables, so it is not a full backup of this software.');
        }
        $live = collect(DB::connection($this->liveConnection())->select("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
            [$this->liveDatabase()]))->pluck('t')->diff(config('backup.keep_on_restore'));
        $covered = $live->intersect($tables)->count();
        if ($live->count() && $covered < $live->count() / 2) {
            throw new \RuntimeException("The file has only {$covered} of the {$live->count()} tables of this database - it looks like a partial export. Nothing was changed.");
        }
    }

    private function afterRestore(): void
    {
        DB::purge(self::TARGET);
        $live = DB::connection($this->liveConnection());
        if (collect($live->select("SHOW TABLES LIKE 'audit_logs'"))->isNotEmpty()) {
            AuditLogProtection::install($live);   // table notun kore bananoy trigger chole gechhe
        }
        if (config('backup.migrate_after_restore')) {
            Artisan::call('migrate', ['--force' => true, '--database' => $this->liveConnection()]);
        }
        try {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            Cache::flush();
        } catch (\Throwable) {
        }
    }

    private function writeManifest(Backup $backup, string $database): void
    {
        file_put_contents($backup->path() . '.json', json_encode([
            'filename'   => $backup->filename,
            'type'       => $backup->trigger,
            'database'   => $database,
            'created_at' => $backup->completed_at?->toIso8601String(),
            'size'       => $backup->size,
            'sha256'     => $backup->sha256,
            'encrypted'  => $backup->encrypted,
            'tables'     => $backup->row_counts,
            'app'        => config('app.name'),
        ], JSON_PRETTY_PRINT));
    }

    private function locked(callable $callback): mixed
    {
        $lock = Cache::lock('backup-operation', (int) config('backup.timeout_seconds') + 60);
        if (!$lock->get()) {
            throw new \RuntimeException('Another backup or restore is running. Try again in a few minutes.');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function liveConnection(): string
    {
        return config('backup.connection') ?: config('database.default');
    }

    private function liveDatabase(): string
    {
        $name = trim((string) config('database.connections.' . $this->liveConnection() . '.database'));
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \RuntimeException('Invalid database name.');
        }

        return $name;
    }

    // alada connection (MySQL e DDL/snapshot cholti request er transaction e prova na)
    private function clone(string $name, ?string $database = null): Connection
    {
        config(["database.connections.{$name}" => ($database ? ['database' => $database] : [])
            + config('database.connections.' . $this->liveConnection())]);
        DB::purge($name);

        return DB::connection($name);
    }

    private static function isGzip(string $file): bool
    {
        $h = fopen($file, 'rb');
        $magic = fread($h, 2);
        fclose($h);

        return $magic === "\x1f\x8b";
    }
}
