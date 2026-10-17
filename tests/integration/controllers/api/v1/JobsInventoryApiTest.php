<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobsController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobLaunchService;
use app\services\WorkflowExecutionService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\InventoryGatedStepFixtures;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * inventory_id and inventory_name of /api/v1/jobs: every job names the
 * inventory it was launched with, as its runner payload records it
 * (JobInventory), right after template_name, in the list, the detail, the
 * launch and the cancel answers. A rename of the inventory, or its job
 * template moving to another inventory, changes nothing; a job launched
 * before 2.12.0 has its inventory's current name; a job launched without
 * an inventory has none. That the list reads the inventories of its page in
 * one query, JobsListQueriesTest shows.
 */
class JobsInventoryApiTest extends WebControllerTestCase
{
    use GateFieldsApiCalls;
    use InventoryGatedStepFixtures;
    use RestoresComponents;
    use TeamScopeFixtures;

    /** An ID that no job, template or inventory has. */
    private const UNKNOWN_ID = 987654321;

    /** The fields of a Job up to its launcher, in their order. */
    private const LEADING_KEYS = ['id', 'status', 'job_template_id', 'template_name', 'inventory_id', 'inventory_name', 'launched_by'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Launches announce their jobs: recorded, not sent. Swapped before
        // any write, so that tearDown() restores it also when a write fails.
        $this->swapComponent('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->admin = $this->createUserWithRole('jobs-inv-admin', 'admin');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * The launch answers the inventory the job was launched with, right
     * after template_name. Renamed, and its template moved to another
     * inventory, the detail, the list and the cancel still answer it, by
     * its name at launch.
     */
    public function testEveryAnswerNamesTheInventoryTheJobWasLaunchedWith(): void
    {
        $template = $this->openTemplate();
        $inventory = $this->inventoryOf($template);
        $launchedAs = ['inventory_id' => (int)$inventory->id, 'inventory_name' => (string)$inventory->name];
        $this->bearerOf($this->admin);

        $launched = $this->answerData($this->launch($template), 201);
        $jobId = (int)$launched['id'];
        $inventory->name = 'jobs-inv-renamed-' . uniqid();
        $inventory->save(false);
        $this->moveToInventory((int)$template->id, (int)$this->createInventory((int)$this->admin->id)->id);

        $answers = [
            'launch' => $launched,
            'detail' => $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => $jobId])),
            'list' => $this->listItem($jobId),
            'cancel' => $this->answerData($this->callApi($this->controller(), 'cancel', 'POST', ['id' => $jobId])),
        ];
        foreach ($answers as $answer => $data) {
            $this->assertSame(self::LEADING_KEYS, array_slice(array_keys($data), 0, 7), "{$answer}: right after template_name");
            $this->assertSame($launchedAs, $this->inventoryFieldsOf($data), $answer);
        }
        $this->assertSame(Job::STATUS_CANCELED, $answers['cancel']['status']);
    }

    /**
     * A launch that waits for the approval rule of the template's inventory
     * answers its inventory with its wait: pending_approval and the request.
     */
    public function testALaunchThatWaitsForItsInventorysRuleAnswersTheInventory(): void
    {
        $rule = $this->ruleOf($this->createUserWithRole('jobs-inv-approver', 'operator'));
        $template = $this->templateOnGatedInventory((int)$this->admin->id, (int)$rule->id);
        $inventory = $this->inventoryOf($template);
        $this->bearerOf($this->admin);

        $launched = $this->answerData($this->launch($template), 201);

        $request = ApprovalRequest::findOne(['job_id' => (int)$launched['id']]);
        $this->assertNotNull($request);
        $this->assertSame(ApprovalRequest::REQUIRED_BY_INVENTORY, $request->requiredBy());
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $launched['status']);
        $this->assertSame((int)$request->id, $launched['approval_request_id']);
        $this->assertSame(['inventory_id' => (int)$inventory->id, 'inventory_name' => (string)$inventory->name], $this->inventoryFieldsOf($launched));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function storedPayloadProvider(): array
    {
        return [
            'launched before 2.12.0: no name, the current one' => ['before 2.12.0'],
            'launched before 2.12.0, its inventory gone' => ['before 2.12.0, gone'],
            'its inventory gone, the name at launch' => ['gone, named'],
            'its ID as a string of digits' => ['digit string'],
            'no runner payload' => ['no payload'],
            'a payload without an inventory' => ['no inventory'],
            'an ID that is none: true' => ['not an ID'],
        ];
    }

    /**
     * What the detail and the list answer for the runner payloads that
     * jobs have: from before 2.12.0 (no inventory_name), naming an
     * inventory that is gone, an ID as a string, none at all.
     *
     * @dataProvider storedPayloadProvider
     */
    public function testTheInventoryOfEveryRunnerPayload(string $case): void
    {
        $inventory = $this->createInventory((int)$this->admin->id);
        $id = (int)$inventory->id;
        [$payload, $expected] = match ($case) {
            'before 2.12.0' => [['inventory_id' => $id], [$id, (string)$inventory->name]],
            'before 2.12.0, gone' => [['inventory_id' => self::UNKNOWN_ID], [self::UNKNOWN_ID, null]],
            'gone, named' => [['inventory_id' => self::UNKNOWN_ID, 'inventory_name' => 'web-prod'], [self::UNKNOWN_ID, 'web-prod']],
            'digit string' => [['inventory_id' => (string)$id, 'inventory_name' => 'web-prod'], [$id, 'web-prod']],
            'no payload' => [null, [null, null]],
            'no inventory' => [['playbook' => 'site.yml'], [null, null]],
            'not an ID' => [['inventory_id' => true, 'inventory_name' => 'web-prod'], [null, null]],
        };
        $job = $this->jobWithPayload($this->openTemplate(), $payload);
        $this->bearerOf($this->admin);

        $detail = $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$job->id]));
        $listed = $this->listItem((int)$job->id);

        $fields = ['inventory_id' => $expected[0], 'inventory_name' => $expected[1]];
        $this->assertSame($fields, $this->inventoryFieldsOf($detail), 'detail');
        $this->assertSame($fields, $this->inventoryFieldsOf($listed), 'list');
    }

    /**
     * The placeholder job of a workflow approval step was launched without
     * an inventory: both fields are null.
     */
    public function testThePlaceholderOfAnApprovalStepNamesNoInventory(): void
    {
        $adminId = (int)$this->admin->id;
        $workflow = $this->createWorkflowTemplate($adminId);
        $rule = $this->ruleOf($this->createUserWithRole('jobs-inv-step-approver', 'operator'));
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        /** @var WorkflowExecutionService $engine */
        $engine = \Yii::$app->get('workflowExecutionService');
        $run = $engine->launch($workflow, $adminId);
        $execution = WorkflowJobStep::findOne(['workflow_job_id' => $run->id]);
        $this->assertNotNull($execution);
        $this->assertTrue($execution->isApprovalPlaceholder());
        $this->bearerOf($this->admin);

        $detail = $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$execution->job_id]));

        $this->assertNull($detail['job_template_id']);
        $this->assertSame(['inventory_id' => null, 'inventory_name' => null], $this->inventoryFieldsOf($detail));
    }

    /**
     * A superadmin without any role gets the inventory from every answer.
     */
    public function testARolelessSuperadminGetsTheInventoryFromEveryAnswer(): void
    {
        $superadmin = $this->rolelessSuperadmin();
        $template = $this->openTemplate();
        $launchedAs = ['inventory_id' => (int)$template->inventory_id, 'inventory_name' => (string)$this->inventoryOf($template)->name];
        $this->bearerOf($superadmin);

        $launched = $this->answerData($this->launch($template), 201);
        $jobId = (int)$launched['id'];
        $detail = $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => $jobId]));
        $listed = $this->listItem($jobId);
        $canceled = $this->answerData($this->callApi($this->controller(), 'cancel', 'POST', ['id' => $jobId]));

        foreach (['launch' => $launched, 'detail' => $detail, 'list' => $listed, 'cancel' => $canceled] as $answer => $data) {
            $this->assertSame($launchedAs, $this->inventoryFieldsOf($data), $answer);
        }
    }

    // -- What the endpoints answer otherwise ----------------------------------

    /**
     * @return array<string, array{0: string, 1: string}> the action and its verb
     */
    public static function endpointProvider(): array
    {
        return [
            'GET list' => ['index', 'GET'],
            'GET one' => ['view', 'GET'],
            'POST' => ['create', 'POST'],
            'POST cancel' => ['cancel', 'POST'],
        ];
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testWithoutAValidTokenTheAnswerIs401(string $action, string $verb): void
    {
        [$job, $body] = $this->queuedJobAndLaunchBody();

        $this->bearerOfNobody(false);
        $missing = $this->failedApiCall($this->controller(), $action, $verb, $this->paramsOf($action, $job), $body);
        $this->bearerOfNobody(true);
        $invalid = $this->failedApiCall($this->controller(), $action, $verb, $this->paramsOf($action, $job), $body);

        $this->assertInstanceOf(UnauthorizedHttpException::class, $missing);
        $this->assertSame([401, 'Bearer token required.'], [$missing->statusCode, $missing->getMessage()]);
        $this->assertInstanceOf(UnauthorizedHttpException::class, $invalid);
        $this->assertSame([401, 'Invalid or expired token.'], [$invalid->statusCode, $invalid->getMessage()]);
        $this->assertNothingLaunchedOrCanceled($job);
    }

    /**
     * A user without the endpoint's permission (job.view, job.launch,
     * job.cancel) gets 403 before anything is read or written.
     *
     * @dataProvider endpointProvider
     */
    public function testWithoutThePermissionTheAnswerIs403(string $action, string $verb): void
    {
        [$job, $body] = $this->queuedJobAndLaunchBody();
        $this->bearerOf($this->createUser('jobs-inv-nobody'));

        $answer = $this->callApi($this->controller(), $action, $verb, $this->paramsOf($action, $job), $body);

        $this->assertApiRefusal(403, 'Forbidden.', $answer);
        $this->assertNothingLaunchedOrCanceled($job);
    }

    /**
     * A job of a project that only another team has is 403: its inventory
     * is not answered, and the list leaves it out.
     */
    public function testAJobOfAnotherTeamsProjectIs403(): void
    {
        $s = $this->teamScope();
        $foreign = $this->jobWithPayload($s['foreign'], $this->payloadNaming($this->inventoryOf($s['foreign'])));
        $own = $this->jobWithPayload($s['own'], $this->payloadNaming($this->inventoryOf($s['own'])));
        $this->bearerOf($s['member']);

        $viewed = $this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$foreign->id]);
        $canceled = $this->callApi($this->controller(), 'cancel', 'POST', ['id' => (int)$foreign->id]);
        $listed = $this->answerItems($this->callApi($this->controller(), 'index', 'GET'));

        $this->assertApiRefusal(403, 'Forbidden.', $viewed);
        $this->assertApiRefusal(403, 'Forbidden.', $canceled);
        $this->assertArrayNotHasKey((int)$foreign->id, $listed);
        $this->assertArrayHasKey((int)$own->id, $listed);
        $foreign->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $foreign->status);
    }

    /**
     * An unknown job is 404 for the detail and the cancel; a launch of an
     * unknown job template answers 404 and launches nothing.
     */
    public function testAnUnknownJobOrTemplateIs404(): void
    {
        $this->bearerOf($this->admin);
        $jobs = (int)Job::find()->count();

        $viewed = $this->failedApiCall($this->controller(), 'view', 'GET', ['id' => self::UNKNOWN_ID]);
        $canceled = $this->failedApiCall($this->controller(), 'cancel', 'POST', ['id' => self::UNKNOWN_ID]);
        $launched = $this->callApi($this->controller(), 'create', 'POST', [], ['job_template_id' => self::UNKNOWN_ID]);

        foreach ([$viewed, $canceled] as $error) {
            $this->assertInstanceOf(NotFoundHttpException::class, $error);
            $this->assertSame('Job #' . self::UNKNOWN_ID . ' not found.', $error->getMessage());
        }
        $this->assertApiRefusal(404, 'Job template #' . self::UNKNOWN_ID . ' not found.', $launched);
        $this->assertSame($jobs, (int)Job::find()->count());
    }

    /**
     * A launch refused for a reason the caller can fix answers 422 with the
     * reason and launches nothing: a template whose own rule differs from
     * its inventory's (JobLaunchService::RULES_DIFFER), and a body without
     * the template.
     */
    public function testARefusedLaunchAnswers422AndLaunchesNothing(): void
    {
        $approver = $this->createUserWithRole('jobs-inv-approver', 'operator');
        $template = $this->templateOnGatedInventory(
            (int)$this->admin->id,
            (int)$this->ruleOf($approver)->id,
            (int)$this->ruleOf($approver)->id
        );
        $this->bearerOf($this->admin);
        $jobs = (int)Job::find()->count();

        $differs = $this->launch($template);
        $noTemplate = $this->callApi($this->controller(), 'create', 'POST', [], ['extra_vars' => ['env' => 'prod']]);

        $this->assertApiRefusal(422, sprintf(JobLaunchService::RULES_DIFFER, (int)$template->id, (int)$template->inventory_id), $differs);
        $this->assertApiRefusal(422, 'job_template_id is required.', $noTemplate);
        $this->assertSame($jobs, (int)Job::find()->count(), 'no job is written');
    }

    // -- Helpers --------------------------------------------------------------

    private function controller(): JobsController
    {
        return new JobsController('api/v1/jobs', \Yii::$app);
    }

    /**
     * POST /api/v1/jobs of $template, as the user whose token the request carries.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function launch(JobTemplate $template): array
    {
        return $this->callApi($this->controller(), 'create', 'POST', [], ['job_template_id' => (int)$template->id]);
    }

    /**
     * The job #$jobId as GET /api/v1/jobs lists it.
     *
     * @return array<string, mixed>
     */
    private function listItem(int $jobId): array
    {
        $item = $this->answerItems($this->callApi($this->controller(), 'index', 'GET'))[$jobId] ?? null;
        $this->assertIsArray($item, "job #{$jobId} is listed");

        return $item;
    }

    /**
     * @param array<mixed> $data
     * @return array{inventory_id: mixed, inventory_name: mixed}
     */
    private function inventoryFieldsOf(array $data): array
    {
        $this->assertArrayHasKey('inventory_id', $data);
        $this->assertArrayHasKey('inventory_name', $data);

        return ['inventory_id' => $data['inventory_id'], 'inventory_name' => $data['inventory_name']];
    }

    /**
     * A job template of a project, an inventory and a runner group of its
     * own (no team restricts the project).
     */
    private function openTemplate(): JobTemplate
    {
        $adminId = (int)$this->admin->id;

        return $this->createJobTemplate(
            (int)$this->createProject($adminId)->id,
            (int)$this->createInventory($adminId)->id,
            (int)$this->createRunnerGroup($adminId)->id,
            $adminId
        );
    }

    private function inventoryOf(JobTemplate $template): Inventory
    {
        $inventory = Inventory::findOne((int)$template->inventory_id);
        $this->assertNotNull($inventory);

        return $inventory;
    }

    /**
     * A rule whose only approver is $approver.
     */
    private function ruleOf(User $approver): ApprovalRule
    {
        return $this->createApprovalRule(
            (int)$this->admin->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [(int)$approver->id]])
        );
    }

    /**
     * A queued job of $template whose runner payload is $payload (null:
     * none), as a launch leaves it.
     *
     * @param array<string, mixed>|null $payload
     */
    private function jobWithPayload(JobTemplate $template, ?array $payload): Job
    {
        $job = $this->createJob((int)$template->id, (int)$this->admin->id);
        $job->runner_payload = $payload === null ? null : (string)json_encode($payload);
        $job->save(false);

        return $job;
    }

    /**
     * The runner payload of a launch with $inventory, as 2.12.0 records it.
     *
     * @return array<string, mixed>
     */
    private function payloadNaming(Inventory $inventory): array
    {
        return ['inventory_id' => (int)$inventory->id, Job::PAYLOAD_INVENTORY_NAME => (string)$inventory->name];
    }

    /**
     * A queued job to view or cancel, and the body of a launch of its
     * template.
     *
     * @return array{0: Job, 1: array<string, mixed>}
     */
    private function queuedJobAndLaunchBody(): array
    {
        $template = $this->openTemplate();
        $job = $this->jobWithPayload($template, $this->payloadNaming($this->inventoryOf($template)));

        return [$job, ['job_template_id' => (int)$template->id]];
    }

    /**
     * @return array<string, int>
     */
    private function paramsOf(string $action, Job $job): array
    {
        return $action === 'view' || $action === 'cancel' ? ['id' => (int)$job->id] : [];
    }

    /**
     * Only $job exists for its template, still queued.
     */
    private function assertNothingLaunchedOrCanceled(Job $job): void
    {
        $this->assertSame(1, (int)Job::find()->where(['job_template_id' => $job->job_template_id])->count(), 'nothing is launched');
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status, 'nothing is canceled');
    }
}
