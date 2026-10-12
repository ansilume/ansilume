<?php

declare(strict_types=1);

namespace app\components;

use app\models\Job;
use yii\db\Connection;
use yii\db\Query;

/**
 * Encrypts the survey password answers that releases up to 2.8.1 stored in
 * plaintext: in job extra vars, the runner payload, the stored command and
 * schedule extra vars. It also removes the defaults of password fields,
 * which launches never used.
 *
 * Two entry points:
 * - runMigration(), from migration m000081 at container start: job
 *   templates, schedules, and unfinished jobs, which a runner still runs
 *   and which need their answers sealed before the first claim;
 * - scrubFinishedJobs(), from maintenance/run once a minute with a time
 *   budget: the history of finished jobs, which can be large.
 *
 * A value is a password answer when its top-level key is a password field
 * name of the job template as it is now, usable or not
 * ({@see SurveySecretRowMasker}): releases up to 2.8.1 stored answers under
 * the exact name, also under one that cannot carry an answer now. Such a
 * template is listed too, as it cannot be launched until the field is
 * renamed.
 *
 * It runs in console processes whose log target writes every SQL statement
 * with its values, so:
 * - SQL logging and profiling are off while it runs, and restored after;
 * - rows are read by keyset per job template, one transaction per page;
 * - an UPDATE names its row by ID only; no value is ever part of a condition;
 * - an error in one row leaves that row unchanged and prints a fixed line,
 *   and an unexpected error stops the run with a fixed line;
 * - what it prints are counts and IDs, never a value or an SQL statement.
 *
 * Running it again changes nothing: rows it wrote hold the placeholder.
 */
class SurveySecretBackfill
{
    public const PAGE_SIZE = 500;

    /** scrubFinishedJobs(): every finished job is done. */
    public const STATUS_DONE = 'done';
    /** scrubFinishedJobs(): the time budget ran out; continue from the cursor. */
    public const STATUS_PARTIAL = 'partial';
    /** The secret_extra_vars columns of migration m000080 do not exist yet. */
    public const STATUS_PENDING_MIGRATION = 'pending-migration';
    /** There are answers to encrypt, and APP_SECRET_KEY cannot encrypt. */
    public const STATUS_NO_KEY = 'no-key';
    /** An unexpected error stopped the run. */
    public const STATUS_ERROR = 'error';

    /** Jobs a runner may still run: the claim needs their answers. */
    public const UNFINISHED_STATUSES = [Job::STATUS_PENDING, Job::STATUS_QUEUED, Job::STATUS_PENDING_APPROVAL, Job::STATUS_RUNNING];

    /** Jobs that are over: only their history is scrubbed. */
    public const FINISHED_STATUSES = [Job::STATUS_SUCCEEDED, Job::STATUS_FAILED, Job::STATUS_CANCELED, Job::STATUS_TIMED_OUT, Job::STATUS_REJECTED];

    /** A progress line every this many pages. */
    private const PROGRESS_EVERY_PAGES = 20;

    private const KEY_PROBE = 'ansilume-backfill-key-probe';

    private const SECRET_COLUMN = 'secret_extra_vars';

    /** What a row of each table is called in report lines. */
    private const ROW_NAMES = ['{{%job}}' => 'job', '{{%schedule}}' => 'schedule', '{{%job_template}}' => 'job template'];

    /** What the run prints when it cannot start. */
    private const BLOCKED = [
        self::STATUS_PENDING_MIGRATION => 'The secret_extra_vars columns of migration m000080 are missing; run the migrations in order. Nothing was changed.',
        self::STATUS_NO_KEY => 'APP_SECRET_KEY is not set or shorter than 16 characters, so the stored survey password answers cannot be encrypted. '
            . 'Set APP_SECRET_KEY in .env to the value your credentials were encrypted with, then start the containers again. Nothing was changed.',
    ];

    /** Report lines for the rows a pass left unchanged or changed only in part. */
    private const PROBLEMS = [
        SurveySecretBackfillProgress::UNDECRYPTABLE => '%s left unchanged because their stored encrypted answers cannot be decrypted (was APP_SECRET_KEY changed?): %s.',
        SurveySecretBackfillProgress::TOO_LONG => '%s left unchanged because the changed value would not fit its column: %s.',
        SurveySecretBackfillProgress::FAILED => '%s left unchanged after an error (see the lines above): %s.',
        SurveySecretBackfillProgress::COMMAND_CLEARED => '%s whose stored command was cleared because it did not contain the extra vars as stored: %s.',
    ];

    /** @var \Closure(string): void */
    private \Closure $output;

    /** @var positive-int */
    private int $pageSize;

    private SurveySecretRowMasker $masker;

    /**
     * @param \Closure(string): void|null $output receives each report line; counts and IDs only
     * @param int $pageSize rows per page and transaction
     */
    public function __construct(private readonly Connection $db, ?\Closure $output = null, int $pageSize = self::PAGE_SIZE)
    {
        $this->output = $output ?? static function (string $line): void {
        };
        $this->pageSize = max(1, $pageSize);
        $this->masker = new SurveySecretRowMasker();
    }

    /**
     * Migration m000081: remove password defaults from surveys, report the
     * job templates an operator has to fix, and seal the answers of
     * schedules and unfinished jobs. When there are answers to encrypt,
     * APP_SECRET_KEY is checked before any row is touched.
     *
     * @return bool false when the run could not start or stopped early
     */
    public function runMigration(): bool
    {
        return $this->guarded(function (): bool {
            $templates = $this->templatesOrBlocker();
            if (is_string($templates)) {
                $this->say(self::BLOCKED[$templates]);
                return false;
            }
            $passwordNames = $templates->passwordNames();
            $this->say(sprintf('Survey password answers: %d job templates checked, %d of them with password fields.', $templates->count(), count($passwordNames)));
            $this->removePasswordDefaults($templates->withPasswordDefaults());
            $this->reportTemplates($templates);
            $this->backfillSchedules($passwordNames);
            $this->backfillUnfinishedJobs($passwordNames);
            $this->reportHistory($passwordNames);

            return true;
        }, static fn (): bool => false);
    }

    /**
     * One slice of the scrub of finished jobs (maintenance/run): from
     * $cursor until every finished job is done or $budgetSeconds have
     * passed. A page that started is always finished.
     *
     * @param array{template: int, job: int} $cursor where the previous slice stopped ({@see cursorFrom()})
     * @return array{status: string, cursor: array{template: int, job: int}, counts: array<string, int>}
     */
    public function scrubFinishedJobs(array $cursor, float $budgetSeconds): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $progress = new SurveySecretBackfillProgress('Finished jobs');
        $status = $this->guarded(fn (): string => $this->scrubFrom($cursor, $progress, $deadline), static fn (): string => self::STATUS_ERROR);
        $this->sayProblems($progress);
        $counts = $progress->counts();

        return [
            'status' => $status,
            'cursor' => $progress->position() ?? $cursor,
            'counts' => [
                'checked' => $counts[SurveySecretBackfillProgress::CHECKED],
                'encrypted' => $counts[SurveySecretBackfillProgress::CHANGED],
                'undecryptable' => $counts[SurveySecretBackfillProgress::UNDECRYPTABLE],
                'too_long' => $counts[SurveySecretBackfillProgress::TOO_LONG],
                'failed' => $counts[SurveySecretBackfillProgress::FAILED],
                'command_cleared' => $counts[SurveySecretBackfillProgress::COMMAND_CLEARED],
            ],
        ];
    }

    /**
     * A cursor from a stored value; the start when the value is missing or
     * not a cursor, which is safe because the scrub is idempotent.
     *
     * @return array{template: int, job: int}
     */
    public static function cursorFrom(mixed $value): array
    {
        if (is_array($value) && is_int($value['template'] ?? null) && is_int($value['job'] ?? null)) {
            return ['template' => max(0, $value['template']), 'job' => max(0, $value['job'])];
        }

        return ['template' => 0, 'job' => 0];
    }

    /**
     * Read every job template row, soft-deleted ones included.
     */
    protected function scanTemplates(): SurveySecretTemplateScan
    {
        $scan = new SurveySecretTemplateScan();
        $afterId = 0;
        do {
            $rows = $this->rows((new Query())->select(['id', 'survey_fields', 'extra_vars'])->from('{{%job_template}}'), $afterId);
            foreach ($rows as $row) {
                $afterId = (int)$row['id'];
                $scan->add($afterId, self::stringOrNull($row['survey_fields']), self::stringOrNull($row['extra_vars']));
            }
        } while (count($rows) === $this->pageSize);

        return $scan;
    }

    /**
     * The only write: $columns of row $id of $table, by ID only.
     *
     * @param array<string, string|null> $columns
     */
    protected function updateRow(string $table, #[\SensitiveParameter] array $columns, int $id): void
    {
        $this->db->createCommand()->update($table, $columns, ['id' => $id])->execute();
    }

    /**
     * @param array{template: int, job: int} $cursor
     */
    private function scrubFrom(array $cursor, SurveySecretBackfillProgress $progress, float $deadline): string
    {
        $templates = $this->templatesOrBlocker();
        if (is_string($templates)) {
            return $templates;
        }
        foreach ($templates->passwordNames() as $templateId => $names) {
            if ($templateId < $cursor['template']) {
                continue;
            }
            $afterId = $templateId === $cursor['template'] ? $cursor['job'] : 0;
            if (!$this->backfillJobs($templateId, $names, self::FINISHED_STATUSES, $afterId, $progress, $deadline)) {
                return self::STATUS_PARTIAL;
            }
        }

        return self::STATUS_DONE;
    }

    /**
     * Run $work with SQL logging and profiling off. An unexpected error
     * prints a fixed line, without the message: a database error's message
     * quotes the statement with its values.
     *
     * @template T
     * @param \Closure(): T $work
     * @param \Closure(): T $onError
     * @return T
     */
    private function guarded(\Closure $work, \Closure $onError): mixed
    {
        $logging = $this->db->enableLogging;
        $profiling = $this->db->enableProfiling;
        $this->db->enableLogging = false;
        $this->db->enableProfiling = false;
        try {
            return $work();
        } catch (\Throwable $e) {
            $this->say(sprintf('The encryption of stored survey password answers stopped after an error: %s. Rows already done stay done; running it again continues.', self::describe($e)));

            return $onError();
        } finally {
            $this->db->enableLogging = $logging;
            $this->db->enableProfiling = $profiling;
        }
    }

    /**
     * The job templates, or why the backfill cannot run now: the columns of
     * m000080 do not exist yet (checked first, before any other query), or
     * there are password fields, with usable names or not, and
     * APP_SECRET_KEY cannot encrypt.
     */
    private function templatesOrBlocker(): SurveySecretTemplateScan|string
    {
        foreach (['{{%job}}', '{{%schedule}}'] as $table) {
            if ($this->db->getTableSchema($table, true)?->getColumn(self::SECRET_COLUMN) === null) {
                return self::STATUS_PENDING_MIGRATION;
            }
        }
        $templates = $this->scanTemplates();

        return $templates->passwordNames() !== [] && !self::keyWorks() ? self::STATUS_NO_KEY : $templates;
    }

    private static function keyWorks(): bool
    {
        try {
            $cipher = new SecretCipher();
            $cipher->decrypt($cipher->encrypt(self::KEY_PROBE));
        } catch (SecretCipherException) {
            return false;
        }

        return true;
    }

    /**
     * @param list<int> $templateIds
     */
    private function removePasswordDefaults(array $templateIds): void
    {
        if ($templateIds === []) {
            return;
        }
        $progress = new SurveySecretBackfillProgress('Job templates');
        $mask = fn (#[\SensitiveParameter] array $row): array => $this->masker->maskSurvey($row);
        foreach (array_chunk($templateIds, $this->pageSize) as $chunk) {
            $rows = $this->rows((new Query())->select(['id', 'survey_fields'])->from('{{%job_template}}')->where(['id' => $chunk]), 0);
            $this->runPage('{{%job_template}}', $rows, $mask, $progress);
        }
        $changed = $progress->get(SurveySecretBackfillProgress::CHANGED);
        if ($changed > 0) {
            $this->say(sprintf('Job templates whose password fields lost their default value: %s.', self::idList($progress->ids(SurveySecretBackfillProgress::CHANGED), $changed)));
        }
        $this->sayProblems($progress);
    }

    private function reportTemplates(SurveySecretTemplateScan $templates): void
    {
        $invalid = $templates->invalid();
        if ($invalid !== []) {
            $this->say(sprintf('Job templates skipped because their survey is not valid JSON: %s.', self::idList($invalid, count($invalid))));
        }
        $unusable = $templates->unusable();
        if ($unusable !== []) {
            $this->say(sprintf(
                'Job templates with a password field whose name cannot carry an answer; they cannot be launched until the field is renamed in the survey editor: %s.',
                self::idList($unusable, count($unusable))
            ));
            $schedules = $this->schedulesOf($unusable);
            if ($schedules !== []) {
                $this->say(sprintf('Schedules that launch those job templates: %s.', self::idList($schedules, count($schedules))));
            }
        }
        $keys = $templates->passwordKeysInExtraVars();
        if ($keys !== []) {
            $this->say(sprintf(
                'Job templates whose Extra Vars hold a password field\'s name, a stored default of the password; remove the key from the Extra Vars: %s.',
                self::idList($keys, count($keys))
            ));
        }
    }

    /**
     * @param array<int, list<string>> $passwordNames every password field name by template ID
     */
    private function backfillSchedules(array $passwordNames): void
    {
        if ($passwordNames === []) {
            return;
        }
        $progress = new SurveySecretBackfillProgress('Schedules');
        foreach ($passwordNames as $templateId => $names) {
            $query = (new Query())->select(['id', 'extra_vars', self::SECRET_COLUMN])->from('{{%schedule}}')->where(['job_template_id' => $templateId]);
            $mask = fn (#[\SensitiveParameter] array $row): array => $this->masker->maskSchedule($row, $names);
            $this->pages('{{%schedule}}', $query, $mask, $progress, ['template' => $templateId, 'job' => 0], null);
        }
        $this->sayCounts($progress, '');
        $this->sayProblems($progress);
    }

    /**
     * @param array<int, list<string>> $passwordNames every password field name by template ID
     */
    private function backfillUnfinishedJobs(array $passwordNames): void
    {
        if ($passwordNames === []) {
            return;
        }
        $progress = new SurveySecretBackfillProgress('Unfinished jobs');
        foreach ($passwordNames as $templateId => $names) {
            $this->backfillJobs($templateId, $names, self::UNFINISHED_STATUSES, 0, $progress, null);
        }
        $this->sayCounts($progress, '');
        $this->sayProblems($progress);
    }

    /**
     * The jobs of one template with one of $statuses, from after job $afterId.
     *
     * @param list<string> $names every password field name of the template
     * @param list<string> $statuses
     * @return bool true when they are done, false when the deadline came first
     */
    private function backfillJobs(int $templateId, array $names, array $statuses, int $afterId, SurveySecretBackfillProgress $progress, ?float $deadline): bool
    {
        $query = (new Query())
            ->select(['id', 'extra_vars', 'runner_payload', 'execution_command', self::SECRET_COLUMN])
            ->from('{{%job}}')
            ->where(['job_template_id' => $templateId, 'status' => $statuses]);
        $mask = fn (#[\SensitiveParameter] array $row): array => $this->masker->maskJob($row, $names);

        return $this->pages('{{%job}}', $query, $mask, $progress, ['template' => $templateId, 'job' => $afterId], $deadline);
    }

    /**
     * The rows of $query after $from['job'], page by page, one transaction
     * per page, until none are left or past $deadline.
     *
     * @param \Closure(array<mixed>): array{status: string, columns: array<string, string|null>, command_cleared: bool} $mask
     * @param array{template: int, job: int} $from the template the rows belong to, and the last row done
     * @return bool true when every row is done, false when the deadline came first
     */
    private function pages(string $table, Query $query, \Closure $mask, SurveySecretBackfillProgress $progress, array $from, ?float $deadline): bool
    {
        $afterId = $from['job'];
        do {
            $rows = $this->rows($query, $afterId);
            if ($rows === []) {
                return true;
            }
            $this->runPage($table, $rows, $mask, $progress);
            $afterId = (int)$rows[count($rows) - 1]['id'];
            if ($progress->pageDone($from['template'], $afterId, self::PROGRESS_EVERY_PAGES)) {
                $this->sayCounts($progress, ' so far');
            }
            if (count($rows) < $this->pageSize) {
                return true;
            }
        } while ($deadline === null || microtime(true) < $deadline);

        return false;
    }

    /**
     * @param list<array<mixed>> $rows
     * @param \Closure(array<mixed>): array{status: string, columns: array<string, string|null>, command_cleared: bool} $mask
     */
    private function runPage(string $table, #[\SensitiveParameter] array $rows, \Closure $mask, SurveySecretBackfillProgress $progress): void
    {
        $this->db->transaction(function () use ($table, $rows, $mask, $progress): void {
            foreach ($rows as $row) {
                $this->backfillRow($table, $row, $mask, $progress);
            }
        });
    }

    /**
     * @param array<mixed> $row
     * @param \Closure(array<mixed>): array{status: string, columns: array<string, string|null>, command_cleared: bool} $mask
     */
    private function backfillRow(string $table, #[\SensitiveParameter] array $row, \Closure $mask, SurveySecretBackfillProgress $progress): void
    {
        $id = (int)$row['id'];
        $progress->add(SurveySecretBackfillProgress::CHECKED, $id);
        try {
            $result = $mask($row);
            if ($result['status'] === SurveySecretRowMasker::STATUS_CHANGED) {
                $this->updateRow($table, $result['columns'], $id);
            }
        } catch (\Throwable $e) {
            $progress->add(SurveySecretBackfillProgress::FAILED, $id);
            $this->say(sprintf('The %s #%d was left unchanged after an error: %s.', self::ROW_NAMES[$table] ?? $table, $id, self::describe($e)));
            return;
        }
        if ($result['status'] !== SurveySecretRowMasker::STATUS_UNCHANGED) {
            $progress->add($result['status'], $id);
        }
        if ($result['command_cleared']) {
            $progress->add(SurveySecretBackfillProgress::COMMAND_CLEARED, $id);
        }
    }

    /**
     * The next page of $query after row $afterId, by ID.
     *
     * @return list<array<mixed>>
     */
    private function rows(Query $query, int $afterId): array
    {
        $rows = [];
        $page = (clone $query)->andWhere(['>', 'id', $afterId])->orderBy(['id' => SORT_ASC])->limit($this->pageSize)->all($this->db);
        foreach ($page as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<int> $templateIds
     * @return list<int>
     */
    private function schedulesOf(array $templateIds): array
    {
        $ids = [];
        foreach (array_chunk($templateIds, $this->pageSize) as $chunk) {
            foreach ((new Query())->select('id')->from('{{%schedule}}')->where(['job_template_id' => $chunk])->column($this->db) as $id) {
                $ids[] = (int)$id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * How many finished jobs the maintenance scrub will check.
     *
     * @param array<int, list<string>> $passwordNames every password field name by template ID
     */
    private function reportHistory(array $passwordNames): void
    {
        $count = 0;
        foreach (array_chunk(array_keys($passwordNames), $this->pageSize) as $chunk) {
            $count += (int)(new Query())->from('{{%job}}')->where(['job_template_id' => $chunk, 'status' => self::FINISHED_STATUSES])->count('*', $this->db);
        }
        if ($count > 0) {
            $this->say(sprintf(
                'Finished jobs of these job templates: %d. Their stored answers are encrypted in the background by maintenance/run in the '
                . 'schedule-runner container, a few seconds per minute; it logs its progress as "survey-secret-scrub".',
                $count
            ));
        }
    }

    private function sayCounts(SurveySecretBackfillProgress $progress, string $suffix): void
    {
        $this->say(sprintf(
            '%s: %d checked, %d encrypted%s.',
            $progress->label(),
            $progress->get(SurveySecretBackfillProgress::CHECKED),
            $progress->get(SurveySecretBackfillProgress::CHANGED),
            $suffix
        ));
    }

    private function sayProblems(SurveySecretBackfillProgress $progress): void
    {
        foreach (self::PROBLEMS as $outcome => $line) {
            $count = $progress->get($outcome);
            if ($count > 0) {
                $this->say(sprintf($line, $progress->label(), self::idList($progress->ids($outcome), $count)));
            }
        }
    }

    private function say(string $line): void
    {
        ($this->output)($line);
    }

    /**
     * "3 (#4, #9, #12)", with "and N more" when $ids holds only the first
     * of $total.
     *
     * @param list<int> $ids
     */
    private static function idList(array $ids, int $total): string
    {
        $list = implode(', ', array_map(static fn (int $id): string => '#' . $id, $ids));
        $more = $total > count($ids) ? sprintf(' and %d more', $total - count($ids)) : '';

        return sprintf('%d (%s%s)', $total, $list, $more);
    }

    /**
     * The class of $e and, for a database error, SQLSTATE and the driver's
     * error code: never the message, which quotes the statement and its
     * values, nor the driver's message, which can quote a value.
     */
    private static function describe(\Throwable $e): string
    {
        if (!$e instanceof \yii\db\Exception) {
            return $e::class;
        }
        $info = $e->errorInfo;

        return sprintf('%s (SQLSTATE %s, driver error %s)', $e::class, self::scalarText($info[0] ?? null), self::scalarText($info[1] ?? null));
    }

    private static function scalarText(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : 'unknown';
    }

    private static function stringOrNull(#[\SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
