<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\HealthController;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\ScheduleFailingCommand;
use yii\console\ExitCode;

/**
 * health/check names a database error of its database, migration, RBAC and
 * user checks only by its class, SQLSTATE and driver error code (ErrorText),
 * never by its message, which quotes the SQL statement. Docker keeps the
 * check's output in the container's health log.
 *
 * Regression: the check printed the error's message.
 */
class HealthControllerDbErrorTest extends DbTestCase
{
    /** What ErrorText says about the error ScheduleFailingCommand throws. */
    private const SUMMARY = 'yii\db\Exception (SQLSTATE HY000, driver error 2006)';

    public function testRegressionADatabaseErrorOfACheckWasPrintedWithItsStatement(): void
    {
        $output = new \ArrayObject();
        $ctrl = $this->makeController($output);
        $db = \Yii::$app->db;
        $driver = $db->getDriverName();
        $previousCommand = $db->commandMap[$driver] ?? null;
        $db->commandMap[$driver] = [
            'class' => ScheduleFailingCommand::class,
            'failingPattern' => '~^SELECT 1$|FROM `migration`|FROM `auth_item`|FROM `user`~',
        ];
        try {
            $result = $ctrl->actionCheck();
        } finally {
            if ($previousCommand === null) {
                unset($db->commandMap[$driver]);
            } else {
                $db->commandMap[$driver] = $previousCommand;
            }
        }

        $captured = implode('', $output->getArrayCopy());
        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
        $this->assertStringNotContainsString('The SQL being executed', $captured);
        $this->assertStringNotContainsString('SQLSTATE[', $captured);
        $this->assertStringContainsString("[health] db: error — yii\\db\\Exception (SQLSTATE HY000, driver error 2006)\n", $captured);
        foreach (['db', 'migrations', 'rbac', 'users'] as $check) {
            $this->assertStringContainsString(sprintf(HealthController::CHECK_ERROR, $check, self::SUMMARY), $captured);
        }
        $this->assertStringContainsString('[health] redis: ok', $captured, 'the other checks still run');
        $this->assertStringContainsString('[health] status: degraded', $captured);
    }

    /**
     * @param \ArrayObject<int, string> $output collects what the check prints
     */
    private function makeController(\ArrayObject $output): HealthController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return new class ('health', $app, $output) extends HealthController {
            /**
             * @param \ArrayObject<int, string> $output
             */
            public function __construct(string $id, \yii\base\Module $module, private readonly \ArrayObject $output)
            {
                parent::__construct($id, $module);
            }

            public function stdout($string): int
            {
                $this->output->append((string)$string);
                return 0;
            }

            public function stderr($string): int
            {
                $this->output->append((string)$string);
                return 0;
            }
        };
    }
}
