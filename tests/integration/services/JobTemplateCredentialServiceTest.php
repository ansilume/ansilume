<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplate;
use app\services\JobTemplateCredentialService;
use app\tests\integration\DbTestCase;

class JobTemplateCredentialServiceTest extends DbTestCase
{
    private JobTemplateCredentialService $service;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new JobTemplateCredentialService();
        $this->userId = (int)$this->createUser('jtc')->id;
    }

    private function newTemplate(?int $primary): JobTemplate
    {
        $template = new JobTemplate();
        $template->name = 'jtc-' . uniqid('', true);
        $template->project_id = $this->createProject($this->userId)->id;
        $template->inventory_id = $this->createInventory($this->userId)->id;
        $template->runner_group_id = $this->createRunnerGroup($this->userId)->id;
        $template->playbook = 'site.yml';
        $template->verbosity = 0;
        $template->forks = 5;
        $template->timeout_minutes = 30;
        $template->become = false;
        $template->credential_id = $primary;
        $template->created_by = $this->userId;

        return $template;
    }

    /**
     * @return list<int>
     */
    private function orderedIds(JobTemplate $template): array
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return array_map(static fn (Credential $c): int => (int)$c->id, $fresh->orderedCredentials());
    }

    private function lastAudit(string $action, int $templateId): array
    {
        $log = AuditLog::find()->where(['action' => $action, 'object_type' => 'job_template', 'object_id' => $templateId])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($log);
        $meta = json_decode((string)$log->metadata, true);
        $this->assertIsArray($meta);

        return $meta;
    }

    private static function twoVaultConflict(Credential $first, Credential $second): string
    {
        return sprintf(
            'Only one vault password can be attached to a job template. "%s" and "%s" are both vault passwords; keep one of them.',
            $first->name,
            $second->name
        );
    }

    private function insertPivot(int $templateId, int $credentialId, int $sortOrder): void
    {
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => $templateId,
            'credential_id' => $credentialId,
            'sort_order' => $sortOrder,
        ])->execute();
    }

    /**
     * @param list<int> $credentialIds
     */
    private function pivotRowCount(array $credentialIds): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%job_template_credential}}')
            ->where(['credential_id' => $credentialIds])
            ->count();
    }

    private function auditEntriesFor(int $templateId): int
    {
        return (int)AuditLog::find()->where(['object_type' => 'job_template', 'object_id' => $templateId])->count();
    }

    /**
     * Audit entries of templates that were never saved, found by their name.
     */
    private function auditEntriesNaming(string $templateName): int
    {
        return (int)AuditLog::find()
            ->where(['object_type' => 'job_template'])
            ->andWhere(['like', 'metadata', $templateName])
            ->count();
    }

    /**
     * A template saved before the one-vault rule, written directly: a vault
     * primary, then a second vault and a token as additional credentials.
     *
     * @return array{0: JobTemplate, 1: Credential, 2: Credential, 3: Credential} template, first vault, second vault, token
     */
    private function legacyTwoVaultTemplate(): array
    {
        $first = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $second = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $token = $this->createCredential($this->userId, Credential::TYPE_TOKEN);
        $template = $this->newTemplate($first->id);
        $template->save(false);
        foreach ([$first, $second, $token] as $order => $credential) {
            $this->insertPivot((int)$template->id, (int)$credential->id, $order);
        }

        return [$template, $first, $second, $token];
    }

    public function testANewTemplateGetsItsCredentialsInTheGivenOrder(): void
    {
        $primary = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $b = $this->createCredential($this->userId);
        $a = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $template = $this->newTemplate($primary->id);

        $this->assertTrue($this->service->saveWithCredentials($template, [$b->id, (string)$a->id, $b->id, $primary->id]));

        $this->assertSame([$primary->id, $b->id, $a->id], $this->orderedIds($template));
        $this->assertSame([$b->id, $a->id], $this->service->additionalIds(JobTemplate::findOne($template->id)));
        $audit = $this->lastAudit(AuditLog::ACTION_TEMPLATE_CREATED, (int)$template->id);
        $this->assertSame([$primary->id, $b->id, $a->id], array_column($audit['credentials']['attached'], 'id'));
        $this->assertSame(['from' => null, 'to' => $primary->id], $audit['credentials']['primary']);
    }

    /**
     * Regression: the REST API changed job_template.credential_id but left
     * the pivot alone, so the old primary stayed attached as an additional
     * credential.
     */
    public function testChangingOnlyThePrimaryDetachesTheOldOne(): void
    {
        $old = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $extra = $this->createCredential($this->userId);
        $new = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $template = $this->newTemplate($old->id);
        $this->service->saveWithCredentials($template, [$extra->id]);

        $template->credential_id = $new->id;
        $this->assertTrue($this->service->saveWithCredentials($template, null, ['source' => 'api']));

        $this->assertSame([$new->id, $extra->id], $this->orderedIds($template));
        $audit = $this->lastAudit(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id);
        $this->assertSame('api', $audit['source']);
        $this->assertSame([$new->id], array_column($audit['credentials']['attached'], 'id'));
        $this->assertSame([$old->id], array_column($audit['credentials']['detached'], 'id'));
    }

    public function testWithoutAListTheAdditionalCredentialsStay(): void
    {
        $primary = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $extra = $this->createCredential($this->userId);
        $template = $this->newTemplate($primary->id);
        $this->service->saveWithCredentials($template, [$extra->id]);

        $template->description = 'only the description changes';
        $this->assertTrue($this->service->saveWithCredentials($template, null));

        $this->assertSame([$primary->id, $extra->id], $this->orderedIds($template));
        $this->assertArrayNotHasKey('credentials', $this->lastAudit(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id));
    }

    public function testAListReplacesTheAdditionalCredentials(): void
    {
        $primary = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $first = $this->createCredential($this->userId);
        $second = $this->createCredential($this->userId);
        $template = $this->newTemplate($primary->id);
        $this->service->saveWithCredentials($template, [$first->id]);

        $this->assertTrue($this->service->saveWithCredentials($template, [$second->id]));
        $this->assertSame([$primary->id, $second->id], $this->orderedIds($template));

        $this->assertTrue($this->service->saveWithCredentials($template, []));
        $this->assertSame([$primary->id], $this->orderedIds($template));
    }

    public function testAPureReorderIsNotAnAttachmentChange(): void
    {
        $primary = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $a = $this->createCredential($this->userId);
        $b = $this->createCredential($this->userId);
        $template = $this->newTemplate($primary->id);
        $this->service->saveWithCredentials($template, [$a->id, $b->id]);

        $this->assertTrue($this->service->saveWithCredentials($template, [$b->id, $a->id]));

        $this->assertSame([$primary->id, $b->id, $a->id], $this->orderedIds($template));
        $this->assertArrayNotHasKey('credentials', $this->lastAudit(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id));
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: string}>
     */
    public static function invalidIdsProvider(): array
    {
        return [
            'not a number' => [['abc'], 'credential_ids must contain positive integer credential IDs.'],
            'zero' => [[0], 'credential_ids must contain positive integer credential IDs.'],
            'unknown' => [[999_999_999], 'Credential #999999999 does not exist.'],
        ];
    }

    /**
     * @dataProvider invalidIdsProvider
     * @param list<mixed> $ids
     */
    public function testInvalidIdsAreRejectedAndNothingIsWritten(array $ids, string $message): void
    {
        $primary = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $template = $this->newTemplate($primary->id);
        $this->service->saveWithCredentials($template, []);
        $template->name = 'renamed-but-rejected';

        $this->assertFalse($this->service->saveWithCredentials($template, $ids));

        $this->assertSame($message, $template->getFirstError('credential_ids'));
        $this->assertNotSame('renamed-but-rejected', JobTemplate::findOne($template->id)?->name);
        $this->assertSame([$primary->id], $this->orderedIds($template));
    }

    public function testATemplateValidationErrorWritesNothing(): void
    {
        $template = $this->newTemplate(999_999_999);

        $this->assertFalse($this->service->saveWithCredentials($template, []));

        $this->assertTrue($template->isNewRecord);
        $this->assertSame('The selected credential does not exist.', $template->getFirstError('credential_id'));
    }

    public function testAClonedTemplateKeepsTheCredentialsAndTheirOrder(): void
    {
        $primary = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $b = $this->createCredential($this->userId);
        $a = $this->createCredential($this->userId);
        $source = $this->newTemplate($primary->id);
        $this->service->saveWithCredentials($source, [$b->id, $a->id]);
        $clone = $this->newTemplate($primary->id);
        $clone->name = $source->name . ' (copy)';

        $this->assertTrue($this->service->copyWithCredentials($clone, JobTemplate::findOne($source->id), ['cloned_from' => $source->id]));

        $this->assertSame([$primary->id, $b->id, $a->id], $this->orderedIds($clone));
        $audit = $this->lastAudit(AuditLog::ACTION_TEMPLATE_CREATED, (int)$clone->id);
        $this->assertSame($source->id, $audit['cloned_from']);
        $this->assertCount(3, $audit['credentials']['attached']);
    }

    public function testAnInvalidCloneIsNotSaved(): void
    {
        $source = $this->newTemplate(null);
        $this->service->saveWithCredentials($source, []);
        $clone = $this->newTemplate(null);
        $clone->name = '';

        $this->assertFalse($this->service->copyWithCredentials($clone, JobTemplate::findOne($source->id)));
        $this->assertTrue($clone->isNewRecord);
    }

    /**
     * The template row and its credential rows are written in one
     * transaction: a failure after the template row was written leaves both
     * as they were.
     */
    public function testAFailedSaveRollsBackTheTemplateAndItsCredentials(): void
    {
        $primary = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $extra = $this->createCredential($this->userId);
        $template = $this->newTemplate($primary->id);
        $this->assertTrue($this->service->saveWithCredentials($template, [$extra->id]));
        $failing = new class extends JobTemplate {
            public function afterSave($insert, $changedAttributes): void
            {
                parent::afterSave($insert, $changedAttributes);
                throw new \RuntimeException('simulated failure after the template row was written');
            }
        };
        JobTemplate::populateRecord($failing, $template->getAttributes());
        $failing->name = 'jtc-renamed';

        try {
            $this->service->saveWithCredentials($failing, []);
            $this->fail('the failure must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure after the template row was written', $e->getMessage());
        }

        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame($template->name, $stored->name);
        $this->assertSame([$primary->id, $extra->id], $this->orderedIds($template));
    }

    /**
     * Regression: a template could hold two vault passwords, and the runner
     * silently dropped the second one (ansible-playbook gets a single
     * --vault-password-file). Such a set is rejected before anything is
     * written.
     */
    public function testANewTemplateWithAVaultPrimaryAndAVaultExtraIsRejectedAndWritesNothing(): void
    {
        $primary = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $extra = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $template = $this->newTemplate($primary->id);

        $this->assertFalse($this->service->saveWithCredentials($template, [$extra->id], ['source' => 'api']));

        $this->assertSame(self::twoVaultConflict($primary, $extra), $template->getFirstError('credential_ids'));
        $this->assertTrue($template->isNewRecord);
        $this->assertSame(0, (int)JobTemplate::findWithDeleted()->where(['name' => $template->name])->count());
        $this->assertSame(0, $this->pivotRowCount([$primary->id, $extra->id]));
        $this->assertSame(0, $this->auditEntriesNaming($template->name));
    }

    /**
     * Regression: the runner silently dropped the second vault password.
     * Two additional vault passwords are rejected as well; the message
     * follows the submitted precedence order, not the credential ids.
     */
    public function testTwoVaultExtrasWithoutAPrimaryAreRejected(): void
    {
        $older = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $newer = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $template = $this->newTemplate(null);

        $this->assertFalse($this->service->saveWithCredentials($template, [$newer->id, $older->id]));

        $this->assertSame(self::twoVaultConflict($newer, $older), $template->getFirstError('credential_ids'));
        $this->assertTrue($template->isNewRecord);
        $this->assertSame(0, $this->pivotRowCount([$older->id, $newer->id]));
        $this->assertSame(0, $this->auditEntriesNaming($template->name));
    }

    public function testOneVaultBesideAnSshKeyAndATokenIsAccepted(): void
    {
        $ssh = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $vault = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $token = $this->createCredential($this->userId, Credential::TYPE_TOKEN);
        $template = $this->newTemplate($ssh->id);

        $this->assertTrue($this->service->saveWithCredentials($template, [$vault->id, $token->id]));

        $this->assertFalse($template->hasErrors());
        $this->assertSame([$ssh->id, $vault->id, $token->id], $this->orderedIds($template));
        $audit = $this->lastAudit(AuditLog::ACTION_TEMPLATE_CREATED, (int)$template->id);
        $this->assertSame([$ssh->id, $vault->id, $token->id], array_column($audit['credentials']['attached'], 'id'));
    }

    /**
     * The vault primary listed again among the additional credentials, even
     * twice, is still one vault password.
     */
    public function testTheSameVaultListedAgainIsOneVaultPassword(): void
    {
        $vault = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $token = $this->createCredential($this->userId, Credential::TYPE_TOKEN);
        $template = $this->newTemplate($vault->id);

        $this->assertTrue($this->service->saveWithCredentials($template, [$vault->id, $token->id, (string)$vault->id]));

        $this->assertSame([$vault->id, $token->id], $this->orderedIds($template));
    }

    /**
     * Regression: the runner silently dropped the second vault password.
     * Without a list the stored additional credentials stay, so a new vault
     * primary next to a stored vault extra would make two.
     */
    public function testSwitchingThePrimaryToAVaultBesideAStoredVaultIsRejected(): void
    {
        $ssh = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $stored = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $newPrimary = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $template = $this->newTemplate($ssh->id);
        $this->assertTrue($this->service->saveWithCredentials($template, [$stored->id]));
        $auditsBefore = $this->auditEntriesFor((int)$template->id);

        $template->credential_id = $newPrimary->id;
        $this->assertFalse($this->service->saveWithCredentials($template, null, ['source' => 'api']));

        $this->assertSame(self::twoVaultConflict($newPrimary, $stored), $template->getFirstError('credential_ids'));
        $this->assertSame($ssh->id, (int)JobTemplate::findOne($template->id)?->credential_id);
        $this->assertSame([$ssh->id, $stored->id], $this->orderedIds($template));
        $this->assertSame($auditsBefore, $this->auditEntriesFor((int)$template->id));
    }

    /**
     * The old primary is detached when only the primary changes, so a vault
     * primary replaced by another vault leaves one vault password.
     */
    public function testReplacingTheVaultPrimaryWithAnotherVaultIsAccepted(): void
    {
        $old = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $new = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $token = $this->createCredential($this->userId, Credential::TYPE_TOKEN);
        $template = $this->newTemplate($old->id);
        $this->assertTrue($this->service->saveWithCredentials($template, [$token->id]));

        $template->credential_id = $new->id;
        $this->assertTrue($this->service->saveWithCredentials($template, null));

        $this->assertSame([$new->id, $token->id], $this->orderedIds($template));
        $audit = $this->lastAudit(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id);
        $this->assertSame([$old->id], array_column($audit['credentials']['detached'], 'id'));
        $this->assertSame(['from' => $old->id, 'to' => $new->id], $audit['credentials']['primary']);
    }

    /**
     * Regression: the runner silently dropped the second vault password of
     * templates saved before the one-vault rule. A plain save keeps the
     * stored credentials, so it is rejected until one vault is removed.
     */
    public function testALegacyTemplateWithTwoVaultsSavesOnlyAfterOneIsRemoved(): void
    {
        [$template, $first, $second, $token] = $this->legacyTwoVaultTemplate();
        $originalName = $template->name;
        $auditsBefore = $this->auditEntriesFor((int)$template->id);

        $template->name = $originalName . '-renamed';
        $this->assertFalse($this->service->saveWithCredentials($template, null));

        $this->assertSame(self::twoVaultConflict($first, $second), $template->getFirstError('credential_ids'));
        $this->assertSame($originalName, JobTemplate::findOne($template->id)?->name);
        $this->assertSame([$first->id, $second->id, $token->id], $this->orderedIds($template));
        $this->assertSame($auditsBefore, $this->auditEntriesFor((int)$template->id));

        $this->assertTrue($this->service->saveWithCredentials($template, [$token->id]));

        $this->assertFalse($template->hasErrors());
        $this->assertSame($originalName . '-renamed', JobTemplate::findOne($template->id)?->name);
        $this->assertSame([$first->id, $token->id], $this->orderedIds($template));
        $audit = $this->lastAudit(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id);
        $this->assertSame(
            ['detached' => [['id' => $second->id, 'name' => $second->name, 'credential_type' => Credential::TYPE_VAULT]]],
            $audit['credentials']
        );
    }

    /**
     * Regression: the runner silently dropped the second vault password.
     * Cloning a template saved before the rule would copy both.
     */
    public function testCloningALegacyTemplateWithTwoVaultsIsRejected(): void
    {
        [$source, $first, $second] = $this->legacyTwoVaultTemplate();
        $clone = $this->newTemplate($first->id);
        $clone->name = $source->name . ' (copy)';

        $this->assertFalse($this->service->copyWithCredentials($clone, JobTemplate::findOne($source->id), ['cloned_from' => $source->id]));

        $this->assertSame(self::twoVaultConflict($first, $second), $clone->getFirstError('credential_ids'));
        $this->assertTrue($clone->isNewRecord);
        $this->assertSame(0, (int)JobTemplate::findWithDeleted()->where(['name' => $clone->name])->count());
        $this->assertSame(2, $this->pivotRowCount([$first->id, $second->id]), 'only the source rows');
        $this->assertSame(0, $this->auditEntriesNaming($clone->name));
    }

    public function testCloningATemplateWithOneVaultKeepsIt(): void
    {
        $vault = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $ssh = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $source = $this->newTemplate($vault->id);
        $this->assertTrue($this->service->saveWithCredentials($source, [$ssh->id]));
        $clone = $this->newTemplate($vault->id);
        $clone->name = $source->name . ' (copy)';

        $this->assertTrue($this->service->copyWithCredentials($clone, JobTemplate::findOne($source->id)));

        $this->assertFalse($clone->hasErrors());
        $this->assertSame([$vault->id, $ssh->id], $this->orderedIds($clone));
    }

    /**
     * Regression: the clone was checked against the source's credentials
     * only, but written with its own primary first. A clone whose primary
     * is another vault password than the source's got two.
     */
    public function testACloneWithAnotherVaultAsPrimaryThanItsSourceIsRejected(): void
    {
        $ssh = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $sourceVault = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $cloneVault = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $source = $this->newTemplate($ssh->id);
        $this->assertTrue($this->service->saveWithCredentials($source, [$sourceVault->id]));
        $clone = $this->newTemplate($cloneVault->id);

        $this->assertFalse($this->service->copyWithCredentials($clone, JobTemplate::findOne($source->id)));

        $this->assertSame(self::twoVaultConflict($cloneVault, $sourceVault), $clone->getFirstError('credential_ids'));
        $this->assertTrue($clone->isNewRecord);
        $this->assertSame(0, $this->auditEntriesNaming($clone->name));
    }

    /**
     * A clone with another primary than its source keeps the source's
     * credentials behind it, and the audit entry lists exactly what was
     * written.
     */
    public function testACloneWithItsOwnPrimaryGetsTheSourceCredentialsBehindIt(): void
    {
        $ssh = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $vault = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $otherSsh = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $source = $this->newTemplate($ssh->id);
        $this->assertTrue($this->service->saveWithCredentials($source, [$vault->id]));
        $clone = $this->newTemplate($otherSsh->id);

        $this->assertTrue($this->service->copyWithCredentials($clone, JobTemplate::findOne($source->id)));

        $this->assertSame([$otherSsh->id, $ssh->id, $vault->id], $this->orderedIds($clone));
        $audit = $this->lastAudit(AuditLog::ACTION_TEMPLATE_CREATED, (int)$clone->id);
        $this->assertSame(
            [(int)$otherSsh->id, (int)$ssh->id, (int)$vault->id],
            array_column($audit['credentials']['attached'], 'id')
        );
    }

    /**
     * Regression: the vault rule ran only after the other fields validated,
     * so a form with a field error and two vault passwords showed the vault
     * error only on the next submit.
     */
    public function testAFieldErrorAndASecondVaultAreReportedTogether(): void
    {
        $first = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $second = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $template = $this->newTemplate($first->id);
        $template->name = '';

        $this->assertFalse($this->service->saveWithCredentials($template, [$second->id]));

        $this->assertSame(['name', 'credential_ids'], array_keys($template->getErrors()));
        $this->assertSame(self::twoVaultConflict($first, $second), $template->getFirstError('credential_ids'));
        $this->assertTrue($template->isNewRecord);
    }

    public function testAFieldErrorAndAnUnknownAdditionalCredentialAreReportedTogether(): void
    {
        $template = $this->newTemplate(null);
        $template->playbook = '';

        $this->assertFalse($this->service->saveWithCredentials($template, [999999999]));

        $this->assertSame(['playbook', 'credential_ids'], array_keys($template->getErrors()));
        $this->assertSame('Credential #999999999 does not exist.', $template->getFirstError('credential_ids'));
    }

    public function testDescribeInOrderKeepsTheGivenOrderAndSkipsUnknownIds(): void
    {
        $ssh = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $vault = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $token = $this->createCredential($this->userId, Credential::TYPE_TOKEN);

        $this->assertSame([
            ['id' => $token->id, 'name' => $token->name, 'credential_type' => Credential::TYPE_TOKEN],
            ['id' => $ssh->id, 'name' => $ssh->name, 'credential_type' => Credential::TYPE_SSH_KEY],
            ['id' => $vault->id, 'name' => $vault->name, 'credential_type' => Credential::TYPE_VAULT],
        ], Credential::describeInOrder([$token->id, 999_999_999, $ssh->id, $vault->id]));
        $this->assertSame([], Credential::describeInOrder([999_999_999]));
        $this->assertSame([], Credential::describeInOrder([]));
    }
}
