<?php

namespace App\Http\Controllers;

use App\Models\NotificationChannel;
use App\Models\NotificationMessage;
use App\Models\NotificationProvider;
use App\Models\NotificationTemplate;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SMS/WhatsApp: message er itihash (abar pathano), provider (credential encrypted; form e gopon man dekhay na),
 * template. Pathano/chesta NotificationService ar queue job e.
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request)
    {
        $request->validate([
            'status'    => ['nullable', Rule::in(array_keys(NotificationMessage::LABELS))],
            'channel'   => ['nullable', Rule::in(array_keys(config('notifications.channels')))],
            'event'     => ['nullable', Rule::in(array_keys(config('notifications.events')))],
            'from_date' => 'nullable|date',
            'to_date'   => 'nullable|date',
        ]);

        return view('back-end.notifications.index', [
            'name'      => 'Notifications',
            'title'     => 'SMS & WhatsApp',
            'messages'  => $this->filtered($request)->with('customer:id,name')->orderByDesc('id')->paginate(30)->withQueryString(),
            'counts'    => NotificationMessage::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'providers' => NotificationProvider::where('is_active', true)->get()->keyBy('channel'),
            'switches'  => NotificationChannel::pluck('is_enabled', 'channel'),
        ]);
    }

    // SMS/WhatsApp main switch
    public function toggleChannel(Request $request, string $channel)
    {
        $request->validate(['enabled' => 'required|boolean']);
        try {
            $this->notifications->setChannel($channel, $request->boolean('enabled'), $request->user());
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }
        $label = config("notifications.channels.{$channel}");

        return back()->with('success', $request->boolean('enabled') ? "{$label} is on - customers will get messages." : "{$label} is off - no {$label} messages will be sent.");
    }

    public function retry(Request $request, $id)
    {
        try {
            $this->notifications->retry(NotificationMessage::findOrFail($id), $request->user());
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return back()->with('success', 'Message queued again.');
    }

    // filter er failed gulo abar (ekbare 200)
    public function retryFailed(Request $request)
    {
        $count = 0;
        foreach ($this->filtered($request)->where('status', NotificationMessage::FAILED)->orderBy('id')->limit(200)->get() as $message) {
            try {
                $this->notifications->retry($message, $request->user());
                $count++;
            } catch (\Exception) {
            }
        }

        return back()->with('success', "{$count} failed message(s) queued again.");
    }

    // ---- provider ----------------------------------------------------------------------------

    public function providers()
    {
        return view('back-end.notifications.providers', [
            'name'      => 'Notifications',
            'title'     => 'Message Providers',
            'providers' => NotificationProvider::orderBy('channel')->orderByDesc('is_active')->get(),
            'drivers'   => config('notifications.drivers'),
            'webhook'   => route('webhooks.whatsapp'),
        ]);
    }

    public function storeProvider(Request $request)
    {
        $data = $this->validateProvider($request);
        $driver = config("notifications.drivers.{$data['driver']}");

        $provider = NotificationProvider::create([
            'channel'     => $driver['channel'],
            'driver'      => $data['driver'],
            'name'        => $data['name'],
            'sender_id'   => $data['sender_id'] ?? null,
            'credentials' => $this->credentials($request, $driver, []),
            'is_active'   => false,
            'updated_by'  => $request->user()->id,
        ]);
        AuditLogger::log('notification.provider_created', $provider, null, $provider->only(['channel', 'driver', 'name', 'sender_id']));

        return back()->with('success', 'Provider saved. Send a test message, then activate it.');
    }

    public function updateProvider(Request $request, $id)
    {
        $provider = NotificationProvider::findOrFail($id);
        $request->validate(['name' => 'required|string|max:100', 'sender_id' => 'nullable|string|max:50']);
        $driver = config("notifications.drivers.{$provider->driver}");

        $old = $provider->only(['name', 'sender_id']);
        $provider->update(['name' => $request->name, 'sender_id' => $request->sender_id,
            'credentials' => $this->credentials($request, $driver, $provider->credentials ?? []), 'updated_by' => $request->user()->id]);
        // credential er man audit e na - shudhu kon gulo bodlalo
        AuditLogger::log('notification.provider_updated', $provider, $old, $provider->only(['name', 'sender_id'])
            + ['credentials_changed' => array_keys(array_filter($request->input('credentials', []), fn ($v) => trim((string) $v) !== ''))]);

        return back()->with('success', 'Provider updated.');
    }

    public function toggleProvider(Request $request, $id)
    {
        $provider = NotificationProvider::findOrFail($id);
        $this->notifications->activate($provider, !$provider->is_active);
        AuditLogger::log('notification.provider_' . ($provider->is_active ? 'activated' : 'deactivated'), $provider);

        if (!$provider->is_active && NotificationChannel::isEnabled($provider->channel)
            && !NotificationProvider::where('channel', $provider->channel)->where('is_active', true)->exists()) {
            $label = config("notifications.channels.{$provider->channel}");

            return back()->with('error', "Provider deactivated, but {$label} is still on - {$label} messages will fail. Activate another provider or turn {$label} off.");
        }

        return back()->with('success', $provider->is_active ? 'Provider activated.' : 'Provider deactivated.');
    }

    // asol pathano - simulation na
    public function testProvider(Request $request, $id)
    {
        $request->validate(['phone' => 'required|string|max:20']);
        $result = $this->notifications->test(NotificationProvider::findOrFail($id), $request->phone);

        return back()->with($result->ok ? 'success' : 'error', $result->ok ? 'The provider accepted the test message.' : 'Test failed: ' . $result->error);
    }

    // ---- template ----------------------------------------------------------------------------

    public function templates()
    {
        return view('back-end.notifications.templates', [
            'name'      => 'Notifications',
            'title'     => 'Message Templates',
            'templates' => NotificationTemplate::orderBy('event')->orderBy('channel')->get()->groupBy('event'),
            'events'    => config('notifications.events'),
        ]);
    }

    public function updateTemplate(Request $request, $id)
    {
        $template = NotificationTemplate::findOrFail($id);
        $request->validate(['body' => 'required|string|max:1000']);
        $unknown = array_diff(self::placeholders($request->body), config("notifications.events.{$template->event}.vars", []));
        if ($unknown) {
            return back()->with('error', 'Unknown placeholder(s): {' . implode('}, {', $unknown) . '}');
        }

        $old = $template->only(['body', 'is_enabled']);
        $template->update(['body' => trim($request->body), 'is_enabled' => $request->boolean('is_enabled'), 'updated_by' => $request->user()->id]);
        AuditLogger::log('notification.template_updated', $template, $old, $template->only(['body', 'is_enabled']));

        return back()->with('success', 'Template saved.');
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function filtered(Request $request)
    {
        $term = trim((string) $request->q);

        return NotificationMessage::query()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->channel, fn ($q, $c) => $q->where('channel', $c))
            ->when($request->event, fn ($q, $e) => $q->where('event', $e))
            ->when($request->from_date, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to_date, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('recipient', 'like', '%' . preg_replace('/\D+/', '', $term) . '%')
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%"))));
    }

    private function validateProvider(Request $request): array
    {
        return $request->validate([
            'driver'    => ['required', Rule::in(array_keys(config('notifications.drivers')))],
            'name'      => 'required|string|max:100',
            'sender_id' => 'nullable|string|max:50',
            'credentials'   => 'nullable|array',
            'credentials.*' => 'nullable|string|max:1000',
        ]);
    }

    // faka field = ager man thakbe (form e gopon man dekhano hoy na)
    private function credentials(Request $request, array $driver, array $current): array
    {
        foreach (array_keys($driver['fields']) as $field) {
            $value = trim((string) $request->input("credentials.{$field}", ''));
            if ($value !== '') {
                $current[$field] = $value;
            }
        }

        return $current;
    }

    private static function placeholders(string $body): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $body, $m);

        return array_unique($m[1]);
    }
}
