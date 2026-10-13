<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eLockedTemplateSeeder;
use app\commands\E2eTeardownHelper;
use app\models\ApprovalRule;
use app\models\Credential;
use app\models\JobTemplate;
use app\models\Project;
use app\models\TeamProject;
use app\services\JobTemplateCredentialService;
use app\services\ProjectAccessChecker;
use app\tests\integration\DbTestCase;

/**
 * The fixture of the E2E specs for a job template whose content only admins
 * may change (E2eLockedTemplateSeeder, tests/e2e/tests/job-templates/
 * rbac.spec.ts and approval-rule.spec.ts). The specs find it by these names;
 * the test database holds no "e2e-" rows outside a test, and the rollback
 * removes them.
 */
class E2eLockedTemplateSeederTest extends DbTestCase
{
    private int $adminId;

    /** Stands for e2e-template, whose parents and primary credential the fixture uses. */
    private JobTemplate $parent;

    private Credential $primary;

    /** Stands for e2e-approval-rule. */
    private ApprovalRule $rule;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin');
        $admin->username = 'e2e-admin';
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->parent = $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
        $this->primary = $this->createCredential($this->adminId);
        $this->parent->credential_id = (int)$this->primary->id;
        $this->parent->save(false);
        $this->rule = $this->createApprovalRule(
            $this->adminId,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [$this->adminId]])
        );
        $this->log = [];
    }

    /**
     * Gated, in a project of its own with e2e-template's inventory and
     * runner group, with what a rename must keep: the primary credential and
     * an additional one, extra vars, a survey and options that differ from
     * the form's defaults.
     */
    public function testTheTemplateIsGatedAndHoldsWhatARenameMustKeep(): void
    {
        $this->seed();

        $template = $this->template();
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id);
        $project = $this->project();
        $this->assertNotSame((int)$this->parent->project_id, (int)$project->id);
        $this->assertNull($project->local_path, 'a save does not run ansible-lint');
        $this->assertSame(
            [(int)$project->id, (int)$this->parent->inventory_id, (int)$this->parent->runner_group_id],
            [(int)$template->project_id, (int)$template->inventory_id, (int)$template->runner_group_id]
        );
        $this->assertSame(E2eLockedTemplateSeeder::DESCRIPTION, $template->description);
        $this->assertSame(E2eLockedTemplateSeeder::PLAYBOOK, $template->playbook);
        $this->assertSame(E2eLockedTemplateSeeder::EXTRA_VARS, $template->extra_vars);
        $this->assertSame(E2eLockedTemplateSeeder::SURVEY, json_decode((string)$template->survey_fields, true));
        $this->assertSame(
            [1, 7, 45, 'localhost', 'e2e', 'slow', true, 'sudo', 'root'],
            [
                (int)$template->verbosity,
                (int)$template->forks,
                (int)$template->timeout_minutes,
                $template->limit,
                $template->tags,
                $template->skip_tags,
                (bool)$template->become,
                $template->become_method,
                $template->become_user,
            ]
        );
        $this->assertNull($template->trigger_token);
        $this->assertNull($template->deleted_at);
        $this->assertSame($this->adminId, (int)$template->created_by, 'e2e-admin\'s');

        $credential = $this->credential();
        $this->assertSame(Credential::TYPE_TOKEN, $credential->credential_type);
        $this->assertSame('E2E_LOCKED_TOKEN', $credential->env_var_name);
        $this->assertNotEmpty($credential->secret_data, 'its token is stored, encrypted');
        $this->assertSame(
            [[(int)$this->primary->id, Credential::ROLE_PRIMARY], [(int)$credential->id, Credential::ROLE_ADDITIONAL]],
            $this->credentialsOf($template)
        );

        $this->assertSame([
            sprintf(
                "  Restored e2e-locked-template (ID %d) in e2e-locked-project (ID %d), gated by approval rule #%d, with e2e-locked-credential (ID %d): only admins change what it runs.\n",
                $template->id,
                $project->id,
                $this->rule->id,
                $credential->id
            ),
        ], $this->log);
    }

    /**
     * Its project has no team, so every role sees the template: an operator
     * may change it as far as the lock lets them, a viewer may only view it
     * (the RBAC rules of the controllers decide what each may do).
     */
    public function testEveryRoleSeesTheTemplateItsProjectHasNoTeam(): void
    {
        $this->seed();
        $projectId = (int)$this->template()->project_id;
        $operator = $this->createUser('operator');
        $viewer = $this->createUser('viewer');
        $access = \Yii::$app->get('projectAccessChecker');
        $this->assertInstanceOf(ProjectAccessChecker::class, $access);

        $this->assertFalse(TeamProject::find()->where(['project_id' => $projectId])->exists());
        $this->assertTrue($access->canOperate((int)$operator->id, $projectId));
        $this->assertTrue($access->canView((int)$viewer->id, $projectId));
    }

    /**
     * The fixture is valid by today's rules: a rename through the form
     * saves, so the specs see the lock at work, not a validation error, and
     * the rename keeps the credentials.
     */
    public function testARenameOfTheFixtureIsValidAndKeepsItsCredentials(): void
    {
        $this->seed();
        $template = $this->template();
        $before = $this->credentialsOf($template);
        $template->name = E2eLockedTemplateSeeder::RENAMED;

        $saved = $this->credentialService()->saveWithCredentials($template, null);

        $this->assertTrue($saved, json_encode($template->errors) ?: '');
        $renamed = JobTemplate::findOne(['name' => E2eLockedTemplateSeeder::RENAMED]);
        $this->assertNotNull($renamed);
        $this->assertSame($before, $this->credentialsOf($renamed));
        $this->assertSame(E2eLockedTemplateSeeder::EXTRA_VARS, $renamed->extra_vars);
    }

    /**
     * After a spec run that stopped before it renamed the template back,
     * and with everything else changed and the template deleted: seeding
     * again restores it in place, under its name.
     */
    public function testSeedingAgainRestoresARenamedChangedAndDeletedTemplateInPlace(): void
    {
        $this->seed();
        $template = $this->template();
        $credentialId = (int)$this->credential()->id;
        $other = $this->createCredential($this->adminId);
        $otherRule = $this->createApprovalRule($this->adminId);
        JobTemplate::updateAll([
            'name' => E2eLockedTemplateSeeder::RENAMED,
            'description' => 'changed',
            'playbook' => 'other.yml',
            'approval_rule_id' => $otherRule->id,
            'extra_vars' => '{"a":1}',
            'survey_fields' => null,
            'forks' => 50,
            'credential_id' => null,
            'trigger_token' => str_repeat('a', 64),
            'trigger_token_created_by' => $this->adminId,
            'deleted_at' => time(),
        ], ['id' => $template->id]);
        $db = \Yii::$app->db;
        $db->createCommand()->delete('{{%job_template_credential}}', ['job_template_id' => $template->id])->execute();
        $db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => $template->id,
            'credential_id' => $other->id,
            'sort_order' => 0,
        ])->execute();
        Credential::updateAll(['env_var_name' => 'OTHER'], ['id' => $credentialId]);
        $projectId = (int)$template->project_id;
        $team = $this->createTeam($this->adminId);
        $this->createTeamProject((int)$team->id, $projectId);
        Project::updateAll(['local_path' => '/tmp/somewhere'], ['id' => $projectId]);

        $this->seed();

        $restored = $this->template();
        $this->assertSame((int)$template->id, (int)$restored->id, 'restored in place');
        $this->assertNull($restored->deleted_at);
        $this->assertSame(E2eLockedTemplateSeeder::DESCRIPTION, $restored->description);
        $this->assertSame(E2eLockedTemplateSeeder::PLAYBOOK, $restored->playbook);
        $this->assertSame((int)$this->rule->id, (int)$restored->approval_rule_id);
        $this->assertSame(E2eLockedTemplateSeeder::EXTRA_VARS, $restored->extra_vars);
        $this->assertSame(E2eLockedTemplateSeeder::SURVEY, json_decode((string)$restored->survey_fields, true));
        $this->assertSame(7, (int)$restored->forks);
        $this->assertNull($restored->trigger_token);
        $this->assertNull($restored->trigger_token_created_by);
        $this->assertSame(
            [[(int)$this->primary->id, Credential::ROLE_PRIMARY], [$credentialId, Credential::ROLE_ADDITIONAL]],
            $this->credentialsOf($restored)
        );
        $this->assertSame('E2E_LOCKED_TOKEN', $this->credential()->env_var_name);
        $this->assertSame($projectId, (int)$restored->project_id, 'the same project');
        $this->assertFalse(TeamProject::find()->where(['project_id' => $projectId])->exists(), 'without a team again');
        $this->assertNull($this->project()->local_path);
        $this->assertSame(1, (int)Project::find()->where(['name' => E2eLockedTemplateSeeder::PROJECT])->count());
        $names = [E2eLockedTemplateSeeder::TEMPLATE, E2eLockedTemplateSeeder::RENAMED];
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => $names])->count());
        $this->assertSame(1, (int)Credential::find()->where(['name' => E2eLockedTemplateSeeder::CREDENTIAL])->count());
        $this->assertCount(2, $this->log);
    }

    /**
     * A renamed template that is still live comes back under its name too.
     */
    public function testSeedingAgainRestoresTheNameOfALiveRenamedTemplate(): void
    {
        $this->seed();
        $id = (int)$this->template()->id;
        JobTemplate::updateAll(['name' => E2eLockedTemplateSeeder::RENAMED], ['id' => $id]);

        $this->seed();

        $this->assertSame($id, (int)$this->template()->id);
        $this->assertNull(JobTemplate::findWithDeleted()->where(['name' => E2eLockedTemplateSeeder::RENAMED])->one());
    }

    /**
     * Without a primary credential on e2e-template, the fixture has none
     * either: its additional credential stays.
     */
    public function testAParentWithoutAPrimaryCredentialGivesNone(): void
    {
        $this->parent->credential_id = null;
        $this->parent->save(false);

        $this->seed();

        $this->assertSame(
            [[(int)$this->credential()->id, Credential::ROLE_ADDITIONAL]],
            $this->credentialsOf($this->template())
        );
    }

    public function testATemplateThatIsGoneIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Job template #999999999 not found.');

        $this->seeder()->seed($this->adminId, 999999999, (int)$this->rule->id);
    }

    /**
     * yii e2e/teardown removes the template (also under the name a spec
     * gave it, and the clones a spec made), and the credential.
     */
    public function testTheTeardownRemovesTheTemplateItsClonesAndTheCredential(): void
    {
        $this->rule->name = 'e2e-approval-rule';
        $this->rule->save(false);
        $this->seed();
        $clone = $this->createJobTemplate(
            (int)$this->template()->project_id,
            (int)$this->parent->inventory_id,
            (int)$this->parent->runner_group_id,
            $this->adminId
        );
        $clone->name = E2eLockedTemplateSeeder::TEMPLATE . ' (copy)';
        $clone->approval_rule_id = (int)$this->rule->id;
        $clone->save(false);
        $clone->softDelete();
        JobTemplate::updateAll(['name' => E2eLockedTemplateSeeder::RENAMED], ['id' => $this->template()->id]);

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertSame(0, (int)JobTemplate::findWithDeleted()->where(['like', 'name', 'e2e-locked-template'])->count());
        $this->assertNull(Credential::findOne(['name' => E2eLockedTemplateSeeder::CREDENTIAL]));
        $this->assertNull(Project::findOne(['name' => E2eLockedTemplateSeeder::PROJECT]));
        $this->assertNull(ApprovalRule::findOne($this->rule->id));
    }

    private function seed(): void
    {
        $this->seeder()->seed($this->adminId, (int)$this->parent->id, (int)$this->rule->id);
    }

    private function seeder(): E2eLockedTemplateSeeder
    {
        return new E2eLockedTemplateSeeder(function (string $msg): void {
            $this->log[] = $msg;
        });
    }

    private function template(): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => E2eLockedTemplateSeeder::TEMPLATE]);
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    private function project(): Project
    {
        $project = Project::findOne(['name' => E2eLockedTemplateSeeder::PROJECT]);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
    }

    private function credential(): Credential
    {
        $credential = Credential::findOne(['name' => E2eLockedTemplateSeeder::CREDENTIAL]);
        $this->assertInstanceOf(Credential::class, $credential);

        return $credential;
    }

    /**
     * The template's credentials in precedence order: [id, role] each.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function credentialsOf(JobTemplate $template): array
    {
        return array_map(
            static fn (array $entry): array => [0 => $entry['id'], 1 => $entry['role']],
            $this->credentialService()->describe($template)
        );
    }

    private function credentialService(): JobTemplateCredentialService
    {
        $service = \Yii::$app->get('jobTemplateCredentialService');
        $this->assertInstanceOf(JobTemplateCredentialService::class, $service);

        return $service;
    }
}
