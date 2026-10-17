<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;
use app\controllers\traits\TeamScopingTrait;
use app\services\JobTemplateGate;
use app\services\ScheduleEnablement;
use app\services\ScheduleRunAs;
use app\services\ScheduleSecrets;
use app\services\ScheduleService;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ScheduleController extends BaseController
{
    use TeamScopingTrait;

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['job.launch']],
            ['actions' => ['create', 'update'], 'allow' => true, 'roles' => ['job.launch']],
            ['actions' => ['delete', 'toggle'], 'allow' => true, 'roles' => ['job.launch']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'delete' => ['POST'],
            'toggle' => ['POST'],
        ];
    }

    public function actionIndex(): string
    {
        $query = Schedule::find()->with(['jobTemplate', 'creator'])->orderBy(['id' => SORT_DESC]);

        $filter = $this->buildScheduleFilter();
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    /**
     * The schedule's page. When its job template is gated (its own approval
     * rule, else its inventory's: JobTemplateGate), the page says that each
     * run waits for approval; not once the template is gone.
     */
    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        $this->requireScheduleView($model);
        /** @var JobTemplate|null $template */
        $template = $model->jobTemplate;

        return $this->render('view', [
            'model' => $model,
            'approvalNote' => $template === null ? null : JobTemplateGate::of($template)->scheduleNote(),
        ]);
    }

    public function actionCreate(): Response|string
    {
        $model = new Schedule();
        $model->timezone = 'UTC';
        $model->enabled = true;

        if ($model->load((array)\Yii::$app->request->post())) {
            // Set in code only: the schedule launches its jobs as this user.
            $model->created_by = (int)(\Yii::$app->user->id ?? 0);
            $secrets = new ScheduleSecrets();
            if ($this->saveSchedule($model, $secrets)) {
                \Yii::$app->get('auditService')->log(
                    AuditLog::ACTION_SCHEDULE_CREATED,
                    'schedule',
                    $model->id,
                    null,
                    ['name' => $model->name, 'cron' => $model->cron_expression] + $secrets->auditContext()
                );
                $this->session()->setFlash('success', "Schedule \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->renderForm($model);
    }

    /**
     * Save the edit form. A change to what or when the schedule runs (job
     * template, cron expression, timezone, extra vars, a password answer
     * entered anew or dropped) or enabling it makes the user who saves it the
     * user the schedule runs as (ScheduleRunAs), audited as run_as {from, to}.
     */
    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireScheduleOperate($model);

        if (!$model->load((array)\Yii::$app->request->post())) {
            // The stored extra vars, with the placeholder for every password answer:
            // submitted unchanged, they keep the stored answers.
            $model->extra_vars = ScheduleSecrets::maskedExtraVars($model);
            return $this->renderForm($model);
        }
        $secrets = new ScheduleSecrets();
        // The stored values, before ScheduleSecrets::prepare() drops the stored extra vars from the old attributes.
        $runAs = new ScheduleRunAs($model);
        if ($this->saveSchedule($model, $secrets, $runAs)) {
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_SCHEDULE_UPDATED,
                'schedule',
                $model->id,
                null,
                ['name' => $model->name] + $secrets->auditContext() + $runAs->auditContext()
            );
            $this->session()->setFlash('success', "Schedule \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }

        // A rejected submission shows what was submitted: never a stored answer.
        return $this->renderForm($model);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireScheduleOperate($model);
        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_SCHEDULE_DELETED,
            'schedule',
            $id,
            null,
            ['name' => $name]
        );
        $this->session()->setFlash('success', "Schedule \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    /**
     * Enable a disabled schedule or disable an enabled one. Enabling a
     * schedule whose job template was deleted is refused with a danger
     * flash (ScheduleEnablement::ENABLE_REFUSED) and changes nothing.
     * Enabling makes the user the schedule runs as (ScheduleRunAs).
     */
    public function actionToggle(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireScheduleOperate($model);
        $enablement = new ScheduleEnablement();
        $refusal = $enablement->toggle($model, (int)$this->currentUserId());
        if ($refusal !== null) {
            $this->session()->setFlash('danger', $refusal);
            return $this->redirect(['index']);
        }
        $state = $model->enabled ? 'enabled' : 'disabled';
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_SCHEDULE_TOGGLED,
            'schedule',
            $model->id,
            null,
            ['name' => $model->name, 'enabled' => $model->enabled] + $enablement->runAsContext()
        );
        $this->session()->setFlash('success', "Schedule \"{$model->name}\" {$state}.");
        return $this->redirect(['index']);
    }

    /**
     * Validate and save a submitted schedule. The job template it points at
     * must be one the user may operate: a template they see but may not
     * operate is forbidden, one they cannot see gets the same error as an
     * unknown one, so the form cannot probe for other teams' templates.
     *
     * Password answers in the extra vars are sealed last (ScheduleSecrets),
     * once the template is known to be one the user may use: their errors
     * name the template's password fields.
     *
     * An update passes $runAs, taken before the change is prepared: when the
     * change arms the schedule, the same save makes the user the schedule
     * runs as.
     *
     * @throws ForbiddenHttpException
     */
    private function saveSchedule(Schedule $model, ScheduleSecrets $secrets, ?ScheduleRunAs $runAs = null): bool
    {
        $access = $this->templateAccess($model);
        if ($access === ScheduleService::TEMPLATE_FORBIDDEN) {
            throw new ForbiddenHttpException('You do not have permission to schedule the selected job template.');
        }

        $model->computeNextRunAt();
        $valid = $model->validate();
        if ($access === ScheduleService::TEMPLATE_MISSING) {
            $model->clearErrors('job_template_id');
            $model->addError('job_template_id', ScheduleService::TEMPLATE_MISSING_MESSAGE);
            return false;
        }
        if (!$valid || $access !== ScheduleService::TEMPLATE_USABLE) {
            return false;
        }

        // The form always submits the extra vars.
        $error = $secrets->prepare($model, true);
        if ($error !== null) {
            $model->addError('extra_vars', $error);
            return false;
        }
        $runAs?->claim((int)$this->currentUserId(), $secrets->answersChanged());

        return $model->save(false);
    }

    /**
     * Access to the submitted job template (ScheduleService::TEMPLATE_*),
     * checked as the int the schedule stores; null when the form holds no
     * template id the integer rule accepts: the model's rules report that.
     *
     * @throws ForbiddenHttpException for guests
     */
    private function templateAccess(Schedule $model): ?string
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            throw new ForbiddenHttpException('You do not have permission to modify this resource.');
        }
        $templateId = $this->normalizeSubmittedId($model, 'job_template_id');

        return $templateId === null ? null : $this->schedules()->templateAccess($userId, $templateId);
    }

    /**
     * Show the form, with the errors of a rejected submission. Its fields
     * render their values as text, so a list that a crafted form posted for
     * one of them (Schedule[timezone][]=x) is shown as an empty field with
     * its error: rendering the list failed with "Array to string conversion".
     */
    private function renderForm(Schedule $model): string
    {
        foreach ($model->getAttributes() as $attribute => $value) {
            if (is_array($value)) {
                $model->setAttribute($attribute, null);
            }
        }

        return $this->render('form', [
            'model' => $model,
            'templates' => $this->getTemplateList(),
        ]);
    }

    private function findModel(int $id): Schedule
    {
        /** @var Schedule|null $model */
        $model = Schedule::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Schedule #{$id} not found.");
        }
        return $model;
    }

    /**
     * The job templates the user may schedule: those of projects they may
     * operate.
     *
     * @return array<int, string>
     */
    private function getTemplateList(): array
    {
        $query = JobTemplate::find()
            ->select(['id', 'name', 'project_id'])
            ->orderBy('name');

        $filter = $this->checker()->buildChildOperateFilter($this->currentUserId(), 'job_template.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        /** @var array<int, array{id: int, name: string}> $rows */
        $rows = $query->asArray()->all();

        $list = [];
        foreach ($rows as $row) {
            $list[$row['id']] = $row['name'] . ' (' . $row['id'] . ')';
        }
        return $list;
    }

    /**
     * Build a schedule filter via job_template subquery.
     *
     * @return array<int|string, mixed>|null
     */
    private function buildScheduleFilter(): ?array
    {
        $jobFilter = $this->checker()->buildJobFilter($this->currentUserId());
        if ($jobFilter === null) {
            return null;
        }
        // buildJobFilter returns ['in', 'job_template_id', $subquery]
        // Schedules also use job_template_id, so reuse directly
        return $jobFilter;
    }

    private function requireScheduleView(Schedule $model): void
    {
        $userId = $this->currentUserId();
        if ($userId === null || !$this->schedules()->canView($userId, $model)) {
            throw new ForbiddenHttpException('You do not have access to this resource.');
        }
    }

    private function requireScheduleOperate(Schedule $model): void
    {
        $userId = $this->currentUserId();
        if ($userId === null || !$this->schedules()->canOperate($userId, $model)) {
            throw new ForbiddenHttpException('You do not have permission to modify this resource.');
        }
    }

    private function schedules(): ScheduleService
    {
        /** @var ScheduleService $service */
        $service = \Yii::$app->get('scheduleService');
        return $service;
    }
}
