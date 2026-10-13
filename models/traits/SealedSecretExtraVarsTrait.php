<?php

declare(strict_types=1);

namespace app\models\traits;

/**
 * The secret_extra_vars column of job, schedule and workflow_job: password answers sealed
 * by {@see \app\components\SurveySecrets::seal()}, while extra_vars shows
 * the placeholder in their place.
 *
 * It is set in code only: the column has no rule, so load() never assigns
 * it, and fields() leaves it out of toArray().
 *
 * The column comes with migration m000080 (job, schedule) and m000084
 * (workflow_job). Code can run before the column's
 * migration (a schedule runner that starts before the app container has
 * migrated, or bind-mounted code right after `git pull`), so the column is
 * read and written only through these methods: without the column there
 * are no stored answers, and storing one fails with an actionable message.
 */
trait SealedSecretExtraVarsTrait
{
    /**
     * The sealed password answers, or null when there are none (also while
     * the column does not exist yet).
     */
    public function getSealedSecretExtraVars(): ?string
    {
        if (!$this->hasAttribute('secret_extra_vars')) {
            return null;
        }
        $sealed = $this->getAttribute('secret_extra_vars');

        return is_string($sealed) && $sealed !== '' ? $sealed : null;
    }

    /**
     * Store sealed password answers; null removes them. The caller saves.
     *
     * @throws \RuntimeException when there is a value to store but the
     *     column does not exist yet
     */
    public function setSealedSecretExtraVars(?string $sealed): void
    {
        if ($this->hasAttribute('secret_extra_vars')) {
            $this->setAttribute('secret_extra_vars', $sealed);
            return;
        }
        if ($sealed !== null) {
            throw new \RuntimeException('The database update has not finished; run the migrations.');
        }
    }

    /**
     * The fields toArray() returns, without the sealed answers.
     *
     * @return array<int|string, string|\Closure>
     */
    public function fields(): array
    {
        $fields = parent::fields();
        unset($fields['secret_extra_vars']);

        return $fields;
    }
}
