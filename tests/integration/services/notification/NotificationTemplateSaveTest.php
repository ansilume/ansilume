<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\NotificationIdentity;
use app\services\notification\NotificationTemplateSave;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\ActiveRecord;

/**
 * A notification template and its workflow filter are saved together, or
 * neither (NotificationTemplateSave): a create that the filter refuses
 * leaves no row and a template that is new again, and an error during an
 * update rolls the filter back with it and reaches the caller.
 */
class NotificationTemplateSaveTest extends DbTestCase
{
    use TeamScopeFixtures;

    /**
     * A create whose filter names a workflow template the creator may not
     * see is rolled back after its insert: no row, an error on
     * workflow_template_ids, and the template new again, so a form can show
     * it as one to create.
     */
    public function testACreateThatItsFilterRefusesLeavesNoRowAndIsNewAgain(): void
    {
        $scope = $this->teamScope();
        $foreign = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['foreign']->id);
        $template = $this->newTemplate((int)$scope['member']->id);

        $saved = NotificationTemplateSave::create($template, [(int)$foreign->id], (int)$scope['member']->id);

        $this->assertFalse($saved);
        $this->assertTrue($template->getIsNewRecord());
        $this->assertNull($template->id);
        $this->assertSame([sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#' . $foreign->id)], $template->getErrors(NotificationTemplateSave::FILTER_ATTRIBUTE));
        $this->assertNull(NotificationTemplate::findOne(['name' => $template->name]));
    }

    /**
     * A create the model refuses writes nothing either, and its filter is
     * never asked.
     */
    public function testACreateTheModelRefusesWritesNothing(): void
    {
        $admin = $this->createUserWithRole('nt-save-invalid', 'admin');
        $template = $this->newTemplate((int)$admin->id);
        $template->events = 'job.exploded';

        $this->assertFalse(NotificationTemplateSave::create($template, [(int)$this->createWorkflowTemplate((int)$admin->id)->id], (int)$admin->id));

        $this->assertTrue($template->getIsNewRecord());
        $this->assertSame(['Unknown event: job.exploded'], $template->getErrors('events'));
        $this->assertSame([], $template->getErrors(NotificationTemplateSave::FILTER_ATTRIBUTE));
        $this->assertNull(NotificationTemplate::findOne(['name' => $template->name]));
    }

    /**
     * An error while the update is written, here a handler of the save
     * that throws, rolls back the filter written before it and reaches the
     * caller; the template keeps its filter and the user it delivers as.
     */
    public function testAnErrorDuringAnUpdateRollsBackTheFilterAndReachesTheCaller(): void
    {
        $admin = $this->createUserWithRole('nt-save-error', 'admin');
        $kept = (int)$this->createWorkflowTemplate((int)$admin->id)->id;
        $other = (int)$this->createWorkflowTemplate((int)$admin->id)->id;
        $template = $this->createNotificationTemplate((int)$admin->id);
        $this->assertSame([], $template->setWorkflowTemplateIds([$kept], (int)$admin->id));
        $editor = $this->createUserWithRole('nt-save-error-editor', 'admin');
        $identity = new NotificationIdentity($template);
        $failure = new \RuntimeException('nt-save: the save failed');
        $throw = static function () use ($failure): never {
            throw $failure;
        };
        $level = NotificationTemplate::getDb()->getTransaction()?->getLevel();
        Event::on(NotificationTemplate::class, ActiveRecord::EVENT_BEFORE_UPDATE, $throw);
        try {
            NotificationTemplateSave::update($template, $identity, [$other], (int)$editor->id);
            $this->fail('The error reaches the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e);
        } finally {
            Event::off(NotificationTemplate::class, ActiveRecord::EVENT_BEFORE_UPDATE, $throw);
        }

        $this->assertSame($level, NotificationTemplate::getDb()->getTransaction()?->getLevel(), 'no transaction left open');
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);
        $this->assertSame([$kept], $stored->getWorkflowTemplateIds());
        $this->assertSame((int)$admin->id, (int)$stored->delivers_as);
    }

    private function newTemplate(int $userId): NotificationTemplate
    {
        $template = new NotificationTemplate();
        $template->name = 'nt-save-' . uniqid('', true);
        $template->channel = NotificationTemplate::CHANNEL_EMAIL;
        $template->config = '{"emails":["ops@example.com"]}';
        $template->events = 'workflow.failed';
        $template->created_by = $userId;
        $template->delivers_as = $userId;

        return $template;
    }
}
