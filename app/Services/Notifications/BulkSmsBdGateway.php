<?php

namespace App\Services\Notifications;

use App\Models\NotificationProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * BulkSMSBD (bulksmsbd.net): api_key, senderid, number, message. Safol uttor: response_code 202.
 * API key credential e (encrypted), code e na.
 */
class BulkSmsBdGateway extends HttpSmsGateway
{
    const URL = 'http://bulksmsbd.net/api/smsapi';

    public function send(NotificationProvider $provider, string $to, string $body): GatewayResult
    {
        if (!$provider->credential('api_key') || !$provider->sender_id) {
            return GatewayResult::failed('BulkSMSBD API key or sender ID is missing.', false);
        }

        return $this->call(fn () => Http::timeout(20)->asForm()->post(self::URL, [
            'api_key'  => $provider->credential('api_key'),
            'type'     => 'text',
            'number'   => $to,
            'senderid' => $provider->sender_id,
            'message'  => $body,
        ]), fn (Response $r) => (int) ($r->json('response_code') ?? 0) === 202);
    }
}
