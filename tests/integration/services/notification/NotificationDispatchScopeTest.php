<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationIdentity;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;

/**
 * Which templates dispatch() queues an event for: those that listen to it,
 * whose level lets it through, whose workflow filter takes its run, and
 * whose identity (the user it delivers as) may see it
 * (NotificationVisibility); an identity that may see the job or the
 * approval of a run but not the run's workflow gets it without the run's
 * context.
 */
class NotificationDispatchScopeTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    /** The events of every family the tests dispatch. */
    private const EVENTS = 'job.failed,workflow.failed,approval.requested,project.sync_failed,schedule.failed_to_launch,'
        . 'runner.offline,runner.recovered,webhook.invalid_token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * Regression (Review focus 5 of the workflow notifications plan):
     * templates were global, so the template of an operator of team A
     * delivered the events of team B, and an operator who edited an admin's
     * template chose where the events of every team went. dispatch() now
     * queues an event only for a template whose identity may see it: the
     * operator's template gets the events of team A and of open projects,
     * the admin's every event, until the operator changes where it
     * delivers; then only what the operator may see.
     */
    public function testATemplateDeliversOnlyWhatItsIdentityMaySee(): void
    {
        $scope = $this->teamScope();
        $admin = $scope['admin'];
        $own = $this->objectsOf($scope['own'], (int)$admin->id);
        $open = $this->objectsOf($scope['open'], (int)$admin->id);
        $foreign = $this->objectsOf($scope['foreign'], (int)$admin->id);
        $operators = $this->templateOf($scope['member']);
        $admins = $this->templateOf($admin);

        foreach ($this->eventsOf($own) + $this->eventsOf($open, 'open ') as $what => [$event, $refs]) {
            $this->assertSame([(int)$operators->id, (int)$admins->id], $this->templatesQueuedFor($event, $refs), $what);
        }
        foreach ($this->eventsOf($foreign, 'foreign ') as $what => [$event, $refs]) {
            $this->assertSame([(int)$admins->id], $this->templatesQueuedFor($event, $refs), "only the admin's template: {$what}");
        }

        $identity = new NotificationIdentity($admins);
        $admins->config = '{"emails":["operator@example.com"]}';
        $this->assertTrue($identity->changesIdentity());
        $identity->apply((int)$scope['member']->id);
        $this->assertTrue($admins->save(false));

        foreach ($this->eventsOf($foreign, 'foreign ') as $what => [$event, $refs]) {
            $this->assertSame([], $this->templatesQueuedFor($event, $refs), "edited by the operator, no template: {$what}");
        }
        foreach ($this->eventsOf($own) as $what => [$event, $refs]) {
            $this->assertSame([(int)$operators->id, (int)$admins->id], $this->templatesQueuedFor($event, $refs), $what);
        }
    }

    /**
     * A template whose identity is inactive gets no delivery of any event,
     * not even webhook.invalid_token.
     */
    public function testAnInactiveIdentityDeliversNothing(): void
    {
        $admin = $this->createUserWithRole('ds-inactive', 'admin');
        $template = $this->templateOf($admin);
        $events = $this->eventsOf($this->objectsOf($this->jobTemplateOf($admin), (int)$admin->id)) + [
            'runner.offline' => [NotificationTemplate::EVENT_RUNNER_OFFLINE, []],
            'webhook.invalid_token' => [NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, []],
        ];
        $admin->status = User::STATUS_INACTIVE;
        $admin->save(false);

        foreach ($events as $what => [$event, $refs]) {
            $this->assertSame([], $this->templatesQueuedFor($event, $refs), $what);
        }
        $this->assertSame([], NotificationDelivery::find()->where(['notification_template_id' => $template->id])->all());
        $this->assertSame([], $this->queue->pushes);
    }

    /**
     * runner.offline and runner.recovered reach a template only when its
     * identity holds runner-group.view (viewers, operators and admins do).
     */
    public function testRunnerEventsNeedRunnerGroupView(): void
    {
        $expected = [];
        foreach (['viewer', 'operator', 'admin'] as $role) {
            $expected[] = (int)$this->templateOf($this->createUserWithRole('ds-runner-' . $role, $role))->id;
        }
        $jobsOnly = $this->createUser('ds-runner-jobsonly');
        $this->assignRole((int)$jobsOnly->id, $this->customRole('ds-jobs-only', ['job.view']));
        $this->templateOf($jobsOnly);
        $this->templateOf($this->createUser('ds-runner-norole'));

        foreach ([NotificationTemplate::EVENT_RUNNER_OFFLINE, NotificationTemplate::EVENT_RUNNER_RECOVERED] as $event) {
            $this->assertSame($expected, $this->templatesQueuedFor($event, []), $event);
        }
    }

    /**
     * At level essential a template gets, of the workflow events, only
     * those of ESSENTIAL_WORKFLOW_EVENTS; every other event as its list
     * says. Level all gets every event it listens to.
     */
    public function testTheLevelDecidesWhichWorkflowEventsATemplateGets(): void
    {
        $admin = $this->createUserWithRole('ds-level', 'admin');
        $objects = $this->objectsOf($this->jobTemplateOf($admin), (int)$admin->id);
        $events = 'workflow.launched,workflow.step_failed,workflow.failed,job.failed';
        $essential = $this->createNotificationTemplate((int)$admin->id, NotificationTemplate::CHANNEL_EMAIL, $events);
        $essential->level = NotificationTemplate::LEVEL_ESSENTIAL;
        $essential->save(false);
        $all = $this->createNotificationTemplate((int)$admin->id, NotificationTemplate::CHANNEL_EMAIL, $events);
        $run = ['workflow_job_id' => $objects['run']];

        $this->assertSame([(int)$all->id], $this->templatesQueuedFor(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, $run));
        $this->assertSame([(int)$essential->id, (int)$all->id], $this->templatesQueuedFor(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED, $run));
        $this->assertSame([(int)$essential->id, (int)$all->id], $this->templatesQueuedFor(NotificationTemplate::EVENT_WORKFLOW_FAILED, $run));
        $this->assertSame([(int)$essential->id, (int)$all->id], $this->templatesQueuedFor(NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => $objects['job']]));
    }

    /**
     * With workflow scope selected, a template gets the events of the runs
     * of its workflow filter: the workflow events and the job and approval
     * events of such a run; events of no run as its list says.
     */
    public function testTheWorkflowFilterDecidesWhichRunsReachATemplate(): void
    {
        $admin = $this->createUserWithRole('ds-filter', 'admin');
        $jobTemplate = $this->jobTemplateOf($admin);
        $selected = $this->createWorkflowWithJobSteps((int)$admin->id, (int)$jobTemplate->id);
        $other = $this->createWorkflowWithJobSteps((int)$admin->id, (int)$jobTemplate->id);
        $inFilter = (int)$this->createWorkflowJob((int)$selected->id, (int)$admin->id)->id;
        $outside = (int)$this->createWorkflowJob((int)$other->id, (int)$admin->id)->id;
        $job = (int)$this->createJob((int)$jobTemplate->id, (int)$admin->id)->id;
        $template = $this->templateOf($admin);
        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $template->save(false);
        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$selected->id], (int)$admin->id));
        $id = [(int)$template->id];

        $this->assertSame($id, $this->templatesQueuedFor(NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => $inFilter]));
        $this->assertSame([], $this->templatesQueuedFor(NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => $outside]));
        $this->assertSame($id, $this->templatesQueuedFor(NotificationTemplate::EVENT_JOB_FAILED, ['workflow_job_id' => $inFilter, 'job_id' => $job]));
        $this->assertSame([], $this->templatesQueuedFor(NotificationTemplate::EVENT_JOB_FAILED, ['workflow_job_id' => $outside, 'job_id' => $job]));
        $this->assertSame($id, $this->templatesQueuedFor(NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => $job]), 'a job of no run');
    }

    /**
     * Spec answer 2: an identity that may see the job of a run but not the
     * run's workflow (a step of another team's project) gets the job's
     * event without the run's context, no workflow.* and no step.*
     * variable; an identity that may see the workflow gets them. Both keep
     * the run as a ref, so the filter is checked again on delivery.
     */
    public function testTheRunsContextIsLeftOutForAnIdentityThatCannotSeeItsWorkflow(): void
    {
        $scope = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id, (int)$scope['foreign']->id);
        $run = (int)$this->createWorkflowJob((int)$workflow->id, (int)$scope['admin']->id)->id;
        $job = (int)$this->createJob((int)$scope['own']->id, (int)$scope['admin']->id, Job::STATUS_FAILED)->id;
        $operators = $this->templateOf($scope['member']);
        $admins = $this->templateOf($scope['admin']);
        $refs = ['workflow_job_id' => $run, 'job_id' => $job];

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_JOB_FAILED, [
            'job' => ['id' => (string)$job, 'status' => Job::STATUS_FAILED],
            'workflow' => ['id' => (string)$run, 'template_name' => 'deploy-everything'],
            'step' => ['name' => 'Rollout', 'position' => '2'],
            NotificationDispatcher::REFS => $refs,
        ]);

        $withoutContext = $this->onlyDeliveryOf($operators);
        $this->assertSame((string)$job, $withoutContext->getVariableMap()['job.id'] ?? null);
        $this->assertSame([], preg_grep('/^(workflow|step)\./', array_keys($withoutContext->getVariableMap())), 'no context of the run');
        $this->assertSame($refs, $withoutContext->getRefs());
        $withContext = $this->onlyDeliveryOf($admins);
        $this->assertSame('deploy-everything', $withContext->getVariableMap()['workflow.template_name'] ?? null);
        $this->assertSame('Rollout', $withContext->getVariableMap()['step.name'] ?? null);
        $this->assertSame($refs, $withContext->getRefs());
    }

    /**
     * A ref that is no ID is left out, and the event fails closed: a job
     * event without its job reaches no template.
     */
    public function testARefThatIsNoIdFailsClosed(): void
    {
        $admin = $this->createUserWithRole('ds-badref', 'admin');
        $this->templateOf($admin);

        $this->assertSame([], $this->templatesQueuedFor(NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => 'abc']));
        $this->assertSame([], $this->templatesQueuedFor(NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => 1.5]));
    }

    // -- Helpers ----------------------------------------------------------------

    private function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }

    /**
     * Dispatches $event with $refs (and nothing else), then answers the
     * templates that got a delivery of it, in the order of their IDs; the
     * deliveries are deleted again. Only the rows of this test's
     * transaction are seen.
     *
     * @param array<string, mixed> $refs
     * @return list<int>
     */
    private function templatesQueuedFor(string $event, array $refs): array
    {
        $before = (int)NotificationDelivery::find()->max('id');
        $this->dispatcher()->dispatch($event, [NotificationDispatcher::REFS => $refs]);
        $rows = NotificationDelivery::find()
            ->where(['>', 'id', $before])
            ->andWhere(['event' => $event])
            ->orderBy(['notification_template_id' => SORT_ASC])
            ->all();
        $ids = array_map(static fn (NotificationDelivery $d): int => (int)$d->notification_template_id, $rows);
        NotificationDelivery::deleteAll(['>', 'id', $before]);

        return $ids;
    }

    /** An email template delivering as $user, listening to an event of every family. */
    private function templateOf(User $user): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$user->id, NotificationTemplate::CHANNEL_EMAIL, self::EVENTS);
    }

    private function onlyDeliveryOf(NotificationTemplate $template): NotificationDelivery
    {
        $rows = NotificationDelivery::find()->where(['notification_template_id' => $template->id])->all();
        $this->assertCount(1, $rows);
        $this->assertInstanceOf(NotificationDelivery::class, $rows[0]);

        return $rows[0];
    }

    private function jobTemplateOf(User $user): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
    }

    /**
     * A run of a workflow with a job step of $template, a job of $template,
     * the job's approval request, and the template's project.
     *
     * @return array{run: int, job: int, request: int, project: int, template: int}
     */
    private function objectsOf(JobTemplate $template, int $userId): array
    {
        $workflow = $this->createWorkflowWithJobSteps($userId, (int)$template->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $userId);
        $job = $this->createJob((int)$template->id, $userId);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return [
            'run' => (int)$run->id,
            'job' => (int)$job->id,
            'request' => (int)$request->id,
            'project' => (int)$template->project_id,
            'template' => (int)$template->id,
        ];
    }

    /**
     * An event of each family about $objects, by what it is.
     *
     * @param array{run: int, job: int, request: int, project: int, template: int} $objects
     * @return array<string, array{0: string, 1: array<string, int>}>
     */
    private function eventsOf(array $objects, string $prefix = ''): array
    {
        return [
            $prefix . 'workflow.failed' => [NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => $objects['run']]],
            $prefix . 'job.failed' => [NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => $objects['job']]],
            $prefix . 'approval.requested' => [NotificationTemplate::EVENT_APPROVAL_REQUESTED, ['approval_request_id' => $objects['request'], 'job_id' => $objects['job']]],
            $prefix . 'project.sync_failed' => [NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, ['project_id' => $objects['project']]],
            $prefix . 'schedule.failed_to_launch' => [NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, ['job_template_id' => $objects['template']]],
        ];
    }

    /**
     * A role of its own with $permissions only; its name.
     *
     * @param list<string> $permissions
     */
    private function customRole(string $name, array $permissions): string
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole($name . '-' . bin2hex(random_bytes(3)));
        $auth->add($role);
        foreach ($permissions as $permission) {
            $item = $auth->getPermission($permission);
            $this->assertNotNull($item, $permission);
            $auth->addChild($role, $item);
        }

        return $role->name;
    }
}
