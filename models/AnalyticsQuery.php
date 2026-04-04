<?php

declare(strict_types=1);

namespace app\models;

use yii\base\Model;

/**
 * Form model for analytics query parameters.
 *
 * Validates and normalises filter inputs used by AnalyticsService.
 *
 * @property-read int $dateFromTimestamp
 * @property-read int $dateToTimestamp
 */
class AnalyticsQuery extends Model
{
    public const GRANULARITY_DAILY = 'daily';
    public const GRANULARITY_WEEKLY = 'weekly';

    public ?string $date_from = null;
    public ?string $date_to = null;
    public ?int $project_id = null;
    public ?int $template_id = null;
    public ?int $user_id = null;
    public ?int $runner_group_id = null;
    public string $granularity = self::GRANULARITY_DAILY;

    public function rules(): array
    {
        return [
            [['date_from', 'date_to'], 'date', 'format' => 'php:Y-m-d'],
            [['project_id', 'template_id', 'user_id', 'runner_group_id'], 'integer'],
            ['granularity', 'in', 'range' => [self::GRANULARITY_DAILY, self::GRANULARITY_WEEKLY]],
            ['date_to', 'validateDateRange'],
        ];
    }

    /**
     * Ensure the date range does not exceed 365 days.
     */
    public function validateDateRange(string $attribute): void
    {
        if ($this->date_from === null || $this->date_to === null) {
            return;
        }
        $from = strtotime($this->date_from);
        $to = strtotime($this->date_to);
        if ($from === false || $to === false) {
            return;
        }
        if ($to < $from) {
            $this->addError($attribute, 'End date must be on or after start date.');
            return;
        }
        if (($to - $from) > 365 * 86400) {
            $this->addError($attribute, 'Date range must not exceed 365 days.');
        }
    }

    /**
     * Apply sensible defaults when no dates are supplied.
     *
     * Defaults to the last 30 days.
     */
    public function applyDefaults(): void
    {
        if ($this->date_from === null) {
            $this->date_from = date('Y-m-d', strtotime('-30 days'));
        }
        if ($this->date_to === null) {
            $this->date_to = date('Y-m-d');
        }
    }

    public function getDateFromTimestamp(): int
    {
        if ($this->date_from === null) {
            return 0;
        }
        $ts = strtotime($this->date_from);
        return $ts !== false ? $ts : 0;
    }

    public function getDateToTimestamp(): int
    {
        if ($this->date_to === null) {
            return 0;
        }
        $ts = strtotime($this->date_to . ' 23:59:59');
        return $ts !== false ? $ts : 0;
    }

    /**
     * @param array<string> $fields
     * @param array<string> $expand
     * @return array<string, mixed>
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'project_id' => $this->project_id,
            'template_id' => $this->template_id,
            'user_id' => $this->user_id,
            'runner_group_id' => $this->runner_group_id,
            'granularity' => $this->granularity,
        ];
    }
}
