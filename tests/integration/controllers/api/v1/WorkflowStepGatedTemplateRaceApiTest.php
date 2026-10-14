<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\WorkflowStepWriteFixtures;

/**
 * Rule 12 on the API's per-step endpoints, checked again under the lock:
 * giving a job step a gated job template (one with an approval rule) is for
 * admins (ApprovalRuleAssignmentPolicy::stepsAddRefusal()). The step write
 * path checks it before the lock and again with the workflow locked. The
 * race replayed here, for POST /steps and PUT /steps/{stepId}: between the
 * caller's checks and the lock, an admin assigns an approval rule to the job
 * template the request gives a job step. The change is refused with 403 and
 * the policy's text, and nothing of it is written.
 */
final class WorkflowStepGatedTemplateRaceApiTest extends WebControllerTestCase
{
    use WorkflowStepApiRequests;
    use WorkflowStepWriteFixtures;

    /** The API's requests that give a job step a job template: case => what the request does. */
    private const PATHS = [
        'POST /steps' => 'create',
        'PUT /steps/{stepId}' => 'update',
    ];

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
        $this->ruleId = (int)$this->createApprovalRule((int)$this->s['admin']->id)->id;
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
     * regression: whether the job template that a request gives a job step
     * is gated was checked only before the lock. An approval rule that an
     * admin assigned to it between the caller's checks and the lock did not
     * stop the change. Now the write path checks it again under the lock:
     * 403 with the policy's text, nothing written, no audit entry.
     *
     * @dataProvider pathProvider
     */
    public function testRegressionAJobTemplateGatedMeanwhileStopsTheChange(string $path): void
    {
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $gated = 0;

        $answer = $this->meanwhile(
            function () use (&$gated): void {
                $gated += JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $this->s['open']->id]);
            },
            fn (): array => $this->send($path, $workflow)
        );

        $this->assertSame(1, $gated, 'the admin gated the job template between the checks and the lock');
        $this->assertApiError(403, ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, $answer);
        $this->assertSame($before, $this->stepRows((int)$workflow->id), 'nothing of the caller\'s change was written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * An admin may give a job step a gated job template: the same race
     * changes the steps for an admin.
     *
     * @dataProvider pathProvider
     */
    public function testAnAdminChangesTheStepsInTheSameRace(string $path): void
    {
        $workflow = $this->workflow();
        $this->tokenOf($this->s['admin']);

        $answer = $this->meanwhile(
            fn () => JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $this->s['open']->id]),
            fn (): array => $this->send($path, $workflow)
        );

        $this->assertSame((int)$this->s['open']->id, $this->dataOf($answer, self::PATHS[$path] === 'create' ? 201 : 200)['job_template_id']);
        $this->assertCount(1, $this->stepAudits((int)$workflow->id));
    }

    /**
     * Without the race, the same requests change the steps for the member:
     * the refusal above is the check under the lock.
     *
     * @dataProvider pathProvider
     */
    public function testWithoutTheRaceTheMemberChangesTheSteps(string $path): void
    {
        $workflow = $this->workflow();
        $this->tokenOf($this->s['member']);

        $answer = $this->send($path, $workflow);

        $this->assertSame((int)$this->s['open']->id, $this->dataOf($answer, self::PATHS[$path] === 'create' ? 201 : 200)['job_template_id']);
        $this->assertCount(1, $this->stepAudits((int)$workflow->id));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Build, a job step of the member's own project. The job template that
     * the requests give a job step, open (a project without a team), is not
     * one of the workflow's yet, so its rule does not lock the workflow's
     * steps.
     */
    private function workflow(): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['own']->id);

        return $workflow;
    }

    /**
     * Send the request of $path, which gives a job step the job template
     * open: POST adds the job step Lint, PUT changes Build's job template.
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function send(string $path, WorkflowTemplate $workflow): array
    {
        $id = (int)$workflow->id;
        $open = (int)$this->s['open']->id;

        return self::PATHS[$path] === 'create'
            ? $this->api('POST', $this->stepsPath($id), ['name' => 'Lint', 'job_template_id' => $open])
            : $this->api('PUT', $this->stepPath($id, $this->stepIdOf($id, 'Build')), ['job_template_id' => $open]);
    }
}
