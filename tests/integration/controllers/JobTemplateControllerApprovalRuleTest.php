<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ApproverEligibility;
use app\services\JobLaunchRejectedException;
use app\services\JobLaunchService;
use app\services\JobTemplateRuleAssignment;
use app\services\LintService;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * The approval rule of a job template in the web UI: only users who may
 * assign rules (approval-rule.update) choose it on create and update, a
 * clone keeps its source's rule, everyone who sees the template sees it,
 * and a launch of a gated template says that it waits for approval.
 */
class JobTemplateControllerApprovalRuleTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    private User $admin;
    private User $operator;
    private ApprovalRule $rule;
    private ApprovalRule $otherRule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swap('lintService', new class extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        $this->admin = $this->createUserWithRole('jt-rule-admin', 'admin');
        $this->operator = $this->createUserWithRole('jt-rule-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->otherRule = $this->createApprovalRule((int)$this->admin->id);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    // -- Create and update: who may assign ----------------------------------------

    /**
     * Regression: an operator could set a template's approval rule through
     * a crafted web POST: approval_rule_id was a safe attribute, and the
     * form's load() took it.
     */
    public function testAnOperatorCannotSetARuleOnANewTemplateThroughACraftedPost(): void
    {
        $this->loginAs($this->operator);
        $name = 'crafted-create-' . uniqid('', true);
        $this->setPost(['JobTemplate' => $this->formFields($name, ['approval_rule_id' => (string)$this->rule->id])]);

        try {
            $this->makeController()->actionCreate();
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $e->getMessage());
        }

        $this->assertNull(JobTemplate::findOne(['name' => $name]), 'nothing is saved');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function changedRuleProvider(): array
    {
        return ['another rule' => ['other'], 'no rule' => ['none']];
    }

    /**
     * Regression: an operator could change or remove a template's approval
     * rule through a crafted web POST, removing the gate of every launch.
     *
     * @dataProvider changedRuleProvider
     */
    public function testAnOperatorCannotChangeOrRemoveTheRuleOfATemplate(string $change): void
    {
        $template = $this->gatedTemplate();
        $name = $template->name;
        $this->loginAs($this->operator);
        $submitted = $change === 'none' ? '' : (string)$this->otherRule->id;
        $this->setPost(['JobTemplate' => ['name' => 'renamed-' . uniqid('', true), 'approval_rule_id' => $submitted]]);

        try {
            $this->makeController()->actionUpdate((int)$template->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $e->getMessage());
        }

        $template->refresh();
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id, 'the gate stays');
        $this->assertSame($name, $template->name, 'nothing else is saved either');
    }

    public function testAnOperatorMaySendTheStoredRuleAgain(): void
    {
        $template = $this->gatedTemplate();
        $this->loginAs($this->operator);
        $newName = 'resent-' . uniqid('', true);
        $this->setPost(['JobTemplate' => ['name' => $newName, 'approval_rule_id' => (string)$this->rule->id]]);

        $result = $this->makeController()->actionUpdate((int)$template->id);

        $this->assertInstanceOf(Response::class, $result);
        $template->refresh();
        $this->assertSame($newName, $template->name);
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id);
        $this->assertArrayNotHasKey('approval_rule', $this->lastContext(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id), 'no rule change');
    }

    public function testAnOperatorsEditWithoutTheRuleKeepsIt(): void
    {
        $template = $this->gatedTemplate();
        $this->loginAs($this->operator);
        $newName = 'kept-' . uniqid('', true);
        $this->setPost(['JobTemplate' => ['name' => $newName]]);

        $this->makeController()->actionUpdate((int)$template->id);

        $template->refresh();
        $this->assertSame($newName, $template->name);
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id);
    }

    /**
     * A crafted form whose JobTemplate field is text, not a list of fields,
     * sets nothing, the rule included.
     */
    public function testATemplateFieldThatIsNoListChangesNothing(): void
    {
        $template = $this->gatedTemplate();
        $name = $template->name;
        $this->loginAs($this->operator);
        $this->setPost(['JobTemplate' => 'approval_rule_id=']);

        $this->makeController()->actionUpdate((int)$template->id);

        $template->refresh();
        $this->assertSame($name, $template->name);
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id);
    }

    public function testAnAdminCreatesAGatedTemplateAndTheAuditNamesTheRule(): void
    {
        $this->loginAs($this->admin);
        $name = 'admin-create-' . uniqid('', true);
        $this->setPost(['JobTemplate' => $this->formFields($name, ['approval_rule_id' => (string)$this->rule->id])]);

        $result = $this->makeController()->actionCreate();

        $this->assertInstanceOf(Response::class, $result);
        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertNotNull($template);
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id);
        $this->assertSame(
            ['from' => null, 'to' => ['id' => (int)$this->rule->id, 'name' => $this->rule->name]],
            $this->lastContext(AuditLog::ACTION_TEMPLATE_CREATED, (int)$template->id)['approval_rule'] ?? null
        );
    }

    public function testAnAdminCreatesATemplateWithoutARule(): void
    {
        $this->loginAs($this->admin);
        $name = 'admin-ungated-' . uniqid('', true);
        $this->setPost(['JobTemplate' => $this->formFields($name, ['approval_rule_id' => ''])]);

        $this->makeController()->actionCreate();

        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertNotNull($template);
        $this->assertNull($template->approval_rule_id);
        $this->assertArrayNotHasKey('approval_rule', $this->lastContext(AuditLog::ACTION_TEMPLATE_CREATED, (int)$template->id));
    }

    public function testAnAdminChangesAndRemovesTheRuleAndEachChangeIsAudited(): void
    {
        $template = $this->gatedTemplate();
        $this->loginAs($this->admin);

        $this->setPost(['JobTemplate' => ['approval_rule_id' => (string)$this->otherRule->id]]);
        $this->makeController()->actionUpdate((int)$template->id);
        $template->refresh();
        $this->assertSame((int)$this->otherRule->id, (int)$template->approval_rule_id);
        $this->assertSame(
            ['from' => ['id' => (int)$this->rule->id, 'name' => $this->rule->name], 'to' => ['id' => (int)$this->otherRule->id, 'name' => $this->otherRule->name]],
            $this->lastContext(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id)['approval_rule'] ?? null
        );

        $this->setPost(['JobTemplate' => ['approval_rule_id' => '']]);
        $this->makeController()->actionUpdate((int)$template->id);
        $template->refresh();
        $this->assertNull($template->approval_rule_id);
        $this->assertSame(
            ['from' => ['id' => (int)$this->otherRule->id, 'name' => $this->otherRule->name], 'to' => null],
            $this->lastContext(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id)['approval_rule'] ?? null
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function assignerProvider(): array
    {
        return ['a superadmin without roles' => ['superadmin'], 'a custom role with approval-rule.update' => ['custom']];
    }

    /**
     * @dataProvider assignerProvider
     */
    public function testEveryHolderOfTheAssignPermissionMayChangeTheRule(string $kind): void
    {
        $template = $this->gatedTemplate();
        $this->loginAs($this->assigner($kind));
        $this->setPost(['JobTemplate' => ['approval_rule_id' => (string)$this->otherRule->id]]);

        $this->makeController()->actionUpdate((int)$template->id);

        $template->refresh();
        $this->assertSame((int)$this->otherRule->id, (int)$template->approval_rule_id);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidRuleProvider(): array
    {
        return [
            'an unknown rule' => ['987654321', 'The selected approval rule does not exist.'],
            'no number' => ['abc', 'Approval rule must be an integer.'],
        ];
    }

    /**
     * @dataProvider invalidRuleProvider
     */
    public function testAnInvalidRuleIsAValidationErrorOnTheForm(string $submitted, string $message): void
    {
        $template = $this->gatedTemplate();
        $this->loginAs($this->admin);
        $this->setPost(['JobTemplate' => ['approval_rule_id' => $submitted]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$template->id);

        $this->assertSame('rendered:form', $result);
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(JobTemplate::class, $model);
        $this->assertSame([$message], $model->getErrors('approval_rule_id'));
        $template->refresh();
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id);
    }

    // -- Clone ------------------------------------------------------------------

    /**
     * Copying is not assigning: an operator's clone keeps the source's
     * rule, so cloning cannot create an ungated twin. The audit entry
     * names the rule.
     */
    public function testAnOperatorsCloneKeepsTheSourcesRule(): void
    {
        $source = $this->gatedTemplate();
        $this->loginAs($this->operator);

        $this->makeController()->actionClone((int)$source->id);

        $clone = JobTemplate::findOne(['name' => $source->name . ' (copy)']);
        $this->assertNotNull($clone);
        $this->assertSame((int)$this->rule->id, (int)$clone->approval_rule_id);
        $context = $this->lastContext(AuditLog::ACTION_TEMPLATE_CREATED, (int)$clone->id);
        $this->assertSame((int)$source->id, $context['cloned_from'] ?? null);
        $this->assertSame(['from' => null, 'to' => ['id' => (int)$this->rule->id, 'name' => $this->rule->name]], $context['approval_rule'] ?? null);
    }

    // -- Form and page -------------------------------------------------------------

    public function testTheFormOffersTheRuleSelectToUsersWhoMayAssign(): void
    {
        $template = $this->gatedTemplate();
        $this->loginAs($this->admin);

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionUpdate((int)$template->id));

        $this->assertMatchesRegularExpression('~<select id="jobtemplate-approval_rule_id"[^>]*name="JobTemplate\[approval_rule_id\]"~', $html);
        $this->assertStringContainsString('<option value="">— None —</option>', $html);
        $this->assertStringContainsString(
            '<option value="' . $this->rule->id . '" selected>' . Html::encode(JobTemplateRuleAssignment::label($this->rule)) . '</option>',
            $html
        );
        $this->assertStringContainsString('<option value="' . $this->otherRule->id . '">', $html);
        $this->assertStringContainsString(Html::encode(JobTemplateRuleAssignment::FORM_HINT), $html);
        $this->assertStringNotContainsString('job-template-approval-rule-readonly', $html);
    }

    public function testTheFormShowsTheRuleAsTextToEveryoneElse(): void
    {
        $template = $this->gatedTemplate();
        $this->rule->name = '<b>gate</b>';
        $this->rule->save(false);
        $this->loginAs($this->operator);

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionUpdate((int)$template->id));

        $this->assertStringNotContainsString('name="JobTemplate[approval_rule_id]"', $html, 'nothing to submit');
        $this->assertMatchesRegularExpression(
            '~id="job-template-approval-rule-readonly">.*' . preg_quote(Html::encode(JobTemplateRuleAssignment::label($this->rule)), '~') . '~s',
            $html
        );
        $this->assertStringContainsString(Html::encode(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED), $html);
        $this->assertStringNotContainsString('<b>gate</b>', $html);
    }

    public function testTheFormOfANewTemplateShowsNoRuleToAnOperator(): void
    {
        $this->loginAs($this->operator);

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionCreate());

        $this->assertMatchesRegularExpression('~id="job-template-approval-rule-readonly">\s*<span[^>]*>Approval rule</span>\s*<span[^>]*>None</span>~', $html);
    }

    public function testThePageLinksTheRuleForUsersWhoMayViewRules(): void
    {
        $template = $this->gatedTemplate();
        $this->loginAs($this->operator);

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionView((int)$template->id));

        $this->assertStringContainsString(
            Html::a(Html::encode(JobTemplateRuleAssignment::label($this->rule)), ['/approval-rule/view', 'id' => $this->rule->id]),
            $html
        );
    }

    public function testThePageNamesTheRuleWithoutALinkForUsersWhoMayNotViewRules(): void
    {
        $template = $this->gatedTemplate();
        $this->loginAs($this->createUser('jt-rule-plain'));

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionView((int)$template->id));

        $this->assertMatchesRegularExpression(
            '~id="job-template-approval-rule">\s*' . preg_quote(Html::encode(JobTemplateRuleAssignment::label($this->rule)), '~') . '\s*</dd>~',
            $html
        );
        $this->assertStringNotContainsString('/approval-rule/view', $html);
    }

    public function testThePageOfAnUngatedTemplateSaysNone(): void
    {
        $template = $this->template();
        $this->loginAs($this->admin);

        $html = $this->rendered(fn (JobTemplateController $ctrl): mixed => $ctrl->actionView((int)$template->id));

        $this->assertMatchesRegularExpression('~id="job-template-approval-rule">\s*<span class="text-muted">None</span>~', $html);
    }

    // -- Launch -------------------------------------------------------------------

    public function testALaunchThatWaitsForApprovalSaysSo(): void
    {
        $template = $this->gatedTemplate();
        $this->loginAs($this->operator);
        $this->stubLaunch(Job::STATUS_PENDING_APPROVAL);
        $this->setQueryParams(['id' => (string)$template->id]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $ctrl->actionLaunch();

        $job = Job::find()->where(['job_template_id' => $template->id])->one();
        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame(['/job/view', 'id' => $job->id], $ctrl->capturedRedirect);
        $this->assertSame(
            ['info' => sprintf(JobTemplateController::FLASH_WAITING_FOR_APPROVAL, $job->id)],
            $this->session()->getAllFlashes()
        );
    }

    public function testAQueuedLaunchSaysItIsQueued(): void
    {
        $template = $this->template();
        $this->loginAs($this->operator);
        $this->stubLaunch(Job::STATUS_QUEUED);
        $this->setQueryParams(['id' => (string)$template->id]);
        $this->setPost([]);

        $this->makeController()->actionLaunch();

        $job = Job::find()->where(['job_template_id' => $template->id])->one();
        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame(['success' => sprintf(JobTemplateController::FLASH_QUEUED, $job->id)], $this->session()->getAllFlashes());
    }

    /**
     * A refused launch (here: too few approvers besides the launcher may
     * decide) shows the launch form again with the reason; nothing is
     * created.
     */
    public function testARefusedLaunchShowsTheReasonOnTheForm(): void
    {
        $template = $this->template();
        $onlyTheLauncher = $this->createApprovalRule(
            (int)$this->admin->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [(int)$this->operator->id]])
        );
        $template->approval_rule_id = (int)$onlyTheLauncher->id;
        $template->save(false);
        $this->loginAs($this->operator);
        $this->setQueryParams(['id' => (string)$template->id]);
        $this->setPost([]);
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');
        $refusal = $eligibility->jobLaunchRefusal($onlyTheLauncher, $template, (int)$this->operator->id);
        $this->assertNotNull($refusal);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertSame('rendered:launch', $result);
        $this->assertSame(['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, $refusal)], $this->session()->getAllFlashes());
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    public function testEveryLaunchRefusalReachesTheForm(): void
    {
        $template = $this->template();
        $this->loginAs($this->operator);
        $this->swap('jobLaunchService', new class extends JobLaunchService {
            public function launch(JobTemplate $template, int $launchedBy, #[\SensitiveParameter] array $overrides = []): Job
            {
                throw new JobLaunchRejectedException(sprintf('Job template #%d was deleted.', $template->id));
            }
        });
        $this->setQueryParams(['id' => (string)$template->id]);
        $this->setPost([]);

        $result = $this->makeController()->actionLaunch();

        $this->assertSame('rendered:launch', $result);
        $this->assertSame(
            ['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, sprintf('Job template #%d was deleted.', $template->id))],
            $this->session()->getAllFlashes()
        );
    }

    // -- helpers ------------------------------------------------------------------

    private function template(): JobTemplate
    {
        $ownerId = (int)$this->admin->id;

        return $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
    }

    private function gatedTemplate(): JobTemplate
    {
        $template = $this->template();
        $template->approval_rule_id = (int)$this->rule->id;
        $template->save(false);

        return $template;
    }

    /**
     * The fields of a valid new template, plus $extra.
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function formFields(string $name, array $extra): array
    {
        $ownerId = (int)$this->admin->id;

        return [
            'name' => $name,
            'project_id' => (string)$this->createProject($ownerId)->id,
            'inventory_id' => (string)$this->createInventory($ownerId)->id,
            'runner_group_id' => (string)$this->createRunnerGroup($ownerId)->id,
            'playbook' => 'site.yml',
            'verbosity' => '0',
            'forks' => '5',
            'timeout_minutes' => '30',
            'become' => '0',
            'become_method' => 'sudo',
            'become_user' => 'root',
        ] + $extra;
    }

    private function assigner(string $kind): User
    {
        if ($kind === 'superadmin') {
            $user = $this->createUser('jt-rule-superadmin');
            $user->is_superadmin = true;
            $user->save(false);

            return $user;
        }
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('jt-rule-assigners-' . uniqid());
        $auth->add($role);
        $permission = $auth->getPermission(ApprovalRuleAssignmentPolicy::PERMISSION);
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $user = $this->createUserWithRole('jt-rule-custom', 'operator');
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastContext(string $action, int $templateId): array
    {
        $entry = AuditLog::find()
            ->where(['action' => $action, 'object_type' => 'job_template', 'object_id' => $templateId])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertInstanceOf(AuditLog::class, $entry);
        $context = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }

    private function stubLaunch(string $status): void
    {
        $this->swap('jobLaunchService', new class ($status) extends JobLaunchService {
            public function __construct(private readonly string $status)
            {
                parent::__construct();
            }

            public function launch(JobTemplate $template, int $launchedBy, #[\SensitiveParameter] array $overrides = []): Job
            {
                $job = new Job();
                $job->job_template_id = $template->id;
                $job->launched_by = $launchedBy;
                $job->status = $this->status;
                $job->timeout_minutes = 120;
                $job->has_changes = 0;
                $job->created_at = time();
                $job->updated_at = time();
                $job->save(false);

                return $job;
            }
        });
    }

    private function swap(string $id, object $replacement): void
    {
        $this->swapped[] = [$id, \Yii::$app->getComponents(true)[$id] ?? null];
        \Yii::$app->set($id, $replacement);
    }

    private function session(): \yii\web\Session
    {
        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;

        return $session;
    }

    /**
     * The page $render renders, with the real views and without the layout.
     * The console application of the tests has no web root, so asset
     * bundles are dummies; view, asset manager and controller are restored.
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
        // ActiveForm posts back to the current URL.
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
