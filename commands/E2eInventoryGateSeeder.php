<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\db\Query;

/**
 * The fixtures of the E2E specs for approval rules on inventories (the
 * names in tests/e2e/lib/inventories.ts):
 *
 * - e2e-host-gate-rule (one approval, rejected after RULE_TIMEOUT_MINUTES)
 *   and e2e-host-other-rule (one approval, no timeout): e2e-admin and
 *   e2e-operator approve, so whichever of the two launches, the other one
 *   decides. e2e-host-solo-rule: only e2e-operator approves, so a launch by
 *   e2e-operator is refused before anything is written. Without e2e-operator
 *   e2e-admin approves alone on all three.
 * - e2e-host-gate-project: a manual project without a team, so every role
 *   sees its job templates; without a local path, so a save of a job template
 *   does not run ansible-lint. e2e-host-gate-runner-group: no runner serves
 *   it, so an approved job stays queued.
 * - e2e-gated-inventory (CONTENT), gated by e2e-host-gate-rule. Its job
 *   templates have no rule of their own: e2e-host-gated-template in
 *   e2e-host-gate-project, and e2e-host-hidden-template in
 *   e2e-host-hidden-project, which only e2e-host-hidden-team has. That team
 *   has no members, so e2e-operator and e2e-viewer cannot see the template
 *   (the inventory's usage counts it as hidden), e2e-admin can. The team is
 *   this seeder's own: e2e-team-beta does not exist yet when it runs on a
 *   fresh seed, and E2eTeamScopingSeeder creates that team later.
 * - e2e-host-solo-inventory, gated by e2e-host-solo-rule, with
 *   e2e-host-solo-template.
 * - e2e-conflict-inventory, without a rule, with e2e-conflict-template, whose
 *   own rule is e2e-host-other-rule: giving the inventory another rule is
 *   refused, a launch waits for one rule only.
 * - e2e-host-in-use-inventory with the job template e2e-host-in-use-template,
 *   and e2e-host-deleted-only-inventory with only the soft-deleted job
 *   template e2e-host-deleted-only-template: neither can be deleted.
 * - e2e-host-assign-inventory, without a rule on every seed, with
 *   e2e-host-assign-template: a spec gives it a rule and takes it away.
 * - e2e-host-ini-inventory, without a rule or a job template on every seed,
 *   whose content is the INI that m000020 seeds for the selftest inventory
 *   (INI_CONTENT), stored before the YAML check: an admin gives it a rule
 *   on its form and takes it away, and e2e-operator renames it
 *   (RENAMED_INI_INVENTORY) and back. No job template uses it, so nothing
 *   runs against the runner itself, which its one host is.
 * - e2e-host-gated-schedule: e2e-admin's disabled schedule of
 *   e2e-host-gated-template, yearly at midnight on 1 January (UTC), so it
 *   never comes due while the specs run.
 * - e2e-host-gate-workflow: the job step e2e-host-gate-step on
 *   e2e-host-gated-template, then the pause step e2e-host-gate-after. Only
 *   admins change the steps of a workflow with a job step whose job
 *   template's inventory has a rule, so e2e-operator finds them locked.
 *
 * The inventories are static, without a project, each with one host at an
 * address reserved for documentation, but for e2e-host-ini-inventory. The
 * job templates use e2e-host-gate-runner-group and the playbook site.yml,
 * without credentials, extra vars, survey or trigger token. No name
 * contains "e2e-inventory", which yaml-editor.spec.ts looks for on the first
 * page of the inventory list, and nothing here uses e2e-inventory,
 * e2e-template or e2e-approval-rule: the other specs launch those without an
 * approval.
 *
 * Every seed restores each fixture in place, by name, with the same IDs: a
 * renamed inventory or job template (RENAMED_INVENTORY,
 * RENAMED_INI_INVENTORY, RENAMED_TEMPLATE), a soft-deleted job template,
 * and changed rules, projects, team, schedule and workflow steps. The jobs
 * of the job templates go, with their approval requests, and so do the runs
 * of the workflow: the specs launch new ones. Nothing is written to disk.
 */
final class E2eInventoryGateSeeder
{
    /** e2e-admin and e2e-operator approve, one approval, rejected after RULE_TIMEOUT_MINUTES: the rule of INVENTORY. */
    public const RULE = 'e2e-host-gate-rule';

    /** e2e-admin and e2e-operator approve, one approval, no timeout: the own rule of CONFLICT_TEMPLATE. */
    public const OTHER_RULE = 'e2e-host-other-rule';

    /** Only e2e-operator approves: the rule of SOLO_INVENTORY, so a launch by e2e-operator is refused. */
    public const SOLO_RULE = 'e2e-host-solo-rule';

    /** A manual project without a team: every role sees its job templates. */
    public const PROJECT = 'e2e-host-gate-project';

    /** No runner serves it: an approved job stays queued. */
    public const RUNNER_GROUP = 'e2e-host-gate-runner-group';

    /** Static, without a project, gated by RULE. */
    public const INVENTORY = 'e2e-gated-inventory';

    /** The name a spec gives INVENTORY before it renames it back. */
    public const RENAMED_INVENTORY = 'e2e-gated-inventory-renamed';

    /** The content of INVENTORY: one host, at an address reserved for documentation (RFC 5737). */
    public const CONTENT = "all:\n  hosts:\n    e2e-host-gate-host:\n      ansible_host: 192.0.2.40\n";

    /** In PROJECT on INVENTORY, without a rule of its own: its inventory's rule gates it. */
    public const TEMPLATE = 'e2e-host-gated-template';

    /** The name a spec gives TEMPLATE before it renames it back. */
    public const RENAMED_TEMPLATE = 'e2e-host-gated-template-renamed';

    /** On INVENTORY, in HIDDEN_PROJECT: e2e-operator and e2e-viewer cannot see it, e2e-admin can. */
    public const HIDDEN_TEMPLATE = 'e2e-host-hidden-template';

    /** Only HIDDEN_TEAM has it. */
    public const HIDDEN_PROJECT = 'e2e-host-hidden-project';

    /** No members; HIDDEN_PROJECT is its only project. */
    public const HIDDEN_TEAM = 'e2e-host-hidden-team';

    /** Gated by SOLO_RULE. */
    public const SOLO_INVENTORY = 'e2e-host-solo-inventory';

    /** In PROJECT on SOLO_INVENTORY: a launch by e2e-operator is refused, by e2e-admin it waits for e2e-operator. */
    public const SOLO_TEMPLATE = 'e2e-host-solo-template';

    /** Without a rule; its job template has a rule of its own. */
    public const CONFLICT_INVENTORY = 'e2e-conflict-inventory';

    /** In PROJECT on CONFLICT_INVENTORY, with OTHER_RULE as its own rule. */
    public const CONFLICT_TEMPLATE = 'e2e-conflict-template';

    /** Without a rule; a live job template uses it. */
    public const IN_USE_INVENTORY = 'e2e-host-in-use-inventory';

    /** In PROJECT on IN_USE_INVENTORY. */
    public const IN_USE_TEMPLATE = 'e2e-host-in-use-template';

    /** Without a rule; only a soft-deleted job template uses it. */
    public const DELETED_ONLY_INVENTORY = 'e2e-host-deleted-only-inventory';

    /** In PROJECT on DELETED_ONLY_INVENTORY, soft-deleted on every seed. */
    public const DELETED_ONLY_TEMPLATE = 'e2e-host-deleted-only-template';

    /** Without a rule on every seed: a spec gives it one and takes it away. */
    public const ASSIGN_INVENTORY = 'e2e-host-assign-inventory';

    /** In PROJECT on ASSIGN_INVENTORY, without a rule of its own. */
    public const ASSIGN_TEMPLATE = 'e2e-host-assign-template';

    /**
     * Static, without a rule, a project or a job template, with INI_CONTENT:
     * stored INI, which it keeps while it is renamed or given a rule.
     */
    public const INI_INVENTORY = 'e2e-host-ini-inventory';

    /** The name a spec gives INI_INVENTORY before it renames it back. */
    public const RENAMED_INI_INVENTORY = 'e2e-host-ini-inventory-renamed';

    /** The content of INI_INVENTORY: the INI m000020 seeds for the selftest inventory, no YAML mapping. */
    public const INI_CONTENT = "[local]\nlocalhost ansible_connection=local\n";

    /** e2e-admin's disabled schedule of TEMPLATE. */
    public const SCHEDULE = 'e2e-host-gated-schedule';

    /** Yearly: the schedule never comes due by itself while the specs run. */
    public const CRON = '0 0 1 1 *';

    /** STEP, then AFTER_STEP. */
    public const WORKFLOW = 'e2e-host-gate-workflow';

    /** The job step on TEMPLATE, at position 1. */
    public const STEP = 'e2e-host-gate-step';

    /** The pause step, at position 2. */
    public const AFTER_STEP = 'e2e-host-gate-after';

    /** The approver besides e2e-admin on RULE and OTHER_RULE, and the only one on SOLO_RULE. */
    public const OPERATOR = 'e2e-operator';

    /** How long a request on RULE waits before it is rejected. */
    public const RULE_TIMEOUT_MINUTES = 60;

    /** The line of every seed; sprintf(): every fixture with its ID. */
    public const RESTORED = '  Restored inventory approval fixtures: %s';

    private const DESCRIPTION = 'E2E fixture: approval rules on inventories (E2eInventoryGateSeeder)';

    /** The content of a static inventory of one host; sprintf(): the host's name and address. */
    private const ONE_HOST = "all:\n  hosts:\n    %s:\n      ansible_host: %s\n";

    /**
     * The inventories: name => [the name and the address of its one host,
     * its approval rule (null: none)].
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    private const INVENTORIES = [
        self::INVENTORY => ['e2e-host-gate-host', '192.0.2.40', self::RULE],
        self::SOLO_INVENTORY => ['e2e-host-solo-host', '192.0.2.41', self::SOLO_RULE],
        self::CONFLICT_INVENTORY => ['e2e-host-conflict-host', '192.0.2.42', null],
        self::IN_USE_INVENTORY => ['e2e-host-in-use-host', '192.0.2.43', null],
        self::DELETED_ONLY_INVENTORY => ['e2e-host-deleted-only-host', '192.0.2.44', null],
        self::ASSIGN_INVENTORY => ['e2e-host-assign-host', '192.0.2.45', null],
    ];

    /**
     * The job templates: name => [project, inventory, own approval rule
     * (null: none), soft-deleted].
     *
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: bool}>
     */
    private const TEMPLATES = [
        self::TEMPLATE => [self::PROJECT, self::INVENTORY, null, false],
        self::HIDDEN_TEMPLATE => [self::HIDDEN_PROJECT, self::INVENTORY, null, false],
        self::SOLO_TEMPLATE => [self::PROJECT, self::SOLO_INVENTORY, null, false],
        self::CONFLICT_TEMPLATE => [self::PROJECT, self::CONFLICT_INVENTORY, self::OTHER_RULE, false],
        self::IN_USE_TEMPLATE => [self::PROJECT, self::IN_USE_INVENTORY, null, false],
        self::DELETED_ONLY_TEMPLATE => [self::PROJECT, self::DELETED_ONLY_INVENTORY, null, true],
        self::ASSIGN_TEMPLATE => [self::PROJECT, self::ASSIGN_INVENTORY, null, false],
    ];

    /** The fixtures a spec renames and renames back: name => the name it gives them meanwhile. */
    private const RENAMED = [
        self::INVENTORY => self::RENAMED_INVENTORY,
        self::INI_INVENTORY => self::RENAMED_INI_INVENTORY,
        self::TEMPLATE => self::RENAMED_TEMPLATE,
    ];

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore every fixture this class describes, which $userId (e2e-admin)
     * created, and log RESTORED.
     */
    public function seed(int $userId): void
    {
        $ids = $this->rules($userId) + $this->infrastructure($userId);
        $ids += $this->inventories($ids, $userId);
        $ids += $this->templates($ids, $userId);
        self::deleteJobs(array_values(array_intersect_key($ids, self::TEMPLATES)));
        $ids[self::SCHEDULE] = $this->schedule($ids[self::TEMPLATE], $userId);
        $ids[self::WORKFLOW] = $this->workflow($ids[self::TEMPLATE], $userId);

        $restored = [];
        foreach ($ids as $name => $id) {
            $restored[] = sprintf('%s (ID %d)', $name, $id);
        }
        ($this->logger)(sprintf(self::RESTORED, implode(', ', $restored)) . ".\n");
    }

    /**
     * The three rules, by name: e2e-admin ($userId) and e2e-operator approve
     * on RULE and OTHER_RULE, e2e-operator alone on SOLO_RULE; without
     * e2e-operator, e2e-admin alone on each.
     *
     * @return array<string, int>
     */
    private function rules(int $userId): array
    {
        $operator = User::findOne(['username' => self::OPERATOR]);
        $soloId = $operator === null ? $userId : (int)$operator->id;
        $approvers = array_values(array_unique([$userId, $soloId]));

        return [
            self::RULE => (int)E2eFixtureHelper::approvalRule(self::RULE, $userId, $approvers, 1, self::RULE_TIMEOUT_MINUTES)->id,
            self::OTHER_RULE => (int)E2eFixtureHelper::approvalRule(self::OTHER_RULE, $userId, $approvers, 1, null)->id,
            self::SOLO_RULE => (int)E2eFixtureHelper::approvalRule(self::SOLO_RULE, $userId, [$soloId], 1, null)->id,
        ];
    }

    /**
     * The two projects, the hidden team and the runner group, by name.
     * Every team grant of the two projects goes: without a team, every role
     * sees PROJECT; HIDDEN_TEAM gets HIDDEN_PROJECT back as its only grant.
     *
     * @return array<string, int>
     */
    private function infrastructure(int $userId): array
    {
        $ids = [];
        foreach ([self::PROJECT, self::HIDDEN_PROJECT] as $name) {
            $project = E2eFixtureHelper::project($name, $userId);
            $project->description = self::DESCRIPTION;
            $project->local_path = null;
            $project->save(false);
            $ids[$name] = (int)$project->id;
        }
        TeamProject::deleteAll(['project_id' => array_values($ids)]);
        $ids[self::HIDDEN_TEAM] = $this->hiddenTeam($ids[self::HIDDEN_PROJECT], $userId);
        $ids[self::RUNNER_GROUP] = $this->runnerGroup($userId);

        return $ids;
    }

    /**
     * HIDDEN_TEAM, without members, with project #$projectId (HIDDEN_PROJECT)
     * as its only project, so nobody but admins sees that project. Its ID.
     */
    private function hiddenTeam(int $projectId, int $userId): int
    {
        $team = Team::findOne(['name' => self::HIDDEN_TEAM]) ?? new Team();
        $team->setAttributes(['name' => self::HIDDEN_TEAM, 'description' => self::DESCRIPTION, 'created_by' => $userId], false);
        $team->save(false);
        $teamId = (int)$team->id;
        TeamMember::deleteAll(['team_id' => $teamId]);
        TeamProject::deleteAll(['team_id' => $teamId]);
        $grant = new TeamProject();
        $grant->setAttributes([
            'team_id' => $teamId,
            'project_id' => $projectId,
            'role' => TeamProject::ROLE_OPERATOR,
            'created_at' => time(),
        ], false);
        $grant->save(false);

        return $teamId;
    }

    private function runnerGroup(int $userId): int
    {
        $group = RunnerGroup::findOne(['name' => self::RUNNER_GROUP]) ?? new RunnerGroup();
        $group->setAttributes([
            'name' => self::RUNNER_GROUP,
            'description' => 'E2E: no runner serves this group, so its jobs stay queued',
            'created_by' => $userId,
        ], false);
        $group->save(false);

        return (int)$group->id;
    }

    /**
     * The inventories, by name: static, without a project or parsed hosts,
     * with their rule, and INI_INVENTORY last. $ids holds the rules by name.
     *
     * @param array<string, int> $ids
     * @return array<string, int>
     */
    private function inventories(array $ids, int $userId): array
    {
        $inventories = [];
        foreach (self::INVENTORIES as $name => [2 => $rule]) {
            $inventories[$name] = $this->inventory($name, $rule === null ? null : $ids[$rule], $userId);
        }
        $inventories[self::INI_INVENTORY] = $this->inventory(self::INI_INVENTORY, null, $userId);

        return $inventories;
    }

    /**
     * The static inventory $name, restored in place with its content
     * (contentOf()) and the rule #$ruleId (null: none), without a project or
     * parsed hosts. Its ID.
     */
    private function inventory(string $name, ?int $ruleId, int $userId): int
    {
        self::takeBackInventoryName($name);
        $inventory = E2eFixtureHelper::inventory($name, Inventory::TYPE_STATIC, null, $userId, self::contentOf($name));
        $inventory->description = self::DESCRIPTION;
        $inventory->setAttribute('approval_rule_id', $ruleId);
        $inventory->parsed_hosts = null;
        $inventory->parsed_error = null;
        $inventory->parsed_at = null;
        $inventory->save(false);

        return (int)$inventory->id;
    }

    /**
     * The content of the inventory $name: INI_CONTENT for INI_INVENTORY, one
     * host for the others (INVENTORIES). Looked up by name, so that no
     * parameter of this class carries an inventory's content (CLAUDE.md rule
     * 11).
     */
    private static function contentOf(string $name): string
    {
        if ($name === self::INI_INVENTORY) {
            return self::INI_CONTENT;
        }
        [$host, $address] = self::INVENTORIES[$name];

        return sprintf(self::ONE_HOST, $host, $address);
    }

    /**
     * A renamed inventory (RENAMED) gets its name back, so it is restored in
     * place instead of a second one.
     */
    private static function takeBackInventoryName(string $name): void
    {
        if (Inventory::find()->where(['name' => $name])->exists()) {
            return;
        }
        /** @var Inventory|null $renamed */
        $renamed = Inventory::find()->where(['name' => self::RENAMED[$name] ?? $name])->orderBy(['id' => SORT_ASC])->one();
        $renamed?->updateAttributes(['name' => $name]);
    }

    /**
     * The job templates, by name. $ids holds the rules, the projects, the
     * runner group and the inventories by name.
     *
     * @param array<string, int> $ids
     * @return array<string, int>
     */
    private function templates(array $ids, int $userId): array
    {
        $templates = [];
        foreach (self::TEMPLATES as $name => [$project, $inventory, $rule, $deleted]) {
            $parents = [0 => $ids[$project], 1 => $ids[$inventory], 2 => $ids[self::RUNNER_GROUP]];
            $template = $this->template($name, $parents, $rule === null ? null : $ids[$rule], $userId);
            if ($deleted) {
                $template->softDelete();
            }
            $templates[$name] = (int)$template->id;
        }

        return $templates;
    }

    /**
     * The job template $name, restored in place: a soft-deleted copy, or one
     * a spec renamed (RENAMED), comes back under its name instead of a second
     * one. $ruleId is its own approval rule (null: none).
     *
     * @param array{0: int, 1: int, 2: int} $parents project, inventory and runner group ids
     */
    private function template(string $name, array $parents, ?int $ruleId, int $userId): JobTemplate
    {
        if (JobTemplate::findOne(['name' => $name]) === null) {
            /** @var JobTemplate|null $previous */
            $previous = JobTemplate::findWithDeleted()
                ->where(['name' => [$name, self::RENAMED[$name] ?? $name]])
                ->orderBy(['id' => SORT_ASC])
                ->one();
            $previous?->updateAttributes(['name' => $name, 'deleted_at' => null]);
        }
        $template = E2eFixtureHelper::template($name, $parents, $userId, null, []);
        $template->setAttributes([
            'description' => self::DESCRIPTION,
            'approval_rule_id' => $ruleId,
            'extra_vars' => null,
            'survey_fields' => null,
            'limit' => null,
            'tags' => null,
            'skip_tags' => null,
            'become_method' => 'sudo',
            'become_user' => 'root',
            'trigger_token' => null,
            'trigger_token_created_by' => null,
        ], false);
        $template->save(false);

        return $template;
    }

    /**
     * Deletes the jobs of the job templates $templateIds: their logs,
     * artifacts and approval requests go with them (foreign keys), their
     * tasks first, which no foreign key takes along.
     *
     * @param list<int> $templateIds
     */
    private static function deleteJobs(array $templateIds): void
    {
        $db = \Yii::$app->db;
        $jobIds = (new Query())->select('id')->from('{{%job}}')->where(['job_template_id' => $templateIds])->column($db);
        if ($jobIds !== []) {
            $db->createCommand()->delete('{{%job_task}}', ['job_id' => $jobIds])->execute();
            Job::deleteAll(['id' => $jobIds]);
        }
    }

    /**
     * SCHEDULE of job template #$templateId: disabled, run as $userId
     * (e2e-admin), without extra vars, password answers or runs. Its ID.
     */
    private function schedule(int $templateId, int $userId): int
    {
        $schedule = Schedule::findOne(['name' => self::SCHEDULE]) ?? new Schedule();
        $schedule->setAttributes([
            'name' => self::SCHEDULE,
            'job_template_id' => $templateId,
            'cron_expression' => self::CRON,
            'timezone' => 'UTC',
            'extra_vars' => null,
            'enabled' => false,
            'last_run_at' => null,
            'next_run_at' => null,
            'created_by' => $userId,
        ], false);
        $schedule->setSealedSecretExtraVars(null);
        $schedule->save(false);

        return (int)$schedule->id;
    }

    /**
     * WORKFLOW, a soft-deleted copy too, without runs (their step executions
     * go with them) or trigger token: STEP on job template #$templateId at
     * position 1, AFTER_STEP at position 2, kept with their IDs; any other
     * step goes. Its ID.
     */
    private function workflow(int $templateId, int $userId): int
    {
        /** @var WorkflowTemplate|null $existing */
        $existing = WorkflowTemplate::findWithDeleted()->where(['name' => self::WORKFLOW])->orderBy(['id' => SORT_ASC])->one();
        $workflow = $existing ?? new WorkflowTemplate();
        $workflow->setAttributes([
            'name' => self::WORKFLOW,
            'description' => self::DESCRIPTION,
            'created_by' => $userId,
            'deleted_at' => null,
            'trigger_token' => null,
            'trigger_token_created_by' => null,
        ], false);
        $workflow->save(false);
        $workflowId = (int)$workflow->id;
        WorkflowJob::deleteAll(['workflow_template_id' => $workflowId]);
        $kept = [
            $this->step($workflowId, self::STEP, 10, $templateId),
            $this->step($workflowId, self::AFTER_STEP, 20, null),
        ];
        WorkflowStep::deleteAll(['and', ['workflow_template_id' => $workflowId], ['not in', 'id', $kept]]);

        return $workflowId;
    }

    /**
     * The step $name of workflow #$workflowId, restored in place: a job step
     * on job template #$jobTemplateId, or a pause step when that is null,
     * without branches or extra vars. Its ID.
     */
    private function step(int $workflowId, string $name, int $order, ?int $jobTemplateId): int
    {
        /** @var WorkflowStep|null $existing */
        $existing = WorkflowStep::find()
            ->where(['workflow_template_id' => $workflowId, 'name' => $name])
            ->orderBy(['id' => SORT_ASC])
            ->one();
        $step = $existing ?? new WorkflowStep();
        $step->setAttributes([
            'workflow_template_id' => $workflowId,
            'name' => $name,
            'step_order' => $order,
            'step_type' => $jobTemplateId === null ? WorkflowStep::TYPE_PAUSE : WorkflowStep::TYPE_JOB,
            'job_template_id' => $jobTemplateId,
            'approval_rule_id' => null,
            'on_success_step_id' => null,
            'on_failure_step_id' => null,
            'on_always_step_id' => null,
            'extra_vars_template' => null,
        ], false);
        $step->save(false);

        return (int)$step->id;
    }
}
