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

        $raw = $template->generateTriggerToken((int)$template->created_by);

        $template->refresh();
        $this->assertNotNull($template->trigger_token);
        $this->assertSame(64, strlen($raw)); // 32 bytes hex = 64 chars
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);
    }

    public function testGenerateTriggerTokenStoresSha256Hash(): void
    {
        $template = $this->makeTemplate();

        $raw = $template->generateTriggerToken((int)$template->created_by);

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
        $template->generateTriggerToken((int)$template->created_by);
        $template->refresh();

        $found = JobTemplate::findByTriggerToken((string)$template->trigger_token);

        $this->assertNull($found);
    }

    public function testRevokeTriggerTokenClearsToken(): void
    {
        $template = $this->makeTemplate();
        $template->generateTriggerToken((int)$template->created_by);
        $template->refresh();
        $this->assertNotNull($template->trigger_token);

        $template->revokeTriggerToken();

        $template->refresh();
        $this->assertNull($template->trigger_token);
    }

    public function testFindByTriggerTokenReturnsTemplate(): void
    {
        $template = $this->makeTemplate();
        $raw      = $template->generateTriggerToken((int)$template->created_by);

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
        $raw      = $template->generateTriggerToken((int)$template->created_by);
        $template->revokeTriggerToken();

        $found = JobTemplate::findByTriggerToken($raw);

        $this->assertNull($found);
    }

    public function testGeneratingNewTokenReplacesOldOne(): void
    {
        $template = $this->makeTemplate();
        $first    = $template->generateTriggerToken((int)$template->created_by);
        $second   = $template->generateTriggerToken((int)$template->created_by);

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

    /**
     * Regression: the template form could set created_by and trigger_token.
     */
    public function testCreatorAndTriggerTokenCannotBeSetFromAForm(): void
    {
        $template = $this->makeTemplate();
        $owner = (int)$template->created_by;

        $template->load(['JobTemplate' => [
            'name' => 'renamed',
            'created_by' => $owner + 1000,
            'trigger_token' => hash('sha256', 'chosen'),
            'trigger_token_created_by' => $owner + 1000,
        ]]);

        $this->assertSame('renamed', $template->name);
        $this->assertSame($owner, (int)$template->created_by);
        $this->assertNull($template->trigger_token);
        $this->assertNull($template->trigger_token_created_by);
        foreach (['created_by', 'trigger_token', 'trigger_token_created_by'] as $attribute) {
            $this->assertNotContains($attribute, $template->safeAttributes());
        }
    }

    public function testTheTriggerRunsAsWhoeverGeneratedTheToken(): void
    {
        $template = $this->makeTemplate();
        $operator = $this->createUser('jt_trigger_operator');
        $this->assertSame((int)$template->created_by, $template->getTriggerUserId());

        $template->generateTriggerToken($operator->id);
        $template->refresh();
        $this->assertSame($operator->id, (int)$template->trigger_token_created_by);
        $this->assertSame($operator->id, $template->getTriggerUserId());

        $template->revokeTriggerToken();
        $template->refresh();
        $this->assertNull($template->trigger_token_created_by);
        $this->assertSame((int)$template->created_by, $template->getTriggerUserId());
    }

    public function testHasTriggerTokenFollowsGenerationAndRevocation(): void
    {
        $template = $this->makeTemplate();
        $this->assertFalse($template->hasTriggerToken());

        $template->generateTriggerToken((int)$template->created_by);
        $this->assertTrue($template->hasTriggerToken());

        $template->revokeTriggerToken();
        $this->assertFalse($template->hasTriggerToken());

        $template->trigger_token = '';
        $this->assertFalse($template->hasTriggerToken(), 'an empty value is no token');
    }
}
