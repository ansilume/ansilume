<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The E2E suite (bin/tests-e2e.sh, "Container user") checks that the main
 * process of each worker of the dev stack runs as www-data, as the
 * entrypoints' gosu drop makes it: every service of docker-compose.yml that
 * runs the app image with a command of its own but the runners, the
 * notification-worker among them. A worker that ran as root would leave
 * files in the shared runtime that php-fpm's workers cannot write.
 */
final class E2eSuiteContainerUserTest extends TestCase
{
    public function testTheSuiteChecksTheUserOfEveryWorkerOfTheDevStack(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.yml');
        $this->assertIsArray($compose);
        $workers = [];
        foreach ((array)($compose['services'] ?? []) as $name => $service) {
            $name = (string)$name;
            $build = is_array($service) ? ($service['build'] ?? null) : null;
            $appImage = is_array($build) && ($build['dockerfile'] ?? null) === 'docker/php/Dockerfile';
            if ($appImage && isset($service['command']) && !str_starts_with($name, 'runner-')) {
                $workers[] = $name;
            }
        }
        sort($workers);

        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/tests-e2e.sh');
        $this->assertSame(1, preg_match('/^section "Container user \(worker runs as www-data\)"$.*?^\s*for svc in ([^;\n]+); do$/ms', $script, $loop), 'the suite has the check');
        $checked = preg_split('/\s+/', trim($loop[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($checked);

        $this->assertContains('notification-worker', $workers);
        $this->assertSame($workers, $checked);
    }
}
