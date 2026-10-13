<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\JobLaunchRejectedException;
use app\services\JobLaunchService;
use PHPUnit\Framework\TestCase;

/**
 * JobLaunchService::failureReason() is the one way for the texts that word
 * a failed job launch themselves (the web launch flash, the re-launch
 * flash, the job trigger's and the API's log lines): what ErrorText says
 * about the error, without the "Job launch failed: " that begins
 * JobLaunchService's own failures.
 *
 * Regression: those texts read "Launch failed: Job launch failed: ...",
 * "Re-launch failed: Job launch failed: ..." and
 * "Trigger for template #N failed: Job launch failed: ...".
 */
final class JobLaunchServiceFailureReasonTest extends TestCase
{
    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function failureProvider(): array
    {
        return [
            'the job could not be saved' => [
                new \RuntimeException(sprintf(JobLaunchService::LAUNCH_FAILED, JobLaunchService::SAVE_FAILED)),
                'the job could not be saved.',
            ],
            'another failure of the launch transaction' => [
                new \RuntimeException('Job launch failed: Failed to create approval request #4.'),
                'Failed to create approval request #4.',
            ],
            'a refusal keeps its message' => [
                new JobLaunchRejectedException('Job template #5 was deleted.'),
                'Job template #5 was deleted.',
            ],
            'any other error keeps its message' => [
                new \RuntimeException('The runner payload builder is not configured.'),
                'The runner payload builder is not configured.',
            ],
            'the prefix elsewhere in a message stays' => [
                new \RuntimeException('Step 2: Job launch failed: x'),
                'Step 2: Job launch failed: x',
            ],
            'a database error: its class, SQLSTATE and driver error code' => [
                new \yii\db\Exception('Job launch failed: SQLSTATE[HY000]: General error: 2006 (launch-reason-marker)', ['HY000', 2006, 'gone away']),
                'yii\db\Exception (SQLSTATE HY000, driver error 2006)',
            ],
        ];
    }

    /**
     * @dataProvider failureProvider
     */
    public function testTheReasonLeavesOutTheServicesPrefix(\Throwable $error, string $reason): void
    {
        $this->assertSame($reason, JobLaunchService::failureReason($error));
    }

    /**
     * The texts the docs quote: the service's failures begin with the
     * prefix, and a database error while the job is saved reads
     * "Job launch failed: the job could not be saved.".
     */
    public function testTheServicesFailureTexts(): void
    {
        $this->assertSame('Job launch failed: ', JobLaunchService::FAILURE_PREFIX);
        $this->assertSame('Job launch failed: %s', JobLaunchService::LAUNCH_FAILED);
        $this->assertSame('Job launch failed: the job could not be saved.', sprintf(JobLaunchService::LAUNCH_FAILED, JobLaunchService::SAVE_FAILED));
    }
}
