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
 */
class SyncProjectJob extends BaseObject implements JobInterface
{
    public int $projectId = 0;

    public function execute($queue): void
    {
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

            /** @var LintService $lintSvc */
            $lintSvc = \Yii::$app->get('lintService');
            $lintSvc->runForProject($project);
            foreach (JobTemplate::find()->where(['project_id' => $project->id])->all() as $tpl) {
                $lintSvc->runForTemplate($tpl);
            }
        } catch (\RuntimeException $e) {
            \Yii::error("SyncProjectJob: project #{$project->id} sync failed: " . $e->getMessage(), __CLASS__);
        }
    }
}
