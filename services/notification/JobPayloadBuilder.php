<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\Job;

/**
 * Builds the notification payload for a job event.
 *
 * Returns a nested array that TemplateRenderer::buildVariables() flattens into
 * dot-keyed template variables like {{ job.id }}, {{ project.name }}, etc.
 */
class JobPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Job $job): array
    {
        $template = $job->jobTemplate;
        $project = $template?->project;
        $launcher = $job->launcher;

        $duration = '';
        if ($job->started_at && $job->finished_at) {
            $duration = (string)($job->finished_at - $job->started_at) . 's';
        }

        $jobUrl = self::jobUrl($job->id);

        return [
            'job' => [
                'id' => (string)$job->id,
                'status' => (string)$job->status,
                'exit_code' => (string)($job->exit_code ?? ''),
                'duration' => $duration,
                'url' => $jobUrl,
                'template_id' => (string)($template?->id ?? ''),
            ],
            'template' => [
                'id' => (string)($template?->id ?? ''),
                'name' => (string)($template?->name ?? ''),
            ],
            'project' => [
                'id' => (string)($project?->id ?? ''),
                'name' => (string)($project?->name ?? ''),
            ],
            'launched_by' => (string)($launcher?->username ?? ''),
        ];
    }

    private static function jobUrl(int $jobId): string
    {
        $base = '';
        if (\Yii::$app->has('request') && \Yii::$app->request instanceof \yii\web\Request) {
            $base = \Yii::$app->request->hostInfo;
        } elseif (!empty(\Yii::$app->params['appBaseUrl'])) {
            $base = rtrim((string)\Yii::$app->params['appBaseUrl'], '/');
        }
        try {
            return $base . \Yii::$app->urlManager->createUrl(['/job/view', 'id' => $jobId]);
        } catch (\yii\base\InvalidConfigException) {
            return $base . '/job/view?id=' . $jobId;
        }
    }
}
