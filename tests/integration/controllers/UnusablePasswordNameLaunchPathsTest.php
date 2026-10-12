<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveySecrets;
use app\controllers\JobTemplateController;
use app\controllers\TriggerController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\services\JobLaunchService;
use app\services\LintService;
use app\services\ScheduleService;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\components\UnusablePasswordNames;
use yii\web\Response;

/**
 * A job template whose password field has a name that no caller sends
 * exactly is refused on every launch path, nothing is launched, and the
 * message shows the name with its invisible characters escaped: the web
 * launch form, job triggers and scheduled runs. (The jobs API is covered by
 * JobsControllerSurveySecretsTest, the launch service by
 * JobLaunchServiceSecretsTest.)
 *
 * Regression (V2-C1U): with a non-breaking space, a byte order mark or
 * another character outside ASCII in the name, the field counted as
 * usable, so an answer sent under the visible name "db_password" (by a
 * trigger, a schedule or the form's Advanced Overrides) was stored in
 * plaintext in the job and its runner payload.
 */
class UnusablePasswordNameLaunchPathsTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;
    use UnusablePasswordNames;

    private const ANSWER = 'unusable-name-answer-marker-7140';
    private const EXACT_NAME_ANSWER = 'unusable-name-exact-marker-3318';
    private const REFUSAL = 'Survey field %s has a name that cannot be used for a password answer; rename it in the survey before launching.';

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * Padding and line breaks, refused since the first fix, on these paths
     * too.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paddedNameProvider(): array
    {
        return [
            'line feed' => ["db_password\n", '"db_password\n"'],
            'trailing space' => ['db_password ', '"db_password "'],
            'tab' => ["db_password\t", '"db_password\t"'],
        ];
    }

    /**
     * The launch form posts the answer under the field's exact name
     * (survey[<name>], a line break as CRLF); Advanced Overrides carry what
     * the operator typed, the visible name. Both are refused with a flash.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testTheWebLaunchIsRefusedWithAFlash(string $name, string $quoted): void
    {
        $user = $this->createUser('unusable-name-web');
        $user->is_superadmin = true;
        $user->save(false);
        $this->loginAs($user);
        $template = $this->templateWithPasswordField((int)$user->id, $name);
        $this->swap('jobLaunchService', new JobLaunchService());
        $this->swap('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        $posts = [
            'the survey form' => ['survey' => [self::SURVEY_TEXT_FIELD => 'prod', (string)preg_replace('/\r?\n/', "\r\n", $name) => self::ANSWER]],
            'Advanced Overrides' => ['overrides' => ['extra_vars' => '{"db_password":"' . self::ANSWER . '"}']],
        ];

        foreach ($posts as $how => $post) {
            $this->setQueryParams(['id' => (string)$template->id]);
            $this->setPost($post);

            $result = $this->webController()->actionLaunch();

            $this->assertSame('rendered:launch', $result, $how);
            $this->assertSame('Launch failed: ' . sprintf(self::REFUSAL, $quoted), \Yii::$app->session->getFlash('danger', null, true), $how);
        }
        $this->assertNoJobOf($template);
    }

    /**
     * A job trigger sends the answer under the visible variable name: 422
     * with the reason, no job and no trigger.fired entry.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testAJobTriggerIs422(string $name, string $quoted): void
    {
        $this->swap('notificationDispatcher', new RecordingNotificationDispatcher());
        $user = $this->createUserWithRole('unusable-name-trigger', 'operator');
        $template = $this->templateWithPasswordField((int)$user->id, $name);
        $this->presentTriggerToken($template->generateTriggerToken((int)$user->id));
        \Yii::$app->request->setRawBody('{"extra_vars":{"app_env":"prod","db_password":"' . self::ANSWER . '"}}');

        $result = (new TriggerController('trigger', \Yii::$app))->actionFire();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => sprintf(self::REFUSAL, $quoted)], $result->data);
        $this->assertNoJobOf($template);
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_TRIGGER_FIRED, 'object_id' => $template->id])->exists(),
            'a refused launch is no trigger.fired'
        );
    }

    /**
     * A schedule sends the answers stored under the visible name: the run
     * fails like any refused launch, notified with the reason, and launches
     * no job. An answer stored under the exact name, as 2.8.1 stored it, is
     * sealed in the schedule first.
     *
     * Regression (R2V2-1): once such a name was unusable, the run left the
     * answer under the exact name in plaintext.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testAScheduledRunFailsWithTheReason(string $name, string $quoted): void
    {
        $dispatcher = new RecordingNotificationDispatcher();
        $this->swap('notificationDispatcher', $dispatcher);
        $user = $this->createUserWithRole('unusable-name-schedule', 'operator');
        $template = $this->templateWithPasswordField((int)$user->id, $name);
        $stored = (string)json_encode(['app_env' => 'prod', 'db_password' => self::ANSWER, $name => self::EXACT_NAME_ANSWER]);
        $schedule = $this->createPlainSchedule((int)$template->id, (int)$user->id, $stored, time() - 60);

        /** @var ScheduleService $service */
        $service = \Yii::$app->get('scheduleService');
        $service->runDue();

        $this->assertNoJobOf($template);
        $errors = [];
        foreach ($dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH) as $payload) {
            $about = is_array($payload['schedule'] ?? null) ? $payload['schedule'] : [];
            if (($about['id'] ?? null) === (string)$schedule->id) {
                $errors[] = $about['error'] ?? null;
            }
        }
        $this->assertSame([sprintf(self::REFUSAL, $quoted)], $errors);
        $this->assertSame([$name => self::EXACT_NAME_ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame(
            ['app_env' => 'prod', 'db_password' => self::ANSWER, $name => SurveySecrets::PLACEHOLDER],
            $this->storedExtraVarsOf($schedule),
            'the visible name is another key, not a password answer'
        );
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * A template saved before the survey rules, with a text field and a
     * password field named $name.
     */
    private function templateWithPasswordField(int $userId, string $name): JobTemplate
    {
        $template = $this->createPasswordSurveyTemplate($userId);
        $template->survey_fields = (string)json_encode([
            ['name' => self::SURVEY_TEXT_FIELD, 'type' => 'text'],
            ['name' => $name, 'type' => 'password'],
        ]);
        $this->assertTrue($template->save(false));

        return $template;
    }

    private function assertNoJobOf(JobTemplate $template): void
    {
        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists(), 'no job is launched');
    }

    private function swap(string $id, object $replacement): void
    {
        $components = \Yii::$app->getComponents(true);
        $this->swapped[] = [$id, $components[$id] ?? null];
        \Yii::$app->set($id, $replacement);
    }

    private function webController(): JobTemplateController
    {
        return new class ('job-template', \Yii::$app) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }
}
