<?php

declare(strict_types=1);

namespace app\jobs;

use app\models\Project;
use app\services\LintService;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Job of the queue-worker's queue (queue) that lints a whole project
 * (LintService::runForProject()), queued by Run Lint on the project's page
 * or POST /api/v1/projects/{id}/lint (LintService::queueForProject()):
 * ansible-lint never runs in a request.
 *
 * It carries the project's ID and when the run was requested, the mark the
 * request set (project.lint_queued_at): the run serves every request up to
 * then, and leaves a newer mark to the run of that request. A project
 * deleted meanwhile is not linted, and that is no error. The run always
 * comes, also when the mark is clear already.
 */
final class LintProjectJob extends BaseObject implements JobInterface
{
    /** The ID of the project (project.id). */
    public int $projectId = 0;

    /** When the run was requested, Unix seconds: the mark the request set. */
    public int $requestedAt = 0;

    public function execute($queue): void
    {
        $project = Project::findOne($this->projectId);
        if (!$project instanceof Project) {
            return;
        }
        /** @var LintService $lint */
        $lint = \Yii::$app->get('lintService');
        $lint->runForProject($project, $this->requestedAt);
    }
}
