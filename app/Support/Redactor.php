<?php

namespace App\Support;

/**
 * Log/audit e gopon tottho na: password, secret, token, API key, credential, NID, card, PIN/OTP, payment account.
 * Key er naam dekhe man "[REDACTED]" (nested array o); lekha (error message) theke key=value / Bearer token muche.
 */
class Redactor
{
    public const MASK = '[REDACTED]';

    // key er ongsho (snake_case e bhag kore milano: "nid_no", "api_key", "password_confirmation")
    private const WORDS = ['password', 'passwd', 'pwd', 'secret', 'token', 'credential', 'credentials', 'authorization', 'cookie',
        'nid', 'passport', 'cvv', 'pin', 'otp', 'salt'];

    // puro key
    private const KEYS = ['api_key', 'apikey', 'private_key', 'card_number', 'card_no', 'national_id', 'payment_account', 'bank_account',
        'account_number', 'remember_token', 'access_token', 'app_secret', 'verify_token', 'date_of_birth'];

    public static function isSensitive(string|int $key): bool
    {
        if (!is_string($key)) {
            return false;
        }
        $k = strtolower(str_replace(['-', ' '], '_', preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', $key)));
        if (in_array($k, self::KEYS, true)) {
            return true;
        }

        return (bool) array_intersect(explode('_', $k), self::WORDS);
    }

    public static function array(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach ($values as $key => $value) {
            if (self::isSensitive($key)) {
                $values[$key] = self::MASK;
            } elseif (is_array($value)) {
                $values[$key] = self::array($value);
            }
        }

        return $values;
    }

    // error message / lekha: password=..., "token":"...", Bearer ..., SQL er quoted man
    public static function text(string $text): string
    {
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/=]+/i', '$1 ' . self::MASK, $text);
        $text = preg_replace('/(["\']?)\b([A-Za-z_]*(?:password|passwd|secret|token|api_?key|credential|authorization)[A-Za-z_]*)\1(\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,&;)}\]]+)/i',
            '$1$2$1$3' . self::MASK, $text);

        return $text;
    }

    // URL: query string bad (reset token, signature thakte pare)
    public static function url(?string $url): ?string
    {
        return $url === null ? null : mb_substr(strtok($url, '?'), 0, 255);
    }
}
