<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveySecrets;
use app\models\JobTemplate;
use app\models\Schedule;
use app\services\ScheduleSecrets;
use app\tests\integration\DbTestCase;
use app\tests\integration\ScheduleAnswerFixtures;

/**
 * What a schedule's pages show of its extra vars (ScheduleSecrets): the edit
 * form and the API the stored extra vars with their placeholders, the page
 * the same pretty-printed. Plain answers are masked too.
 */
class ScheduleSecretsDisplayTest extends DbTestCase
{
    use ScheduleAnswerFixtures;

    private const ANSWER = 'schedule-display-marker-1618';

    private int $userId;
    private JobTemplate $template;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-launch-test-key-0123456789';
        $this->userId = (int)$this->createUser('schedule-launch')->id;
        $this->template = $this->createPasswordSurveyTemplate($this->userId);
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        parent::tearDown();
    }

    // -- What the schedule's pages show -------------------------------------------

    public function testTheFormShowsTheStoredExtraVarsWithTheirPlaceholders(): void
    {
        $schedule = $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, [self::SURVEY_PASSWORD_FIELD => self::ANSWER]);

        $this->assertSame($schedule->extra_vars, ScheduleSecrets::maskedExtraVars($schedule));
    }

    /**
     * Plain answers are masked too, on the page and in the form.
     */
    public function testPlainAnswersAreMaskedOnThePageAndInTheForm(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"app_env":"prod","db_password":"' . self::ANSWER . '"}');

        $masked = (string)ScheduleSecrets::maskedExtraVars($schedule);
        $shown = (string)ScheduleSecrets::displayExtraVars($schedule);

        $expected = [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER];
        $this->assertSame($expected, json_decode($masked, true));
        $this->assertSame($expected, json_decode($shown, true));
        $this->assertStringContainsString("\n", $shown, 'pretty-printed');
        $this->assertStringNotContainsString(self::ANSWER, $masked . $shown);
    }

    /**
     * Masking follows the template, a soft-deleted one included.
     */
    public function testPlainAnswersOfADeletedTemplatesFieldsAreMasked(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"db_password":"' . self::ANSWER . '"}');
        $this->template->softDelete();

        $this->assertStringNotContainsString(self::ANSWER, (string)ScheduleSecrets::displayExtraVars($schedule));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function nothingToShowProvider(): array
    {
        return ['none' => [null], 'empty' => ['']];
    }

    /**
     * @dataProvider nothingToShowProvider
     */
    public function testThePageShowsNoExtraVarsWhenThereAreNone(?string $extraVars): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, $extraVars);

        $this->assertNull(ScheduleSecrets::displayExtraVars($schedule));
    }

    public function testANewScheduleWithoutATemplateShowsItsExtraVarsUnchanged(): void
    {
        $schedule = new Schedule();
        $schedule->extra_vars = '{"db_password":"typed"}';

        $this->assertSame('{"db_password":"typed"}', ScheduleSecrets::maskedExtraVars($schedule));
    }
}
