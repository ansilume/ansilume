<?php

declare(strict_types=1);

namespace app\services;

use app\models\Credential;
use app\models\Job;
use app\models\Project;
use yii\base\Component;

/**
 * Turns the credential ids of a job's launch payload into what the runner
 * needs, and describes them for the UI, the API and the audit log.
 *
 * Resolution fails closed: a credential that was deleted after the launch or
 * cannot be decrypted aborts the job before it runs. It used to be skipped
 * silently, so the job ran without it.
 */
class JobCredentialResolver extends Component
{
    /**
     * Credential ids of a payload in precedence order, primary first.
     *
     * @param array<string, mixed> $raw
     * @return list<int>
     */
    public static function credentialIds(array $raw): array
    {
        $ids = [];
        foreach ((array)($raw['credential_ids'] ?? []) as $value) {
            $id = is_numeric($value) ? (int)$value : 0;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $primary = self::primaryId($raw);
        if ($primary !== null) {
            $ids = array_values(array_diff($ids, [$primary]));
            array_unshift($ids, $primary);
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{credential: array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null, credentials: list<array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}>}
     * @throws CredentialResolutionException when a credential is gone or cannot be decrypted
     */
    public function resolveTemplateCredentials(array $raw): array
    {
        $ids = self::credentialIds($raw);
        $primary = self::primaryId($raw);
        /** @var array<int, Credential> $found */
        $found = $ids === [] ? [] : Credential::find()->where(['id' => $ids])->indexBy('id')->all();
        $snapshot = self::snapshotById($raw);
        $resolved = [];
        $failures = [];
        foreach ($ids as $id) {
            $role = $id === $primary ? Credential::ROLE_PRIMARY : Credential::ROLE_ADDITIONAL;
            $credential = $found[$id] ?? null;
            $data = $credential !== null ? $this->decrypt($credential) : null;
            if ($data === null) {
                $failures[] = [
                    'id' => $id,
                    'name' => $credential->name ?? ($snapshot[$id]['name'] ?? null),
                    'role' => $role,
                    'reason' => $credential === null
                        ? CredentialResolutionException::REASON_MISSING
                        : CredentialResolutionException::REASON_UNDECRYPTABLE,
                ];
                continue;
            }
            $resolved[$id] = $data;
        }
        if ($failures !== []) {
            throw CredentialResolutionException::fromFailures($failures);
        }

        return [
            'credential' => $primary !== null ? ($resolved[$primary] ?? null) : null,
            'credentials' => array_values($resolved),
        ];
    }

    /**
     * @return array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null
     * @throws CredentialResolutionException when the SCM credential cannot be decrypted
     */
    public function resolveScmCredential(?Project $project): ?array
    {
        $credential = Credential::findOne((int)($project?->scm_credential_id ?? 0));
        if ($credential === null) {
            return null;
        }
        $data = $this->decrypt($credential);
        if ($data === null) {
            throw CredentialResolutionException::fromFailures([[
                'id' => (int)$credential->id,
                'name' => (string)$credential->name,
                'role' => Credential::ROLE_SCM,
                'reason' => CredentialResolutionException::REASON_UNDECRYPTABLE,
            ]]);
        }

        return $data;
    }

    /**
     * The job's credentials without secrets, primary first. Names come from
     * the launch snapshot when present, so deleted credentials stay named.
     *
     * @param array<string, mixed> $raw
     * @return list<array{id: int, name: string|null, credential_type: string|null, role: string, deleted: bool}>
     */
    public function describe(array $raw): array
    {
        $ids = self::credentialIds($raw);
        if ($ids === []) {
            return [];
        }
        $primary = self::primaryId($raw);
        $snapshot = self::snapshotById($raw);
        /** @var array<int, array{id: int|string, name: string, credential_type: string}> $live */
        $live = Credential::find()->select(['id', 'name', 'credential_type'])->where(['id' => $ids])->indexBy('id')->asArray()->all();

        return array_map(static fn (int $id): array => [
            'id' => $id,
            'name' => $snapshot[$id]['name'] ?? ($live[$id]['name'] ?? null),
            'credential_type' => $snapshot[$id]['credential_type'] ?? ($live[$id]['credential_type'] ?? null),
            'role' => $id === $primary ? Credential::ROLE_PRIMARY : Credential::ROLE_ADDITIONAL,
            'deleted' => !isset($live[$id]),
        ], $ids);
    }

    /**
     * describe() plus the project's SCM credential, for the job.started audit entry.
     *
     * @param array<string, mixed> $raw
     * @return list<array{id: int, name: string|null, credential_type: string|null, role: string, deleted: bool}>
     */
    public function describeForAudit(array $raw): array
    {
        $described = $this->describe($raw);
        $project = Project::findOne((int)($raw['project_id'] ?? 0));
        $scm = Credential::findOne((int)($project?->scm_credential_id ?? 0));
        if ($scm !== null) {
            $described[] = [
                'id' => (int)$scm->id,
                'name' => (string)$scm->name,
                'credential_type' => (string)$scm->credential_type,
                'role' => Credential::ROLE_SCM,
                'deleted' => false,
            ];
        }

        return $described;
    }

    /**
     * @return array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null
     */
    private function decrypt(Credential $credential): ?array
    {
        try {
            /** @var CredentialService $service */
            $service = \Yii::$app->get('credentialService');
            $secrets = $service->getSecrets($credential);
        } catch (\Exception) {
            \Yii::warning("Credential #{$credential->id} cannot be decrypted.", __CLASS__);
            return null;
        }

        return [
            'credential_type' => (string)$credential->credential_type,
            'username' => $credential->username,
            'env_var_name' => $credential->env_var_name,
            'secrets' => $secrets,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function primaryId(array $raw): ?int
    {
        $primary = is_numeric($raw['credential_id'] ?? null) ? (int)$raw['credential_id'] : 0;

        return $primary > 0 ? $primary : null;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<int, array{name: string|null, credential_type: string|null}>
     */
    private static function snapshotById(array $raw): array
    {
        $byId = [];
        foreach ((array)($raw[Job::PAYLOAD_CREDENTIAL_SNAPSHOT] ?? []) as $entry) {
            if (is_array($entry) && is_numeric($entry['id'] ?? null)) {
                $byId[(int)$entry['id']] = [
                    'name' => isset($entry['name']) ? (string)$entry['name'] : null,
                    'credential_type' => isset($entry['credential_type']) ? (string)$entry['credential_type'] : null,
                ];
            }
        }

        return $byId;
    }
}
