<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\WorkerController;
use app\components\AllowlistQueueSerializer;
use app\components\AuthManager;
use app\components\ExceptionLogText;
use app\components\WorkerHeartbeat;
use app\jobs\DeliverNotificationJob;
use app\jobs\SyncProjectJob;
use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\Team;
use app\models\TeamProject;
use app\models\User;
use app\services\audit\AuditTargetInterface;
use app\services\AuditService;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationDeliveryService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\notification\RecordingNotificationQueue;
use app\tests\integration\services\notification\ScriptedChannel;
use PHPUnit\Framework\TestCase;
use yii\base\Event;
use yii\db\Connection;
use yii\queue\cli\SignalLoop;
use yii\queue\ExecEvent;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\queue\redis\Queue as RedisQueue;

/**
 * The queue workers decide each queue job with the roles that the users
 * hold when it runs: the queue-worker (worker/start), whose project syncs
 * dispatch the project.sync_* notifications, and the notification-worker
 * (worker/start --queue=notificationQueue), which checks again before it
 * sends a delivery whether the user its template delivers as may still
 * see the event. Their events (WorkerController::attachWorkerEvents())
 * forget the role assignments that the authManager read, before each job.
 *
 * Regression (review of the workflow notifications, access finding 2 and
 * delivery finding 1): yii\rbac\DbManager keeps the role assignments of
 * each user it checked for the life of the process, and only an assign()
 * or revoke() in the same process forgets them. A role that the web UI
 * took from a user or gave them reached neither worker until it
 * restarted, weeks later: the template of an admin whom a superadmin made
 * an operator of one team still got the sync failures of every team's
 * projects, its pending deliveries were still sent, and the deliveries of
 * a user who was made an admin were skipped as "may no longer see the
 * event", for good.
 *
 * Each case runs its jobs through yii2-queue's worker loop (run()), as
 * worker/start runs them, on a Redis channel of its own, with the worker's
 * events, in one process, as a worker does; the roles change between two
 * jobs through an authManager of its own on a connection of its own, as
 * the web UI changes them. The worker closes the database connection
 * before each job, so the fixtures cannot live in the transaction of a
 * DbTestCase: they are written for real, and tearDown() deletes them. The
 * audit entries of the deliveries are kept in memory.
 */
final class WorkerJobRoleAssignmentsTest extends TestCase
{
    use RestoresComponents;

    /** The event of the tests: a project sync that failed, of a project of a team that neither user is in. */
    private const EVENT = NotificationTemplate::EVENT_PROJECT_SYNC_FAILED;

    /** The authManager of the web UI: another one than the worker's, on a connection of its own. */
    private AuthManager $web;

    private ?Connection $webDb = null;

    /** The admin whom the test makes an operator. */
    private User $demoted;

    /** The operator whom the test makes an admin. */
    private User $promoted;

    /** A git project without a repository URL, of a team that neither user is in: its sync fails. */
    private Project $restricted;

    /** The template of each user, by the user's role before the change: it delivers as them. */
    private NotificationTemplate $demotedTemplate;

    private NotificationTemplate $promotedTemplate;

    private ScriptedChannel $channel;

    /** @var list<array<string, mixed>> the audit entries written, in order */
    private array $auditEntries = [];

    /** @var array<string, RedisQueue> the queues of the test, by the component ID a worker runs them as */
    private array $queues = [];

    /** @var list<\Throwable> the errors of the jobs that failed */
    private array $errors = [];

    /** @var array{users: list<int>, teams: list<int>, projects: list<int>, templates: list<int>} the rows the test wrote, which tearDown() deletes */
    private array $written = ['users' => [], 'teams' => [], 'projects' => [], 'templates' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new ScriptedChannel();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $this->channel);
        $this->swapComponent('notificationDispatcher', $dispatcher);
        $this->swapComponent('notificationDeliveryService', ['class' => NotificationDeliveryService::class]);
        $this->swapComponent('auditService', new AuditService(['targets' => [$this->auditTarget()]]));
        Event::offAll();
        $this->assertNull(\Yii::$app->getDb()->getTransaction(), 'the fixtures are written for real, outside any transaction');
        // The worker's authManager, made before the test writes anything.
        $this->assertInstanceOf(AuthManager::class, \Yii::$app->getAuthManager());

        $this->webDb = self::connection();
        $this->web = new AuthManager(['db' => $this->webDb]);
        $this->demoted = $this->user('worker-roles-demoted', 'admin');
        $this->promoted = $this->user('worker-roles-promoted', 'operator');
        $this->restricted = $this->restrictedProject();
        $this->demotedTemplate = $this->templateOf($this->demoted);
        $this->promotedTemplate = $this->templateOf($this->promoted);

        $app = \Yii::$app;
        $this->assertNotNull($app);
        (new WorkerController('worker', $app))->attachWorkerEvents(self::silentHeartbeat());
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        Event::offAll();
        foreach ($this->queues as $queue) {
            $queue->clear();
        }
        $this->deleteWritten();
        $this->webDb?->close();
        parent::tearDown();
    }

    /**
     * Regression: the queue-worker's project sync dispatched
     * project.sync_failed of a project of another team for the template of
     * an admin who had been made an operator, and none for the template of
     * an operator who had been made an admin. Now the sync after the change
     * decides with the roles as they are then.
     */
    public function testRegressionTheQueueWorkerDispatchesWithTheRolesAsTheyAreWhenTheJobRuns(): void
    {
        $this->swapComponent('notificationQueue', new RecordingNotificationQueue());

        $this->runJob('queue', new SyncProjectJob(['projectId' => (int)$this->restricted->id]));
        $this->assertSame([1, 0], $this->deliveryCounts(), 'before the change: the admin\'s template, not the operator\'s');

        $this->changeTheRoles();
        Project::updateAll(['last_sync_event' => null], ['id' => $this->restricted->id]);
        $this->runJob('queue', new SyncProjectJob(['projectId' => (int)$this->restricted->id]));

        $this->assertSame([], $this->errorTexts());
        $this->assertSame(Project::STATUS_ERROR, Project::findOne($this->restricted->id)?->status, 'the sync failed, and dispatched its failure');
        $this->assertSame([1, 1], $this->deliveryCounts(), 'after the change: the new admin\'s template, not the former admin\'s');
    }

    /**
     * Regression: the notification-worker sent a delivery of a project of
     * another team for the template of an admin who had been made an
     * operator, and skipped one for the template of an operator who had
     * been made an admin, with "may no longer see the event". Now the
     * delivery after the change is checked with the roles as they are
     * then.
     */
    public function testRegressionTheNotificationWorkerChecksADeliveryWithTheRolesAsTheyAreWhenTheJobRuns(): void
    {
        $before = [$this->delivery($this->demotedTemplate), $this->delivery($this->promotedTemplate)];
        $this->runJobs('notificationQueue', $before);
        $this->assertSame([NotificationDelivery::STATUS_DELIVERED, NotificationDelivery::STATUS_SKIPPED], $this->statusesOf($before), 'before the change');

        $this->changeTheRoles();
        $after = [$this->delivery($this->demotedTemplate), $this->delivery($this->promotedTemplate)];
        $this->runJobs('notificationQueue', $after);

        $this->assertSame([], $this->errorTexts());
        $this->assertSame([NotificationDelivery::STATUS_SKIPPED, NotificationDelivery::STATUS_DELIVERED], $this->statusesOf($after), 'after the change');
        $this->assertSame(sprintf(NotificationDeliveryService::NOT_VISIBLE, (int)$this->demoted->id), NotificationDelivery::findOne($after[0]->id)?->last_error);
        $this->assertSame(
            [(int)$this->demotedTemplate->id, (int)$this->promotedTemplate->id],
            array_column($this->channel->calls, 'template'),
            'sent: the first delivery of the admin before, the delivery of the new admin after the change'
        );
        $this->assertSame(
            [AuditLog::ACTION_NOTIFICATION_DISPATCHED, AuditLog::ACTION_NOTIFICATION_SKIPPED, AuditLog::ACTION_NOTIFICATION_SKIPPED, AuditLog::ACTION_NOTIFICATION_DISPATCHED],
            array_column($this->auditEntries, 'action'),
            'one entry per outcome'
        );
    }

    /**
     * As the web UI does: the admin becomes an operator, the operator an
     * admin, through the web's authManager. The worker's own authManager
     * is not told.
     */
    private function changeTheRoles(): void
    {
        $this->setRole($this->demoted, 'operator');
        $this->setRole($this->promoted, 'admin');
    }

    /**
     * Pushes $job to the queue that a worker started with --queue=$id runs,
     * and runs the worker loop until that queue is empty again.
     */
    private function runJob(string $id, JobInterface $job): void
    {
        $queue = $this->queue($id);
        $queue->push($job);
        $queue->run(false);
    }

    /**
     * Pushes a job of the notification queue for each of $deliveries, in
     * order, to the queue that a worker started with --queue=$id runs, and
     * runs the worker loop until that queue is empty again.
     *
     * @param list<NotificationDelivery> $deliveries
     */
    private function runJobs(string $id, array $deliveries): void
    {
        $queue = $this->queue($id);
        foreach ($deliveries as $delivery) {
            $queue->push(new DeliverNotificationJob(['deliveryId' => (int)$delivery->id]));
        }
        $queue->run(false);
    }

    /**
     * The queue that a worker started with --queue=$id runs, which the
     * component $id is while the test runs: on a Redis channel of its own.
     */
    private function queue(string $id): RedisQueue
    {
        if (isset($this->queues[$id])) {
            return $this->queues[$id];
        }
        $queue = new RedisQueue([
            'redis' => \Yii::$app->get('redis'),
            'channel' => 'ansilume-test-worker-roles-' . bin2hex(random_bytes(6)),
            'serializer' => AllowlistQueueSerializer::class,
            // SignalLoop without exit signals: no signal handlers in the test process.
            'loopConfig' => ['class' => SignalLoop::class, 'exitSignals' => []],
        ]);
        $queue->on(Queue::EVENT_AFTER_ERROR, function (ExecEvent $event): void {
            $this->errors[] = $event->error ?? new \LogicException('An error event without its error.');
        });
        $this->queues[$id] = $queue;
        $this->swapComponent($id, $queue);

        $app = \Yii::$app;
        $this->assertNotNull($app);
        $this->assertSame($queue, (new WorkerController('worker', $app, ['queue' => $id]))->queueComponent(), "worker/start --queue={$id} runs it");

        return $queue;
    }

    /**
     * How many deliveries of EVENT the template of the demoted user and the
     * template of the promoted user have.
     *
     * @return array{0: int, 1: int}
     */
    private function deliveryCounts(): array
    {
        $count = static fn (NotificationTemplate $template): int => (int)NotificationDelivery::find()
            ->where(['notification_template_id' => $template->id, 'event' => self::EVENT])
            ->count();

        return [$count($this->demotedTemplate), $count($this->promotedTemplate)];
    }

    /**
     * The statuses of $deliveries, as stored now.
     *
     * @param list<NotificationDelivery> $deliveries
     * @return list<string>
     */
    private function statusesOf(array $deliveries): array
    {
        return array_map(static fn (NotificationDelivery $delivery): string => (string)NotificationDelivery::findOne($delivery->id)?->status, $deliveries);
    }

    /**
     * A user with $role only, given through the web's authManager.
     */
    private function user(string $name, string $role): User
    {
        $user = new User();
        $user->username = $name . '-' . bin2hex(random_bytes(6));
        $user->email = $user->username . '@example.com';
        $user->password_hash = \Yii::$app->security->generatePasswordHash('test');
        $user->auth_key = \Yii::$app->security->generateRandomString();
        $user->status = User::STATUS_ACTIVE;
        $user->created_at = time();
        $user->updated_at = time();
        $this->assertTrue($user->save(false));
        $this->written['users'][] = (int)$user->id;
        $this->setRole($user, $role);

        return $user;
    }

    /**
     * $user holds $role only from now on, as a save of the Edit User form
     * leaves it.
     */
    private function setRole(User $user, string $role): void
    {
        $item = $this->web->getRole($role);
        $this->assertNotNull($item, $role);
        $this->web->revokeAll((string)$user->id);
        $this->web->assign($item, (string)$user->id);
    }

    /**
     * A git project without a repository URL, whose sync fails, of a team
     * of its own that neither user is in: only an admin may see it.
     */
    private function restrictedProject(): Project
    {
        $project = new Project();
        $project->name = 'worker-roles-' . bin2hex(random_bytes(6));
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_branch = 'main';
        $project->status = Project::STATUS_NEW;
        $project->created_by = (int)$this->demoted->id;
        $project->created_at = time();
        $project->updated_at = time();
        $this->assertTrue($project->save(false));
        $this->written['projects'][] = (int)$project->id;

        $team = new Team();
        $team->name = 'worker-roles-' . bin2hex(random_bytes(6));
        $team->created_by = (int)$this->demoted->id;
        $team->created_at = time();
        $team->updated_at = time();
        $this->assertTrue($team->save(false));
        $this->written['teams'][] = (int)$team->id;
        $teamProject = new TeamProject();
        $teamProject->team_id = (int)$team->id;
        $teamProject->project_id = (int)$project->id;
        $teamProject->role = TeamProject::ROLE_OPERATOR;
        $teamProject->created_at = time();
        $this->assertTrue($teamProject->save(false));

        return $project;
    }

    /** An email template of EVENT that delivers as $user, its creator. */
    private function templateOf(User $user): NotificationTemplate
    {
        $template = new NotificationTemplate();
        $template->name = 'worker-roles-' . bin2hex(random_bytes(6));
        $template->channel = NotificationTemplate::CHANNEL_EMAIL;
        $template->config = '{"emails":["ops@example.com"]}';
        $template->subject_template = 'Sync of {{ project.name }} failed';
        $template->body_template = '{{ project.error }}';
        $template->events = self::EVENT;
        $template->created_by = (int)$user->id;
        $template->delivers_as = (int)$user->id;
        $template->created_at = time();
        $template->updated_at = time();
        $this->assertTrue($template->save(false));
        $this->written['templates'][] = (int)$template->id;

        return $template;
    }

    /** A pending message of EVENT about the restricted project for $template, due now, as dispatch() writes one. */
    private function delivery(NotificationTemplate $template): NotificationDelivery
    {
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = self::EVENT;
        $delivery->kind = NotificationDelivery::KIND_MESSAGE;
        $delivery->status = NotificationDelivery::STATUS_PENDING;
        $delivery->project_id = (int)$this->restricted->id;
        $delivery->setVariableMap(['project.name' => (string)$this->restricted->name, 'project.error' => 'This git project has no repository URL.']);
        $delivery->next_attempt_at = time();
        $this->assertTrue($delivery->save(false));

        return $delivery;
    }

    /**
     * Deletes every row the test wrote: the deliveries of its templates and
     * of its project (dispatch() writes them for every template that may
     * see the project), the templates, the team, the project, the role
     * assignments and the users.
     */
    private function deleteWritten(): void
    {
        $written = $this->written;
        NotificationDelivery::deleteAll(['or', ['notification_template_id' => $written['templates']], ['project_id' => $written['projects']]]);
        NotificationTemplate::deleteAll(['id' => $written['templates']]);
        TeamProject::deleteAll(['team_id' => $written['teams']]);
        Team::deleteAll(['id' => $written['teams']]);
        Project::deleteAll(['id' => $written['projects']]);
        foreach ($written['users'] as $userId) {
            $this->web->revokeAll((string)$userId);
        }
        User::deleteAll(['id' => $written['users']]);
        $this->written = ['users' => [], 'teams' => [], 'projects' => [], 'templates' => []];
    }

    /**
     * The errors of the jobs that failed, as the queue's log names them.
     *
     * @return list<string>
     */
    private function errorTexts(): array
    {
        return array_map(static fn (\Throwable $e): string => ExceptionLogText::brief($e), $this->errors);
    }

    /** An audit target that keeps the entries in memory (auditEntries). */
    private function auditTarget(): AuditTargetInterface
    {
        $keep = function (array $entry): void {
            $this->auditEntries[] = $entry;
        };

        return new class ($keep) implements AuditTargetInterface {
            /** @param \Closure(array<string, mixed>): void $keep */
            public function __construct(private readonly \Closure $keep)
            {
            }

            public function send(array $entry): void
            {
                ($this->keep)($entry);
            }
        };
    }

    /**
     * A new connection to the test database, as config/test.php configures it.
     */
    private static function connection(): Connection
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/config/db-test.php';
        unset($config['class']);

        return new Connection($config);
    }

    /**
     * The worker's heartbeat, but one that writes nothing: the test process
     * is no worker the stack should list.
     */
    private static function silentHeartbeat(): WorkerHeartbeat
    {
        return new class () extends WorkerHeartbeat {
            public function markJobProcessed(): void
            {
            }

            public function deregister(): void
            {
            }
        };
    }
}
