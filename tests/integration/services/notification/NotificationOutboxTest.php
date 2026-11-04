<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\notification\NotificationOutbox;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\db\Connection;

/**
 * The outbox (NotificationOutbox): one pending delivery per template and
 * event, due now, with the event's variables as they were when it happened
 * and its refs; and the throttle of webhook.invalid_token, at most one
 * delivery per template per 300 seconds, decided under the template's row
 * lock so that concurrent calls do not both get one.
 */
class NotificationOutboxTest extends DbTestCase
{
    use TeamScopeFixtures;

    /** The connection of a test that commits rows of its own; null for any other test. */
    private ?Connection $other = null;

    /** @var array<string, int> the rows that $other committed, table => ID, in the order to delete them */
    private array $committed = [];

    public function testADeliveryIsPendingAndDueNowWithTheEventsVariablesAndRefs(): void
    {
        $template = $this->template();
        $runId = $this->runId();
        $before = time();

        $delivery = NotificationOutbox::enqueue(
            $template,
            NotificationTemplate::EVENT_JOB_FAILED,
            ['job.id' => '7', 'event' => 'job.failed'],
            ['workflow_job_id' => $runId, 'job_id' => 7]
        );

        $stored = NotificationDelivery::findOne($delivery->id);
        $this->assertInstanceOf(NotificationDelivery::class, $stored);
        $this->assertSame((int)$template->id, (int)$stored->notification_template_id);
        $this->assertSame(NotificationTemplate::EVENT_JOB_FAILED, $stored->event);
        $this->assertSame(NotificationDelivery::KIND_MESSAGE, $stored->kind);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $stored->status);
        $this->assertSame(0, (int)$stored->attempts);
        $this->assertGreaterThanOrEqual($before, (int)$stored->next_attempt_at);
        $this->assertLessThanOrEqual(time(), (int)$stored->next_attempt_at);
        $this->assertNull($stored->claimed_at);
        $this->assertSame(['job.id' => '7', 'event' => 'job.failed'], $stored->getVariableMap());
        $this->assertSame(['workflow_job_id' => $runId, 'job_id' => 7], $stored->getRefs());
    }

    public function testADeliveryOfAnotherKindKeepsIt(): void
    {
        $delivery = NotificationOutbox::enqueue($this->template(), NotificationTemplate::EVENT_WORKFLOW_FAILED, [], ['workflow_job_id' => $this->runId()], NotificationDelivery::KIND_STATUS);

        $this->assertSame(NotificationDelivery::KIND_STATUS, $this->reload($delivery)->kind);
        $this->assertSame([], $this->reload($delivery)->getVariableMap());
    }

    /**
     * A row the database did not take is an error the dispatcher logs; no
     * delivery without an ID is handed on.
     */
    public function testADeliveryTheDatabaseDidNotTakeIsAnError(): void
    {
        $refuse = static function (\yii\base\ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(NotificationDelivery::class, ActiveRecord::EVENT_BEFORE_INSERT, $refuse);
        try {
            NotificationOutbox::enqueue($this->template(), NotificationTemplate::EVENT_JOB_FAILED, [], []);
            $this->fail('A delivery that was not written is an error.');
        } catch (\RuntimeException $e) {
            $this->assertSame(NotificationOutbox::NOT_WRITTEN, $e->getMessage());
        } finally {
            Event::off(NotificationDelivery::class, ActiveRecord::EVENT_BEFORE_INSERT, $refuse);
        }
    }

    /**
     * The variables fit their column (TEXT, 65535 bytes): a value longer
     * than VALUE_MAX_BYTES is cut (CUT marks it), and when they are still
     * too long together every value is cut shorter; bytes that are no UTF-8
     * become '?', so the JSON can be written. Short values stay as they are.
     */
    public function testTheVariablesAreCutToFitTheirColumn(): void
    {
        $long = str_repeat('ä', 10000);
        $variables = ['event' => 'project.sync_failed', 'project.error' => $long, 'project.name' => "web \xC3\x28 01"];
        for ($i = 0; $i < 20; $i++) {
            $variables['extra.' . $i] = str_repeat('x', 6000);
        }

        $delivery = NotificationOutbox::enqueue($this->template(), NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, $variables, ['project_id' => 1]);

        $stored = $this->reload($delivery);
        $this->assertLessThanOrEqual(NotificationOutbox::VARIABLES_MAX_BYTES, strlen((string)$stored->variables));
        $map = $stored->getVariableMap();
        $this->assertSame(array_keys($variables), array_keys($map), 'every variable is kept');
        $this->assertSame('project.sync_failed', $map['event']);
        $this->assertSame('web ?( 01', $map['project.name']);
        $this->assertStringEndsWith(NotificationOutbox::CUT, $map['project.error']);
        $this->assertStringStartsWith('ääää', $map['project.error']);
        $this->assertTrue(mb_check_encoding($map['project.error'], 'UTF-8'), 'cut between characters');
        $this->assertLessThan(NotificationOutbox::VALUE_MAX_BYTES, strlen($map['project.error']), 'cut shorter to fit');

        $one = NotificationOutbox::snapshot(['project.error' => $long, 'ascii' => str_repeat('x', 9000)]);
        $this->assertSame(NotificationOutbox::VALUE_MAX_BYTES, strlen($one['ascii']), 'a long value is cut to VALUE_MAX_BYTES');
        $this->assertSame(NotificationOutbox::VALUE_MAX_BYTES - 1, strlen($one['project.error']), 'between two-byte characters');
        $this->assertSame(['a' => 'b', 'n' => '7'], NotificationOutbox::snapshot(['a' => 'b', 'n' => '7']));
    }

    /**
     * Regression (spec answer 6): every call with an unknown trigger token
     * delivered webhook.invalid_token to every template that listens, so a
     * caller that sprayed tokens flooded the channels. A template now gets
     * at most one delivery of it per 300 seconds; other templates and other
     * events do not count.
     */
    public function testInvalidTokenEventsAreThrottledPerTemplate(): void
    {
        $event = NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN;
        $seconds = NotificationOutbox::THROTTLE_SECONDS[$event];
        $first = $this->template();
        $second = $this->template();
        $this->assertSame(300, $seconds);

        $sent = NotificationOutbox::enqueueThrottled($first, $event, ['trigger.token_prefix' => 'abc123'], [], $seconds);
        $this->assertInstanceOf(NotificationDelivery::class, $sent);
        $this->assertNull(NotificationOutbox::enqueueThrottled($first, $event, ['trigger.token_prefix' => 'def456'], [], $seconds));
        $this->assertInstanceOf(NotificationDelivery::class, NotificationOutbox::enqueueThrottled($second, $event, [], [], $seconds), 'per template');
        NotificationOutbox::enqueue($first, NotificationTemplate::EVENT_RUNNER_OFFLINE, [], []);
        $this->assertSame(2, (int)NotificationDelivery::find()->where(['notification_template_id' => $first->id])->count());

        NotificationDelivery::updateAll(['created_at' => time() - $seconds + 1], ['id' => $sent->id]);
        $this->assertNull(NotificationOutbox::enqueueThrottled($first, $event, [], [], $seconds), 'still within the 300 seconds');
        NotificationDelivery::updateAll(['created_at' => time() - $seconds], ['id' => $sent->id]);
        $this->assertInstanceOf(NotificationDelivery::class, NotificationOutbox::enqueueThrottled($first, $event, [], [], $seconds), 'the 300 seconds passed');
        $this->assertSame(['abc123'], array_values(array_filter(array_map(
            static fn (NotificationDelivery $d): ?string => $d->getVariableMap()['trigger.token_prefix'] ?? null,
            NotificationDelivery::find()->where(['notification_template_id' => $first->id, 'event' => $event])->all()
        ))), 'the throttled call wrote nothing');
    }

    /**
     * A template deleted meanwhile gets nothing from the throttle: there is
     * no row to lock, and none to write a delivery for.
     */
    public function testTheThrottleWritesNothingForATemplateDeletedMeanwhile(): void
    {
        $template = $this->template();
        NotificationTemplate::deleteAll(['id' => $template->id]);

        $this->assertNull(NotificationOutbox::enqueueThrottled($template, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, [], [], 300));
        $this->assertSame(0, (int)NotificationDelivery::find()->where(['notification_template_id' => $template->id])->count());
        $this->assertNull(NotificationOutbox::recentDeliveryId($template, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, 300));
    }

    /**
     * The throttle decides under the lock of the template's row, so that
     * two calls at the same time cannot both find no delivery and both
     * write one: a call waits for another transaction that holds the row.
     * The template and its user are committed on a connection of their own,
     * which the other transaction runs on; tearDown() deletes them.
     */
    public function testTheThrottleWaitsForTheTemplatesRowLock(): void
    {
        $db = \Yii::$app->db;
        $this->other = new Connection(['dsn' => $db->dsn, 'username' => $db->username, 'password' => $db->password, 'charset' => $db->charset]);
        $template = $this->committedTemplate($this->other);

        // A shared lock: it holds off the throttle's FOR UPDATE, but not the
        // shared lock that the insert's foreign key check takes, so only a
        // throttle that locks the row waits.
        $holder = $this->other->beginTransaction();
        $this->other->createCommand('SELECT [[id]] FROM {{%notification_template}} WHERE [[id]] = :id LOCK IN SHARE MODE', [':id' => $template->id])->queryScalar();
        $previous = $db->createCommand('SELECT @@SESSION.innodb_lock_wait_timeout')->queryScalar();
        $db->createCommand('SET SESSION innodb_lock_wait_timeout = 1')->execute();
        try {
            NotificationOutbox::enqueueThrottled($template, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, [], [], 300);
            $this->fail('The throttle waits for the lock of the template\'s row.');
        } catch (\yii\db\Exception $e) {
            $this->assertSame(1205, (int)($e->errorInfo[1] ?? 0), 'a lock wait timeout');
        } finally {
            $db->createCommand('SET SESSION innodb_lock_wait_timeout = ' . (int)$previous)->execute();
            $holder->rollBack();
        }
        $this->assertSame(0, (int)NotificationDelivery::find()->where(['notification_template_id' => $template->id])->count());
    }

    /**
     * Deletes what testTheThrottleWaitsForTheTemplatesRowLock() committed,
     * after the test's transaction was rolled back, so that no row the test
     * wrote holds the delete up.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->other === null) {
            return;
        }
        $this->other->createCommand('SET SESSION innodb_lock_wait_timeout = 5')->execute();
        foreach ($this->committed as $table => $id) {
            $this->other->createCommand()->delete($table, ['id' => $id])->execute();
        }
        $this->other->close();
        $this->other = null;
        $this->committed = [];
    }

    // -- Helpers ----------------------------------------------------------------

    private function template(): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$this->createUserWithRole('outbox-admin', 'admin')->id);
    }

    /**
     * An email template listening to webhook.invalid_token, with the active
     * user it delivers as, both committed through $connection.
     */
    private function committedTemplate(Connection $connection): NotificationTemplate
    {
        $suffix = bin2hex(random_bytes(4));
        $connection->createCommand()->insert('{{%user}}', [
            'username' => 'outbox-lock-' . $suffix,
            'email' => 'outbox-lock-' . $suffix . '@example.com',
            'password_hash' => 'x',
            'auth_key' => 'outbox-lock-' . $suffix,
            'status' => User::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();
        $userId = (int)$connection->getLastInsertID();
        $this->committed = ['{{%user}}' => $userId];
        $connection->createCommand()->insert('{{%notification_template}}', [
            'name' => 'outbox-lock-' . $suffix,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{"emails":["lock@example.com"]}',
            'events' => NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN,
            'created_by' => $userId,
            'delivers_as' => $userId,
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();
        $templateId = (int)$connection->getLastInsertID();
        $this->committed = ['{{%notification_template}}' => $templateId] + $this->committed;
        $template = NotificationTemplate::findOne($templateId);
        $this->assertInstanceOf(NotificationTemplate::class, $template);

        return $template;
    }

    private function runId(): int
    {
        $admin = $this->createUserWithRole('outbox-run', 'admin');

        return (int)$this->createWorkflowJob((int)$this->createWorkflowTemplate((int)$admin->id)->id, (int)$admin->id)->id;
    }

    private function reload(NotificationDelivery $delivery): NotificationDelivery
    {
        $row = NotificationDelivery::findOne($delivery->id);
        $this->assertInstanceOf(NotificationDelivery::class, $row);

        return $row;
    }
}
