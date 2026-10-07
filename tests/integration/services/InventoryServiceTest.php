<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Inventory;
use app\models\Project;
use app\services\AnsibleInventoryRunner;
use app\services\InventoryService;
use app\tests\integration\DbTestCase;

/**
 * Which inventory sources InventoryService hands to ansible-inventory, with a
 * real project, a real inventory and real directories and symlinks. Only the
 * runner is a recording stub, so no ansible process runs.
 *
 * Two checkouts lie side by side whose names share a prefix, like
 * runtime/projects/1 and runtime/projects/12; project 1 is the one under test.
 */
class InventoryServiceTest extends DbTestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/ansilume_inventory_paths_' . bin2hex(random_bytes(6));
        $this->put('1/hosts.yml', "all:\n  hosts:\n    own-host:\n");
        $this->put('1/inventories/prod.yml', "all:\n  hosts:\n    own-prod-host:\n");
        $this->put('12/inventories/hosts.yml', "all:\n  hosts:\n    host-of-project-12:\n");
        $this->put('1-old/hosts.yml', "all:\n  hosts:\n    host-of-an-old-checkout:\n");
        // Links inside checkout 1: three lead out of it, one stays inside.
        symlink($this->base . '/12/inventories', $this->base . '/1/shared');
        symlink($this->base . '/12/inventories/hosts.yml', $this->base . '/1/linked-hosts.yml');
        symlink($this->base . '/1-old/hosts.yml', $this->base . '/1/old-hosts.yml');
        symlink('inventories/prod.yml', $this->base . '/1/prod-link.yml');
        // And a second way to reach checkout 1.
        symlink($this->base . '/1', $this->base . '/checkout-link');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->base);
        parent::tearDown();
    }

    /**
     * Regression: the containment check compared bare string prefixes, so
     * the checkout /x/1 accepted anything under /x/12 or /x/1-old, for
     * example through a symlink committed to the repository, and
     * ansible-inventory listed the hosts of another project's checkout.
     *
     * @return array<string, array{0: string}>
     */
    public static function siblingCheckoutSourceProvider(): array
    {
        return [
            'a symlinked directory into /x/12' => ['shared/hosts.yml'],
            'a symlinked file into /x/12' => ['linked-hosts.yml'],
            'a symlinked file into /x/1-old' => ['old-hosts.yml'],
            // The model rejects '..' today; rows saved before that rule keep it.
            'a stored dot-dot path into /x/12' => ['../12/inventories/hosts.yml'],
        ];
    }

    /**
     * @dataProvider siblingCheckoutSourceProvider
     */
    public function testASourceInASiblingCheckoutSharingThePrefixIsRejected(string $sourcePath): void
    {
        $this->assertFileExists($this->base . '/1/' . $sourcePath, 'the source must exist, or the test proves nothing');
        $runner = $this->recordingRunner();

        $result = $this->service($runner)->resolve($this->fileInventory($this->base . '/1', $sourcePath));

        $this->assertSame('Invalid inventory path.', $result['error']);
        $this->assertSame([], $result['hosts']);
        $this->assertSame([], $runner->calls, 'ansible-inventory must never read a file outside the checkout');
    }

    public function testASourceThatDoesNotExistIsRejected(): void
    {
        $runner = $this->recordingRunner();

        $result = $this->service($runner)->resolve($this->fileInventory($this->base . '/1', 'inventories/missing.yml'));

        $this->assertSame('Invalid inventory path.', $result['error']);
        $this->assertSame([], $runner->calls);
    }

    /**
     * The separator added to the prefix check must not reject what lies in
     * the checkout, nor the checkout itself (a directory inventory source).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sourceInsideTheCheckoutProvider(): array
    {
        return [
            'a file in the checkout root' => ['hosts.yml', '1/hosts.yml'],
            'a nested file' => ['inventories/prod.yml', '1/inventories/prod.yml'],
            'a symlink that stays inside the checkout' => ['prod-link.yml', '1/inventories/prod.yml'],
            'the checkout itself' => ['.', '1'],
        ];
    }

    /**
     * @dataProvider sourceInsideTheCheckoutProvider
     */
    public function testASourceInsideTheCheckoutIsHandedToAnsibleInventory(string $sourcePath, string $expected): void
    {
        $runner = $this->recordingRunner();

        $result = $this->service($runner)->resolve($this->fileInventory($this->base . '/1', $sourcePath));

        $this->assertNull($result['error']);
        $this->assertSame(
            [['path' => (string)realpath($this->base . '/' . $expected), 'cwd' => $this->base . '/1']],
            $runner->calls
        );
    }

    /**
     * Both sides are resolved before they are compared: a checkout reached
     * through a symlink still accepts its own files and still rejects the
     * sibling checkout.
     */
    public function testACheckoutReachedThroughASymlinkIsComparedByItsRealPath(): void
    {
        $runner = $this->recordingRunner();
        $service = $this->service($runner);

        $own = $service->resolve($this->fileInventory($this->base . '/checkout-link', 'hosts.yml'));
        $sibling = $service->resolve($this->fileInventory($this->base . '/checkout-link', 'linked-hosts.yml'));

        $this->assertNull($own['error']);
        $this->assertSame('Invalid inventory path.', $sibling['error']);
        $this->assertSame(
            [['path' => (string)realpath($this->base . '/1/hosts.yml'), 'cwd' => $this->base . '/checkout-link']],
            $runner->calls
        );
    }

    /**
     * A file inventory of a manual project whose checkout is $checkout.
     * Saved without validation, like rows stored before the '..' rule.
     */
    private function fileInventory(string $checkout, string $sourcePath): Inventory
    {
        $user = $this->createUser();
        $project = $this->createProject((int)$user->id);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $project->scm_type);
        $project->local_path = $checkout;
        $project->save(false);

        $inventory = $this->createInventory((int)$user->id);
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->content = null;
        $inventory->source_path = $sourcePath;
        $inventory->project_id = (int)$project->id;
        $inventory->save(false);

        return $inventory;
    }

    private function service(AnsibleInventoryRunner $runner): InventoryService
    {
        $service = new InventoryService();
        $service->setRunner($runner);

        return $service;
    }

    /**
     * @return AnsibleInventoryRunner&object{calls: list<array{path: string, cwd: string|null}>}
     */
    private function recordingRunner(): AnsibleInventoryRunner
    {
        return new class () extends AnsibleInventoryRunner {
            /** @var list<array{path: string, cwd: string|null}> */
            public array $calls = [];

            public function isAvailable(): bool
            {
                return true;
            }

            public function run(string $inventoryPath, ?string $cwd = null): array
            {
                $this->calls[] = ['path' => $inventoryPath, 'cwd' => $cwd];

                return [
                    'stdout' => '{"_meta": {"hostvars": {}}, "all": {"children": ["ungrouped"]}}',
                    'stderr' => '',
                    'exit_code' => 0,
                    'error' => null,
                ];
            }
        };
    }

    private function put(string $relative, string $content): void
    {
        $path = $this->base . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * Never follows symlinks: a link is removed, not what it points at.
     */
    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            self::removeTree($path . '/' . $name);
        }
        rmdir($path);
    }
}
