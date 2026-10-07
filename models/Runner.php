<?php

declare(strict_types=1);

namespace app\models;

use app\components\RunnerTransportClassifier;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int         $runner_group_id
 * @property string      $name
 * @property string      $token_hash       SHA-256 of the raw token
 * @property string|null $description
 * @property int|null    $last_seen_at
 * @property int|null    $offline_notified_at
 * @property string|null $software_version    Semver reported by the runner on each heartbeat; null for pre-upgrade runners
 * @property string|null $transport           https, http_internal or http_external (RunnerTransportClassifier); null until seen
 * @property string|null $remote_addr         client address of the last request
 * @property int|null    $plaintext_seen_at   last plain HTTP request from outside the trusted networks
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 * @property string|null $capabilities Comma-separated features the runner reported, see CAPABILITIES
 *
 * @property RunnerGroup $group
 * @property User        $creator
 * @property Job[]       $jobs
 */
class Runner extends ActiveRecord
{
    /** Seconds over which repeated self-registrations of one runner are counted. */
    public const REREGISTRATION_WINDOW = 86400;

    /** The runner neutralises the repository's ansible.cfg vault settings when a project asks for it. */
    public const CAPABILITY_VAULT_PASSWORD_SOURCE = 'vault_password_source';
    /** Every capability the server records; runners may report others, which are ignored. */
    public const CAPABILITIES = [self::CAPABILITY_VAULT_PASSWORD_SOURCE];

    public static function tableName(): string
    {
        return '{{%runner}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['runner_group_id', 'name'], 'required'],
            [['runner_group_id', 'created_by'], 'integer'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string', 'max' => 1000],
            [['token_hash'], 'string', 'max' => 64],
            [['software_version'], 'string', 'max' => 32],
            [['transport'], 'in', 'range' => RunnerTransportClassifier::TRANSPORTS],
            [['remote_addr'], 'string', 'max' => 45],
            [['plaintext_seen_at'], 'integer'],
            [['capabilities'], 'string', 'max' => 255],
        ];
    }

    /**
     * @return list<string>
     */
    public function capabilityList(): array
    {
        $names = array_map('trim', explode(',', (string)$this->capabilities));

        return array_values(array_intersect(self::CAPABILITIES, $names));
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilityList(), true);
    }

    public function getGroup(): \yii\db\ActiveQuery
    {
        return $this->hasOne(RunnerGroup::class, ['id' => 'runner_group_id']);
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getJobs(): \yii\db\ActiveQuery
    {
        return $this->hasMany(Job::class, ['runner_id' => 'id']);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && (time() - $this->last_seen_at) < RunnerGroup::STALE_AFTER;
    }

    /**
     * Is this runner running an older version than the server?
     * Returns false when the runner has not reported a version yet
     * (pre-upgrade runners) — callers use {@see hasKnownVersion()}
     * to distinguish "unknown" from "current".
     */
    public function isOutdated(): bool
    {
        if ($this->software_version === null || $this->software_version === '') {
            return false;
        }
        $serverVersion = (string)(\Yii::$app->params['version'] ?? 'dev');
        if ($serverVersion === 'dev') {
            // A dev server build has no meaningful version to compare against
            // — every numbered runner looks "newer" by version_compare, but
            // that's not a real upgrade signal to surface to operators.
            return false;
        }
        return version_compare($this->software_version, $serverVersion, '<');
    }

    public function hasKnownVersion(): bool
    {
        return $this->software_version !== null && $this->software_version !== '';
    }

    /**
     * Generate a new token. Returns ['raw' => ..., 'hash' => ...].
     * Store only the hash; show raw once to the user.
     *
     * @return array{raw: string, hash: string}
     */
    public static function generateToken(): array
    {
        $raw = bin2hex(random_bytes(32)); // 64-char hex string
        $hash = hash('sha256', $raw);
        return ['raw' => $raw, 'hash' => $hash];
    }

    /**
     * Find a runner by its raw token (hashes and compares).
     */
    public static function findByToken(string $rawToken): ?self
    {
        if ($rawToken === '') {
            return null;
        }
        /** @var static|null $result */
        $result = static::findOne(['token_hash' => hash('sha256', $rawToken)]);
        return $result;
    }

    /**
     * Records how this request reached the server and returns the changed
     * columns for the caller's update (see RunnerTransportClassifier).
     *
     * @param array<array-key, mixed> $server $_SERVER
     * @return array<string, string|int>
     */
    public function transportChanges(array $server, int $now): array
    {
        $changes = RunnerTransportClassifier::changes(
            ['transport' => $this->transport, 'remote_addr' => $this->remote_addr],
            $server,
            RunnerTransportClassifier::networks((string)(\Yii::$app->params['runnerTrustedNetworks'] ?? '')),
            $now
        );
        foreach ($changes as $column => $value) {
            $this->$column = $value;
        }

        return $changes;
    }

    /**
     * True when the last request came over plain HTTP from outside the
     * trusted networks: claim responses then carry decrypted credentials
     * in clear.
     */
    public function hasInsecureTransport(): bool
    {
        return $this->transport === RunnerTransportClassifier::HTTP_EXTERNAL;
    }
}
