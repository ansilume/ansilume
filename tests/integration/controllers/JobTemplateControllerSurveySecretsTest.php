<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveySecrets;
use app\controllers\JobTemplateController;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use app\services\LaunchContext;
use yii\web\Response;

/**
 * The web launch and clone of job templates with password survey fields.
 */
class JobTemplateControllerSurveySecretsTest extends WebControllerTestCase
{
    private const ANSWER = 'web-launch-answer-marker-3361';

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

    // -- Launch: what reaches the service ---------------------------------------

    /**
     * Regression: the web launch passed every posted overrides[] key to the
     * launch service, so a request could hand it launch data meant for server
     * code only.
     */
    public function testOnlyTheFormsFieldsReachTheLaunchService(): void
    {
        $recorder = $this->recordingLaunchService();
        $template = $this->launchAs($this->createPasswordSurveyTemplate($this->adminId()), [
            'overrides' => [
                'extra_vars' => '{"env":"prod"}',
                'limit' => 'web',
                'verbosity' => '2',
                'check_mode' => '1',
                LaunchContext::KEY => 'posted',
                'secret_extra_vars' => ['x' => 'y'],
                'job_template_id' => '1',
            ],
            'survey' => [self::SURVEY_TEXT_FIELD => 'prod'],
        ]);

        $this->assertSame([[
            'template_id' => (int)$template->id,
            'overrides' => [
                'extra_vars' => '{"env":"prod"}',
                'limit' => 'web',
                'verbosity' => '2',
                'check_mode' => '1',
                'survey' => [self::SURVEY_TEXT_FIELD => 'prod'],
            ],
        ]], $recorder->calls);
    }

    /**
     * Regression: overrides[limit][]=x made the launch fail with "Array to
     * string conversion" (a 500). The form then dropped such a list and
     * launched the job with the template's limit and verbosity, where
     * POST /api/v1/jobs and the job trigger refuse it. It is refused now
     * with the job record's messages, and nothing is launched
     * (LaunchInputTest has every launch path).
     */
    public function testAListWhereTheFormSendsOneValueIsRefused(): void
    {
        $this->useRealLaunchService();
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->adminId())->id,
            (int)$this->createInventory($this->adminId())->id,
            (int)$this->createRunnerGroup($this->adminId())->id,
            $this->adminId()
        );

        $result = $this->launch($template, ['overrides' => ['limit' => ['x'], 'verbosity' => ['3']]]);

        $this->assertSame('rendered:launch', $result, 'the form again, no server error');
        $this->assertSame('Launch failed: Limit must be a string. Verbosity must be an integer.', \Yii::$app->session->getFlash('danger'));
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count(), 'nothing was launched');
    }

    public function testPostedOverridesThatAreNoListLaunchWithoutOverrides(): void
    {
        $recorder = $this->recordingLaunchService();

        $this->launchAs($this->createPasswordSurveyTemplate($this->adminId()), ['overrides' => 'text', 'survey' => 'text']);

        $this->assertSame([], $recorder->calls[0]['overrides']);
    }

    // -- Launch with the real service -------------------------------------------

    public function testAPasswordAnswerFromTheFormIsStoredOnlySealed(): void
    {
        $this->useRealLaunchService();
        $template = $this->createPasswordSurveyTemplate($this->adminId());

        $result = $this->launch($template, [
            'survey' => [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::ANSWER],
            'overrides' => ['extra_vars' => '', 'limit' => '', 'verbosity' => '0', 'check_mode' => '0'],
        ]);

        $this->assertInstanceOf(Response::class, $result);
        $job = $this->latestJobOf($template);
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode((string)$job->extra_vars, true));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
    }

    public function testARefusedLaunchIsFlashedWithItsReason(): void
    {
        $this->useRealLaunchService();
        $template = $this->createPasswordSurveyTemplate($this->adminId());

        $result = $this->launch($template, ['overrides' => ['extra_vars' => '{"db_password":"$encrypted$"}']]);

        $this->assertSame('rendered:launch', $result);
        $this->assertSame(
            'Launch failed: The value of "db_password" is the placeholder $encrypted$; send the real value.',
            \Yii::$app->session->getFlash('danger')
        );
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    /**
     * JSON allows 1e999 in Advanced Overrides; PHP reads it as INF and
     * cannot write it back.
     *
     * Regression: the flash called it "characters that are not valid UTF-8".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function numberTooLargeProvider(): array
    {
        return [
            'another key' => ['{"timeout_s": 1e999}', 'Launch failed: The extra vars cannot be stored: they contain a number too large for JSON.'],
            'a password answer' => ['{"db_password": 1e999}', 'Launch failed: The password answers cannot be stored: they contain a number too large for JSON.'],
        ];
    }

    /**
     * @dataProvider numberTooLargeProvider
     */
    public function testANumberTooLargeForJsonIsFlashedAndLaunchesNothing(string $extraVars, string $flash): void
    {
        $this->useRealLaunchService();
        $template = $this->createPasswordSurveyTemplate($this->adminId());

        $result = $this->launch($template, [
            'survey' => [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => 'typed-answer'],
            'overrides' => ['extra_vars' => $extraVars],
        ]);

        $this->assertSame('rendered:launch', $result);
        $this->assertSame($flash, \Yii::$app->session->getFlash('danger'));
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    // -- Edit -------------------------------------------------------------------

    /**
     * Regression: the edit form posts the stored Extra Vars as its editor
     * rewrote them on page load (JSON.stringify(JSON.parse(stored)): 1.0 as
     * 1, integer-like keys first), so a description-only edit of a legacy
     * template whose Extra Vars hold a password field's key was refused with
     * "Remove "db_password" from Extra Vars".
     */
    public function testADescriptionOnlyEditOfALegacyTemplateWithAPasswordKeySaves(): void
    {
        $this->useRealLaunchService();
        $template = $this->createPasswordSurveyTemplate($this->adminId());
        $template->extra_vars = '{"cpu_ratio": 1.0, "db_password": "legacy-default", "ports": {"web": 80, "22": "ssh"}}';
        $this->assertTrue($template->save(false));
        $this->setPost(['JobTemplate' => [
            'description' => 'edited in the form',
            'extra_vars' => '{"cpu_ratio":1,"db_password":"legacy-default","ports":{"22":"ssh","web":80}}',
            'survey_fields' => (string)$template->survey_fields,
        ]]);

        $ctrl = $this->controller();
        $result = $ctrl->actionUpdate((int)$template->id);

        $this->assertInstanceOf(Response::class, $result, 'the form is shown again with an error');
        $this->assertSame(['view', 'id' => $template->id], $ctrl->capturedRedirect);
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame('edited in the form', $stored->description);
    }

    // -- Clone ------------------------------------------------------------------

    /**
     * The survey rules compare a clone with its source: a template saved
     * before the rules (a name with a dash) still clones, unchanged.
     */
    public function testATemplateWithALegacySurveyClones(): void
    {
        $this->useRealLaunchService();
        $admin = $this->adminId();
        $source = $this->createJobTemplate(
            (int)$this->createProject($admin)->id,
            (int)$this->createInventory($admin)->id,
            (int)$this->createRunnerGroup($admin)->id,
            $admin
        );
        $legacy = '[{"name":"db-host","type":"text","default":"db1"},{"name":"db-host","type":"bogus"}]';
        $source->survey_fields = $legacy;
        $source->save(false);

        $ctrl = $this->controller();
        $result = $ctrl->actionClone((int)$source->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertIsArray($ctrl->capturedRedirect);
        $this->assertSame('update', $ctrl->capturedRedirect[0], (string)\Yii::$app->session->getFlash('danger'));
        $clone = JobTemplate::findOne($ctrl->capturedRedirect['id']);
        $this->assertNotNull($clone);
        $this->assertSame($legacy, $clone->survey_fields);
    }

    // -- Helpers ----------------------------------------------------------------

    private ?int $admin = null;

    private function adminId(): int
    {
        if ($this->admin === null) {
            $user = $this->createUser('web-survey');
            $user->is_superadmin = true;
            $user->save(false);
            $this->loginAs($user);
            $this->admin = (int)$user->id;
        }

        return $this->admin;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function launchAs(JobTemplate $template, array $post): JobTemplate
    {
        $this->launch($template, $post);

        return $template;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function launch(JobTemplate $template, array $post): Response|string
    {
        $this->setQueryParams(['id' => (string)$template->id]);
        $this->setPost($post);

        return $this->controller()->actionLaunch();
    }

    private function latestJobOf(JobTemplate $template): Job
    {
        /** @var Job|null $job */
        $job = Job::find()->where(['job_template_id' => $template->id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($job);

        return $job;
    }

    /**
     * @return object{calls: list<array{template_id: int, overrides: array<string, mixed>}>}
     */
    private function recordingLaunchService(): object
    {
        $service = new class () extends JobLaunchService {
            /** @var list<array{template_id: int, overrides: array<string, mixed>}> */
            public array $calls = [];

            public function launch(JobTemplate $template, int $launchedBy, array $overrides = []): Job
            {
                $this->calls[] = ['template_id' => (int)$template->id, 'overrides' => $overrides];
                $job = new Job();
                $job->job_template_id = $template->id;
                $job->launched_by = $launchedBy;
                $job->status = Job::STATUS_QUEUED;
                $job->timeout_minutes = 120;
                $job->has_changes = 0;
                $job->save(false);

                return $job;
            }
        };
        $this->swap('jobLaunchService', $service);

        return $service;
    }

    private function useRealLaunchService(): void
    {
        $this->swap('jobLaunchService', new JobLaunchService());
        $this->swap('lintService', new class () extends \app\services\LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
    }

    private function swap(string $id, object $replacement): void
    {
        $components = \Yii::$app->getComponents(true);
        $this->swapped[] = [$id, $components[$id] ?? null];
        \Yii::$app->set($id, $replacement);
    }

    private function controller(): JobTemplateController
    {
        $this->adminId();

        return new class ('job-template', \Yii::$app) extends JobTemplateController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                return new Response();
            }
        };
    }
}
