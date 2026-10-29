<?php

namespace App\Services;

use App\Models\SystemHeartbeat;
use Illuminate\Support\Facades\DB;

/**
 * Audit log er hash chain: protiti row er hash = HMAC(APP_KEY, ager row er hash + ei row er man). Kono row bodlale,
 * majhe theke muchle ba shesh theke muchle (head mile na) verify() dhore. Hash bosano hoy pore (seal), id er kromey,
 * tai bikri/kena r transaction e kono lock lage na.
 */
class AuditChain
{
    public const HEARTBEAT = 'audit_chain';

    private const COLUMNS = ['id', 'user_id', 'user_name', 'branch_id', 'action', 'auditable_type', 'auditable_id', 'reference',
        'old_values', 'new_values', 'method', 'url', 'ip_address', 'user_agent', 'created_at'];

    // hash chara row gulo (id kromey) chain e jog; fere koyta
    public function seal(): int
    {
        $lock = DB::getDriverName() === 'mysql' ? DB::selectOne("SELECT GET_LOCK('audit_chain_seal', 10) AS ok")->ok : 1;
        if (!$lock) {
            return 0;
        }
        try {
            $last = DB::table('audit_logs')->whereNotNull('hash')->orderByDesc('id')->first(['id', 'hash']);
            $prev = $last->hash ?? '';
            $sealed = 0;
            DB::table('audit_logs')->whereNull('hash')->where('id', '>', $last->id ?? 0)->orderBy('id')
                ->chunkById(500, function ($rows) use (&$prev, &$sealed) {
                    foreach ($rows as $row) {
                        $hash = $this->hash($row, $prev);
                        DB::table('audit_logs')->where('id', $row->id)->update(['prev_hash' => $prev ?: null, 'hash' => $hash]);
                        $prev = $hash;
                        $sealed++;
                    }
                });
            if ($sealed) {
                $head = DB::table('audit_logs')->whereNotNull('hash')->orderByDesc('id')->first(['id', 'hash']);
                // head alada jaygay - shesh row muchle dhora porbe
                SystemHeartbeat::beat(self::HEARTBEAT, ['head_id' => $head->id, 'head_hash' => $head->hash]
                    + (SystemHeartbeat::meta(self::HEARTBEAT) ?? []));
            }

            return $sealed;
        } finally {
            if (DB::getDriverName() === 'mysql') {
                DB::select("SELECT RELEASE_LOCK('audit_chain_seal')");
            }
        }
    }

    /**
     * @return array{ok: bool, checked: int, unsealed: int, problems: array<int, string>}
     */
    public function verify(): array
    {
        $this->seal();
        $prev = '';
        $checked = 0;
        $problems = [];
        $lastId = 0;

        DB::table('audit_logs')->whereNotNull('hash')->orderBy('id')->chunkById(1000, function ($rows) use (&$prev, &$checked, &$problems, &$lastId) {
            foreach ($rows as $row) {
                if ((string) $row->prev_hash !== $prev) {
                    $problems[] = "Row #{$row->id}: the row before it was deleted or changed.";
                } elseif (!hash_equals($this->hash($row, $prev), (string) $row->hash)) {
                    $problems[] = "Row #{$row->id} ({$row->action}) was changed after it was written.";
                }
                $prev = (string) $row->hash;
                $lastId = $row->id;
                $checked++;
                if (count($problems) >= 50) {
                    return false;
                }
            }
        });

        $head = SystemHeartbeat::meta(self::HEARTBEAT);
        if ($head && !empty($head['head_id']) && count($problems) < 50) {
            $row = DB::table('audit_logs')->where('id', $head['head_id'])->first(['hash']);
            if (!$row || !hash_equals((string) $head['head_hash'], (string) $row->hash)) {
                $problems[] = "The latest sealed row #{$head['head_id']} is missing or changed (rows were removed from the end).";
            } elseif ($lastId < $head['head_id']) {
                $problems[] = 'Rows after #' . $lastId . ' are missing.';
            }
        }

        $result = ['ok' => !$problems, 'checked' => $checked, 'unsealed' => DB::table('audit_logs')->whereNull('hash')->count(), 'problems' => $problems];
        SystemHeartbeat::beat(self::HEARTBEAT, ['verified_at' => now()->toDateTimeString(), 'verify_ok' => $result['ok'], 'checked' => $checked,
            'problems' => array_slice($problems, 0, 10)] + ($head ?? []));

        return $result;
    }

    private function hash(object $row, string $prev): string
    {
        $data = [];
        foreach (self::COLUMNS as $column) {
            $value = $row->{$column} ?? null;
            // json column: MySQL key er kromo nije sajay - decode kore sort kore ek-i rup
            if (in_array($column, ['old_values', 'new_values'], true) && $value !== null) {
                $value = self::canonical(json_decode($value, true));
            }
            $data[$column] = $value === null ? null : (string) (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
        }

        return hash_hmac('sha256', $prev . '|' . json_encode($data, JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'canonical'], $value);
    }
}
