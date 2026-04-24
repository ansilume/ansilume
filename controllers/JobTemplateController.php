<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\services\JobLaunchService;
use app\services\LintService;
use app\controllers\traits\TeamScopingTrait;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class JobTemplateController extends BaseController
{
    use TeamScopingTrait;

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['job-template.view']],
            ['actions' => ['create', 'clone'], 'allow' => true, 'roles' => ['job-template.create']],
            ['actions' => ['update'], 'allow' => true, 'roles' => ['job-template.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['job-template.delete']],
            ['actions' => ['launch'], 'allow' => true, 'roles' => ['job.launch']],
            ['actions' => ['generate-trigger-token',
                           'revoke-trigger-token'], 'allow' => true, 'roles' => ['job-template.update']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'delete' => ['POST'],
            'launch' => ['POST', 'GET'],
            'generate-trigger-token' => ['POST'],
            'revoke-trigger-token' => ['POST'],
            'clone' => ['POST'],
        ];
    }

    public function actionIndex(): string
    {
        $query = JobTemplate::find()->with(['project', 'inventory', 'creator']);

        $filter = $this->checker()->buildChildResourceFilter($this->currentUserId(), 'job_template.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
            'sort' => [
                'defaultOrder' => ['name' => SORT_ASC],
                'attributes' => [
                    'id',
                    'name',
                    'playbook',
                    'project' => [
                        'asc' => ['{{%project}}.name' => SORT_ASC],
                        'desc' => ['{{%project}}.name' => SORT_DESC],
                    ],
                    'inventory' => [
                        'asc' => ['{{%inventory}}.name' => SORT_ASC],
                        'desc' => ['{{%inventory}}.name' => SORT_DESC],
                    ],
                    'runner_group' => [
                        'asc' => ['{{%runner_group}}.name' => SORT_ASC],
                        'desc' => ['{{%runner_group}}.name' => SORT_DESC],
                    ],
                ],
            ],
        ]);

        // Relational sorts need the parent tables joined; inject them only
        // when the operator actually sorts on a relational column.
        $request = \Yii::$app->request;
        $requested = $request instanceof \yii\web\Request ? $request->getQueryParam('sort', '') : '';
        $sortAttr = ltrim((string)$requested, '-');
        if ($sortAttr === 'project') {
            $query->leftJoin('{{%project}}', '{{%project}}.id = {{%job_template}}.project_id');
        } elseif ($sortAttr === 'inventory') {
            $query->leftJoin('{{%inventory}}', '{{%inventory}}.id = {{%job_template}}.inventory_id');
        } elseif ($sortAttr === 'runner_group') {
            $query->leftJoin('{{%runner_group}}', '{{%runner_group}}.id = {{%job_template}}.runner_group_id');
        }

        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        $this->requireChildView($model->project_id);
        return $this->render('view', ['model' => $model]);
    }

    public function actionCreate(?int $project_id = null, ?string $playbook = null): Response|string
    {
        $model = new JobTemplate();
        $model->verbosity = 0;
        $model->forks = 5;
        $model->timeout_minutes = 120;
        $model->become = false;
        $model->become_method = 'sudo';
        $model->become_user = 'root';
        if ($project_id !== null) {
            $model->project_id = $project_id;
        }
        if ($playbook !== null) {
            $model->playbook = $playbook;
        }
        if ($model->load((array)\Yii::$app->request->post())) {
            $this->requireChildOperate($model->project_id);
            $model->created_by = (int)(\Yii::$app->user->id ?? 0);
            if ($model->save()) {
                $this->syncCredentialPivot($model);
                \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEMPLATE_CREATED, 'job_template', $model->id, null, ['name' => $model->name]);
                /** @var \app\services\LintService $lintService */
                $lintService = \Yii::$app->get('lintService');
                $lintService->runForTemplate($model);
                $this->session()->setFlash('success', "Template \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', $this->formData($model));
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        if ($model->load((array)\Yii::$app->request->post()) && $model->save()) {
            $this->syncCredentialPivot($model);
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', $model->id, null, ['name' => $model->name]);
            /** @var \app\services\LintService $lintService */
            $lintService = \Yii::$app->get('lintService');
            $lintService->runForTemplate($model);
            $this->session()->setFlash('success', "Template \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', $this->formData($model));
    }

    /**
     * Reconcile the job_template_credential pivot against the submitted
     * form state. The primary credential_id is stored first (sort_order
     * 0), then any checked extras in stable order.
     */
    private function syncCredentialPivot(JobTemplate $model): void
    {
        /** @var array<int, mixed> $rawExtras */
        $rawExtras = (array)\Yii::$app->request->post('credential_ids', []);
        $extras = [];
        foreach ($rawExtras as $id) {
            $id = (int)$id;
            if ($id > 0 && $id !== (int)$model->credential_id) {
                $extras[] = $id;
            }
        }

        $db = \Yii::$app->db;
        $db->createCommand()->delete('{{%job_template_credential}}', ['job_template_id' => $model->id])->execute();

        $sort = 0;
        if ((int)$model->credential_id > 0) {
            $db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $model->id,
                'credential_id' => (int)$model->credential_id,
                'sort_order' => $sort++,
            ])->execute();
        }
        foreach (array_unique($extras) as $extraId) {
            $db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $model->id,
                'credential_id' => $extraId,
                'sort_order' => $sort++,
            ])->execute();
        }
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        $name = $model->name;
        $model->softDelete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEMPLATE_DELETED, 'job_template', $id, null, ['name' => $name]);
        $this->session()->setFlash('success', "Template \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    /**
     * POST /job-template/clone?id=<source_id>
     *
     * Duplicates a template 1:1 — all config fields, the full credential
     * attachment list (primary + pivot), and survey_fields — under a new
     * name "<source> (copy)". Stale and security-sensitive fields are
     * stripped (trigger_token, lint_output/lint_at/lint_exit_code).
     *
     * Redirects straight to /job-template/update so the operator can
     * adjust the name and any other fields before committing.
     *
     * Audit: emits a plain ACTION_TEMPLATE_CREATED event with
     * meta.cloned_from pointing at the source template's id and name,
     * so lineage stays discoverable without a dedicated event type.
     */
    public function actionClone(int $id): Response
    {
        $source = $this->findModel($id);
        $this->requireChildView($source->project_id);

        $clone = new JobTemplate();
        foreach ($source->attributes as $attr => $value) {
            if (in_array($attr, [
                'id',
                'created_at',
                'updated_at',
                'trigger_token',
                'lint_output',
                'lint_at',
                'lint_exit_code',
                'deleted_at',
            ], true)) {
                continue;
            }
            $clone->$attr = $value;
        }
        $clone->name = $this->resolveCloneName($source->name);
        $clone->created_by = (int)(\Yii::$app->user->id ?? 0);

        if (!$clone->save()) {
            $this->session()->setFlash('danger', 'Clone failed: ' . json_encode($clone->errors));
            return $this->redirect(['view', 'id' => $source->id]);
        }

        $this->copyCredentialPivot($source->id, $clone->id);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_CREATED,
            'job_template',
            $clone->id,
            null,
            [
                'name' => $clone->name,
                'cloned_from' => $source->id,
                'cloned_from_name' => $source->name,
            ],
        );

        $this->session()->setFlash(
            'success',
            "Cloned \"{$source->name}\" → \"{$clone->name}\". Rename or adjust as needed, then save.",
        );
        return $this->redirect(['update', 'id' => $clone->id]);
    }

    /**
     * Pick a non-colliding name for the clone. Starts with
     * "<source> (copy)", then "(copy 2)", "(copy 3)", … up to 100
     * attempts before giving up with a timestamped suffix.
     */
    private function resolveCloneName(string $sourceName): string
    {
        // Strip an existing "(copy)" / "(copy N)" suffix so cloning a
        // clone doesn't produce "Foo (copy) (copy)".
        $base = preg_replace('/\s*\(copy(?:\s+\d+)?\)\s*$/u', '', $sourceName) ?? $sourceName;
        $candidate = "{$base} (copy)";
        for ($i = 2; $i <= 100; $i++) {
            if (!JobTemplate::find()->where(['name' => $candidate])->exists()) {
                return $candidate;
            }
            $candidate = "{$base} (copy {$i})";
        }
        return "{$base} (copy " . time() . ')';
    }

    /**
     * Duplicate every job_template_credential row from $sourceId onto
     * $cloneId, preserving sort_order. The primary credential_id on the
     * clone was already set via the attribute copy — these are the
     * additional attachments.
     */
    private function copyCredentialPivot(int $sourceId, int $cloneId): void
    {
        $db = \Yii::$app->db;
        $rows = $db->createCommand(
            'SELECT credential_id, sort_order FROM {{%job_template_credential}} WHERE job_template_id = :id ORDER BY sort_order',
            [':id' => $sourceId],
        )->queryAll();
        foreach ($rows as $row) {
            $db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $cloneId,
                'credential_id' => (int)$row['credential_id'],
                'sort_order' => (int)$row['sort_order'],
            ])->execute();
        }
    }

    public function actionLaunch(): Response|string
    {
        $id = (int)(\Yii::$app->request->get('id') ?? \Yii::$app->request->post('id', 0));
        $template = $this->findModel($id);
        $this->requireChildOperate($template->project_id);
        /** @var array<string, mixed> $overrides */
        $overrides = (array)\Yii::$app->request->post('overrides', []);
        /** @var array<string, mixed> $survey */
        $survey = (array)\Yii::$app->request->post('survey', []);
        if (!empty($survey)) {
            $overrides['survey'] = $survey;
        }

        if (\Yii::$app->request->isPost) {
            try {
                /** @var JobLaunchService $svc */
                $svc = \Yii::$app->get('jobLaunchService');
                $job = $svc->launch($template, (int)(\Yii::$app->user->id ?? 0), $overrides);
                $this->session()->setFlash('success', "Job #{$job->id} queued.");
                return $this->redirect(['/job/view', 'id' => $job->id]);
            } catch (\RuntimeException $e) {
                $this->session()->setFlash('danger', 'Launch failed: ' . $e->getMessage());
            }
        }
        return $this->render('launch', ['template' => $template]);
    }

    public function actionGenerateTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        $rawToken = $model->generateTriggerToken();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED,
            'job_template',
            $id,
            \Yii::$app->user->id,
            ['name' => $model->name]
        );
        $this->session()->setFlash('success', 'Trigger token generated. Copy it now — it will not be shown again.');
        // Flash the raw token once so the view can display it. The DB stores only the hash.
        $this->session()->setFlash('trigger_token_raw', $rawToken);
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionRevokeTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        $model->revokeTriggerToken();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED,
            'job_template',
            $id,
            \Yii::$app->user->id,
            ['name' => $model->name]
        );
        $this->session()->setFlash('success', 'Trigger token revoked. The /trigger endpoint is now disabled for this template.');
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(JobTemplate $model): array
    {
        $userId = $this->currentUserId();
        $checker = $this->checker();

        $projectQuery = Project::find()->orderBy('name');
        $projectFilter = $checker->buildProjectFilter($userId);
        if ($projectFilter !== null) {
            $projectQuery->andWhere($projectFilter);
        }

        $inventoryQuery = Inventory::find()->orderBy('name');
        $inventoryFilter = $checker->buildChildResourceFilter($userId, 'inventory.project_id');
        if ($inventoryFilter !== null) {
            $inventoryQuery->andWhere($inventoryFilter);
        }

        return [
            'model' => $model,
            'projects' => $projectQuery->all(),
            'inventories' => $inventoryQuery->all(),
            'credentials' => Credential::find()->orderBy('name')->all(),
            'runnerGroups' => RunnerGroup::find()->orderBy('name')->all(),
        ];
    }

    private function findModel(int $id): JobTemplate
    {
        /** @var JobTemplate|null $model */
        $model = JobTemplate::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Job template #{$id} not found.");
        }
        return $model;
    }
}
