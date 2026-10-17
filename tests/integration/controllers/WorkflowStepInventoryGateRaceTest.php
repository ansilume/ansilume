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
use app\tests\integration\services\InventoryGatedStepFixtures;
use app\tests\integration\services\WorkflowStepWriteFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Rule 12 on the template page for job templates gated through their
 * inventory (contract C15): giving a job step a job template whose
 * inventory has an approval rule is for admins
 * (ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED), on
 * Add Step and on the save of Edit Step. The step write path checks it
 * before the lock and again with the workflow locked
 * (WorkflowStepWriteService). The races replayed here: between the member's
 * checks and the lock, an admin gives the job template's inventory a rule,
 * or moves the job template to an inventory with one. The change is refused
 * (403, the policy's text) and nothing of it is written.
 */
final class WorkflowStepInventoryGateRaceTest extends WebControllerTestCase
{
    use InventoryGatedStepFixtures;
    use WorkflowStepWriteFixtures;

    /** The web paths that give a job step a job template: case => action ID. */
    private const PATHS = [
        'add-step' => 'add-step',
        'edit-step, a save' => 'edit-step',
    ];

    /** What an admin changes meanwhile. */
    private const RACES = ['its inventory gets a rule', 'it moves to an inventory with a rule'];

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
        $this->s = $this->teamScope();
        $this->ruleId = (int)$this->createApprovalRule((int)$this->s['admin']->id)->id;
        // open gets an inventory of its own: the inventory of teamScope() is
        // shared by all four job templates, the workflow's own step's too.
        $this->moveToInventory((int)$this->s['open']->id, (int)$this->createInventory((int)$this->s['admin']->id)->id);
        $this->s['open']->refresh();
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
     * @return array<string, array{0: string, 1: string}>
     */
    public static function raceProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::PATHS) as $path) {
            foreach (self::RACES as $race) {
                $cases["{$path}, {$race}"] = [$path, $race];
            }
        }

        return $cases;
    }

    /**
     * regression: a job template gated only through its inventory counted
     * as ungated, so an operator gave a job step such a job template on the
     * template page, which is for admins (rule 12): the run then launched
     * the job as another user, and the operator could approve it. Now the
     * request is refused (403, the policy's text), and nothing is written.
     *
     * @dataProvider pathProvider
     */
    public function testRegressionAnOperatorGaveAJobStepAJobTemplateGatedThroughItsInventory(string $path): void
    {
        $this->giveInventoryRule((int)$this->s['open']->inventory_id, $this->ruleId);
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->loginAs($this->s['member']);

        $refusal = $this->refusalOfPath($path, $workflow);

        $this->assertInstanceOf(ForbiddenHttpException::class, $refusal, 'Expected a ForbiddenHttpException.');
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED, $refusal->getMessage());
        $this->assertSame($before, $this->stepRows((int)$workflow->id), 'nothing was written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    /**
     * The job template is checked again under the lock: an approval rule
     * that an admin gave its inventory between the member's checks and the
     * lock, or an inventory with a rule that an admin gave it, stops the
     * change. 403 with the policy's text, nothing written, no audit entry,
     * no flash.
     *
     * @dataProvider raceProvider
     */
    public function testAJobTemplateGatedThroughItsInventoryMeanwhileStopsTheChange(string $path, string $race): void
    {
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->loginAs($this->s['member']);
        $raced = 0;
        $refusal = null;

        try {
            $this->meanwhile(
                function () use ($race, &$raced): void {
                    $this->gateMeanwhile($race);
                    $raced++;
                },
                fn () => $this->runPath($path, $workflow)
            );
        } catch (ForbiddenHttpException $e) {
            $refusal = $e;
        }

        $this->assertSame(1, $raced, 'the admin gated the job template between the checks and the lock');
        $this->assertInstanceOf(ForbiddenHttpException::class, $refusal, 'Expected a ForbiddenHttpException.');
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED, $refusal->getMessage());
        $previous = $refusal->getPrevious();
        $this->assertInstanceOf(WorkflowStepChangeRefusedException::class, $previous, 'refused by the write path under the lock');
        $this->assertSame(WorkflowStepChangeRefusedException::FORBIDDEN, $previous->status);
        $this->assertSame($before, $this->stepRows((int)$workflow->id), 'nothing of the member\'s change was written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    /**
     * An admin may give a job step such a job template: the same race
     * changes the steps for an admin.
     *
     * @dataProvider raceProvider
     */
    public function testAnAdminChangesTheStepsInTheSameRace(string $path, string $race): void
    {
        $workflow = $this->workflow();
        $this->loginAs($this->s['admin']);

        $this->meanwhile(fn () => $this->gateMeanwhile($race), fn () => $this->runPath($path, $workflow));

        $this->assertArrayHasKey('success', \Yii::$app->session->getAllFlashes());
        $this->assertCount(1, $this->stepAudits((int)$workflow->id));
        $this->assertTrue(WorkflowStep::find()->where([
            'workflow_template_id' => $workflow->id,
            'job_template_id' => $this->s['open']->id,
        ])->exists(), 'a job step uses the job template gated through its inventory');
    }

    /**
     * Without the race, the same requests change the steps for the member:
     * the refusals above are the gate of the inventory.
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
     * the requests give a job step, open (a project without a team, on an
     * inventory of its own), is not one of the workflow's yet, so its gate
     * does not lock the workflow's steps.
     */
    private function workflow(): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['own']->id);

        return $workflow;
    }

    /**
     * What an admin changes meanwhile, by $race: open's inventory gets the
     * rule, or open moves to another inventory that has it.
     */
    private function gateMeanwhile(string $race): void
    {
        if ($race === self::RACES[0]) {
            $this->giveInventoryRule((int)$this->s['open']->inventory_id, $this->ruleId);
            return;
        }
        $this->moveToInventory((int)$this->s['open']->id, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
    }

    /**
     * The 403 that the request of $path is refused with; null when it goes
     * through.
     */
    private function refusalOfPath(string $path, WorkflowTemplate $workflow): ?ForbiddenHttpException
    {
        try {
            $this->runPath($path, $workflow);
        } catch (ForbiddenHttpException $e) {
            return $e;
        }

        return null;
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
