<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubmittedInteger;
use app\models\Job;

/**
 * The launch fields that go into the job record as they are given: limit
 * and verbosity. JobLaunchService puts a limit (when not empty) into the
 * record as text and a verbosity (when set) as the record's integer rule
 * reads it (SubmittedInteger): a whole number as an int, anything else as
 * it is, for the rule to refuse, and an empty one (""), which the rule
 * skips, as none, so the template's applies. The record takes a limit of at
 * most 255 characters and a verbosity from 0 to 5, and a list or an object
 * is a value for neither, also an empty one, which its rules skip as they
 * skip every empty value. Launch input it would refuse is the caller's to
 * fix, so it is refused before the launch, with the job record's own
 * validation messages: POST /api/v1/jobs answers 422 with them by field, the
 * web launch form shows them, a job trigger answers 422. Each of them hands
 * a limit and a verbosity on as sent, whenever they are set, for this check.
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
 *
 * Regression: a verbosity beyond the int range as a JSON number (1e30,
 * 9223372036854775808, 1e999), which the request's JSON parser gives as a
 * float, answered 500 on PHP 8.5, from POST /api/v1/jobs, from a job trigger
 * and from the web launch form posted as JSON: the check cast it with
 * (int), which warns there ("The float 1.0E+30 is not representable as an
 * int, cast occurred"), and the error handler turned the warning into an
 * exception. Up to PHP 8.4 the cast wrapped it, and 1e999 (infinity) became
 * 0: the job ran at verbosity 0. It is refused now as no whole number
 * ("Verbosity must be an integer."), and so are a fraction, text that is no
 * number and a boolean, which the cast made a whole number of.
 *
 * Regression: an empty list or object ([] or {}) as the limit or the
 * verbosity passed, as the record's rules skip an empty value, and the launch
 * took it for none: POST /api/v1/jobs and a job trigger answered 201, and the
 * job ran with the template's limit and verbosity (2.13.1 ran it at
 * verbosity 0), where the spec says a list or an object is refused. The web
 * launch form dropped a list as either, also one of an empty value
 * (overrides[verbosity][]=), and launched the job with the template's. It is
 * refused now on every launch path with the record's message for a list
 * ("Limit must be a string.", "Verbosity must be an integer.").
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
        $given = self::forTheRecord($overrides['limit'] ?? null, $overrides['verbosity'] ?? null);
        $job = new Job();
        $job->setAttributes($given, false);
        $fields = array_keys($given);
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
     * The launch's $limit and $verbosity for the job record to judge, by
     * field, limit before verbosity, as JobLaunchService puts them in: a
     * limit that is not empty as text, a verbosity that is set as the
     * record's integer rule reads it (never cast: the rule refuses what is no
     * whole number). A limit the launch takes for none (empty, such as "" or
     * null) is not given, and neither is a null verbosity; the integer rule
     * skips an empty string, which the launch takes for none too.
     *
     * A list or an object, an array as the request's JSON parser and a job
     * trigger's json_decode() give both, is a value for neither field, also
     * an empty one. The record's rules would skip an empty one, as they skip
     * every empty value, so it goes to them inside a list, which is no empty
     * value: they refuse it as any list, with their own message.
     *
     * @return array<string, mixed>
     */
    private static function forTheRecord(mixed $limit, mixed $verbosity): array
    {
        $given = [];
        if (is_array($limit) || !empty($limit)) {
            $given['limit'] = is_scalar($limit) ? (string)$limit : $limit;
        }
        if ($verbosity !== null) {
            $given['verbosity'] = SubmittedInteger::orAsSubmitted($verbosity);
        }

        return array_map(static fn (mixed $value): mixed => $value === [] ? [$value] : $value, $given);
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
