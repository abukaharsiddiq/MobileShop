<?php

namespace App\Services\Notifications;

use App\Models\NotificationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API (Meta). Business er pathano message 24 ghontar bahire approved template diye-i jay:
 * template_name dile template (body te ekta {{1}} = puro message), na dile text (shudhu chalu conversation e).
 */
class WhatsAppCloudGateway implements Gateway
{
    const API = 'https://graph.facebook.com/v19.0/';

    public function send(NotificationProvider $provider, string $to, string $body): GatewayResult
    {
        $phoneId = (string) $provider->credential('phone_number_id');
        $token = (string) $provider->credential('access_token');
        if ($phoneId === '' || $token === '') {
            return GatewayResult::failed('WhatsApp phone number ID or access token is missing.', false);
        }

        $template = (string) $provider->credential('template_name');
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to] + ($template !== ''
            ? ['type' => 'template', 'template' => ['name' => $template, 'language' => ['code' => $provider->credential('template_language') ?: 'en'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $body]]]]]]
            : ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $body]]);

        try {
            $response = Http::timeout(20)->withToken($token)->post(self::API . $phoneId . '/messages', $payload);
        } catch (ConnectionException $e) {
            return GatewayResult::failed('Could not connect to WhatsApp: ' . $e->getMessage(), true);
        }

        if ($response->successful() && $response->json('messages.0.id')) {
            return GatewayResult::accepted($response->json('messages.0.id'));
        }

        $error = $response->json('error.message') ?: mb_substr(trim($response->body()), 0, 300);

        return GatewayResult::failed('WhatsApp replied ' . $response->status() . ': ' . $error, $response->serverError() || $response->status() === 429);
    }
}
