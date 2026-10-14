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
 * on it. artifact_count is counted for the whole page in one query.
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

    // -- Helpers --------------------------------------------------------------

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
