<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\NotificationTemplateController;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\notification\MattermostApiException;
use app\services\notification\MattermostConfig;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationTemplatePage;
use app\tests\integration\TeamScopeFixtures;

/**
 * The page of a notification template, rendered with its real view through
 * the controller's access filter (spec answers 3 and 5): whom it delivers
 * as, and a warning when that user is inactive; its workflow filter and its
 * level; the check a Mattermost template keeps, never its fingerprint; and
 * its deliveries that the viewer may see, with a count of the others.
 */
class NotificationTemplateViewTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;

    private const FINGERPRINT_STATE_CHANNEL = 'abcdefghijklmnopqrstuvwxyz';

    public function testThePageSaysWhomTheTemplateDeliversAs(): void
    {
        $owner = $this->createUserWithRole('ntview-owner', 'operator');
        $template = $this->createNotificationTemplate((int)$owner->id);
        $this->loginAs($this->createUserWithRole('ntview-reader', 'viewer'));

        $active = $this->xpath($this->open($template));
        $this->assertSame($owner->username, $this->textOf($active, "//*[@data-testid='notification-template-delivers-as']"));
        $this->assertSame(0, $this->nodeCount($active, "//*[@data-testid='notification-template-inactive-identity']"));

        $owner->status = User::STATUS_INACTIVE;
        $this->assertTrue($owner->save(false));
        $inactive = $this->xpath($this->open($template));
        $this->assertSame($owner->username . ' inactive', $this->textOf($inactive, "//*[@data-testid='notification-template-delivers-as']"));
        $this->assertSame(
            sprintf(NotificationTemplatePage::DELIVERS_AS_INACTIVE, $owner->username),
            $this->textOf($inactive, "//*[@data-testid='notification-template-inactive-identity']")
        );
    }

    /**
     * The filter as the viewer may see it: all workflows; the names of
     * those they may see, linked, with a count of the others; or none.
     */
    public function testThePageShowsTheFilterAsTheViewerMaySeeIt(): void
    {
        $scope = $this->teamScope('viewer');
        $adminId = (int)$scope['admin']->id;
        $own = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $foreign = $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id);
        $template = $this->createNotificationTemplate($adminId);
        $this->loginAs($scope['member']);

        $this->assertSame(NotificationTemplatePage::ALL_WORKFLOWS, $this->textOf($this->xpath($this->open($template)), "//*[@data-testid='notification-template-workflows']"));

        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $this->assertTrue($template->save(false));
        $this->assertSame(NotificationTemplatePage::NO_WORKFLOWS, $this->textOf($this->xpath($this->open($template)), "//*[@data-testid='notification-template-workflows']"));

        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$own->id, (int)$foreign->id], $adminId));
        $html = $this->open($template);
        $xpath = $this->xpath($html);
        $this->assertSame([(string)$own->name], $this->texts($xpath, "//*[@data-testid='notification-template-workflows']/a"));
        $this->assertSame('/workflow-template/view?id=' . $own->id, $this->attributeOf($xpath, "//*[@data-testid='notification-template-workflows']/a", 'href'));
        $this->assertSame(sprintf(NotificationTemplatePage::HIDDEN_WORKFLOWS, 1), $this->textOf($xpath, "//*[@data-testid='notification-template-hidden-workflows']"));
        $this->assertStringNotContainsString((string)$foreign->name, $html);
    }

    public function testThePageNamesTheLevel(): void
    {
        $user = $this->createUserWithRole('ntview-level', 'viewer');
        $template = $this->createNotificationTemplate((int)$user->id);
        $this->loginAs($user);

        $this->assertSame('Everything', $this->textOf($this->xpath($this->open($template)), "//*[@data-testid='notification-template-level']"));

        $template->level = NotificationTemplate::LEVEL_ESSENTIAL;
        $this->assertTrue($template->save(false));
        $this->assertSame('Only failures, waits and the end', $this->textOf($this->xpath($this->open($template)), "//*[@data-testid='notification-template-level']"));
    }

    /**
     * A Mattermost template shows the check it keeps: the bot's username,
     * the channel's ID and when; that the config changed since; or that it
     * was not checked yet. Never the fingerprint, from which no token can be
     * read but which no reader needs. A template of another channel has no
     * such row.
     */
    public function testAMattermostTemplateShowsItsKeptCheckWithoutTheFingerprint(): void
    {
        $user = $this->createUserWithRole('ntview-check', 'viewer');
        $template = $this->createNotificationTemplate(
            (int)$user->id,
            NotificationTemplate::CHANNEL_MATTERMOST,
            'workflow.failed',
            (string)json_encode(['server_url' => 'https://chat.example.com', 'bot_token' => 'ntview-token-7716', 'team' => 'ops', 'channel' => 'deploys'])
        );
        $this->loginAs($user);

        $this->assertSame(NotificationTemplatePage::CHECK_NONE, $this->textOf($this->xpath($this->open($template)), "//*[@data-testid='notification-template-check']"));

        $fingerprint = MattermostConfig::of($template)->fingerprint();
        $checkedAt = time() - 3600;
        $template->channel_state = (string)json_encode([
            'fingerprint' => $fingerprint,
            'channel_id' => self::FINGERPRINT_STATE_CHANNEL,
            'bot_user_id' => 'botuseridbotuseridbotuseri',
            'bot_username' => 'ansilume-bot',
            'checked_at' => $checkedAt,
        ]);
        $this->assertTrue($template->save(false));
        $html = $this->open($template);
        $kept = sprintf(NotificationTemplatePage::CHECK_KEPT, date('Y-m-d H:i', $checkedAt), 'ansilume-bot', self::FINGERPRINT_STATE_CHANNEL);
        $this->assertSame($kept, $this->textOf($this->xpath($html), "//*[@data-testid='notification-template-check']"));
        $this->assertStringNotContainsString($fingerprint, $html);
        $this->assertStringNotContainsString('botuseridbotuseridbotuseri', $html);

        $template->config = (string)json_encode(['server_url' => 'https://chat.example.com', 'bot_token' => 'ntview-token-7716', 'team' => 'ops', 'channel' => 'alerts']);
        $this->assertTrue($template->save(false));
        $this->assertSame($kept . ' ' . NotificationTemplatePage::CHECK_STALE, $this->textOf($this->xpath($this->open($template)), "//*[@data-testid='notification-template-check']"));

        $email = $this->createNotificationTemplate((int)$user->id);
        $this->assertSame(0, $this->nodeCount($this->xpath($this->open($email)), "//*[@data-testid='notification-template-check']"));
    }

    /**
     * The deliveries the viewer may see, newest first: event, kind, status,
     * attempts, what it is about (linked), the last error and the times; a
     * count of the others. A viewer holds no job.launch, so a schedule's
     * delivery is hidden from them. Never the variables or the post made.
     */
    public function testThePageListsTheDeliveriesTheViewerMaySee(): void
    {
        $admin = $this->createUserWithRole('ntview-deliveries-admin', 'admin');
        $viewer = $this->createUserWithRole('ntview-deliveries-viewer', 'viewer');
        $template = $this->createNotificationTemplate((int)$admin->id);
        $jobTemplate = $this->createJobTemplate(
            (int)$this->createProject((int)$admin->id)->id,
            (int)$this->createInventory((int)$admin->id)->id,
            (int)$this->createRunnerGroup((int)$admin->id)->id,
            (int)$admin->id
        );
        $job = $this->createJob((int)$jobTemplate->id, (int)$admin->id);
        $failed = $this->delivery($template, 'job.failed', ['job_id' => (int)$job->id], [
            'status' => NotificationDelivery::STATUS_FAILED,
            'attempts' => 8,
            'last_error' => sprintf(MattermostApiException::TOKEN_REFUSED, 'HTTP 401'),
            'finished_at' => time() - 120,
            'remote_id' => 'ntview-remote-post-4410',
        ]);
        $schedule = $this->delivery($template, 'schedule.failed_to_launch', ['job_template_id' => (int)$jobTemplate->id], [
            'status' => NotificationDelivery::STATUS_SKIPPED,
            'last_error' => sprintf(NotificationDeliveryService::INACTIVE_IDENTITY, (int)$admin->id),
            'finished_at' => time() - 60,
        ]);
        $pending = $this->delivery($template, 'runner.offline', [], ['status' => NotificationDelivery::STATUS_PENDING, 'attempts' => 2, 'next_attempt_at' => time() + 600]);
        $sending = $this->delivery($template, 'runner.recovered', [], ['status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => time() - 30, 'kind' => NotificationDelivery::KIND_MESSAGE]);

        $this->loginAs($admin);
        $html = $this->open($template);
        $xpath = $this->xpath($html);
        $this->assertSame([(string)$sending->id, (string)$pending->id, (string)$schedule->id, (string)$failed->id], $this->attributes($xpath, "//table[@id='notification-template-deliveries']/tbody/tr", 'data-delivery-id'));
        $row = $this->cells($xpath, (int)$failed->id);
        $this->assertSame([(string)$failed->id, 'job.failed', 'Message', 'failed', '8', 'Job #' . $job->id, sprintf(MattermostApiException::TOKEN_REFUSED, 'HTTP 401')], array_slice($row, 0, 7));
        $this->assertSame('/job/view?id=' . $job->id, $this->attributeOf($xpath, "//tr[@data-delivery-id='{$failed->id}']/td[6]/a", 'href'));
        $this->assertSame('badge text-bg-danger', $this->attributeOf($xpath, "//tr[@data-delivery-id='{$failed->id}']/td[4]/span", 'class'));
        $this->assertSame('Job Template #' . $jobTemplate->id, $this->cells($xpath, (int)$schedule->id)[5]);
        $this->assertStringStartsWith('Not yet: next attempt in ', $this->cells($xpath, (int)$pending->id)[8]);
        $this->assertStringStartsWith('Not yet: sending since ', $this->cells($xpath, (int)$sending->id)[8]);
        $this->assertSame(0, $this->nodeCount($xpath, "//*[@data-testid='notification-template-hidden-deliveries']"));
        $this->assertStringNotContainsString('ntview-remote-post-4410', $html, 'not the post it made');
        $this->assertStringNotContainsString('ntview-variables-marker', $html, 'not its variables');

        $this->loginAs($viewer);
        $forViewer = $this->xpath($this->open($template));
        $this->assertSame([(string)$sending->id, (string)$pending->id, (string)$failed->id], $this->attributes($forViewer, "//table[@id='notification-template-deliveries']/tbody/tr", 'data-delivery-id'));
        $this->assertSame(sprintf(NotificationTemplatePage::HIDDEN_DELIVERIES, 1), $this->textOf($forViewer, "//*[@data-testid='notification-template-hidden-deliveries']"));
    }

    /**
     * A template without a delivery the viewer may see says so, and counts
     * the hidden ones; more than a page of deliveries gets a pager.
     */
    public function testNoDeliveriesAndAPager(): void
    {
        $admin = $this->createUserWithRole('ntview-pager-admin', 'admin');
        $viewer = $this->createUserWithRole('ntview-pager-viewer', 'viewer');
        $template = $this->createNotificationTemplate((int)$admin->id);
        $this->loginAs($viewer);

        $empty = $this->xpath($this->open($template));
        $this->assertSame(NotificationTemplatePage::NO_DELIVERIES, $this->textOf($empty, "//*[@data-testid='notification-template-no-deliveries']"));

        $this->delivery($template, 'schedule.failed_to_launch');
        $hidden = $this->xpath($this->open($template));
        $this->assertSame(NotificationTemplatePage::NO_DELIVERIES, $this->textOf($hidden, "//*[@data-testid='notification-template-no-deliveries']"));
        $this->assertSame(sprintf(NotificationTemplatePage::HIDDEN_DELIVERIES, 1), $this->textOf($hidden, "//*[@data-testid='notification-template-hidden-deliveries']"));

        for ($i = 0; $i < NotificationTemplatePage::DELIVERIES_PER_PAGE + 1; $i++) {
            $this->delivery($template, 'runner.offline');
        }
        $paged = $this->xpath($this->open($template));
        $this->assertSame(NotificationTemplatePage::DELIVERIES_PER_PAGE, $this->nodeCount($paged, "//table[@id='notification-template-deliveries']/tbody/tr"));
        $this->assertSame(1, $this->nodeCount($paged, "//ul[contains(@class, 'pagination')]//a[contains(@href, 'deliveries-page=2') and normalize-space(.)='2']"));
    }

    // -- Helpers ----------------------------------------------------------------------

    private function open(NotificationTemplate $template): string
    {
        $ctrl = new class ('notification-template', $this->app()) extends NotificationTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        $result = $this->withRealViews($ctrl, '/notification-template/view?id=' . $template->id, static fn (): mixed => $ctrl->runAction('view', ['id' => $template->id]));
        $this->assertIsString($result);

        return $result;
    }

    /**
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     * @param array<string, mixed> $attributes
     */
    private function delivery(NotificationTemplate $template, string $event, array $refs = [], array $attributes = []): NotificationDelivery
    {
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = $event;
        $delivery->kind = NotificationDelivery::KIND_MESSAGE;
        $delivery->status = NotificationDelivery::STATUS_DELIVERED;
        $delivery->attempts = 1;
        $delivery->setVariableMap(['event' => $event, 'job.name' => 'ntview-variables-marker']);
        $delivery->next_attempt_at = time();
        $delivery->finished_at = time();
        $delivery->setRefs($refs);
        foreach ($attributes as $name => $value) {
            $delivery->setAttribute($name, $value);
        }
        if (!array_key_exists('finished_at', $attributes) && in_array($delivery->status, [NotificationDelivery::STATUS_PENDING, NotificationDelivery::STATUS_SENDING], true)) {
            $delivery->finished_at = null;
        }
        $this->assertTrue($delivery->save(false));

        return $delivery;
    }

    /**
     * The texts of the cells of the delivery $id's row.
     *
     * @return list<string>
     */
    private function cells(\DOMXPath $xpath, int $id): array
    {
        return $this->texts($xpath, "//tr[@data-delivery-id='{$id}']/td");
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));

        return new \DOMXPath($document);
    }

    private function nodeCount(\DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);

        return $nodes === false ? 0 : $nodes->length;
    }

    /**
     * @return list<string>
     */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $texts[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }

    private function textOf(\DOMXPath $xpath, string $query): string
    {
        $texts = $this->texts($xpath, $query);
        $this->assertCount(1, $texts, $query);

        return $texts[0];
    }

    /**
     * @return list<string>
     */
    private function attributes(\DOMXPath $xpath, string $query, string $attribute): array
    {
        $values = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $values[] = $node instanceof \DOMElement ? $node->getAttribute($attribute) : '';
        }

        return $values;
    }

    private function attributeOf(\DOMXPath $xpath, string $query, string $attribute): string
    {
        $values = $this->attributes($xpath, $query, $attribute);
        $this->assertCount(1, $values, $query);

        return $values[0];
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }
}
