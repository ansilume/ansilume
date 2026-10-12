<?php

declare(strict_types=1);

namespace app\components;

/**
 * What the survey password backfill ({@see SurveySecretBackfill}) knows
 * about the job templates after reading every row, soft-deleted ones
 * included: which have password fields, and which need an operator's
 * attention. Reading a row never throws, however malformed its JSON is.
 *
 * Only IDs and field names are kept, never a value.
 */
final class SurveySecretTemplateScan
{
    /** @var array<int, list<string>> every password field name, usable or not, by template ID, ascending */
    private array $passwordNames = [];

    /** @var list<int> */
    private array $unusable = [];

    /** @var list<int> */
    private array $withPasswordDefaults = [];

    /** @var list<int> */
    private array $passwordKeysInExtraVars = [];

    /** @var list<int> */
    private array $invalid = [];

    private int $count = 0;

    /**
     * Classify one job template row. Rows are added in ascending ID order.
     */
    public function add(
        int $id,
        #[\SensitiveParameter] ?string $surveyFields,
        #[\SensitiveParameter] ?string $extraVars
    ): void {
        $this->count++;
        if (SurveyDefinition::entries($surveyFields) === null) {
            $this->invalid[] = $id;
            return;
        }
        $names = SurveyDefinition::secretNames($surveyFields);
        if ($names !== []) {
            $this->passwordNames[$id] = $names;
        }
        if (SurveyDefinition::passwordFields($surveyFields)['unusable'] !== []) {
            $this->unusable[] = $id;
        }
        if (SurveySecretRowMasker::hasPasswordDefault($surveyFields)) {
            $this->withPasswordDefaults[] = $id;
        }
        if (self::hasAnyKey($extraVars, $names)) {
            $this->passwordKeysInExtraVars[] = $id;
        }
    }

    /** How many template rows were read. */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Every password field name of each template that has a password
     * field, usable or not ({@see SurveyDefinition::secretNames()}), by
     * template ID in ascending order: values stored under these exact
     * top-level keys are password answers. Releases up to 2.8.1 stored
     * them under the exact name, also under one that cannot carry an answer
     * now; such a template is also listed by unusable().
     *
     * @return array<int, list<string>>
     */
    public function passwordNames(): array
    {
        return $this->passwordNames;
    }

    /**
     * Templates with a password field whose name cannot carry an answer
     * ({@see SurveyDefinition::isUsablePasswordName()}: empty, numeric, not
     * text, with [ or ] in it, or not printable ASCII without a space at
     * either end, such as a name with a line break, a tab, a non-breaking
     * space or another invisible character in it or around it, or with a
     * letter outside ASCII): they cannot be launched until the field is
     * renamed. Answers stored under that exact name are sealed all the same
     * (passwordNames()).
     *
     * @return list<int>
     */
    public function unusable(): array
    {
        return $this->unusable;
    }

    /**
     * Templates with a password field that has a default.
     *
     * @return list<int>
     */
    public function withPasswordDefaults(): array
    {
        return $this->withPasswordDefaults;
    }

    /**
     * Templates whose own extra vars have a password field's name as a
     * top-level key: a plaintext default of the password. They are only
     * reported, as removing the key would change what launches send.
     *
     * @return list<int>
     */
    public function passwordKeysInExtraVars(): array
    {
        return $this->passwordKeysInExtraVars;
    }

    /**
     * Templates whose survey is not a JSON list or map; they were skipped.
     *
     * @return list<int>
     */
    public function invalid(): array
    {
        return $this->invalid;
    }

    /**
     * @param list<string> $names
     */
    private static function hasAnyKey(#[\SensitiveParameter] ?string $json, array $names): bool
    {
        $vars = json_decode((string)$json, true);
        if (!is_array($vars)) {
            return false;
        }
        foreach ($names as $name) {
            if (array_key_exists($name, $vars)) {
                return true;
            }
        }

        return false;
    }
}
