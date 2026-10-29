<?php

namespace App\Services;

use App\Jobs\SendNotificationMessage;
use App\Models\Customer;
use App\Models\NotificationChannel;
use App\Models\NotificationMessage;
use App\Models\NotificationProvider;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\Gateway;
use App\Services\Notifications\GatewayResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SMS/WhatsApp: event theke message toiri (template, shommoti, number), queue e pathano, chesta/abar chesta, status.
 *
 * - Main switch (NotificationChannel): SMS/WhatsApp bondho thakle kichui hoy na - kono row na, queue na.
 * - notify(): business kaj er bhitore dake; message row oi transaction e (sale batil hole message o na), pathano
 *   commit er por queue e. Kono bhul business kaj atkay na (report kore chhere dey).
 * - dedupe_key unique: ekoi event + channel + reference (+ dhap) e duibar message na.
 * - Provider na thakle/credential bhul hole "failed" (karon shoho) - kokhono bhuya "sent" na.
 * - Network/5xx hole kichukkhon por abar (max_attempts), tarpor failed; failed hate abar pathano jay.
 */
class NotificationService
{
    /**
     * @param array<string, scalar> $vars template er placeholder
     * @return Collection<int, NotificationMessage> notun toiri hoya message (channel prati)
     */
    public function notify(string $event, ?Customer $customer, ?Model $reference, array $vars, string $stage = ''): Collection
    {
        try {
            $created = collect();
            $channels = NotificationChannel::enabled();
            if (!$channels) {
                return $created;   // SMS/WhatsApp bondho: software sadharon vabe chole
            }
            $templates = NotificationTemplate::where('event', $event)->whereIn('channel', $channels)->where('is_enabled', true)->get()->keyBy('channel');

            foreach ($templates as $channel => $template) {
                // WhatsApp shudhu raji customer ke; SMS (lenden er khobor) shommoti bondho na korle
                $consent = $customer && ($channel === 'whatsapp' ? $customer->notify_whatsapp : $customer->notify_sms);
                if ($channel === 'whatsapp' && !$consent) {
                    continue;
                }
                $phone = self::normalizePhone($customer?->phone);
                $reason = !$customer ? 'No customer' : (!$consent ? 'Customer has turned off SMS' : (!$phone ? 'No valid mobile number' : null));

                $key = implode(':', array_filter([$event, $channel, $reference ? class_basename($reference) : 'none', $reference?->getKey(), $stage], fn ($p) => $p !== '' && $p !== null));
                $message = $this->createOnce($key, [
                    'event'          => $event,
                    'channel'        => $channel,
                    'customer_id'    => $customer?->id,
                    'recipient'      => $phone,
                    'body'           => $template->render($vars + ['customer' => $customer?->name, 'shop' => self::shopName()]),
                    'status'         => $reason ? NotificationMessage::SKIPPED : NotificationMessage::QUEUED,
                    'last_error'     => $reason,
                    'reference_type' => $reference ? class_basename($reference) : null,
                    'reference_id'   => $reference?->getKey(),
                    'created_by'     => auth()->id(),
                ]);
                if ($message) {
                    $created->push($message);
                    if ($message->status === NotificationMessage::QUEUED) {
                        $this->queue($message);
                    }
                }
            }

            return $created;
        } catch (\Throwable $e) {
            // khobor na gele bikri/repair atkano jabe na
            report($e);

            return collect();
        }
    }

    /**
     * Queue job theke: lock kore ekbar-i pathano (duita worker ekshathe na). Fere status.
     */
    public function deliver(int $messageId): ?string
    {
        $message = DB::transaction(function () use ($messageId) {
            $message = NotificationMessage::lockForUpdate()->find($messageId);
            if (!$message || $message->status !== NotificationMessage::QUEUED) {
                return null;   // agei pathano/skip/failed - abar na
            }
            // queue e thakar majhe channel bondho kora hoyeche
            if (!NotificationChannel::isEnabled($message->channel)) {
                $message->update(['status' => NotificationMessage::SKIPPED, 'next_attempt_at' => null,
                    'last_error' => config("notifications.channels.{$message->channel}") . ' is turned off.']);

                return null;
            }
            $message->update(['status' => NotificationMessage::SENDING, 'attempts' => $message->attempts + 1]);

            return $message;
        });
        if (!$message) {
            return null;
        }

        $provider = NotificationProvider::where('channel', $message->channel)->where('is_active', true)->orderBy('id')->first();
        $result = $provider
            ? $this->gateway($provider)->send($provider, $message->recipient, $message->body)
            : GatewayResult::failed('No active ' . config("notifications.channels.{$message->channel}") . ' provider is configured.', false);

        if ($result->ok) {
            $message->update(['status' => NotificationMessage::SENT, 'provider_id' => $provider->id, 'provider_message_id' => $result->messageId,
                'sent_at' => now(), 'last_error' => null, 'next_attempt_at' => null]);

            return NotificationMessage::SENT;
        }

        $again = $result->retryable && $message->attempts < (int) config('notifications.max_attempts', 3);
        $delay = (config('notifications.backoff_minutes')[$message->attempts - 1] ?? 15) * 60;
        $message->update([
            'status'          => $again ? NotificationMessage::QUEUED : NotificationMessage::FAILED,
            'provider_id'     => $provider?->id,
            'last_error'      => $result->error,
            'next_attempt_at' => $again ? now()->addSeconds($delay) : null,
        ]);
        if ($again) {
            SendNotificationMessage::dispatch($message->id)->onQueue(config('notifications.queue'))->delay($delay);
        }

        return $message->status;
    }

    // hate abar pathano (failed ba skipped - jemon shommoti/number thik korar por)
    public function retry(NotificationMessage $message, User $by): NotificationMessage
    {
        return DB::transaction(function () use ($message, $by) {
            $message = NotificationMessage::with('customer')->lockForUpdate()->findOrFail($message->id);
            // worker majhpothe bondho hole "sending" e atke thake - 10 minute por abar pathano jay
            $stuck = $message->status === NotificationMessage::SENDING && $message->updated_at->lt(now()->subMinutes(10));
            if (!$stuck && !in_array($message->status, [NotificationMessage::FAILED, NotificationMessage::SKIPPED], true)) {
                throw new \Exception('Only failed, skipped or stuck messages can be sent again.');
            }
            if (!NotificationChannel::isEnabled($message->channel)) {
                throw new \Exception(config("notifications.channels.{$message->channel}") . ' is turned off. Turn it on first.');
            }
            if ($message->status === NotificationMessage::SKIPPED) {
                $customer = $message->customer;
                $phone = self::normalizePhone($customer?->phone);
                $consent = $customer && ($message->channel === 'whatsapp' ? $customer->notify_whatsapp : $customer->notify_sms);
                if (!$consent || !$phone) {
                    throw new \Exception('The customer still has no consent or no valid mobile number.');
                }
                $message->recipient = $phone;
            }

            $message->fill(['status' => NotificationMessage::QUEUED, 'last_error' => null, 'next_attempt_at' => null])->save();
            AuditLogger::log('notification.retried', $message, null, ['by' => $by->id]);
            $this->queue($message);

            return $message;
        });
    }

    // Provider test: asol pathano (simulation na), fol provider e lekha
    public function test(NotificationProvider $provider, string $phone): GatewayResult
    {
        $to = self::normalizePhone($phone);
        $result = $to
            ? $this->gateway($provider)->send($provider, $to, 'Test message from ' . self::shopName() . ' (' . now()->format('d M Y H:i') . ').')
            : GatewayResult::failed('Enter a valid mobile number.', false);
        $provider->update(['last_tested_at' => now(), 'last_test_result' => $result->ok ? 'Accepted by provider' . ($result->messageId ? " ({$result->messageId})" : '') : $result->error]);

        return $result;
    }

    /**
     * SMS/WhatsApp chalu/bondho. Chalu korte active provider lagbe (na hole shob message failed hoto).
     */
    public function setChannel(string $channel, bool $enabled, User $by): NotificationChannel
    {
        $label = config("notifications.channels.{$channel}");
        if (!$label) {
            throw new \Exception('Unknown channel.');
        }
        if ($enabled && !NotificationProvider::where('channel', $channel)->where('is_active', true)->exists()) {
            throw new \Exception("Add and activate a {$label} provider first.");
        }
        $row = NotificationChannel::firstOrCreate(['channel' => $channel]);
        $old = $row->only('is_enabled');
        $row->update(['is_enabled' => $enabled, 'updated_by' => $by->id]);
        AuditLogger::log('notification.channel_' . ($enabled ? 'enabled' : 'disabled'), $row, $old, $row->only('is_enabled'));

        return $row;
    }

    // ek channel e ekta-i active
    public function activate(NotificationProvider $provider, bool $active): void
    {
        DB::transaction(function () use ($provider, $active) {
            if ($active) {
                NotificationProvider::where('channel', $provider->channel)->where('id', '!=', $provider->id)->update(['is_active' => false]);
            }
            $provider->update(['is_active' => $active]);
        });
    }

    /**
     * Provider er delivery khobor (WhatsApp webhook): sent -> delivered -> read, ba failed.
     */
    public function providerStatus(string $providerMessageId, string $status, ?string $error = null): void
    {
        $message = NotificationMessage::where('provider_message_id', $providerMessageId)->first();
        if (!$message) {
            return;
        }
        $order = [NotificationMessage::SENT => 1, NotificationMessage::DELIVERED => 2, NotificationMessage::READ => 3];
        $map = ['sent' => NotificationMessage::SENT, 'delivered' => NotificationMessage::DELIVERED, 'read' => NotificationMessage::READ, 'failed' => NotificationMessage::FAILED];
        $new = $map[$status] ?? null;
        if (!$new) {
            return;
        }
        // pichhone na (read er por delivered ashle bodlay na)
        if ($new !== NotificationMessage::FAILED && ($order[$message->status] ?? 0) >= $order[$new]) {
            return;
        }
        $message->update(['status' => $new, 'delivered_at' => in_array($new, [NotificationMessage::DELIVERED, NotificationMessage::READ], true) ? ($message->delivered_at ?? now()) : $message->delivered_at,
            'last_error' => $new === NotificationMessage::FAILED ? mb_substr((string) $error, 0, 490) : $message->last_error]);
    }

    // 01XXXXXXXXX / +8801... / 8801... -> 8801XXXXXXXXX; onno desh er 10-15 digit (+ chara)
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (preg_match('/^0?1[3-9]\d{8}$/', $digits)) {
            return '880' . ltrim($digits, '0');
        }
        if (preg_match('/^8801[3-9]\d{8}$/', $digits)) {
            return $digits;
        }

        return str_starts_with((string) $phone, '+') && strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    public static function shopName(): string
    {
        return (string) (Setting::query()->value('name') ?: config('app.name'));
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function createOnce(string $key, array $data): ?NotificationMessage
    {
        if (NotificationMessage::where('dedupe_key', $key)->exists()) {
            return null;
        }
        try {
            return NotificationMessage::create($data + ['dedupe_key' => $key]);
        } catch (QueryException $e) {
            // duita request ekshathe: database er unique
            if (str_contains($e->getMessage(), 'dedupe_key')) {
                return null;
            }
            throw $e;
        }
    }

    private function queue(NotificationMessage $message): void
    {
        SendNotificationMessage::dispatch($message->id)->onQueue(config('notifications.queue'))->afterCommit();
    }

    private function gateway(NotificationProvider $provider): Gateway
    {
        $class = config("notifications.drivers.{$provider->driver}.class");
        if (!$class) {
            throw new \LogicException("Unknown notification driver {$provider->driver}.");
        }

        return app($class);
    }
}
