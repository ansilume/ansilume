<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalRuleController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\tests\integration\ApproverListFixtures;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ApprovalRuleControllerActionTest extends WebControllerTestCase
{
    use ApproverListFixtures;
    use RendersRealViews;

    public function testIndexRendersDataProvider(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->createRule($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $this->assertInstanceOf(ActiveDataProvider::class, $ctrl->capturedParams['dataProvider']);
    }

    public function testViewRendersModel(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $rule = $this->createRule($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$rule->id);

        $this->assertSame('rendered:view', $result);
        $this->assertSame($rule->id, $ctrl->capturedParams['model']->id);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView(9999999);
    }

    public function testCreateRendersFormOnGetWithDefaults(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        /** @var ApprovalRule $model */
        $model = $ctrl->capturedParams['model'];
        $this->assertSame(1, $model->required_approvals);
        $this->assertSame(ApprovalRule::TIMEOUT_ACTION_REJECT, $model->timeout_action);
        $this->assertSame(ApprovalRule::APPROVER_TYPE_ROLE, $model->approver_type);

        // Form params include approver selector data
        $this->assertArrayHasKey('roles', $ctrl->capturedParams);
        $this->assertArrayHasKey('teams', $ctrl->capturedParams);
        $this->assertArrayHasKey('users', $ctrl->capturedParams);
        $this->assertIsArray($ctrl->capturedParams['roles']);
        $this->assertIsArray($ctrl->capturedParams['teams']);
        $this->assertIsArray($ctrl->capturedParams['users']);
    }

    public function testCreatePersistsAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setPost([
            'ApprovalRule' => [
                'name' => 'test-rule',
                'approver_type' => ApprovalRule::APPROVER_TYPE_ROLE,
                'approver_config' => '{"role":"admin"}',
                'required_approvals' => 2,
                'timeout_minutes' => 60,
                'timeout_action' => ApprovalRule::TIMEOUT_ACTION_REJECT,
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertInstanceOf(Response::class, $result);
        $stored = ApprovalRule::findOne(['name' => 'test-rule']);
        $this->assertNotNull($stored);
        $this->assertSame($user->id, $stored->created_by);
        $this->assertSame(2, (int)$stored->required_approvals);

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_APPROVAL_RULE_CREATED,
            'object_id' => $stored->id,
        ]));
    }

    public function testCreateInvalidInputRendersForm(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setPost(['ApprovalRule' => ['name' => '']]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertTrue($ctrl->capturedParams['model']->hasErrors());
    }

    public function testUpdatePersistsChanges(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $rule = $this->createRule($user->id);

        $this->setPost([
            'ApprovalRule' => [
                'name' => 'renamed',
                'approver_type' => ApprovalRule::APPROVER_TYPE_ROLE,
                'required_approvals' => 3,
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$rule->id);

        $this->assertInstanceOf(Response::class, $result);
        /** @var ApprovalRule $reloaded */
        $reloaded = ApprovalRule::findOne($rule->id);
        $this->assertSame('renamed', $reloaded->name);
        $this->assertSame(3, (int)$reloaded->required_approvals);

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_APPROVAL_RULE_UPDATED,
            'object_id' => $rule->id,
        ]));
    }

    public function testUpdateRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $rule = $this->createRule($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$rule->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame($rule->id, $ctrl->capturedParams['model']->id);
    }

    public function testUpdateInvalidInputRendersForm(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $rule = $this->createRule($user->id);

        $this->setPost(['ApprovalRule' => ['name' => '', 'approver_type' => '']]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$rule->id);

        $this->assertSame('rendered:form', $result);
        $this->assertTrue($ctrl->capturedParams['model']->hasErrors());
    }

    public function testUpdateThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionUpdate(9999999);
    }

    /**
     * Regression: the form saved a users rule that requires more approvals
     * than the users it names, as the count took every entry of user_ids:
     * also one that is no ID, the same user again or a user who does not
     * exist. Every launch the rule gates was then refused as one that could
     * never be approved. The form is shown again with the error under
     * Required Approvals, and nothing is saved or audited. The form the
     * browser sends lists each chosen user once.
     *
     * @dataProvider oneUserListedProvider
     */
    public function testRegressionTheFormRefusesAUsersRuleThatNeedsMoreApprovalsThanItsUsers(string $list): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $stored = $this->createRule((int)$user->id);
        $audits = $this->ruleAudits();
        $error = [sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 2, 1)];
        $posted = [
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => $this->oneUserConfig($list),
            'required_approvals' => '2',
        ];

        $this->setPost(['ApprovalRule' => ['name' => 'form-one-approver'] + $posted]);
        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionCreate());
        $this->assertSame($error, $ctrl->capturedParams['model']->getErrors('required_approvals'));
        $this->assertFalse(ApprovalRule::find()->where(['name' => 'form-one-approver'])->exists(), 'nothing created');

        $this->setPost(['ApprovalRule' => $posted]);
        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$stored->id));
        $this->assertSame($error, $ctrl->capturedParams['model']->getErrors('required_approvals'));
        $reloaded = ApprovalRule::findOne($stored->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(ApprovalRule::APPROVER_TYPE_ROLE, $reloaded->approver_type, 'the rule stays as it was');
        $this->assertSame('{"role":"admin"}', $reloaded->approver_config);
        $this->assertSame(1, (int)$reloaded->required_approvals);
        $this->assertSame($audits, $this->ruleAudits(), 'no audit entry');
    }

    /**
     * The form shown again says so under Required Approvals.
     */
    public function testTheFormShowsTheCountErrorUnderRequiredApprovals(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->setPost(['ApprovalRule' => [
            'name' => 'form-one-approver',
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => $this->oneUserConfig('[{user}, "abc"]'),
            'required_approvals' => '2',
        ]]);
        $app = \Yii::$app;
        $this->assertNotNull($app);
        $ctrl = new class ('approval-rule', $app) extends ApprovalRuleController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };

        $html = $this->withRealViews($ctrl, '/approval-rule/create', static fn (): mixed => $ctrl->actionCreate());

        $this->assertIsString($html);
        $field = $this->between($html, 'field-required-approvals', 'id="approver-count-warning"');
        $this->assertStringContainsString(sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 2, 1), $field);
        $this->assertFalse(ApprovalRule::find()->where(['name' => 'form-one-approver'])->exists(), 'nothing created');
    }

    public function testDeleteRemovesRuleAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $rule = $this->createRule($user->id);
        $id = (int)$rule->id;

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNull(ApprovalRule::findOne($id));
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_APPROVAL_RULE_DELETED,
            'object_id' => $id,
        ]));
    }

    public function testDeleteThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionDelete(9999999);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The audit entries of approval rules.
     */
    private function ruleAudits(): int
    {
        return (int)AuditLog::find()->where(['object_type' => 'approval_rule'])->count();
    }

    /**
     * The part of $html from the first $from on, up to the next $to.
     */
    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "No {$from}");
        $end = strpos($html, $to, $start);
        $this->assertNotFalse($end, "No {$to} after {$from}");

        return substr($html, $start, $end - $start);
    }

    private function createRule(int $createdBy): ApprovalRule
    {
        $r = new ApprovalRule();
        $r->name = 'rule-' . uniqid('', true);
        $r->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $r->approver_config = '{"role":"admin"}';
        $r->required_approvals = 1;
        $r->timeout_minutes = 30;
        $r->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $r->created_by = $createdBy;
        $r->created_at = time();
        $r->updated_at = time();
        $r->save(false);
        return $r;
    }

    private function makeController(): ApprovalRuleController
    {
        return new class ('approval-rule', \Yii::$app) extends ApprovalRuleController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
