<?php

declare(strict_types=1);

namespace app\commands;

use app\components\RunnerTransportClassifier;
use app\models\AuditLog;
use app\models\Runner;
use app\models\RunnerGroup;

/**
 * Seeds runners for the runner group page specs.
 *
 * - QUOTE_RUNNER: runner names come from self-registration and used to break
 *   out of the confirm() attribute of the runner forms (stored XSS).
 *   runner-groups/confirm-escaping.spec.ts checks that the name stays text.
 * - REREGISTERED_RUNNER: two runner.reregistered audit entries within 24 h,
 *   so runner-groups/reregistration.spec.ts sees the reset notice and badge.
 *
 * Idempotent. Teardown removes the runners with their e2e runner group
 * (foreign key cascade).
 */
class E2eRunnerRegistrationSeeder
{
    public const GROUP = 'e2e-runner-group-2';
    public const QUOTE_RUNNER = 'e2e-quote-runner" data-e2e-injected="1';
    public const REREGISTERED_RUNNER = 'e2e-reregistered-runner';
    /** Talks plain HTTP from a public address: runner-groups/plaintext-transport.spec.ts. */
    public const PLAINTEXT_RUNNER = 'e2e-plaintext-runner';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId): void
    {
        $group = RunnerGroup::findOne(['name' => self::GROUP]);
        if ($group === null) {
            ($this->logger)('  Runner group ' . self::GROUP . " not found, skipping runner seeds.\n");
            return;
        }

        $this->ensureRunner((int)$group->id, self::QUOTE_RUNNER, $userId);
        $this->ensureRecentReregistrations($this->ensureRunner((int)$group->id, self::REREGISTERED_RUNNER, $userId), 2);

        // Re-asserted on every seed: online, last seen over plain HTTP from outside.
        $plaintext = $this->ensureRunner((int)$group->id, self::PLAINTEXT_RUNNER, $userId);
        $plaintext->transport = RunnerTransportClassifier::HTTP_EXTERNAL;
        $plaintext->remote_addr = '203.0.113.7';
        $plaintext->last_seen_at = time();
        $plaintext->plaintext_seen_at = time();
        $plaintext->save(false);
    }

    private function ensureRecentReregistrations(Runner $runner, int $count): void
    {
        $existing = (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_RUNNER_REREGISTERED, 'object_type' => 'runner', 'object_id' => $runner->id])
            ->andWhere(['>=', 'created_at', time() - Runner::REREGISTRATION_WINDOW])
            ->count();
        for ($i = $existing; $i < $count; $i++) {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', $runner->id, null, [
                'name' => $runner->name,
                'source' => 'self-registration',
                'previous_token_revoked' => true,
            ]);
        }
    }

    private function ensureRunner(int $groupId, string $name, int $userId): Runner
    {
        $runner = Runner::findOne(['runner_group_id' => $groupId, 'name' => $name]);
        if ($runner !== null) {
            return $runner;
        }

        $runner = new Runner();
        $runner->runner_group_id = $groupId;
        $runner->name = $name;
        $runner->token_hash = Runner::generateToken()['hash'];
        $runner->created_by = $userId;
        $runner->save(false);
        ($this->logger)("  Created runner ID {$runner->id} in " . self::GROUP . ".\n");

        return $runner;
    }
}
