<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowTemplate;
use yii\data\ActiveDataProvider;

/**
 * What the page of a notification template shows its viewer (spec answers
 * 3 and 5; views/notification-template/view.php), as the API's resource
 * and its deliveries list show it:
 * - whom it delivers as (delivers_as), and whether that user is inactive:
 *   then it delivers nothing, which the page says (DELIVERS_AS_INACTIVE);
 * - the workflow templates of its filter that the viewer may see, by name,
 *   and how many others it holds, of another team or deleted, which no page
 *   names (NotificationTemplateResource);
 * - its deliveries that the viewer may see, newest first,
 *   DELIVERIES_PER_PAGE to a page of their own page parameter
 *   (VisibleDeliveries), and how many others it has;
 * - for a Mattermost template, the check it keeps (channel_state,
 *   MattermostDestination): the bot's username, the channel's ID, when, and
 *   whether the config is still the one checked; never the fingerprint.
 */
final class NotificationTemplatePage
{
    /** The deliveries on one page of the table, as on one page of the API's list. */
    public const DELIVERIES_PER_PAGE = 25;

    /** The query parameter of the table's page. */
    public const DELIVERIES_PAGE_PARAM = 'deliveries-page';

    /** The warning of a template whose user is inactive; sprintf(): the user's name (deliversAsName()). */
    public const DELIVERS_AS_INACTIVE = 'Delivers nothing: %s, the user it delivers as, is inactive. Saving the template makes whoever saves it the user it delivers as.';

    /** The name of a user the template names that no row holds; sprintf(): the ID. */
    public const UNKNOWN_USER = 'user #%d';

    /** The workflows of a template with workflow scope all. */
    public const ALL_WORKFLOWS = NotificationTemplate::WORKFLOW_SCOPE_LABELS[NotificationTemplate::WORKFLOW_SCOPE_ALL];

    /** The workflows of a template with workflow scope selected whose filter holds none. */
    public const NO_WORKFLOWS = 'None: no run reaches it until a workflow is selected.';

    /** The workflow templates of the filter the viewer may not see; sprintf(): how many. */
    public const HIDDEN_WORKFLOWS = '+ %d workflow template(s) you cannot see';

    /** The table of a template without a delivery the viewer may see. */
    public const NO_DELIVERIES = 'No deliveries yet.';

    /** The deliveries the viewer may not see; sprintf(): how many. */
    public const HIDDEN_DELIVERIES = '+ %d delivery(ies) you cannot see';

    /** The table's Finished of a delivery that waits for an attempt; sprintf(): when, as HTML (TimeHelper::relative()). */
    public const NEXT_ATTEMPT = 'Not yet: next attempt %s';

    /** The table's Finished of a delivery a worker sends; sprintf(): since when, as HTML (TimeHelper::relative()). */
    public const SENDING_SINCE = 'Not yet: sending since %s';

    /** A Mattermost template without a kept check. */
    public const CHECK_NONE = 'Not checked yet: Send Test, or the first delivery, checks the bot token and the channel.';

    /** The kept check; sprintf(): when, the bot's username, the channel's ID. */
    public const CHECK_KEPT = 'Checked %s: the bot @%s may post to channel %s.';

    /** After the kept check, when the config is not the one it checked. */
    public const CHECK_STALE = 'The server, the bot token or the channel changed since: Send Test, or the next delivery, checks them again.';

    /** The badge class of each status of a delivery. */
    public const STATUS_BADGES = [
        NotificationDelivery::STATUS_PENDING => 'text-bg-secondary',
        NotificationDelivery::STATUS_SENDING => 'text-bg-info',
        NotificationDelivery::STATUS_DELIVERED => 'text-bg-success',
        NotificationDelivery::STATUS_FAILED => 'text-bg-danger',
        NotificationDelivery::STATUS_SKIPPED => 'text-bg-warning',
        NotificationDelivery::STATUS_SUPERSEDED => 'text-bg-secondary',
    ];

    /** How the table names each kind of a delivery. */
    public const KIND_LABELS = [
        NotificationDelivery::KIND_MESSAGE => 'Message',
        NotificationDelivery::KIND_STATUS => 'Status post',
        NotificationDelivery::KIND_REPLY => 'Reply',
    ];

    /** The page of each object a delivery names (NotificationDelivery::REF_ATTRIBUTES): its label (sprintf(): the ID) and route. */
    public const REF_PAGES = [
        'workflow_job_id' => ['Workflow Job #%d', '/workflow-job/view'],
        'job_id' => ['Job #%d', '/job/view'],
        'approval_request_id' => ['Approval Request #%d', '/approval/view'],
        'project_id' => ['Project #%d', '/project/view'],
        'job_template_id' => ['Job Template #%d', '/job-template/view'],
    ];

    /**
     * @param array<int, string> $workflowTemplates the workflow templates of the filter the viewer may see, ID => name, by name
     * @param array{bot_username: string, channel_id: string, checked_at: int, current: bool}|null $check
     */
    private function __construct(
        private readonly int $deliversAsId,
        public readonly ?User $deliversAs,
        public readonly bool $deliversAsInactive,
        public readonly array $workflowTemplates,
        public readonly int $hiddenWorkflowTemplates,
        public readonly ActiveDataProvider $deliveries,
        public readonly int $hiddenDeliveries,
        public readonly ?array $check
    ) {
    }

    /**
     * What the page of $template shows the user $viewerId.
     */
    public static function of(NotificationTemplate $template, int $viewerId): self
    {
        $extras = NotificationTemplateResource::extrasOf([$template], $viewerId)[(int)$template->id];
        $all = NotificationDelivery::find()->where(['notification_template_id' => (int)$template->id]);
        $deliveries = new ActiveDataProvider([
            'query' => (clone $all)->andWhere(VisibleDeliveries::condition($viewerId))->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => self::DELIVERIES_PER_PAGE, 'pageParam' => self::DELIVERIES_PAGE_PARAM],
            // Newest first, whatever ?sort= says.
            'sort' => false,
        ]);

        return new self(
            (int)$template->delivers_as,
            User::findOne((int)$template->delivers_as),
            $extras['delivers_as_inactive'],
            self::namesOf($extras['workflow_template_ids']),
            $extras['workflow_templates_hidden'],
            $deliveries,
            (int)$all->count() - (int)$deliveries->getTotalCount(),
            self::checkOf($template)
        );
    }

    /**
     * The name of the user the template delivers as; UNKNOWN_USER for one
     * that no row holds.
     */
    public function deliversAsName(): string
    {
        return $this->deliversAs?->username ?? sprintf(self::UNKNOWN_USER, $this->deliversAsId);
    }

    /**
     * What $delivery is about: a label and the route of the page of each
     * object it names (REF_PAGES), in the order of its refs.
     *
     * @return list<array{0: string, 1: array{0: string, id: int}}>
     */
    public static function aboutOf(NotificationDelivery $delivery): array
    {
        $links = [];
        foreach ($delivery->getRefs() as $ref => $id) {
            [$label, $route] = self::REF_PAGES[$ref];
            $links[] = [sprintf($label, $id), [$route, 'id' => $id]];
        }

        return $links;
    }

    /**
     * The names of the workflow templates $ids, ID => name, by name.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function namesOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = WorkflowTemplate::find()
            ->select(['workflow_template.id', 'workflow_template.name'])
            ->where(['workflow_template.id' => $ids])
            ->orderBy(['workflow_template.name' => SORT_ASC, 'workflow_template.id' => SORT_ASC])
            ->asArray()
            ->all();
        $names = [];
        foreach ($rows as $row) {
            $names[(int)$row['id']] = (string)$row['name'];
        }

        return $names;
    }

    /**
     * The check a Mattermost template keeps, without its fingerprint, and
     * whether its config is the one checked; null for a template of another
     * channel, one that keeps none, and a state of another form.
     *
     * @return array{bot_username: string, channel_id: string, checked_at: int, current: bool}|null
     */
    private static function checkOf(NotificationTemplate $template): ?array
    {
        if ($template->channel !== NotificationTemplate::CHANNEL_MATTERMOST) {
            return null;
        }
        $kept = self::keptStateOf((string)$template->channel_state);
        if ($kept === null) {
            return null;
        }

        return [
            'bot_username' => $kept['bot_username'],
            'channel_id' => $kept['channel_id'],
            'checked_at' => $kept['checked_at'],
            'current' => self::isChecked($template, $kept['fingerprint']),
        ];
    }

    /**
     * The kept check of the server state $json (MattermostDestination::
     * STATE_KEYS) that the page reads; null for a state of another form.
     *
     * @return array{fingerprint: string, bot_username: string, channel_id: string, checked_at: int}|null
     */
    private static function keptStateOf(string $json): ?array
    {
        $state = json_decode($json, true);
        if (!is_array($state) || !is_int($state['checked_at'] ?? null)) {
            return null;
        }
        $texts = [];
        foreach (['fingerprint', 'bot_username', 'channel_id'] as $key) {
            if (!is_string($state[$key] ?? null)) {
                return null;
            }
            $texts[$key] = $state[$key];
        }

        return ['fingerprint' => $texts['fingerprint'], 'bot_username' => $texts['bot_username'], 'channel_id' => $texts['channel_id'], 'checked_at' => $state['checked_at']];
    }

    /**
     * Whether $fingerprint is that of the config $template delivers with; a
     * config the channel cannot use is no checked one.
     */
    private static function isChecked(NotificationTemplate $template, string $fingerprint): bool
    {
        try {
            return hash_equals($fingerprint, MattermostConfig::of($template)->fingerprint());
        } catch (MattermostApiException) {
            return false;
        }
    }
}
