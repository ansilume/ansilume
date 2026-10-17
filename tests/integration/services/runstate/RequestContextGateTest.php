<?php

declare(strict_types=1);

namespace app\tests\integration\services\runstate;

use app\components\JobInventory;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\runstate\ApprovalRequestContexts;
use app\services\runstate\RequestContext;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\db\Query;

/**
 * What required an approval request and the inventory its job was launched
 * with, as ApprovalRequestContexts::forRequests() gives them
 * (RequestContext::$requiredBy, $inventory): the Rule cell of the lists
 * (ruleText()) names the inventory of a request that the inventory
 * required, and the API's gate fields (gateFields()) name what required it
 * and the inventory. The inventory is the one the job's runner payload
 * records, by its name at launch, also once it was renamed, deleted, or the
 * template moved to another one. All requests' inventories cost one query,
 * none when no payload names an inventory.
 *
 * The fixtures write what a launch writes, directly: the request's
 * required_by through ApprovalRequest::recordRequiredBy(), and the job's
 * runner payload with inventory_id and Job::PAYLOAD_INVENTORY_NAME.
 */
class RequestContextGateTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = (int)$this->createUserWithRole('ctx-gate', 'admin')->id;
    }

    public function testARequestSaysWhatRequiredItAndTheInventoryItsJobWasLaunchedWith(): void
    {
        $inventory = $this->inventoryNamed('web-prod');
        $template = $this->templateOn($inventory);
        $rule = $this->ruleNamed('Production hosts');
        $byInventory = $this->requestOf($this->launchedJob($template, $this->payloadOf($inventory)), $rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $byTemplate = $this->requestOf($this->launchedJob($template, $this->payloadOf($inventory)), $rule, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE);
        $byStep = $this->requestOf($this->placeholder($rule), $rule, ApprovalRequest::REQUIRED_BY_APPROVAL_STEP);

        $contexts = $this->contexts([$byInventory, $byTemplate, $byStep]);

        $inventoryContext = $contexts[(int)$byInventory->id];
        $this->assertSame(ApprovalRequest::REQUIRED_BY_INVENTORY, $inventoryContext->requiredBy);
        $this->assertSame([(int)$inventory->id, 'web-prod', true], $this->inventoryOf($inventoryContext));
        $this->assertSame('Production hosts (of inventory web-prod)', $inventoryContext->ruleText('Production hosts'));
        $this->assertSame(sprintf(Texts::RULE_OF_INVENTORY, 'Production hosts', 'web-prod'), $inventoryContext->ruleText('Production hosts'));
        $this->assertSame(
            ['required_by' => 'inventory', 'inventory_id' => (int)$inventory->id, 'inventory_name' => 'web-prod'],
            $inventoryContext->gateFields()
        );
        $this->assertSame(sprintf(Texts::FOR_JOB_TEMPLATE, (string)$template->name), $inventoryContext->text(), 'For is unchanged');

        $templateContext = $contexts[(int)$byTemplate->id];
        $this->assertSame(ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, $templateContext->requiredBy);
        $this->assertSame('Production hosts', $templateContext->ruleText('Production hosts'), 'the template\'s own rule');
        $this->assertSame(
            ['required_by' => 'job_template', 'inventory_id' => (int)$inventory->id, 'inventory_name' => 'web-prod'],
            $templateContext->gateFields(),
            'the inventory a job was launched with, whatever required its request'
        );

        $stepContext = $contexts[(int)$byStep->id];
        $this->assertSame(ApprovalRequest::REQUIRED_BY_APPROVAL_STEP, $stepContext->requiredBy);
        $this->assertSame([null, null, false], $this->inventoryOf($stepContext), 'a placeholder has no inventory');
        $this->assertSame(JobInventory::NONE, $stepContext->inventory->label());
        $this->assertSame('Production hosts', $stepContext->ruleText('Production hosts'));
        $this->assertSame(['required_by' => 'approval_step', 'inventory_id' => null, 'inventory_name' => null], $stepContext->gateFields());
    }

    /**
     * The inventory as launched: renamed since, the template moved to
     * another inventory, the row deleted. A job launched before 2.12.0
     * recorded no name: the current row names it, and once the row is gone
     * its ID does.
     */
    public function testTheInventoryIsTheOneTheJobWasLaunchedWith(): void
    {
        $inventory = $this->inventoryNamed('web-prod');
        $template = $this->templateOn($inventory);
        $rule = $this->ruleNamed('Production hosts');
        $named = $this->requestOf($this->launchedJob($template, $this->payloadOf($inventory)), $rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $unnamed = $this->requestOf(
            $this->launchedJob($template, ['inventory_id' => (int)$inventory->id, 'extra_vars' => ['env' => 'prod']]),
            $rule,
            ApprovalRequest::REQUIRED_BY_INVENTORY
        );
        $other = $this->inventoryNamed('db-prod');
        $template->inventory_id = (int)$other->id;
        $template->save(false);
        $inventory->name = 'web-prod-renamed';
        $inventory->save(false);

        $contexts = $this->contexts([$named, $unnamed]);

        $this->assertSame([(int)$inventory->id, 'web-prod', true], $this->inventoryOf($contexts[(int)$named->id]));
        $this->assertSame('Production hosts (of inventory web-prod)', $contexts[(int)$named->id]->ruleText('Production hosts'));
        $this->assertSame([(int)$inventory->id, 'web-prod-renamed', true], $this->inventoryOf($contexts[(int)$unnamed->id]), 'named by the current row');

        $this->assertSame(1, Inventory::deleteAll(['id' => $inventory->id]));
        $contexts = $this->contexts([$named, $unnamed]);

        $this->assertSame([(int)$inventory->id, 'web-prod', false], $this->inventoryOf($contexts[(int)$named->id]));
        $this->assertSame('Production hosts (of inventory web-prod)', $contexts[(int)$named->id]->ruleText('Production hosts'));
        $this->assertSame([(int)$inventory->id, null, false], $this->inventoryOf($contexts[(int)$unnamed->id]));
        $this->assertSame(
            sprintf('Production hosts (of inventory Inventory #%d)', (int)$inventory->id),
            $contexts[(int)$unnamed->id]->ruleText('Production hosts')
        );
        $this->assertSame(
            ['required_by' => 'inventory', 'inventory_id' => (int)$inventory->id, 'inventory_name' => null],
            $contexts[(int)$unnamed->id]->gateFields()
        );
    }

    /**
     * The Rule cell names the inventory only for a request that the
     * inventory required and whose job names it; a rule that no longer
     * exists is UNKNOWN. The names come back as stored: the pages encode
     * them.
     */
    public function testTheRuleCellNamesTheInventoryOnlyWhereItRequiredTheRequest(): void
    {
        $inventory = $this->inventoryNamed('<img src=x onerror=alert(1)> "q" \'a\'');
        $template = $this->templateOn($inventory);
        $rule = $this->ruleNamed('Production hosts');
        $withInventory = $this->requestOf($this->launchedJob($template, $this->payloadOf($inventory)), $rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $withoutPayload = $this->requestOf($this->launchedJob($template, null), $rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $notJson = $this->launchedJob($template, null);
        $notJson->runner_payload = '{"inventory_id": ';
        $notJson->save(false);
        $broken = $this->requestOf($notJson, $rule, ApprovalRequest::REQUIRED_BY_INVENTORY);

        $contexts = $this->contexts([$withInventory, $withoutPayload, $broken]);

        $this->assertSame(
            '<b>"Prod"</b> (of inventory <img src=x onerror=alert(1)> "q" \'a\')',
            $contexts[(int)$withInventory->id]->ruleText('<b>"Prod"</b>')
        );
        foreach ([$withoutPayload, $broken] as $request) {
            $context = $contexts[(int)$request->id];
            $this->assertSame([null, null, false], $this->inventoryOf($context));
            $this->assertSame('Production hosts', $context->ruleText('Production hosts'), 'no inventory to name');
            $this->assertSame(['required_by' => 'inventory', 'inventory_id' => null, 'inventory_name' => null], $context->gateFields());
        }
        foreach ($contexts as $context) {
            $this->assertSame(RequestContext::UNKNOWN, $context->ruleText(null), 'the rule is gone');
            $this->assertSame('—', $context->ruleText(null));
        }
    }

    /**
     * Before m000088, or with a schema cached from before it, a request has
     * no required_by: nothing says what required it, and the Rule cell
     * names no inventory, while the job's inventory is still named.
     */
    public function testWithoutTheColumnNothingSaysWhatRequiredARequest(): void
    {
        $inventory = $this->inventoryNamed('web-prod');
        $rule = $this->ruleNamed('Production hosts');
        $stored = $this->requestOf($this->launchedJob($this->templateOn($inventory), $this->payloadOf($inventory)), $rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $old = new class () extends ApprovalRequest {
            public function attributes(): array
            {
                return array_values(array_diff(parent::attributes(), ['required_by']));
            }
        };
        $row = (new Query())->from(ApprovalRequest::tableName())->where(['id' => $stored->id])->one();
        $this->assertIsArray($row);
        $old::populateRecord($old, $row);
        $this->assertNull($old->requiredBy());

        $context = $this->contexts([$old])[(int)$stored->id];

        $this->assertNull($context->requiredBy);
        $this->assertSame('Production hosts', $context->ruleText('Production hosts'));
        $this->assertSame(['required_by' => null, 'inventory_id' => (int)$inventory->id, 'inventory_name' => 'web-prod'], $context->gateFields());
    }

    public function testARequestWhoseJobNoLongerExistsNamesNoInventory(): void
    {
        $inventory = $this->inventoryNamed('web-prod');
        $job = $this->launchedJob($this->templateOn($inventory), $this->payloadOf($inventory));
        $request = $this->requestOf($job, $this->ruleNamed('Production hosts'), ApprovalRequest::REQUIRED_BY_INVENTORY);
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $db->createCommand()->delete(Job::tableName(), ['id' => $job->id])->execute();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }

        $context = $this->contexts([$request])[(int)$request->id];

        $this->assertSame([null, null, false], $this->inventoryOf($context));
        $this->assertSame('Production hosts', $context->ruleText('Production hosts'));
        $this->assertSame(['required_by' => 'inventory', 'inventory_id' => null, 'inventory_name' => null], $context->gateFields());
    }

    /**
     * The inventories of all requests are read at once: 12 requests whose
     * payloads name 12 inventories cost what 2 cost, and requests whose
     * payloads name none cost one query less.
     */
    public function testTheInventoriesOfManyRequestsCostOneQuery(): void
    {
        $rule = $this->ruleNamed('Production hosts');
        $two = $this->requestsNamingInventories(2, $rule);
        $twelve = $this->requestsNamingInventories(12, $rule);
        $template = $this->templateOn($this->inventoryNamed('unnamed'));
        $without = [
            $this->requestOf($this->launchedJob($template, null), $rule, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE),
            $this->requestOf($this->launchedJob($template, ['extra_vars' => []]), $rule, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE),
        ];
        // The first use loads table schemas.
        $this->contexts($two);

        $forTwo = $this->queriesOf(fn () => $this->assertCount(2, $this->contexts($two)));
        $forTwelve = $this->queriesOf(fn () => $this->assertCount(12, $this->contexts($twelve)));
        $forNone = $this->queriesOf(fn () => $this->assertCount(2, $this->contexts($without)));

        $this->assertSame($forTwo, $forTwelve);
        $this->assertSame($forNone + 1, $forTwo);
        foreach ($this->contexts($twelve) as $context) {
            $this->assertNotNull($context->inventory->id);
            $this->assertTrue($context->inventory->exists);
        }
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * @param list<ApprovalRequest> $requests
     * @return array<int, RequestContext>
     */
    private function contexts(array $requests): array
    {
        /** @var ApprovalRequestContexts $contexts */
        $contexts = \Yii::$app->get('approvalRequestContexts');
        $byRequest = $contexts->forRequests($requests, Viewer::of($this->admin));
        $this->assertSame(array_map(static fn (ApprovalRequest $request): int => (int)$request->id, $requests), array_keys($byRequest));

        return $byRequest;
    }

    /**
     * @return array{0: int|null, 1: string|null, 2: bool}
     */
    private function inventoryOf(RequestContext $context): array
    {
        return [$context->inventory->id, $context->inventory->name, $context->inventory->exists];
    }

    private function inventoryNamed(string $name): Inventory
    {
        $inventory = $this->createInventory($this->admin);
        $inventory->name = $name;
        $inventory->save(false);

        return $inventory;
    }

    private function templateOn(Inventory $inventory): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->admin)->id,
            (int)$inventory->id,
            (int)$this->createRunnerGroup($this->admin)->id,
            $this->admin
        );
    }

    private function ruleNamed(string $name): ApprovalRule
    {
        $rule = $this->createApprovalRule($this->admin);
        $rule->name = $name;
        $rule->save(false);

        return $rule;
    }

    /**
     * A runner payload as a launch writes it: the inventory's ID and its
     * name at launch, next to the launch's extra vars.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(Inventory $inventory): array
    {
        return [
            'inventory_id' => (int)$inventory->id,
            Job::PAYLOAD_INVENTORY_NAME => (string)$inventory->name,
            'extra_vars' => ['env' => 'prod'],
        ];
    }

    /**
     * A job of $template that waits for approval, with $payload as its
     * runner payload (none for null).
     *
     * @param array<string, mixed>|null $payload
     */
    private function launchedJob(JobTemplate $template, ?array $payload): Job
    {
        $job = $this->createJob((int)$template->id, $this->admin, Job::STATUS_PENDING_APPROVAL);
        $job->runner_payload = $payload === null ? null : (string)json_encode($payload);
        $job->save(false);

        return $job;
    }

    /**
     * The placeholder job of a workflow approval step, as a run creates it:
     * no template, no runner payload, linked to the step's execution.
     */
    private function placeholder(ApprovalRule $rule): Job
    {
        $workflow = $this->createWorkflowTemplate($this->admin);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $this->admin);
        $job = new Job();
        $job->job_template_id = null;
        $job->launched_by = $this->admin;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->save(false);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->save(false);

        return $job;
    }

    /**
     * A pending request of $job that waits for $rule and records what
     * required it, read back from the database.
     */
    private function requestOf(Job $job, ApprovalRule $rule, string $requiredBy): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->recordRequiredBy($requiredBy);
        $request->save(false);
        $fresh = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($fresh);
        $this->assertSame($requiredBy, $fresh->requiredBy(), 'the test database has m000088');

        return $fresh;
    }

    /**
     * $count requests required by the inventories of their jobs, each job
     * launched with an inventory of its own.
     *
     * @return list<ApprovalRequest>
     */
    private function requestsNamingInventories(int $count, ApprovalRule $rule): array
    {
        $requests = [];
        for ($i = 0; $i < $count; $i++) {
            $inventory = $this->inventoryNamed('hosts-' . $i);
            $requests[] = $this->requestOf(
                $this->launchedJob($this->templateOn($inventory), $this->payloadOf($inventory)),
                $rule,
                ApprovalRequest::REQUIRED_BY_INVENTORY
            );
        }

        return $requests;
    }
}
