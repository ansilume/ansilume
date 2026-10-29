<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;

/**
 * JobLaunchService puts the launch's verbosity into the job record as the
 * record's integer rule reads it: a whole number as an int, also when it
 * comes as text or as a float such as 5.0; an empty one ("", an empty list),
 * which the rule skips, as none, so that the job runs at the template's
 * verbosity; anything else as it is, for the record to refuse. The request
 * paths refuse such input before the launch (JobLaunchInput), an empty list
 * or object too; this is the launch of a caller that does not.
 *
 * Regression: the launch cast the verbosity with (int). A float beyond the
 * int range, such as the JSON number 1e30, made the cast warn on PHP 8.5
 * ("The float 1.0E+30 is not representable as an int, cast occurred"), and
 * the error handler turned the warning into an exception; up to PHP 8.4 the
 * cast wrapped it, and infinity became verbosity 0.
 */
class JobLaunchServiceVerbosityTest extends DbTestCase
{
    private JobLaunchService $service;

    private int $userId;

    private JobTemplate $template;

    /** The notification dispatcher of the application, put back after the test. */
    private mixed $dispatcher = null;

    protected function setUp(): void
    {
        parent::setUp();
        // A launch notifies job.launched: record it instead of sending it.
        $this->dispatcher = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        \Yii::$app->set('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->service = \Yii::$app->get('jobLaunchService');
        $this->userId = (int)$this->createUser('launch-verbosity')->id;
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $this->template->updateAttributes(['verbosity' => 2]);
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('notificationDispatcher', $this->dispatcher);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function wholeNumberProvider(): array
    {
        return [
            'an int' => [3, 3],
            'zero' => [0, 0],
            'text' => ['4', 4],
            'text with a sign and a zero' => ['+05', 5],
            'the JSON number 5.0' => [json_decode('5.0'), 5],
        ];
    }

    /**
     * @dataProvider wholeNumberProvider
     */
    public function testAWholeNumberIsPutInAsAnInt(mixed $verbosity, int $expected): void
    {
        $job = $this->service->launch($this->template, $this->userId, ['verbosity' => $verbosity]);

        $this->assertSame($expected, $job->verbosity);
        $this->assertSame($expected, $this->reload($job)->verbosity);
        $this->assertSame($expected, $job->decodedRunnerPayload()['verbosity'] ?? null, 'the runner payload\'s int');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function emptyProvider(): array
    {
        return [
            'empty text' => [''],
            'an empty list' => [[]],
        ];
    }

    /**
     * The cast made an empty verbosity 0.
     *
     * @dataProvider emptyProvider
     */
    public function testAnEmptyVerbosityIsNoneSoTheTemplatesApplies(mixed $verbosity): void
    {
        $job = $this->service->launch($this->template, $this->userId, ['verbosity' => $verbosity]);

        $this->assertNull($job->verbosity);
        $this->assertNull($this->reload($job)->verbosity);
        $this->assertSame(2, $job->decodedRunnerPayload()['verbosity'] ?? null, 'the template\'s verbosity');
    }

    /**
     * Floats beyond the int range as the request's JSON parser gives them
     * (1e999 as infinity), and other values that are no whole number.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function notAWholeNumberProvider(): array
    {
        $beyondTheIntRange = json_decode('[1e30, 9223372036854775808, 1e999, -1e999]', true);

        return [
            'the JSON number 1e30' => [$beyondTheIntRange[0]],
            'the JSON number 9223372036854775808' => [$beyondTheIntRange[1]],
            'the JSON number 1e999, infinity' => [$beyondTheIntRange[2]],
            'the JSON number -1e999, minus infinity' => [$beyondTheIntRange[3]],
            'a fraction' => [2.5],
            'text that is no number' => ['abc'],
            'true' => [true],
        ];
    }

    /**
     * Regression: a launch with a verbosity beyond the int range failed with
     * an ErrorException on PHP 8.5 ("The float 1.0E+30 is not representable
     * as an int, cast occurred"). The job record refuses it now, as it
     * refuses a verbosity above 5, and nothing is written.
     *
     * @dataProvider notAWholeNumberProvider
     */
    public function testRegressionAVerbosityThatIsNoWholeNumberIsRefusedByTheJobRecord(mixed $verbosity): void
    {
        $before = (int)Job::find()->count();

        // Not fail() inside the try: PHPUnit's assertion failure is a RuntimeException too.
        $failure = null;
        try {
            $this->service->launch($this->template, $this->userId, ['verbosity' => $verbosity]);
        } catch (\RuntimeException $e) {
            $failure = $e;
        }

        $this->assertNotNull($failure, 'the launch fails');
        // The record's errors, the first being this one; 1e30 is also "no greater than 5".
        $this->assertStringStartsWith(
            'Job launch failed: Failed to save job record: {"verbosity":["Verbosity must be an integer."',
            $failure->getMessage()
        );
        $this->assertSame($before, (int)Job::find()->count(), 'nothing was written');
    }

    private function reload(Job $job): Job
    {
        $reloaded = Job::findOne($job->id);
        $this->assertNotNull($reloaded);

        return $reloaded;
    }
}
