<?php

namespace App\Services\Backup;

/**
 * Backup file encryption: AES-256-GCM, 1 MB tukra (memory kom). Protiti tukrar nijer IV ar tag; tukrar kromik
 * number ar "shesh" chinho AAD te - tukra bodlano, shajano ba file kete chhoto kora dhora pore.
 * Format: "MSBK1" + [flag(1) len(4) iv(12) tag(16) cipher(len)]...
 */
class BackupCrypto
{
    private const MAGIC = 'MSBK1';
    private const CHUNK = 1048576;

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function key(?string $configured): ?string
    {
        if ($configured === null || $configured === '') {
            return null;
        }
        $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('BACKUP_ENCRYPTION_KEY is invalid. Generate one with php artisan backup:key.');
        }

        return $key;
    }

    public static function encryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        try {
            fwrite($dst, self::MAGIC);
            $index = 0;
            $chunk = fread($src, self::CHUNK);
            do {
                $next = feof($src) ? '' : fread($src, self::CHUNK);
                $last = $next === '' && feof($src);
                $iv = random_bytes(12);
                $flag = $last ? "\x01" : "\x00";
                $cipher = openssl_encrypt((string) $chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC . pack('N', $index) . $flag);
                if ($cipher === false) {
                    throw new \RuntimeException('Backup encryption failed.');
                }
                fwrite($dst, $flag . pack('N', strlen($cipher)) . $iv . $tag . $cipher);
                $chunk = $next;
                $index++;
            } while (!$last);
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    public static function decryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        try {
            if (fread($src, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new \RuntimeException('Not an encrypted backup file.');
            }
            $index = 0;
            $done = false;
            while (!$done) {
                $head = fread($src, 33);
                if (strlen($head) !== 33) {
                    throw new \RuntimeException('Encrypted backup is truncated.');
                }
                $flag = $head[0];
                $len = unpack('N', substr($head, 1, 4))[1];
                $iv = substr($head, 5, 12);
                $tag = substr($head, 17, 16);
                $cipher = $len ? self::readExactly($src, $len) : '';
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC . pack('N', $index) . $flag);
                if ($plain === false) {
                    throw new \RuntimeException('Backup cannot be decrypted (wrong key or the file was changed).');
                }
                fwrite($dst, $plain);
                $done = $flag === "\x01";
                $index++;
            }
            if (fread($src, 1) !== '') {
                throw new \RuntimeException('Unexpected data after the end of the encrypted backup.');
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    private static function readExactly($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($handle)) {
            $data .= fread($handle, $length - strlen($data));
        }
        if (strlen($data) !== $length) {
            throw new \RuntimeException('Encrypted backup is truncated.');
        }

        return $data;
    }
}
