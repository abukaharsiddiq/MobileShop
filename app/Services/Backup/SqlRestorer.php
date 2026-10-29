<?php

namespace App\Services\Backup;

use Illuminate\Database\Connection;

/**
 * Jachai kora SQL file chalano. $keep er table (login session, queue, backup er talika) chhoya hoy na. $dropOthers:
 * file e na thaka table muche (pore migrate abar banay) - jate database thik backup er moto hoy.
 */
class SqlRestorer
{
    /**
     * Shudhu jachai (database e kichu na). @return array{statements: int, tables: array<int, string>}
     */
    public function check(string $sqlFile): array
    {
        $count = 0;
        $tables = [];
        foreach (SqlReader::statements($sqlFile) as $statement) {
            $info = SqlGuard::inspect($statement, ++$count);
            if ($info['kind'] === 'create') {
                $tables[$info['tables'][0]] = true;
            }
        }
        if (!$count) {
            throw new \RuntimeException('The file has no SQL statements.');
        }

        return ['statements' => $count, 'tables' => array_keys($tables)];
    }

    /**
     * @param array<int, string> $keep
     * @return array{statements: int, tables: int, dropped: array<int, string>}
     */
    public function restore(Connection $db, string $sqlFile, array $keep = [], bool $dropOthers = false): array
    {
        $plan = $this->check($sqlFile);
        $executed = 0;
        $dropped = [];
        $db->unprepared('SET FOREIGN_KEY_CHECKS=0');
        try {
            if ($dropOthers) {
                foreach ($db->select("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $row) {
                    if (!in_array($row->t, $plan['tables'], true) && !in_array($row->t, $keep, true)) {
                        $db->unprepared('DROP TABLE `' . str_replace('`', '``', $row->t) . '`');
                        $dropped[] = $row->t;
                    }
                }
            }

            $number = 0;
            foreach (SqlReader::statements($sqlFile) as $statement) {
                $info = SqlGuard::inspect($statement, ++$number);
                if ($info['kind'] === 'skip') {
                    continue;
                }
                $mine = array_values(array_diff($info['tables'], $keep));
                if ($info['tables'] && !$mine) {
                    continue;   // rakhar table
                }
                if ($info['kind'] === 'drop' && count($mine) !== count($info['tables'])) {
                    $statement = 'DROP TABLE IF EXISTS ' . implode(',', array_map(fn ($t) => '`' . str_replace('`', '``', $t) . '`', $mine));
                }
                try {
                    $db->unprepared($statement);
                } catch (\Throwable $e) {
                    throw new \RuntimeException("Statement {$number} failed: " . mb_substr($e->getMessage(), 0, 300), 0, $e);
                }
                $executed++;
            }
        } finally {
            $db->unprepared('SET FOREIGN_KEY_CHECKS=1');
        }

        return ['statements' => $executed, 'tables' => count(array_diff($plan['tables'], $keep)), 'dropped' => $dropped];
    }
}
