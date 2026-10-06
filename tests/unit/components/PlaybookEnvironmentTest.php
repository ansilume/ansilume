<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\PlaybookEnvironment;
use app\components\ProcessHardening;
use PHPUnit\Framework\TestCase;

/**
 * Regression: every playbook inherited the runner's full environment, so a
 * playbook could read RUNNER_BOOTSTRAP_SECRET (and in the dev setup the whole
 * .env including APP_SECRET_KEY) with lookup('env', ...).
 */
class PlaybookEnvironmentTest extends TestCase
{
    public function testBuildSetsCallbackAndHomeSettings(): void
    {
        $env = PlaybookEnvironment::build(['PATH' => '/usr/bin'], '/tmp/cb.ndjson');

        $this->assertStringEndsWith('/ansible/callback_plugins', $env['ANSIBLE_CALLBACK_PLUGINS']);
        $this->assertSame('ansilume_callback', $env['ANSIBLE_CALLBACKS_ENABLED']);
        $this->assertSame('ansilume_callback', $env['ANSIBLE_CALLBACK_WHITELIST']);
        $this->assertSame('/tmp/cb.ndjson', $env['ANSILUME_CALLBACK_FILE']);
        $this->assertSame('1', $env['ANSIBLE_FORCE_COLOR']);
        $this->assertSame('1', $env['PYTHONUNBUFFERED']);
        $this->assertSame(PlaybookEnvironment::ANSIBLE_HOME, $env['HOME']);
        $this->assertSame('/usr/bin', $env['PATH']);
    }

    public function testArtifactDirIsOnlySetWhenGiven(): void
    {
        $without = PlaybookEnvironment::build([], '/tmp/cb');
        $with = PlaybookEnvironment::build([], '/tmp/cb', '/tmp/artifacts');

        $this->assertArrayNotHasKey('ANSILUME_ARTIFACT_DIR', $without);
        $this->assertSame('/tmp/artifacts', $with['ANSILUME_ARTIFACT_DIR']);
    }

    public function testPlaybooksNeverSeeRunnerOrServerSecrets(): void
    {
        $env = PlaybookEnvironment::build([
            'PATH' => '/usr/bin',
            'API_URL' => 'http://nginx',
            'RUNNER_NAME' => 'runner-1',
            'RUNNER_BOOTSTRAP_SECRET' => 'leak-canary-bootstrap',
            'RUNNER_TOKEN' => 'leak-canary-token',
            'APP_SECRET_KEY' => 'leak-canary-app',
            'DB_PASSWORD' => 'leak-canary-db',
        ], '/tmp/cb');

        $hidden = [
            'RUNNER_BOOTSTRAP_SECRET',
            'RUNNER_TOKEN',
            'APP_SECRET_KEY',
            'DB_PASSWORD',
            'API_URL',
            'RUNNER_NAME',
        ];
        foreach ($hidden as $name) {
            $this->assertArrayNotHasKey($name, $env);
        }
        $this->assertStringNotContainsString('leak-canary', implode("\n", $env));
    }

    public function testRunnerEnvPassthroughForwardsListedVariables(): void
    {
        $env = PlaybookEnvironment::build([
            'PATH' => '/usr/bin',
            'RUNNER_ENV_PASSTHROUGH' => 'AWS_PROFILE, OP_SERVICE_ACCOUNT_TOKEN',
            'AWS_PROFILE' => 'prod',
            'OP_SERVICE_ACCOUNT_TOKEN' => 'ops-token',
            'NOT_LISTED' => 'x',
        ], '/tmp/cb');

        $this->assertSame('prod', $env['AWS_PROFILE']);
        $this->assertSame('ops-token', $env['OP_SERVICE_ACCOUNT_TOKEN']);
        $this->assertArrayNotHasKey('NOT_LISTED', $env);
        $this->assertArrayNotHasKey('RUNNER_ENV_PASSTHROUGH', $env);
    }

    public function testRunnerEnvPassthroughCannotForwardTheBootstrapSecret(): void
    {
        $env = PlaybookEnvironment::build([
            'RUNNER_ENV_PASSTHROUGH' => 'RUNNER_BOOTSTRAP_SECRET',
            'RUNNER_BOOTSTRAP_SECRET' => 'leak-canary',
        ], '/tmp/cb');

        $this->assertArrayNotHasKey('RUNNER_BOOTSTRAP_SECRET', $env);
    }

    public function testDroppedNamesListsOnlyVariablesAnOperatorMightExpect(): void
    {
        $dropped = PlaybookEnvironment::droppedNames([
            'PATH' => '/usr/bin',
            'ANSIBLE_HOST_KEY_CHECKING' => 'False',
            'AWS_SECRET_ACCESS_KEY' => 'x',
            'OP_SERVICE_ACCOUNT_TOKEN' => 'x',
            'FORWARDED_ON_PURPOSE' => 'x',
            'RUNNER_ENV_PASSTHROUGH' => 'FORWARDED_ON_PURPOSE',
            'RUNNER_NAME' => 'runner-1',
            'RUNNER_BOOTSTRAP_SECRET' => 'x',
            'API_URL' => 'http://nginx',
            'PHP_VERSION' => '8.2',
            'PHPIZE_DEPS' => 'autoconf',
            'HOSTNAME' => 'abc',
            'HOME' => '/var/www',
            'APP_SECRET_KEY' => 'x',
            // Ansilume's own configuration, e.g. a dev runner that loaded .env:
            'DB_HOST' => 'db',
            'SMTP_HOST' => 'mailhog',
            'YII_ENV' => 'dev',
            'NGINX_PORT' => '8080',
            'ADMIN_EMAIL' => 'admin@example.com',
        ]);

        $this->assertSame(['AWS_SECRET_ACCESS_KEY', 'OP_SERVICE_ACCOUNT_TOKEN'], $dropped);
    }

    public function testStartupNoticesNameDroppedVariablesAndThePassthroughSetting(): void
    {
        $notices = PlaybookEnvironment::startupNotices(
            ['PATH' => '/usr/bin', 'AWS_PROFILE' => 'prod'],
            new ProcessHardening(static fn (int $option, int $arg): int => 0)
        );

        $this->assertCount(1, $notices);
        $this->assertStringContainsString('AWS_PROFILE', $notices[0]);
        $this->assertStringContainsString('RUNNER_ENV_PASSTHROUGH', $notices[0]);
    }

    public function testStartupNoticesFlagReservedNamesInPassthrough(): void
    {
        $notices = PlaybookEnvironment::startupNotices(
            [
                'PATH' => '/usr/bin',
                'RUNNER_ENV_PASSTHROUGH' => 'AWS_PROFILE, RUNNER_BOOTSTRAP_SECRET DB_PASSWORD',
                'AWS_PROFILE' => 'prod',
            ],
            new ProcessHardening(static fn (int $option, int $arg): int => 0)
        );

        $this->assertCount(1, $notices);
        $this->assertStringContainsString('RUNNER_BOOTSTRAP_SECRET, DB_PASSWORD', $notices[0]);
        $this->assertStringContainsString('reserved for Ansilume', $notices[0]);
    }

    public function testStartupNoticesWarnWhenRunnerStaysDumpable(): void
    {
        $dumpable = PlaybookEnvironment::startupNotices(
            ['PATH' => '/usr/bin'],
            new ProcessHardening(static fn (int $option, int $arg): int => 1)
        );
        $unknown = PlaybookEnvironment::startupNotices(
            ['PATH' => '/usr/bin'],
            new ProcessHardening(static fn (int $option, int $arg): int => -1)
        );

        $this->assertCount(1, $dumpable);
        $this->assertStringContainsString('non-dumpable', $dumpable[0]);
        $this->assertStringContainsString(ProcessHardening::UNAVAILABLE_REASON, $dumpable[0]);
        $this->assertStringContainsString('RUNNER_BOOTSTRAP_SECRET', $dumpable[0]);
        $this->assertSame($dumpable, $unknown);
    }

    public function testNoStartupNoticesWhenNothingIsDroppedAndTheRunnerIsHardened(): void
    {
        $notices = PlaybookEnvironment::startupNotices(
            ['PATH' => '/usr/bin', 'RUNNER_NAME' => 'runner-1', 'API_URL' => 'http://nginx'],
            new ProcessHardening(static fn (int $option, int $arg): int => 0)
        );

        $this->assertSame([], $notices);
    }
}
