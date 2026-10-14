<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\models\Job;
use app\services\runstate\Texts;
use yii\base\Module;
use yii\helpers\Json;

/**
 * The page of a job that has not finished polls the job's status and shows
 * it in the status badge (#status-badge), with the label and the colour the
 * page itself renders: Job::statusLabel() and Job::statusCssClass().
 */
class JobViewStatusPollTest extends WebControllerTestCase
{
    use RendersRealViews;

    /**
     * Regression: the poll wrote the raw status with its first letter in
     * upper case, so a job waiting for approval showed "Pending_approval"
     * instead of "Awaiting Approval" (and "Timed_out" instead of "Timed
     * Out"), and its colour map knew only six statuses: a job awaiting
     * approval, rejected or timed out turned grey ("secondary"). The page
     * now hands the poll the label and colour of every status.
     */
    public function testThePollShowsTheLabelAndColourOfEveryStatus(): void
    {
        $script = $this->pollScript($this->renderJobPage(Job::STATUS_PENDING_APPROVAL));

        $labels = $this->jsonVar($script, 'statusLabels');
        $classes = $this->jsonVar($script, 'statusClasses');
        $expectedLabels = [];
        $expectedClasses = [];
        foreach (Job::statuses() as $status) {
            $expectedLabels[$status] = Job::statusLabel($status);
            $expectedClasses[$status] = Job::statusCssClass($status);
        }
        $this->assertSame($expectedLabels, $labels);
        $this->assertSame($expectedClasses, $classes);

        foreach (
            [
                Job::STATUS_PENDING_APPROVAL => ['Awaiting Approval', 'info'],
                Job::STATUS_TIMED_OUT => ['Timed Out', 'danger'],
                Job::STATUS_REJECTED => ['Rejected', 'danger'],
            ] as $status => [$label, $class]
        ) {
            $this->assertSame($label, $labels[$status] ?? null, "the label of {$status}");
            $this->assertSame($class, $classes[$status] ?? null, "the colour of {$status}");
        }
    }

    /**
     * The poll writes the badge from these maps; a status the page does not
     * know is shown as it is, in grey, as Job::statusLabel() and
     * Job::statusCssClass() do.
     */
    public function testThePollWritesTheBadgeFromTheMaps(): void
    {
        $script = $this->pollScript($this->renderJobPage(Job::STATUS_QUEUED));

        $this->assertStringContainsString('badgeEl.textContent = statusLabels[data.status] || data.status;', $script);
        $this->assertStringContainsString("'badge text-bg-' + (statusClasses[data.status] || 'secondary') + ' ms-2 fs-6'", $script);
        $this->assertStringNotContainsString('toUpperCase', $script, 'no status is spelled out from its raw value');
        $this->assertSame('Unknown_status', Job::statusLabel('Unknown_status'));
        $this->assertSame('secondary', Job::statusCssClass('Unknown_status'));
    }

    /**
     * #detail-status kept "Awaiting Approval" after the job was approved: the
     * poll wrote the badge only. It writes the Details card's status too, and
     * hides the approval note once the job no longer waits for its request
     * (tests/e2e/tests/jobs/approval-context.spec.ts follows it in a browser).
     */
    public function testThePollWritesTheDetailsStatusAndHidesTheApprovalNote(): void
    {
        $script = $this->pollScript($this->renderJobPage(Job::STATUS_PENDING_APPROVAL));

        $this->assertStringContainsString("var detailStatusEl = document.getElementById('detail-status');", $script);
        $this->assertStringContainsString('detailStatusEl.textContent = badgeEl.textContent;', $script);
        $this->assertStringContainsString("var approvalNoteEl = document.getElementById('job-approval-note');", $script);
        $this->assertStringContainsString('var awaitingApproval = ' . json_encode(Job::STATUS_PENDING_APPROVAL) . ';', $script);
        $this->assertStringContainsString('if (approvalNoteEl && data.status !== awaitingApproval) {', $script);
        $this->assertStringContainsString('approvalNoteEl.hidden = true;', $script);
    }

    /**
     * A refused poll (401, 403, 404) stops polling, with the server's note
     * in place of the Live badge, and the poll asks for JSON: signed out, it
     * is answered 403 instead of being sent to the login page, which would
     * keep the poll as the address to return to after the next sign-in
     * (tests/e2e/tests/jobs/live-poll.spec.ts follows both in a browser).
     */
    public function testARefusedPollStopsWithTheServersNote(): void
    {
        $script = $this->pollScript($this->renderJobPage(Job::STATUS_RUNNING));

        $this->assertStringContainsString("fetch(pollUrl + '&after=' + lastSeq, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })", $script);
        $this->assertStringContainsString('var stopStatuses = [401, 403, 404];', $script);
        $this->assertStringContainsString('var stoppedNote = ' . Json::htmlEncode(Texts::LIVE_STOPPED_JOB) . ';', $script);
        $this->assertStringContainsString('note.textContent = stoppedNote;', $script);
    }

    /**
     * regression: the page polled every 2.5 seconds for as long as the job
     * waited, an approval for days, and in a hidden tab too. While the job
     * waits (the statuses of Job::waitingStatuses(), as the answer gives
     * them), an answer that brings nothing new doubles the wait, up to 30
     * seconds; a failed poll doubles it too; no poll is scheduled while the
     * page is hidden, and one is sent at once when it is shown again
     * (tests/e2e/tests/jobs/live-poll.spec.ts follows the pace in a browser).
     * The page hands the poll the waiting statuses of the Job model.
     */
    public function testRegressionThePollKeptItsPaceWhileTheJobWaitedOrThePageWasHidden(): void
    {
        $script = $this->pollScript($this->renderJobPage(Job::STATUS_PENDING_APPROVAL));

        $this->assertSame(Job::waitingStatuses(), $this->jsonVar($script, 'waitingStatuses'));
        $this->assertSame(
            [Job::STATUS_PENDING, Job::STATUS_QUEUED, Job::STATUS_PENDING_APPROVAL],
            Job::waitingStatuses(),
            'a job waits for an approval, to be queued or for a runner; a running job does not wait'
        );
        foreach (['var FIRST_POLL = 1500;', 'var INTERVAL = 2500;', 'var MAX_INTERVAL = 30000;'] as $pace) {
            $this->assertStringContainsString($pace, $script);
        }
        $this->assertStringContainsString('if (answer !== null && (answer !== lastAnswer || waitingStatuses.indexOf(data.status) === -1)) {', $script);
        $this->assertStringContainsString('interval = Math.min(interval * 2, MAX_INTERVAL);', $script);
        $this->assertStringContainsString('pace(null);', $script, 'a failed poll doubles the wait');
        // Hidden, the page sends no poll (poll()) and schedules none (schedule()); shown again, shown() polls.
        $this->assertSame(2, substr_count($script, 'if (stopped || document.hidden) {'), 'poll() and shown()');
        $this->assertStringContainsString('if (stopped || document.hidden || timer !== null) {', $script);
        $this->assertStringContainsString("document.addEventListener('visibilitychange', shown);", $script);
        $this->assertStringContainsString('timer = setTimeout(poll, interval);', $script);
        $this->assertStringNotContainsString('setInterval', $script, 'each poll is scheduled after the answer before it');
    }

    /**
     * The page as rendered shows the same label and colour as the poll.
     */
    public function testThePageRendersTheBadgeTheWayThePollWritesIt(): void
    {
        $html = $this->renderJobPage(Job::STATUS_PENDING_APPROVAL);

        if (preg_match('#<span class="badge text-bg-(\w+) ms-2 fs-6" id="status-badge">\s*([^<]*?)\s*</span>#', $html, $m) !== 1) {
            $this->fail('the status badge is missing');
        }
        $this->assertSame(['info', 'Awaiting Approval'], [$m[1], $m[2]]);
    }

    /**
     * A finished job's page does not poll.
     */
    public function testAFinishedJobDoesNotPoll(): void
    {
        $html = $this->renderJobPage(Job::STATUS_REJECTED);

        $this->assertStringNotContainsString('var statusLabels', $html);
        $this->assertStringNotContainsString('log-poll', $html);
    }

    private function renderJobPage(string $status): string
    {
        $user = $this->createUser('job-status-poll');
        $user->is_superadmin = true;
        $user->save(false);
        $this->loginAs($user);
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
        $job = $this->createJob((int)$template->id, (int)$user->id, $status);
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new class ('job', $app) extends JobController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };

        return $this->withRealViews($ctrl, '/job/view?id=' . $job->id, static fn (): string => $ctrl->actionView((int)$job->id));
    }

    /**
     * The script that polls the job: from its <script> tag to the first
     * "</script", which is where the browser ends it.
     */
    private function pollScript(string $html): string
    {
        $tag = strpos($html, "<script>\n(function () {\n    var badgeEl");
        $this->assertNotFalse($tag, 'the poll script is missing');
        $start = $tag + strlen('<script>');
        $end = stripos($html, '</script', $start);
        $this->assertNotFalse($end, 'the poll script does not end');

        return substr($html, $start, $end - $start);
    }

    /**
     * The object or array literal assigned to `var $name` in $script, decoded.
     *
     * @return array<mixed>
     */
    private function jsonVar(string $script, string $name): array
    {
        if (preg_match('/^\s*var ' . preg_quote($name, '/') . ' = (\{.*\}|\[.*\]);$/m', $script, $m) !== 1) {
            $this->fail("var {$name} is missing");
        }
        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, "var {$name} is not a JSON object or array");

        return $decoded;
    }
}
