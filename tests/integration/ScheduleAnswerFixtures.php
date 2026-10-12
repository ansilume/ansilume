<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\components\SurveySecrets;
use app\models\Schedule;

/**
 * Schedules with stored password answers; use in DbTestCase subclasses.
 */
trait ScheduleAnswerFixtures
{
    /**
     * A schedule of job template $templateId as a save with password
     * answers stores it: extra_vars holds $public plus the placeholder for
     * every key of $answers, secret_extra_vars holds $answers sealed.
     *
     * @param array<string, mixed> $answers password answers by key, any JSON type
     * @param array<string, mixed> $public the other extra vars
     */
    protected function createScheduleWithAnswers(
        int $templateId,
        int $createdBy,
        array $answers,
        array $public = ['app_env' => 'prod'],
        ?int $nextRunAt = null
    ): Schedule {
        $split = SurveySecrets::split(array_merge($public, $answers), array_map('strval', array_keys($answers)));
        $schedule = $this->createPlainSchedule($templateId, $createdBy, $split['public'] === [] ? null : (string)json_encode($split['public']), $nextRunAt);
        $schedule->setSealedSecretExtraVars(SurveySecrets::seal($split['secret']));
        $schedule->save(false);

        return $this->reloadSchedule($schedule);
    }

    /**
     * A schedule whose extra vars are stored as given, with no sealed answers.
     */
    protected function createPlainSchedule(int $templateId, int $createdBy, ?string $extraVars, ?int $nextRunAt = null): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'schedule-answers-' . uniqid('', true);
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->extra_vars = $extraVars;
        $schedule->next_run_at = $nextRunAt;
        $schedule->created_by = $createdBy;
        $schedule->save(false);

        return $this->reloadSchedule($schedule);
    }

    protected function reloadSchedule(Schedule $schedule): Schedule
    {
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    /**
     * The answers the schedule has stored sealed, opened.
     *
     * @return array<string, mixed>
     */
    protected function sealedAnswersOf(Schedule $schedule): array
    {
        return SurveySecrets::open($this->reloadSchedule($schedule)->getSealedSecretExtraVars());
    }

    /**
     * The extra vars the schedule has stored, decoded.
     *
     * @return mixed
     */
    protected function storedExtraVarsOf(Schedule $schedule): mixed
    {
        return json_decode((string)$this->reloadSchedule($schedule)->extra_vars, true);
    }
}
