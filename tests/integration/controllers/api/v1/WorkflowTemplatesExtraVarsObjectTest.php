<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\web\JsonParser;

/**
 * POST and PUT /api/v1/workflow-templates with a step whose
 * extra_vars_template is a JSON object, not JSON text.
 *
 * Regression: an object that json_encode() cannot write, as it holds a
 * number too large for JSON (1e999 in the request body, which PHP reads as
 * infinity), was stored as an empty extra_vars_template with 201 or 200:
 * the step lost its extra vars silently. The same mapping as JSON text was
 * refused with 422. Both forms now get the same answer.
 */
class WorkflowTemplatesExtraVarsObjectTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const TOO_LARGE = 'steps[%d]: The extra vars cannot be stored: they contain a number too large for JSON.';

    private WorkflowTemplatesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app);
    }

    /**
     * extra_vars_template objects, as JSON text of the request body.
     *
     * @return array<string, array{0: string}>
     */
    public static function tooLargeObjectProvider(): array
    {
        return [
            'a number too large for JSON' => ['{"target_env": 1e999}'],
            'a negative one' => ['{"app_env": "build_env", "retries": -1e999}'],
            'one in a list' => ['{"hosts": ["web1", 1e999]}'],
            'one in an object' => ['{"limits": {"cpu": 1e999}}'],
        ];
    }

    /**
     * @dataProvider tooLargeObjectProvider
     */
    public function testACreateWithSuchAnObjectIs422AndWritesNothing(string $object): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setJsonBody(sprintf(
            '{"name": "api-wf-inf-object", "steps": [{"name": "inf-object-step", "job_template_id": %d, "extra_vars_template": %s}]}',
            $s['own']->id,
            $object
        ));

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(self::TOO_LARGE, 0)]], $result);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => 'api-wf-inf-object'])->exists());
        $this->assertFalse(WorkflowStep::find()->where(['name' => 'inf-object-step'])->exists());
    }

    /**
     * @dataProvider tooLargeObjectProvider
     */
    public function testAnUpdateWithSuchAnObjectIs422AndKeepsTheWorkflowAsItWas(string $object): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $this->authenticate($s['member']);
        $this->setJsonBody(sprintf(
            '{"name": "renamed", "steps": [{"name": "kept", "job_template_id": %1$d}, {"name": "inf-object-step", "job_template_id": %1$d, "extra_vars_template": %2$s}]}',
            $s['own']->id,
            $object
        ));

        $result = $this->ctrl->actionUpdate($workflow->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(self::TOO_LARGE, 1)]], $result);
        $this->assertSame($workflow->name, WorkflowTemplate::findOne($workflow->id)?->name);
        $this->assertSame(['step-0', 'step-1'], $this->stepNames($workflow->id));
    }

    /**
     * Steps with the same mapping, once as an object and once as JSON text.
     * Errors of the other attributes come first in both forms.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sameAnswerProvider(): array
    {
        return [
            'only the number too large for JSON' => ['"name": "same-answer-step", "job_template_id": OWN', 'steps[0]: The extra vars cannot be stored: they contain a number too large for JSON.'],
            'and no name' => ['"name": "", "job_template_id": OWN', 'steps[0]: Name cannot be blank.'],
            'and an unknown step type' => ['"name": "same-answer-step", "step_type": "shell"', 'steps[0]: Step Type is invalid.'],
        ];
    }

    /**
     * @dataProvider sameAnswerProvider
     */
    public function testTheObjectGetsTheAnswerOfTheSameMappingAsJsonText(string $fields, string $message): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $fields = str_replace('OWN', (string)$s['own']->id, $fields);
        $answers = [];
        foreach (['{"target_env": 1e999}', '"{\"target_env\": 1e999}"'] as $mapping) {
            $this->setJsonBody(sprintf('{"name": "api-wf-same-answer", "steps": [{%s, "extra_vars_template": %s}]}', $fields, $mapping));
            $answers[] = [$this->ctrl->actionCreate(), \Yii::$app->response->statusCode];
        }

        $this->assertSame([['error' => ['message' => $message]], 422], $answers[0], 'the object');
        $this->assertSame($answers[1], $answers[0], 'the object and the text get the same answer');
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => 'api-wf-same-answer'])->exists());
    }

    /**
     * Only a form-encoded body can carry text that is not UTF-8 (a JSON body
     * with it is refused as invalid JSON). In an object it gets the message
     * a launch gives such extra vars.
     */
    public function testAnObjectWithTextThatIsNotUtf8Is422(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        parse_str(
            'name=api-wf-utf8-object&steps[0][name]=utf8-object-step&steps[0][job_template_id]=' . $s['own']->id
                . '&steps[0][extra_vars_template][target_env]=%FF',
            $body
        );
        /** @var array<string, mixed> $body */
        $this->setBody($body);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'steps[0]: The extra vars cannot be stored: they contain characters that are not valid UTF-8.']],
            $result
        );
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => 'api-wf-utf8-object'])->exists());
    }

    /**
     * An object JSON can hold is stored as its JSON text, on create and on
     * update, and read back as the same mapping.
     */
    public function testAnObjectJsonCanHoldIsStoredAsJsonText(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setJsonBody(sprintf(
            '{"name": "api-wf-object", "steps": [{"name": "object-step", "job_template_id": %d, "extra_vars_template": {"target_env": "build_env", "build_id": 9007199254740993}}]}',
            $s['own']->id
        ));

        $created = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($created));
        $step = WorkflowStep::findOne(['name' => 'object-step']);
        $this->assertNotNull($step);
        $this->assertSame('{"target_env":"build_env","build_id":9007199254740993}', $step->extra_vars_template);
        $this->assertSame(['target_env' => 'build_env', 'build_id' => 9007199254740993], $created['data']['steps'][0]['extra_vars_template'] ?? null);

        $this->setJsonBody(sprintf(
            '{"steps": [{"name": "object-step-2", "job_template_id": %d, "extra_vars_template": {"app_env": "stage"}}]}',
            $s['own']->id
        ));
        $updated = $this->ctrl->actionUpdate((int)$step->workflow_template_id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($updated));
        $this->assertSame('{"app_env":"stage"}', WorkflowStep::findOne(['name' => 'object-step-2'])?->extra_vars_template);
    }

    // -- Helpers ------------------------------------------------------------------

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'workflow-api-object-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * The body as the API reads a JSON request: with Yii's JsonParser, which
     * reads 1e999 as infinity.
     */
    private function setJsonBody(string $json): void
    {
        $body = (new JsonParser())->parse($json, 'application/json');
        $this->assertIsArray($body);
        /** @var array<string, mixed> $body */
        $this->setBody($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * @return list<string>
     */
    private function stepNames(int $workflowTemplateId): array
    {
        /** @var list<string> $names */
        $names = WorkflowStep::find()
            ->select('name')
            ->where(['workflow_template_id' => $workflowTemplateId])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->column();

        return $names;
    }
}
