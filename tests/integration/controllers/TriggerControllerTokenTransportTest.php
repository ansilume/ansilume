<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveySecrets;
use app\components\TriggerTokenResolver;
use app\controllers\TriggerController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * How an inbound trigger call presents its token, with the real launch
 * services: in the X-Trigger-Token header, in the URL (deprecated), or as
 * `Authorization: Bearer`. A launch is audited as trigger.fired with the
 * transport and what it launched, never with the token.
 */
class TriggerControllerTokenTransportTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use TeamScopeFixtures;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    private RecordingNotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new RecordingNotificationDispatcher();
        $this->swapped[] = ['notificationDispatcher', \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null];
        \Yii::$app->set('notificationDispatcher', $this->dispatcher);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    // ── Transports ───────────────────────────────────────────────────────────

    /**
     * @dataProvider transportProvider
     */
    public function testFireTakesTheTokenFromEveryTransportAndAuditsHowItCame(string $transport): void
    {
        $user = $this->createUserWithRole('trigger_user', 'operator');
        $template = $this->openTemplate($user);
        $raw = $template->generateTriggerToken((int)$user->id);
        $this->presentTriggerToken($raw, $transport);

        $result = $this->controller()->actionFire();

        $this->assertSame(201, $result->statusCode);
        $job = $this->launchedJob($result);
        $this->assertSame((int)$user->id, (int)$job->launched_by);
        $fired = $this->firedEntry('job_template', (int)$template->id);
        $this->assertSame((int)$user->id, (int)$fired->user_id, 'audited as the user the trigger runs as');
        $this->assertSame(['transport' => $transport, 'job_id' => (int)$job->id], $this->metadata($fired));
        $this->assertStringNotContainsString($raw, (string)json_encode($fired->attributes), 'the audit never holds the token');
    }

    /**
     * @dataProvider transportProvider
     */
    public function testFireWorkflowTakesTheTokenFromEveryTransportAndAuditsHowItCame(string $transport): void
    {
        $user = $this->createUserWithRole('trigger_user', 'operator');
        $workflow = $this->pauseWorkflow($user);
        $raw = $workflow->generateTriggerToken((int)$user->id);
        $this->presentTriggerToken($raw, $transport);

        $result = $this->controller()->actionFireWorkflow();

        $this->assertSame(201, $result->statusCode);
        $this->assertIsArray($result->data);
        $wfJob = WorkflowJob::findOne($result->data['workflow_job_id'] ?? 0);
        $this->assertNotNull($wfJob);
        $fired = $this->firedEntry('workflow_template', (int)$workflow->id);
        $this->assertSame((int)$user->id, (int)$fired->user_id);
        $this->assertSame(['transport' => $transport, 'workflow_job_id' => (int)$wfJob->id], $this->metadata($fired));
        $this->assertStringNotContainsString($raw, (string)json_encode($fired->attributes));
    }

    public function testTheHeaderWinsOverTheUrlAndTheUrlOverBearer(): void
    {
        $user = $this->createUserWithRole('trigger_user', 'operator');
        $byHeader = $this->openTemplate($user);
        $byUrl = $this->openTemplate($user);
        $byBearer = $this->openTemplate($user);
        $this->presentTriggerToken($byHeader->generateTriggerToken((int)$user->id), TriggerTokenResolver::TRANSPORT_HEADER);
        $this->presentTriggerToken($byUrl->generateTriggerToken((int)$user->id), TriggerTokenResolver::TRANSPORT_URL);
        $this->presentTriggerToken($byBearer->generateTriggerToken((int)$user->id), TriggerTokenResolver::TRANSPORT_BEARER);

        $this->assertSame((int)$byHeader->id, (int)$this->launchedJob($this->controller()->actionFire())->job_template_id);

        \Yii::$app->request->getHeaders()->remove(TriggerTokenResolver::HEADER);
        $this->assertSame(
            (int)$byUrl->id,
            (int)$this->launchedJob($this->controller()->actionFire())->job_template_id,
            'a caller with a URL token and an unrelated Authorization header keeps working'
        );
    }

    /**
     * An Authorization header of any other scheme, for example Basic
     * credentials for a proxy in front of Ansilume, is not a token.
     */
    public function testBasicCredentialsAreNotAToken(): void
    {
        \Yii::$app->request->getHeaders()->set('Authorization', 'Basic ' . base64_encode('proxy:secret'));
        $this->setPost([]);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Missing trigger token.');
        $this->controller()->actionFire();
    }

    /**
     * @return array<string, array{0: string, 1: callable(\yii\web\Request): void}>
     */
    public static function missingTokenProvider(): array
    {
        $cases = [];
        foreach (['fire', 'fire-workflow'] as $action) {
            $cases["{$action}, nothing"] = [$action, static function (\yii\web\Request $request): void {
            }];
            $cases["{$action}, empty header"] = [$action, static function (\yii\web\Request $request): void {
                $request->getHeaders()->set(TriggerTokenResolver::HEADER, '');
            }];
            $cases["{$action}, empty URL token"] = [$action, static function (\yii\web\Request $request): void {
                $request->setQueryParams(['token' => '']);
            }];
            $cases["{$action}, token[]=x"] = [$action, static function (\yii\web\Request $request): void {
                $request->setQueryParams(['token' => ['x']]);
            }];
            $cases["{$action}, Bearer without credentials"] = [$action, static function (\yii\web\Request $request): void {
                $request->getHeaders()->set('Authorization', 'Bearer ');
            }];
        }

        return $cases;
    }

    /**
     * Regression: an empty ?token= answered 404 and notified operators of an
     * invalid token; token[]=x is no token either.
     *
     * @dataProvider missingTokenProvider
     * @param callable(\yii\web\Request): void $present
     */
    public function testACallWithoutAUsableTokenIs400(string $action, callable $present): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $present($request);
        $this->setPost([]);
        $controller = $this->controller();

        try {
            $action === 'fire' ? $controller->actionFire() : $controller->actionFireWorkflow();
            $this->fail('Expected BadRequestHttpException');
        } catch (BadRequestHttpException $e) {
            $this->assertSame('Missing trigger token.', $e->getMessage());
        }
        $this->assertSame([], $this->dispatcher->events, 'no invalid-token notification');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unknownTokenProvider(): array
    {
        return [
            'fire, header' => ['fire', TriggerTokenResolver::TRANSPORT_HEADER],
            'fire, bearer' => ['fire', TriggerTokenResolver::TRANSPORT_BEARER],
            'fire-workflow, header' => ['fire-workflow', TriggerTokenResolver::TRANSPORT_HEADER],
            'fire-workflow, URL' => ['fire-workflow', TriggerTokenResolver::TRANSPORT_URL],
        ];
    }

    /**
     * @dataProvider unknownTokenProvider
     */
    public function testAnUnknownTokenIs404AndNotifiesWithItsFirstSixCharactersOnly(string $action, string $transport): void
    {
        $unknown = 'abcdef' . str_repeat('0', 58);
        $this->presentTriggerToken($unknown, $transport);
        $controller = $this->controller();

        try {
            $action === 'fire' ? $controller->actionFire() : $controller->actionFireWorkflow();
            $this->fail('Expected NotFoundHttpException');
        } catch (NotFoundHttpException $e) {
            $this->assertSame('Trigger not found.', $e->getMessage());
        }

        $notified = $this->dispatcher->payloadsOf(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN);
        $this->assertCount(1, $notified);
        $trigger = $notified[0]['trigger'] ?? null;
        $this->assertIsArray($trigger);
        $this->assertSame('abcdef', $trigger['token_prefix'] ?? null);
        $this->assertSame($action === 'fire' ? null : 'workflow', $trigger['kind'] ?? null);
        $this->assertStringNotContainsString($unknown, (string)json_encode($notified));
    }

    // ── Regressions through Yii's own dispatch ─────────────────────────────

    /**
     * Regression: the token was only read from the URL. A caller that sent
     * it in the X-Trigger-Token header got 400 "Missing required parameters".
     *
     * @return array<string, array{0: string}>
     */
    public static function headerTransportProvider(): array
    {
        return [
            'X-Trigger-Token' => [TriggerTokenResolver::TRANSPORT_HEADER],
            'Authorization: Bearer' => [TriggerTokenResolver::TRANSPORT_BEARER],
        ];
    }

    /**
     * @dataProvider headerTransportProvider
     */
    public function testRegressionATokenInAHeaderLaunches(string $transport): void
    {
        $user = $this->createUserWithRole('trigger_user', 'operator');
        $template = $this->openTemplate($user);
        $workflow = $this->pauseWorkflow($user);

        $this->presentTriggerToken($template->generateTriggerToken((int)$user->id), $transport);
        $job = $this->dispatch('fire');
        $this->assertInstanceOf(Response::class, $job);
        $this->assertSame(201, $job->statusCode);

        \Yii::$app->request->getHeaders()->removeAll();
        $this->presentTriggerToken($workflow->generateTriggerToken((int)$user->id), $transport);
        $workflowJob = $this->dispatch('fire-workflow');
        $this->assertInstanceOf(Response::class, $workflowJob);
        $this->assertSame(201, $workflowJob->statusCode);
    }

    /**
     * Regression: the token was the actions' argument, so the stack trace of
     * every exception thrown inside them (Yii logs it with the exception)
     * held its first 15 characters.
     *
     * The images set zend.exception_ignore_args = On, which leaves every
     * argument out of traces, so the old actionFire($token) would pass too.
     * phpunit.xml turns it off for the tests, as on a host whose php.ini
     * leaves it off: there only an action without the token as its argument
     * passes. The test turns it off itself too, so it holds under another
     * configuration.
     */
    public function testRegressionTheTokenStaysOutOfStackTraces(): void
    {
        $unknown = 'f1e2d3c4b5a69788' . str_repeat('9', 48);
        $this->setQueryParams(['token' => $unknown]);
        $this->setPost([]);

        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->dispatch('fire');
            $this->fail('Expected NotFoundHttpException');
        } catch (NotFoundHttpException $e) {
            $this->assertStringNotContainsString(substr($unknown, 0, 8), (string)$e);
            $this->assertStringContainsString('TriggerController->actionFire()', $e->getTraceAsString());
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
    }

    /**
     * Regression: a job trigger whose launch was refused for a reason the
     * caller can fix (here: it sent the placeholder as a password answer)
     * answered 500 "Launch failed." without the reason.
     */
    public function testRegressionARefusedJobLaunchAnswers422WithTheReason(): void
    {
        $user = $this->createUserWithRole('trigger_user', 'operator');
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        // In the URL: the transport every version understands.
        $this->presentTriggerToken($template->generateTriggerToken((int)$user->id), TriggerTokenResolver::TRANSPORT_URL);
        \Yii::$app->request->setRawBody((string)json_encode([
            'extra_vars' => [self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER],
        ]));

        $result = $this->dispatch('fire');

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame(
            ['error' => 'The value of "' . self::SURVEY_PASSWORD_FIELD . '" is the placeholder $encrypted$; send the real value.'],
            $result->data
        );
        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists(), 'no job is launched');
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_TRIGGER_FIRED])->andWhere(['object_id' => $template->id])->exists());
    }

    /**
     * A password answer sent by a trigger is stored encrypted, like one from
     * any other launch source.
     */
    public function testAPasswordAnswerSentByATriggerIsStoredEncrypted(): void
    {
        $user = $this->createUserWithRole('trigger_user', 'operator');
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $this->presentTriggerToken($template->generateTriggerToken((int)$user->id));
        \Yii::$app->request->setRawBody((string)json_encode([
            'extra_vars' => [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER],
        ]));

        $job = $this->launchedJob($this->controller()->actionFire());

        $this->assertSame(
            [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER],
            json_decode((string)$job->extra_vars, true)
        );
        $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, (string)$job->runner_payload);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
    }

    /**
     * GET /trigger/<token> reaches the fire action (it used to fall through
     * to a route error that logged the token) and answers 405 there.
     */
    public function testAGetIs405AndLaunchesNothing(): void
    {
        $user = $this->createUserWithRole('trigger_user', 'operator');
        $template = $this->openTemplate($user);
        $this->setQueryParams(['token' => $template->generateTriggerToken((int)$user->id)]);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $this->dispatch('fire');
            $this->fail('Expected MethodNotAllowedHttpException');
        } catch (MethodNotAllowedHttpException) {
            // expected
        }
        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists());
    }

    /**
     * Every other path under /trigger/ (routed to trigger/not-found) is a
     * 404 that does not repeat the path, which may hold a token.
     *
     * @return array<string, array{0: string}>
     */
    public static function verbProvider(): array
    {
        return ['GET' => ['GET'], 'POST' => ['POST'], 'DELETE' => ['DELETE']];
    }

    /**
     * The path stays out of the trace too: the action takes no argument.
     * That half needs the arguments in traces, which phpunit.xml keeps
     * (zend.exception_ignore_args = 0); with the setting On, an action
     * that took the path as its argument would pass as well (fix round 4).
     *
     * @dataProvider verbProvider
     */
    public function testAnyOtherPathIs404WithoutEchoingIt(string $verb): void
    {
        $path = 'fire/' . str_repeat('c0ffee', 10) . 'beef';
        $this->setQueryParams(['token' => $path]);
        $_SERVER['REQUEST_METHOD'] = $verb;

        try {
            $this->dispatch('not-found');
            $this->fail('Expected NotFoundHttpException');
        } catch (NotFoundHttpException $e) {
            $this->assertSame('Page not found.', $e->getMessage());
            $this->assertStringNotContainsString('c0ffee', (string)$e);
        }
        $this->assertSame([], $this->dispatcher->events, 'a malformed path is no invalid-token event');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function controller(): TriggerController
    {
        return new TriggerController('trigger', \Yii::$app);
    }

    /**
     * Run the action as the web application does: with the query
     * parameters, which hold the route's parameters too, as action
     * parameters (Application::handleRequest()), and the controller's
     * filters.
     */
    private function dispatch(string $actionId): mixed
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;

        return $this->controller()->runAction($actionId, $request->getQueryParams());
    }

    /**
     * A job template of a project without a team, so every operator may
     * launch it.
     */
    private function openTemplate(User $user): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
    }

    /**
     * A workflow with a single pause step: it launches without job steps.
     */
    private function pauseWorkflow(User $user): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);

        return $workflow;
    }

    private function launchedJob(Response $result): Job
    {
        $this->assertSame(201, $result->statusCode);
        $this->assertIsArray($result->data);
        $job = Job::findOne($result->data['job_id'] ?? 0);
        $this->assertNotNull($job);

        return $job;
    }

    private function firedEntry(string $objectType, int $templateId): AuditLog
    {
        /** @var AuditLog[] $entries */
        $entries = AuditLog::find()->where([
            'action' => AuditLog::ACTION_TRIGGER_FIRED,
            'object_type' => $objectType,
            'object_id' => $templateId,
        ])->all();
        $this->assertCount(1, $entries);

        return $entries[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(AuditLog $entry): array
    {
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
    }
}
