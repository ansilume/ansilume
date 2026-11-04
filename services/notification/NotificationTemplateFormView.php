<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\SurveySecrets;
use app\models\NotificationTemplate;
use app\services\VisibleWorkflowTemplates;

/**
 * What the web form of a notification template offers its editor
 * (views/notification-template/form.php), beside the template itself:
 * - the workflow filter: the live workflow templates the editor may see
 *   (VisibleWorkflowTemplates::options()), those of them selected, and how
 *   many others the filter holds, of another team or deleted, which the
 *   form never names and a save keeps (NotificationWorkflowFilter);
 * - the panel of each built-in channel (PANEL_KEYS), whose script writes
 *   the keys it shows and keeps every other key of the config while the
 *   channel stays; a channel without a panel, such as one of the
 *   installation's own (ChannelRegistry), gets a JSON editor of its whole
 *   config (rawChannels), with its secrets as the placeholder, as every
 *   config of the form;
 * - the hints, as constants, so that the tests and the docs can quote
 *   them.
 */
final class NotificationTemplateFormView
{
    /**
     * The config keys the panel of each built-in channel shows and writes:
     * every key the channel reads, so that a save never drops one.
     */
    public const PANEL_KEYS = [
        NotificationTemplate::CHANNEL_EMAIL => ['emails'],
        NotificationTemplate::CHANNEL_SLACK => ['webhook_url'],
        NotificationTemplate::CHANNEL_TEAMS => ['webhook_url'],
        NotificationTemplate::CHANNEL_WEBHOOK => ['url', 'headers', 'signing_secret', 'payload'],
        NotificationTemplate::CHANNEL_TELEGRAM => ['bot_token', 'chat_id'],
        NotificationTemplate::CHANNEL_PAGERDUTY => ['routing_key'],
        NotificationTemplate::CHANNEL_MATTERMOST => ['server_url', 'bot_token', 'channel_id', 'team', 'channel', 'mention'],
    ];

    /** The hint of the subject: the variables of the events. */
    public const VARIABLES_HINT = 'Variables: {{ event }}, {{ severity }}, {{ job.id }}, {{ job.status }}, {{ job.url }}, '
        . '{{ job.inventory_name }}, {{ template.name }}, {{ project.name }}, {{ launched_by }}, {{ runner.name }}, '
        . '{{ workflow.id }}, {{ workflow.url }}, {{ workflow.reason }}, {{ step.name }}, {{ approval.url }}, '
        . '{{ approval.rule_name }}, {{ approval.required_by }}, {{ schedule.name }}, {{ timestamp }}, {{ event_id }}';

    /** The hint of the workflow filter. */
    public const SCOPE_HINT = 'Decides which runs reach the template: their workflow events, and the job and approval events '
        . 'of their steps. A selection whose workflows were all deleted reaches no run.';

    /** The workflow templates of the filter the editor may not see; sprintf(): how many. */
    public const HIDDEN_WORKFLOWS = '+ %d workflow template(s) you cannot see, which stay selected';

    /** The hint of the workflow templates of the filter. */
    public const MULTI_SELECT_HINT = 'Hold Ctrl, or Cmd on a Mac, to select more than one.';

    /** The filter of an editor who may see no workflow template. */
    public const NO_WORKFLOWS = 'There is no workflow template that you may see.';

    /** The hint of the level. */
    public const LEVEL_HINT = 'Applies to workflow events only: "Only failures, waits and the end" sends a failed step, '
        . 'a run that waits for someone, and the end of a run.';

    /** The hint of the edit form about whom it delivers as; sprintf(): the user's name. */
    public const DELIVERS_AS_HINT = 'Delivers as %s, and only what that user may see. Saving a change of the channel, of '
        . 'where it delivers, of the events, the workflows or the level makes you the user it delivers as.';

    /** The hint of the Mattermost panel. */
    public const MATTERMOST_HINT = 'The access token of a bot account with the Member role that belongs to the team and '
        . 'the channel. Saving asks Mattermost nothing: Send Test checks the token and the channel.';

    /** The hint of the mention of a Mattermost template. */
    public const MENTION_HINT = 'Optional: one user or group mention, such as @ops-team, that the replies about a failed '
        . 'step, a run that waits for someone and a failed or canceled run start with. Never @all, @channel or @here.';

    /** The hint of the signing secret of a webhook. */
    public const SIGNING_SECRET_HINT = 'Optional: with it, every request carries X-Ansilume-Signature, an HMAC-SHA256 of '
        . 'its time and body. Enter it again when you change the URL.';

    /** The payloads of a webhook, as the form offers them. */
    public const PAYLOAD_LABELS = [
        WebhookConfig::PAYLOAD_TEMPLATE => 'Template: the subject, the body and the variables',
        WebhookConfig::PAYLOAD_EVENT => 'Event: the documented event payload',
    ];

    /** The form's own refusal of headers that are no JSON object, before anything is sent: the old form dropped them. */
    public const HEADERS_FORM = 'Custom Headers must be a JSON object of header names and values, such as {"X-Custom": "value"}.';

    /** The hint of the JSON editor of a channel without a panel; sprintf(): its label. */
    public const RAW_CONFIG_HINT = 'The config of %s as a JSON object.';

    /** The keys the config of such a channel needs; sprintf(): them. */
    public const RAW_REQUIRED_KEYS = 'Required: %s.';

    /** The secrets of such a channel; sprintf(): the placeholder, them. */
    public const RAW_SECRET_KEYS = 'Secrets, shown as %s: %s.';

    /** A secret map, among the secrets of such a channel; sprintf(): its key. */
    public const RAW_SECRET_MAP = 'the values of %s';

    /** The note of the PagerDuty panel; sprintf(): the labels of the events that never raise an incident. */
    public const PAGERDUTY_NOTE = '%s never raise an incident: PagerDuty sends nothing for them.';

    /**
     * @param array<int, string> $workflowTemplates the workflow templates the editor may see, ID => name, by name
     * @param list<int> $selectedWorkflowTemplateIds those of them selected
     * @param int $hiddenWorkflowTemplates the workflow templates of the stored filter the editor may not see
     * @param array<string, ChannelDefinition> $rawChannels the channels of the installation without a panel, by name
     */
    private function __construct(
        public readonly array $workflowTemplates,
        public readonly array $selectedWorkflowTemplateIds,
        public readonly int $hiddenWorkflowTemplates,
        public readonly array $rawChannels
    ) {
    }

    /**
     * What the form of $template offers the user $editorId; $postedIds,
     * the filter a failed save posted (NotificationTemplateFormPost), is
     * the selection, else the stored filter.
     *
     * @param list<int>|null $postedIds
     */
    public static function of(NotificationTemplate $template, int $editorId, ?array $postedIds): self
    {
        $options = VisibleWorkflowTemplates::options($editorId);
        $stored = NotificationWorkflowFilter::idsOf($template);
        $offered = static fn (int $id): bool => array_key_exists($id, $options);

        return new self(
            $options,
            array_values(array_filter($postedIds ?? $stored, $offered)),
            count($stored) - count(array_filter($stored, $offered)),
            array_diff_key(ChannelRegistry::installed(), self::PANEL_KEYS)
        );
    }

    /**
     * The hint of the JSON editor of $definition's channel: its required
     * keys and its secrets.
     */
    public static function rawConfigHint(ChannelDefinition $definition): string
    {
        $hint = sprintf(self::RAW_CONFIG_HINT, $definition->label);
        if ($definition->requiredKeys !== []) {
            $hint .= ' ' . sprintf(self::RAW_REQUIRED_KEYS, implode(', ', $definition->requiredKeys));
        }
        $secrets = array_merge(
            $definition->secretKeys,
            array_map(static fn (string $map): string => sprintf(self::RAW_SECRET_MAP, $map), $definition->secretMaps)
        );
        if ($secrets !== []) {
            $hint .= ' ' . sprintf(self::RAW_SECRET_KEYS, SurveySecrets::PLACEHOLDER, implode(', ', $secrets));
        }

        return $hint;
    }

    /**
     * The note of the PagerDuty panel (PAGERDUTY_NOTE): the events that
     * never raise an incident (PagerDutyChannel::NEVER_TRIGGERED), as the
     * event checkboxes name them.
     */
    public static function pagerDutyNote(): string
    {
        $labels = NotificationTemplate::eventLabels();
        $events = array_map(static fn (string $event): string => $labels[$event] ?? $event, PagerDutyChannel::NEVER_TRIGGERED);

        return sprintf(self::PAGERDUTY_NOTE, implode(' and ', $events));
    }
}
