<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eInventoryGateSeeder;
use app\commands\E2eTeamScopingSeeder;
use app\commands\E2eTeardownHelper;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ApproverEligibility;
use app\services\GatedInventoryLock;
use app\services\GatedJobSteps;
use app\services\GatedJobTemplateLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryUsageService;
use app\services\InventoryWriteRefusedException;
use app\services\InventoryWriteService;
use app\services\JobTemplateCredentialService;
use app\services\JobTemplateGate;
use app\services\ProjectAccessChecker;
use app\tests\integration\DbTestCase;
use yii\db\Query;

/**
 * The fixtures of the E2E specs for approval rules on inventories
 * (E2eInventoryGateSeeder; the names in tests/e2e/lib/inventories.ts). The
 * specs find them by these names and rely on what each one is gated by, who
 * may change it and who may see it; the test database holds no "e2e-" rows
 * outside a test, and the rollback removes them.
 */
class E2eInventoryGateSeederTest extends DbTestCase
{
    /** The public constants of the seeder that are no fixture names. */
    private const NOT_NAMES = ['CONTENT', 'CRON', 'INI_CONTENT', 'RESTORED', 'RULE_TIMEOUT_MINUTES'];

    /** The fixture names lib/inventories.ts leaves out: e2e-operator is no fixture of the seeder. */
    private const NOT_MIRRORED = ['OPERATOR'];

    /** Every inventory, gated or not. */
    private const INVENTORIES = [
        E2eInventoryGateSeeder::INVENTORY,
        E2eInventoryGateSeeder::SOLO_INVENTORY,
        E2eInventoryGateSeeder::CONFLICT_INVENTORY,
        E2eInventoryGateSeeder::IN_USE_INVENTORY,
        E2eInventoryGateSeeder::DELETED_ONLY_INVENTORY,
        E2eInventoryGateSeeder::ASSIGN_INVENTORY,
    ];

    /** The inventories with an approval rule. */
    private const GATED_INVENTORIES = [E2eInventoryGateSeeder::INVENTORY, E2eInventoryGateSeeder::SOLO_INVENTORY];

    private int $adminId;
    private int $operatorId;
    private int $viewerId;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->namedUser('e2e-admin', 'admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->operatorId = (int)$this->namedUser('e2e-operator', 'operator')->id;
        $this->viewerId = (int)$this->namedUser('e2e-viewer', 'viewer')->id;
        $this->log = [];
    }

    /**
     * The rules, the projects, the hidden team and the runner group; the
     * seed says once what it restored, with the IDs.
     */
    public function testSeedsTheRulesProjectsTeamAndRunnerGroup(): void
    {
        $this->seed();

        $both = [$this->adminId, $this->operatorId];
        $this->assertRule(E2eInventoryGateSeeder::RULE, $both, E2eInventoryGateSeeder::RULE_TIMEOUT_MINUTES);
        $this->assertSame(60, E2eInventoryGateSeeder::RULE_TIMEOUT_MINUTES);
        $this->assertRule(E2eInventoryGateSeeder::OTHER_RULE, $both, null);
        $this->assertRule(E2eInventoryGateSeeder::SOLO_RULE, [$this->operatorId], null);

        $project = $this->project(E2eInventoryGateSeeder::PROJECT);
        $hidden = $this->project(E2eInventoryGateSeeder::HIDDEN_PROJECT);
        foreach ([$project, $hidden] as $each) {
            $this->assertSame(Project::SCM_TYPE_MANUAL, $each->scm_type, (string)$each->name);
            $this->assertNull($each->local_path, 'a save of a job template does not run ansible-lint');
            $this->assertSame($this->adminId, (int)$each->created_by);
        }
        $this->assertFalse(TeamProject::find()->where(['project_id' => $project->id])->exists(), 'no team: every role sees it');
        $team = $this->team();
        $this->assertSame(
            [[(int)$team->id, (int)$hidden->id, TeamProject::ROLE_OPERATOR]],
            $this->grantsOf((int)$team->id, (int)$hidden->id),
            'the hidden project has the hidden team only, and the team has no other project'
        );
        $this->assertFalse(TeamMember::find()->where(['team_id' => $team->id])->exists(), 'the team has no members');
        $group = $this->runnerGroup();
        $this->assertSame(0, (int)$group->getRunners()->count(), 'no runner serves it: approved jobs stay queued');

        $this->assertSame([$this->restoredLine()], $this->log, 'one line per seed');
        $this->assertStringStartsWith(sprintf(E2eInventoryGateSeeder::RESTORED, E2eInventoryGateSeeder::RULE . ' (ID '), $this->log[0]);
    }

    /**
     * Static inventories without a project, each with one host; gated by
     * their rule or not. The job templates on them: their project, the
     * runner group, their own rule, and nothing they would need a secret or
     * a runner for.
     */
    public function testSeedsTheInventoriesAndTheJobTemplates(): void
    {
        $this->seed();

        $rules = [
            E2eInventoryGateSeeder::INVENTORY => $this->ruleId(E2eInventoryGateSeeder::RULE),
            E2eInventoryGateSeeder::SOLO_INVENTORY => $this->ruleId(E2eInventoryGateSeeder::SOLO_RULE),
        ];
        foreach (self::INVENTORIES as $name) {
            $inventory = $this->inventory($name);
            $this->assertSame(Inventory::TYPE_STATIC, $inventory->inventory_type, $name);
            $this->assertNull($inventory->project_id, $name);
            $this->assertNull($inventory->source_path, $name);
            $this->assertNull($inventory->parsed_hosts, $name);
            $this->assertSame($rules[$name] ?? null, $this->ruleOf($inventory), "{$name}: its rule");
            $this->assertSame($this->adminId, (int)$inventory->created_by, $name);
            $this->assertStringContainsString('ansible_host: 192.0.2.', (string)$inventory->content, $name);
            $this->assertTrue($inventory->validate(), "{$name} is valid by today's rules: " . json_encode($inventory->errors));
        }
        $this->assertSame(E2eInventoryGateSeeder::CONTENT, $this->inventory(E2eInventoryGateSeeder::INVENTORY)->content);

        // The INI inventory: the content m000020 seeds for the selftest inventory, no rule, no job template.
        $ini = $this->inventory(E2eInventoryGateSeeder::INI_INVENTORY);
        $this->assertSame(
            [Inventory::TYPE_STATIC, null, null, null, null, $this->adminId, "[local]\nlocalhost ansible_connection=local\n"],
            [$ini->inventory_type, $ini->project_id, $ini->source_path, $ini->parsed_hosts, $this->ruleOf($ini), (int)$ini->created_by, $ini->content]
        );
        $this->assertFalse(JobTemplate::findWithDeleted()->where(['inventory_id' => $ini->id])->exists(), 'no job template uses it');
        $this->assertTrue($ini->targetsLocalhost(), 'its one host is the runner itself');
        $this->assertTrue($ini->validate(), 'valid while its content stays as stored: ' . json_encode($ini->errors));
        $new = new Inventory(['name' => 'e2e-host-ini-copy', 'inventory_type' => Inventory::TYPE_STATIC, 'content' => $ini->content]);
        $this->assertFalse($new->validate(['content']), 'no YAML mapping: the form and the API take it only as stored');

        $projectId = (int)$this->project(E2eInventoryGateSeeder::PROJECT)->id;
        $groupId = (int)$this->runnerGroup()->id;
        $expected = [
            E2eInventoryGateSeeder::TEMPLATE => [$projectId, E2eInventoryGateSeeder::INVENTORY, null, false],
            E2eInventoryGateSeeder::HIDDEN_TEMPLATE => [
                (int)$this->project(E2eInventoryGateSeeder::HIDDEN_PROJECT)->id,
                E2eInventoryGateSeeder::INVENTORY,
                null,
                false,
            ],
            E2eInventoryGateSeeder::SOLO_TEMPLATE => [$projectId, E2eInventoryGateSeeder::SOLO_INVENTORY, null, false],
            E2eInventoryGateSeeder::CONFLICT_TEMPLATE => [
                $projectId,
                E2eInventoryGateSeeder::CONFLICT_INVENTORY,
                $this->ruleId(E2eInventoryGateSeeder::OTHER_RULE),
                false,
            ],
            E2eInventoryGateSeeder::IN_USE_TEMPLATE => [$projectId, E2eInventoryGateSeeder::IN_USE_INVENTORY, null, false],
            E2eInventoryGateSeeder::DELETED_ONLY_TEMPLATE => [$projectId, E2eInventoryGateSeeder::DELETED_ONLY_INVENTORY, null, true],
            E2eInventoryGateSeeder::ASSIGN_TEMPLATE => [$projectId, E2eInventoryGateSeeder::ASSIGN_INVENTORY, null, false],
        ];
        foreach ($expected as $name => [$project, $inventory, $rule, $deleted]) {
            $template = $this->templateWithDeleted($name);
            $this->assertSame(
                [$project, (int)$this->inventory($inventory)->id, $groupId, $rule, $deleted],
                [
                    (int)$template->project_id,
                    (int)$template->inventory_id,
                    (int)$template->runner_group_id,
                    $template->approval_rule_id === null ? null : (int)$template->approval_rule_id,
                    $template->deleted_at !== null,
                ],
                "{$name}: project, inventory, runner group, own rule, deleted"
            );
            $this->assertSame('site.yml', $template->playbook, $name);
            $this->assertNull($template->credential_id, $name);
            $this->assertNull($template->extra_vars, $name);
            $this->assertNull($template->survey_fields, $name);
            $this->assertNull($template->trigger_token, $name);
            $this->assertSame($this->adminId, (int)$template->created_by, $name);
        }
        $this->assertFalse(
            (new Query())->from('{{%job_template_credential}}')
                ->where(['job_template_id' => array_map(fn (string $name): int => (int)$this->templateWithDeleted($name)->id, array_keys($expected))])
                ->exists(),
            'no credentials'
        );
    }

    /**
     * e2e-admin's disabled schedule of the gated template, and the workflow:
     * the job step on it, then the pause step, without branches or runs.
     */
    public function testSeedsTheScheduleAndTheWorkflow(): void
    {
        $this->seed();
        $templateId = (int)$this->template(E2eInventoryGateSeeder::TEMPLATE)->id;

        $schedule = $this->schedule();
        $this->assertSame(
            [$templateId, E2eInventoryGateSeeder::CRON, 'UTC', false, $this->adminId, null, null, null, null],
            [
                (int)$schedule->job_template_id,
                $schedule->cron_expression,
                $schedule->timezone,
                (bool)$schedule->enabled,
                (int)$schedule->created_by,
                $schedule->extra_vars,
                $schedule->secret_extra_vars,
                $schedule->last_run_at,
                $schedule->next_run_at,
            ]
        );
        $this->assertSame('0 0 1 1 *', E2eInventoryGateSeeder::CRON, 'yearly: it never comes due while the specs run');

        $workflow = $this->workflow();
        $this->assertNull($workflow->deleted_at);
        $this->assertNull($workflow->trigger_token);
        $this->assertSame($this->adminId, (int)$workflow->created_by);
        $this->assertSame([
            [E2eInventoryGateSeeder::STEP, 10, WorkflowStep::TYPE_JOB, $templateId, null, null, null, null],
            [E2eInventoryGateSeeder::AFTER_STEP, 20, WorkflowStep::TYPE_PAUSE, null, null, null, null, null],
        ], $this->stepsOf($workflow));
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists(), 'no runs');
    }

    /**
     * What gates each fixture, and for whom the locks apply: the inventory
     * lock and the template lock for e2e-operator and e2e-viewer, never for
     * e2e-admin; the notes of the launch and schedule pages; the workflow's
     * locked steps; the refused rule of the conflict inventory; and the
     * inventories that cannot be deleted, with the answers of the delete.
     */
    public function testTheGatesAndTheLocksOfEveryFixtureAreWhatTheSpecsExpect(): void
    {
        $this->seed();

        $lock = new GatedInventoryLock();
        foreach ([...self::INVENTORIES, E2eInventoryGateSeeder::INI_INVENTORY] as $name) {
            $inventory = $this->inventory($name);
            $gated = in_array($name, self::GATED_INVENTORIES, true);
            $this->assertSame($gated, GatedInventoryLock::isGated($inventory), $name);
            $this->assertSame($gated, $lock->appliesTo($this->operatorId, $inventory), "{$name}: locked for e2e-operator");
            $this->assertSame($gated, $lock->appliesTo($this->viewerId, $inventory), "{$name}: locked for e2e-viewer");
            $this->assertFalse($lock->appliesTo($this->adminId, $inventory), "{$name}: open to e2e-admin");
        }

        $templateLock = new GatedJobTemplateLock();
        $expected = [
            E2eInventoryGateSeeder::TEMPLATE => [JobTemplateGate::SOURCE_INVENTORY, E2eInventoryGateSeeder::RULE, GatedJobTemplateLock::NOTICE_INVENTORY],
            E2eInventoryGateSeeder::HIDDEN_TEMPLATE => [JobTemplateGate::SOURCE_INVENTORY, E2eInventoryGateSeeder::RULE, GatedJobTemplateLock::NOTICE_INVENTORY],
            E2eInventoryGateSeeder::SOLO_TEMPLATE => [JobTemplateGate::SOURCE_INVENTORY, E2eInventoryGateSeeder::SOLO_RULE, GatedJobTemplateLock::NOTICE_INVENTORY],
            E2eInventoryGateSeeder::CONFLICT_TEMPLATE => [JobTemplateGate::SOURCE_JOB_TEMPLATE, E2eInventoryGateSeeder::OTHER_RULE, GatedJobTemplateLock::NOTICE],
            E2eInventoryGateSeeder::IN_USE_TEMPLATE => [null, null, null],
            E2eInventoryGateSeeder::ASSIGN_TEMPLATE => [null, null, null],
        ];
        foreach ($expected as $name => [$source, $rule, $notice]) {
            $template = $this->template($name);
            $gate = JobTemplateGate::of($template);
            $this->assertSame($source, $gate->source(), "{$name}: what gates it");
            $this->assertSame($rule === null ? null : $this->ruleId($rule), $gate->ruleId(), "{$name}: its rule");
            $this->assertFalse($gate->rulesDiffer(), $name);
            $this->assertSame($notice, $templateLock->notice($this->operatorId, $template), "{$name}: e2e-operator's notice");
            $this->assertSame($notice, $templateLock->notice($this->viewerId, $template), "{$name}: e2e-viewer's notice");
            $this->assertNull($templateLock->notice($this->adminId, $template), "{$name}: e2e-admin may change it");
        }
        $this->assertFalse(JobTemplateGate::of($this->templateWithDeleted(E2eInventoryGateSeeder::DELETED_ONLY_TEMPLATE))->isGated());

        $gate = JobTemplateGate::of($this->template(E2eInventoryGateSeeder::TEMPLATE));
        $this->assertSame(
            'The job will wait for approval before it runs: rule "e2e-host-gate-rule" (inventory "e2e-gated-inventory").',
            $gate->launchNote()
        );
        $this->assertSame('Each run waits for approval: rule "e2e-host-gate-rule" (inventory "e2e-gated-inventory").', $gate->scheduleNote());

        $workflow = $this->workflow();
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, GatedJobSteps::sourceIn((int)$workflow->id));
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');
        $this->assertInstanceOf(ApprovalRuleAssignmentPolicy::class, $policy);
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED,
            $policy->workflowStepsChangeRefusal($this->operatorId, $workflow, false),
            'e2e-operator finds the steps locked'
        );
        $this->assertNull($policy->workflowStepsChangeRefusal($this->adminId, $workflow, false));

        $assignment = new InventoryRuleAssignment();
        $conflict = $this->inventory(E2eInventoryGateSeeder::CONFLICT_INVENTORY);
        $conflict->setAttribute('approval_rule_id', $this->ruleId(E2eInventoryGateSeeder::RULE));
        $this->assertSame(
            'Job templates that use this inventory have another approval rule of their own: "e2e-conflict-template". '
                . 'A launch waits for one rule only, so give them this rule or none first.',
            $assignment->conflictRefusal($conflict, $this->adminId)
        );
        $assign = $this->inventory(E2eInventoryGateSeeder::ASSIGN_INVENTORY);
        $assign->setAttribute('approval_rule_id', $this->ruleId(E2eInventoryGateSeeder::RULE));
        $this->assertNull($assignment->conflictRefusal($assign, $this->adminId), 'any rule fits the assign inventory');

        $this->assertDeleteRefused(
            E2eInventoryGateSeeder::IN_USE_INVENTORY,
            'Inventory "e2e-host-in-use-inventory" is in use by 1 job template(s).'
        );
        $this->assertDeleteRefused(
            E2eInventoryGateSeeder::DELETED_ONLY_INVENTORY,
            'Inventory "e2e-host-deleted-only-inventory" is in use by 1 deleted job template(s).'
        );
    }

    /**
     * Who sees what: every role sees the open project's job templates, only
     * e2e-admin the hidden one, which the others count as hidden on the
     * gated inventory. Who may launch what: the launcher never approves, so
     * e2e-admin's launch of the gated template waits for e2e-operator and the
     * other way round, e2e-operator's launch of the solo template is refused,
     * and so is e2e-admin's launch of the hidden template, whose project
     * e2e-operator cannot see.
     */
    public function testEachRoleSeesAndMayLaunchWhatTheSpecsExpect(): void
    {
        $this->seed();
        $access = $this->accessChecker();
        $projectId = (int)$this->project(E2eInventoryGateSeeder::PROJECT)->id;
        $hiddenId = (int)$this->project(E2eInventoryGateSeeder::HIDDEN_PROJECT)->id;
        foreach (['e2e-operator' => $this->operatorId, 'e2e-viewer' => $this->viewerId] as $username => $userId) {
            $this->assertTrue($access->canView($userId, $projectId), "{$username} sees the open project");
            $this->assertFalse($access->canView($userId, $hiddenId), "{$username} does not see the hidden project");
            $usage = $this->usage()->forInventory($this->inventory(E2eInventoryGateSeeder::INVENTORY), $userId);
            $this->assertSame([E2eInventoryGateSeeder::TEMPLATE], array_column($usage->jobTemplates, 'name'), $username);
            $this->assertSame(1, $usage->hiddenJobTemplateCount(), "{$username}: + 1 job template(s) in projects you cannot see");
        }
        $this->assertTrue($access->canOperate($this->operatorId, $projectId), 'e2e-operator may launch and rename');
        $this->assertTrue($access->canOperate($this->adminId, $hiddenId));
        $usage = $this->usage()->forInventory($this->inventory(E2eInventoryGateSeeder::INVENTORY), $this->adminId);
        $this->assertSame(
            [E2eInventoryGateSeeder::TEMPLATE, E2eInventoryGateSeeder::HIDDEN_TEMPLATE],
            array_column($usage->jobTemplates, 'name'),
            'e2e-admin sees both'
        );
        $this->assertSame(0, $usage->hiddenJobTemplateCount());

        $this->assertNull($this->launchRefusal(E2eInventoryGateSeeder::TEMPLATE, $this->adminId), 'e2e-operator decides');
        $this->assertNull($this->launchRefusal(E2eInventoryGateSeeder::TEMPLATE, $this->operatorId), 'e2e-admin decides');
        $this->assertNull($this->launchRefusal(E2eInventoryGateSeeder::CONFLICT_TEMPLATE, $this->adminId));
        $this->assertNull($this->launchRefusal(E2eInventoryGateSeeder::SOLO_TEMPLATE, $this->adminId), 'e2e-operator decides');
        $noneLeft = 'Approval rule "%s" needs 1 approval(s) from users other than the one who launches, but only 0 of its '
            . 'approvers may decide on this job. An admin can add approvers to the rule.';
        $this->assertSame(
            sprintf($noneLeft, E2eInventoryGateSeeder::SOLO_RULE),
            $this->launchRefusal(E2eInventoryGateSeeder::SOLO_TEMPLATE, $this->operatorId),
            'the launcher is its only approver'
        );
        $this->assertSame(
            sprintf($noneLeft, E2eInventoryGateSeeder::RULE),
            $this->launchRefusal(E2eInventoryGateSeeder::HIDDEN_TEMPLATE, $this->adminId),
            'e2e-operator cannot see the hidden project'
        );
    }

    /**
     * On a fresh seed the seeder runs before E2eTeamScopingSeeder, so
     * e2e-team-beta does not exist yet. The hidden template is hidden by the
     * seeder's own team, the seeder makes no team of the team scoping
     * seeder, and that seeder still creates its teams afterwards (it creates
     * e2e-team-beta under a unique name).
     */
    public function testTheHiddenTemplateNeedsNoTeamOfTheTeamScopingSeeder(): void
    {
        $this->assertNull(Team::findOne(['name' => 'e2e-team-beta']), 'as on a fresh seed');

        $this->seed();

        $this->assertNull(Team::findOne(['name' => 'e2e-team-alpha']));
        $this->assertNull(Team::findOne(['name' => 'e2e-team-beta']));
        $hiddenId = (int)$this->project(E2eInventoryGateSeeder::HIDDEN_PROJECT)->id;
        $this->assertFalse($this->accessChecker()->canView($this->operatorId, $hiddenId));
        $this->assertTrue($this->accessChecker()->canView($this->adminId, $hiddenId));

        (new E2eTeamScopingSeeder(static function (string $msg): void {
        }))->seed($this->adminId, (int)$this->runnerGroup()->id);

        $this->assertNotNull(Team::findOne(['name' => 'e2e-team-alpha']));
        $this->assertNotNull(Team::findOne(['name' => 'e2e-team-beta']));
        foreach ([$this->operatorId, $this->viewerId] as $userId) {
            $this->assertFalse($this->accessChecker()->canView($userId, $hiddenId), 'still hidden in the teams of the team scoping seeder');
            $usage = $this->usage()->forInventory($this->inventory(E2eInventoryGateSeeder::INVENTORY), $userId);
            $this->assertSame(1, $usage->hiddenJobTemplateCount());
        }
    }

    /**
     * The gated fixtures are valid by today's rules, so the specs see the
     * locks at work, not a validation error: e2e-operator renames the gated
     * inventory and the gated template, but a change of what they run is
     * refused, with nothing written.
     */
    public function testAnOperatorMayRenameTheGatedFixturesButNotChangeWhatTheyRun(): void
    {
        $this->seed();
        $writes = \Yii::$app->get('inventoryWriteService');
        $this->assertInstanceOf(InventoryWriteService::class, $writes);
        $inventory = $this->inventory(E2eInventoryGateSeeder::INVENTORY);
        $inventory->name = E2eInventoryGateSeeder::RENAMED_INVENTORY;
        $this->assertTrue($writes->update($inventory, [], $this->operatorId, InventoryWriteService::SOURCE_WEB), (string)json_encode($inventory->errors));

        $renamed = $this->inventory(E2eInventoryGateSeeder::RENAMED_INVENTORY);
        $renamed->content = "all:\n  hosts:\n    elsewhere:\n";
        try {
            $writes->update($renamed, [], $this->operatorId, InventoryWriteService::SOURCE_WEB);
            $this->fail('A locked change of the hosts was saved.');
        } catch (InventoryWriteRefusedException $e) {
            $this->assertSame(InventoryWriteRefusedException::FORBIDDEN, $e->status);
            $this->assertSame(GatedInventoryLock::CHANGE_REFUSED, $e->getMessage());
        }
        $this->assertSame(E2eInventoryGateSeeder::CONTENT, $this->inventory(E2eInventoryGateSeeder::RENAMED_INVENTORY)->content);

        $credentials = \Yii::$app->get('jobTemplateCredentialService');
        $this->assertInstanceOf(JobTemplateCredentialService::class, $credentials);
        $template = $this->template(E2eInventoryGateSeeder::TEMPLATE);
        $template->name = E2eInventoryGateSeeder::RENAMED_TEMPLATE;
        $this->assertTrue($credentials->saveWithCredentials($template, null, [], $this->operatorId), (string)json_encode($template->errors));

        $template = $this->template(E2eInventoryGateSeeder::RENAMED_TEMPLATE);
        $template->playbook = 'other.yml';
        $this->assertFalse($credentials->saveWithCredentials($template, null, [], $this->operatorId));
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, GatedJobTemplateLock::refusalOf($template));
        $this->assertSame('site.yml', $this->template(E2eInventoryGateSeeder::RENAMED_TEMPLATE)->playbook);
    }

    /**
     * What ini-content.spec.ts does to the INI inventory, through the write
     * service: e2e-admin gives it a rule and takes it away, e2e-operator
     * renames it and back; its INI content stays as stored. Content that
     * changes must be a YAML mapping.
     */
    public function testTheIniInventoryIsGivenARuleAndRenamedWithItsContentKept(): void
    {
        $this->seed();
        $writes = \Yii::$app->get('inventoryWriteService');
        $this->assertInstanceOf(InventoryWriteService::class, $writes);
        $ruleId = $this->ruleId(E2eInventoryGateSeeder::RULE);

        foreach ([$ruleId, null] as $rule) {
            $inventory = $this->inventory(E2eInventoryGateSeeder::INI_INVENTORY);
            $this->assertTrue(
                $writes->update($inventory, [InventoryRuleAssignment::FIELD => $rule], $this->adminId, InventoryWriteService::SOURCE_WEB),
                (string)json_encode($inventory->errors)
            );
            $this->assertSame($rule, $this->ruleOf($this->inventory(E2eInventoryGateSeeder::INI_INVENTORY)));
        }
        $renames = [
            [E2eInventoryGateSeeder::INI_INVENTORY, E2eInventoryGateSeeder::RENAMED_INI_INVENTORY],
            [E2eInventoryGateSeeder::RENAMED_INI_INVENTORY, E2eInventoryGateSeeder::INI_INVENTORY],
        ];
        foreach ($renames as [$from, $to]) {
            $inventory = $this->inventory($from);
            $inventory->name = $to;
            $inventory->content = str_replace("\n", "\r\n", E2eInventoryGateSeeder::INI_CONTENT);
            $this->assertTrue($writes->update($inventory, [], $this->operatorId, InventoryWriteService::SOURCE_WEB), (string)json_encode($inventory->errors));
        }
        $this->assertSame("[local]\r\nlocalhost ansible_connection=local\r\n", $this->inventory(E2eInventoryGateSeeder::INI_INVENTORY)->content, 'as a browser posts it');

        $inventory = $this->inventory(E2eInventoryGateSeeder::INI_INVENTORY);
        $inventory->content = "[local]\nlocalhost\n";
        $this->assertFalse($writes->update($inventory, [], $this->operatorId, InventoryWriteService::SOURCE_WEB));
        $this->assertStringStartsWith('Invalid YAML: ', (string)$inventory->getFirstError('content'));
    }

    /**
     * After a spec run that stopped before it undid its changes, and with
     * everything else changed, renamed or deleted: seeding again restores
     * every fixture in place, with the same IDs. Only a removed workflow
     * step comes back with a new one.
     */
    public function testSeedingAgainRestoresEveryFixtureInPlace(): void
    {
        $this->seed();
        $ids = $this->fixtureIds();
        $stepId = $this->stepId(E2eInventoryGateSeeder::STEP);
        $afterStepId = $this->stepId(E2eInventoryGateSeeder::AFTER_STEP);
        $this->changeEveryFixture($ids + [E2eInventoryGateSeeder::STEP => $stepId], $afterStepId);

        $this->seed();

        $this->assertSame($ids, $this->fixtureIds(), 'restored in place');
        $this->assertSame($stepId, $this->stepId(E2eInventoryGateSeeder::STEP), 'the job step too');
        $this->assertCount(2, $this->log);
        $this->assertSame($this->restoredLine(), $this->log[1]);
        $this->assertRule(E2eInventoryGateSeeder::RULE, [$this->adminId, $this->operatorId], E2eInventoryGateSeeder::RULE_TIMEOUT_MINUTES);
        $inventory = $this->inventory(E2eInventoryGateSeeder::INVENTORY);
        $this->assertSame(E2eInventoryGateSeeder::CONTENT, $inventory->content);
        $this->assertNull($inventory->parsed_hosts);
        $this->assertNull($inventory->parsed_at);
        $this->assertSame($this->ruleId(E2eInventoryGateSeeder::RULE), $this->ruleOf($inventory));
        $this->assertNull(Inventory::findOne(['name' => E2eInventoryGateSeeder::RENAMED_INVENTORY]));
        $solo = $this->inventory(E2eInventoryGateSeeder::SOLO_INVENTORY);
        $this->assertSame(
            [Inventory::TYPE_STATIC, null, null, $this->ruleId(E2eInventoryGateSeeder::SOLO_RULE)],
            [$solo->inventory_type, $solo->source_path, $solo->project_id, $this->ruleOf($solo)]
        );
        $this->assertNull($this->ruleOf($this->inventory(E2eInventoryGateSeeder::ASSIGN_INVENTORY)), 'ungated on every seed');
        $ini = $this->inventory(E2eInventoryGateSeeder::INI_INVENTORY);
        $this->assertSame(
            [E2eInventoryGateSeeder::INI_CONTENT, null, null, null],
            [$ini->content, $this->ruleOf($ini), $ini->parsed_hosts, $ini->parsed_at],
            'the INI inventory: its content, no rule, no parsed hosts'
        );
        $this->assertNotSame('changed', $ini->description);
        $this->assertNull(Inventory::findOne(['name' => E2eInventoryGateSeeder::RENAMED_INI_INVENTORY]));
        $template = $this->template(E2eInventoryGateSeeder::TEMPLATE);
        $this->assertSame(['site.yml', null, null, null], [$template->playbook, $template->approval_rule_id, $template->trigger_token, $template->limit]);
        $this->assertSame(0, (int)JobTemplate::findWithDeleted()->where(['name' => E2eInventoryGateSeeder::RENAMED_TEMPLATE])->count());
        $this->assertNotNull($this->templateWithDeleted(E2eInventoryGateSeeder::DELETED_ONLY_TEMPLATE)->deleted_at, 'deleted again');
        $this->assertSame(
            $this->ruleId(E2eInventoryGateSeeder::OTHER_RULE),
            (int)$this->template(E2eInventoryGateSeeder::CONFLICT_TEMPLATE)->approval_rule_id
        );
        $team = $this->team();
        $this->assertFalse(TeamMember::find()->where(['team_id' => $team->id])->exists());
        $this->assertSame(
            [[(int)$team->id, $ids[E2eInventoryGateSeeder::HIDDEN_PROJECT], TeamProject::ROLE_OPERATOR]],
            $this->grantsOf((int)$team->id, $ids[E2eInventoryGateSeeder::HIDDEN_PROJECT])
        );
        $this->assertFalse(TeamProject::find()->where(['project_id' => $ids[E2eInventoryGateSeeder::PROJECT]])->exists());
        $this->assertNull($this->project(E2eInventoryGateSeeder::PROJECT)->local_path);
        $schedule = $this->schedule();
        $this->assertSame(
            [false, $this->adminId, E2eInventoryGateSeeder::CRON, $ids[E2eInventoryGateSeeder::TEMPLATE]],
            [(bool)$schedule->enabled, (int)$schedule->created_by, $schedule->cron_expression, (int)$schedule->job_template_id]
        );
        $workflow = $this->workflow();
        $this->assertNull($workflow->deleted_at);
        $this->assertNull($workflow->trigger_token);
        $this->assertSame([
            [E2eInventoryGateSeeder::STEP, 10, WorkflowStep::TYPE_JOB, $ids[E2eInventoryGateSeeder::TEMPLATE], null, null, null, null],
            [E2eInventoryGateSeeder::AFTER_STEP, 20, WorkflowStep::TYPE_PAUSE, null, null, null, null, null],
        ], $this->stepsOf($workflow), 'the added step went, the removed one came back');
        $this->assertNotSame($afterStepId, $this->stepId(E2eInventoryGateSeeder::AFTER_STEP));
    }

    /**
     * The jobs the specs launched of the job templates go on every seed,
     * with their approval requests and tasks, and so do the runs of the
     * workflow with their step executions. Jobs of other templates stay.
     */
    public function testSeedingAgainDeletesTheJobsOfTheJobTemplatesAndTheRunsOfTheWorkflow(): void
    {
        $this->seed();
        $template = $this->template(E2eInventoryGateSeeder::TEMPLATE);
        $job = $this->createJob((int)$template->id, $this->adminId, Job::STATUS_PENDING_APPROVAL);
        $request = $this->pendingRequest($job, $this->ruleId(E2eInventoryGateSeeder::RULE));
        $solo = $this->createJob((int)$this->template(E2eInventoryGateSeeder::SOLO_TEMPLATE)->id, $this->adminId, Job::STATUS_CANCELED);
        \Yii::$app->db->createCommand()->insert('{{%job_task}}', ['job_id' => $job->id, 'task_name' => 'E2E task'])->execute();
        $run = $this->runOf($this->workflow(), $this->stepId(E2eInventoryGateSeeder::STEP), (int)$job->id);
        $other = $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
        $kept = $this->createJob((int)$other->id, $this->adminId);

        $this->seed();

        $this->assertNull(Job::findOne($job->id));
        $this->assertNull(Job::findOne($solo->id));
        $this->assertNull(ApprovalRequest::findOne($request->id));
        $this->assertFalse((new Query())->from('{{%job_task}}')->where(['job_id' => $job->id])->exists());
        $this->assertNull(WorkflowJob::findOne($run->id));
        $this->assertFalse(WorkflowJobStep::find()->where(['workflow_job_id' => $run->id])->exists());
        $this->assertNotNull(Job::findOne($kept->id), 'a job of another template stays');
    }

    /**
     * Without e2e-operator, e2e-admin approves alone on every rule.
     */
    public function testWithoutE2eOperatorE2eAdminApprovesAloneOnEveryRule(): void
    {
        User::updateAll(['username' => 'not-e2e-operator-' . uniqid()], ['id' => $this->operatorId]);

        $this->seed();

        foreach ([E2eInventoryGateSeeder::RULE, E2eInventoryGateSeeder::OTHER_RULE, E2eInventoryGateSeeder::SOLO_RULE] as $name) {
            $this->assertSame(['user_ids' => [$this->adminId]], json_decode((string)$this->rule($name)->approver_config, true), $name);
        }
    }

    /**
     * yii e2e/teardown removes every fixture, renamed ones and the jobs a
     * spec left waiting on a request too: the teardown deletes rows named
     * with "e2e-", and every name here starts with it.
     */
    public function testTheTeardownRemovesEveryFixture(): void
    {
        $this->seed();
        $job = $this->createJob((int)$this->template(E2eInventoryGateSeeder::TEMPLATE)->id, $this->adminId, Job::STATUS_PENDING_APPROVAL);
        $this->pendingRequest($job, $this->ruleId(E2eInventoryGateSeeder::RULE));
        Inventory::updateAll(['name' => E2eInventoryGateSeeder::RENAMED_INVENTORY], ['name' => E2eInventoryGateSeeder::INVENTORY]);
        Inventory::updateAll(['name' => E2eInventoryGateSeeder::RENAMED_INI_INVENTORY], ['name' => E2eInventoryGateSeeder::INI_INVENTORY]);
        JobTemplate::updateAll(['name' => E2eInventoryGateSeeder::RENAMED_TEMPLATE], ['name' => E2eInventoryGateSeeder::TEMPLATE]);

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $names = array_values(self::fixtureNames());
        foreach (['approval_rule', 'inventory', 'job_template', 'project', 'team', 'runner_group', 'schedule', 'workflow_template', 'workflow_step'] as $table) {
            $this->assertFalse((new Query())->from("{{%{$table}}}")->where(['name' => $names])->exists(), "{$table}: nothing left");
        }
        $this->assertNull(Job::findOne($job->id));
    }

    /**
     * The teardown finds every fixture by the prefix, and
     * yaml-editor.spec.ts looks for "e2e-inventory" on the first page of the
     * inventory list: every name starts with "e2e-", none contains
     * "e2e-inventory".
     */
    public function testEveryNameStartsWithThePrefixAndNoneContainsE2eInventory(): void
    {
        $names = self::fixtureNames();
        $this->assertGreaterThan(25, count($names));
        foreach ($names as $constant => $name) {
            $this->assertStringStartsWith('e2e-', $name, $constant);
            $this->assertStringNotContainsString('e2e-inventory', $name, $constant);
        }
        $this->assertSame(count($names), count(array_unique($names)), 'every name is a fixture of its own');
    }

    /**
     * tests/e2e/lib/inventories.ts names every fixture as the seeder does,
     * HOST_ in front of the constant's name, and says which constant it
     * mirrors: a name changed on one side only fails here, not in a browser.
     */
    public function testTheE2eLibraryNamesEveryFixtureAsTheSeederDoes(): void
    {
        $file = dirname(__DIR__, 3) . '/tests/e2e/lib/inventories.ts';
        $source = file_get_contents($file);
        $this->assertIsString($source, "{$file} cannot be read.");
        preg_match_all("/^export const (HOST_[A-Z_]+) = '((?:[^'\\\\]|\\\\.)*)';$/m", $source, $matches, PREG_SET_ORDER);
        $exported = [];
        foreach ($matches as $match) {
            $exported[$match[1]] = stripcslashes($match[2]);
        }

        $expected = [];
        foreach (self::fixtureNames() as $constant => $name) {
            if (!in_array($constant, self::NOT_MIRRORED, true)) {
                $expected['HOST_' . $constant] = $name;
                $this->assertStringContainsString("E2eInventoryGateSeeder::{$constant}:", $source, "the comment of HOST_{$constant}");
            }
        }
        ksort($expected);
        ksort($exported);
        $this->assertSame($expected, $exported);
    }

    /**
     * The seeder's constants that name a fixture (or a user), by constant.
     *
     * @return array<string, string>
     */
    private static function fixtureNames(): array
    {
        $names = [];
        foreach ((new \ReflectionClass(E2eInventoryGateSeeder::class))->getReflectionConstants() as $constant) {
            $value = $constant->getValue();
            if ($constant->isPublic() && is_string($value) && !in_array($constant->getName(), self::NOT_NAMES, true)) {
                $names[$constant->getName()] = $value;
            }
        }

        return $names;
    }

    private function seed(): void
    {
        (new E2eInventoryGateSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId);
    }

    /**
     * The line the seed logs: every fixture with its ID, in the seed's order.
     */
    private function restoredLine(): string
    {
        $parts = [];
        foreach ($this->fixtureIds() as $name => $id) {
            $parts[] = sprintf('%s (ID %d)', $name, $id);
        }

        return sprintf(E2eInventoryGateSeeder::RESTORED, implode(', ', $parts)) . ".\n";
    }

    /**
     * The ID of every fixture the seed names, in the seed's order.
     *
     * @return array<string, int>
     */
    private function fixtureIds(): array
    {
        $ids = [];
        foreach ([E2eInventoryGateSeeder::RULE, E2eInventoryGateSeeder::OTHER_RULE, E2eInventoryGateSeeder::SOLO_RULE] as $name) {
            $ids[$name] = $this->ruleId($name);
        }
        $ids[E2eInventoryGateSeeder::PROJECT] = (int)$this->project(E2eInventoryGateSeeder::PROJECT)->id;
        $ids[E2eInventoryGateSeeder::HIDDEN_PROJECT] = (int)$this->project(E2eInventoryGateSeeder::HIDDEN_PROJECT)->id;
        $ids[E2eInventoryGateSeeder::HIDDEN_TEAM] = (int)$this->team()->id;
        $ids[E2eInventoryGateSeeder::RUNNER_GROUP] = (int)$this->runnerGroup()->id;
        foreach (self::INVENTORIES as $name) {
            $ids[$name] = (int)$this->inventory($name)->id;
        }
        $ids[E2eInventoryGateSeeder::INI_INVENTORY] = (int)$this->inventory(E2eInventoryGateSeeder::INI_INVENTORY)->id;
        foreach (
            [
                E2eInventoryGateSeeder::TEMPLATE,
                E2eInventoryGateSeeder::HIDDEN_TEMPLATE,
                E2eInventoryGateSeeder::SOLO_TEMPLATE,
                E2eInventoryGateSeeder::CONFLICT_TEMPLATE,
                E2eInventoryGateSeeder::IN_USE_TEMPLATE,
                E2eInventoryGateSeeder::DELETED_ONLY_TEMPLATE,
                E2eInventoryGateSeeder::ASSIGN_TEMPLATE,
            ] as $name
        ) {
            $ids[$name] = (int)$this->templateWithDeleted($name)->id;
        }
        $ids[E2eInventoryGateSeeder::SCHEDULE] = (int)$this->schedule()->id;
        $ids[E2eInventoryGateSeeder::WORKFLOW] = (int)$this->workflow()->id;

        return $ids;
    }

    /**
     * What spec runs that stopped half-way leave, and more: renamed, changed
     * and deleted fixtures, a member and a project for the hidden team, a
     * team for the open project, a changed schedule, and changed steps.
     *
     * @param array<string, int> $ids
     */
    private function changeEveryFixture(array $ids, int $afterStepId): void
    {
        ApprovalRule::updateAll(
            ['approver_config' => (string)json_encode(['user_ids' => [$this->viewerId]]), 'timeout_minutes' => 5, 'required_approvals' => 2],
            ['id' => $ids[E2eInventoryGateSeeder::RULE]]
        );
        Inventory::updateAll(
            ['name' => E2eInventoryGateSeeder::RENAMED_INVENTORY, 'content' => "all: {}\n", 'description' => 'changed', 'parsed_hosts' => '[]', 'parsed_at' => time()],
            ['id' => $ids[E2eInventoryGateSeeder::INVENTORY]]
        );
        Inventory::updateAll(
            ['approval_rule_id' => null, 'inventory_type' => Inventory::TYPE_FILE, 'source_path' => 'hosts.yml', 'project_id' => $ids[E2eInventoryGateSeeder::PROJECT]],
            ['id' => $ids[E2eInventoryGateSeeder::SOLO_INVENTORY]]
        );
        Inventory::updateAll(['approval_rule_id' => $ids[E2eInventoryGateSeeder::OTHER_RULE]], ['id' => $ids[E2eInventoryGateSeeder::ASSIGN_INVENTORY]]);
        Inventory::updateAll(
            [
                'name' => E2eInventoryGateSeeder::RENAMED_INI_INVENTORY,
                'content' => "[local]\r\nlocalhost ansible_connection=local\r\n",
                'description' => 'changed',
                'approval_rule_id' => $ids[E2eInventoryGateSeeder::RULE],
                'parsed_hosts' => '[]',
                'parsed_at' => time(),
            ],
            ['id' => $ids[E2eInventoryGateSeeder::INI_INVENTORY]]
        );
        JobTemplate::updateAll([
            'name' => E2eInventoryGateSeeder::RENAMED_TEMPLATE,
            'playbook' => 'other.yml',
            'approval_rule_id' => $ids[E2eInventoryGateSeeder::RULE],
            'limit' => 'somewhere',
            'trigger_token' => str_repeat('a', 64),
            'trigger_token_created_by' => $this->adminId,
            'deleted_at' => time(),
        ], ['id' => $ids[E2eInventoryGateSeeder::TEMPLATE]]);
        JobTemplate::updateAll(['deleted_at' => null], ['id' => $ids[E2eInventoryGateSeeder::DELETED_ONLY_TEMPLATE]]);
        JobTemplate::updateAll(['approval_rule_id' => null], ['id' => $ids[E2eInventoryGateSeeder::CONFLICT_TEMPLATE]]);
        $this->addTeamMember($ids[E2eInventoryGateSeeder::HIDDEN_TEAM], $this->operatorId);
        $this->createTeamProject($ids[E2eInventoryGateSeeder::HIDDEN_TEAM], $ids[E2eInventoryGateSeeder::PROJECT]);
        $this->createTeamProject((int)$this->createTeam($this->adminId)->id, $ids[E2eInventoryGateSeeder::HIDDEN_PROJECT]);
        Project::updateAll(['local_path' => '/tmp/somewhere'], ['id' => $ids[E2eInventoryGateSeeder::PROJECT]]);
        Schedule::updateAll(
            ['enabled' => 1, 'created_by' => $this->operatorId, 'cron_expression' => '* * * * *'],
            ['id' => $ids[E2eInventoryGateSeeder::SCHEDULE]]
        );
        WorkflowTemplate::updateAll(
            ['deleted_at' => time(), 'trigger_token' => str_repeat('b', 64)],
            ['id' => $ids[E2eInventoryGateSeeder::WORKFLOW]]
        );
        WorkflowStep::updateAll(
            ['step_order' => 30, 'on_failure_step_id' => WorkflowStep::END_WORKFLOW],
            ['id' => $ids[E2eInventoryGateSeeder::STEP]]
        );
        WorkflowStep::deleteAll(['id' => $afterStepId]);
        $this->createWorkflowStep($ids[E2eInventoryGateSeeder::WORKFLOW], 5, WorkflowStep::TYPE_PAUSE);
    }

    /**
     * @param list<int> $userIds
     */
    private function assertRule(string $name, array $userIds, ?int $timeoutMinutes): void
    {
        $rule = $this->rule($name);
        $this->assertSame(
            [ApprovalRule::APPROVER_TYPE_USERS, ['user_ids' => $userIds], 1, $timeoutMinutes, ApprovalRule::TIMEOUT_ACTION_REJECT, null, $this->adminId],
            [
                $rule->approver_type,
                json_decode((string)$rule->approver_config, true),
                (int)$rule->required_approvals,
                $rule->timeout_minutes === null ? null : (int)$rule->timeout_minutes,
                $rule->timeout_action,
                $rule->job_template_id,
                (int)$rule->created_by,
            ],
            $name
        );
    }

    /**
     * An admin's delete of the inventory $name answers 409 with the usage,
     * and the inventory stays.
     */
    private function assertDeleteRefused(string $name, string $summary): void
    {
        $writes = \Yii::$app->get('inventoryWriteService');
        $this->assertInstanceOf(InventoryWriteService::class, $writes);
        $inventory = $this->inventory($name);
        try {
            $writes->delete($inventory, $this->adminId, InventoryWriteService::SOURCE_WEB);
            $this->fail("{$name} was deleted.");
        } catch (InventoryWriteRefusedException $e) {
            $this->assertSame(InventoryWriteRefusedException::CONFLICT, $e->status, $name);
            $this->assertSame(sprintf(InventoryWriteService::DELETE_IN_USE, $summary), $e->getMessage());
        }
        $this->assertNotNull(Inventory::findOne($inventory->id));
    }

    private function launchRefusal(string $templateName, int $userId): ?string
    {
        $eligibility = \Yii::$app->get('approverEligibility');
        $this->assertInstanceOf(ApproverEligibility::class, $eligibility);

        return JobTemplateGate::of($this->template($templateName))->refusal($userId, $eligibility);
    }

    private function pendingRequest(Job $job, int $ruleId): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = $ruleId;
        $request->recordRequiredBy(ApprovalRequest::REQUIRED_BY_INVENTORY);
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }

    /**
     * A run of $workflow whose step #$stepId waits for job #$jobId.
     */
    private function runOf(WorkflowTemplate $workflow, int $stepId, int $jobId): WorkflowJob
    {
        $run = new WorkflowJob();
        $run->setAttributes([
            'workflow_template_id' => (int)$workflow->id,
            'launched_by' => $this->adminId,
            'status' => WorkflowJob::STATUS_RUNNING,
            'current_step_id' => $stepId,
            'started_at' => time(),
        ], false);
        $run->save(false);
        $execution = new WorkflowJobStep();
        $execution->setAttributes([
            'workflow_job_id' => (int)$run->id,
            'workflow_step_id' => $stepId,
            'job_id' => $jobId,
            'status' => WorkflowJobStep::STATUS_RUNNING,
            'started_at' => time(),
        ], false);
        $execution->save(false);

        return $run;
    }

    /**
     * The team grants of team #$teamId and of project #$projectId: [team, project, role] each.
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private function grantsOf(int $teamId, int $projectId): array
    {
        $rows = TeamProject::find()->where(['or', ['team_id' => $teamId], ['project_id' => $projectId]])->orderBy(['id' => SORT_ASC])->all();

        return array_map(static fn (TeamProject $grant): array => [(int)$grant->team_id, (int)$grant->project_id, (string)$grant->role], $rows);
    }

    /**
     * The steps of $workflow in run order: name, step order, type, job
     * template, approval rule and the three branches.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: int|null, 4: int|null, 5: int|null, 6: int|null, 7: int|null}>
     */
    private function stepsOf(WorkflowTemplate $workflow): array
    {
        $steps = WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])->all();
        $id = static fn (mixed $value): ?int => $value === null ? null : (int)$value;

        return array_map(static fn (WorkflowStep $step): array => [
            (string)$step->name,
            (int)$step->step_order,
            (string)$step->step_type,
            $id($step->job_template_id),
            $id($step->approval_rule_id),
            $id($step->on_success_step_id),
            $id($step->on_failure_step_id),
            $id($step->on_always_step_id),
        ], $steps);
    }

    private function stepId(string $name): int
    {
        $step = WorkflowStep::findOne(['workflow_template_id' => $this->workflow()->id, 'name' => $name]);
        $this->assertInstanceOf(WorkflowStep::class, $step, $name);

        return (int)$step->id;
    }

    private function ruleOf(Inventory $inventory): ?int
    {
        $ruleId = $inventory->getAttribute('approval_rule_id');

        return $ruleId === null ? null : (int)$ruleId;
    }

    private function rule(string $name): ApprovalRule
    {
        $rule = ApprovalRule::findOne(['name' => $name]);
        $this->assertInstanceOf(ApprovalRule::class, $rule, $name);

        return $rule;
    }

    private function ruleId(string $name): int
    {
        return (int)$this->rule($name)->id;
    }

    private function project(string $name): Project
    {
        $project = Project::findOne(['name' => $name]);
        $this->assertInstanceOf(Project::class, $project, $name);

        return $project;
    }

    private function team(): Team
    {
        $team = Team::findOne(['name' => E2eInventoryGateSeeder::HIDDEN_TEAM]);
        $this->assertInstanceOf(Team::class, $team);

        return $team;
    }

    private function runnerGroup(): RunnerGroup
    {
        $group = RunnerGroup::findOne(['name' => E2eInventoryGateSeeder::RUNNER_GROUP]);
        $this->assertInstanceOf(RunnerGroup::class, $group);

        return $group;
    }

    private function inventory(string $name): Inventory
    {
        $inventory = Inventory::findOne(['name' => $name]);
        $this->assertInstanceOf(Inventory::class, $inventory, $name);

        return $inventory;
    }

    private function template(string $name): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(JobTemplate::class, $template, "{$name} is live");

        return $template;
    }

    private function templateWithDeleted(string $name): JobTemplate
    {
        $template = JobTemplate::findWithDeleted()->where(['name' => $name])->one();
        $this->assertInstanceOf(JobTemplate::class, $template, $name);

        return $template;
    }

    private function schedule(): Schedule
    {
        $schedule = Schedule::findOne(['name' => E2eInventoryGateSeeder::SCHEDULE]);
        $this->assertInstanceOf(Schedule::class, $schedule);

        return $schedule;
    }

    private function workflow(): WorkflowTemplate
    {
        $workflow = WorkflowTemplate::findWithDeleted()->where(['name' => E2eInventoryGateSeeder::WORKFLOW])->one();
        $this->assertInstanceOf(WorkflowTemplate::class, $workflow);

        return $workflow;
    }

    private function usage(): InventoryUsageService
    {
        $usage = \Yii::$app->get('inventoryUsageService');
        $this->assertInstanceOf(InventoryUsageService::class, $usage);

        return $usage;
    }

    private function accessChecker(): ProjectAccessChecker
    {
        $access = \Yii::$app->get('projectAccessChecker');
        $this->assertInstanceOf(ProjectAccessChecker::class, $access);

        return $access;
    }

    /**
     * A user named $username who holds the RBAC role $role.
     */
    private function namedUser(string $username, string $role): User
    {
        $user = $this->createUser($role);
        $user->username = $username;
        $user->save(false);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $rbacRole = $auth->getRole($role);
        $this->assertNotNull($rbacRole, "RBAC role {$role}");
        $auth->assign($rbacRole, (int)$user->id);

        return $user;
    }
}
