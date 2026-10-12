<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eSurveyTemplateSeeder;
use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

/**
 * The survey template of survey-launch.spec.ts (E2eSurveyTemplateSeeder):
 * one field of every launch form input, the password field optional, so a
 * launch may leave it empty. The rollback removes it.
 */
class E2eSurveyTemplateSeederTest extends DbTestCase
{
    private const NAME = 'e2e-survey-template';

    /** @var list<string> */
    private array $log = [];

    public function testTheSurveyHasAnOptionalPasswordFieldWithoutDefault(): void
    {
        $id = $this->seed();

        $template = JobTemplate::findOne($id);
        $this->assertInstanceOf(JobTemplate::class, $template);
        $this->assertSame(self::NAME, $template->name);
        $survey = json_decode((string)$template->survey_fields, true);
        $this->assertIsArray($survey);
        $this->assertSame(
            [
                ['target_env', 'text', true, 'staging'],
                ['dry_run', 'boolean', false, '0'],
                ['log_level', 'select', true, 'info'],
                [E2eSurveyTemplateSeeder::PASSWORD_FIELD, 'password', false, ''],
            ],
            array_map(static fn (array $field): array => [$field['name'], $field['type'], $field['required'], $field['default']], $survey)
        );
        $this->assertSame([E2eSurveyTemplateSeeder::PASSWORD_FIELD], $template->passwordFieldNames());
        $this->assertSame(["  Created survey template '" . self::NAME . "' (ID {$id}) with 4 survey fields.\n"], $this->log);
    }

    public function testSeedingAgainReplacesTheTemplate(): void
    {
        $first = $this->seed();

        $second = $this->seed();

        $this->assertNotSame($first, $second);
        $this->assertNull(JobTemplate::findWithDeleted()->where(['id' => $first])->one());
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => self::NAME])->count());
    }

    private function seed(): int
    {
        $userId = (int)$this->createUser('admin')->id;

        return (new E2eSurveyTemplateSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed(
            $userId,
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createCredential($userId)->id,
            (int)$this->createRunnerGroup($userId)->id
        );
    }
}
