<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\TriggerController;
use app\models\AuditLog;
use app\models\Job;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * A job trigger whose extra vars JSON allows but PHP cannot store: the body
 * {"extra_vars": {"db_password": 1e999}} decodes to INF, which json_encode()
 * cannot write. The launch is refused with 422 and the reason, like every
 * launch refusal the caller can fix, and nothing is launched.
 *
 * Regression: under a password field's key the trigger answered 500 "Launch
 * failed." (the sealing failed with a SurveySecretException); under another
 * key it answered 422 with "characters that are not valid UTF-8".
 */
class TriggerLaunchEncodingTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use TeamScopeFixtures;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapped[] = ['notificationDispatcher', \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null];
        \Yii::$app->set('notificationDispatcher', new RecordingNotificationDispatcher());
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bodyProvider(): array
    {
        return [
            'a password answer' => [
                '{"extra_vars":{"app_env":"prod","db_password":1e999}}',
                'The password answers cannot be stored: they contain a number too large for JSON.',
            ],
            'another key' => [
                '{"extra_vars":{"app_env":"prod","db_password":"trigger-answer-marker-6610","timeout_s":1e999}}',
                'The extra vars cannot be stored: they contain a number too large for JSON.',
            ],
        ];
    }

    /**
     * @dataProvider bodyProvider
     */
    public function testANumberTooLargeForJsonIs422AndLaunchesNothing(string $body, string $message): void
    {
        $user = $this->createUserWithRole('trigger_encoding', 'operator');
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $this->presentTriggerToken($template->generateTriggerToken((int)$user->id));
        \Yii::$app->request->setRawBody($body);

        $result = (new TriggerController('trigger', \Yii::$app))->actionFire();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => $message], $result->data);
        $this->assertStringNotContainsString('trigger-answer-marker-6610', (string)json_encode($result->data));
        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists(), 'no job is launched');
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_TRIGGER_FIRED, 'object_id' => $template->id])->exists(),
            'a refused launch is no trigger.fired'
        );
    }
}
