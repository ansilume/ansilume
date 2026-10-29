<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubmittedInteger;

/**
 * The whole numbers of a runner's reports, read before anything is written:
 * the sequence of a log chunk (POST /api/runner/v1/jobs/<id>/logs), the exit
 * code of a completion (.../complete) and the seq and duration_ms of each
 * task result (.../tasks). Each is read as the model's integer rule reads it
 * (SubmittedInteger), never with an (int) cast, and must lie in the range of
 * what stores it: the job log's sequence column (unsigned), the job record's
 * rule for the exit code (Job::rules()), the task list's columns. The runner
 * API refuses any other value with 422 and the message below, writes nothing
 * of the report and logs nothing. A runner reports whole numbers in these
 * ranges only; a refused value comes from a broken or forged client, or from
 * a playbook that wrote to the file the task results are read from.
 *
 * Regression: a JSON number beyond the int range, such as 1e30,
 * 9223372036854775808 or 1e999 (infinity), answered 500 on PHP 8.5: the
 * endpoint's (int) cast warned, and the error handler turned the warning
 * into an exception. Up to PHP 8.4 the cast wrapped such a number to an
 * arbitrary int and made 1e999 0, so that "exit_code": 1e999 ended a job as
 * succeeded. A whole number beyond the column's range failed the insert or
 * update on every version: 500, with the tasks before it saved.
 */
final class RunnerReportNumbers
{
    /** 422: the sequence of a log chunk is no whole number the job log takes. */
    public const SEQUENCE_REFUSED = 'sequence must be a whole number from 0 to 4294967295.';

    /** 422: the exit code of a completion is no whole number the job record takes. */
    public const EXIT_CODE_REFUSED = 'exit_code must be a whole number from -256 to 255.';

    /** 422, sprintf: the task's key in the list. */
    public const TASK_NOT_AN_OBJECT = 'tasks[%s] must be an object.';

    /** 422, sprintf: the task's key in the list, the field (seq or duration_ms). */
    public const TASK_NUMBER_REFUSED = 'tasks[%s].%s must be a whole number from -2147483648 to 2147483647.';

    /** job_log.sequence: INT UNSIGNED. */
    private const SEQUENCE_MAX = 4294967295;

    /** Job::rules() for exit_code. */
    private const EXIT_CODE_MIN = -256;
    private const EXIT_CODE_MAX = 255;

    /** job_task.sequence and job_task.duration_ms: INT. */
    private const TASK_NUMBER_MIN = -2147483648;
    private const TASK_NUMBER_MAX = 2147483647;

    /** The fields of a task result that hold whole numbers. */
    private const TASK_NUMBER_FIELDS = ['seq', 'duration_ms'];

    /**
     * The sequence a log chunk is stored at; null when $value is no whole
     * number the job log takes (SEQUENCE_REFUSED).
     */
    public static function sequence(mixed $value): ?int
    {
        return self::within($value, 0, self::SEQUENCE_MAX);
    }

    /**
     * The exit code a completion ends the job with; null when $value is no
     * whole number the job record takes (EXIT_CODE_REFUSED).
     */
    public static function exitCode(mixed $value): ?int
    {
        return self::within($value, self::EXIT_CODE_MIN, self::EXIT_CODE_MAX);
    }

    /**
     * The seq or duration_ms of a task result; null when $value is no whole
     * number the task list takes (TASK_NUMBER_REFUSED).
     */
    public static function taskNumber(mixed $value): ?int
    {
        return self::within($value, self::TASK_NUMBER_MIN, self::TASK_NUMBER_MAX);
    }

    /**
     * Why $tasks cannot be saved as they are: the first task that is no
     * object, or whose seq or duration_ms, when set, is no whole number the
     * task list takes; null when every task can be saved.
     *
     * @param array<mixed> $tasks the task results as the runner reports them
     */
    public static function taskListRefusal(#[\SensitiveParameter] array $tasks): ?string
    {
        foreach ($tasks as $key => $task) {
            if (!is_array($task)) {
                return sprintf(self::TASK_NOT_AN_OBJECT, $key);
            }
            foreach (self::TASK_NUMBER_FIELDS as $field) {
                if (isset($task[$field]) && self::taskNumber($task[$field]) === null) {
                    return sprintf(self::TASK_NUMBER_REFUSED, $key, $field);
                }
            }
        }

        return null;
    }

    private static function within(mixed $value, int $min, int $max): ?int
    {
        $number = SubmittedInteger::of($value);

        return $number !== null && $number >= $min && $number <= $max ? $number : null;
    }
}
