<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;

/**
 * The launch fields that go into the job record as they are given: limit
 * and verbosity. JobLaunchService puts a limit (when not empty) into the
 * record as text and a verbosity (when set) as a whole number; the record
 * takes a limit of at most 255 characters and a verbosity from 0 to 5, and a
 * list or an object is a value for neither. Launch input it would refuse is
 * the caller's to fix, so it is refused before the launch, with the job
 * record's own validation messages: POST /api/v1/jobs answers 422 with them
 * by field, the web launch form shows them, a job trigger answers 422.
 *
 * The other launch fields are checked elsewhere or cannot be refused: the
 * launch normalises check_mode, and refuses extra vars that JSON cannot
 * hold (JobLaunchRejectedException). Schedules and workflow job steps pass
 * extra vars only, and a re-launch passes the limit and verbosity of a job
 * record, which took them already.
 *
 * Regression: POST /api/v1/jobs with a verbosity outside 0-5 answered 500
 * "Launch failed.": the job record refused it inside the launch, and its
 * error was logged as "Failed to save job record". A limit of more than 255
 * characters did the same, and a list as the limit failed the request with
 * "Array to string conversion"; the web launch form and the job trigger
 * failed alike.
 */
final class JobLaunchInput
{
    /**
     * The errors the job record gives the launch fields of $overrides, as
     * JobLaunchService would put them in, by field: the first error of each,
     * limit before verbosity. [] when the record takes them, or they are not
     * given.
     *
     * @param array<string, mixed> $overrides the launch overrides, as JobLaunchService::launch() takes them
     * @return array<string, string>
     */
    public static function errors(#[\SensitiveParameter] array $overrides): array
    {
        $job = new Job();
        $fields = [];
        if (!empty($overrides['limit'])) {
            $limit = $overrides['limit'];
            $job->setAttribute('limit', is_scalar($limit) ? (string)$limit : $limit);
            $fields[] = 'limit';
        }
        if (isset($overrides['verbosity'])) {
            $verbosity = $overrides['verbosity'];
            $job->setAttribute('verbosity', is_scalar($verbosity) ? (int)$verbosity : $verbosity);
            $fields[] = 'verbosity';
        }
        if ($fields === [] || $job->validate($fields)) {
            return [];
        }

        $errors = [];
        foreach ($fields as $field) {
            $error = $job->getFirstError($field);
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        return $errors;
    }

    /**
     * The message that refuses launch input with $errors (from errors()):
     * the errors in their order, one sentence each.
     *
     * @param array<string, string> $errors
     */
    public static function refusal(array $errors): string
    {
        return implode(' ', $errors);
    }

    /**
     * Refuse launch input the job record would refuse, before the launch.
     *
     * @param array<string, mixed> $overrides the launch overrides, as JobLaunchService::launch() takes them
     * @throws JobLaunchRejectedException with refusal() of the errors
     */
    public static function check(#[\SensitiveParameter] array $overrides): void
    {
        $errors = self::errors($overrides);
        if ($errors !== []) {
            throw new JobLaunchRejectedException(self::refusal($errors));
        }
    }
}
