<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\controllers\api\v1\TeamsController;
use app\controllers\TeamController;
use app\models\AnalyticsQuery;
use app\models\JobSearchForm;
use app\services\WorkflowStepWriteService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use yii\db\ActiveQuery;

/**
 * Contract: what the section of docs/updating.md on PHP 8.5, the
 * troubleshooting entry on numbers and the spec say that 2.13.1 and this
 * release answer, in the words of the code: to a date of only whitespace
 * and to a date that is not in YYYY-MM-DD form, and to a number that is no
 * whole number, posted as JSON to the web actions and sent to the API.
 *
 * The history of 2.13.1 is fixed text: its date rule answered "The format
 * of Date From is invalid." and "The format of Date To is invalid.", as the
 * date rule of AnalyticsQuery still words them.
 */
class Php85AnswersDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const NUMBER_ENTRY = '## An API call or a form refuses a number: it must be a whole number';

    /**
     * Regression (verification of the PHP 8.5 move): the section said that
     * 2.13.1's analytics reports answered "The format of Date From is
     * invalid." to a date_to of only whitespace too, and that the job list
     * ignores any date that is not in YYYY-MM-DD form, where it ignores the
     * error and applies the date as strtotime() reads it. The spec said
     * that 2.13.1 listed no job for any date of only whitespace, where such
     * a date_to (strtotime("  23:59:59"), the end of today) listed every job.
     */
    public function testTheDocsSayWhat2131AnsweredToADateOfOnlyWhitespace(): void
    {
        $section = $this->flat('docs/updating.md', Php85UpdateDocsContractTest::UPDATE_SECTION);
        $from = self::formatError('date_from');
        $to = self::formatError('date_to');
        $this->assertSame(['The format of Date From is invalid.', 'The format of Date To is invalid.'], [$from, $to]);
        $this->assertStringContainsString("'Invalid filter parameters.'", $this->read('controllers/AnalyticsController.php'));
        $this->assertSame(['>=', 'created_at', strtotime('27.10.2026')], $this->jobListCondition('27.10.2026'), 'The job list applies such a date.');

        foreach (
            [
                "where `GET /api/v1/analytics/{report}` answered `422` `{$from}` (`{$to}` for `date_to`) to a date of only"
                    . ' whitespace, the page showed that error without its reports, and the export went back to it with'
                    . ' `Invalid filter parameters.`',
                'while the job list ignores that error, as it ignores the error of any date not in `YYYY-MM-DD` form,'
                    . ' and applies the date as PHP\'s `strtotime()` reads it.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        foreach (['where both answered `422`', 'as it ignores any date not in'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $section, 'The update notes no longer say so.');
        }

        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $jobs = (string)preg_replace('/\s+/', ' ', (string)($spec['paths']['/api/v1/jobs']['get']['description'] ?? ''));
        $this->assertMentions(
            'a date of only whitespace is no filter, where releases up to 2.13.1 listed no job for a `date_from` of only'
            . ' whitespace and every job up to the end of today for such a `date_to`;',
            $jobs,
            'GET /api/v1/jobs'
        );
        $this->assertStringNotContainsString('listed no job for it;', $jobs, 'The spec no longer says so.');
    }

    /**
     * Regression (verification of the PHP 8.5 move): the symptom ended with
     * "a web form posted as JSON shows the field's error", where the team's
     * page answers 400, the runner pages show a flash, and the launch of a
     * workflow and the move of one of its steps answer 404; and the 404s of
     * the team members and projects and of a runner's move are the API's.
     */
    public function testTheSymptomQuotesWhatTheApiAndTheWebActionsAnswer(): void
    {
        $symptom = $this->between($this->flat('docs/troubleshooting.md', self::NUMBER_ENTRY), '**Symptom:**', '**Cause:**');
        $literals = [
            'controllers/api/v1/RunnersController.php' => "'Target runner group not found.'",
            'controllers/RunnerController.php' => "'Runner group not found.'",
            'controllers/WorkflowTemplateController.php' => '"Workflow template #{$id} not found."',
        ];
        foreach ($literals as $file => $literal) {
            $this->assertStringContainsString($literal, $this->read($file), $file);
        }
        $this->assertStringContainsString("'Target runner group not found.'", $this->read('controllers/RunnerController.php'));

        foreach (
            [
                'the API\'s `POST /api/v1/teams/{id}/members` and `/projects` answer `404` `'
                    . sprintf(TeamsController::USER_NOT_FOUND, 0) . '` or `' . sprintf(TeamsController::PROJECT_NOT_FOUND, 0) . '`,'
                    . ' and `POST /api/v1/runners/{id}/move` answers `404` `Target runner group not found.`;',
                'posted as JSON, the project, inventory, job template, schedule and approval rule forms show the field\'s'
                    . ' error; adding a team member or a project on the team\'s page answers `400` `'
                    . TeamController::USER_ID_REQUIRED . '` or `' . TeamController::PROJECT_ID_REQUIRED . '`;',
                'registering or moving a runner shows `Runner group not found.` or `Target runner group not found.`;',
                'launching a workflow answers `404` `Workflow template #0 not found.`, and moving one of its steps answers'
                    . ' `404` `' . sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, 5, 0) . '` for workflow 5.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $symptom, 'The symptom');
        }
        $this->assertStringNotContainsString(
            'a web form posted as JSON shows the field\'s error.',
            $symptom,
            'Not every web action posted as JSON shows a field\'s error.'
        );
    }

    /**
     * The error the analytics reports' date rule gives $attribute for a
     * value that is not in YYYY-MM-DD form.
     */
    private static function formatError(string $attribute): string
    {
        $query = new AnalyticsQuery();
        $query->$attribute = 'x';
        $query->validate([$attribute]);

        return (string)$query->getFirstError($attribute);
    }

    /**
     * The condition the job list adds for a date_from of $date: the list is
     * built, not run.
     */
    private function jobListCondition(string $date): mixed
    {
        $query = (new JobSearchForm())->search(['date_from' => $date])->query;
        $this->assertInstanceOf(ActiveQuery::class, $query);

        return $query->where;
    }
}
