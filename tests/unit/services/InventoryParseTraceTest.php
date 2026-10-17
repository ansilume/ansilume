<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Inventory;
use app\services\AnsibleInventoryRunner;
use app\services\InventoryService;
use PHPUnit\Framework\TestCase;

/**
 * Parse Inventory hands ansible-inventory's output, every host with its
 * variables, through parameters marked #[\SensitiveParameter] (CLAUDE.md
 * rule 11), so an error raised on the way leaves the variables out of the
 * trace that Yii logs and that the debug error page shows in full.
 *
 * The images set zend.exception_ignore_args = On, which leaves every
 * argument out of traces; the test turns it off, as on a host whose
 * php.ini leaves it off, where the mark alone keeps the value out.
 */
class InventoryParseTraceTest extends TestCase
{
    /** A host variable; a test value, not a secret. */
    private const HOST_PASSWORD = 'trace-marker-host-password-4411';

    /**
     * regression: InventoryService::parseOutput(), extractGroups() and
     * extractHosts() were unmarked, so an error below them put the parse
     * into the trace: a plaintext ansible_password of the inventory's
     * variables included. Here a group lists a host that is no name, which
     * makes extractHosts() fail.
     */
    public function testRegressionTheHostVariablesOfAParseStayOutOfTheTrace(): void
    {
        $output = (string)json_encode([
            '_meta' => ['hostvars' => ['web1' => ['ansible_password' => self::HOST_PASSWORD]]],
            'all' => ['children' => ['web']],
            'web' => ['hosts' => ['web1', ['not-a-host-name']]],
        ]);
        $service = new InventoryService();
        $service->setRunner(new class ($output) extends AnsibleInventoryRunner {
            public function __construct(private readonly string $output)
            {
                parent::__construct();
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function run(string $inventoryPath, ?string $cwd = null): array
            {
                return ['stdout' => $this->output, 'stderr' => '', 'exit_code' => 0, 'error' => null];
            }
        });
        $inventory = new Inventory();
        $inventory->inventory_type = Inventory::TYPE_STATIC;
        $inventory->content = "all:\n  hosts:\n    web1:\n";

        $trace = $this->traceOf(static fn () => $service->resolve($inventory));

        $frames = [];
        foreach ($trace as $frame) {
            if (($frame['class'] ?? null) === InventoryService::class) {
                $frames[$frame['function']] = $frame['args'] ?? [];
            }
        }
        $this->assertArrayHasKey('extractHosts', $frames, 'the error is raised in extractHosts()');
        $this->assertCount(2, $frames['extractHosts']);
        $this->assertContainsOnlyInstancesOf(\SensitiveParameterValue::class, $frames['extractHosts']);
        $this->assertArrayHasKey('parseOutput', $frames);
        $this->assertCount(1, $frames['parseOutput']);
        $this->assertContainsOnlyInstancesOf(\SensitiveParameterValue::class, $frames['parseOutput']);
        $this->assertStringNotContainsString(self::HOST_PASSWORD, (string)json_encode($frames, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * The trace of the TypeError that $call raises, with arguments.
     *
     * @return list<array<string, mixed>>
     */
    private function traceOf(callable $call): array
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            $call();
        } catch (\TypeError $e) {
            return $e->getTrace();
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
        $this->fail('expected a TypeError');
    }
}
