<?php

namespace App\Services\Notifications;

// Provider er uttor: nilo (messageId), ba keno na (error) ar abar chesta kora uchit kina
final class GatewayResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}

    public static function accepted(?string $messageId = null): self
    {
        return new self(true, $messageId);
    }

    // network/5xx/429: pore abar; 4xx (bhul number, credential): abar chesta e labh nei
    public static function failed(string $error, bool $retryable): self
    {
        return new self(false, null, mb_substr($error, 0, 490), $retryable);
    }
}
