<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\controllers\SendsJsonBody;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * POST and PUT /api/v1/workflow-templates with a step type that is not job,
 * approval or pause. The web form took a JSON true for a valid type and
 * saved the step as "1" (WorkflowTemplateControllerStepTypeTest). The API
 * reads step_type as a string, so true is checked as "1" and refused: 422,
 * and nothing is written. Pinned here.
 */
class WorkflowTemplatesStepTypeTest extends WebControllerTestCase
{
    use SendsJsonBody;
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function stepTypeThatIsNoTypeProvider(): array
    {
        return ['true' => [true], 'the number 1' => [1], 'the text "1"' => ['1']];
    }

    /**
     * @dataProvider stepTypeThatIsNoTypeProvider
     */
    public function testCreateRefusesTheStepAndWritesNothing(mixed $type): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $name = 'api-wf-type-' . uniqid('', true);
        $this->sendJson(['name' => $name, 'steps' => [['name' => 'typed', 'step_type' => $type]]]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'steps[0]: Step Type is invalid.']], $result);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => $name])->exists());
    }

    /**
     * @dataProvider stepTypeThatIsNoTypeProvider
     */
    public function testUpdateRefusesTheStepAndKeepsTheSteps(mixed $type): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $steps = $this->stepRows($workflow);
        $this->authenticate($s['member']);
        $this->sendJson(['steps' => [['name' => 'typed', 'step_type' => $type]]], 'PUT');

        $result = $this->controller()->actionUpdate((int)$workflow->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'steps[0]: Step Type is invalid.']], $result);
        $this->assertSame($steps, $this->stepRows($workflow));
    }

    private function controller(): WorkflowTemplatesController
    {
        return new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'step-type-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
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
}
