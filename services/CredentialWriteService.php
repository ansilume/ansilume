<?php

declare(strict_types=1);

namespace app\services;

use app\components\CredentialSecretPolicy;
use app\components\CredentialUsage;
use app\models\AuditLog;
use app\models\Credential;
use yii\base\Component;

/**
 * Creates, updates and deletes credentials for the web UI and the REST API.
 *
 * - Create needs the secret of the credential's type.
 * - Update keeps the stored secret when none is submitted; a submitted one
 *   replaces the whole blob. Changing the type needs the new type's secret,
 *   otherwise the old type's secret would stay behind.
 * - Delete refuses while the credential is in use, unless forced.
 *
 * Audit entries name what changed, never a secret value.
 */
class CredentialWriteService extends Component
{
    private const TRACKED_FIELDS = ['name', 'description', 'credential_type', 'username', 'env_var_name'];

    /**
     * @param array<array-key, mixed> $secretInput submitted secrets
     * @param array<string, mixed> $auditContext
     */
    public function create(Credential $credential, array $secretInput, array $auditContext = []): bool
    {
        if (!$credential->validate()) {
            return false;
        }
        $type = (string)$credential->credential_type;
        $provided = CredentialSecretPolicy::provided($type, $secretInput);
        $missing = CredentialSecretPolicy::missing($type, $provided);
        if ($missing !== []) {
            $credential->addError('secrets', CredentialSecretPolicy::missingMessage($type, $missing, false));
            return false;
        }

        $credential->secret_data = $this->credentialService()->encryptSecrets($this->buildSecrets($type, $provided));
        $credential->save(false);
        $this->audit(AuditLog::ACTION_CREDENTIAL_CREATED, $credential, ['name' => $credential->name, 'type' => $type] + $auditContext);

        return true;
    }

    /**
     * @param array<array-key, mixed> $secretInput submitted secrets; blank keeps the stored ones
     * @param array<string, mixed> $auditContext
     */
    public function update(Credential $credential, array $secretInput, array $auditContext = []): bool
    {
        $previousType = (string)$credential->getOldAttribute('credential_type');
        if (!$credential->validate()) {
            return false;
        }
        $type = (string)$credential->credential_type;
        $provided = CredentialSecretPolicy::provided($type, $secretInput);
        $typeChanged = $type !== $previousType;
        $missing = CredentialSecretPolicy::missing($type, $provided);
        if ($typeChanged && $missing !== []) {
            $credential->addError('secrets', CredentialSecretPolicy::missingMessage($type, $missing, true));
            return false;
        }

        $changedFields = $this->changedFields($credential);
        $secretChanged = $provided !== [];
        if ($secretChanged) {
            $credential->secret_data = $this->credentialService()->encryptSecrets($this->buildSecrets($type, $provided));
        }
        $credential->save(false);

        $entry = ['name' => $credential->name, 'type' => $type, 'secret_changed' => $secretChanged, 'changed_fields' => $changedFields];
        if ($typeChanged) {
            $entry['previous_type'] = $previousType;
        }
        $this->audit(AuditLog::ACTION_CREDENTIAL_UPDATED, $credential, $entry + $auditContext);

        return true;
    }

    /**
     * Deletes the credential unless it is in use and $force is false. The
     * database detaches it from templates and projects (foreign keys).
     *
     * @param array<string, mixed> $auditContext
     * @return array{deleted: bool, usage: CredentialUsage}
     */
    public function delete(Credential $credential, bool $force, ?int $viewerId, array $auditContext = []): array
    {
        $usage = $this->usageService()->forCredential($credential, $viewerId);
        if ($usage->isInUse() && !$force) {
            return ['deleted' => false, 'usage' => $usage];
        }

        $entry = ['name' => $credential->name, 'type' => $credential->credential_type];
        if ($usage->isInUse()) {
            $entry['forced'] = true;
            $entry['job_templates'] = $usage->jobTemplateTotal;
            $entry['projects'] = $usage->projectTotal;
            $entry['pending_jobs'] = $usage->pendingJobCount;
        }
        $credential->delete();
        $this->audit(AuditLog::ACTION_CREDENTIAL_DELETED, $credential, $entry + $auditContext);

        return ['deleted' => true, 'usage' => $usage];
    }

    /**
     * The stored blob: the type's secret plus, for SSH keys, the derived
     * public key and strength metadata shown on the credential page.
     *
     * @param array<string, string> $provided
     * @return array<string, mixed>
     */
    private function buildSecrets(string $type, array $provided): array
    {
        if ($type !== Credential::TYPE_SSH_KEY) {
            return $provided;
        }
        $analysis = $this->credentialService()->analyzePrivateKey($provided['private_key']);

        return $provided + [
            'public_key' => $analysis['public_key'],
            'algorithm' => $analysis['algorithm'],
            'bits' => $analysis['bits'],
            'key_secure' => $analysis['key_secure'],
        ];
    }

    /**
     * @return list<string>
     */
    private function changedFields(Credential $credential): array
    {
        return array_values(array_filter(
            self::TRACKED_FIELDS,
            static fn (string $field): bool => self::stringOf($credential->getOldAttribute($field)) !== self::stringOf($credential->getAttribute($field))
        ));
    }

    private static function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function audit(string $action, Credential $credential, array $entry): void
    {
        \Yii::$app->get('auditService')->log($action, 'credential', (int)$credential->id, null, $entry);
    }

    private function credentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = \Yii::$app->get('credentialService');

        return $service;
    }

    private function usageService(): CredentialUsageService
    {
        /** @var CredentialUsageService $service */
        $service = \Yii::$app->get('credentialUsageService');

        return $service;
    }
}
