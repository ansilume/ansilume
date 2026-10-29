<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveySecrets;
use app\models\JobTemplate;
use app\models\Schedule;
use app\services\ScheduleSecrets;
use app\tests\integration\DbTestCase;

/**
 * ScheduleSecrets::maskedExtraVars() masks the password answers of the
 * template a schedule's job_template_id names, read as the model's integer
 * rule reads it (SubmittedId): a schedule whose job_template_id holds no
 * template ID, such as a JSON number beyond the int range that load() of a
 * JSON post assigns as it comes, names no template, and its extra vars are
 * shown as they are, as for a new schedule without a template. A template
 * ID, also as digits, masks as before.
 *
 * Regression: such a number failed on PHP 8.5: the (int) cast of the method
 * warned "The float 1.0E+30 is not representable as an int, cast occurred",
 * and the error handler turned the warning into an exception. Up to PHP 8.4
 * the cast wrapped it to an arbitrary template ID.
 */
class ScheduleSecretsNumbersTest extends DbTestCase
{
    private const EXTRA_VARS = '{"app_env":"prod","db_password":"typed-in-the-form"}';

    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->template = $this->createPasswordSurveyTemplate((int)$this->createUser('numbers-masking')->id);
    }

    /**
     * JSON numbers beyond the int range, as the request's JSON parser gives
     * them: floats, 1e999 as infinity.
     *
     * @return array<string, array{0: string}>
     */
    public static function beyondTheIntRangeProvider(): array
    {
        return [
            '1e30' => ['1e30'],
            'one more than the largest int' => ['9223372036854775808'],
            '1e999, infinity' => ['1e999'],
        ];
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testATemplateIdBeyondTheIntRangeNamesNoTemplate(string $number): void
    {
        $posted = json_decode('{"job_template_id": ' . $number . '}', true);
        $this->assertIsArray($posted);
        $this->assertIsFloat($posted['job_template_id'], 'the parser gives a float');
        $schedule = new Schedule();
        $schedule->job_template_id = $posted['job_template_id'];
        $schedule->extra_vars = self::EXTRA_VARS;

        $this->assertSame(self::EXTRA_VARS, ScheduleSecrets::maskedExtraVars($schedule));
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function templateIdProvider(): array
    {
        return ['as stored' => [false], 'as digits, as the form posts it' => [true]];
    }

    /**
     * @dataProvider templateIdProvider
     */
    public function testATemplateIdMasksThePasswordAnswersAsBefore(bool $asDigits): void
    {
        $schedule = new Schedule();
        $schedule->job_template_id = $asDigits ? (string)$this->template->id : (int)$this->template->id;
        $schedule->extra_vars = self::EXTRA_VARS;

        $masked = json_decode((string)ScheduleSecrets::maskedExtraVars($schedule), true);

        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], $masked);
    }
}
