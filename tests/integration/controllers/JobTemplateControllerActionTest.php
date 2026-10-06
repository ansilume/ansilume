<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use app\services\LintService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Exercises JobTemplateController actions.
 *
 * Stubs LintService (no shell-out) and JobLaunchService (no runner dispatch).
 */
class JobTemplateControllerActionTest extends WebControllerTestCase
{
    /** @var list<array{string, \yii\base\Component}> */
    private array $swappedServices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->swapService('lintService', new class extends LintService {
            public int $runCalls = 0;
            public function runForTemplate(JobTemplate $template): void
            {
                $this->runCalls++;
            }
        });

        $this->swapService('jobLaunchService', new class extends JobLaunchService {
            public int $launchCalls = 0;
            public bool $throwOnLaunch = false;
            public function launch(JobTemplate $template, int $userId, array $overrides = []): Job
            {
                $this->launchCalls++;
                if ($this->throwOnLaunch) {
                    throw new \RuntimeException('test-launch-failure');
                }
                $j = new Job();
                $j->job_template_id = $template->id;
                $j->launched_by = $userId;
                $j->status = Job::STATUS_QUEUED;
                $j->timeout_minutes = 120;
                $j->has_changes = 0;
                $j->queued_at = time();
                $j->created_at = time();
                $j->updated_at = time();
                $j->save(false);
                return $j;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->swappedServices as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swappedServices = [];
        parent::tearDown();
    }

    // ── actionIndex() ────────────────────────────────────────────────────────

    public function testIndexRendersDataProvider(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->makeTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $this->assertInstanceOf(ActiveDataProvider::class, $ctrl->capturedParams['dataProvider']);
    }

    // ── actionView() ─────────────────────────────────────────────────────────

    public function testViewRendersModel(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$tpl->id);

        $this->assertSame('rendered:view', $result);
        $this->assertSame($tpl->id, $ctrl->capturedParams['model']->id);
    }

    public function testViewAndLaunchListTheCredentialsInPrecedenceOrder(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);
        $older = $this->createCredential($user->id, \app\models\Credential::TYPE_TOKEN);
        $primary = $this->createCredential($user->id, \app\models\Credential::TYPE_SSH_KEY);
        $tpl->credential_id = $primary->id;
        $tpl->save(false);
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => $tpl->id,
            'credential_id' => $older->id,
            'sort_order' => 0,
        ])->execute();
        $expected = [
            ['id' => (int)$primary->id, 'name' => $primary->name, 'credential_type' => \app\models\Credential::TYPE_SSH_KEY, 'role' => \app\models\Credential::ROLE_PRIMARY],
            ['id' => (int)$older->id, 'name' => $older->name, 'credential_type' => \app\models\Credential::TYPE_TOKEN, 'role' => \app\models\Credential::ROLE_ADDITIONAL],
        ];

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$tpl->id);
        $this->assertSame($expected, $ctrl->capturedParams['attachedCredentials']);

        $this->setQueryParams(['id' => (string)$tpl->id]);
        $launch = $this->makeController();
        $launch->actionLaunch();
        $this->assertSame($expected, $launch->capturedParams['attachedCredentials']);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView(9999999);
    }

    // ── actionCreate() ───────────────────────────────────────────────────────

    public function testCreateRendersFormOnGetWithDefaults(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        /** @var JobTemplate $model */
        $model = $ctrl->capturedParams['model'];
        $this->assertSame(0, $model->verbosity);
        $this->assertSame(5, $model->forks);
        $this->assertSame(120, $model->timeout_minutes);
        $this->assertFalse((bool)$model->become);
        $this->assertArrayHasKey('projects', $ctrl->capturedParams);
        $this->assertArrayHasKey('inventories', $ctrl->capturedParams);
        $this->assertArrayHasKey('credentials', $ctrl->capturedParams);
        $this->assertArrayHasKey('runnerGroups', $ctrl->capturedParams);
    }

    public function testCreateWithPrefillFromQuery(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);

        $ctrl = $this->makeController();
        $ctrl->actionCreate((int)$project->id, 'deploy.yml');

        /** @var JobTemplate $model */
        $model = $ctrl->capturedParams['model'];
        $this->assertSame($project->id, $model->project_id);
        $this->assertSame('deploy.yml', $model->playbook);
    }

    public function testCreatePersistsAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);

        $this->setPost([
            'JobTemplate' => [
                'name' => 'tpl-created',
                'project_id' => $project->id,
                'inventory_id' => $inventory->id,
                'runner_group_id' => $group->id,
                'playbook' => 'site.yml',
                'verbosity' => 0,
                'forks' => 5,
                'timeout_minutes' => 60,
                'become' => 0,
                'become_method' => 'sudo',
                'become_user' => 'root',
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertInstanceOf(Response::class, $result);
        $stored = JobTemplate::findOne(['name' => 'tpl-created']);
        $this->assertNotNull($stored);
        $this->assertSame($user->id, (int)$stored->created_by);

        /** @var object{runCalls: int} $lint */
        $lint = \Yii::$app->get('lintService');
        $this->assertSame(1, $lint->runCalls);

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_CREATED,
            'object_id' => $stored->id,
        ]));
    }

    public function testCreateInvalidInputRendersForm(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setPost(['JobTemplate' => ['name' => '']]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertTrue($ctrl->capturedParams['model']->hasErrors());
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function templatePost(\app\models\User $user, string $name, array $extra = []): array
    {
        return [
            'JobTemplate' => [
                'name' => $name,
                'project_id' => $this->createProject($user->id)->id,
                'inventory_id' => $this->createInventory($user->id)->id,
                'runner_group_id' => $this->createRunnerGroup($user->id)->id,
                'playbook' => 'site.yml',
                'verbosity' => 0,
                'forks' => 5,
                'timeout_minutes' => 60,
                'become' => 0,
                'become_method' => 'sudo',
                'become_user' => 'root',
            ] + $extra,
        ];
    }

    public function testCreateStoresTheCheckedCredentialsInFormOrder(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $primary = $this->createCredential($user->id, \app\models\Credential::TYPE_SSH_KEY);
        $second = $this->createCredential($user->id);
        $first = $this->createCredential($user->id, \app\models\Credential::TYPE_VAULT);
        $this->setPost($this->templatePost($user, 'tpl-creds', ['credential_id' => $primary->id]) + [
            'credential_ids' => [(string)$first->id, (string)$second->id],
        ]);

        $this->makeController()->actionCreate();

        $stored = JobTemplate::findOne(['name' => 'tpl-creds']);
        $this->assertNotNull($stored);
        $this->assertSame(
            [$primary->id, $first->id, $second->id],
            array_map(static fn (\app\models\Credential $c): int => (int)$c->id, $stored->orderedCredentials())
        );
    }

    public function testAnUnknownCredentialReRendersTheFormWithTheSelection(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $known = $this->createCredential($user->id);
        $this->setPost($this->templatePost($user, 'tpl-bad-creds') + ['credential_ids' => [(string)$known->id, '999999999']]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertSame('Credential #999999999 does not exist.', $ctrl->capturedParams['model']->getFirstError('credential_ids'));
        $this->assertSame([$known->id, 999999999], $ctrl->capturedParams['selectedCredentialIds']);
        $this->assertNull(JobTemplate::findOne(['name' => 'tpl-bad-creds']));
    }

    public function testUncheckingEveryCredentialDetachesTheAdditionalOnes(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $template = $this->makeTemplate($user->id);
        $extra = $this->createCredential($user->id);
        \Yii::$app->get('jobTemplateCredentialService')->saveWithCredentials($template, [$extra->id]);
        $this->setPost(['JobTemplate' => ['description' => 'no extras any more']]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($template->id);

        $this->assertSame([], \Yii::$app->get('jobTemplateCredentialService')->additionalIds(JobTemplate::findOne($template->id)));
    }

    public function testTheFormShowsTheStoredAdditionalCredentials(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $template = $this->makeTemplate($user->id);
        $extra = $this->createCredential($user->id);
        \Yii::$app->get('jobTemplateCredentialService')->saveWithCredentials($template, [$extra->id]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($template->id);

        $this->assertSame([$extra->id], $ctrl->capturedParams['selectedCredentialIds']);
    }

    // ── actionUpdate() ───────────────────────────────────────────────────────

    public function testUpdatePersistsChanges(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $this->setPost([
            'JobTemplate' => [
                'name' => 'renamed',
                'project_id' => $tpl->project_id,
                'inventory_id' => $tpl->inventory_id,
                'runner_group_id' => $tpl->runner_group_id,
                'playbook' => 'site.yml',
                'verbosity' => 1,
                'forks' => 10,
                'timeout_minutes' => 120,
                'become' => 0,
                'become_method' => 'sudo',
                'become_user' => 'root',
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$tpl->id);

        $this->assertInstanceOf(Response::class, $result);
        $reloaded = JobTemplate::findOne($tpl->id);
        $this->assertSame('renamed', $reloaded->name);
        $this->assertSame(10, (int)$reloaded->forks);

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_UPDATED,
            'object_id' => $tpl->id,
        ]));
    }

    public function testUpdateRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$tpl->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame($tpl->id, $ctrl->capturedParams['model']->id);
    }

    // ── actionDelete() ───────────────────────────────────────────────────────

    public function testDeleteSoftDeletesAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);
        $id = (int)$tpl->id;

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($id);

        $this->assertInstanceOf(Response::class, $result);
        // softDelete sets deleted_at; findModel() uses findOne which respects default scope if any.
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_DELETED,
            'object_id' => $id,
        ]));
    }

    public function testDeleteThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionDelete(9999999);
    }

    // ── actionClone() ────────────────────────────────────────────────────────

    public function testClonePersistsDuplicateAndAuditsWithLineage(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $source = $this->makeTemplate($user->id);
        // Exercise a few fields that must round-trip through the clone.
        $source->extra_vars = '{"env":"staging"}';
        $source->forks = 12;
        $source->become = true;
        $source->timeout_minutes = 30;
        $source->save(false);

        $ctrl = $this->makeController();
        $result = $ctrl->actionClone((int)$source->id);

        $this->assertInstanceOf(Response::class, $result);

        /** @var JobTemplate|null $clone */
        $clone = JobTemplate::find()
            ->where(['name' => $source->name . ' (copy)'])
            ->one();
        $this->assertNotNull($clone, 'Clone must be persisted under "<source> (copy)".');
        $this->assertNotSame((int)$source->id, (int)$clone->id);

        // Config carried over.
        $this->assertSame('{"env":"staging"}', $clone->extra_vars);
        $this->assertSame(12, (int)$clone->forks);
        $this->assertSame(1, (int)$clone->become);
        $this->assertSame(30, (int)$clone->timeout_minutes);

        // created_by belongs to the cloning user, not the source's creator.
        $this->assertSame($user->id, (int)$clone->created_by);

        // Audit entry is plain CREATED with lineage in meta.
        $audit = AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_CREATED,
            'object_id' => $clone->id,
        ]);
        $this->assertNotNull($audit);
        $meta = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($meta);
        $this->assertSame((int)$source->id, (int)$meta['cloned_from']);
        $this->assertSame($source->name, (string)$meta['cloned_from_name']);
    }

    public function testCloneStripsStaleAndSensitiveFields(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $source = $this->makeTemplate($user->id);
        $source->trigger_token = hash('sha256', 'some-raw-token');
        $source->lint_output = 'fake lint output';
        $source->lint_at = time();
        $source->lint_exit_code = 2;
        $source->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$source->id);

        /** @var JobTemplate $clone */
        $clone = JobTemplate::find()->where(['name' => $source->name . ' (copy)'])->one();
        $this->assertNull($clone->trigger_token, 'Trigger token must not leak to the clone.');
        $this->assertNull($clone->lint_output);
        $this->assertNull($clone->lint_at);
        $this->assertNull($clone->lint_exit_code);
    }

    public function testClonePicksNonCollidingNameAcrossRepeatedClones(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $source = $this->makeTemplate($user->id);
        $base = $source->name;

        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$source->id);
        $ctrl->actionClone((int)$source->id);
        $ctrl->actionClone((int)$source->id);

        $this->assertNotNull(JobTemplate::findOne(['name' => "{$base} (copy)"]));
        $this->assertNotNull(JobTemplate::findOne(['name' => "{$base} (copy 2)"]));
        $this->assertNotNull(JobTemplate::findOne(['name' => "{$base} (copy 3)"]));
    }

    public function testCloneStripsExistingCopySuffixSoItDoesntStack(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $source = $this->makeTemplate($user->id);
        $source->name = 'my-template (copy)';
        $source->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$source->id);

        // Must end up as "my-template (copy 2)", not "my-template (copy) (copy)".
        $this->assertNotNull(JobTemplate::findOne(['name' => 'my-template (copy 2)']));
        $this->assertNull(JobTemplate::findOne(['name' => 'my-template (copy) (copy)']));
    }

    public function testCloneThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionClone(9999999);
    }

    // ── actionLaunch() ───────────────────────────────────────────────────────

    public function testLaunchRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $this->setQueryParams(['id' => (string)$tpl->id]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertSame('rendered:launch', $result);
        $this->assertSame($tpl->id, $ctrl->capturedParams['template']->id);
    }

    public function testLaunchQueuesJobOnPost(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $this->setQueryParams(['id' => (string)$tpl->id]);
        $this->setPost([
            'overrides' => ['limit' => 'localhost'],
            'survey' => ['env' => 'staging'],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{launchCalls: int} $svc */
        $svc = \Yii::$app->get('jobLaunchService');
        $this->assertSame(1, $svc->launchCalls);
    }

    /**
     * Regression: dashboard quick-launch form sends id via POST body,
     * not as a GET parameter (GitHub #9).
     */
    public function testLaunchAcceptsIdFromPostBody(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $this->setQueryParams([]);
        $this->setPost(['id' => (string)$tpl->id]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{launchCalls: int} $svc */
        $svc = \Yii::$app->get('jobLaunchService');
        $this->assertSame(1, $svc->launchCalls);
    }

    public function testLaunchHandlesRuntimeException(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        /** @var object{throwOnLaunch: bool} $svc */
        $svc = \Yii::$app->get('jobLaunchService');
        $svc->throwOnLaunch = true;

        $this->setQueryParams(['id' => (string)$tpl->id]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        // Falls through to render('launch') after catching the exception.
        $this->assertSame('rendered:launch', $result);
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('danger', $flashes);
    }

    public function testLaunchThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->setQueryParams(['id' => '9999999']);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionLaunch();
    }

    // ── actionGenerateTriggerToken() / actionRevokeTriggerToken() ───────────

    public function testGenerateTriggerTokenAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionGenerateTriggerToken((int)$tpl->id);

        $this->assertInstanceOf(Response::class, $result);
        $reloaded = JobTemplate::findOne($tpl->id);
        $this->assertNotEmpty($reloaded->trigger_token);
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED,
            'object_id' => $tpl->id,
        ]));

        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('trigger_token_raw', $flashes);

        // The flashed raw token must match the stored hash and must NOT be
        // what is stored on the row — a DB dump must not reveal the trigger URL.
        $rawToken = (string)$flashes['trigger_token_raw'];
        $this->assertNotSame($rawToken, $reloaded->trigger_token);
        $this->assertSame(hash('sha256', $rawToken), $reloaded->trigger_token);
    }

    public function testRevokeTriggerTokenAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);
        $tpl->generateTriggerToken();

        $ctrl = $this->makeController();
        $result = $ctrl->actionRevokeTriggerToken((int)$tpl->id);

        $this->assertInstanceOf(Response::class, $result);
        $reloaded = JobTemplate::findOne($tpl->id);
        $this->assertEmpty($reloaded->trigger_token);
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED,
            'object_id' => $tpl->id,
        ]));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeTemplate(int $userId): JobTemplate
    {
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        return $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $userId);
    }

    private function swapService(string $id, \yii\base\Component $replacement): void
    {
        /** @var \yii\base\Component $original */
        $original = \Yii::$app->get($id);
        $this->swappedServices[] = [$id, $original];
        \Yii::$app->set($id, $replacement);
    }

    private function makeController(): JobTemplateController
    {
        return new class ('job-template', \Yii::$app) extends JobTemplateController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
