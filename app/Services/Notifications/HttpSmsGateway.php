<?php

namespace App\Services\Notifications;

use App\Models\NotificationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Jekono HTTP SMS API: URL, method, parameter er naam ar safol uttor e ki lekha thake - shob provider er credential e.
 * Safol = HTTP 2xx ar (success_contains dile) uttor e oi lekha.
 */
class HttpSmsGateway implements Gateway
{
    public function send(NotificationProvider $provider, string $to, string $body): GatewayResult
    {
        $url = (string) $provider->credential('url');
        if ($url === '') {
            return GatewayResult::failed('SMS provider URL is not configured.', false);
        }

        $params = array_filter([
            $provider->credential('api_key_param', 'api_key') => $provider->credential('api_key'),
            $provider->credential('to_param', 'number')        => $to,
            $provider->credential('message_param', 'message') => $body,
            $provider->credential('sender_param', 'senderid')  => $provider->sender_id,
        ], fn ($v, $k) => $k !== '' && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH);

        return $this->call(fn () => strtoupper((string) $provider->credential('method', 'POST')) === 'GET'
            ? Http::timeout(20)->get($url, $params)
            : Http::timeout(20)->asForm()->post($url, $params),
            fn (Response $r) => ($needle = (string) $provider->credential('success_contains')) === '' || str_contains($r->body(), $needle));
    }

    /**
     * Network bhul / 5xx / 429 = abar chesta; baki 4xx ba "safol na" uttor = failed.
     * @param callable(): Response $request
     * @param callable(Response): bool $isSuccess
     */
    protected function call(callable $request, callable $isSuccess): GatewayResult
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            return GatewayResult::failed('Could not connect to the provider: ' . $e->getMessage(), true);
        }

        if ($response->successful() && $isSuccess($response)) {
            $json = $response->json();

            return GatewayResult::accepted(is_array($json) ? (string) ($json['message_id'] ?? $json['id'] ?? $json['messageId'] ?? '') ?: null : null);
        }

        $retryable = $response->serverError() || $response->status() === 429;

        return GatewayResult::failed('Provider replied ' . $response->status() . ': ' . mb_substr(trim($response->body()), 0, 300), $retryable);
    }
}
