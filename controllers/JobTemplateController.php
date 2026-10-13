<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\JobTemplateWarnings;
use app\components\WebLaunchOverrides;
use app\models\Credential;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedJobTemplateLock;
use app\services\JobLaunchInput;
use app\services\JobLaunchService;
use app\services\JobTemplateDeletionService;
use app\services\JobTemplateRuleAssignment;
use app\services\LintService;
use app\services\TriggerTokenService;
use app\controllers\traits\TeamScopingTrait;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class JobTemplateController extends BaseController
{
    use TeamScopingTrait;

    /** sprintf(): the job ID. */
    public const FLASH_QUEUED = 'Job #%d queued.';

    /** A launch of a template with an approval rule; sprintf(): the job ID. */
    public const FLASH_WAITING_FOR_APPROVAL = 'Job #%d is waiting for approval.';

    /**
     * sprintf(): why the launch was refused or failed, as
     * JobLaunchService::failureReason() says it (without the
     * "Job launch failed: " of JobLaunchService's own failures).
     */
    public const FLASH_LAUNCH_FAILED = 'Launch failed: %s';

    /** sprintf(): the template's name, the waiting jobs canceled, the schedules disabled. */
    public const FLASH_DELETED = 'Template "%s" deleted: %d waiting job(s) canceled, %d schedule(s) disabled.';

    /**
     * Posted by an edit form that did not offer the additional credentials
     * (a gated template, GatedJobTemplateLock): without credential_ids, the
     * stored ones stay. Without this field, no credential_ids means none.
     */
    public const KEEP_CREDENTIALS = 'keep_credentials';

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
        $canChange = $this->mayChange($model);
        return $this->render('view', [
            'model' => $model,
            'attachedCredentials' => $this->credentialService()->describe($model),
            'warnings' => JobTemplateWarnings::forTemplate($model),
            // Only users who may change the template see its trigger card
            // and whom the trigger runs as.
            'canChange' => $canChange,
            // Users who may change the template, but not what it runs (a
            // gated template, no approval-rule.update), are told so.
            'changeLocked' => $canChange && (new GatedJobTemplateLock())->appliesTo((int)$this->currentUserId(), $model),
            // Everyone sees the template's approval rule; a link to it only
            // for users who may open rules.
            'canViewRules' => $this->userCan('approval-rule.view'),
        ]);
    }

    /**
     * Whether the user may change the template, as generating or revoking
     * its trigger token needs: job-template.update and operator access to
     * its project.
     */
    private function mayChange(JobTemplate $model): bool
    {
        return $this->userCan('job-template.update')
            && $this->checker()->canOperateChildResource((int)$this->currentUserId(), $model->project_id);
    }

    /**
     * Whether the signed-in user holds $permission; superadmins hold every
     * permission, as in the access rules.
     */
    private function userCan(string $permission): bool
    {
        $identity = \Yii::$app->user->identity;

        return ($identity instanceof User && (bool)$identity->is_superadmin) || \Yii::$app->user->can($permission);
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
            $ruleAudit = $this->applySubmittedRule($model, null);
            $credentialIds = $this->postedCredentialIds();
            if ($this->credentialService()->saveWithCredentials($model, $credentialIds, $ruleAudit, $this->currentUserId())) {
                /** @var \app\services\LintService $lintService */
                $lintService = \Yii::$app->get('lintService');
                $lintService->runForTemplate($model);
                $this->session()->setFlash('success', "Template \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
            return $this->render('form', $this->formData($model, $credentialIds));
        }
        return $this->render('form', $this->formData($model));
    }

    /**
     * Only admins (approval-rule.update) change the approval rule, and what
     * a gated template runs (GatedJobTemplateLock): for everyone else a
     * change of the rule (ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED) or
     * of a gated template's columns other than name and description, or of
     * its credential list (GatedJobTemplateLock::CHANGE_REFUSED), is a 403,
     * and nothing is saved.
     */
    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        if ($model->load((array)\Yii::$app->request->post())) {
            // The project it moves to as well, not only the one it comes from.
            $this->requireChildOperate($model->project_id);
            $this->restrictInventories($model);
            $ruleAudit = $this->applySubmittedRule($model, ApprovalRuleAssignmentPolicy::storedRuleId($model));
            $credentialIds = $this->postedCredentialIds();
            if ($this->credentialService()->saveWithCredentials($model, $credentialIds, $ruleAudit, $this->currentUserId())) {
                /** @var \app\services\LintService $lintService */
                $lintService = \Yii::$app->get('lintService');
                $lintService->runForTemplate($model);
                $this->session()->setFlash('success', "Template \"{$model->name}\" updated.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
            if (GatedJobTemplateLock::refused($model)) {
                throw new ForbiddenHttpException(GatedJobTemplateLock::CHANGE_REFUSED);
            }
            return $this->render('form', $this->formData($model, $credentialIds));
        }
        return $this->render('form', $this->formData($model));
    }

    /**
     * Apply the submitted approval rule (JobTemplate[approval_rule_id]) to
     * $model, when the form submitted one: only users who may assign rules
     * (approval-rule.update) may change it, also on a new template.
     * $baseline is the rule the template has (null for a new one).
     *
     * @return array<string, mixed> the audit context of the rule change, if any
     * @throws ForbiddenHttpException with the refusal, before anything is saved
     */
    private function applySubmittedRule(JobTemplate $model, ?int $baseline): array
    {
        $posted = \Yii::$app->request->post($model->formName(), []);
        $assignment = new JobTemplateRuleAssignment();
        $refusal = $assignment->apply($model, is_array($posted) ? $posted : [], (int)$this->currentUserId(), $baseline);
        if ($refusal !== null) {
            throw new ForbiddenHttpException($refusal);
        }

        return $assignment->auditContext($model, $baseline);
    }

    /**
     * Checked additional credentials, in form order. No checkbox checked
     * means no additional credentials; null (keep the stored ones) when the
     * form did not offer them (KEEP_CREDENTIALS) and none were posted.
     *
     * @return list<mixed>|null
     */
    private function postedCredentialIds(): ?array
    {
        $posted = \Yii::$app->request->post('credential_ids');
        if ($posted === null && \Yii::$app->request->post(self::KEEP_CREDENTIALS) !== null) {
            return null;
        }

        return array_values((array)($posted ?? []));
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

    /**
     * Soft-delete the template (JobTemplateDeletionService): its enabled
     * schedules are disabled and its waiting jobs canceled; the flash
     * reports both counts.
     */
    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        /** @var JobTemplateDeletionService $deletion */
        $deletion = \Yii::$app->get('jobTemplateDeletionService');
        $result = $deletion->delete($model, $this->currentUserId(), JobTemplateDeletionService::SOURCE_WEB);
        if (!$result['deleted']) {
            // Another request deleted it first.
            throw new NotFoundHttpException("Job template #{$id} not found.");
        }
        $this->session()->setFlash(
            'success',
            sprintf(self::FLASH_DELETED, $model->name, $result['canceled_jobs'], $result['disabled_schedules'])
        );
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
     *
     * The clone keeps the source's approval rule, for every user who may
     * clone: copying is not assigning, and dropping the rule would create
     * an ungated twin. The audit entry names the rule (approval_rule).
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
        // The survey rules compare with the source, so a legacy survey clones as it is.
        $clone->setCopiedFrom($source);
        $this->restrictInventories($clone);

        $cloned = $this->credentialService()->copyWithCredentials($clone, $source, [
            'cloned_from' => $source->id,
            'cloned_from_name' => $source->name,
        ] + (new JobTemplateRuleAssignment())->auditContext($clone, null));
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
     * GET shows the launch form; POST launches. A job of a template with an
     * approval rule waits for approval (an info flash says so); a refused
     * launch (JobLaunchRejectedException, such as too few eligible
     * approvers or a template deleted meanwhile, or a limit or verbosity
     * the job record would refuse, JobLaunchInput) shows the form again
     * with the reason.
     */
    public function actionLaunch(): Response|string
    {
        $id = (int)(\Yii::$app->request->get('id') ?? \Yii::$app->request->post('id', 0));
        $template = $this->findModel($id);
        $this->requireChildOperate($template->project_id);
        // Only the form's fields: server-only launch data cannot be posted.
        $overrides = WebLaunchOverrides::fromRequest(\Yii::$app->request->post('overrides'), \Yii::$app->request->post('survey'));

        if (\Yii::$app->request->isPost) {
            try {
                JobLaunchInput::check($overrides);
                /** @var JobLaunchService $svc */
                $svc = \Yii::$app->get('jobLaunchService');
                $job = $svc->launch($template, (int)(\Yii::$app->user->id ?? 0), $overrides);
                if ($job->status === Job::STATUS_PENDING_APPROVAL) {
                    $this->session()->setFlash('info', sprintf(self::FLASH_WAITING_FOR_APPROVAL, $job->id));
                } else {
                    $this->session()->setFlash('success', sprintf(self::FLASH_QUEUED, $job->id));
                }
                $this->flashTemplateWarnings($template);
                return $this->redirect(['/job/view', 'id' => $job->id]);
            } catch (\RuntimeException $e) {
                // ErrorText words it: a PDOException is a RuntimeException, and its message can carry SQL with values.
                $this->session()->setFlash('danger', sprintf(self::FLASH_LAUNCH_FAILED, JobLaunchService::failureReason($e)));
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
        $rawToken = (new TriggerTokenService())->generate($model, (int)\Yii::$app->user->id);
        $this->session()->setFlash('success', 'Trigger token generated. Copy it now — it will not be shown again.');
        // Flash the raw token once so the view can display it. The DB stores only the hash.
        $this->session()->setFlash('trigger_token_raw', $rawToken);
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionRevokeTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        (new TriggerTokenService())->revoke($model, (int)\Yii::$app->user->id);
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
        // The rule select only for users who may assign rules; the others see the rule as text.
        $ruleAssignment = new JobTemplateRuleAssignment();
        $canAssignRule = $ruleAssignment->mayAssign((int)$userId);

        return [
            'canAssignRule' => $canAssignRule,
            'approvalRuleOptions' => $canAssignRule ? $ruleAssignment->options() : [],
            // A gated template, for a user who may not assign rules: only name and description are enabled.
            'changeLocked' => !$canAssignRule && (new GatedJobTemplateLock())->appliesTo((int)$userId, $model),
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
