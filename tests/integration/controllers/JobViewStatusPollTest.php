<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\models\Job;
use yii\base\Module;

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
     * The object literal assigned to `var $name` in $script, decoded.
     *
     * @return array<string, mixed>
     */
    private function jsonVar(string $script, string $name): array
    {
        if (preg_match('/^\s*var ' . preg_quote($name, '/') . ' = (\{.*\});$/m', $script, $m) !== 1) {
            $this->fail("var {$name} is missing");
        }
        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, "var {$name} is not a JSON object");

        return $decoded;
    }
}
