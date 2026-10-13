<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\controllers\WorkflowTemplateController;
use app\models\ApiToken;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\services\WorkflowLaunchRejectedException;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\db\Exception as DbException;
use yii\log\Logger;
use yii\web\Response;

/**
 * A workflow launch that fails with a database error: the web launch shows
 * it in its danger flash, the API answers 500 and logs it, both naming it by
 * class, SQLSTATE and driver error code only (ErrorText), never by its
 * message, which carries the SQL statement with its values. A refusal keeps
 * its reason: a danger flash, 422 in the API.
 *
 * The engine is replaced by one whose launch throws, as a database error
 * thrown while the run is written does.
 */
class WorkflowLaunchDatabaseErrorTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const MARKER = 'wf-launch-marker-2290';

    /** @var mixed the engine's definition before the test replaced it */
    private mixed $engine = null;

    private bool $replaced = false;

    private User $operator;

    private WorkflowTemplate $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = $this->createUserWithRole('wf-launch-db', 'operator');
        $this->workflow = $this->createWorkflowTemplate((int)$this->operator->id);
    }

    protected function tearDown(): void
    {
        if ($this->replaced) {
            $this->app()->set('workflowExecutionService', $this->engine);
            $this->replaced = false;
        }
        parent::tearDown();
    }

    // -- Web -------------------------------------------------------------------------------

    /**
     * Regression: the web workflow launch flashed a database error's
     * message. A PDOException that escaped Yii (a failed BEGIN or COMMIT)
     * reached the flash with the statement and the value it quotes.
     */
    public function testRegressionTheWebWorkflowLaunchFlashedADatabaseErrorsMessage(): void
    {
        $this->engineThrows(self::rawPdoException());
        $controller = $this->webController();

        $controller->runAction('launch');

        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertSame(['danger' => 'Launch failed: PDOException (SQLSTATE 23000, driver error 1062)'], $flashes);
        $this->assertSame(['danger' => sprintf(WorkflowTemplateController::FLASH_LAUNCH_FAILED, 'PDOException (SQLSTATE 23000, driver error 1062)')], $flashes);
        $this->assertStringNotContainsString(self::MARKER, (string)json_encode($flashes));
        $this->assertSame(['/workflow-template/view', 'id' => (int)$this->workflow->id], $controller->capturedRedirect);
    }

    public function testTheWebLaunchShowsARefusalsReason(): void
    {
        $this->engineThrows(new WorkflowLaunchRejectedException('Step "Deploy": approval rule "Prod" needs 2 approval(s).'));

        $this->webController()->runAction('launch');

        $this->assertSame(
            ['danger' => 'Launch failed: Step "Deploy": approval rule "Prod" needs 2 approval(s).'],
            \Yii::$app->session->getAllFlashes()
        );
    }

    /**
     * A yii\db\Exception is no RuntimeException: it reaches the error
     * handler (a 500 page), which logs it without its message
     * (WebErrorHandler).
     */
    public function testTheWebLaunchLeavesAYiiDatabaseErrorToTheErrorHandler(): void
    {
        $error = self::deadlock();
        $this->engineThrows($error);

        try {
            $this->webController()->runAction('launch');
            $this->fail('expected the database error to reach the error handler');
        } catch (DbException $e) {
            $this->assertSame($error, $e);
        }
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    // -- API -------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function databaseErrorProvider(): array
    {
        return [
            'a PDOException that escaped Yii, such as a failed COMMIT' => [self::rawPdoException(), 'PDOException (SQLSTATE 23000, driver error 1062)'],
            'a yii\db\Exception' => [self::deadlock(), 'yii\db\Exception (SQLSTATE 40001, driver error 1213)'],
        ];
    }

    /**
     * Regression: the API workflow launch answered a database error with
     * 422, as if the launch had been refused (a PDOException), or not at
     * all, leaving it to the error handler, which logged it with its SQL (a
     * yii\db\Exception). Now: 500 with the error named by class, SQLSTATE
     * and driver error code, logged so too, and nothing launched.
     *
     * @dataProvider databaseErrorProvider
     */
    public function testRegressionTheApiWorkflowLaunchAnsweredADatabaseErrorWith422(\Throwable $error, string $summary): void
    {
        $this->engineThrows($error);

        [$result, $logged] = $this->apiLaunchLogged();

        $this->assertSame(500, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $summary]], $result);
        $this->assertSame(["Launch of workflow template #{$this->workflow->id} failed: {$summary}"], $logged);
        $this->assertSame([sprintf(WorkflowTemplatesController::LAUNCH_FAILED_LOG, (int)$this->workflow->id, $summary)], $logged);
        $this->assertStringNotContainsString(self::MARKER, (string)json_encode([$result, $logged]));
        $this->assertSame(0, (int)WorkflowJob::find()->where(['workflow_template_id' => $this->workflow->id])->count());
    }

    /**
     * @return array<string, array{0: \RuntimeException}>
     */
    public static function refusalProvider(): array
    {
        return [
            'a refused launch' => [new WorkflowLaunchRejectedException('Workflow template #5 has no steps.')],
            'a run that could not be saved' => [new \RuntimeException('Failed to create workflow job: {"status":["Status is invalid."]}')],
        ];
    }

    /**
     * Any other error of the launch answers 422 with its message, logs
     * nothing, as before.
     *
     * @dataProvider refusalProvider
     */
    public function testTheApiAnswersARefusalWith422AndItsReason(\RuntimeException $refusal): void
    {
        $this->engineThrows($refusal);

        [$result, $logged] = $this->apiLaunchLogged();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $refusal->getMessage()]], $result);
        $this->assertSame([], $logged);
    }

    public function testTheApiAnswersARefusalByTeamScopingWith403(): void
    {
        $this->engineThrows(new WorkflowAccessDeniedException('You may not launch this workflow.', [12, 13]));

        [$result] = $this->apiLaunchLogged();

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'You may not launch this workflow.', 'job_template_ids' => [12, 13]]], $result);
    }

    // -- Helpers ---------------------------------------------------------------------------

    /**
     * A PDOException as PDO raises it for a duplicate key: its message and
     * error info quote the value.
     */
    private static function rawPdoException(): \PDOException
    {
        $error = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '" . self::MARKER . "' for key 'PRIMARY'");
        $error->errorInfo = ['23000', 1062, "Duplicate entry '" . self::MARKER . "' for key 'PRIMARY'"];

        return $error;
    }

    private static function deadlock(): DbException
    {
        return new DbException(
            "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found\nThe SQL being executed was: "
                . "INSERT INTO `workflow_job` (`extra_vars`) VALUES ('{\"db_password\":\"" . self::MARKER . "\"}')",
            ['40001', 1213, 'Deadlock found when trying to get lock']
        );
    }

    private function engineThrows(\Throwable $error): void
    {
        if (!$this->replaced) {
            $this->engine = $this->app()->getComponents(true)['workflowExecutionService'] ?? null;
            $this->replaced = true;
        }
        $this->app()->set('workflowExecutionService', new class ($error) extends WorkflowExecutionService {
            public function __construct(private readonly \Throwable $error)
            {
                parent::__construct();
            }

            public function launch(
                WorkflowTemplate $template,
                int $launchedBy,
                #[\SensitiveParameter] array $overrides = [],
                string $source = 'web'
            ): WorkflowJob {
                throw $this->error;
            }
        });
    }

    /**
     * The API's answer to the operator's launch, and the error lines logged
     * meanwhile under the controller's category.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private function apiLaunchLogged(): array
    {
        ['raw' => $raw] = ApiToken::generate((int)$this->operator->id, 'workflow-launch-db-error-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        $this->setPost([]);
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = (new WorkflowTemplatesController('api/v1/workflow-templates', $this->app()))
                ->runAction('launch', ['id' => $this->workflow->id]);
        } finally {
            \Yii::setLogger($previous);
        }
        $logged = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === WorkflowTemplatesController::class && is_string($message[0])) {
                $logged[] = $message[0];
            }
        }

        return [$result, $logged];
    }

    /**
     * The web controller, as the operator launches the workflow.
     *
     * @return WorkflowTemplateController&object{capturedRedirect: array<int|string, mixed>}
     */
    private function webController(): WorkflowTemplateController
    {
        $this->loginAs($this->operator);
        $this->setQueryParams(['id' => (string)$this->workflow->id]);
        $this->setPost([]);

        return new class ('workflow-template', $this->app()) extends WorkflowTemplateController {
            /** @var array<int|string, mixed> */
            public array $capturedRedirect = [];

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = (array)$url;

                return new Response();
            }
        };
    }

    private function app(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }
}
