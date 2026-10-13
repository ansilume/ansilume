<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\controllers\api\v1\JobsController;
use app\controllers\JobController;
use app\controllers\JobTemplateController;
use app\controllers\TriggerController;
use app\models\Job;
use app\services\JobLaunchService;
use app\tests\unit\docs\ReadsOperatorDocs;
use PHPUnit\Framework\TestCase;

/**
 * What the operator docs quote of a job launch that failed, or that was
 * refused for its limit or verbosity, is what the code says: the texts of
 * the launch form, the re-launch, the API's log line and the job trigger's
 * log line around JobLaunchService::failureReason(), and the job record's
 * own messages for a limit or verbosity it does not take.
 *
 * Regressions from wave 10 of step 3, each fails on the docs as they were
 * before it: such a limit or verbosity failed the launch with a server
 * error (500 from the API and job triggers; a list or an object as the
 * verbosity was taken for a number) and no doc said that it is refused
 * now, nor how; and the texts of a failed launch said "Job launch failed: "
 * a second time, which no doc said either.
 */
class LaunchFailureDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const INPUT_ENTRY = '## Launch refused: the limit or the verbosity';

    /**
     * Regression (wave 10 of step 3): the docs quote the messages the job
     * record gives a limit or verbosity it does not take, and say where the
     * launch form, the API and job triggers answer with them.
     */
    public function testRegressionTheRefusedLimitAndVerbosityAreDocumented(): void
    {
        $tooLong = self::recordError('limit', str_repeat('w', 256));
        $tooHigh = self::recordError('verbosity', 9);
        $messages = [$tooLong, self::recordError('limit', ['web']), $tooHigh, self::recordError('verbosity', -1), self::recordError('verbosity', [3])];
        $this->assertSame('Verbosity must be no greater than 5.', $tooHigh, 'The example the docs give.');
        $answer = '`{"error": {"message": "' . $tooHigh . '", "fields": {"verbosity": "' . $tooHigh . '"}}}`';

        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions(
            '- A launch with a `limit` longer than 255 characters or a `verbosity` below 0 or above 5 is refused before anything is written, with the job record\'s message, such as `'
                . $tooHigh . '` or `' . $tooLong . '`: the launch form shows it after `'
                . sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, '') . '`, and `POST /api/v1/jobs` and job triggers answer `422` with it, also for a list or an object as either',
            $updating,
            'The fixes of updating.md'
        );
        $this->assertMentions('- `POST /api/v1/jobs` answers `422` for a `limit` or a `verbosity` that the job record does not take, with its messages in `error.message` and each such field with its message in `error.fields`, for example ' . $answer, $updating, 'The API contract change of updating.md');

        $entry = $this->flat('docs/troubleshooting.md', self::INPUT_ENTRY);
        $this->assertMentions('**Symptom:** A launch fails with `' . implode('`, `', array_slice($messages, 0, 4)) . '` or `' . $messages[4] . '`, and nothing is launched.', $entry, 'troubleshooting.md');
        $this->assertMentions('`POST /api/v1/jobs` answers `422` with it in `error.message` and with each such field and its message in `error.fields`, for example ' . $answer, $entry, 'troubleshooting.md');
        $this->assertMentions('a job trigger answers `422` with `{"error": "' . $tooHigh . '"}`.', $entry, 'troubleshooting.md');
        $this->assertMentions('[Launch refused: the limit or the verbosity](troubleshooting.md#launch-refused-the-limit-or-the-verbosity)', $updating, 'updating.md links the entry');
    }

    /**
     * Regression (wave 10 of step 3): the texts of a failed job launch name
     * the failure once (JobLaunchService::failureReason() leaves out the
     * service's own "Job launch failed: "), as the docs quote them, and the
     * docs quote the doubled texts of 2.9.0 as what they replace.
     */
    public function testRegressionTheTextsOfAFailedLaunchAreQuotedWithThePrefixOnce(): void
    {
        $failure = new \RuntimeException(sprintf(JobLaunchService::LAUNCH_FAILED, JobLaunchService::SAVE_FAILED));
        $reason = JobLaunchService::failureReason($failure);
        $this->assertSame(JobLaunchService::SAVE_FAILED, $reason);
        $texts = [
            sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, $reason),
            sprintf(JobController::RELAUNCH_FAILED, $reason),
            sprintf(JobsController::LAUNCH_FAILED_LOG, $reason),
            sprintf(TriggerController::JOB_TRIGGER_FAILED, 7, $reason),
        ];
        foreach ($texts as $text) {
            $this->assertStringNotContainsString('failed: ' . JobLaunchService::FAILURE_PREFIX, $text, "No doubled prefix: {$text}");
        }
        $this->assertSame(1, substr_count($texts[2], JobLaunchService::FAILURE_PREFIX), 'The API\'s log line says it once.');

        $this->assertMentions(
            '- The message of a job launch that failed names the failure once: the launch form shows, for example, `' . $texts[0] . '`, a re-launch `' . $texts[1]
                . '`, and the application log has `' . $texts[2] . '` for `POST /api/v1/jobs` and `' . $texts[3] . '` for a job trigger. They said `'
                . sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, JobLaunchService::FAILURE_PREFIX) . '...`, `'
                . sprintf(JobController::RELAUNCH_FAILED, JobLaunchService::FAILURE_PREFIX) . '...`, `'
                . sprintf(JobsController::LAUNCH_FAILED_LOG, JobLaunchService::FAILURE_PREFIX) . '...` and `'
                . sprintf(TriggerController::JOB_TRIGGER_FAILED, 7, JobLaunchService::FAILURE_PREFIX) . '...`.',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The fixes of updating.md'
        );
    }

    /**
     * The first message the job record gives $attribute for $value.
     */
    private static function recordError(string $attribute, mixed $value): string
    {
        $job = new Job();
        $job->setAttribute($attribute, $value);
        $job->validate([$attribute]);

        return (string)$job->getFirstError($attribute);
    }
}
