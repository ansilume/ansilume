<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Job;
use app\models\JobSearchForm;
use app\tests\integration\DbTestCase;
use yii\data\ActiveDataProvider;

/**
 * Integration tests for JobSearchForm::search() — verifies filtering logic
 * against real database rows.
 */
class JobSearchFormIntegrationTest extends DbTestCase
{
    public function testSearchReturnsActiveDataProvider(): void
    {
        $form   = new JobSearchForm();
        $result = $form->search([]);

        $this->assertInstanceOf(ActiveDataProvider::class, $result);
    }

    public function testSearchWithNoFiltersReturnsAllJobs(): void
    {
        [$template, $user] = $this->makeFixtures();
        $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);

        $form     = new JobSearchForm();
        $provider = $form->search([]);

        $this->assertGreaterThanOrEqual(2, $provider->getTotalCount());
    }

    public function testSearchFiltersbyStatus(): void
    {
        [$template, $user] = $this->makeFixtures();
        $this->createJob($template->id, $user->id, Job::STATUS_QUEUED);
        $this->createJob($template->id, $user->id, Job::STATUS_SUCCEEDED);

        $form     = new JobSearchForm();
        $provider = $form->search(['status' => Job::STATUS_QUEUED]);

        foreach ($provider->getModels() as $job) {
            $this->assertSame(Job::STATUS_QUEUED, $job->status);
        }
    }

    public function testSearchFiltersByTemplateId(): void
    {
        [$templateA, $user] = $this->makeFixtures();
        [$templateB]        = $this->makeFixtures();
        $this->createJob($templateA->id, $user->id);
        $this->createJob($templateB->id, $user->id);

        $form     = new JobSearchForm();
        $provider = $form->search(['template_id' => $templateA->id]);

        foreach ($provider->getModels() as $job) {
            $this->assertSame($templateA->id, $job->job_template_id);
        }
    }

    public function testSearchFiltersByLaunchedBy(): void
    {
        [$template, $userA] = $this->makeFixtures();
        $userB = $this->createUser('other');
        $this->createJob($template->id, $userA->id);
        $this->createJob($template->id, $userB->id);

        $form     = new JobSearchForm();
        $provider = $form->search(['launched_by' => $userA->id]);

        foreach ($provider->getModels() as $job) {
            $this->assertSame($userA->id, $job->launched_by);
        }
    }

    public function testSearchWithDateFromFiltersOldJobs(): void
    {
        [$template, $user] = $this->makeFixtures();
        $this->createJob($template->id, $user->id);

        // Tomorrow's date — no jobs created today should appear
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $form     = new JobSearchForm();
        $provider = $form->search(['date_from' => $tomorrow]);

        $ids = array_column($provider->getModels(), 'id');
        $this->assertEmpty($ids, 'No jobs should match a future date_from');
    }

    public function testSearchWithDateToFiltersNewJobs(): void
    {
        [$template, $user] = $this->makeFixtures();
        $this->createJob($template->id, $user->id);

        // Yesterday's date — jobs created today should not appear
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $form      = new JobSearchForm();
        $provider  = $form->search(['date_to' => $yesterday]);

        $ids = array_column($provider->getModels(), 'id');
        $this->assertEmpty($ids, 'No jobs should match a past date_to');
    }

    public function testSearchFiltersByRunnerGroupId(): void
    {
        [$templateA, $user] = $this->makeFixtures();
        [$templateB]        = $this->makeFixtures();
        $this->createJob($templateA->id, $user->id);
        $this->createJob($templateB->id, $user->id);

        $groupId  = $templateA->runner_group_id;
        $form     = new JobSearchForm();
        $provider = $form->search(['runner_group_id' => (string)$groupId]);

        foreach ($provider->getModels() as $job) {
            $this->assertSame($groupId, $job->jobTemplate->runner_group_id);
        }
    }

    public function testSearchFiltersJobsWithChanges(): void
    {
        [$template, $user] = $this->makeFixtures();
        $jobWithChanges    = $this->createJob($template->id, $user->id, Job::STATUS_SUCCEEDED);
        $jobNoChanges      = $this->createJob($template->id, $user->id, Job::STATUS_SUCCEEDED);

        $jobWithChanges->has_changes = 1;
        $jobWithChanges->save(false);

        $form     = new JobSearchForm();
        $provider = $form->search(['has_changes' => '1']);

        $ids = array_column($provider->getModels(), 'id');
        $this->assertContains($jobWithChanges->id, $ids);
        $this->assertNotContains($jobNoChanges->id, $ids);
    }

    public function testSearchFiltersJobsWithoutChanges(): void
    {
        [$template, $user] = $this->makeFixtures();
        $jobWithChanges    = $this->createJob($template->id, $user->id, Job::STATUS_SUCCEEDED);
        $jobNoChanges      = $this->createJob($template->id, $user->id, Job::STATUS_SUCCEEDED);

        $jobWithChanges->has_changes = 1;
        $jobWithChanges->save(false);

        $form     = new JobSearchForm();
        $provider = $form->search(['has_changes' => '0']);

        $ids = array_column($provider->getModels(), 'id');
        $this->assertContains($jobNoChanges->id, $ids);
        $this->assertNotContains($jobWithChanges->id, $ids);
    }

    public function testSearchWithEmptyStringParamsDoesNotThrow(): void
    {
        $form   = new JobSearchForm();
        $result = $form->search([
            'status'          => '',
            'template_id'     => '',
            'launched_by'     => '',
            'runner_group_id' => '',
            'date_from'       => '',
            'date_to'         => '',
            'has_changes'     => '',
        ]);
        $this->assertInstanceOf(ActiveDataProvider::class, $result);
    }

    public function testSearchIgnoresInvalidStatusFilter(): void
    {
        $form   = new JobSearchForm();
        // Invalid status should not throw, just ignore
        $result = $form->search(['status' => 'nonexistent_status']);
        $this->assertInstanceOf(ActiveDataProvider::class, $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function whitespaceOnlyDateProvider(): array
    {
        return [
            'a space' => [' '],
            'a tab' => ["\t"],
            'a line break' => ["\n"],
            'two spaces' => ['  '],
            'a NUL byte' => ["\0"],
            'whitespace and a NUL byte' => [" \t\n\r\v\0"],
            // trim() keeps the form feed, which strtotime() reads as no date on every version.
            'a form feed and a space' => ["\f "],
        ];
    }

    /**
     * Regression: a date_from of only whitespace emptied the job list on
     * PHP 8.3, where strtotime() read " ", "\t", "\n", "  " and "\f " as now
     * (created_at >= now), and was no filter on PHP 8.4 and later; with a
     * NUL byte the date rule threw a ValueError (500) on every version. It
     * is no filter on every version now: the job created a minute ago is
     * listed. On PHP 8.5, which the suites run, only the two sets with a NUL
     * byte fail on the unfixed code; the sets of whitespace pin the bug of
     * PHP 8.3, which the unfixed code has on PHP 8.3 only.
     *
     * @dataProvider whitespaceOnlyDateProvider
     */
    public function testRegressionAWhitespaceOnlyDateFromIsNoFilter(string $value): void
    {
        [$template, $user] = $this->makeFixtures();
        $job = $this->createJob($template->id, $user->id);
        $job->created_at = time() - 60;
        $job->save(false);

        $provider = (new JobSearchForm())->search(['date_from' => $value, 'launched_by' => (string)$user->id]);

        $this->assertSame([$job->id], array_column($provider->getModels(), 'id'));
    }

    /**
     * A date_to of only whitespace is no filter either: it was the end of
     * today (strtotime() of the whitespace and " 23:59:59"), on every
     * version. A job created tomorrow is listed.
     */
    public function testAWhitespaceOnlyDateToIsNoFilter(): void
    {
        [$template, $user] = $this->makeFixtures();
        $job = $this->createJob($template->id, $user->id);
        $job->created_at = (int)strtotime('tomorrow 12:00');
        $job->save(false);

        foreach ([' ', "\t", "\0"] as $value) {
            $provider = (new JobSearchForm())->search(['date_to' => $value, 'launched_by' => (string)$user->id]);

            $this->assertSame([$job->id], array_column($provider->getModels(), 'id'), (string)json_encode($value));
        }
    }

    /**
     * Regression: a date with a NUL byte inside threw a ValueError from the
     * date rule (500). It fails validation now, which the search ignores, and
     * is applied as strtotime() reads it, as any date in another form is:
     * "2020-01-01\0x" from 2020-01-01, so the job created a minute ago is
     * listed.
     */
    public function testRegressionADateWithANulByteInsideNoLongerThrows(): void
    {
        [$template, $user] = $this->makeFixtures();
        $job = $this->createJob($template->id, $user->id);
        $job->created_at = time() - 60;
        $job->save(false);

        $provider = (new JobSearchForm())->search(['date_from' => "2020-01-01\0x", 'launched_by' => (string)$user->id]);

        $this->assertSame([$job->id], array_column($provider->getModels(), 'id'));
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{0: \app\models\JobTemplate, 1: \app\models\User}
     */
    private function makeFixtures(): array
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        return [$template, $user];
    }
}
