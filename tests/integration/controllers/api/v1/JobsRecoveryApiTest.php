<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobsController;
use app\models\Job;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\RecoveryFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * GET /api/v1/jobs and GET /api/v1/jobs/{id}: every job resource has
 * recovered_by_job_id, the ID of the later run of the same job template
 * that recovered a failed or timed-out job (RecoveredFailures), and null
 * for every other job. GET /api/v1/jobs takes recovered=1 for the
 * recovered failures and recovered=0 for those no later run recovered, and
 * finished_since for the jobs that finished at a Unix time or later; as
 * every filter of the list, any other value is ignored, a list included.
 */
final class JobsRecoveryApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RecoveryFixtures;

    private User $admin;

    private int $adminId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('jobs-recovery-admin', 'admin');
        $this->adminId = (int)$this->admin->id;
        $this->loginAs($this->admin);
    }

    public function testTheApiReturnsRecoveredByJobId(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        [$recovered, $success] = $this->recoveredFailureAt($template, $this->adminId, time() - 7200);
        [$timedOut, $later] = $this->recoveredFailureAt($template, $this->adminId, time() - 3600, Job::STATUS_TIMED_OUT);
        $open = $this->jobAt($template, $this->adminId, Job::STATUS_FAILED, time() - 600, time() - 500);
        $canceled = $this->jobAt($template, $this->adminId, Job::STATUS_CANCELED, time() - 400, time() - 300);
        $expected = [
            (int)$canceled->id => null,
            (int)$open->id => null,
            (int)$later->id => null,
            (int)$timedOut->id => (int)$later->id,
            (int)$success->id => null,
            (int)$recovered->id => (int)$success->id,
        ];

        $this->assertSame($expected, $this->recoveredByOfTheList(['template_id' => (string)$template->id]), 'the list, newest first');
        foreach ($expected as $id => $recoveredBy) {
            $detail = $this->controller()->actionView($id);
            $this->assertArrayHasKey('data', $detail);
            $this->assertIsArray($detail['data']);
            $this->assertArrayHasKey('recovered_by_job_id', $detail['data'], 'job #' . $id);
            $this->assertSame($recoveredBy, $detail['data']['recovered_by_job_id'], 'job #' . $id);
        }
    }

    /**
     * recovered=1 lists the recovered failures, recovered=0 the failures no
     * later run recovered (a dry run or an overlapping success recovers
     * nothing); neither lists a job that is no failure.
     */
    public function testTheApiFilterSelectsTheFailuresAsked(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        [$recovered, $success] = $this->recoveredFailureAt($template, $this->adminId, time() - 7200);
        $open = $this->jobAt($template, $this->adminId, Job::STATUS_TIMED_OUT, time() - 900, time() - 600);
        $this->jobAt($template, $this->adminId, Job::STATUS_SUCCEEDED, time() - 700, time() - 650);
        $this->jobAt($template, $this->adminId, Job::STATUS_SUCCEEDED, time() - 500, time() - 450, true);
        $filter = ['template_id' => (string)$template->id];

        $this->assertSame([(int)$recovered->id => (int)$success->id], $this->recoveredByOfTheList($filter + ['recovered' => '1']));
        $this->assertSame([(int)$open->id => null], $this->recoveredByOfTheList($filter + ['recovered' => '0']));
        $this->assertSame(1, $this->list($filter + ['recovered' => '0'])['meta']['total']);
    }

    /**
     * Every other value of recovered is no filter, as for every filter of
     * the list ("none is refused"): the same jobs as without it, and no
     * error, also for a list (?recovered[]=1), which answered 500 for every
     * filter up to 2.14.0.
     */
    public function testTheApiFilterIgnoresInvalidValues(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        $this->recoveredFailureAt($template, $this->adminId, time() - 7200);
        $this->jobAt($template, $this->adminId, Job::STATUS_FAILED, time() - 600, time() - 500);
        $filter = ['template_id' => (string)$template->id];
        $every = $this->recoveredByOfTheList($filter);
        $this->assertCount(3, $every);

        foreach (['', 'yes', 'no', '2', '-1', ' 1', '1.0', 'true', 'false', "1\0", ['1'], ['0'], ['recovered' => '1']] as $value) {
            $this->assertSame($every, $this->recoveredByOfTheList($filter + ['recovered' => $value]), (string)json_encode($value));
            $this->assertSame(200, \Yii::$app->response->statusCode);
        }
        foreach (['status', 'template_id', 'launched_by', 'runner_group_id', 'date_from', 'date_to', 'has_changes', 'finished_since'] as $name) {
            $this->assertSame($every, $this->recoveredByOfTheList($filter + [$name => ['1']]), $name . ' as a list');
        }
    }

    /**
     * finished_since lists the jobs that finished at that Unix time or
     * later, not one that has not finished; with recovered=1, as the
     * dashboard's link passes them, the recovered failures of that window
     * only, which meta.total counts.
     */
    public function testTheApiFiltersByFinishedSince(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        $since = time() - 86400;
        [$recent, $recentSuccess] = $this->recoveredFailureAt($template, $this->adminId, time() - 3600);
        [$old, $oldSuccess] = $this->recoveredFailureAt($template, $this->adminId, time() - 3 * 86400);
        $running = $this->jobAt($template, $this->adminId, Job::STATUS_RUNNING, time() - 60, null);
        $filter = ['template_id' => (string)$template->id];

        $this->assertSame(
            [(int)$running->id, (int)$oldSuccess->id, (int)$old->id, (int)$recentSuccess->id, (int)$recent->id],
            array_keys($this->recoveredByOfTheList($filter))
        );
        $this->assertSame(
            [(int)$recentSuccess->id => null, (int)$recent->id => (int)$recentSuccess->id],
            $this->recoveredByOfTheList($filter + ['finished_since' => (string)$since])
        );
        $window = $this->list($filter + ['recovered' => '1', 'finished_since' => (string)$since]);
        $this->assertSame([(int)$recent->id], array_map(static fn (mixed $job): int => is_array($job) ? (int)$job['id'] : 0, $window['data']));
        $this->assertSame(1, $window['meta']['total']);
    }

    /**
     * A finished_since that is no whole number of 0 or more is no filter,
     * as for every filter of the list, a list included: the same jobs as
     * without it, and no error.
     */
    public function testTheApiIgnoresAFinishedSinceThatIsNoUnixTime(): void
    {
        $template = $this->recoveryTemplate($this->adminId);
        $this->recoveredFailureAt($template, $this->adminId, time() - 3 * 86400);
        $this->jobAt($template, $this->adminId, Job::STATUS_RUNNING, time() - 60, null);
        $filter = ['template_id' => (string)$template->id];
        $every = $this->recoveredByOfTheList($filter);
        $this->assertCount(3, $every);

        foreach (['', 'yesterday', '1.5', '-1', ' 1', '1e3', "1\0", ['1'], ['finished_since' => '1']] as $value) {
            $this->assertSame($every, $this->recoveredByOfTheList($filter + ['finished_since' => $value]), (string)json_encode($value));
            $this->assertSame(200, \Yii::$app->response->statusCode);
        }
    }

    /**
     * Whoever sees the failure sees which job recovered it: both belong to
     * the same job template. A member of a team sees it for a template of
     * the team's project and for one that no team has, and does not see the
     * failures of another team's project at all.
     */
    public function testAMemberSeesTheRecoveringJobOfWhatTheyMaySee(): void
    {
        $s = $this->teamScope('viewer');
        $expected = [];
        foreach (['own', 'foreign', 'open'] as $key) {
            [$failure, $success] = $this->recoveredFailureAt($s[$key], $this->adminId, time() - 900);
            $expected[$key] = [(int)$failure->id, (int)$success->id];
        }
        $this->loginAs($s['member']);

        $listed = $this->recoveredByOfTheList(['recovered' => '1']);

        $this->assertSame([$expected['open'][0] => $expected['open'][1], $expected['own'][0] => $expected['own'][1]], $listed);
        $this->assertSame($expected['own'][1], $this->controller()->actionView($expected['own'][0])['data']['recovered_by_job_id'] ?? 'none');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * recovered_by_job_id of each job GET /api/v1/jobs lists for $params,
     * by job ID, in the list's order.
     *
     * @param array<string, mixed> $params
     * @return array<int, int|null>
     */
    private function recoveredByOfTheList(array $params): array
    {
        $listed = [];
        foreach ($this->list($params)['data'] as $job) {
            $this->assertIsArray($job);
            $this->assertArrayHasKey('recovered_by_job_id', $job);
            $listed[(int)$job['id']] = $job['recovered_by_job_id'];
        }

        return $listed;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    private function list(array $params): array
    {
        $this->setQueryParams($params);

        return $this->controller()->actionIndex();
    }

    private function controller(): JobsController
    {
        return new JobsController('api/v1/jobs', \Yii::$app);
    }
}
