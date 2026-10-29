<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\ApiBodyValues;
use app\controllers\api\runner\JobsController as RunnerJobsController;
use app\controllers\api\v1\ApprovalRulesController;
use app\controllers\api\v1\InventoriesController;
use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\ProjectsController;
use app\controllers\api\v1\RunnersController;
use app\controllers\api\v1\SchedulesController;
use app\controllers\api\v1\TeamsController;
use app\controllers\api\v1\UsersController;
use app\controllers\ApprovalRuleController;
use app\controllers\InventoryController;
use app\controllers\JobTemplateController;
use app\controllers\ProjectController;
use app\controllers\RunnerController;
use app\controllers\ScheduleController;
use app\controllers\TeamController;
use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\LintService;
use app\services\RunnerReportNumbers;
use app\services\WorkflowStepWriteService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Model;
use yii\db\Query;
use yii\helpers\Html;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\JsonParser;
use yii\web\NotFoundHttpException;
use yii\web\Request;

/**
 * A list or an object where a request gives an ID or another whole number:
 * every such field of the API's request bodies, of the runner API's reports
 * and of the web forms, posted as JSON (the request's JSON parser serves the
 * forms too). The parser makes {} an empty array, as it makes []: both arrive
 * as [], which Yii's rules take for empty and skip, and SubmittedInteger::
 * orAsSubmitted() hands on as it came.
 *
 * Each field answers [] and {}, on a create and on an update, with a
 * refusal: the error its integer rule gives a list with values ("Forks must
 * be an integer."), as the API's 422 or under the field of the form shown
 * again, or the outcome the field was given for any value that is no ID: the
 * inventories API's 422 for a list ("project_id must be a single value, not
 * a list or an object.", ApiBodyValues), the required rule's "cannot be
 * blank" where an empty list is no value, a 404 or a 400 for an ID that names
 * nothing, "Status is invalid.", the runner API's refusals. Nothing is
 * written, nothing audited, nothing logged as a failure. Each body is a JSON
 * text that yii\web\JsonParser reads, as config/web.php sets it up.
 *
 * Regression: an empty list skipped the integer, min, max and exist rules,
 * and the column stored it as 0, or its foreign key refused 0 with a server
 * error. POST and PUT /api/v1/projects with "scm_credential_id": [] or {}
 * answered 500 (IntegrityException 1452), where 2.13.1 answered 422 "Scm
 * Credential Id is invalid."; the web project form posted as JSON gave the
 * same 500 before. An admin's "approval_rule_id": [] of POST and PUT
 * /api/v1/job-templates, and of the job template and inventory forms, and
 * the job template form's credential_id, answered 500 as well. A job
 * template's verbosity, through the API and the form, and the form's forks
 * and timeout_minutes were saved as 0; so were an approval rule's
 * required_approvals (a rule that needs no approval: a rejection approves
 * the request) and timeout_minutes (a request that times out at once),
 * through the API and the web form.
 */
class ListsForWholeNumbersTest extends WebControllerTestCase
{
    use RendersRealViews;
    use RestoresComponents;
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    /** The JSON texts that the parser reads as an empty array. */
    private const LISTS = ['an empty list' => '[]', 'an empty object' => '{}'];

    /** The name of what a create would create. */
    private const CREATED = 'lists-created';

    /** The name an update would give. */
    private const RENAMED = 'lists-renamed';

    private const CONTENT = "all:\n  hosts:\n    web1.example.com:\n";

    /** The 422 of the job template API's credential service for an entry of credential_ids that is no ID. */
    private const NOT_A_CREDENTIAL_ENTRY = 'credential_ids must contain positive integer credential IDs.';

    private User $admin;
    private Credential $credential;
    private Project $project;
    private Inventory $inventory;
    private RunnerGroup $group;
    private JobTemplate $template;
    private ApprovalRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapComponent('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        \Yii::$app->session->removeAllFlashes();
        // A superadmin: every check that is not about the value lets the request through.
        $this->admin = $this->createUserWithRole('lists-admin', 'admin');
        $this->admin->is_superadmin = true;
        $this->admin->save(false);
        $owner = (int)$this->admin->id;
        $this->credential = $this->createCredential($owner);
        $this->project = $this->createProject($owner);
        Project::updateAll(['scm_credential_id' => $this->credential->id], ['id' => $this->project->id]);
        $this->inventory = $this->createInventory($owner);
        Inventory::updateAll(['project_id' => $this->project->id, 'content' => self::CONTENT], ['id' => $this->inventory->id]);
        $this->group = $this->createRunnerGroup($owner);
        $this->template = $this->createJobTemplate((int)$this->project->id, (int)$this->inventory->id, (int)$this->group->id, $owner);
        JobTemplate::updateAll(['credential_id' => $this->credential->id, 'verbosity' => 2], ['id' => $this->template->id]);
        $this->rule = $this->createApprovalRule($owner);
        ApprovalRule::updateAll(['timeout_minutes' => 60], ['id' => $this->rule->id]);
        $this->loginAs($this->admin);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        \Yii::$app->session->removeAllFlashes();
        parent::tearDown();
    }

    // -- The API's request bodies ------------------------------------------------

    /**
     * Every ID and whole-number field of the API's create and update bodies,
     * with its answer to a list or an object: the field's integer message,
     * or the answer the endpoint gives any value that is no ID. A schedule's
     * job template is required: an empty list is no value to it, as null and
     * "" are. The inventories API refuses a list in any field of its body
     * (ApiBodyValues).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: int, 5: array<string, mixed>}>
     */
    public static function apiProvider(): array
    {
        $refused = static fn (string $message): array => [422, ['error' => ['message' => $message]]];
        $notAValue = static fn (string $field): array => $refused(sprintf(ApiBodyValues::NOT_A_VALUE, $field));
        $answers = [
            'projects' => ['scm_credential_id' => $refused('Scm Credential Id must be an integer.')],
            'job-templates' => [
                'project_id' => $refused('Project Id must be an integer.'),
                'inventory_id' => $refused('Inventory Id must be an integer.'),
                'credential_id' => $refused('Credential Id must be an integer.'),
                'runner_group_id' => $refused('Runner must be an integer.'),
                'verbosity' => $refused('Verbosity must be an integer.'),
                'forks' => $refused('Forks must be an integer.'),
                'timeout_minutes' => $refused('Timeout Minutes must be an integer.'),
                'approval_rule_id' => $refused('Approval rule must be an integer.'),
                'credential_ids' => $refused(self::NOT_A_CREDENTIAL_ENTRY),
            ],
            'schedules' => ['job_template_id' => $refused('Job Template Id cannot be blank.')],
            'inventories' => ['project_id' => $notAValue('project_id'), 'approval_rule_id' => $notAValue('approval_rule_id')],
            'approval-rules' => [
                'required_approvals' => $refused('Required Approvals must be an integer.'),
                'timeout_minutes' => $refused('Timeout Minutes must be an integer.'),
            ],
            'users' => ['status' => $refused(UsersController::INVALID_STATUS)],
        ];
        $cases = [];
        foreach ($answers as $endpoint => $fields) {
            foreach ($fields as $field => [$status, $answer]) {
                foreach (self::LISTS as $label => $json) {
                    foreach (['POST', 'PUT'] as $method) {
                        $cases["{$method} {$endpoint}, {$field}: {$label}"] = [$endpoint, $field, $json, $method, $status, $answer];
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * @dataProvider apiProvider
     * @param array<string, mixed> $answer
     */
    public function testAListInAnApiBodyIsRefusedAndNothingIsWritten(
        string $endpoint,
        string $field,
        string $json,
        string $method,
        int $status,
        array $answer
    ): void {
        // An entry of credential_ids, which is a list of IDs itself.
        $value = $field === 'credential_ids' ? '[' . $json . ']' : $json;
        [$run, $stored] = $method === 'POST' ? $this->apiCreate($endpoint, $field, $value) : $this->apiUpdate($endpoint, $field, $value);

        $this->assertAnsweredWithoutWriting($run, $stored, $status, $answer);
    }

    /**
     * The fields of the API's other bodies with an ID or a whole number, each
     * of a POST only: an ID that is no ID names nothing (404), and a runner's
     * report with a number it cannot store is refused (422).
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: array<string, mixed>}>
     */
    public static function apiPostProvider(): array
    {
        $answers = [
            'team member' => [404, ['error' => ['message' => sprintf(TeamsController::USER_NOT_FOUND, 0)]]],
            'team project' => [404, ['error' => ['message' => sprintf(TeamsController::PROJECT_NOT_FOUND, 0)]]],
            'runner move' => [404, ['error' => ['message' => 'Target runner group not found.']]],
            'runner log sequence' => [422, ['ok' => false, 'error' => RunnerReportNumbers::SEQUENCE_REFUSED]],
            'runner exit code' => [422, ['ok' => false, 'error' => RunnerReportNumbers::EXIT_CODE_REFUSED]],
            'runner task seq' => [422, ['ok' => false, 'error' => sprintf(RunnerReportNumbers::TASK_NUMBER_REFUSED, 0, 'seq')]],
            'runner task duration' => [422, ['ok' => false, 'error' => sprintf(RunnerReportNumbers::TASK_NUMBER_REFUSED, 0, 'duration_ms')]],
        ];
        $cases = [];
        foreach ($answers as $request => [$status, $answer]) {
            foreach (self::LISTS as $label => $json) {
                $cases["{$request}: {$label}"] = [$request, $json, $status, $answer];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider apiPostProvider
     * @param array<string, mixed> $answer
     */
    public function testAListInAnotherApiBodyNamesNothingAndNothingIsWritten(string $request, string $json, int $status, array $answer): void
    {
        [$run, $stored] = $this->apiPost($request, $json);

        $this->assertAnsweredWithoutWriting($run, $stored, $status, $answer);
    }

    // -- The web forms posted as JSON --------------------------------------------

    /**
     * Every ID and whole-number field of the web forms that save a model,
     * with the error the form shows for a list or an object. The project of
     * a job template is no project (TeamScopingTrait), which its required
     * rule refuses, and so is a schedule's job template.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function formProvider(): array
    {
        $errors = [
            'job-template' => [
                'project_id' => 'Project Id cannot be blank.',
                'inventory_id' => 'Inventory Id must be an integer.',
                'credential_id' => 'Credential Id must be an integer.',
                'runner_group_id' => 'Runner must be an integer.',
                'verbosity' => 'Verbosity must be an integer.',
                'forks' => 'Forks must be an integer.',
                'timeout_minutes' => 'Timeout Minutes must be an integer.',
                'approval_rule_id' => 'Approval rule must be an integer.',
            ],
            'inventory' => ['approval_rule_id' => 'Approval rule must be an integer.'],
            'project' => ['scm_credential_id' => 'Scm Credential Id must be an integer.'],
            'approval-rule' => ['required_approvals' => 'Required Approvals must be an integer.', 'timeout_minutes' => 'Timeout Minutes must be an integer.'],
            'schedule' => ['job_template_id' => 'Job Template Id cannot be blank.'],
        ];
        $cases = [];
        foreach ($errors as $form => $fields) {
            foreach ($fields as $field => $error) {
                foreach (self::LISTS as $label => $json) {
                    foreach (['create', 'update'] as $action) {
                        $cases["/{$form}/{$action}, {$field}: {$label}"] = [$form, $field, $json, $action, $error];
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * The form is rendered with the real views, so a value it cannot show
     * would fail the page.
     *
     * @dataProvider formProvider
     */
    public function testAListInAFormShowsTheFormWithTheErrorAndNothingIsWritten(
        string $form,
        string $field,
        string $json,
        string $action,
        string $error
    ): void {
        $ctrl = $this->formController($form);
        [$run, $stored] = $action === 'create' ? $this->formCreate($ctrl, $form, $field, $json) : $this->formUpdate($ctrl, $form, $field, $json);
        $before = $stored();
        $auditBefore = $this->lastAuditId();

        [$html, $problems] = $this->logged(fn (): mixed => $this->withRealViews($ctrl, "/{$form}/{$action}", $run));

        $this->assertSame('form', $ctrl->renderedView, 'the form is shown again');
        $model = $ctrl->renderedParams['model'] ?? null;
        $this->assertInstanceOf(Model::class, $model);
        $this->assertSame([$error], $model->getErrors($field));
        $this->assertIsString($html);
        $this->assertStringContainsString(Html::encode($error), $html, 'the page shows the error');
        $this->assertSame($before, $stored(), 'nothing was written');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * An approval rule sent as a list or an object by a user who may not
     * assign rules: a change of the rule, which they may not make (403), as
     * for any other value that is no rule ID, through the job templates API
     * and the job template and inventory forms. The inventories API refuses
     * a list in any field of its body first (422, ApiBodyValues).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function ruleFromAnOperatorProvider(): array
    {
        $cases = [];
        foreach (['api job-templates', 'api inventories', 'web job-template', 'web inventory'] as $where) {
            foreach (self::LISTS as $label => $json) {
                foreach (['create', 'update'] as $action) {
                    $cases["{$where} {$action}: {$label}"] = [$where, $json, $action];
                }
            }
        }

        return $cases;
    }

    /**
     * @dataProvider ruleFromAnOperatorProvider
     */
    public function testAListAsTheRuleFromAUserWhoMayNotAssignRulesIsRefused(string $where, string $json, string $action): void
    {
        $this->loginAs($this->createUserWithRole('lists-operator', 'operator'));
        [$kind, $resource] = explode(' ', $where);
        if ($kind === 'api') {
            [$run, $stored] = $action === 'create'
                ? $this->apiCreate($resource, 'approval_rule_id', $json)
                : $this->apiUpdate($resource, 'approval_rule_id', $json);
            $answer = $resource === 'inventories'
                ? [422, ['error' => ['message' => sprintf(ApiBodyValues::NOT_A_VALUE, 'approval_rule_id')]]]
                : [403, ['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]]];
            $this->assertAnsweredWithoutWriting($run, $stored, $answer[0], $answer[1]);

            return;
        }
        $ctrl = $this->formController($resource);
        [$run, $stored] = $action === 'create'
            ? $this->formCreate($ctrl, $resource, 'approval_rule_id', $json)
            : $this->formUpdate($ctrl, $resource, 'approval_rule_id', $json);
        $before = $stored();
        $auditBefore = $this->lastAuditId();
        try {
            $this->withRealViews($ctrl, "/{$resource}/{$action}", $run);
            $this->fail('403 for a rule change');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $e->getMessage());
        }
        $this->assertSame($before, $stored(), 'nothing was written');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
    }

    /**
     * The inventory form's empty list is no project, as its empty choice is
     * (TeamScopingTrait, and the model's default rule): the inventory is
     * saved without one.
     *
     * @dataProvider listProvider
     */
    public function testAListAsTheProjectOfTheInventoryFormIsNoProject(string $json): void
    {
        $ctrl = $this->formController('inventory');
        $this->assertInstanceOf(InventoryController::class, $ctrl);
        $this->sendJson('{"Inventory": {"project_id": ' . $json . '}}', 'POST');

        [, $problems] = $this->logged(fn (): mixed => $this->withRealViews($ctrl, '/inventory/update', fn (): mixed => $ctrl->actionUpdate((int)$this->inventory->id)));

        $this->assertSame(['view', 'id' => $this->inventory->id], $ctrl->redirectedTo);
        $this->assertNull(Inventory::findOne($this->inventory->id)?->project_id);
        $this->assertSame([], $problems);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function listProvider(): array
    {
        return array_map(static fn (string $json): array => [$json], self::LISTS);
    }

    /**
     * The web posts with an ID that are no form: an ID that is no ID names
     * nothing, as a missing one.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function webPostProvider(): array
    {
        $cases = [];
        foreach (['team add-member', 'team add-project', 'runner create', 'runner move', 'workflow launch', 'workflow move-step', 'workflow remove-step'] as $post) {
            foreach (self::LISTS as $label => $json) {
                $cases["{$post}: {$label}"] = [$post, $json];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider webPostProvider
     */
    public function testAListAsTheIdOfAWebPostNamesNothingAndNothingIsWritten(string $post, string $json): void
    {
        $auditBefore = $this->lastAuditId();

        [, $problems] = $this->logged(fn (): mixed => $this->webPost($post, $json));

        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    // -- API requests ------------------------------------------------------------

    /**
     * The create of $endpoint with $field set to the JSON text $value: the
     * request, and what it could write (the rows with the created name).
     *
     * @return array{0: callable(): mixed, 1: callable(): mixed}
     */
    private function apiCreate(string $endpoint, string $field, string $value): array
    {
        $app = $this->app();
        [$ctrl, $table, $nameColumn, $body] = match ($endpoint) {
            'projects' => [new ProjectsController('api/v1/projects', $app), Project::tableName(), 'name', ['name' => self::CREATED, 'scm_type' => 'manual']],
            'job-templates' => [new JobTemplatesController('api/v1/job-templates', $app), JobTemplate::tableName(), 'name', $this->templateFields(self::CREATED)],
            'schedules' => [new SchedulesController('api/v1/schedules', $app), Schedule::tableName(), 'name', $this->scheduleFields(self::CREATED)],
            'inventories' => [
                new InventoriesController('api/v1/inventories', $app),
                Inventory::tableName(),
                'name',
                ['name' => self::CREATED, 'inventory_type' => 'static', 'content' => self::CONTENT],
            ],
            'approval-rules' => [
                new ApprovalRulesController('api/v1/approval-rules', $app),
                ApprovalRule::tableName(),
                'name',
                ['name' => self::CREATED, 'approver_type' => 'role', 'approver_config' => ['role' => 'operator']],
            ],
            'users' => [
                new UsersController('api/v1/users', $app),
                User::tableName(),
                'username',
                ['username' => self::CREATED, 'email' => 'lists-created@example.com', 'password' => 'Lists-example-4711'],
            ],
        };
        $this->sendJson($this->json($body, $field, $value), 'POST');

        return [
            static fn (): mixed => $ctrl->actionCreate(),
            static fn (): mixed => (new Query())->from($table)->where([$nameColumn => self::CREATED])->count(),
        ];
    }

    /**
     * The update of a record of $endpoint, renamed, with $field set to the
     * JSON text $value: the request, and the record's row (for a job
     * template, also its additional credentials).
     *
     * @return array{0: callable(): mixed, 1: callable(): mixed}
     */
    private function apiUpdate(string $endpoint, string $field, string $value): array
    {
        $app = $this->app();
        [$ctrl, $table, $id, $body] = match ($endpoint) {
            'projects' => [new ProjectsController('api/v1/projects', $app), Project::tableName(), (int)$this->project->id, ['name' => self::RENAMED]],
            'job-templates' => [new JobTemplatesController('api/v1/job-templates', $app), JobTemplate::tableName(), (int)$this->template->id, ['name' => self::RENAMED]],
            'schedules' => [new SchedulesController('api/v1/schedules', $app), Schedule::tableName(), $this->scheduleId(), ['name' => self::RENAMED]],
            'inventories' => [new InventoriesController('api/v1/inventories', $app), Inventory::tableName(), (int)$this->inventory->id, ['name' => self::RENAMED]],
            'approval-rules' => [new ApprovalRulesController('api/v1/approval-rules', $app), ApprovalRule::tableName(), (int)$this->rule->id, ['name' => self::RENAMED]],
            'users' => [new UsersController('api/v1/users', $app), User::tableName(), (int)$this->createUser('lists-target')->id, ['username' => self::RENAMED]],
        };
        $this->sendJson($this->json($body, $field, $value), 'PUT');

        return [
            static fn (): mixed => $ctrl->actionUpdate($id),
            static fn (): mixed => [
                (new Query())->from($table)->where(['id' => $id])->one(),
                (new Query())->from('{{%job_template_credential}}')->where(['job_template_id' => $id])->all(),
            ],
        ];
    }

    /**
     * The POST $request with its ID or number set to the JSON text $json:
     * the request, and what it could write.
     *
     * @return array{0: callable(): mixed, 1: callable(): mixed}
     */
    private function apiPost(string $request, string $json): array
    {
        $app = $this->app();
        $team = (int)$this->createTeam((int)$this->admin->id)->id;
        $runner = $this->createRunner((int)$this->group->id, (int)$this->admin->id);
        $job = $this->runningJob($runner);
        // What a report writes. Every report, refused or not, marks the job's progress
        // (last_progress_at): the liveness signal of the runner API (findOwnedJob()).
        $runnerRows = static fn (): mixed => [
            (new Query())->from('{{%job_log}}')->where(['job_id' => $job->id])->all(),
            (new Query())->from('{{%job_task}}')->where(['job_id' => $job->id])->all(),
            (new Query())->select(['status', 'exit_code', 'finished_at'])->from(Job::tableName())->where(['id' => $job->id])->one(),
        ];
        $task = '{"tasks": [{"seq": %s, "name": "ping", "action": "ping", "host": "localhost", "status": "ok", "changed": false, "duration_ms": %s}]}';

        return match ($request) {
            'team member' => $this->sent('{"user_id": ' . $json . '}', static fn (): mixed => (new TeamsController('api/v1/teams', $app))->actionAddMember($team), static fn (): mixed => (new Query())->from('{{%team_member}}')->where(['team_id' => $team])->count()),
            'team project' => $this->sent('{"project_id": ' . $json . '}', static fn (): mixed => (new TeamsController('api/v1/teams', $app))->actionAddProject($team), static fn (): mixed => (new Query())->from('{{%team_project}}')->where(['team_id' => $team])->count()),
            'runner move' => $this->sent('{"target_group_id": ' . $json . '}', static fn (): mixed => (new RunnersController('api/v1/runners', $app))->actionMove((int)$runner->id), static fn (): mixed => (new Query())->from(Runner::tableName())->where(['id' => $runner->id])->one()),
            'runner log sequence' => $this->sent('{"stream": "stdout", "content": "PLAY [all]", "sequence": ' . $json . '}', $this->runnerRequest($runner, 'logs', $job), $runnerRows),
            'runner exit code' => $this->sent('{"exit_code": ' . $json . '}', $this->runnerRequest($runner, 'complete', $job), $runnerRows),
            'runner task seq' => $this->sent(sprintf($task, $json, '5'), $this->runnerRequest($runner, 'tasks', $job), $runnerRows),
            'runner task duration' => $this->sent(sprintf($task, '1', $json), $this->runnerRequest($runner, 'tasks', $job), $runnerRows),
        };
    }

    /**
     * Sends $json as the body of a POST: the request $run and what it could write, $stored.
     *
     * @param callable(): mixed $run
     * @param callable(): mixed $stored
     * @return array{0: callable(): mixed, 1: callable(): mixed}
     */
    private function sent(string $json, callable $run, callable $stored): array
    {
        $this->sendJson($json, 'POST');

        return [$run, $stored];
    }

    /**
     * A report of $runner on $job to the runner API's $action, signed in
     * with the runner's token.
     *
     * @return callable(): mixed
     */
    private function runnerRequest(Runner $runner, string $action, Job $job): callable
    {
        $token = Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false);
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $app = $this->app();

        return static fn (): mixed => (new RunnerJobsController('api/runner/v1/jobs', $app))->runAction($action, ['id' => $job->id]);
    }

    private function runningJob(Runner $runner): Job
    {
        $job = $this->createJob((int)$this->template->id, (int)$this->admin->id, Job::STATUS_RUNNING);
        $job->runner_id = $runner->id;
        $job->started_at = time();
        $job->save(false);

        return $job;
    }

    /**
     * Runs $run: its answer and its status are $answer and $status, and
     * nothing was written ($stored is as before), audited or logged.
     *
     * @param callable(): mixed $run
     * @param callable(): mixed $stored
     * @param array<string, mixed> $answer
     */
    private function assertAnsweredWithoutWriting(callable $run, callable $stored, int $status, array $answer): void
    {
        $before = $stored();
        $auditBefore = $this->lastAuditId();

        [$result, $problems] = $this->logged($run);

        $this->assertSame($answer, $result);
        $this->assertSame($status, \Yii::$app->response->statusCode);
        $this->assertSame($before, $stored(), 'nothing was written');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    // -- Web posts ---------------------------------------------------------------

    /**
     * A web controller of $form that renders the real views and keeps what
     * it rendered (CapturesRenderedForm).
     */
    private function formController(string $form): Controller
    {
        $app = $this->app();

        return match ($form) {
            'job-template' => new class ('job-template', $app) extends JobTemplateController {
                use CapturesRenderedForm;
            },
            'inventory' => new class ('inventory', $app) extends InventoryController {
                use CapturesRenderedForm;
            },
            'project' => new class ('project', $app) extends ProjectController {
                use CapturesRenderedForm;
            },
            'approval-rule' => new class ('approval-rule', $app) extends ApprovalRuleController {
                use CapturesRenderedForm;
            },
            'schedule' => new class ('schedule', $app) extends ScheduleController {
                use CapturesRenderedForm;
            },
        };
    }

    /**
     * The create form of $form posted with $field set to the JSON text
     * $json: the request, and what it could write.
     *
     * @return array{0: callable(): mixed, 1: callable(): mixed}
     */
    private function formCreate(Controller $ctrl, string $form, string $field, string $json): array
    {
        [$formName, $table, $fields] = match ($form) {
            'job-template' => ['JobTemplate', JobTemplate::tableName(), $this->templateFields(self::CREATED)],
            'inventory' => ['Inventory', Inventory::tableName(), ['name' => self::CREATED, 'inventory_type' => 'static', 'content' => self::CONTENT]],
            'project' => ['Project', Project::tableName(), ['name' => self::CREATED, 'scm_type' => 'manual']],
            'approval-rule' => ['ApprovalRule', ApprovalRule::tableName(), ['name' => self::CREATED, 'approver_type' => 'role', 'approver_config' => '{"role": "operator"}']],
            'schedule' => ['Schedule', Schedule::tableName(), $this->scheduleFields(self::CREATED)],
        };
        $this->sendJson($this->json([$formName => $fields], $field, $json, $formName), 'POST');

        return [
            static fn (): mixed => $ctrl->actionCreate(),
            static fn (): mixed => (new Query())->from($table)->where(['name' => self::CREATED])->count(),
        ];
    }

    /**
     * The edit form of a record of $form posted, renamed, with $field set to
     * the JSON text $json: the request, and the record's row.
     *
     * @return array{0: callable(): mixed, 1: callable(): mixed}
     */
    private function formUpdate(Controller $ctrl, string $form, string $field, string $json): array
    {
        [$formName, $table, $id] = match ($form) {
            'job-template' => ['JobTemplate', JobTemplate::tableName(), (int)$this->template->id],
            'inventory' => ['Inventory', Inventory::tableName(), (int)$this->inventory->id],
            'project' => ['Project', Project::tableName(), (int)$this->project->id],
            'approval-rule' => ['ApprovalRule', ApprovalRule::tableName(), (int)$this->rule->id],
            'schedule' => ['Schedule', Schedule::tableName(), $this->scheduleId()],
        };
        $posted = [$formName => ['name' => self::RENAMED]];
        if ($form === 'job-template') {
            // As the edit form of a gated template posts: the stored additional credentials stay.
            $posted[JobTemplateController::KEEP_CREDENTIALS] = '1';
        }
        $this->sendJson($this->json($posted, $field, $json, $formName), 'POST');

        return [
            static fn (): mixed => $ctrl->actionUpdate($id),
            static fn (): mixed => (new Query())->from($table)->where(['id' => $id])->one(),
        ];
    }

    /**
     * Posts $post with its ID set to the JSON text $json and asserts that it
     * named nothing: the answer of a missing ID, and nothing written.
     */
    private function webPost(string $post, string $json): void
    {
        match ($post) {
            'team add-member', 'team add-project' => $this->assertTeamPostRefused($post, $json),
            'runner create' => $this->assertRunnerPostRefused('{"name": "' . self::CREATED . '", "group_id": ' . $json . '}', null, 'Runner group not found.'),
            'runner move' => $this->assertRunnerPostRefused(
                '{"target_group_id": ' . $json . '}',
                $this->createRunner((int)$this->group->id, (int)$this->admin->id),
                'Target runner group not found.'
            ),
            'workflow launch', 'workflow move-step', 'workflow remove-step' => $this->assertWorkflowPostRefused($post, $json),
        };
    }

    private function assertTeamPostRefused(string $post, string $json): void
    {
        $team = (int)$this->createTeam((int)$this->admin->id)->id;
        $ctrl = new TeamController('team', $this->app());
        [$field, $message, $table] = $post === 'team add-member'
            ? ['user_id', TeamController::USER_ID_REQUIRED, '{{%team_member}}']
            : ['project_id', TeamController::PROJECT_ID_REQUIRED, '{{%team_project}}'];
        $this->sendJson('{"' . $field . '": ' . $json . '}', 'POST');

        try {
            $post === 'team add-member' ? $ctrl->actionAddMember($team) : $ctrl->actionAddProject($team);
            $this->fail('400 for a ' . $field . ' that is no ID');
        } catch (BadRequestHttpException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame(0, (int)(new Query())->from($table)->where(['team_id' => $team])->count(), 'nothing was added');
    }

    private function assertRunnerPostRefused(string $body, ?Runner $runner, string $flash): void
    {
        $ctrl = new class ('runner', $this->app()) extends RunnerController {
            use CapturesRenderedForm;
        };
        $this->sendJson($body, 'POST');

        $runner === null ? $ctrl->actionCreate() : $ctrl->actionMove((int)$runner->id);

        $this->assertNotNull($ctrl->redirectedTo, 'back to the runner groups');
        $this->assertSame(['danger' => $flash], \Yii::$app->session->getAllFlashes());
        if ($runner === null) {
            $this->assertFalse(Runner::find()->where(['name' => self::CREATED])->exists(), 'no runner was registered');

            return;
        }
        $this->assertSame((int)$this->group->id, Runner::findOne($runner->id)?->runner_group_id, 'the runner stays in its group');
    }

    private function assertWorkflowPostRefused(string $post, string $json): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $ctrl = new class ('workflow-template', $this->app()) extends WorkflowTemplateController {
            use CapturesRenderedForm;
        };
        $field = $post === 'workflow launch' ? 'id' : 'step_id';
        $this->sendJson('{"' . $field . '": ' . $json . ', "direction": "down"}', 'POST');

        try {
            match ($post) {
                'workflow launch' => $ctrl->actionLaunch(),
                'workflow move-step' => $ctrl->actionMoveStep((int)$workflow->id),
                'workflow remove-step' => $ctrl->actionRemoveStep((int)$workflow->id),
            };
            $this->assertSame('workflow remove-step', $post, 'only a removal ignores a step it cannot find');
            $this->assertSame([], \Yii::$app->session->getAllFlashes(), 'nothing was flashed');
        } catch (NotFoundHttpException $e) {
            $expected = $post === 'workflow launch'
                ? 'Workflow template #0 not found.'
                : sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, $workflow->id, 0);
            $this->assertSame($expected, $e->getMessage());
        }
        $this->assertTrue(WorkflowStep::find()->where(['id' => $step->id, 'step_order' => 1])->exists(), 'the step stays');
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists(), 'nothing was launched');
    }

    // -- Helpers -----------------------------------------------------------------

    /**
     * The fields of a valid job template, named $name.
     *
     * @return array<string, mixed>
     */
    private function templateFields(string $name): array
    {
        return [
            'name' => $name,
            'project_id' => (int)$this->project->id,
            'inventory_id' => (int)$this->inventory->id,
            'playbook' => 'site.yml',
            'runner_group_id' => (int)$this->group->id,
        ];
    }

    /**
     * The fields of a valid schedule of the job template, named $name.
     *
     * @return array<string, mixed>
     */
    private function scheduleFields(string $name): array
    {
        return ['name' => $name, 'cron_expression' => '0 3 * * *', 'timezone' => 'UTC', 'job_template_id' => (int)$this->template->id];
    }

    /** A schedule of the job template. */
    private function scheduleId(): int
    {
        $schedule = new Schedule();
        $schedule->name = 'lists-schedule';
        $schedule->job_template_id = (int)$this->template->id;
        $schedule->cron_expression = '0 * * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = (int)$this->admin->id;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        return (int)$schedule->id;
    }

    /**
     * The JSON text of $fields with $field set to the JSON text $value, a
     * value json_encode() cannot write as such: among the fields of the form
     * $formName when one is named, else at the top.
     *
     * @param array<string, mixed> $fields
     */
    private function json(array $fields, string $field, string $value, ?string $formName = null): string
    {
        if ($formName === null) {
            $fields[$field] = '@value@';
        } else {
            $this->assertIsArray($fields[$formName]);
            $fields[$formName][$field] = '@value@';
        }

        return str_replace('"@value@"', $value, (string)json_encode($fields));
    }

    /**
     * Sends the JSON text $json as the body of a $method request, read by
     * the JSON parser when the action reads it.
     */
    private function sendJson(string $json, string $method): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->parsers = ['application/json' => JsonParser::class];
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody($json);
        $request->setBodyParams(null);
        $this->assertIsArray($request->getBodyParams(), 'the parser read the body');
        $_SERVER['REQUEST_METHOD'] = $method;
        \Yii::$app->response->statusCode = 200;
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function app(): \yii\base\Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }
}
