<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SurveySecretTemplateScan;
use PHPUnit\Framework\TestCase;

/**
 * SurveySecretTemplateScan: how the survey password backfill classifies
 * job template rows, whatever their survey and extra vars JSON look like.
 *
 * Every password field name is a secret key, usable or not: a name that
 * cannot carry an answer only keeps the template from being launched.
 */
class SurveySecretTemplateScanTest extends TestCase
{
    public function testRowsAreClassifiedByTheirPasswordFields(): void
    {
        $scan = new SurveySecretTemplateScan();

        $scan->add(1, '[{"name":"db_password","type":"password"},{"name":"app_env","type":"text","default":"x"}]', '{"app_env":"prod"}');
        $scan->add(2, '[{"name":"db[pw]","type":"password","default":"d"},{"name":"token","type":"password"}]', '{"token":"t"}');
        $scan->add(3, '{"a":{"name":"api_key","type":"password","default":"k"}}', null);
        $scan->add(4, null, '{"db_password":"not a field of this template"}');
        $scan->add(5, '[{"name": ', '{}');
        $scan->add(6, '[{"name":"5","type":"password"}]', '{"5":"numeric key"}');
        $scan->add(7, '[{"name":"pw","type":"password"}]', 'not json');

        $this->assertSame(7, $scan->count());
        $this->assertSame([1 => ['db_password'], 2 => ['token', 'db[pw]'], 3 => ['api_key'], 6 => ['5'], 7 => ['pw']], $scan->passwordNames());
        $this->assertSame([2, 6], $scan->unusable());
        $this->assertSame([2, 3], $scan->withPasswordDefaults());
        $this->assertSame([2, 6], $scan->passwordKeysInExtraVars(), 'usable and unusable names both count');
        $this->assertSame([5], $scan->invalid());
    }

    public function testAnEmptyScanHasNothing(): void
    {
        $scan = new SurveySecretTemplateScan();

        $this->assertSame([0, [], [], [], [], []], [
            $scan->count(),
            $scan->passwordNames(),
            $scan->unusable(),
            $scan->withPasswordDefaults(),
            $scan->passwordKeysInExtraVars(),
            $scan->invalid(),
        ]);
    }
}
