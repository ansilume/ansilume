<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\BaseController;
use app\controllers\JobTemplateController;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\User;
use app\services\GatedJobTemplateLock;
use app\services\LintService;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\JsonParser;
use yii\web\NotFoundHttpException;
use yii\web\Request;
use yii\web\Response;
use yii\web\View;

/**
 * Regression: the job template form posted as JSON, which the request's
 * parser reads for every request (a signed-in user sends the CSRF token as a
 * header), with a number beyond the int range (1e30, 9223372036854775808,
 * -1e30, or 1e999, which a JSON parser reads as infinity) in an integer
 * field: on PHP 8.5 an (int) cast of it warns, the error handler turns the
 * warning into an exception, and the page answered 500. The casts were in
 * the access check of the submitted project, the template lock, the
 * credential service, the approval gate, the re-rendered form (the primary
 * and the checked additional credentials) and the launch's template ID.
 *
 * Now the form is shown again with the field's error and nothing is saved;
 * the lock of a gated template refuses such a number first for users who
 * may not change what the template runs (403), except in the project, whose
 * error comes before the access check and the lock; a launch of such an ID
 * answers 404. Each body is a literal JSON text, read by the request's JSON
 * parser, so the values are the floats a request gives; the form is
 * rendered with the real views.
 */
class JobTemplateControllerIntRangeTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** JSON numbers beyond the int range, as a client writes them. */
    private const NUMBERS = ['1e30', '9223372036854775808', '-1e30', '1e999'];

    /** The integer fields of the form, with the message of each one's integer rule. */
    private const MESSAGES = [
        'project_id' => 'Project Id must be an integer.',
        'inventory_id' => 'Inventory Id must be an integer.',
        'credential_id' => 'Credential Id must be an integer.',
        'runner_group_id' => 'Runner must be an integer.',
        'verbosity' => 'Verbosity must be an integer.',
        'forks' => 'Forks must be an integer.',
        'timeout_minutes' => 'Timeout Minutes must be an integer.',
        'approval_rule_id' => 'Approval rule must be an integer.',
    ];

    /** @var array<string, mixed> component definitions the test replaced */
    private array $replaced = [];

    private User $admin;
    private Project $project;
    private Inventory $inventory;
    private RunnerGroup $group;
    private Credential $credential;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replace('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        $this->admin = $this->createUserWithRole('ir-admin', 'admin');
        $ownerId = (int)$this->admin->id;
        $this->project = $this->createProject($ownerId);
        $this->inventory = $this->createInventory($ownerId);
        $this->group = $this->createRunnerGroup($ownerId);
        $this->credential = $this->createCredential($ownerId);
    }

    protected function tearDown(): void
    {
        foreach ($this->replaced as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replaced = [];
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function beyondTheIntRangeProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::MESSAGES) as $field) {
            foreach (self::NUMBERS as $number) {
                $cases["{$field}: {$number}"] = [$field, $number];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testACreateWithANumberBeyondTheIntRangeShowsTheFormWithTheError(string $field, string $number): void
    {
        $this->loginAs($this->admin);
        $name = 'ir-create-' . uniqid('', true);
        $audits = $this->templateAuditCount();
        $this->postJsonText($this->json(['JobTemplate' => $this->formFields($name)], $field, $number), $field);

        $html = $this->rendered(static fn (JobTemplateController $ctrl): mixed => $ctrl->actionCreate());

        $this->assertStringContainsString('id="jt-form"', $html, 'the form is shown again');
        $this->assertStringContainsString(Html::encode(self::MESSAGES[$field]), $html);
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'no template was created');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnUpdateWithANumberBeyondTheIntRangeShowsTheFormWithTheError(string $field, string $number): void
    {
        $template = $this->template(null);
        $before = $this->fresh($template)->getAttributes();
        $audits = $this->templateAuditCount();
        $this->loginAs($this->admin);
        $fields = ['name' => 'ir-renamed-' . uniqid('', true)];
        $this->postJsonText($this->json(['JobTemplate' => $fields, JobTemplateController::KEEP_CREDENTIALS => '1'], $field, $number), $field);

        $html = $this->rendered(static fn (JobTemplateController $ctrl): mixed => $ctrl->actionUpdate((int)$template->id));

        $this->assertStringContainsString('id="jt-form"', $html, 'the form is shown again');
        $this->assertStringContainsString(Html::encode(self::MESSAGES[$field]), $html);
        $this->assertSame($before, $this->fresh($template)->getAttributes(), 'not even the name was saved');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
    }

    /**
     * The form shows the field of such a number empty, with its error; a
     * whole number of the same submission, a whole float among them, as it
     * was posted.
     */
    public function testTheFieldOfSuchANumberIsShownEmptyAndAWholeNumberAsPosted(): void
    {
        $this->loginAs($this->admin);
        $fields = $this->formFields('ir-shown-' . uniqid('', true)) + ['forks' => 7.0];
        $this->postJsonText($this->json(['JobTemplate' => $fields], 'timeout_minutes', '1e30'), 'timeout_minutes');

        $html = $this->rendered(static fn (JobTemplateController $ctrl): mixed => $ctrl->actionCreate());

        $this->assertStringContainsString('Timeout Minutes must be an integer.', $html);
        $this->assertDoesNotMatchRegularExpression('/name="JobTemplate\[timeout_minutes\]" value=/', $html, 'shown empty');
        $this->assertMatchesRegularExpression('/name="JobTemplate\[forks\]" value="7"/', $html, 'shown as posted');
    }

    /**
     * For a user who may not change what a gated template runs, such a
     * number is a change of the field like any value but the stored one,
     * become included: the lock refuses it before validation. The project
     * is refused before the lock (testTheProjectsErrorComesBeforeTheLock()).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lockedFieldProvider(): array
    {
        $cases = [];
        foreach (['inventory_id', 'credential_id', 'runner_group_id', 'verbosity', 'forks', 'timeout_minutes', 'become'] as $field) {
            foreach (self::NUMBERS as $number) {
                $cases["{$field}: {$number}"] = [$field, $number];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider lockedFieldProvider
     */
    public function testTheLockOfAGatedTemplateRefusesSuchANumberFirst(string $field, string $number): void
    {
        $operator = $this->createUserWithRole('ir-operator', 'operator');
        $template = $this->template((int)$this->createApprovalRule((int)$this->admin->id)->id);
        $before = $this->fresh($template)->getAttributes();
        $this->loginAs($operator);
        $fields = ['name' => 'ir-forged-' . uniqid('', true)];
        $this->postJsonText($this->json(['JobTemplate' => $fields, JobTemplateController::KEEP_CREDENTIALS => '1'], $field, $number), $field);

        try {
            $this->rendered(static fn (JobTemplateController $ctrl): mixed => $ctrl->actionUpdate((int)$template->id));
            $this->fail('the lock refuses the change');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, $e->getMessage());
        }
        $this->assertSame($before, $this->fresh($template)->getAttributes());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function numberProvider(): array
    {
        return array_combine(self::NUMBERS, array_map(static fn (string $number): array => [$number], self::NUMBERS));
    }

    /**
     * A project beyond the int range names no project the access check
     * could clear: its integer rule refuses it before the check and before
     * the lock of a gated template, also for a user the lock applies to, and
     * the form shows the error; nothing is saved.
     *
     * @dataProvider numberProvider
     */
    public function testTheProjectsErrorComesBeforeTheLock(string $number): void
    {
        $operator = $this->createUserWithRole('ir-project-operator', 'operator');
        $template = $this->template((int)$this->createApprovalRule((int)$this->admin->id)->id);
        $before = $this->fresh($template)->getAttributes();
        $this->loginAs($operator);
        $fields = ['name' => 'ir-forged-' . uniqid('', true)];
        $this->postJsonText($this->json(['JobTemplate' => $fields, JobTemplateController::KEEP_CREDENTIALS => '1'], 'project_id', $number), 'project_id');

        $html = $this->rendered(static fn (JobTemplateController $ctrl): mixed => $ctrl->actionUpdate((int)$template->id));

        $this->assertStringContainsString(Html::encode(self::MESSAGES['project_id']), $html);
        $this->assertSame($before, $this->fresh($template)->getAttributes());
    }

    /**
     * The checked additional credentials of a rejected submission are shown
     * again: an ID beyond the int range is none (no box is checked), the
     * others stay checked.
     */
    public function testRejectedCredentialIdsBeyondTheIntRangeAreShownUnchecked(): void
    {
        $other = $this->createCredential((int)$this->admin->id);
        $this->loginAs($this->admin);
        $name = 'ir-credentials-' . uniqid('', true);
        foreach (self::NUMBERS as $number) {
            $json = (string)json_encode(['JobTemplate' => $this->formFields($name), 'credential_ids' => ['@a@', '@b@']]);
            $this->postJsonText(str_replace(['"@a@"', '"@b@"'], [(string)$other->id, $number], $json));

            $html = $this->rendered(static fn (JobTemplateController $ctrl): mixed => $ctrl->actionCreate());

            $this->assertStringContainsString(Html::encode('credential_ids must contain positive integer credential IDs.'), $html, $number);
            preg_match_all('/name="credential_ids\[\]" value="(\d+)"\s+id="cred-extra-\d+"\s+checked/', $html, $checked);
            $this->assertSame([(string)$other->id], $checked[1], 'only the other one is checked');
        }
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists());
    }

    /**
     * POST /job-template/launch with an id beyond the int range: no
     * template has it, so the answer is the 404 of an unknown ID, and
     * nothing is launched.
     */
    public function testALaunchOfAnIdBeyondTheIntRangeIs404(): void
    {
        $template = $this->template(null);
        $this->loginAs($this->admin);
        foreach (self::NUMBERS as $number) {
            $this->postJsonText('{"id": ' . $number . '}', 'id');
            try {
                $this->rendered(static fn (JobTemplateController $ctrl): mixed => $ctrl->actionLaunch());
                $this->fail('no template has the ID ' . $number);
            } catch (NotFoundHttpException $e) {
                $this->assertSame('Job template #0 not found.', $e->getMessage());
            }
        }
        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists(), 'nothing was launched');
    }

    /**
     * A JSON post with whole numbers keeps its answer: the template is
     * saved with them and the browser goes to its page.
     */
    public function testAJsonPostWithWholeNumbersStillSaves(): void
    {
        $this->loginAs($this->admin);
        $name = 'ir-whole-' . uniqid('', true);
        $fields = $this->formFields($name) + ['credential_id' => (string)$this->credential->id, 'forks' => 7.0, 'timeout_minutes' => '30'];
        $this->postJsonText((string)json_encode(['JobTemplate' => $fields]));
        $ctrl = $this->makeController();

        $ctrl->actionCreate();

        $stored = JobTemplate::find()->where(['name' => $name])->one();
        $this->assertInstanceOf(JobTemplate::class, $stored);
        $this->assertSame(['view', 'id' => $stored->id], $ctrl->capturedRedirect);
        $this->assertSame(
            [(int)$this->project->id, (int)$this->inventory->id, (int)$this->credential->id, (int)$this->group->id, 7, 30],
            [$stored->project_id, $stored->inventory_id, $stored->credential_id, $stored->runner_group_id, $stored->forks, $stored->timeout_minutes]
        );
    }

    /**
     * The access check reads the submitted project as the save stores it
     * (SubmittedId): a project the user may not operate in is still a 403;
     * a value that is no ID, which the (int) cast had made the ID it starts
     * with, is refused by the integer rule before the check, which never
     * takes it for no project, so nothing is saved there either.
     */
    public function testTheSubmittedProjectIsCheckedAsTheSaveStoresIt(): void
    {
        ['member' => $member, 'foreign' => $foreign] = $this->teamScope();
        $foreignProject = (int)$foreign->project_id;
        $this->loginAs($member);
        $name = 'ir-foreign-' . uniqid('', true);
        foreach (["{$foreignProject}", "\"{$foreignProject}\"", "{$foreignProject}.0"] as $value) {
            $this->postJsonText($this->json(['JobTemplate' => $this->formFields($name)], 'project_id', $value));
            try {
                $this->rendered(static fn (JobTemplateController $ctrl): mixed => $ctrl->actionCreate());
                $this->fail('the project is foreign: ' . $value);
            } catch (ForbiddenHttpException $e) {
                $this->assertSame(BaseController::MODIFY_FORBIDDEN, $e->getMessage());
            }
        }
        foreach (["{$foreignProject}.5", "\"{$foreignProject}.5\"", "\" {$foreignProject}\""] as $value) {
            $this->postJsonText($this->json(['JobTemplate' => $this->formFields($name)], 'project_id', $value));
            $html = $this->rendered(static fn (JobTemplateController $ctrl): mixed => $ctrl->actionCreate());
            $this->assertStringContainsString('Project Id must be an integer.', $html, $value);
        }
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'nothing was created');
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * The fields of a valid form submission.
     *
     * @return array<string, mixed>
     */
    private function formFields(string $name): array
    {
        return [
            'name' => $name,
            'project_id' => (string)$this->project->id,
            'inventory_id' => (string)$this->inventory->id,
            'playbook' => 'site.yml',
            'runner_group_id' => (string)$this->group->id,
        ];
    }

    /**
     * The JSON text of $body with JobTemplate[$field] set to $value as
     * written: a JSON number that PHP could not encode itself.
     *
     * @param array<string, mixed> $body
     */
    private function json(array $body, string $field, string $value): string
    {
        /** @var array<string, mixed> $fields */
        $fields = $body['JobTemplate'];
        $fields[$field] = '@value@';
        $body['JobTemplate'] = $fields;

        return str_replace('"@value@"', $value, (string)json_encode($body));
    }

    /**
     * Posts $json as the request body, read by the JSON parser when the
     * action reads it; $floatField names a field (of JobTemplate, or id) the
     * parser must give as a float (the premise of the test).
     */
    private function postJsonText(string $json, ?string $floatField = null): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->parsers = ['application/json' => JsonParser::class];
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody($json);
        $request->setBodyParams(null);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        if ($floatField !== null) {
            $body = $request->getBodyParams();
            $this->assertIsArray($body);
            $value = $floatField === 'id' ? $body['id'] : $body['JobTemplate'][$floatField];
            $this->assertIsFloat($value, 'the parser gives a float');
        }
    }

    /**
     * A template in the fixtures' project, inventory and runner group, with
     * the fixtures' credential as its primary one; gated by $ruleId unless
     * it is null.
     */
    private function template(?int $ruleId): JobTemplate
    {
        $ownerId = (int)$this->admin->id;
        $template = $this->createJobTemplate((int)$this->project->id, (int)$this->inventory->id, (int)$this->group->id, $ownerId);
        JobTemplate::updateAll(['approval_rule_id' => $ruleId, 'credential_id' => $this->credential->id], ['id' => $template->id]);
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => $template->id,
            'credential_id' => $this->credential->id,
            'sort_order' => 0,
        ])->execute();

        return $this->fresh($template);
    }

    private function fresh(JobTemplate $template): JobTemplate
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function templateAuditCount(): int
    {
        return (int)AuditLog::find()
            ->where(['action' => [AuditLog::ACTION_TEMPLATE_CREATED, AuditLog::ACTION_TEMPLATE_UPDATED]])
            ->count();
    }

    private function replace(string $id, object $replacement): void
    {
        if (!array_key_exists($id, $this->replaced)) {
            $this->replaced[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $replacement);
    }

    /**
     * The page $render renders, with the real views and without the layout.
     *
     * @param callable(JobTemplateController): mixed $render
     */
    private function rendered(callable $render): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new class ('job-template', $this->app()) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        \Yii::$app->controller = $ctrl;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setUrl('/job-template/update');
        try {
            $html = $render($ctrl);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
        $this->assertIsString($html);

        return $html;
    }

    private function app(): \yii\base\Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }

    /**
     * @return JobTemplateController&object{capturedRedirect: mixed}
     */
    private function makeController(): JobTemplateController
    {
        return new class ('job-template', $this->app()) extends JobTemplateController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            /**
             * @param array<mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                return new Response();
            }
        };
    }
}
