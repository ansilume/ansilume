<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\SurveySecretBackfill;
use app\components\SurveySecrets;
use app\tests\integration\DbTestCase;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\unit\components\UnusablePasswordNames;

/**
 * m000081 (SurveySecretBackfill::runMigration()) lists a job template whose
 * password field has a name with a character outside ASCII around or in it
 * among those that cannot be launched until the field is renamed, with its
 * schedules. It seals the answer stored under that exact name, as 2.8.1's
 * launch form stored it, and leaves a value under another spelling of the
 * name (the visible "db_password") as it is.
 *
 * Regression (V2-C1U): such a name counted as usable, so the migration did
 * not list the template.
 *
 * Regression (R2V2-1): once such a name was unusable, the migration no
 * longer sealed the answer stored under the exact name.
 */
class SurveySecretBackfillUnusableNameTest extends DbTestCase
{
    use ScheduleAnswerFixtures;
    use UnusablePasswordNames;

    private const ANSWER = 'backfill-unusable-name-marker-5307';

    private const OTHER_SPELLING = 'backfill-other-spelling-marker-6604';

    private mixed $originalKey;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'survey-backfill-unusable-name-key-0123';
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        \Yii::$app->db->enableLogging = true;
        \Yii::$app->db->enableProfiling = true;
        parent::tearDown();
    }

    /**
     * @dataProvider unicodeNameProvider
     */
    public function testTheMigrationListsTheTemplateAndSealsOnlyTheAnswerUnderItsExactName(string $name): void
    {
        $userId = (int)$this->createUser('backfill-unusable')->id;
        $template = $this->createPasswordSurveyTemplate($userId);
        $survey = (string)json_encode([['name' => self::SURVEY_TEXT_FIELD, 'type' => 'text'], ['name' => $name, 'type' => 'password']]);
        \Yii::$app->db->createCommand()->update('{{%job_template}}', ['survey_fields' => $survey], ['id' => $template->id])->execute();
        $extraVars = (string)json_encode(['app_env' => 'prod', 'db_password' => self::OTHER_SPELLING, $name => self::ANSWER]);
        $schedule = $this->createPlainSchedule((int)$template->id, $userId, $extraVars);

        $this->assertTrue((new SurveySecretBackfill(\Yii::$app->db, function (string $line): void {
            $this->lines[] = $line;
        }))->runMigration());

        $this->assertContains(sprintf(
            'Job templates with a password field whose name cannot carry an answer; they cannot be launched until the field is renamed '
            . 'in the survey editor: 1 (#%d).',
            $template->id
        ), $this->lines);
        $this->assertContains(sprintf('Schedules that launch those job templates: 1 (#%d).', $schedule->id), $this->lines);
        $stored = $this->reloadSchedule($schedule);
        $this->assertSame(
            ['app_env' => 'prod', 'db_password' => self::OTHER_SPELLING, $name => SurveySecrets::PLACEHOLDER],
            json_decode((string)$stored->extra_vars, true),
            'the answer under the exact name is sealed; another spelling of the name is not found'
        );
        $this->assertSame([$name => self::ANSWER], SurveySecrets::open($stored->getSealedSecretExtraVars()));
        $this->assertStringNotContainsString(self::ANSWER, implode("\n", $this->lines));
    }
}
