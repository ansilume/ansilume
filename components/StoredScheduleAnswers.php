<?php

declare(strict_types=1);

namespace app\components;

/**
 * The password answers a schedule has stored, read from its extra vars and
 * its sealed answers (schedule.secret_extra_vars).
 *
 * The extra vars hold SurveySecrets::PLACEHOLDER at the key of every sealed
 * answer. A key that is a password field name of the job template, usable
 * or not, and still holds its value in plain text (it became a password
 * field after the schedule was saved, or a release up to 2.8.1 stored it)
 * is a "plain answer": it counts as a stored answer too, so the schedule
 * page, form and API show the placeholder for it, and the next save or
 * scheduled launch seals it.
 */
final class StoredScheduleAnswers
{
    /** @var array<int|string, mixed> the extra vars decoded; [] when they are no JSON object or list */
    private array $vars;

    /** @var array<string, mixed>|null the sealed answers, once opened */
    private ?array $opened = null;

    /**
     * @param string|null $extraVars the schedule's extra vars, as stored
     * @param string|null $sealed the schedule's sealed answers
     * @param list<string> $passwordNames every password field name of the schedule's job template, usable or not
     */
    public function __construct(
        #[\SensitiveParameter] private readonly ?string $extraVars,
        private readonly ?string $sealed,
        private readonly array $passwordNames
    ) {
        $decoded = json_decode((string)$extraVars, true);
        $this->vars = is_array($decoded) ? $decoded : [];
    }

    /**
     * The extra vars, decoded.
     *
     * @return array<int|string, mixed>
     */
    public function vars(): array
    {
        return $this->vars;
    }

    /**
     * Whether the schedule has stored any answer.
     */
    public function exist(): bool
    {
        return $this->sealed !== null || $this->plain() !== [];
    }

    /**
     * The plain answers: values other than the placeholder that the extra
     * vars hold for password fields.
     *
     * @return array<string, mixed>
     */
    public function plain(): array
    {
        $plain = [];
        foreach ($this->passwordNames as $name) {
            if (array_key_exists($name, $this->vars) && $this->vars[$name] !== SurveySecrets::PLACEHOLDER) {
                $plain[$name] = $this->vars[$name];
            }
        }

        return $plain;
    }

    /**
     * The keys of the stored answers. Those of the sealed answers come from
     * opening them, or, when they cannot be decrypted, from the placeholders
     * in the extra vars, which stand at their keys.
     *
     * @return list<string>
     */
    public function names(): array
    {
        try {
            $sealedNames = array_keys($this->sealedAnswers());
        } catch (SurveySecretException) {
            $sealedNames = $this->placeholderKeys();
        }

        return array_values(array_unique(array_map('strval', array_merge(array_keys($this->plain()), $sealedNames))));
    }

    /**
     * The stored answers by key, plain and sealed.
     *
     * @return array<string, mixed>
     * @throws SurveySecretException when the sealed answers cannot be decrypted
     */
    public function values(): array
    {
        return $this->sealedAnswers() + $this->plain();
    }

    /**
     * The extra vars as they may be shown: with the placeholder in place of
     * every plain answer too, re-encoded in their top-level shape (an
     * object keyed "0" stays an object). Unchanged when there is none.
     */
    public function masked(): ?string
    {
        $plain = $this->plain();
        if ($plain === []) {
            return $this->extraVars;
        }
        $public = SurveySecrets::split($this->vars, array_map('strval', array_keys($plain)))['public'];

        // A number too large for JSON (1e999 decodes as INF) shows as 0 rather than not at all.
        return (string)SurveySecrets::reencode(
            $this->extraVars,
            $public,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    /**
     * @return array<string, mixed>
     * @throws SurveySecretException
     */
    private function sealedAnswers(): array
    {
        if ($this->opened === null) {
            $this->opened = SurveySecrets::open($this->sealed);
        }

        return $this->opened;
    }

    /**
     * @return list<string>
     */
    private function placeholderKeys(): array
    {
        $keys = [];
        foreach ($this->vars as $key => $value) {
            if ($value === SurveySecrets::PLACEHOLDER) {
                $keys[] = (string)$key;
            }
        }

        return $keys;
    }
}
