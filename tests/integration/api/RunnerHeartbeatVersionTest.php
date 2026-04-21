<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\runner\JobsController;
use app\models\Runner;
use app\tests\integration\DbTestCase;

/**
 * Pin the runner-version telemetry wiring inside BaseRunnerApiController:
 * when a runner sends `software_version` in the JSON body of any
 * authenticated request, that value must be persisted on the Runner row
 * alongside the normal `last_seen_at` heartbeat update.
 *
 * This is what feeds the dashboard "outdated runner" tile and the
 * per-runner Version column on the runner-group detail page.
 */
class RunnerHeartbeatVersionTest extends DbTestCase
{
    public function testHeartbeatPersistsReportedSoftwareVersion(): void
    {
        [$runner, $rawToken] = $this->seedRunner();
        $this->assertNull($runner->software_version, 'Fresh runners start with no reported version.');

        $this->invokeAuthenticatedRunnerRequest($rawToken, ['software_version' => '2.2.16']);

        $runner->refresh();
        $this->assertSame('2.2.16', $runner->software_version);
        $this->assertNotNull($runner->last_seen_at);
    }

    public function testHeartbeatUpdatesVersionWhenRunnerUpgrades(): void
    {
        [$runner, $rawToken] = $this->seedRunner();
        $runner->software_version = '2.2.14';
        $runner->save(false, ['software_version']);

        $this->invokeAuthenticatedRunnerRequest($rawToken, ['software_version' => '2.2.16']);

        $runner->refresh();
        $this->assertSame('2.2.16', $runner->software_version);
    }

    public function testHeartbeatLeavesVersionAloneWhenFieldMissing(): void
    {
        // Pre-upgrade runners don't send software_version — we must not
        // wipe the stored value in that case; keep whatever we knew before.
        [$runner, $rawToken] = $this->seedRunner();
        $runner->software_version = '2.2.15';
        $runner->save(false, ['software_version']);

        $this->invokeAuthenticatedRunnerRequest($rawToken, []);

        $runner->refresh();
        $this->assertSame('2.2.15', $runner->software_version);
    }

    public function testHeartbeatRejectsOversizedVersionString(): void
    {
        // Column caps at 32 chars. Reject anything longer to prevent a
        // hostile or broken runner stuffing garbage into the DB.
        [$runner, $rawToken] = $this->seedRunner();
        $this->invokeAuthenticatedRunnerRequest($rawToken, [
            'software_version' => str_repeat('x', 50),
        ]);

        $runner->refresh();
        $this->assertNull($runner->software_version, 'Oversized version strings are dropped, not truncated.');
    }

    public function testHeartbeatRejectsNonStringVersion(): void
    {
        [$runner, $rawToken] = $this->seedRunner();
        $this->invokeAuthenticatedRunnerRequest($rawToken, [
            'software_version' => ['not', 'a', 'string'],
        ]);

        $runner->refresh();
        $this->assertNull($runner->software_version);
    }

    /**
     * @return array{0: Runner, 1: string} [runner, rawToken]
     */
    private function seedRunner(): array
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $token = Runner::generateToken();

        $runner = new Runner();
        $runner->runner_group_id = $group->id;
        $runner->name = 'test-runner-' . uniqid('', true);
        $runner->created_by = $user->id;
        $runner->token_hash = $token['hash'];
        $runner->save(false);

        return [$runner, $token['raw']];
    }

    /**
     * Stage a Yii web request matching what the runner sends, then drive
     * BaseRunnerApiController::beforeAction (via a JobsController instance)
     * so the auth + software_version persistence path runs end-to-end.
     *
     * @param array<string, mixed> $body
     */
    private function invokeAuthenticatedRunnerRequest(string $rawToken, array $body): void
    {
        $originalRequest = \Yii::$app->has('request') ? \Yii::$app->request : null;

        \Yii::$app->set('request', new class ($rawToken, $body) extends \yii\web\Request {
            /** @param array<string, mixed> $body */
            public function __construct(private readonly string $token, private readonly array $bodyParamsValue)
            {
                parent::__construct();
            }
            public function getBodyParams(): array
            {
                return $this->bodyParamsValue;
            }
            public function getHeaders(): \yii\web\HeaderCollection
            {
                $headers = new \yii\web\HeaderCollection();
                $headers->set('Authorization', 'Bearer ' . $this->token);
                return $headers;
            }
        });

        try {
            $controller = new JobsController('runner-jobs', \Yii::$app);
            // Bypass ContentNegotiator (behaviors) — it tries to set a
            // `format` property on Response, which isn't present on the
            // console Response used by DbTestCase. Auth + persistence
            // live in the private authenticateRunner() method; call it
            // directly via reflection.
            $ref = new \ReflectionMethod($controller, 'authenticateRunner');
            $ref->setAccessible(true);
            $ref->invoke($controller);
        } finally {
            if ($originalRequest !== null) {
                \Yii::$app->set('request', $originalRequest);
            }
        }
    }
}
