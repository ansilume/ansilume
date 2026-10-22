<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every action a workflow uses is pinned to a full commit SHA, with the
 * release it belongs to in a comment:
 *
 *     uses: actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803 # v6.1.0
 *
 * A tag such as v6 is moved by the owner of the action, so whoever takes
 * over the action, or its owner's account, changes what runs in the release
 * workflow, which pushes the images. Dependabot (.github/dependabot.yml)
 * proposes new pins and updates their comments.
 *
 * Regression: every action was used by such a tag (actions/checkout@v6).
 */
class WorkflowActionPinTest extends TestCase
{
    private const USES = '/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/';

    public function testRegressionEveryActionIsPinnedToACommit(): void
    {
        $checked = 0;
        foreach ($this->workflows() as $file) {
            $parsed = 0;
            foreach ((array)file($file) as $index => $line) {
                if (!preg_match(self::USES, (string)$line, $match)) {
                    continue;
                }
                $parsed++;
                $where = basename($file) . ':' . ($index + 1);
                if (str_starts_with($match[1], './')) {
                    continue;
                }
                $checked++;
                $this->assertMatchesRegularExpression('#^[\w.-]+/[\w.-]+(?:/[\w./-]+)?@[0-9a-f]{40}$#', $match[1], "{$where}: pinned to a commit");
                $this->assertMatchesRegularExpression('/^ # v\d+\.\d+\.\d+$/', rtrim($match[2]), "{$where}: the release of the commit");
            }
            $this->assertSame($this->usesIn($file), $parsed, basename($file) . ': every uses is on a line of its own');
        }
        $this->assertGreaterThan(20, $checked);
    }

    public function testDependabotProposesNewPins(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3) . '/.github/dependabot.yml');
        $this->assertIsArray($config);
        $actions = array_values(array_filter(
            (array)($config['updates'] ?? []),
            static fn (array $update): bool => ($update['package-ecosystem'] ?? null) === 'github-actions',
        ));

        $this->assertCount(1, $actions);
        $this->assertSame('/', $actions[0]['directory'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function workflows(): array
    {
        $directory = dirname(__DIR__, 3) . '/.github/workflows';
        $files = [...(array)glob($directory . '/*.yml'), ...(array)glob($directory . '/*.yaml')];
        sort($files);
        $this->assertNotEmpty($files);

        return array_map('strval', $files);
    }

    /**
     * The number of uses the workflow has, as GitHub reads it.
     */
    private function usesIn(string $file): int
    {
        $workflow = Yaml::parseFile($file);
        $this->assertIsArray($workflow);
        $count = 0;
        foreach ((array)($workflow['jobs'] ?? []) as $job) {
            $count += isset($job['uses']) ? 1 : 0;
            foreach ((array)($job['steps'] ?? []) as $step) {
                $count += isset($step['uses']) ? 1 : 0;
            }
        }

        return $count;
    }
}
