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
}
