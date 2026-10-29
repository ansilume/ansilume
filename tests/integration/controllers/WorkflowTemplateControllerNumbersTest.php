<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\AuditLog;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepWriteService;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The id of POST /workflow-template/launch (the dashboard's quick launch
 * posts it) and the step_id of POST /workflow-template/move-step and
 * /remove-step, sent as JSON by a signed-in operator (the request's JSON
 * parser serves the web forms too). A value that is no ID, such as a JSON
 * number beyond the int range, a fraction, a boolean, text or a list, is
 * looked up as a missing one always was, as ID 0, which nothing has: the
 * launch answers 404 ("Workflow template #0 not found."), the move 404
 * (WorkflowStepWriteService::STEP_NOT_FOUND), the remove changes nothing;
 * nothing is launched, moved, removed or audited, and nothing is logged as
 * a failure. An ID is taken as before. Through runAction(), so the access
 * filter and the verb filter run as for a real request.
 *
 * Regression: a JSON number beyond the int range answered 500 on PHP 8.5:
 * the (int) cast of the action warned "The float 1.0E+30 is not
 * representable as an int, cast occurred", and the error handler turned the
 * warning into an exception. Up to PHP 8.4 the cast wrapped it to an
 * arbitrary ID, and took a fraction as the workflow or step of its whole
 * part: the move and the remove changed that step.
 */
class WorkflowTemplateControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private WorkflowTemplate $workflow;

    private WorkflowStep $first;

    private WorkflowStep $second;

    protected function setUp(): void
    {
        parent::setUp();
        $operator = $this->createUserWithRole('numbers-workflow-web', 'operator');
        $this->loginAs($operator);
        $this->workflow = $this->createWorkflowTemplate((int)$operator->id);
        $this->first = $this->createWorkflowStep((int)$this->workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $this->second = $this->createWorkflowStep((int)$this->workflow->id, 20, WorkflowStep::TYPE_PAUSE);
        // Started here: a session component that starts within logged() warns that PHP's session of an earlier test is active.
        $this->session()->removeAllFlashes();
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testALaunchOfAnIdBeyondTheIntRangeFindsNoWorkflow(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText('{"id": ' . $number . '}');
        $this->assertIsFloat($body['id'], 'the parser gives a float');

        $this->assertNotFound('launch', [], 'Workflow template #0 not found.');
        $this->assertNothingChanged($auditBefore);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAMoveOfAStepIdBeyondTheIntRangeFindsNoStep(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"step_id": ' . $number . ', "direction": "up"}');

        $this->assertNotFound('move-step', ['id' => $this->workflow->id], $this->noStep());
        $this->assertNothingChanged($auditBefore);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testARemovalOfAStepIdBeyondTheIntRangeRemovesNothing(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"step_id": ' . $number . '}');

        $this->assertNothingRemoved();
        $this->assertNothingChanged($auditBefore);
    }

    /**
     * Other values that are no ID; %d is the ID of the workflow's second
     * step, or of the workflow. Up to now a fraction, a space and an
     * exponent were taken as that ID, true and a list of one as ID 1; text
     * and false were looked up as ID 0 already.
     *
     * @return array<string, array{0: string}>
     */
    public static function noIdProvider(): array
    {
        return [
            'fraction' => ['%d.5'],
            'decimal text' => ['"%d.5"'],
            'digits after a space' => ['" %d"'],
            'exponent text' => ['"%de0"'],
            'text' => ['"abc"'],
            'true' => ['true'],
            'false' => ['false'],
            'a list of the ID' => ['[%d]'],
        ];
    }

    /**
     * @dataProvider noIdProvider
     */
    public function testALaunchOfAValueThatIsNoIdFindsNoWorkflow(string $value): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"id": ' . sprintf($value, $this->workflow->id) . '}');

        $this->assertNotFound('launch', [], 'Workflow template #0 not found.');
        $this->assertNothingChanged($auditBefore);
    }

    /**
     * @dataProvider noIdProvider
     */
    public function testAMoveOfAValueThatIsNoStepIdFindsNoStep(string $value): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"step_id": ' . sprintf($value, $this->second->id) . ', "direction": "up"}');

        $this->assertNotFound('move-step', ['id' => $this->workflow->id], $this->noStep());
        $this->assertNothingChanged($auditBefore);
    }

    /**
     * @dataProvider noIdProvider
     */
    public function testARemovalOfAValueThatIsNoStepIdRemovesNothing(string $value): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"step_id": ' . sprintf($value, $this->second->id) . '}');

        $this->assertNothingRemoved();
        $this->assertNothingChanged($auditBefore);
    }

    /**
     * The quick launch posts the ID as digits; a JSON body may send it as a
     * number.
     *
     * @return array<string, array{0: string}>
     */
    public static function idProvider(): array
    {
        return [
            'the ID' => ['%d'],
            'the ID as digits, as the form posts it' => ['"%d"'],
            'the ID as a whole float' => ['%d.0'],
        ];
    }

    /**
     * @dataProvider idProvider
     */
    public function testALaunchStartsARunAsBefore(string $value): void
    {
        $this->sendJsonText('{"id": ' . sprintf($value, $this->workflow->id) . '}');
        $ctrl = $this->controller();

        $ctrl->runAction('launch');

        $run = WorkflowJob::findOne(['workflow_template_id' => $this->workflow->id]);
        $this->assertNotNull($run, 'a run was launched');
        $this->assertSame(['/workflow-job/view', 'id' => (int)$run->id], $ctrl->redirectedTo);
    }

    /**
     * @dataProvider idProvider
     */
    public function testAMoveMovesTheStepAsBefore(string $value): void
    {
        $this->sendJsonText('{"step_id": ' . sprintf($value, $this->second->id) . ', "direction": "up"}');
        $ctrl = $this->controller();

        $ctrl->runAction('move-step', ['id' => $this->workflow->id]);

        $this->assertSame(['view', 'id' => (int)$this->workflow->id, '#' => 'step-' . $this->second->id], $ctrl->redirectedTo);
        $this->assertSame([(int)$this->second->id, (int)$this->first->id], $this->stepIdsInOrder(), 'the second step comes first now');
    }

    /**
     * @dataProvider idProvider
     */
    public function testARemovalRemovesTheStepAsBefore(string $value): void
    {
        $this->sendJsonText('{"step_id": ' . sprintf($value, $this->second->id) . '}');
        $ctrl = $this->controller();

        $ctrl->runAction('remove-step', ['id' => $this->workflow->id]);

        $this->assertSame(['view', 'id' => (int)$this->workflow->id, '#' => 'workflow-steps'], $ctrl->redirectedTo);
        $this->assertSame([(int)$this->first->id], $this->stepIdsInOrder());
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     */
    private function assertNotFound(string $action, array $params, string $message): void
    {
        [$refusal, $problems] = $this->logged(function () use ($action, $params): ?NotFoundHttpException {
            try {
                $this->controller()->runAction($action, $params);
            } catch (NotFoundHttpException $e) {
                return $e;
            }

            return null;
        });

        $this->assertInstanceOf(NotFoundHttpException::class, $refusal, 'answered 404');
        $this->assertSame($message, $refusal->getMessage());
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    private function assertNothingRemoved(): void
    {
        $ctrl = $this->controller();

        [, $problems] = $this->logged(fn (): mixed => $ctrl->runAction('remove-step', ['id' => $this->workflow->id]));

        $this->assertSame(['view', 'id' => (int)$this->workflow->id, '#' => 'workflow-steps'], $ctrl->redirectedTo);
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * The workflow keeps its steps in their order, nothing was launched,
     * audited or flashed.
     */
    private function assertNothingChanged(int $auditBefore): void
    {
        $this->assertSame([(int)$this->first->id, (int)$this->second->id], $this->stepIdsInOrder(), 'the steps stay as they are');
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $this->workflow->id])->exists(), 'nothing was launched');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $this->session()->getAllFlashes(), 'nothing was flashed');
    }

    private function noStep(): string
    {
        return sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, $this->workflow->id, 0);
    }

    /**
     * @return list<int>
     */
    private function stepIdsInOrder(): array
    {
        return array_map('intval', WorkflowStep::find()
            ->select('id')
            ->where(['workflow_template_id' => $this->workflow->id])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->column());
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function session(): \yii\web\Session
    {
        $session = \Yii::$app->session;
        $this->assertInstanceOf(\yii\web\Session::class, $session);

        return $session;
    }

    /**
     * @return WorkflowTemplateController&object{redirectedTo: mixed}
     */
    private function controller(): WorkflowTemplateController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new class ('workflow-template', $app) extends WorkflowTemplateController {
            public mixed $redirectedTo = null;

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->redirectedTo = $url;

                return new Response();
            }
        };
    }
}
