<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\ApprovalRuleUsage;
use app\controllers\ApprovalRuleController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\services\ApprovalRuleWriteService;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Approval rules in the web UI: only admins create and change them
 * (approval-rule.create / .update), a rule in use is not deleted, the creator
 * is never a posted value, and the rule page shows what uses it.
 */
class ApprovalRuleControllerGuardTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;

    // -- Access filter (through runAction) ----------------------------------------

    /**
     * Regression: an operator could change any rule's approvers (and so
     * neutralise every gate that used it), and create rules.
     */
    public function testAnOperatorCanNeitherCreateNorChangeNorDeleteRules(): void
    {
        $operator = $this->signedIn('operator');
        $rule = $this->ruleOf($operator);
        $config = (string)$rule->approver_config;

        $this->assertDenied(fn () => $this->makeController()->runAction('create'));
        $this->setPost(['ApprovalRule' => ['approver_config' => '{"user_ids":[]}', 'required_approvals' => '1']]);
        $this->assertDenied(fn () => $this->makeController()->runAction('create'));
        $this->assertDenied(fn () => $this->makeController()->runAction('update', ['id' => $rule->id]));
        $this->assertDenied(fn () => $this->makeController()->runAction('delete', ['id' => $rule->id]));

        $rule->refresh();
        $this->assertSame($config, (string)$rule->approver_config, 'the approvers stay');
    }

    public function testOperatorsAndViewersStillSeeRules(): void
    {
        foreach (['operator', 'viewer'] as $role) {
            $user = $this->signedIn($role);
            $rule = $this->ruleOf($user);

            $this->assertSame('rendered:index', $this->makeController()->runAction('index'), $role);
            $this->assertSame('rendered:view', $this->makeController()->runAction('view', ['id' => $rule->id]), $role);
        }
    }

    public function testAdminsSuperadminsAndCustomRolesManageRules(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->ruleOf($admin);
        $this->setPost(['ApprovalRule' => ['required_approvals' => '1', 'description' => 'by an admin']]);
        $this->assertInstanceOf(Response::class, $this->makeController()->runAction('update', ['id' => $rule->id]));

        $this->loginAs($this->superadminWithoutRoles());
        $this->setPost(['ApprovalRule' => ['description' => 'by a superadmin']]);
        $this->assertInstanceOf(Response::class, $this->makeController()->runAction('update', ['id' => $rule->id]), 'superadmins pass the filter');
        $this->assertSame('rendered:form', $this->makeController()->runAction('create'));

        $this->loginAs($this->ruleManager());
        $this->setPost(['ApprovalRule' => ['description' => 'by a custom role']]);
        $this->assertInstanceOf(Response::class, $this->makeController()->runAction('update', ['id' => $rule->id]));
        $rule->refresh();
        $this->assertSame('by a custom role', $rule->description);
    }

    // -- Buttons ----------------------------------------------------------------

    public function testTheButtonsFollowThePermissionsAndSuperadmins(): void
    {
        $cases = [
            'operator' => [$this->userWithRole('operator'), false],
            'admin' => [$this->userWithRole('admin'), true],
            'superadmin without roles' => [$this->superadminWithoutRoles(), true],
        ];
        foreach ($cases as $case => [$user, $may]) {
            $this->loginAs($user);
            $rule = $this->ruleOf($user);

            $index = $this->makeController();
            $index->actionIndex();
            $this->assertSame($may, $index->capturedParams['canCreate'], $case);
            $view = $this->makeController();
            $view->actionView((int)$rule->id);
            $this->assertSame($may, $view->capturedParams['canUpdate'], $case);
            $this->assertSame($may, $view->capturedParams['canDelete'], $case);
            $this->assertInstanceOf(ApprovalRuleUsage::class, $view->capturedParams['usage']);
        }
    }

    /**
     * Without a signed-in user (the access filter keeps guests out of the
     * action) nothing is listed and nothing offered.
     */
    public function testWithoutASignedInUserNothingIsListedOrOffered(): void
    {
        $owner = $this->createUser('rule-web-owner');
        $rule = $this->ruleOf($owner);
        $template = $this->templateOf($owner);
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$rule->id);

        $usage = $ctrl->capturedParams['usage'];
        $this->assertInstanceOf(ApprovalRuleUsage::class, $usage);
        $this->assertSame([], $usage->jobTemplates);
        $this->assertSame(1, $usage->hiddenJobTemplateCount());
        $this->assertFalse($ctrl->capturedParams['canUpdate']);
        $this->assertFalse($ctrl->capturedParams['canDelete']);
    }

    // -- Delete -----------------------------------------------------------------

    /**
     * Regression: deleting a rule silently ungated its templates and deleted
     * its pending requests.
     */
    public function testDeletingARuleInUseIsRefusedWithWhatUsesIt(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->ruleOf($admin);
        $template = $this->templateOf($admin);
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $request = $this->pendingRequest($rule, $template, $admin);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $ctrl->runAction('delete', ['id' => $rule->id]);

        $this->assertSame(['view', 'id' => $rule->id], $ctrl->capturedRedirect);
        $this->assertSame(
            'Approval rule ' . json_encode($rule->name) . ' is in use by 1 job template(s) and 1 pending approval request(s). '
            . 'Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it.',
            \Yii::$app->session->getFlash('danger')
        );
        $this->assertNotNull(ApprovalRule::findOne($rule->id));
        $template->refresh();
        $this->assertSame((int)$rule->id, (int)$template->approval_rule_id, 'the template stays gated');
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status, 'the request stays decidable');
    }

    public function testDeletingAnUnusedRuleDeletesItAndAuditsTheWebSource(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->ruleOf($admin);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $ctrl->runAction('delete', ['id' => $rule->id]);

        $this->assertSame(['index'], $ctrl->capturedRedirect);
        $this->assertSame(sprintf(ApprovalRuleController::FLASH_DELETED, $rule->name), \Yii::$app->session->getFlash('success'));
        $this->assertNull(ApprovalRule::findOne($rule->id));
        $this->assertSame(ApprovalRuleWriteService::SOURCE_WEB, $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_DELETED, (int)$rule->id)['source']);
    }

    // -- Create / update --------------------------------------------------------

    /**
     * Regression: created_by was mass-assignable on update, so a form post
     * could make another user the creator; job_template_id, which never
     * gated anything, could be set the same way.
     */
    public function testAPostedCreatorOrJobTemplateIsIgnored(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->ruleOf($admin);
        $other = $this->createUser('rule-web-other');
        $template = $this->templateOf($admin);
        $this->setPost(['ApprovalRule' => [
            'name' => 'renamed-' . uniqid(),
            'created_by' => (string)$other->id,
            'job_template_id' => (string)$template->id,
        ]]);

        $this->assertInstanceOf(Response::class, $this->makeController()->actionUpdate((int)$rule->id));

        $rule->refresh();
        $this->assertSame((int)$admin->id, (int)$rule->created_by);
        $this->assertNull($rule->job_template_id);
        $this->assertStringStartsWith('renamed-', $rule->name);
    }

    public function testCreateRecordsTheSignedInUserAndAuditsTheSettings(): void
    {
        $admin = $this->signedIn('admin');
        $other = $this->createUser('rule-web-forged');
        $name = 'web-created-' . uniqid();
        $this->setPost(['ApprovalRule' => [
            'name' => $name,
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => (string)json_encode(['user_ids' => [(int)$admin->id]]),
            'required_approvals' => '1',
            'timeout_action' => ApprovalRule::TIMEOUT_ACTION_APPROVE,
            'created_by' => (string)$other->id,
        ]]);

        $ctrl = $this->makeController();
        $this->assertInstanceOf(Response::class, $ctrl->actionCreate());

        $rule = ApprovalRule::findOne(['name' => $name]);
        $this->assertNotNull($rule);
        $this->assertSame((int)$admin->id, (int)$rule->created_by);
        $this->assertSame(['view', 'id' => $rule->id], $ctrl->capturedRedirect);
        $this->assertSame(sprintf(ApprovalRuleController::FLASH_CREATED, $name), \Yii::$app->session->getFlash('success'));
        $audit = $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_CREATED, (int)$rule->id);
        $this->assertSame('web', $audit['source']);
        $this->assertSame(ApprovalRule::TIMEOUT_ACTION_APPROVE, $audit['timeout_action']);
    }

    public function testUpdateAuditsWhatChanged(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->ruleOf($admin);
        $this->setPost(['ApprovalRule' => ['timeout_minutes' => '90']]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$rule->id);

        $this->assertSame(sprintf(ApprovalRuleController::FLASH_UPDATED, $rule->name), \Yii::$app->session->getFlash('success'));
        $audit = $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_UPDATED, (int)$rule->id);
        $this->assertSame(['timeout_minutes' => ['from' => null, 'to' => 90]], $audit['changes']);
        $this->assertSame('web', $audit['source']);
    }

    public function testARuleWithoutApproversIsRefused(): void
    {
        $this->signedIn('admin');
        $this->setPost(['ApprovalRule' => ['name' => 'no-config', 'approver_type' => ApprovalRule::APPROVER_TYPE_ROLE]]);

        $ctrl = $this->makeController();

        $this->assertSame('rendered:form', $ctrl->actionCreate());
        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(ApprovalRule::class, $model);
        $this->assertSame('Approver Config cannot be blank.', $model->getFirstError('approver_config'));
    }

    // -- The pages, rendered ------------------------------------------------------

    public function testTheRulePageListsWhatUsesItAndBlocksTheDelete(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->ruleOf($admin);
        $template = $this->templateOf($admin);
        $template->name = 'gated <b>deploy</b>';
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->pendingRequest($rule, $template, $admin);

        $html = $this->viewPage($rule);

        $this->assertStringContainsString('id="approval-rule-usage"', $html);
        $this->assertStringContainsString('>' . Html::encode('gated <b>deploy</b>') . '</a>', $html);
        $this->assertStringContainsString('href="/job-template/view?id=' . $template->id . '"', $html);
        $this->assertStringContainsString('href="/workflow-template/view?id=' . $workflow->id . '"', $html);
        $this->assertStringContainsString(Html::encode('step ' . $step->name), $html);
        $this->assertStringContainsString('1 approval request(s) are waiting for a decision under this rule.', $html);
        $this->assertStringContainsString('id="approval-rule-delete-blocked" disabled', $html);
        $this->assertStringContainsString(Html::encode(ApprovalRuleUsage::DELETE_BLOCKED), $html);
        $this->assertStringNotContainsString('id="approval-rule-delete-form"', $html);
        $this->assertStringContainsString('id="approval-rule-edit"', $html);
    }

    public function testTheRulePageNamesNothingTheViewerMayNotSee(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleOf($s['admin']);
        foreach (['own', 'foreign'] as $key) {
            $s[$key]->approval_rule_id = (int)$rule->id;
            $s[$key]->save(false);
        }
        $hiddenWorkflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['foreign']->id);
        $this->createWorkflowStep((int)$hiddenWorkflow->id, 5, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->loginAs($s['member']);

        $html = $this->viewPage($rule);

        $this->assertStringContainsString('>' . Html::encode((string)$s['own']->name) . '</a>', $html);
        $this->assertStringNotContainsString(Html::encode((string)$s['foreign']->name), $html);
        $this->assertStringNotContainsString(Html::encode((string)$hiddenWorkflow->name), $html);
        $this->assertStringContainsString('+ 1 job template(s) in projects you cannot see', $html);
        $this->assertStringContainsString('+ 1 workflow step(s) in workflows you cannot see', $html);
        $this->assertStringNotContainsString('approval-rule-delete', $html, 'an operator gets no delete control');
        $this->assertStringNotContainsString('id="approval-rule-edit"', $html);
    }

    public function testTheRulePageOfAnUnusedRuleOffersTheDelete(): void
    {
        $superadmin = $this->superadminWithoutRoles();
        $this->loginAs($superadmin);
        $rule = $this->ruleOf($superadmin);

        $html = $this->viewPage($rule);

        $this->assertStringContainsString('Not used by any job template, workflow step or pending approval request.', $html);
        $this->assertStringContainsString('id="approval-rule-delete-form"', $html);
        $this->assertStringContainsString('return confirm(', $html);
        $this->assertStringNotContainsString('approval-rule-delete-blocked', $html);
        $this->assertStringContainsString('id="approval-rule-edit"', $html, 'superadmins see the buttons without a role');
    }

    public function testTheIndexOffersNewRulesOnlyToThoseWhoMayCreate(): void
    {
        $this->signedIn('operator');
        $this->assertStringNotContainsString('New Approval Rule', $this->indexPage());

        $this->loginAs($this->superadminWithoutRoles());
        $this->assertStringContainsString('New Approval Rule', $this->indexPage());
    }

    public function testTheFormExplainsThatTheLauncherNeverApproves(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->ruleOf($admin);
        $ctrl = new ApprovalRuleController('approval-rule', $this->app());
        $ctrl->layout = false;

        $html = $this->withRealViews($ctrl, '/approval-rule/update?id=' . $rule->id, static fn (): mixed => $ctrl->actionUpdate((int)$rule->id));

        $this->assertIsString($html);
        $this->assertStringContainsString('<div id="approver-launcher-note" class="alert alert-info" style="display:none">' . Html::encode(ApprovalRule::LAUNCHER_NOTE) . '</div>', $html);
        $this->assertStringContainsString("launcherNote.style.display = count > 0 && required >= count ? '' : 'none';", $html);
    }

    // -- Helpers ----------------------------------------------------------------

    private function viewPage(ApprovalRule $rule): string
    {
        $ctrl = new ApprovalRuleController('approval-rule', $this->app());
        $ctrl->layout = false;
        $html = $this->withRealViews($ctrl, '/approval-rule/view?id=' . $rule->id, static fn (): string => $ctrl->actionView((int)$rule->id));
        $this->assertIsString($html);

        return $html;
    }

    private function indexPage(): string
    {
        $ctrl = new ApprovalRuleController('approval-rule', $this->app());
        $ctrl->layout = false;

        return $this->withRealViews($ctrl, '/approval-rule/index', static fn (): string => $ctrl->actionIndex());
    }

    private function signedIn(string $role): User
    {
        $user = $this->userWithRole($role);
        $this->loginAs($user);

        return $user;
    }

    private function userWithRole(string $role): User
    {
        return $this->createUserWithRole('rule-web-' . $role, $role);
    }

    private function superadminWithoutRoles(): User
    {
        $user = $this->createUser('rule-web-superadmin');
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
    }

    /**
     * A user whose only role is a custom one with approval-rule.view and
     * approval-rule.update.
     */
    private function ruleManager(): User
    {
        $user = $this->createUser('rule-web-manager');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('rule-managers-' . uniqid());
        $auth->add($role);
        foreach (['approval-rule.view', 'approval-rule.update'] as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission);
            $auth->addChild($role, $permission);
        }
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    private function ruleOf(User $creator): ApprovalRule
    {
        $rule = $this->createApprovalRule((int)$creator->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [(int)$creator->id]]));
        $stored = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    private function templateOf(User $owner): JobTemplate
    {
        $userId = (int)$owner->id;

        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function pendingRequest(ApprovalRule $rule, JobTemplate $template, User $launcher): ApprovalRequest
    {
        $job = $this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }

    private function assertDenied(callable $call): void
    {
        try {
            $call();
            $this->fail('expected the access filter to deny the action');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You are not allowed to perform this action.', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditOf(string $action, int $ruleId): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()
            ->where(['action' => $action, 'object_type' => 'approval_rule', 'object_id' => $ruleId])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return $app;
    }

    /**
     * @return ApprovalRuleController&object{capturedParams: array<string, mixed>, capturedRedirect: array<int|string, mixed>}
     */
    private function makeController(): ApprovalRuleController
    {
        return new class ('approval-rule', $this->app()) extends ApprovalRuleController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            /** @var array<int|string, mixed> */
            public array $capturedRedirect = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            /**
             * @param string|array<int|string, mixed> $url
             * @param int $statusCode
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = (array)$url;
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }
}
