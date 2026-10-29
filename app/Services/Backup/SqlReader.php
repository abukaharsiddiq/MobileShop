<?php

namespace App\Services\Backup;

/**
 * .sql / .sql.gz file theke ekta ekta SQL statement (shesh er ";" chara). Quote ('...', "...", `...`) er bhitorer
 * ";" statement shesh na; -- , # ar /* *\/ comment bad; /*! ... *\/ (mysqldump er version comment) lekha hisebe thake.
 * File puro memory te ne na (1 MB kore pore).
 */
class SqlReader
{
    private const MAX_STATEMENT = 64 * 1024 * 1024;

    /** @return \Generator<int, string> */
    public static function statements(string $path): \Generator
    {
        $handle = @gzopen($path, 'rb');   // gz na holeo shadharon file pore
        if (!$handle) {
            throw new \RuntimeException('The SQL file cannot be opened.');
        }

        try {
            $buf = '';
            $pos = 0;
            $eof = false;
            $stmt = '';
            $quote = '';          // '', "'", '"', '`'
            $skip = '';           // '', 'line', 'block'

            while (true) {
                if (!$eof && strlen($buf) - $pos < 4) {
                    $chunk = gzread($handle, 1048576);
                    if ($chunk === false) {
                        throw new \RuntimeException('The file is damaged and cannot be read.');
                    }
                    $buf = substr($buf, $pos) . $chunk;
                    $pos = 0;
                    $eof = gzeof($handle);
                }
                $len = strlen($buf);
                if ($pos >= $len) {
                    break;
                }

                if ($skip === 'line') {
                    $nl = strpos($buf, "\n", $pos);
                    $pos = $nl === false ? $len : $nl + 1;
                    if ($nl !== false) {
                        $skip = '';
                        $stmt .= "\n";
                    }
                    continue;
                }
                if ($skip === 'block') {
                    $end = strpos($buf, '*/', $pos);
                    if ($end === false) {
                        $pos = max($pos, $len - 1);   // "*" shesh e thakle porer tukray "/" milbe
                        if ($eof) {
                            break;
                        }
                        continue;
                    }
                    $pos = $end + 2;
                    $skip = '';
                    $stmt .= ' ';
                    continue;
                }

                if ($quote !== '') {
                    $n = strcspn($buf, $quote . '\\', $pos);
                    $stmt .= substr($buf, $pos, $n);
                    $pos += $n;
                    if ($pos >= $len || (!$eof && $len - $pos < 4)) {
                        continue;
                    }
                    if ($buf[$pos] === '\\') {
                        $stmt .= substr($buf, $pos, 2);
                        $pos += 2;
                    } elseif (($buf[$pos + 1] ?? '') === $quote) {
                        $stmt .= $quote . $quote;
                        $pos += 2;
                    } else {
                        $stmt .= $quote;
                        $quote = '';
                        $pos++;
                    }
                    continue;
                }

                $n = strcspn($buf, "'\"`;-#/", $pos);
                $stmt .= substr($buf, $pos, $n);
                $pos += $n;
                if (strlen($stmt) > self::MAX_STATEMENT) {
                    throw new \RuntimeException('A statement in the file is too large.');
                }
                if ($pos >= $len || (!$eof && $len - $pos < 4)) {
                    continue;
                }

                $c = $buf[$pos];
                $next = $buf[$pos + 1] ?? '';
                if ($c === ';') {
                    $done = trim($stmt);
                    $stmt = '';
                    $pos++;
                    if ($done !== '') {
                        yield $done;
                    }
                } elseif ($c === "'" || $c === '"' || $c === '`') {
                    $quote = $c;
                    $stmt .= $c;
                    $pos++;
                } elseif ($c === '#' || ($c === '-' && $next === '-' && in_array($buf[$pos + 2] ?? "\n", [' ', "\t", "\n", "\r"], true))) {
                    $skip = 'line';
                } elseif ($c === '/' && $next === '*' && ($buf[$pos + 2] ?? '') !== '!') {
                    $skip = 'block';
                    $pos += 2;
                } else {
                    $stmt .= $c;
                    $pos++;
                }
            }

            if ($quote !== '') {
                throw new \RuntimeException('The file ends inside a quoted value (it is cut off or damaged).');
            }
            if (trim($stmt) !== '') {
                yield trim($stmt);
            }
        } finally {
            gzclose($handle);
        }
    }
}
