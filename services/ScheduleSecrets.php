<?php

declare(strict_types=1);

namespace app\services;

use app\components\StoredScheduleAnswers;
use app\components\SurveyDefinition;
use app\components\SurveySecretException;
use app\components\SurveySecrets;
use app\models\JobTemplate;
use app\models\Schedule;

/**
 * Password answers in schedule extra vars.
 *
 * A value is secret when its top-level key is a password survey field name
 * of the schedule's job template, usable or not (a template with a name that
 * cannot carry an answer cannot be launched, but answers stored under that
 * exact name are sealed all the same), or the key of an answer the schedule
 * has stored.
 * The schedule stores it only sealed (schedule.secret_extra_vars); its extra
 * vars, its page and its edit form show SurveySecrets::PLACEHOLDER instead.
 * Submitting the placeholder unchanged keeps the stored answer, but only
 * while the schedule keeps its job template: stored answers never follow a
 * schedule to another template.
 *
 * prepare() runs on every save; ScheduleLaunchSecrets::launchOverrides() at
 * every scheduled launch. Both write the extra vars and the sealed answers
 * only together, so a save and the sealing at launch never leave a mix of
 * the two in the row.
 */
final class ScheduleSecrets
{
    public const TEMPLATE_CHANGED = '%s: the job template changed; enter the real value.';
    public const RESEND_AFTER_TEMPLATE_CHANGE = 'The job template changed: send extra_vars with the password answers again.';
    public const NO_STORED_VALUE = '%s has no stored value; enter the real value.';
    public const UNDECRYPTABLE = 'The stored password answers cannot be decrypted; enter the real values.';
    public const NOT_ENCODABLE = 'The extra vars cannot be stored: they contain a number too large for JSON.';
    public const TOO_LONG = 'The extra vars are too long: with $encrypted$ in place of the password answers they exceed 65535 bytes.';

    /** The size of the extra_vars column (TEXT). */
    public const MAX_EXTRA_VARS_BYTES = 65535;

    /** How schedule.extra_vars is written: at a save, and when a launch seals plain answers. */
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var array<string, int|list<string>> */
    private array $auditContext = [];

    /** Whether the last successful prepare() stored an answer anew or dropped one. */
    private bool $answersChanged = false;

    /**
     * Seal the password answers of a schedule about to be saved. Call it
     * after validate() succeeded and the job template access check returned
     * usable (its errors name password fields of the template), immediately
     * before save(false).
     *
     * $extraVarsSubmitted is false for an API update whose body has no
     * extra_vars: the stored extra vars and answers stay as they are, unless
     * the job template changed.
     *
     * Returns null after assigning extra_vars and the sealed answers, or an
     * error for extra_vars; then nothing is assigned and nothing must be saved.
     */
    public function prepare(Schedule $schedule, bool $extraVarsSubmitted): ?string
    {
        $this->auditContext = [];
        $this->answersChanged = false;
        $storedTemplateId = self::storedTemplateId($schedule);
        $stored = new StoredScheduleAnswers(
            self::storedExtraVars($schedule),
            $schedule->getSealedSecretExtraVars(),
            self::passwordNames($storedTemplateId)
        );
        $changed = $storedTemplateId !== null && $storedTemplateId !== (int)$schedule->job_template_id;
        if (!$extraVarsSubmitted && !$changed) {
            $this->record($schedule, ['kept' => $stored->names()]);
            return null;
        }
        if (!$extraVarsSubmitted && $stored->exist()) {
            return self::RESEND_AFTER_TEMPLATE_CHANGE;
        }

        // Without extra_vars in the body, the stored ones are split again for the new template.
        return $this->resplit($schedule, $stored, $changed);
    }

    /**
     * What the last successful prepare() did, for the schedule.created and
     * schedule.updated audit entries: the job template IDs and the names of
     * the answers it kept, replaced, added and dropped. Never values.
     *
     * @return array<string, int|list<string>>
     */
    public function auditContext(): array
    {
        return $this->auditContext;
    }

    /**
     * Whether the last successful prepare() replaced a stored answer, added
     * one or dropped one: a value other than the placeholder counts as an
     * answer entered anew, even one equal to the stored answer. Such a save
     * changes what the schedule runs (see ScheduleRunAs).
     */
    public function answersChanged(): bool
    {
        return $this->answersChanged;
    }

    /**
     * The schedule's extra vars for its edit form and the API: as stored,
     * with the placeholder also for plain answers, under every password
     * field name of the template, usable or not. Submitted unchanged, they
     * keep every answer.
     */
    public static function maskedExtraVars(Schedule $schedule): ?string
    {
        $templateId = is_numeric($schedule->job_template_id) ? (int)$schedule->job_template_id : null;
        // A list loads the job templates with its schedules; a soft-deleted one is not among them.
        $loaded = $schedule->isRelationPopulated('jobTemplate') ? $schedule->jobTemplate : null;
        $names = $loaded instanceof JobTemplate && (int)$loaded->id === $templateId
            ? $loaded->passwordFieldNames()
            : self::passwordNames($templateId);

        return (new StoredScheduleAnswers($schedule->extra_vars, null, $names))->masked();
    }

    /**
     * The schedule's extra vars for its page, masked like the form's and
     * pretty-printed; null when it has none.
     */
    public static function displayExtraVars(Schedule $schedule): ?string
    {
        $masked = self::maskedExtraVars($schedule);
        if ($masked === null || $masked === '') {
            return null;
        }

        return (string)json_encode(json_decode($masked), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Split the submitted extra vars into the extra vars to store and the
     * answers to seal.
     */
    private function resplit(Schedule $schedule, StoredScheduleAnswers $stored, bool $changed): ?string
    {
        $vars = json_decode((string)$schedule->extra_vars, true);
        if (!is_array($vars)) {
            // Empty or null (clears every answer), or a JSON value without keys.
            return $this->store($schedule, $schedule->extra_vars, [], ['dropped' => $stored->names()]);
        }
        $storedNames = $stored->names();
        $secretKeys = array_merge(self::passwordNames((int)$schedule->job_template_id), $storedNames);
        $plan = self::classify($vars, $secretKeys, $storedNames, $changed);
        if (is_string($plan)) {
            return $plan;
        }
        try {
            $secrets = self::answers($vars, $plan, $stored);
        } catch (SurveySecretException) {
            return self::UNDECRYPTABLE;
        }
        $plan['dropped'] = array_values(array_diff($storedNames, array_map('strval', array_keys($secrets))));
        if ($secrets === []) {
            // No answer among them: stored as submitted.
            return $this->store($schedule, $schedule->extra_vars, [], $plan);
        }
        $public = SurveySecrets::split($vars, array_map('strval', array_keys($secrets)))['public'];
        // In the submitted shape: a password field may be named "0", and {"0": ...} must stay an object.
        $json = SurveySecrets::reencode($schedule->extra_vars, $public, self::JSON_FLAGS);
        if ($json === false) {
            return self::NOT_ENCODABLE;
        }
        if (strlen($json) > self::MAX_EXTRA_VARS_BYTES) {
            return self::TOO_LONG;
        }

        return $this->store($schedule, $json, $secrets, $plan);
    }

    /**
     * What happens to the answer of every secret key in the submitted extra
     * vars: a new value replaces the stored answer (or adds one), the
     * placeholder keeps it. The placeholder is an error for a key without a
     * stored answer, and after a template change for every key.
     *
     * @param array<int|string, mixed> $vars
     * @param list<string> $secretKeys
     * @param list<string> $storedNames
     * @return array{kept: list<string>, replaced: list<string>, added: list<string>}|string the plan, or an error
     */
    private static function classify(#[\SensitiveParameter] array $vars, array $secretKeys, array $storedNames, bool $changed): array|string
    {
        $plan = ['kept' => [], 'replaced' => [], 'added' => []];
        foreach ($vars as $key => $value) {
            $key = (string)$key;
            if (!in_array($key, $secretKeys, true)) {
                continue;
            }
            $isStored = in_array($key, $storedNames, true);
            $error = $value === SurveySecrets::PLACEHOLDER ? self::placeholderError($key, $isStored, $changed) : null;
            if ($error !== null) {
                return $error;
            }
            $plan[self::changeOf($value, $isStored)][] = $key;
        }

        return $plan;
    }

    /**
     * @return 'kept'|'replaced'|'added'
     */
    private static function changeOf(#[\SensitiveParameter] mixed $value, bool $isStored): string
    {
        if ($value === SurveySecrets::PLACEHOLDER) {
            return 'kept';
        }

        return $isStored ? 'replaced' : 'added';
    }

    private static function placeholderError(string $key, bool $isStored, bool $changed): ?string
    {
        if (!$isStored) {
            return sprintf(self::NO_STORED_VALUE, SurveyDefinition::quote($key));
        }

        return $changed ? sprintf(self::TEMPLATE_CHANGED, SurveyDefinition::quote($key)) : null;
    }

    /**
     * The answers to seal, in the order of the extra vars: the stored value
     * of every kept key, the submitted value of every other secret key.
     *
     * @param array<int|string, mixed> $vars
     * @param array{kept: list<string>, replaced: list<string>, added: list<string>} $plan
     * @return array<string, mixed>
     * @throws SurveySecretException when a kept answer cannot be decrypted
     */
    private static function answers(#[\SensitiveParameter] array $vars, array $plan, StoredScheduleAnswers $stored): array
    {
        $storedValues = $plan['kept'] === [] ? [] : $stored->values();
        $submitted = array_merge($plan['replaced'], $plan['added']);
        $answers = [];
        foreach ($vars as $key => $value) {
            $key = (string)$key;
            if (in_array($key, $plan['kept'], true)) {
                $answers[$key] = $storedValues[$key] ?? null;
            } elseif (in_array($key, $submitted, true)) {
                $answers[$key] = $value;
            }
        }

        return $answers;
    }

    /**
     * Assign the extra vars and the sealed answers. The save writes both,
     * even a value that looks unchanged: the row read before the save may be
     * older than the row the save writes, whose plain answers the scheduler
     * may have sealed meanwhile (see ScheduleLaunchSecrets). Writing only
     * one of them would mix it with the other as the scheduler left it: the
     * sealed answer of a key the extra vars no longer have, or a placeholder
     * without its sealed answer.
     *
     * @param array<string, mixed> $secrets
     * @param array<string, list<string>> $changes
     */
    private function store(Schedule $schedule, ?string $public, #[\SensitiveParameter] array $secrets, array $changes): ?string
    {
        try {
            $schedule->setSealedSecretExtraVars(SurveySecrets::seal($secrets));
        } catch (\RuntimeException $e) {
            // The answers cannot be sealed (APP_SECRET_KEY), or the database update has not finished.
            return $e->getMessage();
        }
        $schedule->extra_vars = $public;
        $schedule->markAttributeDirty('extra_vars');
        $schedule->markAttributeDirty('secret_extra_vars');
        $this->record($schedule, $changes);

        return null;
    }

    /**
     * @param array<string, list<string>> $changes
     */
    private function record(Schedule $schedule, array $changes): void
    {
        $context = ['job_template_id' => (int)$schedule->job_template_id];
        $storedTemplateId = self::storedTemplateId($schedule);
        if ($storedTemplateId !== null) {
            $context['previous_job_template_id'] = $storedTemplateId;
        }
        foreach (['kept', 'replaced', 'added', 'dropped'] as $change) {
            $names = $changes[$change] ?? [];
            if ($names !== []) {
                $context['secret_keys_' . $change] = $names;
            }
        }
        $this->auditContext = $context;
        $this->answersChanged = array_filter(array_diff_key($changes, ['kept' => true])) !== [];
    }

    /**
     * Every password field name of job template $templateId, usable or not
     * ({@see JobTemplate::passwordFieldNames()}), a soft-deleted one
     * included; [] for none.
     *
     * @return list<string>
     */
    public static function passwordNames(?int $templateId): array
    {
        if ($templateId === null) {
            return [];
        }
        /** @var JobTemplate|null $template */
        $template = JobTemplate::findWithDeleted()->andWhere(['id' => $templateId])->one();

        return $template === null ? [] : $template->passwordFieldNames();
    }

    /**
     * The job template ID the schedule has stored; null for a new schedule.
     */
    private static function storedTemplateId(Schedule $schedule): ?int
    {
        $stored = $schedule->getOldAttribute('job_template_id');

        return is_numeric($stored) ? (int)$stored : null;
    }

    /**
     * The extra vars the schedule has stored; null for a new schedule.
     */
    private static function storedExtraVars(Schedule $schedule): ?string
    {
        $stored = $schedule->getOldAttribute('extra_vars');

        return is_string($stored) ? $stored : null;
    }
}
