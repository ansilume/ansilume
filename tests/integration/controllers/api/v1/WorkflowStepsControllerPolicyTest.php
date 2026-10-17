<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\WorkflowStepCommand;
use app\controllers\api\v1\WorkflowStepsController;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepWriteService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\WorkflowStepWriteFixtures;

/**
 * The per-step endpoints of the workflow API under security rule 12 and
 * team scoping (contracts C6 and C13):
 * - the steps of a workflow with an approval step or a gated job step (a
 *   job step whose job template has an approval rule) are for admins
 *   (approval-rule.update), and so are adding an approval step and giving
 *   a job step a gated job template, on every endpoint;
 * - a job template must exist for the caller (422, also when it is in a
 *   project they may not see, so the answer does not tell whether it
 *   exists or is gated), and the caller must operate it (403);
 * - operator access to the project of every job step of the workflow is
 *   checked by the controller, before the write path, and again by the
 *   write path with the workflow locked (WorkflowStepChangeGuard).
 */
class WorkflowStepsControllerPolicyTest extends WebControllerTestCase
{
    use WorkflowStepApiRequests;
    use WorkflowStepWriteFixtures;

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedWorkflowProvider(): array
    {
        $cases = [];
        foreach (['create', 'update', 'move', 'delete'] as $endpoint) {
            $cases["{$endpoint}, an approval step"] = [$endpoint, 'approval'];
            $cases["{$endpoint}, a gated job step"] = [$endpoint, 'gated job'];
        }

        return $cases;
    }

    /**
     * An operator may not change the steps of a workflow with an approval
     * step or a gated job step, on any endpoint: 403 with the policy's
     * text, nothing written, no audit entry. An admin may.
     *
     * @dataProvider gatedWorkflowProvider
     */
    public function testOnlyAdminsChangeTheStepsOfAGatedWorkflow(string $endpoint, string $gate): void
    {
        $workflow = $this->gatedWorkflow($gate);
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);

        $refused = $this->api(...$this->request($endpoint, $workflow));

        $this->assertApiError(
            403,
            $gate === 'approval'
                ? ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED
                : ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            $refused
        );
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));

        $this->tokenOf($this->s['admin']);
        $allowed = $this->api(...$this->request($endpoint, $workflow));

        $this->assertSame($endpoint === 'create' ? 201 : 200, $allowed['status'], (string)json_encode($allowed['body']));
        $this->assertCount(1, $this->stepAudits((int)$workflow->id));
    }

    /**
     * Adding an approval step is for admins; an operator gets 403 and
     * nothing is written.
     */
    public function testOnlyAdminsAddAnApprovalStep(): void
    {
        $workflow = $this->plainWorkflow();
        $rule = $this->ruleOfAdmin();
        $body = ['name' => 'Approve', 'step_type' => 'approval', 'approval_rule_id' => (int)$rule->id];
        $this->tokenOf($this->s['member']);

        $refused = $this->api('POST', $this->stepsPath((int)$workflow->id), $body);

        $this->assertApiError(403, ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, $refused);
        $this->assertSame(['Build', 'Deploy'], $this->runOrder((int)$workflow->id));

        $this->tokenOf($this->s['admin']);
        $data = $this->dataOf($this->api('POST', $this->stepsPath((int)$workflow->id), $body + ['position' => 2]), 201);

        $this->assertSame(['approval', (int)$rule->id, null, 2], [$data['step_type'], $data['approval_rule_id'], $data['job_template_id'], $data['position']]);
        $this->assertSame(
            ['kind' => 'end', 'via' => 'on_failure', 'step_id' => null, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
            $data['routes']['closed_gate'] ?? null,
            'a refused approval ends the run as failed'
        );
        $this->assertNull($data['routes']['failure']);
    }

    /**
     * A job step with a gated job template is for admins, whether a POST
     * adds it or a PUT gives an existing step that template.
     */
    public function testOnlyAdminsGiveAJobStepAGatedJobTemplate(): void
    {
        $workflow = $this->plainWorkflow();
        $gated = $this->gatedTemplate();
        $deploy = $this->stepIdOf((int)$workflow->id, 'Deploy');
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);

        $added = $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Release', 'job_template_id' => (int)$gated->id]);
        $edited = $this->api('PUT', $this->stepPath((int)$workflow->id, $deploy), ['job_template_id' => (int)$gated->id]);

        $this->assertApiError(403, ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, $added);
        $this->assertApiError(403, ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, $edited);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));

        $this->tokenOf($this->s['admin']);
        $data = $this->dataOf($this->api('PUT', $this->stepPath((int)$workflow->id, $deploy), ['job_template_id' => (int)$gated->id]));

        $this->assertSame((int)$gated->id, $data['job_template_id']);
        $this->assertSame(
            ['kind' => 'end', 'via' => 'on_failure', 'step_id' => null, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
            $data['routes']['closed_gate'] ?? null,
            'a gated job step can end with a closed gate'
        );
    }

    /**
     * A job template gated only through its inventory (C15) is a gated job
     * template too: a POST that adds a job step with it, or a PUT that gives
     * a step it, is for admins (the inventory's text), and the admin's
     * answer gives the step its closed gate.
     */
    public function testOnlyAdminsGiveAJobStepAJobTemplateGatedThroughItsInventory(): void
    {
        $workflow = $this->plainWorkflow();
        $own = $this->s['own'];
        $gated = $this->createJobTemplate((int)$own->project_id, (int)$own->inventory_id, (int)$own->runner_group_id, (int)$this->s['admin']->id);
        $inventory = $this->createInventory((int)$this->s['admin']->id);
        Inventory::updateAll(['approval_rule_id' => (int)$this->ruleOfAdmin()->id], ['id' => $inventory->id]);
        JobTemplate::updateAll(['inventory_id' => (int)$inventory->id], ['id' => $gated->id]);
        $deploy = $this->stepIdOf((int)$workflow->id, 'Deploy');
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);

        $added = $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Release', 'job_template_id' => (int)$gated->id]);
        $edited = $this->api('PUT', $this->stepPath((int)$workflow->id, $deploy), ['job_template_id' => (int)$gated->id]);

        $this->assertApiError(403, ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED, $added);
        $this->assertApiError(403, ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED, $edited);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));

        $this->tokenOf($this->s['admin']);
        $data = $this->dataOf($this->api('PUT', $this->stepPath((int)$workflow->id, $deploy), ['job_template_id' => (int)$gated->id]));

        $this->assertSame((int)$gated->id, $data['job_template_id']);
        $this->assertSame(
            ['kind' => 'end', 'via' => 'on_failure', 'step_id' => null, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
            $data['routes']['closed_gate'] ?? null,
            'a job step gated through its inventory can end with a closed gate'
        );
        $this->assertNull($data['cannot_run']);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function unusableTemplateProvider(): array
    {
        return [
            'POST, another team\'s' => ['create', 422, 'foreign'],
            'POST, unknown' => ['create', 422, 'unknown'],
            'POST, deleted' => ['create', 422, 'deleted'],
            'POST, view only' => ['create', 403, 'viewed'],
            'PUT, another team\'s' => ['update', 422, 'foreign'],
            'PUT, unknown' => ['update', 422, 'unknown'],
            'PUT, deleted' => ['update', 422, 'deleted'],
            'PUT, view only' => ['update', 403, 'viewed'],
        ];
    }

    /**
     * A job template that does not exist for the caller (unknown, deleted,
     * or in a project they may not see) answers 422, one they may see but
     * not operate 403, both naming it in job_template_ids; nothing is
     * written.
     *
     * @dataProvider unusableTemplateProvider
     */
    public function testAJobTemplateTheCallerMayNotUseIsRefused(string $endpoint, int $status, string $kind): void
    {
        $workflow = $this->plainWorkflow();
        $templateId = match ($kind) {
            'unknown' => 987654321,
            'deleted' => $this->deletedTemplateId(),
            default => (int)$this->s[$kind]->id,
        };
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $body = ['name' => 'Release', 'job_template_id' => $templateId];

        $answer = $endpoint === 'create'
            ? $this->api('POST', $this->stepsPath((int)$workflow->id), $body)
            : $this->api('PUT', $this->stepPath((int)$workflow->id, $this->stepIdOf((int)$workflow->id, 'Deploy')), $body);

        $this->assertApiError(
            $status,
            $status === 422 ? WorkflowStep::JOB_TEMPLATE_NOT_FOUND : WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN,
            $answer,
            ['job_template_ids' => [$templateId]]
        );
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * A job template of another team that has an approval rule answers as
     * not found (422), not as gated (403): the answer does not tell whether
     * a template the caller may not see exists or is gated.
     */
    public function testAGatedJobTemplateTheCallerCannotSeeIsNotFound(): void
    {
        $workflow = $this->plainWorkflow();
        JobTemplate::updateAll(['approval_rule_id' => (int)$this->ruleOfAdmin()->id], ['id' => $this->s['foreign']->id]);
        $this->tokenOf($this->s['member']);

        $answer = $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Release', 'job_template_id' => (int)$this->s['foreign']->id]);

        $this->assertApiError(422, WorkflowStep::JOB_TEMPLATE_NOT_FOUND, $answer, ['job_template_ids' => [(int)$this->s['foreign']->id]]);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function idWrittenAnotherWayProvider(): array
    {
        return [
            'zero-padded: no ID' => ['0%d', false],
            'with a plus sign: that ID' => ['+%d', true],
            'with a space: that ID' => [' %d', true],
        ];
    }

    /**
     * The step-1 bug class (a check of one ID, a write of another): an ID
     * written another way never reaches a job template the caller may only
     * view. The API reads it as WorkflowStepsInput::toId() does, for the
     * check and for the write alike: a zero-padded ID is no ID (422, a job
     * step needs a job template), a sign or a space is read away, and the
     * template is refused as itself (403). Nothing is written.
     *
     * @dataProvider idWrittenAnotherWayProvider
     */
    public function testAJobTemplateIdWrittenAnotherWayNeverReachesAViewOnlyTemplate(string $format, bool $readAsTheId): void
    {
        $workflow = $this->plainWorkflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $viewed = (int)$this->s['viewed']->id;
        $id = sprintf($format, $viewed);

        $added = $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Release', 'job_template_id' => $id]);
        $edited = $this->api('PUT', $this->stepPath((int)$workflow->id, $this->stepIdOf((int)$workflow->id, 'Deploy')), ['job_template_id' => $id]);

        foreach ([$added, $edited] as $answer) {
            if ($readAsTheId) {
                $this->assertApiError(403, WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN, $answer, ['job_template_ids' => [$viewed]]);
            } else {
                $this->assertApiError(422, WorkflowStep::JOB_TEMPLATE_REQUIRED, $answer);
            }
        }
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
    }

    public function testAnAdminMayUseAnyJobTemplate(): void
    {
        $workflow = $this->plainWorkflow();
        $this->tokenOf($this->s['admin']);

        $data = $this->dataOf($this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Release', 'job_template_id' => (int)$this->s['foreign']->id]), 201);

        $this->assertSame((int)$this->s['foreign']->id, $data['job_template_id']);
    }

    /**
     * Security (C13 step 4): the controller checks operator access to the
     * project of every job step before the write path, so a member who may
     * only view one step's project cannot rename, move or remove the
     * workflow's steps. The write path checks it again with the workflow
     * locked (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, the same text),
     * for a step that another user adds in between
     * (WorkflowStepTeamScopeRaceApiTest).
     */
    public function testTheTeamScopeIsCheckedBeforeTheWritePath(): void
    {
        $workflow = $this->plainWorkflow();
        $report = $this->namedStep((int)$workflow->id, 'Report', 30, WorkflowStep::TYPE_JOB, (int)$this->s['viewed']->id);
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);

        foreach (['PUT' => $this->stepPath((int)$workflow->id, (int)$report->id), 'DELETE' => $this->stepPath((int)$workflow->id, (int)$report->id)] as $verb => $path) {
            $this->assertApiError(403, WorkflowStepsController::FORBIDDEN, $this->api($verb, $path, ['name' => 'Renamed']));
        }
        $this->assertApiError(403, WorkflowStepsController::FORBIDDEN, $this->api('POST', $this->movePath((int)$workflow->id, (int)$report->id), ['position' => 'start']));
        $this->assertApiError(403, WorkflowStepsController::FORBIDDEN, $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Pause', 'step_type' => 'pause']));
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));

        // The write path checks it again under the lock (WorkflowStepChangeGuard), with the same answer.
        $refusal = $this->refusalOf(fn () => $this->writer()->update($workflow, (int)$report->id, $this->apiCommand(['name' => 'Renamed'], false), (int)$this->s['member']->id, 'api'));
        $this->assertSame([403, WorkflowStepsController::FORBIDDEN, []], [$refusal->status, $refusal->getMessage(), $refusal->details]);
        $this->assertSame(WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, $refusal->getMessage());
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
    }

    /**
     * The policy comes after the body (C13: 422 for the body, then 403) and
     * before the job template (then 422 or 403 for the template).
     */
    public function testThePolicyComesAfterTheBodyAndBeforeTheJobTemplate(): void
    {
        $workflow = $this->gatedWorkflow('approval');
        $this->tokenOf($this->s['member']);
        $path = $this->stepsPath((int)$workflow->id);

        $body = $this->api('POST', $path, ['name' => 'Release', 'job_template_id' => (int)$this->s['own']->id, 'on_success_step_id' => 'next']);
        $policy = $this->api('POST', $path, ['name' => 'Release', 'job_template_id' => (int)$this->s['foreign']->id]);

        $this->assertApiError(422, sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_success_step_id'), $body);
        $this->assertApiError(403, ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $policy);
    }

    /**
     * The job template comes before the model's other rules: a step
     * without a name and with a job template of another team answers that
     * the template does not exist.
     */
    public function testTheJobTemplateComesBeforeTheModelsRules(): void
    {
        $workflow = $this->plainWorkflow();
        $this->tokenOf($this->s['member']);

        $answer = $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => '', 'job_template_id' => (int)$this->s['foreign']->id]);

        $this->assertApiError(422, WorkflowStep::JOB_TEMPLATE_NOT_FOUND, $answer, ['job_template_ids' => [(int)$this->s['foreign']->id]]);
    }

    /**
     * An approval step that an older version saved with an Always branch
     * keeps it until an update clears it: a PUT that leaves it is refused
     * (the model's rule), one that sends on_always_step_id null clears it.
     */
    public function testAnApprovalStepWithAnOldAlwaysBranchIsSavedOnlyWithoutIt(): void
    {
        $workflow = $this->gatedWorkflow('approval');
        $approve = WorkflowStep::findOne(['workflow_template_id' => $workflow->id, 'step_type' => WorkflowStep::TYPE_APPROVAL]);
        $this->assertNotNull($approve);
        $approve->on_always_step_id = $this->stepIdOf((int)$workflow->id, 'Deploy');
        $approve->save(false);
        $this->tokenOf($this->s['admin']);
        $path = $this->stepPath((int)$workflow->id, (int)$approve->id);

        $kept = $this->api('PUT', $path, ['name' => 'Approve release']);
        $cleared = $this->dataOf($this->api('PUT', $path, ['name' => 'Approve release', 'on_always_step_id' => null]));

        $this->assertApiError(422, WorkflowStep::APPROVAL_ALWAYS_REFUSED, $kept);
        $this->assertSame(['Approve release', null], [$cleared['name'], $cleared['on_always_step_id']]);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Build and Deploy, two job steps on the member's own job template.
     */
    private function plainWorkflow(): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['own']->id);
        $this->namedStep((int)$workflow->id, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->s['own']->id);

        return $workflow;
    }

    /**
     * plainWorkflow() with an approval step first, or with a gated job step
     * (a job template of the member's own project with an approval rule).
     */
    private function gatedWorkflow(string $gate): WorkflowTemplate
    {
        $workflow = $this->plainWorkflow();
        if ($gate === 'approval') {
            $this->namedStep((int)$workflow->id, 'Approve', 5, WorkflowStep::TYPE_APPROVAL, null, (int)$this->ruleOfAdmin()->id);
        } else {
            $this->namedStep((int)$workflow->id, 'Release', 30, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate()->id);
        }

        return $workflow;
    }

    /**
     * The request of $endpoint on $workflow's step Build, with a body that
     * changes something.
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function request(string $endpoint, WorkflowTemplate $workflow): array
    {
        $build = $this->stepIdOf((int)$workflow->id, 'Build');

        return match ($endpoint) {
            'create' => ['POST', $this->stepsPath((int)$workflow->id), ['name' => 'Lint', 'step_type' => 'pause']],
            'update' => ['PUT', $this->stepPath((int)$workflow->id, $build), ['name' => 'Compile']],
            'move' => ['POST', $this->movePath((int)$workflow->id, $build), ['position' => 'end']],
            default => ['DELETE', $this->stepPath((int)$workflow->id, $build), []],
        };
    }

    /**
     * A job template in the member's own project, gated by an approval rule
     * whose approvers are the admin and the member.
     */
    private function gatedTemplate(): JobTemplate
    {
        $own = $this->s['own'];
        $template = $this->createJobTemplate((int)$own->project_id, (int)$own->inventory_id, (int)$own->runner_group_id, (int)$this->s['admin']->id);
        JobTemplate::updateAll(['approval_rule_id' => (int)$this->ruleOfAdmin()->id], ['id' => $template->id]);
        $template->refresh();

        return $template;
    }

    /**
     * An approval rule whose approvers are the admin and the member.
     */
    private function ruleOfAdmin(): ApprovalRule
    {
        return $this->createApprovalRule(
            (int)$this->s['admin']->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [(int)$this->s['admin']->id, (int)$this->s['member']->id]])
        );
    }

    private function deletedTemplateId(): int
    {
        $own = $this->s['own'];
        $deleted = $this->createJobTemplate((int)$own->project_id, (int)$own->inventory_id, (int)$own->runner_group_id, (int)$this->s['admin']->id);
        $deleted->softDelete();

        return (int)$deleted->id;
    }
}
