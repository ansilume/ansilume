<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\AllowlistQueueSerializer;
use app\jobs\DeliverNotificationJob;
use app\jobs\LintProjectJob;
use app\jobs\LintTemplateJob;
use app\jobs\SyncProjectJob;
use PHPUnit\Framework\TestCase;
use yii\queue\InvalidJobException;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\queue\serializers\PhpSerializer;
use yii\queue\sync\Queue as SyncQueue;

/**
 * Regression: the queue used the stock PhpSerializer, i.e. unserialize() with
 * no class restriction. Redis has no password by default and was reachable
 * from bundled runners, so a playbook could push a crafted message and make
 * the queue-worker instantiate arbitrary classes or run any loadable job.
 */
class AllowlistQueueSerializerTest extends TestCase
{
    protected function setUp(): void
    {
        QueueInjectionProbe::reset();
    }

    private function queueWith(PhpSerializer $serializer): SyncQueue
    {
        return new SyncQueue(['serializer' => $serializer]);
    }

    private function armedProbePayload(): string
    {
        $probe = new QueueInjectionProbe();
        $probe->payload = 'armed';
        $serialized = serialize($probe);
        $probe->payload = '';
        QueueInjectionProbe::reset();

        return $serialized;
    }

    public function testAllowlistedJobRoundTrips(): void
    {
        $serializer = new AllowlistQueueSerializer();
        $job = new SyncProjectJob(['projectId' => 42]);

        $restored = $serializer->unserialize($serializer->serialize($job));

        $this->assertInstanceOf(SyncProjectJob::class, $restored);
        $this->assertSame(42, $restored->projectId);
    }

    /**
     * The job of the notification queue, which carries the ID of a
     * notification delivery only, is allowed; a message whose ID is no
     * whole number is rejected as an invalid job.
     */
    public function testTheNotificationDeliveryJobRoundTrips(): void
    {
        $serializer = new AllowlistQueueSerializer();

        $restored = $serializer->unserialize($serializer->serialize(new DeliverNotificationJob(['deliveryId' => 42])));

        $this->assertInstanceOf(DeliverNotificationJob::class, $restored);
        $this->assertSame(42, $restored->deliveryId);
        $this->assertSame([SyncProjectJob::class, DeliverNotificationJob::class, LintTemplateJob::class, LintProjectJob::class], AllowlistQueueSerializer::ALLOWED_CLASSES);

        $payload = serialize(new DeliverNotificationJob(['deliveryId' => 1]));
        $this->assertStringContainsString('s:10:"deliveryId";i:1;', $payload);
        [$job, $error] = $this->queueWith($serializer)->unserializeMessage(str_replace('s:10:"deliveryId";i:1;', 's:10:"deliveryId";s:4:"evil";', $payload));
        $this->assertNull($job);
        $this->assertInstanceOf(InvalidJobException::class, $error);
    }

    /**
     * The lint runs of the queue-worker, which carry the ID of a job
     * template or a project and when the run was requested, are allowed; a
     * message whose time is no whole number is rejected as an invalid job.
     */
    public function testTheLintJobsRoundTrip(): void
    {
        $serializer = new AllowlistQueueSerializer();

        $template = $serializer->unserialize($serializer->serialize(new LintTemplateJob(['templateId' => 42, 'requestedAt' => 1700000000])));
        $project = $serializer->unserialize($serializer->serialize(new LintProjectJob(['projectId' => 7, 'requestedAt' => 1700000001])));

        $this->assertInstanceOf(LintTemplateJob::class, $template);
        $this->assertSame([42, 1700000000], [$template->templateId, $template->requestedAt]);
        $this->assertInstanceOf(LintProjectJob::class, $project);
        $this->assertSame([7, 1700000001], [$project->projectId, $project->requestedAt]);

        $payload = serialize(new LintTemplateJob(['templateId' => 1, 'requestedAt' => 2]));
        $this->assertStringContainsString('s:11:"requestedAt";i:2;', $payload);
        [$job, $error] = $this->queueWith($serializer)->unserializeMessage(str_replace('s:11:"requestedAt";i:2;', 's:11:"requestedAt";s:4:"evil";', $payload));
        $this->assertNull($job);
        $this->assertInstanceOf(InvalidJobException::class, $error);
    }

    public function testInjectedJobIsRejectedInsteadOfBeingRunnable(): void
    {
        $payload = $this->armedProbePayload();

        // Before the fix: the stock serializer hands the worker a runnable job.
        [$stockJob, $stockError] = $this->queueWith(new PhpSerializer())->unserializeMessage($payload);
        $this->assertInstanceOf(JobInterface::class, $stockJob);
        $this->assertNull($stockError);
        $stockJob = null;
        QueueInjectionProbe::reset();

        [$job, $error] = $this->queueWith(new AllowlistQueueSerializer())->unserializeMessage($payload);

        $this->assertNull($job);
        $this->assertInstanceOf(InvalidJobException::class, $error);
        $this->assertStringContainsString('not allowed', $error->getMessage());
        $this->assertFalse(QueueInjectionProbe::$woke, '__wakeup of a disallowed class must never run');
        $this->assertFalse(QueueInjectionProbe::$destroyed, '__destruct of a disallowed class must never run');
        $this->assertFalse(QueueInjectionProbe::$executed);
    }

    private function syncJobPayloadWithProjectId(string $serializedValue): string
    {
        $payload = serialize(new SyncProjectJob(['projectId' => 1]));
        $this->assertStringContainsString('s:9:"projectId";i:1;', $payload);

        return str_replace('s:9:"projectId";i:1;', 's:9:"projectId";' . $serializedValue, $payload);
    }

    public function testDisallowedObjectNestedInAnAllowedJobIsNeverInstantiated(): void
    {
        $nested = $this->syncJobPayloadWithProjectId(serialize(new QueueInjectionProbe()));
        QueueInjectionProbe::reset();

        $this->expectException(\UnexpectedValueException::class);
        try {
            (new AllowlistQueueSerializer())->unserialize($nested);
        } finally {
            $this->assertFalse(QueueInjectionProbe::$woke);
        }
    }

    public function testTypeMismatchIsRejectedInsteadOfCrashingTheWorker(): void
    {
        // Queue::unserializeMessage() only catches \Exception; a TypeError from
        // a typed property would otherwise escape and kill the worker loop.
        $payload = $this->syncJobPayloadWithProjectId('s:4:"evil";');

        [$job, $error] = $this->queueWith(new AllowlistQueueSerializer())->unserializeMessage($payload);

        $this->assertNull($job);
        $this->assertInstanceOf(InvalidJobException::class, $error);
    }

    public function testMalformedPayloadIsRejectedWithoutAPhpNotice(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Malformed queue message');

        (new AllowlistQueueSerializer())->unserialize('not-serialized');
    }

    /**
     * Since PHP 8.3, unserialize() warns about bytes after the serialized
     * job, so such a message is malformed and dropped; PHP 8.2 ran the job.
     * The queue never writes one: it stores serialize()'s output.
     */
    public function testBytesAfterTheJobMakeTheMessageMalformed(): void
    {
        $payload = (new AllowlistQueueSerializer())->serialize(new SyncProjectJob(['projectId' => 42])) . 'x';

        [$job, $error] = $this->queueWith(new AllowlistQueueSerializer())->unserializeMessage($payload);

        $this->assertNull($job);
        $this->assertInstanceOf(InvalidJobException::class, $error);
        $this->assertStringStartsWith('Malformed queue message: ', $error->getMessage());
    }

    public function testNonStringPayloadIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new AllowlistQueueSerializer())->unserialize(['not', 'a', 'string']);
    }

    /**
     * Guard: a new job class that is not allowlisted would be dropped by the
     * worker without anyone noticing until production.
     */
    public function testEveryJobClassIsAllowlisted(): void
    {
        $jobs = [];
        foreach (glob(dirname(__DIR__, 3) . '/jobs/*.php') ?: [] as $file) {
            $class = 'app\\jobs\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, JobInterface::class)) {
                $jobs[] = $class;
            }
        }

        $this->assertNotEmpty($jobs);
        foreach ($jobs as $class) {
            $this->assertContains($class, AllowlistQueueSerializer::ALLOWED_CLASSES, "{$class} must be allowlisted");
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function configProvider(): array
    {
        return ['web' => ['web.php'], 'console' => ['console.php'], 'test' => ['test.php']];
    }

    /**
     * Every queue of every application unserializes with the allowlist, the
     * notification queue as the queue of project syncs; both are found, so
     * that the check cannot pass on none.
     *
     * @dataProvider configProvider
     */
    public function testEveryQueueConfigUsesTheAllowlistSerializer(string $file): void
    {
        $config = require dirname(__DIR__, 3) . '/config/' . $file;

        $serializers = [];
        foreach ($config['components'] as $id => $component) {
            $class = is_array($component) ? ltrim((string)($component['class'] ?? ''), '\\') : '';
            if ($class !== '' && is_a($class, Queue::class, true)) {
                $serializers[$id] = $component['serializer'] ?? null;
            }
        }
        ksort($serializers);

        $this->assertSame(['notificationQueue' => AllowlistQueueSerializer::class, 'queue' => AllowlistQueueSerializer::class], $serializers);
    }
}
