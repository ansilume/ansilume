<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\ApprovalRulesController;
use app\models\ApprovalRule;
use app\tests\integration\DbTestCase;

/**
 * Tests API controller structure for approval rules.
 */
class ApprovalRulesApiTest extends DbTestCase
{
    public function testControllerExtendsBaseApiController(): void
    {
        $ref = new \ReflectionClass(ApprovalRulesController::class);
        $this->assertTrue(
            $ref->isSubclassOf(\app\controllers\api\v1\BaseApiController::class),
            'ApprovalRulesController must extend BaseApiController'
        );
    }

    public function testActionIndexExists(): void
    {
        $this->assertTrue(method_exists(ApprovalRulesController::class, 'actionIndex'));
    }

    public function testActionViewExists(): void
    {
        $this->assertTrue(method_exists(ApprovalRulesController::class, 'actionView'));
    }

    public function testActionCreateExists(): void
    {
        $this->assertTrue(method_exists(ApprovalRulesController::class, 'actionCreate'));
    }

    public function testActionUpdateExists(): void
    {
        $this->assertTrue(method_exists(ApprovalRulesController::class, 'actionUpdate'));
    }

    public function testActionDeleteExists(): void
    {
        $this->assertTrue(method_exists(ApprovalRulesController::class, 'actionDelete'));
    }

    public function testApprovalRuleModelValidation(): void
    {
        $rule = new ApprovalRule();
        $this->assertFalse($rule->validate());
        $this->assertArrayHasKey('name', $rule->errors);
        $this->assertArrayHasKey('approver_type', $rule->errors);
    }

    public function testApprovalRuleValidWithRequiredFields(): void
    {
        $rule = new ApprovalRule();
        $rule->name = 'Test Rule';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $rule->approver_config = '{"role": "admin"}';
        $rule->required_approvals = 1;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = 1;
        $this->assertTrue($rule->validate());
    }

    public function testApprovalRuleInvalidApproverType(): void
    {
        $rule = new ApprovalRule();
        $rule->name = 'Test';
        $rule->approver_type = 'invalid';
        $this->assertFalse($rule->validate());
        $this->assertArrayHasKey('approver_type', $rule->errors);
    }

    public function testApprovalRuleInvalidJson(): void
    {
        $rule = new ApprovalRule();
        $rule->name = 'Test';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $rule->approver_config = 'not-json';
        $this->assertFalse($rule->validate());
        $this->assertArrayHasKey('approver_config', $rule->errors);
    }

    public function testApprovalRuleRejectsExcessApprovers(): void
    {
        $rule = new ApprovalRule();
        $rule->name = 'Test Excess';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $rule->approver_config = (string)json_encode(['user_ids' => [1]]);
        $rule->required_approvals = 3;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = 1;
        $this->assertFalse($rule->validate());
        $this->assertArrayHasKey('required_approvals', $rule->errors);
    }

    public function testApprovalRuleAcceptsMatchingApprovers(): void
    {
        $rule = new ApprovalRule();
        $rule->name = 'Test Match';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $rule->approver_config = (string)json_encode(['user_ids' => [1, 2, 3]]);
        $rule->required_approvals = 3;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = 1;
        $this->assertTrue($rule->validate());
    }
}
