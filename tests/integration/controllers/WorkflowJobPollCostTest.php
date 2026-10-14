<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\AuthManager;
use app\controllers\WorkflowJobController;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\TeamProject;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\runstate\Display;
use app\services\runstate\Texts;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * What one poll of the run page (GET /workflow-job/status) costs, measured
 * as a request runs it: through runAction('status') with the controller's
 * filters, with a fresh RBAC manager that has the production default role
 * (app\components\AuthManager, defaultRoles ['viewer']), a fresh user
 * component and the identity read as a request reads it
 * (User::findIdentity()).
 *
 * The run waits at an approval step whose rule lists an admin, who launched
 * the run, and an operator. A job step ran before it, of a project that the
 * operator's team operates, so team scoping applies.
 */
class WorkflowJobPollCostTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use CountsQueries;

    /**
     * The statements an operator's poll of that run costs at most: 64 in
     * 2.11.0, measured; 81 before the approvers' eligibility was computed
     * once per poll and workflow.launch checked only for a paused run.
     */
    private const OPERATOR_POLL_BUDGET = 64;

    /** @var array<string, mixed> the components a poll replaces, restored after the test */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $components = \Yii::$app->getComponents(true);
        $this->saved = ['authManager' => $components['authManager'] ?? null, 'user' => $components['user'] ?? null];
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        parent::tearDown();
    }

    /**
     * regression: an operator's poll of a run that waits for an approval
     * cost 81 statements. The approvers' eligibility was computed twice: for
     * the banner, and again for the operator's own relation to the request
     * (ApprovalService::decisionRefusal(), with a lookup of their vote of
     * its own); and workflow.launch was checked although Resume is offered
     * only while the run is paused.
     */
    public function testRegressionAnOperatorsPollComputedTheApproversEligibilityTwice(): void
    {
        ['run' => $run, 'operator' => $operator] = $this->waitingRun();
        // Once before measuring: table schemas are read on first use.
        $this->poll($run, $operator);

        $payload = [];
        $queries = $this->queriesOf(function () use ($run, $operator, &$payload): void {
            $payload = $this->poll($run, $operator);
        });

        $this->assertLessThanOrEqual(self::OPERATOR_POLL_BUDGET, $queries, "an operator's poll costs {$queries} statements");
        // The answer is the full one: the wait, the operator's own line and the actions.
        $this->assertSame(Display::STATE_AWAITING_APPROVAL, $payload['state']);
        $this->assertIsArray($payload['banner']);
        $this->assertIsArray($payload['banner']['personal']);
        $this->assertSame(Texts::PERSONAL_MAY_DECIDE, $payload['banner']['personal']['text']);
        $this->assertSame(['resume' => false, 'cancel' => true], $payload['actions']);
    }

    /**
     * A run that waits at an approval step, with a job step of a team's
     * project before it, and the operator of that team.
     *
     * @return array{run: WorkflowJob, operator: User}
     */
    private function waitingRun(): array
    {
        $admin = $this->createUserWithRole('wpc-admin', 'admin');
        $operator = $this->createUserWithRole('wpc-operator', 'operator');
        $template = $this->openJobTemplate((int)$admin->id);
        $team = $this->createTeam((int)$admin->id);
        $this->addTeamMember((int)$team->id, (int)$operator->id);
        $this->createTeamProject((int)$team->id, (int)$template->project_id, TeamProject::ROLE_OPERATOR);
        $rule = $this->ruleOf((int)$admin->id, [$admin, $operator]);
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $build = $this->namedStep($workflow, 10, 'build', WorkflowStep::TYPE_JOB, (int)$template->id);
        $gate = $this->namedStep($workflow, 20, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$admin->id);
        $run->current_step_id = (int)$gate->id;
        $run->save(false);
        $built = $this->jobOf((int)$template->id, (int)$admin->id, Job::STATUS_SUCCEEDED, time() - 100);
        $this->executionOf($run, $build, WorkflowJobStep::STATUS_SUCCEEDED, $built);
        $placeholder = $this->jobOf(null, (int)$admin->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($placeholder, $rule, ApprovalRequest::STATUS_PENDING, time() - 60);
        $this->executionOf($run, $gate, WorkflowJobStep::STATUS_RUNNING, $placeholder, null, time() - 70);

        return ['run' => $run, 'operator' => $operator];
    }

    /**
     * One poll of $run by $user, as a request runs it.
     *
     * @return array<string, mixed>
     */
    private function poll(WorkflowJob $run, User $user): array
    {
        \Yii::$app->set('authManager', new AuthManager(['defaultRoles' => ['viewer']]));
        \Yii::$app->set('user', new \yii\web\User([
            'identityClass' => User::class,
            'enableSession' => false,
            'enableAutoLogin' => false,
        ]));
        /** @var \yii\web\User<User> $webUser */
        $webUser = \Yii::$app->user;
        $webUser->setIdentity(User::findIdentity((int)$user->id));
        $result = (new WorkflowJobController('workflow-job', \Yii::$app))->runAction('status', ['id' => (int)$run->id]);
        $this->assertIsArray($result);

        /** @var array<string, mixed> $result */
        return $result;
    }
}
