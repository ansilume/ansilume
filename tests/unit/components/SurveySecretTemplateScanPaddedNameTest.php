<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SurveySecretTemplateScan;
use PHPUnit\Framework\TestCase;

/**
 * The survey password backfill (m000081 and the history scrub) reports a
 * template whose password field name no caller sends exactly (whitespace
 * around it, a control character in it, or any character outside ASCII) as
 * one that cannot be launched until the field is renamed.
 *
 * Regression: such a name ("db_password\n", stored through the API) counted
 * as usable, so the backfill did not report the template.
 *
 * Regression (R2V2-1): once such names were unusable, the backfill no longer
 * sealed the answers releases up to 2.8.1 had stored under the exact name,
 * "contraseña_db" for one. Every password field name is a secret key again.
 */
class SurveySecretTemplateScanPaddedNameTest extends TestCase
{
    use UnusablePasswordNames;

    public function testATemplateWithAPaddedPasswordNameIsReportedAsUnusable(): void
    {
        $scan = new SurveySecretTemplateScan();

        $scan->add(1, '[{"name":"db_password\n","type":"password"}]', '{"db_password":"sent-as-the-variable-name"}');
        $scan->add(2, '[{"name":" api_token","type":"password"},{"name":"region","type":"text"}]', null);
        $scan->add(3, '[{"name":"db password","type":"password"}]', null);

        $this->assertSame([1, 2], $scan->unusable(), 'inner spaces are fine');
        $this->assertSame([1 => ["db_password\n"], 2 => [' api_token'], 3 => ['db password']], $scan->passwordNames(), 'every name is a secret key');
    }

    /**
     * Regression (V2-C1U): with a non-breaking space, a byte order mark or
     * another character outside ASCII in its name, the field counted as
     * usable, so m000081 and the scrub did not report the template and
     * looked for its answers under that exact name, which no caller sends.
     *
     * @dataProvider unicodeNameProvider
     */
    public function testATemplateWithAPasswordNameOutsideAsciiIsReportedAsUnusable(string $name): void
    {
        $scan = new SurveySecretTemplateScan();

        $scan->add(4, (string)json_encode([['name' => 'app_env', 'type' => 'text'], ['name' => $name, 'type' => 'password']]), '{"db_password":"sent-as-the-variable-name"}');
        $scan->add(5, '[{"name":"db_password","type":"password"}]', null);

        $this->assertSame([4], $scan->unusable(), 'only the template with the plain name has a usable one');
        $this->assertSame([4 => [$name], 5 => ['db_password']], $scan->passwordNames(), 'the exact name is a secret key all the same');
    }
}
