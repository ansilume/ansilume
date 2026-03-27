<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use yii\base\Component;

/**
 * Sends notifications for job lifecycle events.
 *
 * Supports failure and success notifications, each configurable per template.
 * Uses Yii2 view-based email composition with HTML + plain text templates.
 */
class NotificationService extends Component
{
    /**
     * Send a failure notification for a completed job.
     * Only sends if the template has notify_on_failure=true and emails configured.
     */
    public function notifyJobFailed(Job $job): void
    {
        $template = $job->jobTemplate;
        if ($template === null || !$template->notify_on_failure) {
            return;
        }

        $recipients = $template->getNotifyEmailList();
        if (empty($recipients)) {
            return;
        }

        try {
            $this->sendJobMail(
                $job,
                $recipients,
                sprintf('[Ansilume] Job #%d failed — %s', $job->id, $template->name ?? 'unknown template'),
                'job-failed',
            );
        } catch (\Throwable $e) {
            \Yii::error(
                sprintf('NotificationService: failed to send failure mail for job #%d: %s', $job->id, $e->getMessage()),
                __CLASS__
            );
        }
    }

    /**
     * Send a success notification for a completed job.
     * Only sends if the template has notify_on_success=true and emails configured.
     */
    public function notifyJobSucceeded(Job $job): void
    {
        $template = $job->jobTemplate;
        if ($template === null || !$template->notify_on_success) {
            return;
        }

        $recipients = $template->getNotifyEmailList();
        if (empty($recipients)) {
            return;
        }

        try {
            $this->sendJobMail(
                $job,
                $recipients,
                sprintf('[Ansilume] Job #%d succeeded — %s', $job->id, $template->name ?? 'unknown template'),
                'job-succeeded',
            );
        } catch (\Throwable $e) {
            \Yii::error(
                sprintf('NotificationService: failed to send success mail for job #%d: %s', $job->id, $e->getMessage()),
                __CLASS__
            );
        }
    }

    /**
     * @param string[] $recipients
     */
    protected function sendJobMail(Job $job, array $recipients, string $subject, string $template): void
    {
        $params = \Yii::$app->params;
        $jobUrl = $this->buildJobUrl($job);

        /** @var \yii\mail\MailerInterface $mailer */
        $mailer = \Yii::$app->mailer;
        $message = $mailer->compose(
            ['html' => $template . '-html', 'text' => $template . '-text'],
            ['job' => $job, 'jobUrl' => $jobUrl]
        )
            ->setFrom([$params['senderEmail'] => $params['senderName']])
            ->setTo($recipients)
            ->setSubject($subject);

        if (!$message->send()) {
            throw new \RuntimeException("Mailer::send() returned false for job #{$job->id}");
        }

        \Yii::info(
            sprintf('NotificationService: %s mail sent for job #%d to %s', $template, $job->id, implode(', ', $recipients)),
            __CLASS__
        );
    }

    /**
     * Build the full URL to view a job in the UI.
     */
    protected function buildJobUrl(Job $job): string
    {
        $baseUrl = '';
        if (\Yii::$app->has('request') && \Yii::$app->request instanceof \yii\web\Request) {
            $baseUrl = \Yii::$app->request->hostInfo;
        } elseif (!empty(\Yii::$app->params['appBaseUrl'])) {
            $baseUrl = rtrim(\Yii::$app->params['appBaseUrl'], '/');
        }
        return $baseUrl . \Yii::$app->urlManager->createUrl(['/job/view', 'id' => $job->id]);
    }
}
