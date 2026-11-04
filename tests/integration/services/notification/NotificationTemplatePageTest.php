<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowTemplate;
use app\services\notification\MattermostConfig;
use app\services\notification\NotificationTemplatePage;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * What the page of a notification template shows its viewer
 * (NotificationTemplatePage, spec answers 3 and 5): whom it delivers as and
 * whether that user is inactive, the workflow templates of its filter that
 * the viewer may see with a count of the others, its deliveries that the
 * viewer may see (VisibleDeliveries) with a count of the others, and the
 * kept check of a Mattermost template without its fingerprint.
 */
class NotificationTemplatePageTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const SERVER = 'https://chat.example.com';
    private const TOKEN = 'ntpage-bot-token-4471';
    private const CHANNEL_ID = 'abcdefghijklmnopqrstuvwxyz';
    private const BOT_ID = 'botuseridbotuseridbotuseri';

    public function testItSaysWhomTheTemplateDeliversAsAndWhetherThatUserIsActive(): void
    {
        $user = $this->createUserWithRole('ntpage-identity', 'operator');
        $template = $this->createNotificationTemplate((int)$user->id);

        $active = NotificationTemplatePage::of($template, (int)$user->id);
        $this->assertSame((int)$user->id, $active->deliversAs?->id);
        $this->assertSame($user->username, $active->deliversAsName());
        $this->assertFalse($active->deliversAsInactive);

        $user->status = User::STATUS_INACTIVE;
        $this->assertTrue($user->save(false));
        $inactive = NotificationTemplatePage::of($this->reload($template), (int)$user->id);
        $this->assertTrue($inactive->deliversAsInactive);
        $this->assertSame($user->username, $inactive->deliversAsName());
        $this->assertSame(
            sprintf('Delivers nothing: %s, the user it delivers as, is inactive. Saving the template makes whoever saves it the user it delivers as.', $user->username),
            sprintf(NotificationTemplatePage::DELIVERS_AS_INACTIVE, $inactive->deliversAsName())
        );
    }

    /**
     * A user the template names that no row holds (it cannot be deleted
     * while the template names it) is named by its ID, and counts as
     * inactive.
     */
    public function testAUserNoRowHoldsIsNamedByItsId(): void
    {
        $user = $this->createUser('ntpage-gone');
        $template = $this->createNotificationTemplate((int)$user->id);
        $template->delivers_as = 987654321;

        $page = NotificationTemplatePage::of($template, (int)$user->id);

        $this->assertNull($page->deliversAs);
        $this->assertSame('user #987654321', $page->deliversAsName());
        $this->assertTrue($page->deliversAsInactive);
    }

    /**
     * Spec answer 5: of the workflow filter, the viewer sees the names of
     * the workflow templates they may see, by name; the others, of another
     * team or deleted, are only counted.
     */
    public function testTheViewerSeesTheWorkflowTemplatesOfTheFilterTheyMaySee(): void
    {
        $scope = $this->teamScope('viewer');
        $adminId = (int)$scope['admin']->id;
        $own = $this->namedWorkflow($adminId, 'ntpage-b-own', (int)$scope['own']->id);
        $open = $this->namedWorkflow($adminId, 'ntpage-a-open', (int)$scope['open']->id);
        $foreign = $this->namedWorkflow($adminId, 'ntpage-c-foreign', (int)$scope['foreign']->id);
        $deleted = $this->namedWorkflow($adminId, 'ntpage-d-deleted', (int)$scope['own']->id);
        $template = $this->createNotificationTemplate($adminId);
        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $this->assertTrue($template->save(false));
        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$own->id, (int)$open->id, (int)$foreign->id, (int)$deleted->id], $adminId));
        $deleted->softDelete();

        $member = NotificationTemplatePage::of($template, (int)$scope['member']->id);
        $this->assertSame([(int)$open->id => 'ntpage-a-open', (int)$own->id => 'ntpage-b-own'], $member->workflowTemplates);
        $this->assertSame(2, $member->hiddenWorkflowTemplates);

        $admin = NotificationTemplatePage::of($template, $adminId);
        $this->assertSame([(int)$open->id => 'ntpage-a-open', (int)$own->id => 'ntpage-b-own', (int)$foreign->id => 'ntpage-c-foreign'], $admin->workflowTemplates);
        $this->assertSame(1, $admin->hiddenWorkflowTemplates, 'a deleted one is hidden from everyone');

        $unfiltered = NotificationTemplatePage::of($this->createNotificationTemplate($adminId), $adminId);
        $this->assertSame([], $unfiltered->workflowTemplates);
        $this->assertSame(0, $unfiltered->hiddenWorkflowTemplates);
    }

    /**
     * The deliveries the viewer may see, newest first, 25 to a page of its
     * own page parameter; the others are counted, never listed. A viewer
     * holds no job.launch, so the deliveries of a schedule are hidden from
     * them.
     */
    public function testTheViewerSeesTheDeliveriesTheyMaySeeAndCountsTheOthers(): void
    {
        $admin = $this->createUserWithRole('ntpage-deliveries-admin', 'admin');
        $viewer = $this->createUserWithRole('ntpage-deliveries-viewer', 'viewer');
        $template = $this->createNotificationTemplate((int)$admin->id);
        $other = $this->createNotificationTemplate((int)$admin->id);
        $jobTemplate = $this->createJobTemplate(
            (int)$this->createProject((int)$admin->id)->id,
            (int)$this->createInventory((int)$admin->id)->id,
            (int)$this->createRunnerGroup((int)$admin->id)->id,
            (int)$admin->id
        );
        $runner = $this->delivery($template, NotificationTemplate::EVENT_RUNNER_OFFLINE);
        $schedule = $this->delivery($template, NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, ['job_template_id' => (int)$jobTemplate->id]);
        $trigger = $this->delivery($template, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN);
        $this->delivery($other, NotificationTemplate::EVENT_RUNNER_OFFLINE);

        $forViewer = NotificationTemplatePage::of($template, (int)$viewer->id);
        $this->assertSame([(int)$trigger->id, (int)$runner->id], $this->idsOf($forViewer));
        $this->assertSame(1, $forViewer->hiddenDeliveries);

        $forAdmin = NotificationTemplatePage::of($template, (int)$admin->id);
        $this->assertSame([(int)$trigger->id, (int)$schedule->id, (int)$runner->id], $this->idsOf($forAdmin));
        $this->assertSame(0, $forAdmin->hiddenDeliveries);

        $pagination = $forAdmin->deliveries->getPagination();
        $this->assertNotFalse($pagination);
        $this->assertSame(NotificationTemplatePage::DELIVERIES_PER_PAGE, $pagination->pageSize);
        $this->assertSame('deliveries-page', $pagination->pageParam);
        $this->assertSame(25, NotificationTemplatePage::DELIVERIES_PER_PAGE);
    }

    public function testTheDeliveriesArePagedNewestFirst(): void
    {
        $admin = $this->createUserWithRole('ntpage-pages', 'admin');
        $template = $this->createNotificationTemplate((int)$admin->id);
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $ids[] = (int)$this->delivery($template, NotificationTemplate::EVENT_RUNNER_OFFLINE)->id;
        }
        rsort($ids);

        $this->setQueryParams(['deliveries-page' => '2', 'sort' => 'id']);
        $second = NotificationTemplatePage::of($template, (int)$admin->id);
        $this->assertSame(array_slice($ids, 25), $this->idsOf($second));

        $this->setQueryParams(['sort' => 'id']);
        $first = NotificationTemplatePage::of($template, (int)$admin->id);
        $this->assertSame(array_slice($ids, 0, 25), $this->idsOf($first), 'newest first, whatever ?sort= says');
        $this->assertSame(26, (int)$first->deliveries->getTotalCount());
    }

    /**
     * What a delivery is about, as links to the pages of its objects.
     */
    public function testWhatADeliveryIsAboutLinksTheirPages(): void
    {
        $delivery = new NotificationDelivery();
        $delivery->setRefs(['workflow_job_id' => 12, 'job_id' => 34, 'approval_request_id' => 5, 'project_id' => 6, 'job_template_id' => 7]);

        $this->assertSame([
            ['Workflow Job #12', ['/workflow-job/view', 'id' => 12]],
            ['Job #34', ['/job/view', 'id' => 34]],
            ['Approval Request #5', ['/approval/view', 'id' => 5]],
            ['Project #6', ['/project/view', 'id' => 6]],
            ['Job Template #7', ['/job-template/view', 'id' => 7]],
        ], NotificationTemplatePage::aboutOf($delivery));
        $this->assertSame([], NotificationTemplatePage::aboutOf(new NotificationDelivery()));
    }

    /**
     * The check a Mattermost template keeps (channel_state): the bot's
     * username, the channel ID and when, current while the config is the
     * one checked; none for a template that keeps none, or a state of
     * another form; null for a template of another channel. Never the
     * fingerprint.
     */
    public function testTheKeptCheckOfAMattermostTemplate(): void
    {
        $user = $this->createUserWithRole('ntpage-check', 'admin');
        $template = $this->mattermostTemplate((int)$user->id);
        $this->assertNull(NotificationTemplatePage::of($template, (int)$user->id)->check, 'not checked yet');

        $checkedAt = time() - 600;
        $fingerprint = MattermostConfig::of($template)->fingerprint();
        $this->keepState($template, ['fingerprint' => $fingerprint, 'channel_id' => self::CHANNEL_ID, 'bot_user_id' => self::BOT_ID, 'bot_username' => 'ansilume-bot', 'checked_at' => $checkedAt]);
        $current = NotificationTemplatePage::of($template, (int)$user->id)->check;
        $this->assertSame(['bot_username' => 'ansilume-bot', 'channel_id' => self::CHANNEL_ID, 'checked_at' => $checkedAt, 'current' => true], $current);

        $template->config = (string)json_encode(['server_url' => self::SERVER, 'bot_token' => self::TOKEN, 'channel_id' => 'zyxwvutsrqponmlkjihgfedcba']);
        $this->assertTrue($template->save(false));
        $stale = NotificationTemplatePage::of($template, (int)$user->id)->check;
        $this->assertNotNull($stale);
        $this->assertFalse($stale['current'], 'the channel changed since');

        $template->config = '{"server_url":"http://chat.example.com"}';
        $this->assertTrue($template->save(false));
        $unusable = NotificationTemplatePage::of($template, (int)$user->id)->check;
        $this->assertNotNull($unusable);
        $this->assertFalse($unusable['current'], 'a config the channel cannot use is no checked one');

        foreach ([['channel_id' => self::CHANNEL_ID], ['bot_username' => ['x'], 'channel_id' => self::CHANNEL_ID, 'checked_at' => $checkedAt, 'fingerprint' => $fingerprint]] as $state) {
            $this->keepState($template, $state);
            $this->assertNull(NotificationTemplatePage::of($template, (int)$user->id)->check, (string)json_encode($state));
        }
        $template->channel_state = 'no json';
        $this->assertTrue($template->save(false));
        $this->assertNull(NotificationTemplatePage::of($template, (int)$user->id)->check);

        $email = $this->createNotificationTemplate((int)$user->id);
        $email->channel_state = (string)json_encode(['fingerprint' => $fingerprint, 'channel_id' => self::CHANNEL_ID, 'bot_username' => 'ansilume-bot', 'checked_at' => $checkedAt]);
        $this->assertTrue($email->save(false));
        $this->assertNull(NotificationTemplatePage::of($email, (int)$user->id)->check, 'only a Mattermost template keeps a check');
    }

    /**
     * @return list<int>
     */
    private function idsOf(NotificationTemplatePage $page): array
    {
        return array_map(static fn (NotificationDelivery $delivery): int => (int)$delivery->id, array_values($page->deliveries->getModels()));
    }

    /**
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     */
    private function delivery(NotificationTemplate $template, string $event, array $refs = []): NotificationDelivery
    {
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = $event;
        $delivery->kind = NotificationDelivery::KIND_MESSAGE;
        $delivery->status = NotificationDelivery::STATUS_DELIVERED;
        $delivery->attempts = 1;
        $delivery->setVariableMap(['event' => $event]);
        $delivery->next_attempt_at = time();
        $delivery->setRefs($refs);
        $this->assertTrue($delivery->save(false));

        return $delivery;
    }

    private function mattermostTemplate(int $userId): NotificationTemplate
    {
        return $this->createNotificationTemplate(
            $userId,
            NotificationTemplate::CHANNEL_MATTERMOST,
            'workflow.failed',
            (string)json_encode(['server_url' => self::SERVER, 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID])
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function keepState(NotificationTemplate $template, array $state): void
    {
        $template->channel_state = (string)json_encode($state);
        $this->assertTrue($template->save(false));
    }

    private function namedWorkflow(int $userId, string $name, int $jobTemplateId): WorkflowTemplate
    {
        $workflow = $this->createWorkflowWithJobSteps($userId, $jobTemplateId);
        $workflow->name = $name;
        $this->assertTrue($workflow->save(false));

        return $workflow;
    }

    private function reload(NotificationTemplate $template): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne((int)$template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }
}
