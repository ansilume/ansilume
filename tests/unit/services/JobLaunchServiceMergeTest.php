<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\JobTemplate;
use app\services\JobLaunchService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JobLaunchService::mergeExtraVars.
 *
 * Merge order: template defaults ← survey answers ← explicit overrides.
 * Later layers win over earlier ones.
 */
class JobLaunchServiceMergeTest extends TestCase
{
    private JobLaunchService $service;

    protected function setUp(): void
    {
        $this->service = new class extends JobLaunchService {
            public function exposeMerge(JobTemplate $template, array $overrides): array
            {
                return $this->mergeExtraVars($template, $overrides);
            }
        };
    }

    public function testEmptyTemplateAndNoOverridesReturnsEmpty(): void
    {
        $template = $this->makeTemplate(null);
        $result   = $this->service->exposeMerge($template, []);
        $this->assertSame([], $result);
    }

    public function testTemplateDefaultsReturnedWhenNoOverrides(): void
    {
        $template = $this->makeTemplate('{"env":"dev","region":"eu"}');
        $result   = $this->service->exposeMerge($template, []);
        $this->assertSame(['env' => 'dev', 'region' => 'eu'], $result);
    }

    public function testSurveyAnswersMergedOverTemplateDefaults(): void
    {
        $template = $this->makeTemplate('{"env":"dev"}');
        $result   = $this->service->exposeMerge($template, [
            'survey' => ['env' => 'staging', 'app_version' => '1.2.3'],
        ]);
        $this->assertSame('staging',  $result['env']);
        $this->assertSame('1.2.3',    $result['app_version']);
    }

    public function testExplicitExtraVarsWinOverSurveyAndDefaults(): void
    {
        $template = $this->makeTemplate('{"env":"dev"}');
        $result   = $this->service->exposeMerge($template, [
            'survey'     => ['env' => 'staging'],
            'extra_vars' => '{"env":"prod"}',
        ]);
        $this->assertSame('prod', $result['env']);
    }

    public function testExplicitExtraVarsAsArrayAlsoMerges(): void
    {
        $template = $this->makeTemplate('{"a":"template"}');
        $result   = $this->service->exposeMerge($template, [
            'extra_vars' => ['a' => 'override', 'b' => 'new'],
        ]);
        $this->assertSame('override', $result['a']);
        $this->assertSame('new',      $result['b']);
    }

    public function testInvalidJsonExtraVarsIgnored(): void
    {
        $template = $this->makeTemplate('{"env":"dev"}');
        $result   = $this->service->exposeMerge($template, [
            'extra_vars' => '{not-json}',
        ]);
        // Invalid JSON cannot be decoded — result falls back to template defaults
        $this->assertSame(['env' => 'dev'], $result);
    }

    public function testMergeOrderIsTemplateFirstSurveySecondOverrideLast(): void
    {
        $template = $this->makeTemplate('{"key":"template"}');
        $result   = $this->service->exposeMerge($template, [
            'survey'     => ['key' => 'survey'],
            'extra_vars' => '{"key":"override"}',
        ]);
        $this->assertSame('override', $result['key']);
    }

    private function makeTemplate(?string $extraVars): JobTemplate
    {
        $t = $this->getMockBuilder(JobTemplate::class)
            ->disableOriginalConstructor()
            ->getMock();
        $t->extra_vars = $extraVars;
        return $t;
    }
}
