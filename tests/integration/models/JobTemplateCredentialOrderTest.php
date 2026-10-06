<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Credential;
use app\models\Job;
use app\models\JobTemplate;
use app\models\JobTemplateCredential;
use app\tests\integration\DbTestCase;

/**
 * Regression: the credentials relation sorted by credential id, so the
 * documented precedence (primary first, then sort_order) never applied and
 * an older credential could win --user or --vault-password-file.
 */
class JobTemplateCredentialOrderTest extends DbTestCase
{
    /**
     * @return array{0: JobTemplate, 1: Credential, 2: Credential, 3: Credential}
     */
    private function templateWithCredentials(bool $primaryPivotRow = true): array
    {
        $user = $this->createUser('order');
        $userId = (int)$user->id;
        // Created in this order on purpose: the extra credential that must
        // come last gets the lowest id.
        $extraLast = $this->createCredential($userId, Credential::TYPE_VAULT);
        $primary = $this->createCredential($userId, Credential::TYPE_SSH_KEY);
        $extraFirst = $this->createCredential($userId, Credential::TYPE_TOKEN);
        $template = $this->createJobTemplate(
            $this->createProject($userId)->id,
            $this->createInventory($userId)->id,
            $this->createRunnerGroup($userId)->id,
            $userId
        );
        $template->credential_id = $primary->id;
        $template->save(false);
        $rows = [[$extraFirst->id, 1], [$extraLast->id, 2]];
        if ($primaryPivotRow) {
            $rows[] = [$primary->id, 0];
        }
        foreach ($rows as [$credentialId, $order]) {
            $pivot = new JobTemplateCredential();
            $pivot->job_template_id = $template->id;
            $pivot->credential_id = $credentialId;
            $pivot->sort_order = $order;
            $pivot->save(false);
        }

        return [JobTemplate::findOne($template->id), $primary, $extraFirst, $extraLast];
    }

    public function testCredentialsComeInPrecedenceOrder(): void
    {
        [$template, $primary, $extraFirst, $extraLast] = $this->templateWithCredentials();

        $ids = array_map(static fn (Credential $c): int => (int)$c->id, $template->orderedCredentials());

        $this->assertSame([$primary->id, $extraFirst->id, $extraLast->id], $ids);
    }

    public function testThePrimaryComesFirstEvenWithoutAPivotRow(): void
    {
        [$template, $primary, $extraFirst, $extraLast] = $this->templateWithCredentials(false);

        $ids = array_map(static fn (Credential $c): int => (int)$c->id, $template->orderedCredentials());

        $this->assertSame([$primary->id, $extraFirst->id, $extraLast->id], $ids);
    }

    public function testTheSnapshotHasRolesAndNoSecrets(): void
    {
        [$template, $primary, $extraFirst] = $this->templateWithCredentials();

        $snapshot = $template->credentialSnapshot();

        $this->assertSame(
            ['id' => (int)$primary->id, 'name' => $primary->name, 'credential_type' => Credential::TYPE_SSH_KEY, 'role' => Credential::ROLE_PRIMARY],
            $snapshot[0]
        );
        $this->assertSame(Credential::ROLE_ADDITIONAL, $snapshot[1]['role']);
        $this->assertSame((int)$extraFirst->id, $snapshot[1]['id']);
        $this->assertStringNotContainsString('secret', (string)json_encode($snapshot));
    }

    public function testTheLaunchPayloadKeepsThePrecedenceOrder(): void
    {
        [$template, $primary, $extraFirst, $extraLast] = $this->templateWithCredentials();
        $user = $this->createUser('launcher');

        $job = \Yii::$app->get('jobLaunchService')->launch($template, (int)$user->id);
        $payload = Job::findOne($job->id)?->decodedRunnerPayload() ?? [];

        $this->assertSame([$primary->id, $extraFirst->id, $extraLast->id], $payload['credential_ids']);
        $this->assertSame(
            [Credential::ROLE_PRIMARY, Credential::ROLE_ADDITIONAL, Credential::ROLE_ADDITIONAL],
            array_column($payload[Job::PAYLOAD_CREDENTIAL_SNAPSHOT], 'role')
        );
    }

    /**
     * Regression: an unknown credential_id passed validation and failed on
     * the foreign key with a 500 instead of a validation error.
     */
    public function testAnUnknownPrimaryCredentialIsAValidationError(): void
    {
        [$template] = $this->templateWithCredentials();
        $template->credential_id = 999_999_999;

        $this->assertFalse($template->validate(['credential_id']));
        $this->assertSame('The selected credential does not exist.', $template->getFirstError('credential_id'));
    }

    public function testABrokenPayloadDecodesToNothing(): void
    {
        $job = new Job();
        $job->runner_payload = '{not json';

        $this->assertSame([], $job->decodedRunnerPayload());
        $job->runner_payload = null;
        $this->assertSame([], $job->decodedRunnerPayload());
    }
}
