<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ScheduleRunAs;
use PHPUnit\Framework\TestCase;

/**
 * What the operator docs say about the user a launch runs as, who is the
 * launcher of its jobs and never approves them, is what the code does:
 * schedules run as the user who last changed what or when they run, or
 * enabled them; only admins add or change gated job steps; and a trigger
 * token generated before Ansilume recorded who generated it runs as the
 * template's creator.
 *
 * Regressions from the last review round of step 3 (decision 4, "the editor
 * becomes the launcher"), each test fails on the docs as they were before:
 * the docs said that a schedule runs as its creator and that editing it
 * keeps that user, named no refusal of gated job steps, and did not say that
 * the creator of a template counts as the launcher of the jobs of its older
 * trigger token.
 */
class RunAsDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const SCHEDULES = '**Schedules run as the user who last changed what or when they run.**';

    private const GATED_JOB_STEPS = '**Gated job steps are for admins too.**';

    /**
     * What decides what or when a schedule runs, besides its extra vars, in
     * the words of the docs (ScheduleRunAs::ARMING_ATTRIBUTES).
     */
    private const ARMING_WORDS = [
        'job_template_id' => 'job template',
        'cron_expression' => 'cron expression',
        'timezone' => 'timezone',
    ];

    /**
     * Regression (decision 4): a schedule runs as the user who last changed
     * what or when it runs, or enabled it; the docs name what counts, what
     * keeps the user, the audit context, the label of the web UI, and that
     * this replaces the rule of 2.8.1.
     */
    public function testSchedulesRunAsTheUserWhoLastChangedWhatOrWhenTheyRun(): void
    {
        $arming = (new \ReflectionClassConstant(ScheduleRunAs::class, 'ARMING_ATTRIBUTES'))->getValue();
        $this->assertSame(array_keys(self::ARMING_WORDS), $arming, 'The docs name every attribute that decides what or when a schedule runs.');
        $schedules = $this->paragraphs(self::SCHEDULES);
        $this->assertMentions(
            'a change of its ' . implode(', ', self::ARMING_WORDS) . ' or extra vars, in the schedule form or with `PUT /api/v1/schedules/{id}`',
            $schedules,
            'updating.md'
        );
        $this->assertMentions('a password answer entered anew or removed counts, `$encrypted$` sent back for a stored answer does not', $schedules, 'updating.md');
        $this->assertMentions('enabling a disabled schedule, with **Enable** on the schedule list or page', $schedules, 'updating.md');
        $this->assertMentions('Renaming a schedule, disabling it, and a save that changes none of these keep the user it runs as.', $schedules, 'updating.md');
        $this->assertMentions(
            'The `' . AuditLog::ACTION_SCHEDULE_UPDATED . '` and `' . AuditLog::ACTION_SCHEDULE_TOGGLED . '` audit entries record `'
                . ScheduleRunAs::AUDIT_KEY . '` with `from` and `to` when the user changes.',
            $schedules,
            'updating.md'
        );
        $this->assertMentions('This replaces the rule of 2.8.1 that editing a schedule keeps its creator', $schedules, 'updating.md');
        $this->assertMentions('`' . ScheduleRunAs::VIEW_NOTE . '`', $schedules, 'updating.md, quoting the note of the schedule page,');
        $this->assertMentions('`' . ScheduleRunAs::FORM_NOTE . '`', $schedules, 'updating.md, quoting the note of the edit form,');

        // The label as the schedule page and the list show it (views are code: read from the repository).
        foreach (['views/schedule/view.php', 'views/schedule/index.php'] as $view) {
            $this->assertStringContainsString('>Runs as</', $this->code($view), "{$view} labels the user as the docs say.");
        }
        $this->assertMentions('The schedule page and the schedule list show that user as **Runs as**', $schedules, 'updating.md');
        $this->assertMentions('under **Runs as**', $this->flat('docs/troubleshooting.md', '## Schedule did not launch (schedule.launch.denied)'), 'troubleshooting.md');
    }

    /**
     * Regression (decision 4): no doc says any more that a schedule runs as
     * its creator for good, or that editing it keeps that user; they name
     * the user it runs as instead.
     */
    public function testNoDocSaysThatEditingAScheduleKeepsItsCreator(): void
    {
        $stale = [
            'docs/troubleshooting.md' => ['the creator of a schedule cannot be changed', 'editing the schedule keeps it', '**Created by**', 'the creator of a schedule, the user a trigger runs as'],
            'README.md' => ["the schedule's creator, the user who generated the trigger token"],
            'CLAUDE.md' => ['schedules (run as their creator)'],
        ];
        foreach ($stale as $file => $phrases) {
            foreach ($phrases as $phrase) {
                $this->assertStringNotContainsString($phrase, $this->flat($file), "{$file} still says: {$phrase}");
            }
        }
        $current = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertStringNotContainsString('the creator of a schedule, the user a trigger runs as', $current);
        $this->assertMentions('the user a schedule runs as (see ' . substr(self::SCHEDULES, 0, -3) . '**)', $current, 'updating.md, on the launcher,');
        $this->assertMentions('for a schedule, the user who last changed what or when it runs, or enabled it', $this->flat('README.md'), 'README.md');
        $this->assertMentions('runs as the user who last changed what or when it runs, or enabled it ("Runs as", `created_by`)', $this->flat('CLAUDE.md'), 'CLAUDE.md');
        $this->assertMentions(
            'the user a schedule runs as (who last changed what or when it runs, or enabled it)',
            $this->flat('docs/troubleshooting.md', '## Launch refused: too few approvers besides the launcher'),
            'troubleshooting.md'
        );
    }

    /**
     * The update changes no schedule's user; the docs say so and give a
     * query for the schedules other users changed, with the values the code
     * writes.
     */
    public function testTheScheduleQueryUsesTheValuesTheCodeWrites(): void
    {
        $schedules = $this->paragraphs(self::SCHEDULES);
        $this->assertMentions("The update changes no schedule's user.", $schedules, 'updating.md');
        $this->assertSame(1, preg_match('/^-- Schedules of gated job templates that users other than the one they\R(.*?)^```/ms', $this->section('docs/updating.md', self::UPDATE_SECTION), $query));
        $sql = (string)preg_replace('/\s+/', ' ', $query[1] ?? '');
        $this->assertStringContainsString(
            "a.action IN ('" . AuditLog::ACTION_SCHEDULE_UPDATED . "', '" . AuditLog::ACTION_SCHEDULE_TOGGLED . "')",
            $sql
        );
        $this->assertStringContainsString("a.object_type = 'schedule'", $sql);
        $this->assertMentions(
            '7. Check the schedules that the query under ' . substr(self::SCHEDULES, 0, -3) . '** lists',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The checklist of updating.md'
        );
    }

    /**
     * Regression (decision 4): a gated job step, a job step whose job
     * template has an approval rule, is for admins as an approval step is;
     * the docs quote both refusals as the code words them.
     */
    public function testGatedJobStepsAreDocumentedAsForAdmins(): void
    {
        $refusals = [ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED];
        $gated = $this->paragraphs(self::GATED_JOB_STEPS);
        $troubleshooting = $this->flat('docs/troubleshooting.md', '## Changing workflow steps is refused');
        foreach ($refusals as $refusal) {
            $this->assertMentions('`' . $refusal . '`', $gated, 'updating.md');
            $this->assertMentions('`' . $refusal . '`', $troubleshooting, 'troubleshooting.md');
        }
        $this->assertMentions('a job template that gets an approval rule later locks the steps of the workflows that use it', $gated, 'updating.md');
        $this->assertMentions('The add-step form offers them only job templates without an approval rule', $gated, 'updating.md');
        $this->assertMentions(
            '8. Tell the users who are not admins that only admins change the steps of a workflow with a job step whose job template has an approval rule',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The checklist of updating.md'
        );
        $this->assertMentions('who alone change the steps of workflows with an approval step or a job step whose template has a rule', $this->flat('README.md'), 'README.md');
        $this->assertMentions('change the steps of a workflow with an approval step or a gated job step', $this->flat('CLAUDE.md'), 'CLAUDE.md');
    }

    /**
     * A trigger token generated before Ansilume recorded who generated it
     * runs as the template's creator (the code's fallback), who therefore
     * counts as the launcher of its jobs; the docs say so.
     */
    public function testTheCreatorCountsAsTheLauncherOfAnOlderTriggerToken(): void
    {
        foreach ([new JobTemplate(), new WorkflowTemplate()] as $template) {
            $template->created_by = 7;
            $template->trigger_token_created_by = null;
            $this->assertSame(7, $template->getTriggerUserId(), $template::class . ': an older token runs as the creator.');
            $template->trigger_token_created_by = 12;
            $this->assertSame(12, $template->getTriggerUserId(), $template::class . ': a newer token runs as the user who generated it.');
        }
        $this->assertMentions(
            "a token generated before Ansilume recorded that user runs as the template's creator, who therefore counts as the launcher of its jobs, and never approves them, until the token is generated again",
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'updating.md'
        );
        $this->assertMentions(
            'because you created the template and its token was generated before Ansilume recorded who generated it (until the token is generated again)',
            $this->flat('docs/troubleshooting.md', '## Approve or Reject is refused, or missing'),
            'troubleshooting.md'
        );
    }

    /**
     * The paragraphs of the section from the one that starts with $title up
     * to the next bold title, on one line.
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
     * A file of the code, read from the repository whatever root() says.
     */
    private function code(string $file): string
    {
        $text = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertIsString($text, "{$file} cannot be read.");

        return $text;
    }
}
