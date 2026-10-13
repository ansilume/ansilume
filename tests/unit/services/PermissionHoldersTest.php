<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\PermissionHolders;
use PHPUnit\Framework\TestCase;
use yii\rbac\ManagerInterface;

/**
 * An RBAC manager other than DbManager has no tables to read in bulk: it is
 * asked user by user, so the answer stays the manager's own.
 * The DbManager path is covered by the integration test of the same name.
 */
class PermissionHoldersTest extends TestCase
{
    public function testAManagerOtherThanDbManagerIsAskedUserByUser(): void
    {
        $auth = $this->createMock(ManagerInterface::class);
        $auth->method('checkAccess')->willReturnCallback(
            static fn (int|string $userId, string $permission): bool => $permission === 'approval.decide'
                && in_array((int)$userId, [3, 7], true)
        );
        $holders = new PermissionHolders($auth);

        $this->assertSame([7, 3], $holders->among('approval.decide', [7, 5, 3]), 'in the order given');
        $this->assertSame([], $holders->among('job.launch', [7, 5, 3]));
        $this->assertSame([], $holders->among('approval.decide', []));
    }
}
