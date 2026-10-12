<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\runner;

use app\controllers\api\runner\JobsController;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Runner;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * POST /api/runner/v1/jobs/claim decides how password survey answers travel
 * from the capabilities the same request reports, and keeps its 200/204
 * contract when a job's answers cannot be decrypted.
 */
class JobsControllerSurveySecretsTest extends WebControllerTestCase
{
    private JobTemplate $template;
    private int $userId;
    private Runner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('runner-claim-secrets')->id;
        $this->template = $this->createPasswordSurveyTemplate($this->userId);
        $this->runner = $this->createRunner((int)$this->template->runner_group_id, $this->userId);
        $token = Runner::generateToken();
        $this->runner->token_hash = $token['hash'];
        $this->runner->save(false);
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token['raw']);
    }

    /**
     * A runner build that reports the capability with its claim gets the
     * answers in their own field, outside the command.
     */
    public function testARunnerReportingTheCapabilityGetsTheAnswersInTheirOwnField(): void
    {
        $job = $this->createJobWithSealedSecrets($this->template, $this->userId);

        $result = $this->claim(['capabilities' => ['vault_password_source', 'secret_extra_vars']]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($job->id, $result['data']['job_id']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], $result['data']['secret_extra_vars']);
        $this->assertSame('ansible-playbook', $result['data']['command'][0]);
        $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, implode(' ', $result['data']['command']));
    }

    /**
     * The same runner downgraded to a build without the capability: its
     * claim reports none, so the answers go inline with the verbosity pinned.
     */
    public function testARunnerReportingNoCapabilitiesGetsTheAnswersInline(): void
    {
        Runner::updateAll(['capabilities' => 'vault_password_source,secret_extra_vars'], ['id' => $this->runner->id]);
        $this->createJobWithSealedSecrets($this->template, $this->userId);

        $result = $this->claim([]);

        $this->assertArrayNotHasKey('secret_extra_vars', $result['data']);
        $this->assertSame(['/usr/bin/env', 'ANSIBLE_VERBOSITY=0', 'ansible-playbook'], array_slice($result['data']['command'], 0, 3));
        $this->assertStringContainsString(self::SURVEY_PASSWORD_ANSWER, (string)$result['data']['extra_vars']);
    }

    public function testAJobWhoseAnswersCannotBeDecryptedIsFailedAndTheAnswerIs204(): void
    {
        $broken = $this->createJobWithSealedSecrets($this->template, $this->userId);
        $broken->setSealedSecretExtraVars('not-a-ciphertext');
        $broken->save(false);

        $this->assertSame([], $this->claim(['capabilities' => ['secret_extra_vars']]));

        $this->assertSame(204, \Yii::$app->response->statusCode);
        $broken->refresh();
        $this->assertSame(Job::STATUS_FAILED, $broken->status);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function claim(array $body): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
        $result = (new JobsController('api/runner/v1/jobs', \Yii::$app))->runAction('claim');
        $this->assertIsArray($result);

        return $result;
    }
}
