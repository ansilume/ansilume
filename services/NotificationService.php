<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use yii\base\Component;

/**
 * Sends notifications for job lifecycle events.
 * Currently supports email-on-failure.
 * Designed so transport can be swapped without touching callers.
 */
class NotificationService extends Component
{
    /**
     * Send a failure notification for a completed job.
     * Only sends if the job template has notify_on_failure=true and emails configured.
     */
    public function notifyJobFailed(Job $job): void
    {
        $template = $job->jobTemplate;
        if ($template === null) {
            return;
        }
        if (!$template->notify_on_failure) {
            return;
        }

        $recipients = $template->getNotifyEmailList();
        if (empty($recipients)) {
            return;
        }

        try {
            $this->sendFailureMail($job, $recipients);
        } catch (\Throwable $e) {
            \Yii::error(
                sprintf('NotificationService: failed to send failure mail for job #%d: %s', $job->id, $e->getMessage()),
                __CLASS__
            );
        }
    }

    /**
     * @param string[] $recipients
     */
    private function sendFailureMail(Job $job, array $recipients): void
    {
        $mailer   = \Yii::$app->mailer;
        $params   = \Yii::$app->params;
        $template = $job->jobTemplate;

        $subject = sprintf('[Ansilume] Job #%d failed — %s', $job->id, $template->name ?? 'unknown template');

        $body = $mailer->compose()
            ->setFrom([$params['senderEmail'] => $params['senderName']])
            ->setTo($recipients)
            ->setSubject($subject)
            ->setHtmlBody($this->renderBody($job))
            ->setTextBody($this->renderTextBody($job));

        if (!$body->send()) {
            throw new \RuntimeException("Mailer::send() returned false for job #{$job->id}");
        }

        \Yii::info(
            sprintf('NotificationService: failure mail sent for job #%d to %s', $job->id, implode(', ', $recipients)),
            __CLASS__
        );
    }

    private function renderBody(Job $job): string
    {
        $template   = $job->jobTemplate;
        $launcher   = $job->launcher;
        $started    = $job->started_at  ? date('Y-m-d H:i:s', $job->started_at)  : '—';
        $finished   = $job->finished_at ? date('Y-m-d H:i:s', $job->finished_at) : '—';
        $exitCode   = $job->exit_code !== null ? (string)$job->exit_code : '—';
        $baseUrl    = \Yii::$app->has('request') ? \Yii::$app->request->hostInfo : '';
        $jobUrl     = $baseUrl . \Yii::$app->urlManager->createUrl(['/job/view', 'id' => $job->id]);

        return <<<HTML
        <h2 style="color:#dc3545">Job #{$job->id} Failed</h2>
        <table cellpadding="4" style="border-collapse:collapse;font-family:monospace">
            <tr><td><strong>Template</strong></td><td>{$template->name}</td></tr>
            <tr><td><strong>Playbook</strong></td><td>{$template->playbook}</td></tr>
            <tr><td><strong>Launched by</strong></td><td>{$launcher->username}</td></tr>
            <tr><td><strong>Started</strong></td><td>{$started}</td></tr>
            <tr><td><strong>Finished</strong></td><td>{$finished}</td></tr>
            <tr><td><strong>Exit code</strong></td><td>{$exitCode}</td></tr>
        </table>
        <p><a href="{$jobUrl}">View Job in Ansilume</a></p>
        HTML;
    }

    private function renderTextBody(Job $job): string
    {
        $template = $job->jobTemplate;
        $launcher = $job->launcher;
        return sprintf(
            "Job #%d FAILED\nTemplate: %s\nPlaybook: %s\nLaunched by: %s\nExit code: %s\n",
            $job->id,
            $template->name ?? '—',
            $template->playbook ?? '—',
            $launcher->username ?? '—',
            $job->exit_code !== null ? $job->exit_code : '—'
        );
    }
}
