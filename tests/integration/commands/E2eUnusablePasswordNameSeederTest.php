<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eUnusablePasswordNameSeeder;
use app\components\SurveySecrets;
use app\models\JobTemplate;
use app\models\Schedule;
use app\services\JobLaunchRejectedException;
use app\services\JobLaunchService;
use app\services\ScheduleSecrets;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of tests/e2e/tests/schedules/unusable-password-name.spec.ts
 * (E2eUnusablePasswordNameSeeder): a template saved before the survey rules
 * whose password field name, contraseña_db, cannot carry an answer, and a
 * disabled schedule of it with the answer in plaintext under that name, as
 * 2.8.1 stored it. The rollback removes them.
 */
class E2eUnusablePasswordNameSeederTest extends DbTestCase
{
    private int $adminId;

    /** @var list<string> */
    private array $log = [];

    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'e2e-unusable-name-seeder-key-0123456789';
        $this->adminId = (int)$this->createUser('admin')->id;
        $this->log = [];
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

    public function testTheTemplateHasAPasswordFieldWhoseNameCannotCarryAnAnswer(): void
    {
        $this->seed();

        $template = $this->template();
        $this->assertSame(
            ['usable' => [], 'unusable' => [E2eUnusablePasswordNameSeeder::PASSWORD_FIELD]],
            JobTemplate::passwordFieldsFromJson($template->survey_fields)
        );
        $this->assertSame([E2eUnusablePasswordNameSeeder::PASSWORD_FIELD], $template->passwordFieldNames(), 'a secret key all the same');
        $this->assertStringContainsString('"contraseña_db"', (string)$template->survey_fields, 'stored as the name, not escaped');
        $this->assertSame(
            [E2eUnusablePasswordNameSeeder::PROJECT, E2eUnusablePasswordNameSeeder::INVENTORY, E2eUnusablePasswordNameSeeder::GROUP],
            [$template->project?->name, $template->inventory?->name, $template->runnerGroup?->name]
        );
    }

    /**
     * The spec's launch: refused with the name as messages quote it.
     */
    public function testALaunchOfTheTemplateIsRefusedWithTheNameEscaped(): void
    {
        $this->seed();

        try {
            (new JobLaunchService())->launch($this->template(), $this->adminId, ['survey' => ['app_env' => 'prod', 'contraseña_db' => 'typed']]);
            $this->fail('the launch was not refused');
        } catch (JobLaunchRejectedException $e) {
            $this->assertSame(
                'Survey field "contrase\\u00f1a_db" has a name that cannot be used for a password answer; rename it in the survey before launching.',
                $e->getMessage()
            );
        }
    }

    /**
     * The schedule holds the answer as 2.8.1 stored it, and its page, form
     * and API show the placeholder; the edit form saved unchanged seals it.
     */
    public function testTheScheduleHoldsThePlainAnswerWhichAnUnchangedSaveSeals(): void
    {
        $this->seed();

        $schedule = $this->schedule();
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertSame((int)$this->template()->id, (int)$schedule->job_template_id);
        $this->assertSame(
            ['app_env' => 'prod', E2eUnusablePasswordNameSeeder::PASSWORD_FIELD => E2eUnusablePasswordNameSeeder::PLAIN_ANSWER],
            json_decode((string)$schedule->extra_vars, true)
        );
        $this->assertNull($schedule->getSealedSecretExtraVars());
        $masked = (string)ScheduleSecrets::maskedExtraVars($schedule);
        $this->assertSame(['app_env' => 'prod', E2eUnusablePasswordNameSeeder::PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode($masked, true));

        $schedule->extra_vars = $masked;
        $this->assertNull((new ScheduleSecrets())->prepare($schedule, true));
        $this->assertTrue($schedule->save(false));

        $this->assertSame([E2eUnusablePasswordNameSeeder::PASSWORD_FIELD => E2eUnusablePasswordNameSeeder::PLAIN_ANSWER], SurveySecrets::open($this->schedule()->getSealedSecretExtraVars()));
    }

    /**
     * Seeding again restores the plaintext answer that a spec's save sealed,
     * on the same rows.
     */
    public function testSeedingAgainRestoresTheFixtures(): void
    {
        $this->seed();
        $ids = [(int)$this->template()->id, (int)$this->schedule()->id];
        $schedule = $this->schedule();
        $schedule->extra_vars = (string)ScheduleSecrets::maskedExtraVars($schedule);
        $this->assertNull((new ScheduleSecrets())->prepare($schedule, true));
        $schedule->enabled = true;
        $this->assertTrue($schedule->save(false));

        $this->seed();

        $restored = $this->schedule();
        $this->assertSame($ids, [(int)$this->template()->id, (int)$restored->id]);
        $this->assertNull($restored->getSealedSecretExtraVars());
        $this->assertFalse((bool)$restored->enabled);
        $this->assertStringContainsString(E2eUnusablePasswordNameSeeder::PLAIN_ANSWER, (string)$restored->extra_vars);
        $this->assertSame(1, (int)JobTemplate::find()->where(['name' => E2eUnusablePasswordNameSeeder::TEMPLATE])->count());
        $this->assertSame(1, (int)Schedule::find()->where(['name' => E2eUnusablePasswordNameSeeder::SCHEDULE])->count());
        $line = sprintf(
            "  Restored unusable password name fixtures: template '%s' (ID %d), schedule '%s' (ID %d).\n",
            E2eUnusablePasswordNameSeeder::TEMPLATE,
            $ids[0],
            E2eUnusablePasswordNameSeeder::SCHEDULE,
            $ids[1]
        );
        $this->assertSame([$line, $line], $this->log);
    }

    private function seed(): void
    {
        (new E2eUnusablePasswordNameSeeder(function (string $line): void {
            $this->log[] = $line;
        }))->seed($this->adminId);
    }

    private function template(): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => E2eUnusablePasswordNameSeeder::TEMPLATE]);
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    private function schedule(): Schedule
    {
        $schedule = Schedule::findOne(['name' => E2eUnusablePasswordNameSeeder::SCHEDULE]);
        $this->assertInstanceOf(Schedule::class, $schedule);

        return $schedule;
    }
}
