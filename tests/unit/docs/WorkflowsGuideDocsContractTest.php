<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\SurveyDefinition;
use app\components\WorkflowStepChange;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepMapping;
use app\components\WorkflowStepPosition;
use app\controllers\ApprovalController;
use app\controllers\SiteController;
use app\controllers\WorkflowTemplateController;
use app\helpers\TimeHelper;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ApproverEligibility;
use app\services\runstate\Display;
use app\services\runstate\PollPayload;
use app\services\runstate\Texts;
use app\services\WorkflowCancellationService;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepFormView;
use app\services\WorkflowStepsTable;
use app\services\WorkflowStepWriteService;
use app\services\WorkflowTemplatePage;
use app\tests\unit\services\WorkflowStepModels;
use PHPUnit\Framework\TestCase;

/**
 * docs/workflows.md, the operator guide to workflows, says what the code
 * does: it has the headings that the other docs link to, in their order,
 * every label, flash and refusal it quotes is the text of the
 * constant that the page or the API shows, its examples of the steps table,
 * the success path and the change flashes are what the presenters and
 * WorkflowStepChangeSummary make of the example workflow, and its API
 * examples call routes of config/web.php with bodies the readers take.
 *
 * The example workflow "Release web" (ID 7): 1. Build (41, On Failure ends
 * the run), 2. Release approval (42), 3. Deploy (43, On Failure → 45),
 * 4. Verify (44, On Success ends the run, On Failure ↺ 43), 5. Rollback (45).
 */
class WorkflowsGuideDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use WorkflowStepModels;

    /** The guide's headings, in this order; other docs link to them. */
    public const HEADINGS = [
        '# Workflows',
        '## Concepts',
        '## Building a workflow',
        '## Positions and the run order',
        '## Branches: where a run goes next',
        '## Moving steps',
        '## Editing a step',
        '## Removing a step',
        '## Running a workflow',
        '## What a run waits for',
        '## Deciding on an approval',
        '## Where waiting runs show',
        '## A run that seems stuck',
        '## Who may do what',
        '## API',
        '### Steps',
        '### Runs and approvals',
    ];

    private const GUIDE = 'docs/workflows.md';

    /** An example time of the banner and the decision notes (TimeHelper::stamp() of today). */
    private const TODAY = '14:02:11';

    /** The same day as TODAY, after it. */
    private const DECIDED = '14:05:40';

    /** An example time of another day (TimeHelper::stamp()). */
    private const OTHER_DAY = '2026-10-14 14:02:11';

    public function testTheGuideHasTheHeadingsOfThePlanInOrder(): void
    {
        $headings = [];
        $inCode = false;
        foreach (preg_split('/\R/', $this->read(self::GUIDE)) ?: [] as $line) {
            $inCode = $inCode !== str_starts_with($line, '```');
            if (!$inCode && preg_match('/^#{1,6} /', $line) === 1) {
                $headings[] = $line;
            }
        }

        $this->assertSame(self::HEADINGS, $headings);
    }

    /**
     * The example table of the steps of "Release web" is what the steps
     * table shows for it, cell by cell, and so are its success path, the
     * choices of its Add Step form and the hints of its Move to… menu.
     */
    public function testTheExampleIsWhatTheTemplatePageShows(): void
    {
        $steps = self::exampleSteps();
        $map = WorkflowRouteMap::fromModels($steps);
        $table = new WorkflowStepsTable($map, $steps, true, []);
        $guide = $this->flat(self::GUIDE, '## Branches: where a run goes next');
        foreach ($table->rows() as $row) {
            $cells = [];
            foreach (['success', 'failure', 'always'] as $branch) {
                $cells[] = '`' . implode(WorkflowStepsTable::PART_SEPARATOR, array_column($row['routes'][$branch]['parts'], 'text')) . '`';
            }
            $line = sprintf('| %d | %s | %s | %s |', $row['position'], $row['name'], $row['typeLabel'], implode(' | ', $cells));
            $this->assertMentions($line, $guide, 'The example table of workflows.md');
        }
        $path = $table->successPath();
        $this->assertNotNull($path);
        $this->assertFalse($path['loops']);
        $this->assertMentions('`' . WorkflowStepsTable::SUCCESS_PATH_LABEL . ' ' . $path['text'] . '`', $this->flat(self::GUIDE), 'workflows.md');

        $add = WorkflowStepFormView::forAdd($map, null)->position(new WorkflowStep());
        $this->assertNotNull($add);
        $building = $this->flat(self::GUIDE, '## Building a workflow');
        $this->assertSame('end', $add['selected']);
        foreach (['end', 'start', 'after:43'] as $choice) {
            $this->assertMentions('`' . $add['items'][$choice] . '`', $building, 'workflows.md, on Position');
        }
        $this->assertMentions('`' . $add['options']['after:43']['data-preview'] . '`', $building, 'workflows.md, on the preview');
        // The preview follows the branches: Verify's success ends the run, so a step after it is skipped.
        $this->assertSame(sprintf(WorkflowStepFormView::PREVIEW_SKIPPED_END, 'Verify'), $add['options']['after:44']['data-preview']);
        $this->assertMentions('`' . $add['options']['after:44']['data-preview'] . '`', $building, 'workflows.md, on the preview');
        $insertBelow = WorkflowStepFormView::forAdd($map, 43)->position(new WorkflowStep());
        $this->assertSame('after:43', $insertBelow['selected'] ?? null, '+ Insert below on Deploy preselects after Deploy.');

        $moves = [];
        foreach ($table->rows()[2]['actions']['moveTargets'] ?? [] as $target) {
            $moves[$target['value']] = $target;
        }
        $moving = $this->flat(self::GUIDE, '## Moving steps');
        foreach (['start', 'after:41', 'end'] as $value) {
            $this->assertArrayHasKey($value, $moves);
        }
        $this->assertMentions(
            '`' . $moves['start']['label'] . '`, `' . $moves['after:41']['label'] . '` or `' . $moves['end']['label'] . '`',
            $moving,
            'workflows.md, on Move to…'
        );
        foreach ($moves['start']['hints'] as $hint) {
            $this->assertMentions('`' . $hint . '`', $moving, 'workflows.md, on the hints of Move to…');
        }
    }

    /**
     * The flashes the guide quotes for the example are what
     * WorkflowStepChangeSummary says about each change.
     */
    public function testTheChangeFlashesAreTheSummarysWords(): void
    {
        [$build, $approval, $deploy, $verify, $rollback] = self::exampleSteps();
        $all = [$build, $approval, $deploy, $verify, $rollback];
        $smokeTest = self::job(46, 'Smoke test', ['failure' => 0]);
        $added = self::summary(WorkflowStepChange::ADDED, $smokeTest, 0, 4, $all, [$build, $approval, $deploy, $smokeTest, $verify, $rollback]);
        $moved = self::summary(WorkflowStepChange::MOVED, $deploy, 3, 1, $all, [$deploy, $build, $approval, $verify, $rollback]);
        $rerouted = self::job(44, 'Verify', ['success' => 0, 'failure' => 45]);
        $saved = self::summary(WorkflowStepChange::UPDATED, $rerouted, 4, 4, $all, [$build, $approval, $deploy, $rerouted, $rollback], [
            'on_failure_step_id' => ['from' => 43, 'to' => 45],
        ]);
        $removed = self::summary(WorkflowStepChange::REMOVED, $verify, 4, 0, $all, [$build, $approval, $deploy, $rollback]);

        $this->assertSame([], $added['warnings']);
        $this->assertMentions('`' . $added['message'] . '`', $this->flat(self::GUIDE, '## Building a workflow'), 'workflows.md, on Add Step');
        $moving = $this->flat(self::GUIDE, '## Moving steps');
        $this->assertMentions('`' . $moved['message'] . '`', $moving, 'workflows.md, on moves');
        // The warnings follow the branches: Deploy's On Failure leads to Rollback, which runs now reach before the approval.
        $this->assertSame([
            sprintf(WorkflowStepChangeSummary::START_CHANGED, 'Deploy', 'Build'),
            sprintf(WorkflowStepChangeSummary::JOB_BEFORE_APPROVAL, 'Deploy', 'Release approval'),
            sprintf(WorkflowStepChangeSummary::JOB_BEFORE_APPROVAL, 'Rollback', 'Release approval'),
        ], $moved['warnings']);
        $this->assertMentions('`' . $moved['warnings'][0] . '`, `' . $moved['warnings'][1] . '` and `' . $moved['warnings'][2] . '`', $moving, 'workflows.md, on moves');
        $this->assertMentions('`' . sprintf(WorkflowStepChangeSummary::STAYS, 'Deploy', 3) . '`', $moving, 'workflows.md, on moves');
        $editing = $this->flat(self::GUIDE, '## Editing a step');
        $this->assertSame([], $saved['warnings']);
        $this->assertMentions('`' . $saved['message'] . '`', $editing, 'workflows.md, on edits');
        $this->assertMentions('`' . sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Verify') . '`', $editing, 'workflows.md, on edits');
        $removing = $this->flat(self::GUIDE, '## Removing a step');
        $this->assertMentions('`' . $removed['message'] . '`', $removing, 'workflows.md, on removals');
        $this->assertMentions('`' . sprintf(WorkflowStepChangeSummary::JOB_WITHOUT_APPROVAL, 'Deploy') . '`', $removing, 'workflows.md, on removals');
        $routedTo = WorkflowStepChangeGuard::routedToMessage($rollback, [$deploy], [41 => 1, 42 => 2, 43 => 3, 44 => 4, 45 => 5]);
        $this->assertMentions('`' . $routedTo . '`', $removing, 'workflows.md, on removals');
        $this->assertMentions('`' . WorkflowStepChangeGuard::runsActiveMessage([12]) . '`', $removing, 'workflows.md, on active runs');
        $this->assertMentions('`' . sprintf(WorkflowStepsTable::REMOVE_CONFIRM, WorkflowStepsTable::stepReference(4, 'Verify')) . '`', $removing, 'workflows.md');
    }

    /**
     * The buttons, menus and pages the guide names in bold are named so on
     * the pages: the texts of the steps table and the step form, and the
     * sidebar's entries (literals of the layout).
     */
    public function testTheButtonsAndPagesAreNamedAsThePagesNameThem(): void
    {
        $guide = $this->flat(self::GUIDE);
        $names = [
            WorkflowStepFormView::ADD_HEADING,
            WorkflowStepFormView::EDIT_SUBMIT,
            WorkflowStepsTable::EDIT,
            WorkflowStepsTable::INSERT_BELOW,
            WorkflowStepsTable::MOVE_TO,
            WorkflowStepsTable::REMOVE,
            Texts::REVIEW_BUTTON,
            WorkflowTemplatePage::TEMPLATES_CRUMB,
            'Workflow Jobs',
        ];
        foreach ($names as $name) {
            $this->assertMentions('**' . $name . '**', $guide, 'workflows.md');
        }
        // The layout is code: read from the repository, whatever root() says.
        $layout = (string)file_get_contents(dirname(__DIR__, 3) . '/views/layouts/main.php');
        foreach (['>Workflow Templates</a>', '>Workflow Jobs</a>'] as $entry) {
            $this->assertStringContainsString($entry, $layout, 'The sidebar has the entry the guide names.');
        }
    }

    /**
     * The audit actions the guide names are the ones the step changes write.
     */
    public function testTheAuditActionsAreTheCodes(): void
    {
        $actions = [
            '## Building a workflow' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED,
            '## Moving steps' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED,
            '## Editing a step' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED,
            '## Removing a step' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED,
        ];
        foreach ($actions as $heading => $action) {
            $this->assertMentions('audited as `' . $action . '`', $this->flat(self::GUIDE, $heading), "workflows.md, {$heading},");
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function quoteProvider(): array
    {
        $quotes = array_merge(self::tableQuotes(), self::formQuotes(), self::runQuotes(), self::apiQuotes());
        $provided = [];
        foreach ($quotes as $quote) {
            $provided[$quote] = [$quote];
        }

        return $provided;
    }

    /**
     * Every text of the page, the run's banner and the API that the guide
     * quotes is the text of the code.
     *
     * @dataProvider quoteProvider
     */
    public function testTheGuideQuotesTheTextsAsTheCodeWordsThem(string $quote): void
    {
        $this->assertMentions('`' . $quote . '`', $this->flat(self::GUIDE), 'workflows.md');
    }

    /**
     * The states table: what each derived state reads, its stored status
     * and its waiting_for, as Display and the run states give them.
     */
    public function testTheStatesTableIsTheDerivationOfTheCode(): void
    {
        $rows = [
            Display::STATE_AWAITING_APPROVAL => WorkflowJob::STATUS_RUNNING,
            Display::STATE_PAUSED => WorkflowJob::STATUS_RUNNING,
            Display::STATE_QUEUED => WorkflowJob::STATUS_RUNNING,
            Display::STATE_RUNNING => WorkflowJob::STATUS_RUNNING,
            Display::STATE_CANCELED => WorkflowJobStep::STATUS_FAILED,
        ];
        $section = $this->flat(self::GUIDE, '## What a run waits for');
        foreach ($rows as $state => $stored) {
            $waitingFor = Display::waitingFor($state);
            $line = sprintf('| %s | `%s` | %s |', Display::label($state), $stored, $waitingFor === null ? 'none' : '`' . $waitingFor . '`');
            $this->assertMentions($line, $section, 'The states table of workflows.md');
        }
        $this->assertSame(
            [WorkflowCancellationService::STEP_RUN_CANCELED, WorkflowCancellationService::STEP_TEMPLATE_DELETED],
            WorkflowCancellationService::stepMessages(),
            'A step ended by a cancel or a template deletion reads Canceled.'
        );
    }

    /**
     * The curl examples of the API section call routes of config/web.php
     * with the verb of the route, and send bodies that the readers of the
     * step endpoints take; the filters are values the lists know.
     */
    public function testTheApiExamplesCallTheRoutesWithBodiesTheCodeTakes(): void
    {
        $calls = $this->apiCalls();
        $this->assertCount(9, $calls, 'Add, edit, three moves, remove, two lists and a job.');
        $rules = $this->apiRules();
        foreach ($calls as [$verb, $path, $body]) {
            $route = $this->routeOf($rules, $verb, (string)parse_url($path, PHP_URL_PATH));
            $this->assertNotNull($route, "No URL rule takes {$verb} {$path}.");
            $this->assertBodyIsTaken($route, $body, $path);
        }
        $this->assertContains('approval', Display::waitingForValues());
        $this->assertContains('pending', ApprovalRequest::statuses());
    }

    /**
     * The permissions of the API section's endpoints are those of the
     * controller (workflow-template.update for every step change).
     */
    public function testTheStepEndpointsNeedTheUpdatePermission(): void
    {
        $this->assertMentions(
            'Each needs `workflow-template.update` and operator access to the project of every job step',
            $this->flat(self::GUIDE, '### Steps'),
            'workflows.md'
        );
        $controller = new \ReflectionClass(\app\controllers\api\v1\WorkflowStepsController::class);
        $rules = $controller->getMethod('apiAccessRules');
        $instance = $controller->newInstanceWithoutConstructor();
        $this->assertSame(
            ['create' => 'workflow-template.update', 'update' => 'workflow-template.update', 'move' => 'workflow-template.update', 'delete' => 'workflow-template.update'],
            $rules->invoke($instance)
        );
    }

    /**
     * The guide says who a role rule counts as the rule's form does under
     * Role, quoting the form's hint (ApprovalRule::ROLE_HINT, which
     * ApprovalRuleFormRoleHintTest renders), and
     * WorkflowsGuidePermissionsDocsContractTest shows a rule counting so.
     *
     * Regression (wave 5, R12-2): the form said "All users with this role
     * can approve.", and the guide did not say that a role rule counts the
     * users assigned the role directly. Fails on the guide from before.
     */
    public function testRegressionTheGuideSaysWhoARoleRuleCountsAsTheFormDoes(): void
    {
        $hint = ApprovalRule::ROLE_HINT;
        $this->assertStringContainsString('the users assigned this role directly, not through another role, nor as the default role every signed-in user holds', $hint);
        $this->assertStringNotContainsString('All users with this role can approve.', $hint);
        $deciding = $this->flat(self::GUIDE, '## Deciding on an approval');
        $this->assertMentions(
            'for a role rule, the users assigned that role directly: not those who hold it through another role, as admins hold operator, nor every signed-in user for the default role `viewer`.',
            $deciding,
            'workflows.md, on deciding'
        );
        $this->assertMentions('The rule\'s form says so under Role: `' . $hint . '`', $deciding, 'workflows.md, on deciding');
    }

    /**
     * The guide says who a team rule counts, every member of the team, and
     * who of them can approve, quoting the form's hint under Team
     * (ApprovalRule::TEAM_HINT, which ApprovalRuleFormRoleHintTest renders;
     * WorkflowsGuidePermissionsDocsContractTest shows a rule counting so);
     * and that who may approve follows roles, team memberships and the
     * teams' projects, which whoever may change them changes without
     * approval-rule.update, a known limit of the release.
     *
     * Regression (wave 5c): the form said "All members of this team can
     * approve.", though members without approval.decide, members who may
     * not see the job and the launcher cannot, and the guide said nothing of
     * who changes the approvers. Fails on the guide from before.
     */
    public function testRegressionTheGuideSaysWhoATeamRuleCountsAndWhoChangesTheApprovers(): void
    {
        $deciding = $this->flat(self::GUIDE, '## Deciding on an approval');
        $this->assertMentions('A team rule counts every member of the team.', $deciding, 'workflows.md, on deciding');
        $this->assertMentions('and under Team: `' . ApprovalRule::TEAM_HINT . '`', $deciding, 'workflows.md, on deciding');
        $this->assertStringNotContainsString('All members of this team can approve.', ApprovalRule::TEAM_HINT);
        $expected = [
            'Who may approve so follows the users\' roles, their team memberships and the projects of their teams, which an approval rule does not lock: whoever may change these changes who may approve a request, without `approval-rule.update`.',
            'By default only admins assign roles, change what a role grants, and manage teams, their members and their projects; with LDAP, the directory groups of a user give their roles.',
            'This is a known limit of 2.11.0 (see [updating.md](updating.md#after-updating-from-2101-or-older-workflow-steps-and-waiting-runs)).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $deciding, 'workflows.md, on who changes the approvers');
        }
    }

    /**
     * The run's page follows the run as its script does: 3 seconds after a
     * change, up to MAX_INTERVAL apart while nothing changes, not in a hidden
     * tab, and it stops with the note of Texts when a poll is refused.
     */
    public function testTheRunPageFollowsTheRunAsItsScriptDoes(): void
    {
        $running = $this->flat(self::GUIDE, '## Running a workflow');
        $this->assertMentions(
            sprintf('It asks for news %d seconds after something changed, less and less often while nothing does, at most %d seconds apart, and not while its tab is hidden; shown again, it asks at once.', self::liveScriptNumber('INTERVAL') / 1000, self::liveScriptNumber('MAX_INTERVAL') / 1000),
            $running,
            'workflows.md, on the run page'
        );
        $this->assertMentions('the Live badge gives way to `' . Texts::LIVE_STOPPED_RUN . '`; the page of a job says `' . Texts::LIVE_STOPPED_JOB . '`', $running, 'workflows.md, on the run page');
        $this->assertStringNotContainsString('updates every few seconds', $running);
    }

    /**
     * The page of a job follows the job as its script does
     * (views/job/view.php): every INTERVAL while the job runs, less and less
     * often while it waits, up to MAX_INTERVAL, and not in a hidden tab
     * (JobViewStatusPollTest pins the script).
     */
    public function testTheJobPageFollowsTheJobAsItsScriptDoes(): void
    {
        $this->assertMentions(
            sprintf(
                'A job\'s page asks every %s seconds while the job runs; while the job waits for an approval, to be queued or for a runner, it asks less and less often while nothing changes, from %s up to %d seconds apart, and it does not ask while its tab is hidden either.',
                self::seconds(self::jobScriptNumber('INTERVAL')),
                self::seconds(self::jobScriptNumber('INTERVAL')),
                self::jobScriptNumber('MAX_INTERVAL') / 1000
            ),
            $this->flat(self::GUIDE, '## Running a workflow'),
            'workflows.md, on the job page'
        );
        $this->assertSame(['pending', 'queued', 'pending_approval'], \app\models\Job::waitingStatuses(), 'A job waits for an approval, to be queued or for a runner.');
    }

    /**
     * The step forms say what they warn about and show: Add Step warns of a
     * place where no success leads before an approval step is added there
     * (WorkflowStepFormView::THIS_APPROVAL_OFF_PATH, the warning of the
     * Position field while the type is Approval), and the page of a step
     * shows a variable mapping read-only only where the step has one
     * (WorkflowStepMapping::isNone()).
     */
    public function testTheStepFormsSayWhatTheyWarnAboutAndShow(): void
    {
        $this->assertMentions(
            'or, for an approval step, when no success would lead to the place: `' . WorkflowStepFormView::THIS_APPROVAL_OFF_PATH
                . '` The form shows it before the step is added, while the type is Approval.',
            $this->flat(self::GUIDE, '## Building a workflow'),
            'workflows.md, on Position'
        );
        $this->assertMentions(
            '- A step\'s variable mapping is shown read-only (`' . WorkflowStepFormView::EXTRA_VARS_READONLY
                . '`). A step without one shows none, also one whose mapping a version before 2.11.0 stored as `[]` or `{}`.',
            $this->flat(self::GUIDE, '## Editing a step'),
            'workflows.md, on the mapping'
        );
        foreach (['[]', '{}', null, ''] as $none) {
            $this->assertTrue(WorkflowStepMapping::isNone($none), 'no mapping: ' . var_export($none, true));
        }
        $this->assertFalse(WorkflowStepMapping::isNone('{"app_version": "build_version"}'));
    }

    /**
     * The API section says what a variable mapping may be, what approval
     * requests answer about their votes, and what a run's detail leaves
     * out; the moving and editing sections name the long table's Move to…
     * and what Steps that route here lists.
     *
     * Regression (wave 5, W5-API-6): the guide said "A run's detail is the
     * same for every caller", though a step's approval_request_id and
     * approval are null where the caller may not see the request.
     */
    public function testRegressionTheApiSectionSaysWhatADetailLeavesOutAndWhatRequestsAnswer(): void
    {
        $api = $this->flat(self::GUIDE, '## API');
        $expected = [
            'A step\'s variable mapping (`extra_vars_template`) is an object of extra var name to output variable name, such as `{"app_version": "build_version"}`; `null`, `{}` and `[]` mean none.',
            'An edit compares the mapping by its JSON value, so sending a step back as `GET` answers it changes nothing.',
            'Every approval request also answers `decisions`: every vote on it in the order cast, with `user_id`, `username`, `decision`, `comment` (`null` without one) and `created_at`.',
            '`' . sprintf(Texts::DECIDED_APPROVED_BY, 'alice', self::DECIDED) . '` and its `' . sprintf(Texts::DECIDED_COMMENT, 'Change window confirmed') . '` on the run page',
            '`decisions` is `null` for callers without `approval.view`.',
            'A run\'s detail says nothing about the caller\'s own relation to a request (may decide, launched the run, voted), and nothing about whether they may resume or cancel; the `approval_request_id` and `approval` of a step are `null` where the caller may not see the request.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $api, 'workflows.md, API');
        }
        $this->assertStringNotContainsString('the same for every caller', $this->flat(self::GUIDE));
        $this->assertMentions(
            sprintf('On a workflow of more than %d steps, **%s** opens the page of the step at its Position field instead', WorkflowStepsTable::MOVE_MENU_LIMIT, WorkflowStepsTable::MOVE_TO),
            $this->flat(self::GUIDE, '## Moving steps'),
            'workflows.md, on Move to…'
        );
        $this->assertMentions(
            'They are exactly the steps that keep it from being removed',
            $this->flat(self::GUIDE, '## Editing a step'),
            'workflows.md, on Steps that route here'
        );
    }

    /**
     * The number the poll script of a job's page (views/job/view.php, the
     * repository's) gives the variable $name, in milliseconds.
     */
    private static function jobScriptNumber(string $name): int
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/views/job/view.php');
        self::assertSame(1, preg_match('/\bvar ' . $name . ' = (\d+);/', $script, $match), "views/job/view.php sets {$name}.");

        return (int)$match[1];
    }

    /**
     * $milliseconds in seconds as the guide writes them: 2.5, 30.
     */
    private static function seconds(int $milliseconds): string
    {
        return rtrim(rtrim(number_format($milliseconds / 1000, 1, '.', ''), '0'), '.');
    }

    /**
     * The number the run page's script (web/js/workflow-run-live.js, the
     * repository's) gives the variable $name, in milliseconds.
     */
    private static function liveScriptNumber(string $name): int
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/web/js/workflow-run-live.js');
        self::assertSame(1, preg_match('/\bvar ' . $name . ' = (\d+);/', $script, $match), "workflow-run-live.js sets {$name}.");

        return (int)$match[1];
    }

    /**
     * Guard: every relative link of the docs (docs/*.md, README.md and
     * CLAUDE.md), with or without an anchor, leads to a file of the
     * repository; OperatorDocsContractTest checks the anchors.
     */
    public function testEveryRelativeLinkOfTheDocsLeadsToAFile(): void
    {
        $files = array_merge(
            array_map(static fn (string $path): string => 'docs/' . basename($path), glob($this->root() . '/docs/*.md') ?: []),
            ['README.md', 'CLAUDE.md']
        );
        $this->assertContains(self::GUIDE, $files);
        $checked = 0;
        foreach ($files as $file) {
            $text = (string)preg_replace('/^```.*?^```/ms', '', $this->read($file));
            preg_match_all('/\]\(([^)#\s]+)(?:#[^)\s]*)?\)/', $text, $links);
            foreach ($links[1] as $link) {
                if (preg_match('/^[a-z]+:/', $link) === 1) {
                    continue;
                }
                $checked++;
                $this->assertFileExists($this->root() . '/' . dirname($file) . '/' . $link, "{$file} links to {$link}.");
            }
        }
        $this->assertGreaterThan(10, $checked);
    }

    /**
     * @return list<string>
     */
    private static function tableQuotes(): array
    {
        return [
            WorkflowStepsTable::POSITION_TITLE,
            WorkflowStepsTable::START_BADGE,
            WorkflowStepsTable::START_TITLE,
            WorkflowStepsTable::UNREACHABLE_BADGE,
            WorkflowStepsTable::UNREACHABLE_TITLE,
            WorkflowStepsTable::GATED_BADGE,
            // 2.12.0: a gated job step is gated by its job template's rule or its inventory's.
            sprintf(WorkflowStepsTable::GATED_TITLE, 'deploy-prod'),
            sprintf(WorkflowStepsTable::GATED_INVENTORY_TITLE, 'deploy-prod'),
            WorkflowStepsTable::LEGEND,
            WorkflowStepsTable::SUCCESS_PATH_LOOP_NOTE,
            sprintf(WorkflowStepsTable::SUCCESS_PATH_CANNOT_RUN, WorkflowStepsTable::stepLabel(4, 'Verify')),
            WorkflowStepsTable::stepLabel(3, 'Deploy'),
            WorkflowStepsTable::stepReference(3, 'Deploy'),
            sprintf(WorkflowStepsTable::TARGET_TEMPLATE_GONE, 12),
            WorkflowStepsTable::TARGET_TEMPLATE_NONE,
            sprintf(WorkflowStepsTable::TARGET_RULE_GONE, 9),
            WorkflowStepsTable::TARGET_RULE_NONE,
            WorkflowStepsTable::TARGET_RULES_DIFFER,
            WorkflowStepsTable::TARGET_UNKNOWN_TYPE,
            sprintf(WorkflowStepsTable::ROUTE_TO, WorkflowStepsTable::stepLabel(5, 'Rollback')),
            sprintf(WorkflowStepsTable::ROUTE_TO_NEXT, WorkflowStepsTable::stepLabel(3, 'Deploy')),
            WorkflowStepsTable::NEXT_TITLE,
            sprintf(WorkflowStepsTable::ROUTE_TO_NEXT, WorkflowStepsTable::stepLabel(3, 'Deploy')) . WorkflowStepsTable::ALSO_AFTER_FAILURE,
            sprintf(WorkflowStepsTable::ROUTE_LOOP, WorkflowStepsTable::stepLabel(3, 'Deploy')),
            WorkflowStepsTable::LOOP_TITLE,
            WorkflowStepsTable::ROUTE_END_SUCCEEDS,
            WorkflowStepsTable::ROUTE_END_FAILS,
            WorkflowStepsTable::ROUTE_END_WITH_STEP,
            sprintf(WorkflowStepsTable::ROUTE_MISSING, 7),
            WorkflowStepsTable::MISSING_TITLE,
            WorkflowStepsTable::ROUTE_UNUSED,
            WorkflowStepsTable::ALWAYS_WINS_TITLE,
            WorkflowStepsTable::PAUSE_FAILURE_TITLE,
            WorkflowStepsTable::CANNOT_RUN_TITLE,
            sprintf(WorkflowStepsTable::ROUTE_TO_NEXT, WorkflowStepsTable::stepLabel(4, 'Verify')) . WorkflowStepsTable::ALSO_AFTER_FAILED_JOB
                . WorkflowStepsTable::PART_SEPARATOR . WorkflowStepsTable::ROUTE_END_FAILS . WorkflowStepsTable::AFTER_REFUSED_APPROVAL,
            WorkflowStepsTable::REFUSED_APPROVAL_TITLE,
            WorkflowStepsTable::APPROVAL_ALWAYS_NOTE,
            WorkflowStepsTable::PAUSE_ALWAYS_NOTE,
            WorkflowStepsTable::GATED_JOB_ALWAYS_NOTE,
            sprintf(WorkflowStepsTable::MOVE_UP_LABEL, WorkflowStepsTable::stepReference(3, 'Deploy')),
            sprintf(Texts::STEP_WAITING_MARKER, 1),
            WorkflowStepsTable::MOVE_TO_EDIT_PAGE_TITLE,
            sprintf(WorkflowStepsTable::WILL_SKIP_APPROVAL, 'Release approval'),
            sprintf(WorkflowStepChangeSummary::APPROVAL_OFF_SUCCESS_PATH, 'Release approval'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function formQuotes(): array
    {
        return [
            WorkflowTemplatePage::GATED_TEMPLATES_HINT,
            WorkflowStepFormView::FIRST_STEP_NOTE,
            WorkflowStepFormView::POSITION_START,
            sprintf(WorkflowStepFormView::NEXT_OPTION, 'Verify'),
            WorkflowStepFormView::NEXT_OPTION_NONE_SUCCEEDS,
            WorkflowStepFormView::SUCCESS_LABEL_PAUSE,
            WorkflowStepFormView::END_FAILS_OPTION,
            WorkflowStepFormView::FAILURE_DEFAULT_NOTE,
            WorkflowTemplateController::FLASH_STEP_NOT_ADDED,
            WorkflowTemplateController::FLASH_STEP_NOT_MOVED,
            WorkflowTemplateController::FLASH_STEP_NOT_SAVED,
            sprintf(WorkflowStepFormView::EDIT_HEADING, WorkflowStepsTable::stepReference(4, 'Verify')),
            sprintf(WorkflowStepFormView::POSITION_KEEP, 4),
            sprintf(WorkflowStepFormView::THIS_STEP_OPTION, WorkflowStepsTable::stepLabel(4, 'Verify')),
            WorkflowStepCommand::TYPE_LOCKED,
            WorkflowStepFormView::ROUTED_BY_TITLE,
            WorkflowStepFormView::ROUTED_BY_NONE,
            WorkflowStepFormView::EXTRA_VARS_READONLY,
            WorkflowStepFormView::TEMPLATE_GONE_HINT,
            WorkflowStepFormView::LEGACY_ALWAYS_NOTE,
            ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED,
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED,
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULES_DIFFER, SurveyDefinition::quote('Deploy')),
            sprintf(WorkflowStepFormView::PREVIEW_SKIPPED_TO, 'Build', 'Deploy'),
            sprintf(WorkflowStepFormView::PREVIEW_AFTER_BROKEN, 'Build'),
            sprintf(WorkflowStepFormView::PREVIEW_AFTER, 'Build'),
            WorkflowStepFormView::PREVIEW_FIRST,
            WorkflowStepFormView::THIS_APPROVAL_OFF_PATH,
            WorkflowStepFormView::ROUTED_BY_ALWAYS_WINS,
            WorkflowStepFormView::ROUTED_BY_PAUSE_FAILURE,
            WorkflowStepFormView::ROUTED_BY_CANNOT_RUN,
            WorkflowStepFormView::ROUTED_BY_UNKNOWN_TYPE,
        ];
    }

    /**
     * @return list<string>
     */
    private static function runQuotes(): array
    {
        return [
            sprintf(Texts::LAUNCHED, 'Release web'),
            sprintf(Texts::LAUNCHED_AWAITING_APPROVAL, 'Hotfix', 'Release approval', 18),
            sprintf(Texts::LAUNCHED_PAUSED, 'Hotfix', 'Wait'),
            PollPayload::ORDER_TITLE,
            TimeHelper::duration(45),
            TimeHelper::duration(75),
            TimeHelper::duration(7980),
            TimeHelper::duration(3 * 86400 + 4 * 3600),
            sprintf(Display::DURATION_RUNNING, ''),
            sprintf(Display::DURATION_WAITING, ''),
            TimeHelper::STAMP_TODAY,
            TimeHelper::STAMP_OTHER_DAY,
            sprintf(Texts::BANNER_APPROVAL_STEP, 'Release approval'),
            sprintf(Texts::BANNER_GATED_JOB, 34, 'Deploy'),
            sprintf(Texts::BANNER_PAUSED, 'Wait'),
            sprintf(Texts::BANNER_QUEUED, 34, 'Deploy'),
            sprintf(Texts::FACT_REQUEST, 18),
            sprintf(Texts::FACT_RULE, 'deploy-prod'),
            Texts::FACT_RULE_DELETED,
            sprintf(Texts::FACT_APPROVALS, 1, 2),
            sprintf(Texts::FACT_OPEN_APPROVERS, 2),
            sprintf(Texts::FACT_SINCE, self::TODAY),
            Texts::FACT_NEVER_EXPIRES,
            sprintf(Texts::FACT_EXPIRES, self::OTHER_DAY, Texts::THEN_REJECTED),
            sprintf(Texts::FACT_EXPIRED, self::OTHER_DAY, Texts::THEN_REJECTED),
            Texts::BANNER_REQUEST_MISSING,
            Texts::PERSONAL_MAY_DECIDE,
            Texts::REVIEW_BUTTON,
            Texts::PERSONAL_LAUNCHER,
            Texts::PERSONAL_VOTED,
            Texts::PERSONAL_NOT_APPROVER,
            Texts::PERSONAL_NOT_ELIGIBLE,
            Texts::WARNING_UNDECIDABLE,
            ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN,
            sprintf(Texts::DECIDED_APPROVED_BY, 'alice', self::DECIDED),
            sprintf(Texts::DECIDED_REJECTED_BY, 'bob', self::DECIDED),
            sprintf(Texts::DECIDED_TIMEOUT_APPROVED, self::DECIDED),
            sprintf(Texts::DECIDED_COMMENT, 'Change window confirmed'),
            sprintf(Texts::DECIDED_APPROVED, self::DECIDED),
            sprintf(Texts::PLACEHOLDER_NOTE, 'Release approval', 12),
            Texts::ACTIVE_RUNS_TITLE,
            sprintf(Texts::ACTIVE_RUN_AT, 'Release approval', self::TODAY),
            sprintf(Texts::ACTIVE_RUN_BY, 'alice'),
            sprintf(Texts::ACTIVE_RUNS_MORE, 3),
            Texts::ACTIVE_RUNS_ALL,
            SiteController::ALL_WORKFLOW_JOBS,
            ApprovalController::ALL_PENDING,
            sprintf(Texts::FOR_WORKFLOW_STEP, 'Release web', 'Release approval', 12),
            sprintf(Texts::FOR_JOB_TEMPLATE, 'Deploy web'),
            sprintf(Texts::RULE_OF_INVENTORY, 'deploy-prod', 'web-prod'),
            sprintf(Texts::JOB_APPROVAL_NOTE, 18),
            sprintf(Texts::JOB_WORKFLOW_ORIGIN, 12, 'Deploy'),
            sprintf(Texts::PLACEHOLDER_LABEL, 'Release approval', 12),
            Texts::LIVE_STOPPED_RUN,
            Texts::LIVE_STOPPED_JOB,
        ];
    }

    /**
     * @return list<string>
     */
    private static function apiQuotes(): array
    {
        return [
            sprintf(\app\models\WorkflowJobSearch::INVALID_FILTER, 'waiting_for', implode(', ', Display::waitingForValues())),
            sprintf(\app\models\ApprovalRequestSearch::INVALID_STATUS, implode(', ', ApprovalRequest::statuses())),
            WorkflowCancellationService::STEP_RUN_CANCELED,
            WorkflowCancellationService::STEP_TEMPLATE_DELETED,
            WorkflowStepMapping::INVALID,
        ];
    }

    /**
     * The example workflow "Release web", in run order.
     *
     * @return list<WorkflowStep>
     */
    private static function exampleSteps(): array
    {
        return [
            self::job(41, 'Build', ['failure' => 0]),
            self::approval(42, 'Release approval'),
            self::job(43, 'Deploy', ['failure' => 45]),
            self::job(44, 'Verify', ['success' => 0, 'failure' => 43]),
            self::job(45, 'Rollback'),
        ];
    }

    /**
     * The summary of a change of $step, as the flash and the API's change
     * give it.
     *
     * @param list<WorkflowStep> $before
     * @param 'added'|'moved'|'removed'|'unchanged'|'updated' $kind
     * @param list<WorkflowStep> $after
     * @param array<string, array{from: mixed, to: mixed}> $changes
     * @return array{message: string, warnings: list<string>}
     */
    private static function summary(string $kind, WorkflowStep $step, int $from, int $to, array $before, array $after, array $changes = []): array
    {
        $change = new WorkflowStepChange($kind, $step, $from, $to, $before, $after, $changes, WorkflowStepWriteService::SOURCE_WEB);

        return WorkflowStepChangeSummary::of($change)->toArray();
    }

    /**
     * The calls of the curl examples of the API section: verb, URL path (and
     * query) and the JSON body (null without one).
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    private function apiCalls(): array
    {
        preg_match_all('/^```bash\R(.*?)^```/ms', $this->section(self::GUIDE, '## API'), $blocks);
        $calls = [];
        foreach ($blocks[1] as $block) {
            foreach (preg_split('/\R\R/', trim((string)preg_replace('/\\\\\R\s*/', ' ', $block))) ?: [] as $command) {
                $this->assertSame(1, preg_match('/^curl (.*)$/s', trim($command), $curl), "Not a curl call: {$command}");
                $options = $curl[1] ?? '';
                $this->assertStringContainsString('-H "Authorization: Bearer $TOKEN"', $options, 'Every example sends the token placeholder, never a token.');
                preg_match('/-X ([A-Z]+)/', $options, $verb);
                preg_match("/-d '([^']*)'/", $options, $data);
                $this->assertSame(1, preg_match('#"?https://ansilume\.example\.com(/api/v1/[^"\s]+)"?#', $options, $url));
                $body = isset($data[1]) ? json_decode($data[1], true, 512, JSON_THROW_ON_ERROR) : null;
                $this->assertTrue($body === null || is_array($body));
                $calls[] = [$verb[1] ?? 'GET', $url[1] ?? '', $body];
            }
        }

        return $calls;
    }

    /**
     * The API URL rules of config/web.php: pattern, route and verb.
     *
     * @return list<array{pattern: string, route: string, verb: string}>
     */
    private function apiRules(): array
    {
        $config = require dirname(__DIR__, 3) . '/config/web.php';
        $this->assertIsArray($config);
        $rules = [];
        foreach ($config['components']['urlManager']['rules'] ?? [] as $rule) {
            if (is_array($rule) && str_starts_with((string)($rule['pattern'] ?? ''), 'api/v1/')) {
                $rules[] = ['pattern' => (string)$rule['pattern'], 'route' => (string)$rule['route'], 'verb' => (string)($rule['verb'] ?? 'GET')];
            }
        }
        $this->assertNotEmpty($rules);

        return $rules;
    }

    /**
     * The route of the first rule that takes $verb on $path.
     *
     * @param list<array{pattern: string, route: string, verb: string}> $rules
     */
    private function routeOf(array $rules, string $verb, string $path): ?string
    {
        foreach ($rules as $rule) {
            $regex = '#^/' . preg_replace('/<\w+:([^>]+)>/', '($1)', $rule['pattern']) . '$#';
            if ($rule['verb'] === $verb && preg_match($regex, $path) === 1) {
                return $rule['route'];
            }
        }

        return null;
    }

    /**
     * The reader of $route takes $body, as the step endpoints read it.
     *
     * @param array<string, mixed>|null $body
     */
    private function assertBodyIsTaken(string $route, ?array $body, string $path): void
    {
        $taken = match ($route) {
            'api/v1/workflow-steps/create' => WorkflowStepCommand::fromApi((array)$body, true),
            'api/v1/workflow-steps/update' => WorkflowStepCommand::fromApi((array)$body, false),
            'api/v1/workflow-steps/move' => WorkflowStepPosition::fromApiMove((array)$body),
            default => $body === null ? true : 'a body for ' . $route,
        };
        $this->assertFalse(is_string($taken), "The body of {$path} is refused: " . (is_string($taken) ? $taken : ''));
        $this->assertNotNull($taken, "The body of {$path} names no move.");
        if ($route === 'api/v1/workflow-steps/create') {
            $this->assertInstanceOf(WorkflowStepCommand::class, $taken);
            $this->assertInstanceOf(WorkflowStepPosition::class, $taken->position(), 'The add names its place.');
            $this->assertSame('Smoke test', $taken->value('name'));
        }
    }
}
