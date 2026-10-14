<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\WorkflowStepMapping;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepChangeSummary;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\WorkflowStepWriteFixtures;

/**
 * The variable mapping (extra_vars_template) on the per-step endpoints,
 * POST /api/v1/workflow-templates/{id}/steps and PUT
 * /api/v1/workflow-templates/{id}/steps/{stepId}, sent through the URL rules
 * and the controller: a mapping is none (null, [], {} or the same as JSON
 * text, stored as null) or a JSON object of extra var names to the names of
 * output variables (WorkflowStepMapping); anything else answers 422. An
 * update compares the mapping by what it maps, so a step sent back as the
 * API answers it is unchanged.
 */
class WorkflowStepsControllerMappingTest extends WebControllerTestCase
{
    use WorkflowStepApiRequests;
    use WorkflowStepWriteFixtures;

    /** Deploy's mapping as an older client stored it: with spaces. */
    private const SPACED_MAPPING = '{"app_version":  "build_version",  "target" : "hosts"}';

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
    }

    // -- Regressions ----------------------------------------------------------

    /**
     * regression: a GET→PUT round trip of an unchanged step answered 'Step
     * "Build" saved: variable mapping changed.', wrote the column ("[]"
     * where there was none, the stored mapping without its spaces) and added
     * a workflow-template.step.updated entry, although nothing changed. Each
     * step sent back as the API answers it is unchanged now: nothing is
     * written and nothing is audited.
     */
    public function testRegressionAGetPutRoundTripOfUnchangedStepsChangesNothing(): void
    {
        [$workflow] = $this->workflow();
        $id = (int)$workflow->id;
        $before = $this->stepRows($id);
        $detail = $this->detailOf($workflow);
        $this->tokenOf($this->s['member']);

        $this->assertSame([[], ['app_version' => 'build_version', 'target' => 'hosts']], array_column($detail['steps'], 'extra_vars_template'));
        foreach ($detail['steps'] as $answered) {
            $answer = null;
            $writes = $this->stepWritesOf(function () use (&$answer, $id, $answered): void {
                $answer = $this->api('PUT', $this->stepPath($id, (int)$answered['id']), $answered);
            });

            $this->assertSame(
                ['message' => sprintf(WorkflowStepChangeSummary::UNCHANGED, (string)$answered['name']), 'warnings' => []],
                $this->changeOf($answer),
                (string)$answered['name']
            );
            $this->assertSame([], $writes, (string)$answered['name'] . ': nothing written');
        }
        $this->assertSame($before, $this->stepRows($id), 'Build keeps no mapping, Deploy its text with the spaces');
        $this->assertSame([], $this->stepAudits($id));
    }

    /**
     * regression: PUT {"extra_vars_template": {}} on a step without a
     * mapping answered "variable mapping changed" and stored "[]". Every
     * form of none is no change there now.
     *
     * @dataProvider noneProvider
     */
    public function testRegressionNoMappingInAnyFormIsNoChange(string $json): void
    {
        [$workflow, $ids] = $this->workflow();
        $id = (int)$workflow->id;
        $before = $this->stepRows($id);
        $this->tokenOf($this->s['member']);
        $answer = null;

        $writes = $this->stepWritesOf(function () use (&$answer, $id, $ids, $json): void {
            $answer = $this->apiRaw('PUT', $this->stepPath($id, $ids['Build']), '{"extra_vars_template": ' . $json . '}');
        });

        $this->assertSame(['message' => sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Build'), 'warnings' => []], $this->changeOf($answer));
        $this->assertSame([], $this->dataOf($answer)['extra_vars_template']);
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->stepRows($id));
        $this->assertSame([], $this->stepAudits($id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noneProvider(): array
    {
        return [
            'an empty object' => ['{}'],
            'an empty list, as the API answers none' => ['[]'],
            'null' => ['null'],
            'the text {}' => ['"{}"'],
            'the text []' => ['"[]"'],
            'the text null' => ['"null"'],
            'empty text' => ['""'],
        ];
    }

    /**
     * Shapes that are no mapping, as a JSON value of the body or as JSON
     * text.
     *
     * @return array<string, array{0: string}>
     */
    public static function noMappingProvider(): array
    {
        return [
            'a list of numbers as text' => ['"[1,2]"'],
            'a number as text' => ['"5"'],
            'a JSON string as text' => ['"\"text\""'],
            'an object as a value, as text' => ['"{\"a\":{\"b\":\"c\"}}"'],
            'a number as a value' => ['{"a": 1}'],
            'null as a value' => ['{"a": null}'],
            'a list of names' => ['["build_version"]'],
            'a list of names as text' => ['"[\"build_version\"]"'],
            'true' => ['true'],
            'a number' => ['5'],
            'a key PHP reads as a number' => ['"{\"0\": \"build_version\"}"'],
            'an empty key' => ['{"": "build_version"}'],
            'a good entry and a bad one' => ['{"app_version": "build_version", "count": 3}'],
        ];
    }

    /**
     * regression: the step endpoints stored any JSON as the variable
     * mapping, such as [1,2], 5, "text", {"a": {"b": "c"}} or {"a": 1}, and
     * answered it in a shape the schema does not have, or as [] although
     * something was stored; ["build_version"] made the extra vars of the
     * runs' jobs a JSON list, which Ansible refuses. POST answers 422 now,
     * and nothing is written.
     *
     * @dataProvider noMappingProvider
     */
    public function testRegressionAnAddWithAMappingOfAnotherShapeIs422(string $json): void
    {
        [$workflow] = $this->workflow();
        $id = (int)$workflow->id;
        $before = $this->stepRows($id);
        $this->tokenOf($this->s['member']);

        $answer = $this->apiRaw('POST', $this->stepsPath($id), '{"name": "Lint", "step_type": "pause", "extra_vars_template": ' . $json . '}');

        $this->assertSame(422, $answer['status'], (string)json_encode($answer['body']));
        $this->assertApiError(422, WorkflowStepMapping::INVALID, $answer);
        $this->assertSame($before, $this->stepRows($id));
        $this->assertSame([], $this->stepAudits($id));
    }

    /**
     * regression: as for an add, PUT stored any JSON as the variable
     * mapping. It answers 422 now, and the step keeps its mapping.
     *
     * @dataProvider noMappingProvider
     */
    public function testRegressionAnUpdateWithAMappingOfAnotherShapeIs422(string $json): void
    {
        [$workflow, $ids] = $this->workflow();
        $id = (int)$workflow->id;
        $before = $this->stepRows($id);
        $this->tokenOf($this->s['member']);

        $answer = $this->apiRaw('PUT', $this->stepPath($id, $ids['Deploy']), '{"name": "Ship", "extra_vars_template": ' . $json . '}');

        $this->assertSame(422, $answer['status'], (string)json_encode($answer['body']));
        $this->assertApiError(422, WorkflowStepMapping::INVALID, $answer);
        $this->assertSame($before, $this->stepRows($id));
        $this->assertSame([], $this->stepAudits($id));
    }

    // -- Mappings that are taken ------------------------------------------------

    /**
     * An add stores none as null and a mapping as sent: an object as its
     * JSON, JSON text as it is.
     */
    public function testAnAddStoresNoneAsNullAndAMappingAsSent(): void
    {
        [$workflow] = $this->workflow();
        $id = (int)$workflow->id;
        $this->tokenOf($this->s['member']);

        $none = $this->dataOf($this->apiRaw('POST', $this->stepsPath($id), '{"name": "Wait", "step_type": "pause", "extra_vars_template": {}}'), 201);
        $object = $this->dataOf($this->apiRaw('POST', $this->stepsPath($id), '{"name": "Notify", "step_type": "pause", "extra_vars_template": {"version": "build_version"}}'), 201);
        $text = $this->dataOf($this->apiRaw('POST', $this->stepsPath($id), '{"name": "Report", "step_type": "pause", "extra_vars_template": "{\"host\": \"target_host\"}"}'), 201);

        $this->assertSame([[], ['version' => 'build_version'], ['host' => 'target_host']], [
            $none['extra_vars_template'],
            $object['extra_vars_template'],
            $text['extra_vars_template'],
        ]);
        $this->assertSame([null, '{"version":"build_version"}', '{"host": "target_host"}'], [
            WorkflowStep::findOne((int)$none['id'])?->extra_vars_template,
            WorkflowStep::findOne((int)$object['id'])?->extra_vars_template,
            WorkflowStep::findOne((int)$text['id'])?->extra_vars_template,
        ]);
    }

    /**
     * A step sent back with only a new name changes only its name: the
     * mapping, the same in other words, keeps its stored text, and the audit
     * entry lists the name alone.
     */
    public function testAStepSentBackWithANewNameChangesOnlyTheName(): void
    {
        [$workflow, $ids] = $this->workflow();
        $id = (int)$workflow->id;
        $deploy = $this->detailOf($workflow)['steps'][1];
        $this->tokenOf($this->s['member']);

        $answer = $this->api('PUT', $this->stepPath($id, $ids['Deploy']), ['name' => 'Ship'] + $deploy);

        $this->assertSame(sprintf(WorkflowStepChangeSummary::SAVED, 'Ship', 'name'), $this->changeOf($answer)['message']);
        $stored = WorkflowStep::findOne($ids['Deploy']);
        $this->assertNotNull($stored);
        $this->assertSame(['Ship', self::SPACED_MAPPING], [$stored->name, $stored->extra_vars_template]);
        $audits = $this->stepAudits($id);
        $this->assertCount(1, $audits);
        $this->assertSame(['name' => ['from' => 'Deploy', 'to' => 'Ship']], $audits[0]['context']['changes'] ?? null);
    }

    /**
     * Another mapping is a change: stored as sent and audited without its
     * values.
     */
    public function testAnotherMappingIsAChange(): void
    {
        [$workflow, $ids] = $this->workflow();
        $id = (int)$workflow->id;
        $this->tokenOf($this->s['member']);

        $answer = $this->api('PUT', $this->stepPath($id, $ids['Deploy']), ['extra_vars_template' => ['app_version' => 'release_version']]);

        $this->assertSame(sprintf(WorkflowStepChangeSummary::SAVED, 'Deploy', 'variable mapping'), $this->changeOf($answer)['message']);
        $this->assertSame(['app_version' => 'release_version'], $this->dataOf($answer)['extra_vars_template']);
        $this->assertSame('{"app_version":"release_version"}', WorkflowStep::findOne($ids['Deploy'])?->extra_vars_template);
        $this->assertSame(['extra_vars_template' => ['changed' => true]], $this->stepAudits($id)[0]['context']['changes'] ?? null);
    }

    /**
     * A mapping of another shape that an older version stored stays while
     * an update leaves it alone: the step's other fields change, and the
     * mapping sent back as answered is no change. Another mapping of that
     * shape is refused, and null removes it.
     */
    public function testAMappingAnOlderVersionStoredStaysUntilItIsChanged(): void
    {
        [$workflow, $ids] = $this->workflow();
        $id = (int)$workflow->id;
        WorkflowStep::updateAll(['extra_vars_template' => '["build_version"]'], ['id' => $ids['Deploy']]);
        $this->tokenOf($this->s['member']);
        $path = $this->stepPath($id, $ids['Deploy']);

        $renamed = $this->api('PUT', $path, ['name' => 'Ship']);
        $sentBack = $this->api('PUT', $path, $this->detailOf($workflow)['steps'][1]);
        $another = $this->api('PUT', $path, ['extra_vars_template' => ['release_version']]);

        $this->assertSame(sprintf(WorkflowStepChangeSummary::SAVED, 'Ship', 'name'), $this->changeOf($renamed)['message']);
        $this->assertSame(['build_version'], $this->dataOf($renamed)['extra_vars_template']);
        $this->assertSame(sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Ship'), $this->changeOf($sentBack)['message']);
        $this->assertApiError(422, WorkflowStepMapping::INVALID, $another);
        $this->assertSame('["build_version"]', WorkflowStep::findOne($ids['Deploy'])?->extra_vars_template);

        $cleared = $this->api('PUT', $path, ['extra_vars_template' => null]);

        $this->assertSame(sprintf(WorkflowStepChangeSummary::SAVED, 'Ship', 'variable mapping'), $this->changeOf($cleared)['message']);
        $this->assertNull(WorkflowStep::findOne($ids['Deploy'])?->extra_vars_template);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Build, a job step without a mapping, then Deploy, one with
     * SPACED_MAPPING, both on the member's own job template.
     *
     * @return array{0: WorkflowTemplate, 1: array{Build: int, Deploy: int}}
     */
    private function workflow(): array
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $own = (int)$this->s['own']->id;
        $build = $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, $own);
        $deploy = $this->namedStep((int)$workflow->id, 'Deploy', 20, WorkflowStep::TYPE_JOB, $own);
        WorkflowStep::updateAll(['extra_vars_template' => self::SPACED_MAPPING], ['id' => $deploy->id]);

        return [$workflow, ['Build' => (int)$build->id, 'Deploy' => (int)$deploy->id]];
    }

    /**
     * GET /api/v1/workflow-templates/{id} as the member.
     *
     * @return array{steps: list<array<string, mixed>>}
     */
    private function detailOf(WorkflowTemplate $workflow): array
    {
        \Yii::$app->user->setIdentity($this->s['member']);
        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app))->actionView((int)$workflow->id);
        $this->assertArrayHasKey('data', $result);
        /** @var array{steps: list<array<string, mixed>>} $data */
        $data = $result['data'];

        return $data;
    }

    /**
     * The statements $action sends that write a workflow step or an audit
     * entry; the request's API token records its use, which is no change
     * of the step.
     *
     * @return list<string>
     */
    private function stepWritesOf(callable $action): array
    {
        return array_values(array_filter(
            $this->writesOf($action),
            static fn (string $sql): bool => preg_match('~\b(workflow_step|audit_log)\b~', $sql) === 1
        ));
    }
}
