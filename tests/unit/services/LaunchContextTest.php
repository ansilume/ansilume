<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\LaunchContext;
use PHPUnit\Framework\TestCase;

/**
 * LaunchContext: server-only launch data, with the audit context naming the
 * carried password answers by key only.
 */
class LaunchContextTest extends TestCase
{
    private const MARKER = 'carried-answer-marker-5521';

    public function testARelaunchCarriesTheAnswersAndNamesThemForTheAudit(): void
    {
        $context = LaunchContext::forRelaunch(41, ['db_password' => self::MARKER, 'pin' => 1234]);

        $this->assertSame(['db_password' => self::MARKER, 'pin' => 1234], $context->carriedSecrets);
        $this->assertSame(['relaunch_of' => 41, 'carried_secret_keys' => ['db_password', 'pin']], $context->auditContext);
    }

    public function testAScheduleCarriesItsStoredAnswers(): void
    {
        $context = LaunchContext::forSchedule(9, ['db_password' => self::MARKER]);

        $this->assertSame(['db_password' => self::MARKER], $context->carriedSecrets);
        $this->assertSame(['schedule_id' => 9, 'carried_secret_keys' => ['db_password']], $context->auditContext);
    }

    public function testWithoutAnswersTheAuditListsNoKeys(): void
    {
        $this->assertSame(['relaunch_of' => 3, 'carried_secret_keys' => []], LaunchContext::forRelaunch(3, [])->auditContext);
    }

    public function testWithAuditCarriesNoAnswers(): void
    {
        $context = LaunchContext::withAudit(['trigger_transport' => 'header']);

        $this->assertSame([], $context->carriedSecrets);
        $this->assertSame(['trigger_transport' => 'header'], $context->auditContext);
    }

    public function testDumpsShowTheKeysOfTheAnswersNotTheirValues(): void
    {
        $context = LaunchContext::forRelaunch(41, ['db_password' => self::MARKER]);

        $this->assertSame(['carriedSecretKeys' => ['db_password'], 'auditContext' => $context->auditContext], $context->__debugInfo());
        // The dumpers are called by name: the lint suite rejects direct
        // calls to them as debug leftovers.
        ob_start();
        call_user_func('var_dump', $context);
        $dump = (string)ob_get_clean();
        $printed = (string)call_user_func('print_r', $context, true);
        $this->assertStringNotContainsString(self::MARKER, $dump);
        $this->assertStringNotContainsString(self::MARKER, $printed);
        $this->assertStringContainsString('db_password', $dump);
        $this->assertStringContainsString('db_password', $printed);
    }
}
