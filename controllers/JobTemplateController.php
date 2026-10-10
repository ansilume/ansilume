<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\JobTemplateWarnings;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\User;
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
        $warningCounts = JobTemplateWarnings::counts(clone $query);
        $warning = \Yii::$app->request->get('warning', '');
        $activeWarning = is_string($warning) && JobTemplateWarnings::filter($query, $warning) ? $warning : null;

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
            'sort' => [
                'defaultOrder' => ['name' => SORT_ASC],
                // Qualified: the warning filter joins the inventory table.
                'attributes' => [
                    'id' => ['asc' => ['{{%job_template}}.id' => SORT_ASC], 'desc' => ['{{%job_template}}.id' => SORT_DESC]],
                    'name' => ['asc' => ['{{%job_template}}.name' => SORT_ASC], 'desc' => ['{{%job_template}}.name' => SORT_DESC]],
                    'playbook' => ['asc' => ['{{%job_template}}.playbook' => SORT_ASC], 'desc' => ['{{%job_template}}.playbook' => SORT_DESC]],
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

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'activeWarning' => $activeWarning,
            'warningCounts' => $warningCounts,
        ]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        $this->requireChildView($model->project_id);
        return $this->render('view', [
            'model' => $model,
            'attachedCredentials' => $this->credentialService()->describe($model),
            'warnings' => JobTemplateWarnings::forTemplate($model),
            // Only users who may change the template see its trigger card
            // and whom the trigger runs as.
            'canChange' => $this->mayChange($model),
        ]);
    }

    /**
     * Whether the user may change the template, as generating or revoking
     * its trigger token needs: job-template.update (superadmins hold every
     * permission, as in the access rules) and operator access to its project.
     */
    private function mayChange(JobTemplate $model): bool
    {
        $identity = \Yii::$app->user->identity;
        $superadmin = $identity instanceof User && (bool)$identity->is_superadmin;

        return ($superadmin || \Yii::$app->user->can('job-template.update'))
            && $this->checker()->canOperateChildResource((int)$this->currentUserId(), $model->project_id);
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
            $this->restrictInventories($model);
            $model->created_by = (int)(\Yii::$app->user->id ?? 0);
            if ($this->credentialService()->saveWithCredentials($model, $this->postedCredentialIds())) {
                /** @var \app\services\LintService $lintService */
                $lintService = \Yii::$app->get('lintService');
                $lintService->runForTemplate($model);
                $this->session()->setFlash('success', "Template \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
            return $this->render('form', $this->formData($model, $this->postedCredentialIds()));
        }
        return $this->render('form', $this->formData($model));
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        if ($model->load((array)\Yii::$app->request->post())) {
            // The project it moves to as well, not only the one it comes from.
            $this->requireChildOperate($model->project_id);
            $this->restrictInventories($model);
            if ($this->credentialService()->saveWithCredentials($model, $this->postedCredentialIds())) {
                /** @var \app\services\LintService $lintService */
                $lintService = \Yii::$app->get('lintService');
                $lintService->runForTemplate($model);
                $this->session()->setFlash('success', "Template \"{$model->name}\" updated.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
            return $this->render('form', $this->formData($model, $this->postedCredentialIds()));
        }
        return $this->render('form', $this->formData($model));
    }

    /**
     * Checked additional credentials, in form order. No checkbox checked
     * means no additional credentials.
     *
     * @return list<mixed>
     */
    private function postedCredentialIds(): array
    {
        return array_values((array)\Yii::$app->request->post('credential_ids', []));
    }

    /**
     * Launches that skip the launch page (dashboard quick launch, a form
     * posted elsewhere) still show the template's warnings, after the fact.
     */
    private function flashTemplateWarnings(JobTemplate $template): void
    {
        $warnings = JobTemplateWarnings::forTemplate($template);
        if ($warnings !== []) {
            $this->session()->setFlash('warning', implode(' ', array_column($warnings, 'message')));
        }
    }

    /**
     * Only inventories the user may see: the form offers no others, and a
     * crafted request must not reach another team's hosts.
     */
    private function restrictInventories(JobTemplate $model): void
    {
        $model->restrictInventories($this->checker()->buildChildResourceFilter($this->currentUserId(), 'inventory.project_id'));
    }

    private function credentialService(): \app\services\JobTemplateCredentialService
    {
        /** @var \app\services\JobTemplateCredentialService $service */
        $service = \Yii::$app->get('jobTemplateCredentialService');

        return $service;
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
     * stripped (trigger_token and its trigger_token_created_by,
     * lint_output/lint_at/lint_exit_code).
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
        // The clone is created in the source's project.
        $this->requireChildOperate($source->project_id);

        $clone = new JobTemplate();
        foreach ($source->attributes as $attr => $value) {
            if (
                in_array($attr, [
                'id',
                'created_at',
                'updated_at',
                'trigger_token',
                // The clone has no token; a copied generator would also
                // keep that user from being deleted (foreign key).
                'trigger_token_created_by',
                'lint_output',
                'lint_at',
                'lint_exit_code',
                'deleted_at',
                ], true)
            ) {
                continue;
            }
            $clone->$attr = $value;
        }
        $clone->name = $this->resolveCloneName($source->name);
        $clone->created_by = (int)(\Yii::$app->user->id ?? 0);
        $this->restrictInventories($clone);

        $cloned = $this->credentialService()->copyWithCredentials($clone, $source, [
            'cloned_from' => $source->id,
            'cloned_from_name' => $source->name,
        ]);
        if (!$cloned) {
            $this->session()->setFlash('danger', 'Clone failed: ' . implode(' ', $clone->getFirstErrors()) . ' Fix the source template first.');
            return $this->redirect(['view', 'id' => $source->id]);
        }

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
                $this->flashTemplateWarnings($template);
                return $this->redirect(['/job/view', 'id' => $job->id]);
            } catch (\RuntimeException $e) {
                $this->session()->setFlash('danger', 'Launch failed: ' . $e->getMessage());
            }
        }
        return $this->render('launch', [
            'template' => $template,
            'attachedCredentials' => $this->credentialService()->describe($template),
            'warnings' => JobTemplateWarnings::forTemplate($template),
        ]);
    }

    public function actionGenerateTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        $rawToken = $model->generateTriggerToken((int)\Yii::$app->user->id);
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
    /**
     * @param list<mixed>|null $selectedCredentialIds submitted additional
     *        credentials to re-render; null shows the stored ones
     * @return array<string, mixed>
     */
    private function formData(JobTemplate $model, ?array $selectedCredentialIds = null): array
    {
        $userId = $this->currentUserId();
        $checker = $this->checker();

        $projectQuery = Project::find()->orderBy('name');
        $projectFilter = $checker->buildProjectFilter($userId);
        if ($projectFilter !== null) {
            $projectQuery->andWhere($projectFilter);
        }

        $inventoryQuery = Inventory::find()->with('project')->orderBy('name');
        $inventoryFilter = $checker->buildChildResourceFilter($userId, 'inventory.project_id');
        if ($inventoryFilter !== null) {
            $inventoryQuery->andWhere($inventoryFilter);
        }

        return [
            'model' => $model,
            'projects' => $projectQuery->all(),
            'inventories' => $inventoryQuery->all(),
            'credentials' => Credential::find()->orderBy('name')->all(),
            'selectedCredentialIds' => $selectedCredentialIds !== null
                ? array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $selectedCredentialIds)
                : ($model->isNewRecord ? [] : $this->credentialService()->additionalIds($model)),
            'runnerGroups' => RunnerGroup::find()->orderBy('name')->all(),
            // From the stored template, not from a rejected submission.
            'warnings' => $model->isNewRecord ? [] : JobTemplateWarnings::forTemplate($this->findModel((int)$model->id)),
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
