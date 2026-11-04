<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobCancellationService;
use app\services\NotificationDispatcher;
use app\services\notification\MattermostApi;
use app\services\notification\MattermostApiException;
use app\services\notification\MattermostChannel;
use app\services\notification\MattermostText;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationOutbox;
use app\services\notification\RunThread;
use app\services\WorkflowCancellationService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\WorkflowEngineFixtures;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\services\notification\MattermostMentions;

/**
 * The Mattermost channel (MattermostChannel) as the notification worker
 * sends through it, against a fake of Mattermost's API (FakeMattermost):
 * - one status post per template and run, created by the run's first event
 *   the template subscribes to and its level lets through, and edited by
 *   every later transition, rendered from the run's state for the user the
 *   template delivers as; older status deliveries are superseded;
 * - the run's events as replies in the post's thread, as the level says;
 *   those that need attention start with the template's mention;
 * - other events as posts of their own;
 * - the fallbacks: a status post that was deleted, or may no longer be
 *   edited, is posted again; a reply to a deleted root makes a new root;
 * - errors that name their causes, a 429 that waits its Retry-After;
 * - Send Test refuses a personal token and needs the bot in the channel;
 * - no name mentions anyone, breaks the layout or makes a post too long,
 *   and a stored token never goes to another server.
 */
class MattermostChannelTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    private const BASE = 'https://ansilume.example.com';

    private const CONFIG = ['server_url' => FakeMattermost::SERVER, 'bot_token' => FakeMattermost::BOT_TOKEN, 'channel_id' => FakeMattermost::CHANNEL_ID];

    private const WORKFLOW_EVENTS = 'workflow.launched,workflow.succeeded,workflow.failed,workflow.canceled,'
        . 'workflow.step_started,workflow.step_succeeded,workflow.step_failed,workflow.waiting';

    private RecordingNotificationQueue $queue;

    private FakeMattermost $mattermost;

    private User $admin;

    private User $launcher;

    private bool $hadBase = false;

    private mixed $base = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new RecordingNotificationQueue();
        $this->swapComponent('notificationQueue', $this->queue);
        $this->mattermost = new FakeMattermost();
        $this->swapComponent('outboundHttp', $this->mattermost);
        $dispatcher = new NotificationDispatcher();
        $channel = $dispatcher->channel(NotificationTemplate::CHANNEL_MATTERMOST);
        $this->assertInstanceOf(MattermostChannel::class, $channel, 'the dispatcher sends Mattermost templates through the channel');
        $channel->editRetryPauseMs = 0;
        $channel->createRetryPauseMs = 0;
        $this->swapComponent('notificationDispatcher', $dispatcher);
        $this->swapComponent('notificationDeliveryService', ['class' => NotificationDeliveryService::class]);
        $this->hadBase = array_key_exists('appBaseUrl', \Yii::$app->params);
        $this->base = \Yii::$app->params['appBaseUrl'] ?? null;
        \Yii::$app->params['appBaseUrl'] = self::BASE;
        $this->admin = $this->createUserWithRole('mm-admin', 'admin');
        $this->launcher = $this->operator('mm-launcher');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        if ($this->hadBase) {
            \Yii::$app->params['appBaseUrl'] = $this->base;
        } else {
            unset(\Yii::$app->params['appBaseUrl']);
        }
        parent::tearDown();
    }

    // -- The status post and its thread --------------------------------------------------

    /**
     * The run's first event creates its status post, once; every later
     * event edits it, so the channel gets one post per run with the
     * details in its thread. A status that a later one replaces before it
     * is sent is superseded. The post shows the run as it is when it is
     * sent: its name and state, each step with its position, what it waits
     * for, and the link to the run.
     */
    public function testTheFirstEventCreatesTheStatusPostAndLaterOnesEditIt(): void
    {
        $template = $this->mattermostTemplate($this->admin, self::WORKFLOW_EVENTS);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $hold = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $deploy = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->launcher->id)->id);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $this->queue->run();

        $roots = $this->rootPosts();
        $this->assertCount(1, $roots, 'one status post for the run');
        $root = $roots[0];
        $thread = $this->threadOf($template, $run);
        $this->assertSame([$root['id'], FakeMattermost::CHANNEL_ID, FakeMattermost::SERVER], [$thread->root_post_id, $thread->channel_id, $thread->server]);
        $this->assertSame(
            ['Step 1 "step\\-0" started. [Open the run](' . self::BASE . '/workflow-job/view?id=' . $run->id . ')', 'Paused at step "step\\-0" until someone resumes the run\\. [Open the run](' . self::BASE . '/workflow-job/view?id=' . $run->id . ')'],
            array_column($this->repliesTo($root['id']), 'message')
        );
        $this->assertSame(1, $this->post($root['id'])['edits'], 'the waiting step edited the post that the launch created');
        $attachment = $this->attachmentOf($root['id']);
        $this->assertSame('Workflow Job #' . $run->id . ': ' . MattermostText::escape((string)$workflow->name), $attachment['title']);
        $this->assertSame(self::BASE . '/workflow-job/view?id=' . $run->id, $attachment['title_link']);
        $this->assertSame('#FFBC1F', $attachment['color']);
        $this->assertStringContainsString('Paused at step "step\\-0" until someone resumes the run\\.', $attachment['text']);
        $this->assertStringContainsString('Step 1 "step\\-0": Paused', $attachment['text']);
        $this->assertSame([['title' => 'Status', 'value' => 'Paused', 'short' => true], ['title' => 'Steps', 'value' => '0 of 2 done', 'short' => true]], $attachment['fields']);
        $this->assertSame(
            [NotificationDelivery::STATUS_DELIVERED, NotificationDelivery::STATUS_SUPERSEDED, NotificationDelivery::STATUS_DELIVERED],
            array_column($this->deliveriesOf($template, NotificationDelivery::KIND_STATUS), 'status'),
            'the status of the started step was replaced by the waiting one before it was sent'
        );
        $this->assertSame(
            [$root['id'], null, $root['id']],
            array_map(static fn (NotificationDelivery $d): ?string => $d->remote_id, $this->deliveriesOf($template, NotificationDelivery::KIND_STATUS)),
            'a status delivery names the post it made or edited'
        );

        $this->engine()->resume($run, (int)$this->launcher->id);
        $this->queue->run();

        $this->assertCount(1, $this->rootPosts(), 'still one status post');
        $this->assertSame(
            ['Step 1 "step\\-0" started.', 'Paused at step "step\\-0" until someone resumes the run\\.', 'Step 1 "step\\-0" succeeded.', 'Step 2 "step\\-1" started.'],
            array_map(static fn (array $post): string => explode(' [', $post['message'])[0], $this->repliesTo($root['id']))
        );
        $text = $this->attachmentOf($root['id'])['text'];
        $this->assertStringContainsString('Step 1 "step\\-0": Succeeded', $text);
        $this->assertStringContainsString('Step 2 "step\\-1": Queued', $text);
        $this->assertSame('#6C757D', $this->attachmentOf($root['id'])['color']);
        $this->assertSame([], $this->failedDeliveries());
        $this->assertNotNull($this->executionOfStep($run, $deploy));
        $this->assertNotNull($this->executionOfStep($run, $hold));
    }

    /**
     * The level decides which events get a reply, and whether a post is
     * made, never whether a post stays current: level all replies to every
     * step event, level essential only to a failed step, a wait and the end,
     * and makes its post at the first of them; a template subscribed only
     * to the run's failure has no post until the run fails, and then one
     * (spec answer 1).
     */
    public function testRepliesFollowTheLevel(): void
    {
        $all = $this->mattermostTemplate($this->admin, self::WORKFLOW_EVENTS);
        $essential = $this->mattermostTemplate($this->admin, self::WORKFLOW_EVENTS, [], NotificationTemplate::LEVEL_ESSENTIAL);
        $failures = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_FAILED);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $deploy = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->launcher->id)->id);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $this->engine()->resume($run, (int)$this->launcher->id);
        $this->finishJob($this->jobOfStep($run, $deploy), \app\models\Job::STATUS_FAILED);
        $this->queue->run();

        $this->assertSame(
            ['workflow.step_started', 'workflow.waiting', 'workflow.step_succeeded', 'workflow.step_started', 'workflow.step_failed', 'workflow.failed'],
            array_column($this->deliveriesOf($all, NotificationDelivery::KIND_REPLY), 'event')
        );
        $this->assertSame(
            ['workflow.waiting', 'workflow.step_failed', 'workflow.failed'],
            array_column($this->deliveriesOf($essential, NotificationDelivery::KIND_REPLY), 'event')
        );
        $this->assertSame(['workflow.failed'], array_column($this->deliveriesOf($failures, NotificationDelivery::KIND_REPLY), 'event'));
        $this->assertSame(['workflow.failed'], array_column($this->deliveriesOf($failures, NotificationDelivery::KIND_STATUS), 'event'), 'no post until the failure');
        $this->assertSame(
            ['workflow.waiting', 'workflow.step_succeeded', 'job.launched', 'workflow.step_started', 'workflow.step_failed', 'workflow.failed'],
            array_column($this->deliveriesOf($essential, NotificationDelivery::KIND_STATUS), 'event'),
            'no post before the first event the level lets through; then every transition, a job of a step included, keeps it current'
        );
        foreach ([$all, $essential, $failures] as $template) {
            $thread = $this->threadOf($template, $run);
            $this->assertStringContainsString('**Result:** Failed', $this->attachmentOf($thread->root_post_id)['text'], 'every post shows the end');
            $this->assertSame('#D24B4E', $this->attachmentOf($thread->root_post_id)['color']);
        }
        $this->assertCount(3, $this->rootPosts());
        $this->assertSame([], $this->failedDeliveries());
    }

    /**
     * Edits never notify and replies reach only the thread's followers, so
     * the replies that need attention (a wait, a failed step, the run's
     * failure or cancel: RunThread::ATTENTION_EVENTS) start with the
     * template's mention; the others do not.
     */
    public function testAttentionRepliesStartWithTheMention(): void
    {
        $template = $this->mattermostTemplate($this->admin, self::WORKFLOW_EVENTS, ['mention' => '@ops-team']);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $this->cancellation()->cancelRun($run, (int)$this->launcher->id, JobCancellationService::REASON_OPERATOR);
        $this->queue->run();

        $replies = array_column($this->repliesTo($this->threadOf($template, $run)->root_post_id), 'message');
        $this->assertCount(3, $replies);
        $this->assertStringStartsWith('Step 1 "step\\-0" started.', $replies[0]);
        $this->assertStringStartsWith('@ops-team Paused at step "step\\-0"', $replies[1]);
        $this->assertStringStartsWith('@ops-team The run was canceled.', $replies[2]);
        $this->assertSame([['ops-team']], array_map(static fn (string $m): array => MattermostMentions::in($m), [$replies[1]]));
        $this->assertSame(
            [NotificationTemplate::EVENT_WORKFLOW_WAITING, NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED, NotificationTemplate::EVENT_WORKFLOW_FAILED, NotificationTemplate::EVENT_WORKFLOW_CANCELED],
            RunThread::ATTENTION_EVENTS
        );
    }

    // -- The fallbacks ----------------------------------------------------------------------

    /**
     * A status post that was deleted answers its edit with 403: the edit is
     * tried once more (Mattermost's read replicas may lag right after a
     * create), then the bot's membership is checked and the post looked up,
     * as Mattermost answers an edit it does not allow with the same 403;
     * Mattermost no longer has it (404), so the post is made anew and
     * recorded; later edits go to the new post. An edit after the server's
     * edit time limit (400 api.post.update_post.permissions_time_limit, an
     * answer only for a post that exists and that the bot may edit) is not
     * tried again, and posted anew without a lookup.
     */
    public function testADeletedStatusPostIsPostedAgain(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $first = $this->threadOf($template, $run)->root_post_id;
        $this->mattermost->deletePost($first);
        $this->mattermost->requests = [];

        $this->refresh($run);

        $second = $this->threadOf($template, $run)->root_post_id;
        $this->assertNotSame($first, $second);
        $this->assertSame(
            [
                'PUT /api/v4/posts/' . $first . '/patch',
                'PUT /api/v4/posts/' . $first . '/patch',
                'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID . '/members/me',
                'GET /api/v4/posts/' . $first,
                'POST /api/v4/posts',
            ],
            $this->requestLines()
        );
        $this->assertSame(['', ''], [$this->post($second)['root_id'], $this->post($second)['message']]);

        $this->mattermost->requests = [];
        $this->mattermost->script = [['method' => 'PUT', 'path' => '/api/v4/posts/' . $second . '/patch', 'status' => 400, 'body' => '{"id":"api.post.update_post.permissions_time_limit.app_error"}']];
        $this->refresh($run);

        $third = $this->threadOf($template, $run)->root_post_id;
        $this->assertNotSame($second, $third);
        $this->assertSame(['PUT /api/v4/posts/' . $second . '/patch', 'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID . '/members/me', 'POST /api/v4/posts'], $this->requestLines());
        $this->assertSame([], $this->failedDeliveries());
    }

    /**
     * The retry of a refused edit is for a replica that lagged: when it
     * succeeds, the post is kept.
     */
    public function testAnEditThatTheReplicaRefusedOnceIsKept(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $post = $this->threadOf($template, $run)->root_post_id;
        $this->mattermost->requests = [];
        $this->mattermost->script = [['method' => 'PUT', 'path' => '/api/v4/posts/' . $post . '/patch', 'status' => 403, 'body' => '{"id":"' . FakeMattermost::PERMISSION_ERROR . '"}']];
        $channel = $this->dispatcher()->channel(NotificationTemplate::CHANNEL_MATTERMOST);
        $this->assertInstanceOf(MattermostChannel::class, $channel);
        $channel->editRetryPauseMs = 20;
        $started = microtime(true);

        $this->refresh($run);

        $this->assertGreaterThanOrEqual(0.02, microtime(true) - $started, 'the retry waits its pause');
        $this->assertSame($post, $this->threadOf($template, $run)->root_post_id);
        $this->assertSame(['PUT /api/v4/posts/' . $post . '/patch', 'PUT /api/v4/posts/' . $post . '/patch'], $this->requestLines());
        $this->assertCount(1, $this->rootPosts());
    }

    /**
     * Regression (anti-goal: the channel floods): a bot that was removed
     * from the channel may still create posts where it holds post:channels,
     * but no longer edit them. Its refused edit is not answered with a new
     * post on every change: the delivery fails, saying why.
     */
    public function testABotRemovedFromTheChannelPostsNoNewStatusOnEveryChange(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $this->mattermost->removeBot();
        $this->mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::CHANNEL_ID]['open'] = true;

        $this->refresh($run);
        $this->refresh($run);

        $this->assertCount(1, $this->rootPosts(), 'no new post');
        $failed = $this->failedDeliveries();
        $this->assertCount(2, $failed);
        foreach ($failed as $delivery) {
            $this->assertStringStartsWith('The bot is not a member of the Mattermost channel ' . FakeMattermost::CHANNEL_ID . ' (HTTP 404, error app.channel.get_member.missing.app_error', (string)$delivery->last_error);
            $this->assertSame(1, (int)$delivery->attempts, 'for good, at once');
        }
        $this->assertSame([], $this->mattermost->requestsTo('GET', '/api/v4/posts/'), 'the membership decides before any lookup');
    }

    /**
     * Regression (anti-goal: the channel floods): a Mattermost whose
     * permission scheme takes Edit Posts (own posts) from members refuses
     * every edit of the bot's posts with 403 api.context.permissions.app_error,
     * the answer it also gives for a deleted post; the bot is a member, so
     * every status delivery (each step event, wait and refresh) made a new
     * status post: five refreshes, five more posts. Now the post is looked
     * up after the refused retry: it exists as the bot's own, so the
     * delivery fails for good, saying what to allow, and nothing is posted
     * anew; the run keeps its one status post.
     */
    public function testRegressionABotThatMayNotEditItsPostsPostsNoNewStatusOnEveryChange(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $post = $this->threadOf($template, $run)->root_post_id;
        $this->mattermost->ownEditsAllowed = false;
        $this->mattermost->requests = [];

        for ($i = 0; $i < 5; $i++) {
            $this->refresh($run);
        }

        $this->assertCount(1, $this->rootPosts(), 'no new post');
        $this->assertSame($post, $this->threadOf($template, $run)->root_post_id);
        $perDelivery = [
            'PUT /api/v4/posts/' . $post . '/patch',
            'PUT /api/v4/posts/' . $post . '/patch',
            'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID . '/members/me',
            'GET /api/v4/posts/' . $post,
        ];
        $this->assertSame(array_merge(...array_fill(0, 5, $perDelivery)), $this->requestLines());
        $failed = $this->failedDeliveries();
        $this->assertCount(5, $failed);
        $expected = sprintf(MattermostApiException::EDIT_NOT_ALLOWED, $post, 'HTTP 403, error ' . FakeMattermost::PERMISSION_ERROR . ', request ID %REQUEST%');
        foreach ($failed as $delivery) {
            $this->assertMatchesRegularExpression('/^' . str_replace('%REQUEST%', 'req\d+', preg_quote($expected, '/')) . '$/', (string)$delivery->last_error);
            $this->assertSame([NotificationDelivery::KIND_STATUS, 1], [$delivery->kind, (int)$delivery->attempts], 'for good, at once');
        }
        $this->assertStringContainsString('allow members to edit their own posts', MattermostApiException::EDIT_NOT_ALLOWED);
    }

    /**
     * The bot's own post counts as the status post only where the run's
     * thread says it is: one that the lookup finds in another channel
     * (Mattermost moved it there) is left, and the run gets a new status
     * post in its channel, as for a post on another server or channel.
     */
    public function testAStatusPostTheLookupFindsInAnotherChannelIsPostedAnew(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $old = $this->threadOf($template, $run)->root_post_id;
        $this->mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::OTHER_CHANNEL_ID] = [
            'id' => FakeMattermost::OTHER_CHANNEL_ID, 'team' => FakeMattermost::TEAM, 'name' => 'archive', 'delete_at' => 0,
            'private' => false, 'open' => false, 'members' => [FakeMattermost::BOT_ID],
        ];
        $this->mattermost->servers[FakeMattermost::SERVER]['posts'][$old]['channel_id'] = FakeMattermost::OTHER_CHANNEL_ID;
        $this->mattermost->ownEditsAllowed = false;

        $this->refresh($run);

        $thread = $this->threadOf($template, $run);
        $this->assertNotSame($old, $thread->root_post_id);
        $this->assertSame([FakeMattermost::CHANNEL_ID, FakeMattermost::CHANNEL_ID], [$thread->channel_id, $this->post($thread->root_post_id)['channel_id']]);
        $this->assertSame([], $this->failedDeliveries());
    }

    /**
     * The lookup that tells a deleted post from a refused edit fails the
     * delivery as its error says, and posts nothing anew: a 403 (the bot
     * may not read the post) for good, a 503 for a later attempt.
     */
    public function testALookupThatFailsPostsNothingAnew(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $post = $this->threadOf($template, $run)->root_post_id;
        $this->mattermost->ownEditsAllowed = false;

        $this->mattermost->script = [['method' => 'GET', 'path' => '/api/v4/posts/' . $post, 'status' => 403, 'body' => '{"id":"' . FakeMattermost::PERMISSION_ERROR . '"}']];
        $this->refresh($run);
        $this->mattermost->script = [['method' => 'GET', 'path' => '/api/v4/posts/' . $post, 'status' => 503, 'body' => '']];
        $this->refresh($run);

        $statuses = $this->deliveriesOf($template, NotificationDelivery::KIND_STATUS);
        $refused = $statuses[count($statuses) - 2];
        $failing = $statuses[count($statuses) - 1];
        $this->assertSame([NotificationDelivery::STATUS_FAILED, 1], [$refused->status, (int)$refused->attempts]);
        $this->assertStringStartsWith('Mattermost: looking up the status post failed (HTTP 403, error ' . FakeMattermost::PERMISSION_ERROR, (string)$refused->last_error);
        $this->assertSame([NotificationDelivery::STATUS_PENDING, 1], [$failing->status, (int)$failing->attempts]);
        $this->assertStringStartsWith('Mattermost: looking up the status post failed (HTTP 503', (string)$failing->last_error);
        $this->assertCount(1, $this->rootPosts());
        $this->assertSame($post, $this->threadOf($template, $run)->root_post_id);
    }

    /**
     * A reply whose root was deleted (400 api.post.create_post.root_id) makes
     * a new status post of the run, records it, and is posted in its thread.
     */
    public function testAReplyToADeletedRootCreatesANewRoot(): void
    {
        $template = $this->mattermostTemplate($this->admin, self::WORKFLOW_EVENTS);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $first = $this->threadOf($template, $run)->root_post_id;
        $this->mattermost->deletePost($first);
        $this->mattermost->requests = [];
        $reply = NotificationOutbox::enqueue($template, NotificationTemplate::EVENT_WORKFLOW_WAITING, ['event' => NotificationTemplate::EVENT_WORKFLOW_WAITING, 'workflow.reason' => 'Waiting.', 'workflow.url' => self::BASE . '/x'], ['workflow_job_id' => (int)$run->id], NotificationDelivery::KIND_REPLY);

        $this->service()->deliver((int)$reply->id);

        $second = $this->threadOf($template, $run)->root_post_id;
        $this->assertNotSame($first, $second);
        $this->assertSame(['POST /api/v4/posts', 'POST /api/v4/posts', 'POST /api/v4/posts'], $this->requestLines());
        $this->assertSame(
            [$first, null, $second],
            array_map(static fn (array $r): mixed => $r['body']['root_id'] ?? null, $this->mattermost->requests),
            'the reply to the deleted root, the new root, the reply to it'
        );
        $this->assertSame([$second], array_column($this->repliesTo($second), 'root_id'));
        $delivered = $this->reload($reply);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $delivered->status);
        $this->assertSame($this->repliesTo($second)[0]['id'], $delivered->remote_id);
        $this->assertStringContainsString('Paused at step', $this->attachmentOf($second)['text'], 'the new root shows the run as it is');
    }

    /**
     * A reply of a run whose post is in another channel than the config
     * names now makes a new status post where the config points, and is
     * posted in its thread.
     */
    public function testAReplyFollowsTheRunToItsNewChannel(): void
    {
        $template = $this->mattermostTemplate($this->admin, self::WORKFLOW_EVENTS);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $second = 'chan3aaaaaaaaaaaaaaaaaaaaa';
        $this->mattermost->servers[FakeMattermost::SERVER]['channels'][$second] = [
            'id' => $second, 'team' => FakeMattermost::TEAM, 'name' => 'releases', 'delete_at' => 0,
            'private' => false, 'open' => false, 'members' => [FakeMattermost::BOT_ID],
        ];
        $template->config = (string)json_encode(['channel_id' => $second] + self::CONFIG, JSON_UNESCAPED_SLASHES);
        $template->save(false);
        $reply = NotificationOutbox::enqueue($template, NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED, ['event' => NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED], ['workflow_job_id' => (int)$run->id], NotificationDelivery::KIND_REPLY);

        $this->service()->deliver((int)$reply->id);

        $thread = $this->threadOf($template, $run);
        $this->assertSame($second, $thread->channel_id);
        $replies = $this->repliesTo($thread->root_post_id);
        $this->assertSame(['The run succeeded.'], array_column($replies, 'message'));
        $this->assertSame($second, $replies[0]['channel_id']);
    }

    /**
     * A reply or an edit that fails otherwise than its fallbacks answer
     * (here 429 and 503) is an error of the delivery, retried with the
     * backoff; nothing is posted anew for it.
     */
    public function testAReplyOrAnEditThatFailsOtherwiseIsRetried(): void
    {
        $template = $this->mattermostTemplate($this->admin, self::WORKFLOW_EVENTS);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $post = $this->threadOf($template, $run)->root_post_id;
        $this->mattermost->script = [
            ['method' => 'POST', 'path' => '/api/v4/posts', 'status' => 429, 'body' => 'limit exceeded'],
            ['method' => 'PUT', 'path' => '/api/v4/posts/' . $post . '/patch', 'status' => 503, 'body' => ''],
        ];
        $reply = NotificationOutbox::enqueue($template, NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED, ['event' => NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED], ['workflow_job_id' => (int)$run->id], NotificationDelivery::KIND_REPLY);
        $this->service()->deliver((int)$reply->id);
        $status = NotificationOutbox::enqueue($template, NotificationDelivery::EVENT_STATUS_REFRESH, ['event' => NotificationDelivery::EVENT_STATUS_REFRESH], ['workflow_job_id' => (int)$run->id], NotificationDelivery::KIND_STATUS);
        NotificationDelivery::updateAll(['status' => NotificationDelivery::STATUS_DELIVERED], ['id' => $reply->id]);
        $this->service()->deliver((int)$status->id);

        $this->assertStringStartsWith('Mattermost: posting failed (HTTP 429', (string)$this->reload($reply)->last_error);
        $this->assertSame([NotificationDelivery::STATUS_PENDING, 1], [$this->reload($status)->status, (int)$this->reload($status)->attempts]);
        $this->assertStringStartsWith('Mattermost: editing the status post failed (HTTP 503', (string)$this->reload($status)->last_error);
        $this->assertSame($post, $this->threadOf($template, $run)->root_post_id);
        $this->assertCount(1, $this->rootPosts());
    }

    /**
     * A status post whose thread cannot be saved (a handler refused it)
     * fails the attempt, saying so, and is tried again.
     */
    public function testAThreadThatCannotBeSavedFailsTheAttempt(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $refuse = static function (\yii\base\ModelEvent $event): void {
            $event->isValid = false;
        };
        \yii\base\Event::on(NotificationThread::class, \yii\db\BaseActiveRecord::EVENT_BEFORE_INSERT, $refuse);
        try {
            $run = $this->launchPausedRun();
            $this->queue->run();
        } finally {
            \yii\base\Event::off(NotificationThread::class, \yii\db\BaseActiveRecord::EVENT_BEFORE_INSERT, $refuse);
        }

        $first = $this->deliveriesOf($template, NotificationDelivery::KIND_STATUS)[0];
        $this->assertSame([NotificationDelivery::STATUS_PENDING, \app\services\notification\ThreadedSend::THREAD_NOT_SAVED], [$first->status, $first->last_error]);
        $this->assertNull(NotificationThread::findOne(['notification_template_id' => $template->id, 'workflow_job_id' => $run->id]));
    }

    /**
     * A kept check that is not of the form a check keeps (here a channel ID
     * of another form) counts as none: the token and the channel are
     * checked again.
     */
    public function testAKeptCheckOfAnotherFormIsCheckedAgain(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED);
        $this->deliverMessage($template);
        $state = json_decode((string)NotificationTemplate::findOne($template->id)?->channel_state, true);
        $this->assertIsArray($state);
        NotificationTemplate::updateAll(['channel_state' => (string)json_encode(['channel_id' => '../x'] + $state)], ['id' => $template->id]);
        $template->refresh();
        $this->mattermost->requests = [];

        $this->deliverMessage($template);

        $this->assertSame(['GET /api/v4/users/me', 'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID, 'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID . '/members/me', 'POST /api/v4/posts'], $this->requestLines());
    }

    /**
     * A status of a run that went while it was sent fails, for good.
     */
    public function testAStatusOfARunThatIsGoneFails(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $delivery = new NotificationDelivery(['notification_template_id' => (int)$template->id, 'kind' => NotificationDelivery::KIND_STATUS, 'workflow_job_id' => 999999999, 'event' => 'workflow.launched']);
        $channel = $this->dispatcher()->channel(NotificationTemplate::CHANNEL_MATTERMOST);
        $this->assertInstanceOf(MattermostChannel::class, $channel);

        $outcome = \app\services\notification\ThreadedSend::outcome($channel, $delivery, $template, ['subject' => 's', 'body' => 'b'], []);

        $this->assertSame([\app\services\notification\DeliveryOutcome::PERMANENT, \app\services\notification\ThreadedSend::RUN_GONE], [$outcome->kind, $outcome->error]);
        $this->assertSame([], $this->mattermost->requests);
    }

    /**
     * A reply without a status post (its status delivery failed) fails, and
     * says why, without a request.
     */
    public function testAReplyWithoutAStatusPostFails(): void
    {
        $template = $this->mattermostTemplate($this->admin, self::WORKFLOW_EVENTS);
        $run = $this->launchPausedRun(false);
        $reply = NotificationOutbox::enqueue($template, NotificationTemplate::EVENT_WORKFLOW_WAITING, ['event' => 'workflow.waiting'], ['workflow_job_id' => (int)$run->id], NotificationDelivery::KIND_REPLY);

        $this->service()->deliver((int)$reply->id);

        $this->assertSame([NotificationDelivery::STATUS_FAILED, \app\services\notification\ThreadedSend::NO_STATUS_POST], [$this->reload($reply)->status, $this->reload($reply)->last_error]);
        $this->assertSame([], $this->mattermost->requests);
    }

    // -- The checks of the token and the channel ----------------------------------------------

    /**
     * The first delivery after the token or the channel changed checks the
     * token (a bot's), the channel (by its names, resolved to its ID) and
     * the bot's membership, and keeps the result with the template's server
     * state (channel_state), not in its config; the next deliveries ask
     * nothing more. A change of the channel checks again.
     */
    public function testTheTokenAndTheChannelAreCheckedOnceAfterAChange(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, ['channel_id' => '', 'team' => FakeMattermost::TEAM, 'channel' => FakeMattermost::CHANNEL]);

        $this->deliverMessage($template);
        $this->deliverMessage($template);

        $this->assertSame([
            'GET /api/v4/users/me',
            'GET /api/v4/teams/name/' . FakeMattermost::TEAM . '/channels/name/' . FakeMattermost::CHANNEL,
            'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID . '/members/me',
            'POST /api/v4/posts',
            'POST /api/v4/posts',
        ], $this->requestLines());
        $state = json_decode((string)NotificationTemplate::findOne($template->id)?->channel_state, true);
        $this->assertIsArray($state);
        $this->assertSame(
            ['channel_id' => FakeMattermost::CHANNEL_ID, 'bot_user_id' => FakeMattermost::BOT_ID, 'bot_username' => 'ansilume-bot'],
            ['channel_id' => $state['channel_id'] ?? null, 'bot_user_id' => $state['bot_user_id'] ?? null, 'bot_username' => $state['bot_username'] ?? null]
        );
        $this->assertStringNotContainsString(FakeMattermost::BOT_TOKEN, (string)NotificationTemplate::findOne($template->id)?->channel_state);
        $this->assertSame(FakeMattermost::CHANNEL_ID, $this->mattermost->posts()[0]['channel_id']);

        $this->mattermost->requests = [];
        $template->config = (string)json_encode(self::CONFIG, JSON_UNESCAPED_SLASHES);
        $template->save(false);
        $this->deliverMessage($template);

        $this->assertSame([
            'GET /api/v4/users/me',
            'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID,
            'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID . '/members/me',
            'POST /api/v4/posts',
        ], $this->requestLines());
    }

    /**
     * What the check refuses, each for good, with what to do: a personal
     * token, an archived channel, a channel that does not exist.
     */
    public function testTheCheckRefusesWhatCannotWork(): void
    {
        $personal = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, ['bot_token' => FakeMattermost::PERSONAL_TOKEN]);
        $missing = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, ['channel_id' => 'nochannelaaaaaaaaaaaaaaaaa']);
        $archived = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED);

        $this->deliverMessage($personal);
        $this->deliverMessage($missing);
        $this->mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::CHANNEL_ID]['delete_at'] = 1700000000000;
        $this->deliverMessage($archived);

        $errors = array_map(static fn (NotificationDelivery $d): string => (string)$d->last_error, $this->failedDeliveries());
        $this->assertSame(MattermostApiException::PERSONAL_TOKEN, $errors[0]);
        $this->assertStringStartsWith('Mattermost has no channel nochannelaaaaaaaaaaaaaaaaa that the bot may see (HTTP 404', $errors[1]);
        $this->assertSame(sprintf(MattermostApiException::ARCHIVED, FakeMattermost::CHANNEL_ID), $errors[2]);
        $this->assertSame([], $this->mattermost->posts());
        $this->assertNull(NotificationTemplate::findOne($personal->id)?->channel_state, 'a refused check is not kept');
    }

    /**
     * Regression (spec section 5): any 401 means the token, and its error
     * names the causes; it fails the delivery at once, for good, and its
     * notification.failed entry says the same, never with the token.
     */
    public function testUnauthorizedNamesTheCauses(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, ['bot_token' => 'revoked-token-7799']);

        $delivery = $this->deliverMessage($template);

        $row = $this->reload($delivery);
        $this->assertSame([NotificationDelivery::STATUS_FAILED, 1], [$row->status, (int)$row->attempts]);
        $this->assertMatchesRegularExpression(
            '/^Mattermost refused the bot token \(HTTP 401, error api\.context\.session_expired\.app_error, request ID req\d+\): it was revoked or has expired, or the bot was disabled when its owner was deactivated\.$/',
            (string)$row->last_error
        );
        $entry = AuditLog::find()->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_id' => $template->id])->one();
        $this->assertInstanceOf(AuditLog::class, $entry);
        $this->assertStringContainsString('Mattermost refused the bot token', (string)$entry->metadata);
        foreach ([(string)$row->last_error, (string)$entry->metadata] as $text) {
            $this->assertStringNotContainsString('revoked-token-7799', $text);
            $this->assertStringNotContainsString(FakeMattermost::BODY_MARKER, $text);
        }
        $this->assertSame([], $this->queue->pushes);
    }

    /**
     * Regression (spec section 1, as for every HTTP channel): a stored
     * template whose server_url is a file: URL, written around the rules,
     * fails its delivery for good before any request, saying why; nothing
     * of the file shows.
     */
    public function testRegressionAFileServerUrlIsRefusedWithoutBeingRead(): void
    {
        $hostname = trim((string)file_get_contents('/etc/hostname'));
        $this->assertNotSame('', $hostname);
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, ['server_url' => 'file:///etc/hostname']);

        $delivery = $this->deliverMessage($template);

        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $row->status);
        $this->assertSame(sprintf(MattermostApiException::CONFIG_UNUSABLE, sprintf(NotificationTemplate::HTTPS_REQUIRED, 'server_url')), $row->last_error);
        $this->assertStringNotContainsString($hostname, (string)$row->last_error);
        $this->assertSame([], $this->mattermost->requests);
        $this->assertSame(
            sprintf(MattermostApiException::CONFIG_UNUSABLE, sprintf(NotificationTemplate::HTTPS_REQUIRED, 'server_url')),
            $this->dispatcher()->sendSingle($template, ['event' => 'test'], 'test'),
            'Send Test says the same'
        );
    }

    /**
     * A 429 is retried after the server's Retry-After, when it is longer
     * than the backoff's step, and the attempt counts.
     */
    public function testRateLimitWaitsRetryAfter(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED);
        $this->mattermost->script = [['method' => 'POST', 'path' => '/api/v4/posts', 'status' => 429, 'body' => 'limit exceeded', 'headers' => ['retry-after' => '90']]];
        $before = time();

        $delivery = $this->deliverMessage($template);

        $row = $this->reload($delivery);
        $this->assertSame([NotificationDelivery::STATUS_PENDING, 1], [$row->status, (int)$row->attempts]);
        $this->assertGreaterThanOrEqual($before + 90, (int)$row->next_attempt_at);
        $this->assertLessThanOrEqual(time() + 90, (int)$row->next_attempt_at);
        $this->assertStringStartsWith('Mattermost: posting failed (HTTP 429, request ID req', (string)$row->last_error);
        $this->assertSame([['deliveryId' => (int)$delivery->id, 'delay' => 90]], $this->queue->pushes);
    }

    // -- Send Test --------------------------------------------------------------------------

    /**
     * Send Test checks the token first: a personal token carries a human's
     * rights, so it is refused before anything is posted, with what to do.
     */
    public function testSendTestRefusesAPersonalToken(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, ['bot_token' => FakeMattermost::PERSONAL_TOKEN]);

        $error = $this->dispatcher()->sendSingle($template, ['event' => 'test', 'project.name' => 'p'], 'test');

        $this->assertSame(MattermostApiException::PERSONAL_TOKEN, $error);
        $this->assertSame(['GET /api/v4/users/me'], $this->requestLines());
        $this->assertSame([], $this->mattermost->posts());
        $this->assertTrue(AuditLog::find()->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_id' => $template->id])->exists());
    }

    /**
     * Send Test needs the bot in the channel, where it must edit its status
     * posts: a bot outside it is refused, with what to do. In the channel,
     * Send Test checks everything again, though checked before, posts the
     * test message and keeps the check.
     */
    public function testSendTestRequiresChannelMembership(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED);
        $this->mattermost->removeBot();

        $refused = $this->dispatcher()->sendSingle($template, ['event' => 'test'], 'test');

        $this->assertStringStartsWith('The bot is not a member of the Mattermost channel ' . FakeMattermost::CHANNEL_ID . ' (HTTP 404', (string)$refused);
        $this->assertSame([], $this->mattermost->posts());

        $this->mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::CHANNEL_ID]['members'][] = FakeMattermost::BOT_ID;
        $this->mattermost->requests = [];
        $this->assertNull($this->dispatcher()->sendSingle($template, ['event' => 'test'], 'test'));
        $this->assertNull($this->dispatcher()->sendSingle($template, ['event' => 'test'], 'test'));

        $checks = ['GET /api/v4/users/me', 'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID, 'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID . '/members/me', 'POST /api/v4/posts'];
        $this->assertSame([...$checks, ...$checks], $this->requestLines(), 'Send Test checks every time');
        $this->assertCount(2, $this->mattermost->posts());
        $this->assertNotNull(NotificationTemplate::findOne($template->id)?->channel_state);
    }

    // -- Other events --------------------------------------------------------------------------

    /**
     * An event outside a run is a post of its own: an attachment coloured
     * by the event's severity with the template's subject and body, whose
     * variables are escaped; its ID is the delivery's remote_id, and its
     * create carries a pending post ID of the bot and the delivery.
     */
    public function testAnEventOutsideARunIsAPostOfItsOwn(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_PROJECT_SYNC_FAILED);
        $template->subject_template = '**Sync failed:** {{ project.name }}';
        $template->body_template = "Error:\n{{ project.error }}";
        $template->save(false);

        $delivery = $this->deliverMessage($template, ['project.name' => '@all_team', 'project.error' => '# boom']);

        $post = $this->mattermost->posts()[0];
        $this->assertSame(['', ''], [$post['message'], $post['root_id']]);
        $this->assertSame([[
            'fallback' => '**Sync failed:** @' . "\u{200B}" . 'all_team',
            'color' => '#D24B4E',
            'title' => '**Sync failed:** @' . "\u{200B}" . 'all\\_team',
            'text' => "Error:\n\\# boom",
        ]], $post['props']['attachments']);
        $this->assertSame($post['id'], $this->reload($delivery)->remote_id);
        $create = $this->mattermost->requestsTo('POST', '/api/v4/posts')[0];
        $this->assertSame('set_online=false', $create['query']);
        $this->assertMatchesRegularExpression('/^' . FakeMattermost::BOT_ID . ':[0-9a-f]{8}-' . $delivery->id . '-message$/', (string)($create['body']['pending_post_id'] ?? ''));
        $this->assertArrayNotHasKey('from_bot', $create['body'] ?? []);
    }

    /**
     * A job event of a run reaches a template whose user may see the job
     * but not the run's workflow as a post of its own, without the run's
     * context: no thread, no workflow name (spec answer 2).
     */
    public function testAJobEventOfARunTheIdentityCannotSeeIsAPostOfItsOwn(): void
    {
        $scope = $this->teamScope();
        $hidden = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id, (int)$scope['foreign']->id);
        $run = $this->createWorkflowJob((int)$hidden->id, (int)$scope['admin']->id);
        $job = $this->createJob((int)$scope['own']->id, (int)$scope['admin']->id, \app\models\Job::STATUS_FAILED);
        $template = $this->mattermostTemplate($scope['member'], NotificationTemplate::EVENT_JOB_FAILED);

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_JOB_FAILED, [
            'job' => ['id' => (string)$job->id, 'status' => 'failed'],
            'workflow' => ['id' => (string)$run->id, 'template_name' => 'hidden-workflow-name'],
            NotificationDispatcher::REFS => ['workflow_job_id' => (int)$run->id, 'job_id' => (int)$job->id],
        ]);
        $this->queue->run();

        $this->assertSame([NotificationDelivery::KIND_MESSAGE], array_column($this->deliveriesOf($template), 'kind'));
        $this->assertNull(NotificationThread::findOne(['notification_template_id' => $template->id]));
        $this->assertCount(1, $this->mattermost->posts());
        $this->assertStringNotContainsString('hidden-workflow-name', (string)json_encode($this->mattermost->requests));
    }

    // -- What a post may hold -----------------------------------------------------------------

    /**
     * Regression (review focus 4): a workflow or step name with @all,
     * Markdown or a mention, an error with @channel, a body of more than
     * 20000 characters: every post renders them as plain text, mentions
     * nobody, and stays below Mattermost's limit, cut with a link to the
     * run.
     */
    public function testUserTextCannotMentionOrBreakTheLayout(): void
    {
        $template = $this->mattermostTemplate($this->admin, self::WORKFLOW_EVENTS . ',project.sync_failed');
        $template->body_template = str_repeat('{{ project.error }}', 4);
        $template->save(false);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $workflow->name = '@all **deploy** [x](https://evil.example) # h | > q';
        $workflow->save(false);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $step->name = '@here `rm -rf` ~town-square';
        $step->save(false);
        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        WorkflowJobStep::updateAll(['error_message' => '@channel ' . str_repeat('*x* ', 120)], ['workflow_job_id' => $run->id]);
        $this->cancellation()->cancelRun($run, (int)$this->launcher->id, JobCancellationService::REASON_OPERATOR);
        $messageId = (string)$this->reload($this->deliverMessage($template, ['project.error' => '@channel ' . str_repeat('ä', 7000)]))->remote_id;
        $this->queue->run();

        $this->assertNotSame([], $this->mattermost->requestsTo('POST', '/api/v4/posts'));
        foreach ($this->mattermost->requests as $request) {
            foreach ($this->textsOf($request['body'] ?? []) as $text) {
                $this->assertSame([], MattermostMentions::in($text), $text);
                $this->assertLessThan(MattermostText::MATTERMOST_LIMIT, mb_strlen($text));
            }
        }
        $this->assertSame([], $this->failedDeliveries());
        $attachment = $this->attachmentOf($this->threadOf($template, $run)->root_post_id);
        $this->assertSame('Workflow Job #' . $run->id . ': @' . "\u{200B}" . 'all \\*\\*deploy\\*\\* \\[x\\]\\(https://evil\\.example\\) \\# h \\| \\> q', $attachment['title']);
        $this->assertStringContainsString('Step 1 "@' . "\u{200B}" . 'here \\`rm \\-rf\\` \\~town\\-square": Canceled', $attachment['text']);
        $this->assertStringEndsWith(sprintf(MattermostText::CUT, MattermostText::REST), $this->attachmentOf($messageId)['text'], 'a message without a link names where the rest is');
    }

    /**
     * A long loop of a run lists its last executions, says how many came
     * before, and stays short.
     */
    public function testALongLoopListsItsLastSteps(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $first = WorkflowJobStep::find()->where(['workflow_job_id' => $run->id])->one();
        $this->assertInstanceOf(WorkflowJobStep::class, $first);
        for ($i = 0; $i < 40; $i++) {
            $loop = new WorkflowJobStep();
            $loop->setAttributes($first->getAttributes(null, ['id']), false);
            $loop->status = WorkflowJobStep::STATUS_SUCCEEDED;
            $loop->save(false);
        }
        $this->queue->run();

        $text = $this->attachmentOf($this->threadOf($template, $run)->root_post_id)['text'];
        $this->assertStringContainsString('… and 11 earlier step runs', $text);
        $this->assertSame(30, substr_count($text, 'Step 1 "step\\-0":'));
    }

    // -- The server and the token ----------------------------------------------------------------

    /**
     * Regression (review focus 3): a template whose post lives on another
     * server than its config names now (an admin moved it, entering the new
     * server's token) posts anew on its server, with its token: the old
     * server gets no request, so no token goes where the template no longer
     * points, and the new token never goes to the old server.
     */
    public function testRegressionAStoredBotTokenNeverGoesToAChangedServer(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $old = $this->threadOf($template, $run)->root_post_id;
        $edits = $this->post($old)['edits'];
        $template->config = (string)json_encode(['server_url' => FakeMattermost::OTHER_SERVER, 'bot_token' => FakeMattermost::OTHER_BOT_TOKEN, 'channel_id' => FakeMattermost::OTHER_CHANNEL_ID], JSON_UNESCAPED_SLASHES);
        $template->save(false);
        $this->mattermost->requests = [];

        $this->refresh($run);

        $this->assertSame([FakeMattermost::OTHER_SERVER], array_values(array_unique(array_column($this->mattermost->requests, 'server'))));
        $this->assertSame([FakeMattermost::OTHER_BOT_TOKEN], array_values(array_unique(array_column($this->mattermost->requests, 'token'))));
        $this->assertSame(
            ['GET /api/v4/users/me', 'GET /api/v4/channels/' . FakeMattermost::OTHER_CHANNEL_ID, 'GET /api/v4/channels/' . FakeMattermost::OTHER_CHANNEL_ID . '/members/me', 'POST /api/v4/posts'],
            $this->requestLines(),
            'the check of the new config, then a new post: no edit of a post that lives elsewhere'
        );
        $thread = $this->threadOf($template, $run);
        $this->assertSame([FakeMattermost::OTHER_SERVER, FakeMattermost::OTHER_CHANNEL_ID], [$thread->server, $thread->channel_id]);
        $this->assertNotSame($old, $thread->root_post_id);
        $this->assertCount(1, $this->mattermost->posts(FakeMattermost::OTHER_SERVER));
        $this->assertSame($edits, $this->post($old)['edits'], 'the old post is left as it was');
    }

    /**
     * A post on another server than the config names now is never edited,
     * also when the new server knows a channel of the same ID (the same
     * server under a new address, or a copy of it): the run gets a new
     * status post on the server the config names.
     */
    public function testAPostOnAnotherServerIsNeverEdited(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $copy = 'https://chat-new.example.com';
        $this->mattermost->servers[$copy] = $this->mattermost->servers[FakeMattermost::SERVER];
        $template->config = (string)json_encode(['server_url' => $copy] + self::CONFIG, JSON_UNESCAPED_SLASHES);
        $template->save(false);
        $this->mattermost->requests = [];

        $this->refresh($run);

        $this->assertSame(
            ['GET /api/v4/users/me', 'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID, 'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID . '/members/me', 'POST /api/v4/posts'],
            $this->requestLines()
        );
        $this->assertSame([$copy], array_values(array_unique(array_column($this->mattermost->requests, 'server'))));
        $this->assertSame($copy, $this->threadOf($template, $run)->server);
    }

    /**
     * A post of the run in another channel than the config names now (the
     * template was moved to another channel of the same server) is left
     * there, although the bot could still edit it: the run gets a new
     * status post in its channel, and its thread follows.
     */
    public function testAPostInAnotherChannelIsLeftAndTheRunPostedAnew(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $old = $this->threadOf($template, $run)->root_post_id;
        $edits = $this->post($old)['edits'];
        $second = 'chan3aaaaaaaaaaaaaaaaaaaaa';
        $this->mattermost->servers[FakeMattermost::SERVER]['channels'][$second] = [
            'id' => $second, 'team' => FakeMattermost::TEAM, 'name' => 'releases', 'delete_at' => 0,
            'private' => true, 'open' => false, 'members' => [FakeMattermost::BOT_ID],
        ];
        $template->config = (string)json_encode(['channel_id' => $second] + self::CONFIG, JSON_UNESCAPED_SLASHES);
        $template->save(false);
        $this->mattermost->requests = [];

        $this->refresh($run);

        $thread = $this->threadOf($template, $run);
        $this->assertSame($second, $thread->channel_id);
        $this->assertSame($second, $this->post($thread->root_post_id)['channel_id']);
        $this->assertSame($edits, $this->post($old)['edits'], 'the post in the old channel is left as it was');
        $this->assertSame([], $this->mattermost->requestsTo('PUT', '/api/v4/posts'));
    }

    /**
     * The worst case of one delivery stays within MattermostApi::MAX_REQUESTS:
     * a new token (the check: 3 requests) whose bot may not edit the post of
     * the one before (an edit, its retry), the lookup that finds the post
     * another user's, then a new post. No request is left to send the create
     * once more.
     */
    public function testTheWorstCaseOfADeliveryStaysWithinItsRequests(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $run = $this->launchPausedRun();
        $this->queue->run();
        $old = $this->threadOf($template, $run)->root_post_id;
        $this->mattermost->servers[FakeMattermost::SERVER]['users']['second-bot-token-7790'] = ['id' => 'bot3aaaaaaaaaaaaaaaaaaaaaa', 'username' => 'second', 'is_bot' => true];
        $this->mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::CHANNEL_ID]['members'][] = 'bot3aaaaaaaaaaaaaaaaaaaaaa';
        $template->config = (string)json_encode(['bot_token' => 'second-bot-token-7790'] + self::CONFIG, JSON_UNESCAPED_SLASHES);
        $template->save(false);
        $this->mattermost->requests = [];

        $this->refresh($run);

        $this->assertSame(
            [
                'GET /api/v4/users/me',
                'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID,
                'GET /api/v4/channels/' . FakeMattermost::CHANNEL_ID . '/members/me',
                'PUT /api/v4/posts/' . $old . '/patch',
                'PUT /api/v4/posts/' . $old . '/patch',
                'GET /api/v4/posts/' . $old,
                'POST /api/v4/posts',
            ],
            $this->requestLines()
        );
        $this->assertCount(MattermostApi::MAX_REQUESTS, $this->mattermost->requests);
        $this->assertSame([], $this->failedDeliveries());
        $this->assertCount(2, $this->rootPosts());
        $this->assertSame('bot3aaaaaaaaaaaaaaaaaaaaaa', $this->post($this->threadOf($template, $run)->root_post_id)['user_id'], 'the post of the bot of the new token');
    }

    // -- A lost answer ------------------------------------------------------------------------

    /**
     * Regression (review: a lost answer posted twice): a create whose
     * answer was lost after Mattermost made the post (here the client's
     * timeout) is sent once more at once, with the same pending post ID,
     * and Mattermost answers the post it made: the run keeps one status
     * post, recorded, and the delivery is delivered. Before, the retry came
     * after the backoff, when Mattermost had forgotten the pending post ID,
     * and posted the status a second time.
     */
    public function testRegressionACreateWhoseAnswerWasLostIsNotPostedTwice(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $this->mattermost->script = [['method' => 'POST', 'path' => '/api/v4/posts', 'status' => 0, 'served' => true]];

        $run = $this->launchPausedRun();
        $this->queue->run();

        $roots = $this->rootPosts();
        $this->assertCount(1, $roots, 'one status post');
        $this->assertSame($roots[0]['id'], $this->threadOf($template, $run)->root_post_id);
        $creates = $this->mattermost->requestsTo('POST', '/api/v4/posts');
        $this->assertCount(2, $creates);
        $this->assertSame($creates[0]['body']['pending_post_id'] ?? null, $creates[1]['body']['pending_post_id'] ?? null);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->deliveriesOf($template, NotificationDelivery::KIND_STATUS)[0]->status);
        $this->assertSame([], $this->failedDeliveries());
    }

    /**
     * What stays (docs: Limits): a create whose answer was lost twice, the
     * one sent again too, waits for the backoff, at least 30 seconds, after
     * which Mattermost forgot the pending post ID: its retry posts the
     * status again, and the first post stays as it was.
     */
    public function testACreateWhoseAnswerWasLostTwiceIsPostedAgainAfterTheBackoff(): void
    {
        $template = $this->mattermostTemplate($this->admin, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED);
        $lost = ['method' => 'POST', 'path' => '/api/v4/posts', 'status' => 0, 'served' => true];
        $this->mattermost->script = [$lost, $lost];
        $run = $this->launchPausedRun();
        $this->queue->run();
        $status = $this->deliveriesOf($template, NotificationDelivery::KIND_STATUS)[0];
        $this->assertSame([NotificationDelivery::STATUS_PENDING, 1], [$this->reload($status)->status, (int)$this->reload($status)->attempts]);
        $this->assertStringStartsWith('Mattermost: posting failed (The request timed out after 15 seconds)', (string)$this->reload($status)->last_error);
        $this->assertCount(1, $this->rootPosts(), 'the first create made its post');

        $this->mattermost->clock += 30 + 1;
        NotificationDelivery::updateAll(['next_attempt_at' => time()], ['id' => $status->id]);
        $this->service()->deliver((int)$status->id);

        $this->assertCount(2, $this->rootPosts(), 'posted again');
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($status)->status);
        $this->assertSame($this->rootPosts()[1]['id'], $this->threadOf($template, $run)->root_post_id);
        $this->assertSame(30, FakeMattermost::PENDING_SECONDS);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * A Mattermost template of the fake's server that delivers as $identity,
     * listens to $events at $level, with $config on top of CONFIG.
     *
     * @param array<string, mixed> $config
     */
    private function mattermostTemplate(User $identity, string $events, array $config = [], string $level = NotificationTemplate::LEVEL_ALL): NotificationTemplate
    {
        $template = $this->createNotificationTemplate((int)$identity->id, NotificationTemplate::CHANNEL_MATTERMOST, $events, (string)json_encode($config + self::CONFIG, JSON_UNESCAPED_SLASHES));
        $template->level = $level;
        $template->save(false);

        return $template;
    }

    /** A run of a workflow whose only step is a pause, waiting; its events are queued, not sent, when $notify. */
    private function launchPausedRun(bool $notify = true): WorkflowJob
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        if ($notify) {
            return $this->engine()->launch($workflow, (int)$this->launcher->id);
        }

        return $this->createWorkflowJob((int)$workflow->id, (int)$this->launcher->id);
    }

    /** Refreshes the status posts of $run (an approval of a step was settled) and sends the deliveries. */
    private function refresh(WorkflowJob $run): void
    {
        $this->dispatcher()->refreshStatus((int)$run->id);
        $this->queue->run();
    }

    /**
     * Queues project.sync_failed for $template, with $variables, and runs the queue.
     *
     * @param array<string, string> $variables
     */
    private function deliverMessage(NotificationTemplate $template, array $variables = []): NotificationDelivery
    {
        $project = $this->createProject((int)$this->admin->id);
        $delivery = NotificationOutbox::enqueue(
            $template,
            NotificationTemplate::EVENT_PROJECT_SYNC_FAILED,
            $variables + ['event' => NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, 'severity' => 'critical', 'project.name' => 'p'],
            ['project_id' => (int)$project->id]
        );
        $this->service()->deliver((int)$delivery->id);

        return $delivery;
    }

    private function threadOf(NotificationTemplate $template, WorkflowJob $run): NotificationThread
    {
        $thread = NotificationThread::findOne(['notification_template_id' => $template->id, 'workflow_job_id' => $run->id]);
        $this->assertInstanceOf(NotificationThread::class, $thread);

        return $thread;
    }

    /**
     * The posts of the fake's server that start a thread.
     *
     * @return list<array{id: string, channel_id: string, root_id: string, user_id: string, message: string, props: array<mixed>, deleted: bool, edits: int}>
     */
    private function rootPosts(): array
    {
        return array_values(array_filter(
            array_merge($this->mattermost->posts(), $this->mattermost->posts(FakeMattermost::OTHER_SERVER)),
            static fn (array $post): bool => $post['root_id'] === '' && isset($post['props']['attachments'][0]['title_link'])
        ));
    }

    /**
     * @return list<array{id: string, channel_id: string, root_id: string, user_id: string, message: string, props: array<mixed>, deleted: bool, edits: int}>
     */
    private function repliesTo(string $rootId): array
    {
        return array_values(array_filter($this->mattermost->posts(), static fn (array $post): bool => $post['root_id'] === $rootId));
    }

    /**
     * @return array{id: string, channel_id: string, root_id: string, user_id: string, message: string, props: array<mixed>, deleted: bool, edits: int}
     */
    private function post(string $id): array
    {
        $post = $this->mattermost->servers[FakeMattermost::SERVER]['posts'][$id] ?? null;
        $this->assertIsArray($post);

        return $post;
    }

    /**
     * The attachment of post $id.
     *
     * @return array<string, mixed>
     */
    private function attachmentOf(string $id): array
    {
        $attachment = $this->post($id)['props']['attachments'][0] ?? null;
        $this->assertIsArray($attachment);

        return $attachment;
    }

    /**
     * The deliveries of $template, of $kind when given, by ID.
     *
     * @return list<NotificationDelivery>
     */
    private function deliveriesOf(NotificationTemplate $template, ?string $kind = null): array
    {
        $query = NotificationDelivery::find()->where(['notification_template_id' => $template->id])->orderBy(['id' => SORT_ASC]);
        if ($kind !== null) {
            $query->andWhere(['kind' => $kind]);
        }

        /** @var list<NotificationDelivery> $rows */
        $rows = $query->all();

        return $rows;
    }

    /**
     * Every failed delivery, by ID.
     *
     * @return list<NotificationDelivery>
     */
    private function failedDeliveries(): array
    {
        /** @var list<NotificationDelivery> $rows */
        $rows = NotificationDelivery::find()->where(['status' => NotificationDelivery::STATUS_FAILED])->orderBy(['id' => SORT_ASC])->all();

        return $rows;
    }

    /**
     * The requests the fake was given, as "METHOD path".
     *
     * @return list<string>
     */
    private function requestLines(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $this->mattermost->requests);
    }

    /**
     * Every text of a request's body that Mattermost renders or searches
     * for mentions.
     *
     * @param array<mixed> $body
     * @return list<string>
     */
    private function textsOf(array $body): array
    {
        $texts = [];
        array_walk_recursive($body, static function (mixed $value, int|string $key) use (&$texts): void {
            if (is_string($value) && in_array($key, ['message', 'title', 'text', 'pretext', 'footer', 'value', 'fallback'], true)) {
                $texts[] = $value;
            }
        });

        return $texts;
    }

    private function reload(NotificationDelivery $delivery): NotificationDelivery
    {
        $row = NotificationDelivery::findOne($delivery->id);
        $this->assertInstanceOf(NotificationDelivery::class, $row);

        return $row;
    }

    private function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }

    private function service(): NotificationDeliveryService
    {
        /** @var NotificationDeliveryService $service */
        $service = \Yii::$app->get('notificationDeliveryService');

        return $service;
    }

    private function cancellation(): WorkflowCancellationService
    {
        /** @var WorkflowCancellationService $service */
        $service = \Yii::$app->get('workflowCancellationService');

        return $service;
    }
}
