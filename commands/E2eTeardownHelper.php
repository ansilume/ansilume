<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
use yii\db\Query;

/**
 * Removes the E2E test data: rows named with the prefix ("e2e-"), the users
 * named with it, and everything those users created or launched whatever its
 * name, because a user can only be deleted once no row refers to it.
 *
 * "Named with the prefix" means the name starts with it; a name that only
 * contains it (a user "qa-e2e-bob") is not E2E data.
 *
 * Every part runs even when an earlier one fails, and nothing throws:
 * teardownAll() and teardownDatabase() return what is left behind.
 */
class E2eTeardownHelper
{
    /**
     * Tables whose rows carry the prefix in a name column, children before
     * the rows they refer to. Teams take their members and project grants
     * along; workflow templates their steps and runs; runner groups their
     * runners (foreign keys with ON DELETE CASCADE).
     *
     * @var array<string, string> table => name column
     */
    private const NAMED = [
        'workflow_step' => 'name',
        'workflow_template' => 'name',
        'approval_rule' => 'name',
        'schedule' => 'name',
        'webhook' => 'name',
        'notification_template' => 'name',
        'job_template' => 'name',
        'credential' => 'name',
        'inventory' => 'name',
        'project' => 'name',
        'team' => 'name',
        'runner' => 'name',
        'runner_group' => 'name',
    ];

    /**
     * The tables that refer to their creator with a RESTRICT foreign key, in
     * the same order. Jobs and workflow runs (launched_by) are handled by
     * deleteJobsOf(), runner groups by handOverRunnerGroups() first.
     *
     * @var list<string>
     */
    private const CREATED_BY = [
        'workflow_template',
        'approval_rule',
        'schedule',
        'webhook',
        'notification_template',
        'job_template',
        'credential',
        'inventory',
        'project',
        'team',
        'runner',
        'runner_group',
    ];

    private string $prefix;

    /** @var callable(string): void */
    private $logger;

    /** @var list<string> */
    private array $problems = [];

    /**
     * @param callable(string): void $logger
     */
    public function __construct(string $prefix, callable $logger)
    {
        $this->prefix = $prefix;
        $this->logger = $logger;
    }

    /**
     * The database part, then the fixture checkouts on disk, each regardless
     * of the other.
     *
     * @return list<string> what is left behind; empty when everything is gone
     */
    public function teardownAll(): array
    {
        $problems = $this->teardownDatabase();
        $this->problems = [];
        $this->attempt('removing the vault scan checkout', function (): void {
            (new E2eVaultScanSeeder($this->logger))->teardown();
        });
        $this->attempt('removing the vault edge checkout', function (): void {
            (new E2eVaultEdgeSeeder($this->logger))->teardown();
        });

        return array_merge($problems, $this->problems);
    }

    /**
     * Deletes the E2E rows and users. Touches the database only.
     *
     * @return list<string> what is left behind; empty when everything is gone
     */
    public function teardownDatabase(): array
    {
        $this->problems = [];
        /** @var User[] $users */
        $users = User::find()->where($this->startsWithPrefix('username'))->all();
        $userIds = array_map(static fn (User $user): int => (int)$user->id, $users);

        // First pass. A named row that a row of the e2e users still refers
        // to, such as the e2e project under a template a spec created, cannot
        // go yet. Its failures do not count: the second pass tries again.
        $this->deleteNamedRows();
        $this->attempt('removing the custom roles', fn () => $this->deleteCustomRoles());
        if ($userIds !== []) {
            $this->attempt('deleting the jobs of e2e users', fn () => $this->deleteJobsOf($userIds));
            $this->attempt('revoking the trigger tokens of e2e users', fn () => $this->revokeTriggerTokensOf($userIds));
            $this->attempt('handing over runner groups', fn () => $this->handOverRunnerGroups($userIds));
            foreach (self::CREATED_BY as $table) {
                $this->attempt("deleting the {$table} rows of e2e users", fn () => $this->deleteCreatedBy($table, $userIds));
            }
        }
        // Second pass, after the rows of the e2e users: only what cannot go
        // now is left behind.
        $this->problems = array_merge($this->problems, $this->deleteNamedRows());
        foreach ($users as $user) {
            $this->deleteUser($user);
        }

        return array_merge($this->problems, $this->leftovers());
    }

    /**
     * Runs one part of the teardown; a failure is recorded, not thrown.
     */
    private function attempt(string $what, callable $part): void
    {
        try {
            $part();
        } catch (\Exception $e) {
            $this->problems[] = "Failed {$what}: " . self::firstLine($e);
        }
    }

    /**
     * $column starts with the prefix ('like') or does not ('not like'). The
     * prefix is matched as it is: its LIKE wildcards are escaped.
     *
     * @return array{0: string, 1: string, 2: string, 3: false}
     */
    private function startsWithPrefix(string $column, string $operator = 'like'): array
    {
        return [$operator, $column, addcslashes($this->prefix, '%_\\') . '%', false];
    }

    /**
     * Deletes the rows named with the prefix, table by table in NAMED order.
     *
     * @return list<string> the rows that could not go, and why
     */
    private function deleteNamedRows(): array
    {
        $failures = [];
        foreach (self::NAMED as $table => $column) {
            $failures = array_merge($failures, $this->deleteNamed($table, $column));
        }

        return $failures;
    }

    /**
     * Deletes the rows of $table named with the prefix one by one, so one
     * row that cannot go does not keep the others. Soft-deleted rows too.
     *
     * @return list<string> the rows that could not go, and why
     */
    private function deleteNamed(string $table, string $column): array
    {
        $db = \Yii::$app->db;
        $rows = (new Query())->select(['id', $column])->from("{{%{$table}}}")
            ->where($this->startsWithPrefix($column))->all($db);
        $failures = [];
        foreach ($rows as $row) {
            $name = (string)$row[$column];
            try {
                $db->createCommand()->delete("{{%{$table}}}", ['id' => $row['id']])->execute();
                $this->log("  Deleted {$table} '{$name}'.\n");
            } catch (\Exception $e) {
                $failures[] = "Failed deleting {$table} '{$name}': " . self::firstLine($e);
            }
        }

        return $failures;
    }

    private function deleteCustomRoles(): void
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        foreach ($auth->getRoles() as $role) {
            if (str_starts_with($role->name, $this->prefix)) {
                $auth->remove($role);
                $this->log("  Deleted custom role '{$role->name}'.\n");
            }
        }
    }

    /**
     * Jobs and workflow runs the e2e users launched, with the rows that
     * refer to them.
     *
     * @param list<int> $userIds
     */
    private function deleteJobsOf(array $userIds): void
    {
        $db = \Yii::$app->db;
        $del = fn (string $t, array $w) => $db->createCommand()->delete($t, $w)->execute();
        $col = fn (string $t, array $w) => (new Query())->select('id')->from($t)->where($w)->column($db);

        $jobIds = $col('{{%job}}', ['launched_by' => $userIds]);
        if ($jobIds !== []) {
            $del('{{%workflow_job_step}}', ['job_id' => $jobIds]);
            $requestIds = $col('{{%approval_request}}', ['job_id' => $jobIds]);
            if ($requestIds !== []) {
                $del('{{%approval_decision}}', ['approval_request_id' => $requestIds]);
                $del('{{%approval_request}}', ['id' => $requestIds]);
            }
            foreach (['job_artifact', 'job_host_summary', 'job_task', 'job_log'] as $t) {
                $del('{{%' . $t . '}}', ['job_id' => $jobIds]);
            }
            $del('{{%job}}', ['id' => $jobIds]);
            $this->log("  Cleaned up " . count($jobIds) . " job(s) created by e2e users.\n");
        }

        $wfJobIds = $col('{{%workflow_job}}', ['launched_by' => $userIds]);
        if ($wfJobIds !== []) {
            $del('{{%workflow_job_step}}', ['workflow_job_id' => $wfJobIds]);
            $del('{{%workflow_job}}', ['id' => $wfJobIds]);
            $this->log("  Cleaned up " . count($wfJobIds) . " workflow job(s).\n");
        }
    }

    /**
     * A trigger runs as the user who generated its token. Tokens the e2e
     * users generated on templates that stay are revoked, not handed over.
     *
     * @param list<int> $userIds
     */
    private function revokeTriggerTokensOf(array $userIds): void
    {
        foreach (['job_template', 'workflow_template'] as $table) {
            $count = \Yii::$app->db->createCommand()->update(
                "{{%{$table}}}",
                ['trigger_token' => null, 'trigger_token_created_by' => null],
                ['trigger_token_created_by' => $userIds]
            )->execute();
            if ($count > 0) {
                $this->log("  Revoked {$count} {$table} trigger token(s) generated by e2e users.\n");
            }
        }
    }

    /**
     * Runner groups without the prefix are shared infrastructure, like
     * "default", which runner self-registration creates as the first
     * superadmin; that may be e2e-admin. They go to the first other
     * superadmin. Without one they are deleted with the rest.
     *
     * @param list<int> $userIds
     */
    private function handOverRunnerGroups(array $userIds): void
    {
        $heir = User::find()->where(['is_superadmin' => true])->andWhere(['not in', 'id', $userIds])
            ->orderBy(['id' => SORT_ASC])->one();
        if (!$heir instanceof User) {
            return;
        }
        $count = \Yii::$app->db->createCommand()->update(
            '{{%runner_group}}',
            ['created_by' => (int)$heir->id],
            ['and', ['created_by' => $userIds], $this->startsWithPrefix('name', 'not like')]
        )->execute();
        if ($count > 0) {
            $this->log("  Handed {$count} runner group(s) created by e2e users over to '{$heir->username}'.\n");
        }
    }

    /**
     * Deletes the rows of $table the e2e users created. One statement: if a
     * row cannot go (a row of another user refers to it), none of them goes
     * and the user stays, reported.
     *
     * @param list<int> $userIds
     */
    private function deleteCreatedBy(string $table, array $userIds): void
    {
        $count = \Yii::$app->db->createCommand()->delete("{{%{$table}}}", ['created_by' => $userIds])->execute();
        if ($count > 0) {
            $this->log("  Deleted {$count} {$table} row(s) created by e2e users.\n");
        }
    }

    /**
     * Deletes the user, then its role assignments, in one transaction: a user
     * that cannot be deleted keeps its roles.
     */
    private function deleteUser(User $user): void
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            if ($user->delete() === false) {
                throw new \RuntimeException('the delete was refused');
            }
            /** @var \yii\rbac\ManagerInterface $auth */
            $auth = \Yii::$app->authManager;
            $auth->revokeAll($user->id);
            $transaction->commit();
            $this->log("  Deleted user '{$user->username}'.\n");
        } catch (\Exception $e) {
            $transaction->rollBack();
            $this->problems[] = "User '{$user->username}' was not deleted: " . self::firstLine($e);
        }
    }

    /**
     * Rows still named with the prefix.
     *
     * @return list<string>
     */
    private function leftovers(): array
    {
        $left = [];
        foreach (['user' => 'username'] + self::NAMED as $table => $column) {
            $count = (int)(new Query())->from("{{%{$table}}}")
                ->where($this->startsWithPrefix($column))->count('*', \Yii::$app->db);
            if ($count > 0) {
                $left[] = "{$count} {$table} row(s) named like '{$this->prefix}' are left.";
            }
        }

        return $left;
    }

    private function log(string $msg): void
    {
        ($this->logger)($msg);
    }

    private static function firstLine(\Exception $e): string
    {
        return strtok($e->getMessage(), "\n") ?: get_class($e);
    }
}
