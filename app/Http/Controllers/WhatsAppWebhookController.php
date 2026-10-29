<?php

namespace App\Http\Controllers;

use App\Models\NotificationProvider;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * Meta WhatsApp webhook: GET = verify (verify_token), POST = message status (sent/delivered/read/failed).
 * POST er X-Hub-Signature-256 app_secret diye milate hobe - na mille 403 (bhuya status bosano jay na).
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $provider = $this->provider();
        $token = $provider?->credential('verify_token');

        abort_unless($token && $request->query('hub_mode') === 'subscribe' && hash_equals((string) $token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200);
    }

    public function receive(Request $request, NotificationService $notifications)
    {
        $secret = (string) $this->provider()?->credential('app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');
        abort_unless($secret !== '' && hash_equals('sha256=' . hash_hmac('sha256', $request->getContent(), $secret), $signature), 403);

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    if (!empty($status['id']) && !empty($status['status'])) {
                        $notifications->providerStatus((string) $status['id'], (string) $status['status'], $status['errors'][0]['title'] ?? null);
                    }
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function provider(): ?NotificationProvider
    {
        return NotificationProvider::where('channel', 'whatsapp')->where('driver', 'whatsapp_cloud')->orderByDesc('is_active')->orderBy('id')->first();
    }
}
