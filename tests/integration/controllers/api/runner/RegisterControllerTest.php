<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\runner;

use app\controllers\api\runner\RegisterController;
use app\models\AuditLog;
use app\models\Runner;
use app\models\RunnerGroup;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Runner self-registration audits who got a token.
 *
 * Regression: registering again under an existing name silently replaced
 * the runner's token. Anyone with the bootstrap secret could take over a
 * runner's identity (and its jobs and credentials) without a trace.
 */
class RegisterControllerTest extends WebControllerTestCase
{
    private const SECRET = 'test-bootstrap-secret';

    private mixed $previousSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousSecret = $_ENV['RUNNER_BOOTSTRAP_SECRET'] ?? null;
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = self::SECRET;
        $this->createUser('register-owner');
    }

    protected function tearDown(): void
    {
        if ($this->previousSecret === null) {
            unset($_ENV['RUNNER_BOOTSTRAP_SECRET']);
        } else {
            $_ENV['RUNNER_BOOTSTRAP_SECRET'] = $this->previousSecret;
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function register(array $body): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body + ['bootstrap_secret' => self::SECRET]);

        return (new RegisterController('api/runner/v1/register', \Yii::$app))->actionRegister();
    }

    private function group(): RunnerGroup
    {
        return $this->createRunnerGroup((int)$this->createUser('group-owner')->id);
    }

    /**
     * @return list<AuditLog>
     */
    private function auditsFor(string $action, int $runnerId): array
    {
        /** @var list<AuditLog> $rows */
        $rows = AuditLog::find()->where(['action' => $action, 'object_type' => 'runner', 'object_id' => $runnerId])->all();

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(AuditLog $log): array
    {
        $decoded = json_decode((string)$log->metadata, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testFirstRegistrationCreatesTheRunnerAndAuditsIt(): void
    {
        $group = $this->group();

        $result = $this->register(['name' => 'runner-a', 'group' => $group->name]);

        $this->assertTrue($result['ok']);
        $runnerId = (int)$result['data']['runner_id'];
        $created = $this->auditsFor(AuditLog::ACTION_RUNNER_CREATED, $runnerId);
        $this->assertCount(1, $created);
        $this->assertNull($created[0]->user_id);
        $this->assertSame(
            ['name' => 'runner-a', 'group_id' => $group->id, 'group_name' => $group->name, 'source' => 'self-registration'],
            $this->metadata($created[0])
        );
        $this->assertSame([], $this->auditsFor(AuditLog::ACTION_RUNNER_REREGISTERED, $runnerId));
    }

    public function testRegressionReRegistrationRevokesTheOldTokenAndIsAudited(): void
    {
        $group = $this->group();
        $first = $this->register(['name' => 'runner-b', 'group' => $group->name, 'software_version' => '2.5.0']);
        $oldToken = (string)$first['data']['token'];

        $second = $this->register(['name' => 'runner-b', 'group' => $group->name, 'software_version' => '2.5.1']);

        $runnerId = (int)$second['data']['runner_id'];
        $this->assertSame((int)$first['data']['runner_id'], $runnerId, 'same runner, not a new one');
        $this->assertNull(Runner::findByToken($oldToken), 'the old token is revoked');
        $this->assertNotNull(Runner::findByToken((string)$second['data']['token']));

        $logs = $this->auditsFor(AuditLog::ACTION_RUNNER_REREGISTERED, $runnerId);
        $this->assertCount(1, $logs);
        $context = $this->metadata($logs[0]);
        $this->assertSame('self-registration', $context['source']);
        $this->assertTrue($context['previous_token_revoked']);
        $this->assertFalse($context['was_online']);
        $this->assertNull($context['previous_last_seen_at']);
        $this->assertSame('2.5.1', $context['software_version']);
        $this->assertStringNotContainsString($oldToken, (string)$logs[0]->metadata);
        $this->assertStringNotContainsString((string)$second['data']['token'], (string)$logs[0]->metadata);
        $this->assertStringNotContainsString(self::SECRET, (string)$logs[0]->metadata);
    }

    public function testReRegisteringAnOnlineRunnerRecordsThat(): void
    {
        $group = $this->group();
        $first = $this->register(['name' => 'runner-c', 'group' => $group->name]);
        $lastSeen = time() - 5;
        Runner::updateAll(['last_seen_at' => $lastSeen], ['id' => $first['data']['runner_id']]);

        $this->register(['name' => 'runner-c', 'group' => $group->name]);

        $logs = $this->auditsFor(AuditLog::ACTION_RUNNER_REREGISTERED, (int)$first['data']['runner_id']);
        $context = $this->metadata($logs[0]);
        $this->assertTrue($context['was_online']);
        $this->assertSame($lastSeen, $context['previous_last_seen_at']);
    }

    public function testTheSameNameInAnotherGroupNeverMovesOrResetsTheExistingRunner(): void
    {
        $groupA = $this->group();
        $groupB = $this->group();
        $inA = $this->register(['name' => 'runner-d', 'group' => $groupA->name]);
        $tokenA = (string)$inA['data']['token'];

        $inB = $this->register(['name' => 'runner-d', 'group' => $groupB->name]);

        $this->assertNotSame($inA['data']['runner_id'], $inB['data']['runner_id']);
        $runnerA = Runner::findByToken($tokenA);
        $this->assertNotNull($runnerA, 'the runner in group A keeps its token');
        $this->assertSame($groupA->id, $runnerA->runner_group_id);
        $created = $this->auditsFor(AuditLog::ACTION_RUNNER_CREATED, (int)$inB['data']['runner_id']);
        $this->assertSame([$groupA->id], $this->metadata($created[0])['other_group_ids']);
    }

    public function testRejectedRegistrationsWriteNoAudit(): void
    {
        $group = $this->group();
        $before = AuditLog::find()->where(['like', 'action', 'runner.'])->count();

        $wrongSecret = $this->register(['name' => 'runner-e', 'group' => $group->name, 'bootstrap_secret' => 'wrong']);
        $noName = $this->register(['name' => '', 'group' => $group->name]);
        $unknownGroup = $this->register(['name' => 'runner-e', 'group' => 'no-such-group']);

        $this->assertFalse($wrongSecret['ok']);
        $this->assertFalse($noName['ok']);
        $this->assertFalse($unknownGroup['ok']);
        $this->assertSame($before, AuditLog::find()->where(['like', 'action', 'runner.'])->count());
    }
}
