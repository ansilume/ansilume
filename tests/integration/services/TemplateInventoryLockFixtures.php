<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\JobTemplateCredentialService;
use app\services\ProjectAccessChecker;
use yii\log\Logger;

/**
 * Fixtures of the tests of the template lock for job templates gated
 * through their inventory (GatedJobTemplateLock, GatedInventoryChoice) and
 * of one rule per launch (JobTemplateRuleAssignment::RULES_DIFFER); use in
 * DbTestCase subclasses together with TeamScopeFixtures.
 *
 * Rules are written with updateAll(): neither model mass-assigns
 * approval_rule_id, and the tests change rules behind the back of loaded
 * models on purpose.
 */
trait TemplateInventoryLockFixtures
{
    /**
     * A job template of its own project, inventory and runner group, without
     * a rule of its own; created by $ownerId.
     */
    protected function ungatedTemplate(int $ownerId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
    }

    /**
     * A job template without a rule of its own, on an inventory with $rule.
     */
    protected function inventoryGated(int $ownerId, ApprovalRule $rule): JobTemplate
    {
        $template = $this->ungatedTemplate($ownerId);
        $this->setRuleOfInventory((int)$template->inventory_id, $rule);

        return $this->reloaded($template);
    }

    /**
     * A static inventory with $rule (null: none), of project #$projectId
     * (null: none).
     */
    protected function inventoryWithRule(int $ownerId, ?ApprovalRule $rule, ?int $projectId = null): Inventory
    {
        $inventory = $this->createInventory($ownerId);
        Inventory::updateAll(['approval_rule_id' => $rule?->id, 'project_id' => $projectId], ['id' => $inventory->id]);
        $stored = Inventory::findOne($inventory->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    /**
     * Give inventory #$inventoryId the rule $rule (null: none), as an
     * admin's save stores it.
     */
    protected function setRuleOfInventory(int $inventoryId, ?ApprovalRule $rule): void
    {
        Inventory::updateAll(['approval_rule_id' => $rule?->id], ['id' => $inventoryId]);
    }

    /**
     * $template as stored, after giving it the rule $rule of its own (null:
     * none).
     */
    protected function withOwnRule(JobTemplate $template, ?ApprovalRule $rule): JobTemplate
    {
        JobTemplate::updateAll(['approval_rule_id' => $rule?->id], ['id' => $template->id]);

        return $this->reloaded($template);
    }

    protected function reloaded(JobTemplate $template): JobTemplate
    {
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    /**
     * A new template of $projectId on inventory #$inventoryId, unsaved, as a
     * form or a request body fills it.
     */
    protected function newTemplateOn(int $projectId, int $inventoryId, int $ownerId): JobTemplate
    {
        $template = new JobTemplate();
        $template->name = 'lock-new-' . uniqid('', true);
        $template->project_id = $projectId;
        $template->inventory_id = $inventoryId;
        $template->playbook = 'site.yml';
        $template->runner_group_id = (int)$this->createRunnerGroup($ownerId)->id;
        $template->verbosity = 0;
        $template->forks = 5;
        $template->timeout_minutes = 120;
        $template->become = false;
        $template->become_method = 'sudo';
        $template->become_user = 'root';
        $template->created_by = $ownerId;

        return $template;
    }

    /**
     * A user who may assign approval rules through a custom role, not the
     * admin role.
     */
    protected function customAssigner(): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('inventory-lock-assigners-' . uniqid());
        $auth->add($role);
        $permission = $auth->getPermission(ApprovalRuleAssignmentPolicy::PERMISSION);
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $user = $this->createUserWithRole('inventory-lock-custom', 'operator');
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    protected function superadminUser(): User
    {
        $user = $this->createUser('inventory-lock-superadmin');
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
    }

    /**
     * The default install: no project is team-restricted, so the inventory
     * filter of $userId is null and every inventory counts as visible.
     */
    protected function assertDefaultInstall(int $userId): void
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        $this->assertFalse($checker->hasRestrictedProjects(), 'precondition: no team-restricted project');
        $this->assertNull($checker->buildChildResourceFilter($userId, 'inventory.project_id'), 'precondition: no inventory filter');
    }

    protected function templateCredentials(): JobTemplateCredentialService
    {
        /** @var JobTemplateCredentialService $service */
        $service = \Yii::$app->get('jobTemplateCredentialService');

        return $service;
    }

    /**
     * The audit entries of job templates.
     */
    protected function templateAuditCount(): int
    {
        return (int)AuditLog::find()->where(['object_type' => 'job_template'])->count();
    }

    /**
     * The SQL statements $action runs, in order.
     *
     * @return list<string>
     */
    protected function statementsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $statements = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
            ) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }

    /**
     * The index of the first statement of $statements that matches $pattern.
     *
     * @param list<string> $statements
     */
    protected function statementIndex(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }
        $this->fail("No statement matches {$pattern}:\n" . implode("\n", $statements));
    }

    /**
     * Every locking read of inventory rows among $statements, by its index:
     * the IDs it names. A locking read of inventory rows in any other form
     * fails the test.
     *
     * @param list<string> $statements
     * @return array<int, list<int>>
     */
    protected function inventoryLocks(array $statements): array
    {
        $locks = [];
        foreach ($statements as $index => $statement) {
            if (!str_contains($statement, '`inventory`') || preg_match('~(FOR UPDATE|LOCK IN SHARE MODE)$~', $statement) !== 1) {
                continue;
            }
            $this->assertSame(
                1,
                preg_match('~^SELECT \* FROM `inventory` WHERE `id` IN \(([\d, ]+)\) ORDER BY `id` LOCK IN SHARE MODE$~', $statement, $match),
                "only JobTemplateGate::readInventoryRules() locks inventory rows, and only LOCK IN SHARE MODE:\n" . $statement
            );
            $locks[$index] = array_map('intval', explode(', ', $match[1]));
        }

        return $locks;
    }
}
