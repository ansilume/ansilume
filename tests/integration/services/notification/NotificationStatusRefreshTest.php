<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\User;
use app\models\WorkflowJob;
use app\services\NotificationDispatcher;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\log\Logger;

/**
 * NotificationDispatcher::refreshStatus(), what WorkflowRunEvents::statusChanged()
 * asks for when a run's state changed without an event of its own: a status
 * delivery of the run (NotificationDelivery::KIND_STATUS, event
 * NotificationDelivery::EVENT_STATUS_REFRESH), pushed to the notification queue, for
 * every template
 * - whose status post of the run exists (notification_thread) or is on its
 *   way (a status delivery of the run that is not finished),
 * - whose channel keeps one (ThreadedChannelInterface),
 * - and whose identity may still see the run, its workflow filter included.
 * The template's events and level do not count: they decide which replies a
 * thread gets, not whether its post stays current (spec answer 1). It never
 * throws into the caller.
 */
class NotificationStatusRefreshTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    /** The channel of ThreadedTestChannel, which keeps a status post per run. */
    private const THREADED = 'threaded-test';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
        $this->dispatcher()->setChannel(self::THREADED, new ThreadedTestChannel());
        $this->admin = $this->createUserWithRole('refresh-admin', 'admin');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * A template with a status post of the run gets a status delivery of
     * it, whatever events and level it has, each time the status changes;
     * the post renders the run as it is when the delivery is sent, so the
     * delivery carries only what names the run.
     */
    public function testATemplateWithAPostOfTheRunGetsARefreshWhateverItsEventsAndLevel(): void
    {
        $run = $this->newRun();
        $template = $this->threadedTemplate($this->admin);
        $template->level = NotificationTemplate::LEVEL_ESSENTIAL;
        $template->save(false);
        $this->postOf($template, $run);

        $this->dispatcher()->refreshStatus((int)$run->id);
        $this->dispatcher()->refreshStatus((int)$run->id);

        $refreshes = $this->refreshesOf($run);
        $this->assertCount(2, $refreshes, 'one per change; the later supersedes the earlier when they are sent');
        foreach ($refreshes as $refresh) {
            $this->assertSame((int)$template->id, (int)$refresh->notification_template_id);
            $this->assertSame(NotificationDelivery::KIND_STATUS, $refresh->kind);
            $this->assertSame(NotificationDelivery::EVENT_STATUS_REFRESH, $refresh->event);
            $this->assertSame(NotificationDelivery::STATUS_PENDING, $refresh->status);
            $this->assertSame(0, (int)$refresh->attempts);
            $this->assertSame(['workflow_job_id' => (int)$run->id], $refresh->getRefs());
            $variables = $refresh->getVariableMap();
            $this->assertSame(
                ['event' => NotificationDelivery::EVENT_STATUS_REFRESH, 'workflow.id' => (string)$run->id],
                ['event' => $variables['event'] ?? null, 'workflow.id' => $variables['workflow.id'] ?? null]
            );
        }
        $this->assertSame(
            array_map(static fn (NotificationDelivery $d): array => ['deliveryId' => (int)$d->id, 'delay' => 0], $refreshes),
            $this->queue->pushes
        );
    }

    /**
     * A post on its way (an unfinished status delivery of the run, before
     * the post exists) counts as a post: the refresh waits its turn behind
     * it in the run's thread. A status delivery that finished without a
     * post, a message of the run, and a post of another run do not count.
     */
    public function testAPostOnItsWayCountsAndNothingElseDoes(): void
    {
        $run = $this->newRun();
        $onItsWay = $this->threadedTemplate($this->admin);
        $this->deliveryOf($onItsWay, $run, NotificationDelivery::KIND_STATUS, NotificationDelivery::STATUS_SENDING);
        $failedPost = $this->threadedTemplate($this->admin);
        $this->deliveryOf($failedPost, $run, NotificationDelivery::KIND_STATUS, NotificationDelivery::STATUS_FAILED);
        $messageOnly = $this->threadedTemplate($this->admin);
        $this->deliveryOf($messageOnly, $run, NotificationDelivery::KIND_MESSAGE, NotificationDelivery::STATUS_PENDING);
        $otherRun = $this->threadedTemplate($this->admin);
        $this->postOf($otherRun, $this->newRun());

        $this->dispatcher()->refreshStatus((int)$run->id);

        $this->assertSame([(int)$onItsWay->id], $this->templatesRefreshed($run));
    }

    /**
     * Only a channel that keeps a status post gets a status delivery: a
     * template whose channel was changed to email after its post was made
     * gets none, which its channel could not send.
     */
    public function testOnlyAChannelThatKeepsAPostGetsARefresh(): void
    {
        $run = $this->newRun();
        $email = $this->createNotificationTemplate((int)$this->admin->id, NotificationTemplate::CHANNEL_EMAIL, 'project.sync_failed');
        $this->postOf($email, $run);
        $unknown = $this->createNotificationTemplate((int)$this->admin->id, 'no-such-channel', 'project.sync_failed', '{}');
        $this->postOf($unknown, $run);

        $this->dispatcher()->refreshStatus((int)$run->id);

        $this->assertSame([], $this->templatesRefreshed($run));
        $this->assertSame([], $this->queue->pushes);
    }

    /**
     * The identity and the workflow filter still decide: a template whose
     * identity may not see the run's workflow, or whose filter no longer
     * takes the run, gets no refresh, although its post exists.
     */
    public function testTheIdentityAndTheWorkflowFilterStillDecide(): void
    {
        $scope = $this->teamScope();
        $foreign = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['foreign']->id);
        $run = $this->createWorkflowJob((int)$foreign->id, (int)$scope['admin']->id);
        $members = $this->threadedTemplate($scope['member']);
        $this->postOf($members, $run);
        $filtered = $this->threadedTemplate($this->admin);
        $filtered->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $filtered->save(false);
        $this->postOf($filtered, $run);
        $selecting = $this->threadedTemplate($this->admin);
        $selecting->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $selecting->save(false);
        $this->assertSame([], $selecting->setWorkflowTemplateIds([(int)$foreign->id], (int)$this->admin->id));
        $this->postOf($selecting, $run);

        $this->dispatcher()->refreshStatus((int)$run->id);

        $this->assertSame([(int)$selecting->id], $this->templatesRefreshed($run));
    }

    /**
     * An error before any template is judged (here the templates cannot be
     * read) is logged (NOT_QUEUED: class, SQLSTATE and driver code, never
     * the statement) and never reaches the caller.
     */
    public function testARefreshNeverThrowsWhenTheTemplatesCannotBeRead(): void
    {
        $dispatcher = new class () extends NotificationDispatcher {
            protected function postHolders(int $workflowJobId): array
            {
                throw new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\nThe SQL being executed was: SELECT 'marker-4721'",
                    ['HY000', 2006, 'MySQL server has gone away']
                );
            }
        };

        $logs = $this->logsDuring(fn () => $dispatcher->refreshStatus((int)$this->newRun()->id));

        $this->assertSame(
            [sprintf(NotificationDispatcher::NOT_QUEUED, NotificationDelivery::EVENT_STATUS_REFRESH, 'yii\db\Exception', 'HY000', '2006')],
            $this->dispatcherErrors($logs)
        );
        $this->assertStringNotContainsString('marker-4721', (string)json_encode($logs));
        $this->assertSame([], $this->queue->pushes);
    }

    /**
     * A template whose refresh cannot be written is logged
     * (TEMPLATE_NOT_QUEUED, without the statement); the next template still
     * gets its refresh.
     */
    public function testATemplateWhoseRefreshCannotBeWrittenLeavesTheOthersTheirs(): void
    {
        $run = $this->newRun();
        $first = $this->threadedTemplate($this->admin);
        $this->postOf($first, $run);
        $second = $this->threadedTemplate($this->admin);
        $this->postOf($second, $run);
        $failOnce = static function (): void {
            static $failed = false;
            if (!$failed) {
                $failed = true;
                throw new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 1030 Got error 28\nThe SQL being executed was: INSERT 'marker-4722'",
                    ['HY000', 1030, 'Got error 28']
                );
            }
        };
        Event::on(NotificationDelivery::class, ActiveRecord::EVENT_BEFORE_INSERT, $failOnce);
        try {
            $logs = $this->logsDuring(fn () => $this->dispatcher()->refreshStatus((int)$run->id));
        } finally {
            Event::off(NotificationDelivery::class, ActiveRecord::EVENT_BEFORE_INSERT, $failOnce);
        }

        $this->assertSame([(int)$second->id], $this->templatesRefreshed($run));
        $this->assertSame(
            [sprintf(NotificationDispatcher::TEMPLATE_NOT_QUEUED, NotificationDelivery::EVENT_STATUS_REFRESH, (int)$first->id, 'yii\db\Exception', 'HY000', '1030')],
            $this->dispatcherErrors($logs)
        );
        $this->assertStringNotContainsString('marker-4722', (string)json_encode($logs));
    }

    // -- Helpers ------------------------------------------------------------------------

    private function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }

    /** A running run of a workflow without steps, launched by the admin. */
    private function newRun(): WorkflowJob
    {
        return $this->createWorkflowJob((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, (int)$this->admin->id);
    }

    /** A template of THREADED that delivers as $identity and listens to an event no run sends. */
    private function threadedTemplate(User $identity): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$identity->id, self::THREADED, 'project.sync_failed', '{}');
    }

    /** $template's status post of $run. */
    private function postOf(NotificationTemplate $template, WorkflowJob $run): void
    {
        $thread = new NotificationThread();
        $thread->notification_template_id = (int)$template->id;
        $thread->workflow_job_id = (int)$run->id;
        $thread->root_post_id = 'post-' . $template->id;
        $thread->channel_id = 'channel-1';
        $thread->server = 'https://chat.example.com';
        $this->assertTrue($thread->save(false));
    }

    /** A delivery of $run to $template of $kind in $status. */
    private function deliveryOf(NotificationTemplate $template, WorkflowJob $run, string $kind, string $status): NotificationDelivery
    {
        return $this->delivery([
            'workflow_job_id' => (int)$run->id,
            'kind' => $kind,
            'status' => $status,
            'event' => NotificationTemplate::EVENT_WORKFLOW_LAUNCHED,
            'next_attempt_at' => time() + 600,
        ], $template);
    }

    /**
     * The status deliveries of $run that refreshStatus() wrote.
     *
     * @return list<NotificationDelivery>
     */
    private function refreshesOf(WorkflowJob $run): array
    {
        /** @var list<NotificationDelivery> $rows */
        $rows = NotificationDelivery::find()
            ->where(['workflow_job_id' => $run->id, 'kind' => NotificationDelivery::KIND_STATUS, 'event' => NotificationDelivery::EVENT_STATUS_REFRESH])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $rows;
    }

    /**
     * The templates that got a refresh of $run, in order.
     *
     * @return list<int>
     */
    private function templatesRefreshed(WorkflowJob $run): array
    {
        return array_map(static fn (NotificationDelivery $d): int => (int)$d->notification_template_id, $this->refreshesOf($run));
    }

    /**
     * @param list<array{0: int, 1: string, 2: string}> $logs
     * @return list<string>
     */
    private function dispatcherErrors(array $logs): array
    {
        $lines = [];
        foreach ($logs as [$level, $category, $text]) {
            if ($level === Logger::LEVEL_ERROR && $category === NotificationDispatcher::class) {
                $lines[] = $text;
            }
        }

        return $lines;
    }
}
