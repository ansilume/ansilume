<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ScheduleController;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\JobTemplateGate;
use app\tests\integration\services\TemplateInventoryLockFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;

/**
 * The schedule page says when each run waits for approval: an Approval row
 * (#schedule-approval) after Runs as, with JobTemplateGate::scheduleNote()
 * of the schedule's job template: its own rule, else its inventory's, with
 * the inventory's name. No row for a template without a rule, with two
 * different rules (each run is refused, the failed-to-launch notification
 * reports it), or once the template is gone.
 */
class ScheduleViewApprovalNoteTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    /** A name that a page must encode. */
    private const HOSTILE_NAME = '<img src=x onerror=alert(1)> "q" \'a\'';

    private User $admin;
    private ApprovalRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('w3t-schedule-admin', 'admin');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->loginAs($this->admin);
    }

    public function testATemplateGatedThroughItsInventoryNamesTheRuleAndTheInventory(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->rule);

        $html = $this->schedulePage($this->createSchedule($template));

        $inventory = Inventory::findOne($template->inventory_id);
        $this->assertNotNull($inventory);
        $this->assertSame(
            sprintf(JobTemplateGate::SCHEDULE_NOTE_INVENTORY, $this->rule->name, $inventory->name),
            $this->textOf($html, 'schedule-approval')
        );
    }

    public function testATemplateWithItsOwnRuleNamesTheRule(): void
    {
        $template = $this->withOwnRule($this->ungatedTemplate((int)$this->admin->id), $this->rule);

        $html = $this->schedulePage($this->createSchedule($template));

        $this->assertSame(sprintf(JobTemplateGate::SCHEDULE_NOTE, $this->rule->name), $this->textOf($html, 'schedule-approval'));
    }

    /**
     * The row comes right after Runs as, labeled Approval.
     */
    public function testTheRowFollowsRunsAs(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->rule);

        $html = $this->schedulePage($this->createSchedule($template));

        // The two labels before the row, in document order.
        $rows = $this->xpath($html)->query('//dd[@id="schedule-approval"]/preceding-sibling::dt[position() <= 2]');
        $this->assertInstanceOf(\DOMNodeList::class, $rows);
        $this->assertSame(['Runs as', 'Approval'], array_map(static fn (\DOMNode $dt): string => trim($dt->textContent), iterator_to_array($rows)));
        $this->assertStringContainsString('<dt class="col-sm-4">Approval</dt>', $html);
        $this->assertStringContainsString('<dd class="col-sm-8" id="schedule-approval">', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noNoteProvider(): array
    {
        return [
            'a template without a rule' => ['ungated'],
            'two different rules' => ['differ'],
            'a deleted template' => ['deleted'],
        ];
    }

    /**
     * @dataProvider noNoteProvider
     */
    public function testNoApprovalRowWithoutOneRuleOfALiveTemplate(string $case): void
    {
        $template = match ($case) {
            'ungated' => $this->ungatedTemplate((int)$this->admin->id),
            'differ' => $this->withOwnRule(
                $this->inventoryGated((int)$this->admin->id, $this->rule),
                $this->createApprovalRule((int)$this->admin->id)
            ),
            default => $this->inventoryGated((int)$this->admin->id, $this->rule),
        };
        $schedule = $this->createSchedule($template);
        if ($case === 'deleted') {
            JobTemplate::updateAll(['deleted_at' => time()], ['id' => $template->id]);
        }

        $html = $this->schedulePage($schedule);

        $this->assertNull($this->element($html, 'schedule-approval'));
        $this->assertStringNotContainsString('>Approval</dt>', $html);
    }

    /**
     * The controller gives the note to the view: null for a template that
     * is gone (the page says Missing).
     */
    public function testTheControllerPassesTheNote(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->rule);
        $schedule = $this->createSchedule($template);
        $ctrl = $this->capturingController();

        $ctrl->actionView((int)$schedule->id);
        $this->assertSame(JobTemplateGate::of($this->reloaded($template))->scheduleNote(), $ctrl->capturedParams['approvalNote'] ?? 'missing');

        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $template->id]);
        $ctrl->actionView((int)$schedule->id);
        $this->assertArrayHasKey('approvalNote', $ctrl->capturedParams);
        $this->assertNull($ctrl->capturedParams['approvalNote']);
    }

    public function testNamesAreEncoded(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->rule);
        ApprovalRule::updateAll(['name' => self::HOSTILE_NAME], ['id' => $this->rule->id]);
        Inventory::updateAll(['name' => self::HOSTILE_NAME], ['id' => $template->inventory_id]);

        $html = $this->schedulePage($this->createSchedule($template));

        $note = sprintf(JobTemplateGate::SCHEDULE_NOTE_INVENTORY, self::HOSTILE_NAME, self::HOSTILE_NAME);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('<dd class="col-sm-8" id="schedule-approval">' . Html::encode($note) . '</dd>', $html);
        $this->assertSame($note, $this->textOf($html, 'schedule-approval'));
    }

    // -- helpers ----------------------------------------------------------------------

    /**
     * The directory of the schedule views. A check of these tests against
     * other copies of the views overrides it.
     */
    protected function viewPath(): string
    {
        return '@app/views/schedule';
    }

    private function createSchedule(JobTemplate $template): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'w3t-schedule-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = false;
        $schedule->created_by = (int)$this->admin->id;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        return $schedule;
    }

    private function schedulePage(Schedule $schedule): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);
        $ctrl = new ScheduleController('schedule', $app);
        $ctrl->layout = false;
        $ctrl->setViewPath($this->viewPath());
        $html = $this->withRealViews($ctrl, '/schedule/view?id=' . $schedule->id, static fn (): mixed => $ctrl->actionView((int)$schedule->id));
        $this->assertIsString($html);

        return $html;
    }

    /**
     * @return ScheduleController&object{capturedParams: array<string, mixed>}
     */
    private function capturingController(): ScheduleController
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);

        return new class ('schedule', $app) extends ScheduleController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;

                return 'rendered:' . $view;
            }
        };
    }

    private function textOf(string $html, string $id): ?string
    {
        $element = $this->element($html, $id);

        return $element === null ? null : trim($element->textContent);
    }

    private function element(string $html, string $id): ?\DOMElement
    {
        $nodes = $this->xpath($html)->query('//*[@id="' . $id . '"]');
        $this->assertInstanceOf(\DOMNodeList::class, $nodes);
        $this->assertLessThanOrEqual(1, $nodes->length, "#{$id} at most once");
        $element = $nodes->item(0);

        return $element instanceof \DOMElement ? $element : null;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING));

        return new \DOMXPath($document);
    }
}
