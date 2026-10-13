<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\models\AuditLog;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedJobTemplateLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What the operator docs say about job templates with an approval rule
 * (gated job templates) is what the code does: only admins change what
 * such a template runs, every field but its name and description; an
 * assigned rule leaves alone what was set before; and the inventory, the
 * project and the credentials of a gated template keep their own
 * permissions, a limit until approvals at the level of an inventory.
 *
 * Regressions from wave 8 of step 3 (decision 5, "only admins change"), each
 * test fails on the docs as they were before it: no doc named the lock, its
 * notice or its 403; none said that assigning a rule leaves the template's
 * content and the job steps that workflows added for it as they were, whose
 * jobs the user who added them may approve; none said that whoever may
 * change a gated template's inventory, project or credentials changes what
 * its jobs run and may approve them; and CLAUDE.md said without a limit
 * that the launcher is the user who chose what runs.
 *
 * Regressions from wave 9 of step 3, each fails on the docs as they were
 * before it: the docs said that everyone the lock stops still deletes the
 * template, which needs job-template.delete; no doc said that a forced
 * delete of a credential detaches it from gated templates; and CLAUDE.md's
 * roadmap did not list the inventory-level approvals that the docs call
 * planned.
 */
class GatedTemplateDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const LOCK = '**Only admins change what a gated job template runs.**';

    private const LIMITS = '**What an approval rule does not stop.**';

    private const ENTRY = '## Saving a job template is refused: it has an approval rule';

    /**
     * Regression (decision 5): only admins change what a gated job template
     * runs, every field but its name and description; the docs quote the
     * refusal and the notice as the code words them, and say what stays
     * open to everyone else.
     */
    public function testOnlyAdminsChangeWhatAGatedJobTemplateRuns(): void
    {
        $this->assertSame(['name', 'description'], GatedJobTemplateLock::OPEN_ATTRIBUTES, 'The docs say: every field but its name and description.');
        $lock = $this->paragraphs(self::LOCK);
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRY);
        foreach ([GatedJobTemplateLock::CHANGE_REFUSED, GatedJobTemplateLock::NOTICE] as $text) {
            $this->assertMentions('`' . $text . '`', $lock, 'updating.md');
            $this->assertMentions('`' . $text . '`', $entry, 'troubleshooting.md');
        }
        $this->assertMentions('Now only users with `approval-rule.update` (admins and superadmins) change what a gated job template runs: every field but its name and description', $lock, 'updating.md');
        $this->assertMentions('in the web UI and from `PUT /api/v1/job-templates/{id}`, and nothing is saved', $lock, 'updating.md');
        $this->assertMentions('A field sent with the value it has is no change', $lock, 'updating.md');
        $this->assertMentions('so a rule that an admin assigns at the same moment counts', $lock, 'updating.md');
        $this->assertMentions(
            'They still launch the template, generate and revoke its trigger token and clone it, and delete it where they hold `job-template.delete`, which the operator role does not grant. A clone keeps the rule',
            $lock,
            'updating.md'
        );
        $this->assertMentions('Admins see no notice and change everything as before.', $lock, 'updating.md');
        $this->assertMentions('A change of the rule itself is refused with `' . ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED . '` first', $entry, 'troubleshooting.md');
        $this->assertMentions('(an error page in the web UI, `403` from the API), and nothing is saved', $entry, 'troubleshooting.md');
        // Regression (wave 9 of step 3): the docs said that everyone the lock
        // stops still deletes the template, but that needs job-template.delete,
        // which operators do not hold (GatedTemplateBehaviourDocsContractTest).
        $this->assertMentions('Deleting it needs `job-template.delete`, as before, which the operator role does not grant.', $entry, 'troubleshooting.md');
        foreach (['clone it and delete it', 'clone it, delete it'] as $unconditional) {
            $this->assertStringNotContainsString($unconditional, $lock . ' ' . $entry, 'Deleting is not open to everyone the lock stops.');
        }

        $section = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions('only admins change what a job template with an approval rule runs, and the steps of a workflow', $section, 'The start of the section');
        $this->assertMentions('9. Tell them that only admins change what a job template with an approval rule runs, every field but its name and description', $section, 'The checklist of updating.md');
        $this->assertMentions(
            '`PUT /api/v1/job-templates/{id}` of a job template with an approval rule answers `403` for a change of any field but `name` and `description` without `approval-rule.update`',
            $section,
            'The API contract change of updating.md'
        );
        $this->assertMentions(
            'only admins change what a job template with an approval rule runs',
            $this->flat('README.md'),
            'README.md'
        );
        $this->assertMentions('and what a job template with a rule runs (every field but its name and description)', $this->flat('README.md'), 'README.md');
        // Every save of a job template asks the lock, also the assignment of
        // a vault password to several templates (OpenApiGatedTemplateContractTest).
        $this->assertMentions(
            'the assignment leaves it as it is and reports it as failed with `' . GatedJobTemplateLock::CHANGE_REFUSED . '`; the other templates are assigned',
            $this->flat('docs/credentials.md', '## Assigning a vault password to several job templates'),
            'credentials.md'
        );
    }

    /**
     * Regression (decision 5, and the wave-7 check's gap): assigning a rule
     * leaves the template's content and the job steps that workflows added
     * for it as they were; the job of such a step runs as whoever starts
     * the run, and the user who added the step may approve it.
     */
    public function testAssigningARuleLeavesAloneWhatWasSetBefore(): void
    {
        $lock = $this->paragraphs(self::LOCK);
        $this->assertMentions('Assigning a rule leaves alone what was set before.', $lock, 'updating.md');
        $this->assertMentions(
            'and so do the job steps that workflows got for the template while it had no rule',
            $lock,
            'updating.md'
        );
        $this->assertMentions(
            'but the job of such a step runs as the user who starts the run, or as the user its trigger runs as, and the user who added the step may approve it',
            $lock,
            'updating.md'
        );
        $this->assertMentions('lists the job steps of live workflows whose job template has an approval rule', $lock, 'updating.md');
        $this->assertSame(1, preg_match('/^-- Job steps of live workflows whose job template has an approval rule$/m', $this->section('docs/updating.md', self::UPDATE_SECTION)));
        $this->assertMentions(
            'Such a step stays as it was added: its job runs as the user who starts the run, and the user who added it may approve it',
            $this->flat('docs/troubleshooting.md', '## Changing workflow steps is refused'),
            'troubleshooting.md'
        );
        $this->assertMentions(
            'A job step that a workflow got for a job template before the template got its approval rule stays',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The known limits of updating.md'
        );
    }

    /**
     * Regression (decision 5): the inventory, the project and the
     * credentials of a gated job template keep their own permissions;
     * whoever changes them changes what its jobs run and may approve them,
     * until approvals at the level of an inventory. The docs state the limit
     * and the mitigation, and give an audit query.
     */
    public function testTheLimitOfTheInventoryProjectAndCredentialsIsDescribed(): void
    {
        $limits = $this->paragraphs(self::LIMITS);
        $this->assertMentions('but not what it refers to: its inventory, its project and its credentials keep their own permissions', $limits, 'updating.md');
        $this->assertMentions(
            'Such a change does not make them the launcher of these jobs, so they may approve them when they are on the rule\'s approver list. This stays so until approvals at the level of an inventory come.',
            $limits,
            'updating.md'
        );
        $this->assertMentions('`inventory.update` and `project.update`', $limits, 'updating.md, on who holds the rights,');
        $this->assertMentions('`credential.update` for every credential', $limits, 'updating.md, on who holds the rights,');
        $this->assertMentions('choose the approvers of a rule among admins and users who may not change the inventory, project and credentials of its templates', $limits, 'updating.md');
        $this->assertMentions('Pushes to a repository happen outside Ansilume and are not in its audit log', $limits, 'updating.md');
        $this->assertSame(1, preg_match('/^-- Changes of the last 30 days to gated job templates and to the$/m', $this->section('docs/updating.md', self::UPDATE_SECTION)));
        $this->assertMentions(
            'Users who may change the inventory, the project or the credentials of a gated job template, or push to its repository, change what its next jobs run without becoming their launcher, and may approve them',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The known limits of updating.md'
        );

        // Regression (wave 9 of step 3): a forced delete of a credential
        // detaches it from every job template, gated ones included (the
        // foreign keys of job_template.credential_id and of
        // job_template_credential), outside the lock; no doc said so.
        $this->assertMentions(
            'and users who delete a credential that the template uses: deleting a credential that is in use, with **Delete anyway** on its page or `DELETE /api/v1/credentials/{id}?force=1`, detaches it from every job template that uses it, gated ones included, whose next jobs then run without it; that needs `credential.delete`, which only admins hold by default. Such a change does not make them the launcher of these jobs',
            $limits,
            'updating.md'
        );
        $this->assertMentions('keep `credential.delete` with the admins', $limits, 'updating.md, in the mitigation,');
        $this->assertMentions(
            'decide who may change the inventories, projects and credentials they use, and who may delete credentials',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The checklist of updating.md'
        );
        $this->assertMentions(
            'A forced delete of a credential is not among them: its `' . AuditLog::ACTION_CREDENTIAL_DELETED . '` audit entry records `forced` and the number of job templates it was detached from (`job_templates`), not which.',
            $limits,
            'updating.md'
        );
        $this->assertMentions(
            'So may users who delete one of its credentials with force (`credential.delete`, admins by default), which detaches it from the template.',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The known limits of updating.md'
        );
        $this->assertMentions(
            'Job templates with an approval rule lose the credential too, although only admins change what they run otherwise',
            $this->flat('docs/credentials.md', '## Deleting a credential'),
            'credentials.md'
        );
        $this->assertStringContainsString(
            'Job templates with an approval rule lose it too, although only admins (`approval-rule.update`) change what they run otherwise; `credential.delete` is for admins by default.',
            $this->specText(['paths', '/api/v1/credentials/{id}', 'delete', 'description'])
        );

        $this->assertMentions(
            'and whoever may change the inventory, project or credentials of a template with a rule changes what its jobs run',
            $this->flat('README.md'),
            'README.md'
        );
        $tag = $this->approvalRulesTag();
        $this->assertStringContainsString('Changing what a job template with a rule runs, any field but its name and description, needs `approval-rule.update` too', $tag);
        $this->assertStringContainsString('The inventory, the project and the credentials of a job template with a rule keep their own permissions', $tag);
    }

    /**
     * Regression (decision 5, and the wave-7 check's note on rule 12):
     * CLAUDE.md names the lock in the current state, the domain language
     * and security rule 12, and says how far "the launcher is the user who
     * chose what runs" holds.
     */
    public function testClaudeMdNamesTheLockAndItsLimit(): void
    {
        $claude = $this->flat('CLAUDE.md');
        $this->assertTrue(class_exists(GatedJobTemplateLock::class), 'The class CLAUDE.md names exists.');
        $this->assertMentions('admins manage and assign rules, change what a gated job template runs, and change the steps of workflows', $claude, 'The current state of CLAUDE.md');
        $this->assertMentions(
            '**Gated Job Template**: a job template with an approval rule; only admins (`approval-rule.update`) change what it runs, every field but its name and description (`GatedJobTemplateLock`)',
            $claude,
            'The domain language of CLAUDE.md'
        );
        $this->assertMentions('change what a gated job template (a job template with a rule) runs, every field but its name and description', $claude, 'Rule 12 of CLAUDE.md');
        $this->assertMentions('The launcher is the user who chose what runs as far as Ansilume controls it', $claude, 'Rule 12 of CLAUDE.md');
        $this->assertMentions('a documented limit until inventory-level approvals', $claude, 'Rule 12 of CLAUDE.md');
        $this->assertStringNotContainsString('and the launcher is the user who chose what runs (a schedule runs as', $claude, 'Rule 12 no longer claims it without a limit.');
        // Regression (wave 9 of step 3): rule 12 and the docs call
        // inventory-level approvals planned, but the roadmap did not list them.
        $this->assertMentions(
            '3. **Inventory-level approvals** — approval rules at the level of an inventory, so that an ungated twin of a gated job template, and changes to the inventory, project or credentials it uses, no longer get past an approval',
            $this->flat('CLAUDE.md', '### Next'),
            'The roadmap of CLAUDE.md'
        );
    }

    /**
     * The units of the section from the one that starts with $title up to
     * the next bold title, on one line.
     */
    private function paragraphs(string $title): string
    {
        $text = '';
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if ($text === '' && !str_starts_with($unit, $title)) {
                continue;
            }
            if ($text !== '' && preg_match('/^\*\*[^*]+[.:]\*\*/', $unit) === 1) {
                break;
            }
            $text .= ' ' . $unit;
        }
        $this->assertNotSame('', $text, "updating.md has no paragraph {$title}");

        return trim($text);
    }

    /**
     * The description of the spec's tag Approval Rules, on one line.
     */
    private function approvalRulesTag(): string
    {
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $tags = array_column(is_array($spec['tags'] ?? null) ? $spec['tags'] : [], 'description', 'name');

        return (string)preg_replace('/\s+/', ' ', (string)($tags['Approval Rules'] ?? ''));
    }

    /**
     * The text at $keys of the spec, on one line.
     *
     * @param list<string> $keys
     */
    private function specText(array $keys): string
    {
        $node = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        foreach ($keys as $key) {
            $this->assertIsArray($node);
            $this->assertArrayHasKey($key, $node, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
            $node = $node[$key];
        }
        $this->assertIsString($node);

        return trim((string)preg_replace('/\s+/', ' ', $node));
    }
}
