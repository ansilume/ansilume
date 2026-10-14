<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowStepsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\WorkflowStepWriteFixtures;

/**
 * Durable guard of the API's step write paths (security rule 12, contracts
 * C6 and C13): every request that changes the steps of an existing workflow
 * through the API refuses an operator on a workflow with an approval step or
 * a gated job step (403), and everyone while a run of the workflow is active
 * (409), and writes nothing then. The paths are the per-step endpoints and
 * the bulk PUT with a "steps" list, each sent through the URL rules of
 * config/web.php. A URL rule that changes steps, or an action of the
 * per-step controller, added later without its case in PATHS fails
 * testEveryStepWritePathOfTheApiHasACase(), so no API path can skip the
 * guard unnoticed. (Creating a workflow with steps has no runs and no stored
 * lock; its gated steps are WorkflowTemplatesStepGuardTest's.)
 */
class WorkflowStepApiPathsTest extends WebControllerTestCase
{
    use WorkflowStepApiRequests;
    use WorkflowStepWriteFixtures;

    /** Each step write path of the API, "<verb> <URL pattern>" => what it does. */
    private const PATHS = [
        'POST api/v1/workflow-templates/<id:\d+>/steps' => 'create',
        'PUT api/v1/workflow-templates/<id:\d+>/steps/<stepId:\d+>' => 'update',
        'DELETE api/v1/workflow-templates/<id:\d+>/steps/<stepId:\d+>' => 'delete',
        'POST api/v1/workflow-templates/<id:\d+>/steps/<stepId:\d+>/move' => 'move',
        'PUT api/v1/workflow-templates/<id:\d+>' => 'bulk',
    ];

    /** The routes that change steps besides the per-step controller's: the bulk PUT takes a "steps" list. */
    private const OTHER_STEP_ROUTES = ['api/v1/workflow-templates/update'];

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
    }

    public function testEveryStepWritePathOfTheApiHasACase(): void
    {
        $found = [];
        foreach ($this->apiRules() as ['pattern' => $pattern, 'route' => $route, 'verb' => $verb]) {
            $stepRoute = str_starts_with($route, self::STEPS_ROUTE) || in_array($route, self::OTHER_STEP_ROUTES, true);
            if ($verb !== 'GET' && ($stepRoute || str_contains($pattern, 'steps'))) {
                $found[] = $verb . ' ' . $pattern;
            }
        }
        sort($found);
        $paths = array_keys(self::PATHS);
        sort($paths);

        $this->assertSame($paths, $found, 'every API rule that changes steps needs a case in PATHS');

        $actions = [];
        foreach ((new \ReflectionClass(WorkflowStepsController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (preg_match('/^action([A-Z]\w*)$/', $method->getName(), $m) === 1 && $method->getDeclaringClass()->getName() === WorkflowStepsController::class) {
                $actions[] = strtolower($m[1]);
            }
        }
        sort($actions);
        $covered = array_values(array_diff(self::PATHS, ['bulk']));
        sort($covered);

        $this->assertSame($covered, $actions, 'every action of the per-step controller needs a case in PATHS');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lockedPathProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::PATHS) as $path) {
            $cases["{$path}, an approval step"] = [$path, 'approval'];
            $cases["{$path}, a gated job step"] = [$path, 'gated job'];
        }

        return $cases;
    }

    /**
     * @dataProvider lockedPathProvider
     */
    public function testAnOperatorMayNotChangeTheStepsOfAGatedWorkflow(string $path, string $gate): void
    {
        $workflow = $this->workflow();
        if ($gate === 'approval') {
            $this->namedStep((int)$workflow->id, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, null, (int)$this->rule()->id);
        } else {
            $this->namedStep((int)$workflow->id, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate()->id);
        }
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);

        $answer = $this->send($path, $workflow);

        $this->assertApiError(
            403,
            $gate === 'approval'
                ? ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED
                : ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            $answer
        );
        $this->assertNothingWritten($workflow, $before);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathProvider(): array
    {
        $paths = array_keys(self::PATHS);

        return array_combine($paths, array_map(static fn (string $path): array => [$path], $paths));
    }

    /**
     * @dataProvider pathProvider
     */
    public function testNobodyChangesTheStepsWhileARunIsActive(string $path): void
    {
        $workflow = $this->workflow();
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->s['admin']->id);
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['admin']);

        $answer = $this->send($path, $workflow);

        $this->assertApiError(409, WorkflowStepChangeGuard::runsActiveMessage([(int)$run->id]), $answer, ['workflow_job_ids' => [(int)$run->id]]);
        $this->assertNothingWritten($workflow, $before);
    }

    /**
     * The same requests go through for an admin when no run is active: the
     * refusals above are the guard's and the policy's, not the cases'.
     *
     * @dataProvider pathProvider
     */
    public function testAnAdminChangesTheStepsOfAGatedWorkflow(string $path): void
    {
        $workflow = $this->workflow();
        $this->namedStep((int)$workflow->id, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, null, (int)$this->rule()->id);
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['admin']);

        $answer = $this->send($path, $workflow);

        $this->assertSame(self::PATHS[$path] === 'create' ? 201 : 200, $answer['status'], (string)json_encode($answer['body']));
        $this->assertNotSame($before, $this->stepRows((int)$workflow->id));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A workflow with one plain job step, Build, on the member's own job
     * template.
     */
    private function workflow(): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['own']->id);

        return $workflow;
    }

    /**
     * Send the request of $path to $workflow's step Build, with a body that
     * changes the steps, through the URL rules and the controller they
     * route to (access rules included).
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function send(string $path, WorkflowTemplate $workflow): array
    {
        $id = (int)$workflow->id;
        $build = $this->stepIdOf($id, 'Build');

        return match (self::PATHS[$path]) {
            'create' => $this->api('POST', $this->stepsPath($id), ['name' => 'Lint', 'step_type' => 'pause']),
            'update' => $this->api('PUT', $this->stepPath($id, $build), ['name' => 'Compile']),
            'delete' => $this->api('DELETE', $this->stepPath($id, $build)),
            'move' => $this->api('POST', $this->movePath($id, $build), ['position' => 'end']),
            default => $this->bulkUpdate($workflow),
        };
    }

    /**
     * PUT /api/v1/workflow-templates/{id} with a "steps" list, through the
     * URL rules and WorkflowTemplatesController.
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function bulkUpdate(WorkflowTemplate $workflow): array
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['steps' => [['name' => 'Only', 'step_type' => 'pause']]]);
        $request->setPathInfo('api/v1/workflow-templates/' . (int)$workflow->id);
        $request->setQueryParams([]);
        $parsed = $this->webRules()->parseRequest($request);
        $this->assertSame(['api/v1/workflow-templates/update', ['id' => (string)$workflow->id]], $parsed);
        \Yii::$app->response->statusCode = 200;
        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app))->runAction('update', ['id' => (string)$workflow->id]);
        $this->assertIsArray($result);

        return ['status' => \Yii::$app->response->statusCode, 'body' => $result];
    }

    /**
     * The workflow's steps are as they were, and neither a step nor the
     * workflow was audited as changed.
     *
     * @param array<int, array<string, mixed>> $before
     */
    private function assertNothingWritten(WorkflowTemplate $workflow, array $before): void
    {
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
        $this->assertFalse(AuditLog::find()->where([
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED,
            'object_id' => $workflow->id,
        ])->exists());
    }

    /**
     * The API rules of config/web.php that name a verb, as pattern, route
     * and verb.
     *
     * @return list<array{pattern: string, route: string, verb: string}>
     */
    private function apiRules(): array
    {
        /** @var array{components: array{urlManager: array{rules: array<mixed>}}} $config */
        $config = require dirname(__DIR__, 5) . '/config/web.php';
        $rules = [];
        foreach ($config['components']['urlManager']['rules'] as $rule) {
            if (is_array($rule) && is_string($rule['verb'] ?? null) && str_starts_with((string)$rule['pattern'], 'api/v1/')) {
                $rules[] = ['pattern' => (string)$rule['pattern'], 'route' => (string)$rule['route'], 'verb' => $rule['verb']];
            }
        }
        $this->assertNotSame([], $rules);

        return $rules;
    }

    /**
     * A job template in the member's own project, gated by an approval rule.
     */
    private function gatedTemplate(): JobTemplate
    {
        $own = $this->s['own'];
        $template = $this->createJobTemplate((int)$own->project_id, (int)$own->inventory_id, (int)$own->runner_group_id, (int)$this->s['admin']->id);
        JobTemplate::updateAll(['approval_rule_id' => (int)$this->rule()->id], ['id' => $template->id]);

        return $template;
    }

    /**
     * An approval rule whose approvers are the admin and the member.
     */
    private function rule(): ApprovalRule
    {
        return $this->createApprovalRule(
            (int)$this->s['admin']->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [(int)$this->s['admin']->id, (int)$this->s['member']->id]])
        );
    }
}
