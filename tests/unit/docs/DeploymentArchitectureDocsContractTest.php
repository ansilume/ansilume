<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\tests\unit\docker\RendersDeployRole;
use PHPUnit\Framework\TestCase;

/**
 * The Architecture table of docs/deployment.md names every container that
 * the deploy role runs, the runners as one row (runner-1..N): an operator
 * who reads it knows each container of the stack and what it does, the
 * notification-worker included.
 */
final class DeploymentArchitectureDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use RendersDeployRole;

    /** The row of the runners. */
    private const RUNNERS = 'runner-1..N';

    public function testTheTableNamesEveryContainerOfTheDeployRole(): void
    {
        $named = [];
        foreach ($this->units('docs/deployment.md', '## Architecture') as $unit) {
            if (preg_match('/^\| `([^`]+)` \|/', $unit, $row) === 1) {
                $named[] = $row[1];
            }
        }
        sort($named);

        $services = [];
        foreach (array_keys((array)($this->renderDeployTemplate(true)['services'] ?? [])) as $service) {
            $services[] = preg_match('/^runner-\d+$/', (string)$service) === 1 ? self::RUNNERS : (string)$service;
        }
        $services = array_values(array_unique($services));
        sort($services);

        $this->assertContains('notification-worker', $services);
        $this->assertSame($services, $named);
    }
}
