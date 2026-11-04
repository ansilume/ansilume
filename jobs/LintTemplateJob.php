<?php

declare(strict_types=1);

namespace app\jobs;

use app\models\JobTemplate;
use app\services\LintService;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Job of the queue-worker's queue (queue) that lints the playbook of one job
 * template (LintService::runForTemplate()), queued by a save of the
 * template in the web UI or the API (LintService::queueForTemplate()):
 * ansible-lint never runs in a request.
 *
 * It carries the template's ID and when the run was requested, the mark the
 * request set (job_template.lint_queued_at): the run serves every request up
 * to then, and leaves a newer mark to the run of that request. A template
 * deleted meanwhile, which JobTemplate::find() hides, is not linted, and
 * that is no error. The run always comes, also when the mark is clear
 * already.
 */
final class LintTemplateJob extends BaseObject implements JobInterface
{
    /** The ID of the job template (job_template.id). */
    public int $templateId = 0;

    /** When the run was requested, Unix seconds: the mark the request set. */
    public int $requestedAt = 0;

    public function execute($queue): void
    {
        $template = JobTemplate::findOne($this->templateId);
        if (!$template instanceof JobTemplate) {
            return;
        }
        /** @var LintService $lint */
        $lint = \Yii::$app->get('lintService');
        $lint->runForTemplate($template, $this->requestedAt);
    }
}
