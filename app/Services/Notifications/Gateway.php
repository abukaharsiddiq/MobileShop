<?php

namespace App\Services\Notifications;

use App\Models\NotificationProvider;

/**
 * Provider-er bhinnota ekhane lukano: NotificationService shudhu ei interface chene. Notun provider = notun class +
 * config('notifications.drivers') e ekta line.
 */
interface Gateway
{
    /** $to: 8801XXXXXXXXX (desh code shoho, + chara) */
    public function send(NotificationProvider $provider, string $to, string $body): GatewayResult;
}
