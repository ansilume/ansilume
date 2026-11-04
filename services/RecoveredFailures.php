<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;

/**
 * Failures that a later run put right. A job is recovered when its status
 * is a terminal failure (Job::terminalFailureStatuses(): failed, timed
 * out) and a later job of the same job template succeeded that was no dry
 * run (check_mode off). The recovering job is the first such job by start
 * time, of two that started in the same second the one with the lower ID.
 * A canceled or rejected job is no failure, and a job without a job
 * template, or without an end, is never recovered.
 *
 * "Later" is by time, to the second: a success is later when it started
 * after the failure finished, or in the second the failure finished and is
 * the later job, of a higher ID. A runner runs one job at a time and claims
 * its next job right after it reported the end of the last, so a retry on
 * the same runner, as of a workflow step whose On Failure branch leads back
 * to it, starts in the second the failure finished. A success that was
 * already running when the failure ended is no later run, and does not
 * recover it, even when it finished after the failure. The dashboard, the
 * job page, the job list, the jobs API and the analytics' MTTR
 * (AnalyticsService) share this rule: each form below is built from
 * successesOf() and startedLater(), or, in filterRecovered(), the same
 * order of start and ID. Who sees a failure may see the job that recovered
 * it: both belong to the same job template, and so to the same project.
 *
 * The forms are chosen for the index idx_job_template_status_check_started
 * (job_template_id, status, check_mode, started_at), which InnoDB extends
 * by the primary key id, so that each failure costs one index lookup:
 * filterRecovered() compares the failure's end and ID with the start and
 * ID of the template's latest such success, and recoveredBy() asks for the
 * first one from the failure's end on with that failure's values. A
 * correlated EXISTS of a success "started after the failure finished" reads
 * every success of the template up to that point instead: the count of the
 * job list's filter took 48 seconds on 300,000 jobs, against 20
 * milliseconds; asked with a page's values, the recovering jobs of 25
 * failures take 1.6 milliseconds, against 165 correlated. MariaDB's
 * subquery cache keeps no row subquery, as filterRecovered()'s comparison
 * of start and ID is, where it kept the start alone for each job template:
 * on 300,000 jobs with 37,298 failures in 30 job templates, that count took
 * 99 milliseconds instead of 48, the same index lookup per failure.
 */
final class RecoveredFailures
{
    /** The job page's note on a recovered failure; sprintf(): the link to the recovering job ("#136"). */
    public const JOB_PAGE_NOTE = 'A later run of this job template succeeded: %s';

    /** What the job list shows next to the status of a recovered failure; sprintf(): the link to the recovering job. */
    public const LIST_NOTE = 'recovered by %s';

    /** The dashboard's line on the recovered failures of the last 24 hours, for one of them. */
    public const DASHBOARD_LINE_ONE = '1 failed job of the last 24 hours was followed by a successful run of the same job template.';

    /** The same line for more than one; sprintf(): how many. */
    public const DASHBOARD_LINE_MANY = '%d failed jobs of the last 24 hours were followed by a successful run of the same job template.';

    /** The link of that line to the job list of the recovered failures. */
    public const DASHBOARD_LINK = 'Show them';

    /** Why an alias was refused; sprintf(): the alias. */
    public const NOT_AN_ALIAS = 'Not an SQL alias: "%s".';

    /** Why recoveryCondition() refused one alias for both jobs. */
    public const SAME_ALIAS = 'The failure and the recovering job need aliases of their own.';

    /** The prefix of the column of each failure in recoveredBy()'s query. */
    private const COLUMN = 'failure_';

    /**
     * Each recovered failure among $jobs, mapped to the ID of the job that
     * recovered it; the other jobs are left out. One query for all of
     * them, as a page of jobs asks, and none when none of them failed.
     *
     * @param iterable<Job> $jobs
     * @return array<int, int> failure ID => recovering job ID
     */
    public static function recoveredBy(iterable $jobs): array
    {
        $columns = self::firstRecoveriesOf($jobs);
        if ($columns === []) {
            return [];
        }
        // A SELECT without a table answers one row: each failure's column.
        $row = (new Query())->select($columns)->one();

        $recovered = [];
        foreach (is_array($row) ? $row : [] as $column => $recovering) {
            if ($recovering !== null) {
                $recovered[(int)substr((string)$column, strlen(self::COLUMN))] = (int)$recovering;
            }
        }

        return $recovered;
    }

    /**
     * Restricts $jobs, whose job table goes by $alias, to the terminal
     * failures that a later run recovered ($recovered true), or to those
     * that none recovered ($recovered false).
     *
     * @template T of ActiveQuery
     * @param T $jobs
     * @return T
     */
    public static function filterRecovered(ActiveQuery $jobs, bool $recovered, string $alias = 'job'): ActiveQuery
    {
        $a = self::alias($alias);
        $latest = $a . '_latest';
        // The start and the ID of the template's latest success, by start, then ID.
        $latestSuccess = sprintf(
            'SELECT %1$s.started_at, %1$s.id FROM %2$s %1$s WHERE %3$s ORDER BY %1$s.started_at DESC, %1$s.id DESC LIMIT 1',
            $latest,
            Job::tableName(),
            self::successesOf($latest, $a . '.job_template_id')
        );
        // A row comparison: it started after the failure finished, or in that second with a higher ID.
        $jobs->andWhere([$a . '.status' => Job::terminalFailureStatuses()])
            ->andWhere(new Expression(sprintf('COALESCE((%1$s.finished_at, %1$s.id) < (%2$s), 0) = %3$d', $a, $latestSuccess, $recovered ? 1 : 0)));

        return $jobs;
    }

    /**
     * The SQL condition under which the job $successAlias recovers the
     * failure $failedAlias: the same job template, succeeded, no dry run,
     * started after the failure finished or in that second with a higher ID
     * (startedLater()). Literal values only, so it fits any query. Whether
     * $failedAlias is a failure at all is the caller's to ask
     * (Job::terminalFailureStatuses()).
     *
     * @throws \InvalidArgumentException for an alias that is no plain SQL name, or one alias for both jobs
     */
    public static function recoveryCondition(string $failedAlias, string $successAlias): string
    {
        $f = self::alias($failedAlias);
        $s = self::alias($successAlias);
        if ($f === $s) {
            throw new \InvalidArgumentException(self::SAME_ALIAS);
        }

        return self::successesOf($s, $f . '.job_template_id') . ' AND ' . self::startedLater($s, $f . '.finished_at', $f . '.id');
    }

    /**
     * For each failure among $jobs that has a job template and an end, the
     * subquery of its first recovering job, under the column of the failure.
     *
     * @param iterable<Job> $jobs
     * @return array<string, Query>
     */
    private static function firstRecoveriesOf(iterable $jobs): array
    {
        $columns = [];
        foreach ($jobs as $job) {
            if ($job->isTerminalFailure() && $job->job_template_id !== null && $job->finished_at !== null) {
                $columns[self::COLUMN . (int)$job->id] = self::firstRecoveryOf($job);
            }
        }

        return $columns;
    }

    /**
     * The first job that recovers the failure $failure, of a job template
     * and with an end, by start time, then ID: its ID, as a subquery.
     */
    private static function firstRecoveryOf(Job $failure): Query
    {
        $later = self::startedLater('s', (string)(int)$failure->finished_at, (string)(int)$failure->id);

        return (new Query())
            ->select('s.id')
            ->from(['s' => Job::tableName()])
            ->where(new Expression(self::successesOf('s', (string)(int)$failure->job_template_id) . ' AND ' . $later))
            ->orderBy(['s.started_at' => SORT_ASC, 's.id' => SORT_ASC])
            ->limit(1);
    }

    /**
     * The jobs $s that are later than a failure that finished at $finishedAt
     * and has the ID $failureId (SQL expressions): they started after it
     * finished, or in that second with a higher ID. The start's lower bound
     * comes first, so that the index on the start reads from it on.
     */
    private static function startedLater(string $s, string $finishedAt, string $failureId): string
    {
        return sprintf('%1$s.started_at >= %2$s AND (%1$s.started_at > %2$s OR %1$s.id > %3$s)', $s, $finishedAt, $failureId);
    }

    /**
     * The jobs $s that can recover a failure of the job template $template
     * (an SQL expression): its jobs that succeeded and were no dry run.
     */
    private static function successesOf(string $s, string $template): string
    {
        return sprintf("%s.job_template_id = %s AND %s.status = '%s' AND %s.check_mode = 0", $s, $template, $s, Job::STATUS_SUCCEEDED, $s);
    }

    /**
     * $alias, when it is a plain SQL name: it goes into the SQL as it is.
     */
    private static function alias(string $alias): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException(sprintf(self::NOT_AN_ALIAS, $alias));
        }

        return $alias;
    }
}
