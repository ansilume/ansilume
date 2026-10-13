<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;

/**
 * An error of the launch transaction that is no yii\db\Exception becomes a
 * RuntimeException "Job launch failed: ..." (JobLaunchService::LAUNCH_FAILED)
 * with what ErrorText may say about it.
 *
 * Regression: a PDOException that escaped Yii's wrapping (a failed commit,
 * or a driver call Yii does not wrap) was passed on with its message, which
 * can quote a value; callers flashed, answered and logged it.
 */
class JobLaunchServiceRawDbErrorTest extends DbTestCase
{
    private const MARKER = 'launch-raw-db-marker-5521';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('launch-raw-db')->id;
    }

    public function testRegressionAPdoExceptionOfTheLaunchWasPassedOnWithItsMessage(): void
    {
        $service = $this->serviceWhoseJobSaveThrows(new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '" . self::MARKER . "' for key 'PRIMARY'"
        ));
        $template = $this->plainTemplate();
        $before = (int)Job::find()->count();

        try {
            $service->launch($template, $this->userId);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Job launch failed: PDOException (SQLSTATE -, driver error -)', $e->getMessage());
            $this->assertSame(sprintf(JobLaunchService::LAUNCH_FAILED, 'PDOException (SQLSTATE -, driver error -)'), $e->getMessage());
            $this->assertNull($e->getPrevious(), 'the database error is not passed on as the previous exception either');
            $this->assertStringNotContainsString(self::MARKER, (string)$e);
        }

        $this->assertSame($before, (int)Job::find()->count(), 'nothing was written');
    }

    /**
     * Any other error keeps its message and is kept as the previous
     * exception, as before.
     */
    public function testAnotherErrorOfTheLaunchKeepsItsMessage(): void
    {
        $error = new \LogicException('The runner payload builder is not configured.');
        $service = $this->serviceWhoseJobSaveThrows($error);

        try {
            $service->launch($this->plainTemplate(), $this->userId);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Job launch failed: The runner payload builder is not configured.', $e->getMessage());
            $this->assertSame($error, $e->getPrevious());
        }
    }

    // -- Helpers ----------------------------------------------------------------

    private function serviceWhoseJobSaveThrows(\Throwable $error): JobLaunchService
    {
        return new class ($error) extends JobLaunchService {
            public function __construct(private readonly \Throwable $error)
            {
                parent::__construct();
            }

            protected function newJobRecord(): Job
            {
                return new class ($this->error) extends Job {
                    public function __construct(private readonly \Throwable $error)
                    {
                        parent::__construct();
                    }

                    /**
                     * @param array<int, string>|null $attributeNames
                     */
                    public function save($runValidation = true, $attributeNames = null): bool
                    {
                        throw $this->error;
                    }
                };
            }
        };
    }

    private function plainTemplate(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
    }
}
