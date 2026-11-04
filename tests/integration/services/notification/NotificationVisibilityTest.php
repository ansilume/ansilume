<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\notification\NotificationIdentity;
use app\services\notification\NotificationVisibility;
use app\services\VisibleWorkflowTemplates;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Which events a notification template may deliver
 * (NotificationVisibility::allows()): those its identity, the user it
 * delivers as (delivers_as), may see in Ansilume, while that user is
 * active, and of a workflow run only the runs its workflow filter takes.
 * The dispatcher asks when the event happens, the notification worker
 * again when it delivers.
 *
 * | Events | Permission | Access check |
 * | workflow | workflow.view | the run's workflow template |
 * | job | job.view | the job |
 * | approval | approval.view | the approval request |
 * | project | project.view | the project |
 * | schedule.failed_to_launch | job.launch | the job template's project |
 * | runner.* | runner-group.view | none: installation-level |
 * | webhook.invalid_token | none | none: any active identity |
 */
class NotificationVisibilityTest extends DbTestCase
{
    use TeamScopeFixtures;

    /**
     * Regression (Review focus 5 of the workflow notifications plan):
     * templates were global, so the template of an operator of team A
     * delivered the events of team B, and an operator who edited an admin's
     * template chose where the events of every team went. A template now
     * delivers only what the user it delivers as may see: the operator's
     * template the events of team A and of open projects, the admin's
     * everything until the operator changes where it delivers, then only
     * what the operator may see.
     */
    public function testATemplateDeliversOnlyWhatItsIdentityMaySee(): void
    {
        $scope = $this->teamScope();
        $member = $scope['member'];
        $own = $this->objectsOf($scope['own'], (int)$scope['admin']->id);
        $open = $this->objectsOf($scope['open'], (int)$scope['admin']->id);
        $foreign = $this->objectsOf($scope['foreign'], (int)$scope['admin']->id);
        $operatorsTemplate = $this->templateOf($member);
        $adminsTemplate = $this->templateOf($scope['admin']);

        foreach ($this->eventsOf($own) + $this->eventsOf($open, 'open ') as $what => [$event, $refs]) {
            $this->assertTrue($this->allows($operatorsTemplate, $event, $refs), "the operator's template delivers {$what}");
        }
        foreach ($this->eventsOf($foreign, 'foreign ') as $what => [$event, $refs]) {
            $this->assertFalse($this->allows($operatorsTemplate, $event, $refs), "the operator's template never delivers {$what}");
            $this->assertTrue($this->allows($adminsTemplate, $event, $refs), "the admin's template delivers {$what}");
        }

        $identity = new NotificationIdentity($adminsTemplate);
        $adminsTemplate->config = '{"emails":["operator@example.com"]}';
        $this->assertTrue($identity->changesIdentity());
        $identity->apply((int)$member->id);
        $this->assertTrue($adminsTemplate->save(false));

        foreach ($this->eventsOf($foreign, 'foreign ') as $what => [$event, $refs]) {
            $this->assertFalse($this->allows($adminsTemplate, $event, $refs), "edited by the operator, the admin's template no longer delivers {$what}");
        }
        foreach ($this->eventsOf($own) as $what => [$event, $refs]) {
            $this->assertTrue($this->allows($adminsTemplate, $event, $refs), "it delivers {$what} of the operator's team");
        }
    }

    /**
     * Without team-restricted projects every active user may see every
     * object: installations without teams deliver what they delivered.
     */
    public function testWithoutTeamsEveryEventOfItsPermissionsIsDelivered(): void
    {
        $admin = $this->createUserWithRole('nv-noteam-admin', 'admin');
        $operator = $this->createUserWithRole('nv-noteam-operator', 'operator');
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$admin->id)->id,
            (int)$this->createInventory((int)$admin->id)->id,
            (int)$this->createRunnerGroup((int)$admin->id)->id,
            (int)$admin->id
        );
        $objects = $this->objectsOf($template, (int)$admin->id);
        $notification = $this->templateOf($operator);

        foreach ($this->eventsOf($objects) as $what => [$event, $refs]) {
            $this->assertTrue($this->allows($notification, $event, $refs), $what);
        }
    }

    /**
     * Every family of events needs its permission: a user with the viewer
     * role sees jobs, workflows, approvals, projects and runners, but holds
     * no job.launch for a schedule that failed to launch; a user without a
     * role sees none of them.
     */
    public function testEachFamilyOfEventsNeedsItsPermission(): void
    {
        $admin = $this->createUserWithRole('nv-perm-admin', 'admin');
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$admin->id)->id,
            (int)$this->createInventory((int)$admin->id)->id,
            (int)$this->createRunnerGroup((int)$admin->id)->id,
            (int)$admin->id
        );
        $events = $this->eventsOf($this->objectsOf($template, (int)$admin->id)) + [
            'runner.offline' => [NotificationTemplate::EVENT_RUNNER_OFFLINE, []],
        ];
        $viewer = $this->templateOf($this->createUserWithRole('nv-perm-viewer', 'viewer'));
        $noRole = $this->templateOf($this->createUser('nv-perm-norole'));

        foreach ($events as $what => [$event, $refs]) {
            $expected = $event !== NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH;
            $this->assertSame($expected, $this->allows($viewer, $event, $refs), "a viewer: {$what}");
            $this->assertFalse($this->allows($noRole, $event, $refs), "no role: {$what}");
        }
        $this->assertSame([
            'jobs' => 'job.view',
            'workflows' => 'workflow.view',
            'approvals' => 'approval.view',
            'schedules' => 'job.launch',
            'runners' => 'runner-group.view',
            'projects' => 'project.view',
            'webhooks' => null,
        ], NotificationVisibility::PERMISSIONS);
    }

    /**
     * runner.offline and runner.recovered need runner-group.view, which
     * viewers and operators hold; a role without it delivers none. Runner
     * events are about the installation, not about an object of a team.
     */
    public function testRunnerEventsNeedRunnerGroupView(): void
    {
        $jobsOnly = $this->createUser('nv-runner-jobsonly');
        $this->assignRole((int)$jobsOnly->id, $this->customRole('nv-jobs-only', ['job.view']));

        foreach ([NotificationTemplate::EVENT_RUNNER_OFFLINE, NotificationTemplate::EVENT_RUNNER_RECOVERED] as $event) {
            foreach (['viewer', 'operator', 'admin'] as $role) {
                $this->assertTrue(
                    $this->allows($this->templateOf($this->createUserWithRole('nv-runner-' . $role, $role)), $event, []),
                    "{$role}: {$event}"
                );
            }
            $this->assertFalse($this->allows($this->templateOf($jobsOnly), $event, []), "without runner-group.view: {$event}");
            $this->assertFalse($this->allows($this->templateOf($this->createUser('nv-runner-norole')), $event, []), "no role: {$event}");
        }
    }

    /**
     * webhook.invalid_token carries nothing of a team: it goes to any
     * active identity, also one without a role, as it did.
     */
    public function testAnInvalidTokenEventGoesToAnyActiveIdentity(): void
    {
        $noRole = $this->templateOf($this->createUser('nv-token-norole'));
        $viewer = $this->templateOf($this->createUserWithRole('nv-token-viewer', 'viewer'));

        $this->assertTrue($this->allows($noRole, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, []));
        $this->assertTrue($this->allows($viewer, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, ['job_id' => 987654321]));
    }

    /**
     * A template whose identity is inactive delivers nothing, an admin's or
     * a superadmin's neither, not even webhook.invalid_token.
     */
    public function testAnInactiveIdentityDeliversNothing(): void
    {
        $admin = $this->createUserWithRole('nv-inactive-admin', 'admin');
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$admin->id)->id,
            (int)$this->createInventory((int)$admin->id)->id,
            (int)$this->createRunnerGroup((int)$admin->id)->id,
            (int)$admin->id
        );
        $events = $this->eventsOf($this->objectsOf($template, (int)$admin->id)) + [
            'runner.recovered' => [NotificationTemplate::EVENT_RUNNER_RECOVERED, []],
            'webhook.invalid_token' => [NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, []],
        ];
        $superadmin = $this->createUser('nv-inactive-super');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $templates = ['admin' => $this->templateOf($admin), 'superadmin' => $this->templateOf($superadmin)];

        foreach ($templates as $who => $notification) {
            foreach ($events as $what => [$event, $refs]) {
                $this->assertTrue($this->allows($notification, $event, $refs), "active {$who}: {$what}");
            }
        }
        foreach ([$admin, $superadmin] as $user) {
            $user->status = User::STATUS_INACTIVE;
            $user->save(false);
        }
        foreach ($templates as $who => $notification) {
            $this->assertTrue($notification->isDeliveringAsInactive());
            foreach ($events as $what => [$event, $refs]) {
                $this->assertFalse($this->allows($notification, $event, $refs), "inactive {$who}: {$what}");
            }
        }
    }

    /**
     * With workflow scope selected, the events of a run reach the template
     * only when the run's workflow template is in its workflow filter: the
     * workflow events, and the job and approval events that name the run.
     * Events of no run reach it as before.
     */
    public function testASelectedWorkflowFilterTakesOnlyItsRuns(): void
    {
        $admin = $this->createUserWithRole('nv-filter-admin', 'admin');
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$admin->id)->id,
            (int)$this->createInventory((int)$admin->id)->id,
            (int)$this->createRunnerGroup((int)$admin->id)->id,
            (int)$admin->id
        );
        $selected = $this->createWorkflowWithJobSteps((int)$admin->id, (int)$template->id);
        $other = $this->createWorkflowWithJobSteps((int)$admin->id, (int)$template->id);
        $inFilter = $this->createWorkflowJob((int)$selected->id, (int)$admin->id);
        $outside = $this->createWorkflowJob((int)$other->id, (int)$admin->id);
        $job = $this->createJob((int)$template->id, (int)$admin->id);
        $request = $this->approvalRequestFor($job);
        $notification = $this->templateOf($admin);
        $notification->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $notification->save(false);
        $this->assertSame([], $notification->setWorkflowTemplateIds([(int)$selected->id], (int)$admin->id));

        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => (int)$inFilter->id]));
        $this->assertFalse($this->allows($notification, NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => (int)$outside->id]));
        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => (int)$job->id, 'workflow_job_id' => (int)$inFilter->id]));
        $this->assertFalse($this->allows($notification, NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => (int)$job->id, 'workflow_job_id' => (int)$outside->id]), 'a job of a run outside the filter');
        $this->assertFalse($this->allows($notification, NotificationTemplate::EVENT_APPROVAL_REQUESTED, ['approval_request_id' => (int)$request->id, 'workflow_job_id' => (int)$outside->id]));
        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => (int)$job->id]), 'a job of no run');
        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_APPROVAL_REQUESTED, ['approval_request_id' => (int)$request->id]));
        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, ['project_id' => (int)$template->project_id]));

        $notification->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_ALL;
        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => (int)$outside->id]), 'scope all: every run');
    }

    /**
     * A selected filter whose workflow templates were all deleted from the
     * database (the filter loses them with them) matches nothing: it never
     * turns into "all". A workflow template that was soft-deleted stays in
     * the filter, so the runs its delete cancels still reach the template.
     */
    public function testASelectedFilterWhoseWorkflowsWereDeletedMatchesNothing(): void
    {
        $admin = $this->createUserWithRole('nv-deleted-admin', 'admin');
        $deleted = $this->createWorkflowTemplate((int)$admin->id);
        $softDeleted = $this->createWorkflowTemplate((int)$admin->id);
        $other = $this->createWorkflowTemplate((int)$admin->id);
        $softRun = $this->createWorkflowJob((int)$softDeleted->id, (int)$admin->id);
        $otherRun = $this->createWorkflowJob((int)$other->id, (int)$admin->id);
        $notification = $this->templateOf($admin);
        $notification->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $notification->save(false);
        $notification->setWorkflowTemplateIds([(int)$deleted->id, (int)$softDeleted->id], (int)$admin->id);

        $softDeleted->softDelete();
        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_WORKFLOW_CANCELED, ['workflow_job_id' => (int)$softRun->id]), 'the canceled run of a soft-deleted workflow');

        WorkflowTemplate::deleteAll(['id' => [$deleted->id, $softDeleted->id]]);
        $this->assertSame([], $notification->getWorkflowTemplateIds());
        $this->assertFalse($this->allows($notification, NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => (int)$otherRun->id]), 'an empty selected filter matches nothing');
        $this->assertFalse($this->allows($notification, NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => (int)$softRun->id]));
    }

    /**
     * An identity that may see a job of a run but not the run's workflow
     * still gets the job's events (without the run's context, which the
     * producer leaves out), as long as the filter takes the run.
     */
    public function testAJobOfARunIsDeliveredByTheJobsVisibility(): void
    {
        $scope = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id, (int)$scope['foreign']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$scope['admin']->id);
        $job = $this->createJob((int)$scope['own']->id, (int)$scope['admin']->id);
        $notification = $this->templateOf($scope['member']);

        $this->assertFalse($this->allows($notification, NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => (int)$run->id]), 'the workflow has a step of another team');
        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => (int)$job->id, 'workflow_job_id' => (int)$run->id]));
        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => (int)$job->id]));
    }

    /**
     * The request of a workflow approval step (its placeholder job has no
     * job template) follows the run's workflow.
     */
    public function testTheApprovalOfAnApprovalStepFollowsItsWorkflow(): void
    {
        $scope = $this->teamScope();
        $ownWorkflow = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id);
        $foreignWorkflow = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['foreign']->id);
        $ownRequest = $this->approvalRequestFor($this->placeholderJobOf($ownWorkflow, (int)$scope['admin']->id));
        $foreignRequest = $this->approvalRequestFor($this->placeholderJobOf($foreignWorkflow, (int)$scope['admin']->id));
        $notification = $this->templateOf($scope['member']);

        $this->assertTrue($this->allows($notification, NotificationTemplate::EVENT_APPROVAL_REQUESTED, ['approval_request_id' => (int)$ownRequest->id]));
        $this->assertFalse($this->allows($notification, NotificationTemplate::EVENT_APPROVAL_REQUESTED, ['approval_request_id' => (int)$foreignRequest->id]));
    }

    /**
     * A schedule event follows its job template's project, also when the
     * job template was soft-deleted (a schedule fails to launch because its
     * template was deleted). Without a job template only an identity that
     * no team restricts sees it.
     */
    public function testAScheduleEventFollowsItsJobTemplatesProject(): void
    {
        $scope = $this->teamScope();
        $foreign = JobTemplate::findOne($scope['foreign']->id);
        $this->assertInstanceOf(JobTemplate::class, $foreign);
        $foreign->softDelete();
        $refs = ['job_template_id' => (int)$scope['foreign']->id];
        $member = $this->templateOf($scope['member']);
        $outsider = $this->templateOf($scope['outsider']);
        $admin = $this->templateOf($scope['admin']);

        $this->assertTrue($this->allows($outsider, NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, $refs), 'the soft-deleted template keeps its project');
        $this->assertFalse($this->allows($member, NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, $refs));
        $this->assertTrue($this->allows($member, NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, ['job_template_id' => (int)$scope['own']->id]));

        $this->assertTrue($this->allows($admin, NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, []), 'unrestricted');
        $this->assertFalse($this->allows($member, NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, []), 'restricted by its team');
        $this->assertFalse($this->allows($member, NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, ['job_template_id' => 987654321]), 'a job template that does not exist');
    }

    /**
     * An event whose object is not named, or no longer exists, is not
     * delivered: the check fails closed. An event of no group neither.
     */
    public function testAnEventWithoutItsObjectIsNotDelivered(): void
    {
        $admin = $this->templateOf($this->createUserWithRole('nv-missing-admin', 'admin'));
        $cases = [
            'a workflow event without its run' => [NotificationTemplate::EVENT_WORKFLOW_FAILED, []],
            'a run that does not exist' => [NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => 987654321]],
            'a job event without its job' => [NotificationTemplate::EVENT_JOB_FAILED, []],
            'a job that does not exist' => [NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => 987654321]],
            'a job of a run that does not exist' => [NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => 987654321, 'workflow_job_id' => 987654321]],
            'an approval event without its request' => [NotificationTemplate::EVENT_APPROVAL_APPROVED, []],
            'a request that does not exist' => [NotificationTemplate::EVENT_APPROVAL_APPROVED, ['approval_request_id' => 987654321]],
            'a project event without its project' => [NotificationTemplate::EVENT_PROJECT_SYNC_SUCCEEDED, []],
            'a project that does not exist' => [NotificationTemplate::EVENT_PROJECT_SYNC_SUCCEEDED, ['project_id' => 987654321]],
            'an event of no group' => ['workflow.unknown', ['workflow_job_id' => 1]],
            'the test event of Send Test' => ['test', []],
        ];

        foreach ($cases as $what => [$event, $refs]) {
            $this->assertFalse($this->allows($admin, $event, $refs), $what);
        }
    }

    /**
     * A status refresh of a run (NotificationDelivery::EVENT_STATUS_REFRESH), which
     * no event of the catalog names, is judged as a workflow event of its
     * run: the identity must see the run's workflow, and the filter take
     * the run; without a run it is delivered nowhere.
     */
    public function testAStatusRefreshIsJudgedAsAWorkflowEventOfItsRun(): void
    {
        $scope = $this->teamScope();
        $own = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id);
        $foreign = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['foreign']->id);
        $ownRun = $this->createWorkflowJob((int)$own->id, (int)$scope['admin']->id);
        $foreignRun = $this->createWorkflowJob((int)$foreign->id, (int)$scope['admin']->id);
        $notification = $this->templateOf($scope['member']);

        $this->assertTrue($this->allows($notification, NotificationDelivery::EVENT_STATUS_REFRESH, ['workflow_job_id' => (int)$ownRun->id]));
        $this->assertFalse($this->allows($notification, NotificationDelivery::EVENT_STATUS_REFRESH, ['workflow_job_id' => (int)$foreignRun->id]), 'a workflow of another team');
        $this->assertFalse($this->allows($notification, NotificationDelivery::EVENT_STATUS_REFRESH, []), 'no run');
        $this->assertFalse(NotificationTemplate::isWorkflowEvent(NotificationDelivery::EVENT_STATUS_REFRESH), 'no event of the catalog');
        $this->assertArrayNotHasKey(NotificationDelivery::EVENT_STATUS_REFRESH, NotificationTemplate::eventLabels(), 'no template listens to it');

        $notification->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $this->assertFalse($this->allows($notification, NotificationDelivery::EVENT_STATUS_REFRESH, ['workflow_job_id' => (int)$ownRun->id]), 'a filter without its workflow');
    }

    /**
     * Every group of events has its rule: a group added without one would
     * deliver nothing (fail closed), and this test says so. Every permission
     * named exists.
     */
    public function testEveryGroupOfEventsHasItsRule(): void
    {
        $this->assertSame(array_keys(NotificationTemplate::eventGroups()), array_keys(NotificationVisibility::PERMISSIONS));
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach (array_filter(NotificationVisibility::PERMISSIONS) as $permission) {
            $this->assertNotNull($auth->getPermission($permission), $permission);
        }
    }

    public function testTheVisibleWorkflowTemplatesAreThoseTheViewerMaySee(): void
    {
        $scope = $this->teamScope();
        $own = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id);
        $foreign = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['foreign']->id);
        $ids = [(int)$foreign->id, (int)$own->id, 987654321];

        $this->assertSame([(int)$own->id], (new NotificationVisibility())->visibleWorkflowTemplateIds((int)$scope['member']->id, $ids));
        $this->assertSame(VisibleWorkflowTemplates::ids((int)$scope['outsider']->id, $ids), (new NotificationVisibility())->visibleWorkflowTemplateIds((int)$scope['outsider']->id, $ids));
        $this->assertSame([], (new NotificationVisibility())->visibleWorkflowTemplateIds((int)$scope['member']->id, []));
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     */
    private function allows(NotificationTemplate $template, string $event, array $refs): bool
    {
        return (new NotificationVisibility())->allows($template, $event, $refs);
    }

    /** An email template of $user, delivering as $user, listening to everything that matters here. */
    private function templateOf(User $user): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$user->id);
    }

    /**
     * A run of a workflow with a job step of $template, a job of $template
     * in it, the job's approval request, and the template's project.
     *
     * @return array{run: int, job: int, request: int, project: int, template: int}
     */
    private function objectsOf(JobTemplate $template, int $userId): array
    {
        $workflow = $this->createWorkflowWithJobSteps($userId, (int)$template->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $userId);
        $job = $this->createJob((int)$template->id, $userId);

        return [
            'run' => (int)$run->id,
            'job' => (int)$job->id,
            'request' => (int)$this->approvalRequestFor($job)->id,
            'project' => (int)$template->project_id,
            'template' => (int)$template->id,
        ];
    }

    /**
     * An event of each family about $objects, by what it is.
     *
     * @param array{run: int, job: int, request: int, project: int, template: int} $objects
     * @return array<string, array{0: string, 1: array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int}}>
     */
    private function eventsOf(array $objects, string $prefix = ''): array
    {
        return [
            $prefix . 'workflow.failed' => [NotificationTemplate::EVENT_WORKFLOW_FAILED, ['workflow_job_id' => $objects['run']]],
            $prefix . 'job.failed' => [NotificationTemplate::EVENT_JOB_FAILED, ['job_id' => $objects['job']]],
            $prefix . 'approval.requested' => [NotificationTemplate::EVENT_APPROVAL_REQUESTED, ['approval_request_id' => $objects['request']]],
            $prefix . 'project.sync_failed' => [NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, ['project_id' => $objects['project']]],
            $prefix . 'schedule.failed_to_launch' => [NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, ['job_template_id' => $objects['template']]],
        ];
    }

    private function approvalRequestFor(Job $job): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }

    /**
     * A running run of $workflow whose approval step waits with its
     * placeholder job (no job template), as WorkflowExecutionService makes it.
     */
    private function placeholderJobOf(WorkflowTemplate $workflow, int $launchedBy): Job
    {
        $run = $this->createWorkflowJob((int)$workflow->id, $launchedBy);
        $step = $this->createWorkflowStep((int)$workflow->id, 99, WorkflowStep::TYPE_APPROVAL);
        $job = new Job();
        $job->job_template_id = null;
        $job->launched_by = $launchedBy;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->created_at = time();
        $job->updated_at = time();
        $job->save(false);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->created_at = time();
        $execution->updated_at = time();
        $execution->save(false);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);

        return $job;
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
