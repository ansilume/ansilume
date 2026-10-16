<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\AuthManager;
use app\components\GitSyncDiagnostics;
use app\components\NotificationConfigSecrets;
use app\components\SurveyDefinition;
use app\components\SurveySecrets;
use app\components\WorkflowStepChange;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepMapping;
use app\components\WorkflowStepPosition;
use app\controllers\api\v1\UsersController;
use app\controllers\ApprovalController;
use app\controllers\BaseController;
use app\controllers\ProjectController;
use app\controllers\SiteController;
use app\controllers\UserController;
use app\controllers\WorkflowTemplateController;
use app\helpers\TimeHelper;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\models\UserForm;
use app\models\Webhook;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\notification\WebhookChannel;
use app\services\ProjectService;
use app\services\ProjectSyncProcessRunner;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\services\UserPrivileges;
use app\services\runstate\Display;
use app\services\runstate\PollPayload;
use app\services\runstate\Texts;
use app\services\WorkflowCancellationService;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepFormView;
use app\services\WorkflowStepReorderService;
use app\services\WorkflowStepRouter;
use app\services\WorkflowStepsTable;
use app\services\WorkflowStepWriteService;
use app\tests\unit\services\WorkflowStepModels;
use PHPUnit\Framework\TestCase;

/**
 * What the docs say about workflow steps and waiting runs in 2.11.0 is what
 * the code does: docs/updating.md's section for the update from 2.10.1, the
 * troubleshooting entries on stuck runs, the order of steps, refused step
 * changes and refused votes, README.md and CLAUDE.md quote the texts of the
 * code, map the labels of 2.10.1 to the labels of today, and say what the
 * web and the API do differently.
 *
 * Regression (2.11.0): the docs told users to remove a step and add it
 * again to change its branches, which gives the step a new ID and is
 * refused while other steps route to it, quoted labels the steps table no
 * longer shows, and said nothing of signed-out visitors reading list pages
 * or of saved users who became viewers. These tests fail on the docs from
 * before.
 *
 * Regressions (wave 5 of 2.11.0), each failing on the section from before:
 * the item on signed-out visitors said no page showed a secret and nothing
 * was to be done, though notification templates showed their channel
 * secrets; the rollback named no image tag that exists and claimed nothing
 * was left to undo; and a run's detail was called the same for every
 * caller. The section also says what every signed-in user read up to
 * 2.10.1, who may give roles and the superadmin flag, and the fixes and
 * API changes of wave 5, in the words of the code.
 *
 * Regressions (wave 5c of 2.11.0), each failing on the section from before:
 * the known limits said the credentials of repository URLs still reached
 * the live sync output and the queue-worker's log, which the release now
 * redacts at the source; and nothing said that whoever held user.update
 * could take over a superadmin's account. The section also says who may
 * approve follows roles and teams, and lists the fixes of wave 5c in the
 * words of the code.
 *
 * The section's examples use the workflow Build, Release approval, Deploy
 * (Deploy's On Failure ends the run), as the OpenAPI spec does.
 */
class WorkflowStepsUpdateDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use ReadsTheSyncCommands;
    use ReadsTheUpdateSection;
    use WorkflowStepModels;

    /** The section of docs/updating.md; WorkflowStepTieQueryDocsContractTest runs its query. */
    public const UPDATE_SECTION = '## After updating from 2.10.1 or older: workflow steps and waiting runs';

    /** The anchor GitHub gives the section. */
    public const SECTION_ANCHOR = 'after-updating-from-2101-or-older-workflow-steps-and-waiting-runs';

    private const UPDATING = 'docs/updating.md';

    private const TROUBLESHOOTING = 'docs/troubleshooting.md';

    private const STUCK_ENTRY = '## A workflow run seems stuck';

    private const ORDER_ENTRY = '## Workflow steps run in the wrong order';

    private const REFUSED_ENTRY = '## Changing workflow steps is refused';

    private const VOTE_ENTRY = '## Approve or Reject is refused, or missing';

    private const SECRETS_ENTRY = '## Saving a notification template or a webhook: a secret has no stored value';

    /**
     * The labels of 2.10.1 and the labels that replace them, as the section's
     * table maps them; the new ones are built from the constants of the
     * steps table.
     *
     * @return array<string, string>
     */
    public static function labelTable(): array
    {
        $deploy = WorkflowStepsTable::stepLabel(3, 'Deploy');
        $next = sprintf(WorkflowStepsTable::ROUTE_TO_NEXT, $deploy);

        return [
            '`#20 Test` in the branch lists of the form' => '`' . WorkflowStepsTable::stepLabel(2, 'Test') . '`',
            '`→ next step`' => '`' . $next . '`; after the last step `' . WorkflowStepsTable::ROUTE_END_SUCCEEDS
                . '` or `' . WorkflowStepsTable::ROUTE_END_FAILS . '`',
            '`→ next step` as a job step\'s On Failure' => '`' . $next . WorkflowStepsTable::ALSO_AFTER_FAILURE . '`',
            '`end workflow`' => '`' . WorkflowStepsTable::ROUTE_END_SUCCEEDS . '` after a success, `' . WorkflowStepsTable::ROUTE_END_FAILS
                . '` after a failure, `' . WorkflowStepsTable::ROUTE_END_WITH_STEP . '` as Always',
            '`end workflow (failed)`' => '`' . WorkflowStepsTable::ROUTE_END_FAILS . '`',
            '`→ next step; end workflow (failed) after a refused approval`' => '`' . $next . WorkflowStepsTable::ALSO_AFTER_FAILED_JOB
                . WorkflowStepsTable::PART_SEPARATOR . WorkflowStepsTable::ROUTE_END_FAILS . WorkflowStepsTable::AFTER_REFUSED_APPROVAL . '`',
            '`#7`, a branch to a removed step' => '`' . sprintf(WorkflowStepsTable::ROUTE_MISSING, 7) . '`',
            'a branch to the step itself or an earlier one' => '`' . sprintf(WorkflowStepsTable::ROUTE_LOOP, WorkflowStepsTable::stepLabel(1, 'Build')) . '`',
            'On Success and On Failure of a step with an Always branch' => '`' . WorkflowStepsTable::ROUTE_UNUSED . '`',
            'On Failure of a pause step' => '`' . WorkflowStepsTable::ROUTE_UNUSED . '`',
        ];
    }

    /**
     * The section follows the newest one, of the update that leaves runner
     * management to admins.
     */
    public function testTheSectionFollowsTheNewest(): void
    {
        preg_match_all('/^## After updating from .*$/m', $this->read(self::UPDATING), $headings);
        $this->assertSame(RunnerManagementDocsContractTest::UPDATE_SECTION, $headings[0][0] ?? null, 'The newest section comes first.');
        $this->assertSame(self::UPDATE_SECTION, $headings[0][1] ?? null, 'This section follows.');
        $this->assertSame(
            self::SECTION_ANCHOR,
            str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 -]/', '', strtolower(substr(self::UPDATE_SECTION, 3)))),
            'The anchor GitHub gives the heading.'
        );
    }

    public function testTheChecklistStartsWithTheQuery(): void
    {
        $section = $this->section(self::UPDATING, self::UPDATE_SECTION);
        $this->assertSame(1, preg_match('/^1\. (.*?)\R(?:\S|\R)/ms', $section, $first));
        $this->assertStringStartsWith(
            'Run the read-only query under **Steps that share a step order**, before or after the update',
            (string)preg_replace('/\s+/', ' ', $first[1] ?? '')
        );
    }

    /**
     * Every "(see **...**)" of the section leads to a paragraph of it.
     */
    public function testEveryPointerOfTheSectionLeadsToOneOfItsParagraphs(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        preg_match_all('/see \*\*([^*]+)\*\*/', $section, $pointers);
        $this->assertGreaterThan(5, count(array_unique($pointers[1])));
        foreach (array_unique($pointers[1]) as $title) {
            $this->assertTrue(
                str_contains($section, '**' . $title . '.**') || str_contains($section, '**' . $title . ':**'),
                "updating.md points to **{$title}**, which the section does not have."
            );
        }
    }

    /**
     * The table maps each label of 2.10.1 to the label the steps table shows
     * now, and the code shows none of the old ones any more.
     */
    public function testTheTableMapsTheLabelsOf2101ToTheLabelsOfToday(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        foreach (self::labelTable() as $before => $now) {
            $this->assertMentions('| ' . $before . ' | ' . $now . ' |', $section, 'The label table of updating.md');
        }
        $old = ['→ next step', 'end workflow', '— next step (default) —', '— end workflow —', '— use success/failure above —', 'Next step →'];
        foreach (['services', 'views', 'web/js'] as $directory) {
            foreach ($this->codeFiles($directory) as $file) {
                $code = (string)file_get_contents($file);
                foreach ($old as $label) {
                    $this->assertStringNotContainsString($label, $code, "{$file} still has the label of 2.10.1 {$label}");
                }
            }
        }
        $form = [
            '`— next step (default) —` reads `' . sprintf(WorkflowStepFormView::NEXT_OPTION, 'Deploy') . '` (`' . WorkflowStepFormView::NEXT_OPTION_NONE_SUCCEEDS . '`',
            '`— end workflow —` reads `' . WorkflowStepFormView::END_SUCCEEDS_OPTION . '` or `' . WorkflowStepFormView::END_FAILS_OPTION . '`',
            '`— end workflow as failed (default) —` reads `' . WorkflowStepFormView::END_FAILS_OPTION . '`',
            '`— use success/failure above —` reads `' . WorkflowStepFormView::ALWAYS_NONE_OPTION . '`',
            '`Next step →` reads `' . WorkflowStepFormView::SUCCESS_LABEL_PAUSE . '`',
        ];
        foreach ($form as $mapping) {
            $this->assertMentions($mapping, $section, 'updating.md, on the choices of the form');
        }
    }

    /**
     * The examples of the section are what the presenters and the summary
     * of a change make of the example workflow.
     */
    public function testTheExamplesAreWhatThePageShows(): void
    {
        $build = self::job(41, 'Build');
        $approval = self::approval(42, 'Release approval');
        $deploy = self::job(43, 'Deploy', ['failure' => 0]);
        $steps = [$build, $approval, $deploy];
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        $path = (new WorkflowStepsTable(WorkflowRouteMap::fromModels($steps), $steps, false, []))->successPath();
        $this->assertNotNull($path);
        $this->assertMentions('`' . WorkflowStepsTable::SUCCESS_PATH_LABEL . ' ' . $path['text'] . '`', $section, 'updating.md');
        $moved = self::summary(WorkflowStepChange::MOVED, $deploy, 3, 1, $steps, [$deploy, $build, $approval]);
        $this->assertMentions('`' . $moved['message'] . '`', $section, 'updating.md, on moves');
        $this->assertMentions('`' . $moved['warnings'][0] . '` and `' . $moved['warnings'][1] . '`', $section, 'updating.md, on moves');
        $added = self::summary(WorkflowStepChange::ADDED, $deploy, 0, 3, [$build, $approval], $steps);
        $removed = self::summary(WorkflowStepChange::REMOVED, $deploy, 3, 0, $steps, [$build, $approval]);
        $this->assertMentions('`Step "Deploy" added.` reads like `' . $added['message'] . '`', $section, 'updating.md, on renamed flashes');
        $this->assertMentions(
            '`Step "Deploy" moved up.` like `' . sprintf(WorkflowStepChangeSummary::MOVED, 'Deploy', 2, 3, sprintf(WorkflowStepChangeSummary::AFTER, 'Build')) . '`',
            $section,
            'updating.md, on renamed flashes'
        );
        $this->assertMentions('`Step removed.` like `' . $removed['message'] . '`', $section, 'updating.md, on renamed flashes');

        $form = WorkflowStepFormView::forAdd(WorkflowRouteMap::fromModels([$build, self::job(2, 'Test'), $deploy]), null)->position(new WorkflowStep());
        $this->assertNotNull($form);
        $this->assertMentions('`' . $form['items']['end'] . '`, the default, `' . $form['items']['start'] . '`', $section, 'updating.md, on Position');
        $this->assertMentions('or `' . $form['items']['after:2'] . '`', $section, 'updating.md, on Position');
        $positions = $this->item('**Steps have positions.**');
        $this->assertMentions('`' . sprintf(WorkflowStepFormView::PREVIEW_SKIPPED_TO, 'Build', 'Deploy') . '`', $positions, 'updating.md, on Position');
        $this->assertMentions(
            sprintf('On a workflow of more than %d steps, **%s** opens the page of the step at its Position field instead.', WorkflowStepsTable::MOVE_MENU_LIMIT, WorkflowStepsTable::MOVE_TO),
            $positions,
            'updating.md, on Move to…'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function quoteProvider(): array
    {
        $quotes = [
            WorkflowStepsTable::START_BADGE,
            WorkflowStepsTable::GATED_BADGE,
            WorkflowStepsTable::UNREACHABLE_BADGE,
            WorkflowStepsTable::LEGEND,
            WorkflowStepsTable::stepLabel(3, 'Deploy'),
            WorkflowStepsTable::stepReference(3, 'Deploy'),
            sprintf(WorkflowStepFormView::EDIT_HEADING, WorkflowStepsTable::stepReference(2, 'Test')),
            WorkflowStepCommand::TYPE_LOCKED,
            WorkflowStepFormView::ROUTED_BY_TITLE,
            WorkflowTemplateController::FLASH_STEP_NOT_SAVED,
            sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Test'),
            WorkflowStepFormView::END_FAILS_OPTION,
            WorkflowStepFormView::FAILURE_DEFAULT_NOTE,
            Texts::PERSONAL_LAUNCHER,
            Texts::REVIEW_BUTTON,
            Texts::WARNING_UNDECIDABLE,
            Texts::ACTIVE_RUNS_TITLE,
            sprintf(Texts::LAUNCHED_AWAITING_APPROVAL, 'Hotfix', 'Release approval', 18),
            sprintf(Texts::FOR_WORKFLOW_STEP, 'Release web', 'Release approval', 12),
            sprintf(Texts::FOR_JOB_TEMPLATE, 'Deploy web'),
            ApprovalController::ALL_PENDING,
            ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN,
            ApprovalDecisionRefusedException::LAUNCHER_REFUSED,
            sprintf(Texts::JOB_APPROVAL_NOTE, 18),
            ApprovalController::LIST_TITLE,
            SiteController::ALL_WORKFLOW_JOBS,
            PollPayload::NO_RUNS,
            WorkflowTemplateController::FLASH_STEP_NOT_MOVED . WorkflowStepPosition::DIRECTION_INVALID,
            sprintf(WorkflowStepsTable::REMOVE_CONFIRM, WorkflowStepsTable::stepReference(3, 'Deploy')),
            sprintf(WorkflowStepRouter::ROUTE_TARGET_MISSING, 7),
            sprintf(WorkflowStepChangeGuard::STEP_ROUTED_TO, SurveyDefinition::quote('Build'), sprintf(WorkflowStepChangeGuard::ROUTING_STEP_LABEL, 3, SurveyDefinition::quote('Retry'))),
            sprintf(WorkflowStepsTable::MOVE_UP_LABEL, WorkflowStepsTable::stepReference(3, 'Deploy')),
            TimeHelper::duration(45),
            TimeHelper::duration(75),
            TimeHelper::duration(7980),
            TimeHelper::duration(3 * 86400 + 4 * 3600),
            sprintf(\app\models\ApprovalRequestSearch::INVALID_STATUS, implode(', ', ApprovalRequest::statuses())),
            WorkflowCancellationService::STEP_RUN_CANCELED,
            WorkflowCancellationService::STEP_TEMPLATE_DELETED,
            AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED,
            WorkflowStepsTable::UNREACHABLE_TITLE,
            sprintf(WorkflowStepsTable::WILL_SKIP_APPROVAL, 'Release approval'),
            sprintf(WorkflowStepChangeSummary::APPROVAL_OFF_SUCCESS_PATH, 'Release approval'),
            WorkflowStepFormView::ROUTED_BY_ALWAYS_WINS,
            WorkflowStepMapping::INVALID,
            Texts::LIVE_STOPPED_RUN,
            Texts::LIVE_STOPPED_JOB,
            RoleGrantPolicy::SUPERADMIN_REFUSED,
            sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin'),
            Webhook::SECRET_NOT_STORED,
            Webhook::SECRET_HINT,
            UserChangePolicy::CHANGE_REFUSED,
            ProjectController::SYNC_LIVE_STOPPED,
            WorkflowStepFormView::THIS_APPROVAL_OFF_PATH,
            sprintf(WebhookChannel::SENT, 12, 204),
        ];
        $provided = [];
        foreach ($quotes as $quote) {
            $provided[$quote] = [$quote];
        }

        return $provided;
    }

    /**
     * @dataProvider quoteProvider
     */
    public function testTheSectionQuotesTheTextsAsTheCodeWordsThem(string $quote): void
    {
        $this->assertMentions('`' . $quote . '`', $this->flat(self::UPDATING, self::UPDATE_SECTION), 'updating.md');
    }

    /**
     * The states and the renamed labels the section names are what the pages
     * show: the labels of the derived states, and the literals of the
     * layout and the run page.
     */
    public function testTheStatesAndTheRenamedLabelsAreThePages(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $states = [Display::STATE_AWAITING_APPROVAL, Display::STATE_PAUSED, Display::STATE_QUEUED];
        $this->assertMentions(
            '`' . implode('`, `', array_map([Display::class, 'label'], array_slice($states, 0, 2))) . '` or `' . Display::label($states[2]) . '`',
            $section,
            'updating.md, on the states'
        );
        $this->assertMentions(
            'reads `' . Display::label(Display::STATE_CANCELED) . '` instead of `' . Display::label(Display::STATE_FAILED) . '`',
            $section,
            'updating.md'
        );
        // The views are code: read from the repository, whatever root() says.
        $root = dirname(__DIR__, 3);
        $this->assertStringContainsString('>Workflow Jobs</a>', (string)file_get_contents($root . '/views/layouts/main.php'));
        $this->assertStringContainsString('<th>Job / Request</th>', (string)file_get_contents($root . '/views/workflow-job/view.php'));
        $this->assertMentions('The sidebar\'s **Executions** is **Workflow Jobs**', $section, 'updating.md');
        $this->assertMentions('column is `Job / Request`', $section, 'updating.md');
    }

    /**
     * The changes of the web that show no message: the step actions need
     * workflow-template.update, the Add Step form reads neither a step order
     * nor a variable mapping, and a step type sent as JSON true is refused as
     * invalid.
     */
    public function testTheSilentChangesOfTheWebAreTheCodes(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $controller = new \ReflectionClass(WorkflowTemplateController::class);
        $rules = $controller->getMethod('accessRules')->invoke($controller->newInstanceWithoutConstructor());
        $this->assertIsArray($rules);
        foreach (['add-step', 'edit-step', 'move-step', 'remove-step'] as $action) {
            $roles = [];
            foreach ($rules as $rule) {
                if (in_array($action, (array)($rule['actions'] ?? []), true)) {
                    $roles = array_merge($roles, (array)$rule['roles']);
                }
            }
            $this->assertSame(['workflow-template.update'], $roles, "{$action} needs workflow-template.update only.");
        }
        $this->assertMentions('needs `workflow-template.update`, in the web UI and in the API', $section, 'updating.md');

        $command = WorkflowStepCommand::fromForm(
            ['name' => 'Test', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => '5', 'step_order' => '1', 'extra_vars_template' => '{"a":"b"}'],
            'end',
            false
        );
        $this->assertInstanceOf(WorkflowStepCommand::class, $command);
        $this->assertFalse($command->has('step_order'), 'The form reads no step order.');
        $this->assertFalse($command->has('extra_vars_template'), 'The form reads no variable mapping.');
        $this->assertMentions('A Step Order sent with the Add Step form is ignored', $section, 'updating.md');
        $this->assertMentions('A variable mapping (`extra_vars_template`) sent with the form is ignored too', $section, 'updating.md');

        $step = new WorkflowStep();
        $step->step_type = '1';
        $step->validate(['step_type']);
        $refusal = WorkflowTemplateController::FLASH_STEP_NOT_ADDED . $step->getFirstError('step_type');
        $this->assertMentions('A step type sent as JSON `true` answers `' . $refusal . '`', $section, 'updating.md');
    }

    /**
     * The security item on signed-out visitors names every page such a
     * visitor could open up to 2.10.1 and what it showed, the channel
     * secrets of notification templates among them, and tells an instance
     * that was reachable from untrusted networks to rotate them, as step 7
     * of the checklist does; the API was not affected, and the code applies
     * the default role to signed-in users only. It shows no way to read the
     * pages.
     *
     * Regression (wave 5, R-SEC-1/W5-API-1): the item said the pages showed
     * "never a secret value" and "Nothing needs to be done after the
     * update.", while a notification template's page showed its channel
     * secrets to signed-out visitors. Fails on the item from before.
     */
    public function testRegressionTheSecurityItemNamesTheChannelSecretsAndTheirRotation(): void
    {
        $item = $this->item('**Security: signed-out visitors.**');
        $expected = [
            'could open the pages of that role that team scoping does not filter: the lists and the single pages of credentials, runner groups, approval rules and notification templates, and the analytics.',
            'on a template\'s page its channel configuration as stored, with its secrets:',
            'and the recipients of an email channel',
            'on a credential\'s page the SSH public key with its algorithm and size, the environment variable of a token and whether a secret is stored, never the secret itself',
            'on a group\'s page its runners with their status, version, transport, last contact and address',
            'on a rule\'s page its approvers by role, team or user names',
            'the analytics: the names of all users and runners, without counts',
            'The API was not affected',
            'the default role applies to signed-in users only',
            'An instance that was reachable from untrusted networks rotates the secrets of its notification channels (step 7 above), as anyone could read them there',
        ];
        foreach (array_merge($expected, $this->channelSecretsInWords()) as $phrase) {
            $this->assertMentions($phrase, $item, 'The security item of updating.md');
        }
        foreach (['never a secret value', 'Nothing needs to be done after the update.'] as $old) {
            $this->assertStringNotContainsString($old, $item, 'The security item of updating.md no longer says: ' . $old);
        }
        $this->assertStringNotContainsString('curl', $item, 'The item shows no way to read the pages.');
        $this->assertMentions(
            '7. Rotate the secrets of your notification channels, and the credentials in repository URLs, if the instance was reachable from untrusted networks before the update',
            $this->flat(self::UPDATING, self::UPDATE_SECTION),
            'The checklist of updating.md'
        );
        $config = require dirname(__DIR__, 3) . '/config/web.php';
        $this->assertIsArray($config);
        $this->assertSame(AuthManager::class, $config['components']['authManager']['class'] ?? null);
        $this->assertSame(['viewer'], $config['components']['authManager']['defaultRoles'] ?? null);
        $this->assertFalse((new AuthManager())->checkAccess(null, 'notification-template.view'), 'A signed-out visitor holds nothing.');
    }

    /**
     * The item on secrets says what every signed-in user read up to 2.10.1
     * (the channel secrets of every notification template, the credentials
     * of repository URLs, the webhook's signing secret in its form), what
     * the pages and the API show now, in the words of the code, and that
     * the secrets are still stored as entered; step 7 rotates them.
     */
    public function testTheSecretsItemSaysWhatEverySignedInUserReadAndWhatTheyReadNow(): void
    {
        $item = $this->item('**Security: secrets on pages and in the API.**');
        $placeholder = SurveySecrets::PLACEHOLDER;
        $expected = [
            'Up to 2.10.1, every signed-in user, a viewer of any team too, read the channel secrets of every notification template on its page and through `GET /api/v1/notification-templates` and `GET /api/v1/notification-templates/{id}`',
            'Everyone who could see a project read the credentials in its repository URL (`https://user:token@host/...`) on its page, in its sync output and through the API',
            'the edit form of an outbound webhook carried its signing secret in the page source',
            'the API show every channel secret as `' . $placeholder . '`, to admins too',
            '`' . sprintf(NotificationConfigSecrets::PAGE_NOTE, $placeholder) . '`',
            '`' . sprintf(NotificationConfigSecrets::FORM_HINT, $placeholder) . '`',
            'Saving the form, or a `PUT`, with `' . $placeholder . '` keeps the stored value, except where nothing is stored, after a change of the channel, and for a header once the URL changed',
            '`' . GitSyncDiagnostics::redactUrl('https://deploy:token@git.example.com/org/repo.git') . '`, to everyone; the API does so for callers who may not update the project.',
            '`' . Webhook::SECRET_HINT . '`',
            'Where users who should not hold these secrets had accounts, rotate them (step 7 above).',
            'The channel secrets and the webhook signing secret are still stored as entered, without encryption',
        ];
        foreach (array_merge($expected, $this->channelSecretsInWords()) as $phrase) {
            $this->assertMentions($phrase, $item, 'The secrets item of updating.md');
        }
        $this->assertTrue($this->checklist(7) !== '', 'The checklist has step 7.');
        $this->assertMentions('signed-out visitors** and **Security: secrets on pages and in the API**)', $this->checklist(7), 'Step 7 of the checklist');
    }

    /**
     * The item on roles and the superadmin flag says who could give them up
     * to 2.10.1, what refuses them now in the words of RoleGrantPolicy, what
     * the audit records, and gives the read-only query that lists the
     * superadmins and admins (SuperadminQueryDocsContractTest runs it); step
     * 8 of the checklist points to it.
     */
    public function testTheRolesAndSuperadminItemQuotesTheRefusalsAndTheQuery(): void
    {
        $item = $this->item('**Security: who may give roles and the superadmin flag.**');
        $expected = [
            'Up to 2.10.1, whoever held `user.update` or `user.create` could make any user, themselves included, an admin or a superadmin.',
            'The Edit User form hid the Superadmin field from users who are no superadmin but took it when it was sent, and `POST` and `PUT /api/v1/users` set both.',
            'The audit entries `' . AuditLog::ACTION_USER_CREATED . '` and `' . AuditLog::ACTION_USER_UPDATED . '` recorded neither.',
            'the API answers `403` `' . RoleGrantPolicy::SUPERADMIN_REFUSED . '`; a body that repeats the current value is accepted.',
            'A role is assigned only by a superadmin, or by a user who holds every permission the role grants, through its child roles and the default role too.',
            '`' . sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin') . '`, as an error of the field and as a `403` of the API, and nothing is written.',
            'record `roles` and `is_superadmin` as `{from, to}` when they change',
            '-- Superadmins and admins, to check after the update',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The roles and superadmin item of updating.md');
        }
        $this->assertMentions(
            '8. Check who is a superadmin or an admin with the read-only query under **Security: who may give roles and the superadmin flag**',
            $this->flat(self::UPDATING, self::UPDATE_SECTION),
            'The checklist of updating.md'
        );
    }

    /**
     * The item on the roles of edited users says what saving a user did up
     * to 2.10.1, what the pages and the API read now, and that a user with
     * several assigned roles keeps them all when the form is saved with the
     * role it shows (UserForm::SEVERAL_ROLES_HINT) or the API gets one of
     * them; the checklist asks to check the roles, as the audit entries of
     * saves made with 2.10.1 or older name no role, while those from 2.11.0
     * on record roles and is_superadmin (UserControllerActionTest and
     * UsersControllerTest pin their fields); the API contract change names
     * the role.
     */
    public function testTheRolesItemSaysWhatSavingAUserDidAndWhatToCheck(): void
    {
        $item = $this->item('**Roles of edited users.**');
        $expected = [
            'Every signed-in user holds the role `viewer` by default, and up to 2.10.1',
            'The Edit User form preselected Viewer for every user',
            'even unchanged, for example to change their email, made them a viewer',
            'The users list and the user page showed `viewer` beside every user\'s role',
            'said `viewer` for every user, so a client that sent it back with `PUT /api/v1/users/{id}` made the user a viewer too',
            'read the roles assigned to the user',
            'the first by name when a user has several, and `null` when none is assigned',
            'The update changes no role',
            'A user with several assigned roles, as an LDAP group mapping gives them, keeps all of them when the Edit User form is saved with the role it shows, the first by name',
            '`' . sprintf(UserForm::SEVERAL_ROLES_HINT, 'approvers, operator') . '`',
            'Through the API, a `role` the user already has keeps every role assigned to them, so a `role` read with `GET` and sent back keeps them all.',
            'Another role replaces them all, in the form and in the API.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The roles item of updating.md');
        }
        $step = $this->checklist(6);
        $this->assertMentions('6. Check the Role column of the Users page after the update', $step, 'The checklist of updating.md');
        $this->assertMentions(
            'Saves made with 2.10.1 or older are recorded as `' . AuditLog::ACTION_USER_UPDATED . '` without the role, so the log cannot tell which of them changed one; from 2.11.0 on, `'
                . AuditLog::ACTION_USER_CREATED . '` and `' . AuditLog::ACTION_USER_UPDATED . '` record `roles` and `is_superadmin` as `{from, to}` when they change.',
            $step,
            'Step 6 of the checklist'
        );
        $this->assertStringNotContainsString('but not the role, so it cannot tell which saves changed one', $step, 'The audit of 2.11.0 records the roles.');
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $this->assertMentions(
            'The `role` of a user in the answers of `/api/v1/users` is the role assigned to the user, the first by name when several are, and `null` when none is',
            $section,
            'The API contract change of updating.md'
        );
        $config = require dirname(__DIR__, 3) . '/config/web.php';
        $this->assertIsArray($config);
        $this->assertSame(['viewer'], $config['components']['authManager']['defaultRoles'] ?? null, 'the default role the item names');
    }

    /**
     * The API contract change names the 422 of a role the instance does not
     * have in the words of UsersController::INVALID_ROLE, with the stock
     * roles (UsersControllerTest pins the answer on an instance that has
     * them), and what POST and PUT did with such a role before.
     */
    public function testTheApiContractChangeQuotesThe422OfAnUnknownRole(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $expected = [
            '`POST /api/v1/users` and `PUT /api/v1/users/{id}` answer `422` for a `role` the instance does not have, with the roles it has, such as `'
                . sprintf(UsersController::INVALID_ROLE, 'admin, operator, viewer') . '`, and write nothing.',
            'They saved the user without any role: an update removed every role the user had.',
            'A `role` that is not a string, such as `5` or `true`, answers the same `422`; it was ignored.',
            'Role names are compared exactly, so `Admin` answers `422` too, where it assigned `admin`.',
            'Without a `role`, or with `null` or an empty one, the roles stay as they are.',
            '`POST /api/v1/users` and `PUT /api/v1/users/{id}` answer `403` `' . RoleGrantPolicy::SUPERADMIN_REFUSED
                . '` when the body would change `is_superadmin` and the caller is no superadmin, and `403` `' . sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin')
                . '` for a role the caller may not assign; nothing is written.',
            'A `role` the user already has keeps every role assigned to them; it replaced them all, so a `PUT` with the `role` read from `GET` dropped every role but the first by name.',
            'The audit entries `' . AuditLog::ACTION_USER_CREATED . '` and `' . AuditLog::ACTION_USER_UPDATED . '` add `roles` and `is_superadmin` as `{from, to}`: on create both, from `null`; on update each when it changed.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $section, 'The API contract change of updating.md');
        }
    }

    /**
     * The API contract change names what the answers of notification
     * templates, projects and webhooks no longer show, and how a round trip
     * keeps a secret, in the words of NotificationConfigSecrets and Webhook;
     * the channel secrets it names are NotificationTemplate's.
     */
    public function testTheApiContractChangeNamesTheSecretsTheAnswersNoLongerShow(): void
    {
        $api = $this->between($this->flat(self::UPDATING, self::UPDATE_SECTION), '**API contract change:**', '**Database and deployment.**');
        $placeholder = SurveySecrets::PLACEHOLDER;
        $named = ['bot_token' => '`bot_token`', 'webhook_url' => '`webhook_url`', 'url' => '`url`', 'routing_key' => '`routing_key`', 'headers' => 'the value of every header'];
        $secretKeys = array_unique(array_merge(...array_values(NotificationTemplate::SECRET_CONFIG_KEYS), ...array_values(NotificationTemplate::SECRET_CONFIG_MAPS)));
        $this->assertEqualsCanonicalizing(array_keys($named), array_values($secretKeys), 'The API contract change names every secret key of a channel config.');
        $expected = [
            'answer every channel secret of `config` as `' . $placeholder . '`, to every caller: the `bot_token` of a Telegram channel, the `webhook_url` of a Slack or Teams channel, the `url` and the value of every header of a webhook channel, and the `routing_key` of a PagerDuty channel.',
            'A `PUT` that sends `' . $placeholder . '` back keeps the stored value, so a `GET` and `PUT` round trip changes no secret',
            '`' . sprintf(NotificationConfigSecrets::NOT_STORED, 'bot_token') . '`, and writes nothing.',
            'answer the `scm_url` of a project with `***@` in place of the credentials of the URL, such as `'
                . GitSyncDiagnostics::redactUrl('https://deploy:token@git.example.com/org/repo.git') . '`, to callers who may not update the project (`project.update` and operator access to it).',
            '`PUT /api/v1/webhooks/{id}` with `secret` `' . $placeholder . '` keeps the stored signing secret; `POST /api/v1/webhooks` with it answers `422` `' . Webhook::SECRET_NOT_STORED . '`',
        ];
        foreach (array_merge($expected, array_values($named)) as $phrase) {
            $this->assertMentions($phrase, $api, 'The API contract change of updating.md');
        }
    }

    /**
     * The fixes of the release are listed, and the old messages stand beside
     * the new ones where runs keep what they stored.
     */
    public function testTheFixesAndTheChangedMessagesAreListed(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $expected = [
            'used colours that the dark theme draws like its background',
            'A deleted workflow is named `Release web ' . Texts::WORKFLOW_DELETED . '`, without a link',
            'The team scope of a workflow\'s job steps is checked again while its steps are locked for a change',
            // The answers in that race: those of the API's and the page's own checks.
            'The API answers `403` `' . WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED . '`, the template page `'
                . BaseController::MODIFY_FORBIDDEN . '`, the answers of their own checks before the change.',
            '`GET /api/v1/jobs` counts the artifacts of its jobs without a query per job',
            'The example of `PUT /api/v1/schedules/{id}` in the OpenAPI spec names the required `name` and `job_template_id`.',
            '▲ and ▼ on a step that shared its step order with its neighbour wrote the same order back',
            'The status in the details of a job\'s page follows the job',
            'the dashboard\'s Running for started over after 24 hours',
            'no longer overflow the page at phone width',
        ];
        foreach ($expected as $fix) {
            $this->assertMentions($fix, $section, 'The fixes of updating.md');
        }
        $this->assertMentions(
            'steps that ran before 2.11.0 keep `The next step #7 of this workflow no longer exists.`',
            $section,
            'updating.md'
        );
        $this->assertMentions(
            'Up to 2.10.1 it named the steps by their step order and advised to remove them: `Step "Build" was not removed: other steps route to it (#10 "Retry"). Remove those steps first, and add them again with other routes if you still need them.`',
            $section,
            'updating.md'
        );
        $this->assertMentions('Where the notes of earlier releases advise removing a step and adding it again to change its target or its branches, edit it instead', $section, 'updating.md');
        $fixes = $this->between($section, '**Fixes that change behaviour:**', '**API contract change:**');
        $mapping = WorkflowStepChangeSummary::FIELD_LABELS['extra_vars_template'];
        $wave5 = [
            '`' . sprintf(WorkflowStepFormView::PREVIEW_SKIPPED_TO, 'Build', 'Deploy') . '` instead of `' . sprintf(WorkflowStepFormView::PREVIEW_BETWEEN, 'Build', 'Deploy') . '`',
            '`' . WorkflowStepFormView::ROUTED_BY_TITLE . '` on the page of a step left out the steps whose branch to it no run takes',
            'said `' . WorkflowStepFormView::ROUTED_BY_NONE . '` while the removal of the step was refused because of them',
            'was not marked `' . WorkflowStepsTable::UNREACHABLE_BADGE . '`, and the API answered `reachable: true` for it',
            sprintf('Above %d steps, **%s** opens the page of the step, whose Position field offers every place', WorkflowStepsTable::MOVE_MENU_LIMIT, WorkflowStepsTable::MOVE_TO),
            'answered `' . sprintf(WorkflowStepChangeSummary::SAVED, 'Build', $mapping) . '`, rewrote the mapping and added a `' . AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED . '` entry',
            'Such a request answers `' . sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Build') . '` and writes nothing.',
            'gave its jobs the extra vars `["1.2.3"]`, a JSON list instead of an object of variables',
            '`' . ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED . '`, the same text on the template page), and nothing is written.',
            'it became `' . WorkflowStepFormView::END_FAILS_OPTION . '` again.',
            sprintf('A run\'s page asks for news %d seconds after something changed, and up to %d seconds apart while nothing does', $this->liveScriptNumber('INTERVAL') / 1000, $this->liveScriptNumber('MAX_INTERVAL') / 1000),
            '`' . Texts::LIVE_STOPPED_RUN . '` (`' . Texts::LIVE_STOPPED_JOB . '` on the page of a job)',
            'landed on the job\'s poll (`/job/log-poll`, JSON) instead of the dashboard',
        ];
        foreach ($wave5 as $fix) {
            $this->assertMentions($fix, $fixes, 'The fixes of updating.md');
        }
        $step = WorkflowStepReorderService::ORDER_STEP;
        $this->assertMentions(sprintf('renumbers the workflow\'s steps %d, %d, %d… in their run order', $step, 2 * $step, 3 * $step), $section, 'updating.md');
    }

    /**
     * The API differences the section names: the API keeps the stored step
     * status of a canceled step, a run's detail is not personal, and job
     * list items carry no link fields.
     */
    public function testTheApiDifferencesByDesignAreNamed(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $this->assertMentions('Three things differ from the web UI on purpose.', $section, 'updating.md');
        $this->assertMentions('ended keeps the status `failed`, with the `error`', $section, 'updating.md');
        // regression (W5-API-6): "A run's detail is the same for every caller", though a step's approval fields depend on the caller.
        $this->assertMentions(
            'A run\'s detail says nothing about the caller\'s own relation to a request, nor whether they may resume or cancel; the `approval_request_id` and `approval` of a step are `null` where the caller may not see the request.',
            $section,
            'updating.md'
        );
        $this->assertStringNotContainsString('the same for every caller', $section, 'updating.md');
        $this->assertMentions('The items of `GET /api/v1/jobs` carry no link fields', $section, 'updating.md');
        $this->assertMentions(
            '`?status=` on `GET /api/v1/approvals` and `GET /api/v1/workflow-jobs` was ignored and filters now',
            $section,
            'updating.md'
        );
        $this->assertMentions('`PUT /api/v1/workflow-templates/{id}` with a `steps` list still replaces every step', $section, 'updating.md');
    }

    /**
     * Database and deployment gives the rollback to 2.10.1 for both kinds of
     * installation, with the image tags release.yml publishes (no leading
     * v, DocsImageTagsTest) and the git tag, and says what 2.10.1 cannot
     * undo: branches that lead to each other.
     *
     * Regression (wave 5, W5-OPS-5 and W5-OPS-6): the section said only
     * "deploy it again", which led to the Rollback steps and their tag
     * `:v2.1.0`, which does not exist, and claimed nothing was left to undo,
     * though 2.10.1 neither edits branches nor removes a step that a branch
     * leads to. Fails on the section from before.
     */
    public function testRegressionTheRollbackNamesTheImagesTheGitTagAndTheBranchCycles(): void
    {
        $item = $this->item('**Database and deployment.**');
        $expected = [
            'Prebuilt images: set the images in `docker-compose.yml` to `ghcr.io/ansilume/ansilume:2.10.1`, `ghcr.io/ansilume/ansilume-nginx:2.10.1` and `ghcr.io/ansilume/ansilume-runner:2.10.1` (the image tags have no leading `v`), then run `docker compose pull && docker compose up -d`.',
            'Git checkout: `git checkout v2.10.1 && docker compose up -d --build --force-recreate`.',
            '2.10.1 can neither edit branches nor remove steps whose branches lead to each other',
            'it refuses to remove a step that a branch of another step leads to.',
            'Clear such branches with **Edit** before rolling back, or replace the steps with `PUT /api/v1/workflow-templates/{id}` afterwards, which drops every branch.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'Database and deployment of updating.md');
        }
        $this->assertMentions(
            'change `:latest` to `:2.10.1`; the image tags have no leading `v`, unlike the git tags such as `v2.10.1`',
            $this->flat(self::UPDATING, '## Rollback'),
            'The Rollback steps of updating.md'
        );
        $this->assertStringNotContainsString('`:v2.1.0`', $this->read(self::UPDATING));
    }

    /**
     * The known limits name what this release leaves as it is: the run
     * page's poll sends every executed step; the credentials of a repository
     * URL whose password holds a raw / or a space, which
     * GitSyncDiagnostics::redactUrl() does not recognise, and sync output
     * that 2.10.1 split across rows; who may approve follows roles, team
     * memberships and the teams' projects, which their managers change
     * without approval-rule.update; and dev mode opens the debug and gii
     * modules to everyone who reaches the stack (config/web.php,
     * config/debug.php).
     */
    public function testTheKnownLimitsNameThePollRepositoryUrlsTheApproversAndDevMode(): void
    {
        $limits = $this->item('**Known limits.**');
        $expected = [
            'A run\'s page asks for every step the run executed at each poll.',
            'makes each poll send megabytes (about 1 MB for 2,000 executed steps), every 3 seconds while the run goes on, to each open page of the run. Close such a page, or cancel the run.',
            'The credentials of a repository URL are hidden up to the last `@` before the URL\'s path or the next space. A password with a raw `/` or a space is not recognised, so such a URL shows in full wherever the URL is shown; it does not work either.',
            'Percent-encode such characters (`%2F`, `%20`, and `%40` for `@`), or give the project an SCM credential.',
            'Sync output that 2.10.1 or older stored with a URL split across two of its rows shows as stored until the next sync of the project replaces it.',
            'Who may approve follows the users\' roles, their team memberships and the projects of their teams, which an approval rule does not lock: whoever may assign roles, change what a role grants, or manage teams, their members and their projects, by default only admins, changes who may approve a request without `approval-rule.update`.',
            'With LDAP, the directory groups of a user give their roles.',
            'With `YII_ENV=dev`, as `.env.example` sets it for a git checkout, the debug toolbar and gii answer everyone who reaches the stack, signed in or not',
            'A dev stack must never be reachable by others; production runs with `YII_ENV=prod`.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $limits, 'The known limits of updating.md');
        }
        // What the redaction recognises, as the bullet says.
        $this->assertSame('https://***@host/org/repo.git', GitSyncDiagnostics::redactUrl('https://u:p@ss@host/org/repo.git'), 'up to the last @');
        $this->assertSame('https://u:pa/ss@host/r', GitSyncDiagnostics::redactUrl('https://u:pa/ss@host/r'), 'a raw / ends the authority');
        $this->assertSame('https://u:p ss@host/r', GitSyncDiagnostics::redactUrl('https://u:p ss@host/r'), 'a space ends the authority');
        $this->assertSame('https://***@host/r', GitSyncDiagnostics::redactUrl('https://u:pa%2Fss%20x%40y@host/r'), 'percent-encoded characters are hidden');
        $root = dirname(__DIR__, 3);
        $debug = require $root . '/config/debug.php';
        $this->assertIsArray($debug);
        $this->assertSame(['*'], $debug['allowedIPs'] ?? null, 'The debug module answers everyone, as the docs say.');
        $web = (string)file_get_contents($root . '/config/web.php');
        $gii = <<<'REGEX'
            /if \(YII_ENV_DEV\) \{[^}]*'class' => 'yii\\gii\\Module',\s*'allowedIPs' => \['\*'\]/s
            REGEX;
        $this->assertSame(1, preg_match($gii, $web), 'Dev mode loads gii for everyone, as the docs say.');
    }

    /**
     * The item on repository URLs says where their credentials reached up to
     * 2.10.1: the sync output every viewer of the project read, the
     * queue-worker's log (ProjectService::GIT_COMMAND_LOGGED), and the error
     * of a clone that ran too long (ProjectSyncProcessRunner::TIMED_OUT),
     * which became the project's last sync error and its project.sync_failed
     * notification; what shows now, in the words of the code
     * (GitSyncDiagnostics::redactUrl(), WebhookChannel::SENT); and that the
     * credentials are rotated and moved into an SCM credential, and that a
     * checkout takes the project's current URL at its next sync
     * (ProjectService::gitPull(), RunnerProjectSync), except on runners
     * older than 2.11.0, whose checkouts are removed instead; a runner only
     * fast-forwards, so another history fails its job.
     *
     * Regression (wave 5c): the known limits said the credentials of a
     * repository URL still reached the sync output a project's page receives
     * and the queue-worker's log, and the secrets item pointed there; the
     * release redacts them at the source. Fails on the section from before.
     *
     * Regression (wave 5d): the item and step 7 told to remove every
     * checkout after a change of the URL, as syncs kept fetching from the
     * URL a checkout was cloned with; both paths now point origin at the
     * current URL first. The guard at the end fails when either path stops
     * doing so. Fails on the section from before.
     */
    public function testRegressionTheRepositoryUrlLeakIsAFixWithRotationAdvice(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $this->assertStringNotContainsString('still reach the sync output that the page of a project receives while a sync runs', $section, 'The known limit of 2.11.0 before wave 5c is gone.');
        $this->assertStringNotContainsString('The sync output that the page receives while a sync runs still holds them (see **Known limits**).', $section);
        $this->assertMentions(
            'So do the sync output that the page receives while a sync runs, the errors of a sync and the logs (see **Security: repository URLs in syncs and logs**).',
            $this->item('**Security: secrets on pages and in the API.**'),
            'The secrets item of updating.md'
        );

        $service = new ProjectService();
        $url = 'https://deploy:token@git.example.com/org/repo.git';
        $clone = ['git', 'clone', '--progress', '--branch', 'main', '--depth', '1', '--', $url, $service->workspacePath . '/5'];
        $item = $this->item('**Security: repository URLs in syncs and logs.**');
        $expected = [
            'the sync output of the project, which everyone who could see the project read on its page while a sync ran: the command that clones the repository, with its URL;',
            'the log of the queue-worker, which writes the level info: the same command, as `' . sprintf(ProjectService::GIT_COMMAND_LOGGED, 'git clone … https://user:token@host/…') . '`;',
            'the error of a clone that ran too long, which named that command: the project\'s last sync error, which its page shows and `project.sync_failed` sends, and which the queue-worker logs;',
            'for a password with a raw `@`, the part after it: git\'s own messages name it as the user, also in the job log of a runner\'s sync.',
            'From 2.11.0 on, all of these show the URL as `' . GitSyncDiagnostics::redactUrl($url) . '`, also the rows and errors stored before the update when the page shows them, and a password with a raw `@` is hidden whole.',
            'A clone that runs too long fails with, for example, `' . sprintf(ProjectSyncProcessRunner::TIMED_OUT, $service->gitTimeoutSeconds, GitSyncDiagnostics::redactUrl(implode(' ', $clone))) . '`.',
            'A webhook notification that its endpoint took was logged with the URL of the webhook, which can carry a token, as `WebhookChannel: sent to <url> — 204`; it is logged as `'
                . sprintf(WebhookChannel::SENT, 12, 204) . '` now.',
            'The application log of the queue-worker and the schedule-runner (`runtime/logs/app.log`) keeps the lines it wrote before the update: a git checkout keeps the file in its install directory, so truncate it as **Purge old logs** in [the notes of 2.8.1](#after-updating-from-281-or-older-encrypted-password-answers-trigger-tokens-in-a-header-logs-without-secrets) describes, and purge its copies in log shippers and backups; the prebuilt images start the new containers with new log files.',
            'Rotate the credentials in repository URLs where the readers of these pages, logs and notifications should not hold them (step 7 above), and keep the new ones in an SCM credential of the project rather than in its URL: git gets them at each sync, and no page, log or checkout holds them.',
            'A checkout keeps the URL it was cloned with, credentials included, in its `.git/config`. Up to 2.10.1 later syncs and jobs fetched from that URL even after the project\'s URL changed, so a rotated token or a moved repository was used only once the checkout was removed, and the old credentials stayed on the disk.',
            'From 2.11.0 on, the sync of an existing checkout first points its origin at the project\'s current URL: on the server at every sync (saving a git project queues one), shown in the sync output as `'
                . rtrim(sprintf(ProjectService::GIT_COMMAND_ROW, 'git -C ' . $service->workspacePath . '/5 remote set-url -- origin ' . GitSyncDiagnostics::redactUrl($url)))
                . '`, and on a runner before every job of the project. The old credentials leave a checkout at its next sync.',
            'Runners older than 2.11.0 keep fetching from the URL their checkout was cloned with until they are updated (see **Database and deployment**); until then, remove the project\'s checkout on such a runner (`'
                . $service->workspacePath . '/<project ID>`, in its container if it runs in one) after a change of the URL or of the credentials in it.',
            'A runner only fast-forwards its checkout, so a URL that names a repository with another history fails the job, with git\'s `fatal: refusing to merge unrelated histories` or `fatal: Not possible to fast-forward, aborting.`, as a force push of the branch did before: remove the project\'s checkout on that runner, and its next job clones the repository again.',
            'Up to 2.10.1 such a runner kept running the repository its checkout was cloned from. The server resets its checkout to what it fetched and needs nothing.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The repository URL item of updating.md');
        }
        // regression (wave 5d): the advice of 2.11.0 before the fix.
        $this->assertStringNotContainsString('and later syncs and jobs fetch from that URL even after the project\'s URL changed', $item);
        $this->assertStringNotContainsString('so that both clone the repository again from the new URL', $item);
        $step = $this->checklist(7);
        $this->assertMentions(
            'The credentials in repository URLs also reached the log of the queue-worker, and could reach the error of a failed sync and its `project.sync_failed` notifications: rotate them where these should not hold them, and keep the new ones in an SCM credential of the project; saving the project syncs it, and each checkout takes the new URL at its next sync, except on runners older than 2.11.0 (see **Security: repository URLs in syncs and logs**).',
            $step,
            'Step 7 of the checklist'
        );
        $this->assertStringNotContainsString('then remove the project\'s checkouts', $step, 'Step 7 no longer removes every checkout.');
        $this->assertSyncsPointOriginAtTheCurrentUrl();
    }

    /**
     * The item on changing other users' accounts says what user.update and
     * user.delete allowed up to 2.10.1 and what UserChangePolicy allows now,
     * with its refusal, and gives the read-only query of the changes to
     * check (AccountChangesQueryDocsContractTest runs it); step 9 of the
     * checklist points to it, the API contract change names the 403, and
     * CLAUDE.md's rule 14 and README.md say the same. The Users pages need
     * the permissions the item names.
     *
     * Regression (wave 5c): nothing said that whoever held user.update could
     * set the password and the email of a superadmin and sign in as them.
     * Fails on the section from before.
     */
    public function testRegressionTheAccountsItemQuotesTheRuleTheRefusalAndTheQuery(): void
    {
        $item = $this->item('**Security: who may change other users\' accounts.**');
        $expected = [
            'Up to 2.10.1, whoever held `user.update` could set the password and the email of any user, a superadmin included, through the Edit User form and `PUT /api/v1/users/{id}`, and so sign in as them; whoever held `user.delete` could deactivate or delete any user.',
            'Of the built-in roles only admin holds these permissions, so this concerned custom roles, such as one for a help desk, and admins who are no superadmins, who could take over the account of a superadmin.',
            'From 2.11.0 on, a user who is no superadmin changes or deletes another user only when that user is no superadmin and every role assigned to them is one the first may assign, that is, one that grants no permission they do not hold, and, for the `admin` role or a role that contains it, only when they hold the `admin` role (see **Security: who may give roles and the superadmin flag**).',
            'A user without an assigned role who is no superadmin may be changed by everyone who holds `user.update`.',
            'Superadmins change everyone, and admins who are no superadmins everyone but superadmins.',
            'Anyone else gets `403` `' . UserChangePolicy::CHANGE_REFUSED . '` for the Edit User form, opened or saved, for **Deactivate**, **Activate** and **Delete**, and for `PUT` and `DELETE /api/v1/users/{id}`; nothing is written,',
            'the users list and the user page no longer offer **Edit**, **Deactivate**, **Activate** and **Delete** for such a user.',
            'A deleted account is not listed: check the `' . AuditLog::ACTION_USER_DELETED . '` entries of the audit log as well.',
            '-- Changes to the accounts of superadmins and admins by users who may no longer make them',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The accounts item of updating.md');
        }
        $this->assertStringNotContainsString('curl', $item, 'The item shows no way to take an account over.');
        $this->assertMentions(
            '9. Check the changes that users who are no superadmin made to the accounts of superadmins and admins, and those that users who are neither a superadmin nor an admin made to the accounts of team members with more access to a project than they had (the `'
                . AuditLog::ACTION_USER_UPDATED . '` and `' . AuditLog::ACTION_USER_STATUS_CHANGED . '` entries of the audit log), with the two read-only queries under **Security: who may change other users\' accounts**.',
            $this->checklist(9),
            'Step 9 of the checklist'
        );
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $this->assertMentions(
            '`PUT` and `DELETE /api/v1/users/{id}` answer `403` `' . UserChangePolicy::CHANGE_REFUSED
                . '` when the caller is no superadmin and the user is a superadmin or holds a role the caller may not assign. The answer comes after the `403` of a missing permission and the `404` of an unknown user, and before every other answer, and nothing is written.',
            $section,
            'The API contract change of updating.md'
        );
        $this->assertMentions(
            'Admins hold every permission, so for them only the superadmin flag changes here, besides that they no longer change the accounts of superadmins (see **Security: who may change other users\' accounts**).',
            $this->item('**Security: who may give roles and the superadmin flag.**'),
            'The roles item of updating.md'
        );
        $claude = $this->flat('CLAUDE.md');
        foreach (
            [
                'only when that user is no superadmin, they may assign every role assigned to that user and, unless they hold the admin role, their teams give them every team-restricted project the user\'s teams give the user, with at least the same role (`'
                    . UserChangePolicy::class . '`); otherwise the answer is 403 (`UserChangePolicy::refusalOf()`: `CHANGE_REFUSED`, `ADMIN_ROLE_CHANGE_REFUSED` where the admin role is all they lack, or `TEAM_ACCESS_REFUSED`), after the 404 and before every other check',
                'A new action that writes another user\'s account (password, email, username, status, TOTP, auth source, API tokens) asks `UserChangePolicy::refusalOf()` first.',
            ] as $phrase
        ) {
            $this->assertMentions($phrase, $claude, 'Rule 14 of CLAUDE.md');
        }
        $this->assertTrue(method_exists(UserChangePolicy::class, 'refusalOf'));
        $this->assertMentions(
            'a user who is no superadmin changes or deletes only users who are no superadmins, whose roles they may assign and, unless they are admins, whose teams give them no project beyond their own, nor a higher role on one',
            $this->flat('README.md'),
            'The security list of README.md'
        );
        $controller = new \ReflectionClass(UserController::class);
        $rules = $controller->getMethod('accessRules')->invoke($controller->newInstanceWithoutConstructor());
        $this->assertIsArray($rules);
        $permissions = [];
        foreach ($rules as $rule) {
            foreach ((array)($rule['actions'] ?? []) as $action) {
                $permissions[$action] = (array)($rule['roles'] ?? []);
            }
        }
        $this->assertSame(['user.update'], $permissions['update'] ?? null, 'The Edit User form needs user.update.');
        $this->assertSame(['user.delete'], $permissions['toggle-status'] ?? null, 'Deactivate and Activate need user.delete.');
        $this->assertSame(['user.delete'], $permissions['delete'] ?? null, 'Delete needs user.delete.');
    }

    /**
     * The fixes of wave 5c are listed in the words of the code: the pace of
     * a job's page (views/job/view.php), the stop of a project page's sync
     * output (ProjectController::SYNC_LIVE_STOPPED, views/project/view.php),
     * the JSON requests of the sync output and the dashboard's charts, the
     * order of the votes on a request's page, the re-check of a steps list,
     * and the Add Step warning of an approval step
     * (WorkflowStepFormView::THIS_APPROVAL_OFF_PATH); the edit page of a
     * step shows a mapping only where there is one; the API contract change
     * names has_secret.
     */
    public function testTheFixesOfWave5cAreListedInTheWordsOfTheCode(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $fixes = $this->between($section, '**Fixes that change behaviour:**', '**API contract change:**');
        $interval = $this->viewScriptNumber('views/job/view.php', 'INTERVAL');
        $max = $this->viewScriptNumber('views/job/view.php', 'MAX_INTERVAL');
        $gaps = [];
        for ($gap = $interval; $gap < $max; $gap *= 2) {
            $gaps[] = self::seconds($gap);
        }
        $last = array_pop($gaps);
        $expected = [
            sprintf('A job\'s page asks for news %s seconds after each answer while the job runs, so its output keeps up.', self::seconds($interval)),
            sprintf(
                'While the job waits for an approval, to be queued or for a runner, it asks up to %s seconds apart while nothing changes (after %s and %s seconds, then every %s), and %s seconds after something changed.',
                self::seconds($max),
                implode(', ', $gaps),
                $last,
                self::seconds($max),
                self::seconds($interval)
            ),
            'It does not ask while its tab is hidden, and asks at once when the tab is shown again. After a poll that failed it asks twice as late each time, at most ' . self::seconds($max) . ' seconds apart.',
            'It asked every 2.5 seconds for as long as the job waited, also in hidden tabs, and every 5 seconds while its polls failed.',
            sprintf(
                'The sync output of a project\'s page stops the same way and says below the output `%s`; it asked again every %s seconds for as long as the page stayed open.',
                ProjectController::SYNC_LIVE_STOPPED,
                self::seconds($this->viewScriptNumber('views/project/view.php', 'pollIntervalMs'))
            ),
            'and so did a user whose project page asked for its sync output (`/project/sync-status`), whose dashboard asked for its charts (`/site/chart-data`), or who opened the preview of a text artifact on a job\'s page (`/job/<id>/artifact/<artifact_id>/content`), while they were signed out. These requests ask for JSON now, and are answered `403` instead of being sent to the login page.',
            'A dashboard whose request for its charts is refused or fails keeps its charts as they are; it read every answer as JSON, which ended in an error that the page did not catch.',
            'The page of an approval request lists its votes in the order they were cast, as `decisions` in the API does; it listed them in the order the database returned them, which can be by user ID.',
            'by Add Step, Edit Step, the step endpoints or a `steps` list of `POST` and `PUT /api/v1/workflow-templates`, is checked again in the transaction that writes the change, with the steps of an existing workflow locked:',
            'and an approval step that is not on the success path is flagged, on Add Step before the add too (see **Steps have positions**).',
        ];
        foreach ($expected as $fix) {
            $this->assertMentions($fix, $fixes, 'The fixes of updating.md');
        }
        $this->assertMentions(
            'or, for an approval step, when no success would lead to the place: `' . WorkflowStepFormView::THIS_APPROVAL_OFF_PATH . '`',
            $this->item('**Steps have positions.**'),
            'updating.md, on Position'
        );
        $this->assertMentions(
            'shows its variable mapping, if it has one, read-only (a mapping that an earlier version stored as `[]` or `{}` is none).',
            $this->item('**Steps can be edited.**'),
            'updating.md, on the page of a step'
        );
        $this->assertMentions(
            '`GET /api/v1/webhooks` and `GET /api/v1/webhooks/{id}`, and the answers of `POST` and `PUT`, gain `has_secret`: whether the webhook has a signing secret, so that its deliveries are signed, as the webhook pages say. The secret itself is never answered.',
            $this->between($section, '**API contract change:**', '**Database and deployment.**'),
            'The API contract change of updating.md'
        );
    }

    /**
     * The entry on stuck runs quotes the banner as the run page shows it,
     * and the API fields that say the same.
     */
    public function testTheStuckRunEntryQuotesTheBanner(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::STUCK_ENTRY);
        $expected = [
            WorkflowStepChangeGuard::runsActiveMessage([12]),
            sprintf(Texts::BANNER_APPROVAL_STEP, 'Release approval'),
            sprintf(Texts::BANNER_GATED_JOB, 34, 'Deploy'),
            sprintf(Texts::FACT_REQUEST, 18),
            sprintf(Texts::FACT_APPROVALS, 1, 2),
            sprintf(Texts::FACT_OPEN_APPROVERS, 2),
            Texts::PERSONAL_MAY_DECIDE,
            Texts::PERSONAL_LAUNCHER,
            Texts::WARNING_UNDECIDABLE,
            sprintf(Texts::FACT_EXPIRED, '2026-10-14 14:02:11', Texts::THEN_REJECTED),
            Texts::FACT_NEVER_EXPIRES,
            Texts::BANNER_REQUEST_MISSING,
            sprintf(Texts::BANNER_PAUSED, 'Wait'),
            sprintf(Texts::BANNER_QUEUED, 34, 'Deploy'),
        ];
        foreach ($expected as $quote) {
            $this->assertMentions('`' . $quote . '`', $entry, 'troubleshooting.md, on stuck runs');
        }
        foreach (['`waiting_for`', '`can_still_be_approved`', '`GET /api/v1/approvals?status=pending`'] as $field) {
            $this->assertMentions($field, $entry, 'troubleshooting.md, on stuck runs');
        }
    }

    /**
     * The entry on refused step changes quotes every refusal of the step
     * write path as the code words it.
     */
    public function testTheRefusedChangesEntryQuotesTheWritePath(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::REFUSED_ENTRY);
        $expected = [
            WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN,
            WorkflowTemplateController::FLASH_STEP_NOT_ADDED . WorkflowStep::JOB_TEMPLATE_REQUIRED,
            WorkflowTemplateController::FLASH_STEP_NOT_ADDED . WorkflowStep::JOB_TEMPLATE_NOT_FOUND,
            WorkflowStepCommand::TYPE_LOCKED,
            WorkflowTemplateController::FLASH_STEP_NOT_ADDED . sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, 43),
            WorkflowStepReorderService::AFTER_ITSELF,
            sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 5),
            WorkflowStepPosition::FORM_INVALID,
            WorkflowStepPosition::BOTH_GIVEN,
            WorkflowStepPosition::MOVE_NEEDS_ONE,
            WorkflowStepPosition::POSITION_INVALID,
            WorkflowStepPosition::AFTER_INVALID,
            WorkflowStepPosition::DIRECTION_INVALID,
            sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_failure_step_id'),
            WorkflowStepCommand::PAUSE_BRANCHES_REFUSED,
            sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, 7, 44),
            ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED,
            WorkflowTemplateController::FLASH_STEP_NOT_SAVED,
            WorkflowTemplateController::FLASH_STEP_NOT_MOVED,
            WorkflowStepMapping::INVALID,
        ];
        foreach ($expected as $quote) {
            $this->assertMentions('`' . $quote . '`', $entry, 'troubleshooting.md, on refused step changes');
        }
        $this->assertMentions('with a `Not used: …` note under a step whose branch here no run takes, which keeps the step all the same', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . WorkflowStepFormView::ROUTED_BY_TITLE . '`', $entry, 'troubleshooting.md');
        $this->assertMentions('(`409`, with those steps in `error.step_ids`)', $entry, 'troubleshooting.md');
    }

    /**
     * The entry on refused saves of notification templates and webhooks
     * quotes NotificationConfigSecrets's and Webhook's refusals and says
     * when $encrypted$ keeps a stored value.
     */
    public function testTheSecretsEntryQuotesTheRefusalsOfAPlaceholder(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::SECRETS_ENTRY);
        $expected = [
            '`' . sprintf(NotificationConfigSecrets::NOT_STORED, 'bot_token') . '`',
            '`' . sprintf(NotificationConfigSecrets::CHANNEL_CHANGED, 'webhook_url') . '`',
            '`' . sprintf(NotificationConfigSecrets::URL_CHANGED, 'headers.Authorization', 'url') . '`',
            '`' . Webhook::SECRET_NOT_STORED . '`',
            'the edit form of an outbound webhook shows its signing secret so (the API never answers it).',
            'A save that sends `' . SurveySecrets::PLACEHOLDER . '` back keeps the stored value. That works only where a value is stored: not for a new template or webhook, not after the template\'s channel changed, and not for a header of a webhook channel once its URL changed',
            'Enter the real value in place of `' . SurveySecrets::PLACEHOLDER . '`',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $entry, 'troubleshooting.md, on secrets that have no stored value');
        }
    }

    /**
     * The entry on the order of steps explains positions, empty branches
     * and loops with the table's labels, and leads to the tie query.
     */
    public function testTheOrderEntryExplainsPositionsAndLeadsToTheTieQuery(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::ORDER_ENTRY);
        $expected = [
            '`' . WorkflowStepsTable::START_BADGE . '`',
            '`' . sprintf(WorkflowStepsTable::ROUTE_TO_NEXT, WorkflowStepsTable::stepLabel(2, 'Test')) . '`',
            '`' . sprintf(WorkflowStepsTable::ROUTE_TO_NEXT, WorkflowStepsTable::stepLabel(3, 'Deploy')) . WorkflowStepsTable::ALSO_AFTER_FAILURE . '`',
            '`' . sprintf(WorkflowStepsTable::ROUTE_LOOP, WorkflowStepsTable::stepLabel(1, 'Build')) . '`',
            '`' . WorkflowStepFormView::END_FAILS_OPTION . '`',
            '[updating.md](updating.md#' . self::SECTION_ANCHOR . ')',
            'The query under **Steps that share a step order** in',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $entry, 'troubleshooting.md, on the order of steps');
        }
    }

    /**
     * The entry on refused votes quotes both launcher refusals: the one of a
     * job and the one of a workflow run, and the run page's line.
     */
    public function testTheVoteEntryQuotesTheRefusalOfARunsLauncher(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::VOTE_ENTRY);
        $this->assertMentions('`' . ApprovalDecisionRefusedException::LAUNCHER_REFUSED . '` (`403`)', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN . '` (`403`)', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . Texts::PERSONAL_LAUNCHER . '`', $entry, 'troubleshooting.md');
    }

    /**
     * Regression (2.11.0): the troubleshooting docs told to remove a step and
     * add it again to change its target or branches; they tell to edit it.
     */
    public function testRegressionTheDocsAdviseEditingAStepNotRemovingAndAddingIt(): void
    {
        $troubleshooting = $this->flat(self::TROUBLESHOOTING);
        foreach (['remove the step and add it again', 'removes the step and adds it again'] as $advice) {
            $this->assertStringNotContainsString($advice, $troubleshooting, 'troubleshooting.md');
        }
        $this->assertMentions('edit the step and choose another job template, rule or branch', $troubleshooting, 'troubleshooting.md');
        $this->assertMentions('An admin edits the step and chooses a rule.', $troubleshooting, 'troubleshooting.md');
        $this->assertMentions(
            'Remove such a step and add it again with its routes, or, from 2.11.0 on, edit the step and choose another target for the branch',
            $this->flat(self::UPDATING, '## After updating from 2.9.0 or older: approvals and workflows'),
            'updating.md, on the sixth query of 2.9.0'
        );
    }

    /**
     * README.md and CLAUDE.md name the guide, positions, waiting states and
     * the rule for signed-out visitors.
     */
    public function testReadmeAndClaudeMdNameTheGuideAndTheRules(): void
    {
        $readme = $this->flat('README.md');
        $this->assertMentions('a run that waits says what for (Awaiting Approval, Paused, Queued) and links its approval request', $readme, 'README.md');
        $this->assertMentions('([guide](docs/workflows.md))', $readme, 'README.md');
        $this->assertMentions('| [Workflows](docs/workflows.md) |', $readme, 'The docs table of README.md');
        $this->assertMentions('Signed-out visitors reach only the login, password-reset and error pages', $readme, 'README.md');
        $claude = $this->flat('CLAUDE.md');
        foreach (['**Position**', '**Start Step**', '**Branch**', '**Waiting state**'] as $term) {
            $this->assertMentions($term, $claude, 'The domain language of CLAUDE.md');
        }
        $this->assertMentions('whether a step is added, edited, moved or removed, and give a job step a gated job template', $claude, 'Rule 12 of CLAUDE.md');
        $this->assertMentions(
            '13. Signed-out visitors reach only the login, password-reset and error pages, the trigger endpoint and the health checks',
            $claude,
            'The security requirements of CLAUDE.md'
        );
        $this->assertMentions('the default role applies to signed-in users only (`' . AuthManager::class . '`)', $claude, 'CLAUDE.md');
        $this->assertFileExists(dirname(__DIR__, 3) . '/tests/integration/controllers/GuestAccessTest.php', 'The guard CLAUDE.md names exists.');
    }

    /**
     * Wave 5: CLAUDE.md's rules name the channel secrets, the repository
     * URL, dev mode and who gives roles, with the classes that do it, and
     * README.md's security list says the same; installation.md says what dev
     * mode opens.
     */
    public function testReadmeClaudeMdAndInstallationNameTheRulesOfWave5(): void
    {
        $claude = $this->flat('CLAUDE.md');
        $expected = [
            'show password survey answers, notification channel secrets (`NotificationTemplate::SECRET_CONFIG_KEYS`, `SECRET_CONFIG_MAPS`) and the webhook signing secret only as `' . SurveySecrets::PLACEHOLDER . '`',
            'a form or API request that sends `' . SurveySecrets::PLACEHOLDER . '` back keeps the stored value',
            'A repository URL is shown and logged without the credentials of its userinfo (`GitSyncDiagnostics::redactUrl`), and so is every text a sync stores: on the project page, in its sync output and errors, in the logs, in the job log and in notifications; only the API and the edit form show it in full, to those who may update the project.',
            'A git command, git\'s output and any other text that can hold a repository URL pass through `GitSyncDiagnostics::redactUrl` before they are logged, stored or shown (`ProjectService`, `ProjectSyncProcessRunner`, `RunnerProjectSync`); a notification channel logs its HTTP code, never its URL',
            'Who may approve follows the users\' roles, team memberships and the teams\' projects, so whoever may change those (by default only admins) changes who may approve without `approval-rule.update`: a documented limit too',
            'In dev mode (`YII_ENV=dev`) `config/web.php` also loads the debug and gii modules, which everyone who reaches the stack can open, signed out too',
            'so a dev stack must never be reachable by others',
            '14. Only a superadmin sets or clears a user\'s superadmin flag, and a role is assigned only by a superadmin or by a user who holds every permission the role grants, its child roles and the default role included (`' . RoleGrantPolicy::class . '`)',
            '(`' . UserPrivileges::class . '`)',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $claude, 'The security requirements of CLAUDE.md');
        }
        foreach (['NotificationTemplate' => NotificationTemplate::class, 'GitSyncDiagnostics' => GitSyncDiagnostics::class] as $name => $class) {
            $this->assertTrue(class_exists($class), "CLAUDE.md names {$name}.");
        }
        $this->assertTrue(method_exists(GitSyncDiagnostics::class, 'redactUrl'));
        $readme = $this->flat('README.md');
        $readmeSays = [
            'Channel secrets of notification templates (bot tokens, webhook URLs and header values, routing keys) show as `' . SurveySecrets::PLACEHOLDER . '` on every page and in the API, and the signing secrets of outbound webhooks on their edit form',
            'A project\'s page, its sync output and errors, the logs and the notifications show its repository URL without the credentials in it, and so does the API to those who may not update the project',
            'only a superadmin sets or clears it, and a role is assigned only by a superadmin or a user who holds every permission the role grants',
            'In development mode (`YII_ENV=dev`) the debug and gii modules answer everyone who reaches the stack, so a dev stack must never be reachable by others',
        ];
        foreach ($readmeSays as $phrase) {
            $this->assertMentions($phrase, $readme, 'The security list of README.md');
        }
        // The note is a quote: its lines start with "> ".
        $installation = (string)preg_replace('/\s+/', ' ', (string)preg_replace('/^>\s?/m', '', $this->section('docs/installation.md', '## Manual setup — build from source')));
        $installationSays = [
            'With `YII_ENV=dev` the debug and gii modules answer everyone who reaches the stack, signed in or not, and the development compose file publishes the web port on every interface of the host.',
            'A dev stack must never be reachable by others.',
        ];
        foreach ($installationSays as $phrase) {
            $this->assertMentions($phrase, $installation, 'installation.md, on the development compose file,');
        }
        $compose = (string)file_get_contents(dirname(__DIR__, 3) . '/docker-compose.yml');
        $this->assertStringContainsString('- "${NGINX_PORT:-8080}:80"', $compose, 'The development compose file publishes the web port on every interface.');
    }

    /**
     * The PHP files under $directory of the repository (the code, whatever
     * root() says), and the scripts under web/js.
     *
     * @return list<string>
     */
    private function codeFiles(string $directory): array
    {
        $root = dirname(__DIR__, 3) . '/' . $directory;
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && in_array($file->getExtension(), ['php', 'js'], true) && !str_contains($file->getPathname(), '.min.')) {
                $files[] = $file->getPathname();
            }
        }
        $this->assertNotEmpty($files);

        return $files;
    }

    /**
     * The summary of a change of $step.
     *
     * @param 'added'|'moved'|'removed'|'unchanged'|'updated' $kind
     * @param list<WorkflowStep> $before
     * @param list<WorkflowStep> $after
     * @return array{message: string, warnings: list<string>}
     */
    private static function summary(string $kind, WorkflowStep $step, int $from, int $to, array $before, array $after): array
    {
        $change = new WorkflowStepChange($kind, $step, $from, $to, $before, $after, [], WorkflowStepWriteService::SOURCE_WEB);

        return WorkflowStepChangeSummary::of($change)->toArray();
    }

    /**
     * The number the run page's script (web/js/workflow-run-live.js, the
     * repository's) gives the variable $name, such as INTERVAL, in
     * milliseconds.
     */
    private function liveScriptNumber(string $name): int
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/web/js/workflow-run-live.js');
        $this->assertSame(1, preg_match('/\bvar ' . $name . ' = (\d+);/', $script, $match), "workflow-run-live.js sets {$name}.");

        return (int)$match[1];
    }

    /**
     * The number the script of the view $view (the repository's) gives the
     * variable $name, such as INTERVAL, in milliseconds.
     */
    private function viewScriptNumber(string $view, string $name): int
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $view);
        $this->assertSame(1, preg_match('/\bvar ' . $name . ' = (\d+);/', $script, $match), "{$view} sets {$name}.");

        return (int)$match[1];
    }

    /**
     * $milliseconds in seconds as the docs write them: 2.5, 30.
     */
    private static function seconds(int $milliseconds): string
    {
        return rtrim(rtrim(number_format($milliseconds / 1000, 1, '.', ''), '0'), '.');
    }

    /**
     * How the security items name the secrets of each channel whose config
     * holds one (NotificationTemplate::SECRET_CONFIG_KEYS and
     * SECRET_CONFIG_MAPS): a channel that gets a secret fails the test until
     * the items name it.
     *
     * @return list<string>
     */
    private function channelSecretsInWords(): array
    {
        $words = [
            NotificationTemplate::CHANNEL_TELEGRAM => 'the bot token of a Telegram channel',
            NotificationTemplate::CHANNEL_SLACK => 'the incoming-webhook URL of a Slack or Teams channel',
            NotificationTemplate::CHANNEL_TEAMS => 'the incoming-webhook URL of a Slack or Teams channel',
            NotificationTemplate::CHANNEL_WEBHOOK => 'the URL and the header values of a webhook channel',
            NotificationTemplate::CHANNEL_PAGERDUTY => 'the routing key of a PagerDuty channel',
        ];
        $withSecrets = [];
        foreach ([NotificationTemplate::SECRET_CONFIG_KEYS, NotificationTemplate::SECRET_CONFIG_MAPS] as $secrets) {
            foreach ($secrets as $channel => $keys) {
                if ($keys !== []) {
                    $withSecrets[] = $channel;
                }
            }
        }
        $this->assertEqualsCanonicalizing(array_keys($words), array_values(array_unique($withSecrets)), 'Every channel whose config holds a secret is named.');

        return array_values(array_unique($words));
    }
}
