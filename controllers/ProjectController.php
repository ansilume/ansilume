<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Credential;
use app\models\Project;
use app\services\LintService;
use app\services\ProjectAccessChecker;
use app\services\ProjectService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ProjectController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'],   'allow' => true, 'roles' => ['project.view']],
            ['actions' => ['create'],           'allow' => true, 'roles' => ['project.create']],
            ['actions' => ['update', 'sync', 'lint'], 'allow' => true, 'roles' => ['project.update']],
            ['actions' => ['delete'],           'allow' => true, 'roles' => ['project.delete']],
        ];
    }

    protected function verbRules(): array
    {
        return ['delete' => ['POST'], 'sync' => ['POST'], 'lint' => ['POST']];
    }

    public function actionIndex(): string
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        $query   = Project::find()->with('creator')->orderBy(['id' => SORT_DESC]);

        $filter = $checker->buildProjectFilter(\Yii::$app->user->id);
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query'      => $query,
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        if (!$checker->canView(\Yii::$app->user->id, $model->id)) {
            throw new \yii\web\ForbiddenHttpException('You do not have access to this project.');
        }
        $playbooks = [];
        $tree      = [];
        $localPath = $this->resolveEffectivePath($model);
        if ($localPath !== null) {
            $playbooks = $this->detectPlaybooks($localPath);
            $tree      = $this->buildTree($localPath, $localPath, 0, 5);
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
            $svc  = \Yii::$app->get('projectService');
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

    /**
     * Return root-level YAML files that look like playbooks
     * (contain a top-level list, i.e. start with "---" or "- ").
     */
    private function detectPlaybooks(string $base): array
    {
        $playbooks = [];

        // Root-level YAML files
        foreach (glob($base . '/*.{yml,yaml}', GLOB_BRACE) ?: [] as $file) {
            if ($this->looksLikePlaybook($file)) {
                $playbooks[] = basename($file);
            }
        }

        // Recursively scan playbooks/ directory
        if (is_dir($base . '/playbooks')) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base . '/playbooks', \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if ($ext !== 'yml' && $ext !== 'yaml') {
                    continue;
                }
                if ($this->looksLikePlaybook($file->getPathname())) {
                    $playbooks[] = 'playbooks/' . ltrim(
                        substr($file->getPathname(), strlen($base . '/playbooks')), '/'
                    );
                }
            }
        }

        sort($playbooks);
        return $playbooks;
    }

    private function looksLikePlaybook(string $path): bool
    {
        $name = strtolower(basename($path));

        if (str_starts_with($name, '.')) {
            return false;
        }

        // Known non-playbook YAML files — exclude by filename.
        $excluded = [
            'requirements.yml', 'requirements.yaml',
            'galaxy.yml',        'galaxy.yaml',
            'molecule.yml',      'molecule.yaml',
        ];
        if (in_array($name, $excluded, true)) {
            return false;
        }

        $head = file_get_contents($path, false, null, 0, 512);
        if ($head === false) {
            return false;
        }

        // A playbook is a YAML list at the document root.
        // Skip blank lines, the document-start marker (---), and comments.
        // The first real content line must start with "- " (no indentation).
        foreach (explode("\n", $head) as $line) {
            $trimmed = rtrim($line);
            if ($trimmed === '' || $trimmed === '---' || str_starts_with($trimmed, '#')) {
                continue;
            }
            return str_starts_with($trimmed, '- ') || $trimmed === '-';
        }

        return false;
    }

    /**
     * Recursively build a directory tree array, ignoring .git and hidden dirs.
     * Each node: ['name' => string, 'type' => 'dir'|'file', 'children' => [...]]
     */
    private function buildTree(string $base, string $dir, int $depth, int $maxDepth): array
    {
        if ($depth >= $maxDepth) {
            return [];
        }
        $nodes = [];
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.git' || str_starts_with($item, '.')) {
                continue;
            }
            $path = $dir . '/' . $item;
            $rel  = ltrim(substr($path, strlen($base)), '/');
            if (is_dir($path)) {
                $nodes[] = [
                    'name'     => $item,
                    'rel'      => $rel,
                    'type'     => 'dir',
                    'children' => $this->buildTree($base, $path, $depth + 1, $maxDepth),
                ];
            } else {
                $nodes[] = ['name' => $item, 'rel' => $rel, 'type' => 'file', 'children' => []];
            }
        }
        // Dirs first, then files, both alphabetical
        usort($nodes, fn($a, $b) =>
            ($a['type'] === $b['type'])
                ? strcmp($a['name'], $b['name'])
                : ($a['type'] === 'dir' ? -1 : 1)
        );
        return $nodes;
    }

    public function actionCreate(): Response|string
    {
        $model = new Project();
        $model->scm_type   = Project::SCM_TYPE_GIT;
        $model->scm_branch = 'main';
        $model->status     = Project::STATUS_NEW;

        if ($model->load(\Yii::$app->request->post())) {
            $model->created_by = \Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_CREATED, 'project', $model->id, null, ['name' => $model->name]);
                if ($model->scm_type === Project::SCM_TYPE_GIT && $model->scm_url) {
                    /** @var ProjectService $svc */
                    $svc = \Yii::$app->get('projectService');
                    $svc->queueSync($model);
                    \Yii::$app->session->setFlash('success', "Project \"{$model->name}\" created. Sync queued.");
                } else {
                    \Yii::$app->session->setFlash('success', "Project \"{$model->name}\" created.");
                }
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model, 'sshCredentials' => $this->sshCredentials()]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireAccess($model);
        if ($model->load(\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_UPDATED, 'project', $model->id, null, ['name' => $model->name]);
            if ($model->scm_type === Project::SCM_TYPE_GIT && $model->scm_url) {
                /** @var ProjectService $svc */
                $svc = \Yii::$app->get('projectService');
                $svc->queueSync($model);
                \Yii::$app->session->setFlash('success', "Project \"{$model->name}\" updated. Sync queued.");
            } else {
                \Yii::$app->session->setFlash('success', "Project \"{$model->name}\" updated.");
            }
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', ['model' => $model, 'sshCredentials' => $this->sshCredentials()]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireAccess($model);

        $templateCount = $model->getJobTemplates()->count();
        if ($templateCount > 0) {
            \Yii::$app->session->setFlash('danger', "Cannot delete \"{$model->name}\": {$templateCount} job template(s) still reference this project. Remove or reassign them first.");
            return $this->redirect(['view', 'id' => $id]);
        }

        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_DELETED, 'project', $id, null, ['name' => $name]);
        \Yii::$app->session->setFlash('success', "Project \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionLint(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireAccess($model);
        /** @var LintService $lintSvc */
        $lintSvc = \Yii::$app->get('lintService');
        $lintSvc->runForProject($model);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_LINTED, 'project', $model->id, null, ['name' => $model->name]);
        \Yii::$app->session->setFlash('success', "Lint completed for \"{$model->name}\".");
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionSync(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireAccess($model);
        if ($model->scm_type !== Project::SCM_TYPE_GIT) {
            \Yii::$app->session->setFlash('warning', 'This project has no SCM configured.');
            return $this->redirect(['view', 'id' => $id]);
        }
        /** @var ProjectService $svc */
        $svc = \Yii::$app->get('projectService');
        $svc->queueSync($model);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_SYNCED, 'project', $model->id, null, ['name' => $model->name]);
        \Yii::$app->session->setFlash('success', "Sync queued for \"{$model->name}\".");
        return $this->redirect(['view', 'id' => $id]);
    }

    private function sshCredentials(): array
    {
        return Credential::find()
            ->where(['credential_type' => Credential::TYPE_SSH_KEY])
            ->orderBy('name')
            ->all();
    }

    private function findModel(int $id): Project
    {
        $model = Project::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Project #{$id} not found.");
        }
        return $model;
    }

    private function requireAccess(Project $model): void
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        if (!$checker->canView(\Yii::$app->user->id, $model->id)) {
            throw new \yii\web\ForbiddenHttpException('You do not have access to this project.');
        }
    }
}
