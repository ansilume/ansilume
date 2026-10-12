<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\components\SurveySecrets;
use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

/**
 * JobTemplate::passwordFieldNames(), the keys whose stored values are
 * password answers: every password field name, usable or not. Schedules
 * (ScheduleSecrets, ScheduleLaunchSecrets) take their secret keys from it.
 * The launch refuses a template with an unusable name (JobLaunchSecrets),
 * which is all that usability decides.
 *
 * Regression (R2V2-1): it returned the usable names only, so once every
 * name outside printable ASCII was unusable ("contraseña_db"), a
 * schedule's answer under such a name was neither sealed nor masked.
 */
class JobTemplatePasswordFieldNamesTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('password-field-names')->id;
    }

    public function testEveryPasswordFieldNameIsReturnedUsableOrNot(): void
    {
        $template = $this->templateWith((string)json_encode([
            ['name' => 'db_password', 'type' => 'password'],
            ['name' => 'contraseña_db', 'type' => 'password'],
            ['name' => 'app_env', 'type' => 'text'],
            ['name' => "db_password\u{00A0}", 'type' => 'password'],
            ['name' => '0', 'type' => 'password'],
            ['name' => 'db[pw]', 'type' => 'password'],
        ]), null);

        $this->assertSame(['db_password', 'contraseña_db', "db_password\u{00A0}", '0', 'db[pw]'], $template->passwordFieldNames());
        $this->assertSame(['db_password'], JobTemplate::passwordFieldsFromJson($template->survey_fields)['usable'], 'only one can be launched with');
    }

    public function testATemplateWithoutPasswordFieldsHasNone(): void
    {
        $this->assertSame([], $this->templateWith('[{"name":"app_env","type":"text"}]', null)->passwordFieldNames());
        $this->assertSame([], $this->templateWith(null, null)->passwordFieldNames());
    }

    /**
     * The template page shows a stored default of a password field named
     * "0" as the placeholder, in a JSON object.
     *
     * Regression (task 3 of R2V2-1): json_decode() made the key 0, and
     * json_encode() wrote [0 => ...] as a list, so the page showed [...]
     * for {"0": ...}.
     */
    public function testTheMaskedExtraVarsOfAPasswordFieldNamedZeroStayAnObject(): void
    {
        $template = $this->templateWith('[{"name":"0","type":"password"}]', '{"0":"legacy-default-4417"}');

        $this->assertSame("{\n    \"0\": \"" . SurveySecrets::PLACEHOLDER . "\"\n}", $template->getMaskedExtraVars());
    }

    /**
     * A template saved before the survey rules, stored as given.
     */
    private function templateWith(?string $survey, ?string $extraVars): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $template->survey_fields = $survey;
        $template->extra_vars = $extraVars;
        $this->assertTrue($template->save(false));
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);

        return $stored;
    }
}
