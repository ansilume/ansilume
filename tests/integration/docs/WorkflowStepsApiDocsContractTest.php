<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\User;
use app\models\WorkflowStep;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;
use yii\base\Module;
use yii\web\Request;

/**
 * What docs/troubleshooting.md quotes of the API's refusals of workflow
 * steps is what POST /api/v1/workflow-templates answers: the tests run the
 * endpoint and compare its message with the docs, the IDs aside.
 *
 * Regression from the review of step 3 (contract-3): both messages are
 * literals in the code, which the docs quoted without anything that would
 * notice a change. They pin the quotes to the answers.
 */
class WorkflowStepsApiDocsContractTest extends WebControllerTestCase
{
    use ReadsOperatorDocs;

    private const ENTRY = '## Changing workflow steps is refused';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('docs-steps-api');
        $this->admin->is_superadmin = true;
        $this->admin->save(false);
        $this->loginAs($this->admin);
    }

    public function testAnApprovalStepWithoutARuleIsRefusedAsTheDocsQuote(): void
    {
        $message = $this->refusal([
            ['name' => 'Build', 'step_type' => WorkflowStep::TYPE_PAUSE],
            ['name' => 'Gate', 'step_type' => WorkflowStep::TYPE_APPROVAL],
        ]);

        $this->assertStringStartsWith('steps[1]: ', $message);
        $this->assertMentions('`' . $message . '`', $this->flat('docs/troubleshooting.md', self::ENTRY), 'troubleshooting.md');
    }

    public function testAnUnknownApprovalRuleIsRefusedAsTheDocsQuote(): void
    {
        $message = $this->refusal([['name' => 'Gate', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => 987654321]]);

        $this->assertStringContainsString('#987654321', $message);
        $this->assertMentions(
            '`' . str_replace('#987654321', '#9', $message) . '`',
            $this->flat('docs/troubleshooting.md', self::ENTRY),
            'troubleshooting.md'
        );
    }

    /**
     * The 422 message of a POST with these steps, by a superadmin, who may
     * add approval steps.
     *
     * @param list<array<string, mixed>> $steps
     */
    private function refusal(array $steps): string
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams(['name' => 'docs-steps-api', 'steps' => $steps]);
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', $app))->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $error = $result['error'] ?? null;
        $this->assertIsArray($error);
        $this->assertIsString($error['message'] ?? null);

        return $error['message'];
    }
}
