<?php

namespace App\Services\Backup;

/**
 * Restore er age protiti statement jachai: shudhu table er gothon ar row (DROP/CREATE TABLE, INSERT, ALTER TABLE er
 * key/column, session SET). Baki shob (trigger, procedure, view, GRANT, DROP DATABASE, UPDATE/DELETE, INSERT...SELECT,
 * onno database er table, FEDERATED engine, file e lekha) bondho.
 *
 * kind: set | skip (transaction/lock - chalano lage na) | drop | create | insert | alter
 */
class SqlGuard
{
    private const ID = '(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)';

    /** @return array{kind: string, tables: array<int, string>} */
    public static function inspect(string $statement, int $number = 0): array
    {
        $sql = trim($statement);
        // mysqldump: /*!40101 SET ... */
        if (str_starts_with($sql, '/*!') && preg_match('~^/\*!\d*\s*(.*?)\s*\*/$~s', $sql, $m)) {
            $sql = trim($m[1]);
        }
        $head = substr($sql, 0, 8192);

        if (preg_match('/^SET\s/i', $head)) {
            if (strlen($sql) > 4000 || preg_match('/\b(GLOBAL|PERSIST|PERSIST_ONLY|PASSWORD|ROLE)\b|@@\s*global/i', $sql)) {
                throw self::refuse($number, $sql);
            }

            return ['kind' => 'set', 'tables' => []];
        }
        if (preg_match('/^(START\s+TRANSACTION|BEGIN|COMMIT|UNLOCK\s+TABLES|LOCK\s+TABLES\s+[^;]*)$/i', $head) && strlen($sql) < 4000) {
            return ['kind' => 'skip', 'tables' => []];
        }
        if (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(.+)$/is', $head, $m) && strlen($sql) < 4000) {
            return ['kind' => 'drop', 'tables' => self::names($m[1], $number, $sql)];
        }
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::ID . '\s*\(/i', $head, $m)) {
            $tail = substr($sql, (int) strrpos($sql, ')') + 1);
            if (preg_match('/\b(SELECT|UNION|DATA\s+DIRECTORY|INDEX\s+DIRECTORY|CONNECTION)\b|ENGINE\s*=\s*(FEDERATED|CONNECT|SPIDER)/i', $tail)) {
                throw self::refuse($number, $sql);
            }

            return ['kind' => 'create', 'tables' => [self::name($m[1], $number, $sql)]];
        }
        if (preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+' . self::ID . '\s*(?:\([^()]*\)\s*)?VALUES\s*\(/i', $head, $m)) {
            return ['kind' => 'insert', 'tables' => [self::name($m[1], $number, $sql)]];
        }
        if (strlen($sql) < 200000 && preg_match('/^ALTER\s+TABLE\s+' . self::ID . '\s+(.+)$/is', $sql, $m)) {
            // quote er bhitorer lekha (comment/default) bad diye keyword dekhi
            $rest = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $m[2]);
            if (!preg_match('/^(ADD|MODIFY|CHANGE|AUTO_INCREMENT|DISABLE\s+KEYS|ENABLE\s+KEYS|ALTER\s+COLUMN)\b/i', ltrim($rest))
                || preg_match('/\b(RENAME|DROP|DISCARD|IMPORT|PARTITION|DATA\s+DIRECTORY|INDEX\s+DIRECTORY|CONNECTION)\b|ENGINE\s*=\s*(FEDERATED|CONNECT|SPIDER)/i', $rest)) {
                throw self::refuse($number, $sql);
            }

            return ['kind' => 'alter', 'tables' => [self::name($m[1], $number, $sql)]];
        }

        throw self::refuse($number, $sql);
    }

    private static function names(string $list, int $number, string $sql): array
    {
        $names = [];
        foreach (preg_split('/\s*,\s*/', trim($list)) as $part) {
            if (!preg_match('/^' . self::ID . '$/', $part)) {
                throw self::refuse($number, $sql);
            }
            $names[] = self::name($part, $number, $sql);
        }

        return $names;
    }

    private static function name(string $raw, int $number, string $sql): string
    {
        $name = str_starts_with($raw, '`') ? str_replace('``', '`', substr($raw, 1, -1)) : $raw;
        if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $name)) {
            throw self::refuse($number, $sql);
        }

        return $name;
    }

    private static function refuse(int $number, string $sql): \RuntimeException
    {
        $preview = mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 80);

        return new \RuntimeException(($number ? "Statement {$number}" : 'A statement') . " is not allowed in a backup file: \"{$preview}\". Only table definitions and rows are accepted.");
    }
}
