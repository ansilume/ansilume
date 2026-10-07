<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Credential;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\tests\integration\DbTestCase;

/**
 * The stored vault check of a job template. Its unopened column is JSON
 * written by VaultCheckService; reading it must never fail the template page,
 * the launch page or the REST API, whatever the column holds.
 */
class JobTemplateVaultCheckTest extends DbTestCase
{
    // -- unopenedEntries() -------------------------------------------------------

    public function testUnopenedEntriesReadWhatTheCheckStored(): void
    {
        $check = $this->checkWith((string)json_encode([
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null, 'reason' => JobTemplateVaultCheck::ENTRY_VAULT_ID_MATCH],
            ['path' => 'inventories/prod/host_vars/prod-web1.yml', 'line' => 2, 'key' => 'host_secret', 'reason' => null],
        ]));

        $this->assertSame([
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null, 'reason' => 'vault_id_match'],
            ['path' => 'inventories/prod/host_vars/prod-web1.yml', 'line' => 2, 'key' => 'host_secret', 'reason' => null],
        ], $check->unopenedEntries());
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unreadableUnopenedProvider(): array
    {
        return [
            'nothing stored' => [null],
            'empty string' => [''],
            'not JSON' => ['[{"path": "vars/secrets.yml"'],
            'garbage' => ['not json at all'],
            'a JSON string' => ['"vars/secrets.yml"'],
            'a JSON number' => ['42'],
            'JSON null' => ['null'],
            'an empty list' => ['[]'],
        ];
    }

    /**
     * @dataProvider unreadableUnopenedProvider
     */
    public function testUnopenedEntriesAreEmptyWhenNothingReadableIsStored(?string $unopened): void
    {
        $this->assertSame([], $this->checkWith($unopened)->unopenedEntries());
    }

    public function testEntriesThatAreNoObjectsAreSkipped(): void
    {
        $check = $this->checkWith((string)json_encode([
            'vars/secrets.yml',
            42,
            null,
            true,
            ['vars/other.yml', 3],
            ['path' => 'group_vars/all.yml', 'line' => 3, 'key' => 'app_secret'],
        ]));

        $this->assertSame(
            [['path' => 'group_vars/all.yml', 'line' => 3, 'key' => 'app_secret', 'reason' => null]],
            $check->unopenedEntries()
        );
    }

    public function testAnEntryNeedsAPathAsString(): void
    {
        $check = $this->checkWith((string)json_encode([
            ['line' => 3, 'key' => 'app_secret'],
            ['path' => null, 'line' => 3],
            ['path' => 7],
            ['path' => ['vars/secrets.yml']],
            ['path' => 'vars/secrets.yml'],
        ]));

        $this->assertSame([['path' => 'vars/secrets.yml', 'line' => null, 'key' => null, 'reason' => null]], $check->unopenedEntries());
    }

    public function testLinesAndKeysOfTheWrongTypeAreDropped(): void
    {
        $check = $this->checkWith((string)json_encode([
            ['path' => 'a.yml', 'line' => '3', 'key' => 7],
            ['path' => 'b.yml', 'line' => 3.5, 'key' => ['secret']],
            ['path' => 'c.yml', 'line' => true, 'key' => false],
            ['path' => 'd.yml', 'line' => 12, 'key' => '', 'reason' => 7],
            ['path' => 'e.yml', 'reason' => ['vault_id_match']],
        ]));

        $this->assertSame([
            ['path' => 'a.yml', 'line' => null, 'key' => null, 'reason' => null],
            ['path' => 'b.yml', 'line' => null, 'key' => null, 'reason' => null],
            ['path' => 'c.yml', 'line' => null, 'key' => null, 'reason' => null],
            ['path' => 'd.yml', 'line' => 12, 'key' => '', 'reason' => null],
            ['path' => 'e.yml', 'line' => null, 'key' => null, 'reason' => null],
        ], $check->unopenedEntries());
    }

    public function testExtraFieldsAreLeftOut(): void
    {
        $check = $this->checkWith((string)json_encode([
            ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null, 'fingerprint' => str_repeat('a', 64)],
        ]));

        $this->assertSame([['path' => 'vars/secrets.yml', 'line' => null, 'key' => null, 'reason' => null]], $check->unopenedEntries());
    }

    // -- isCurrentFor(), effectiveStatus() --------------------------------------

    public function testACheckIsCurrentOnlyForTheScanItUsed(): void
    {
        $project = new Project(['vault_scanned_at' => 1760000000]);
        $check = new JobTemplateVaultCheck(['status' => JobTemplateVaultCheck::STATUS_MISMATCH, 'scanned_at' => 1760000000]);

        $this->assertTrue($check->isCurrentFor($project));
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $check->effectiveStatus($project));

        $project->vault_scanned_at = 1760000060;
        $this->assertFalse($check->isCurrentFor($project), 'a newer scan');
        $this->assertSame(JobTemplateVaultCheck::STATUS_STALE, $check->effectiveStatus($project));

        $project->vault_scanned_at = null;
        $this->assertFalse($check->isCurrentFor($project), 'no scan');
        $check->scanned_at = null;
        $this->assertFalse($check->isCurrentFor($project), 'neither has a scan');
        $this->assertSame(JobTemplateVaultCheck::STATUS_STALE, $check->effectiveStatus($project));
    }

    // -- stalestFirst() -----------------------------------------------------------

    /**
     * A run of checks that runs out of time must get further next time:
     * templates without a current check go first, then the longest unchecked.
     */
    public function testStalestFirstPutsTemplatesWithoutACurrentCheckFirst(): void
    {
        $userId = (int)$this->createUser('vault-order')->id;
        $project = $this->createProject($userId);
        $project->vault_scanned_at = 1760000000;
        $project->save(false);
        $inventoryId = (int)$this->createInventory($userId)->id;
        $groupId = (int)$this->createRunnerGroup($userId)->id;
        $new = fn (): int => (int)$this->createJobTemplate((int)$project->id, $inventoryId, $groupId, $userId)->id;
        $checkedEarly = $new();
        $checkedLate = $new();
        $neverChecked = $new();
        $outOfTime = $new();
        $olderScan = $new();
        $this->checkAt($checkedEarly, 100, 1760000000);
        $this->checkAt($checkedLate, 200, 1760000000);
        $this->checkAt($outOfTime, 300, 1760000000, JobTemplateVaultCheck::REASON_TIME_LIMIT);
        $this->checkAt($olderScan, 400, 1759999000);

        $this->assertSame(
            [$neverChecked, $outOfTime, $olderScan, $checkedEarly, $checkedLate],
            JobTemplateVaultCheck::stalestFirst([$checkedLate, $checkedEarly, $olderScan, $outOfTime, $neverChecked, 999999999])
        );
        $this->assertSame([], JobTemplateVaultCheck::stalestFirst([]));
    }

    // -- relations and the lifetime of a check -----------------------------------

    public function testACheckNamesItsTemplateAndTheCheckedCredential(): void
    {
        [$template, $vault] = $this->templateWithVault();
        $this->storedCheck((int)$template->id, (int)$vault->id);

        $check = JobTemplateVaultCheck::findOne((int)$template->id);

        $this->assertNotNull($check);
        $this->assertSame((int)$template->id, (int)$check->jobTemplate->id);
        $this->assertNotNull($check->credential);
        $this->assertSame($vault->name, $check->credential->name);
        $this->assertNotNull($template->vaultCheck);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $template->vaultCheck->status);
    }

    public function testACheckOutlivesItsCredential(): void
    {
        [$template, $vault] = $this->templateWithVault();
        $this->storedCheck((int)$template->id, (int)$vault->id);

        $this->assertSame(1, $vault->delete());

        $check = JobTemplateVaultCheck::findOne((int)$template->id);
        $this->assertNotNull($check);
        $this->assertNull($check->credential_id);
        $this->assertNull($check->credential);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $check->status);
    }

    public function testACheckGoesWithItsTemplate(): void
    {
        [$template, $vault] = $this->templateWithVault();
        $this->storedCheck((int)$template->id, (int)$vault->id);

        $this->assertSame(1, $template->delete());

        $this->assertNull(JobTemplateVaultCheck::findOne((int)$template->id));
        $this->assertNotNull(Credential::findOne((int)$vault->id), 'the credential stays');
    }

    public function testATemplateHasAtMostOneCheck(): void
    {
        [$template, $vault] = $this->templateWithVault();
        $this->storedCheck((int)$template->id, (int)$vault->id);

        $this->expectException(\yii\db\IntegrityException::class);
        $this->storedCheck((int)$template->id, null);
    }

    // -- helpers -----------------------------------------------------------------

    private function checkWith(?string $unopened): JobTemplateVaultCheck
    {
        $check = new JobTemplateVaultCheck();
        $check->status = JobTemplateVaultCheck::STATUS_MISMATCH;
        $check->unopened = $unopened;

        return $check;
    }

    /**
     * @return array{0: JobTemplate, 1: Credential}
     */
    private function templateWithVault(): array
    {
        $userId = (int)$this->createUser('vault-check')->id;
        $vault = $this->createCredential($userId, Credential::TYPE_VAULT);
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $template->credential_id = $vault->id;
        $template->save(false);

        return [$template, $vault];
    }

    private function checkAt(int $templateId, int $checkedAt, int $scannedAt, ?string $reason = null): void
    {
        $check = new JobTemplateVaultCheck();
        $check->job_template_id = $templateId;
        $check->status = $reason === null ? JobTemplateVaultCheck::STATUS_OK : JobTemplateVaultCheck::STATUS_INCOMPLETE;
        $check->incomplete_reason = $reason;
        $check->checked_at = $checkedAt;
        $check->scanned_at = $scannedAt;
        $check->save(false);
    }

    private function storedCheck(int $templateId, ?int $credentialId): void
    {
        $check = new JobTemplateVaultCheck();
        $check->job_template_id = $templateId;
        $check->status = JobTemplateVaultCheck::STATUS_MISMATCH;
        $check->credential_id = $credentialId;
        $check->relevant_count = 1;
        $check->unopened = (string)json_encode([['path' => 'vars/secrets.yml', 'line' => null, 'key' => null]]);
        $check->checked_at = time();
        $check->scanned_at = time();
        $check->save(false);
    }
}
