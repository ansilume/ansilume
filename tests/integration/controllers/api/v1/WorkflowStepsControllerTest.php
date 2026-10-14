<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveySecrets;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepPosition;
use app\components\WorkflowStepSerializer;
use app\controllers\api\v1\WorkflowStepsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\JobLaunchSecrets;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepWriteService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\WorkflowStepWriteFixtures;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * The per-step endpoints of the workflow API (contract C13), sent through
 * the URL rules of config/web.php and the controller's filters and gate:
 * POST /api/v1/workflow-templates/{id}/steps, PUT and DELETE
 * /api/v1/workflow-templates/{id}/steps/{stepId}, and POST
 * /api/v1/workflow-templates/{id}/steps/{stepId}/move. Each step keeps its
 * ID, and the branches of every step stay. The answers up to the body
 * (401, 403, 404, 422) are here; the policy (rule 12) and the job templates
 * are in WorkflowStepsControllerPolicyTest, active runs, steps that other
 * steps route to and changes made meanwhile in
 * WorkflowStepsControllerConflictTest.
 */
class WorkflowStepsControllerTest extends WebControllerTestCase
{
    use WorkflowStepApiRequests;
    use WorkflowStepWriteFixtures;

    /** The fields of a step in every answer, in this order. */
    private const STEP_FIELDS = [
        'id', 'name', 'step_order', 'step_type', 'job_template_id', 'approval_rule_id',
        'on_success_step_id', 'on_failure_step_id', 'on_always_step_id', 'extra_vars_template',
        'position', 'is_start', 'reachable', 'cannot_run', 'routes',
    ];

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
    }

    // -- Regressions ----------------------------------------------------------

    /**
     * regression: the API could change one step only by replacing all
     * steps, which dropped every branch. PUT on one step changes it in
     * place: it keeps its ID, and the branches of every other step stay.
     */
    public function testRegressionTheApiChangesOneStepWithoutDroppingTheBranches(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $others = array_intersect_key($this->stepRows((int)$workflow->id), [$ids['Build'] => true, $ids['Cleanup'] => true]);
        $this->tokenOf($this->s['member']);

        $answer = $this->api('PUT', $this->stepPath((int)$workflow->id, $ids['Test']), [
            'name' => 'Unit tests',
            'on_failure_step_id' => $ids['Cleanup'],
        ]);

        $data = $this->dataOf($answer);
        $this->assertSame($ids['Test'], $data['id'], 'the step keeps its ID');
        $this->assertSame('Unit tests', $data['name']);
        $this->assertSame(2, $data['position']);
        $this->assertSame($ids['Cleanup'], $data['on_failure_step_id']);
        $this->assertSame(
            ['kind' => 'step', 'via' => 'on_failure', 'step_id' => $ids['Cleanup'], 'position' => 3, 'run_outcome' => null, 'loop' => false],
            $data['routes']['failure'] ?? null
        );
        $this->assertSame(
            ['message' => sprintf(WorkflowStepChangeSummary::SAVED, 'Unit tests', 'name, On Failure'), 'warnings' => []],
            $this->changeOf($answer)
        );
        $this->assertSame(array_values($ids), array_keys($this->stepRows((int)$workflow->id)), 'no step got a new ID');
        $this->assertSame($others, array_intersect_key($this->stepRows((int)$workflow->id), $others), 'the other steps and their branches stay');
        $audits = $this->stepAudits((int)$workflow->id);
        $this->assertCount(1, $audits);
        $this->assertSame(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED, $audits[0]['action']);
        $this->assertSame((int)$this->s['member']->id, $audits[0]['user_id']);
        $this->assertSame('api', $audits[0]['context']['source'] ?? null);
    }

    /**
     * regression: the API could not set a branch: the steps list does not
     * read routes. A step is added with its branches, and an earlier step
     * is given a branch to it, a step added after it.
     */
    public function testRegressionTheApiSetsTheBranchesOfAStep(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $added = $this->dataOf($this->api('POST', $this->stepsPath((int)$workflow->id), [
            'name' => 'Notify',
            'job_template_id' => (int)$this->s['own']->id,
            'on_success_step_id' => 0,
            'on_failure_step_id' => (string)$ids['Cleanup'],
        ]), 201);
        $notify = (int)$added['id'];
        $this->assertSame([0, $ids['Cleanup'], null], [$added['on_success_step_id'], $added['on_failure_step_id'], $added['on_always_step_id']]);
        $this->assertSame(
            [
                'success' => ['kind' => 'end', 'via' => 'on_success', 'step_id' => null, 'position' => null, 'run_outcome' => 'succeeds', 'loop' => false],
                'failure' => ['kind' => 'step', 'via' => 'on_failure', 'step_id' => $ids['Cleanup'], 'position' => 3, 'run_outcome' => null, 'loop' => true],
                'closed_gate' => null,
            ],
            $added['routes']
        );

        $build = $this->dataOf($this->api('PUT', $this->stepPath((int)$workflow->id, $ids['Build']), ['on_success_step_id' => $notify]));

        $this->assertSame($notify, $build['on_success_step_id']);
        $this->assertSame(
            ['kind' => 'step', 'via' => 'on_success', 'step_id' => $notify, 'position' => 4, 'run_outcome' => null, 'loop' => false],
            $build['routes']['success'] ?? null
        );
        $stored = WorkflowStep::findOne($ids['Build']);
        $this->assertNotNull($stored);
        $this->assertSame([$notify, $ids['Cleanup']], [(int)$stored->on_success_step_id, (int)$stored->on_failure_step_id]);
        $detail = $this->detailOf($workflow);
        $this->assertSame([true, false, true, true], array_column($detail['steps'], 'reachable'), 'no branch leads to Test any more');
        $this->assertSame([$ids['Build'], $notify], $detail['success_path']['step_ids']);
    }

    // -- POST -----------------------------------------------------------------

    public function testCreateAnswers201WithTheStepAsTheWorkflowListsIt(): void
    {
        [$workflow] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $answer = $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Deploy', 'job_template_id' => (int)$this->s['own']->id]);

        $data = $this->dataOf($answer, 201);
        $this->assertSame(self::STEP_FIELDS, array_keys($data));
        $id = (int)$data['id'];
        $this->assertSame(WorkflowStepSerializer::forTemplate($workflow)->step($id), $data);
        // Unreachable: both branches of Cleanup, the step before it, end the workflow.
        $this->assertSame(['Deploy', 'job', (int)$this->s['own']->id, 4, false, false, null], [
            $data['name'], $data['step_type'], $data['job_template_id'], $data['position'], $data['is_start'], $data['reachable'], $data['cannot_run'],
        ]);
        $this->assertSame(
            ['message' => sprintf(WorkflowStepChangeSummary::ADDED, 'Deploy', 4, 4, sprintf(WorkflowStepChangeSummary::AFTER, 'Cleanup')), 'warnings' => []],
            $this->changeOf($answer, 201)
        );
        $this->assertSame(['Build', 'Test', 'Cleanup', 'Deploy'], $this->runOrder((int)$workflow->id));
        $audits = $this->stepAudits((int)$workflow->id);
        $this->assertSame([AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED], array_column($audits, 'action'));
        $this->assertSame(['api', $id], [$audits[0]['context']['source'] ?? null, $audits[0]['context']['step_id'] ?? null]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function placementProvider(): array
    {
        return [
            'at the start' => [['position' => 'start'], ['Lint', 'Build', 'Test', 'Cleanup']],
            'at the end' => [['position' => 'end'], ['Build', 'Test', 'Cleanup', 'Lint']],
            'no position: the end' => [[], ['Build', 'Test', 'Cleanup', 'Lint']],
            'position null: the end' => [['position' => null], ['Build', 'Test', 'Cleanup', 'Lint']],
            'a position' => [['position' => 3], ['Build', 'Test', 'Lint', 'Cleanup']],
            'a position as digits' => [['position' => '03'], ['Build', 'Test', 'Lint', 'Cleanup']],
            'after a step' => [['after_step_id' => 'Build'], ['Build', 'Lint', 'Test', 'Cleanup']],
            'after a step, as digits' => [['after_step_id' => 'Build as digits'], ['Build', 'Lint', 'Test', 'Cleanup']],
        ];
    }

    /**
     * position or after_step_id places an added step; the answer gives its
     * position. In the cases, after_step_id "Build" stands for that step's
     * ID as a number, "Build as digits" for it as a string of digits.
     *
     * @dataProvider placementProvider
     * @param array<string, mixed> $placement
     * @param list<string> $order
     */
    public function testCreatePlacesTheStep(array $placement, array $order): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);
        if (isset($placement['after_step_id'])) {
            $build = $ids['Build'];
            $placement['after_step_id'] = $placement['after_step_id'] === 'Build' ? $build : (string)$build;
        }

        $answer = $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Lint', 'step_type' => 'pause'] + $placement);

        $data = $this->dataOf($answer, 201);
        $this->assertSame($order, $this->runOrder((int)$workflow->id));
        $position = (int)array_search('Lint', $order, true) + 1;
        $this->assertSame([$position, $position === 1], [$data['position'], $data['is_start']]);
        $warnings = $this->changeOf($answer, 201)['warnings'];
        $this->assertSame($position === 1 ? [sprintf(WorkflowStepChangeSummary::START_CHANGED, 'Lint', 'Build')] : [], $warnings);
    }

    /**
     * A pause step with its On Success branch (a loop back to the start) and
     * a variable mapping, as an object or as JSON text; null for the routes
     * a pause step never takes is accepted.
     */
    public function testCreateSetsTheBranchesAndTheVariableMapping(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $wait = $this->dataOf($this->api('POST', $this->stepsPath((int)$workflow->id), [
            'name' => 'Wait',
            'step_type' => 'pause',
            'on_success_step_id' => $ids['Build'],
            'on_failure_step_id' => null,
            'on_always_step_id' => null,
            'extra_vars_template' => ['env' => 'target_env'],
        ]), 201);
        $mapped = $this->dataOf($this->api('POST', $this->stepsPath((int)$workflow->id), [
            'name' => 'Mapped',
            'step_type' => 'pause',
            'extra_vars_template' => '{"host": "target_host"}',
        ]), 201);

        $this->assertSame(['env' => 'target_env'], $wait['extra_vars_template']);
        $this->assertSame(['host' => 'target_host'], $mapped['extra_vars_template']);
        $this->assertSame([$ids['Build'], null, null], [$wait['on_success_step_id'], $wait['on_failure_step_id'], $wait['on_always_step_id']]);
        $this->assertSame(
            [
                'success' => ['kind' => 'step', 'via' => 'on_success', 'step_id' => $ids['Build'], 'position' => 1, 'run_outcome' => null, 'loop' => true],
                'failure' => null,
                'closed_gate' => null,
            ],
            $wait['routes']
        );
        $this->assertSame([null, null], [$wait['job_template_id'], $wait['approval_rule_id']]);
    }

    // -- PUT ------------------------------------------------------------------

    /**
     * A PUT keeps what its body leaves out; null clears a route (the run
     * goes on with the next step), 0 ends the workflow.
     */
    public function testUpdateKeepsWhatTheBodyLeavesOutAndNullClearsARoute(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $answer = $this->api('PUT', $this->stepPath((int)$workflow->id, $ids['Build']), ['on_failure_step_id' => null, 'on_success_step_id' => 0]);

        $data = $this->dataOf($answer);
        $this->assertSame(['Build', (int)$this->s['own']->id, 0, null], [$data['name'], $data['job_template_id'], $data['on_success_step_id'], $data['on_failure_step_id']]);
        $this->assertSame(
            [
                'success' => ['kind' => 'end', 'via' => 'on_success', 'step_id' => null, 'position' => null, 'run_outcome' => 'succeeds', 'loop' => false],
                'failure' => ['kind' => 'step', 'via' => 'next', 'step_id' => $ids['Test'], 'position' => 2, 'run_outcome' => null, 'loop' => false],
                'closed_gate' => null,
            ],
            $data['routes']
        );
        $this->assertSame(sprintf(WorkflowStepChangeSummary::SAVED, 'Build', 'On Success, On Failure'), $this->changeOf($answer)['message']);
    }

    /**
     * null clears the variable mapping too; the audit entry says only that
     * it changed, never its values.
     */
    public function testUpdateClearsTheVariableMapping(): void
    {
        [$workflow, $ids] = $this->pipeline();
        WorkflowStep::updateAll(['extra_vars_template' => '{"env": "target_env"}'], ['id' => $ids['Test']]);
        $this->tokenOf($this->s['member']);

        $answer = $this->api('PUT', $this->stepPath((int)$workflow->id, $ids['Test']), ['extra_vars_template' => null]);

        $this->assertSame([], $this->dataOf($answer)['extra_vars_template']);
        $this->assertSame(sprintf(WorkflowStepChangeSummary::SAVED, 'Test', 'variable mapping'), $this->changeOf($answer)['message']);
        $this->assertSame(['extra_vars_template' => ['changed' => true]], $this->stepAudits((int)$workflow->id)[0]['context']['changes'] ?? null);
    }

    /**
     * position in a PUT body moves the step too, in the same change; a
     * change of the position alone reads as a move.
     */
    public function testUpdateMovesTheStepWhenTheBodyNamesAPosition(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $answer = $this->api('PUT', $this->stepPath((int)$workflow->id, $ids['Cleanup']), ['position' => 1]);

        $data = $this->dataOf($answer);
        $this->assertSame([$ids['Cleanup'], 1, true], [$data['id'], $data['position'], $data['is_start']]);
        $this->assertSame(['Cleanup', 'Build', 'Test'], $this->runOrder((int)$workflow->id));
        $this->assertSame(
            [
                'message' => sprintf(WorkflowStepChangeSummary::MOVED, 'Cleanup', 1, 3, WorkflowStepChangeSummary::AT_START)
                    . ' ' . sprintf(WorkflowStepChangeSummary::NOW_ENDS, 'Test', '', 'Cleanup'),
                'warnings' => [sprintf(WorkflowStepChangeSummary::START_CHANGED, 'Cleanup', 'Build')],
            ],
            $this->changeOf($answer)
        );
        $audits = $this->stepAudits((int)$workflow->id);
        $this->assertSame([AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED], array_column($audits, 'action'));
        $this->assertSame(['position' => ['from' => 3, 'to' => 1]], $audits[0]['context']['changes'] ?? null);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unchangingBodyProvider(): array
    {
        return [
            'an empty body' => [[]],
            'the same values' => [['name' => 'Test', 'on_success_step_id' => null, 'step_type' => 'job']],
            'its own position' => [['position' => 2]],
            'position null' => [['position' => null]],
            'unknown and ignored keys' => [['id' => 99, 'step_order' => 5, 'colour' => 'red']],
            'a body that is no object' => ['Test'],
        ];
    }

    /**
     * A PUT that changes nothing answers 200 with UNCHANGED, writes nothing
     * and is not audited.
     *
     * @dataProvider unchangingBodyProvider
     */
    public function testAnUpdateThatChangesNothingAnswersUnchanged(mixed $body): void
    {
        [$workflow, $ids] = $this->pipeline();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);

        $answer = $this->api('PUT', $this->stepPath((int)$workflow->id, $ids['Test']), $body);

        $this->assertSame(['id' => $ids['Test'], 'position' => 2], array_intersect_key($this->dataOf($answer), ['id' => 0, 'position' => 0]));
        $this->assertSame(['message' => sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Test'), 'warnings' => []], $this->changeOf($answer));
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    // -- move -----------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>, 2: string|null}>
     */
    public static function moveProvider(): array
    {
        return [
            'to the start' => [['position' => 'start'], ['Test', 'Build', 'Cleanup'], null],
            'to the end' => [['position' => 'end'], ['Build', 'Cleanup', 'Test'], null],
            'to a position' => [['position' => 3], ['Build', 'Cleanup', 'Test'], null],
            'after a step' => [['after_step_id' => 'Cleanup'], ['Build', 'Cleanup', 'Test'], null],
            'up' => [['direction' => 'up'], ['Test', 'Build', 'Cleanup'], 'up'],
            'down' => [['direction' => 'down'], ['Build', 'Cleanup', 'Test'], 'down'],
        ];
    }

    /**
     * @dataProvider moveProvider
     * @param array<string, mixed> $body
     * @param list<string> $order
     */
    public function testMovePlacesTheStep(array $body, array $order, ?string $direction): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);
        if (isset($body['after_step_id'])) {
            $body['after_step_id'] = $ids['Cleanup'];
        }

        $answer = $this->api('POST', $this->movePath((int)$workflow->id, $ids['Test']), $body);

        $position = (int)array_search('Test', $order, true) + 1;
        $data = $this->dataOf($answer);
        $this->assertSame([$ids['Test'], $position], [$data['id'], $data['position']]);
        $this->assertSame($order, $this->runOrder((int)$workflow->id));
        $where = $position === 1 ? WorkflowStepChangeSummary::AT_START : sprintf(WorkflowStepChangeSummary::AFTER, $order[$position - 2]);
        $this->assertStringStartsWith(sprintf(WorkflowStepChangeSummary::MOVED, 'Test', $position, 3, $where), (string)$this->changeOf($answer)['message']);
        $audits = $this->stepAudits((int)$workflow->id);
        $this->assertSame([AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED], array_column($audits, 'action'));
        $this->assertSame(
            ['source' => 'api', 'direction' => $direction],
            ['source' => $audits[0]['context']['source'] ?? null, 'direction' => $audits[0]['context']['direction'] ?? null]
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: int}>
     */
    public static function stayingMoveProvider(): array
    {
        return [
            'its own position' => ['Test', ['position' => 2], 2],
            'after the step before it' => ['Test', ['after_step_id' => 'Build'], 2],
            'the first step up' => ['Build', ['direction' => 'up'], 1],
            'the first step to the start' => ['Build', ['position' => 'start'], 1],
            'the last step down' => ['Cleanup', ['direction' => 'down'], 3],
            'the last step to the end' => ['Cleanup', ['position' => 'end'], 3],
        ];
    }

    /**
     * A move to where the step stands answers 200 with STAYS, writes
     * nothing and is not audited (the lead's STAYS decision).
     *
     * @dataProvider stayingMoveProvider
     * @param array<string, mixed> $body
     */
    public function testAMoveToWhereTheStepStandsAnswersStays(string $name, array $body, int $position): void
    {
        [$workflow, $ids] = $this->pipeline();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        if (isset($body['after_step_id'])) {
            $body['after_step_id'] = $ids['Build'];
        }

        $answer = $this->api('POST', $this->movePath((int)$workflow->id, $ids[$name]), $body);

        $this->assertSame($position, $this->dataOf($answer)['position']);
        $this->assertSame(['message' => sprintf(WorkflowStepChangeSummary::STAYS, $name, $position), 'warnings' => []], $this->changeOf($answer));
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    // -- DELETE ---------------------------------------------------------------

    public function testDeleteRemovesTheStepAndSaysWhatChanged(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $answer = $this->api('DELETE', $this->stepPath((int)$workflow->id, $ids['Test']));

        $this->assertSame(['deleted' => true, 'id' => $ids['Test']], $this->dataOf($answer));
        $this->assertSame(
            [
                'message' => sprintf(WorkflowStepChangeSummary::REMOVED, 'Test', 2, 3)
                    . ' ' . sprintf(WorkflowStepChangeSummary::NOW_CONTINUES, 'Build', 'Cleanup', '', sprintf(WorkflowStepChangeSummary::WAS_STEP, 'Test')),
                'warnings' => [],
            ],
            $this->changeOf($answer)
        );
        $this->assertNull(WorkflowStep::findOne($ids['Test']));
        $this->assertSame(['Build', 'Cleanup'], $this->runOrder((int)$workflow->id));
        $audits = $this->stepAudits((int)$workflow->id);
        $this->assertSame([AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED], array_column($audits, 'action'));
        $this->assertSame(['position' => 2, 'source' => 'api'], array_intersect_key($audits[0]['context'], ['position' => 0, 'source' => 0]));
    }

    /**
     * The answer of a DELETE carries the warnings too: runs now start with
     * another step.
     */
    public function testDeletingTheStartStepWarnsWhichStepRunsFirstNow(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $answer = $this->api('DELETE', $this->stepPath((int)$workflow->id, $ids['Build']));

        $this->assertSame(
            ['message' => sprintf(WorkflowStepChangeSummary::REMOVED, 'Build', 1, 3), 'warnings' => [sprintf(WorkflowStepChangeSummary::START_CHANGED, 'Test', 'Build')]],
            $this->changeOf($answer)
        );
    }

    // -- 401, 403, 404, 405 ---------------------------------------------------

    /**
     * Each endpoint, by its action (request() builds its request).
     *
     * @return array<string, array{0: string}>
     */
    public static function endpointProvider(): array
    {
        return ['POST' => ['create'], 'PUT' => ['update'], 'move' => ['move'], 'DELETE' => ['delete']];
    }

    /**
     * 401 without an API token, and with one that is not valid; nothing is
     * written.
     *
     * @dataProvider endpointProvider
     */
    public function testWithoutAValidTokenTheAnswerIs401(string $endpoint): void
    {
        [$workflow, $ids] = $this->pipeline();
        $before = $this->stepRows((int)$workflow->id);

        $missing = $this->apiFails(...$this->request($endpoint, (int)$workflow->id, $ids['Test']));
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . str_repeat('0', 64));
        $invalid = $this->apiFails(...$this->request($endpoint, (int)$workflow->id, $ids['Test']));

        $this->assertInstanceOf(UnauthorizedHttpException::class, $missing);
        $this->assertSame([401, 'Bearer token required.'], [$missing->statusCode, $missing->getMessage()]);
        $this->assertInstanceOf(UnauthorizedHttpException::class, $invalid);
        $this->assertSame([401, 'Invalid or expired token.'], [$invalid->statusCode, $invalid->getMessage()]);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
    }

    /**
     * 403 for a user without workflow-template.update, such as a viewer,
     * before anything else; nothing is written.
     *
     * @dataProvider endpointProvider
     */
    public function testWithoutThePermissionTheAnswerIs403(string $endpoint): void
    {
        [$workflow, $ids] = $this->pipeline();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->createUserWithRole('steps-viewer', 'viewer'));

        $answer = $this->api(...$this->request($endpoint, (int)$workflow->id, $ids['Test']));

        $this->assertApiError(403, WorkflowStepsController::FORBIDDEN, $answer);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * 404 for a workflow template that does not exist or was deleted, as
     * the framework's error; it comes before the team scope.
     *
     * @dataProvider endpointProvider
     */
    public function testAnUnknownOrDeletedWorkflowIs404(string $endpoint): void
    {
        [$workflow, $ids] = $this->pipeline();
        $this->tokenOf($this->s['outsider']);

        $unknown = $this->apiFails(...$this->request($endpoint, 987654321, $ids['Test']));
        $workflow->softDelete();
        $deleted = $this->apiFails(...$this->request($endpoint, (int)$workflow->id, $ids['Test']));

        $this->assertInstanceOf(NotFoundHttpException::class, $unknown);
        $this->assertSame(sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, 987654321), $unknown->getMessage());
        $this->assertInstanceOf(NotFoundHttpException::class, $deleted);
        $this->assertSame(sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, (int)$workflow->id), $deleted->getMessage());
        $this->assertNotNull(WorkflowStep::findOne($ids['Test']));
    }

    /**
     * 403 without operator access to the project of every job step (team
     * scope), whatever the step and the body: before the step's 404 and
     * the body's 422. Nothing is written.
     *
     * @dataProvider endpointProvider
     */
    public function testWithoutOperatorAccessToEveryJobStepTheAnswerIs403(string $endpoint): void
    {
        foreach (['viewed', 'foreign'] as $key) {
            [$workflow, $ids] = $this->pipeline();
            $this->namedStep((int)$workflow->id, 'Report', 40, WorkflowStep::TYPE_JOB, (int)$this->s[$key]->id);
            $before = $this->stepRows((int)$workflow->id);
            $this->tokenOf($this->s['member']);

            $valid = $this->api(...$this->request($endpoint, (int)$workflow->id, $ids['Test']));
            $unknownStep = $this->api(...$this->request($endpoint, (int)$workflow->id, 987654321, ['position' => 'nowhere']));

            $this->assertApiError(403, WorkflowStepsController::FORBIDDEN, $valid);
            $this->assertApiError(403, WorkflowStepsController::FORBIDDEN, $unknownStep);
            $this->assertSame($before, $this->stepRows((int)$workflow->id), $key);
            $this->assertSame([], $this->stepAudits((int)$workflow->id));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function stepEndpointProvider(): array
    {
        return ['PUT' => ['update'], 'move' => ['move'], 'DELETE' => ['delete']];
    }

    /**
     * 404 with STEP_NOT_FOUND for a step that does not exist or belongs to
     * another workflow, before the body's 422.
     *
     * @dataProvider stepEndpointProvider
     */
    public function testAStepThatIsNotOneOfTheWorkflowsIs404(string $endpoint): void
    {
        [$workflow] = $this->pipeline();
        [, $otherIds] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $unknown = $this->api(...$this->request($endpoint, (int)$workflow->id, 987654321, ['position' => 'nowhere']));
        $foreign = $this->api(...$this->request($endpoint, (int)$workflow->id, $otherIds['Test']));

        $this->assertApiError(404, sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$workflow->id, 987654321), $unknown);
        $this->assertApiError(404, sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$workflow->id, $otherIds['Test']), $foreign);
        $this->assertNotNull(WorkflowStep::findOne($otherIds['Test']));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function otherVerbProvider(): array
    {
        return [
            'GET create' => ['GET', 'create'],
            'PUT create' => ['PUT', 'create'],
            'GET update' => ['GET', 'update'],
            'POST update' => ['POST', 'update'],
            'GET move' => ['GET', 'move'],
            'DELETE move' => ['DELETE', 'move'],
            'GET delete' => ['GET', 'delete'],
            'POST delete' => ['POST', 'delete'],
        ];
    }

    /**
     * Yii also resolves the undocumented default route
     * /api/v1/workflow-steps/<action>?id=N&stepId=M for every verb. Any verb
     * but the action's own answers 405 in the API's error shape there,
     * before authentication: a GET there changes nothing, and the token is
     * not even marked as used.
     *
     * @dataProvider otherVerbProvider
     */
    public function testAnyOtherVerbOnTheDefaultRouteIs405AndChangesNothing(string $verb, string $action): void
    {
        [$workflow, $ids] = $this->pipeline();
        $before = $this->stepRows((int)$workflow->id);
        $token = $this->tokenOf($this->s['member']);
        $_SERVER['REQUEST_METHOD'] = $verb;
        \Yii::$app->request->setBodyParams(['name' => 'Renamed', 'step_type' => 'pause', 'position' => 'start']);

        $result = (new WorkflowStepsController('api/v1/workflow-steps', \Yii::$app))
            ->runAction($action, ['id' => (string)$workflow->id, 'stepId' => (string)$ids['Test']]);

        $allowed = ['create' => 'POST', 'update' => 'PUT', 'move' => 'POST', 'delete' => 'DELETE'][$action];
        $this->assertNull($result, 'the action did not run');
        $this->assertSame(405, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => "Method Not Allowed. This URL can only handle the following request methods: {$allowed}."]],
            \Yii::$app->response->data
        );
        $this->assertSame($allowed, \Yii::$app->response->headers->get('Allow'));
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $token->refresh();
        $this->assertNull($token->last_used_at, 'answered before authentication');
    }

    /**
     * The verb is checked first: without an API token, another verb on the
     * default route gets the 405 as well, not a 401.
     */
    public function testTheVerbIsCheckedBeforeAuthentication(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $result = (new WorkflowStepsController('api/v1/workflow-steps', \Yii::$app))
            ->runAction('delete', ['id' => (string)$workflow->id, 'stepId' => (string)$ids['Test']]);

        $this->assertNull($result);
        $this->assertSame(405, \Yii::$app->response->statusCode);
        $this->assertNotNull(WorkflowStep::findOne($ids['Test']));
    }

    /**
     * Each documented request resolves through the URL rules to its action,
     * and only for its verb.
     */
    public function testTheUrlRulesRouteEachDocumentedRequestToItsAction(): void
    {
        $rules = $this->webRules();
        $request = new \yii\web\Request();
        $cases = [
            ['POST', 'api/v1/workflow-templates/5/steps', 'api/v1/workflow-steps/create', ['id' => '5']],
            ['PUT', 'api/v1/workflow-templates/5/steps/7', 'api/v1/workflow-steps/update', ['id' => '5', 'stepId' => '7']],
            ['DELETE', 'api/v1/workflow-templates/5/steps/7', 'api/v1/workflow-steps/delete', ['id' => '5', 'stepId' => '7']],
            ['POST', 'api/v1/workflow-templates/5/steps/7/move', 'api/v1/workflow-steps/move', ['id' => '5', 'stepId' => '7']],
        ];
        foreach ($cases as [$verb, $path, $route, $params]) {
            $_SERVER['REQUEST_METHOD'] = $verb;
            $request->setPathInfo($path);
            $this->assertSame([$route, $params], $rules->parseRequest($request), "{$verb} /{$path}");
        }
        foreach ([['GET', 'api/v1/workflow-templates/5/steps'], ['PATCH', 'api/v1/workflow-templates/5/steps/7'], ['GET', 'api/v1/workflow-templates/5/steps/7/move']] as [$verb, $path]) {
            $_SERVER['REQUEST_METHOD'] = $verb;
            $request->setPathInfo($path);
            $parsed = $rules->parseRequest($request);
            $this->assertIsArray($parsed);
            $this->assertStringStartsNotWith('api/v1/workflow-steps/', (string)$parsed[0], "{$verb} /{$path} reaches no action");
        }
    }

    // -- 422 for the body -------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function unreadableBodyProvider(): array
    {
        $route = static fn (string $field): string => sprintf(WorkflowStepCommand::ROUTE_INVALID, $field);

        return [
            'POST: position and after_step_id' => ['create', ['position' => 'end', 'after_step_id' => 3], WorkflowStepPosition::BOTH_GIVEN],
            'POST: position text' => ['create', ['position' => 'top'], WorkflowStepPosition::POSITION_INVALID],
            'POST: position 0' => ['create', ['position' => 0], WorkflowStepPosition::POSITION_INVALID],
            'POST: position true' => ['create', ['position' => true], WorkflowStepPosition::POSITION_INVALID],
            'POST: position a float' => ['create', ['position' => 1.5], WorkflowStepPosition::POSITION_INVALID],
            'POST: position with a sign' => ['create', ['position' => '+2'], WorkflowStepPosition::POSITION_INVALID],
            'POST: after_step_id text' => ['create', ['after_step_id' => 'Build'], WorkflowStepPosition::AFTER_INVALID],
            'POST: after_step_id 0' => ['create', ['after_step_id' => 0], WorkflowStepPosition::AFTER_INVALID],
            'POST: a negative route' => ['create', ['on_success_step_id' => -1], $route('on_success_step_id')],
            'POST: a route true' => ['create', ['on_failure_step_id' => true], $route('on_failure_step_id')],
            'POST: a route as a float' => ['create', ['on_always_step_id' => 1.0], $route('on_always_step_id')],
            'POST: an empty route' => ['create', ['on_success_step_id' => ''], $route('on_success_step_id')],
            'POST: a pause step with On Failure' => ['create', ['step_type' => 'pause', 'on_failure_step_id' => 0], WorkflowStepCommand::PAUSE_BRANCHES_REFUSED],
            'POST: a pause step with Always' => ['create', ['step_type' => 'pause', 'on_always_step_id' => 4], WorkflowStepCommand::PAUSE_BRANCHES_REFUSED],
            'PUT: position and after_step_id' => ['update', ['position' => 1, 'after_step_id' => 3], WorkflowStepPosition::BOTH_GIVEN],
            'PUT: position text' => ['update', ['position' => 'first'], WorkflowStepPosition::POSITION_INVALID],
            'PUT: a route as text' => ['update', ['on_success_step_id' => 'next'], $route('on_success_step_id')],
            'PUT: another step type' => ['update', ['step_type' => 'pause'], WorkflowStepCommand::TYPE_LOCKED],
            'PUT: the type as true' => ['update', ['step_type' => true], WorkflowStepCommand::TYPE_LOCKED],
            'move: nothing' => ['move', [], WorkflowStepPosition::MOVE_NEEDS_ONE],
            'move: two places' => ['move', ['position' => 1, 'direction' => 'up'], WorkflowStepPosition::MOVE_NEEDS_ONE],
            'move: all three' => ['move', ['position' => 1, 'after_step_id' => 3, 'direction' => 'up'], WorkflowStepPosition::MOVE_NEEDS_ONE],
            'move: a direction' => ['move', ['direction' => 'left'], WorkflowStepPosition::DIRECTION_INVALID],
            'move: position text' => ['move', ['position' => 'last'], WorkflowStepPosition::POSITION_INVALID],
            'move: after_step_id 0' => ['move', ['after_step_id' => '0'], WorkflowStepPosition::AFTER_INVALID],
            'move: a body that is no object' => ['move', 'up', WorkflowStepPosition::MOVE_NEEDS_ONE],
        ];
    }

    /**
     * A body that cannot be read answers 422 with the reader's text
     * (WorkflowStepCommand, WorkflowStepPosition); nothing is written.
     *
     * @dataProvider unreadableBodyProvider
     */
    public function testABodyThatCannotBeReadIs422(string $endpoint, mixed $body, string $message): void
    {
        [$workflow, $ids] = $this->pipeline();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $valid = ['name' => 'Lint', 'job_template_id' => (int)$this->s['own']->id];

        $answer = $this->api(...$this->request($endpoint, (int)$workflow->id, $ids['Test'], is_array($body) ? $body + $valid : $body));

        $this->assertApiError(422, $message, $answer);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * A pause step only takes On Success: a PUT that sends it another route
     * that is not null is refused (422), one that sends them as null is not.
     */
    public function testAPauseStepTakesNoOtherRouteThanOnSuccess(): void
    {
        [$workflow, $ids] = $this->pipeline();
        $wait = $this->namedStep((int)$workflow->id, 'Wait', 40, WorkflowStep::TYPE_PAUSE);
        $this->tokenOf($this->s['member']);
        $path = $this->stepPath((int)$workflow->id, (int)$wait->id);

        $refused = $this->api('PUT', $path, ['on_failure_step_id' => $ids['Build']]);
        $allowed = $this->api('PUT', $path, ['on_success_step_id' => $ids['Build'], 'on_failure_step_id' => null, 'on_always_step_id' => null]);

        $this->assertApiError(422, WorkflowStepCommand::PAUSE_BRANCHES_REFUSED, $refused);
        $this->assertSame([$ids['Build'], null, null], array_values(array_intersect_key(
            $this->dataOf($allowed),
            ['on_success_step_id' => 0, 'on_failure_step_id' => 0, 'on_always_step_id' => 0]
        )));
    }

    /**
     * A variable mapping that cannot be stored: a number too large for JSON
     * arrives as infinity, as an object (422 from the body) or as text (422
     * from the model).
     */
    public function testAVariableMappingThatCannotBeStoredIs422(): void
    {
        [$workflow] = $this->pipeline();
        $this->tokenOf($this->s['member']);

        $object = $this->apiRaw('POST', $this->stepsPath((int)$workflow->id), '{"name": "Wait", "step_type": "pause", "extra_vars_template": {"x": 1e999}}');
        $text = $this->api('POST', $this->stepsPath((int)$workflow->id), ['name' => 'Wait', 'step_type' => 'pause', 'extra_vars_template' => '{nope']);

        $this->assertApiError(422, SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN), $object);
        $this->assertApiError(422, 'Extra_vars_template must be valid JSON.', $text);
        $this->assertFalse(WorkflowStep::find()->where(['workflow_template_id' => $workflow->id, 'name' => 'Wait'])->exists());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidStepProvider(): array
    {
        return [
            'no name' => [['job_template_id' => 'OWN'], 'Name cannot be blank.'],
            'an empty name' => [['name' => '', 'job_template_id' => 'OWN'], 'Name cannot be blank.'],
            'a name that is no text' => [['name' => ['x'], 'job_template_id' => 'OWN'], 'Name cannot be blank.'],
            'a name too long' => [['name' => str_repeat('x', 129), 'job_template_id' => 'OWN'], 'Name should contain at most 128 characters.'],
            'an unknown step type' => [['name' => 'Lint', 'step_type' => 'shell'], 'Step Type is invalid.'],
            'a job step without job template' => [['name' => 'Lint'], WorkflowStep::JOB_TEMPLATE_REQUIRED],
            'a job template that is no ID' => [['name' => 'Lint', 'job_template_id' => true], WorkflowStep::JOB_TEMPLATE_REQUIRED],
            'a route to a step of another workflow' => [['name' => 'Lint', 'step_type' => 'pause', 'on_success_step_id' => 'OTHER'], WorkflowStep::ROUTE_TARGET_OUTSIDE],
            'a route to a step that does not exist' => [['name' => 'Lint', 'job_template_id' => 'OWN', 'on_failure_step_id' => 987654321], WorkflowStep::ROUTE_TARGET_OUTSIDE],
        ];
    }

    /**
     * A step that breaks the model's rules answers 422 with the first
     * error; nothing is written.
     *
     * @dataProvider invalidStepProvider
     * @param array<string, mixed> $body
     */
    public function testAStepThatBreaksTheModelsRulesIs422(array $body, string $message): void
    {
        [$workflow] = $this->pipeline();
        [, $otherIds] = $this->pipeline();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $own = (int)$this->s['own']->id;
        $body = array_map(static fn (mixed $value): mixed => match ($value) {
            'OWN' => $own,
            'OTHER' => $otherIds['Test'],
            default => $value,
        }, $body);

        $answer = $this->api('POST', $this->stepsPath((int)$workflow->id), $body);

        $this->assertApiError(422, $message, $answer);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A workflow of three job steps on the member's own job template, in
     * run order: Build (On Failure: Cleanup), Test, Cleanup (both branches
     * end the workflow).
     *
     * @return array{0: WorkflowTemplate, 1: array{Build: int, Test: int, Cleanup: int}}
     */
    private function pipeline(): array
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $own = (int)$this->s['own']->id;
        $build = $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, $own);
        $test = $this->namedStep((int)$workflow->id, 'Test', 20, WorkflowStep::TYPE_JOB, $own);
        $cleanup = $this->namedStep((int)$workflow->id, 'Cleanup', 30, WorkflowStep::TYPE_JOB, $own);
        $cleanup->on_success_step_id = WorkflowStep::END_WORKFLOW;
        $cleanup->on_failure_step_id = WorkflowStep::END_WORKFLOW;
        $cleanup->save(false);
        $build->on_failure_step_id = (int)$cleanup->id;
        $build->save(false);

        return [$workflow, ['Build' => (int)$build->id, 'Test' => (int)$test->id, 'Cleanup' => (int)$cleanup->id]];
    }

    /**
     * The request of $endpoint on the step $stepId of workflow $workflowId,
     * with $body or a valid one, as api() takes it.
     *
     * @return array{0: string, 1: string, 2: mixed}
     */
    private function request(string $endpoint, int $workflowId, int $stepId, mixed $body = null): array
    {
        return match ($endpoint) {
            'create' => ['POST', $this->stepsPath($workflowId), $body ?? ['name' => 'Lint', 'step_type' => 'pause']],
            'update' => ['PUT', $this->stepPath($workflowId, $stepId), $body ?? ['name' => 'Renamed']],
            'move' => ['POST', $this->movePath($workflowId, $stepId), $body ?? ['position' => 'start']],
            default => ['DELETE', $this->stepPath($workflowId, $stepId), $body ?? []],
        };
    }

    /**
     * GET /api/v1/workflow-templates/{id} as the member.
     *
     * @return array{steps: list<array<string, mixed>>, success_path: array{step_ids: list<int>}}
     */
    private function detailOf(WorkflowTemplate $workflow): array
    {
        \Yii::$app->user->setIdentity($this->s['member']);
        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app))->actionView((int)$workflow->id);
        $this->assertArrayHasKey('data', $result);
        /** @var array{steps: list<array<string, mixed>>, success_path: array{step_ids: list<int>}} $data */
        $data = $result['data'];

        return $data;
    }
}
