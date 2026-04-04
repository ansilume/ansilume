<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\ApprovalRule;
use PHPUnit\Framework\TestCase;

class ApprovalRuleTest extends TestCase
{
    public function testApproverTypeConstants(): void
    {
        $this->assertSame('role', ApprovalRule::APPROVER_TYPE_ROLE);
        $this->assertSame('team', ApprovalRule::APPROVER_TYPE_TEAM);
        $this->assertSame('users', ApprovalRule::APPROVER_TYPE_USERS);
    }

    public function testTimeoutActionConstants(): void
    {
        $this->assertSame('reject', ApprovalRule::TIMEOUT_ACTION_REJECT);
        $this->assertSame('approve', ApprovalRule::TIMEOUT_ACTION_APPROVE);
    }

    public function testApproverTypesReturnsLabels(): void
    {
        $types = ApprovalRule::approverTypes();
        $this->assertArrayHasKey('role', $types);
        $this->assertArrayHasKey('team', $types);
        $this->assertArrayHasKey('users', $types);
    }

    public function testTimeoutActionsReturnsLabels(): void
    {
        $actions = ApprovalRule::timeoutActions();
        $this->assertArrayHasKey('reject', $actions);
        $this->assertArrayHasKey('approve', $actions);
    }

    public function testGetParsedConfigReturnsEmptyForNull(): void
    {
        $rule = $this->createPartialMock(ApprovalRule::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($rule, ['approver_config' => null]);

        $this->assertSame([], $rule->getParsedConfig());
    }

    public function testGetParsedConfigReturnsDecodedJson(): void
    {
        $rule = $this->createPartialMock(ApprovalRule::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($rule, ['approver_config' => '{"role": "admin"}']);

        $this->assertSame(['role' => 'admin'], $rule->getParsedConfig());
    }

    public function testGetParsedConfigReturnsEmptyForInvalidJson(): void
    {
        $rule = $this->createPartialMock(ApprovalRule::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($rule, ['approver_config' => 'not-json']);

        $this->assertSame([], $rule->getParsedConfig());
    }
}
