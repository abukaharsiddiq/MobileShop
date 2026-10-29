<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SystemAlert;
use App\Models\SystemError;
use App\Models\SystemHeartbeat;
use App\Services\AuditChain;
use App\Services\AuditLogger;
use App\Services\Monitoring\ErrorRecorder;
use App\Services\Monitoring\MonitoringService;
use App\Support\Redactor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Audit (gopon tottho na, append-only, hash chain, login/403, itihash), monitoring (error, failed job, alert ekbar),
 * (backup: BackupTest).
 */
class AuditMonitoringTest extends SecurityTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups-' . Str::random(8));
        config(['backup.path' => $this->dir, 'backup.encryption_key' => null, 'mail.default' => 'array', 'monitoring.alert_emails' => ['ops@example.com']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ---- audit --------------------------------------------------------------------------------

    public function test_audit_log_never_stores_passwords_secrets_or_id_numbers(): void
    {
        $this->actingAs($admin = $this->admin(['branch_id' => $this->ids['branchA']]));
        $log = AuditLogger::log('test.secret', null,
            ['password' => 'Pa55word!', 'nid_no' => '1990123456789', 'name' => 'Rahim'],
            ['provider' => ['api_key' => 'sk-live-123', 'sender' => 'SHOP'], 'credentials' => ['x' => 1], 'access_token' => 'EAAG-token', 'payment_account' => '01711111111', 'note' => 'ok']);

        $raw = DB::table('audit_logs')->where('id', $log->id)->first();
        foreach (['Pa55word!', '1990123456789', 'sk-live-123', 'EAAG-token', '01711111111'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw->old_values . $raw->new_values);
        }
        $this->assertSame(['Rahim', 'SHOP', 'ok', Redactor::MASK], [$log->old_values['name'], $log->new_values['provider']['sender'], $log->new_values['note'], $log->new_values['credentials']]);
        $this->assertSame([$admin->id, $admin->name, $this->ids['branchA']], [$raw->user_id, $raw->user_name, $raw->branch_id]);

        $text = Redactor::text('Failed: password=hunter2&user=a {"api_key":"abc123"} Authorization: Bearer eyJhbGciOi.xyz');
        foreach (['hunter2', 'abc123', 'eyJhbGciOi'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertSame('/sale/123', Redactor::url('/sale/123?token=abc'));

        // staff profile (NID, payment account) bodlale-o audit e man na
        $this->actingAs($admin)->post(route('permission.store'), ['name' => 'test perm', 'group_name' => 'Test']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'permission.created']);
    }

    public function test_audit_log_is_append_only_and_tampering_is_detected(): void
    {
        $this->actingAs($this->admin());
        AuditLogger::log('test.one', null, null, ['a' => 1]);
        $log = AuditLogger::log('test.two', null, ['amount' => '100.00'], ['amount' => '90.00']);

        // app theke bodlano/muchhano na
        $this->assertThrows(fn () => $log->update(['action' => 'x']), \LogicException::class);
        $this->assertThrows(fn () => $log->delete(), \LogicException::class);
        // edit/delete er kono route nei
        $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($n) => str_starts_with($n, 'audit.'))->sort()->values()->all();
        $this->assertSame(['audit.export', 'audit.history', 'audit.index', 'audit.show', 'audit.verify'], $routes);

        // database trigger: hash chara row er-o man bodlano jay na; seal er por kichui na; muchhano na
        $this->assertThrows(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'forged']), QueryException::class, 'append-only');
        $chain = app(AuditChain::class);
        $this->assertGreaterThan(0, $chain->seal());
        $this->assertTrue($chain->verify()['ok']);
        $this->assertThrows(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['new_values' => json_encode(['amount' => '1'])]), QueryException::class, 'append-only');
        $this->assertThrows(fn () => DB::table('audit_logs')->where('id', $log->id)->delete(), QueryException::class, 'append-only');

        // trigger er baire (jemon DB te sorasori notun row) - chain dhore
        $last = DB::table('audit_logs')->orderByDesc('id')->first();
        DB::table('audit_logs')->insert(['action' => 'forged.row', 'prev_hash' => $last->hash, 'hash' => str_repeat('a', 64), 'created_at' => now()]);
        $result = $chain->verify();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('forged.row', implode(' ', $result['problems']));
        $this->assertSame('critical', app(MonitoringService::class)->checks()['audit_chain']['status']);
    }

    public function test_tail_deletion_is_detected_through_the_chain_head(): void
    {
        $this->actingAs($this->admin());
        AuditLogger::log('test.one');
        $chain = app(AuditChain::class);
        $chain->seal();
        $this->assertTrue($chain->verify()['ok']);
        // shesh row "muche" fela hoyeche dhore nei: head er row khuje na pele
        SystemHeartbeat::beat(AuditChain::HEARTBEAT, ['head_id' => DB::table('audit_logs')->max('id') + 50, 'head_hash' => str_repeat('b', 64)]);
        $this->assertFalse($chain->verify()['ok']);
    }

    public function test_login_logout_failed_login_and_access_denied_are_logged(): void
    {
        $user = $this->staff('salesman', ['email' => 'sales@example.com', 'branch_id' => $this->ids['branchA']]);

        $this->post('/login', ['email' => 'sales@example.com', 'password' => 'wrong-password']);
        $failed = AuditLog::where('action', 'auth.failed')->firstOrFail();
        $this->assertSame(['sales@example.com', $user->id], [$failed->new_values['email'], $failed->auditable_id]);
        $this->assertStringNotContainsString('wrong-password', DB::table('audit_logs')->where('id', $failed->id)->value('new_values'));
        $this->assertNotNull($failed->ip_address);

        $this->post('/login', ['email' => 'sales@example.com', 'password' => 'password']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id, 'url' => '/login', 'method' => 'POST']);

        $this->get(route('audit.index'))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.access_denied', 'user_id' => $user->id]);

        $this->post('/logout');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
        $this->assertGuest();
    }

    public function test_record_history_combines_audit_stock_and_journal_with_before_after(): void
    {
        $admin = $this->admin(['branch_id' => $this->ids['branchA']]);
        $this->actingAs($admin);

        $category = Category::create(['name' => 'Chargers', 'slug' => 'chargers', 'status' => 1]);
        $category->update(['name' => 'Fast Chargers']);
        $log = AuditLog::where('action', 'category.updated')->firstOrFail();
        $this->assertSame([['Chargers', 'Fast Chargers']], array_values($log->changes()));

        $brand = DB::table('brands')->insertGetId(['brand_name' => 'Anker', 'slug' => 'anker', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['type' => 'accessory', 'product_name' => 'Charger', 'model_number' => 'CH', 'category_id' => $category->id, 'brand_id' => $brand,
            'purchase_price' => 100, 'sale_price' => 300, 'status' => 1]);
        $stock = MainStock::create(['product_id' => $product->id, 'quantity' => 0, 'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => 300, 'sku' => 'CH']);
        $this->post(route('purchase.store'), ['supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $product->id, 'main_stock_id' => $stock->id, 'qty' => 5, 'price' => 100, 'unit_discount' => 0, 'imeis' => '']]])->assertSessionMissing('error');
        $purchase = Purchase::latest('id')->firstOrFail();

        $this->get(route('audit.history', ['Category', $category->id]))->assertOk()->assertSee('category.updated')->assertSee('Fast Chargers');
        $page = $this->get(route('audit.history', ['Purchase', $purchase->id]))->assertOk();
        if (DB::table('stock_movements')->where('reference_type', 'Purchase')->where('reference_id', $purchase->id)->exists()) {
            $page->assertSee('stock.');
        }
        if (DB::table('journal_entries')->where('source_type', 'Purchase')->where('source_id', $purchase->id)->exists()) {
            $page->assertSee('journal.');
        }
        $this->get(route('audit.history', ['MainStock', $stock->id]))->assertOk()->assertSee('stock.');
        $this->get(route('audit.history', ['NotAModel', 1]))->assertNotFound();

        $this->get(route('audit.index', ['area' => 'category']))->assertOk()->assertSee('category.updated')->assertDontSee('purchase.submitted');
        $this->get(route('audit.show', $log->id))->assertOk()->assertSee('Fast Chargers');
        $csv = $this->get(route('audit.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('category.updated', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.exported']);

        // shudhu admin (ba "read audit log")
        $this->actingAs($this->staff('manager'))->get(route('audit.index'))->assertForbidden();
    }

    // ---- monitoring ---------------------------------------------------------------------------

    public function test_errors_and_failed_jobs_are_recorded_and_alerts_are_sent_once(): void
    {
        $recorder = app(ErrorRecorder::class);
        $e = new \RuntimeException('Payment gateway refused: api_key=sk_live_999 password=hunter2');
        $recorder->record($e);
        $recorder->record($e);
        $error = SystemError::sole();
        $this->assertSame(2, $error->occurrences);
        $this->assertStringNotContainsString('sk_live_999', $error->message);
        $this->assertStringNotContainsString('hunter2', $error->message);
        report(new \LogicException('reported through the handler'));
        $this->assertSame(2, SystemError::count());

        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'notifications',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendNotificationMessage', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]),
            'exception' => "RuntimeException: token=abc123 boom\n#0 trace", 'failed_at' => now()]);

        $monitoring = app(MonitoringService::class);
        $checks = $monitoring->checks();
        $this->assertSame(['critical', 'warning', 'warning'], [$checks['failed_jobs']['status'], $checks['backup']['status'], $checks['errors']['status']]);   // automatic backup bondho = warning

        $monitoring->run();
        $mails = collect(app('mailer')->getSymfonyTransport()->messages());
        $this->assertTrue($mails->contains(fn ($m) => str_contains($m->getOriginalMessage()->getSubject(), 'Failed jobs')));
        $this->assertSame('ops@example.com', $mails->first()->getOriginalMessage()->getTo()[0]->getAddress());
        $count = $mails->count();
        $alert = SystemAlert::open()->where('check_key', 'failed_jobs')->sole();
        $this->assertStringContainsString('emailed', $alert->notify_result);

        // abar chalale ekoi alert abar mail na (cooldown)
        $monitoring->run();
        $this->assertSame($count, collect(app('mailer')->getSymfonyTransport()->messages())->count());

        // page: admin dekhe, gopon tottho na; retry kore failed job queue e
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('monitoring.index'))->assertOk()->assertSee('SendNotificationMessage')->assertDontSee('abc123')->assertDontSee('hunter2');
        $this->actingAs($this->staff('manager'))->get(route('monitoring.index'))->assertForbidden();
        $uuid = DB::table('failed_jobs')->value('uuid');
        $this->actingAs($admin)->post(route('monitoring.jobs.forget', $uuid))->assertSessionMissing('error');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'monitoring.job_removed']);

        // thik hole resolved + janano
        $monitoring->run();
        $this->assertSame(SystemAlert::RESOLVED, $alert->fresh()->status);
        $this->assertTrue(collect(app('mailer')->getSymfonyTransport()->messages())->contains(fn ($m) => str_contains($m->getOriginalMessage()->getSubject(), '[RESOLVED]')));

        $this->actingAs($admin)->post(route('monitoring.errors.resolve', $error->id));
        $this->assertNotNull($error->fresh()->resolved_at);
        $recorder->record($e);   // abar holo - abar khola
        $this->assertNull($error->fresh()->resolved_at);
    }
}
