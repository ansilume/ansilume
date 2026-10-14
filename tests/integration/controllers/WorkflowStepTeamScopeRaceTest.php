<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\WorkflowStepPosition;
use app\controllers\BaseController;
use app\controllers\WorkflowTemplateController;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\tests\integration\services\WorkflowStepWriteFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The team scope of the template page's step changes, checked again under
 * the lock. Every web path that changes a workflow's steps checks operator
 * access to the project of every job step before it writes
 * (requireWorkflowOperate()), and the step write path checks it again with
 * the workflow locked (WorkflowStepChangeGuard). The race replayed here:
 * between the member's own check and the lock, another user adds a job step
 * of a project the member may only view. The change is refused (403) and
 * nothing of it is written. The page answers in the words of its own check
 * (BaseController::MODIFY_FORBIDDEN), not the API's "Forbidden."
 * (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED); every other 403 under the
 * lock keeps its own text.
 */
final class WorkflowStepTeamScopeRaceTest extends WebControllerTestCase
{
    use WorkflowStepWriteFixtures;

    /** The web paths that change steps: case => action ID. */
    private const PATHS = [
        'add-step' => 'add-step',
        'edit-step, a save' => 'edit-step',
        'move-step, ▲ ▼' => 'move-step',
        'move-step, Move to…' => 'move-step',
        'remove-step' => 'remove-step',
    ];

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
        $this->s = $this->teamScope();
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
     * regression: the team scope of a step change was checked only before
     * the lock. A job step of a project the member may only view, added by
     * another user between the member's check and the lock, did not stop the
     * member's change. Now the write path refuses it under the lock: 403,
     * nothing written, no audit entry, no flash.
     *
     * regression: in this race the page answered 403 "Forbidden.", the
     * API's text (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED), where its own
     * check before the lock refuses the same user with
     * BaseController::MODIFY_FORBIDDEN. It answers that text now, on every
     * web path.
     *
     * @dataProvider pathProvider
     */
    public function testRegressionAStepOfAViewOnlyProjectAddedMeanwhileStopsTheChange(string $path): void
    {
        $e = $this->refusedMeanwhile(
            $path,
            fn (WorkflowTemplate $workflow): WorkflowStep => $this->namedStep((int)$workflow->id, 'Report', 30, WorkflowStep::TYPE_JOB, (int)$this->s['viewed']->id)
        );

        $this->assertSame(BaseController::MODIFY_FORBIDDEN, $e->getMessage(), 'the page\'s own text, not the API\'s');
        $this->assertNotSame(WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, BaseController::MODIFY_FORBIDDEN);
        $refusal = $e->getPrevious();
        $this->assertInstanceOf(WorkflowStepChangeRefusedException::class, $refusal, 'refused by the write path under the lock');
        $this->assertSame(WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, $refusal->getMessage());
    }

    /**
     * Only the team scope's refusal takes the page's words: every other 403
     * under the lock keeps its own text. Here an admin adds an approval step
     * between the member's checks and the lock, which locks the steps for
     * the member (rule 12): the page says so in the policy's words, and
     * nothing of the member's change is written.
     *
     * @dataProvider pathProvider
     */
    public function testAWorkflowLockedMeanwhileKeepsThePolicysText(string $path): void
    {
        $e = $this->refusedMeanwhile(
            $path,
            fn (WorkflowTemplate $workflow): WorkflowStep => $this->namedStep(
                (int)$workflow->id,
                'Gate',
                30,
                WorkflowStep::TYPE_APPROVAL,
                null,
                (int)$this->createApprovalRule((int)$this->s['admin']->id)->id
            )
        );

        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $e->getMessage());
    }

    /**
     * Without a step added meanwhile, the same requests change the steps for
     * the member: the refusal above is the check under the lock.
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
     * The 403 that the member gets for $path when another user's $change
     * adds a step to the workflow between the member's checks and the lock.
     * Only that step is written: nothing of the member's change, no audit
     * entry, no flash.
     *
     * @param callable(WorkflowTemplate): WorkflowStep $change
     */
    private function refusedMeanwhile(string $path, callable $change): ForbiddenHttpException
    {
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->loginAs($this->s['member']);
        $added = [];
        $refusal = null;

        try {
            $this->meanwhile(
                function () use ($change, $workflow, &$added): void {
                    $added[] = $change($workflow);
                },
                fn () => $this->runPath($path, $workflow)
            );
        } catch (ForbiddenHttpException $e) {
            $refusal = $e;
        }

        $this->assertInstanceOf(ForbiddenHttpException::class, $refusal, 'Expected a ForbiddenHttpException.');
        $this->assertCount(1, $added, 'the other user\'s step was added between the checks and the lock');
        $after = $this->stepRows((int)$workflow->id);
        unset($after[(int)$added[0]->id]);
        $this->assertSame($before, $after, 'nothing of the member\'s change was written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
        $this->assertSame([], \Yii::$app->session->getAllFlashes());

        return $refusal;
    }

    /**
     * Build and Deploy, job steps of projects the member may operate: their
     * team's and one without a team.
     */
    private function workflow(): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['own']->id);
        $this->namedStep((int)$workflow->id, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->s['open']->id);

        return $workflow;
    }

    /**
     * Post the request of $path for the workflow's step Build, as its form
     * does, and run the action as the signed-in user.
     */
    private function runPath(string $path, WorkflowTemplate $workflow): mixed
    {
        $build = $this->stepIdOf($workflow, 'Build');
        $this->setPost(match ($path) {
            'add-step' => ['WorkflowStep' => ['name' => 'Lint', 'step_type' => WorkflowStep::TYPE_PAUSE]],
            'edit-step, a save' => ['WorkflowStep' => ['name' => 'Compile']],
            'move-step, ▲ ▼' => ['step_id' => (string)$build, 'direction' => WorkflowStepPosition::KIND_DOWN],
            'move-step, Move to…' => ['step_id' => (string)$build, 'position' => WorkflowStepPosition::KIND_END],
            default => ['step_id' => (string)$build],
        });
        $ctrl = $this->makeController();

        return match (self::PATHS[$path]) {
            'add-step' => $ctrl->actionAddStep((int)$workflow->id),
            'edit-step' => $ctrl->actionEditStep((int)$workflow->id, $build),
            'move-step' => $ctrl->actionMoveStep((int)$workflow->id),
            default => $ctrl->actionRemoveStep((int)$workflow->id),
        };
    }

    private function stepIdOf(WorkflowTemplate $workflow, string $name): int
    {
        $step = WorkflowStep::findOne(['workflow_template_id' => $workflow->id, 'name' => $name]);
        $this->assertNotNull($step, $name);

        return (int)$step->id;
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
