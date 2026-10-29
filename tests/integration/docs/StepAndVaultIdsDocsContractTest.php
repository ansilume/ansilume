<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\components\WorkflowStepCommand;
use app\components\WorkflowStepPosition;
use app\controllers\api\v1\CredentialAssignmentsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\Credential;
use app\models\JobTemplateCredential;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\CredentialWriteService;
use app\tests\integration\controllers\api\v1\WorkflowStepApiRequests;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\docs\Php85UpdateDocsContractTest;
use app\tests\unit\docs\ReadsOperatorDocs;
use Symfony\Component\Yaml\Yaml;
use yii\base\Module;
use yii\web\JsonParser;
use yii\web\Request;

/**
 * The IDs of workflow steps and of the vault password assignment are read
 * as up to 2.13.1, and the texts that say how a whole number of a request
 * body is read name them as the exceptions: the section of docs/updating.md
 * on PHP 8.5, the troubleshooting entry on numbers and the spec's "Whole
 * numbers". The tests send the spellings the texts name through the
 * endpoints (the IDs of the fixtures in place of 5), and check the words.
 *
 * A step's job_template_id and approval_rule_id and an entry of
 * job_template_ids are read by filter_var() (WorkflowStepsInput::toId(),
 * VaultCredentialAssignmentService::checkedIds()): a plus sign and
 * whitespace around the digits are taken, a leading zero and a float are
 * not. A step's branches, position and after_step_id take digits only
 * (WorkflowStepCommand, WorkflowStepPosition), leading zeros too.
 *
 * Regression (verification of the PHP 8.5 move): the three texts said that
 * every ID of a request body is read as the model's integer rule reads it,
 * so that "+5", "05" and 5.0 are 5 and " 5" is refused, which these fields
 * contradict. When they move to SubmittedId, the exceptions go from the
 * texts, and from this test.
 */
class StepAndVaultIdsDocsContractTest extends WebControllerTestCase
{
    use ReadsOperatorDocs;
    use TeamScopeFixtures;
    use WorkflowStepApiRequests;

    /** How the step targets and the vault assignment read an ID: what they take. */
    private const TARGET_TAKES = ['%d', '"%d"', '"+%d"', '" %d"', '"%d "', '"%d\n"'];

    /** What they refuse: a leading zero, a float, also one without a fraction. */
    private const TARGET_REFUSES = ['"0%d"', '%d.0', '%de0', '"%d.0"'];

    /** How the branches and positions of a step read a number: what they take. */
    private const ROUTE_TAKES = ['%d', '"%d"', '"0%d"'];

    /** What they refuse: a sign, whitespace, a float. */
    private const ROUTE_REFUSES = ['"+%d"', '" %d"', '"%d "', '"%d\n"', '%d.0', '%de0'];

    /** The refusal of a steps list whose job step names no job template. */
    private const STEPS_LIST_REFUSAL = 'steps[0]: A job step needs job_template_id, the ID of a job template.';

    /** The refusal of a vault assignment whose list holds an entry that is no ID. */
    private const VAULT_REFUSAL = 'job_template_ids must be a non-empty list of job template IDs.';

    private const NUMBER_ENTRY = '## An API call or a form refuses a number: it must be a whole number';

    private User $admin;

    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('docs-step-ids', 'admin');
        $userId = (int)$this->admin->id;
        $this->templateId = (int)$this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        )->id;
    }

    /**
     * POST /api/v1/workflow-templates/{id}/steps reads the job template of
     * a job step as filter_var() does; a value it refuses is no job template.
     */
    public function testAStepReadsItsJobTemplateAsUpTo2131(): void
    {
        $this->tokenOf($this->admin);
        foreach ([...self::TARGET_TAKES, ...self::TARGET_REFUSES] as $spelling) {
            $workflowId = (int)$this->createWorkflowTemplate((int)$this->admin->id)->id;
            $json = '{"name": "docs-ids", "step_type": "job", "job_template_id": ' . sprintf($spelling, $this->templateId) . '}';

            $answer = $this->apiRaw('POST', $this->stepsPath($workflowId), $json);

            if (in_array($spelling, self::TARGET_TAKES, true)) {
                $this->assertSame($this->templateId, $this->dataOf($answer, 201)['job_template_id'] ?? null, $json);
                continue;
            }
            $this->assertApiError(422, WorkflowStep::JOB_TEMPLATE_REQUIRED, $answer);
            $this->assertFalse(WorkflowStep::find()->where(['workflow_template_id' => $workflowId])->exists(), $json);
        }
    }

    /**
     * The steps of POST /api/v1/workflow-templates are read the same way.
     */
    public function testAStepsListReadsItsJobTemplatesAsUpTo2131(): void
    {
        $this->loginAs($this->admin);
        foreach ([...self::TARGET_TAKES, ...self::TARGET_REFUSES] as $spelling) {
            $name = 'docs-ids-' . uniqid('', true);
            $json = '{"name": "' . $name . '", "steps": [{"name": "Deploy", "step_type": "job", "job_template_id": '
                . sprintf($spelling, $this->templateId) . '}]}';
            $this->sendJson($json);

            $result = (new WorkflowTemplatesController('api/v1/workflow-templates', $this->app()))->actionCreate();

            $workflow = WorkflowTemplate::findOne(['name' => $name]);
            if (in_array($spelling, self::TARGET_TAKES, true)) {
                $this->assertSame(201, \Yii::$app->response->statusCode, $json . ' ' . json_encode($result));
                $this->assertNotNull($workflow, $json);
                $this->assertSame([$this->templateId], array_map('intval', WorkflowStep::find()->select('job_template_id')->where(['workflow_template_id' => $workflow->id])->column()));
                continue;
            }
            $this->assertSame(['error' => ['message' => self::STEPS_LIST_REFUSAL]], $result, $json);
            $this->assertSame(422, \Yii::$app->response->statusCode, $json);
            $this->assertNull($workflow, $json);
        }
    }

    /**
     * A branch, a position and after_step_id of POST
     * /api/v1/workflow-templates/{id}/steps take digits only, leading zeros
     * too, and refuse anything else with their own message.
     */
    public function testTheBranchesAndThePositionOfAStepTakeDigitsOnly(): void
    {
        $this->tokenOf($this->admin);
        $fields = [
            'on_success_step_id' => sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_success_step_id'),
            'position' => WorkflowStepPosition::POSITION_INVALID,
            'after_step_id' => WorkflowStepPosition::AFTER_INVALID,
        ];
        foreach ($fields as $field => $refusal) {
            foreach ([...self::ROUTE_TAKES, ...self::ROUTE_REFUSES] as $spelling) {
                $workflowId = (int)$this->createWorkflowTemplate((int)$this->admin->id)->id;
                $first = (int)$this->createWorkflowStep($workflowId, 1, WorkflowStep::TYPE_PAUSE)->id;
                $number = $field === 'position' ? 1 : $first;
                $json = '{"name": "docs-ids", "step_type": "pause", "' . $field . '": ' . sprintf($spelling, $number) . '}';

                $answer = $this->apiRaw('POST', $this->stepsPath($workflowId), $json);

                if (!in_array($spelling, self::ROUTE_TAKES, true)) {
                    $this->assertApiError(422, $refusal, $answer);
                    $this->assertSame(1, (int)WorkflowStep::find()->where(['workflow_template_id' => $workflowId])->count(), $json);
                    continue;
                }
                $data = $this->dataOf($answer, 201);
                $this->assertSame(
                    ['on_success_step_id' => $first, 'position' => 1, 'after_step_id' => 2][$field],
                    $field === 'on_success_step_id' ? $data['on_success_step_id'] : $data['position'],
                    $json
                );
            }
        }
    }

    /**
     * POST /api/v1/credentials/{id}/job-templates reads an entry of
     * job_template_ids as filter_var() does; one it refuses refuses the list.
     */
    public function testTheVaultAssignmentReadsItsIdsAsUpTo2131(): void
    {
        $vaultId = $this->vault();
        foreach ([...self::TARGET_TAKES, ...self::TARGET_REFUSES] as $spelling) {
            JobTemplateCredential::deleteAll(['job_template_id' => $this->templateId]);
            $json = '{"job_template_ids": [' . sprintf($spelling, $this->templateId) . ']}';
            $this->sendJson($json);

            $result = (new CredentialAssignmentsController('api/v1/credential-assignments', $this->app()))->actionCreate($vaultId);

            $attached = (int)JobTemplateCredential::find()->where(['job_template_id' => $this->templateId, 'credential_id' => $vaultId])->count();
            if (in_array($spelling, self::TARGET_TAKES, true)) {
                $this->assertSame(200, \Yii::$app->response->statusCode, $json . ' ' . json_encode($result));
                $this->assertSame(1, $attached, $json);
                continue;
            }
            $this->assertSame(['error' => ['message' => self::VAULT_REFUSAL]], $result, $json);
            $this->assertSame(422, \Yii::$app->response->statusCode, $json);
            $this->assertSame(0, $attached, $json);
        }
    }

    /**
     * The section, the troubleshooting entry and the spec name these
     * fields as the exceptions, with the spellings the tests above send.
     */
    public function testTheTextsOnWholeNumbersNameTheseFieldsAsTheExceptions(): void
    {
        $section = $this->flat('docs/updating.md', Php85UpdateDocsContractTest::UPDATE_SECTION);
        foreach (
            [
                'The API, the job triggers, the runner API and the web forms posted as JSON read an ID or another whole'
                    . ' number of the request body as the integer rule of the model reads it',
                'The IDs of workflow steps and of the vault password assignment are read as up to 2.13.1: a step\'s'
                    . ' `job_template_id` and `approval_rule_id` (`POST` and `PUT /api/v1/workflow-templates/{id}/steps`,'
                    . ' and the `steps` of `POST` and `PUT /api/v1/workflow-templates`) and an entry of `job_template_ids`'
                    . ' (`POST /api/v1/credentials/{id}/job-templates` and **Assign to job templates**) take an integer or'
                    . ' its digits, also with a plus sign or with whitespace around them (`"+5"`, `" 5"`), and refuse a'
                    . ' leading zero (`"05"`) and a number with a fraction or an exponent (`5.0`, `5e0`);',
                'a step\'s `on_success_step_id`, `on_failure_step_id` and `on_always_step_id`, also in the step form, and'
                    . ' its `position` and `after_step_id`, also of'
                    . ' `POST /api/v1/workflow-templates/{id}/steps/{stepId}/move`, take an integer or its digits, also with'
                    . ' leading zeros (`"05"`), and refuse a sign, whitespace and a number with a fraction or an exponent'
                    . ' (`"+5"`, `" 5"`, `5.0`).',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }

        $entry = $this->flat('docs/troubleshooting.md', self::NUMBER_ENTRY);
        foreach (
            [
                'each ID and other whole number of a request is read as the model\'s integer rule reads it, except the'
                    . ' IDs of workflow steps and of the vault password assignment, which are read as up to 2.13.1 (see'
                    . ' **Fix**);',
                'A step\'s `job_template_id` and `approval_rule_id` and an entry of the `job_template_ids` of a vault'
                    . ' password\'s assignment refuse `"05"` and `5.0`, and a step\'s `on_success_step_id`,'
                    . ' `on_failure_step_id`, `on_always_step_id`, `position` and `after_step_id` refuse `"+5"`, `" 5"`'
                    . ' and `5.0`: send `5` or `"5"` there.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $entry, self::NUMBER_ENTRY);
        }

        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $info = (string)preg_replace('/\s+/', ' ', (string)($spec['info']['description'] ?? ''));
        $this->assertMentions(
            'The IDs of workflow steps and of the vault password assignment are read as releases up to 2.13.1 read'
            . ' them: `job_template_id` and `approval_rule_id` of `WorkflowStepCreateInput`, `WorkflowStepUpdateInput`'
            . ' and `WorkflowStepInput`, and the entries of `job_template_ids` of `VaultAssignmentInput`, take an'
            . ' integer or its digits, also with a plus sign or with whitespace around them (`"+5"`, `" 5"`), and refuse'
            . ' leading zeros (`"05"`) and a number with a fraction or an exponent (`5.0`, `5e0`);'
            . ' `WorkflowStepBranchInput`, `WorkflowStepPositionInput` and `WorkflowStepAfterInput` take an integer or'
            . ' its digits, also with leading zeros, and refuse a sign, whitespace and a number with a fraction or an'
            . ' exponent.',
            $info,
            'the Whole numbers part of the spec'
        );
        $schemas = $spec['components']['schemas'] ?? [];
        $this->assertIsArray($schemas);
        foreach (['WorkflowStepCreateInput', 'WorkflowStepUpdateInput', 'WorkflowStepInput', 'VaultAssignmentInput', 'WorkflowStepBranchInput', 'WorkflowStepPositionInput', 'WorkflowStepAfterInput'] as $schema) {
            $this->assertArrayHasKey($schema, $schemas, "The spec names {$schema}.");
        }
    }

    /**
     * Sends $json as the request body, as the request's JSON parser reads
     * it, so a float stays a float.
     */
    private function sendJson(string $json): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody($json);
        $request->setBodyParams((new JsonParser())->parse($json, 'application/json'));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \Yii::$app->response->statusCode = 200;
    }

    /**
     * The application, as the module of a controller the tests run.
     */
    private function app(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }

    /**
     * A vault password with a secret, and the admin signed in with a token,
     * as the API's caller.
     */
    private function vault(): int
    {
        $userId = (int)$this->admin->id;
        $vault = new Credential();
        $vault->name = 'docs-ids-vault-' . uniqid('', true);
        $vault->credential_type = Credential::TYPE_VAULT;
        $vault->created_by = $userId;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($vault, ['vault_password' => 'docs-ids-vault-fixture-' . uniqid()]));
        ['raw' => $raw] = ApiToken::generate($userId, 'docs-ids-vault');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);

        return (int)$vault->id;
    }
}
