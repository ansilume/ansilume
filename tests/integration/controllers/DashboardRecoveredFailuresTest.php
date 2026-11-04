<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\SiteController;
use app\models\Job;
use app\models\JobSearchForm;
use app\models\User;
use app\services\RecoveredFailures;
use app\tests\integration\CountsQueries;
use app\tests\integration\RecoveryFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;

/**
 * The dashboard points at what still needs attention: a failed or
 * timed-out job that a later run of the same job template recovered
 * (RecoveredFailures) leaves the red Failed Jobs (last 24h) panel, and a
 * muted line says how many of the last 24 hours were recovered, with a link
 * to the job list of the recovered failures. When every failure was
 * recovered, only that line remains; its link passes the window's start
 * (finished_since), so the list shows the failures the line counts. Jobs
 * Today turns red only for a failure of today that no later run recovered.
 * The chart keeps counting every failure (SiteControllerActionTest). The
 * panel and the line cover the last 24 hours, as their titles say, and
 * only the jobs the user may see, in a number of queries that does not grow
 * with the failures.
 */
final class DashboardRecoveredFailuresTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RecoveryFixtures;
    use CountsQueries;

    public function testARecoveredFailureLeavesTheRedPanel(): void
    {
        $admin = $this->createUserWithRole('dash-recovered', 'admin');
        $template = $this->recoveryTemplate((int)$admin->id);
        [$recovered, $success] = $this->recoveredFailureAt($template, (int)$admin->id, time() - 3600);
        $open = $this->jobAt($this->recoveryTemplate((int)$admin->id), (int)$admin->id, Job::STATUS_FAILED, time() - 7300, time() - 7200);

        $params = $this->dashboardFor($admin);

        $this->assertSame([(int)$open->id], $this->ids($params['failedJobs']), 'only the failure that no later run recovered');
        $this->assertSame(1, $params['recoveredFailures24h']);

        $xpath = $this->xpathOf($this->render($params));
        $panel = $this->elementAt($xpath, '//div[@id="dashboard-failed-jobs"]');
        $this->assertNotNull($panel, 'the red panel is there for the open failure');
        $this->assertSame(1, $xpath->query(sprintf('.//a[@href="/job/view?id=%d"]', $open->id), $panel)->length);
        $this->assertSame(0, $xpath->query(sprintf('.//a[@href="/job/view?id=%d"]', $recovered->id), $panel)->length, 'the recovered failure left it');
        $this->assertSame(0, $xpath->query(sprintf('.//a[@href="/job/view?id=%d"]', $success->id), $panel)->length);
        $this->assertLine($xpath, RecoveredFailures::DASHBOARD_LINE_ONE, $params['recoveredFailuresSince']);
    }

    /**
     * When every failure of the last 24 hours was recovered, the red panel
     * goes, and only the muted line remains. Without a recovered failure
     * there is no line.
     */
    public function testWhenEveryFailureWasRecoveredOnlyTheLineRemains(): void
    {
        $admin = $this->createUserWithRole('dash-all-recovered', 'admin');
        $template = $this->recoveryTemplate((int)$admin->id);

        $quiet = $this->xpathOf($this->render($this->dashboardFor($admin)));
        $this->assertNull($this->elementAt($quiet, '//div[@id="dashboard-failed-jobs"]'));
        $this->assertNull($this->elementAt($quiet, '//*[@id="dashboard-recovered-failures"]'), 'no recovered failure, no line');

        $this->recoveredFailureAt($template, (int)$admin->id, time() - 600);
        $this->recoveredFailureAt($template, (int)$admin->id, time() - 300, Job::STATUS_TIMED_OUT);

        $params = $this->dashboardFor($admin);
        $this->assertSame([], $params['failedJobs']);
        $this->assertSame(2, $params['recoveredFailures24h']);
        $xpath = $this->xpathOf($this->render($params));
        $this->assertNull($this->elementAt($xpath, '//div[@id="dashboard-failed-jobs"]'), 'no red panel');
        $this->assertLine($xpath, sprintf(RecoveredFailures::DASHBOARD_LINE_MANY, 2), $params['recoveredFailuresSince']);
    }

    /**
     * Review focus: a recovered failure older than 24 hours is not counted,
     * and neither the panel nor the line reach back further. Both reached
     * back to the start of yesterday before (regression: a failure that
     * finished 25 hours ago was listed under "last 24h" from 01:00 on).
     */
    public function testTheDashboardLineCountsOnlyTheLast24Hours(): void
    {
        $admin = $this->createUserWithRole('dash-24h', 'admin');
        $template = $this->recoveryTemplate((int)$admin->id);
        $this->recoveredFailureAt($template, (int)$admin->id, time() - 23 * 3600);
        $this->recoveredFailureAt($template, (int)$admin->id, time() - 22 * 3600, Job::STATUS_TIMED_OUT);
        $this->recoveredFailureAt($template, (int)$admin->id, time() - 25 * 3600);
        $other = $this->recoveryTemplate((int)$admin->id);
        $old = $this->jobAt($other, (int)$admin->id, Job::STATUS_FAILED, time() - 25 * 3600 - 60, time() - 25 * 3600);
        $recent = $this->jobAt($other, (int)$admin->id, Job::STATUS_FAILED, time() - 23 * 3600 - 60, time() - 23 * 3600);

        $params = $this->dashboardFor($admin);

        $this->assertSame(2, $params['recoveredFailures24h'], 'the two of the last 24 hours, not the one of 25 hours ago');
        $this->assertSame([(int)$recent->id], $this->ids($params['failedJobs']), 'the open failure of 25 hours ago is not listed');
        $this->assertNotContains((int)$old->id, $this->ids($params['failedJobs']));
        $this->assertLine($this->xpathOf($this->render($params)), sprintf(RecoveredFailures::DASHBOARD_LINE_MANY, 2), $params['recoveredFailuresSince']);
    }

    /**
     * Regression (review of the dashboard): the line counts the recovered
     * failures of the last 24 hours, but its link Show them listed every
     * recovered failure, of any age (/job/index?recovered=1). The link
     * passes the start of the window the line counts
     * (SiteController::FAILED_JOBS_WINDOW) as finished_since, so the list
     * shows the failures the line counted, and no other.
     */
    public function testRegressionShowThemListsTheRecoveredFailuresTheLineCounts(): void
    {
        $admin = $this->createUserWithRole('dash-show-them', 'admin');
        $template = $this->recoveryTemplate((int)$admin->id);
        [$recent] = $this->recoveredFailureAt($template, (int)$admin->id, time() - 3600);
        $this->recoveredFailureAt($template, (int)$admin->id, time() - 3 * 86400);
        $this->recoveredFailureAt($template, (int)$admin->id, time() - 10 * 86400);
        $before = time();

        $params = $this->dashboardFor($admin);
        $link = $this->elementAt($this->xpathOf($this->render($params)), '//*[@id="dashboard-recovered-failures"]//a');
        $this->assertNotNull($link);
        parse_str((string)parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        $listed = (new JobSearchForm())->search($query + ['template_id' => (string)$template->id])->getModels();

        $this->assertSame(1, $params['recoveredFailures24h']);
        $this->assertSame([(int)$recent->id], $this->ids($listed), 'the failure the line counted, not those of 3 and 10 days ago');
        $since = $params['recoveredFailuresSince'];
        $this->assertIsInt($since);
        $this->assertGreaterThanOrEqual($before - SiteController::FAILED_JOBS_WINDOW, $since);
        $this->assertLessThanOrEqual(time() - SiteController::FAILED_JOBS_WINDOW, $since);
        $this->assertSame(['recovered' => JobSearchForm::RECOVERED_YES, 'finished_since' => (string)$since], $query);
    }

    /**
     * Jobs Today turns red only for a failure of today that no later run
     * recovered; it counted every failure of today before.
     */
    public function testJobsTodayTurnsRedOnlyForUnrecoveredFailures(): void
    {
        $admin = $this->createUserWithRole('dash-today', 'admin');
        $template = $this->recoveryTemplate((int)$admin->id);
        $today = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $this->recoveredFailureAt($template, (int)$admin->id, $today + 60);

        $params = $this->dashboardFor($admin);
        $this->assertSame(2, $params['stats']['jobs_today']);
        $this->assertSame(0, $params['stats']['jobs_today_failed'], 'the recovered failure does not count');
        $this->assertSame('text-bg-success', $this->jobsTodayColour($params));

        $this->jobAt($this->recoveryTemplate((int)$admin->id), (int)$admin->id, Job::STATUS_TIMED_OUT, $today + 100, $today + 160);

        $params = $this->dashboardFor($admin);
        $this->assertSame(1, $params['stats']['jobs_today_failed'], 'the failure that no later run recovered');
        $this->assertSame('text-bg-danger', $this->jobsTodayColour($params));
    }

    /**
     * The line counts only the failures the user may see, as the panel
     * lists only those: team scoping is unchanged.
     */
    public function testTheLineCountsOnlyTheFailuresTheUserMaySee(): void
    {
        $s = $this->teamScope();
        foreach (['own', 'foreign', 'open'] as $key) {
            $this->recoveredFailureAt($s[$key], (int)$s['admin']->id, time() - 900);
        }
        $this->jobAt($s['foreign'], (int)$s['admin']->id, Job::STATUS_FAILED, time() - 700, time() - 600);

        $member = $this->dashboardFor($s['member']);
        $this->assertSame(2, $member['recoveredFailures24h'], 'own and open, not foreign');
        $this->assertSame([], $member['failedJobs'], 'the open failure is foreign');

        $admin = $this->dashboardFor($s['admin']);
        $this->assertSame(3, $admin['recoveredFailures24h']);
        $this->assertCount(1, $admin['failedJobs']);
    }

    /**
     * The panel, the line and Jobs Today ask whether a failure was
     * recovered in their own queries: more failures, recovered or not,
     * cost no more queries.
     */
    public function testTheDashboardDoesNotAskPerFailure(): void
    {
        $admin = $this->createUserWithRole('dash-queries', 'admin');
        $template = $this->recoveryTemplate((int)$admin->id);
        $this->recoveredFailureAt($template, (int)$admin->id, time() - 3000);
        $this->jobAt($template, (int)$admin->id, Job::STATUS_FAILED, time() - 1800, time() - 1700);
        $this->dashboardPage($admin);
        $before = $this->queriesOf(fn (): string => $this->dashboardPage($admin));

        for ($i = 0; $i < 6; $i++) {
            $other = $this->recoveryTemplate((int)$admin->id);
            $this->recoveredFailureAt($other, (int)$admin->id, time() - 2000 - $i);
            $this->jobAt($other, (int)$admin->id, Job::STATUS_TIMED_OUT, time() - 900, time() - 800 - $i);
        }

        $this->assertSame($before, $this->queriesOf(fn (): string => $this->dashboardPage($admin)));
        $this->assertSame(7, $this->dashboardFor($admin)['recoveredFailures24h']);
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * The muted line says $text and links the job list of the recovered
     * failures that finished since $since, the start of its window.
     */
    private function assertLine(\DOMXPath $xpath, string $text, mixed $since): void
    {
        $line = $this->elementAt($xpath, '//*[@id="dashboard-recovered-failures"]');
        $this->assertNotNull($line, 'the muted line');
        $this->assertStringContainsString('text-body-secondary', $line->getAttribute('class'));
        $this->assertSame($text . ' ' . RecoveredFailures::DASHBOARD_LINK, $this->textOf($line));
        $link = $xpath->query('.//a', $line)->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertIsInt($since);
        $this->assertSame('/job/index?recovered=1&finished_since=' . $since, $link->getAttribute('href'));
        $this->assertSame(RecoveredFailures::DASHBOARD_LINK, $this->textOf($link));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function jobsTodayColour(array $params): string
    {
        $xpath = $this->xpathOf($this->render($params));
        $card = $this->elementAt($xpath, '//div[contains(@class, "card") and .//a[normalize-space(.)="Jobs Today"]]');
        $this->assertNotNull($card, 'the Jobs Today card');
        $colours = array_values(array_intersect(['text-bg-secondary', 'text-bg-success', 'text-bg-danger'], explode(' ', $card->getAttribute('class'))));
        $this->assertCount(1, $colours);

        return $colours[0];
    }

    /**
     * @param mixed $models
     * @return list<int>
     */
    private function ids(mixed $models): array
    {
        $this->assertIsArray($models);

        return array_values(array_map(static fn (Job $job): int => (int)$job->id, $models));
    }

    /**
     * The parameters the dashboard hands its view, for $user.
     *
     * @return array<string, mixed>
     */
    private function dashboardFor(User $user): array
    {
        $this->loginAs($user);
        $ctrl = new class ('site', \Yii::$app) extends SiteController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }
        };
        $ctrl->actionIndex();

        return $ctrl->capturedParams;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function render(array $params): string
    {
        return \Yii::$app->view->renderFile('@app/views/site/index.php', $params);
    }

    private function dashboardPage(User $user): string
    {
        return $this->render($this->dashboardFor($user));
    }

    private function xpathOf(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function elementAt(\DOMXPath $xpath, string $path): ?\DOMElement
    {
        $node = $xpath->query($path)->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function textOf(\DOMNode $node): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', (string)$node->textContent));
    }
}
