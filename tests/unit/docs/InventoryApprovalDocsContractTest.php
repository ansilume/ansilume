<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\ApiBodyValues;
use app\components\ApprovalRuleUsage;
use app\components\InventoryUsage;
use app\components\SurveyDefinition;
use app\controllers\api\v1\InventoriesController;
use app\controllers\JobController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ApprovalRuleWriteService;
use app\services\ApproverEligibility;
use app\services\GatedInventoryLock;
use app\services\GatedJobTemplateLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryService;
use app\services\InventoryWriteService;
use app\services\JobClaimGate;
use app\services\JobClaimRefusedException;
use app\services\JobClaimService;
use app\services\JobLaunchService;
use app\services\JobTemplateGate;
use app\services\JobTemplateInventoryOptions;
use app\services\JobTemplateRuleAssignment;
use app\services\runstate\Texts;
use app\services\WorkflowStepFormView;
use app\services\WorkflowStepsTable;
use app\services\WorkflowTemplatePage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What the docs say about approval rules on inventories (2.12.0) is what
 * the code does: docs/updating.md's section for the update from 2.11.1 has
 * the items of the release in their order, and it, docs/troubleshooting.md
 * and docs/workflows.md quote every new text as the code words it; the
 * known limits are facts, never recipes; the API example calls a route with
 * a body the API takes; the API contract change names the fields of the
 * spec; README.md, CLAUDE.md, docs/credentials.md and docs/runners.md say
 * what changed; and the section of 2.10.0 points forward to this one.
 *
 * The section's queries run in InventoryApprovalQueriesDocsContractTest, and
 * its facts about the schema, the roles and the model in
 * InventoryApprovalBehaviourDocsContractTest.
 *
 * Regression (2.12.0): the docs called an approval at the level of an
 * inventory planned, and CLAUDE.md a documented limit until then; no doc
 * said what the release changes, what to check before it, or what an
 * approval rule on an inventory leaves open. Fails on the docs from before.
 *
 * Regression (2.12.0 review): the limit of the SCM credential names whoever
 * may create a project too (SEC-1, with its behaviour in
 * ProjectCreatorScmCredentialDocsContractTest), the rollback says that
 * 2.11.1 cannot delete a rule an inventory names (OPS-3, with its behaviour
 * in InventoryApprovalBehaviourDocsContractTest), and the API contract
 * change names used_by and the renamed responses (API-2, API-3). The pins
 * fail on the docs from before.
 */
class InventoryApprovalDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    /** The section of docs/updating.md. */
    public const UPDATE_SECTION = '## After updating from 2.11.1 or older: approval rules for inventories';

    /** The anchor GitHub gives the section. */
    public const SECTION_ANCHOR = 'after-updating-from-2111-or-older-approval-rules-for-inventories';

    /** The bold titles of the section's items, in their order. */
    public const ITEMS = [
        'Check before the update.',
        'Inventories can have an approval rule.',
        'Who may do what.',
        'Giving an inventory a rule.',
        'Check before you give an inventory a rule.',
        'One approval rule per launch.',
        'Only admins change a gated inventory.',
        'Job templates on a gated inventory are gated too.',
        'Gated job steps.',
        'Runners start only approved jobs.',
        'A job whose inventory is missing or empty fails.',
        'Deleting an inventory that job templates use is refused.',
        'Approval requests say what required them.',
        'Approval rules list the inventories they gate.',
        'API contract change.',
        'Fixes that change behaviour:',
        'Known limits.',
        'What an approval rule does not stop.',
        'Database and deployment.',
    ];

    /** The first comment line of each of the section's queries, in their order. */
    public const QUERIES = [
        '-- Queued jobs of job templates with an approval rule that were never approved',
        '-- Waiting jobs whose inventory is gone or names no hosts',
        '-- Job templates on inventory <inventory id>, with their own approval rule',
        '-- Queued jobs on inventory <inventory id> that were never approved',
        '-- Job templates whose own approval rule differs from the approval rule of their inventory',
        '-- Inventories whose creator differs from the user of their inventory.created entry',
        '-- Static inventories whose content may have been cut off at 65,535 bytes',
        '-- Changes of the last 30 days to job templates gated by their own rule or their inventory\'s,',
        '-- Job templates that wait for approval only through their inventory',
    ];

    /** The item each query stands under, by its place in QUERIES. */
    public const QUERY_ITEMS = [
        'Check before the update.',
        'Check before the update.',
        'Check before you give an inventory a rule.',
        'Check before you give an inventory a rule.',
        'One approval rule per launch.',
        'Fixes that change behaviour:',
        'Fixes that change behaviour:',
        'What an approval rule does not stop.',
        'Database and deployment.',
    ];

    /** The entries of docs/troubleshooting.md that 2.12.0 adds. */
    public const ENTRIES = [
        '## Job aborted before execution: the job was not approved',
        '## Job aborted before execution: the inventory is missing or empty',
        '## Saving an inventory is refused: it has an approval rule',
        '## Saving a job template is refused: its inventory has an approval rule',
        '## Deleting an inventory is refused',
        '## Launch refused: two approval rules meet',
        '## Approval rule form: nobody can approve',
    ];

    /** The YAML mappings the docs give for the localhost that the claim gave a job without hosts. */
    public const LOCALHOST_CONTENTS = [
        'all: {hosts: {localhost: }}',
        'all: {hosts: {localhost: {ansible_connection: local}}}',
    ];

    /**
     * Words that would turn the limits into a recipe: the paragraph on what
     * an approval rule does not stop names none of them.
     */
    private const RECIPE_MARKERS = ['ansible_host', 'ansible_connection', 'delegate_to', 'add_host', 'credential helper', 'Authorization'];

    /** What the docs said of inventory approvals before 2.12.0 made them. */
    private const PLANNED = [
        'of an inventory is planned',
        'until approvals at the level of an inventory come',
        'until inventory-level approvals',
        '**Inventory-level approvals** — approval rules at the level of an inventory',
    ];

    /** Where the migration of the release lives; its constants are the lines it prints. */
    private const MIGRATION = 'm000088_000000_add_inventory_approval_rules';

    /** The sentence every claim refusal ends with. */
    private const NOT_RUN = ' The job did not run: no credentials were decrypted or sent to the runner.';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
    }

    /**
     * The section is the newest, first of the update notes, and its anchor
     * is the one GitHub gives its heading, which the docs link to.
     */
    public function testTheSectionIsTheNewest(): void
    {
        preg_match_all('/^## After updating from .*$/m', $this->read('docs/updating.md'), $headings);
        $this->assertSame(self::UPDATE_SECTION, $headings[0][0] ?? null, 'The newest section comes first.');
        $this->assertSame(RunnerManagementDocsContractTest::UPDATE_SECTION, $headings[0][1] ?? null, 'The section of 2.11.1 follows.');
        $this->assertSame(
            self::SECTION_ANCHOR,
            str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 -]/', '', strtolower(substr(self::UPDATE_SECTION, 3)))),
            'The anchor GitHub gives the heading.'
        );
    }

    /**
     * The section has the items of the release in their order, its intro and
     * checklist say what changes and what to do, and every "see **...**"
     * leads to one of its items.
     */
    public function testTheItemsAreInOrderAndThePointersLeadToThem(): void
    {
        $this->assertSame(self::ITEMS, $this->itemTitles(), 'The items of the section, in order.');
        $section = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        preg_match_all('/see \*\*([^*]+)\*\*/', $section, $pointers);
        $this->assertGreaterThan(10, count($pointers[1]));
        foreach (array_unique($pointers[1]) as $title) {
            $this->assertTrue(
                in_array($title . '.', self::ITEMS, true) || in_array($title . ':', self::ITEMS, true),
                "The section points to **{$title}**, which it does not have."
            );
        }
        $intro = [
            'An admin can give an inventory an approval rule now: every launch of every job template that uses the inventory then waits for the rule\'s approvers',
            'A launch waits for one rule only, so a job template on such an inventory has no rule of its own, or the same rule',
            'A runner starts a job whose job template or inventory has an approval rule only once the job was approved, and a job whose inventory is gone or names no hosts fails before it runs',
            'The update has one migration, and runners need no update',
        ];
        foreach ($intro as $phrase) {
            $this->assertMentions($phrase, $section, 'The intro of the section');
        }
        $steps = [
            1 => 'Before the update, run the read-only queries under **Check before the update**. Let the jobs that the first query lists run first, or cancel them and launch them again after the update: a runner fails them before they run once the update is in place. Do not let the jobs that the second query lists run first, as they would run against the runner itself or with the default inventory of Ansible: give their inventories hosts or a source path, or cancel them.',
            2 => 'Before you give an inventory a rule, run the read-only queries under **Check before you give an inventory a rule**, and review the schedules, trigger tokens and workflow job steps they list',
            3 => 'To lock the hosts of a job template that has an approval rule of its own, give its inventory the same rule; you may then remove the template\'s own rule. Nothing launches ungated in between',
            4 => 'Tell the users who are not admins what is for admins now',
            5 => 'Tell the systems that use the API what changes',
            6 => 'Read **What an approval rule does not stop**, and decide who may create projects, and who may change the projects and credentials of gated work.',
        ];
        foreach ($steps as $number => $start) {
            $this->assertStringStartsWith($number . '. ' . $start, $this->checklist($number), "Step {$number} of the checklist");
        }
    }

    /**
     * Every ```sql block of the section is one of the nine queries, in
     * their order, each under its item.
     */
    public function testTheNineQueriesStandUnderTheirItems(): void
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', self::UPDATE_SECTION), $blocks);
        $this->assertCount(count(self::QUERIES), $blocks[1], 'The section has exactly nine queries.');
        foreach (self::QUERIES as $index => $comment) {
            $this->assertStringStartsWith($comment . "\n", $blocks[1][$index]);
            $this->assertStringContainsString(
                (string)preg_replace('/\s+/', ' ', $comment),
                $this->item(self::QUERY_ITEMS[$index]),
                "The query {$comment} stands under **" . self::QUERY_ITEMS[$index] . '**'
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function messageProvider(): array
    {
        $messages = [];
        foreach ([self::launchMessages(), self::claimMessages(), self::lockMessages(), self::usageMessages(), self::pageMessages(), self::stepMessages()] as $part) {
            $messages += $part;
        }

        return $messages;
    }

    /**
     * Every new text of the release is quoted, in backticks, as the code
     * words it.
     *
     * @dataProvider messageProvider
     * @param list<string> $docs
     */
    public function testEveryNewTextIsQuotedAsTheCodeWordsIt(string $message, array $docs): void
    {
        foreach ($docs as $doc) {
            $text = $doc === 'updating' ? $this->flat('docs/updating.md', self::UPDATE_SECTION) : $this->flat('docs/' . $doc . '.md');
            $this->assertMentions('`' . $message . '`', $text, "{$doc}.md");
        }
    }

    /**
     * The migration's lines are quoted in its words, without the indent it
     * prints them with.
     */
    public function testTheMigrationOutputIsQuotedInItsWords(): void
    {
        $deployment = $this->item('Database and deployment.');
        $this->assertMentions('One migration runs when the app container starts, `' . self::MIGRATION . '`.', $deployment, 'Database and deployment');
        $lines = [
            trim(sprintf($this->migrationText('MARKED'), 12)),
            trim(sprintf($this->migrationText('CLEARED'), 9, 3)),
            trim(sprintf($this->migrationText('RULES_REMOVED'), 1, '#3 (rule #5)')),
            trim(sprintf($this->migrationText('CONTENT_KEPT'), '#3')),
            trim(sprintf($this->migrationText('PARSED_CLEARED'), '#3')),
        ];
        foreach ($lines as $line) {
            $this->assertStringStartsWith('> ', $line);
            $this->assertMentions('`' . $line . '`', $deployment, 'Database and deployment');
        }
        $this->assertMentions('`docker compose exec app php yii migrate/down 1 --interactive=0`', $deployment, 'Database and deployment');
    }

    /**
     * REPOSITORY_HOSTS_NOTE, the limit of a gated file or dynamic inventory,
     * is quoted where the gated inventory is described and in its own item
     * among the limits.
     */
    public function testTheRepositoryHostsNoteIsQuotedWithTheLockAndAmongTheLimits(): void
    {
        $note = '`' . GatedInventoryLock::REPOSITORY_HOSTS_NOTE . '`';
        $this->assertMentions($note, $this->item('Only admins change a gated inventory.'), 'Only admins change a gated inventory');
        $this->assertMentions('This is a limit, see its item under **What an approval rule does not stop**.', $this->item('Only admins change a gated inventory.'), 'Only admins change a gated inventory');
        $bullet = $this->limit('- The hosts of a gated file or dynamic inventory come from its project\'s repository when a runner claims the job');
        $this->assertMentions($note, $bullet, 'The limit of a gated file or dynamic inventory');
        $this->assertMentions('Such a user is not the launcher of these jobs, so may approve them.', $bullet, 'The limit of a gated file or dynamic inventory');
    }

    /**
     * Every item of what an approval rule does not stop is a limit stated as
     * a fact: the paragraph names no recipe marker, and says what the plan
     * of the release names.
     */
    public function testTheLimitsAreFactsNotRecipes(): void
    {
        $limits = $this->item('What an approval rule does not stop.');
        foreach (self::RECIPE_MARKERS as $marker) {
            $this->assertStringNotContainsStringIgnoringCase($marker, $limits, "The limits name no recipe: {$marker}");
        }
        $expected = [
            '- A rule gates an inventory record, not hosts: inventories and job templates without a rule can reach the same hosts.',
            '- Which hosts a job reaches follows from its playbook and the extra vars of its launch as well as from its inventory.',
            '- Credentials have no team scope, so against a user determined to get around it, an approval rule is a procedural control rather than a barrier.',
            '- The content of the repository is read after the approval',
            '- The project and the credentials of gated work keep their own permissions, and so does the inventory of a job template with a rule of its own while the inventory has none.',
            'Whoever may create or change a project (`project.create`, `project.update`) can make its sync present any token or username and password credential to a server of their choosing, as credentials have no team scope. A later release locks these.',
            'Until then grant `project.create`, `project.update` and `credential.update` only to users you would let launch the gated work, choose approvers who may change neither the projects nor the credentials of that work',
            '- An admin\'s change between the approval and the claim reaches the job, another rule included: a runner checks that the job was approved, not under which rule',
            '- What was set up before the rule keeps its user',
            '- Who may approve is judged by the job template\'s project, for the rule of its inventory too',
            '- A rule whose timeout action is `approve` approves a job without a decision',
            '- A re-launch runs as the user who re-launches, who never approves it',
            '- The name and the description of a gated inventory or job template stay open to everyone who may change it',
            '- **Parse Inventory** is no launch',
            '- A launch waits for one rule: a job template on a gated inventory cannot add a sign-off of its own. Two sign-offs need a workflow with an approval step before the job step.',
            '- A rule changes nothing for jobs that already run',
            '- Whoever registers a runner or holds a runner\'s token receives the approved jobs of its runner group, with their credentials and hosts (see [Who manages runners](runners.md#'
                . RunnerManagementDocsContractTest::RUNNERS_ANCHOR . ')).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $limits, 'What an approval rule does not stop');
        }
    }

    /**
     * What the audit log shows for a job that the claim refuses, and that no
     * credential was decrypted or sent: as the claim records it
     * (JobClaimService writes job.started before the gate runs, and
     * JobCompletionService::failBeforeExecution() job.finished).
     */
    public function testTheAuditTrailOfARefusedJobIsDescribed(): void
    {
        $refusals = [
            JobClaimRefusedException::NOT_APPROVED,
            JobClaimRefusedException::INVENTORY_MISSING,
            JobClaimRefusedException::INVENTORY_NOT_RECORDED,
            JobClaimRefusedException::INVENTORY_EMPTY,
            JobClaimRefusedException::INVENTORY_NO_SOURCE,
        ];
        foreach ($refusals as $refusal) {
            $this->assertStringEndsWith(self::NOT_RUN, $refusal, 'Every claim refusal says what did not happen.');
        }
        $this->assertSame([ApprovalRequest::STATUS_APPROVED, ApprovalRequest::STATUS_TIMED_OUT], JobClaimGate::RUNNABLE_REQUEST_STATUSES);
        $this->assertStringContainsString("'reason' => 'pre_execution_failure',", $this->source('services/JobCompletionService.php'));
        $this->assertStringContainsString('AuditService::ACTION_JOB_STARTED', $this->source('services/JobClaimService.php'), 'The claim writes job.started.');
        $this->assertTrue(method_exists(JobClaimService::class, 'checkClaimable'));

        $item = $this->item('Runners start only approved jobs.');
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRIES[0]);
        $expected = [
            'updating.md' => [$item, [
                'What the audit log shows for such a job: `job.started`, written when the runner claimed it, which names the runner and the job\'s credentials as they were launched, and then `job.finished` with `reason: pre_execution_failure` and `claim_refusal` (`not_approved`)',
                'No credential was decrypted or sent to the runner.',
                'its approval request was approved, or timed out with the rule\'s `approve` action',
            ]],
            'troubleshooting.md' => [$entry, [
                'The audit log shows `job.started`, written when the runner claimed the job, which names the runner and the job\'s credentials as they were launched, and then `job.finished` with `claim_refusal: not_approved`',
                'No credential was decrypted or sent to the runner, and nothing ran on the target hosts.',
            ]],
        ];
        foreach ($expected as $where => [$text, $phrases]) {
            foreach ($phrases as $phrase) {
                $this->assertMentions($phrase, $text, $where);
            }
        }
        foreach (['inventory_missing', 'inventory_not_recorded', 'inventory_empty', 'inventory_no_source'] as $reason) {
            $this->assertContains($reason, [
                JobClaimRefusedException::REASON_INVENTORY_MISSING,
                JobClaimRefusedException::REASON_INVENTORY_NOT_RECORDED,
                JobClaimRefusedException::REASON_INVENTORY_EMPTY,
                JobClaimRefusedException::REASON_INVENTORY_NO_SOURCE,
            ]);
            $this->assertMentions('`' . $reason . '`', $this->item('A job whose inventory is missing or empty fails.'), 'updating.md');
            $this->assertMentions('`' . $reason . '`', $this->flat('docs/troubleshooting.md', self::ENTRIES[1]), 'troubleshooting.md');
        }
        $this->assertSame('not_approved', JobClaimRefusedException::REASON_NOT_APPROVED);
    }

    /**
     * The variables the docs name are those the approval notifications send
     * (ApprovalEffects: what required the request, and the inventory the job
     * was launched with), those the form of a notification template lists,
     * and the sample values of its test send.
     */
    public function testTheNotificationVariablesAreThoseTheCodeSends(): void
    {
        $effects = $this->source('services/ApprovalEffects.php');
        foreach (["'required_by' => (string)\$request->requiredBy()", "'inventory_id' => (string)\$inventory->id", "'inventory_name' => (string)\$inventory->name"] as $part) {
            $this->assertStringContainsString($part, $effects, 'The approval notifications send what the docs name.');
        }
        $item = $this->item('Approval requests say what required them.');
        foreach (['{{ approval.required_by }}', '{{ job.inventory_id }}', '{{ job.inventory_name }}'] as $variable) {
            $this->assertMentions('`' . $variable . '`', $item, 'updating.md');
        }
        $form = $this->source('views/notification-template/form.php');
        foreach (['{{ approval.required_by }}', '{{ job.inventory_name }}'] as $variable) {
            $this->assertStringContainsString($variable, $form, 'The form lists the variable.');
        }
        $this->assertMentions('fills them with the sample values `inventory`, `0` and `Production hosts`', $item, 'updating.md');
        // The sample values of the test send, wherever the code keeps them.
        $samples = '';
        foreach (['components/NotificationTestVariables.php', 'controllers/NotificationTemplateController.php'] as $path) {
            $samples .= is_file(dirname(__DIR__, 3) . '/' . $path) ? $this->source($path) : '';
        }
        foreach (["'approval.required_by' => 'inventory'", "'job.inventory_id' => '0'", "'job.inventory_name' => 'Production hosts'"] as $sample) {
            $this->assertStringContainsString($sample, $samples, 'The test send fills the variable with the value the docs name.');
        }
    }

    /**
     * The claim's localhost for a job without hosts is gone; the docs say
     * what replaces it, with contents that the inventory form and the API
     * take (InventoryApprovalBehaviourDocsContractTest validates them).
     */
    public function testTheLocalhostOfTheClaimIsReplacedAsTheDocsSay(): void
    {
        $this->assertStringNotContainsString('localhost', $this->source('services/JobClaimService.php'), 'The claim gives no job localhost any more.');
        $fix = $this->limitOf('Fixes that change behaviour:', '- A job whose inventory is gone, whose launch record names none');
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRIES[1]);
        foreach (['updating.md' => $fix, 'troubleshooting.md' => $entry] as $where => $text) {
            $this->assertMentions('no longer runs against the runner itself', $text, $where);
            $this->assertMentions('The inventory form and the API take new or changed content of a static inventory only as a YAML mapping', $text, $where);
            $this->assertMentions('`' . self::LOCALHOST_CONTENTS[0] . '` names the host `localhost` that the claim gave such a job', $text, $where);
            $this->assertMentions('`' . self::LOCALHOST_CONTENTS[1] . '` runs on the runner itself as the seeded selftest inventory does', $text, $where);
        }
    }

    /**
     * README.md says what only admins change, in its feature list and its
     * security list, and that a rule gates inventories.
     */
    public function testReadmeSaysWhatARuleOnAnInventoryGates(): void
    {
        $readme = $this->flat('README.md');
        $expected = [
            '- **Inventories** — static, file-based, and dynamic inventories parsed through real `ansible-inventory`; an admin can give one an approval rule, and every launch against it then waits for approval',
            '- **Approval workflows** — gate sensitive job templates, inventories and workflow steps behind named approval rules with per-rule approver lists; rules are managed and assigned by admins, who alone change the steps of workflows with an approval step or a job step whose template has a rule, the hosts of a static inventory with a rule and the source of a file or dynamic one, and what a job template with a rule runs (every field but its name and description); a rule on an inventory gates every job template that uses it, one rule per launch; nobody approves their own launch, runners start a gated job only once it is approved, and timeouts apply every minute. A rule gates job templates and inventories, not hosts or playbooks: whoever may create inventories and job templates can reach the same hosts through ones without a rule, and whoever may change the project or credentials of a template with a rule, or its inventory while the inventory has no rule, changes what its jobs run. An approval step gates the runs of its workflow, not the job templates in it: whoever may launch those templates can launch them directly, or from another workflow without the step',
            'and only admins change what a job template with an approval rule runs, and the hosts of a static inventory with one or the source of a file or dynamic one;',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $readme, 'README.md');
        }
        $this->assertStringNotContainsString('admins alone change the hosts of a file', $readme, 'A file or dynamic inventory takes its hosts from the repository.');
    }

    /**
     * CLAUDE.md names the gated inventory, its classes and its limits: the
     * domain language, the lock order, rules 11 and 12, the claim and the
     * data model, and the roadmap.
     */
    public function testClaudeMdNamesTheGatedInventoryAndItsLimits(): void
    {
        foreach ([GatedInventoryLock::class, GatedJobTemplateLock::class, JobTemplateGate::class, JobClaimGate::class, JobClaimService::class, InventoryWriteService::class] as $class) {
            $this->assertTrue(class_exists($class), "{$class}, which CLAUDE.md names, exists.");
        }
        $claude = $this->flat('CLAUDE.md');
        $expected = [
            '- **Gated Inventory**: an inventory with an approval rule; every launch of every job template that uses it waits for that rule; only admins (`approval-rule.update`) change it, every field but its name and description (`GatedInventoryLock`)',
            'records what required it (`required_by`: `job_template`, `inventory` or `approval_step`)',
            'the job_template row FOR UPDATE before the inventory rows LOCK IN SHARE MODE, read in one statement in ascending ID order',
            'a plaintext secret, a token, credentials, survey answers, static inventory content, the runner\'s claim payload or job output',
            'and a gated file or dynamic inventory takes its hosts from its project\'s repository when the job runs, so whoever may change that project or push to its repository changes them',
            'whoever may create or change a project can make its sync present any token or username and password credential to a server of their choosing, as credentials are not team-scoped',
            'for the rule of an inventory too, the job template\'s project counts.',
            '- The claim (`JobClaimService`, `JobClaimGate`) fails a job before execution, before any credential is decrypted, when its job template, the inventory it was launched with or the template\'s current inventory has an approval rule and the job was not approved',
            'the claim\'s job.started entry stays, and job.finished records `claim_refusal`; the claim payload a runner receives stays the same.',
            '- `inventory` — static, file, or dynamic host definitions; an optional approval rule (`approval_rule_id`) makes it a gated inventory',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $claude, 'CLAUDE.md');
        }
    }

    /**
     * The guides to workflows and credentials, and the runners' polling, say
     * what changed.
     */
    public function testTheOtherGuidesSayWhatChanged(): void
    {
        $workflows = $this->flat('docs/workflows.md');
        $expected = [
            '- **Gated job step**: a job step whose job template has an approval rule, its own or its inventory\'s.',
            'A gated job step whose job was queued before its job template or inventory got its rule never waited for an approval: a runner fails that job before it runs',
            'and the run goes on as after a failed job, with On Failure, or with Always where the step has one.',
            'Who may see the job follows the project of its job template, also when the rule is that of the job template\'s inventory',
            'The Rule column names the inventory of a request that the rule of a job template\'s inventory required, such as `'
                . sprintf(Texts::RULE_OF_INVENTORY, 'deploy-prod', 'web-prod') . '`',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $workflows, 'workflows.md');
        }
        $credentials = $this->flat('docs/credentials.md');
        $this->assertMentions(
            'A job template whose inventory has an approval rule, and which has none of its own, is reported with `' . GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY . '`',
            $this->flat('docs/credentials.md', '## Assigning a vault password to several job templates'),
            'credentials.md, on the vault assignment,'
        );
        $this->assertMentions('and so do job templates on an inventory with an approval rule', $this->flat('docs/credentials.md', '## Deleting a credential'), 'credentials.md');
        $this->assertMentions(
            'Credentials have no team scope. Whoever may create or change a project (`project.create`, `project.update`) can make its sync present any token or username and password credential to a server of their choosing, so grant `project.create` and `project.update` only to users you would trust with every such credential. A later release scopes credentials to teams',
            $this->flat('docs/credentials.md', '## RBAC'),
            'credentials.md, RBAC,'
        );
        $this->assertStringNotContainsString('credential helper', $credentials, 'The limit is stated without a recipe.');
        $this->assertMentions(
            'and it fails, before it runs, a job of a job template or inventory with an approval rule that was not approved, and a job whose inventory is missing or names no hosts',
            $this->flat('docs/runners.md', '## Network Requirements'),
            'runners.md'
        );
        // Regression (2.12.0 review, OPS-2): a claim that failed slow jobs
        // went on to hand out a job its runner no longer waited for.
        $this->assertMentions(
            'once such failures have taken ' . JobClaimService::CLAIM_BUDGET_SECONDS . ' seconds, a claim hands out no further job, and the runner asks again at its next poll',
            $this->flat('docs/runners.md', '## Network Requirements'),
            'runners.md'
        );
    }

    /**
     * The unit of the section that names the schema cache's flush also says
     * not to flush the whole cache, as the troubleshooting entry does; the
     * command exists.
     */
    public function testTheSchemaCacheFlushIsNamedWithItsWarning(): void
    {
        $this->assertTrue(method_exists(\yii\console\controllers\CacheController::class, 'actionFlushSchema'));
        $command = '`docker compose exec app php yii cache/flush-schema db --interactive=0`';
        $warning = 'Do not run `php yii cache/flush cache` instead: the cache shares its Redis database with the sessions and the queue, so that logs every user out and drops queued work.';
        $units = array_values(array_filter(
            $this->units('docs/updating.md', self::UPDATE_SECTION),
            static fn (string $unit): bool => str_contains($unit, 'cache/flush')
        ));
        $this->assertCount(1, $units, 'One unit of the section names the flush.');
        $this->assertMentions($command, $units[0], 'Database and deployment');
        $this->assertMentions($warning, $units[0], 'Database and deployment');
        $this->assertMentions('`' . sprintf(JobLaunchService::INVENTORY_CHANGED, 7, 3) . '`', $units[0], 'Database and deployment');
        $entry = array_values(array_filter(
            $this->units('docs/troubleshooting.md', '## Launch refused: the job template or its approval rule changed'),
            static fn (string $unit): bool => str_contains($unit, 'cache/flush')
        ));
        $this->assertCount(1, $entry);
        $this->assertMentions($command, $entry[0], 'troubleshooting.md');
        $this->assertMentions($warning, $entry[0], 'troubleshooting.md');
    }

    /**
     * The rollback says what 2.11.1 ignores and how to revert the
     * migration; the Rollback steps point to it.
     */
    public function testTheRollbackSaysWhatAnOlderVersionIgnores(): void
    {
        $deployment = $this->item('Database and deployment.');
        $expected = [
            'To roll back to 2.11.1, deploy it as [Rollback](#rollback) describes.',
            '2.11.1 and older ignore the approval rules of inventories: job templates gated only through their inventory launch without approval, runners start every queued job',
            'Give such job templates a rule of their own first',
            '2.11.1 runs on the schema this release leaves, and records `job_template` for the requests of workflow approval steps, as before.',
            'To revert the migration, before you deploy 2.11.1: `docker compose exec app php yii migrate/down 1 --interactive=0`.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $deployment, 'The rollback of the section');
        }
        $this->assertMentions(
            'To roll back from 2.12.0 (migration `m000088`), see **Database and deployment** under [After updating from 2.11.1 or older](#' . self::SECTION_ANCHOR
                . '): 2.11.1 runs on the schema 2.12.0 leaves, but ignores the approval rules of inventories, and cannot delete an approval rule that an inventory still names.',
            $this->flat('docs/updating.md', '## Rollback'),
            'The Rollback steps of updating.md'
        );
    }

    /**
     * Each new entry of troubleshooting.md says the symptom, the cause and
     * the fix; the entries that point to updating.md use the anchor of the
     * section.
     */
    public function testTheTroubleshootingEntriesHaveSymptomCauseAndFix(): void
    {
        foreach (self::ENTRIES as $entry) {
            $text = $this->flat('docs/troubleshooting.md', $entry);
            foreach (['**Symptom:**', '**Cause', '**Fix:**'] as $part) {
                $this->assertMentions($part, $text, "troubleshooting.md, {$entry},");
            }
        }
        $this->assertSame(
            1,
            preg_match_all('/^## Saving a job template is refused: it has an approval rule$/m', $this->read('docs/troubleshooting.md')),
            'The entry of a job template\'s own rule stays, so its links keep resolving.'
        );
        $this->assertMentions(
            '(updating.md#' . self::SECTION_ANCHOR . ')',
            $this->flat('docs/troubleshooting.md', self::ENTRIES[0]),
            'troubleshooting.md'
        );
    }

    /**
     * The curl example of the section calls the route that updates an
     * inventory, with a body whose fields the API reads, and a placeholder
     * for the token.
     */
    public function testTheApiExampleCallsTheRouteWithABodyTheApiTakes(): void
    {
        $item = $this->section('docs/updating.md', self::UPDATE_SECTION);
        $this->assertSame(1, preg_match('/^```bash\R(curl -X PUT .*?)^```/ms', $item, $block), 'The section shows a curl example.');
        $command = (string)preg_replace('/\\\\\R\s*/', ' ', $block[1] ?? '');
        $this->assertStringContainsString('-H "Authorization: Bearer <api token>"', $command, 'A placeholder, never a token.');
        $this->assertSame(1, preg_match("/-d '([^']*)'/", $command, $data));
        $this->assertSame(1, preg_match('#https://ansilume\.example\.com(/api/v1/inventories/\d+)\s*$#', trim($command), $url));
        $body = json_decode($data[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertSame([], array_diff(array_keys($body), InventoriesController::BODY_FIELDS), 'The API reads every field of the body.');
        $this->assertSame([], array_keys(array_filter($body, 'is_array')), 'No field holds a list or an object.');
        $this->assertIsInt($body[InventoryRuleAssignment::FIELD] ?? null, 'The body gives a rule ID.');
        $config = require dirname(__DIR__, 3) . '/config/web.php';
        $this->assertIsArray($config);
        $routes = [];
        foreach ($config['components']['urlManager']['rules'] ?? [] as $rule) {
            if (is_array($rule) && ($rule['verb'] ?? null) === 'PUT' && preg_match('#^/' . preg_replace('/<\w+:([^>]+)>/', '($1)', (string)$rule['pattern']) . '$#', $url[1] ?? '') === 1) {
                $routes[] = (string)$rule['route'];
            }
        }
        $this->assertSame(['api/v1/inventories/update'], $routes, 'The example calls the update of an inventory.');
    }

    /**
     * The fields the API contract change names are those of the spec and
     * of the answers.
     */
    public function testTheApiContractChangeNamesTheFieldsOfTheSpec(): void
    {
        $item = $this->item('API contract change.');
        $schemas = $this->schemas();
        $fields = [
            'Inventory' => ['approval_rule_id', 'approval_rule_name'],
            'JobTemplate' => ['approval_gate'],
            'Job' => ['inventory_id', 'inventory_name'],
            'ApprovalRequest' => ['required_by', 'inventory_id', 'inventory_name'],
            'ApprovalRuleUsage' => ['inventories', 'hidden_inventory_count'],
            'TriggerJobResult' => ['job_id', 'status'],
        ];
        foreach ($fields as $schema => $names) {
            foreach ($names as $name) {
                $this->assertArrayHasKey($name, $this->properties($schemas, $schema), "The spec's {$schema} has {$name}.");
                $this->assertMentions('`' . $name . '`', $item, 'The API contract change');
            }
        }
        $this->assertMentions('`InventoryDetail` in the spec', $item, 'The API contract change');
        $this->assertSame(['content', 'source_path'], $schemas['InventoryDetail']['allOf'][1]['required'] ?? null);
        $usage = array_keys((new InventoryUsage('web-prod', [], 0, 0))->toArray());
        $this->assertMentions('`error.used_by` (`' . implode('`, `', $usage) . '`)', $item, 'The API contract change');
        $this->assertSame(['queued', 'pending_approval'], $schemas['TriggerJobResult']['properties']['status']['enum'] ?? null);
        $this->assertSame(
            [JobTemplateGate::SOURCE_JOB_TEMPLATE, JobTemplateGate::SOURCE_INVENTORY],
            $schemas['JobTemplateApprovalGate']['properties']['required_by']['enum'] ?? null
        );
        $this->assertMentions(
            '`{"approval_rule_id": 3, "approval_rule_name": "deploy-prod", "required_by": "inventory"}`',
            $item,
            'The API contract change'
        );
        $this->assertSame(['approval_rule_id', 'approval_rule_name', 'required_by'], $schemas['JobTemplateApprovalGate']['required'] ?? null);
        $this->assertMentions('`{"job_id": 42, "status": "pending_approval"}`', $item, 'The API contract change');
    }

    /**
     * regression (2.12.0 review, API-2 and API-3): GET
     * /api/v1/inventories/{id} answers used_by, the job templates of the
     * card Job templates of the inventory's page (InventoryView, in the
     * response InventoryViewResponse), and the spec's response
     * InventoryResponse of 2.11.1 is named InventoryDetailResponse now; no
     * doc said either. The API contract change names both, and where a
     * delete is refused for the job templates that use the inventory, the
     * docs name used_by of the GET. Fails on the docs from before.
     */
    public function testRegressionTheApiContractChangeNamesUsedByAndTheRenamedResponses(): void
    {
        $spec = $this->spec();
        $responses = (array)($spec['components']['responses'] ?? []);
        $this->assertArrayNotHasKey('InventoryResponse', $responses, 'The response of 2.11.1 has another name now.');
        $answers = [
            ['/api/v1/inventories/{id}', 'get', '200', 'InventoryViewResponse'],
            ['/api/v1/inventories', 'post', '201', 'InventoryDetailResponse'],
            ['/api/v1/inventories/{id}', 'put', '200', 'InventoryDetailResponse'],
        ];
        foreach ($answers as [$path, $verb, $status, $response]) {
            $this->assertSame(['$ref' => '#/components/responses/' . $response], $spec['paths'][$path][$verb]['responses'][$status] ?? null, "{$verb} {$path} answers {$response}.");
            $this->assertArrayHasKey($response, $responses);
        }
        $this->assertSame(
            '#/components/schemas/InventoryView',
            $responses['InventoryViewResponse']['content']['application/json']['schema']['properties']['data']['$ref'] ?? null,
            'The GET answers InventoryView.'
        );
        $schemas = $this->schemas();
        $this->assertContains(['$ref' => '#/components/schemas/InventoryDetail'], (array)($schemas['InventoryView']['allOf'] ?? []), 'InventoryView is the detail ...');
        $this->assertSame('#/components/schemas/InventoryUsage', $this->properties($schemas, 'InventoryView')['used_by']['$ref'] ?? null, '... with used_by.');
        $this->assertArrayNotHasKey('used_by', $this->properties($schemas, 'InventoryDetail'), 'POST and PUT do not answer used_by.');

        $usage = array_keys((new InventoryUsage('web-prod', [], 0, 0))->toArray());
        $expected = [
            '`GET /api/v1/inventories/{id}` also has `used_by`, the job templates that use the inventory, as the card **Job templates** of its page lists them (`'
                . implode('`, `', $usage) . '`; `InventoryView` in the spec); the answers of `POST` and `PUT` do not.',
            'The response `InventoryResponse` of the spec is now `InventoryDetailResponse`, the answer of `POST` and `PUT`, and `GET /api/v1/inventories/{id}` answers `InventoryViewResponse`: a reference to `#/components/responses/InventoryResponse` needs the new name.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $this->item('API contract change.'), 'The API contract change');
        }
        $this->assertMentions(
            'Nothing is deleted. `GET /api/v1/inventories/{id}` lists the job templates that use the inventory as `used_by`, as its card does.',
            $this->item('Deleting an inventory that job templates use is refused.'),
            'updating.md'
        );
        $this->assertMentions(
            'the deleted ones; `GET /api/v1/inventories/{id}` answers the same as `used_by`.',
            $this->flat('docs/troubleshooting.md', self::ENTRIES[4]),
            'troubleshooting.md'
        );
    }

    /**
     * regression: the docs called an approval at the level of an inventory
     * planned, the spec too, and CLAUDE.md a limit until it comes and the
     * next step of its roadmap. 2.12.0 made it: no doc says so any more, and
     * the section of 2.10.0 points forward. Fails on the docs of 3007a3b
     * (v2.11.1), through a subclass whose root() reads copies of them.
     */
    public function testRegressionNoDocCallsInventoryApprovalsPlanned(): void
    {
        $files = array_merge(
            array_map(static fn (string $path): string => 'docs/' . basename($path), glob($this->root() . '/docs/*.md') ?: []),
            ['README.md', 'CLAUDE.md', 'web/openapi.yaml']
        );
        $this->assertContains('docs/updating.md', $files);
        $found = [];
        foreach ($files as $file) {
            $text = (string)preg_replace('/\s+/', ' ', $this->read($file));
            foreach (self::PLANNED as $phrase) {
                if (str_contains($text, $phrase)) {
                    $found[] = "{$file}: {$phrase}";
                }
            }
        }
        $this->assertSame([], $found, 'Inventory approvals are no longer planned.');
        $this->assertMentions(
            'Since 2.12.0 a rule can gate an inventory as well, see **What an approval rule does not stop** under [After updating from 2.11.1 or older](#' . self::SECTION_ANCHOR . ').',
            $this->flat('docs/updating.md', '## After updating from 2.9.0 or older: approvals and workflows'),
            'The section of 2.10.0'
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private static function launchMessages(): array
    {
        $both = ['updating', 'troubleshooting'];

        return [
            'two rules meet' => [sprintf(JobLaunchService::RULES_DIFFER, 7, 3), $both],
            'inventory rule missing' => [sprintf(JobLaunchService::INVENTORY_RULE_MISSING, 7, 3), $both],
            'inventory missing' => [sprintf(JobLaunchService::INVENTORY_MISSING, 7, 3), $both],
            'template inventory changed' => [sprintf(JobLaunchService::TEMPLATE_INVENTORY_CHANGED, 7), $both],
            'inventory rule changed' => [sprintf(JobLaunchService::INVENTORY_CHANGED, 7, 3), $both],
            'too few approvers' => [sprintf(ApproverEligibility::REFUSAL_JOB, SurveyDefinition::quote('deploy-prod'), 1, 0), $both],
            'workflow: two rules meet' => [sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULES_DIFFER, SurveyDefinition::quote('Deploy')), [...$both, 'workflows']],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private static function claimMessages(): array
    {
        $both = ['updating', 'troubleshooting'];
        $name = SurveyDefinition::quote('web-prod');

        return [
            'claim: not approved' => [sprintf(JobClaimRefusedException::NOT_APPROVED, sprintf(JobClaimRefusedException::GATE_INVENTORY, 3), 5), $both],
            'claim: its job template' => [sprintf(JobClaimRefusedException::GATE_JOB_TEMPLATE, 7), $both],
            'claim: inventory missing' => [sprintf(JobClaimRefusedException::INVENTORY_MISSING, 3), $both],
            'claim: no inventory recorded' => [JobClaimRefusedException::INVENTORY_NOT_RECORDED, $both],
            'claim: no content' => [sprintf(JobClaimRefusedException::INVENTORY_EMPTY, 3, $name), $both],
            'claim: no source path' => [sprintf(JobClaimRefusedException::INVENTORY_NO_SOURCE, 3, $name), $both],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private static function lockMessages(): array
    {
        $both = ['updating', 'troubleshooting'];
        $usage = new InventoryUsage('web-prod', [], 2, 1);

        return [
            'inventory: notice' => [GatedInventoryLock::NOTICE, $both],
            'inventory: change refused' => [GatedInventoryLock::CHANGE_REFUSED, $both],
            'inventory: delete refused' => [GatedInventoryLock::DELETE_REFUSED, $both],
            'inventory: repository hosts' => [GatedInventoryLock::REPOSITORY_HOSTS_NOTE, ['updating']],
            'inventory: form hint' => [InventoryRuleAssignment::FORM_HINT, ['updating']],
            'inventory: rules differ' => [sprintf(InventoryRuleAssignment::RULES_DIFFER, SurveyDefinition::quote('Deploy web')), $both],
            'inventory: rules differ, hidden' => [SurveyDefinition::quote('Deploy web') . ' and ' . sprintf(InventoryRuleAssignment::RULES_DIFFER_HIDDEN, 2), ['updating']],
            'inventory: in use' => [sprintf(InventoryWriteService::DELETE_IN_USE, $usage->summary()), $both],
            'inventory: source path' => [Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED, ['updating']],
            'inventory: unknown rule' => [Inventory::APPROVAL_RULE_NOT_FOUND, ['updating']],
            'inventory: a list' => [sprintf(ApiBodyValues::NOT_A_VALUE, 'project_id'), ['updating']],
            'inventory: assign refused' => [ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $both],
            'template: change refused' => [GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, $both],
            'template: notice' => [GatedJobTemplateLock::NOTICE_INVENTORY, $both],
            'template: gated inventory' => [GatedJobTemplateLock::GATED_INVENTORY_REFUSED, $both],
            'template: rules differ' => [sprintf(JobTemplateRuleAssignment::RULES_DIFFER, SurveyDefinition::quote('web-prod'), SurveyDefinition::quote('deploy-prod')), $both],
            'template: rule hint' => [sprintf(JobTemplateRuleAssignment::INVENTORY_RULE_HINT, SurveyDefinition::quote('web-prod'), SurveyDefinition::quote('deploy-prod')), ['updating']],
            'template: hidden inventories' => [JobTemplateInventoryOptions::GATED_INVENTORIES_HINT, $both],
            'template: option' => ['web-prod' . sprintf(JobTemplateInventoryOptions::GATED_OPTION_SUFFIX, 'deploy-prod'), ['updating']],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private static function usageMessages(): array
    {
        $both = ['updating', 'troubleshooting'];
        $rule = new ApprovalRuleUsage('deploy-prod', [], 2, [], 1, 0, [], 1);

        return [
            'rule: delete blocked' => [ApprovalRuleUsage::DELETE_BLOCKED, $both],
            'rule: not in use' => [ApprovalRuleUsage::NOT_IN_USE, ['updating']],
            'rule: hidden inventories' => [sprintf(ApprovalRuleUsage::HIDDEN_INVENTORIES, 2), $both],
            'rule: in use' => [sprintf(ApprovalRuleWriteService::DELETE_REFUSED, $rule->summary()), $both],
            'rule: not deleted' => [sprintf(ApprovalRuleWriteService::DELETE_REFUSED_UNLISTED, SurveyDefinition::quote('deploy-prod')), $both],
            'rule form: nobody' => [ApprovalRule::NO_ELIGIBLE_APPROVERS, $both],
            'inventory card: empty' => [InventoryUsage::CARD_EMPTY, ['updating']],
            'inventory card: hidden' => [sprintf(InventoryUsage::CARD_HIDDEN, 2), ['updating']],
            'inventory card: deleted' => [sprintf(InventoryUsage::CARD_DELETED, 1), ['updating']],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private static function pageMessages(): array
    {
        $messages = [
            'launch note' => [sprintf(JobTemplateGate::LAUNCH_NOTE, 'deploy-prod'), ['updating']],
            'launch note, inventory' => [sprintf(JobTemplateGate::LAUNCH_NOTE_INVENTORY, 'deploy-prod', 'web-prod'), ['updating']],
            'schedule note' => [sprintf(JobTemplateGate::SCHEDULE_NOTE, 'deploy-prod'), ['updating']],
            'schedule note, inventory' => [sprintf(JobTemplateGate::SCHEDULE_NOTE_INVENTORY, 'deploy-prod', 'web-prod'), ['updating']],
            'inventory badge' => [InventoryRuleAssignment::GATED_BADGE, ['updating']],
            'inventory badge title' => [sprintf(InventoryRuleAssignment::GATED_TITLE, 'deploy-prod'), ['updating']],
            'relaunch waits' => [sprintf(JobController::FLASH_RELAUNCHED_WAITING, 43), ['updating']],
            'relaunch' => [sprintf(JobController::FLASH_RELAUNCHED, 43), ['updating']],
            'rule of an inventory' => [sprintf(Texts::RULE_OF_INVENTORY, 'deploy-prod', 'web-prod'), ['updating', 'workflows']],
            'parse too large to keep' => [sprintf(InventoryService::PARSED_TOO_LARGE, 17000000), ['updating']],
        ];
        foreach (ApprovalRequest::REQUIRED_BY_LABELS as $value => $label) {
            $messages['required by: ' . $value] = [$label, ['updating']];
            $messages['required by, value: ' . $value] = [$value, ['updating']];
        }

        return $messages;
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private static function stepMessages(): array
    {
        $all = ['updating', 'troubleshooting', 'workflows'];

        return [
            'step: add refused' => [ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED, $all],
            'step: steps refused' => [ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED, $all],
            'step: badge title' => [sprintf(WorkflowStepsTable::GATED_INVENTORY_TITLE, 'deploy-prod'), ['updating', 'workflows']],
            'step: badge title, own rule' => [sprintf(WorkflowStepsTable::GATED_TITLE, 'deploy-prod'), ['workflows']],
            'step: hint' => [WorkflowTemplatePage::GATED_TEMPLATES_HINT, ['updating', 'workflows']],
            'step: help' => [WorkflowStepFormView::HELP_JOB, ['updating']],
            'step: rules differ' => [WorkflowStepsTable::TARGET_RULES_DIFFER, $all],
        ];
    }

    /**
     * The text of the constant $name of the migration, the sprintf pattern
     * of a line it prints.
     */
    private function migrationText(string $name): string
    {
        $text = (new \ReflectionClassConstant(self::MIGRATION, $name))->getValue();
        $this->assertIsString($text);

        return $text;
    }

    /**
     * The titles of the section's items, in order, with their closing
     * period or colon.
     *
     * @return list<string>
     */
    private function itemTitles(): array
    {
        $titles = [];
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if (preg_match('/^\*\*([^*]+[.:])\*\*/', $unit, $match) === 1) {
                $titles[] = $match[1];
            }
        }

        return $titles;
    }

    /**
     * The item of the section under the bold title $title (with its period
     * or colon): its paragraph and everything after it up to the next bold
     * title, on one line.
     */
    private function item(string $title): string
    {
        $item = [];
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if ($item === [] && !str_starts_with($unit, '**' . $title . '**')) {
                continue;
            }
            if ($item !== [] && preg_match('/^\*\*[^*]+[.:]\*\*/', $unit) === 1) {
                break;
            }
            $item[] = $unit;
        }
        $this->assertNotEmpty($item, "The section has no item {$title}");

        return implode(' ', $item);
    }

    /**
     * The list item of What an approval rule does not stop that starts with
     * $start.
     */
    private function limit(string $start): string
    {
        return $this->limitOf('What an approval rule does not stop.', $start);
    }

    /**
     * The unit of the item $title that starts with $start, such as one of
     * its list items.
     */
    private function limitOf(string $title, string $start): string
    {
        $found = false;
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            $found = $found || str_starts_with($unit, '**' . $title . '**');
            if ($found && str_starts_with($unit, $start)) {
                return $unit;
            }
        }
        $this->fail("The item {$title} has no unit {$start}");
    }

    /**
     * Step $number of the section's checklist, on one line.
     */
    private function checklist(int $number): string
    {
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if (str_starts_with($unit, $number . '. ')) {
                return $unit;
            }
        }
        $this->fail("The checklist of the section has no step {$number}.");
    }

    /**
     * The schemas of web/openapi.yaml.
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemas(): array
    {
        $schemas = $this->spec()['components']['schemas'] ?? null;
        $this->assertIsArray($schemas);

        return $schemas;
    }

    /**
     * web/openapi.yaml.
     *
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);

        return $spec;
    }

    /**
     * The properties of the schema $name, those of its allOf parts too.
     *
     * @param array<string, array<string, mixed>> $schemas
     * @return array<string, mixed>
     */
    private function properties(array $schemas, string $name): array
    {
        $schema = $schemas[$name] ?? [];
        $properties = (array)($schema['properties'] ?? []);
        foreach ((array)($schema['allOf'] ?? []) as $part) {
            $properties += (array)(is_array($part) ? ($part['properties'] ?? []) : []);
        }

        return $properties;
    }

    /**
     * The file $path of the repository, as it is, whatever root() says.
     */
    private function source(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }
}
