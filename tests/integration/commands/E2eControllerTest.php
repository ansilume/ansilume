<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eApprovalSeeder;
use app\commands\E2eCancelOnlyUserSeeder;
use app\commands\E2eController;
use app\commands\E2eDeletedTemplateApprovalSeeder;
use app\commands\E2eExtraVarsEditorSeeder;
use app\commands\E2eFailingNotificationSeeder;
use app\commands\E2eInventoryGateSeeder;
use app\commands\E2eLockedTemplateSeeder;
use app\commands\E2eRunnerGroupGuardSeeder;
use app\commands\E2eScheduleRunAsSeeder;
use app\commands\E2eSurveyEditorSeeder;
use app\commands\E2eSurveySecretSeeder;
use app\commands\E2eTeardownHelper;
use app\commands\E2eUnusablePasswordNameSeeder;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\services\ApproverEligibility;
use app\services\ProjectAccessChecker;
use app\services\ScheduleService;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;
use yii\helpers\FileHelper;

/**
 * `yii e2e/teardown` exits non-zero and prints every problem when the
 * teardown leaves anything behind, so bin/tests-e2e.sh fails the run instead
 * of the next seed building on leftovers.
 *
 * Every e2e command refuses to run unless YII_ENV is dev or test.
 *
 * The exit code tests replace the teardown helper: the real one deletes the
 * e2e fixtures of the database and the vault checkouts on disk.
 *
 * `yii e2e/seed` runs here only up to the fixtures that live in the
 * database, and the one file every seed writes when it is missing, the
 * playbook of e2e-project in /tmp: the seeders after them write fixture
 * projects into /tmp and the runtime directory, outside the test's
 * transaction. One test runs it on to the team scoping fixtures, with the
 * artifact directory pointed at a scratch directory for the files of the
 * artifact job.
 */
class E2eControllerTest extends DbTestCase
{
    /**
     * The lines e2e/seed prints before the cancel-only user's line: the
     * users, then the survey fixtures.
     */
    private const SURVEY_SEED_LINES = [
        "Seeding E2E test data...\n",
        "  Created user '",
        "  User '",
        "  Reset user '",
        '  Restored unusable password name fixtures: ',
        '  Restored survey secret fixtures: ',
        '  Restored survey editor fixtures ',
        '  Restored Extra Vars editor fixtures ',
    ];

    /** Where the test stops the seed: the cancel-only user's line, before any fixture is written to disk. */
    private const STOP_LINE = "  Restored user '" . E2eCancelOnlyUserSeeder::USERNAME . "' ";

    private const SEED_STOPPED = 'The test stopped the seed at the cancel-only user.';

    /**
     * The lines of e2e/seed after which e2e-team and the approval fixtures
     * exist: the team's (a fresh seed creates it) and E2eApprovalSeeder's last.
     */
    private const TEAM_AND_APPROVAL_LINES = ['  Created team (ID ', '  Restored approval fixtures: '];

    private const SEED_STOPPED_AFTER_APPROVALS = 'The test stopped the seed after the team and the approval fixtures.';

    /** The line E2eScheduleRunAsSeeder prints; the seed writes nothing to disk before it. */
    private const RUN_AS_LINE = '  Restored ' . E2eScheduleRunAsSeeder::SCHEDULE . ' (ID ';

    private const SEED_STOPPED_AFTER_RUN_AS = 'The test stopped the seed after the run-as schedule.';

    /** The line E2eFailingNotificationSeeder prints, after the locked and the deleted gated template. */
    private const FAILING_NOTIFICATION_LINE = '  Restored ' . E2eFailingNotificationSeeder::NAME . ' (ID ';

    private const SEED_STOPPED_AFTER_FAILING_NOTIFICATION = 'The test stopped the seed after the failing notification.';

    /** The line E2eRunnerGroupGuardSeeder prints, right after the failing notification; the seed wrote nothing to disk before it. */
    private const RUNNER_GROUP_GUARD_LINE = '  Restored ' . E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP . ' (ID ';

    private const SEED_STOPPED_AFTER_RUNNER_GROUPS = 'The test stopped the seed after the runner groups in use.';

    /** The line E2eInventoryGateSeeder prints, right after the LDAP status fixtures. */
    private const INVENTORY_GATE_LINE = '  Restored inventory approval fixtures: ';

    /** The line E2eTeamScopingSeeder prints on a fresh seed, once it created its teams. */
    private const TEAM_SCOPING_LINE = '  Created team scoping data ';

    private const SEED_STOPPED_AFTER_TEAM_SCOPING = 'The test stopped the seed after the team scoping fixtures.';

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedEnvironmentProvider(): array
    {
        return [
            'prod' => ['prod'],
            'empty' => [''],
            'staging' => ['staging'],
            'other case' => ['Dev'],
            'padded' => [' test'],
        ];
    }

    /**
     * Regression: e2e/seed ran on every installation. It creates e2e-admin,
     * a superadmin whose password is in the public repository, so one seed on
     * a production install handed out a superadmin login.
     *
     * The controller fails the test as soon as the seed starts writing.
     *
     * @dataProvider refusedEnvironmentProvider
     */
    public function testSeedRefusesToRunOutsideDevAndTest(string $environment): void
    {
        $controller = $this->controller(null, $environment);
        $controller->failWhenWriting = true;
        $users = User::find()->count();

        $this->assertSame(ExitCode::CONFIG, $controller->runAction('seed'));

        $this->assertSame('', $controller->out);
        $this->assertRefusal($controller->err, $environment);
        $this->assertSame($users, User::find()->count());
    }

    /**
     * Regression: e2e/teardown ran on every installation and deleted every
     * record whose name starts with "e2e-", and the users named so with
     * everything they created. Without a teardown helper the controller
     * would fail the test if the teardown started.
     *
     * @dataProvider refusedEnvironmentProvider
     */
    public function testTeardownRefusesToRunOutsideDevAndTest(string $environment): void
    {
        $controller = $this->controller(null, $environment);

        $this->assertSame(ExitCode::CONFIG, $controller->runAction('teardown'));

        $this->assertSame('', $controller->out);
        $this->assertRefusal($controller->err, $environment);
    }

    /**
     * bin/tests-e2e.sh counts a seed as successful when its output contains
     * "complete" or "already exists", and ignores the exit code. The refusal
     * must say neither.
     */
    public function testTheRefusalCannotPassForASuccessfulSeed(): void
    {
        $controller = $this->controller(null, 'prod');
        $controller->failWhenWriting = true;

        $this->assertNotSame(ExitCode::OK, $controller->runAction('seed'));

        $output = strtolower($controller->out . $controller->err);
        $this->assertStringNotContainsString('complete', $output);
        $this->assertStringNotContainsString('already exists', $output);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function allowedEnvironmentProvider(): array
    {
        return [
            'dev (bin/tests-e2e.sh and CI)' => ['dev'],
            'test' => ['test'],
            'YII_ENV of this process' => [null],
        ];
    }

    /**
     * @dataProvider allowedEnvironmentProvider
     */
    public function testTheCommandsRunInDevAndTest(?string $environment): void
    {
        $controller = $this->controller($this->helperReporting([]), $environment);

        $this->assertSame(ExitCode::OK, $controller->runAction('teardown'));

        $this->assertSame("Tearing down E2E test data...\nE2E teardown complete.\n", $controller->out);
        $this->assertSame('', $controller->err);
    }
    public function testTeardownFailsAndPrintsEveryProblemWhenSomethingIsLeft(): void
    {
        $controller = $this->controller($this->helperReporting([
            "User 'e2e-operator' was not deleted: SQLSTATE[23000]: Integrity constraint violation: 1451",
            "1 user row(s) named like 'e2e-' are left.",
        ]));

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $controller->actionTeardown());

        $this->assertSame("Tearing down E2E test data...\n", $controller->out);
        $this->assertSame(
            "  User 'e2e-operator' was not deleted: SQLSTATE[23000]: Integrity constraint violation: 1451\n"
            . "  1 user row(s) named like 'e2e-' are left.\n"
            . "E2E teardown incomplete: 2 problem(s), see above.\n",
            $controller->err
        );
    }

    public function testTeardownSucceedsWhenNothingIsLeft(): void
    {
        $controller = $this->controller($this->helperReporting([]));

        $this->assertSame(ExitCode::OK, $controller->actionTeardown());

        $this->assertSame("Tearing down E2E test data...\nE2E teardown complete.\n", $controller->out);
        $this->assertSame('', $controller->err);
    }

    /**
     * The controller's own helper removes users named "e2e-…" and reports on
     * stdout. Only its database part runs, inside the test's transaction.
     */
    public function testTheTeardownHelperRemovesE2eUsersAndReportsOnStdout(): void
    {
        $user = $this->createUser();
        $user->username = 'e2e-' . bin2hex(random_bytes(4)) . '-ctl';
        $user->save(false);
        $controller = $this->controller(null);

        $this->assertSame([], $controller->ownTeardownHelper()->teardownDatabase());

        $this->assertNull(User::findOne($user->id));
        $this->assertStringContainsString("  Deleted user '{$user->username}'.\n", $controller->out);
    }

    /**
     * e2e/seed stores the survey fixtures first, before any fixture project:
     * the unusable password name template and schedule, the survey secret
     * template, then the survey editor and the Extra Vars editor templates
     * on its project, inventory and runner group, then the cancel-only user.
     * The seeder tests run the seeders themselves; this runs the seed's own
     * calls of them.
     *
     * The test stops the seed at the cancel-only user's line and fails at
     * any other line it does not expect before it, so the seed never gets
     * to the seeders that write into /tmp and the runtime directory, which a
     * Playwright run may be using at the same time.
     */
    public function testTheSeedStoresTheSurveyFixturesFirstOnTheSameParents(): void
    {
        $controller = $this->controller(null, null, static function (string $line): void {
            if (str_starts_with($line, self::STOP_LINE)) {
                throw new \RuntimeException(self::SEED_STOPPED);
            }
            foreach (self::SURVEY_SEED_LINES as $start) {
                if (str_starts_with($line, $start)) {
                    return;
                }
            }
            throw new \LogicException('The seed went past the survey fixtures: ' . $line);
        });

        try {
            $controller->runAction('seed');
            $this->fail('The seed ended without restoring the cancel-only user.');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SEED_STOPPED, $e->getMessage());
        }

        $parents = $this->parentsOf(E2eSurveySecretSeeder::TEMPLATE);
        $extraVars = [
            E2eSurveyEditorSeeder::REWRITTEN_VARS_TEMPLATE => E2eSurveyEditorSeeder::REWRITTEN_EXTRA_VARS,
            E2eExtraVarsEditorSeeder::BIGINT_TEMPLATE => E2eExtraVarsEditorSeeder::BIGINT_EXTRA_VARS,
            E2eExtraVarsEditorSeeder::BIGINT_SECRET_TEMPLATE => E2eExtraVarsEditorSeeder::BIGINT_SECRET_EXTRA_VARS,
            E2eExtraVarsEditorSeeder::REFUSAL_TEMPLATE => E2eExtraVarsEditorSeeder::REFUSAL_EXTRA_VARS,
        ];
        foreach ($extraVars as $name => $text) {
            $this->assertSame($parents, $this->parentsOf($name), "{$name} uses the survey secret template's parents");
            $this->assertSame($text, $this->templateNamed($name)->extra_vars, "{$name} keeps its Extra Vars as text");
        }
        $this->assertNotNull(User::findOne(['username' => E2eCancelOnlyUserSeeder::USERNAME]));
        $unusable = $this->templateNamed(E2eUnusablePasswordNameSeeder::TEMPLATE);
        $this->assertLessThan((int)$this->templateNamed(E2eSurveySecretSeeder::TEMPLATE)->id, (int)$unusable->id, 'seeded first, with the lowest ids');
        $this->assertNotSame($parents, $this->parentsOf(E2eUnusablePasswordNameSeeder::TEMPLATE), 'on parents of its own');
        $this->assertNotNull(Schedule::findOne(['name' => E2eUnusablePasswordNameSeeder::SCHEDULE, 'job_template_id' => $unusable->id]));
        foreach (array_slice(self::SURVEY_SEED_LINES, 4) as $line) {
            $this->assertSame(1, substr_count($controller->out, $line), "printed once: {$line}");
        }
    }

    /**
     * Regression: e2e/seed found e2e-approval-rule from an earlier seed and
     * kept it as it was, with e2e-admin as its only approver. The launcher
     * never approves their own job, so every launch by e2e-admin of
     * e2e-approval-workflow, and of any template the rule gates, was refused.
     *
     * The whole seed writes fixture projects to disk, so the test runs the
     * seed's approval rule step alone.
     */
    public function testRegressionTheSeedGivesTheApprovalRuleOfAnEarlierSeedASecondApprover(): void
    {
        $admin = $this->namedUser('e2e-admin', 'admin');
        $operator = $this->namedUser('e2e-operator', 'operator');
        $stale = $this->createApprovalRule((int)$admin->id);
        $stale->name = 'e2e-approval-rule';
        $stale->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $stale->approver_config = (string)json_encode(['user_ids' => [(int)$admin->id]]);
        $stale->required_approvals = 1;
        $stale->save(false);
        $controller = $this->controller(null);

        $id = (new \ReflectionMethod(E2eController::class, 'seedApprovalRule'))->invoke($controller, (int)$admin->id);

        $this->assertSame((int)$stale->id, $id);
        $rule = ApprovalRule::findOne($stale->id);
        $this->assertNotNull($rule);
        $this->assertSame(
            ['user_ids' => [(int)$admin->id, (int)$operator->id]],
            json_decode((string)$rule->approver_config, true),
            'e2e-operator decides on what e2e-admin launches'
        );
        $this->assertSame(1, (int)ApprovalRule::find()->where(['name' => 'e2e-approval-rule'])->count());
        $this->assertSame("  Restored approval rule e2e-approval-rule (ID {$stale->id}): e2e-admin and e2e-operator approve.\n", $controller->out);
    }

    /**
     * A fresh seed restores the approval fixtures after e2e-team got
     * e2e-template's project, which restricts the project to the team: only
     * then does e2e-approval-launcher join the team and may launch
     * e2e-dual-gated-template (tests/e2e/tests/approvals/two-approvals.spec.ts).
     * Seeded before the team, the launcher stayed outside it, and the launch
     * was refused.
     *
     * The test stops the seed once both are restored, before the seeders
     * that write fixture projects to disk.
     */
    public function testOnAFreshSeedTheApprovalLauncherJoinsTheTeamOfTheProjectAndMayLaunch(): void
    {
        $this->assertNull(Team::findOne(['name' => 'e2e-team']), 'a fresh seed');
        $seen = [];
        $controller = $this->controller(null, null, static function (string $line) use (&$seen): void {
            foreach (self::TEAM_AND_APPROVAL_LINES as $start) {
                if (str_starts_with($line, $start)) {
                    $seen[$start] = true;
                }
            }
            if (count($seen) === count(self::TEAM_AND_APPROVAL_LINES)) {
                throw new \RuntimeException(self::SEED_STOPPED_AFTER_APPROVALS);
            }
        });

        try {
            $controller->runAction('seed');
            $this->fail('The seed ended without restoring e2e-team and the approval fixtures.');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SEED_STOPPED_AFTER_APPROVALS, $e->getMessage());
        }

        $template = $this->templateNamed(E2eApprovalSeeder::DUAL_TEMPLATE);
        $projectId = (int)$template->project_id;
        $team = Team::findOne(['name' => 'e2e-team']);
        $this->assertNotNull($team);
        $this->assertTrue(
            TeamProject::find()->where(['team_id' => $team->id, 'project_id' => $projectId, 'role' => TeamProject::ROLE_OPERATOR])->exists(),
            'e2e-team may operate the project of e2e-template'
        );
        $launcher = User::findOne(['username' => E2eApprovalSeeder::LAUNCHER]);
        $this->assertNotNull($launcher);
        $this->assertTrue(TeamMember::find()->where(['team_id' => $team->id, 'user_id' => $launcher->id])->exists(), 'in e2e-team');
        $access = \Yii::$app->get('projectAccessChecker');
        $this->assertInstanceOf(ProjectAccessChecker::class, $access);
        $this->assertTrue($access->canOperate((int)$launcher->id, $projectId));
        $rule = ApprovalRule::findOne(['name' => E2eApprovalSeeder::DUAL_RULE]);
        $this->assertNotNull($rule);
        $eligibility = \Yii::$app->get('approverEligibility');
        $this->assertInstanceOf(ApproverEligibility::class, $eligibility);
        $this->assertNull($eligibility->jobLaunchRefusal($rule, $template, (int)$launcher->id), 'e2e-admin and e2e-operator decide');
    }

    /**
     * The seed restores e2e-admin's schedule of a template that
     * e2e-approval-rule gates, on e2e-template's project, which e2e-team
     * operates: e2e-operator may change the schedule, which then runs as
     * e2e-operator, whose job only e2e-admin may approve
     * (tests/e2e/tests/schedules/runs-as.spec.ts).
     *
     * The test stops the seed at that line, before the seeders that write
     * fixture projects to disk.
     */
    public function testTheSeedRestoresE2eAdminsScheduleOfAGatedTemplateThatE2eOperatorMayChange(): void
    {
        $controller = $this->controller(null, null, static function (string $line): void {
            if (str_starts_with($line, self::RUN_AS_LINE)) {
                throw new \RuntimeException(self::SEED_STOPPED_AFTER_RUN_AS);
            }
        });

        try {
            $controller->runAction('seed');
            $this->fail('The seed ended without restoring the run-as schedule.');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SEED_STOPPED_AFTER_RUN_AS, $e->getMessage());
        }

        $schedule = Schedule::findOne(['name' => E2eScheduleRunAsSeeder::SCHEDULE]);
        $this->assertNotNull($schedule);
        $admin = User::findOne(['username' => 'e2e-admin']);
        $operator = User::findOne(['username' => 'e2e-operator']);
        $this->assertNotNull($admin);
        $this->assertNotNull($operator);
        $this->assertSame((int)$admin->id, (int)$schedule->created_by, 'it runs as e2e-admin');
        $this->assertFalse((bool)$schedule->enabled);
        $template = $this->templateNamed(E2eScheduleRunAsSeeder::TEMPLATE);
        $this->assertSame((int)$template->id, (int)$schedule->job_template_id);
        $this->assertSame($this->parentsOf('e2e-template'), $this->parentsOf(E2eScheduleRunAsSeeder::TEMPLATE));
        $rule = ApprovalRule::findOne(['name' => E2eApprovalSeeder::RULE]);
        $this->assertNotNull($rule);
        $this->assertSame((int)$rule->id, (int)$template->approval_rule_id);

        $schedules = \Yii::$app->get('scheduleService');
        $this->assertInstanceOf(ScheduleService::class, $schedules);
        $this->assertTrue($schedules->canOperate((int)$operator->id, $schedule), 'e2e-operator may change it (e2e-team)');
        $eligibility = \Yii::$app->get('approverEligibility');
        $this->assertInstanceOf(ApproverEligibility::class, $eligibility);
        $this->assertNull($eligibility->jobLaunchRefusal($rule, $template, (int)$operator->id), 'e2e-admin decides on what e2e-operator launches');
    }

    /**
     * The seed restores, right after the run-as schedule, e2e-locked-template
     * in e2e-locked-project (no team: e2e-operator may change it as far as
     * the lock lets them, e2e-viewer sees it) with e2e-template's inventory,
     * runner group and credential, the deleted e2e-deleted-gate-template on
     * e2e-template's parents, both gated by e2e-approval-rule, and
     * e2e-failing-notification (tests/e2e/tests/job-templates/rbac.spec.ts,
     * approvals/deleted-template.spec.ts,
     * notification-templates/send-test.spec.ts).
     *
     * The test stops the seed at the failing notification's line, before
     * the seeders that write fixture projects to disk.
     */
    public function testTheSeedRestoresTheLockedTemplateTheDeletedGatedTemplateAndTheFailingNotification(): void
    {
        $controller = $this->controller(null, null, static function (string $line): void {
            if (str_starts_with($line, self::FAILING_NOTIFICATION_LINE)) {
                throw new \RuntimeException(self::SEED_STOPPED_AFTER_FAILING_NOTIFICATION);
            }
        });

        try {
            $controller->runAction('seed');
            $this->fail('The seed ended without restoring the failing notification.');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SEED_STOPPED_AFTER_FAILING_NOTIFICATION, $e->getMessage());
        }

        $rule = ApprovalRule::findOne(['name' => E2eApprovalSeeder::RULE]);
        $this->assertNotNull($rule);
        $locked = $this->templateNamed(E2eLockedTemplateSeeder::TEMPLATE);
        $parent = $this->templateNamed('e2e-template');
        $this->assertSame((int)$rule->id, (int)$locked->approval_rule_id);
        $this->assertSame(
            [(int)$parent->inventory_id, (int)$parent->runner_group_id, (int)$parent->credential_id],
            [(int)$locked->inventory_id, (int)$locked->runner_group_id, (int)$locked->credential_id]
        );
        $project = Project::findOne($locked->project_id);
        $this->assertNotNull($project);
        $this->assertSame(E2eLockedTemplateSeeder::PROJECT, $project->name);
        $operator = User::findOne(['username' => 'e2e-operator']);
        $viewer = User::findOne(['username' => 'e2e-viewer']);
        $this->assertNotNull($operator);
        $this->assertNotNull($viewer);
        $access = \Yii::$app->get('projectAccessChecker');
        $this->assertInstanceOf(ProjectAccessChecker::class, $access);
        $this->assertTrue($access->canOperate((int)$operator->id, (int)$locked->project_id), 'e2e-operator may rename it');
        $this->assertTrue($access->canView((int)$viewer->id, (int)$locked->project_id), 'e2e-viewer sees it');

        $deleted = JobTemplate::findWithDeleted()->where(['name' => E2eDeletedTemplateApprovalSeeder::TEMPLATE])->one();
        $this->assertInstanceOf(JobTemplate::class, $deleted);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame((int)$rule->id, (int)$deleted->approval_rule_id);
        $this->assertSame($this->parentsOf('e2e-template'), [(int)$deleted->project_id, (int)$deleted->inventory_id, (int)$deleted->runner_group_id]);

        $notification = NotificationTemplate::findOne(['name' => E2eFailingNotificationSeeder::NAME]);
        $this->assertNotNull($notification);
        $this->assertSame(['url' => E2eFailingNotificationSeeder::URL], $notification->getParsedConfig());
    }

    /**
     * The seed restores the runner groups in use of
     * runner-groups/delete-in-use.spec.ts and rbac.spec.ts: one that
     * e2e-group-template uses (on e2e-template's project and inventory), one
     * that the runner e2e-group-runner belongs to.
     *
     * The test stops the seed at their line, before the seeders that write
     * fixture projects to disk.
     */
    public function testTheSeedRestoresARunnerGroupThatATemplateUsesAndOneThatARunnerBelongsTo(): void
    {
        $controller = $this->controller(null, null, static function (string $line): void {
            if (str_starts_with($line, self::RUNNER_GROUP_GUARD_LINE)) {
                throw new \RuntimeException(self::SEED_STOPPED_AFTER_RUNNER_GROUPS);
            }
        });

        try {
            $controller->runAction('seed');
            $this->fail('The seed ended without restoring the runner groups in use.');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SEED_STOPPED_AFTER_RUNNER_GROUPS, $e->getMessage());
        }

        $templateGroup = RunnerGroup::findOne(['name' => E2eRunnerGroupGuardSeeder::TEMPLATE_GROUP]);
        $runnerGroup = RunnerGroup::findOne(['name' => E2eRunnerGroupGuardSeeder::RUNNER_GROUP]);
        $this->assertNotNull($templateGroup);
        $this->assertNotNull($runnerGroup);
        $template = $this->templateNamed(E2eRunnerGroupGuardSeeder::TEMPLATE);
        $parent = $this->templateNamed('e2e-template');
        $this->assertSame(
            [(int)$parent->project_id, (int)$parent->inventory_id, (int)$templateGroup->id],
            [(int)$template->project_id, (int)$template->inventory_id, (int)$template->runner_group_id]
        );
        $this->assertSame(0, (int)$templateGroup->getRunners()->count());
        $this->assertSame(['e2e-group-runner'], $runnerGroup->getRunners()->select('name')->column());
        $this->assertSame(0, (int)$runnerGroup->getJobTemplates()->count());
    }

    /**
     * A fresh seed restores the inventory approval fixtures
     * (E2eInventoryGateSeeder) right after the LDAP status fixtures, before
     * e2e-inventory and before E2eTeamScopingSeeder creates e2e-team-alpha and
     * e2e-team-beta:
     *
     * - their inventories are older than e2e-inventory, so they never push it
     *   off the first page of the inventory list (yaml-editor.spec.ts);
     * - e2e-host-hidden-template is hidden from e2e-operator and e2e-viewer by
     *   the seeder's own team, also once they joined the team scoping teams,
     *   and E2eTeamScopingSeeder still creates its teams (it creates
     *   e2e-team-beta under a unique name);
     * - e2e-inventory and e2e-approval-rule gate nothing new: the other specs
     *   launch e2e-template without an approval.
     *
     * The seed writes the files of its artifact job on the way
     * (E2eArtifactSeeder): the test points @runtime/artifacts at a scratch
     * directory for them, and stops the seed at the team scoping line, before
     * the seeders that write fixture projects to disk.
     */
    public function testOnAFreshSeedTheInventoryApprovalFixturesComeBeforeE2eInventoryAndTheTeamScopingTeams(): void
    {
        $this->assertNull(Team::findOne(['name' => 'e2e-team-beta']), 'a fresh seed');
        $atGateLine = [];
        $controller = $this->controller(null, null, static function (string $line) use (&$atGateLine): void {
            if (str_starts_with($line, self::INVENTORY_GATE_LINE)) {
                $atGateLine[] = [
                    'e2e-inventory' => Inventory::find()->where(['name' => 'e2e-inventory'])->exists(),
                    'e2e-team-beta' => Team::find()->where(['name' => 'e2e-team-beta'])->exists(),
                ];
            }
            if (str_starts_with($line, self::TEAM_SCOPING_LINE)) {
                throw new \RuntimeException(self::SEED_STOPPED_AFTER_TEAM_SCOPING);
            }
        });
        $artifacts = sys_get_temp_dir() . '/ansilume-e2e-controller-test-' . bin2hex(random_bytes(6));
        $runtimeAliases = \Yii::$aliases['@runtime'];
        \Yii::setAlias('@runtime/artifacts', $artifacts);
        try {
            try {
                $controller->runAction('seed');
                $this->fail('The seed ended without creating the team scoping fixtures.');
            } catch (\RuntimeException $e) {
                $this->assertSame(self::SEED_STOPPED_AFTER_TEAM_SCOPING, $e->getMessage());
            }
            $this->assertDirectoryExists($artifacts, 'the artifact job wrote its files to the scratch directory');
        } finally {
            \Yii::$aliases['@runtime'] = $runtimeAliases;
            FileHelper::removeDirectory($artifacts);
        }

        $this->assertSame([['e2e-inventory' => false, 'e2e-team-beta' => false]], $atGateLine, 'restored once, before both');
        $this->assertNotNull(Team::findOne(['name' => 'e2e-team-alpha']));
        $this->assertNotNull(Team::findOne(['name' => 'e2e-team-beta']));
        $shared = Inventory::findOne(['name' => 'e2e-inventory']);
        $this->assertNotNull($shared);
        $gateInventories = Inventory::find()->where(['name' => [
            E2eInventoryGateSeeder::INVENTORY,
            E2eInventoryGateSeeder::SOLO_INVENTORY,
            E2eInventoryGateSeeder::CONFLICT_INVENTORY,
            E2eInventoryGateSeeder::IN_USE_INVENTORY,
            E2eInventoryGateSeeder::DELETED_ONLY_INVENTORY,
            E2eInventoryGateSeeder::ASSIGN_INVENTORY,
        ]])->select('id')->column();
        $this->assertCount(6, $gateInventories);
        $this->assertLessThan((int)$shared->id, max(array_map('intval', $gateInventories)), 'older than e2e-inventory');

        $hidden = $this->templateNamed(E2eInventoryGateSeeder::HIDDEN_TEMPLATE);
        $access = \Yii::$app->get('projectAccessChecker');
        $this->assertInstanceOf(ProjectAccessChecker::class, $access);
        foreach (['e2e-operator', 'e2e-viewer'] as $username) {
            $user = User::findOne(['username' => $username]);
            $this->assertNotNull($user);
            $this->assertFalse($access->canView((int)$user->id, (int)$hidden->project_id), "{$username} cannot see it");
        }
        $admin = User::findOne(['username' => 'e2e-admin']);
        $this->assertNotNull($admin);
        $this->assertTrue($access->canView((int)$admin->id, (int)$hidden->project_id));

        $this->assertNull($shared->getAttribute('approval_rule_id'), 'e2e-inventory stays ungated');
        $sharedRule = ApprovalRule::findOne(['name' => E2eApprovalSeeder::RULE]);
        $this->assertNotNull($sharedRule);
        $this->assertFalse(Inventory::find()->where(['approval_rule_id' => $sharedRule->id])->exists(), 'no inventory has e2e-approval-rule');
        $this->assertSame((int)$shared->id, (int)$this->templateNamed('e2e-template')->inventory_id);
        $this->assertFalse(
            JobTemplate::findWithDeleted()->where(['inventory_id' => $shared->id, 'name' => [
                E2eInventoryGateSeeder::TEMPLATE,
                E2eInventoryGateSeeder::HIDDEN_TEMPLATE,
                E2eInventoryGateSeeder::SOLO_TEMPLATE,
                E2eInventoryGateSeeder::CONFLICT_TEMPLATE,
                E2eInventoryGateSeeder::IN_USE_TEMPLATE,
                E2eInventoryGateSeeder::DELETED_ONLY_TEMPLATE,
                E2eInventoryGateSeeder::ASSIGN_TEMPLATE,
            ]])->exists(),
            'no fixture of it uses e2e-inventory'
        );
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

    /**
     * A teardown helper that deletes nothing and reports $problems.
     *
     * @param list<string> $problems
     */
    private function helperReporting(array $problems): E2eTeardownHelper
    {
        return new class ($problems) extends E2eTeardownHelper {
            /** @var list<string> */
            private array $reported;

            /**
             * @param list<string> $reported
             */
            public function __construct(array $reported)
            {
                parent::__construct('unused-', static function (string $msg): void {
                });
                $this->reported = $reported;
            }

            public function teardownAll(): array
            {
                return $this->reported;
            }
        };
    }

    private function assertRefusal(string $stderr, string $environment): void
    {
        $this->assertStringContainsString('Refused: the e2e commands run only with YII_ENV dev or test', $stderr);
        $this->assertStringContainsString('YII_ENV is "' . $environment . '" here', $stderr);
    }

    private function templateNamed(string $name): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(JobTemplate::class, $template, "{$name} is stored");

        return $template;
    }

    /**
     * @return array{0: int, 1: int, 2: int} the project, inventory and runner group ids of the template $name
     */
    private function parentsOf(string $name): array
    {
        $template = $this->templateNamed($name);

        return [(int)$template->project_id, (int)$template->inventory_id, (int)$template->runner_group_id];
    }

    /**
     * An e2e controller whose output is captured and whose teardown runs
     * $helper. Without one, actionTeardown() refuses to run. $environment
     * replaces YII_ENV for the guard (null: the YII_ENV of this process).
     * $onOutput gets every line written to stdout, once it is captured.
     *
     * @param (\Closure(string): void)|null $onOutput
     * @return E2eController&object{out: string, err: string, failWhenWriting: bool}
     */
    private function controller(
        ?E2eTeardownHelper $helper,
        ?string $environment = null,
        ?\Closure $onOutput = null
    ): E2eController {
        $controller = new class ('e2e', \Yii::$app) extends E2eController {
            public string $out = '';
            public string $err = '';
            public ?E2eTeardownHelper $helper = null;
            public ?string $environment = null;
            public bool $failWhenWriting = false;
            /** @var (\Closure(string): void)|null */
            public ?\Closure $onOutput = null;

            public function ownTeardownHelper(): E2eTeardownHelper
            {
                return parent::createTeardownHelper();
            }

            protected function yiiEnvironment(): string
            {
                return $this->environment ?? parent::yiiEnvironment();
            }

            public function stdout($string): int
            {
                if ($this->failWhenWriting) {
                    throw new \LogicException('The e2e command ran: ' . $string);
                }
                $this->out .= $string;
                if ($this->onOutput !== null) {
                    ($this->onOutput)($string);
                }
                return 0;
            }

            public function stderr($string): int
            {
                $this->err .= $string;
                return 0;
            }

            protected function createTeardownHelper(): E2eTeardownHelper
            {
                if ($this->helper === null) {
                    throw new \LogicException('No teardown helper set: the real one deletes the E2E fixtures.');
                }
                return $this->helper;
            }
        };
        $controller->helper = $helper;
        $controller->environment = $environment;
        $controller->onOutput = $onOutput;

        return $controller;
    }
}
