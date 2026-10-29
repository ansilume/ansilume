<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\services\CredentialResolutionException;
use app\services\JobCredentialResolver;
use app\tests\integration\DbTestCase;

/**
 * The credential IDs of a job's launch payload, as the database returns the
 * decoded payload: IDs as ints or their digits, read as the integer rule
 * reads an ID (SubmittedId), never with an (int) cast after is_numeric(),
 * which is true for a float beyond the int range too (its cast warns on PHP
 * 8.5). A snapshot entry that is no object names no credential.
 */
class JobCredentialResolverPayloadIdsTest extends DbTestCase
{
    /**
     * The names of credentials deleted after the launch come from the
     * snapshot, whatever form its IDs take; an entry that is no object, or
     * whose ID is none, is passed over.
     */
    public function testTheSnapshotNamesDeletedCredentialsAndPassesOverOtherEntries(): void
    {
        $resolver = new JobCredentialResolver();
        $userId = (int)$this->createUser('payload-ids')->id;
        $gone = $this->createCredential($userId);
        $goneId = (int)$gone->id;
        $gone->delete();

        try {
            $resolver->resolveTemplateCredentials([
                'credential_ids' => [(string)$goneId],
                Job::PAYLOAD_CREDENTIAL_SNAPSHOT => [
                    'not an entry',
                    ['id' => 1e30, 'name' => 'beyond the range'],
                    ['id' => (string)$goneId, 'name' => 'prod-vault', 'credential_type' => 'vault'],
                ],
            ]);
            $this->fail('a deleted credential aborts the job');
        } catch (CredentialResolutionException $e) {
            $this->assertSame([[
                'id' => $goneId,
                'name' => 'prod-vault',
                'role' => 'additional',
                'reason' => CredentialResolutionException::REASON_MISSING,
            ]], $e->failures);
        }
    }

    /**
     * IDs as the payload stores them keep their order; a value that is no
     * ID is none, also as the primary credential.
     */
    public function testThePayloadsIdsAreReadAsTheIntegerRuleReadsThem(): void
    {
        $this->assertSame([5, 9, 7], JobCredentialResolver::credentialIds(['credential_id' => '5', 'credential_ids' => [9, '7', 1e30, INF, 9.0]]));
        $this->assertSame([9], JobCredentialResolver::credentialIds(['credential_id' => 1e30, 'credential_ids' => ['9']]));
    }
}
