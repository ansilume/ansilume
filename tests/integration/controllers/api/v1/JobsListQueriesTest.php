<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobsController;
use app\models\Job;
use app\models\JobArtifact;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;

/**
 * GET /api/v1/jobs: the cost of a page of jobs does not grow with the jobs
 * on it. artifact_count is counted for the whole page in one query, the
 * inventories the jobs were launched with (inventory_id, inventory_name)
 * are read for the whole page in one query, and so are the runs that
 * recovered its failures (recovered_by_job_id).
 */
final class JobsListQueriesTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use CountsQueries;

    /**
     * regression: the list counted artifact_count with one query per job.
     * A page of 2 jobs and a page of 12 cost the same now, and each job
     * still has its own count.
     */
    public function testRegressionTheListCountedArtifactsWithAQueryPerJob(): void
    {
        $admin = $this->createUserWithRole('jobs-list-admin', 'admin');
        $template = $this->template((int)$admin->id);
        $few = $this->createUser('jobs-list-few');
        $many = $this->createUser('jobs-list-many');
        $fewJobs = $this->jobsWithArtifacts($template, $few, [2, 0]);
        $this->jobsWithArtifacts($template, $many, [2, 0, 1, 3, 1, 1, 1, 1, 1, 1, 1, 1]);
        $this->loginAs($admin);
        $this->list($few);

        $this->assertSame(
            $this->queriesOf(fn () => $this->list($few)),
            $this->queriesOf(fn () => $this->list($many)),
            'a page of 2 jobs and a page of 12'
        );
        $this->assertCount(12, $this->list($many)['data']);
        $counts = [];
        foreach ($this->list($few)['data'] as $job) {
            $this->assertIsArray($job);
            $counts[$job['id']] = $job['artifact_count'];
        }
        $this->assertSame([(int)$fewJobs[1]->id => 0, (int)$fewJobs[0]->id => 2], $counts, 'newest first, each with its own count');
    }

    /**
     * The inventories the jobs were launched with, as their runner payloads
     * record them, are read for the page in one query
     * (JobInventory::ofPayloads()): a page of 2 jobs and a page of 12, each
     * launched with an inventory of its own, cost the same, and each job
     * names its own inventory, by its name at launch.
     */
    public function testTheListReadsTheInventoriesOfItsJobsInOneQuery(): void
    {
        $admin = $this->createUserWithRole('jobs-list-admin', 'admin');
        $template = $this->template((int)$admin->id);
        $few = $this->createUser('jobs-list-few');
        $many = $this->createUser('jobs-list-many');
        $fewJobs = $this->jobsOnInventoriesOfTheirOwn($template, $few, 2);
        $this->jobsOnInventoriesOfTheirOwn($template, $many, 12);
        $this->loginAs($admin);
        $this->list($few);

        $this->assertSame(
            $this->queriesOf(fn () => $this->list($few)),
            $this->queriesOf(fn () => $this->list($many)),
            'a page of 2 jobs and a page of 12'
        );
        $this->assertCount(12, $this->list($many)['data']);
        $listed = [];
        foreach ($this->list($few)['data'] as $job) {
            $this->assertIsArray($job);
            $listed[$job['id']] = [$job['inventory_id'], $job['inventory_name']];
        }
        $expected = [];
        foreach (array_reverse($fewJobs) as [$job, $inventoryId, $name]) {
            $expected[(int)$job->id] = [$inventoryId, $name];
        }
        $this->assertSame($expected, $listed, 'newest first, each with its own inventory');
    }

    /**
     * recovered_by_job_id is read for the whole page in one query
     * (RecoveredFailures::recoveredBy()): a page of 2 recovered failures and
     * a page of 12 cost the same, and each failure names the run that
     * recovered it.
     */
    public function testTheListReadsTheRecoveringJobsOfItsFailuresInOneQuery(): void
    {
        $admin = $this->createUserWithRole('jobs-list-admin', 'admin');
        $template = $this->template((int)$admin->id);
        $few = $this->createUser('jobs-list-few');
        $many = $this->createUser('jobs-list-many');
        $fewRecovered = $this->recoveredFailuresOf($template, $few, (int)$admin->id, 2, time() - 20000);
        $this->recoveredFailuresOf($template, $many, (int)$admin->id, 12, time() - 10000);
        $this->loginAs($admin);
        $this->list($few);

        $this->assertSame(
            $this->queriesOf(fn () => $this->list($few)),
            $this->queriesOf(fn () => $this->list($many)),
            'a page of 2 recovered failures and a page of 12'
        );
        $listed = [];
        foreach ($this->list($few)['data'] as $job) {
            $this->assertIsArray($job);
            $listed[$job['id']] = $job['recovered_by_job_id'];
        }
        $this->assertSame(array_reverse($fewRecovered, true), $listed, 'newest first, each with the run that recovered it');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * $count failures of $template that $launcher launched, oldest first,
     * from $from on, each recovered by a success that $recoverer launched
     * ten seconds after it finished and before the next one failed.
     *
     * @return array<int, int> failure ID => recovering job ID
     */
    private function recoveredFailuresOf(JobTemplate $template, User $launcher, int $recoverer, int $count, int $from): array
    {
        $recovered = [];
        for ($i = 0; $i < $count; $i++) {
            $finishedAt = $from + $i * 100;
            $failure = $this->finishedJob($template, (int)$launcher->id, Job::STATUS_FAILED, $finishedAt);
            $recovered[(int)$failure->id] = (int)$this->finishedJob($template, $recoverer, Job::STATUS_SUCCEEDED, $finishedAt + 40)->id;
        }

        return $recovered;
    }

    private function finishedJob(JobTemplate $template, int $launchedBy, string $status, int $finishedAt): Job
    {
        $job = $this->createJob((int)$template->id, $launchedBy, $status);
        $job->started_at = $finishedAt - 30;
        $job->finished_at = $finishedAt;
        $job->save(false);

        return $job;
    }

    private function template(int $createdBy): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($createdBy)->id,
            (int)$this->createInventory($createdBy)->id,
            (int)$this->createRunnerGroup($createdBy)->id,
            $createdBy
        );
    }

    /**
     * One finished job of $template launched by $launcher per entry of
     * $artifacts, oldest first, each with that many artifacts.
     *
     * @param list<int> $artifacts
     * @return list<Job>
     */
    private function jobsWithArtifacts(JobTemplate $template, User $launcher, array $artifacts): array
    {
        $jobs = [];
        foreach ($artifacts as $count) {
            $job = $this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_SUCCEEDED);
            for ($i = 0; $i < $count; $i++) {
                $artifact = new JobArtifact();
                $artifact->job_id = (int)$job->id;
                $artifact->filename = 'report-' . $i . '.txt';
                $artifact->display_name = 'report-' . $i . '.txt';
                $artifact->mime_type = 'text/plain';
                $artifact->size_bytes = 10;
                $artifact->storage_path = '/tmp/ansilume-test-artifact-' . $job->id . '-' . $i;
                $artifact->created_at = time();
                $artifact->save(false);
            }
            $jobs[] = $job;
        }

        return $jobs;
    }

    /**
     * $count finished jobs of $template launched by $launcher, oldest first,
     * each launched with an inventory of its own; their runner payloads
     * record a name at launch (Job::PAYLOAD_INVENTORY_NAME) other than the
     * inventory's current one, as after a rename. Each with the inventory's
     * ID and its name at launch.
     *
     * @return list<array{0: Job, 1: int, 2: string}>
     */
    private function jobsOnInventoriesOfTheirOwn(JobTemplate $template, User $launcher, int $count): array
    {
        $jobs = [];
        for ($i = 0; $i < $count; $i++) {
            $inventory = $this->createInventory((int)$launcher->id);
            $name = 'jobs-list-at-launch-' . $i . '-' . uniqid();
            $job = $this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_SUCCEEDED);
            $job->runner_payload = (string)json_encode(['inventory_id' => (int)$inventory->id, Job::PAYLOAD_INVENTORY_NAME => $name]);
            $job->save(false);
            $jobs[] = [$job, (int)$inventory->id, $name];
        }

        return $jobs;
    }

    /**
     * GET /api/v1/jobs?launched_by= the jobs of $launcher, as the
     * signed-in user.
     *
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    private function list(User $launcher): array
    {
        $this->setQueryParams(['launched_by' => (string)$launcher->id]);

        return (new JobsController('api/v1/jobs', \Yii::$app))->actionIndex();
    }
}
