<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\WorkerHeartbeat;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\LintService;
use app\services\ProjectAccessChecker;
use app\services\ProjectService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ProjectController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view', 'sync-status'], 'allow' => true, 'roles' => ['project.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['project.create']],
            ['actions' => ['update', 'sync', 'lint'], 'allow' => true, 'roles' => ['project.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['project.delete']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['delete' => ['POST'], 'sync' => ['POST'], 'lint' => ['POST']];
    }

    public function actionIndex(): string
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        $query = Project::find()->with('creator')->orderBy(['id' => SORT_DESC]);

        $filter = $checker->buildProjectFilter(\Yii::$app->user->isGuest ? null : (int)\Yii::$app->user->id);
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        if (!$checker->canView((int)\Yii::$app->user->id, $model->id)) {
            throw new \yii\web\ForbiddenHttpException('You do not have access to this project.');
        }
        $playbooks = [];
        $tree = [];
        $localPath = $this->resolveEffectivePath($model);
        if ($localPath !== null) {
            $scanner = new \app\services\ProjectFilesystemScanner();
            $playbooks = $scanner->detectPlaybooks($localPath);
            $tree = $scanner->buildTree($localPath, $localPath);
        }
        return $this->render('view', ['model' => $model, 'playbooks' => $playbooks, 'tree' => $tree]);
    }

    /**
     * Resolve the effective local filesystem path for a project.
     *
     * For git projects, always derive the path from ProjectService (authoritative)
     * rather than relying on the stored local_path field, which may be null on
     * a project that has not been synced yet or stale on a moved installation.
     *
     * For manual projects, fall back to the stored local_path.
     */
    private function resolveEffectivePath(Project $model): ?string
    {
        if ($model->scm_type === Project::SCM_TYPE_GIT) {
            /** @var ProjectService $svc */
            $svc = \Yii::$app->get('projectService');
            $path = $svc->localPath($model);
            return is_dir($path) ? $path : null;
        }

        return $this->resolveLocalPath($model->local_path);
    }

    /**
     * Resolve a stored local_path to a real absolute filesystem path.
     * Handles Yii aliases (@runtime/…) and relative paths from old records.
     */
    private function resolveLocalPath(?string $stored): ?string
    {
        if (!$stored) {
            return null;
        }
        // Try Yii alias resolution first
        $resolved = \Yii::getAlias($stored, false);
        if ($resolved !== false && is_dir($resolved)) {
            return $resolved;
        }
        // Fallback: relative path rooted at the app base path (legacy behaviour
        // where git created the directory literally, e.g. /var/www/@runtime/…)
        $fallback = \Yii::$app->basePath . '/' . $stored;
        if (is_dir($fallback)) {
            return $fallback;
        }
        return null;
    }

    public function actionCreate(): Response|string
    {
        $model = new Project();
        $model->scm_type = Project::SCM_TYPE_GIT;
        $model->scm_branch = 'main';
        $model->status = Project::STATUS_NEW;

        if ($model->load((array)\Yii::$app->request->post())) {
            $model->created_by = (int)\Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_CREATED, 'project', $model->id, null, ['name' => $model->name]);
                if ($model->scm_type === Project::SCM_TYPE_GIT && $model->scm_url) {
                    /** @var ProjectService $svc */
                    $svc = \Yii::$app->get('projectService');
                    $svc->queueSync($model);
                    $this->session()->setFlash('success', "Project \"{$model->name}\" created. Sync queued.");
                } else {
                    $this->session()->setFlash('success', "Project \"{$model->name}\" created.");
                }
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model, 'scmCredentials' => $this->scmCredentials()]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireAccess($model, true);
        if ($model->load((array)\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_UPDATED, 'project', $model->id, null, ['name' => $model->name]);
            if ($model->scm_type === Project::SCM_TYPE_GIT && $model->scm_url) {
                /** @var ProjectService $svc */
                $svc = \Yii::$app->get('projectService');
                $svc->queueSync($model);
                $this->session()->setFlash('success', "Project \"{$model->name}\" updated. Sync queued.");
            } else {
                $this->session()->setFlash('success', "Project \"{$model->name}\" updated.");
            }
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', ['model' => $model, 'scmCredentials' => $this->scmCredentials()]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireAccess($model, true);

        $templateCount = $model->getJobTemplates()->count();
        if ($templateCount > 0) {
            $this->session()->setFlash('danger', "Cannot delete \"{$model->name}\": {$templateCount} job template(s) still reference this project. Remove or reassign them first.");
            return $this->redirect(['view', 'id' => $id]);
        }

        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_DELETED, 'project', $id, null, ['name' => $name]);
        $this->session()->setFlash('success', "Project \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionLint(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireAccess($model, true);
        /** @var LintService $lintSvc */
        $lintSvc = \Yii::$app->get('lintService');
        $lintSvc->runForProject($model);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_LINTED, 'project', $model->id, null, ['name' => $model->name]);
        $this->session()->setFlash('success', "Lint completed for \"{$model->name}\".");
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionSync(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireAccess($model, true);
        if ($model->scm_type !== Project::SCM_TYPE_GIT) {
            $this->session()->setFlash('warning', 'This project has no SCM configured.');
            return $this->redirect(['view', 'id' => $id]);
        }
        /** @var ProjectService $svc */
        $svc = \Yii::$app->get('projectService');
        $svc->queueSync($model);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_SYNCED, 'project', $model->id, null, ['name' => $model->name]);
        $this->session()->setFlash('success', "Sync queued for \"{$model->name}\".");
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * GET /project/sync-status?id=N&since=SEQ
     *
     * JSON snapshot for the live-polling sync log on /project/view. Returns
     * the project's current sync status plus any project_sync_log rows the
     * client has not yet seen, so the panel can append them in place
     * without reloading the page. Caller passes the highest sequence it
     * has rendered as `since`; the server sends only newer rows.
     *
     * @return array<string, mixed>
     */
    public function actionSyncStatus(int $id, int $since = 0): array
    {
        $model = $this->findModel($id);
        $this->requireAccess($model, false);
        \Yii::$app->response->format = Response::FORMAT_JSON;

        $rows = ProjectSyncLog::find()
            ->where(['project_id' => $model->id])
            ->andWhere(['>', 'sequence', $since])
            ->orderBy(['sequence' => SORT_ASC])
            ->limit(500)
            ->all();

        $logs = [];
        foreach ($rows as $row) {
            /** @var ProjectSyncLog $row */
            $logs[] = [
                'sequence' => (int)$row->sequence,
                'stream' => (string)$row->stream,
                'content' => (string)$row->content,
                'created_at' => (int)$row->created_at,
            ];
        }

        return [
            'id' => (int)$model->id,
            'status' => (string)$model->status,
            'is_syncing' => $model->status === Project::STATUS_SYNCING,
            'sync_started_at' => $model->sync_started_at !== null ? (int)$model->sync_started_at : null,
            'last_synced_at' => $model->last_synced_at !== null ? (int)$model->last_synced_at : null,
            'last_sync_error' => $model->last_sync_error !== null ? (string)$model->last_sync_error : null,
            'logs' => $logs,
            'worker' => $this->workerSnapshot(),
        ];
    }

    /**
     * Snapshot of queue-worker liveness so the sync log panel can show
     * "no worker running" or "worker is 4 days old (stale code likely)"
     * — both are common causes of a syncing-but-empty-output card and the
     * operator otherwise has no way to tell from the UI alone.
     *
     * @return array{
     *     alive: bool,
     *     count: int,
     *     last_seen_seconds_ago: int|null,
     *     oldest_started_seconds_ago: int|null,
     *     stale_after_seconds: int,
     *     stale_code_warn_seconds: int,
     * }
     */
    private function workerSnapshot(): array
    {
        $workers = WorkerHeartbeat::all();
        $now = time();
        $latestSeen = 0;
        $oldestStart = 0;
        foreach ($workers as $w) {
            $latestSeen = max($latestSeen, (int)($w['seen_at'] ?? 0));
            $started = (int)($w['started_at'] ?? 0);
            if ($started > 0 && ($oldestStart === 0 || $started < $oldestStart)) {
                $oldestStart = $started;
            }
        }
        return [
            'alive' => count($workers) > 0,
            'count' => count($workers),
            'last_seen_seconds_ago' => $latestSeen > 0 ? $now - $latestSeen : null,
            'oldest_started_seconds_ago' => $oldestStart > 0 ? $now - $oldestStart : null,
            'stale_after_seconds' => WorkerHeartbeat::STALE_AFTER,
            // 24h: arbitrary-but-safe threshold beyond which a long-running
            // worker is statistically likely to have stale opcache/code in
            // memory after one or more deploys.
            'stale_code_warn_seconds' => 86400,
        ];
    }

    /**
     * Credentials compatible with SCM authentication (SSH key, token, username/password).
     *
     * @return \app\models\Credential[]
     */
    private function scmCredentials(): array
    {
        return Credential::find()
            ->where(['credential_type' => [
                Credential::TYPE_SSH_KEY,
                Credential::TYPE_TOKEN,
                Credential::TYPE_USERNAME_PASSWORD,
            ]])
            ->orderBy('name')
            ->all();
    }

    private function findModel(int $id): Project
    {
        /** @var Project|null $model */
        $model = Project::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Project #{$id} not found.");
        }
        return $model;
    }

    private function requireAccess(Project $model, bool $operatorRequired = false): void
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        $userId = (int)\Yii::$app->user->id;
        if ($operatorRequired) {
            if (!$checker->canOperate($userId, $model->id)) {
                throw new \yii\web\ForbiddenHttpException('You do not have permission to modify this project.');
            }
        } elseif (!$checker->canView($userId, $model->id)) {
            throw new \yii\web\ForbiddenHttpException('You do not have access to this project.');
        }
    }
}
