<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Inventory;
use app\models\Project;
use app\services\InventoryService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for InventoryService guard-clause paths, output parsing,
 * path traversal protection, and file-based inventory resolution.
 *
 * Uses anonymous subclasses to stub isAvailable(), runAnsibleInventory(),
 * and resolveProjectPath() so no real process is spawned and no DB is hit.
 */
class InventoryServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ansilume_inv_test_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a service stub that doesn't call ansible-inventory.
     */
    private function makeService(
        bool $available = true,
        ?array $runResult = null,
        ?string $projectPath = null,
    ): InventoryService {
        $stubProjectPath = $projectPath;

        return new class($available, $runResult, $stubProjectPath) extends InventoryService {
            public ?string $lastInventoryPath = null;
            public ?string $lastCwd = null;

            public function __construct(
                private readonly bool $stubAvailable,
                private readonly ?array $stubRunResult,
                private readonly ?string $stubProjectPath,
            ) {
            }

            protected function isAvailable(): bool
            {
                return $this->stubAvailable;
            }

            protected function runAnsibleInventory(string $inventoryPath, ?string $cwd = null): array
            {
                $this->lastInventoryPath = $inventoryPath;
                $this->lastCwd = $cwd;

                if ($this->stubRunResult !== null) {
                    return $this->stubRunResult;
                }

                return ['groups' => [], 'hosts' => [], 'error' => null];
            }

            protected function resolveProjectPath(Project $project): ?string
            {
                return $this->stubProjectPath;
            }
        };
    }

    /**
     * Create a file-based service that actually resolves file paths
     * but still stubs the process execution and project path.
     */
    private function makeFileService(
        string $projectPath,
        ?array $runResult = null,
    ): InventoryService {
        return new class($projectPath, $runResult) extends InventoryService {
            public ?string $lastInventoryPath = null;
            public ?string $lastCwd = null;

            public function __construct(
                private readonly string $stubProjectPath,
                private readonly ?array $stubRunResult,
            ) {
            }

            protected function isAvailable(): bool
            {
                return true;
            }

            protected function runAnsibleInventory(string $inventoryPath, ?string $cwd = null): array
            {
                $this->lastInventoryPath = $inventoryPath;
                $this->lastCwd = $cwd;

                if ($this->stubRunResult !== null) {
                    return $this->stubRunResult;
                }

                return ['groups' => [], 'hosts' => [], 'error' => null];
            }

            protected function resolveProjectPath(Project $project): ?string
            {
                return $this->stubProjectPath;
            }
        };
    }

    private function makeInventory(string $type = Inventory::TYPE_STATIC, ?string $content = null, ?string $sourcePath = null): Inventory
    {
        $inv = new class() extends Inventory {
            private ?Project $_stubProject = null;

            public function init(): void
            {
            }

            public static function tableName(): string
            {
                return 'inventory';
            }

            public function setStubProject(?Project $project): void
            {
                $this->_stubProject = $project;
            }

            public function __get($name)
            {
                if ($name === 'project') {
                    return $this->_stubProject;
                }
                return parent::__get($name);
            }
        };
        $inv->inventory_type = $type;
        $inv->content = $content;
        $inv->source_path = $sourcePath;
        return $inv;
    }

    private function makeProject(): Project
    {
        $p = new class() extends Project {
            public function init(): void
            {
            }

            public static function tableName(): string
            {
                return 'project';
            }
        };
        $p->scm_type = Project::SCM_TYPE_MANUAL;
        return $p;
    }

    // =========================================================================
    // resolve() dispatch
    // =========================================================================

    public function testResolveReturnsErrorWhenNotAvailable(): void
    {
        $service = $this->makeService(available: false);
        $inv = $this->makeInventory(content: "[web]\nhost1");

        $result = $service->resolve($inv);

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('not installed', $result['error']);
        $this->assertSame([], $result['groups']);
        $this->assertSame([], $result['hosts']);
    }

    public function testResolveUnknownType(): void
    {
        $service = $this->makeService();
        $inv = $this->makeInventory(type: 'bogus');

        $result = $service->resolve($inv);

        $this->assertStringContainsString('Unknown inventory type', $result['error']);
    }

    public function testResolveDynamicDelegatesToResolveFile(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0750, true);
        file_put_contents($projectDir . '/hosts.py', '#!/usr/bin/env python');

        $service = $this->makeFileService($projectDir);
        $project = $this->makeProject();
        $inv = $this->makeInventory(type: Inventory::TYPE_DYNAMIC, sourcePath: 'hosts.py');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        $this->assertNull($result['error']);
        $this->assertNotNull($service->lastInventoryPath);
        $this->assertStringEndsWith('/hosts.py', $service->lastInventoryPath);
    }

    // =========================================================================
    // resolveStatic()
    // =========================================================================

    public function testResolveStaticEmptyContent(): void
    {
        $service = $this->makeService();
        $inv = $this->makeInventory(content: '   ');

        $result = $service->resolve($inv);

        $this->assertStringContainsString('empty', $result['error']);
    }

    public function testResolveStaticNullContent(): void
    {
        $service = $this->makeService();
        $inv = $this->makeInventory(content: null);

        $result = $service->resolve($inv);

        $this->assertStringContainsString('empty', $result['error']);
    }

    public function testResolveStaticDelegatesToRun(): void
    {
        $expected = [
            'groups' => ['web' => ['hosts' => ['host1'], 'children' => [], 'vars' => []]],
            'hosts'  => ['host1' => ['ansible_host' => '10.0.0.1']],
            'error'  => null,
        ];
        $service = $this->makeService(runResult: $expected);
        $inv = $this->makeInventory(content: "[web]\nhost1 ansible_host=10.0.0.1");

        $result = $service->resolve($inv);

        $this->assertSame($expected, $result);
        $this->assertNotNull($service->lastInventoryPath);
        $this->assertNull($service->lastCwd);
    }

    public function testResolveStaticTempFileIsCleanedUp(): void
    {
        $service = $this->makeService();
        $inv = $this->makeInventory(content: "[web]\nhost1");

        $service->resolve($inv);

        // The temp file should have been deleted (we can check that lastInventoryPath was set)
        $this->assertNotNull($service->lastInventoryPath);
        // Temp file should no longer exist
        $this->assertFileDoesNotExist($service->lastInventoryPath);
    }

    // =========================================================================
    // resolveFile() — project and path guards
    // =========================================================================

    public function testResolveFileNoProject(): void
    {
        $service = $this->makeService();
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: 'hosts');
        $inv->setStubProject(null);

        $result = $service->resolve($inv);

        $this->assertStringContainsString('No project assigned', $result['error']);
    }

    public function testResolveFileProjectPathNull(): void
    {
        $service = $this->makeService(projectPath: null);
        $project = $this->makeProject();
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: 'hosts');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        $this->assertStringContainsString('workspace not found', $result['error']);
    }

    public function testResolveFileProjectPathNotExists(): void
    {
        $service = $this->makeService(projectPath: '/nonexistent/path');
        $project = $this->makeProject();
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: 'hosts');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        $this->assertStringContainsString('workspace not found', $result['error']);
    }

    public function testResolveFileInventoryNotFound(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0750, true);

        $service = $this->makeFileService($projectDir);
        $project = $this->makeProject();
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: 'nonexistent-inventory');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        $this->assertStringContainsString('Invalid inventory path', $result['error']);
    }

    public function testResolveFileValidPath(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0750, true);
        file_put_contents($projectDir . '/hosts.ini', "[web]\nhost1");

        $service = $this->makeFileService($projectDir);
        $project = $this->makeProject();
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: 'hosts.ini');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        $this->assertNull($result['error']);
        $this->assertSame(realpath($projectDir . '/hosts.ini'), $service->lastInventoryPath);
        $this->assertSame($projectDir, $service->lastCwd);
    }

    public function testResolveFileNestedPath(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir . '/inventories/production', 0750, true);
        file_put_contents($projectDir . '/inventories/production/hosts', "host1\nhost2");

        $service = $this->makeFileService($projectDir);
        $project = $this->makeProject();
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: 'inventories/production/hosts');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        $this->assertNull($result['error']);
        $this->assertStringEndsWith('/inventories/production/hosts', $service->lastInventoryPath);
    }

    // =========================================================================
    // resolveFile() — path traversal protection
    // =========================================================================

    public function testResolveFileBlocksPathTraversalDotDot(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0750, true);
        // Create a file outside the project
        file_put_contents($this->tempDir . '/secret.txt', 'password=hunter2');

        $service = $this->makeFileService($projectDir);
        $project = $this->makeProject();
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: '../secret.txt');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        $this->assertStringContainsString('Invalid inventory path', $result['error']);
        $this->assertNull($service->lastInventoryPath);
    }

    public function testResolveFileBlocksAbsolutePath(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0750, true);

        $service = $this->makeFileService($projectDir);
        $project = $this->makeProject();
        // Absolute path via leading slash (gets ltrimmed, but won't resolve inside project)
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: '/etc/passwd');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        $this->assertStringContainsString('Invalid inventory path', $result['error']);
    }

    public function testResolveFileBlocksSymlinkOutsideProject(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir, 0750, true);

        // Create a file outside the project
        file_put_contents($this->tempDir . '/secret.key', 'private-key-data');

        // Create a symlink inside the project pointing outside
        symlink($this->tempDir . '/secret.key', $projectDir . '/hosts');

        $service = $this->makeFileService($projectDir);
        $project = $this->makeProject();
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: 'hosts');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        // realpath() resolves the symlink, which points outside the project
        $this->assertStringContainsString('Invalid inventory path', $result['error']);
        $this->assertNull($service->lastInventoryPath);
    }

    public function testResolveFileBlocksDeepTraversal(): void
    {
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir . '/subdir', 0750, true);

        $service = $this->makeFileService($projectDir);
        $project = $this->makeProject();
        $inv = $this->makeInventory(type: Inventory::TYPE_FILE, sourcePath: 'subdir/../../../../../../etc/passwd');
        $inv->setStubProject($project);

        $result = $service->resolve($inv);

        $this->assertStringContainsString('Invalid inventory path', $result['error']);
    }

    // =========================================================================
    // parseOutput() — JSON parsing edge cases
    // =========================================================================

    public function testParseOutputValidJson(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        $json = json_encode([
            '_meta' => [
                'hostvars' => [
                    'host1' => ['ansible_host' => '10.0.0.1'],
                    'host2' => ['ansible_host' => '10.0.0.2'],
                ],
            ],
            'all' => [
                'children' => ['ungrouped', 'web'],
            ],
            'web' => [
                'hosts' => ['host1', 'host2'],
                'vars'  => ['http_port' => 80],
            ],
            'ungrouped' => [
                'hosts' => [],
            ],
        ]);

        $result = $method->invoke($service, $json);

        $this->assertNull($result['error']);
        $this->assertArrayHasKey('web', $result['groups']);
        $this->assertSame(['host1', 'host2'], $result['groups']['web']['hosts']);
        $this->assertSame(80, $result['groups']['web']['vars']['http_port']);
        $this->assertArrayHasKey('host1', $result['hosts']);
        $this->assertSame('10.0.0.1', $result['hosts']['host1']['ansible_host']);
        $this->assertArrayHasKey('host2', $result['hosts']);
    }

    public function testParseOutputInvalidJson(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        $result = $method->invoke($service, 'not json');

        $this->assertStringContainsString('Failed to parse', $result['error']);
    }

    public function testParseOutputEmptyString(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        $result = $method->invoke($service, '');

        $this->assertStringContainsString('Failed to parse', $result['error']);
    }

    public function testParseOutputHostsNotInMeta(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        $json = json_encode([
            '_meta' => ['hostvars' => []],
            'web'   => ['hosts' => ['orphan-host']],
        ]);

        $result = $method->invoke($service, $json);

        $this->assertNull($result['error']);
        $this->assertArrayHasKey('orphan-host', $result['hosts']);
        $this->assertSame([], $result['hosts']['orphan-host']);
    }

    public function testParseOutputMissingMetaKey(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        $json = json_encode([
            'web' => ['hosts' => ['host1']],
        ]);

        $result = $method->invoke($service, $json);

        $this->assertNull($result['error']);
        $this->assertArrayHasKey('host1', $result['hosts']);
    }

    public function testParseOutputNonArrayGroupData(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        $json = json_encode([
            '_meta' => ['hostvars' => []],
            'web'   => ['hosts' => ['host1']],
            'broken' => 'not an array',
            'also_broken' => 42,
        ]);

        $result = $method->invoke($service, $json);

        $this->assertNull($result['error']);
        $this->assertArrayHasKey('web', $result['groups']);
        $this->assertArrayNotHasKey('broken', $result['groups']);
        $this->assertArrayNotHasKey('also_broken', $result['groups']);
    }

    public function testParseOutputSortsGroupsAndHosts(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        $json = json_encode([
            '_meta' => ['hostvars' => ['z-host' => [], 'a-host' => []]],
            'z-group' => ['hosts' => ['z-host']],
            'a-group' => ['hosts' => ['a-host']],
        ]);

        $result = $method->invoke($service, $json);

        $this->assertSame(['a-group', 'z-group'], array_keys($result['groups']));
        $this->assertSame(['a-host', 'z-host'], array_keys($result['hosts']));
    }

    public function testParseOutputGroupWithChildrenAndVars(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        $json = json_encode([
            '_meta' => ['hostvars' => []],
            'parent_group' => [
                'children' => ['child_a', 'child_b'],
                'vars'     => ['env' => 'production'],
            ],
            'child_a' => ['hosts' => ['host-a']],
            'child_b' => ['hosts' => ['host-b']],
        ]);

        $result = $method->invoke($service, $json);

        $this->assertNull($result['error']);
        $this->assertSame(['child_a', 'child_b'], $result['groups']['parent_group']['children']);
        $this->assertSame('production', $result['groups']['parent_group']['vars']['env']);
    }

    public function testParseOutputGroupMissingOptionalKeys(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        // Group with no hosts, children, or vars keys at all
        $json = json_encode([
            '_meta' => ['hostvars' => []],
            'empty_group' => [],
        ]);

        $result = $method->invoke($service, $json);

        $this->assertNull($result['error']);
        $this->assertSame([], $result['groups']['empty_group']['hosts']);
        $this->assertSame([], $result['groups']['empty_group']['children']);
        $this->assertSame([], $result['groups']['empty_group']['vars']);
    }

    public function testParseOutputUnicodeHostNames(): void
    {
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'parseOutput');

        $json = json_encode([
            '_meta' => ['hostvars' => ['münchen-db01' => ['region' => 'de']]],
            'db' => ['hosts' => ['münchen-db01']],
        ], JSON_UNESCAPED_UNICODE);

        $result = $method->invoke($service, $json);

        $this->assertNull($result['error']);
        $this->assertArrayHasKey('münchen-db01', $result['hosts']);
        $this->assertSame('de', $result['hosts']['münchen-db01']['region']);
    }

    // =========================================================================
    // runAnsibleInventory() — process handling edge cases
    // =========================================================================

    public function testRunAnsibleInventoryReturnStructure(): void
    {
        // Verify the method always returns the expected structure
        $service = new InventoryService();
        $method = new \ReflectionMethod($service, 'runAnsibleInventory');

        $result = $method->invoke($service, '/nonexistent/inventory/file');

        // Should have the correct keys regardless of outcome
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('hosts', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertIsArray($result['groups']);
        $this->assertIsArray($result['hosts']);
    }

    public function testTimeoutPropertyIsConfigurable(): void
    {
        $service = new InventoryService();
        $this->assertSame(30, $service->timeout);

        $service->timeout = 60;
        $this->assertSame(60, $service->timeout);
    }
}
