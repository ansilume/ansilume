<?php

declare(strict_types=1);

use app\models\Schedule;
use yii\db\Migration;
use yii\db\Query;

/**
 * Computes anew the next run of every schedule that is not yet due, as this
 * release computes runs (CronRuns, Schedule::nextRunOf()). Releases up to
 * 2.12.2 asked the cron library for it in the schedule's timezone, which on
 * the night the clocks go back runs a time that comes twice at both, and a
 * schedule at the hour after it an hour early as well, and around the clock
 * changes of some timezones skips runs: a next run computed so for the
 * coming such night moves to its time. A schedule that is due keeps its next
 * run, which the scheduler launches as before, and so does one whose next
 * run cannot be computed.
 *
 * Running it again changes nothing. It prints a count only.
 */
class m000089_000000_recompute_schedule_next_runs extends Migration
{
    /** sprintf(): how many next runs moved. */
    public const MOVED = 'Computed anew the next runs of the schedules that are not yet due: %d moved.';

    public function safeUp(): void
    {
        echo '    > ' . sprintf(self::MOVED, $this->moveNextRuns(time())) . "\n";
    }

    /**
     * Store the next run after $now of every schedule that is not yet due
     * at $now, as this release computes it: one moment for both, so that a
     * run the select saw as not yet due is not moved past itself when a
     * minute ends while the migration runs.
     *
     * @return int how many next runs moved
     */
    public function moveNextRuns(int $now): int
    {
        $rows = (new Query())
            ->select(['id', 'cron_expression', 'timezone', 'next_run_at'])
            ->from('{{%schedule}}')
            ->where(['>', 'next_run_at', $now])
            ->orderBy(['id' => SORT_ASC])
            ->all($this->db);
        $moved = 0;
        foreach ($rows as $row) {
            $moved += $this->moveNextRun($row, $now);
        }

        return $moved;
    }

    public function safeDown(): bool
    {
        // Nothing to restore: 2.12.2 launches a schedule at the next run
        // stored, which is one of its runs, and computes the ones after it
        // itself; the schema is unchanged.
        return true;
    }

    /**
     * Store the next run after $now of the schedule $row as this release
     * computes it, unless it cannot be computed, is the one stored, or the
     * schedule changed meanwhile.
     *
     * @param array<string, mixed> $row
     * @return int 1 when its next run moved, else 0
     */
    private function moveNextRun(array $row, int $now): int
    {
        $expression = (string)$row['cron_expression'];
        $next = Schedule::isParsableCron($expression)
            ? Schedule::nextRunOf($expression, (string)$row['timezone'] ?: 'UTC', $now)
            : null;
        if ($next === null || $next === (int)$row['next_run_at']) {
            return 0;
        }

        return $this->db->createCommand()->update(
            '{{%schedule}}',
            ['next_run_at' => $next],
            ['id' => $row['id'], 'next_run_at' => $row['next_run_at']]
        )->execute();
    }
}
