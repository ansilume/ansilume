<?php

declare(strict_types=1);

namespace app\commands;

use app\components\SurveyField;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\RunnerGroup;
use app\models\Schedule;

/**
 * Fixtures for tests/e2e/tests/schedules/unusable-password-name.spec.ts,
 * schedules/rbac.spec.ts and job-templates/survey-launch.spec.ts: a job
 * template saved before the survey rules whose password field has a name
 * that cannot carry an answer, and a schedule of it that holds the answer in
 * plaintext, as releases up to 2.8.1 stored it.
 *
 * - e2e-unusable-name-template: survey app_env (text) and contraseña_db
 *   (password, a letter outside ASCII). Every launch of it is refused until
 *   the field is renamed; the name is a secret key all the same.
 * - e2e-unusable-name-schedule: disabled, with PLAIN_ANSWER in plaintext
 *   under that name and no sealed answers. Its page, edit form and API show
 *   $encrypted$; a save seals the answer.
 *
 * Both are restored by name on every seed, the schedule with its plaintext
 * answer again. They have their own project, static inventory and runner
 * group, which no runner serves. The seeder runs first, so its rows get the
 * lowest ids and stay off the first page of lists sorted newest first. The
 * answer is an E2E test value, not a secret.
 */
final class E2eUnusablePasswordNameSeeder
{
    public const PROJECT = 'e2e-unusable-name-project';
    public const INVENTORY = 'e2e-unusable-name-inventory';
    public const GROUP = 'e2e-unusable-name-group';
    public const TEMPLATE = 'e2e-unusable-name-template';
    public const SCHEDULE = 'e2e-unusable-name-schedule';
    public const TEXT_FIELD = 'app_env';
    /** A password field name with a letter outside ASCII, which cannot carry an answer. */
    public const PASSWORD_FIELD = 'contraseña_db';
    /** The answer the schedule holds in plaintext; tests/e2e/tests/schedules/unusable-password-name.spec.ts has the same value. */
    public const PLAIN_ANSWER = 'e2e-unusable-name-answer-Tz58';

    /** No host a runner could reach: no runner serves the group anyway. */
    private const INVENTORY_CONTENT = "all:\n  hosts:\n    e2e-unusable-name-host.example.test:\n";

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId): void
    {
        $group = $this->runnerGroup($userId);
        $project = E2eFixtureHelper::project(self::PROJECT, $userId);
        $inventory = E2eFixtureHelper::inventory(self::INVENTORY, Inventory::TYPE_STATIC, null, $userId, self::INVENTORY_CONTENT);
        $template = E2eFixtureHelper::surveyTemplate(self::TEMPLATE, [(int)$project->id, (int)$inventory->id, (int)$group->id], $userId, self::surveyFields(), 0);
        $schedule = $this->schedule($template, $userId);

        ($this->logger)(sprintf(
            "  Restored unusable password name fixtures: template '%s' (ID %d), schedule '%s' (ID %d).\n",
            self::TEMPLATE,
            $template->id,
            self::SCHEDULE,
            $schedule->id
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function surveyFields(): array
    {
        return [
            [
                'name' => self::TEXT_FIELD,
                'label' => 'Environment',
                'type' => SurveyField::TYPE_TEXT,
                'required' => true,
                'default' => 'staging',
                'options' => [],
                'hint' => '',
            ],
            [
                'name' => self::PASSWORD_FIELD,
                'label' => 'Database password',
                'type' => SurveyField::TYPE_PASSWORD,
                'required' => false,
                'default' => '',
                'options' => [],
                'hint' => 'Saved before the survey rules: this name cannot carry an answer.',
            ],
        ];
    }

    private function runnerGroup(int $userId): RunnerGroup
    {
        $group = RunnerGroup::findOne(['name' => self::GROUP]) ?? new RunnerGroup();
        $group->name = self::GROUP;
        $group->description = 'E2E: no real runner serves this group';
        $group->created_by = $userId;
        $group->save(false);

        return $group;
    }

    /**
     * Restores the disabled schedule with PLAIN_ANSWER in plaintext and no
     * sealed answers.
     */
    private function schedule(JobTemplate $template, int $userId): Schedule
    {
        $schedule = Schedule::findOne(['name' => self::SCHEDULE]) ?? new Schedule();
        $schedule->name = self::SCHEDULE;
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 0 1 1 *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = false;
        $schedule->created_by = $userId;
        $schedule->extra_vars = (string)json_encode(
            [self::TEXT_FIELD => 'prod', self::PASSWORD_FIELD => self::PLAIN_ANSWER],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $schedule->setSealedSecretExtraVars(null);
        $schedule->computeNextRunAt();
        $schedule->save(false);

        return $schedule;
    }
}
