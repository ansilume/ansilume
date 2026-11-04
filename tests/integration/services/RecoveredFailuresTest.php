<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AnalyticsQuery;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobTemplate;
use app\models\RunnerGroup;
use app\services\AnalyticsService;
use app\services\JobClaimService;
use app\services\JobCompletionService;
use app\services\RecoveredFailures;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;

/**
 * A failed or timed-out job is recovered once a later job of the same job
 * template succeeded: one that started after the failure finished, or in
 * the second it finished as a job of a higher ID, and was no dry run. The
 * recovering job is the first such job by start time (RecoveredFailures).
 * The dashboard, the job page, the job list, the jobs API and the
 * analytics' MTTR share this rule, so each case is checked in both forms:
 * recoveredBy(), which names the recovering job, and filterRecovered(),
 * which selects the failures that were or were not recovered.
 */
final class RecoveredFailuresTest extends DbTestCase
{
    use CountsQueries;

    /** The moment the jobs of a test are placed around. */
    private int $now;

    private int $userId;

    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();
        $this->userId = (int)$this->createUser('recovery')->id;
        $this->template = $this->template();
    }

    public function testALaterSuccessRecovers(): void
    {
        $failure = $this->job(Job::STATUS_FAILED, 600, 500);
        $success = $this->job(Job::STATUS_SUCCEEDED, 400, 300);

        $this->assertSame(
            [(int)$failure->id => (int)$success->id],
            RecoveredFailures::recoveredBy([$failure, $success]),
            'the success itself is no failure, so it is not in the map'
        );
        $this->assertRecovered([$failure], []);
    }

    /**
     * Review focus: a success that started before the failure finished, as
     * runs of a template overlap, does not recover it: it was already
     * running when the failure ended, so it is no later run.
     */
    public function testAnOverlappingSuccessDoesNotRecover(): void
    {
        $failure = $this->job(Job::STATUS_FAILED, 600, 100);
        $this->job(Job::STATUS_SUCCEEDED, 500, 200);

        $this->assertSame([], RecoveredFailures::recoveredBy([$failure]));
        $this->assertRecovered([], [$failure]);

        $later = $this->job(Job::STATUS_SUCCEEDED, 99, 40);
        $this->assertSame([(int)$failure->id => (int)$later->id], RecoveredFailures::recoveredBy([$failure]));
        $this->assertRecovered([$failure], []);
    }

    /**
     * Review finding: the docs said an overlapping success leaves the
     * failure open "as it is the newest outcome", which is not so when the
     * success finished last. The rule stays: a success that was already
     * running when the failure ended does not recover it, even when it
     * finished later and is the template's newest outcome.
     */
    public function testASuccessThatWasRunningWhenTheFailureEndedDoesNotRecoverItEvenWhenItFinishedLast(): void
    {
        $failure = $this->job(Job::STATUS_FAILED, 1000, 900);
        $this->job(Job::STATUS_SUCCEEDED, 950, 100);

        $this->assertSame([], RecoveredFailures::recoveredBy([$failure]));
        $this->assertRecovered([], [$failure]);
    }

    /**
     * Regression (review of the dashboard): a success that started in the
     * second the failure finished never recovered it, as every form
     * compared the start with the end strictly. That is a retry on the same
     * runner, which claims its next job right after it reported the
     * failure. Such a success recovers the failure when it is the later
     * job, of a higher ID; one of a lower ID that started in that second
     * does not, as it may have been running already. Of two failures that
     * finished in that second, it recovers the one before it only.
     */
    public function testRegressionASuccessThatStartedInTheSecondTheFailureFinishedRecoversIt(): void
    {
        $this->job(Job::STATUS_SUCCEEDED, 100, 50);
        $failure = $this->job(Job::STATUS_FAILED, 600, 100);

        $this->assertSame([], RecoveredFailures::recoveredBy([$failure]), 'an earlier job that started in that second');
        $this->assertRecovered([], [$failure]);

        $retry = $this->job(Job::STATUS_SUCCEEDED, 100, 60);
        $after = $this->job(Job::STATUS_FAILED, 300, 100);

        $this->assertSame([(int)$failure->id => (int)$retry->id], RecoveredFailures::recoveredBy([$failure, $after]));
        $this->assertRecovered([$failure], [$after]);
    }

    /**
     * Regression (review of the dashboard), as it happens: a runner claims
     * the first queued job of the template, reports it failed, and claims
     * the next queued job of the template in the same second, which
     * succeeds. The failure is recovered, in every form.
     */
    public function testRegressionARetryClaimedInTheSecondTheFailureFinishedRecoversIt(): void
    {
        [$failure, $retry] = $this->failureAndRetryInOneSecond();

        $this->assertSame([(int)$failure->id => (int)$retry->id], RecoveredFailures::recoveredBy([$failure, $retry]));
        $this->assertSame([(int)$failure->id], $this->idsOf(RecoveredFailures::filterRecovered(Job::find()->where(['id' => [(int)$failure->id, (int)$retry->id]]), true)));
        $this->assertSame([], $this->idsOf(RecoveredFailures::filterRecovered(Job::find()->where(['id' => (int)$failure->id]), false)));
    }

    /**
     * MTTR (AnalyticsService) follows the rule: a retry that started in the
     * second the failure finished recovered it after 0 seconds, where the
     * failure ran to the next later success before.
     */
    public function testRegressionMttrCountsARetryInTheSecondTheFailureFinished(): void
    {
        $this->job(Job::STATUS_FAILED, 600, 500);
        $this->job(Job::STATUS_SUCCEEDED, 500, 450);
        $this->job(Job::STATUS_FAILED, 400, 300);
        $this->job(Job::STATUS_SUCCEEDED, 200, 150);
        $admin = $this->createUser('recovery-mttr');
        $admin->is_superadmin = true;
        $admin->save(false);
        $query = new AnalyticsQuery();
        $query->date_from = date('Y-m-d', strtotime('-1 day'));
        $query->date_to = date('Y-m-d', strtotime('+1 day'));
        $query->template_id = (int)$this->template->id;
        $query->scopeUserId = (int)$admin->id;
        /** @var AnalyticsService $analytics */
        $analytics = \Yii::$app->get('analyticsService');

        $this->assertEqualsWithDelta(50.0, $analytics->summary($query)['mttr_seconds'], 0.01, '0 seconds to the retry, 100 to the next success');
    }

    /**
     * Review focus: a dry run checks and changes nothing, so it recovers
     * nothing, also when it came first.
     */
    public function testADryRunDoesNotRecover(): void
    {
        $failure = $this->job(Job::STATUS_FAILED, 600, 500);
        $this->job(Job::STATUS_SUCCEEDED, 400, 390, true);

        $this->assertSame([], RecoveredFailures::recoveredBy([$failure]));
        $this->assertRecovered([], [$failure]);

        $real = $this->job(Job::STATUS_SUCCEEDED, 300, 200);
        $this->assertSame([(int)$failure->id => (int)$real->id], RecoveredFailures::recoveredBy([$failure]));
        $this->assertRecovered([$failure], []);
    }

    /**
     * A failed dry run is a failure like any other: a later real success
     * recovers it.
     */
    public function testAFailedDryRunIsRecoveredByARealSuccess(): void
    {
        $failure = $this->job(Job::STATUS_FAILED, 600, 500, true);
        $success = $this->job(Job::STATUS_SUCCEEDED, 400, 300);

        $this->assertSame([(int)$failure->id => (int)$success->id], RecoveredFailures::recoveredBy([$failure]));
        $this->assertRecovered([$failure], []);
    }

    public function testAnotherTemplateDoesNotRecover(): void
    {
        $failure = $this->job(Job::STATUS_FAILED, 600, 500);
        $this->job(Job::STATUS_SUCCEEDED, 400, 300, false, $this->template());

        $this->assertSame([], RecoveredFailures::recoveredBy([$failure]));
        $this->assertRecovered([], [$failure]);
    }

    /**
     * Timed out is a terminal failure (Job::terminalFailureStatuses()), and
     * a later success recovers it. A canceled or rejected job is no failure
     * and is never recovered, nor listed by either filter.
     */
    public function testTimedOutCountsCanceledDoesNot(): void
    {
        $timedOut = $this->job(Job::STATUS_TIMED_OUT, 900, 800);
        $canceled = $this->job(Job::STATUS_CANCELED, 900, 800);
        $rejected = $this->job(Job::STATUS_REJECTED, 900, 800);
        $success = $this->job(Job::STATUS_SUCCEEDED, 400, 300);

        $this->assertSame([(int)$timedOut->id => (int)$success->id], RecoveredFailures::recoveredBy([$timedOut, $canceled, $rejected]));
        $this->assertRecovered([$timedOut], []);
    }

    /**
     * Only a succeeded job recovers: a later job in any other status,
     * finished or not, does not.
     */
    public function testOnlyASucceededJobRecovers(): void
    {
        $failure = $this->job(Job::STATUS_FAILED, 900, 800);
        $finished = [Job::STATUS_FAILED, Job::STATUS_TIMED_OUT, Job::STATUS_CANCELED, Job::STATUS_REJECTED];
        foreach (array_diff(Job::statuses(), [Job::STATUS_SUCCEEDED]) as $status) {
            $this->job($status, 400, in_array($status, $finished, true) ? 300 : null);
        }

        $this->assertSame([], RecoveredFailures::recoveredBy([$failure]));
        $this->assertNotContains((int)$failure->id, $this->idsOf(RecoveredFailures::filterRecovered($this->jobsOfTheTemplate(), true)));
    }

    /**
     * The recovering job is the first success by start time, not the one
     * with the lowest ID; of two that started in the same second, the one
     * with the lower ID.
     */
    public function testTheFirstRecoveringJobIsNamed(): void
    {
        $failure = $this->job(Job::STATUS_FAILED, 900, 800);
        $this->job(Job::STATUS_SUCCEEDED, 200, 100);
        $startedFirst = $this->job(Job::STATUS_SUCCEEDED, 300, 250);

        $this->assertSame([(int)$failure->id => (int)$startedFirst->id], RecoveredFailures::recoveredBy([$failure]));

        $other = $this->template();
        $tie = $this->job(Job::STATUS_FAILED, 700, 600, false, $other);
        $sameSecondFirst = $this->job(Job::STATUS_SUCCEEDED, 500, 400, false, $other);
        $this->job(Job::STATUS_SUCCEEDED, 500, 450, false, $other);
        $recovered = RecoveredFailures::recoveredBy([$tie, $failure]);
        ksort($recovered);
        $this->assertSame([(int)$failure->id => (int)$startedFirst->id, (int)$tie->id => (int)$sameSecondFirst->id], $recovered);
    }

    /**
     * Review focus: twenty failures take one query, as do two: a list page
     * asks once for all its jobs. Jobs without a failure ask nothing.
     */
    public function testRecoveredByTakesOneQuery(): void
    {
        [$fewJobs] = $this->recoveredFailures(2);
        [$manyJobs, $many] = $this->recoveredFailures(20);
        $success = $this->job(Job::STATUS_SUCCEEDED, 100, 50);
        $canceled = $this->job(Job::STATUS_CANCELED, 100, 50);
        RecoveredFailures::recoveredBy($fewJobs);

        $this->assertSame(1, $this->queriesOf(fn () => RecoveredFailures::recoveredBy($fewJobs)), 'two failures');
        $this->assertSame(1, $this->queriesOf(fn () => RecoveredFailures::recoveredBy($manyJobs)), 'twenty failures');
        $this->assertSame(0, $this->queriesOf(fn () => RecoveredFailures::recoveredBy([$success, $canceled])), 'no failure');
        $this->assertSame(0, $this->queriesOf(fn () => RecoveredFailures::recoveredBy([])), 'no job');

        $recovered = RecoveredFailures::recoveredBy($manyJobs);
        ksort($recovered);
        $this->assertSame($many, $recovered, 'each failure with its own recovering job');
    }

    /**
     * Review focus: a job without a job template (the placeholder of a
     * workflow approval step, or the history of a purged template) is never
     * recovered, by another such job neither, and asking for it is no
     * error. Nor is a failure without an end.
     */
    public function testAJobWithoutTemplateIsNeverRecovered(): void
    {
        $orphan = $this->job(Job::STATUS_FAILED, 900, 800);
        $orphan->job_template_id = null;
        $orphan->save(false);
        $orphanSuccess = $this->job(Job::STATUS_SUCCEEDED, 400, 300);
        $orphanSuccess->job_template_id = null;
        $orphanSuccess->save(false);
        $unfinished = $this->job(Job::STATUS_FAILED, 900, null);

        $this->assertSame([], RecoveredFailures::recoveredBy([$orphan, $unfinished]));
        $ids = [(int)$orphan->id, (int)$unfinished->id];
        $this->assertSame([], $this->idsOf(RecoveredFailures::filterRecovered(Job::find()->where(['id' => $ids]), true)));
        $this->assertSame($ids, $this->idsOf(RecoveredFailures::filterRecovered(Job::find()->where(['id' => $ids]), false)));
    }

    /**
     * filterRecovered() keeps the terminal failures that were recovered, or
     * those that were not: never a job of another status.
     */
    public function testFilterRecoveredKeepsOnlyTheFailuresAsked(): void
    {
        $recovered = $this->job(Job::STATUS_FAILED, 900, 800);
        $recoveredTimeout = $this->job(Job::STATUS_TIMED_OUT, 900, 850);
        $this->job(Job::STATUS_CANCELED, 900, 800);
        $this->job(Job::STATUS_SUCCEEDED, 700, 600);
        $open = $this->job(Job::STATUS_FAILED, 500, 400);
        $openTimeout = $this->job(Job::STATUS_TIMED_OUT, 500, 450);

        $this->assertSame(
            [(int)$recovered->id, (int)$recoveredTimeout->id],
            $this->idsOf(RecoveredFailures::filterRecovered($this->jobsOfTheTemplate(), true)),
            'the recovered failures: neither the canceled job nor the success'
        );
        $this->assertSame(
            [(int)$open->id, (int)$openTimeout->id],
            $this->idsOf(RecoveredFailures::filterRecovered($this->jobsOfTheTemplate(), false)),
            'the failures no later run recovered: neither the canceled job nor the success'
        );
    }

    /**
     * filterRecovered() refers to the jobs of the query by its alias, so a
     * query that names its table otherwise keeps working, and refuses an
     * alias that is no plain name.
     */
    public function testFilterRecoveredTakesTheAliasOfTheQuery(): void
    {
        $recovered = $this->job(Job::STATUS_FAILED, 900, 800);
        $this->job(Job::STATUS_SUCCEEDED, 700, 600);

        $query = Job::find()->alias('j')->where(['j.job_template_id' => (int)$this->template->id]);

        $this->assertSame([(int)$recovered->id], $this->idsOf(RecoveredFailures::filterRecovered($query, true, 'j')));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(RecoveredFailures::NOT_AN_ALIAS, 'j; --'));
        RecoveredFailures::filterRecovered(Job::find(), true, 'j; --');
    }

    /**
     * The condition MTTR uses: the aliases go into SQL as they are, so
     * anything but a plain name is refused, and so is one alias for both.
     */
    public function testRecoveryConditionRefusesAnAliasThatIsNoName(): void
    {
        $this->assertSame(
            "s.job_template_id = f.job_template_id AND s.status = 'succeeded' AND s.check_mode = 0"
            . ' AND s.started_at >= f.finished_at AND (s.started_at > f.finished_at OR s.id > f.id)',
            RecoveredFailures::recoveryCondition('f', 's')
        );
        $refused = [
            [['f.x', 's'], sprintf(RecoveredFailures::NOT_AN_ALIAS, 'f.x')],
            [['f', 's; DROP TABLE job'], sprintf(RecoveredFailures::NOT_AN_ALIAS, 's; DROP TABLE job')],
            [['f', ''], sprintf(RecoveredFailures::NOT_AN_ALIAS, '')],
            [['1f', 's'], sprintf(RecoveredFailures::NOT_AN_ALIAS, '1f')],
            [['job', 'job'], RecoveredFailures::SAME_ALIAS],
        ];
        foreach ($refused as [[$failed, $success], $message]) {
            try {
                RecoveredFailures::recoveryCondition($failed, $success);
                $this->fail('refused: ' . json_encode([$failed, $success]));
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * filterRecovered() lists exactly $recovered among the failures of the
     * test's template when asked for the recovered ones, and exactly
     * $open when asked for the others.
     *
     * @param list<Job> $recovered
     * @param list<Job> $open
     */
    private function assertRecovered(array $recovered, array $open): void
    {
        $ids = static fn (array $jobs): array => array_map(static fn (Job $job): int => (int)$job->id, $jobs);
        $this->assertSame($ids($recovered), $this->idsOf(RecoveredFailures::filterRecovered($this->jobsOfTheTemplate(), true)), 'recovered');
        $this->assertSame($ids($open), $this->idsOf(RecoveredFailures::filterRecovered($this->jobsOfTheTemplate(), false)), 'not recovered');
    }

    /**
     * @return \yii\db\ActiveQuery<Job>
     */
    private function jobsOfTheTemplate(): \yii\db\ActiveQuery
    {
        return Job::find()->where(['job_template_id' => (int)$this->template->id]);
    }

    private function template(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
    }

    /**
     * A job of $template (else the test's), started $startedAgo seconds
     * before the test's now and finished $finishedAgo seconds before it
     * (null: not finished).
     */
    private function job(string $status, int $startedAgo, ?int $finishedAgo, bool $dryRun = false, ?JobTemplate $template = null): Job
    {
        $job = $this->createJob((int)($template ?? $this->template)->id, $this->userId, $status);
        $job->check_mode = $dryRun ? 1 : 0;
        $job->started_at = $this->now - $startedAgo;
        $job->finished_at = $finishedAgo === null ? null : $this->now - $finishedAgo;
        $job->save(false);

        return $job;
    }

    /**
     * $count failures, each of a template of its own and recovered by a
     * success of that template.
     *
     * @return array{0: list<Job>, 1: array<int, int>} the failures, and each failure's ID => its recovering job's ID
     */
    private function recoveredFailures(int $count): array
    {
        $failures = [];
        $recovered = [];
        for ($i = 0; $i < $count; $i++) {
            $template = $this->template();
            $failure = $this->job(Job::STATUS_FAILED, 900, 800, false, $template);
            $failures[] = $failure;
            $recovered[(int)$failure->id] = (int)$this->job(Job::STATUS_SUCCEEDED, 700 - $i, 600, false, $template)->id;
        }
        ksort($recovered);

        return [$failures, $recovered];
    }

    /**
     * @param \yii\db\ActiveQuery<Job> $query
     * @return list<int>
     */
    private function idsOf(\yii\db\ActiveQuery $query): array
    {
        return array_map('intval', $query->select('id')->orderBy(['id' => SORT_ASC])->column());
    }

    /**
     * Two queued jobs of a template of their own; a runner of its group
     * claims the first, reports it failed (exit code 2), claims the second
     * and reports it succeeded, with the real claim and completion
     * services, started at the beginning of a second, so that the second
     * claim comes in the second the failure finished, as it does on a
     * runner; tried again, in a new second, should it not.
     *
     * @return array{0: Job, 1: Job} the failure and the retry
     */
    private function failureAndRetryInOneSecond(): array
    {
        /** @var JobClaimService $claims */
        $claims = \Yii::$app->get('jobClaimService');
        /** @var JobCompletionService $completion */
        $completion = \Yii::$app->get('jobCompletionService');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $template = $this->template();
            $group = RunnerGroup::findOne((int)$template->runner_group_id);
            $this->assertInstanceOf(RunnerGroup::class, $group);
            $runner = $this->createRunner((int)$group->id, $this->userId);
            $this->createJob((int)$template->id, $this->userId);
            $this->createJob((int)$template->id, $this->userId);
            usleep((int)((1 - fmod(microtime(true), 1)) * 1000000) + 1000);

            $failure = $claims->claim($group, $runner);
            $this->assertInstanceOf(Job::class, $failure);
            $completion->complete($failure, 2);
            $retry = $claims->claim($group, $runner);
            $this->assertInstanceOf(Job::class, $retry);
            $this->hostSummaryOf($retry);
            $completion->complete($retry, 0);

            $failure->refresh();
            $retry->refresh();
            $this->assertSame([Job::STATUS_FAILED, Job::STATUS_SUCCEEDED], [$failure->status, $retry->status]);
            $this->assertGreaterThan((int)$failure->id, (int)$retry->id);
            if ((int)$retry->started_at === (int)$failure->finished_at) {
                return [$failure, $retry];
            }
        }
        $this->fail('The retry was never claimed in the second the failure finished.');
    }

    /** A host that $job ran on: without one, an exit code 0 is no success (JobCompletionService). */
    private function hostSummaryOf(Job $job): void
    {
        $summary = new JobHostSummary();
        $summary->job_id = (int)$job->id;
        $summary->host = 'localhost';
        $summary->ok = 1;
        $summary->changed = 0;
        $summary->failed = 0;
        $summary->unreachable = 0;
        $summary->skipped = 0;
        $summary->rescued = 0;
        $summary->created_at = time();
        $summary->save(false);
    }
}
