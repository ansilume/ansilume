<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\WebLaunchOverrides;
use app\services\LaunchContext;
use PHPUnit\Framework\TestCase;

/**
 * WebLaunchOverrides: the web launch form's fields are the only overrides a
 * request can set.
 */
class WebLaunchOverridesTest extends TestCase
{
    public function testTheFormsFieldsPassThrough(): void
    {
        $this->assertSame(
            ['extra_vars' => '{"env":"prod"}', 'limit' => 'web', 'verbosity' => '3', 'check_mode' => '1', 'survey' => ['env' => 'staging']],
            WebLaunchOverrides::fromRequest(
                ['extra_vars' => '{"env":"prod"}', 'limit' => 'web', 'verbosity' => '3', 'check_mode' => '1'],
                ['env' => 'staging']
            )
        );
    }

    public function testExtraVarsPostedAsFieldsPassThrough(): void
    {
        $this->assertSame(['extra_vars' => ['env' => 'prod']], WebLaunchOverrides::fromRequest(['extra_vars' => ['env' => 'prod']], null));
    }

    /**
     * Regression: the web launch passed every posted overrides[] key to the
     * launch service, so a request could set launch data that only server
     * code may set.
     */
    public function testEverythingElseIsDropped(): void
    {
        $this->assertSame(['limit' => 'web'], WebLaunchOverrides::fromRequest([
            'limit' => 'web',
            LaunchContext::KEY => ['carriedSecrets' => ['db_password' => 'x']],
            'secret_extra_vars' => ['db_password' => 'x'],
            'survey' => ['smuggled' => 'x'],
            'job_template_id' => 7,
        ], null));
    }

    /**
     * Regression: overrides[limit][]=x reached the service as a list, and
     * casting it failed the request with "Array to string conversion".
     */
    public function testListsWhereTheFormSendsSingleValuesAreDropped(): void
    {
        $this->assertSame([], WebLaunchOverrides::fromRequest(
            ['limit' => ['x'], 'verbosity' => ['3'], 'check_mode' => ['1'], 'extra_vars' => 5],
            null
        ));
    }

    public function testOverridesThatAreNoListGiveNothing(): void
    {
        $this->assertSame([], WebLaunchOverrides::fromRequest('overrides', null));
        $this->assertSame([], WebLaunchOverrides::fromRequest(null, null));
    }

    public function testOnlyANonEmptyListOfSurveyAnswersIsTaken(): void
    {
        $this->assertSame([], WebLaunchOverrides::fromRequest([], []));
        $this->assertSame([], WebLaunchOverrides::fromRequest([], 'answer'));
        $this->assertSame(['survey' => ['pw' => '']], WebLaunchOverrides::fromRequest([], ['pw' => '']));
    }
}
