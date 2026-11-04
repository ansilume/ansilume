<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\NotificationTestVariables;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelRegistry;
use app\services\notification\NotificationTemplateFormView;
use app\services\notification\PagerDutyChannel;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;

/**
 * What the web form of a notification template offers its editor
 * (NotificationTemplateFormView): the workflow templates they may see for
 * the filter, which of them are selected, how many others the filter holds
 * (kept and counted, never listed: spec answer 5), the panel of each
 * built-in channel with the keys it shows, the JSON editor of every other
 * channel, and the hints.
 */
class NotificationTemplateFormViewTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;

    /** A channel of one's own, as ChannelRegistryTest configures it. */
    private const ACME = [
        'label' => 'Acme Pager',
        'class' => ConfiguredTestChannel::class,
        'requiredKeys' => ['endpoint', 'api_key'],
        'secretKeys' => ['api_key'],
        'secretMaps' => ['headers'],
        'boundTo' => ['api_key' => 'endpoint'],
        'urlSchemes' => ['endpoint' => ['https']],
    ];

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * The editor is offered the workflow templates they may see; of the
     * filter, those they may see are selected, the others (another team's,
     * deleted) only counted. After a failed save the selection is the one
     * posted, of those offered.
     */
    public function testTheFilterOffersWhatTheEditorMaySeeAndCountsTheRest(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $memberId = (int)$scope['member']->id;
        $own = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $open = $this->createWorkflowWithJobSteps($adminId, (int)$scope['open']->id);
        $foreign = $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id);
        $deleted = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $template = $this->createNotificationTemplate($adminId);
        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$own->id, (int)$foreign->id, (int)$deleted->id], $adminId));
        $deleted->softDelete();

        $form = NotificationTemplateFormView::of($template, $memberId, null);

        $this->assertArrayHasKey((int)$own->id, $form->workflowTemplates);
        $this->assertArrayHasKey((int)$open->id, $form->workflowTemplates);
        $this->assertArrayNotHasKey((int)$foreign->id, $form->workflowTemplates, 'another team\'s is not offered');
        $this->assertArrayNotHasKey((int)$deleted->id, $form->workflowTemplates, 'a deleted one is not offered');
        $this->assertSame($own->name, $form->workflowTemplates[(int)$own->id]);
        $this->assertSame([(int)$own->id], $form->selectedWorkflowTemplateIds);
        $this->assertSame(2, $form->hiddenWorkflowTemplates);

        $posted = NotificationTemplateFormView::of($template, $memberId, [(int)$open->id, (int)$foreign->id]);
        $this->assertSame([(int)$open->id], $posted->selectedWorkflowTemplateIds, 'as posted, of those offered');
        $this->assertSame(2, $posted->hiddenWorkflowTemplates, 'what is stored');

        $admin = NotificationTemplateFormView::of($template, $adminId, null);
        $this->assertSame([(int)$own->id, (int)$foreign->id], $admin->selectedWorkflowTemplateIds);
        $this->assertSame(1, $admin->hiddenWorkflowTemplates, 'a deleted one is hidden from everyone');

        $new = NotificationTemplateFormView::of(new NotificationTemplate(), $memberId, null);
        $this->assertSame([], $new->selectedWorkflowTemplateIds);
        $this->assertSame(0, $new->hiddenWorkflowTemplates);
    }

    /**
     * Every built-in channel has a panel of its own, which shows every key
     * its definition names: its required, secret, URL and destination keys,
     * and the keys its secrets are bound to, so that an editor can enter a
     * bound secret again. Regression: the webhook panel showed neither the
     * signing secret nor the payload, so a web save dropped both.
     */
    public function testEveryBuiltInChannelHasAPanelThatShowsEveryKeyItsDefinitionNames(): void
    {
        $this->assertSame(array_keys(ChannelRegistry::BUILT_IN), array_keys(NotificationTemplateFormView::PANEL_KEYS));
        foreach (ChannelRegistry::installed() as $name => $definition) {
            $named = array_merge(
                $definition->requiredKeys,
                $definition->secretKeys,
                $definition->secretMaps,
                array_keys($definition->boundTo),
                array_values($definition->boundTo),
                array_keys($definition->urlSchemes),
                $definition->destinationKeys ?? []
            );
            $this->assertSame([], array_values(array_diff($named, NotificationTemplateFormView::PANEL_KEYS[$name])), $name);
        }
        $this->assertSame(['url', 'headers', 'signing_secret', 'payload'], NotificationTemplateFormView::PANEL_KEYS[NotificationTemplate::CHANNEL_WEBHOOK]);
        $this->assertSame(
            ['server_url', 'bot_token', 'channel_id', 'team', 'channel', 'mention'],
            NotificationTemplateFormView::PANEL_KEYS[NotificationTemplate::CHANNEL_MATTERMOST]
        );
        $this->assertSame([], NotificationTemplateFormView::of(new NotificationTemplate(), 0, null)->rawChannels);
    }

    /**
     * A channel of the installation's own has no panel: the form edits its
     * config as JSON, and says which keys it needs and which are secrets.
     */
    public function testAChannelOfOnesOwnGetsTheJsonEditor(): void
    {
        $this->swapComponent('notificationDispatcher', ['class' => NotificationDispatcher::class, 'channels' => ['acme' => self::ACME]]);

        $form = NotificationTemplateFormView::of(new NotificationTemplate(), 0, null);

        $this->assertSame(['acme'], array_keys($form->rawChannels));
        $this->assertSame(
            'The config of Acme Pager as a JSON object. Required: endpoint, api_key. Secrets, shown as $encrypted$: api_key, the values of headers.',
            NotificationTemplateFormView::rawConfigHint($form->rawChannels['acme'])
        );
        $plain = ChannelRegistry::installed()[NotificationTemplate::CHANNEL_EMAIL];
        $this->assertSame('The config of Email as a JSON object. Required: emails.', NotificationTemplateFormView::rawConfigHint($plain));
    }

    /**
     * The PagerDuty panel names the events that never raise an incident,
     * as the event checkboxes name them.
     */
    public function testThePagerDutyPanelNamesTheEventsThatNeverRaiseAnIncident(): void
    {
        $labels = NotificationTemplate::eventLabels();
        $this->assertSame(
            sprintf('%s and %s never raise an incident: PagerDuty sends nothing for them.', $labels[PagerDutyChannel::NEVER_TRIGGERED[0]], $labels[PagerDutyChannel::NEVER_TRIGGERED[1]]),
            NotificationTemplateFormView::pagerDutyNote()
        );
        $this->assertSame('Workflow step started and Workflow step succeeded never raise an incident: PagerDuty sends nothing for them.', NotificationTemplateFormView::pagerDutyNote());
    }

    /**
     * The variables the hint names are variables that notifications carry
     * (the Send Test samples), the new ones of workflow events included.
     */
    public function testTheVariablesHintNamesOnlyVariablesNotificationsCarry(): void
    {
        preg_match_all('/\{\{ ([a-z_.]+) \}\}/', NotificationTemplateFormView::VARIABLES_HINT, $matches);
        $named = $matches[1];
        $samples = array_keys(NotificationTestVariables::of('workflow.waiting', 'nt-form-view'));

        $this->assertSame([], array_values(array_diff($named, $samples)), 'every variable the hint names is carried');
        foreach (['workflow.url', 'workflow.reason', 'step.name', 'approval.url', 'event_id'] as $variable) {
            $this->assertContains($variable, $named);
        }
        $this->assertSame(array_values(array_unique($named)), $named, 'each once');
    }
}
