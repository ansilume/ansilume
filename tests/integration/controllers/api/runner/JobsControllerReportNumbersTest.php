<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\runner;

use app\controllers\api\runner\JobsController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobLog;
use app\models\JobTask;
use app\models\JobTemplate;
use app\models\Runner;
use app\services\RunnerReportNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\log\Logger;
use yii\web\JsonParser;

/**
 * The whole numbers of a runner's reports: the sequence of a log chunk
 * (POST /api/runner/v1/jobs/<id>/logs), the exit code of a completion
 * (.../complete) and the seq and duration_ms of each task result
 * (.../tasks). A number that the job log, the job record or the task list
 * cannot take is refused with 422 and the runner API's error body, before
 * anything is written, and nothing is logged as a failure; a whole number
 * they take is stored as before. The bodies are JSON texts that the
 * request's JSON parser decodes, so a number beyond the int range arrives as
 * the float the parser gives (1e999 as infinity).
 *
 * Regression: such a number answered 500 on PHP 8.5: the endpoint's (int)
 * cast warned "The float 1.0E+30 is not representable as an int, cast
 * occurred", and the error handler turned the warning into an exception. A
 * task list stopped at the task with such a number, with the tasks before it
 * saved and without host summaries. Up to PHP 8.4 the cast wrapped such a
 * number to an arbitrary int, and made 1e999 0: a completion with
 * "exit_code": 1e999 ended the job as succeeded.
 */
class JobsControllerReportNumbersTest extends WebControllerTestCase
{
    private JobTemplate $template;
    private int $userId;
    private Runner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('runner-numbers')->id;
        $group = $this->createRunnerGroup($this->userId);
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$group->id,
            $this->userId
        );
        $this->runner = $this->createRunner((int)$group->id, $this->userId);
        $token = Runner::generateToken();
        $this->runner->token_hash = $token['hash'];
        $this->runner->save(false);
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token['raw']);
    }

    /**
     * JSON numbers beyond the int range.
     *
     * @return array<string, array{0: string}>
     */
    public static function beyondTheIntRangeProvider(): array
    {
        return [
            '1e30' => ['1e30'],
            'one more than the largest int' => ['9223372036854775808'],
            '1e999, infinity' => ['1e999'],
        ];
    }

    // -- POST /api/runner/v1/jobs/<id>/logs ---------------------------------------

    /**
     * Regression: answered 500 on PHP 8.5 (ErrorException of the (int) cast);
     * up to 8.4 the chunk with 1e999 was stored at sequence 0, before the
     * job's first chunk.
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testALogChunkWithASequenceBeyondTheIntRangeIsRefused(string $number): void
    {
        $job = $this->runningJob();

        [$result, $status, $problems] = $this->post('logs', $job, '{"stream": "stdout", "content": "PLAY [all]", "sequence": ' . $number . '}');

        $this->assertSame(422, $status);
        $this->assertSame(['ok' => false, 'error' => RunnerReportNumbers::SEQUENCE_REFUSED], $result);
        $this->assertSame([], $this->logLinesOf($job), 'no log chunk was written');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no sequence the job log takes. Up to now a
     * fraction, text, a boolean and a list were stored at the sequence (int)
     * made of them, and a negative sequence or one beyond the column's range
     * failed the insert: 500.
     *
     * @return array<string, array{0: string}>
     */
    public static function noLogSequenceProvider(): array
    {
        return [
            'fraction' => ['5.5'],
            'decimal text' => ['"5.5"'],
            'text' => ['"abc"'],
            'empty text' => ['""'],
            'boolean' => ['true'],
            'list' => ['[5]'],
            'negative' => ['-1'],
            'beyond the job log\'s range' => ['4294967296'],
            'digits beyond the int range' => ['"99999999999999999999"'],
        ];
    }

    /**
     * @dataProvider noLogSequenceProvider
     */
    public function testALogChunkWithAnyOtherSequenceIsRefused(string $sequence): void
    {
        $job = $this->runningJob();

        [$result, $status, $problems] = $this->post('logs', $job, '{"stream": "stdout", "content": "PLAY [all]", "sequence": ' . $sequence . '}');

        $this->assertSame(422, $status);
        $this->assertSame(['ok' => false, 'error' => RunnerReportNumbers::SEQUENCE_REFUSED], $result);
        $this->assertSame([], $this->logLinesOf($job));
        $this->assertSame([], $problems);
    }

    /**
     * The body, the sequence the chunk is stored at.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function logSequenceProvider(): array
    {
        return [
            'whole number' => ['{"stream": "stdout", "content": "ok: [web1]", "sequence": 7}', 7],
            'digits' => ['{"stream": "stdout", "content": "ok: [web1]", "sequence": "07"}', 7],
            'whole float' => ['{"stream": "stdout", "content": "ok: [web1]", "sequence": 7.0}', 7],
            'the largest the job log takes' => ['{"stream": "stdout", "content": "ok: [web1]", "sequence": 4294967295}', 4294967295],
            'null, as no sequence' => ['{"stream": "stdout", "content": "ok: [web1]", "sequence": null}', 0],
            'no sequence' => ['{"stream": "stdout", "content": "ok: [web1]"}', 0],
        ];
    }

    /**
     * @dataProvider logSequenceProvider
     */
    public function testALogChunkWithAWholeSequenceIsStoredAsBefore(string $json, int $sequence): void
    {
        $job = $this->runningJob();

        [$result, $status, $problems] = $this->post('logs', $job, $json);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true], $result);
        /** @var list<JobLog> $logs */
        $logs = JobLog::find()->where(['job_id' => $job->id])->all();
        $this->assertCount(1, $logs);
        $this->assertSame($sequence, (int)$logs[0]->sequence);
        $this->assertSame('ok: [web1]', $logs[0]->content);
        $this->assertSame(JobLog::STREAM_STDOUT, $logs[0]->stream);
        $this->assertSame([], $problems);
    }

    /**
     * A chunk without content writes nothing, whatever its sequence, as up to
     * PHP 8.4. Regression: answered 500 on PHP 8.5, as the cast came first.
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnEmptyLogChunkIsIgnoredWhateverItsSequence(string $number): void
    {
        $job = $this->runningJob();

        [$result, $status] = $this->post('logs', $job, '{"stream": "stdout", "content": "", "sequence": ' . $number . '}');

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true], $result);
        $this->assertSame([], $this->logLinesOf($job));
    }

    // -- POST /api/runner/v1/jobs/<id>/complete -----------------------------------

    /**
     * Regression: answered 500 on PHP 8.5 (ErrorException of the (int) cast);
     * up to 8.4 a completion with 1e999 ended the job as succeeded with exit
     * code 0. The job keeps running: the runner's report is refused, and the
     * stale-job reclaim ends the job if no valid report follows.
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testACompletionWithAnExitCodeBeyondTheIntRangeIsRefused(string $number): void
    {
        $job = $this->runningJob();

        [$result, $status, $problems] = $this->post('complete', $job, '{"exit_code": ' . $number . ', "has_changes": true}');

        $this->assertSame(422, $status);
        $this->assertSame(['ok' => false, 'error' => RunnerReportNumbers::EXIT_CODE_REFUSED], $result);
        $this->assertJobStillRunning($job);
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no exit code the job record takes (-256 to 255).
     * Up to now (int) made a fraction, text and a boolean an exit code (false
     * and text: 0, a success), an exit code beyond the record's range was
     * written as it came, and one beyond the column's failed the update: 500.
     *
     * @return array<string, array{0: string}>
     */
    public static function noExitCodeProvider(): array
    {
        return [
            'fraction' => ['1.5'],
            'decimal text' => ['"1.5"'],
            'text' => ['"failed"'],
            'empty text' => ['""'],
            'true' => ['true'],
            'false' => ['false'],
            'list' => ['[1]'],
            'below the job record\'s range' => ['-257'],
            'beyond the job record\'s range' => ['256'],
            'beyond the column\'s range' => ['40000'],
        ];
    }

    /**
     * @dataProvider noExitCodeProvider
     */
    public function testACompletionWithAnyOtherExitCodeIsRefused(string $exitCode): void
    {
        $job = $this->runningJob();

        [$result, $status, $problems] = $this->post('complete', $job, '{"exit_code": ' . $exitCode . '}');

        $this->assertSame(422, $status);
        $this->assertSame(['ok' => false, 'error' => RunnerReportNumbers::EXIT_CODE_REFUSED], $result);
        $this->assertJobStillRunning($job);
        $this->assertSame([], $problems);
    }

    /**
     * The body, the status and exit code the job ends with.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function exitCodeProvider(): array
    {
        return [
            'success' => ['{"exit_code": 0, "has_changes": false}', Job::STATUS_SUCCEEDED, 0],
            'failure' => ['{"exit_code": 2, "has_changes": false}', Job::STATUS_FAILED, 2],
            'digits' => ['{"exit_code": "2"}', Job::STATUS_FAILED, 2],
            'whole float' => ['{"exit_code": 2.0}', Job::STATUS_FAILED, 2],
            'a process that could not be started' => ['{"exit_code": -1}', Job::STATUS_FAILED, -1],
            'the lowest the job record takes' => ['{"exit_code": -256}', Job::STATUS_FAILED, -256],
            'the highest the job record takes' => ['{"exit_code": 255}', Job::STATUS_FAILED, 255],
            'null, as no exit code' => ['{"exit_code": null}', Job::STATUS_SUCCEEDED, 0],
            'no exit code' => ['{"has_changes": false}', Job::STATUS_SUCCEEDED, 0],
        ];
    }

    /**
     * @dataProvider exitCodeProvider
     */
    public function testACompletionWithAWholeExitCodeEndsTheJobAsBefore(string $json, string $endsAs, int $exitCode): void
    {
        $job = $this->runningJob();

        [$result, $status, $problems] = $this->post('complete', $job, $json);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'data' => ['status' => $endsAs]], $result);
        $job->refresh();
        $this->assertSame($endsAs, $job->status);
        $this->assertSame($exitCode, (int)$job->exit_code);
        $this->assertNotNull($job->finished_at);
        $this->assertSame([], $problems);
    }

    /**
     * A timeout report ends the job as timed out whatever exit code it
     * carries, as up to PHP 8.4: the server writes -1. Regression: answered
     * 500 on PHP 8.5, as the cast came before the timeout was looked at.
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testATimeoutReportEndsTheJobAsTimedOutWhateverItsExitCode(string $number): void
    {
        $job = $this->runningJob();

        [$result, $status] = $this->post('complete', $job, '{"exit_code": ' . $number . ', "timed_out": true}');

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'data' => ['status' => Job::STATUS_TIMED_OUT]], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_TIMED_OUT, $job->status);
        $this->assertSame(-1, (int)$job->exit_code);
    }

    // -- POST /api/runner/v1/jobs/<id>/tasks --------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function taskNumberBeyondTheIntRangeProvider(): array
    {
        $cases = [];
        foreach (['seq', 'duration_ms'] as $field) {
            foreach (self::beyondTheIntRangeProvider() as $name => [$number]) {
                $cases[$field . ' ' . $name] = [$field, $number];
            }
        }

        return $cases;
    }

    /**
     * The list is refused whole: the task before the one with such a number
     * is not saved either, and the job keeps has_changes as it was.
     *
     * Regression: answered 500 on PHP 8.5 (ErrorException of the (int) cast),
     * with the tasks before that one saved and without host summaries; a job
     * whose first task carried such a number then ended as "did not execute
     * against any host".
     *
     * @dataProvider taskNumberBeyondTheIntRangeProvider
     */
    public function testATaskListWithANumberBeyondTheIntRangeIsRefusedWhole(string $field, string $number): void
    {
        $job = $this->runningJob(withTask: false);

        [$result, $status, $problems] = $this->post('tasks', $job, $this->taskListWith($field, $number));

        $this->assertSame(422, $status);
        $this->assertSame(['ok' => false, 'error' => sprintf(RunnerReportNumbers::TASK_NUMBER_REFUSED, 1, $field)], $result);
        $this->assertNoTaskResults($job);
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no whole number the task list takes. Up to now
     * (int) made a fraction, text and a boolean a number, and a number beyond
     * the column's range failed the insert: 500, with the tasks before it
     * saved.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noTaskNumberProvider(): array
    {
        $values = [
            'fraction' => '1.5',
            'decimal text' => '"1.5"',
            'text' => '"fast"',
            'empty text' => '""',
            'boolean' => 'true',
            'list' => '[1]',
            'below the column\'s range' => '-2147483649',
            'beyond the column\'s range' => '2147483648',
        ];
        $cases = [];
        foreach (['seq', 'duration_ms'] as $field) {
            foreach ($values as $name => $value) {
                $cases[$field . ' ' . $name] = [$field, $value];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider noTaskNumberProvider
     */
    public function testATaskListWithAnyOtherNumberIsRefusedWhole(string $field, string $value): void
    {
        $job = $this->runningJob(withTask: false);

        [$result, $status, $problems] = $this->post('tasks', $job, $this->taskListWith($field, $value));

        $this->assertSame(422, $status);
        $this->assertSame(['ok' => false, 'error' => sprintf(RunnerReportNumbers::TASK_NUMBER_REFUSED, 1, $field)], $result);
        $this->assertNoTaskResults($job);
        $this->assertSame([], $problems);
    }

    /**
     * Up to now an entry that is no object failed the request with a
     * TypeError (500), with the tasks before it saved.
     */
    public function testATaskListWithAnEntryThatIsNoObjectIsRefusedWhole(): void
    {
        $job = $this->runningJob(withTask: false);

        [$result, $status, $problems] = $this->post('tasks', $job, '{"tasks": [' . $this->taskJson(0) . ', "ok: [web1]"]}');

        $this->assertSame(422, $status);
        $this->assertSame(['ok' => false, 'error' => sprintf(RunnerReportNumbers::TASK_NOT_AN_OBJECT, 1)], $result);
        $this->assertNoTaskResults($job);
        $this->assertSame([], $problems);
    }

    public function testATaskListOfWholeNumbersIsSavedAsBefore(): void
    {
        $job = $this->runningJob(withTask: false);
        $json = '{"tasks": ['
            . '{"seq": 0, "name": "Gathering Facts", "action": "setup", "host": "web1", "status": "ok", "changed": false, "duration_ms": 812},'
            . '{"seq": "1", "name": "Install nginx", "action": "apt", "host": "web1", "status": "changed", "changed": true, "duration_ms": 2.0},'
            . '{"seq": 2, "name": "Clock stepped back", "action": "command", "host": "web2", "status": "ok", "changed": false, "duration_ms": -3},'
            . '{"seq": 2147483647, "name": "Largest", "action": "ping", "host": "web2", "status": "ok", "changed": false, "duration_ms": -2147483648},'
            . '{"seq": null, "name": "No numbers", "action": "ping", "host": "web3", "status": "ok"}'
            . ']}';

        [$result, $status, $problems] = $this->post('tasks', $job, $json);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'data' => ['saved' => 5]], $result);
        $this->assertSame(
            [[0, 'Gathering Facts', 812], [1, 'Install nginx', 2], [2, 'Clock stepped back', -3], [2147483647, 'Largest', -2147483648], [0, 'No numbers', 0]],
            array_map(
                static fn (JobTask $task): array => [(int)$task->sequence, $task->task_name, (int)$task->duration_ms],
                JobTask::find()->where(['job_id' => $job->id])->orderBy(['id' => SORT_ASC])->all()
            )
        );
        $this->assertSame(3, (int)JobHostSummary::find()->where(['job_id' => $job->id])->count());
        $job->refresh();
        $this->assertSame(1, (int)$job->has_changes);
        $this->assertSame([], $problems);
    }

    /** The answer for a body without a list of tasks stays 400. */
    public function testTasksThatAreNoListStayRefusedWith400(): void
    {
        $job = $this->runningJob(withTask: false);

        [$result, $status] = $this->post('tasks', $job, '{"tasks": 1e30}');

        $this->assertSame(400, $status);
        $this->assertSame(['ok' => false, 'error' => 'tasks must be an array'], $result);
        $this->assertNoTaskResults($job);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * A job this runner has claimed; with one task result unless told
     * otherwise, so a success report counts as having run against a host.
     */
    private function runningJob(bool $withTask = true): Job
    {
        $job = $this->createJob((int)$this->template->id, $this->userId, Job::STATUS_RUNNING);
        $job->runner_id = $this->runner->id;
        $job->started_at = time();
        $job->save(false);
        if ($withTask) {
            $task = new JobTask();
            $task->job_id = $job->id;
            $task->sequence = 0;
            $task->task_name = 'ping';
            $task->task_action = 'ping';
            $task->host = 'localhost';
            $task->status = 'ok';
            $task->changed = 0;
            $task->duration_ms = 1;
            $task->created_at = time();
            $task->save(false);
        }

        return $job;
    }

    /**
     * A task list of two: a changed task with whole numbers, then a task
     * whose $field is the JSON text $value.
     */
    private function taskListWith(string $field, string $value): string
    {
        $numbers = ['seq' => '1', 'duration_ms' => '40'];
        $numbers[$field] = $value;
        $second = sprintf(
            '{"seq": %s, "name": "Restart nginx", "action": "service", "host": "web1", "status": "ok", "changed": false, "duration_ms": %s}',
            $numbers['seq'],
            $numbers['duration_ms']
        );

        return '{"tasks": [' . $this->taskJson(0) . ', ' . $second . ']}';
    }

    private function taskJson(int $seq): string
    {
        return '{"seq": ' . $seq . ', "name": "Install nginx", "action": "apt", "host": "web1", "status": "changed", "changed": true, "duration_ms": 120}';
    }

    /**
     * POST /api/runner/v1/jobs/<id>/<action> of this runner with the JSON body
     * $json, parsed by the request's JSON parser as config/web.php sets it
     * up: what the endpoint answered, its status code, and the errors and
     * warnings logged meanwhile.
     *
     * @return array{0: array<string, mixed>, 1: int, 2: list<string>}
     */
    private function post(string $action, Job $job, string $json): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->parsers = ['application/json' => JsonParser::class];
        $request->headers->set('Content-Type', 'application/json');
        $request->setRawBody($json);
        $this->assertSame(json_decode($json, true), $request->getBodyParams(), 'the JSON parser decoded the body');
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = (new JobsController('api/runner/v1/jobs', $app))->runAction($action, ['id' => $job->id]);
        } finally {
            \Yii::setLogger($previous);
        }
        $this->assertIsArray($result);

        $problems = [];
        foreach ($logger->messages as $message) {
            if (in_array($message[1], [Logger::LEVEL_ERROR, Logger::LEVEL_WARNING], true)) {
                $problems[] = is_string($message[0]) ? $message[0] : get_debug_type($message[0]);
            }
        }
        /** @var \yii\web\Response $response */
        $response = \Yii::$app->response;

        return [$result, $response->statusCode, $problems];
    }

    private function assertJobStillRunning(Job $job): void
    {
        $job->refresh();
        $this->assertSame(Job::STATUS_RUNNING, $job->status, 'the job keeps running');
        $this->assertNull($job->exit_code);
        $this->assertNull($job->finished_at);
        $this->assertSame(0, (int)$job->has_changes);
        $this->assertSame([], $this->logLinesOf($job), 'no line was written to the job log');
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_JOB_FINISHED, 'object_type' => 'job', 'object_id' => $job->id])->exists(),
            'no job.finished audit entry'
        );
    }

    private function assertNoTaskResults(Job $job): void
    {
        $this->assertSame(0, (int)JobTask::find()->where(['job_id' => $job->id])->count(), 'no task was saved');
        $this->assertSame(0, (int)JobHostSummary::find()->where(['job_id' => $job->id])->count(), 'no host summary was saved');
        $job->refresh();
        $this->assertSame(0, (int)$job->has_changes);
    }

    /**
     * @return list<string>
     */
    private function logLinesOf(Job $job): array
    {
        /** @var list<JobLog> $logs */
        $logs = JobLog::find()->where(['job_id' => $job->id])->orderBy(['sequence' => SORT_ASC, 'id' => SORT_ASC])->all();

        return array_map(static fn (JobLog $log): string => (string)$log->content, $logs);
    }
}
