<?php

declare(strict_types=1);

namespace app\jobs;

use app\models\JobTemplate;
use app\models\Project;
use app\services\LintService;
use app\services\ProjectService;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Queue job that syncs a project's SCM repository.
 *
 * Two-phase shape: the SCM sync is the load-bearing step (its outcome
 * decides the project's status), the lint phase is opportunistic — it
 * runs *after* a successful sync to refresh the lint badge. A failure in
 * the lint phase must never push the project back into SYNCING/ERROR or
 * mask a successful sync.
 */
class SyncProjectJob extends BaseObject implements JobInterface
{
    public int $projectId = 0;

    public function execute($queue): void
    {
        /** @var Project|null $project */
        $project = Project::findOne($this->projectId);

        if ($project === null) {
            \Yii::error("SyncProjectJob: project #{$this->projectId} not found.", __CLASS__);
            return;
        }

        \Yii::info("SyncProjectJob: syncing project #{$project->id} ({$project->name})", __CLASS__);

        try {
            /** @var ProjectService $svc */
            $svc = \Yii::$app->get('projectService');
            $svc->sync($project);
            \Yii::info("SyncProjectJob: project #{$project->id} synced successfully.", __CLASS__);
        } catch (\RuntimeException $e) {
            // ProjectService::sync already wrote STATUS_ERROR + last_sync_error
            // before re-throwing. Just log and bail — there's no point in
            // running lint against a tree we couldn't even update.
            \Yii::error("SyncProjectJob: project #{$project->id} sync failed: " . $e->getMessage(), __CLASS__);
            return;
        }

        // Opportunistic lint phase. Anything thrown here is a lint-side
        // problem (tool missing, permission glitch, ansible-lint crash) —
        // the sync itself is already committed and should stay as SYNCED.
        try {
            /** @var LintService $lintSvc */
            $lintSvc = \Yii::$app->get('lintService');
            $lintSvc->runForProject($project);
            foreach (JobTemplate::find()->where(['project_id' => $project->id])->all() as $tpl) {
                $lintSvc->runForTemplate($tpl);
            }
        } catch (\Throwable $e) {
            \Yii::warning(
                "SyncProjectJob: lint phase for project #{$project->id} failed (sync still committed): "
                    . $e->getMessage(),
                __CLASS__,
            );
        }
    }
}
