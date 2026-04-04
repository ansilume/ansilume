<?php

declare(strict_types=1);

namespace app\services\notification;

/**
 * Simple mustache-like template renderer.
 *
 * Replaces {{ variable.path }} placeholders with provided values.
 * Missing variables are replaced with an empty string.
 */
class TemplateRenderer
{
    /**
     * Render a template string with the given variables.
     *
     * @param array<string, string> $variables Flat key-value map (e.g. 'job.id' => '42')
     */
    public function render(string $template, array $variables): string
    {
        return (string)preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                return $variables[$matches[1]] ?? '';
            },
            $template
        );
    }

    /**
     * Build the standard variable map for a job notification.
     *
     * @return array<string, string>
     */
    public function buildJobVariables(\app\models\Job $job): array
    {
        $template = $job->jobTemplate;
        $project = $template !== null ? $template->project : null;
        $launcher = $job->launcher;

        $duration = '';
        if ($job->started_at && $job->finished_at) {
            $duration = (string)($job->finished_at - $job->started_at) . 's';
        }

        $baseUrl = '';
        if (\Yii::$app->has('request') && \Yii::$app->request instanceof \yii\web\Request) {
            $baseUrl = \Yii::$app->request->hostInfo;
        } elseif (!empty(\Yii::$app->params['appBaseUrl'])) {
            $baseUrl = rtrim(\Yii::$app->params['appBaseUrl'], '/');
        }
        try {
            $jobUrl = $baseUrl . \Yii::$app->urlManager->createUrl(['/job/view', 'id' => $job->id]);
        } catch (\yii\base\InvalidConfigException $e) {
            $jobUrl = $baseUrl . '/job/view?id=' . $job->id;
        }

        return [
            'job.id' => (string)$job->id,
            'job.status' => (string)$job->status,
            'job.exit_code' => (string)($job->exit_code ?? ''),
            'job.duration' => $duration,
            'job.url' => $jobUrl,
            'template.name' => (string)($template?->name ?? ''),
            'project.name' => (string)($project?->name ?? ''),
            'launched_by' => (string)($launcher?->username ?? ''),
            'timestamp' => date('Y-m-d H:i:s T'),
        ];
    }
}
