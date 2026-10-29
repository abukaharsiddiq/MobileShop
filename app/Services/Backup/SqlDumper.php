<?php

namespace App\Services\Backup;

use Illuminate\Database\Connection;
use PDO;

/**
 * PHP diye database er SQL copy (mysqldump lage na). Ek "consistent snapshot" e pore - backup cholar somoy bikri
 * holeo shob table ek-i muhurter. Protiti table: DROP + CREATE + INSERT (200 row kore). Generated column bad (MySQL
 * nije banay), binary hex e. Row er hisab fere (restore jachai e mile).
 */
class SqlDumper
{
    private const BINARY = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'];

    /**
     * @param callable(string): void $write
     * @param array<int, string> $exclude
     * @return array<string, int> table => row
     */
    public function dump(Connection $db, string $database, callable $write, array $exclude = []): array
    {
        $pdo = $db->getPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $pdo->exec("SET NAMES utf8mb4");
        $counts = [];

        try {
            $write("-- MobileShop database backup\n-- Database: {$database}\n-- Created: " . now()->toDateTimeString() . "\n\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");

            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            $columnQuery = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');

            foreach ($tables as [$table]) {
                if (in_array($table, $exclude, true)) {
                    continue;
                }
                $q = '`' . str_replace('`', '``', $table) . '`';
                $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM)[1];
                $write("-- Table {$table}\nDROP TABLE IF EXISTS {$q};\n{$create};\n");

                $columnQuery->execute([$database, $table]);
                $columns = array_values(array_filter($columnQuery->fetchAll(PDO::FETCH_ASSOC),
                    fn ($c) => ($c['GENERATION_EXPRESSION'] ?? '') === '' && stripos((string) $c['EXTRA'], 'GENERATED') === false));
                $binary = array_map(fn ($c) => in_array(strtolower($c['DATA_TYPE']), self::BINARY, true), $columns);
                $list = implode(',', array_map(fn ($c) => '`' . str_replace('`', '``', $c['COLUMN_NAME']) . '`', $columns));

                // unbuffered: boro table o memory te na
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $rows = $pdo->query("SELECT {$list} FROM {$q}");
                $count = 0;
                $batch = [];
                $size = 0;
                try {
                    while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                        $values = [];
                        foreach ($row as $i => $value) {
                            $values[] = $value === null ? 'NULL'
                                : ($binary[$i] ? ($value === '' ? "''" : '0x' . bin2hex($value)) : $pdo->quote((string) $value));
                        }
                        $tuple = '(' . implode(',', $values) . ')';
                        $batch[] = $tuple;
                        $size += strlen($tuple);
                        $count++;
                        if (count($batch) >= 200 || $size > 1048576) {
                            $write("INSERT INTO {$q} ({$list}) VALUES\n" . implode(",\n", $batch) . ";\n");
                            $batch = [];
                            $size = 0;
                        }
                    }
                } finally {
                    $rows->closeCursor();
                    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
                if ($batch) {
                    $write("INSERT INTO {$q} ({$list}) VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                $write("\n");
                $counts[$table] = $count;
            }

            $write("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- Dump completed " . now()->toDateTimeString() . "\n");
        } finally {
            $pdo->exec('COMMIT');
        }

        return $counts;
    }
}
