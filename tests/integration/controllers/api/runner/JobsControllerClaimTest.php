<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\runner;

use app\controllers\api\runner\JobsController;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Runner;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * POST /api/runner/v1/jobs/claim keeps its contract (200 with a payload, or
 * 204) when a queued job cannot run: that job is failed on the server and
 * the runner gets the next one.
 */
class JobsControllerClaimTest extends WebControllerTestCase
{
    private JobTemplate $template;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('runner-claim')->id;
        $group = $this->createRunnerGroup($this->userId);
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$group->id,
            $this->userId
        );
        $runner = $this->createRunner((int)$group->id, $this->userId);
        $token = Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false);
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token['raw']);
    }

    public function testNothingQueuedIs204(): void
    {
        $this->assertSame([], $this->claim());
        $this->assertSame(204, \Yii::$app->response->statusCode);
    }

    public function testAJobWithAMissingCredentialIsFailedAndTheAnswerIs204(): void
    {
        $broken = $this->queuedJob(['credential_id' => 999999]);

        $this->assertSame([], $this->claim());

        $this->assertSame(204, \Yii::$app->response->statusCode);
        $broken->refresh();
        $this->assertSame(Job::STATUS_FAILED, $broken->status);
    }

    public function testTheRunnerGetsTheNextHealthyJob(): void
    {
        $broken = $this->queuedJob(['credential_ids' => [999999]]);
        $healthy = $this->queuedJob([]);

        $result = $this->claim();

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertTrue($result['ok']);
        $this->assertSame($healthy->id, $result['data']['job_id']);
        $broken->refresh();
        $this->assertSame(Job::STATUS_FAILED, $broken->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(): array
    {
        $result = (new JobsController('api/runner/v1/jobs', \Yii::$app))->runAction('claim');
        $this->assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function queuedJob(array $payload): Job
    {
        $job = $this->createJob((int)$this->template->id, $this->userId, Job::STATUS_QUEUED);
        $job->runner_payload = (string)json_encode($payload + [
            'project_id' => $this->template->project_id,
            'inventory_id' => $this->template->inventory_id,
        ]);
        $job->save(false);

        return $job;
    }
}
