<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Schedule;
use app\services\ScheduleSecrets;
use app\tests\integration\DbTestCase;

/**
 * A schedule whose password answer is a number JSON allows but PHP cannot
 * store: 1e999 decodes to INF, which json_encode() cannot write. The web
 * form and the API show the reason from SurveySecrets::seal() as the
 * extra_vars error, and nothing is assigned.
 *
 * Regression: the error called such a number "characters that are not
 * valid UTF-8".
 */
class ScheduleSecretsEncodingTest extends DbTestCase
{
    public function testAPasswordAnswerTooLargeForJsonIsNamedAsSuch(): void
    {
        $userId = (int)$this->createUser('schedule-encoding')->id;
        $template = $this->createPasswordSurveyTemplate($userId);
        $schedule = new Schedule();
        $schedule->name = 'schedule-encoding-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->extra_vars = '{"app_env":"prod","db_password":1e999}';
        $schedule->created_by = $userId;

        $error = (new ScheduleSecrets())->prepare($schedule, true);

        $this->assertSame('The password answers cannot be stored: they contain a number too large for JSON.', $error);
        $this->assertSame('{"app_env":"prod","db_password":1e999}', $schedule->extra_vars, 'nothing is assigned');
        $this->assertNull($schedule->getSealedSecretExtraVars());
    }
}
