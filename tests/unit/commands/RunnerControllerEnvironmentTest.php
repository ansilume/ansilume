<?php

declare(strict_types=1);

namespace app\tests\unit\commands;

use app\commands\RunnerController;
use app\components\ProcessHardening;
use app\components\RunnerHttpClient;
use app\components\RunnerTokenResolver;
use app\tests\unit\TemporaryEnvironment;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * Regression: the runner started ansible-playbook with array_merge(getenv(), ...),
 * so every playbook could read RUNNER_BOOTSTRAP_SECRET and RUNNER_TOKEN with
 * lookup('env', ...). In the dev setup it also saw the complete .env.
 */
class RunnerControllerEnvironmentTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function playbookEnv(): array
    {
        $controller = new RunnerController('runner', \Yii::$app);
        $method = new \ReflectionMethod(RunnerController::class, 'buildProcessEnv');
        $method->setAccessible(true);
        /** @var array<string, string> $env */
        $env = $method->invoke($controller, '/tmp/cb.ndjson');

        return $env;
    }

    public function testPlaybooksDoNotSeeRunnerOrServerSecrets(): void
    {
        $temp = new TemporaryEnvironment([
            'RUNNER_BOOTSTRAP_SECRET' => 'leak-canary-bootstrap',
            'RUNNER_TOKEN' => 'leak-canary-token',
            'APP_SECRET_KEY' => 'leak-canary-app',
            'DB_PASSWORD' => 'leak-canary-db',
        ]);
        try {
            $env = $this->playbookEnv();
        } finally {
            $temp->restore();
        }

        $this->assertStringNotContainsString('leak-canary', implode("\n", $env));
        $this->assertSame('/tmp/cb.ndjson', $env['ANSILUME_CALLBACK_FILE']);
        $this->assertSame('/var/www/runtime/ansible-home', $env['HOME']);
    }

    /**
     * The runner start-up log must tell operators which variables playbooks
     * will no longer see (documented in docs/runners.md and docs/updating.md).
     */
    public function testStartLogsWhichVariablesPlaybooksWillNotSee(): void
    {
        $http = new class ('http://test-server', '') extends RunnerHttpClient {
            public function post(string $path, array $body): ?array
            {
                return ['ok' => true, 'data' => ['runner_name' => 'runner-1', 'group_name' => 'default']];
            }

            public function getLastHttpStatus(): int
            {
                return 200;
            }
        };
        $controller = new class ('runner', \Yii::$app, $http) extends RunnerController {
            public string $out = '';

            public function __construct($id, $module, RunnerHttpClient $http)
            {
                parent::__construct($id, $module);
                $this->http = $http;
                $this->tokenResolver = new class ($http, $this) extends RunnerTokenResolver {
                    public function resolve(): string
                    {
                        return 'runner-token';
                    }
                };
            }

            protected function registerSignalHandlers(): void
            {
            }

            protected function pollLoop(): void
            {
            }

            public function stdout($string): int
            {
                $this->out .= (string)$string;
                return 0;
            }

            public function stderr($string): int
            {
                return 0;
            }
        };

        $_ENV['API_URL'] = 'http://test-server';
        $temp = new TemporaryEnvironment(['AWS_PROFILE' => 'prod']);
        try {
            $exit = $controller->actionStart();
        } finally {
            $temp->restore();
            unset($_ENV['API_URL']);
        }

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('not forwarded to playbooks: ', $controller->out);
        $this->assertStringContainsString('AWS_PROFILE', $controller->out);
        $this->assertStringContainsString('RUNNER_ENV_PASSTHROUGH', $controller->out);
        if ((new ProcessHardening())->isDumpable() !== false) {
            $this->assertStringContainsString('could not be marked non-dumpable', $controller->out);
        }
    }

    public function testRunnerEnvPassthroughForwardsListedVariables(): void
    {
        $temp = new TemporaryEnvironment([
            'RUNNER_ENV_PASSTHROUGH' => 'AWS_PROFILE',
            'AWS_PROFILE' => 'prod',
        ]);
        try {
            $env = $this->playbookEnv();
        } finally {
            $temp->restore();
        }

        $this->assertSame('prod', $env['AWS_PROFILE']);
        $this->assertArrayNotHasKey('RUNNER_ENV_PASSTHROUGH', $env);
    }
}
