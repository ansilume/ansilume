<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\AppLinks;
use app\components\OutboundHttpClient;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\WorkflowJob;
use app\services\runstate\Display;
use app\services\runstate\Viewer;
use app\services\runstate\WorkflowRunStates;

/**
 * The Mattermost channel (NotificationTemplate::CHANNEL_MATTERMOST): a bot
 * posts to one channel through Mattermost's REST API v4, with the server
 * URL and the bot token of the template's config (MattermostConfig).
 *
 * Every send makes its destination first (MattermostDestination): the
 * token, the channel and the bot's membership are checked on the first
 * delivery after the config changed, and on every Send Test
 * (checkDestination(), which NotificationDispatcher::sendSingle() runs
 * before send()).
 *
 * A workflow run gets one status post per template (syncStatus(),
 * notification_thread), rendered from the run's state as it is now for the
 * user the template delivers as (MattermostStatusPost), and replies in its
 * thread (reply(), MattermostPosts). Fallbacks, before an error fails the
 * delivery:
 * - an edit that Mattermost refuses with 403 is tried once more after
 *   editRetryPauseMs (a read replica may lag right after the create). Once
 *   the bot was found a member of the channel (a bot that is none would
 *   otherwise post anew on every change), an edit refused with 400 (the
 *   server's edit time limit, api.post.update_post.permissions_time_limit,
 *   which it answers only for a post that exists and that the bot may
 *   edit) or 404 makes a new status post. One refused again with 403 is
 *   looked up first, as Mattermost answers a deleted post and an edit it
 *   does not allow alike: a post it no longer has (deleted, or moved), or
 *   one that is not the bot's own in the channel (the post of the bot of a
 *   token before), makes a new status post; the bot's own fails the
 *   delivery for good (EDIT_NOT_ALLOWED: a permission scheme without Edit
 *   Posts for members), and nothing is posted anew, or every change of the
 *   run would post one;
 * - a reply whose root was deleted (400 api.post.create_post.root_id)
 *   makes a new status post, and is posted in its thread;
 * - a post on another server or channel than the config names now is never
 *   edited (its server would get this template's token): the run gets a
 *   new status post where the config points.
 * Any other event is a post of its own (postMessage(), send()).
 *
 * A create carries a pending post ID of the delivery
 * (MattermostDestination::post()), which Mattermost keeps for 30 seconds
 * after it made the post: a create whose answer may have been lost after
 * Mattermost made it (no answer, or a 5xx) is sent once more after
 * createRetryPauseMs, once per delivery and while a request is left
 * (MattermostApi), and gets that post. A delivery's retry after its
 * backoff, at least 30 seconds later, comes after Mattermost forgot the
 * ID: a post whose answer was lost twice, or that was made but not
 * recorded because the worker stopped in between, is made again by it:
 * such a duplicate is accepted.
 *
 * A delivery makes at most MattermostApi::MAX_REQUESTS requests.
 */
class MattermostChannel implements ThreadedChannelInterface, CheckedChannelInterface
{
    /** The error id of a reply whose root post is deleted. */
    public const ROOT_DELETED = 'api.post.create_post.root_id.app_error';

    /**
     * The statuses of an edit that Mattermost refused, which a new status
     * post answers: a 403 only for a post that is not the bot's own in the
     * channel, or that Mattermost no longer has.
     */
    public const EDIT_REFUSED = [400, 403, 404];

    /** How long to wait before the edit is tried again after a 403, in milliseconds. */
    public int $editRetryPauseMs = 1000;

    /** How long to wait before a create whose answer may have been lost is sent again, in milliseconds (MattermostApi). */
    public int $createRetryPauseMs = MattermostApi::CREATE_RETRY_PAUSE_MS;

    public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
    {
        $this->postMessage($template, $subject, $body, $variables, 0);
    }

    public function postMessage(NotificationTemplate $template, string $subject, string $body, array $variables, int $deliveryId): string
    {
        return $this->destination($template, false)->post('', MattermostPosts::message($template, $variables), null, $deliveryId, 'message');
    }

    public function checkDestination(NotificationTemplate $template): void
    {
        $this->destination($template, true);
    }

    public function syncStatus(NotificationTemplate $template, ?NotificationThread $thread, WorkflowJob $run, int $deliveryId): NotificationThread
    {
        $destination = $this->destination($template, false);
        $props = $this->statusOf($template, $run);
        if ($thread !== null && $destination->holds($thread) && $this->edited($destination, (string)$thread->root_post_id, $props)) {
            return $thread;
        }

        return $this->record($thread, $template, $run, $destination, $destination->post('', $props, null, $deliveryId, 'status'));
    }

    public function reply(
        NotificationTemplate $template,
        NotificationThread $thread,
        string $subject,
        string $body,
        array $variables,
        bool $attention,
        int $deliveryId
    ): string {
        $destination = $this->destination($template, false);
        $message = MattermostPosts::reply($variables, $attention ? $destination->config->mention : null, $subject);
        $moved = !$destination->holds($thread);
        if ($moved) {
            $thread = $this->newRoot($destination, $template, $thread, $deliveryId);
        }
        try {
            return $destination->post($message, [], (string)$thread->root_post_id, $deliveryId, 'reply');
        } catch (MattermostApiException $e) {
            if ($moved || $e->httpStatus !== 400 || $e->errorId !== self::ROOT_DELETED) {
                throw $e;
            }
        }
        $thread = $this->newRoot($destination, $template, $thread, $deliveryId);

        return $destination->post($message, [], (string)$thread->root_post_id, $deliveryId, 'reply');
    }

    /**
     * Waits before an edit is tried again (editRetryPauseMs).
     */
    protected function pause(): void
    {
        if ($this->editRetryPauseMs > 0) {
            usleep($this->editRetryPauseMs * 1000);
        }
    }

    /**
     * The destination of $template, checked now when $check (see
     * MattermostDestination).
     */
    private function destination(NotificationTemplate $template, bool $check): MattermostDestination
    {
        $config = MattermostConfig::of($template);
        /** @var OutboundHttpClient $http */
        $http = \Yii::$app->get('outboundHttp');

        return MattermostDestination::of($template, new MattermostApi($http, $config->serverUrl, $config->token, $this->createRetryPauseMs), $config, $check);
    }

    /**
     * Whether the status post $postId took $props: false when Mattermost
     * refused the edit, after the retry of a 403, and the bot is a member
     * of the channel, so that the post is made anew; a 403 for the bot's
     * own post, which Mattermost still has, fails for good instead
     * (EDIT_NOT_ALLOWED; see the class).
     *
     * @param array<string, mixed> $props
     */
    private function edited(MattermostDestination $destination, string $postId, array $props): bool
    {
        $refusal = $this->editRefusal($destination, $postId, $props);
        if ($refusal?->httpStatus === 403) {
            $this->pause();
            $refusal = $this->editRefusal($destination, $postId, $props);
        }
        if ($refusal === null) {
            return true;
        }
        $destination->requireMembership();
        if ($refusal->httpStatus === 403 && $destination->isOwnPost($postId)) {
            throw $refusal->because(MattermostApiException::EDIT_NOT_ALLOWED, $postId);
        }

        return false;
    }

    /**
     * Edits post $postId: null when it took $props, the refusal of a status
     * of EDIT_REFUSED otherwise; any other error is thrown.
     *
     * @param array<string, mixed> $props
     */
    private function editRefusal(MattermostDestination $destination, string $postId, array $props): ?MattermostApiException
    {
        try {
            $destination->api->patchPost($postId, '', $props);
        } catch (MattermostApiException $e) {
            if (!in_array($e->httpStatus, self::EDIT_REFUSED, true)) {
                throw $e;
            }

            return $e;
        }

        return null;
    }

    /**
     * A new status post of the run of $thread, recorded as its root.
     */
    private function newRoot(MattermostDestination $destination, NotificationTemplate $template, NotificationThread $thread, int $deliveryId): NotificationThread
    {
        /** @var WorkflowJob $run */
        $run = $thread->workflowJob;
        $postId = $destination->post('', $this->statusOf($template, $run), null, $deliveryId, 'root');

        return $this->record($thread, $template, $run, $destination, $postId);
    }

    /**
     * The props of the status post of $run, rendered for the user $template
     * delivers as.
     *
     * @return array{attachments: list<array<string, mixed>>}
     */
    private function statusOf(NotificationTemplate $template, WorkflowJob $run): array
    {
        /** @var WorkflowRunStates $states */
        $states = \Yii::$app->get('workflowRunStates');
        $state = $states->forRun($run, Viewer::of((int)$template->delivers_as));
        $approval = $state->waitingFor === Display::WAITING_FOR_APPROVAL ? $state->current?->approval : null;

        return MattermostStatusPost::props(
            $state,
            (string)($run->workflowTemplate?->name ?? ''),
            AppLinks::workflowRun((int)$run->id),
            array_flip(RunContext::stepIdsOf((int)$run->workflow_template_id)),
            $approval !== null && $approval->linkable ? AppLinks::approval($approval->requestId) : null,
            $run->started_at === null ? null : (int)$run->started_at,
            $run->finished_at === null ? null : (int)$run->finished_at
        );
    }

    /**
     * $thread (a new one for none) as the status post $postId of $run made
     * by $destination, saved.
     */
    private function record(?NotificationThread $thread, NotificationTemplate $template, WorkflowJob $run, MattermostDestination $destination, string $postId): NotificationThread
    {
        $thread ??= new NotificationThread();
        $thread->notification_template_id = (int)$template->id;
        $thread->workflow_job_id = (int)$run->id;
        $thread->root_post_id = $postId;
        $thread->channel_id = $destination->channelId;
        $thread->server = $destination->config->serverUrl;
        if (!$thread->save(false)) {
            throw new \RuntimeException(ThreadedSend::THREAD_NOT_SAVED);
        }

        return $thread;
    }
}
