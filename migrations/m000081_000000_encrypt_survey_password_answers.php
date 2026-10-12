<?php

declare(strict_types=1);

use app\components\SurveySecretBackfill;
use app\services\MaintenanceService;
use yii\db\Migration;

/**
 * Encrypt the survey password answers that earlier releases stored in
 * plaintext, so the placeholder $encrypted$ stands for them everywhere:
 * - job templates: password fields lose their default (launches never used
 *   it); templates whose password field has a name that cannot carry an
 *   answer, their schedules, and templates whose own extra vars hold a
 *   password field's name are listed for the operator;
 * - schedules and unfinished jobs (pending, queued, awaiting approval,
 *   running): the answers move into secret_extra_vars (m000080), sealed.
 *
 * The history of finished jobs, which can be large, is encrypted in the
 * background by maintenance/run, so container start stays short. Its
 * position and done mark live in the cache; they are reset here, because
 * this migration runs whenever the data may be from before the release
 * (an update, or a restored backup).
 *
 * up(), not safeUp(): the work commits one page of rows at a time, so a
 * run that is interrupted keeps what it did, and running it again finishes
 * the rest (every step skips rows that are done). The logic lives in
 * SurveySecretBackfill, which logs and prints counts and IDs only, never a
 * value or an SQL statement; no Migration helper is used, as they print
 * their SQL.
 */
class m000081_000000_encrypt_survey_password_answers extends Migration
{
    public function up(): bool
    {
        (new MaintenanceService())->resetSurveySecretScrub();
        $backfill = new SurveySecretBackfill($this->db, static function (string $line): void {
            echo '    > ' . $line . "\n";
        });

        return $backfill->runMigration();
    }

    public function down(): bool
    {
        // Nothing to revert: the answers stay sealed. Writing them back in
        // plaintext would undo the point of the release, and the schema is
        // unchanged (m000080 owns the columns).
        return true;
    }
}
