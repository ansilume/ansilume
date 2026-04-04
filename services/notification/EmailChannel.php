<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Sends notifications via email using the Yii2 mailer.
 *
 * Config: {"emails": ["ops@example.com", "alerts@example.com"]}
 */
class EmailChannel implements ChannelInterface
{
    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $config = $template->getParsedConfig();
        $emails = $config['emails'] ?? [];
        if (!is_array($emails) || empty($emails)) {
            return;
        }

        $recipients = array_filter($emails, 'is_string');
        if (empty($recipients)) {
            return;
        }

        $params = \Yii::$app->params;

        /** @var \yii\mail\MailerInterface $mailer */
        $mailer = \Yii::$app->mailer;
        $message = $mailer->compose()
            ->setFrom([$params['senderEmail'] => $params['senderName']])
            ->setTo($recipients)
            ->setSubject($subject)
            ->setHtmlBody(nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')))
            ->setTextBody($body);

        if (!$message->send()) {
            throw new \RuntimeException('Mailer::send() returned false');
        }

        \Yii::info(
            sprintf('EmailChannel: sent to %s — %s', implode(', ', $recipients), $subject),
            __CLASS__
        );
    }
}
