<?php

declare(strict_types=1);

namespace app\models\traits;

/**
 * Inbound trigger token of a job or workflow template. Only the SHA-256 hash
 * of the raw token is stored; the raw value is shown to the operator once,
 * right after generation, and cannot be recovered. The trigger runs as the
 * user who generated the token, or as the template's creator for tokens
 * generated before that was recorded.
 *
 * The using ActiveRecord has the attributes trigger_token,
 * trigger_token_created_by and created_by.
 */
trait TriggerTokenTrait
{
    /**
     * Generate a new random trigger token for $createdBy, the user the
     * trigger will run as. Returns the raw value.
     */
    public function generateTriggerToken(int $createdBy): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->trigger_token = hash('sha256', $raw);
        $this->trigger_token_created_by = $createdBy;
        $this->save(false, ['trigger_token', 'trigger_token_created_by']);
        return $raw;
    }

    /**
     * Remove the trigger token, effectively disabling the inbound trigger.
     */
    public function revokeTriggerToken(): void
    {
        $this->clearTriggerToken();
        $this->save(false, ['trigger_token', 'trigger_token_created_by']);
    }

    /**
     * Whether the inbound trigger is enabled, i.e. a token is configured.
     */
    public function hasTriggerToken(): bool
    {
        return (string)$this->trigger_token !== '';
    }

    /**
     * The user the inbound trigger runs as: whoever generated the token, or
     * the template's creator for tokens generated before that was recorded.
     */
    public function getTriggerUserId(): int
    {
        return (int)($this->trigger_token_created_by ?? $this->created_by);
    }

    /**
     * Forget the token and whom it runs as, without saving.
     */
    protected function clearTriggerToken(): void
    {
        $this->trigger_token = null;
        $this->trigger_token_created_by = null;
    }

    /**
     * Look up a template by its raw trigger token. Returns null for empty
     * input and unknown tokens; TriggerController turns either into a 404
     * and an invalid-token notification.
     */
    public static function findByTriggerToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }
        /** @var static|null $result */
        $result = static::findOne(['trigger_token' => hash('sha256', $token)]);
        return $result;
    }
}
