<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\WorkflowStepChange;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepPosition;
use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepWriteService;
use app\tests\integration\services\InventoryGatedStepFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Inflector;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Every web path that changes a workflow's steps answers the same way. A
 * durable guard of security rule 12 for the template page:
 * - each needs workflow-template.update (the access filter);
 * - a user without operator access to the project of every job step gets
 *   403 before the step write service is called (the service checks it
 *   again under the lock, for a step added in between:
 *   WorkflowStepTeamScopeRaceTest);
 * - an operator gets 403 with the policy's reason on a workflow with an
 *   approval step or a gated job step, by the rule of its job template or
 *   of the template's inventory (C15), and nothing is written;
 * - while a run of the workflow is active nothing changes: add, move and
 *   remove answer with a danger flash, the save of the edit page shows the
 *   page again with the refusal.
 * testEveryStepActionHasACase fails when the controller gets a step action
 * (an action whose ID contains "step") without a case here.
 */
final class WorkflowStepWebPathsTest extends WebControllerTestCase
{
    use InventoryGatedStepFixtures;
    use TeamScopeFixtures;

    /** The web step paths: case => action ID. */
    private const PATHS = [
        'add-step' => 'add-step',
        'edit-step, the page' => 'edit-step',
        'edit-step, a save' => 'edit-step',
        'move-step, ▲ ▼' => 'move-step',
        'move-step, Move to…' => 'move-step',
        'remove-step' => 'remove-step',
    ];

    /** The paths that write. */
    private const WRITES = ['add-step', 'edit-step, a save', 'move-step, ▲ ▼', 'move-step, Move to…', 'remove-step'];

    /** @var array<string, mixed> */
    private array $swapped = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
    }

    protected function tearDown(): void
    {
        foreach ($this->swapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    public function testEveryStepActionHasACase(): void
    {
        $actions = [];
        foreach ((new \ReflectionClass(WorkflowTemplateController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (preg_match('/^action([A-Z]\w*)$/', $method->getName(), $name) === 1) {
                $actions[] = Inflector::camel2id($name[1]);
            }
        }
        $stepActions = array_values(array_filter($actions, static fn (string $id): bool => str_contains($id, 'step')));
        sort($stepActions);
        $cases = array_values(array_unique(self::PATHS));
        sort($cases);

        $this->assertSame($cases, $stepActions, 'every step action of WorkflowTemplateController has a case in PATHS');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::PATHS) as $path) {
            $cases[$path] = [$path];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function writeProvider(): array
    {
        return array_intersect_key(self::pathProvider(), array_flip(self::WRITES));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedPathProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::PATHS) as $path) {
            foreach (['approval step', 'gated job step', 'inventory-gated job step'] as $gate) {
                $cases[$path . ', ' . $gate] = [$path, $gate];
            }
        }

        return $cases;
    }

    /**
     * regression: a user with workflow-template.create but not .update could
     * add and remove steps (the create rule listed add-step and remove-step).
     * Every step path needs workflow-template.update now, as the API does;
     * a superadmin needs no role.
     *
     * @dataProvider pathProvider
     */
    public function testRegressionEveryStepPathNeedsTheUpdatePermission(string $path): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $before = $this->stepRows($workflow);
        $creator = $this->userWithPermissions('paths-creator', ['workflow-template.view', 'workflow-template.create']);
        $this->loginAs($creator);

        try {
            $this->runThroughTheFilters($path, $workflow);
            $this->fail('expected the access filter to deny the action');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You are not allowed to perform this action.', $e->getMessage());
        }
        $this->assertSame($before, $this->stepRows($workflow));

        $superadmin = $this->createUser('paths-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->loginAs($superadmin);
        $this->assertNotNull($this->runThroughTheFilters($path, $workflow), 'a superadmin passes the filter');
    }

    /**
     * A member who may only view a job step's project gets 403 on every step
     * path before the write service is asked (which asks it again under the
     * lock, WorkflowStepChangeGuard).
     *
     * @dataProvider pathProvider
     */
    public function testTheTeamScopeIsCheckedBeforeTheWriteService(string $path): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id, $s['open']->id);
        $before = $this->stepRows($workflow);
        $writer = $this->spyWriter();
        $this->loginAs($s['member']);

        $this->assertForbidden($path, $workflow, 'You do not have permission to modify this resource.');

        $this->assertSame(0, $writer->calls, 'the write service is not called');
        $this->assertSame($before, $this->stepRows($workflow));
    }

    /**
     * Security rule 12: only admins change the steps of a workflow with an
     * approval step or a gated job step (gated by its job template's rule or
     * its inventory's); an operator gets 403 with the reason, and nothing is
     * written.
     *
     * @dataProvider gatedPathProvider
     */
    public function testAnOperatorMayNotChangeTheStepsOfAGatedWorkflow(string $path, string $gate): void
    {
        $s = $this->teamScope();
        $workflow = $this->gatedWorkflow($s, $gate);
        $before = $this->stepRows($workflow);
        $this->loginAs($s['member']);

        $this->assertForbidden(
            $path,
            $workflow,
            match ($gate) {
                'approval step' => ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED,
                'gated job step' => ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
                default => ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED,
            }
        );

        $this->assertSame($before, $this->stepRows($workflow), 'nothing written');
        $this->assertFalse($this->stepAuditExists($workflow));
    }

    /**
     * An admin changes the steps of the same workflows on every path.
     *
     * @dataProvider gatedPathProvider
     */
    public function testAnAdminChangesTheStepsOfAGatedWorkflow(string $path, string $gate): void
    {
        $s = $this->teamScope();
        $workflow = $this->gatedWorkflow($s, $gate);
        $this->loginAs($s['admin']);

        $result = $this->runPath($path, $workflow);

        if ($path === 'edit-step, the page') {
            $this->assertSame('rendered:edit-step', $result);
            return;
        }
        $flashes = \Yii::$app->session->getAllFlashes(true);
        $this->assertArrayHasKey('success', $flashes, (string)json_encode($flashes));
        $this->assertTrue($this->stepAuditExists($workflow), 'audited');
    }

    /**
     * While a run of the workflow is active, no path changes its steps, not
     * even for an admin: the refusal is shown, nothing is written.
     *
     * @dataProvider writeProvider
     */
    public function testNobodyChangesTheStepsWhileARunIsActive(string $path): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $before = $this->stepRows($workflow);
        $refusal = WorkflowStepChangeGuard::runsActiveMessage([(int)$run->id]);
        $this->loginAs($s['admin']);

        $ctrl = $this->makeController();
        $result = $this->runWith($ctrl, $path, $workflow);

        if ($path === 'edit-step, a save') {
            $this->assertSame('rendered:edit-step', $result);
            $this->assertSame(WorkflowTemplateController::FLASH_STEP_NOT_SAVED . $refusal, $ctrl->capturedParams['error']);
            $this->assertSame([], \Yii::$app->session->getAllFlashes());
        } else {
            $prefix = match (self::PATHS[$path]) {
                'add-step' => WorkflowTemplateController::FLASH_STEP_NOT_ADDED,
                'move-step' => WorkflowTemplateController::FLASH_STEP_NOT_MOVED,
                default => '',
            };
            $this->assertSame(['danger' => $prefix . $refusal], \Yii::$app->session->getAllFlashes(true));
        }
        $this->assertSame($before, $this->stepRows($workflow), 'nothing written');
        $this->assertFalse($this->stepAuditExists($workflow));
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * Run $path on $workflow as the signed-in user, through the controller
     * directly; the first step is the one it changes.
     */
    private function runPath(string $path, WorkflowTemplate $workflow): mixed
    {
        return $this->runWith($this->makeController(), $path, $workflow);
    }

    private function runWith(WorkflowTemplateController $ctrl, string $path, WorkflowTemplate $workflow): mixed
    {
        $stepId = $this->firstStepId($workflow);
        $this->request($path, $stepId);

        return match (self::PATHS[$path]) {
            'add-step' => $ctrl->actionAddStep((int)$workflow->id),
            'edit-step' => $ctrl->actionEditStep((int)$workflow->id, $stepId),
            'move-step' => $ctrl->actionMoveStep((int)$workflow->id),
            default => $ctrl->actionRemoveStep((int)$workflow->id),
        };
    }

    /**
     * Run $path on $workflow through the controller's filters (access
     * rules and verbs), as a request does.
     */
    private function runThroughTheFilters(string $path, WorkflowTemplate $workflow): mixed
    {
        $stepId = $this->firstStepId($workflow);
        $this->request($path, $stepId);
        $params = ['id' => (int)$workflow->id] + (self::PATHS[$path] === 'edit-step' ? ['step' => $stepId] : []);

        return $this->makeController()->runAction(self::PATHS[$path], $params);
    }

    /**
     * Set up the request of $path for the step $stepId.
     */
    private function request(string $path, int $stepId): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $post = match ($path) {
            'add-step' => ['WorkflowStep' => ['name' => 'added', 'step_type' => WorkflowStep::TYPE_PAUSE]],
            'edit-step, a save' => ['WorkflowStep' => ['name' => 'renamed']],
            'move-step, ▲ ▼' => ['step_id' => (string)$stepId, 'direction' => WorkflowStepPosition::KIND_DOWN],
            'move-step, Move to…' => ['step_id' => (string)$stepId, 'position' => WorkflowStepPosition::KIND_END],
            'remove-step' => ['step_id' => (string)$stepId],
            default => null,
        };
        if ($post !== null) {
            $this->setPost($post);
        }
    }

    private function firstStepId(WorkflowTemplate $workflow): int
    {
        $first = $workflow->getStartStep();
        $this->assertNotNull($first);

        return (int)$first->id;
    }

    private function assertForbidden(string $path, WorkflowTemplate $workflow, string $message): void
    {
        try {
            $this->runPath($path, $workflow);
            $this->fail('Expected ForbiddenHttpException.');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * A workflow the member may operate, locked by $gate: an approval step
     * first, a job step whose job template has an approval rule, or one
     * whose job template's inventory (one of its own) has an approval rule.
     *
     * @param array{member: User, admin: User, own: JobTemplate, open: JobTemplate} $s
     */
    private function gatedWorkflow(array $s, string $gate): WorkflowTemplate
    {
        $rule = $this->createApprovalRule(
            $s['admin']->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [$s['admin']->id, $s['member']->id]])
        );
        if ($gate === 'approval step') {
            $workflow = $this->createWorkflowTemplate($s['admin']->id);
            $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
            $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_JOB, (int)$s['own']->id);

            return $workflow;
        }
        if ($gate === 'inventory-gated job step') {
            $this->moveToInventory((int)$s['own']->id, (int)$this->gatedInventory($s['admin']->id, (int)$rule->id)->id);
        } else {
            JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $s['own']->id]);
        }

        return $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
    }

    /**
     * A user whose only role is a custom one with $permissions.
     *
     * @param list<string> $permissions
     */
    private function userWithPermissions(string $suffix, array $permissions): User
    {
        $user = $this->createUser($suffix);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('paths-role-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    /**
     * The step write service, counting its calls.
     *
     * @return WorkflowStepWriteService&object{calls: int}
     */
    private function spyWriter(): WorkflowStepWriteService
    {
        $spy = new class () extends WorkflowStepWriteService {
            public int $calls = 0;

            public function add(WorkflowTemplate $template, WorkflowStepCommand $command, int $userId, string $source): WorkflowStepChange
            {
                $this->calls++;
                return parent::add($template, $command, $userId, $source);
            }

            public function update(WorkflowTemplate $template, int $stepId, WorkflowStepCommand $command, int $userId, string $source): WorkflowStepChange
            {
                $this->calls++;
                return parent::update($template, $stepId, $command, $userId, $source);
            }

            public function move(WorkflowTemplate $template, int $stepId, WorkflowStepPosition $to, int $userId, string $source): WorkflowStepChange
            {
                $this->calls++;
                return parent::move($template, $stepId, $to, $userId, $source);
            }

            public function remove(WorkflowTemplate $template, int $stepId, int $userId, string $source): ?WorkflowStepChange
            {
                $this->calls++;
                return parent::remove($template, $stepId, $userId, $source);
            }
        };
        if (!array_key_exists('workflowStepWriteService', $this->swapped)) {
            $this->swapped['workflowStepWriteService'] = \Yii::$app->getComponents(true)['workflowStepWriteService'] ?? null;
        }
        \Yii::$app->set('workflowStepWriteService', $spy);

        return $spy;
    }

    /**
     * @return list<array<string, mixed>> the workflow's steps as stored
     */
    private function stepRows(WorkflowTemplate $workflow): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = WorkflowStep::find()
            ->where(['workflow_template_id' => $workflow->id])
            ->orderBy(['id' => SORT_ASC])
            ->asArray()
            ->all();
        return $rows;
    }

    private function stepAuditExists(WorkflowTemplate $workflow): bool
    {
        return AuditLog::find()->where([
            'action' => [
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED,
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED,
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED,
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED,
            ],
            'object_id' => $workflow->id,
        ])->exists();
    }

    /**
     * @return WorkflowTemplateController&object{capturedParams: array<string, mixed>}
     */
    private function makeController(): WorkflowTemplateController
    {
        return new class ('workflow-template', \Yii::$app) extends WorkflowTemplateController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }
}
