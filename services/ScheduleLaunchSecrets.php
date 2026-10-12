<?php

declare(strict_types=1);

namespace app\services;

use app\components\StoredScheduleAnswers;
use app\components\SurveySecretException;
use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;

/**
 * Password answers of a schedule at its scheduled launch: the stored answers
 * travel in the LaunchContext, never in the extra vars.
 *
 * Plain answers (values under a password field name of the job template,
 * usable or not, that the extra vars still hold in plain text: the key
 * became a password field after the schedule was saved, or a release up to
 * 2.8.1 stored it) are sealed in the schedule first, under a lock of its
 * row, and the run launches what the locked row holds. A template with a
 * name that cannot carry an answer is refused by the launch afterwards
 * (JobLaunchSecrets), with its answers sealed.
 * The sealing writes the extra vars and the sealed answers only together, as
 * a save does (see ScheduleSecrets, which stores the answers at a save).
 */
final class ScheduleLaunchSecrets
{
    public const LAUNCH_UNDECRYPTABLE = 'Its stored password answers cannot be decrypted (was APP_SECRET_KEY changed?); '
        . 'edit the schedule and enter them again.';
    public const LAUNCH_SEAL_FAILED = 'Its plain password answers could not be sealed: the schedule could not be saved.';
    public const LAUNCH_SCHEDULE_CHANGED = 'The schedule changed while its run was being launched; it runs again at its next time.';

    /** Reads a schedule row and locks it until the transaction ends. */
    private const LOCK_SQL = 'SELECT * FROM {{%schedule}} WHERE [[id]] = :id FOR UPDATE';

    /**
     * The launch overrides of a scheduled run of $template: the extra vars
     * without the stored answers, which the LaunchContext carries.
     *
     * $schedule is the row as the scheduler read it. Plain answers (keys that
     * became password fields since the schedule was saved) are sealed first,
     * in the row as it is now (see sealPlainAnswers()); the overrides then
     * come from that row.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when the stored answers cannot be decrypted,
     *     plain ones not sealed, or the schedule changed its job template or
     *     was deleted since the scheduler read it
     */
    public function launchOverrides(Schedule $schedule, JobTemplate $template): array
    {
        $names = $template->passwordFieldNames();
        $row = $schedule;
        $stored = self::storedAnswersOf($row, $names);
        if ($stored->plain() !== []) {
            ['row' => $row, 'stored' => $stored] = $this->sealPlainAnswers((int)$schedule->id, (int)$template->id, $names);
        }
        $secrets = self::openAnswers($stored);

        $overrides = [LaunchContext::KEY => LaunchContext::forSchedule((int)$row->id, $secrets)];
        $extraVars = $secrets === [] ? $row->extra_vars : SurveySecrets::withoutKeys($stored->vars(), array_keys($secrets));
        if (!empty($extraVars)) {
            $overrides['extra_vars'] = $extraVars;
        }

        return $overrides;
    }

    /**
     * Seal the plain answers of schedule #$scheduleId, about to launch job
     * template #$templateId, in the row as it is now, and save it; audited
     * with their names.
     *
     * The row is read, sealed and saved in one transaction that locks it
     * (SELECT ... FOR UPDATE). An edit saved before the lock is what gets
     * sealed and launched; one saved meanwhile waits until the transaction
     * has ended, and then writes the extra vars and the sealed answers it
     * computed. A row that is gone, or that now points at another job
     * template than the one being launched, fails the launch: its answers
     * belong to that template. The schedule runs again at its next time.
     *
     * @param list<string> $names every password field name of the job template, usable or not
     * @return array{row: Schedule, stored: StoredScheduleAnswers} the row as locked and its stored answers
     * @throws \RuntimeException
     */
    private function sealPlainAnswers(int $scheduleId, int $templateId, array $names): array
    {
        $transaction = Schedule::getDb()->beginTransaction();
        try {
            $row = Schedule::findBySql(self::LOCK_SQL, [':id' => $scheduleId])->one();
            if (!$row instanceof Schedule || (int)$row->job_template_id !== $templateId) {
                throw new \RuntimeException(self::LAUNCH_SCHEDULE_CHANGED);
            }
            $stored = self::storedAnswersOf($row, $names);
            $sealed = self::sealRow($row, $stored);
            $transaction->commit();
        } catch (\yii\db\Exception $e) {
            $transaction->rollBack();
            throw self::sealFailed($scheduleId, $e);
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        if ($sealed !== []) {
            /** @var AuditService $audit */
            $audit = \Yii::$app->get('auditService');
            $audit->log(AuditLog::ACTION_SCHEDULE_UPDATED, 'schedule', (int)$row->id, null, [
                'name' => $row->name,
                'source' => 'console:schedule/run',
                'job_template_id' => (int)$row->job_template_id,
                'secret_keys_sealed' => $sealed,
            ]);
        }

        return ['row' => $row, 'stored' => $stored];
    }

    /**
     * Seal the plain answers of the locked $row together with its sealed
     * ones, and save its extra vars and sealed answers (nothing else).
     *
     * @return list<string> the keys of the plain answers it sealed; [] when the row has none
     * @throws \RuntimeException when they cannot be sealed or the row not saved
     */
    private static function sealRow(Schedule $row, StoredScheduleAnswers $stored): array
    {
        $plain = array_map('strval', array_keys($stored->plain()));
        if ($plain === []) {
            return [];
        }
        $masked = SurveySecrets::split($stored->vars(), $plain)['public'];
        // In the stored shape: a password field may be named "0", and {"0": ...} must stay an object.
        $public = SurveySecrets::reencode($row->extra_vars, $masked, ScheduleSecrets::JSON_FLAGS);
        if ($public === false || strlen($public) > ScheduleSecrets::MAX_EXTRA_VARS_BYTES) {
            throw new \RuntimeException(self::LAUNCH_SEAL_FAILED);
        }
        $row->setSealedSecretExtraVars(SurveySecrets::seal(self::openAnswers($stored)));
        $row->extra_vars = $public;
        if (!$row->save(false, ['extra_vars', 'secret_extra_vars', 'updated_at'])) {
            throw new \RuntimeException(self::LAUNCH_SEAL_FAILED);
        }

        return $plain;
    }

    /**
     * The exception for a database error while sealing. Its message carries
     * the SQL with the values, so it is neither passed on nor logged: only
     * SQLSTATE and the driver's error code are.
     */
    private static function sealFailed(int $scheduleId, \yii\db\Exception $e): \RuntimeException
    {
        $info = $e->errorInfo;
        \Yii::error(sprintf(
            'Schedule #%d: its plain password answers could not be sealed (SQLSTATE %s, driver error %s).',
            $scheduleId,
            is_scalar($info[0] ?? null) ? (string)$info[0] : 'unknown',
            is_scalar($info[1] ?? null) ? (string)$info[1] : 'unknown'
        ), __CLASS__);

        return new \RuntimeException(self::LAUNCH_SEAL_FAILED);
    }

    /**
     * Every stored answer by key, plain and sealed.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when the sealed answers cannot be decrypted
     */
    private static function openAnswers(StoredScheduleAnswers $stored): array
    {
        try {
            return $stored->values();
        } catch (SurveySecretException) {
            throw new \RuntimeException(self::LAUNCH_UNDECRYPTABLE);
        }
    }

    /**
     * @param list<string> $names every password field name of the schedule's job template, usable or not
     */
    private static function storedAnswersOf(Schedule $schedule, array $names): StoredScheduleAnswers
    {
        return new StoredScheduleAnswers($schedule->extra_vars, $schedule->getSealedSecretExtraVars(), $names);
    }
}
