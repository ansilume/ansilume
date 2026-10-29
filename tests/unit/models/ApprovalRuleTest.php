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
        $ref->setValue($rule, ['approver_config' => null]);

        $this->assertSame([], $rule->getParsedConfig());
    }

    public function testGetParsedConfigReturnsDecodedJson(): void
    {
        $rule = $this->createPartialMock(ApprovalRule::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setValue($rule, ['approver_config' => '{"role": "admin"}']);

        $this->assertSame(['role' => 'admin'], $rule->getParsedConfig());
    }

    public function testGetParsedConfigReturnsEmptyForInvalidJson(): void
    {
        $rule = $this->createPartialMock(ApprovalRule::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setValue($rule, ['approver_config' => 'not-json']);

        $this->assertSame([], $rule->getParsedConfig());
    }

    /**
     * Entries of user_ids that are no ID name no approver and count none,
     * without a look at the users. Which IDs count, those of users who
     * exist, each once, is tested against the database in
     * tests/integration/models/ApprovalRuleTest.php.
     */
    public function testCountEligibleApproversForUsersCountsNoEntryThatIsNoId(): void
    {
        $rule = $this->createPartialMock(ApprovalRule::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setValue($rule, [
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => json_encode(['user_ids' => ['abc', true, 12.5, null, [5]]]),
        ]);

        $this->assertSame(0, $rule->countEligibleApprovers());
    }

    public function testCountEligibleApproversForUsersEmpty(): void
    {
        $rule = $this->createPartialMock(ApprovalRule::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setValue($rule, [
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => json_encode(['user_ids' => []]),
        ]);

        $this->assertSame(0, $rule->countEligibleApprovers());
    }

    public function testCountEligibleApproversNullForUnknownType(): void
    {
        $rule = $this->createPartialMock(ApprovalRule::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setValue($rule, [
            'approver_type' => 'unknown',
            'approver_config' => '{}',
        ]);

        $this->assertNull($rule->countEligibleApprovers());
    }
}
