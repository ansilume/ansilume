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
     * A list as check_mode, which the launch normalises, is dropped, and so
     * are extra vars that are neither JSON text nor fields.
     */
    public function testAListAsCheckModeAndExtraVarsOfAnotherKindAreDropped(): void
    {
        $this->assertSame([], WebLaunchOverrides::fromRequest(['check_mode' => ['1'], 'extra_vars' => 5], null));
    }

    /**
     * Regression: a list as the limit or the verbosity was dropped, also
     * one of an empty value (a forged overrides[verbosity][]=), and the job
     * launched with the template's limit and verbosity. It goes on as
     * posted, as POST /api/v1/jobs and the job trigger hand it on, for
     * JobLaunchInput to refuse before the launch (LaunchInputTest), so that
     * it cannot fail the launch with "Array to string conversion" either, as
     * overrides[limit][]=x did before the form dropped it.
     */
    public function testAListAsTheLimitOrTheVerbosityGoesOnForTheLaunchInputToRefuse(): void
    {
        $this->assertSame(
            ['limit' => ['x'], 'verbosity' => ['']],
            WebLaunchOverrides::fromRequest(['limit' => ['x'], 'verbosity' => [''], 'check_mode' => ['1']], null)
        );
        // An empty list, as the form posted as JSON gives [] and {}.
        $this->assertSame(['limit' => [], 'verbosity' => []], WebLaunchOverrides::fromRequest(['limit' => [], 'verbosity' => []], null));
    }

    public function testANullLimitOrVerbosityIsNone(): void
    {
        $this->assertSame([], WebLaunchOverrides::fromRequest(['limit' => null, 'verbosity' => null, 'check_mode' => null], null));
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
