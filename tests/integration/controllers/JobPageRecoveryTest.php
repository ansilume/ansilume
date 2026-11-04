<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\helpers\TimeHelper;
use app\models\Job;
use app\models\JobSearchForm;
use app\models\JobTemplate;
use app\models\User;
use app\services\RecoveredFailures;
use app\tests\integration\CountsQueries;
use app\tests\integration\RecoveryFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;

/**
 * The job page of a failed or timed-out job that a later run of the same
 * job template recovered (RecoveredFailures) says so and links that run
 * (#job-recovered-note). The job list shows a muted "recovered by #N" next
 * to the status of such a job, read for the whole page in one query, and
 * filters by "recovered": 1 lists the recovered failures, 0 the failures
 * that no later run recovered, any other value is no filter. Whoever sees
 * the failure sees the note: the recovering job belongs to the same job
 * template.
 */
final class JobPageRecoveryTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;
    use RecoveryFixtures;
    use CountsQueries;

    private User $admin;

    private int $adminId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('jpr-admin', 'admin');
        $this->adminId = (int)$this->admin->id;
        $this->loginAs($this->admin);
    }

    public function testTheJobPageNamesTheRecoveringJob(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        foreach ([Job::STATUS_FAILED, Job::STATUS_TIMED_OUT] as $i => $status) {
            [$failure, $success] = $this->recoveredFailureAt($template, $this->adminId, time() - 3600 + $i * 100, $status);

            $page = $this->jobPage($failure);

            $this->assertSame((int)$success->id, $page['recoveredBy'], $status);
            $note = $this->elementAt($this->xpathOf($page['html']), '//div[@id="job-recovered-note"]');
            $this->assertNotNull($note, $status);
            $this->assertSame(sprintf(RecoveredFailures::JOB_PAGE_NOTE, '#' . $success->id), $this->textOf($note), $status);
            $link = $note->getElementsByTagName('a')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $link);
            $this->assertSame('/job/view?id=' . $success->id, $link->getAttribute('href'));
        }
    }

    /**
     * No note for a failure that no later run recovered (a dry run and an
     * overlapping success do not), nor for any job that is no failure.
     */
    public function testOtherJobsHaveNoNote(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        $open = $this->jobAt($template, $this->adminId, Job::STATUS_FAILED, time() - 900, time() - 600);
        $this->jobAt($template, $this->adminId, Job::STATUS_SUCCEEDED, time() - 700, time() - 650);
        $this->jobAt($template, $this->adminId, Job::STATUS_SUCCEEDED, time() - 500, time() - 450, true);
        $other = $this->recoveryTemplate($this->adminId);
        $canceled = $this->jobAt($other, $this->adminId, Job::STATUS_CANCELED, time() - 1900, time() - 1800);
        $success = $this->jobAt($other, $this->adminId, Job::STATUS_SUCCEEDED, time() - 400, time() - 300);

        foreach (['open failure' => $open, 'canceled' => $canceled, 'success' => $success] as $what => $job) {
            $page = $this->jobPage($job);

            $this->assertNull($page['recoveredBy'], $what);
            $this->assertNull($this->elementAt($this->xpathOf($page['html']), '//*[@id="job-recovered-note"]'), $what);
        }
    }

    /**
     * Whoever may view the failure sees the note: a viewer of the project,
     * through a team, and anyone for a project that no team has.
     */
    public function testAViewerSeesTheNote(): void
    {
        $s = $this->teamScope('viewer');
        [$failure, $success] = $this->recoveredFailureAt($s['own'], $this->adminId, time() - 1200);
        $this->loginAs($s['member']);

        $page = $this->jobPage($failure);

        $this->assertSame((int)$success->id, $page['recoveredBy']);
        $this->assertNotNull($this->elementAt($this->xpathOf($page['html']), '//div[@id="job-recovered-note"]'));
    }

    public function testTheListNamesTheRecoveringJobNextToTheStatus(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        [$recovered, $success] = $this->recoveredFailureAt($template, $this->adminId, time() - 3600);
        $open = $this->jobAt($template, $this->adminId, Job::STATUS_TIMED_OUT, time() - 600, time() - 500);

        $page = $this->listPage(['template_id' => (string)$template->id]);
        $xpath = $this->xpathOf($page['html']);

        $this->assertSame([(int)$recovered->id => (int)$success->id], $page['recoveredBy']);
        $cell = $this->elementAt($xpath, sprintf('//tbody/tr[td[1]//a[@href="/job/view?id=%d"]]/td[2]', $recovered->id));
        $this->assertNotNull($cell);
        $note = $this->elementAt($xpath, sprintf('//tbody/tr[td[1]//a[@href="/job/view?id=%d"]]/td[2]//*[@data-recovered-by]', $recovered->id));
        $this->assertNotNull($note, 'next to the status');
        $this->assertSame((string)$success->id, $note->getAttribute('data-recovered-by'));
        $this->assertSame(sprintf(RecoveredFailures::LIST_NOTE, '#' . $success->id), $this->textOf($note));
        $this->assertStringContainsString('text-body-secondary', $note->getAttribute('class'), 'muted');
        $link = $note->getElementsByTagName('a')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame('/job/view?id=' . $success->id, $link->getAttribute('href'));
        foreach ([$open, $success] as $other) {
            $this->assertNull(
                $this->elementAt($xpath, sprintf('//tbody/tr[td[1]//a[@href="/job/view?id=%d"]]//*[@data-recovered-by]', $other->id)),
                'job #' . $other->id
            );
        }
    }

    /**
     * recovered=1 lists the recovered failures, recovered=0 the failures no
     * later run recovered; the filter keeps its choice and offers Clear
     * filters.
     */
    public function testTheListFiltersRecovered(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        [$recovered, $firstSuccess] = $this->recoveredFailureAt($template, $this->adminId, time() - 7200);
        [$recoveredTimeout, $success] = $this->recoveredFailureAt($template, $this->adminId, time() - 3600, Job::STATUS_TIMED_OUT);
        $open = $this->jobAt($template, $this->adminId, Job::STATUS_FAILED, time() - 600, time() - 500);
        $canceled = $this->jobAt($template, $this->adminId, Job::STATUS_CANCELED, time() - 400, time() - 300);
        $filter = ['template_id' => (string)$template->id];

        $yes = $this->listPage($filter + ['recovered' => '1']);
        $this->assertSame([(int)$recoveredTimeout->id, (int)$recovered->id], $this->listedIds($yes['html']));
        $this->assertSame('1', $this->selectedRecovered($yes['html']));
        $this->assertTrue($this->offersClearFilters($yes['html']));

        $no = $this->listPage($filter + ['recovered' => '0']);
        $this->assertSame([(int)$open->id], $this->listedIds($no['html']));
        $this->assertSame('0', $this->selectedRecovered($no['html']));
        $this->assertTrue($this->offersClearFilters($no['html']));

        $any = $this->listPage($filter);
        $this->assertSame(
            [(int)$canceled->id, (int)$open->id, (int)$success->id, (int)$recoveredTimeout->id, (int)$firstSuccess->id, (int)$recovered->id],
            $this->listedIds($any['html'])
        );
        $this->assertSame('', $this->selectedRecovered($any['html']));

        $this->assertFalse($this->offersClearFilters($this->listPage([])['html']), 'no filter');
        $this->assertTrue($this->offersClearFilters($this->listPage(['recovered' => '0'])['html']), 'recovered alone is a filter');
    }

    /**
     * Any other value of recovered is no filter: every job is listed, as
     * without it, the select shows Any and no Clear filters is offered.
     */
    public function testTheListIgnoresAnInvalidRecoveredValue(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        $this->recoveredFailureAt($template, $this->adminId, time() - 3600);
        $this->jobAt($template, $this->adminId, Job::STATUS_FAILED, time() - 600, time() - 500);
        $filter = ['template_id' => (string)$template->id];
        $every = $this->listedIds($this->listPage($filter)['html']);
        $this->assertCount(3, $every);

        foreach (['yes', '2', ' 1', '1.0', '-1', 'true', ['1'], ['0', '1']] as $value) {
            $page = $this->listPage($filter + ['recovered' => $value]);
            $what = (string)json_encode($value);

            $this->assertSame($every, $this->listedIds($page['html']), $what);
            $this->assertSame('', $this->selectedRecovered($page['html']), $what);
            $this->assertFalse($this->offersClearFilters($this->listPage(['recovered' => $value])['html']), $what);
        }
    }

    /**
     * finished_since lists the jobs that finished at that Unix time or
     * later: not those that finished before, nor one that has not finished.
     * With recovered=1, as the dashboard's link passes them, only the
     * recovered failures of that window.
     */
    public function testTheListFiltersByFinishedSince(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        $since = time() - 86400;
        [$recent, $recentSuccess] = $this->recoveredFailureAt($template, $this->adminId, time() - 3600);
        [$old, $oldSuccess] = $this->recoveredFailureAt($template, $this->adminId, time() - 3 * 86400);
        $atTheStart = $this->jobAt($template, $this->adminId, Job::STATUS_SUCCEEDED, $since - 60, $since);
        $running = $this->jobAt($template, $this->adminId, Job::STATUS_RUNNING, time() - 60, null);
        $filter = ['template_id' => (string)$template->id];

        $this->assertSame(
            [(int)$running->id, (int)$atTheStart->id, (int)$oldSuccess->id, (int)$old->id, (int)$recentSuccess->id, (int)$recent->id],
            $this->listedIds($this->listPage($filter)['html']),
            'without the filter'
        );
        $this->assertSame(
            [(int)$atTheStart->id, (int)$recentSuccess->id, (int)$recent->id],
            $this->listedIds($this->listPage($filter + ['finished_since' => (string)$since])['html']),
            'from the second given on'
        );
        $this->assertSame(
            [(int)$recent->id],
            $this->listedIds($this->listPage($filter + ['recovered' => '1', 'finished_since' => (string)$since])['html']),
            'the recovered failures of the window'
        );
    }

    /**
     * The list keeps finished_since: its form carries it, so that Filter
     * keeps it, and so do the links of its pages. A note says that it is
     * active, with a link that removes it and keeps the other filters, and
     * Clear filters is offered.
     */
    public function testTheListKeepsFinishedSinceAndSaysSo(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        for ($i = 0; $i < 26; $i++) {
            $this->jobAt($template, $this->adminId, Job::STATUS_SUCCEEDED, time() - 900 + $i, time() - 800 + $i);
        }
        $since = time() - 86400;

        $html = $this->listPage(['template_id' => (string)$template->id, 'finished_since' => '+' . $since])['html'];
        $xpath = $this->xpathOf($html);

        $hidden = $this->elementAt($xpath, '//form//input[@type="hidden"][@name="finished_since"]');
        $this->assertNotNull($hidden, 'the form carries the filter');
        $this->assertSame((string)$since, $hidden->getAttribute('value'), 'as the number it is');
        $note = $this->elementAt($xpath, '//form//*[@id="job-filter-finished-since"]');
        $this->assertNotNull($note);
        $this->assertSame(
            sprintf(JobSearchForm::FINISHED_SINCE_NOTE, date(TimeHelper::STAMP_OTHER_DAY, $since)) . ' ' . JobSearchForm::FINISHED_SINCE_CLEAR,
            $this->textOf($note)
        );
        $remove = $note->getElementsByTagName('a')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $remove);
        $this->assertSame('/job/index?template_id=' . $template->id, $remove->getAttribute('href'), 'the other filters stay');
        $this->assertTrue($this->offersClearFilters($html));
        $pages = $xpath->query('//ul[contains(@class, "pagination")]//a[contains(@href, "page=2")]');
        $this->assertNotFalse($pages);
        $this->assertGreaterThan(0, $pages->length, 'a second page');
        foreach ($pages as $page) {
            $this->assertInstanceOf(\DOMElement::class, $page);
            $this->assertStringContainsString('finished_since=', $page->getAttribute('href'), 'the pages keep the filter');
        }

        $page2 = $this->listPage(['template_id' => (string)$template->id, 'finished_since' => (string)$since, 'page' => '2'])['html'];
        $removeOnPage2 = $this->elementAt($this->xpathOf($page2), '//*[@id="job-filter-finished-since"]//a');
        $this->assertNotNull($removeOnPage2);
        $this->assertSame('/job/index?template_id=' . $template->id, $removeOnPage2->getAttribute('href'), 'back to the first page');
    }

    /**
     * Any value of finished_since that is no whole number of 0 or more is
     * no filter, as for every filter of the list: every job is listed, the
     * form carries no such value, there is no note, and no Clear filters.
     */
    public function testTheListIgnoresAFinishedSinceThatIsNoUnixTime(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        $this->recoveredFailureAt($template, $this->adminId, time() - 3 * 86400);
        $this->jobAt($template, $this->adminId, Job::STATUS_RUNNING, time() - 60, null);
        $filter = ['template_id' => (string)$template->id];
        $every = $this->listedIds($this->listPage($filter)['html']);
        $this->assertCount(3, $every);

        foreach (['', 'yesterday', '1.5', '-1', ' 1', '1e3', '9999999999999999999999.5', ['1'], ['finished_since' => '1']] as $value) {
            $page = $this->listPage($filter + ['finished_since' => $value]);
            $what = (string)json_encode($value);
            $xpath = $this->xpathOf($page['html']);

            $this->assertSame($every, $this->listedIds($page['html']), $what);
            $this->assertNull($this->elementAt($xpath, '//input[@name="finished_since"]'), $what);
            $this->assertNull($this->elementAt($xpath, '//*[@id="job-filter-finished-since"]'), $what);
            $this->assertFalse($this->offersClearFilters($this->listPage(['finished_since' => $value])['html']), $what);
        }
    }

    /**
     * regression: a filter sent as a list (?status[]=failed) answered 500, a
     * TypeError of JobSearchForm::load(). Every filter of the job list
     * ignores a list now, as it ignores any other value it cannot use.
     */
    public function testRegressionAFilterSentAsAListAnswered500(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        $job = $this->jobAt($template, $this->adminId, Job::STATUS_FAILED, time() - 600, time() - 500);
        $filter = ['launched_by' => (string)$this->adminId];

        foreach (['status', 'template_id', 'runner_group_id', 'date_from', 'date_to', 'has_changes', 'recovered', 'finished_since'] as $name) {
            $page = $this->listPage($filter + [$name => ['1']]);

            $this->assertSame([(int)$job->id], $this->listedIds($page['html']), $name);
        }
        $this->assertSame([(int)$job->id], $this->listedIds($this->listPage(['launched_by' => [(string)$this->adminId], 'status' => 'failed'])['html']));
    }

    /**
     * The list reads the recovering jobs of a page in one query: a page with
     * two failures and one with twelve cost the same.
     */
    public function testTheListAsksOnceForThePage(): void
    {
        $few = $this->createUser('jpr-few');
        $many = $this->createUser('jpr-many');
        $this->failuresOf($few, 2);
        $this->failuresOf($many, 12);
        $this->listPage(['launched_by' => (string)$few->id]);

        $this->assertSame(
            $this->queriesOf(fn () => $this->listPage(['launched_by' => (string)$few->id])),
            $this->queriesOf(fn () => $this->listPage(['launched_by' => (string)$many->id])),
            'a page with 2 recovered failures and one with 12'
        );
        $this->assertCount(12, $this->listPage(['launched_by' => (string)$many->id])['recoveredBy']);
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * $count failures that $user launched, of a template of their own, and
     * the success that recovered them all, which the admin launched.
     */
    private function failuresOf(User $user, int $count): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        for ($i = 0; $i < $count; $i++) {
            $this->jobAt($template, (int)$user->id, Job::STATUS_FAILED, time() - 900 - $i, time() - 800 - $i);
        }
        $this->jobAt($template, $this->adminId, Job::STATUS_SUCCEEDED, time() - 700, time() - 600);
    }

    /**
     * The job page of $job as the signed-in user sees it.
     *
     * @return array{html: string, recoveredBy: mixed}
     */
    private function jobPage(Job $job): array
    {
        $ctrl = $this->controller();
        $html = $this->withRealViews($ctrl, '/job/view?id=' . $job->id, static fn (): string => $ctrl->actionView((int)$job->id));

        return ['html' => $html, 'recoveredBy' => $this->passed($ctrl->capturedParams, 'recoveredBy')];
    }

    /**
     * The parameter $name the action handed its view.
     *
     * @param array<string, mixed> $params
     */
    private function passed(array $params, string $name): mixed
    {
        $this->assertArrayHasKey($name, $params);

        return $params[$name];
    }

    /**
     * The job list for the query $params, as the signed-in user sees it.
     *
     * @param array<string, mixed> $params
     * @return array{html: string, recoveredBy: mixed}
     */
    private function listPage(array $params): array
    {
        $this->setQueryParams($params);
        $ctrl = $this->controller();
        $html = $this->withRealViews($ctrl, '/job/index', static fn (): string => $ctrl->actionIndex());

        return ['html' => $html, 'recoveredBy' => $this->passed($ctrl->capturedParams, 'recoveredBy')];
    }

    private function controller(): JobController
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return new class ('job', $app) extends JobController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;

                return $this->renderPartial($view, $params);
            }
        };
    }

    /**
     * The IDs of the jobs the list shows, in its order.
     *
     * @return list<int>
     */
    private function listedIds(string $html): array
    {
        $ids = [];
        foreach ($this->xpathOf($html)->query('//tbody/tr/td[1]/a') ?: [] as $link) {
            $this->assertInstanceOf(\DOMElement::class, $link);
            $ids[] = (int)ltrim($this->textOf($link), '#');
        }

        return $ids;
    }

    private function selectedRecovered(string $html): string
    {
        $selected = $this->elementAt($this->xpathOf($html), '//select[@name="recovered"]/option[@selected]');
        $this->assertNotNull($this->elementAt($this->xpathOf($html), '//select[@name="recovered"]'), 'the filter');

        return $selected === null ? '' : $selected->getAttribute('value');
    }

    private function offersClearFilters(string $html): bool
    {
        return $this->xpathOf($html)->query('//a[normalize-space(.)="Clear filters"]')->length > 0;
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
