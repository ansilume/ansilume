<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\ApiBodyValues;
use app\models\NotificationTemplate;
use app\services\notification\NotificationIdentity;
use app\services\notification\NotificationTemplateBody;
use app\services\notification\NotificationTemplateFormPost;
use app\services\notification\NotificationTemplateSave;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the web form of a notification template posts for its workflow
 * filter (NotificationTemplateFormPost): the multi-select's IDs, each read
 * as the integer rule reads an ID, '' (its hidden field) when none is
 * selected, and nothing at all when the post did not come from the form;
 * the template and its filter are saved together (NotificationTemplateSave).
 * The same rules and messages as the API's workflow_template_ids.
 */
class NotificationTemplateFormPostTest extends DbTestCase
{
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<int>|null}>
     */
    public static function takenProvider(): array
    {
        return [
            'no field: the filter stays' => [[], null],
            'the hidden field alone: none selected' => [['workflow_template_ids' => ''], []],
            'IDs as the form posts them' => [['workflow_template_ids' => ['5', '7']], [5, 7]],
            'IDs as a JSON body brings them' => [['workflow_template_ids' => [5, 7.0]], [5, 7]],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param list<int>|null $ids
     */
    #[DataProvider('takenProvider')]
    public function testThePostedIdsAreRead(array $post, ?array $ids): void
    {
        $form = NotificationTemplateFormPost::of($post + ['NotificationTemplate' => ['name' => 'x']]);

        $this->assertSame($ids, $form->workflowTemplateIds);
        $this->assertFalse($form->refused);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function refusedProvider(): array
    {
        return [
            'a number beyond the int range' => [[1e30]],
            'a fraction' => [[12.5]],
            'a fraction that reads as a whole number' => [[2.9999999999999996]],
            'text that is no number' => [['abc']],
            'an ID below 1' => [['0']],
            'a list in the list' => [[[5]]],
            'an object' => [['a' => 5]],
            'one ID instead of a list' => ['5'],
            'null' => [null],
            'true' => [true],
        ];
    }

    /**
     * A post whose field holds anything but a list of IDs, or '', is
     * refused with the API's message, at the field, after the template's
     * own rules, and nothing is written.
     */
    #[DataProvider('refusedProvider')]
    public function testAnythingButAListOfIdsIsRefusedAndNothingIsWritten(mixed $ids): void
    {
        $user = $this->createUserWithRole('ntfp-refused', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $form = NotificationTemplateFormPost::of(['workflow_template_ids' => $ids]);
        $this->assertTrue($form->refused);
        $this->assertNull($form->workflowTemplateIds);

        $new = $this->newTemplate((int)$user->id, '');
        $this->assertFalse($form->create($new, (int)$user->id));
        $this->assertSame([
            'name' => ['Name cannot be blank.'],
            NotificationTemplateSave::FILTER_ATTRIBUTE => [NotificationTemplateBody::WORKFLOW_TEMPLATE_IDS_FORM],
        ], $new->getErrors());
        $this->assertTrue($new->getIsNewRecord());

        $stored = $this->createNotificationTemplate((int)$user->id);
        $this->assertSame([], $stored->setWorkflowTemplateIds([(int)$workflow->id], (int)$user->id));
        $stored = $this->reload($stored);
        $before = $stored->getAttributes();
        $stored->name = 'ntfp-renamed';
        $this->assertFalse($form->update($stored, new NotificationIdentity($stored), (int)$user->id));
        $this->assertSame([NotificationTemplateSave::FILTER_ATTRIBUTE => [NotificationTemplateBody::WORKFLOW_TEMPLATE_IDS_FORM]], $stored->getErrors());
        $reloaded = NotificationTemplate::findOne((int)$stored->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($before, $reloaded->getAttributes(), 'nothing is written');
        $this->assertSame([(int)$workflow->id], $reloaded->getWorkflowTemplateIds());
    }

    /**
     * The template and its filter are saved together, as the editor may set
     * the filter: an ID the editor may not see is refused as the API refuses
     * it, and a create leaves no template behind.
     */
    public function testACreateSavesTheTemplateWithItsFilter(): void
    {
        $scope = $this->teamScope();
        $member = $scope['member'];
        $own = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id);
        $foreign = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['foreign']->id);

        $template = $this->newTemplate((int)$member->id, 'ntfp-create-' . uniqid('', true));
        $this->assertTrue(NotificationTemplateFormPost::of(['workflow_template_ids' => [(string)$own->id]])->create($template, (int)$member->id));
        $this->assertSame([(int)$own->id], $this->reload($template)->getWorkflowTemplateIds());

        $refused = $this->newTemplate((int)$member->id, 'ntfp-create-refused-' . uniqid('', true));
        $this->assertFalse(NotificationTemplateFormPost::of(['workflow_template_ids' => [(string)$foreign->id]])->create($refused, (int)$member->id));
        $this->assertSame(
            [NotificationTemplateSave::FILTER_ATTRIBUTE => [sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#' . $foreign->id)]],
            $refused->getErrors()
        );
        $this->assertTrue($refused->getIsNewRecord());
        $this->assertNull(NotificationTemplate::findOne(['name' => $refused->name]));

        $unfiltered = $this->newTemplate((int)$member->id, 'ntfp-create-none-' . uniqid('', true));
        $this->assertTrue(NotificationTemplateFormPost::of([])->create($unfiltered, (int)$member->id));
        $this->assertSame([], $this->reload($unfiltered)->getWorkflowTemplateIds());
    }

    /**
     * An update replaces the part of the filter its editor may see; the
     * others stay. '' empties the editor's part; a post without the field
     * keeps the filter. A change of the filter makes the editor the user
     * the template delivers as.
     */
    public function testAnUpdateReplacesThePartOfTheFilterTheEditorMaySee(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $memberId = (int)$scope['member']->id;
        $own = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $open = $this->createWorkflowWithJobSteps($adminId, (int)$scope['open']->id);
        $foreign = $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id);
        $template = $this->createNotificationTemplate($adminId);
        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$own->id, (int)$foreign->id], $adminId));

        $this->assertTrue($this->update($template, ['workflow_template_ids' => [(string)$open->id]], $memberId));
        $this->assertSame($this->sorted([(int)$open->id, (int)$foreign->id]), $this->reload($template)->getWorkflowTemplateIds());
        $this->assertSame($memberId, (int)$this->reload($template)->delivers_as, 'the filter changed');

        $this->assertTrue($this->update($this->reload($template), ['workflow_template_ids' => ''], $memberId));
        $this->assertSame([(int)$foreign->id], $this->reload($template)->getWorkflowTemplateIds());

        $this->assertTrue($this->update($this->reload($template), [], $adminId));
        $this->assertSame([(int)$foreign->id], $this->reload($template)->getWorkflowTemplateIds(), 'a post without the field keeps the filter');
        $this->assertSame($memberId, (int)$this->reload($template)->delivers_as, 'nothing changed');
    }

    /**
     * The form's fields are read as the API reads a body: a post without
     * them holds no form; a field that holds a list is refused with the
     * API's message, before anything changes the template, and the save is
     * refused with it.
     */
    public function testTheFieldsAreReadAsTheApiReadsABody(): void
    {
        $user = $this->createUserWithRole('ntfp-fields', 'operator');
        $template = $this->newTemplate((int)$user->id, 'ntfp-fields-' . uniqid('', true));

        $this->assertFalse(NotificationTemplateFormPost::of([])->load($template), 'no form');
        $this->assertFalse(NotificationTemplateFormPost::of(['NotificationTemplate' => 'x'])->load($template), 'no fields');

        $refused = NotificationTemplateFormPost::of(['NotificationTemplate' => ['name' => ['x'], 'description' => 'kept out']]);
        $this->assertTrue($refused->load($template));
        $this->assertNull($template->description, 'nothing was applied');
        $this->assertFalse($refused->create($template, (int)$user->id));
        $this->assertSame([NotificationTemplateFormPost::FIELDS_ERROR => [sprintf(ApiBodyValues::NOT_A_VALUE, 'name')]], $template->getErrors());
        $this->assertTrue($template->getIsNewRecord());

        $stored = $this->createNotificationTemplate((int)$user->id);
        $this->assertFalse($refused->update($stored, new NotificationIdentity($stored), (int)$user->id));
        $this->assertSame([NotificationTemplateFormPost::FIELDS_ERROR => [sprintf(ApiBodyValues::NOT_A_VALUE, 'name')]], $stored->getErrors());

        $taken = NotificationTemplateFormPost::of(['NotificationTemplate' => ['description' => 'applied', 'events' => ['job.failed', 'workflow.waiting']]]);
        $this->assertTrue($taken->load($template));
        $this->assertSame('applied', $template->description);
        $this->assertSame('job.failed,workflow.waiting', $template->events);
        $this->assertSame(NotificationTemplateFormPost::FORM, $template->formName());
    }

    /**
     * @param array<string, mixed> $post
     */
    private function update(NotificationTemplate $template, array $post, int $userId): bool
    {
        return NotificationTemplateFormPost::of($post)->update($template, new NotificationIdentity($template), $userId);
    }

    private function newTemplate(int $userId, string $name): NotificationTemplate
    {
        $template = new NotificationTemplate();
        $template->name = $name;
        $template->channel = NotificationTemplate::CHANNEL_EMAIL;
        $template->config = '{"emails":["ops@example.com"]}';
        $template->events = 'workflow.failed';
        $template->created_by = $userId;
        $template->delivers_as = $userId;

        return $template;
    }

    private function reload(NotificationTemplate $template): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne((int)$template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
