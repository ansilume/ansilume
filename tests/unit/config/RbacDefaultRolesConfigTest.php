<?php

declare(strict_types=1);

namespace app\tests\unit\config;

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
