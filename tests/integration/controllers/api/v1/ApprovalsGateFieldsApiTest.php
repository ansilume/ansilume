<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalsController;
use app\models\ApprovalRequest;
use app\models\ApprovalRequestSearch;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\services\JobLaunchService;
use app\services\WorkflowExecutionService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\InventoryGatedStepFixtures;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * required_by, inventory_id and inventory_name of /api/v1/approvals: what
 * required a request (the job template's own rule, its inventory's, or a
 * workflow approval step) and the inventory its job was launched with, as
 * the job's runner payload records it, right after approval_rule_id, in
 * the list, the detail and the answers of approve and reject. The list
 * reads them for its page at once.
 */
class ApprovalsGateFieldsApiTest extends WebControllerTestCase
{
    use CountsQueries;
    use GateFieldsApiCalls;
    use InventoryGatedStepFixtures;
    use RestoresComponents;
    use TeamScopeFixtures;

    /** An ID that no request has. */
    private const UNKNOWN_ID = 987654321;

    private User $admin;

    private User $launcher;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        // Launches announce their jobs: recorded, not sent. Swapped before
        // any write, so that tearDown() restores it also when a write fails.
        $this->swapComponent('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->admin = $this->createUserWithRole('appr-gate-admin', 'admin');
        $this->launcher = $this->createUserWithRole('appr-gate-launcher', 'operator');
        $this->approver = $this->createUserWithRole('appr-gate-approver', 'operator');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredByProvider(): array
    {
        return [
            'the rule of the job\'s inventory' => ['inventory'],
            'the job template\'s own rule' => ['job_template'],
            'a workflow approval step' => ['approval_step'],
        ];
    }

    /**
     * Every answer says what required the request and names the inventory
     * its job was launched with (none for the placeholder of an approval
     * step), right after approval_rule_id: the list, the detail, and the
     * answers of approve and of reject.
     *
     * @dataProvider requiredByProvider
     */
    public function testEveryAnswerSaysWhatRequiredTheRequest(string $requiredBy): void
    {
        [$approved, $fields] = $this->launchedRequest($requiredBy);
        [$rejected, $rejectedFields] = $this->launchedRequest($requiredBy);
        $this->bearerOf($this->admin);
        $answers = [
            'list' => $this->answerItems($this->callApi($this->controller(), 'index', 'GET'))[(int)$approved->id] ?? null,
            'detail' => $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$approved->id])),
        ];
        $this->bearerOf($this->approver);
        $answers['approve'] = $this->answerData($this->callApi($this->controller(), 'approve', 'POST', ['id' => (int)$approved->id]));
        $answers['reject'] = $this->answerData($this->callApi($this->controller(), 'reject', 'POST', ['id' => (int)$rejected->id]));

        foreach ($answers as $answer => $data) {
            $this->assertIsArray($data, $answer);
            $this->assertSame($answer === 'reject' ? $rejectedFields : $fields, $this->gateFieldsOf($data), $answer);
            $keys = array_keys($data);
            $at = array_search('approval_rule_id', $keys, true);
            $this->assertIsInt($at);
            $this->assertSame(['approval_rule_id', 'required_by', 'inventory_id', 'inventory_name'], array_slice($keys, $at, 4), "{$answer}: right after approval_rule_id");
        }
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $answers['approve']['status']);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $answers['reject']['status']);
    }

    /**
     * The inventory is the one the job was launched with, by its name at
     * launch, also once it was renamed and its template uses another one; a
     * job launched before 2.12.0 recorded no name, so its inventory's
     * current one is answered.
     */
    public function testTheInventoryIsTheOneTheJobWasLaunchedWith(): void
    {
        [$request, $fields] = $this->launchedRequest('inventory');
        $job = Job::findOne((int)$request->job_id);
        $this->assertNotNull($job);
        $template = JobTemplate::findOne((int)$job->job_template_id);
        $this->assertNotNull($template);
        Inventory::updateAll(['name' => 'appr-gate-renamed-' . uniqid()], ['id' => $fields['inventory_id']]);
        $this->moveToInventory((int)$template->id, (int)$this->createInventory((int)$this->admin->id)->id);
        [$older, $olderFields] = $this->launchedRequest('inventory');
        $current = 'appr-gate-current-' . uniqid();
        Inventory::updateAll(['name' => $current], ['id' => $olderFields['inventory_id']]);
        $this->forgetTheNameAtLaunch((int)$older->job_id);
        $this->bearerOf($this->admin);

        $renamed = $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$request->id]));
        $beforeTheRelease = $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$older->id]));

        $this->assertSame($fields, $this->gateFieldsOf($renamed), 'the name at launch');
        $this->assertSame(array_merge($olderFields, ['inventory_name' => $current]), $this->gateFieldsOf($beforeTheRelease), 'the current name');
    }

    /**
     * The list reads what required its requests and their inventories for
     * the page at once: 2 and 12 requests, each of a job on an inventory of
     * its own, cost the same.
     */
    public function testTheListCostsTheSameFor2And12Requests(): void
    {
        $rule = $this->ruleListing($this->approver);
        $this->storedRequests($rule, 2);
        $this->loginAs($this->admin);
        $controller = $this->controller();
        $this->setQueryParams([]);
        $controller->actionIndex();
        $few = $this->queriesOf(fn () => $controller->actionIndex());

        $requests = $this->storedRequests($rule, 10);
        $many = $this->queriesOf(fn () => $controller->actionIndex());
        $listed = [];
        foreach ($controller->actionIndex()['data'] as $item) {
            $this->assertIsArray($item);
            $listed[(int)$item['id']] = $this->gateFieldsOf($item);
        }

        $this->assertSame($few, $many, '2 requests and 12 requests');
        foreach ($requests as [$request, $inventory]) {
            $this->assertSame(
                ['required_by' => 'inventory', 'inventory_id' => (int)$inventory->id, 'inventory_name' => (string)$inventory->name],
                $listed[(int)$request->id] ?? null
            );
        }
    }

    /**
     * A superadmin without any role gets the fields from the list, the
     * detail and the answer of their vote.
     */
    public function testARolelessSuperadminGetsTheFieldsFromEveryAnswer(): void
    {
        $superadmin = $this->rolelessSuperadmin();
        $rule = $this->ruleListing($superadmin);
        $template = $this->templateOnGatedInventory((int)$this->admin->id, (int)$rule->id);
        $request = $this->launchAndRequest($template);
        $inventory = Inventory::findOne((int)$template->inventory_id);
        $this->assertNotNull($inventory);
        $fields = ['required_by' => 'inventory', 'inventory_id' => (int)$inventory->id, 'inventory_name' => (string)$inventory->name];
        $this->bearerOf($superadmin);

        $listed = $this->answerItems($this->callApi($this->controller(), 'index', 'GET'))[(int)$request->id] ?? null;
        $viewed = $this->answerData($this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$request->id]));
        $approved = $this->answerData($this->callApi($this->controller(), 'approve', 'POST', ['id' => (int)$request->id]));

        $this->assertIsArray($listed);
        foreach (['list' => $listed, 'detail' => $viewed, 'approve' => $approved] as $answer => $data) {
            $this->assertSame($fields, $this->gateFieldsOf($data), $answer);
        }
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $approved['status']);
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
            'POST approve' => ['approve', 'POST'],
            'POST reject' => ['reject', 'POST'],
        ];
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testWithoutAValidTokenTheAnswerIs401(string $action, string $verb): void
    {
        [$request] = $this->launchedRequest('inventory');
        $params = $action === 'index' ? [] : ['id' => (int)$request->id];

        $this->bearerOfNobody(false);
        $missing = $this->failedApiCall($this->controller(), $action, $verb, $params);
        $this->bearerOfNobody(true);
        $invalid = $this->failedApiCall($this->controller(), $action, $verb, $params);

        $this->assertInstanceOf(UnauthorizedHttpException::class, $missing);
        $this->assertSame([401, 'Bearer token required.'], [$missing->statusCode, $missing->getMessage()]);
        $this->assertInstanceOf(UnauthorizedHttpException::class, $invalid);
        $this->assertSame([401, 'Invalid or expired token.'], [$invalid->statusCode, $invalid->getMessage()]);
        $this->assertStillPending($request);
    }

    /**
     * A user without the endpoint's permission (approval.view to read,
     * approval.decide to vote) gets 403 before anything is read or written:
     * one without any role, and a viewer, who may read but not vote.
     *
     * @dataProvider endpointProvider
     */
    public function testWithoutThePermissionTheAnswerIs403(string $action, string $verb): void
    {
        [$request] = $this->launchedRequest('inventory');
        $params = $action === 'index' ? [] : ['id' => (int)$request->id];
        $callers = ['no role' => $this->createUser('appr-gate-nobody')];
        if ($verb === 'POST') {
            $callers['a viewer'] = $this->createUserWithRole('appr-gate-viewer', 'viewer');
        }

        foreach ($callers as $caller => $user) {
            $this->bearerOf($user);
            $answer = $this->callApi($this->controller(), $action, $verb, $params);
            $this->assertSame(['error' => ['message' => 'Forbidden.']], $answer['body'], $caller);
            $this->assertSame(403, $answer['status'], $caller);
        }
        $this->assertStillPending($request);
    }

    /**
     * The request of a job of a project that only another team has is 403
     * to read and to vote on, and the list leaves it out.
     */
    public function testARequestOfAnotherTeamsProjectIs403(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleListing($s['admin']);
        $this->giveInventoryRule((int)$s['foreign']->inventory_id, (int)$rule->id);
        $request = $this->launchAndRequest($s['foreign'], $s['outsider']);
        $this->bearerOf($s['member']);

        $viewed = $this->callApi($this->controller(), 'view', 'GET', ['id' => (int)$request->id]);
        $approved = $this->callApi($this->controller(), 'approve', 'POST', ['id' => (int)$request->id]);
        $listed = $this->answerItems($this->callApi($this->controller(), 'index', 'GET'));

        $this->assertApiRefusal(403, 'Forbidden.', $viewed);
        $this->assertApiRefusal(403, 'Forbidden.', $approved);
        $this->assertArrayNotHasKey((int)$request->id, $listed);
        $this->assertStillPending($request);
    }

    /**
     * @return array<string, array{0: string, 1: string}> the action and its verb
     */
    public static function oneRequestProvider(): array
    {
        return [
            'GET one' => ['view', 'GET'],
            'POST approve' => ['approve', 'POST'],
            'POST reject' => ['reject', 'POST'],
        ];
    }

    /**
     * @dataProvider oneRequestProvider
     */
    public function testAnUnknownRequestIs404(string $action, string $verb): void
    {
        $this->bearerOf($this->admin);

        $error = $this->failedApiCall($this->controller(), $action, $verb, ['id' => self::UNKNOWN_ID]);

        $this->assertInstanceOf(NotFoundHttpException::class, $error);
        $this->assertSame('Approval request #' . self::UNKNOWN_ID . ' not found.', $error->getMessage());
    }

    /**
     * An unknown ?status= of the list, and a comment that is no string,
     * answer 422; the vote is not recorded.
     */
    public function testAnInvalidStatusOrCommentIs422(): void
    {
        [$request] = $this->launchedRequest('inventory');
        $this->bearerOf($this->admin);
        $listed = $this->callApi($this->controller(), 'index', 'GET', [], [], ['status' => 'bogus']);
        $this->bearerOf($this->approver);
        $voted = $this->callApi($this->controller(), 'approve', 'POST', ['id' => (int)$request->id], ['comment' => ['not', 'text']]);

        $this->assertApiRefusal(422, ApprovalRequestSearch::statusError(), $listed);
        $this->assertApiRefusal(422, sprintf(ApprovalDecisionRefusedException::INVALID_DECISION, ApprovalService::COMMENT_NOT_A_STRING), $voted);
        $this->assertStillPending($request);
    }

    // -- Helpers --------------------------------------------------------------

    private function controller(): ApprovalsController
    {
        return new ApprovalsController('api/v1/approvals', \Yii::$app);
    }

    /**
     * A pending request that $requiredBy required, of a job the launcher
     * launched as a launch writes it, whose rule lists the approver; with
     * the gate fields the API should answer for it.
     *
     * @return array{0: ApprovalRequest, 1: array{required_by: string, inventory_id: int|null, inventory_name: string|null}}
     */
    private function launchedRequest(string $requiredBy): array
    {
        $rule = $this->ruleListing($this->approver);
        if ($requiredBy === 'approval_step') {
            return [$this->placeholderRequest($rule), ['required_by' => 'approval_step', 'inventory_id' => null, 'inventory_name' => null]];
        }
        $byInventory = $requiredBy === 'inventory';
        $template = $this->templateOnGatedInventory(
            (int)$this->admin->id,
            $byInventory ? (int)$rule->id : null,
            $byInventory ? null : (int)$rule->id
        );
        $inventory = Inventory::findOne((int)$template->inventory_id);
        $this->assertNotNull($inventory);
        $request = $this->launchAndRequest($template);
        $this->assertSame($requiredBy, $request->requiredBy());

        return [$request, ['required_by' => $requiredBy, 'inventory_id' => (int)$inventory->id, 'inventory_name' => (string)$inventory->name]];
    }

    /**
     * The request of the job that $launcher (else the launcher) launched of
     * $template.
     */
    private function launchAndRequest(JobTemplate $template, ?User $launcher = null): ApprovalRequest
    {
        /** @var JobLaunchService $launches */
        $launches = \Yii::$app->get('jobLaunchService');
        $job = $launches->launch($template, (int)($launcher ?? $this->launcher)->id);
        $request = ApprovalRequest::findOne(['job_id' => (int)$job->id]);
        $this->assertNotNull($request, 'the job waits for approval');

        return $request;
    }

    /**
     * The request of the placeholder job of a workflow's only step, an
     * approval step with $rule, in a run the launcher launched.
     */
    private function placeholderRequest(ApprovalRule $rule): ApprovalRequest
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        /** @var WorkflowExecutionService $engine */
        $engine = \Yii::$app->get('workflowExecutionService');
        $run = $engine->launch($workflow, (int)$this->launcher->id);
        $execution = WorkflowJobStep::findOne(['workflow_job_id' => $run->id]);
        $this->assertNotNull($execution);
        $request = ApprovalRequest::findOne(['job_id' => (int)$execution->job_id]);
        $this->assertNotNull($request);
        $this->assertSame('approval_step', $request->requiredBy());

        return $request;
    }

    private function ruleListing(User $approver): ApprovalRule
    {
        return $this->createApprovalRule(
            (int)$this->admin->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [(int)$approver->id]])
        );
    }

    /**
     * $count pending requests of $rule that inventories required, each of a
     * job launched with an inventory of its own; each with its inventory.
     *
     * @return list<array{0: ApprovalRequest, 1: Inventory}>
     */
    private function storedRequests(ApprovalRule $rule, int $count): array
    {
        $adminId = (int)$this->admin->id;
        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');
        $requests = [];
        for ($i = 0; $i < $count; $i++) {
            $template = $this->templateOnGatedInventory($adminId, (int)$rule->id);
            $inventory = Inventory::findOne((int)$template->inventory_id);
            $this->assertNotNull($inventory);
            $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_PENDING_APPROVAL);
            $job->runner_payload = (string)json_encode(['inventory_id' => (int)$inventory->id, Job::PAYLOAD_INVENTORY_NAME => (string)$inventory->name]);
            $job->save(false);
            $requests[] = [$approvals->createRequest($job, $rule, ApprovalRequest::REQUIRED_BY_INVENTORY), $inventory];
        }

        return $requests;
    }

    /**
     * Leave job #$jobId's runner payload as a launch before 2.12.0 wrote
     * it: with the inventory's ID, without its name.
     */
    private function forgetTheNameAtLaunch(int $jobId): void
    {
        $job = Job::findOne($jobId);
        $this->assertNotNull($job);
        $payload = $job->decodedRunnerPayload();
        $this->assertArrayHasKey(Job::PAYLOAD_INVENTORY_NAME, $payload);
        unset($payload[Job::PAYLOAD_INVENTORY_NAME]);
        $job->runner_payload = (string)json_encode($payload);
        $job->save(false);
    }

    /**
     * @param array<mixed> $data
     * @return array{required_by: mixed, inventory_id: mixed, inventory_name: mixed}
     */
    private function gateFieldsOf(array $data): array
    {
        foreach (['required_by', 'inventory_id', 'inventory_name'] as $field) {
            $this->assertArrayHasKey($field, $data);
        }

        return ['required_by' => $data['required_by'], 'inventory_id' => $data['inventory_id'], 'inventory_name' => $data['inventory_name']];
    }

    private function assertStillPending(ApprovalRequest $request): void
    {
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status, 'no vote is recorded');
        $this->assertSame([], $request->decisions);
    }
}
