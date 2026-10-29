<?php

namespace Tests\Feature\Notifications;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\MainStock;
use App\Models\NotificationChannel;
use App\Models\NotificationMessage;
use App\Models\NotificationProvider;
use App\Models\NotificationTemplate;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ReminderService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Security\SecurityTestCase;

/**
 * SMS & WhatsApp: event theke message (ekbar), provider na thakle failed (bhuya sent na), abar chesta, shommoti,
 * WhatsApp template + delivery webhook, reminder, credential encrypted, permission. Kono asol provider e request jay na.
 */
class NotificationTest extends SecurityTestCase
{
    private User $admin;
    private MainStock $stock;
    private int $token = 0;
    private array $replies = [];          // ei test er por por uttor
    private ?\Closure $default = null;   // tarpor protiti request e

    protected function setUp(): void
    {
        parent::setUp();
        // ekta-i fake (por por fake dile ager ta age mile): queue, na thakle default, na hole 500
        Http::preventStrayRequests();
        Http::fake(fn () => array_shift($this->replies) ?? ($this->default ? ($this->default)() : Http::response('no reply configured', 500)));
        // main switch default bondho; ei test gulo chalu dhore (bondho er test alada)
        NotificationChannel::query()->update(['is_enabled' => true]);

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Chargers', 'slug' => 'chargers']);
        $brand = $id('brands', ['brand_name' => 'Anker', 'slug' => 'anker']);
        $product = Product::create(['type' => 'accessory', 'product_name' => 'Charger', 'model_number' => 'CH', 'category_id' => $cat, 'brand_id' => $brand,
            'purchase_price' => 100, 'sale_price' => 300, 'status' => 1, 'warranty_months' => 1]);
        $this->stock = MainStock::create(['product_id' => $product->id, 'quantity' => 0, 'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => 300, 'sku' => 'CH']);
        $this->admin = $this->admin(['branch_id' => $this->ids['branchA']]);
        $this->actingAs($this->admin)->post(route('purchase.store'), ['supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $product->id, 'main_stock_id' => $this->stock->id, 'qty' => 50, 'price' => 100, 'unit_discount' => 0, 'imeis' => '']]]);
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function smsProvider(bool $active = true): NotificationProvider
    {
        return NotificationProvider::create(['channel' => 'sms', 'driver' => 'bulksmsbd', 'name' => 'BulkSMSBD', 'sender_id' => 'SHOP',
            'credentials' => ['api_key' => 'secret-key-1234'], 'is_active' => $active]);
    }

    private function smsAccepted(): void
    {
        $this->default = fn () => Http::response(['response_code' => 202, 'message_id' => 'm-1', 'success_message' => 'SMS Submitted'], 200);
    }

    private function sell(int $qty = 1, ?float $paid = null): Sale
    {
        $total = $qty * 300;
        $this->actingAs($this->admin)->post(route('sale.store'), ['customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(),
            'stock_ids' => [$this->stock->id], 'qtys' => [$qty], 'sale_prices' => [300], 'item_discounts' => [0], 'imeis' => [[]],
            'payments' => [['method' => 'Cash', 'amount' => $paid ?? $total]], 'sale_token' => 's-' . ++$this->token])->assertSessionMissing('error');

        return Sale::latest('id')->firstOrFail();
    }

    private function message(string $event, string $channel = 'sms'): NotificationMessage
    {
        return NotificationMessage::where('event', $event)->where('channel', $channel)->latest('id')->firstOrFail();
    }

    // ---- sending ------------------------------------------------------------------------------

    public function test_sale_confirmation_goes_out_once_through_the_queue(): void
    {
        $this->smsProvider();
        $this->smsAccepted();
        $sale = $this->sell(2);

        $m = $this->message('sale_confirmation');
        $this->assertSame([NotificationMessage::SENT, '8801800000001', 'm-1', 1], [$m->status, $m->recipient, $m->provider_message_id, $m->attempts]);
        $this->assertStringContainsString($sale->invoice_no, $m->body);
        $this->assertStringContainsString('Dear Customer', $m->body);
        Http::assertSent(fn (HttpRequest $r) => $r['number'] === '8801800000001' && $r['senderid'] === 'SHOP' && $r['api_key'] === 'secret-key-1234'
            && str_contains($r['message'], $sale->invoice_no));

        // ekoi event abar: notun message na; WhatsApp e raji na thakay WhatsApp na
        $this->assertCount(0, app(NotificationService::class)->notify('sale_confirmation', Customer::find($this->ids['customer']), $sale, []));
        $this->assertSame(1, NotificationMessage::where('event', 'sale_confirmation')->count());
        $this->assertSame(0, NotificationMessage::where('channel', 'whatsapp')->count());
        Http::assertSentCount(1);
    }

    public function test_without_a_provider_the_message_fails_and_can_be_retried_later(): void
    {
        $this->sell();
        $m = $this->message('sale_confirmation');
        $this->assertSame(NotificationMessage::FAILED, $m->status);
        $this->assertStringContainsString('No active SMS provider', $m->last_error);
        $this->assertNull($m->sent_at);
        Http::assertNothingSent();

        $this->smsProvider();
        $this->smsAccepted();
        $this->actingAs($this->admin)->post(route('notifications.retry', $m->id))->assertSessionMissing('error');
        $this->assertSame([NotificationMessage::SENT, 2], [$m->fresh()->status, $m->fresh()->attempts]);
        $this->actingAs($this->admin)->post(route('notifications.retry', $m->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'Only failed'));
    }

    public function test_temporary_errors_are_retried_and_permanent_errors_stop(): void
    {
        $this->smsProvider();
        $this->replies = [Http::response('busy', 500), Http::response('busy', 503), Http::response(['response_code' => 202], 200)];
        $this->sell();
        $m = $this->message('sale_confirmation');
        $this->assertSame([NotificationMessage::SENT, 3], [$m->status, $m->attempts]);

        // shob bar 500: 3 bar chesta, tarpor failed
        $this->default = fn () => Http::response('down', 500);
        $this->sell();
        $this->assertSame([NotificationMessage::FAILED, 3], [$this->message('sale_confirmation')->status, $this->message('sale_confirmation')->attempts]);

        // provider "safol na" (bhul key): ekbar-i
        $this->default = fn () => Http::response(['response_code' => 1002, 'error_message' => 'Invalid API key'], 200);
        $this->sell();
        $last = $this->message('sale_confirmation');
        $this->assertSame([NotificationMessage::FAILED, 1], [$last->status, $last->attempts]);
        $this->assertStringContainsString('Invalid API key', $last->last_error);
    }

    public function test_consent_and_mobile_number_are_respected(): void
    {
        $this->smsProvider();
        $this->smsAccepted();
        Customer::whereKey($this->ids['customer'])->update(['notify_sms' => false]);
        $this->sell();
        $m = $this->message('sale_confirmation');
        $this->assertSame(NotificationMessage::SKIPPED, $m->status);
        $this->assertStringContainsString('turned off', $m->last_error);
        Http::assertNothingSent();

        // shommoti fire pele hate pathano jay
        Customer::whereKey($this->ids['customer'])->update(['notify_sms' => true]);
        $this->actingAs($this->admin)->post(route('notifications.retry', $m->id))->assertSessionMissing('error');
        $this->assertSame(NotificationMessage::SENT, $m->fresh()->status);

        // number thik na
        $bad = Customer::create(['name' => 'Landline', 'phone' => '029876543', 'status' => 1]);
        $this->assertSame(NotificationMessage::SKIPPED, app(NotificationService::class)->notify('sale_confirmation', $bad, $bad, [])->first()->status);

        // checkbox chara form (POS) shommoti bodlay na; customer form bodlay
        $this->actingAs($this->admin)->post(route('customer.store'), ['name' => 'Quick', 'phone' => '01711111111'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) Customer::where('phone', '01711111111')->value('notify_sms'));
        $this->actingAs($this->admin)->post(route('customer.store'), ['name' => 'Form', 'phone' => '01722222222', 'consent_form' => 1, 'notify_whatsapp' => 1]);
        $this->assertSame([0, 1], array_map('intval', array_values((array) DB::table('customers')->where('phone', '01722222222')->first(['notify_sms', 'notify_whatsapp']))));
    }

    public function test_whatsapp_cloud_template_and_signed_delivery_webhook(): void
    {
        NotificationTemplate::where('channel', 'sms')->update(['is_enabled' => false]);
        Customer::whereKey($this->ids['customer'])->update(['notify_whatsapp' => true]);
        NotificationProvider::create(['channel' => 'whatsapp', 'driver' => 'whatsapp_cloud', 'name' => 'Meta', 'is_active' => true,
            'credentials' => ['phone_number_id' => '1055', 'access_token' => 'EAAG-token', 'template_name' => 'shop_update', 'template_language' => 'en',
                'app_secret' => 'app-secret', 'verify_token' => 'verify-me']]);
        $this->default = fn () => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200);

        $this->sell();
        $m = $this->message('sale_confirmation', 'whatsapp');
        $this->assertSame([NotificationMessage::SENT, 'wamid.ABC'], [$m->status, $m->provider_message_id]);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/1055/messages') && $r->hasHeader('Authorization', 'Bearer EAAG-token')
            && $r['type'] === 'template' && $r['template']['name'] === 'shop_update' && $r['template']['components'][0]['parameters'][0]['text'] === $m->body);

        // webhook: verify, signature chara/bhul 403, sothik hole delivered -> read (pichhone na)
        $this->get(route('webhooks.whatsapp', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'verify-me', 'hub_challenge' => '42']))->assertOk()->assertSee('42');
        $this->get(route('webhooks.whatsapp', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'wrong', 'hub_challenge' => '42']))->assertForbidden();

        $payload = fn ($status) => json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.ABC', 'status' => $status]]]]]]]]);
        $post = fn ($body, $secret) => $this->call('POST', route('webhooks.whatsapp.receive'), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, $secret)], $body);

        $post($payload('delivered'), 'not-the-secret')->assertForbidden();
        $this->assertSame(NotificationMessage::SENT, $m->fresh()->status);
        $post($payload('read'), 'app-secret')->assertOk();
        $post($payload('delivered'), 'app-secret')->assertOk();
        $this->assertSame(NotificationMessage::READ, $m->fresh()->status);
        $this->assertNotNull($m->fresh()->delivered_at);
    }

    // ---- events / reminders -------------------------------------------------------------------

    public function test_payment_repair_and_daily_reminders_send_once(): void
    {
        $this->smsProvider();
        $this->smsAccepted();

        // baki adai
        $sale = $this->sell(2, 100);
        $this->actingAs($this->admin)->post(route('sale.payment'), ['sale_id' => $sale->id, 'paid_amount' => 200, 'payment_date' => now()->toDateString(),
            'payment_method' => 'Cash', 'request_token' => 'pay-1'])->assertSessionMissing('error');
        $this->assertStringContainsString('200.00', $this->message('payment_received')->body);

        // repair job khola
        $this->actingAs($this->admin)->post(route('repair.store'), ['customer_id' => $this->ids['customer'], 'device_model' => 'Samsung A05', 'imei_no' => '',
            'imei_missing_reason' => 'Dead', 'complaint' => 'No power', 'request_token' => 'job-1'])->assertSessionMissing('error');
        $this->assertStringContainsString('RJ-', $this->message('repair_received')->body);

        // kisti: kal due -> ekbar; abar chalale na; overdue hole notun dhap e ekbar
        $installment = Installment::create(['sale_id' => $sale->id, 'customer_id' => $this->ids['customer'], 'installment_no' => 1, 'amount' => 300,
            'paid_amount' => 0, 'due_date' => now()->addDay()->toDateString(), 'status' => Installment::STATUS_PENDING]);
        $reminders = app(ReminderService::class);
        $this->assertSame(1, $reminders->installments());
        $this->assertSame(0, $reminders->installments());
        $this->assertSame(1, (int) $installment->fresh()->reminder_count);
        $installment->update(['due_date' => now()->subDays(9)->toDateString()]);
        $this->assertSame(1, $reminders->installments());
        $this->assertSame(0, $reminders->installments());

        // warranty (charger 1 mash): shesh hote 40 diner bhitore hole ekbar
        config(['notifications.warranty_days_before' => 40]);
        $this->assertGreaterThan(0, $reminders->warranties());
        $this->assertSame(0, $reminders->warranties());
        $this->assertSame(NotificationMessage::SENT, $this->message('warranty_expiry')->status);
        $this->artisan('notifications:reminders')->assertSuccessful();
    }

    // ---- main switch --------------------------------------------------------------------------

    public function test_when_sms_and_whatsapp_are_off_the_software_works_as_usual(): void
    {
        NotificationChannel::query()->update(['is_enabled' => false]);
        $this->smsProvider();
        $this->smsAccepted();
        $customer = Customer::find($this->ids['customer']);
        $customer->update(['notify_whatsapp' => true]);

        // bikri, baki adai, repair, reminder - shob kaj kore, kono message row/request na
        $sale = $this->sell(2, 100);
        $this->actingAs($this->admin)->post(route('sale.payment'), ['sale_id' => $sale->id, 'paid_amount' => 200, 'payment_date' => now()->toDateString(),
            'payment_method' => 'Cash', 'request_token' => 'pay-off'])->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('repair.store'), ['customer_id' => $customer->id, 'device_model' => 'Samsung A05', 'imei_no' => '',
            'imei_missing_reason' => 'Dead', 'complaint' => 'No power', 'request_token' => 'job-off'])->assertSessionMissing('error');
        $installment = Installment::create(['sale_id' => $sale->id, 'customer_id' => $customer->id, 'installment_no' => 1, 'amount' => 300,
            'paid_amount' => 0, 'due_date' => now()->addDay()->toDateString(), 'status' => Installment::STATUS_PENDING]);
        $this->assertSame(0, app(ReminderService::class)->installments());
        $this->assertSame(0, (int) $installment->fresh()->reminder_count);
        $this->artisan('notifications:reminders')->expectsOutputToContain('turned off')->assertSuccessful();
        $this->assertSame(0, NotificationMessage::count());
        Http::assertNothingSent();
        $this->actingAs($this->admin)->get(route('notifications.index'))->assertOk()->assertSee('Turn on')->assertDontSee('no SMS provider is active');

        // chalu: active provider chara na
        $this->actingAs($this->admin)->post(route('notifications.channels.toggle', 'whatsapp'), ['enabled' => 1])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'WhatsApp provider first'));
        $this->assertFalse(NotificationChannel::isEnabled('whatsapp'));
        $this->actingAs($this->admin)->post(route('notifications.channels.toggle', 'sms'), ['enabled' => 1])->assertSessionMissing('error');
        $this->assertTrue(NotificationChannel::isEnabled('sms'));
        $this->assertSame(1, AuditLog::where('action', 'notification.channel_enabled')->count());

        // shudhu SMS chalu: SMS jay, WhatsApp na
        $this->sell();
        $this->assertSame(NotificationMessage::SENT, $this->message('sale_confirmation')->status);
        $this->assertSame(0, NotificationMessage::where('channel', 'whatsapp')->count());

        // queue e thakar majhe bondho: pathano na (skipped), abar pathano jay na jotokkhon bondho
        $queued = NotificationMessage::create(['dedupe_key' => 'manual:1', 'event' => 'sale_confirmation', 'channel' => 'sms', 'customer_id' => $customer->id,
            'recipient' => '8801800000001', 'body' => 'x', 'status' => NotificationMessage::QUEUED]);
        $this->actingAs($this->admin)->post(route('notifications.channels.toggle', 'sms'), ['enabled' => 0])->assertSessionMissing('error');
        $sent = Http::recorded()->count();
        app(NotificationService::class)->deliver($queued->id);
        $this->assertSame(NotificationMessage::SKIPPED, $queued->fresh()->status);
        $this->assertSame($sent, Http::recorded()->count());
        $this->actingAs($this->admin)->post(route('notifications.retry', $queued->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'turned off'));
    }

    // ---- security -----------------------------------------------------------------------------

    public function test_credentials_are_encrypted_hidden_and_audited_without_values(): void
    {
        $provider = $this->smsProvider(false);
        $raw = DB::table('notification_providers')->where('id', $provider->id)->value('credentials');
        $this->assertStringNotContainsString('secret-key-1234', $raw);
        $this->assertArrayNotHasKey('credentials', $provider->toArray());

        $this->actingAs($this->admin)->get(route('notifications.providers'))->assertOk()->assertDontSee('secret-key-1234')->assertSee('1234');

        // faka secret field = ager man thake; audit e man na
        $this->actingAs($this->admin)->post(route('notifications.providers.update', $provider->id), ['name' => 'Renamed', 'sender_id' => 'SHOP', 'credentials' => ['api_key' => '']]);
        $this->assertSame('secret-key-1234', $provider->fresh()->credential('api_key'));
        $this->actingAs($this->admin)->post(route('notifications.providers.update', $provider->id), ['name' => 'Renamed', 'sender_id' => 'SHOP', 'credentials' => ['api_key' => 'new-key-9999']]);
        $this->assertSame('new-key-9999', $provider->fresh()->credential('api_key'));
        $this->assertStringNotContainsString('new-key-9999', AuditLog::where('action', 'notification.provider_updated')->latest('id')->first()->toJson());

        // ek channel e ekta active; test e asol request (simulation na)
        $other = $this->smsProvider(true);
        $this->actingAs($this->admin)->post(route('notifications.providers.toggle', $provider->id));
        $this->assertSame([true, false], [$provider->fresh()->is_active, $other->fresh()->is_active]);
        $this->default = fn () => Http::response(['response_code' => 1003, 'error_message' => 'Invalid Sender ID'], 200);
        $this->actingAs($this->admin)->post(route('notifications.providers.test', $provider->id), ['phone' => '01800000001'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Invalid Sender ID'));
    }

    public function test_permissions_and_template_validation(): void
    {
        $this->actingAs($this->staff('salesman'))->get(route('notifications.index'))->assertForbidden();
        $manager = $this->staff('manager');
        $this->actingAs($manager)->get(route('notifications.index'))->assertOk()->assertSee('no SMS provider is active');
        $this->actingAs($manager)->get(route('notifications.providers'))->assertForbidden();
        $this->actingAs($manager)->post(route('notifications.retry', 1))->assertForbidden();
        $this->actingAs($manager)->post(route('notifications.channels.toggle', 'sms'), ['enabled' => 0])->assertForbidden();

        $template = NotificationTemplate::where('event', 'sale_confirmation')->where('channel', 'sms')->firstOrFail();
        $this->actingAs($this->admin)->post(route('notifications.templates.update', $template->id), ['body' => 'Hi {customer} {password}', 'is_enabled' => 1])
            ->assertSessionHas('error', fn ($e) => str_contains($e, '{password}'));
        $this->actingAs($this->admin)->post(route('notifications.templates.update', $template->id), ['body' => 'Thanks {customer}, invoice {invoice}'])->assertSessionMissing('error');
        $this->assertFalse($template->fresh()->is_enabled);   // checkbox na = bondho

        $this->smsProvider();
        $this->smsAccepted();
        $this->sell();
        $this->assertSame(0, NotificationMessage::where('event', 'sale_confirmation')->where('channel', 'sms')->count());
        $this->actingAs($this->admin)->get(route('notifications.templates'))->assertOk();
    }
}
