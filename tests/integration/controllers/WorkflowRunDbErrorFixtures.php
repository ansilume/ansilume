<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\WorkflowRunOperationFailure;
use app\models\WorkflowStep;
use app\services\WorkflowStepRouter;
use yii\log\Logger;

/**
 * Database errors while a workflow run is canceled or resumed, for the tests
 * of the web and the API controller: an error whose message carries
 * DB_ERROR_SECRET, a router that fails with it (a resume then fails after it
 * moved the pause step on), and the lines WorkflowRunOperationFailure logs.
 */
trait WorkflowRunDbErrorFixtures
{
    /** A value the error's message carries, which no log line and no answer may show. */
    private const DB_ERROR_SECRET = 'secret-value-8126';

    protected function dbError(): \yii\db\Exception
    {
        return new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away: UPDATE `workflow_job_step` SET `error_message`='"
                . self::DB_ERROR_SECRET . "'",
            ['HY000', 2006, 'MySQL server has gone away'],
        );
    }

    /**
     * A WorkflowStepRouter whose route() fails with dbError().
     */
    protected function failingRouter(): WorkflowStepRouter
    {
        return new class ($this->dbError()) extends WorkflowStepRouter {
            public function __construct(private readonly \Throwable $error)
            {
                parent::__construct();
            }

            public function route(WorkflowStep $step, bool $succeeded, bool $gateClosed): array
            {
                throw $this->error;
            }
        };
    }

    /**
     * Run $action and return the error lines WorkflowRunOperationFailure
     * logged meanwhile; fails when any line logged meanwhile shows $secret.
     *
     * @return list<string>
     */
    protected function failuresLoggedBy(callable $action, string $secret = self::DB_ERROR_SECRET): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $lines = [];
        foreach ($logger->messages as $message) {
            $text = match (true) {
                is_string($message[0]) => $message[0],
                $message[0] instanceof \Throwable => (string)$message[0],
                default => (string)json_encode($message[0]),
            };
            $this->assertStringNotContainsString($secret, $text, 'a log line shows the error\'s message');
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === WorkflowRunOperationFailure::class) {
                $lines[] = $text;
            }
        }

        return $lines;
    }
}
