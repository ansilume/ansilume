<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use yii\db\Connection;

/**
 * What a request wrote to the database: a checksum of every table, taken in
 * one query before and after it, inside the test's transaction (so the
 * rows the test created count, and nothing of other connections does).
 * written() names the tables whose rows changed. It takes MySQL and MariaDB
 * alike (CRC32, JSON_ARRAY, BIT_XOR); it needs no privilege beyond SELECT.
 *
 * Left out, as every request writes them whatever it answers:
 * - api_token.last_used_at: every API request stamps the token it signs in
 *   with (BaseApiController::authenticateRequest());
 * - runner.last_seen_at and runner.updated_at: every runner API request is
 *   the runner's heartbeat (BaseRunnerApiController);
 * - job.last_progress_at: every report of a runner on a job stamps the job
 *   (api\runner\JobsController::findOwnedJob()).
 * The audit log is only ever appended to, so its count and its last ID
 * stand for it. The user table of the shared test database keeps the users
 * of earlier runs, thousands of them, which a checksum per request would
 * spend most of its time on: its rows from $firstUserId on (the users of
 * the test) are summed, and of the others the count and the last change
 * (updated_at), which every save of a user sets.
 */
final class DatabaseWrites
{
    /** Columns every request writes, by table. */
    private const BOOKKEEPING = [
        'api_token' => ['last_used_at'],
        'runner' => ['last_seen_at', 'updated_at'],
        'job' => ['last_progress_at'],
    ];

    /** Tables that are only appended to. */
    private const APPEND_ONLY = ['audit_log'];

    private const USER_TABLE = 'user';

    private ?string $query = null;

    public function __construct(private readonly Connection $db, private readonly int $firstUserId)
    {
    }

    /**
     * The checksum of each table, by table name.
     *
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        $sums = [];
        foreach ($this->db->createCommand($this->query())->queryAll() as $row) {
            $table = (string)$row['t'];
            $sums[$table] = (isset($sums[$table]) ? $sums[$table] . '/' : '') . $row['n'] . ':' . $row['h'];
        }
        ksort($sums);

        return $sums;
    }

    /**
     * The tables whose checksum differs between the snapshots $before and
     * $after.
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return list<string>
     */
    public static function written(array $before, array $after): array
    {
        $tables = [];
        foreach ($before + $after as $table => $sum) {
            if (($before[$table] ?? null) !== ($after[$table] ?? null)) {
                $tables[] = $table;
            }
        }
        sort($tables);

        return $tables;
    }

    private function query(): string
    {
        if ($this->query === null) {
            $parts = [];
            foreach ($this->db->getSchema()->getTableNames() as $table) {
                array_push($parts, ...$this->parts($table));
            }
            $this->query = implode(' UNION ALL ', $parts);
        }

        return $this->query;
    }

    /**
     * The SELECTs that sum up $table: a label (t), a count (n), a sum (h).
     *
     * @return list<string>
     */
    private function parts(string $table): array
    {
        $quoted = $this->db->quoteTableName($table);
        $label = $this->db->quoteValue($table);
        if (in_array($table, self::APPEND_ONLY, true)) {
            return ["SELECT {$label} AS t, COUNT(*) AS n, COALESCE(MAX(id), 0) AS h FROM {$quoted}"];
        }
        $schema = $this->db->getTableSchema($table);
        $columns = array_diff($schema === null ? [] : $schema->columnNames, self::BOOKKEEPING[$table] ?? []);
        $row = 'CRC32(JSON_ARRAY(' . implode(', ', array_map([$this->db, 'quoteColumnName'], $columns)) . '))';
        $sum = "SELECT {$label} AS t, COUNT(*) AS n, COALESCE(BIT_XOR({$row}), 0) AS h FROM {$quoted}";
        if ($table !== self::USER_TABLE) {
            return [$sum];
        }

        return [
            $sum . ' WHERE id >= ' . $this->firstUserId,
            "SELECT {$label} AS t, COUNT(*) AS n, COALESCE(MAX(updated_at), 0) AS h FROM {$quoted}",
        ];
    }
}
