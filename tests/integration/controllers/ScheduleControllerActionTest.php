<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ScheduleController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ScheduleService;
use app\tests\integration\TeamScopeFixtures;
use yii\data\ActiveDataProvider;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * Team scoping of the schedule pages: a schedule may only point at a job
 * template the user may operate, checked on the template the form submits.
 */
class ScheduleControllerActionTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** Makes the names of the schedules a test submits unique. */
    private string $run = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = uniqid('', true);
    }

    // ── actionCreate() ───────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function templateTheUserMayOperateProvider(): array
    {
        return ['the team\'s project' => ['own'], 'an open project' => ['open']];
    }

    /**
     * @dataProvider templateTheUserMayOperateProvider
     */
    public function testCreateSchedulesATemplateTheUserMayOperate(string $key): void
    {
        $scope = $this->loggedInScope();
        $template = $this->template($scope, $key);
        $this->setPost(['Schedule' => $this->formData('create-' . $key, (int)$template->id)]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertInstanceOf(Response::class, $result);
        $schedule = Schedule::findOne(['name' => $this->scheduleName('create-' . $key)]);
        $this->assertNotNull($schedule);
        $this->assertSame(['view', 'id' => $schedule->id], $ctrl->capturedRedirect);
        $this->assertSame((int)$template->id, (int)$schedule->job_template_id);
        $this->assertSame((int)$scope['member']->id, (int)$schedule->created_by);
        $this->assertNotNull($schedule->next_run_at);
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_SCHEDULE_CREATED, 'object_id' => $schedule->id]));
    }

    /**
     * Regression: created_by was mass-assignable, so a form could make a
     * schedule launch its jobs as another user.
     */
    public function testCreateIgnoresAPostedCreator(): void
    {
        $scope = $this->loggedInScope();
        $data = $this->formData('creator', (int)$scope['own']->id);
        $data['created_by'] = (string)$scope['admin']->id;
        $this->setPost(['Schedule' => $data]);

        $this->makeController()->actionCreate();

        $schedule = Schedule::findOne(['name' => $this->scheduleName('creator')]);
        $this->assertNotNull($schedule);
        $this->assertSame((int)$scope['member']->id, (int)$schedule->created_by);
    }

    /**
     * A template the user sees but may not launch is forbidden, so a
     * schedule cannot launch it on their behalf.
     */
    public function testCreateWithATemplateTheTeamOnlyViewsIsForbidden(): void
    {
        $scope = $this->loggedInScope();
        $this->setPost(['Schedule' => $this->formData('viewed', (int)$scope['viewed']->id)]);

        try {
            $this->makeController()->actionCreate();
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You do not have permission to schedule the selected job template.', $e->getMessage());
        }
        $this->assertNull(Schedule::findOne(['name' => $this->scheduleName('viewed')]));
    }

    /**
     * Regression: another team's template answered 403, which told the user
     * that it exists. It is reported like an unknown template now.
     */
    public function testCreateWithAnotherTeamsTemplateReadsLikeAnUnknownOne(): void
    {
        $scope = $this->loggedInScope();

        $foreign = $this->submitCreate('foreign', (int)$scope['foreign']->id);
        $unknown = $this->submitCreate('unknown', 999999999);

        $expected = ['job_template_id' => [ScheduleService::TEMPLATE_MISSING_MESSAGE]];
        $this->assertSame($expected, $foreign->getErrors());
        $this->assertSame($expected, $unknown->getErrors());
        $this->assertNull(Schedule::findOne(['name' => $this->scheduleName('foreign')]));
        $this->assertNull(Schedule::findOne(['name' => $this->scheduleName('unknown')]));
    }

    public function testCreateWithADeletedTemplateReadsLikeAnUnknownOne(): void
    {
        $scope = $this->loggedInScope();
        $scope['own']->softDelete();

        $model = $this->submitCreate('deleted', (int)$scope['own']->id);

        $this->assertSame(['job_template_id' => [ScheduleService::TEMPLATE_MISSING_MESSAGE]], $model->getErrors());
    }

    public function testCreateWithAHiddenTemplateStillReportsTheOtherErrors(): void
    {
        $scope = $this->loggedInScope();
        $data = $this->formData('other-errors', (int)$scope['foreign']->id);
        $data['cron_expression'] = 'not a cron';
        $this->setPost(['Schedule' => $data]);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame([ScheduleService::TEMPLATE_MISSING_MESSAGE], $model->getErrors('job_template_id'));
        $this->assertNotEmpty($model->getErrors('cron_expression'));
    }

    public function testCreateWithoutATemplateShowsTheRequiredError(): void
    {
        $this->loggedInScope();

        $model = $this->submitCreate('no-template', null);

        $label = $model->getAttributeLabel('job_template_id');
        $this->assertSame([$label . ' cannot be blank.'], $model->getErrors('job_template_id'));
    }

    public function testTheFormListsOnlyTemplatesTheUserMayOperate(): void
    {
        $scope = $this->loggedInScope();

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        $this->assertSame('form', $ctrl->capturedView);
        $templates = $ctrl->capturedParams['templates'] ?? null;
        $this->assertIsArray($templates);
        $this->assertArrayHasKey((int)$scope['own']->id, $templates);
        $this->assertArrayHasKey((int)$scope['open']->id, $templates);
        $this->assertArrayNotHasKey((int)$scope['viewed']->id, $templates, 'the team only views it');
        $this->assertArrayNotHasKey((int)$scope['foreign']->id, $templates, 'another team\'s template');
    }

    public function testTheFormListsEveryTemplateForAnAdmin(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['admin']);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        $templates = $ctrl->capturedParams['templates'] ?? null;
        $this->assertIsArray($templates);
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $this->assertArrayHasKey((int)$this->template($scope, $key)->id, $templates, $key);
        }
    }

    // ── actionUpdate() ───────────────────────────────────────────────────────

    /**
     * Regression: update checked the schedule's stored template before
     * loading the form, so the submitted template was never checked.
     */
    public function testUpdateCannotSwitchToATemplateTheTeamOnlyViews(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $this->setPost(['Schedule' => ['job_template_id' => (string)$scope['viewed']->id]]);

        try {
            $this->makeController()->actionUpdate((int)$schedule->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You do not have permission to schedule the selected job template.', $e->getMessage());
        }
        $this->assertSame((int)$scope['own']->id, $this->storedTemplateId($schedule));
    }

    public function testUpdateCannotSwitchToAnotherTeamsTemplate(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $this->setPost(['Schedule' => ['job_template_id' => (string)$scope['foreign']->id]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame('rendered:form', $result);
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame(['job_template_id' => [ScheduleService::TEMPLATE_MISSING_MESSAGE]], $model->getErrors());
        $this->assertSame((int)$scope['own']->id, $this->storedTemplateId($schedule));
    }

    public function testUpdateSwitchesToAnOpenTemplateAndKeepsTheCreator(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['outsider']->id);
        $this->setPost(['Schedule' => ['job_template_id' => (string)$scope['open']->id, 'created_by' => (string)$scope['member']->id]]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(['view', 'id' => $schedule->id], $ctrl->capturedRedirect);
        $schedule->refresh();
        $this->assertSame((int)$scope['open']->id, (int)$schedule->job_template_id);
        $this->assertSame((int)$scope['outsider']->id, (int)$schedule->created_by, 'whom the schedule runs as is not a form field');
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_SCHEDULE_UPDATED, 'object_id' => $schedule->id]));
    }

    public function testUpdateKeepsAnUnchangedTemplate(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $this->setPost(['Schedule' => ['name' => $this->scheduleName('renamed')]]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(['view', 'id' => $schedule->id], $ctrl->capturedRedirect);
        $schedule->refresh();
        $this->assertSame($this->scheduleName('renamed'), $schedule->name);
        $this->assertSame((int)$scope['own']->id, (int)$schedule->job_template_id);
    }

    public function testAnAdminMaySwitchAScheduleToAnyTemplate(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['admin']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $this->setPost(['Schedule' => ['job_template_id' => (string)$scope['foreign']->id]]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(['view', 'id' => $schedule->id], $ctrl->capturedRedirect);
        $this->assertSame((int)$scope['foreign']->id, $this->storedTemplateId($schedule));
    }

    public function testUpdateOfAScheduleOfAProjectTheTeamOnlyViewsIsForbidden(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['viewed']->id, (int)$scope['admin']->id);
        $this->setPost(['Schedule' => ['job_template_id' => (string)$scope['own']->id]]);

        $this->expectException(ForbiddenHttpException::class);
        $this->makeController()->actionUpdate((int)$schedule->id);
    }

    // ── The submitted ID as stored ───────────────────────────────────────────

    /**
     * Regression: the form decided access on filter_var(), which rejects a
     * leading zero, but the integer rule accepts "0112709" and the column
     * stores it as 112709. Such an ID skipped the check, so a schedule could
     * point at any template, and an update could make another user's
     * schedule run another team's template as that user. Every form the
     * integer rule accepts is checked as the int it is stored as; a sign
     * without a leading zero always passed filter_var() and stays pinned.
     *
     * @return array<string, array{0: string}>
     */
    public static function storedIdFormProvider(): array
    {
        return [
            'leading zero' => ['0%d'],
            'leading zeros' => ['000%d'],
            'plus sign and leading zero' => ['+0%d'],
            'plus sign' => ['+%d'],
            'trailing newline' => ["%d\n"],
        ];
    }

    /**
     * @dataProvider storedIdFormProvider
     */
    public function testCreateReportsAnotherTeamsTemplateMissingInEveryIdForm(string $form): void
    {
        $scope = $this->loggedInScope();

        $model = $this->submitCreate('form-foreign', sprintf($form, $scope['foreign']->id));

        $this->assertSame(['job_template_id' => [ScheduleService::TEMPLATE_MISSING_MESSAGE]], $model->getErrors());
        $this->assertNull(Schedule::findOne(['name' => $this->scheduleName('form-foreign')]));
    }

    /**
     * @dataProvider storedIdFormProvider
     */
    public function testCreateForbidsATemplateTheTeamOnlyViewsInEveryIdForm(string $form): void
    {
        $scope = $this->loggedInScope();
        $this->setPost(['Schedule' => $this->formData('form-viewed', sprintf($form, $scope['viewed']->id))]);

        try {
            $this->makeController()->actionCreate();
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You do not have permission to schedule the selected job template.', $e->getMessage());
        }
        $this->assertNull(Schedule::findOne(['name' => $this->scheduleName('form-viewed')]));
    }

    /**
     * @dataProvider storedIdFormProvider
     */
    public function testCreateStoresTheTeamsTemplateGivenInEveryIdForm(string $form): void
    {
        $scope = $this->loggedInScope();
        $this->setPost(['Schedule' => $this->formData('form-own', sprintf($form, $scope['own']->id))]);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        $schedule = Schedule::findOne(['name' => $this->scheduleName('form-own')]);
        $this->assertNotNull($schedule);
        $this->assertSame(['view', 'id' => $schedule->id], $ctrl->capturedRedirect);
        $this->assertSame((int)$scope['own']->id, $schedule->job_template_id);
    }

    /**
     * The attack: an operator repoints the schedule of an open project,
     * which runs as its creator (an admin here), to another team's template.
     *
     * @dataProvider storedIdFormProvider
     */
    public function testUpdateReportsAnotherTeamsTemplateMissingInEveryIdForm(string $form): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['open']->id, (int)$scope['admin']->id);
        $this->setPost(['Schedule' => ['job_template_id' => sprintf($form, $scope['foreign']->id)]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame('rendered:form', $result);
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame(['job_template_id' => [ScheduleService::TEMPLATE_MISSING_MESSAGE]], $model->getErrors());
        $this->assertSame((int)$scope['open']->id, $this->storedTemplateId($schedule));
    }

    /**
     * @dataProvider storedIdFormProvider
     */
    public function testUpdateForbidsATemplateTheTeamOnlyViewsInEveryIdForm(string $form): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['open']->id, (int)$scope['admin']->id);
        $this->setPost(['Schedule' => ['job_template_id' => sprintf($form, $scope['viewed']->id)]]);

        try {
            $this->makeController()->actionUpdate((int)$schedule->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You do not have permission to schedule the selected job template.', $e->getMessage());
        }
        $this->assertSame((int)$scope['open']->id, $this->storedTemplateId($schedule));
    }

    /**
     * @dataProvider storedIdFormProvider
     */
    public function testUpdateStoresTheTeamsTemplateGivenInEveryIdForm(string $form): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['open']->id, (int)$scope['admin']->id);
        $this->setPost(['Schedule' => ['job_template_id' => sprintf($form, $scope['own']->id)]]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(['view', 'id' => $schedule->id], $ctrl->capturedRedirect);
        $this->assertSame((int)$scope['own']->id, $this->storedTemplateId($schedule));
    }

    // ── Path access and lists ────────────────────────────────────────────────

    /**
     * Regression: the access checks read the project through the template
     * relation, which skips soft-deleted templates; the schedule of another
     * team's deleted template counted as global, so anyone could read its
     * extra vars, change, toggle or delete it.
     *
     * @return array<string, array{0: string}>
     */
    public static function pathActionProvider(): array
    {
        return ['view' => ['view'], 'update' => ['update'], 'toggle' => ['toggle'], 'delete' => ['delete']];
    }

    /**
     * @dataProvider pathActionProvider
     */
    public function testAScheduleOfAnotherTeamsDeletedTemplateStaysForbidden(string $action): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['foreign']->id, (int)$scope['outsider']->id);
        $scope['foreign']->softDelete();
        $this->setPost(['Schedule' => ['job_template_id' => (string)$scope['own']->id]]);

        $ctrl = $this->makeController();
        try {
            match ($action) {
                'view' => $ctrl->actionView((int)$schedule->id),
                'update' => $ctrl->actionUpdate((int)$schedule->id),
                'toggle' => $ctrl->actionToggle((int)$schedule->id),
                default => $ctrl->actionDelete((int)$schedule->id),
            };
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException) {
            // expected
        }
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        $this->assertSame((int)$scope['foreign']->id, (int)$stored->job_template_id);
        $this->assertTrue((bool)$stored->enabled);
    }

    public function testTheTeamCanStillManageTheScheduleOfItsDeletedTemplate(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $scope['own']->softDelete();

        $ctrl = $this->makeController();
        $ctrl->actionDelete((int)$schedule->id);

        $this->assertNull(Schedule::findOne($schedule->id));
    }

    public function testIndexListsOnlySchedulesOfVisibleTemplates(): void
    {
        $scope = $this->loggedInScope();
        $ids = [];
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $ids[$key] = (int)$this->createSchedule((int)$this->template($scope, $key)->id, (int)$scope['admin']->id)->id;
        }

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $provider = $ctrl->capturedParams['dataProvider'] ?? null;
        $this->assertInstanceOf(ActiveDataProvider::class, $provider);
        $listed = array_map(static fn (Schedule $s): int => (int)$s->id, $provider->getModels());
        $this->assertContains($ids['own'], $listed);
        $this->assertContains($ids['viewed'], $listed);
        $this->assertContains($ids['open'], $listed);
        $this->assertNotContains($ids['foreign'], $listed);
    }

    public function testViewShowsAScheduleOfTheTeam(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['viewed']->id, (int)$scope['admin']->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$schedule->id);

        $this->assertSame('rendered:view', $result);
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame($schedule->id, $model->id);
    }

    public function testViewOfAnotherTeamsScheduleIsForbidden(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['foreign']->id, (int)$scope['outsider']->id);

        $this->expectException(ForbiddenHttpException::class);
        $this->makeController()->actionView((int)$schedule->id);
    }

    public function testAnUnknownScheduleIs404(): void
    {
        $this->loggedInScope();

        $this->expectException(NotFoundHttpException::class);
        $this->makeController()->actionView(999999999);
    }

    public function testToggleDisablesAndEnablesAScheduleOfTheTeam(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);

        $ctrl = $this->makeController();
        $ctrl->actionToggle((int)$schedule->id);
        $schedule->refresh();
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertNull($schedule->next_run_at);
        $this->assertSame(['index'], $ctrl->capturedRedirect);

        $ctrl->actionToggle((int)$schedule->id);
        $schedule->refresh();
        $this->assertTrue((bool)$schedule->enabled);
        $this->assertGreaterThan(time(), (int)$schedule->next_run_at);
        $this->assertSame(2, (int)AuditLog::find()->where([
            'action' => AuditLog::ACTION_SCHEDULE_TOGGLED,
            'object_id' => $schedule->id,
        ])->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function operateActionProvider(): array
    {
        return ['toggle' => ['toggle'], 'delete' => ['delete']];
    }

    /**
     * @dataProvider operateActionProvider
     */
    public function testAScheduleOfAProjectTheTeamOnlyViewsCannotBeChanged(string $action): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['viewed']->id, (int)$scope['admin']->id);

        $ctrl = $this->makeController();
        try {
            $action === 'toggle' ? $ctrl->actionToggle((int)$schedule->id) : $ctrl->actionDelete((int)$schedule->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException) {
            // expected
        }
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        $this->assertTrue((bool)$stored->enabled);
    }

    public function testAGuestCannotCreateASchedule(): void
    {
        $scope = $this->teamScope();
        $this->setPost(['Schedule' => $this->formData('guest', (int)$scope['open']->id)]);

        try {
            $this->makeController()->actionCreate();
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException) {
            // expected
        }
        $this->assertNull(Schedule::findOne(['name' => $this->scheduleName('guest')]));
    }

    public function testAnAdminSeesEverySchedule(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['admin']);
        $foreign = $this->createSchedule((int)$scope['foreign']->id, (int)$scope['outsider']->id);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $provider = $ctrl->capturedParams['dataProvider'] ?? null;
        $this->assertInstanceOf(ActiveDataProvider::class, $provider);
        $listed = array_map(static fn (Schedule $s): int => (int)$s->id, $provider->getModels());
        $this->assertContains((int)$foreign->id, $listed);
    }

    // ── Views ────────────────────────────────────────────────────────────────

    public function testTheFormSaysWhomTheScheduleRunsAs(): void
    {
        $scope = $this->loggedInScope();
        $creator = $this->createUser('<b>creator</b>');
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$creator->id);

        $new = $this->renderPage('form', ['model' => new Schedule(), 'templates' => []]);
        $existing = $this->renderPage('form', ['model' => $schedule, 'templates' => []]);

        $this->assertStringContainsString('Lists the job templates you may launch. Scheduled jobs run as you.', $new);
        $this->assertStringContainsString('Scheduled jobs run as ' . htmlspecialchars((string)$creator->username) . '.', $existing);
        $this->assertStringNotContainsString('<b>creator</b>', $existing);
    }

    public function testTheViewSaysThatScheduledJobsRunAsTheCreator(): void
    {
        $scope = $this->loggedInScope();
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);

        $html = $this->renderPage('view', ['model' => $schedule]);

        $this->assertMatchesRegularExpression('/data-testid="schedule-runs-as">Scheduled jobs run as this user/', $html);
        $this->assertStringContainsString(htmlspecialchars((string)$scope['member']->username), $html);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * teamScope() with the member (RBAC operator) logged in.
     *
     * @return array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate}
     */
    private function loggedInScope(): array
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function template(array $scope, string $key): JobTemplate
    {
        $template = $scope[$key] ?? null;
        $this->assertInstanceOf(JobTemplate::class, $template);
        return $template;
    }

    private function scheduleName(string $suffix): string
    {
        return 'test-schedule-' . $suffix . '-' . $this->run;
    }

    /**
     * @param int|string|null $templateId posted as given, as a string
     * @return array<string, string>
     */
    private function formData(string $suffix, int|string|null $templateId): array
    {
        $data = [
            'name' => $this->scheduleName($suffix),
            'cron_expression' => '0 2 * * *',
            'timezone' => 'UTC',
            'enabled' => '1',
        ];
        if ($templateId !== null) {
            $data['job_template_id'] = (string)$templateId;
        }
        return $data;
    }

    /**
     * Submit the create form and return the model it re-renders.
     */
    private function submitCreate(string $suffix, int|string|null $templateId): Schedule
    {
        $this->setPost(['Schedule' => $this->formData($suffix, $templateId)]);
        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Schedule::class, $model);
        return $model;
    }

    private function createSchedule(int $templateId, int $createdBy): Schedule
    {
        $s = new Schedule();
        $s->name = 'test-schedule-' . uniqid('', true);
        $s->job_template_id = $templateId;
        $s->cron_expression = '0 2 * * *';
        $s->timezone = 'UTC';
        $s->enabled = true;
        $s->created_by = $createdBy;
        $s->created_at = time();
        $s->updated_at = time();
        $s->save(false);
        return $s;
    }

    private function storedTemplateId(Schedule $schedule): int
    {
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        return (int)$stored->job_template_id;
    }

    /**
     * Render a real schedule view. The console application of the tests has
     * no web root, so asset bundles are dummies; view, asset manager and
     * controller are restored afterwards.
     *
     * @param array<string, mixed> $params
     */
    private function renderPage(string $view, array $params): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new ScheduleController('schedule', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        // ActiveForm posts back to the current URL.
        $request->setUrl('/schedule/' . $view);
        try {
            return $ctrl->renderPartial($view, $params);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    private function makeController(): ScheduleController
    {
        return new class ('schedule', \Yii::$app) extends ScheduleController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
