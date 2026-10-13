<?php

declare(strict_types=1);

namespace app\services;

use app\components\StoredScheduleAnswers;
use app\models\Schedule;

/**
 * Whom a schedule runs as: the user who last armed it, that is who last
 * saved what or when it runs, or enabled it. The column is created_by (the
 * API field keeps that name; the web UI calls it "Runs as"). Its jobs name
 * that user as their launcher, who never approves them.
 *
 * A save arms the schedule when it changes its job template, cron
 * expression, timezone or extra vars, enters a password answer (a value
 * other than SurveySecrets::PLACEHOLDER for a password field) or drops a
 * stored one, or enables it (enabled from 0 to 1): through the web form, the
 * web toggle, the API update and the API toggle alike. Renaming, disabling
 * and a save that changes nothing keep the user it runs as.
 *
 * Why: otherwise a user who edits someone else's schedule of a job template
 * with an approval rule chooses what runs and when, while its job names the
 * schedule's creator as launcher, and then approves that job alone.
 *
 * Extra vars count as changed when they hold other JSON values than the
 * stored ones, compared with the password answers that either still holds
 * in plain text masked as the edit form shows them: a text that only writes
 * the same values differently (whitespace, line breaks, escapes, another
 * spelling of the same number) does not count; another value, another type
 * (1 and 1.0, {} and []) or another order of keys does. A text that is no
 * JSON, or holds a number too large for JSON, counts as changed unless it
 * stays exactly the same.
 *
 * Usage, for a change of a loaded schedule: create it before the change is
 * prepared (ScheduleSecrets::prepare() marks the extra vars dirty, which
 * drops their stored value from the old attributes), then claim() right
 * before the save, and add auditContext() to the change's audit entry.
 */
final class ScheduleRunAs
{
    /**
     * The key of a change of the user a schedule runs as in the context of
     * its schedule.updated or schedule.toggled audit entry: {from, to}, the
     * user IDs before and after the change.
     */
    public const AUDIT_KEY = 'run_as';

    /** The schedule page's note under Runs as. */
    public const VIEW_NOTE = 'Scheduled jobs run as this user, and only while they may launch the job template. '
        . 'Whoever saves a change to the job template, cron expression, timezone or extra vars, or enables '
        . 'the schedule, becomes the user it runs as.';

    /** The edit form's note for a schedule that runs as another user than the one who edits it. */
    public const FORM_NOTE = 'Saving a change to the job template, cron expression, timezone or extra vars, '
        . 'or enabling the schedule, makes it run as you.';

    /** The form's hint of whom the schedule runs as: a new schedule, or one that runs as the user who edits it. */
    public const FORM_RUNS_AS_YOU = 'Scheduled jobs run as you.';

    /**
     * The form's hint of whom a schedule runs as that runs as another user
     * than the one who edits it, followed by FORM_NOTE. sprintf(): the
     * user's name, or "user #<id>" when the user cannot be read.
     */
    public const FORM_RUNS_AS_OTHER = 'Scheduled jobs run as %s. ';

    /** The attributes besides the extra vars that decide what or when a schedule runs. */
    private const ARMING_ATTRIBUTES = ['job_template_id', 'cron_expression', 'timezone'];

    /** Prefix of the comparison key of extra vars compared as their text. */
    private const TEXT_KEY = "text\0";

    /** How decoded extra vars are encoded for the comparison: a float keeps its fraction, so 1.0 is not 1. */
    private const JSON_FLAGS = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var array<string, mixed> the schedule's stored values (its old attributes); [] for a new schedule */
    private readonly array $stored;

    /** @var array{run_as?: array{from: int, to: int}} */
    private array $auditContext = [];

    /**
     * Take the stored values of $schedule, before its change is prepared.
     */
    public function __construct(private readonly Schedule $schedule)
    {
        $this->stored = $schedule->getOldAttributes();
    }

    /**
     * When the change of the schedule about to be saved arms it, make
     * $actorId the user it runs as (created_by), so the save stores both
     * together. Call it once the change is validated and its password
     * answers are prepared, right before the save. A new schedule runs as
     * its creator already, so nothing changes for it.
     *
     * @param bool $answersChanged whether the change stores a password answer
     *     anew or drops one (ScheduleSecrets::answersChanged())
     * @return bool whether the user the schedule runs as changed
     */
    public function claim(int $actorId, bool $answersChanged = false): bool
    {
        $this->auditContext = [];
        $from = (int)$this->schedule->created_by;
        if ($from === $actorId || !$this->arms($answersChanged)) {
            return false;
        }
        $this->schedule->created_by = $actorId;
        $this->auditContext = [self::AUDIT_KEY => ['from' => $from, 'to' => $actorId]];

        return true;
    }

    /**
     * The context the schedule.updated or schedule.toggled audit entry of
     * the save after the last claim() gets: run_as with the user the
     * schedule ran as and the one it runs as now, or [] when it keeps its
     * user.
     *
     * @return array{run_as?: array{from: int, to: int}}
     */
    public function auditContext(): array
    {
        return $this->auditContext;
    }

    /**
     * Whether the change of the schedule about to be saved arms it.
     */
    private function arms(bool $answersChanged): bool
    {
        if ($answersChanged || ((bool)$this->schedule->enabled && !(bool)($this->stored['enabled'] ?? false))) {
            return true;
        }
        foreach (self::ARMING_ATTRIBUTES as $attribute) {
            if (self::text($this->stored[$attribute] ?? null) !== self::text($this->schedule->getAttribute($attribute))) {
                return true;
            }
        }

        return $this->varsChanged();
    }

    /**
     * Whether the extra vars about to be saved differ from the stored ones.
     * The job template is the stored one here (another one arms anyway), so
     * its password fields mask the plain answers on both sides.
     */
    private function varsChanged(): bool
    {
        $names = ScheduleSecrets::passwordNames((int)$this->schedule->job_template_id);
        $stored = $this->stored['extra_vars'] ?? null;

        return self::varsKey(is_string($stored) ? $stored : null, $names) !== self::varsKey($this->schedule->extra_vars, $names);
    }

    /**
     * The comparison key of an extra vars text: that of the text with its
     * plain password answers masked (StoredScheduleAnswers::masked()), or,
     * for no extra vars or a text that is no JSON that can be encoded again,
     * that of the text itself.
     *
     * @param list<string> $passwordNames every password field name of the job template, usable or not
     */
    private static function varsKey(#[\SensitiveParameter] ?string $text, array $passwordNames): string
    {
        $key = self::canonical($text);
        if ($key === '' || str_starts_with($key, self::TEXT_KEY)) {
            return $key;
        }

        return self::canonical((new StoredScheduleAnswers($text, null, $passwordNames))->masked());
    }

    /**
     * '' for no extra vars; the text decoded and encoded again, twice: with
     * integers too large for PHP as numbers and as their digits, so neither
     * two such integers nor such an integer and the string of its digits
     * compare equal; TEXT_KEY and the text itself for a text that is no
     * JSON, or that cannot be encoded again (a number too large for JSON).
     */
    private static function canonical(#[\SensitiveParameter] ?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $decoded = json_decode($text);
        $encoded = json_last_error() === JSON_ERROR_NONE ? json_encode($decoded, self::JSON_FLAGS) : false;
        if ($encoded === false) {
            return self::TEXT_KEY . $text;
        }

        return $encoded . "\0" . (string)json_encode(json_decode($text, false, 512, JSON_BIGINT_AS_STRING), self::JSON_FLAGS);
    }

    /**
     * An attribute's value for the comparison: as text, '' for none.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
