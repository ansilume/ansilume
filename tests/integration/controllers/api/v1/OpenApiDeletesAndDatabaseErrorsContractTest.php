<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\InventoryUsage;
use app\controllers\api\v1\InventoriesController;
use app\controllers\api\v1\ProjectsController;
use app\controllers\api\v1\RunnerGroupsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\controllers\RunnerGroupController;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use app\services\InventoryWriteService;
use app\services\ProjectDeletionService;
use app\services\WorkflowExecutionService;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\db\Exception as DbException;

/**
 * What web/openapi.yaml says about the refused deletes of runner groups and
 * projects, and about a workflow launch that fails on a database error, is
 * what the API answers: each test runs the endpoint and compares its answers
 * with the spec's descriptions and examples.
 *
 * Regressions from wave 9 of step 3, each test fails on the spec as it was
 * before it: DELETE /api/v1/runner-groups/{id} quoted neither of its
 * refusals, which the web UI now shares; DELETE /api/v1/projects/{id} did
 * not say that a job template created while the delete runs gets the
 * refusal; and the workflow launch documented no 500 for a database error.
 *
 * Regression from 2.12.0, fails on the spec of 2.11.1: DELETE
 * /api/v1/inventories/{id} answered 500 for an inventory in use, and the
 * spec documented neither that nor the 409 that answers it now.
 */
class OpenApiDeletesAndDatabaseErrorsContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;

    private const RUNNER_GROUP = '/api/v1/runner-groups/{id}';

    private const WORKFLOW_LAUNCH = '/api/v1/workflow-templates/{id}/launch';

    /**
     * Regression (wave 9 of step 3): a runner group that runners belong to,
     * or that job templates use, is not deleted: 422 with the messages the
     * spec's examples quote, for the example's group name and counts.
     */
    public function testARunnerGroupInUseIsRefusedAsTheSpecSays(): void
    {
        $admin = $this->userWithRole('rg-contract-admin', 'admin');
        $adminId = (int)$admin->id;
        $this->loginAs($admin);
        $examples = $this->exampleMessages(self::RUNNER_GROUP, 'delete', '422');
        $ctrl = new RunnerGroupsController('api/v1/runner-groups', $this->app());

        $withRunners = $this->groupNamed('eu-west', $adminId);
        $this->createRunner((int)$withRunners->id, $adminId);
        $this->createRunner((int)$withRunners->id, $adminId);
        $this->assertSame(['error' => ['message' => $examples['runners'] ?? null]], $ctrl->actionDelete((int)$withRunners->id));
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertNotNull(RunnerGroup::findOne($withRunners->id), 'The group stays.');

        $withTemplates = $this->groupNamed('eu-west', $adminId);
        $projectId = (int)$this->createProject($adminId)->id;
        $inventoryId = (int)$this->createInventory($adminId)->id;
        for ($i = 0; $i < 3; $i++) {
            $this->createJobTemplate($projectId, $inventoryId, (int)$withTemplates->id, $adminId);
        }
        $deleted = $this->createJobTemplate($projectId, $inventoryId, (int)$withTemplates->id, $adminId);
        $this->assertTrue($deleted->softDelete(), 'A deleted job template does not count, as the spec says.');
        $this->assertSame(['error' => ['message' => $examples['jobTemplates'] ?? null]], $ctrl->actionDelete((int)$withTemplates->id));
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertNotNull(RunnerGroup::findOne($withTemplates->id), 'The group stays.');

        // The web UI refuses with the same messages, as the spec says.
        $web = new RunnerGroupController('runner-group', $this->app());
        $active = \Yii::$app->controller;
        \Yii::$app->controller = $web;
        try {
            foreach (['runners' => $withRunners, 'jobTemplates' => $withTemplates] as $example => $group) {
                $web->actionDelete((int)$group->id);
                $this->assertSame(['danger' => $examples[$example] ?? null], \Yii::$app->session->getAllFlashes(true), "The web UI's {$example} refusal.");
                $this->assertNotNull(RunnerGroup::findOne($group->id), 'The group stays.');
            }
        } finally {
            \Yii::$app->controller = $active;
        }

        $description = $this->textOf(['paths', self::RUNNER_GROUP, 'delete', 'description']);
        $this->assertStringContainsString('while runners belong to the group or job templates use it (deleted job templates do not count)', $description);
        $this->assertStringContainsString('The web UI refuses the same deletes with the same messages.', $description);
    }

    /**
     * regression (2.12.0): deleting an inventory that a job template uses
     * failed on the foreign key with a server error, and the spec documented
     * no such answer. It answers 409 as the spec's InventoryInUse documents
     * it, the example word for word for the fixture's IDs, with used_by as
     * InventoryUsage, and the inventory stays.
     */
    public function testRegressionAnInventoryInUseIsRefusedAsTheSpecSays(): void
    {
        $admin = $this->userWithRole('inventory-contract-admin', 'admin');
        $adminId = (int)$admin->id;
        $this->loginAs($admin);
        $inventory = $this->createInventory($adminId);
        $inventory->name = 'web-prod';
        $this->assertTrue($inventory->save(false));
        $project = $this->createProject($adminId);
        $project->name = 'web';
        $this->assertTrue($project->save(false));
        $template = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$this->createRunnerGroup($adminId)->id, $adminId);
        $template->name = 'deploy-web';
        $this->assertTrue($template->save(false));

        $answer = (new InventoriesController('api/v1/inventories', $this->app()))->actionDelete((int)$inventory->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertNotNull(Inventory::findOne($inventory->id), 'The inventory stays.');
        $this->assertSame(
            self::RESPONSE_REF_PREFIX . 'InventoryInUse',
            $this->dig($this->spec(), ['paths', '/api/v1/inventories/{id}', 'delete', 'responses', '409', '$ref'], 'DELETE /api/v1/inventories/{id} documents its 409.')
        );
        /** @var array{error: array{message: string, used_by: array{job_templates: list<array<string, mixed>>}}} $expected */
        $expected = $this->exampleAt(['components', 'responses', 'InventoryInUse']);
        $this->assertIsArray($expected['error']['used_by']['job_templates'][0] ?? null);
        $expected['error']['used_by']['job_templates'][0]['id'] = (int)$template->id;
        $expected['error']['used_by']['job_templates'][0]['project_id'] = (int)$project->id;
        $this->assertSame($expected, $answer, 'The spec\'s example is the answer, for the fixture\'s IDs.');
        $this->assertIsArray($answer['error']);
        $this->assertSame(
            sprintf(InventoryWriteService::DELETE_IN_USE, (new InventoryUsage('web-prod', [], 1, 0))->summary()),
            $answer['error']['message']
        );
        $usedBy = $answer['error']['used_by'];
        $this->assertIsArray($usedBy);
        $this->assertMatchesSchema('InventoryUsage', $usedBy);
        $this->assertSame(array_keys($usedBy), $this->required('InventoryUsage'), 'InventoryUsage documents what used_by holds, in its order.');
        $this->assertIsArray($usedBy['job_templates'][0]);
        $items = $this->dig($this->properties('InventoryUsage'), ['job_templates', 'items', 'properties'], 'InventoryUsage.job_templates documents its items.');
        $this->assertIsArray($items);
        $this->assertMatchesProperties($items, $usedBy['job_templates'][0], 'InventoryUsage.job_templates[]');
        $this->assertStringContainsString(
            'An inventory that job templates use, also when only deleted ones do, is not deleted: 409 with `error.used_by`, and nothing is changed.',
            $this->textOf(['paths', '/api/v1/inventories/{id}', 'delete', 'description'])
        );
    }

    /**
     * Regression (wave 9 of step 3): a job template created in a project
     * while the project's delete runs makes the delete fail in the database;
     * the API answers the refusal the spec's 422 example quotes, with the
     * templates counted again, and the project and the template stay.
     */
    public function testAProjectDeleteThatMeetsANewTemplateIsRefusedAsTheSpecSays(): void
    {
        $admin = $this->userWithRole('project-contract-admin', 'admin');
        $adminId = (int)$admin->id;
        $this->loginAs($admin);
        $project = $this->createProject($adminId);
        $project->name = 'web';
        $this->assertTrue($project->save(false));
        $template = $this->createJobTemplate((int)$project->id, (int)$this->createInventory($adminId)->id, (int)$this->createRunnerGroup($adminId)->id, $adminId);
        $deletion = self::deletionThatCountsTooEarly();
        $before = $this->app()->getComponents(true)['projectDeletionService'] ?? null;
        try {
            $this->app()->set('projectDeletionService', $deletion);
            $answer = (new ProjectsController('api/v1/projects', $this->app()))->actionDelete((int)$project->id);
        } finally {
            $this->app()->set('projectDeletionService', $before);
        }

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(ProjectDeletionService::REFUSED, 'web', 1)]], $answer);
        $this->assertSame($this->exampleAt(['paths', '/api/v1/projects/{id}', 'delete', 'responses', '422']), $answer, 'The spec\'s 422 example is the answer.');
        $this->assertNotNull(Project::findOne($project->id), 'The project stays.');
        $this->assertNotNull(JobTemplate::findOne($template->id), 'and so does the template.');
        $this->assertStringContainsString(
            'A job template created in the project, or moved into it, while the delete runs gets the same refusal (422) with the templates counted again, and the project and its templates stay',
            $this->textOf(['paths', '/api/v1/projects/{id}', 'delete', 'description'])
        );
    }

    /**
     * Regression (wave 9 of step 3): a database error of a workflow launch
     * is no refusal: 500, with the error named as the spec's examples quote
     * it, never with its message, which carries the SQL statement with its
     * values. It answered 422 with the message (a PDOException) or a bare
     * server error (a yii\db\Exception) before.
     */
    public function testAWorkflowLaunchThatFailsOnADatabaseErrorAnswersAsTheSpecSays(): void
    {
        $operator = $this->userWithRole('launch-contract-operator', 'operator');
        $this->loginAs($operator);
        $workflowId = (int)$this->createWorkflowTemplate((int)$operator->id)->id;
        $examples = $this->exampleMessages(self::WORKFLOW_LAUNCH, 'post', '500');
        $sql = 'The SQL being executed was: INSERT INTO workflow_job (extra_vars) VALUES (\'{"db_password": "contract-secret"}\')';
        $deadlock = new DbException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found' . "\n" . $sql, ['40001', 1213, 'Deadlock found when trying to get lock']);
        $lost = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $lost->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];

        foreach (['deadlock' => $deadlock, 'lostConnection' => $lost] as $example => $error) {
            $answer = $this->launchFailingWith($error, $workflowId);
            $this->assertSame(500, \Yii::$app->response->statusCode, "{$example}: a database error is no refusal.");
            $this->assertSame(['error' => ['message' => $examples[$example] ?? null]], $answer, "{$example}: the spec's example is the answer.");
            $this->assertStringNotContainsString('contract-secret', (string)json_encode($answer));
            $this->assertStringNotContainsString('SQLSTATE[', (string)json_encode($answer), 'The answer holds no message of the error.');
        }

        $this->assertSame('#/components/responses/WorkflowLaunchFailed', $this->dig($this->spec(), ['paths', self::WORKFLOW_LAUNCH, 'post', 'responses', '500', '$ref'], 'The launch documents its 500.'));
        $this->assertStringContainsString(
            'A database error is no refusal: 500, with the error named by its class, SQLSTATE and driver error code only',
            $this->textOf(['paths', self::WORKFLOW_LAUNCH, 'post', 'description'])
        );
        $this->assertStringContainsString(
            'the application log has it as `Launch of workflow template #5 failed: ...` with the same text',
            $this->textOf(['components', 'responses', 'WorkflowLaunchFailed', 'description'])
        );
        $this->assertSame(
            'Launch of workflow template #5 failed: ...',
            sprintf(WorkflowTemplatesController::LAUNCH_FAILED_LOG, 5, '...'),
            'The spec quotes the log line as the controller writes it.'
        );
    }

    /**
     * The answer of the API's workflow launch when the engine throws $error.
     *
     * @return array<mixed>
     */
    private function launchFailingWith(\Throwable $error, int $workflowId): array
    {
        $engine = self::engineThatThrows($error);
        $before = $this->app()->getComponents(true)['workflowExecutionService'] ?? null;
        try {
            $this->app()->set('workflowExecutionService', $engine);

            return (new WorkflowTemplatesController('api/v1/workflow-templates', $this->app()))->actionLaunch($workflowId);
        } finally {
            $this->app()->set('workflowExecutionService', $before);
        }
    }

    /**
     * A workflow engine whose launch throws $error, as a database error
     * thrown while the run is written does.
     */
    private static function engineThatThrows(\Throwable $error): WorkflowExecutionService
    {
        return new class ($error) extends WorkflowExecutionService {
            public function __construct(private readonly \Throwable $error)
            {
                parent::__construct();
            }

            public function launch(
                WorkflowTemplate $template,
                int $launchedBy,
                #[\SensitiveParameter] array $overrides = [],
                string $source = 'web'
            ): WorkflowJob {
                throw $this->error;
            }
        };
    }

    /**
     * A project deletion whose first count finds no job template, as when
     * one is created right after the count.
     */
    private static function deletionThatCountsTooEarly(): ProjectDeletionService
    {
        return new class () extends ProjectDeletionService {
            public function blockingTemplateCount(Project $project): int
            {
                return 0;
            }
        };
    }

    private function groupNamed(string $name, int $createdBy): RunnerGroup
    {
        $group = $this->createRunnerGroup($createdBy);
        $group->name = $name;
        $this->assertTrue($group->save(false));

        return $group;
    }

    /**
     * The messages of the examples of the $status response of $method
     * $path, by the example's name.
     *
     * @return array<string, mixed>
     */
    private function exampleMessages(string $path, string $method, string $status): array
    {
        $response = $this->dig($this->spec(), ['paths', $path, $method, 'responses', $status], "{$method} {$path} has a {$status} response.");
        $this->assertIsArray($response);
        if (isset($response['$ref']) && is_string($response['$ref'])) {
            $this->assertStringStartsWith(self::RESPONSE_REF_PREFIX, $response['$ref']);
            $response = $this->dig($this->spec(), ['components', 'responses', substr($response['$ref'], strlen(self::RESPONSE_REF_PREFIX))], "{$response['$ref']} exists.");
        }
        $examples = $this->dig($response, ['content', 'application/json', 'examples'], "{$method} {$path} has {$status} examples.");
        $this->assertIsArray($examples);
        $messages = [];
        foreach ($examples as $name => $example) {
            $messages[(string)$name] = $this->dig($example, ['value', 'error', 'message'], 'An example of the error shape.');
        }

        return $messages;
    }

    /**
     * The example of the response at $keys (its application/json content).
     *
     * @param list<string> $keys
     * @return array<mixed>
     */
    private function exampleAt(array $keys): array
    {
        $example = $this->dig($this->spec(), [...$keys, 'content', 'application/json', 'example'], 'web/openapi.yaml has an example at ' . implode('.', $keys) . '.');
        $this->assertIsArray($example);

        return $example;
    }

    /**
     * The text at $keys of the spec, on one line.
     *
     * @param list<string> $keys
     */
    private function textOf(array $keys): string
    {
        $text = $this->dig($this->spec(), $keys, 'web/openapi.yaml has ' . implode('.', $keys) . '.');
        $this->assertIsString($text);

        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}
