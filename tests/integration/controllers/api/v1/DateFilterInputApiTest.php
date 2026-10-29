<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\AnalyticsController;
use app\controllers\api\v1\JobsController;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The date filters of GET /api/v1/jobs and of the analytics reports
 * (GET /api/v1/analytics/*): whitespace around a date is trimmed, a date of
 * only whitespace is no filter, as an empty one is, on every PHP version,
 * and a date with a NUL byte answers no 500.
 *
 * Regression: strtotime() read " ", "\t" and "\n" as now on PHP 8.3 and as
 * no date on PHP 8.4 and later: GET /api/v1/jobs?date_from=%20 listed no job
 * on PHP 8.3. The analytics reports answered 422 for such a date ("The
 * format of Date From is invalid."). A date with a NUL byte, such as %00,
 * answered 500 on every version: the date rule's
 * DateTime::createFromFormat() throws a ValueError for it.
 */
final class DateFilterInputApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: string}>
     */
    public static function whitespaceOnlyDateProvider(): array
    {
        return [
            'a space (%20)' => [' '],
            'a tab (%09)' => ["\t"],
            'a NUL byte (%00)' => ["\0"],
        ];
    }

    /**
     * @dataProvider whitespaceOnlyDateProvider
     */
    public function testRegressionTheJobListTakesAWhitespaceOnlyDateAsNoFilter(string $value): void
    {
        $admin = $this->createUserWithRole('date-filter-admin', 'admin');
        $job = $this->finishedJob($this->template($admin), $admin, time() - 60);

        $result = $this->jobList($admin, ['date_from' => $value, 'date_to' => $value]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([(int)$job->id], array_column($result['data'], 'id'));
        $this->assertSame(1, $result['meta']['total']);
    }

    /**
     * The job list applies a date that fails validation as strtotime() reads
     * it, as before: "2020-01-01\0x" from 2020-01-01.
     */
    public function testRegressionTheJobListAnswersADateWithANulByteInside(): void
    {
        $admin = $this->createUserWithRole('date-filter-admin', 'admin');
        $job = $this->finishedJob($this->template($admin), $admin, time() - 60);

        $result = $this->jobList($admin, ['date_from' => "2020-01-01\0x"]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([(int)$job->id], array_column($result['data'], 'id'));
    }

    /**
     * No bound, as for an empty date: a job of more than a year ago counts.
     *
     * @dataProvider whitespaceOnlyDateProvider
     */
    public function testRegressionAnAnalyticsReportTakesAWhitespaceOnlyDateAsNoBound(string $value): void
    {
        $admin = $this->createUserWithRole('date-filter-admin', 'admin');
        $template = $this->template($admin);
        $this->finishedJob($template, $admin, time() - 400 * 86400);
        $this->loginAs($admin);
        $this->setQueryParams(['date_from' => $value, 'date_to' => $value, 'template_id' => (string)$template->id]);

        $result = $this->analytics()->actionSummary();

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame('', $result['data']['filters']['date_from']);
        $this->assertSame('', $result['data']['filters']['date_to']);
        $this->assertSame(1, $result['data']['result']['total_jobs']);
    }

    public function testAnAnalyticsReportTrimsTheDates(): void
    {
        $admin = $this->createUserWithRole('date-filter-admin', 'admin');
        $this->loginAs($admin);
        $this->setQueryParams(['date_from' => ' 2026-01-01', 'date_to' => "2026-01-31\t"]);

        $result = $this->analytics()->actionSummary();

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame('2026-01-01', $result['data']['filters']['date_from']);
        $this->assertSame('2026-01-31', $result['data']['filters']['date_to']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedDateProvider(): array
    {
        return [
            'a NUL byte inside' => ["2026-01\0-15", 'Date From is invalid.'],
            'a form feed, which the trim keeps' => ["\f", 'The format of Date From is invalid.'],
        ];
    }

    /**
     * What is left after the trim is still checked (422), and a NUL byte
     * inside a date answers 422 instead of 500.
     *
     * @dataProvider refusedDateProvider
     */
    public function testRegressionAnAnalyticsReportRefusesADateInAnotherForm(string $value, string $message): void
    {
        $admin = $this->createUserWithRole('date-filter-admin', 'admin');
        $this->loginAs($admin);
        $this->setQueryParams(['date_from' => $value, 'date_to' => '2026-01-31']);

        $result = $this->analytics()->actionSummary();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * GET /api/v1/jobs with $params and the jobs of $user, as $user.
     *
     * @param array<string, string> $params
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    private function jobList(User $user, array $params): array
    {
        $this->loginAs($user);
        $this->setQueryParams($params + ['launched_by' => (string)$user->id]);

        return (new JobsController('api/v1/jobs', \Yii::$app))->actionIndex();
    }

    private function analytics(): AnalyticsController
    {
        return new AnalyticsController('api/v1/analytics', \Yii::$app);
    }

    private function template(User $owner): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject((int)$owner->id)->id,
            (int)$this->createInventory((int)$owner->id)->id,
            (int)$this->createRunnerGroup((int)$owner->id)->id,
            (int)$owner->id
        );
    }

    /**
     * A succeeded job of $template launched by $launcher, created at $createdAt.
     */
    private function finishedJob(JobTemplate $template, User $launcher, int $createdAt): Job
    {
        $job = $this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_SUCCEEDED);
        $job->created_at = $createdAt;
        $job->started_at = $createdAt;
        $job->finished_at = $createdAt + 30;
        $job->save(false);

        return $job;
    }
}
