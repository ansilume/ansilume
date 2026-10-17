<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\models\AuditLog;
use app\models\WorkflowStep;
use app\services\WorkflowStepReorderService;
use app\services\WorkflowTemplatePage;
use PHPUnit\Framework\TestCase;
use yii\validators\NumberValidator;

/**
 * What the operator docs say about the order of workflow steps is what the
 * code does: docs/updating.md's section for the update from 2.10.0 and
 * docs/troubleshooting.md quote the Add Step form's texts as the code words
 * them, name the renumbering the code does and the audit entry of a move,
 * compare step types in their query as runs do, and the section's pointers
 * and links lead to their paragraphs.
 *
 * Regression (patch 2.10.1): from 2.3.15 to 2.10.0 a step added with the
 * Step Order that the Add Step form filled in went to the top of its
 * workflow, also in front of an approval step, and no doc said how the
 * steps are ordered, how to find such workflows or how to fix them. These
 * tests fail on the docs from before.
 *
 * Since 2.11.0 the section is history: positions replaced the Step Order
 * field and its hint (WorkflowStepsUpdateDocsContractTest), so the section
 * keeps quoting them as 2.10.1 worded them, and these tests quote the same
 * literals; the troubleshooting entry describes positions.
 */
class WorkflowStepOrderDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    /** The section of docs/updating.md; WorkflowStepOrderQueryDocsContractTest runs its query. */
    public const UPDATE_SECTION = '## After updating from 2.10.0 or older: new workflow steps go to the end';

    private const ENTRY = '## Workflow steps run in the wrong order';

    /** The anchor GitHub gives the section. */
    private const SECTION_ANCHOR = 'after-updating-from-2100-or-older-new-workflow-steps-go-to-the-end';

    /** The releases that put a step added with the form's Step Order at the top. */
    private const AFFECTED = 'From 2.3.15 to 2.10.0';

    /** The placeholder of the Step Order field in 2.10.1, which 2.11.0 replaced with Position. */
    private const PLACEHOLDER_2101 = 'At the end';

    /** The hint below the steps in 2.10.1, as the section quotes it. */
    private const HINT_2101 = 'Leave Step Order empty to add the step at the end. To put a step between two others, '
        . 'enter the number of the step it should follow: steps are renumbered 10, 20, 30… after every add and '
        . 'move. Use ▲ / ▼ to move a step.';

    public function testTheSectionFollowsTheOneOf2110(): void
    {
        preg_match_all('/^## After updating from .*$/m', $this->read('docs/updating.md'), $headings);
        $this->assertSame(InventoryApprovalDocsContractTest::UPDATE_SECTION, $headings[0][0] ?? null, 'The newest section comes first.');
        $this->assertSame(RunnerManagementDocsContractTest::UPDATE_SECTION, $headings[0][1] ?? null, 'The section of 2.11.1 follows.');
        $this->assertSame(WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION, $headings[0][2] ?? null, 'The section of 2.11.0 follows.');
        $this->assertSame(self::UPDATE_SECTION, $headings[0][3] ?? null, 'This section comes next.');
        $this->assertSame(
            self::SECTION_ANCHOR,
            str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 -]/', '', strtolower(substr(self::UPDATE_SECTION, 3)))),
            'The anchor GitHub gives the heading.'
        );
    }

    public function testTheChecklistStartsWithTheQuery(): void
    {
        $section = $this->section('docs/updating.md', self::UPDATE_SECTION);
        $this->assertSame(1, preg_match('/^1\. (.*?)\R(?:\S|\R)/ms', $section, $first));
        $this->assertStringStartsWith(
            'Run the read-only query under **Check your workflows**, before or after the update',
            (string)preg_replace('/\s+/', ' ', $first[1] ?? '')
        );
        $this->assertMentions('**Check your workflows.**', $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md');
    }

    /**
     * Every "(see **...**)" of the section leads to a paragraph of it.
     */
    public function testEveryPointerOfTheSectionLeadsToOneOfItsParagraphs(): void
    {
        $section = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        preg_match_all('/see \*\*([^*]+)\*\*/', $section, $pointers);
        $this->assertCount(3, array_unique($pointers[1]));
        foreach (array_unique($pointers[1]) as $title) {
            $this->assertMentions('**' . $title . '.**', $section, "updating.md, pointed to as **{$title}**,");
        }
    }

    /**
     * The section quotes the placeholder and the hint of the Add Step form
     * as 2.10.1 worded them. 2.11.0 replaced the field with Position: the
     * form has no Step Order field, the page no such constants, and the
     * troubleshooting entry no longer tells to leave the field empty.
     */
    public function testTheSectionQuotesTheFormAs2101WordedIt(): void
    {
        $section = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions(
            'The Step Order field of the Add Step form is empty now, with the placeholder `' . self::PLACEHOLDER_2101 . '`',
            $section,
            'updating.md'
        );
        $this->assertMentions('The hint below the steps reads `' . self::HINT_2101 . '`', $section, 'updating.md');
        // The form is code: read from the repository, whatever root() says.
        $form = (string)file_get_contents(dirname(__DIR__, 3) . '/views/workflow-template/_step-form.php');
        $this->assertStringNotContainsString('step_order', $form, 'The step form has no Step Order field.');
        $this->assertStringContainsString('WorkflowStepFormView::POSITION_LABEL', $form, 'The step form has a Position field.');
        foreach (['STEP_ORDER_PLACEHOLDER', 'STEP_ORDER_HINT'] as $name) {
            $this->assertFalse(defined(WorkflowTemplatePage::class . '::' . $name), "{$name} is gone.");
        }
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRY);
        $this->assertStringNotContainsString('leave Step Order empty', $entry, 'troubleshooting.md advises no field that is gone.');
    }

    /**
     * The numbers the docs say Step Order takes are the ones the step's
     * rule takes: none below 0, none above what the column holds.
     */
    public function testTheLimitsOfStepOrderAreTheRulesOfTheStep(): void
    {
        $limits = [];
        foreach ((new WorkflowStep())->getActiveValidators('step_order') as $validator) {
            if ($validator instanceof NumberValidator) {
                $limits[] = [$validator->integerOnly, $validator->min, $validator->max];
            }
        }
        $this->assertSame([[true, 0, WorkflowStep::MAX_STEP_ORDER]], $limits);
        $section = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions('with the placeholder `' . self::PLACEHOLDER_2101 . '`, and takes no number below 0.', $section, 'updating.md');
        $this->assertMentions(
            sprintf('An emptied Step Order field, and a number above %d, ended in a server error (`500`) before; such a number is refused now.', WorkflowStep::MAX_STEP_ORDER),
            $section,
            'updating.md'
        );
    }

    /**
     * The docs name the renumbering the code does after every add and
     * move, as the hint does, the releases that had the bug, and what it
     * did to steps added one after the other.
     */
    public function testTheRenumberingAndTheReleasesAreNamed(): void
    {
        $step = WorkflowStepReorderService::ORDER_STEP;
        $renumbered = sprintf('%d, %d, %d…', $step, 2 * $step, 3 * $step);
        $this->assertStringContainsString('steps are renumbered ' . $renumbered . ' after every add and move', self::HINT_2101);
        $section = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions("every step added or moved on a workflow's page renumbers the workflow's steps {$renumbered}", $section, 'updating.md');
        $this->assertMentions(
            sprintf('That number sorts before %d, so a step added without changing Step Order became the first step of the workflow', $step),
            $section,
            'updating.md'
        );
        $this->assertMentions('Build, Test and Deploy, added one after the other this way, ran as Deploy, Test, Build.', $section, 'updating.md');
        $this->assertMentions(self::AFFECTED . ', a step added with the Step Order that the Add Step form filled in went to the top instead', $section, 'updating.md');
        $this->assertMentions('its job ran without that approval, whatever the decision', $section, 'updating.md');
        $this->assertMentions(
            self::AFFECTED . ', a step added with the Step Order that the Add Step form filled in went to the top, not to the end',
            $this->flat('docs/troubleshooting.md', self::ENTRY),
            'troubleshooting.md'
        );
    }

    /**
     * The query compares the step types exactly, as runs do, with the
     * types of the code, and leaves deleted workflows out.
     */
    public function testTheQueryComparesTheStepTypesAsRunsDo(): void
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', self::UPDATE_SECTION), $blocks);
        $this->assertCount(1, $blocks[1]);
        $sql = (string)preg_replace('/\s+/', ' ', $blocks[1][0]);
        foreach (['a' => WorkflowStep::TYPE_APPROVAL, 's' => WorkflowStep::TYPE_JOB, 'b' => WorkflowStep::TYPE_APPROVAL] as $alias => $type) {
            $this->assertStringContainsString("CAST({$alias}.step_type AS BINARY) = '{$type}'", $sql);
        }
        $this->assertStringContainsString('WHERE w.deleted_at IS NULL', $sql);
        $this->assertStringContainsString('s.id > a.id AS added_after_approval_step', $sql);
    }

    /**
     * How to fix the order: the moves, named as the audit log names them,
     * and the refusals of a move, in the troubleshooting entry it links to,
     * which names the moves of 2.11.0.
     */
    public function testTheFixNamesTheMovesAndWhereTheirRefusalsAre(): void
    {
        $fix = $this->paragraph('**Fix the order.**');
        $this->assertStringContainsString('Use ▲ / ▼ to move a step.', self::HINT_2101);
        $this->assertMentions('move a step with ▲ / ▼, one place per click; each move is audited as `' . AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED . '`', $fix, 'updating.md');
        $this->assertMentions('[troubleshooting.md](troubleshooting.md#changing-workflow-steps-is-refused)', $fix, 'updating.md');
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRY);
        $this->assertMentions('move the steps with ▲ / ▼ or **Move to…** until the table lists them in the order they should run', $entry, 'troubleshooting.md');
        $this->assertMentions('[Changing workflow steps is refused](#changing-workflow-steps-is-refused)', $entry, 'troubleshooting.md');
    }

    /**
     * The troubleshooting entry leads to the section, for the bug and for
     * its query.
     */
    public function testTheTroubleshootingEntryLeadsToTheSection(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRY);
        $link = '[updating.md](updating.md#' . self::SECTION_ANCHOR . ')';
        $this->assertSame(2, substr_count($entry, $link), 'The entry links to the section for the bug and for the query.');
        $this->assertMentions('The query under **Check your workflows** in ' . $link, $entry, 'troubleshooting.md');
    }

    /**
     * The paragraph of the section that starts with the bold title $title.
     */
    private function paragraph(string $title): string
    {
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if (str_starts_with($unit, $title)) {
                return $unit;
            }
        }
        $this->fail("updating.md has no paragraph {$title}");
    }
}
