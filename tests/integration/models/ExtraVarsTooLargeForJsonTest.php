<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\components\SurveySecrets;
use app\components\WorkflowStepMapping;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\WorkflowStep;
use app\services\JobLaunchRejectedException;
use app\services\JobLaunchSecrets;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;

/**
 * Extra vars that json_decode() reads but json_encode() cannot write back
 * are refused when they are saved, on every attribute that holds extra vars:
 * JobTemplate.extra_vars, Schedule.extra_vars and
 * WorkflowStep.extra_vars_template.
 *
 * Regression: JSON allows a number too large for it, such as 1e999 or
 * -1e999, which PHP reads as infinity. The rules took such text for valid
 * JSON and stored it, and then every launch of the template or the schedule
 * was refused ("The extra vars cannot be stored: they contain a number too
 * large for JSON."). A save now gets that message instead.
 */
class ExtraVarsTooLargeForJsonTest extends DbTestCase
{
    /** The launch's refusal: SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN). */
    private const MESSAGE = 'The extra vars cannot be stored: they contain a number too large for JSON.';

    /**
     * @return array<string, array{0: string}>
     */
    public static function unencodableProvider(): array
    {
        return [
            'a number too large' => ['{"timeout_s": 1e999}'],
            'a negative one' => ['{"timeout_s": -1e999}'],
            'with a capital E and a sign' => ['{"timeout_s": 1E+999}'],
            'just above the largest double' => ['{"timeout_s": 1.8e308}'],
            'in a list' => ['{"ports": [80, 1e400]}'],
            'nested in objects' => ['{"limits": {"cpu": {"max": 2e308}}}'],
            'a list at the top' => ['[1, 1e999]'],
            'a number at the top' => ['1e999'],
            'among other keys' => ['{"app_env": "prod", "huge": 1e999, "region": "eu"}'],
        ];
    }

    /**
     * Numbers JSON and a launch can carry: they are stored.
     *
     * @return array<string, array{0: string}>
     */
    public static function storableProvider(): array
    {
        return [
            'an integer above 2^53' => ['{"build_id": 9007199254740993}'],
            'an integer above PHP_INT_MAX' => ['{"build_id": 123456789012345678901234567890}'],
            'the largest double' => ['{"x": 1.7976931348623157e308}'],
            'a number too small for a double' => ['{"x": 1e-999}'],
            'negative zero' => ['{"x": -0.0}'],
            'a whole number written as a float' => ['{"cpu_ratio": 1.0}'],
            'text that names infinity' => ['{"x": "1e999", "y": "Infinity"}'],
            // The rules skip null and '', but not "0", which empty() takes for nothing.
            'zero' => ['0'],
        ];
    }

    /**
     * @dataProvider unencodableProvider
     */
    public function testAJobTemplateRefusesThem(string $json): void
    {
        $template = $this->template();
        $template->extra_vars = $json;

        $this->assertFalse($template->validate(['extra_vars']));
        $this->assertSame([self::MESSAGE], $template->getErrors('extra_vars'));
    }

    /**
     * @dataProvider unencodableProvider
     */
    public function testAScheduleRefusesThem(string $json): void
    {
        $schedule = $this->schedule($json);

        $this->assertFalse($schedule->validate(['extra_vars']));
        $this->assertSame([self::MESSAGE], $schedule->getErrors('extra_vars'));
    }

    /**
     * @dataProvider unencodableProvider
     */
    public function testAWorkflowStepRefusesThem(string $json): void
    {
        $step = new WorkflowStep();
        $step->extra_vars_template = $json;

        $this->assertFalse($step->validate(['extra_vars_template']));
        $this->assertSame([self::MESSAGE], $step->getErrors('extra_vars_template'));
    }

    /**
     * The JSON rule of every model takes them. A step's variable mapping
     * maps names to names, so its shape rule refuses a number there
     * (WorkflowStepMapping::INVALID, not the message of a number too large).
     *
     * @dataProvider storableProvider
     */
    public function testEveryModelStoresNumbersJsonCanHold(string $json): void
    {
        $template = $this->template();
        $template->extra_vars = $json;
        $schedule = $this->schedule($json);
        $step = new WorkflowStep();
        $step->extra_vars_template = $json;
        $step->validateJson('extra_vars_template');

        $this->assertTrue($template->validate(['extra_vars']), (string)json_encode($template->getErrors()));
        $this->assertTrue($schedule->validate(['extra_vars']), (string)json_encode($schedule->getErrors()));
        $this->assertSame([], $step->getErrors(), 'the JSON rule of a step takes them');
        $this->assertSame(
            WorkflowStepMapping::isValid($json) ? [] : [WorkflowStepMapping::INVALID],
            $step->validate(['extra_vars_template']) ? [] : $step->getErrors('extra_vars_template'),
            'the mapping\'s shape decides'
        );
    }

    /**
     * Text that is no JSON keeps the message each model had.
     */
    public function testInvalidJsonKeepsItsMessage(): void
    {
        $template = $this->template();
        $template->extra_vars = '{"timeout_s": }';
        $schedule = $this->schedule('{"timeout_s": }');
        $step = new WorkflowStep();
        $step->extra_vars_template = '{"timeout_s": }';

        $template->validate(['extra_vars']);
        $schedule->validate(['extra_vars']);
        $step->validate(['extra_vars_template']);

        $this->assertSame(['Extra_vars must be valid JSON.'], $template->getErrors('extra_vars'));
        $this->assertSame(['Must be valid JSON.'], $schedule->getErrors('extra_vars'));
        $this->assertSame(['Extra_vars_template must be valid JSON.'], $step->getErrors('extra_vars_template'));
    }

    /**
     * The save gives the message a launch refuses the same extra vars with.
     */
    public function testTheMessageIsTheOneALaunchRefusesSuchExtraVarsWith(): void
    {
        $launchMessage = SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN);
        $this->assertSame($launchMessage, self::MESSAGE);
        $template = $this->template();
        $template->extra_vars = '{"timeout_s": 1e999}';
        $this->assertTrue($template->save(false), 'stored like a template saved before the rule');

        try {
            (new JobLaunchService())->launch($template, (int)$template->created_by);
            $this->fail('the launch must be refused');
        } catch (JobLaunchRejectedException $e) {
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }
        $this->assertFalse($template->validate(['extra_vars']));
        $this->assertSame([self::MESSAGE], $template->getErrors('extra_vars'));
    }

    /**
     * A template stored with such extra vars before the rule gets the error
     * on its next save, whatever else changes: its launches fail already.
     */
    public function testALegacyTemplateGetsTheErrorOnItsNextSave(): void
    {
        $template = $this->template();
        $template->extra_vars = '{"timeout_s": 1e999}';
        $this->assertTrue($template->save(false));
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);

        $stored->description = 'only the description changes';

        $this->assertFalse($stored->save());
        $this->assertSame([self::MESSAGE], $stored->getErrors('extra_vars'));
        $unchanged = JobTemplate::findOne($template->id);
        $this->assertSame('{"timeout_s": 1e999}', $unchanged?->extra_vars, 'nothing is stored');
    }

    public function testALegacyScheduleGetsTheErrorOnItsNextSave(): void
    {
        $schedule = $this->schedule('{"retries": -1e999}');
        $this->assertTrue($schedule->save(false));
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);

        $stored->name = 'renamed-' . $schedule->id;

        $this->assertFalse($stored->save());
        $this->assertSame([self::MESSAGE], $stored->getErrors('extra_vars'));
    }

    /**
     * The rule runs in validate(), which the forms and the API call before
     * ScheduleSecrets seals the password answers: an answer like that gets
     * the extra vars message too, and nothing reaches the sealing.
     */
    public function testAPasswordAnswerTooLargeForJsonGetsTheSameMessage(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->createUser('inf-answer')->id);
        $schedule = $this->schedule('{"app_env": "prod", "db_password": 1e999}');
        $schedule->job_template_id = $template->id;

        $this->assertFalse($schedule->validate());
        $this->assertSame(['extra_vars' => [self::MESSAGE]], $schedule->getErrors());
        $this->assertNull($schedule->getSealedSecretExtraVars());
    }

    /**
     * Only extra vars: the survey definition is not launched as extra vars,
     * and its own rules decide about it.
     */
    public function testTheSurveyDefinitionIsNotCheckedAsExtraVars(): void
    {
        $template = $this->template();
        $template->survey_fields = '[{"name": "retries", "type": "integer", "default": 1e999}]';

        $template->validate(['survey_fields']);

        $this->assertNotContains(self::MESSAGE, $template->getErrors('survey_fields'));
    }

    private function template(): JobTemplate
    {
        $userId = (int)$this->createUser('inf-template')->id;

        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function schedule(string $extraVars): Schedule
    {
        $template = $this->template();
        $schedule = new Schedule();
        $schedule->name = 'inf-schedule-' . uniqid('', true);
        $schedule->job_template_id = $template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->extra_vars = $extraVars;
        $schedule->created_by = (int)$template->created_by;

        return $schedule;
    }
}
