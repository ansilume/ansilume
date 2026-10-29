<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * The conditions that guard a token of a PhpCode, for RequestIntCastScan:
 * each condition with whether the token runs when it holds or when it does
 * not. From the token outwards, up to the body of its function:
 *
 * - `C ? token : …` (holds) and `C ? … : token` or `C ?: token` (does not);
 * - `C && token` (holds) and `C || token` (does not);
 * - `if (C) token`, `while (C) token` and `elseif (C) token`, braced or not
 *   (holds), and the conditions of the braced `if` and `elseif` before an
 *   `elseif` or `else` (do not);
 * - `if (C) { … return; }` before the token in a block that holds it, whose
 *   body returns, throws, continues, breaks or exits (does not).
 *
 * Alternative syntax (`if (C):`) and `and`, `or` and `xor` are not read.
 */
final class PhpGuards
{
    /** Tokens after which an expression starts anew in the piece that holds a token. */
    private const EXPRESSION_STARTS = [
        '=',
        T_PLUS_EQUAL,
        T_MINUS_EQUAL,
        T_MUL_EQUAL,
        T_DIV_EQUAL,
        T_CONCAT_EQUAL,
        T_MOD_EQUAL,
        T_AND_EQUAL,
        T_OR_EQUAL,
        T_XOR_EQUAL,
        T_SL_EQUAL,
        T_SR_EQUAL,
        T_POW_EQUAL,
        T_COALESCE_EQUAL,
        T_DOUBLE_ARROW,
        T_RETURN,
        T_ECHO,
        T_PRINT,
        T_YIELD,
        T_YIELD_FROM,
        T_THROW,
        T_OPEN_TAG_WITH_ECHO,
    ];

    /** Tokens that end a statement. */
    private const STATEMENT_ENDS = [';', T_CLOSE_TAG, T_INLINE_HTML];

    /** Tokens that leave a block: a body with one of them ends what follows it. */
    private const EXITS = [T_RETURN, T_THROW, T_CONTINUE, T_BREAK, T_EXIT];

    /** The statements whose condition guards their body. */
    private const CONDITIONAL = [T_IF, T_ELSEIF, T_WHILE];

    private readonly PhpCode $code;

    public function __construct(private readonly PhpExpressions $expressions)
    {
        $this->code = $expressions->code;
    }

    /**
     * The conditions that guard the token at $at: each from `start` to
     * before `end`, whether the token runs when it holds, and the token
     * after which it holds or fails (`since`), so that an assignment between
     * there and the token changes what it says of the assigned variable.
     *
     * @return list<array{holds: bool, start: int, end: int, since: int}>
     */
    public function of(int $at): array
    {
        $guards = [];
        $position = $at;
        while (true) {
            $open = $this->code->parent($position);
            if ($open >= 0 && $this->code->tokens[$open]->text !== '{') {
                // ( … ) or [ … ]: the argument or element that holds the position.
                $close = (int)$this->code->closing($open);
                foreach ($this->expressions->parts($open + 1, $close, ',') as [$from, $to]) {
                    if ($position >= $from && $position < $to) {
                        $start = $this->expressionStart($from, $position);
                        $guards = [...$guards, ...$this->expressionGuards($start, $to, $position)];
                    }
                }
                $position = $open;
                continue;
            }
            $from = $open + 1;
            [$start, $end] = $this->statementOf($from, $position);
            $guards = [
                ...$guards,
                ...$this->statementGuards($start, $end, $position),
                ...$this->earlyExits($from, $start),
            ];
            if ($open < 0 || $this->code->isFunctionBody($open)) {
                return $guards;
            }
            $position = $open;
        }
    }

    /**
     * The statement from $from (the start of its block) that holds the
     * token at $position: from after the last `;` or block before it, up to
     * the next end.
     *
     * @return array{0: int, 1: int}
     */
    private function statementOf(int $from, int $position): array
    {
        $start = $from;
        for ($i = $from; $i < $position; $i++) {
            $token = $this->code->tokens[$i];
            $closing = $this->code->closing($i);
            if ($closing !== null) {
                $i = $closing;
                $start = $token->text === '{' ? $closing + 1 : $start;
            } elseif ($token->is(self::STATEMENT_ENDS)) {
                $start = $i + 1;
            }
        }
        $end = $position;
        while (
            ($token = $this->code->tokens[$end] ?? null) !== null
            && !$token->is(self::STATEMENT_ENDS)
            && $token->text !== '}'
        ) {
            $end = ($this->code->closing($end) ?? $end) + 1;
        }

        return [$start, $end];
    }

    /**
     * The conditions that guard the token at $position in the statement
     * from $start to before $end: the head of an if, elseif, else or while
     * before it, and the expression that holds it.
     *
     * @return list<array{holds: bool, start: int, end: int, since: int}>
     */
    private function statementGuards(int $start, int $end, int $position): array
    {
        $guards = [];
        $head = $start;
        if ($this->code->tokens[$start]->is(T_ELSE)) {
            $guards = $this->earlierConditions($start);
            $head++;
        }
        $keyword = $this->code->tokens[$head] ?? null;
        $close = $keyword !== null && $keyword->is(self::CONDITIONAL) ? $this->code->closing($head + 1) : null;
        if ($keyword !== null && $close !== null) {
            if ($position < $close) {
                return $guards;
            }
            $guards[] = ['holds' => true, 'start' => $head + 2, 'end' => $close, 'since' => $close];
            if ($keyword->is(T_ELSEIF)) {
                $guards = [...$guards, ...$this->earlierConditions($head)];
            }
            $head = $close + 1;
        }
        if ($this->code->tokens[$position]->text === '{') {
            return $guards;
        }

        return [...$guards, ...$this->expressionGuards($this->expressionStart($head, $position), $end, $position)];
    }

    /**
     * The conditions of the braced `if` and `elseif`s before the `else` or
     * `elseif` at $keyword, none of which held: also across `else if`.
     *
     * @return list<array{holds: bool, start: int, end: int, since: int}>
     */
    private function earlierConditions(int $keyword): array
    {
        $guards = [];
        $i = $keyword - 1;
        while (($this->code->tokens[$i] ?? null)?->text === '}') {
            $body = (int)$this->code->opening($i);
            $paren = ($this->code->tokens[$body - 1] ?? null)?->text === ')' ? $this->code->opening($body - 1) : null;
            $before = $paren === null ? null : ($this->code->tokens[$paren - 1] ?? null);
            if ($paren === null || $before === null || !$before->is([T_IF, T_ELSEIF])) {
                break;
            }
            $guards[] = ['holds' => false, 'start' => $paren + 1, 'end' => $body - 1, 'since' => $keyword];
            $else = $before->is(T_IF) && ($this->code->tokens[$paren - 2] ?? null)?->is(T_ELSE);
            if ($before->is(T_IF) && !$else) {
                break;
            }
            $i = $else ? $paren - 3 : $paren - 2;
        }

        return $guards;
    }

    /**
     * The conditions that guard the token at $position in the expression
     * from $from to before $end: the condition of each `? :` and `?:` around
     * it, and the operands of `&&` and `||` before it.
     *
     * @return list<array{holds: bool, start: int, end: int, since: int}>
     */
    private function expressionGuards(int $from, int $end, int $position): array
    {
        $question = $this->expressions->first('?', $from, $end);
        $colon = $question === null ? null : $this->expressions->first(':', $question + 1, $end);
        if ($question !== null && $colon !== null) {
            if ($position < $question) {
                return $this->expressionGuards($from, $question, $position);
            }
            // Of `C ?: x` only x is after the `?`, where C does not hold.
            $then = $position < $colon;
            $condition = ['holds' => $then, 'start' => $from, 'end' => $question, 'since' => $question];

            return $then
                ? [$condition, ...$this->expressionGuards($question + 1, $colon, $position)]
                : [$condition, ...$this->expressionGuards($colon + 1, $end, $position)];
        }
        foreach ([T_BOOLEAN_OR => false, T_BOOLEAN_AND => true] as $operator => $holds) {
            $operands = $this->expressions->parts($from, $end, $operator);
            if (count($operands) < 2) {
                continue;
            }
            $guards = [];
            foreach ($operands as [$start, $stop]) {
                if ($position < $stop) {
                    return [...$guards, ...$this->expressionGuards($start, $stop, $position)];
                }
                $guards[] = ['holds' => $holds, 'start' => $start, 'end' => $stop, 'since' => $stop];
            }
        }

        return [];
    }

    /**
     * The conditions of the `if` statements before $statement in the block
     * from $from whose body leaves it: none of them held where the
     * statement runs, from the end of that body on.
     *
     * @return list<array{holds: bool, start: int, end: int, since: int}>
     */
    private function earlyExits(int $from, int $statement): array
    {
        $guards = [];
        for ($i = $from; $i < $statement; $i++) {
            $token = $this->code->tokens[$i];
            $close = $token->is([T_IF, T_ELSEIF]) ? $this->code->closing($i + 1) : null;
            if ($close === null) {
                $i = $this->code->closing($i) ?? $i;
                continue;
            }
            $bodyEnd = ($this->code->tokens[$close + 1] ?? null)?->text === '{'
                ? (int)$this->code->closing($close + 1)
                : $this->expressions->first(';', $close + 1, $statement) ?? $statement;
            if ($this->leaves($close + 1, $bodyEnd)) {
                $guards[] = ['holds' => false, 'start' => $i + 2, 'end' => $close, 'since' => $bodyEnd];
            }
            $i = $bodyEnd;
        }

        return $guards;
    }

    /** Whether the body from $start to $end has a statement that leaves the block (EXITS). */
    private function leaves(int $start, int $end): bool
    {
        $from = $this->code->tokens[$start]->text === '{' ? $start + 1 : $start;
        for ($i = $from; $i < $end; $i++) {
            if ($this->code->tokens[$i]->is(self::EXITS)) {
                return true;
            }
            $i = $this->code->closing($i) ?? $i;
        }

        return false;
    }

    /**
     * Where the expression that holds the token at $position starts in the
     * piece from $from: after the label of a named argument, and after the
     * last assignment, `=>`, return, echo and the like before it.
     */
    private function expressionStart(int $from, int $position): int
    {
        $label = $this->code->tokens[$from];
        if ($label->is(T_STRING) && ($this->code->tokens[$from + 1] ?? null)?->text === ':' && $from + 1 < $position) {
            $from += 2;
        }
        $start = $from;
        for ($i = $from; $i < $position; $i++) {
            if ($this->code->tokens[$i]->is(self::EXPRESSION_STARTS)) {
                $start = $i + 1;
            }
            $i = $this->code->closing($i) ?? $i;
        }

        return $start;
    }
}
