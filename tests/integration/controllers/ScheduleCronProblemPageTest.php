<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\CronExpressionRules;
use app\controllers\ScheduleController;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;

/**
 * Review follow-up: a schedule that an older version saved with a cron
 * expression the cron rules refuse runs as before, skipped and misplaced
 * runs included, and only schedule/check said so. Its page and its edit
 * form say why a save refuses it (Schedule::CRON_REFUSED_NOTE), and the
 * schedules list marks it (Schedule::CRON_REFUSED_BADGE, the reason as its
 * title); a rejected submission shows the reason as the error of the cron
 * expression instead.
 */
class ScheduleCronProblemPageTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;

    /** The list the library walks out of order: it ran at 18:00 only. */
    private const REFUSED = '0 18,6 * * *';

    private User $member;

    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $scope = $this->teamScope();
        $this->member = $scope['member'];
        $this->template = $scope['own'];
        $this->loginAs($this->member);
    }

    public function testThePageSaysWhyASaveRefusesTheStoredExpression(): void
    {
        $html = $this->render('view', $this->storedSchedule(self::REFUSED));

        $problem = $this->element($html, 'schedule-cron-problem');
        $this->assertNotNull($problem);
        $this->assertSame(
            Schedule::CRON_REFUSED_NOTE . ' ' . self::reason(),
            (string)preg_replace('/\s+/', ' ', trim($problem->textContent))
        );
        $this->assertStringContainsString(Html::encode(self::reason()), $html);
        $this->assertSame('1', $this->element($html, 'cron-human')?->getAttribute('data-refused'), 'its description reads as written');
    }

    public function testThePageOfAnAcceptedExpressionSaysNothing(): void
    {
        $html = $this->render('view', $this->storedSchedule('0 6,18 * * *'));

        $this->assertNull($this->element($html, 'schedule-cron-problem'));
        $this->assertStringNotContainsString(Schedule::CRON_REFUSED_NOTE, $html);
        $this->assertFalse($this->element($html, 'cron-human')?->hasAttribute('data-refused'));
    }

    public function testTheListMarksTheRefusedExpressionWithItsReason(): void
    {
        $refused = $this->storedSchedule(self::REFUSED);
        $accepted = $this->storedSchedule('0 6,18 * * *');

        $html = $this->render('index', null);

        $badges = $this->xpath($html)->query('//span[@data-testid="schedule-cron-refused"]');
        $this->assertInstanceOf(\DOMNodeList::class, $badges);
        $marked = [];
        foreach ($badges as $badge) {
            $this->assertInstanceOf(\DOMElement::class, $badge);
            $this->assertSame(Schedule::CRON_REFUSED_BADGE, trim($badge->textContent));
            $row = $badge->parentNode?->parentNode;
            $this->assertInstanceOf(\DOMElement::class, $row);
            $marked[trim((string)$row->getElementsByTagName('td')->item(0)?->textContent)] = $badge->getAttribute('title');
        }
        $this->assertSame(self::reason(), $marked[(string)$refused->id] ?? null);
        $this->assertArrayNotHasKey((string)$accepted->id, $marked);
    }

    public function testTheEditFormSaysWhyBeforeASave(): void
    {
        $html = $this->render('update', $this->storedSchedule(self::REFUSED));

        $problem = $this->element($html, 'schedule-form-cron-problem');
        $this->assertNotNull($problem);
        $this->assertSame(
            Schedule::CRON_REFUSED_NOTE . ' ' . self::reason(),
            (string)preg_replace('/\s+/', ' ', trim($problem->textContent))
        );
        $this->assertSame(self::REFUSED, $this->element($html, 'cron-input')?->getAttribute('data-refused'), 'no check mark for it');
    }

    /**
     * A rejected submission names the reason once, as the error of the cron
     * expression.
     */
    public function testARejectedSubmissionShowsTheReasonAsTheFieldError(): void
    {
        $schedule = $this->storedSchedule(self::REFUSED);
        $this->setPost(['Schedule' => ['name' => 'renamed-' . uniqid('', true)]]);

        $html = $this->render('update', $schedule);

        $this->assertNull($this->element($html, 'schedule-form-cron-problem'));
        $this->assertSame(1, substr_count($html, Html::encode(self::reason())));
        $this->assertSame(self::REFUSED, $this->element($html, 'cron-input')?->getAttribute('data-refused'));
        $this->assertSame(self::REFUSED, Schedule::findOne($schedule->id)?->cron_expression);
    }

    public function testTheCreateFormSaysNothing(): void
    {
        $html = $this->render('create', null);

        $this->assertNull($this->element($html, 'schedule-form-cron-problem'));
        $this->assertFalse($this->element($html, 'cron-input')?->hasAttribute('data-refused'));
    }

    /**
     * Asking does not touch the errors of the schedule itself.
     */
    public function testTheProblemLeavesTheErrorsOfTheScheduleAlone(): void
    {
        $schedule = $this->storedSchedule(self::REFUSED);
        $schedule->addError('name', 'kept');

        $this->assertSame(self::reason(), $schedule->cronExpressionProblem());
        $this->assertSame(['name' => ['kept']], $schedule->getErrors());
    }

    private static function reason(): string
    {
        return sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18');
    }

    /**
     * A schedule as an older version stored it, without the cron rules.
     */
    private function storedSchedule(string $cron): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'cron-problem-page-' . uniqid('', true);
        $schedule->job_template_id = (int)$this->template->id;
        $schedule->cron_expression = $cron;
        $schedule->timezone = 'UTC';
        $schedule->enabled = false;
        $schedule->created_by = (int)$this->member->id;
        $schedule->save(false);

        return $schedule;
    }

    /**
     * The page of action $action (view, index, update or create) with the
     * real views, for $schedule where the action takes one.
     */
    private function render(string $action, ?Schedule $schedule): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);
        $ctrl = new ScheduleController('schedule', $app);
        $ctrl->layout = false;
        $id = (int)$schedule?->id;
        $html = $this->withRealViews($ctrl, '/schedule/' . $action . ($schedule === null ? '' : '?id=' . $id), static fn (): mixed => match ($action) {
            'view' => $ctrl->actionView($id),
            'index' => $ctrl->actionIndex(),
            'update' => $ctrl->actionUpdate($id),
            default => $ctrl->actionCreate(),
        });
        $this->assertIsString($html);

        return $html;
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
