<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\AuditLog;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * Regression: the web application parses JSON bodies, and the step type
 * rule compared loosely, so add-step saved a step whose step_type was the
 * JSON true, stored as "1". No step type matched it, so a run that reached
 * the step waited there until someone canceled it.
 */
class WorkflowTemplateControllerStepTypeTest extends WebControllerTestCase
{
    use SendsJsonBody;
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function stepTypeThatIsNoTypeProvider(): array
    {
        return [
            'true' => [true, 'Step not added: Step Type must be a string.'],
            'the number 1' => [1, 'Step not added: Step Type must be a string.'],
            'the text "1"' => ['1', 'Step not added: Step Type is invalid.'],
        ];
    }

    /**
     * @dataProvider stepTypeThatIsNoTypeProvider
     */
    public function testAddStepRefusesAStepTypeThatIsNoType(mixed $type, string $flash): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $steps = $this->stepRows($workflow);
        $this->loginAs($s['member']);
        $this->sendJson(['WorkflowStep' => ['name' => 'typed', 'step_type' => $type, 'step_order' => 10]]);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertSame(['danger' => $flash], \Yii::$app->session->getAllFlashes(true));
        $this->assertSame($steps, $this->stepRows($workflow));
        $this->assertFalse(AuditLog::find()->where([
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED,
            'object_id' => $workflow->id,
        ])->exists());
    }

    public function testAddStepSavesEachStepTypeFromAJsonBody(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $rule = $this->createApprovalRule($s['admin']->id);
        $this->loginAs($s['member']);
        $targets = [
            WorkflowStep::TYPE_JOB => ['job_template_id' => (int)$s['own']->id],
            WorkflowStep::TYPE_APPROVAL => ['approval_rule_id' => (int)$rule->id],
            WorkflowStep::TYPE_PAUSE => [],
        ];

        foreach ($targets as $type => $target) {
            $this->sendJson(['WorkflowStep' => ['name' => 'json-' . $type, 'step_type' => $type] + $target]);
            $this->makeController()->actionAddStep((int)$workflow->id);
            $this->assertSame(['success' => "Step \"json-{$type}\" added."], \Yii::$app->session->getAllFlashes(true), $type);
        }

        $saved = WorkflowStep::find()
            ->select(['name', 'step_type', 'job_template_id', 'approval_rule_id'])
            ->where(['workflow_template_id' => $workflow->id, 'name' => ['json-job', 'json-approval', 'json-pause']])
            ->orderBy(['id' => SORT_ASC])
            ->asArray()
            ->all();
        $this->assertEquals([
            ['name' => 'json-job', 'step_type' => 'job', 'job_template_id' => $s['own']->id, 'approval_rule_id' => null],
            ['name' => 'json-approval', 'step_type' => 'approval', 'job_template_id' => null, 'approval_rule_id' => $rule->id],
            ['name' => 'json-pause', 'step_type' => 'pause', 'job_template_id' => null, 'approval_rule_id' => null],
        ], $saved);
    }

    /**
     * @return list<array<string, mixed>> the workflow's steps as stored
     */
    private function stepRows(WorkflowTemplate $workflow): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = WorkflowStep::find()
            ->where(['workflow_template_id' => $workflow->id])
            ->orderBy(['id' => SORT_ASC])
            ->asArray()
            ->all();
        return $rows;
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
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }
}
