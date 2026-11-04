<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\MattermostPosts;
use app\services\notification\MattermostStatusPost;
use app\services\notification\MattermostText;
use app\services\runstate\RunState;
use app\services\runstate\StepState;
use PHPUnit\Framework\TestCase;

/**
 * What the Mattermost channel posts, rendered without a server: the status
 * post of a run from its state (MattermostStatusPost) and the replies in its
 * thread and the posts of their own from a delivery's variables
 * (MattermostPosts).
 */
class MattermostRenderTest extends TestCase
{
    private const RUN_URL = 'https://ansilume.example.com/workflow-job/view?id=12';
    private const APPROVAL_URL = 'https://ansilume.example.com/approval/view?id=7';
    private const JOB_URL = 'https://ansilume.example.com/job/view?id=45';

    // -- The status post --------------------------------------------------------------------

    /**
     * A run that waits for an approval links the request, when the user
     * may open it; else the run. Its steps carry their positions, a step
     * removed from the workflow since its name alone.
     */
    public function testAWaitForApprovalLinksTheRequest(): void
    {
        $state = $this->runState('running', 'awaiting_approval', 'approval', [
            $this->step(1, 101, 'Build', 'succeeded', 'succeeded'),
            $this->step(2, null, 'Old step', 'failed', 'failed'),
            $this->step(3, 102, 'Approve', 'running', 'awaiting_approval', 'approval', 'approval', 77),
        ]);

        $linked = $this->attachment($state, self::APPROVAL_URL);
        $unlinked = $this->attachment($state, null);

        $this->assertSame(
            "**Now:** Waiting for approval at step \"Approve\"\\. [Open the approval request](" . self::APPROVAL_URL . ")\n\n"
            . "Step 1 \"Build\": Succeeded\nStep \"Old step\": Failed\nStep 2 \"Approve\": Awaiting Approval",
            $linked['text']
        );
        $this->assertStringContainsString('[Open the run](' . self::RUN_URL . ')', $unlinked['text']);
        $this->assertSame([['title' => 'Status', 'value' => 'Awaiting Approval', 'short' => true], ['title' => 'Steps', 'value' => '1 of 3 done', 'short' => true]], $linked['fields']);
        $this->assertSame(['#FFBC1F', 'Workflow Job #12 (deploy): Awaiting Approval'], [$linked['color'], $linked['fallback']]);
    }

    /**
     * A run whose step runs, waiting for nothing, says which step; between
     * two steps, that it goes on; a gated job step that waits for its
     * approval links the run when no request may be shown.
     */
    public function testWhatARunningRunDoesNow(): void
    {
        $running = $this->runState('running', 'running', null, [$this->step(4, 101, 'Deploy', 'running', 'running', 'job', null, 45)]);
        $between = $this->runState('running', 'running', null, [$this->step(4, 101, 'Deploy', 'succeeded', 'succeeded')], false);

        $this->assertStringStartsWith('**Now:** Step 1 "Deploy" runs. [Open the run](' . self::RUN_URL . ')', $this->attachment($running, self::APPROVAL_URL)['text']);
        $this->assertStringStartsWith('**Now:** ' . MattermostStatusPost::BETWEEN_STEPS . ' [Open the run]', $this->attachment($between, null)['text']);
        $this->assertSame('#1C58D9', $this->attachment($running, null)['color']);
    }

    /**
     * A run that ended says its result and how long it took, in its
     * state's colour; a state without a colour of its own is blue.
     */
    public function testARunThatEndedShowsItsResultAndDuration(): void
    {
        $state = $this->runState('succeeded', 'succeeded', null, [$this->step(1, 101, 'Build', 'succeeded', 'succeeded')], false);
        $odd = $this->runState('succeeded', 'unknown-state', null, [], false);

        $attachment = MattermostStatusPost::props($state, 'deploy', self::RUN_URL, [101 => 0], null, 1000, 1075)['attachments'][0];

        $this->assertStringStartsWith('**Result:** Succeeded. [Open the run](' . self::RUN_URL . ')', (string)$attachment['text']);
        $this->assertSame(['title' => 'Duration', 'value' => '1m 15s', 'short' => true], $attachment['fields'][2] ?? null);
        $this->assertSame('#3DB887', $attachment['color']);
        $this->assertSame('#1C58D9', $this->attachment($odd, null)['color']);
    }

    /**
     * Without an http address of the run (appBaseUrl unset), the title is
     * not linked; a workflow name is cut and escaped.
     */
    public function testATitleWithoutAnAddressIsNotLinked(): void
    {
        $state = $this->runState('running', 'running', null, [], false);

        $attachment = MattermostStatusPost::props($state, str_repeat('_', 300), '/workflow-job/view?id=12', [], null, null, null)['attachments'][0];

        $this->assertArrayNotHasKey('title_link', $attachment);
        $this->assertSame('Workflow Job #12: ' . str_repeat('\\_', MattermostStatusPost::NAME_LENGTH), $attachment['title']);
    }

    // -- Replies ---------------------------------------------------------------------------

    /**
     * Each kind of event replies with what happened and one link: a step
     * with its position, a job and an approval request by their IDs; the
     * reason of a failed step and a failed run is added; a wait says what
     * the run page says and links its request; an event without a reply of
     * its own replies with the subject.
     */
    public function testEachEventRepliesWithWhatHappenedAndOneLink(): void
    {
        $run = ['workflow.url' => self::RUN_URL, 'step.position' => '2', 'step.name' => 'De_ploy'];

        $this->assertSame('Step 2 "De\\_ploy" started. [Open the run](' . self::RUN_URL . ')', MattermostPosts::reply(['event' => 'workflow.step_started'] + $run, null, 's'));
        $this->assertSame(
            '@oncall Step 2 "De\\_ploy" failed. Job \\#45 failed\\. [Open the run](' . self::RUN_URL . ')',
            MattermostPosts::reply(['event' => 'workflow.step_failed', 'workflow.reason' => 'Job #45 failed.'] + $run, '@oncall', 's')
        );
        $this->assertSame('The run failed. [Open the run](' . self::RUN_URL . ')', MattermostPosts::reply(['event' => 'workflow.failed'] + $run, null, 's'));
        $this->assertSame(
            'Waiting for approval at step "De\\_ploy"\\. [Open the approval request](' . self::APPROVAL_URL . ')',
            MattermostPosts::reply(['event' => 'workflow.waiting', 'workflow.reason' => 'Waiting for approval at step "De_ploy".', 'approval.url' => self::APPROVAL_URL] + $run, null, 's')
        );
        $this->assertSame('Step 2 "De\\_ploy" waits. [Open the run](' . self::RUN_URL . ')', MattermostPosts::reply(['event' => 'workflow.waiting'] + $run, null, 's'));
        $this->assertSame('Job #45 failed. [Open the job](' . self::JOB_URL . ')', MattermostPosts::reply(['event' => 'job.failed', 'job.id' => '45', 'job.url' => self::JOB_URL] + $run, null, 's'));
        $this->assertSame(
            'Approval Request #7 was approved. [Open the approval request](' . self::APPROVAL_URL . ')',
            MattermostPosts::reply(['event' => 'approval.approved', 'approval.id' => '7', 'approval.url' => self::APPROVAL_URL] + $run, null, 's')
        );
        $this->assertSame('Step "Gone" succeeded.', MattermostPosts::reply(['event' => 'workflow.step_succeeded', 'step.name' => 'Gone'], null, 's'), 'no position, no address: no link');
        $this->assertSame('\\*\\*custom\\*\\* @' . "\u{200B}" . 'all [Open the run](' . self::RUN_URL . ')', MattermostPosts::reply(['event' => 'future.event'] + $run, null, '**custom** @all'));
    }

    // -- Posts of their own -------------------------------------------------------------------

    /**
     * A post of its own links the run, the approval request or the job of
     * its event, the first that is an http address, and cuts a long title.
     */
    public function testAPostOfItsOwnLinksItsObject(): void
    {
        $template = new NotificationTemplate();
        $template->subject_template = '{{ title }}';
        $template->body_template = 'body';

        $job = MattermostPosts::message($template, ['title' => 'x', 'severity' => 'warning', 'workflow.url' => '/relative', 'job.url' => self::JOB_URL])['attachments'][0];
        $long = MattermostPosts::message($template, ['title' => str_repeat('*', 400), 'severity' => 'unknown'])['attachments'][0];

        $this->assertSame(['fallback' => 'x', 'color' => '#FFBC1F', 'title' => 'x', 'title_link' => self::JOB_URL, 'text' => 'body'], $job);
        $this->assertSame(str_repeat('\\*', 150), $long['title'], 'cut between two escaped characters');
        $this->assertSame(MattermostPosts::SEVERITY_COLORS['info'], $long['color']);
        $this->assertSame(MattermostPosts::TITLE_LENGTH, mb_strlen($long['fallback']));
        $this->assertLessThanOrEqual(MattermostText::MAX_LENGTH, mb_strlen($long['text']));
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function attachment(RunState $state, ?string $approvalUrl): array
    {
        $attachment = MattermostStatusPost::props($state, 'deploy', self::RUN_URL, [101 => 0, 102 => 1, 103 => 2], $approvalUrl, 1000, null)['attachments'][0];
        $this->assertIsArray($attachment);

        return $attachment;
    }

    /**
     * Run #12 in $status and $state, waiting for $waitingFor, with $steps,
     * its last step the current one when $current.
     *
     * @param list<StepState> $steps
     */
    private function runState(string $status, string $state, ?string $waitingFor, array $steps, bool $current = true): RunState
    {
        return new RunState(12, $status, $state, $waitingFor, $current && $steps !== [] ? $steps[array_key_last($steps)] : null, $steps);
    }

    private function step(
        int $executionId,
        ?int $workflowStepId,
        string $name,
        string $status,
        string $state,
        ?string $type = 'job',
        ?string $waitingFor = null,
        ?int $jobId = null
    ): StepState {
        return new StepState($executionId, $workflowStepId, $name, $type, $status, $state, $waitingFor, $jobId, null, null, 1000, null, null, null, null, null);
    }
}
