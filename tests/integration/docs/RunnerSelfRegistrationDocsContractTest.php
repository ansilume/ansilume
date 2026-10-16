<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\components\RunnerHttpClient;
use app\controllers\api\runner\RegisterController;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\unit\components\CapturingController;
use app\tests\unit\components\RetryCountingTokenResolver;
use app\tests\unit\docs\ReadsOperatorDocs;
use app\tests\unit\docs\RunnerManagementDocsContractTest;
use yii\helpers\FileHelper;

/**
 * What docs/runners.md says about self-registration with the bootstrap
 * secret is what the runner (RunnerTokenResolver) and the server
 * (api\runner\RegisterController) do: the runner names its group with
 * RUNNER_GROUP; a named group must exist, and registering in one that does
 * not is refused with the answer and the log line the docs quote, and
 * creates nothing; without a name the runner joins the group `default`,
 * which the server creates where no group has that name and makes the
 * runner group of the job templates without one whose names begin with
 * Selftest or Demo —. So the bootstrap secret creates a runner group, which
 * the documented limit of security rule 16 says.
 *
 * Regression: runners.md said that runners which register themselves are
 * placed in the "default" group, documented no RUNNER_GROUP, and did not
 * say that the bootstrap secret creates a runner group. Each test fails on
 * the docs from before.
 */
class RunnerSelfRegistrationDocsContractTest extends WebControllerTestCase
{
    use ReadsOperatorDocs;

    /** A test value of RUNNER_BOOTSTRAP_SECRET, not a secret. */
    private const SECRET = 'docs-bootstrap-secret';

    /** What the docs write in place of a group's name. */
    private const PLACEHOLDER = '<name>';

    /** The runner's environment variables that self-registration reads. */
    private const RUNNER_ENV = ['RUNNER_TOKEN', 'RUNNER_NAME', 'RUNNER_GROUP', 'RUNNER_BOOTSTRAP_SECRET'];

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    private string $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::RUNNER_ENV as $name) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name]);
        }
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = self::SECRET;
        $this->cacheDir = sys_get_temp_dir() . '/docs-runner-register-' . bin2hex(random_bytes(4));
        FileHelper::createDirectory($this->cacheDir, 0700);
        // The server registers runners in the name of its first user.
        $this->createUser('docs-register-owner');
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            unset($_ENV[$name]);
            if ($value !== null) {
                $_ENV[$name] = $value;
            }
        }
        FileHelper::removeDirectory($this->cacheDir);
        parent::tearDown();
    }

    /**
     * A runner whose RUNNER_GROUP names a group that does not exist, also
     * `default`, is refused: the server answers 400 with the text the docs
     * quote and creates nothing, and the runner logs the line the docs
     * quote and ends up without a token.
     */
    public function testANamedGroupThatDoesNotExistIsRefusedAsTheDocsQuoteIt(): void
    {
        $this->renameTheDefaultGroup();
        $env = $this->flat('docs/runners.md', '## Environment Variables');
        $optionA = $this->flat('docs/runners.md', '### Option A: Self-registration');
        foreach (['docs-no-such-group-' . uniqid(), 'default'] as $name) {
            $before = [(int)Runner::find()->count(), (int)RunnerGroup::find()->count()];

            $answer = $this->register(['name' => 'docs-runner-' . uniqid(), 'group' => $name]);

            $this->assertSame(400, \Yii::$app->response->statusCode, $name);
            $this->assertFalse($answer['ok'] ?? null, $name);
            $error = (string)($answer['error'] ?? '');
            $this->assertStringContainsString('"' . $name . '"', $error);
            $this->assertSame($before, [(int)Runner::find()->count(), (int)RunnerGroup::find()->count()], "{$name}: nothing is created");
            $runner = $this->runnerRegisters($name, $answer, 400);
            $this->assertSame(['group' => $name], array_intersect_key($runner['payload'], ['group' => true]), 'The runner sends RUNNER_GROUP as group.');
            $this->assertStringContainsString('ERROR: Registration failed: ' . $error . "\n", $runner['stderr']);
            $this->assertSame('', $runner['token'], 'The runner gets no token.');

            $quoted = str_replace($name, self::PLACEHOLDER, $error);
            $this->assertMentions(
                '| `RUNNER_GROUP` | No | Name of the runner group to register in, used during self-registration. The group must exist: otherwise the server refuses the registration with `'
                    . $quoted . '` and the runner exits without a token. Empty or not set: the group `default`, which the server creates if no group has that name. |',
                $env,
                'The variables of runners.md'
            );
            $this->assertMentions(
                'The server creates the group `default` if no group has that name, but never a group that `group` names: for a group that does not exist it answers `400` `'
                    . $quoted . '` and creates nothing, and the runner logs `ERROR: Registration failed: ' . $quoted . '` and exits.',
                $optionA,
                'Option A of runners.md'
            );
        }
        $this->assertMentions(
            '2. Runner sends `POST /api/runner/v1/register` with `{ name, bootstrap_secret }`, and `group` with the value of `RUNNER_GROUP` when that is set',
            $optionA,
            'Option A of runners.md'
        );
    }

    /**
     * A runner without RUNNER_GROUP sends no group and joins `default`,
     * which the server creates where no group has that name; the server makes
     * it the runner group of the job templates without one whose names begin
     * with Selftest or Demo —, as the seeded ones do, and leaves every other
     * job template as it is. A second runner joins the same group.
     */
    public function testWithoutAGroupTheRunnerJoinsDefaultWhichTheServerCreates(): void
    {
        $this->renameTheDefaultGroup();
        $owner = (int)$this->createUser('docs-default-owner')->id;
        $elsewhere = $this->createRunnerGroup($owner);
        $templates = [
            'selftest' => $this->template('Selftest — docs ', null, $elsewhere, $owner),
            'demo' => $this->template('Demo — docs ', null, $elsewhere, $owner),
            'other' => $this->template('docs-other ', null, $elsewhere, $owner),
            'grouped' => $this->template('Selftest — docs grouped ', (int)$elsewhere->id, $elsewhere, $owner),
        ];
        $runner = $this->runnerRegisters(null, ['ok' => false, 'error' => 'not used'], 400);
        $this->assertArrayNotHasKey('group', $runner['payload'], 'Without RUNNER_GROUP the runner names no group.');

        $first = $this->register(['name' => 'docs-self-' . uniqid()]);
        $second = $this->register(['name' => 'docs-self-' . uniqid(), 'group' => '']);

        $default = RunnerGroup::findOne(['name' => 'default']);
        $this->assertNotNull($default, 'The server creates the group default.');
        foreach ([$first, $second] as $answer) {
            $this->assertTrue($answer['ok'] ?? null);
            $this->assertSame(['group_id' => (int)$default->id, 'group_name' => 'default'], array_intersect_key((array)($answer['data'] ?? []), ['group_id' => true, 'group_name' => true]));
        }
        $this->assertSame(1, (int)RunnerGroup::find()->where(['name' => 'default'])->count(), 'Once.');
        $groups = array_map(static fn (JobTemplate $template): ?int => JobTemplate::findOne($template->id)?->runner_group_id, $templates);
        $this->assertSame(
            ['selftest' => (int)$default->id, 'demo' => (int)$default->id, 'other' => null, 'grouped' => (int)$elsewhere->id],
            array_map(static fn (?int $id): ?int => $id === null ? null : (int)$id, $groups)
        );

        $this->assertMentions(
            'A runner group has no token: each runner authenticates with a token of its own. A runner that registers itself joins the group that its `RUNNER_GROUP` names, which must exist, or the group `default` when `RUNNER_GROUP` is empty',
            $this->flat('docs/runners.md', '## Runner Groups'),
            'The Runner Groups section of runners.md'
        );
        $this->assertMentions(
            'When the server creates `default` for such a runner, it also makes it the runner group of the job templates that have none and whose names begin with `Selftest` or `Demo —`, as those it seeds do.',
            $this->flat('docs/runners.md', '## Runner Groups'),
            'The Runner Groups section of runners.md'
        );
        $this->assertMentions(
            'It creates the runner in the group that `group` names, or in the group `default` when it names none,',
            $this->flat('docs/runners.md', '### Option A: Self-registration'),
            'Option A of runners.md'
        );
        $this->assertMentions(
            'Where no runner group is named `default`, registering a runner without a group creates that group, and makes it the runner group of the job templates without one whose names begin with `Selftest` or `Demo —` (see [Runner Groups](#runner-groups)).',
            $this->flat('docs/runners.md', RunnerManagementDocsContractTest::RUNNERS_SECTION),
            'The bootstrap secret limit of runners.md'
        );
        $this->assertStringNotContainsString('placed in the "default" group', $this->flat('docs/runners.md'));
    }

    /**
     * The sample log under Verifying Connectivity begins with what a runner
     * of the compose example prints when it registers itself without
     * RUNNER_GROUP, and the common issues quote each refusal as the runner
     * logs it: the server's answer after "ERROR: Registration failed: ".
     *
     * Regression: runners.md showed log lines that the runner never prints
     * (Runner registered: …, Polling for jobs..., Heartbeat sent.), quoted
     * the server's `403 Invalid bootstrap secret` in place of the runner's
     * line, and had no row for a runner group that does not exist. The test
     * fails on the docs from before.
     */
    public function testTheLogLinesOfRunnersMdAreWhatTheRunnerPrints(): void
    {
        $sample = $this->flat('docs/runners.md', '## Verifying Connectivity');
        $registered = $this->runnerRegisters(null, ['ok' => true, 'data' => ['token' => 'docs-token']], 201, 'dc2-runner-1');
        $this->assertSame('docs-token', $registered['token']);
        $lines = array_values(array_filter(explode("\n", $registered['stdout'])));
        $this->assertCount(2, $lines, 'The runner prints two lines when it registers itself.');
        foreach ($lines as $line) {
            $this->assertMentions($line, $sample, 'The sample log of runners.md');
        }
        foreach (['Runner registered:', 'Polling for jobs...', 'Heartbeat sent.'] as $never) {
            $this->assertStringNotContainsString($never, $sample, 'The runner never prints it.');
        }

        $answer = $this->register(['name' => 'docs-runner-' . uniqid(), 'bootstrap_secret' => 'wrong-' . self::SECRET]);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(RegisterController::INVALID_BOOTSTRAP_SECRET, $answer['error'] ?? null);
        $refused = $this->runnerRegisters(null, $answer, 403);
        $this->assertStringContainsString('ERROR: Registration failed: ' . RegisterController::INVALID_BOOTSTRAP_SECRET . "\n", $refused['stderr']);
        $this->assertSame('', $refused['token']);

        $issues = $this->flat('docs/runners.md', '### Common issues');
        $this->assertMentions(
            '| `ERROR: Registration failed: ' . RegisterController::INVALID_BOOTSTRAP_SECRET . '` | Secret mismatch: the server answers `403` |',
            $issues,
            'The common issues of runners.md'
        );
        $this->assertMentions(
            '| `ERROR: Registration failed: ' . sprintf(RegisterController::GROUP_NOT_FOUND, self::PLACEHOLDER)
                . '` | `RUNNER_GROUP` names a runner group that does not exist: the server answers `400` and creates nothing |',
            $issues,
            'The common issues of runners.md'
        );
        $this->assertStringNotContainsString('403 Invalid bootstrap secret', $issues);
    }

    /**
     * Calls the register endpoint with $body and the bootstrap secret, as a
     * runner does.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function register(array $body): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body + ['bootstrap_secret' => self::SECRET]);
        \Yii::$app->response->statusCode = 200;

        return (new RegisterController('api/runner/v1/register', $this->app()))->actionRegister();
    }

    /**
     * Self-registration of a runner named $name (null: a unique name) with
     * $group as its RUNNER_GROUP (null: not set) against a server that
     * answers $answer with HTTP $status: what the runner sent, logged to
     * stdout and stderr and got as its token.
     *
     * @param array<string, mixed> $answer
     * @return array{payload: array<string, mixed>, stdout: string, stderr: string, token: string}
     */
    private function runnerRegisters(?string $group, array $answer, int $status, ?string $name = null): array
    {
        $_ENV['RUNNER_NAME'] = $name ?? 'docs-runner-' . uniqid();
        if ($group !== null) {
            $_ENV['RUNNER_GROUP'] = $group;
        }
        $sent = [];
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('postUnauthenticated')->willReturnCallback(static function (string $path, array $payload) use (&$sent, $answer): array {
            $sent = $payload;

            return $answer;
        });
        $http->method('getLastHttpStatus')->willReturn($status);
        $controller = new CapturingController('runner', $this->app());
        $token = (new RetryCountingTokenResolver($http, $controller, $this->cacheDir))->resolve();
        unset($_ENV['RUNNER_NAME'], $_ENV['RUNNER_GROUP']);

        return ['payload' => $sent, 'stdout' => $controller->capturedStdout, 'stderr' => $controller->capturedStderr, 'token' => $token];
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);

        return $app;
    }

    /**
     * Gives every runner group named `default` another name, so that this
     * test starts without one; the rename is rolled back with the test.
     */
    private function renameTheDefaultGroup(): void
    {
        foreach (RunnerGroup::find()->where(['name' => 'default'])->all() as $group) {
            $group->name = 'docs-was-default-' . $group->id;
            $group->save(false);
        }
        $this->assertNull(RunnerGroup::findOne(['name' => 'default']));
    }

    /**
     * A job template named $prefix plus a unique part, of runner group
     * $groupId (none: null); created in $existing, as the foreign key needs
     * a group that exists.
     */
    private function template(string $prefix, ?int $groupId, RunnerGroup $existing, int $owner): JobTemplate
    {
        $template = $this->createJobTemplate((int)$this->createProject($owner)->id, (int)$this->createInventory($owner)->id, (int)$existing->id, $owner);
        $template->name = $prefix . uniqid();
        $template->runner_group_id = $groupId;
        $template->save(false);

        return $template;
    }
}
