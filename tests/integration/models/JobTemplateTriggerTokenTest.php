<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

class JobTemplateTriggerTokenTest extends DbTestCase
{
    public function testGenerateTriggerTokenPersistsToken(): void
    {
        $template = $this->makeTemplate();

        $raw = $template->generateTriggerToken();

        $template->refresh();
        $this->assertNotNull($template->trigger_token);
        $this->assertSame(64, strlen($raw)); // 32 bytes hex = 64 chars
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);
    }

    public function testGenerateTriggerTokenStoresSha256Hash(): void
    {
        $template = $this->makeTemplate();

        $raw = $template->generateTriggerToken();

        // The raw value must never hit the DB — only its SHA-256 hash is persisted.
        $template->refresh();
        $this->assertNotSame($raw, $template->trigger_token);
        $this->assertSame(hash('sha256', $raw), $template->trigger_token);
    }

    public function testFindByTriggerTokenRejectsStoredHash(): void
    {
        // Passing the hash itself must NOT match — only the raw token matches.
        // This is the whole point of hashing at rest.
        $template = $this->makeTemplate();
        $template->generateTriggerToken();
        $template->refresh();

        $found = JobTemplate::findByTriggerToken((string)$template->trigger_token);

        $this->assertNull($found);
    }

    public function testRevokeTriggerTokenClearsToken(): void
    {
        $template = $this->makeTemplate();
        $template->generateTriggerToken();
        $template->refresh();
        $this->assertNotNull($template->trigger_token);

        $template->revokeTriggerToken();

        $template->refresh();
        $this->assertNull($template->trigger_token);
    }

    public function testFindByTriggerTokenReturnsTemplate(): void
    {
        $template = $this->makeTemplate();
        $raw      = $template->generateTriggerToken();

        $found = JobTemplate::findByTriggerToken($raw);

        $this->assertNotNull($found);
        $this->assertSame($template->id, $found->id);
    }

    public function testFindByTriggerTokenReturnsNullForUnknownToken(): void
    {
        $found = JobTemplate::findByTriggerToken(str_repeat('a', 64));
        $this->assertNull($found);
    }

    public function testFindByTriggerTokenReturnsNullForEmptyString(): void
    {
        $found = JobTemplate::findByTriggerToken('');
        $this->assertNull($found);
    }

    public function testFindByTriggerTokenReturnsNullAfterRevoke(): void
    {
        $template = $this->makeTemplate();
        $raw      = $template->generateTriggerToken();
        $template->revokeTriggerToken();

        $found = JobTemplate::findByTriggerToken($raw);

        $this->assertNull($found);
    }

    public function testGeneratingNewTokenReplacesOldOne(): void
    {
        $template = $this->makeTemplate();
        $first    = $template->generateTriggerToken();
        $second   = $template->generateTriggerToken();

        $this->assertNotSame($first, $second);
        $this->assertNull(JobTemplate::findByTriggerToken($first));
        $this->assertNotNull(JobTemplate::findByTriggerToken($second));
    }

    // -------------------------------------------------------------------------

    private function makeTemplate(): JobTemplate
    {
        $user        = $this->createUser();
        $runnerGroup = $this->createRunnerGroup($user->id);
        $project     = $this->createProject($user->id);
        $inventory   = $this->createInventory($user->id);
        return $this->createJobTemplate($project->id, $inventory->id, $runnerGroup->id, $user->id);
    }
}
