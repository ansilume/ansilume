<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\components\AuthManager;
use PHPUnit\Framework\TestCase;

/**
 * Web requests and console commands must resolve the same RBAC permissions:
 * who may approve is decided in both (a web launch and a vote in the web
 * application, a scheduled launch, an approval timeout and a workflow step
 * in the console), and a launch must not pass where a vote is refused.
 *
 * Regression: the console application configured no default roles while
 * the web application gave every user the viewer role, so a permission
 * granted to the viewer role counted on the web but not in the console.
 *
 * The tests run with the same manager class, but without the default role:
 * they build users without the viewer's permissions, as an admin can by
 * editing the viewer role, and the tests that need the default role set it
 * themselves (GuestAccessTest, AuthManagerTest).
 */
class RbacDefaultRolesConfigTest extends TestCase
{
    public function testWebAndConsoleConfigureTheSameAuthManager(): void
    {
        $web = $this->authManager('web.php');
        $console = $this->authManager('console.php');

        $this->assertSame(['viewer'], $web['defaultRoles'] ?? null, 'precondition: the web application\'s default role');
        $this->assertSame($web, $console);
    }

    /**
     * regression: signed-out visitors held the default role viewer.
     * yii\rbac\DbManager applied the default roles to the user ID null of a
     * visitor who had not signed in, so they passed every access rule that
     * names a viewer permission. AuthManager applies them to signed-in users
     * only.
     */
    public function testTheDefaultRoleIsGivenToSignedInUsersOnly(): void
    {
        $this->assertSame(AuthManager::class, $this->authManager('web.php')['class'] ?? null);
        $this->assertSame(AuthManager::class, $this->authManager('console.php')['class'] ?? null);
    }

    public function testTheTestsUseTheSameClassWithoutADefaultRole(): void
    {
        $web = $this->authManager('web.php');
        $test = $this->authManager('test.php');

        $this->assertSame($web['class'] ?? null, $test['class'] ?? null);
        $this->assertArrayNotHasKey('defaultRoles', $test);
    }

    /**
     * @return array<string, mixed>
     */
    private function authManager(string $file): array
    {
        $config = require dirname(__DIR__, 3) . '/config/' . $file;
        $this->assertIsArray($config);
        $this->assertIsArray($config['components'] ?? null);
        $authManager = $config['components']['authManager'] ?? null;
        $this->assertIsArray($authManager, $file);

        return $authManager;
    }
}
