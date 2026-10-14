<?php

declare(strict_types=1);

namespace app\components;

/**
 * Where a workflow step goes when it is added or moved: at the start, at the
 * end, after another step of the workflow (by its ID), at a position (1-based,
 * from the API), or one place up or down (a move only).
 *
 * It names the place, not the outcome: placement (WorkflowStepReorderService)
 * resolves it against the run order when the change is applied, under the
 * workflow's row lock, and refuses a step ID that is not part of the workflow
 * or a position out of range.
 *
 * The readers turn a request into a position: fromWeb() the Position field of
 * the step form, fromApi() the body of an API add or update, fromApiMove() the
 * body of an API move, and fromDirection() a move's direction. Each returns
 * the position, null to keep the step where it is (an edit or update that
 * names none), or why the value is refused: the text the web shows and the
 * API answers 422 with. A whole number is an int or a string of digits;
 * booleans, floats, signs, spaces and 0 are refused. In an API body, a key
 * sent as null counts as not sent.
 */
final class WorkflowStepPosition
{
    /** The Position field of the web form holds none of the values it offers. */
    public const FORM_INVALID = 'Choose a position: at the start, at the end, or after a step of this workflow.';

    /** An API add or update sends both position and after_step_id. */
    public const BOTH_GIVEN = 'Send either position or after_step_id, not both.';

    /** An API move sends none, or more than one, of position, after_step_id and direction. */
    public const MOVE_NEEDS_ONE = 'Send exactly one of position, after_step_id and direction.';

    /** An API position that is neither "start", "end" nor a whole number from 1. */
    public const POSITION_INVALID = 'position must be "start", "end" or a whole number from 1.';

    /** An API after_step_id that is not a whole number from 1. */
    public const AFTER_INVALID = 'after_step_id must be the ID of a step of this workflow.';

    /** A move direction other than "up" and "down", from the web or the API. */
    public const DIRECTION_INVALID = 'direction must be "up" or "down".';

    /** The kinds of position, as kind() returns them. */
    public const KIND_START = 'start';
    public const KIND_END = 'end';
    public const KIND_AFTER = 'after';
    public const KIND_AT = 'at';
    public const KIND_UP = 'up';
    public const KIND_DOWN = 'down';

    /** The web value that keeps the step where it is (the edit form only). */
    public const WEB_KEEP = 'keep';

    /** The web value of "after a step" is this prefix and the step's ID, such as "after:12". */
    public const WEB_AFTER_PREFIX = 'after:';

    /**
     * @param 'start'|'end'|'after'|'at'|'up'|'down' $kind
     * @param int|null $stepId the step to place it after (kind after)
     * @param int|null $position the 1-based position (kind at)
     */
    private function __construct(
        private readonly string $kind,
        private readonly ?int $stepId = null,
        private readonly ?int $position = null,
    ) {
    }

    /**
     * First in the run order: runs start with the step.
     */
    public static function start(): self
    {
        return new self(self::KIND_START);
    }

    /**
     * Last in the run order.
     */
    public static function end(): self
    {
        return new self(self::KIND_END);
    }

    /**
     * Right after the step with ID $stepId.
     */
    public static function after(int $stepId): self
    {
        return new self(self::KIND_AFTER, $stepId);
    }

    /**
     * At the 1-based $position of the run order.
     */
    public static function at(int $position): self
    {
        return new self(self::KIND_AT, null, $position);
    }

    /**
     * One place earlier (a move only).
     */
    public static function up(): self
    {
        return new self(self::KIND_UP);
    }

    /**
     * One place later (a move only).
     */
    public static function down(): self
    {
        return new self(self::KIND_DOWN);
    }

    /**
     * @return 'start'|'end'|'after'|'at'|'up'|'down'
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * The ID of the step to place it after; null unless kind() is after.
     */
    public function stepId(): ?int
    {
        return $this->stepId;
    }

    /**
     * The 1-based position; null unless kind() is at.
     */
    public function position(): ?int
    {
        return $this->position;
    }

    /**
     * The Position field of the web form: "end", "start", "after:<step ID>",
     * and "keep" on the edit form. Empty or missing is the end when a step is
     * added, and keep (null) when it is edited.
     *
     * @return self|string|null the position; null to keep the step where it is
     *     (edit only); FORM_INVALID for any other value
     */
    public static function fromWeb(mixed $value, bool $edit): self|string|null
    {
        if ($value === null || $value === '' || ($edit && $value === self::WEB_KEEP)) {
            return $edit ? null : self::end();
        }

        return match ($value) {
            self::KIND_START => self::start(),
            self::KIND_END => self::end(),
            default => self::afterFromWeb($value),
        };
    }

    /**
     * The position of an API add ($create) or update body: position
     * ("start", "end" or a whole number from 1) or after_step_id (a whole
     * number from 1), not both. Neither is the end when a step is added, and
     * keep (null) when it is updated.
     *
     * @param array<array-key, mixed> $body
     * @return self|string|null the position; null to keep the step where it is
     *     (update only); BOTH_GIVEN, POSITION_INVALID or AFTER_INVALID
     */
    public static function fromApi(array $body, bool $create): self|string|null
    {
        $sent = self::sentKeys($body, ['position', 'after_step_id']);
        if (count($sent) > 1) {
            return self::BOTH_GIVEN;
        }
        if ($sent === []) {
            return $create ? self::end() : null;
        }

        return self::readSent($body);
    }

    /**
     * The position of an API move body: exactly one of position (as in
     * fromApi()), after_step_id and direction ("up" or "down").
     *
     * @param array<array-key, mixed> $body
     * @return self|string the position; MOVE_NEEDS_ONE, POSITION_INVALID,
     *     AFTER_INVALID or DIRECTION_INVALID
     */
    public static function fromApiMove(array $body): self|string
    {
        $sent = self::sentKeys($body, ['position', 'after_step_id', 'direction']);
        if (count($sent) !== 1) {
            return self::MOVE_NEEDS_ONE;
        }

        return $sent === ['direction'] ? self::fromDirection($body['direction']) : self::readSent($body);
    }

    /**
     * A move's direction, from the web or the API: "up" or "down".
     *
     * @return self|string the position; DIRECTION_INVALID for any other value
     */
    public static function fromDirection(mixed $value): self|string
    {
        return match ($value) {
            self::KIND_UP => self::up(),
            self::KIND_DOWN => self::down(),
            default => self::DIRECTION_INVALID,
        };
    }

    /**
     * "after:<step ID>" from the web form, or FORM_INVALID.
     */
    private static function afterFromWeb(mixed $value): self|string
    {
        $stepId = is_string($value) && str_starts_with($value, self::WEB_AFTER_PREFIX)
            ? self::wholeNumber(substr($value, strlen(self::WEB_AFTER_PREFIX)))
            : null;

        return $stepId === null ? self::FORM_INVALID : self::after($stepId);
    }

    /**
     * The keys among $keys that $body sends a value for, in the order of
     * $keys; a key sent as null counts as not sent.
     *
     * @param array<array-key, mixed> $body
     * @param list<string> $keys
     * @return list<string>
     */
    private static function sentKeys(array $body, array $keys): array
    {
        return array_values(array_filter($keys, static fn (string $key): bool => isset($body[$key])));
    }

    /**
     * The position that $body sends as position or as after_step_id (one of
     * them), or why it is refused.
     *
     * @param array<array-key, mixed> $body
     */
    private static function readSent(array $body): self|string
    {
        if (isset($body['position'])) {
            return self::readPosition($body['position']);
        }
        $stepId = self::wholeNumber($body['after_step_id'] ?? null);

        return $stepId === null ? self::AFTER_INVALID : self::after($stepId);
    }

    /**
     * An API position: "start", "end" or a whole number from 1.
     */
    private static function readPosition(mixed $value): self|string
    {
        if ($value === self::KIND_START || $value === self::KIND_END) {
            return $value === self::KIND_START ? self::start() : self::end();
        }
        $position = self::wholeNumber($value);

        return $position === null ? self::POSITION_INVALID : self::at($position);
    }

    /**
     * A whole number from 1: an int, or a string of digits whose value fits
     * into an int (leading zeros are read away); null for anything else, such
     * as a boolean, a float, a sign, a space or 0.
     */
    private static function wholeNumber(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $digits = ltrim($value, '0');
            $value = (string)(int)$digits === $digits ? (int)$digits : null;
        }

        return is_int($value) && $value >= 1 ? $value : null;
    }
}
