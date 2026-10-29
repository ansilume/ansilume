<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * Where the expressions of a PhpCode begin and end, as far as
 * RequestIntCastScan needs to know: the operand of a cast, the right side
 * of an assignment, the arguments of a call, the values an expression gives
 * as they are (alternatives()), and the head and the links of a chain such
 * as \Yii::$app->request->post('id')['x'].
 *
 * Every range is two token indexes, from its first token to before its end.
 */
final class PhpExpressions
{
    /** Tokens before an operand that belong to it: casts and the other unary operators. */
    private const PREFIX_OPERATORS = [
        T_INT_CAST,
        T_DOUBLE_CAST,
        T_STRING_CAST,
        T_ARRAY_CAST,
        T_OBJECT_CAST,
        T_BOOL_CAST,
        T_INC,
        T_DEC,
        T_CLONE,
        T_PRINT,
        '-',
        '+',
        '!',
        '~',
        '@',
    ];

    /** Tokens that name a function, a constant or a class, and the language constructs called like functions. */
    private const NAMES = [
        T_STRING,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_QUALIFIED,
        T_NAME_RELATIVE,
        T_STATIC,
        T_ARRAY,
        T_ISSET,
        T_EMPTY,
        T_LIST,
        T_EXIT,
        T_EVAL,
    ];

    private const MEMBER_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    /** Tokens that end an expression where no bracket is open: the right side of an assignment ends there. */
    private const EXPRESSION_ENDS = [
        ';',
        ',',
        ')',
        ']',
        '}',
        T_CLOSE_TAG,
        T_INLINE_HTML,
        T_AS,
        T_DOUBLE_ARROW,
        T_LOGICAL_AND,
        T_LOGICAL_OR,
        T_LOGICAL_XOR,
    ];

    public function __construct(public readonly PhpCode $code)
    {
    }

    /**
     * The end of the operand from $start, as a cast reads it: the unary
     * operators before it, its head and the links after it, and `** x`,
     * which binds tighter than a cast.
     */
    public function operandEnd(int $start): int
    {
        $token = $this->code->tokens[$start] ?? null;
        if ($token !== null && $token->is(self::PREFIX_OPERATORS)) {
            return $this->operandEnd($start + 1);
        }
        $end = $this->linksEnd($this->headEnd($start));
        if (($this->code->tokens[$end] ?? null)?->is(T_POW)) {
            return $this->operandEnd($end + 1);
        }

        return $end;
    }

    /**
     * The end of the head of a chain from $start: a variable, a name (with
     * its arguments when it is called), a bracketed group, `new` with its
     * arguments, a closure, `match`, or any single token such as a literal.
     */
    public function headEnd(int $start): int
    {
        $token = $this->code->tokens[$start] ?? null;
        $closing = $this->code->closing($start);
        if ($token === null) {
            return $start;
        }
        if ($closing !== null) {
            return $closing + 1;
        }
        if ($token->is(T_NEW)) {
            return $this->newEnd($start + 1);
        }
        if ($token->is([T_FUNCTION, T_FN]) || ($token->is(T_STATIC) && $this->isClosureAt($start + 1))) {
            return $this->closureEnd($token->is(T_STATIC) ? $start + 1 : $start);
        }
        if ($token->is(T_MATCH)) {
            return $this->groupsEnd($start + 1, 2);
        }
        if ($token->text === '$') {
            return $this->headEnd($start + 1);
        }
        $call = $token->is(self::NAMES) ? $this->code->closing($start + 1) : null;
        if ($call !== null && $this->code->tokens[$start + 1]->text === '(') {
            return $call + 1;
        }

        return $start + 1;
    }

    /**
     * The end of the links of a chain from $end, the end of its head: each
     * `->name`, `?->name` and `::name` (called or not), offset `[…]`, call
     * `(…)` and `++` or `--` after it.
     */
    public function linksEnd(int $end): int
    {
        while (($token = $this->code->tokens[$end] ?? null) !== null) {
            if ($token->is(self::MEMBER_OPERATORS)) {
                $end = $this->memberEnd($end + 1);
            } elseif ($token->text === '[' || $token->text === '(') {
                $end = (int)$this->code->closing($end) + 1;
            } elseif ($token->is([T_INC, T_DEC])) {
                $end++;
            } else {
                break;
            }
        }

        return $end;
    }

    /**
     * The links from $start (the end of a head) to before $end: a member
     * by its operator, its name ('' when it is computed) and whether it is
     * called; an offset as '[', a call as '(', and `++` or `--`.
     *
     * @return list<array{operator: string, name: string, called: bool}>
     */
    public function links(int $start, int $end): array
    {
        $links = [];
        for ($i = $start; $i < $end;) {
            $token = $this->code->tokens[$i];
            if (!$token->is(self::MEMBER_OPERATORS)) {
                $links[] = ['operator' => $token->text, 'name' => '', 'called' => false];
                $i = ($this->code->closing($i) ?? $i) + 1;
                continue;
            }
            $member = $this->code->tokens[$i + 1] ?? null;
            $next = $this->memberEnd($i + 1);
            $called = ($this->code->tokens[$next] ?? null)?->text === '(';
            $named = $member !== null && $member->is([T_STRING, T_CLASS]);
            $links[] = ['operator' => $token->text, 'name' => $named ? $member->text : '', 'called' => $called];
            $i = $called ? (int)$this->code->closing($next) + 1 : $next;
        }

        return $links;
    }

    /**
     * The end of the expression from $start: the first token where no
     * bracket of it is open that ends one (EXPRESSION_ENDS), such as the `;`
     * after the right side of an assignment.
     */
    public function expressionEnd(int $start): int
    {
        $i = $start;
        while (($token = $this->code->tokens[$i] ?? null) !== null && !$token->is(self::EXPRESSION_ENDS)) {
            $i = ($this->code->closing($i) ?? $i) + 1;
        }

        return $i;
    }

    /**
     * The arguments of the call whose `(` is at $open, or the elements of
     * the list whose `[` is: each without the label of a named argument.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function arguments(int $open): array
    {
        $close = $this->code->closing($open) ?? $open;
        $arguments = [];
        foreach ($this->parts($open + 1, $close, ',') as [$start, $end]) {
            $label = $this->code->tokens[$start];
            $colon = $this->code->tokens[$start + 1] ?? null;
            $named = $end - $start > 2 && $label->is(T_STRING) && $colon !== null && $colon->text === ':';
            $arguments[] = [$named ? $start + 2 : $start, $end];
        }

        return $arguments;
    }

    /**
     * The values the expression from $start to before $end gives as they
     * are: through parentheses, both sides of `??`, both values of `? :`
     * and `?:`, an (array) or (object) cast and a reference `&`. Any other
     * expression gives itself.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function alternatives(int $start, int $end): array
    {
        if ($start < $end && $this->code->tokens[$start]->text === '&') {
            $start++;
        }
        if ($start >= $end) {
            return [];
        }
        $first = $this->code->tokens[$start];
        if ($first->text === '(' && $this->code->closing($start) === $end - 1) {
            return $this->alternatives($start + 1, $end - 1);
        }
        $question = $this->first('?', $start, $end);
        $colon = $question === null ? null : $this->first(':', $question + 1, $end);
        if ($question !== null && $colon !== null) {
            $values = $colon === $question + 1 ? [$start, $question] : [$question + 1, $colon];

            return [...$this->alternatives(...$values), ...$this->alternatives($colon + 1, $end)];
        }
        $coalesce = $this->first(T_COALESCE, $start, $end);
        if ($coalesce !== null) {
            return [...$this->alternatives($start, $coalesce), ...$this->alternatives($coalesce + 1, $end)];
        }
        if ($first->is([T_ARRAY_CAST, T_OBJECT_CAST]) && $this->operandEnd($start + 1) === $end) {
            return $this->alternatives($start + 1, $end);
        }

        return [[$start, $end]];
    }

    /**
     * The parts of the range from $start to before $end between the
     * $separator tokens where no bracket of it is open.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function parts(int $start, int $end, int|string $separator): array
    {
        $parts = [];
        $from = $start;
        for ($i = $start; $i < $end; $i++) {
            if ($this->code->tokens[$i]->is($separator)) {
                $parts[] = [$from, $i];
                $from = $i + 1;
            }
            $i = $this->code->closing($i) ?? $i;
        }
        if ($from < $end) {
            $parts[] = [$from, $end];
        }

        return $parts;
    }

    /**
     * The first $kind token (an ID, a text, or a list of them) from $start
     * to before $end where no bracket of the range is open.
     *
     * @param int|string|list<int|string> $kind
     */
    public function first(int|string|array $kind, int $start, int $end): ?int
    {
        for ($i = $start; $i < $end; $i++) {
            if ($this->code->tokens[$i]->is($kind)) {
                return $i;
            }
            $i = $this->code->closing($i) ?? $i;
        }

        return null;
    }

    /** The end of a member name after `->`, `?->` or `::`: one token, or `{…}` of a computed one. */
    private function memberEnd(int $member): int
    {
        $closing = ($this->code->tokens[$member] ?? null)?->text === '{' ? $this->code->closing($member) : null;

        return ($closing ?? $member) + 1;
    }

    /** The end of `new` from the token after it: an anonymous class with its body, or a class with its arguments. */
    private function newEnd(int $class): int
    {
        $token = $this->code->tokens[$class] ?? null;
        if ($token !== null && $token->is(T_CLASS)) {
            $body = $class;
            while (($this->code->tokens[$body] ?? null) !== null && $this->code->tokens[$body]->text !== '{') {
                $body = ($this->code->closing($body) ?? $body) + 1;
            }

            return ($this->code->closing($body) ?? $body) + 1;
        }
        $end = ($this->code->closing($class) ?? $class) + 1;
        while (($this->code->tokens[$end] ?? null)?->is(self::MEMBER_OPERATORS)) {
            $end = $this->memberEnd($end + 1);
        }

        return ($this->code->tokens[$end] ?? null)?->text === '(' ? (int)$this->code->closing($end) + 1 : $end;
    }

    /** Whether a closure starts at $i: `function (` or `fn (`. */
    private function isClosureAt(int $i): bool
    {
        return ($this->code->tokens[$i] ?? null)?->is([T_FUNCTION, T_FN]) ?? false;
    }

    /** The end of the closure or arrow function whose `function` or `fn` is at $start. */
    private function closureEnd(int $start): int
    {
        if ($this->code->tokens[$start]->is(T_FUNCTION)) {
            // The parameters, `use (…)`, a return type, then the body.
            $body = $start + 1;
            while (($this->code->tokens[$body] ?? null) !== null && $this->code->tokens[$body]->text !== '{') {
                $body = ($this->code->closing($body) ?? $body) + 1;
            }

            return ($this->code->closing($body) ?? $body) + 1;
        }
        $arrow = $this->first(T_DOUBLE_ARROW, $start, count($this->code->tokens));

        return $arrow === null ? $start + 1 : $this->expressionEnd($arrow + 1);
    }

    /** The end of $count bracketed groups from $start, such as `(…) {…}` of `match`. */
    private function groupsEnd(int $start, int $count): int
    {
        $end = $start;
        for ($group = 0; $group < $count; $group++) {
            $end = ($this->code->closing($end) ?? $end) + 1;
        }

        return $end;
    }
}
