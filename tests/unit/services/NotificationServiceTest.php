<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\models\JobTemplate;
use app\services\NotificationService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for NotificationService guard clauses and dispatch logic.
 * No actual mail is sent — the sendJobMail method is stubbed.
 */
class NotificationServiceTest extends TestCase
{
    // ── notifyJobFailed ──────────────────────────────────────────────────────

    public function testNoNotificationWhenTemplateIsNull(): void
    {
        $service = $this->guardService();
        $job     = $this->makeJob(template: null);
        $service->notifyJobFailed($job);
        $this->assertTrue(true);
    }

    public function testNoNotificationWhenNotifyOnFailureIsFalse(): void
    {
        $service = $this->guardService();
        $job     = $this->makeJob(notifyOnFailure: false, emails: 'ops@example.com');
        $service->notifyJobFailed($job);
        $this->assertTrue(true);
    }

    public function testNoNotificationWhenEmailListIsEmpty(): void
    {
        $service = $this->guardService();
        $job     = $this->makeJob(notifyOnFailure: true, emails: '');
        $service->notifyJobFailed($job);
        $this->assertTrue(true);
    }

    public function testFailureMailSentWhenConditionsMet(): void
    {
        $calls = [];
        $service = $this->capturingService($calls);
        $job = $this->makeJob(notifyOnFailure: true, emails: 'ops@example.com');

        $service->notifyJobFailed($job);

        $this->assertCount(1, $calls);
        $this->assertStringContainsString('job-failed', $calls[0]['template']);
    }

    public function testFailureMailExceptionIsCaught(): void
    {
        $service = $this->throwingService();
        $job = $this->makeJob(notifyOnFailure: true, emails: 'ops@example.com');
        $service->notifyJobFailed($job);
        $this->assertTrue(true);
    }

    // ── notifyJobSucceeded ───────────────────────────────────────────────────

    public function testNoSuccessNotificationWhenTemplateIsNull(): void
    {
        $service = $this->guardService();
        $job     = $this->makeJob(template: null);
        $service->notifyJobSucceeded($job);
        $this->assertTrue(true);
    }

    public function testNoSuccessNotificationWhenNotifyOnSuccessIsFalse(): void
    {
        $service = $this->guardService();
        $job     = $this->makeJob(notifyOnSuccess: false, emails: 'ops@example.com');
        $service->notifyJobSucceeded($job);
        $this->assertTrue(true);
    }

    public function testNoSuccessNotificationWhenEmailListIsEmpty(): void
    {
        $service = $this->guardService();
        $job     = $this->makeJob(notifyOnSuccess: true, emails: '');
        $service->notifyJobSucceeded($job);
        $this->assertTrue(true);
    }

    public function testSuccessMailSentWhenConditionsMet(): void
    {
        $calls = [];
        $service = $this->capturingService($calls);
        $job = $this->makeJob(notifyOnSuccess: true, emails: 'ops@example.com');

        $service->notifyJobSucceeded($job);

        $this->assertCount(1, $calls);
        $this->assertStringContainsString('job-succeeded', $calls[0]['template']);
    }

    public function testSuccessMailExceptionIsCaught(): void
    {
        $service = $this->throwingService();
        $job = $this->makeJob(notifyOnSuccess: true, emails: 'ops@example.com');
        $service->notifyJobSucceeded($job);
        $this->assertTrue(true);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Service that throws if sendJobMail is reached — for guard clause tests. */
    private function guardService(): NotificationService
    {
        return new class extends NotificationService {
            protected function sendJobMail(Job $job, array $recipients, string $subject, string $template): void
            {
                throw new \LogicException('sendJobMail should not be called in this test');
            }
        };
    }

    /** Service that captures sendJobMail calls for verification. */
    private function capturingService(array &$calls): NotificationService
    {
        return new class ($calls) extends NotificationService {
            public function __construct(private array &$calls)
            {
            }

            protected function sendJobMail(Job $job, array $recipients, string $subject, string $template): void
            {
                $this->calls[] = ['recipients' => $recipients, 'subject' => $subject, 'template' => $template];
            }
        };
    }

    /** Service that throws from sendJobMail — for exception handling tests. */
    private function throwingService(): NotificationService
    {
        return new class extends NotificationService {
            protected function sendJobMail(Job $job, array $recipients, string $subject, string $template): void
            {
                throw new \RuntimeException('mail server down');
            }
        };
    }

    private function makeJob(
        ?JobTemplate $template = null,
        bool $notifyOnFailure = false,
        bool $notifyOnSuccess = false,
        string $emails = '',
    ): Job {
        $hasTemplate = ($template !== null || $notifyOnFailure || $notifyOnSuccess || $emails !== '');

        $tpl = null;
        if ($hasTemplate) {
            $tpl = $this->makeTemplate($notifyOnFailure, $notifyOnSuccess, $emails);
        }

        $job = $this->getMockBuilder(Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $job->method('attributes')->willReturn(['id', 'status', 'exit_code', 'job_template_id', 'launched_by',
                                                 'started_at', 'finished_at', 'created_at', 'updated_at']);
        $job->method('save')->willReturn(true);

        $attrRef = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $attrRef->setAccessible(true);
        $attrRef->setValue($job, ['id' => 1, 'status' => 'failed', 'exit_code' => 1,
                                   'job_template_id' => $tpl ? 1 : null, 'launched_by' => 1,
                                   'started_at' => null, 'finished_at' => null]);

        $relRef = new \ReflectionProperty(BaseActiveRecord::class, '_related');
        $relRef->setAccessible(true);
        $relRef->setValue($job, ['jobTemplate' => $tpl]);

        return $job;
    }

    private function makeTemplate(bool $notifyOnFailure, bool $notifyOnSuccess, string $emails): JobTemplate
    {
        $tpl = $this->getMockBuilder(JobTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save', 'getNotifyEmailList'])
            ->getMock();
        $tpl->method('attributes')->willReturn(
            ['id', 'name', 'notify_on_failure', 'notify_on_success', 'notify_emails', 'playbook']
        );
        $tpl->method('save')->willReturn(true);
        $tpl->method('getNotifyEmailList')->willReturn(
            $emails ? array_map('trim', explode(',', $emails)) : []
        );

        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($tpl, [
            'id'                => 1,
            'name'              => 'Test Template',
            'notify_on_failure' => $notifyOnFailure ? 1 : 0,
            'notify_on_success' => $notifyOnSuccess ? 1 : 0,
            'notify_emails'     => $emails ?: null,
            'playbook'          => 'site.yml',
        ]);
        return $tpl;
    }
}
