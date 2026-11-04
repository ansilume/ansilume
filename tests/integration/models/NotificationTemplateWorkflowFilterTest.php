<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\NotificationTemplate;
use app\services\notification\NotificationWorkflowFilter;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\ScheduleFailingCommand;
use app\tests\integration\TeamScopeFixtures;
use yii\base\InvalidArgumentException;
use yii\base\InvalidCallException;

/**
 * The workflow filter of a notification template ("for these workflows"):
 * written through setWorkflowTemplateIds(), where an editor adds or removes
 * only the workflow templates they may see and the others stay; read by
 * getWorkflowTemplateIds() and applied by isForWorkflowTemplate() while the
 * workflow scope is selected.
 */
class NotificationTemplateWorkflowFilterTest extends DbTestCase
{
    use TeamScopeFixtures;

    /**
     * The editor's IDs replace what the editor may see of the filter;
     * unknown, deleted and invisible IDs are refused and not written.
     */
    public function testTheEditorSetsTheVisiblePartAndHearsWhichIdsWereRefused(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $own = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $open = $this->createWorkflowWithJobSteps($adminId, (int)$scope['open']->id);
        $foreign = $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id);
        $deleted = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $deleted->softDelete();
        $template = $this->createNotificationTemplate((int)$scope['member']->id);

        $refused = $template->setWorkflowTemplateIds([(int)$open->id, (int)$foreign->id, (int)$own->id, (int)$deleted->id, 987654321, 0, -4, (int)$own->id], (int)$scope['member']->id);

        $expectedRefused = [(int)$foreign->id, (int)$deleted->id, 987654321, 0, -4];
        $this->assertSame($expectedRefused, $refused, 'in the order submitted, each once');
        $this->assertSame($this->sorted([(int)$own->id, (int)$open->id]), $template->getWorkflowTemplateIds());

        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$open->id], (int)$scope['member']->id));
        $this->assertSame([(int)$open->id], $template->getWorkflowTemplateIds(), 'removed what the editor left out');

        $this->assertSame([], $template->setWorkflowTemplateIds([], (int)$scope['member']->id));
        $this->assertSame([], $template->getWorkflowTemplateIds());
    }

    /**
     * Stored workflow templates the editor may not see stay, removed or
     * not, and so do deleted ones, which nobody sees.
     */
    public function testTheWorkflowsTheEditorCannotSeeStay(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $own = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $foreign = $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id);
        $deleted = $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id);
        $template = $this->createNotificationTemplate($adminId);
        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$own->id, (int)$foreign->id, (int)$deleted->id], $adminId));
        $deleted->softDelete();

        $this->assertSame([], $template->setWorkflowTemplateIds([], (int)$scope['member']->id));

        $this->assertSame($this->sorted([(int)$foreign->id, (int)$deleted->id]), $template->getWorkflowTemplateIds());

        $this->assertSame(
            [(int)$foreign->id],
            $template->setWorkflowTemplateIds([(int)$foreign->id], (int)$scope['member']->id),
            'refused, though stored: a caller learns nothing of what it cannot see'
        );
        $this->assertSame($this->sorted([(int)$foreign->id, (int)$deleted->id]), $template->getWorkflowTemplateIds(), 'and kept');
    }

    /**
     * The filter decides only while the workflow scope is selected: then
     * a run of a workflow template outside it is held back, and a filter
     * without a workflow template holds back every run. Any other scope
     * than all counts as selected.
     */
    public function testTheFilterDecidesWhileTheScopeIsSelected(): void
    {
        $admin = $this->createUserWithRole('nwf-scope', 'admin');
        $chosen = $this->createWorkflowTemplate((int)$admin->id);
        $other = $this->createWorkflowTemplate((int)$admin->id);
        $template = $this->createNotificationTemplate((int)$admin->id);

        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_ALL;
        $this->assertTrue($template->isForWorkflowTemplate((int)$chosen->id));
        $this->assertTrue($template->isForWorkflowTemplate((int)$other->id));

        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $this->assertFalse($template->isForWorkflowTemplate((int)$chosen->id), 'an empty selection holds back every run');

        $template->setWorkflowTemplateIds([(int)$chosen->id], (int)$admin->id);
        $this->assertTrue($template->isForWorkflowTemplate((int)$chosen->id));
        $this->assertFalse($template->isForWorkflowTemplate((int)$other->id));

        $template->workflow_scope = 'some';
        $this->assertTrue($template->isForWorkflowTemplate((int)$chosen->id));
        $this->assertFalse($template->isForWorkflowTemplate((int)$other->id));
    }

    public function testATemplateNotSavedYetHasNoFilterToWrite(): void
    {
        $template = new NotificationTemplate();
        $this->assertSame([], $template->getWorkflowTemplateIds());

        $this->expectException(InvalidCallException::class);
        $this->expectExceptionMessage(NotificationWorkflowFilter::NOT_SAVED);
        $template->setWorkflowTemplateIds([1], 1);
    }

    /**
     * IDs from a request are read with SubmittedId first: anything but an
     * integer is a programming error, and nothing is written.
     *
     * @dataProvider notIds
     */
    public function testAnIdThatIsNoIntegerIsAProgrammingError(mixed $id): void
    {
        $admin = $this->createUserWithRole('nwf-type', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $template = $this->createNotificationTemplate((int)$admin->id);

        try {
            /** @phpstan-ignore-next-line the test hands over what a caller must not */
            $template->setWorkflowTemplateIds([(int)$workflow->id, $id], (int)$admin->id);
            $this->fail('expected the ID to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(NotificationWorkflowFilter::NOT_AN_ID, $e->getMessage());
        }
        $this->assertSame([], $template->getWorkflowTemplateIds());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notIds(): array
    {
        return [
            'a string' => ['5'],
            'a float' => [5.0],
            'true' => [true],
            'null' => [null],
            'a list' => [[5]],
        ];
    }

    /**
     * A write that fails leaves the filter as it was: the removal and the
     * additions are one transaction.
     */
    public function testAFailedWriteChangesNothing(): void
    {
        $admin = $this->createUserWithRole('nwf-atomic', 'admin');
        $removed = $this->createWorkflowTemplate((int)$admin->id);
        $added = $this->createWorkflowTemplate((int)$admin->id);
        $template = $this->createNotificationTemplate((int)$admin->id);
        $template->setWorkflowTemplateIds([(int)$removed->id], (int)$admin->id);
        $db = \Yii::$app->db;
        $driver = $db->getDriverName();
        $previous = $db->commandMap[$driver] ?? null;
        $db->commandMap[$driver] = ['class' => ScheduleFailingCommand::class, 'failingPattern' => '/^INSERT INTO `notification_template_workflow_template`/'];
        try {
            $template->setWorkflowTemplateIds([(int)$added->id], (int)$admin->id);
            $this->fail('expected the insert to fail');
        } catch (\yii\db\Exception) {
            $this->addToAssertionCount(1);
        } finally {
            if ($previous === null) {
                unset($db->commandMap[$driver]);
            } else {
                $db->commandMap[$driver] = $previous;
            }
        }

        $this->assertSame([(int)$removed->id], $template->getWorkflowTemplateIds(), 'the removal was rolled back');
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
