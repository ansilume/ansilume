<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * The replies in a run's thread and the posts of their own that the
 * Mattermost channel sends, from the variables of their delivery, as the
 * event left them:
 * - a reply is short: the step or the object it is about and what
 *   happened (REPLIES; for a wait, a failed step and a failed run what the
 *   run page says, workflow.reason), and one link: to the approval request
 *   a step waits for, to the job, or to the run. The template's mention
 *   goes first when the reply needs attention;
 * - a post of its own (an event outside a run's thread) is one attachment
 *   in the colour of the event's severity, with the template's subject as
 *   its title and its body as its text, linked to the run, the approval
 *   request or the job of the event.
 * Every value of a variable is escaped (MattermostText), the template's own
 * text is Markdown as written, and a text is cut to MattermostText::MAX_LENGTH.
 */
final class MattermostPosts
{
    /** The colour of a post of its own by the event's severity (NotificationTemplate::eventSeverity()), as #RRGGBB. */
    public const SEVERITY_COLORS = ['critical' => '#D24B4E', 'error' => '#E8590C', 'warning' => '#FFBC1F', 'info' => '#1C58D9'];

    /**
     * The reply to each event of a run; sprintf(): the step
     * (MattermostStatusPost::stepLabel()), the job's ID or the approval
     * request's ID, escaped. An event not listed replies with the
     * template's subject.
     */
    public const REPLIES = [
        NotificationTemplate::EVENT_WORKFLOW_LAUNCHED => 'The run was launched.',
        NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED => 'Step %s started.',
        NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED => 'Step %s succeeded.',
        NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED => 'Step %s failed.',
        NotificationTemplate::EVENT_WORKFLOW_WAITING => 'Step %s waits.',
        NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED => 'The run succeeded.',
        NotificationTemplate::EVENT_WORKFLOW_FAILED => 'The run failed.',
        NotificationTemplate::EVENT_WORKFLOW_CANCELED => 'The run was canceled.',
        NotificationTemplate::EVENT_JOB_LAUNCHED => 'Job #%s was launched.',
        NotificationTemplate::EVENT_JOB_SUCCEEDED => 'Job #%s succeeded.',
        NotificationTemplate::EVENT_JOB_FAILED => 'Job #%s failed.',
        NotificationTemplate::EVENT_JOB_CANCELED => 'Job #%s was canceled.',
        NotificationTemplate::EVENT_APPROVAL_REQUESTED => 'Approval Request #%s waits for a decision.',
        NotificationTemplate::EVENT_APPROVAL_APPROVED => 'Approval Request #%s was approved.',
        NotificationTemplate::EVENT_APPROVAL_REJECTED => 'Approval Request #%s was rejected.',
    ];

    /** The events whose reply says why (workflow.reason): the wait says only that. */
    public const WITH_REASON = [NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED, NotificationTemplate::EVENT_WORKFLOW_FAILED];

    /** The labels of the links. */
    public const OPEN_RUN = 'Open the run';
    public const OPEN_JOB = 'Open the job';
    public const OPEN_APPROVAL = 'Open the approval request';

    /** The longest title of a post of its own. */
    public const TITLE_LENGTH = 300;

    /**
     * The props of a post of its own of $template with $variables.
     *
     * @param array<string, string> $variables
     * @return array{attachments: list<array<string, string>>}
     */
    public static function message(NotificationTemplate $template, array $variables): array
    {
        $renderer = new TemplateRenderer();
        $escaped = array_map([MattermostText::class, 'escape'], $variables);
        $url = self::firstAddress($variables, ['workflow.url', 'approval.url', 'job.url']);
        $attachment = [
            'fallback' => mb_substr(MattermostText::plain($renderer->render((string)$template->subject_template, $variables)), 0, self::TITLE_LENGTH),
            'color' => self::SEVERITY_COLORS[$variables['severity'] ?? ''] ?? self::SEVERITY_COLORS['info'],
            'title' => rtrim(mb_substr($renderer->render((string)$template->subject_template, $escaped), 0, self::TITLE_LENGTH), '\\'),
        ];
        if ($url !== '') {
            $attachment['title_link'] = $url;
        }
        $attachment['text'] = MattermostText::truncate($renderer->render((string)$template->body_template, $escaped), $url);

        return ['attachments' => [$attachment]];
    }

    /**
     * The message of the reply to the event of $variables: $mention first
     * when given, then what happened, then its link; the template's
     * $subject for an event without a reply of its own.
     *
     * @param array<string, string> $variables
     */
    public static function reply(array $variables, ?string $mention, string $subject): string
    {
        $event = $variables['event'] ?? '';
        $text = self::replyText($event, $variables, $subject);
        [$label, $url] = self::linkOf($event, $variables);
        $link = MattermostText::isAddress($url) ? ' ' . MattermostText::link($label, $url) : '';

        return MattermostText::truncate(($mention === null ? '' : $mention . ' ') . $text . $link, $variables['workflow.url'] ?? '');
    }

    /**
     * @param array<string, string> $variables
     */
    private static function replyText(string $event, array $variables, string $subject): string
    {
        $format = self::REPLIES[$event] ?? null;
        if ($format === null) {
            return MattermostText::escape($subject);
        }
        $reason = trim($variables['workflow.reason'] ?? '');
        if ($event === NotificationTemplate::EVENT_WORKFLOW_WAITING && $reason !== '') {
            return MattermostText::escape($reason);
        }
        $text = sprintf($format, self::objectOf($event, $variables));

        return in_array($event, self::WITH_REASON, true) && $reason !== '' ? $text . ' ' . MattermostText::escape($reason) : $text;
    }

    /**
     * What the reply to $event names: the step of a step event, the job of
     * a job event, the request of an approval event; '' for an event of
     * the whole run.
     *
     * @param array<string, string> $variables
     */
    private static function objectOf(string $event, array $variables): string
    {
        return match (strstr($event, '.', true)) {
            'job' => MattermostText::escape($variables['job.id'] ?? ''),
            'approval' => MattermostText::escape($variables['approval.id'] ?? ''),
            default => MattermostStatusPost::stepLabel($variables['step.position'] ?? '', $variables['step.name'] ?? ''),
        };
    }

    /**
     * The link of the reply to $event: its label and address.
     *
     * @param array<string, string> $variables
     * @return array{0: string, 1: string}
     */
    private static function linkOf(string $event, array $variables): array
    {
        $approval = $variables['approval.url'] ?? '';
        $object = strstr($event, '.', true);
        if ($object === 'approval' || ($event === NotificationTemplate::EVENT_WORKFLOW_WAITING && $approval !== '')) {
            return [self::OPEN_APPROVAL, $approval];
        }

        return $object === 'job' ? [self::OPEN_JOB, $variables['job.url'] ?? ''] : [self::OPEN_RUN, $variables['workflow.url'] ?? ''];
    }

    /**
     * The first of $keys whose variable is an http or https address; ''
     * for none.
     *
     * @param array<string, string> $variables
     * @param list<string> $keys
     */
    private static function firstAddress(array $variables, array $keys): string
    {
        foreach ($keys as $key) {
            if (MattermostText::isAddress($variables[$key] ?? '')) {
                return $variables[$key] ?? '';
            }
        }

        return '';
    }
}
