<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\ErrorText;
use app\controllers\JobTemplateController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedJobTemplateLock;
use app\services\JobLaunchService;
use app\services\LintService;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\Model;
use yii\helpers\Html;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * What a gated job template runs is for admins, in the web UI: an operator
 * renames it through the locked form (name and description only, every
 * other value kept), any other change is a 403 with
 * GatedJobTemplateLock::CHANGE_REFUSED and saves nothing, the page and the
 * form tell why, and an admin changes everything.
 */
class JobTemplateControllerGatedLockTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const VARS = '{"env": "prod"}';
    private const SURVEY = '[{"name":"region","label":"Region","type":"text","required":true,"default":"eu","options":[],"hint":""}]';

    /** @var array<string, mixed> component definitions the test replaced */
    private array $replaced = [];

    private User $admin;
    private User $operator;
    private ApprovalRule $rule;
    private JobTemplate $template;

    /** @var array<string, Credential> the gated template's credentials: P (primary), A and B */
    private array $credentials = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->replace('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        $this->admin = $this->createUserWithRole('gl-admin', 'admin');
        $this->operator = $this->createUserWithRole('gl-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        foreach (['P', 'A', 'B'] as $symbol) {
            $this->credentials[$symbol] = $this->createCredential((int)$this->admin->id);
        }
        $this->template = $this->template((int)$this->rule->id);
    }

    protected function tearDown(): void
    {
        foreach ($this->replaced as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replaced = [];
        parent::tearDown();
    }

    // -- Name and description stay open --------------------------------------------

    /**
     * The locked form posts the name, the description and KEEP_CREDENTIALS
     * (its other fields are disabled): every other value stays as stored.
     */
    public function testAnOperatorRenamesAGatedTemplateThroughTheLockedFormAndKeepsEverythingElse(): void
    {
        $before = $this->snapshot($this->template);
        $this->loginAs($this->operator);
        $name = 'renamed-' . uniqid('', true);
        $this->setPost(['JobTemplate' => ['name' => $name, 'description' => 'Renamed.'], JobTemplateController::KEEP_CREDENTIALS => '1']);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(['view', 'id' => $this->template->id], $ctrl->capturedRedirect);
        $stored = $this->fresh($this->template);
        $this->assertSame($name, $stored->name);
        $this->assertSame('Renamed.', $stored->description);
        $this->assertSame($before, $this->snapshot($stored), 'the playbook, extra vars, survey, credentials and the rest stay');
        $this->assertArrayNotHasKey('credentials', $this->lastContext(AuditLog::ACTION_TEMPLATE_UPDATED), 'no credential change');
    }

    /**
     * A field the form disables is not posted; one sent with its stored
     * value is no change either.
     */
    public function testValuesSentAsStoredAreNoChange(): void
    {
        $this->loginAs($this->operator);
        $name = 'resent-' . uniqid('', true);
        $this->setPost([
            'JobTemplate' => [
                'name' => $name,
                'playbook' => 'site.yml',
                'project_id' => (string)$this->template->project_id,
                'credential_id' => (string)$this->credentials['P']->id,
                'extra_vars' => "{\n  \"env\": \"prod\"\n}",
                'become' => '0',
            ],
            'credential_ids' => [(string)$this->credentials['A']->id, (string)$this->credentials['B']->id],
        ]);

        $this->makeController()->actionUpdate((int)$this->template->id);

        $this->assertSame($name, $this->fresh($this->template)->name);
    }

    // -- Every other change is for admins ------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function lockedChangeProvider(): array
    {
        return [
            'the extra vars' => ['extra_vars'],
            'the playbook' => ['playbook'],
            'the inventory' => ['inventory'],
            'the runner group' => ['runner_group'],
            'the survey' => ['survey'],
            'the credential list, another order' => ['credential_order'],
            'the credential list, one removed' => ['credential_removed'],
            'the credential list, another credential' => ['credential_other'],
            'the credential list, none (no field and no KEEP_CREDENTIALS)' => ['credential_none'],
            'the primary credential' => ['primary'],
            'become' => ['become'],
        ];
    }

    /**
     * A forged post (the locked form disables these fields) is refused with
     * 403 before anything is saved, the rename sent with it too.
     *
     * @dataProvider lockedChangeProvider
     */
    public function testAnyOtherChangeOfAGatedTemplateIsA403AndSavesNothing(string $change): void
    {
        $before = $this->snapshot($this->template);
        $audits = $this->auditCount();
        $this->loginAs($this->operator);
        $this->setPost($this->forgedPost($change));

        try {
            $this->makeController()->actionUpdate((int)$this->template->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, $e->getMessage());
        }

        $stored = $this->fresh($this->template);
        $this->assertSame($this->template->name, $stored->name, 'not even the rename is saved');
        $this->assertSame($before, $this->snapshot($stored));
        $this->assertSame($audits, $this->auditCount(), 'no audit entry');
    }

    public function testAnAdminChangesWhatAGatedTemplateRuns(): void
    {
        $this->loginAs($this->admin);
        $other = $this->createCredential((int)$this->admin->id);
        $this->setPost(array_merge_recursive($this->forgedPost('extra_vars'), [
            'JobTemplate' => ['playbook' => 'admin.yml', 'survey_fields' => '[]'],
            'credential_ids' => [(string)$other->id],
        ]));

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(['view', 'id' => $this->template->id], $ctrl->capturedRedirect);
        $stored = $this->fresh($this->template);
        $this->assertSame('admin.yml', $stored->playbook);
        $this->assertSame('{"chosen_by": "operator"}', $stored->extra_vars);
        $this->assertSame('[]', $stored->survey_fields);
        $this->assertSame([(int)$this->credentials['P']->id, (int)$other->id], array_column($stored->credentialSnapshot(), 'id'));
    }

    /**
     * The lock is read when the template is saved: a template that gets a
     * rule later is locked from then on.
     */
    public function testATemplateThatGetsARuleLaterIsLocked(): void
    {
        $template = $this->template(null);
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => ['playbook' => 'before-the-rule.yml'], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $this->makeController()->actionUpdate((int)$template->id);
        $this->assertSame('before-the-rule.yml', $this->fresh($template)->playbook, 'without a rule, operators change it');

        JobTemplate::updateAll(['approval_rule_id' => $this->rule->id], ['id' => $template->id]);
        $this->setPost(['JobTemplate' => ['playbook' => 'after-the-rule.yml'], JobTemplateController::KEEP_CREDENTIALS => '1']);
        $this->expectExceptionObject(new ForbiddenHttpException(GatedJobTemplateLock::CHANGE_REFUSED));
        $this->makeController()->actionUpdate((int)$template->id);
    }

    /**
     * The decision is taken on the template row, locked in the save's
     * transaction: a rule assigned while the request runs (here: during its
     * validation) is seen, and nothing is saved.
     */
    public function testARuleAssignedWhileTheSaveRunsIsSeen(): void
    {
        $template = $this->template(null);
        $assign = function (Event $event) use ($template): void {
            if ($event->sender instanceof JobTemplate && (int)$event->sender->id === (int)$template->id) {
                JobTemplate::updateAll(['approval_rule_id' => $this->rule->id], ['id' => $template->id]);
            }
        };
        Event::on(JobTemplate::class, Model::EVENT_AFTER_VALIDATE, $assign);
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => ['playbook' => 'raced.yml'], JobTemplateController::KEEP_CREDENTIALS => '1']);

        try {
            $this->makeController()->actionUpdate((int)$template->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, $e->getMessage());
        } finally {
            Event::off(JobTemplate::class, Model::EVENT_AFTER_VALIDATE, $assign);
        }

        $this->assertSame('site.yml', $this->fresh($template)->playbook);
    }

    public function testARuleChangeIsRefusedBeforeTheLock(): void
    {
        $other = $this->createApprovalRule((int)$this->admin->id);
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => ['approval_rule_id' => (string)$other->id, 'playbook' => 'other.yml']]);

        $this->expectExceptionObject(new ForbiddenHttpException(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED));
        $this->makeController()->actionUpdate((int)$this->template->id);
    }

    public function testAnInvalidNameOnTheLockedFormIsAValidationError(): void
    {
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => ['name' => ''], JobTemplateController::KEEP_CREDENTIALS => '1']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame('rendered:form', $result);
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(JobTemplate::class, $model);
        $this->assertSame(['Name cannot be blank.'], $model->getErrors('name'));
        $this->assertTrue($ctrl->capturedParams['changeLocked'] ?? null, 'the form is shown locked again');
        $this->assertSame(
            [(int)$this->credentials['A']->id, (int)$this->credentials['B']->id],
            $ctrl->capturedParams['selectedCredentialIds'] ?? null,
            'with the stored additional credentials'
        );
    }

    // -- Page and form ------------------------------------------------------------

    public function testTheLockedFormDisablesEverythingButNameAndDescription(): void
    {
        $this->loginAs($this->operator);

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionUpdate((int)$this->template->id));

        $this->assertStringContainsString(
            '<div class="alert alert-info" id="job-template-locked-notice">' . Html::encode(GatedJobTemplateLock::NOTICE) . '</div>',
            $html
        );
        $this->assertStringContainsString('<fieldset id="job-template-run-fields" disabled>', $html);
        $this->assertStringContainsString(Html::hiddenInput(JobTemplateController::KEEP_CREDENTIALS, '1'), $html);
        $start = (int)strpos($html, '<fieldset id="job-template-run-fields"');
        $end = (int)strpos($html, '</fieldset>', $start);
        $fieldset = substr($html, $start, $end - $start);
        foreach (
            ['JobTemplate[project_id]', 'JobTemplate[playbook]', 'JobTemplate[inventory_id]', 'JobTemplate[credential_id]', 'credential_ids[]',
            'JobTemplate[extra_vars]', 'JobTemplate[runner_group_id]', 'JobTemplate[timeout_minutes]', 'JobTemplate[verbosity]',
            'JobTemplate[forks]', 'JobTemplate[limit]', 'JobTemplate[tags]', 'JobTemplate[skip_tags]', 'JobTemplate[become]',
            'JobTemplate[become_method]', 'JobTemplate[become_user]', 'JobTemplate[survey_fields]'] as $name
        ) {
            $this->assertStringContainsString('name="' . Html::encode($name) . '"', $fieldset, $name . ' is in the disabled fieldset');
        }
        foreach (['JobTemplate[name]', 'JobTemplate[description]', JobTemplateController::KEEP_CREDENTIALS] as $name) {
            $this->assertStringNotContainsString('name="' . Html::encode($name) . '"', $fieldset, $name . ' stays enabled');
        }
        $this->assertStringContainsString('+ Add Field', $fieldset, 'the survey editor is disabled with it');
        $this->assertStringNotContainsString('data-extra-vars-editor', $html, 'no editor that takes edits');
        $this->assertGreaterThan($end, (int)strpos($html, 'Save Changes'), 'the save button stays enabled');
    }

    public function testAnAdminGetsTheFormAsBefore(): void
    {
        $this->loginAs($this->admin);

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionUpdate((int)$this->template->id));

        $this->assertStringNotContainsString('job-template-locked-notice', $html);
        $this->assertStringContainsString('<fieldset id="job-template-run-fields">', $html);
        $this->assertStringNotContainsString(JobTemplateController::KEEP_CREDENTIALS, $html);
        $this->assertStringContainsString('data-extra-vars-editor="1"', $html);
    }

    public function testAnOperatorGetsTheFormOfATemplateWithoutARuleAsBefore(): void
    {
        $template = $this->template(null);
        $this->loginAs($this->operator);

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionUpdate((int)$template->id));

        $this->assertStringNotContainsString('job-template-locked-notice', $html);
        $this->assertStringContainsString('<fieldset id="job-template-run-fields">', $html);
        $this->assertStringContainsString('data-extra-vars-editor="1"', $html);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function pageNoticeProvider(): array
    {
        return [
            'an operator, a gated template' => ['operator', true, true],
            'an admin, a gated template' => ['admin', true, false],
            'a viewer, who may change nothing' => ['viewer', true, false],
            'an operator, a template without a rule' => ['operator', false, false],
        ];
    }

    /**
     * @dataProvider pageNoticeProvider
     */
    public function testThePageTellsWhoMayNotChangeWhatTheTemplateRuns(string $role, bool $gated, bool $notice): void
    {
        $template = $gated ? $this->template : $this->template(null);
        $this->loginAs(match ($role) {
            'admin' => $this->admin,
            'operator' => $this->operator,
            default => $this->createUserWithRole('gl-viewer', 'viewer'),
        });

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionView((int)$template->id));

        $expected = '<div class="alert alert-info" id="job-template-locked-notice">' . Html::encode(GatedJobTemplateLock::NOTICE) . '</div>';
        $this->assertSame($notice, str_contains($html, $expected));
        $this->assertSame($notice, str_contains($html, 'job-template-locked-notice'));
    }

    /**
     * An operator's clone keeps the rule (copying is not assigning), so the
     * form it opens is locked.
     */
    public function testAnOperatorsCloneOfAGatedTemplateOpensALockedForm(): void
    {
        $this->loginAs($this->operator);
        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$this->template->id);
        $clone = JobTemplate::findOne(['name' => $this->template->name . ' (copy)']);
        $this->assertNotNull($clone);
        $this->assertSame(['update', 'id' => $clone->id], $ctrl->capturedRedirect);
        $this->assertSame((int)$this->rule->id, (int)$clone->approval_rule_id);

        $html = $this->rendered(fn (JobTemplateController $c): mixed => $c->actionUpdate((int)$clone->id));

        $this->assertStringContainsString('id="job-template-locked-notice"', $html);
        $this->assertStringContainsString('<fieldset id="job-template-run-fields" disabled>', $html);
    }

    // -- Launch --------------------------------------------------------------------

    /**
     * Regression: a launch failure that was a PDOException (a
     * RuntimeException) showed its message, which can carry SQL with its
     * values, in the flash.
     */
    public function testRegressionALaunchDatabaseErrorShowsNoSqlInTheFlash(): void
    {
        $error = new \PDOException("SQLSTATE[HY000]: General error: INSERT INTO job VALUES ('gl-secret-value-4711')");
        $this->replace('jobLaunchService', new class ($error) extends JobLaunchService {
            public function __construct(private readonly \PDOException $error)
            {
                parent::__construct();
            }

            public function launch(JobTemplate $template, int $launchedBy, #[\SensitiveParameter] array $overrides = []): Job
            {
                throw $this->error;
            }
        });
        $this->loginAs($this->operator);
        $this->setQueryParams(['id' => (string)$this->template->id]);
        $this->setPost([]);

        $result = $this->makeController()->actionLaunch();

        $this->assertSame('rendered:launch', $result);
        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;
        $flashes = $session->getAllFlashes();
        $this->assertSame(['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, ErrorText::of($error))], $flashes);
        $this->assertStringNotContainsString('gl-secret-value-4711', (string)json_encode($flashes));
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * A post of the template's name and $change, which the locked form does
     * not offer.
     *
     * @return array<string, mixed>
     */
    private function forgedPost(string $change): array
    {
        $ownerId = (int)$this->admin->id;
        $fields = ['name' => 'forged-' . uniqid('', true)];
        $credentials = [(string)$this->credentials['A']->id, (string)$this->credentials['B']->id];
        $keep = [JobTemplateController::KEEP_CREDENTIALS => '1'];
        $extra = match ($change) {
            'extra_vars' => ['JobTemplate' => $fields + ['extra_vars' => '{"chosen_by": "operator"}']] + $keep,
            'playbook' => ['JobTemplate' => $fields + ['playbook' => 'other.yml']] + $keep,
            'inventory' => ['JobTemplate' => $fields + ['inventory_id' => (string)$this->createInventory($ownerId)->id]] + $keep,
            'runner_group' => ['JobTemplate' => $fields + ['runner_group_id' => (string)$this->createRunnerGroup($ownerId)->id]] + $keep,
            'survey' => ['JobTemplate' => $fields + ['survey_fields' => '[]']] + $keep,
            'primary' => ['JobTemplate' => $fields + ['credential_id' => ''], 'credential_ids' => $credentials],
            'become' => ['JobTemplate' => $fields + ['become' => '1']] + $keep,
            'credential_order' => ['JobTemplate' => $fields, 'credential_ids' => array_reverse($credentials)],
            'credential_removed' => ['JobTemplate' => $fields, 'credential_ids' => [$credentials[0]]],
            'credential_other' => ['JobTemplate' => $fields, 'credential_ids' => [(string)$this->createCredential($ownerId)->id]],
            'credential_none' => ['JobTemplate' => $fields],
            default => $this->fail('unknown change ' . $change),
        };

        return $extra;
    }

    /**
     * A template with extra vars, a survey and the credentials P (primary),
     * A and B; gated by $ruleId unless it is null.
     */
    private function template(?int $ruleId): JobTemplate
    {
        $ownerId = (int)$this->admin->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
        JobTemplate::updateAll([
            'approval_rule_id' => $ruleId,
            'extra_vars' => self::VARS,
            'survey_fields' => self::SURVEY,
            'credential_id' => $this->credentials['P']->id,
        ], ['id' => $template->id]);
        foreach (['P', 'A', 'B'] as $order => $symbol) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $this->credentials[$symbol]->id,
                'sort_order' => $order,
            ])->execute();
        }

        return $this->fresh($template);
    }

    /**
     * Everything the template runs: its columns but name, description and
     * the timestamps, and its credentials in order.
     *
     * @return array<string, mixed>
     */
    private function snapshot(JobTemplate $template): array
    {
        $columns = $template->getAttributes(null, ['name', 'description', 'updated_at']);

        return $columns + ['credentials' => array_column($this->fresh($template)->credentialSnapshot(), 'id')];
    }

    private function fresh(JobTemplate $template): JobTemplate
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function auditCount(): int
    {
        return (int)AuditLog::find()->where(['object_type' => 'job_template', 'object_id' => $this->template->id])->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function lastContext(string $action): array
    {
        $entry = AuditLog::find()
            ->where(['action' => $action, 'object_type' => 'job_template', 'object_id' => $this->template->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertInstanceOf(AuditLog::class, $entry);
        $context = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($context);

        return $context;
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
     * @return JobTemplateController&object{capturedRedirect: mixed, capturedParams: array<string, mixed>}
     */
    private function makeController(): JobTemplateController
    {
        return new class ('job-template', $this->app()) extends JobTemplateController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
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
