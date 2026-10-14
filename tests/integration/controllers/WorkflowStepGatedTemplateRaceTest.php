<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeRefusedException;
use app\tests\integration\services\WorkflowStepWriteFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Rule 12 on the template page, checked again under the lock: giving a job
 * step a gated job template (one with an approval rule) is for admins
 * (ApprovalRuleAssignmentPolicy::stepsAddRefusal()). The step write path
 * checks it before the lock and again with the workflow locked
 * (WorkflowStepWriteService). The race replayed here: between the member's
 * checks and the lock, an admin assigns an approval rule to the job
 * template that the member's Add Step or Edit Step gives a job step. The
 * change is refused (403, the policy's text) and nothing of it is written.
 */
final class WorkflowStepGatedTemplateRaceTest extends WebControllerTestCase
{
    use WorkflowStepWriteFixtures;

    /** The web paths that give a job step a job template: case => action ID. */
    private const PATHS = [
        'add-step' => 'add-step',
        'edit-step, a save' => 'edit-step',
    ];

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
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
     * regression: whether the job template that a change gives a job step
     * is gated was checked only before the lock. An approval rule that an
     * admin assigned to it between the member's checks and the lock did not
     * stop the change, so the member gave a job step a gated job template,
     * which is for admins. Now the write path checks it again under the
     * lock: 403 with the policy's text, nothing written, no audit entry, no
     * flash.
     *
     * @dataProvider pathProvider
     */
    public function testRegressionAJobTemplateGatedMeanwhileStopsTheChange(string $path): void
    {
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->loginAs($this->s['member']);
        $gated = 0;
        $refusal = null;

        try {
            $this->meanwhile(
                function () use (&$gated): void {
                    $gated += JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $this->s['open']->id]);
                },
                fn () => $this->runPath($path, $workflow)
            );
        } catch (ForbiddenHttpException $e) {
            $refusal = $e;
        }

        $this->assertSame(1, $gated, 'the admin gated the job template between the checks and the lock');
        $this->assertInstanceOf(ForbiddenHttpException::class, $refusal, 'Expected a ForbiddenHttpException.');
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, $refusal->getMessage());
        $previous = $refusal->getPrevious();
        $this->assertInstanceOf(WorkflowStepChangeRefusedException::class, $previous, 'refused by the write path under the lock');
        $this->assertSame(WorkflowStepChangeRefusedException::FORBIDDEN, $previous->status);
        $this->assertSame($before, $this->stepRows((int)$workflow->id), 'nothing of the member\'s change was written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
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
        $this->loginAs($this->s['admin']);

        $this->meanwhile(
            fn () => JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $this->s['open']->id]),
            fn () => $this->runPath($path, $workflow)
        );

        $this->assertArrayHasKey('success', \Yii::$app->session->getAllFlashes());
        $this->assertCount(1, $this->stepAudits((int)$workflow->id));
        $this->assertTrue(WorkflowStep::find()->where([
            'workflow_template_id' => $workflow->id,
            'job_template_id' => $this->s['open']->id,
        ])->exists(), 'a job step uses the gated job template');
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
        $this->loginAs($this->s['member']);

        $this->runPath($path, $workflow);

        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('success', $flashes, (string)json_encode($flashes));
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
     * Post the request of $path as its form does, giving a job step the job
     * template open, and run the action as the signed-in user: Add Step
     * adds the job step Lint, Edit Step changes Build's job template.
     */
    private function runPath(string $path, WorkflowTemplate $workflow): mixed
    {
        $open = (string)$this->s['open']->id;
        $build = WorkflowStep::findOne(['workflow_template_id' => $workflow->id, 'name' => 'Build']);
        $this->assertNotNull($build);
        $this->setPost(['WorkflowStep' => match ($path) {
            'add-step' => ['name' => 'Lint', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => $open],
            default => ['name' => 'Build', 'job_template_id' => $open],
        }]);
        $ctrl = $this->makeController();

        return self::PATHS[$path] === 'add-step'
            ? $ctrl->actionAddStep((int)$workflow->id)
            : $ctrl->actionEditStep((int)$workflow->id, (int)$build->id);
    }

    private function makeController(): WorkflowTemplateController
    {
        return new class ('workflow-template', \Yii::$app) extends WorkflowTemplateController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }
}
