<?php

declare(strict_types=1);

namespace app\components;

/**
 * Counts of one pass of the survey password backfill ({@see SurveySecretBackfill})
 * over one kind of row, the first IDs of each outcome, and where the pass
 * got to (the last committed page). Counts and IDs only, never a value.
 */
final class SurveySecretBackfillProgress
{
    /** Every row read. */
    public const CHECKED = 'checked';
    /** Rows written: answers sealed and masked, or a survey's password defaults removed. */
    public const CHANGED = SurveySecretRowMasker::STATUS_CHANGED;
    /** Rows left as they are: their existing sealed answers cannot be opened. */
    public const UNDECRYPTABLE = SurveySecretRowMasker::STATUS_UNDECRYPTABLE;
    /** Rows left as they are: a masked column would not fit. */
    public const TOO_LONG = SurveySecretRowMasker::STATUS_TOO_LONG;
    /** Rows left as they are after an unexpected error. */
    public const FAILED = 'failed';
    /** Written rows whose stored command was cleared. */
    public const COMMAND_CLEARED = 'command_cleared';

    /** How many IDs of each outcome are kept for the report. */
    public const MAX_IDS = 50;

    /** @var array<string, int> */
    private array $counts = [
        self::CHECKED => 0,
        self::CHANGED => 0,
        self::UNDECRYPTABLE => 0,
        self::TOO_LONG => 0,
        self::FAILED => 0,
        self::COMMAND_CLEARED => 0,
    ];

    /** @var array<string, list<int>> */
    private array $ids = [];

    private int $pages = 0;

    /** @var array{template: int, job: int}|null */
    private ?array $position = null;

    /**
     * @param string $label what the rows are, for report lines ("unfinished jobs")
     */
    public function __construct(private readonly string $label)
    {
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * Count row $id under $outcome (one of the constants).
     */
    public function add(string $outcome, int $id): void
    {
        $this->counts[$outcome] = ($this->counts[$outcome] ?? 0) + 1;
        if ($outcome !== self::CHECKED && count($this->ids[$outcome] ?? []) < self::MAX_IDS) {
            $this->ids[$outcome][] = $id;
        }
    }

    public function get(string $outcome): int
    {
        return $this->counts[$outcome] ?? 0;
    }

    /**
     * Every count, by outcome.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * The first MAX_IDS row IDs counted under $outcome.
     *
     * @return list<int>
     */
    public function ids(string $outcome): array
    {
        return $this->ids[$outcome] ?? [];
    }

    /**
     * Record a committed page of job template $templateId, up to job $lastId;
     * true every $every pages, when a progress line is due.
     */
    public function pageDone(int $templateId, int $lastId, int $every): bool
    {
        $this->pages++;
        $this->position = ['template' => $templateId, 'job' => $lastId];

        return $this->pages % $every === 0;
    }

    /**
     * Where the pass got to: the template and job of the last committed
     * page; null before the first.
     *
     * @return array{template: int, job: int}|null
     */
    public function position(): ?array
    {
        return $this->position;
    }
}
