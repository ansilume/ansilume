<?php

declare(strict_types=1);

namespace app\models;

use app\components\CronExpressionRules;
use app\components\CronRuns;
use app\components\SurveySecrets;
use app\models\traits\SealedSecretExtraVarsTrait;
use app\services\JobLaunchSecrets;
use Cron\CronExpression;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property int         $job_template_id
 * @property string      $cron_expression  Standard 5-field cron: min hour dom mon dow
 * @property string      $timezone         PHP timezone name
 * @property string|null $extra_vars       JSON extra vars override; password answers show as SurveySecrets::PLACEHOLDER
 * @property bool        $enabled
 * @property int|null    $last_run_at      Unix timestamp of last execution
 * @property int|null    $next_run_at      Pre-computed Unix timestamp of next execution
 * @property int         $created_by       The user the schedule runs as ("Runs as"): its creator, or whoever last changed what or when it runs, or enabled it (ScheduleRunAs)
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property JobTemplate $jobTemplate
 * @property User        $creator          The user the schedule runs as (created_by)
 *
 * The sealed password answers (column secret_extra_vars) are read and
 * written only through getSealedSecretExtraVars() and
 * setSealedSecretExtraVars(), see SealedSecretExtraVarsTrait.
 */
class Schedule extends ActiveRecord
{
    use SealedSecretExtraVarsTrait;

    /** Longest cron expression and timezone the string rules accept. */
    public const CRON_FIELD_MAX_LENGTH = 64;

    /**
     * The schedule's page and edit form, above why a save refuses its cron
     * expression (cronExpressionProblem()).
     */
    public const CRON_REFUSED_NOTE = 'An older version saved this cron expression, which a save now refuses. '
        . 'The schedule runs as before until its expression is changed as this says:';

    /** The schedules list, next to a cron expression a save refuses; its title says why. */
    public const CRON_REFUSED_BADGE = 'Change needed';

    /**
     * Before the description of a cron expression a save refuses, on the
     * schedule's page and under the cron expression of its form, in place
     * of the check mark: the scheduler does not run it as the description
     * reads.
     */
    public const CRON_AS_WRITTEN = 'As written:';

    /**
     * A negative step, such as the "/-5" of a minute field "0-30/-5". The
     * cron library accepts it, then loops until PHP runs out of memory, a
     * fatal error no catch block stops, or throws a ValueError when it
     * computes a run.
     */
    private const NEGATIVE_CRON_STEP = '~/-~';

    public static function tableName(): string
    {
        return '{{%schedule}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name', 'job_template_id', 'cron_expression'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['job_template_id'], 'integer'],
            // Set in code only: a form must not choose whom the schedule runs as.
            [['!created_by'], 'integer'],
            [['cron_expression'], 'string', 'max' => self::CRON_FIELD_MAX_LENGTH],
            [['cron_expression'], 'validateCronExpression'],
            [['timezone'], 'string', 'max' => self::CRON_FIELD_MAX_LENGTH],
            [['timezone'], 'validateTimezone'],
            [['timezone'], 'default', 'value' => 'UTC'],
            [['extra_vars'], 'string', 'max' => 65535],
            [['extra_vars'], 'validateJson'],
            [['enabled'], 'boolean'],
            [['enabled'], 'default', 'value' => true],
            [['job_template_id'], 'exist', 'targetClass' => JobTemplate::class, 'targetAttribute' => 'id'],
        ];
    }

    /**
     * The cron library must accept the expression, it must meet
     * CronExpressionRules, and it must have a run in the schedule's timezone,
     * or UTC while that is invalid (nextRunOf()): otherwise the schedule
     * would never run, or run at other times than it says.
     */
    public function validateCronExpression(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_string($value) || !self::isParsableCron($value) || !self::constructs($value)) {
            $this->addError($attribute, CronExpressionRules::INVALID);
            return;
        }
        $problem = CronExpressionRules::problem($value);
        if ($problem === null && self::nextRunOf($value, $this->timezoneOfRuns()) === null) {
            $problem = CronExpressionRules::NEVER_RUNS;
        }
        if ($problem !== null) {
            $this->addError($attribute, $problem);
        }
    }

    /**
     * The timezone the runs are computed in: the schedule's, or UTC while it
     * is empty or invalid, which the timezone rule reports.
     */
    private function timezoneOfRuns(): string
    {
        $timezone = $this->timezone;
        if (!self::isComputable($timezone)) {
            return 'UTC';
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception | \ValueError $e) {
            return 'UTC';
        }

        return $timezone;
    }

    /**
     * Whether a cron expression may be handed to the cron library: no
     * longer than the rule allows, as parsing takes time quadratic in the
     * length, and without a negative step. Stored expressions are checked
     * too: older versions could save ones that fail it.
     */
    public static function isParsableCron(string $expression): bool
    {
        return self::isComputable($expression) && preg_match(self::NEGATIVE_CRON_STEP, $expression) === 0;
    }

    private static function constructs(string $expression): bool
    {
        try {
            new CronExpression($expression);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    public function validateTimezone(string $attribute): void
    {
        try {
            new \DateTimeZone($this->$attribute);
        } catch (\Exception | \ValueError $e) {
            // ValueError: a timezone with a NUL byte.
            $this->addError($attribute, 'Invalid timezone identifier.');
        }
    }

    /**
     * The extra vars must be JSON a launch can store: JSON allows a number
     * too large for it (1e999, -1e999), which PHP reads as infinity and
     * cannot write back, so every run would fail to launch. They get the
     * message of that refusal. This runs before ScheduleSecrets seals the
     * password answers, so a password answer like that gets it too.
     */
    public function validateJson(string $attribute): void
    {
        if (empty($this->$attribute)) {
            return;
        }
        $decoded = json_decode((string)$this->$attribute);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError($attribute, 'Must be valid JSON.');
        } elseif (json_encode($decoded) === false) {
            $this->addError($attribute, SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, json_last_error()));
        }
    }

    /**
     * Set next_run_at from cron_expression and timezone; null when there is
     * no valid expression. The caller saves it.
     */
    public function computeNextRunAt(): void
    {
        // Forms compute this before validating: a missing, non-text,
        // over-long or otherwise unparsable cron expression or timezone (a
        // list from a form, a number or a boolean from a JSON body, text
        // longer than the string rules allow, a negative step) is left to the
        // rules. An empty timezone is UTC, as the default rule stores it.
        $timezone = $this->timezone ?: 'UTC';
        $expression = $this->cron_expression;
        if (!is_string($expression) || !self::isParsableCron($expression) || !self::isComputable($timezone)) {
            $this->next_run_at = null;
            return;
        }
        $this->next_run_at = self::nextRunOf($expression, $timezone);
    }

    /**
     * The next run of a cron expression after $now, the current time when
     * null, in a timezone, as a Unix timestamp, or null when there is none:
     * the first run from the second after $now (firstRunFrom()). In a
     * timezone whose offset has seconds (+01:00:30) the runs fall between
     * two minutes of UTC, so a search from the next whole minute would pass
     * the one of the current minute.
     */
    public static function nextRunOf(string $expression, string $timezone, ?int $now = null): ?int
    {
        return self::firstRunFrom($expression, $timezone, ($now ?? time()) + 1);
    }

    /**
     * The first run of a cron expression at or after $from, a Unix
     * timestamp, in a timezone, as CronRuns computes it also on the nights
     * the clocks change; null when there is none. The cron
     * library throws a RuntimeException when it finds no run in 1000 tries,
     * as for the 30th of February, and a TypeError when a day of the month
     * and a day of the week are both set and neither finds one, as for
     * "0 0 30 2 ?"; DateTimeZone an Exception for an unknown timezone and a
     * ValueError for one with a NUL byte.
     */
    public static function firstRunFrom(string $expression, string $timezone, int $from): ?int
    {
        try {
            return CronRuns::first(new CronExpression($expression), $from, $timezone);
        } catch (\Exception | \TypeError | \ValueError $e) {
            return null;
        }
    }

    /**
     * Why a save refuses the stored cron expression, as the error of the
     * cron rules says, or null when a save accepts it. Versions up to 2.12.2
     * saved expressions that the rules refuse, and such a schedule runs as
     * before until its expression is changed: its page, its edit form, the
     * schedules list, the API and schedule/check say so. The schedule's own
     * errors stay as they are.
     */
    public function cronExpressionProblem(): ?string
    {
        $check = clone $this;

        return $check->validate(['cron_expression']) ? null : (string)$check->getFirstError('cron_expression');
    }

    /**
     * Whether a cron expression or timezone may be handed to the parsers:
     * non-empty text no longer than the string rules allow.
     */
    private static function isComputable(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= self::CRON_FIELD_MAX_LENGTH;
    }

    /**
     * Returns true if this schedule is due to run right now (next_run_at <= time()).
     */
    public function isDue(): bool
    {
        if (!$this->enabled || $this->next_run_at === null) {
            return false;
        }
        return $this->next_run_at <= time();
    }

    public function getJobTemplate(): \yii\db\ActiveQuery
    {
        return $this->hasOne(JobTemplate::class, ['id' => 'job_template_id']);
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }
}
