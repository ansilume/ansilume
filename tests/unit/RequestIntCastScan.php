<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * Finds the int conversions in PHP code that warn on PHP 8.5 for a float
 * beyond the int range, from the tokens of the code: for
 * RequestIntCastsTest.
 *
 * An int conversion is an (int) cast, intval() (also as the callback of
 * array_map(): 'intval' or intval(...)), settype() to 'int' or 'integer',
 * and each argument that an integer conversion of a printf format takes
 * (%d, %u, %c, %o, %x, %X, %b: intArgumentsOf()) in sprintf(), printf(),
 * fprintf(), vsprintf(), vprintf() and vfprintf(), where the format is a
 * quoted string, a class constant or a concatenation of them.
 *
 * - REQUEST_VALUE: the operand reads a request value as it is, through
 *   parentheses, ??, ?: and ? : (PhpExpressions::alternatives()), with any
 *   offsets, members and calls after it: a call of post(), getBodyParam(),
 *   getBodyParams() or getRawBody(), or the property bodyParams, of any
 *   object; the variables $body (the controllers' name for the decoded
 *   body) and $overrides (the launch's, which the controllers fill from the
 *   body); json_decode() of one of them; or a variable that its function
 *   assigned one of them to before, or that a foreach over one gave.
 * - TYPE_CHECK: a condition that guards the conversion (PhpGuards) is
 *   is_numeric(), is_scalar() or is_float() (is_double()) of the operand,
 *   all of which a float beyond the int range passes, and no other
 *   condition that guards it narrows the operand, as a range check or a
 *   validation would (narrows()). The operand is the checked value when
 *   one of their values that reads a variable is the same, so
 *   is_numeric($a['x'] ?? null) checks (int)$a['x']; an assignment to the
 *   checked variable between a condition and the conversion ends what the
 *   condition says of it.
 *
 * A function is a named function or method; a closure or an arrow function
 * is part of the one around it, and code outside every function is one
 * function of the file (a view).
 */
final class RequestIntCastScan
{
    public const REQUEST_VALUE = 'request value';
    public const TYPE_CHECK = 'type check';

    /** The directories of the application code, from the repository root. */
    public const DIRECTORIES = [
        'commands',
        'components',
        'config',
        'controllers',
        'helpers',
        'jobs',
        'models',
        'services',
        'views',
        'web',
    ];

    /** The console entry script, which has no .php extension. */
    public const ENTRY_SCRIPT = 'yii';

    /** Below DIRECTORIES, what is no source: the assets Yii publishes. */
    public const SKIPPED = ['web/assets'];

    /** Methods of a request whose value is the submitted body or a part of it, in lower case. */
    private const BODY_METHODS = ['post', 'getbodyparam', 'getbodyparams', 'getrawbody'];

    /** The property of a request that is the decoded body, in lower case. */
    private const BODY_PROPERTY = 'bodyparams';

    /** Variables that hold a decoded request body in every function, by their name. */
    private const BODY_VARIABLES = ['$body', '$overrides'];

    /** Functions whose value is what they decode from their first argument. */
    private const DECODERS = ['json_decode'];

    /** Checks that a float beyond the int range passes. */
    private const NO_INT_GUARDS = ['is_numeric', 'is_scalar', 'is_float', 'is_double'];

    /** Checks of a value that say nothing of the number it is: of its presence, or of a type other than a number. */
    private const NOT_NARROWING = [
        'is_null',
        'is_array',
        'is_bool',
        'is_object',
        'is_iterable',
        'is_countable',
        'is_callable',
        'is_resource',
        'array_key_exists',
        'key_exists',
        'in_array',
    ];

    /** Operators of a condition that say nothing of the range of a number: (in)equality and instanceof. */
    private const NOT_NARROWING_OPERATORS = [
        T_IS_IDENTICAL,
        T_IS_NOT_IDENTICAL,
        T_IS_EQUAL,
        T_IS_NOT_EQUAL,
        T_INSTANCEOF,
    ];

    /** The operators that assign a variable. */
    private const ASSIGNMENTS = [
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
    ];

    /**
     * The printf functions: the argument that is the format, and whether
     * the values are one list (vsprintf()) rather than the arguments after it.
     */
    private const FORMATS = [
        'sprintf' => [0, false],
        'printf' => [0, false],
        'fprintf' => [1, false],
        'vsprintf' => [0, true],
        'vprintf' => [0, true],
        'vfprintf' => [1, true],
    ];

    /** The printf conversions that convert their argument to an int. */
    private const INT_CONVERSIONS = 'bcdouxX';

    /** A printf conversion: %%, or an argument number, flags, width, precision and the conversion. */
    private const FORMAT_SPECIFICATION = '/%(?:%|(?<argument>[1-9][0-9]*\$)?(?:[-+ 0]|\'.)*'
        . '(?<width>\*(?:[1-9][0-9]*\$)?|[0-9]+)?(?:\.(?<precision>\*(?:[1-9][0-9]*\$)?|[0-9]*))?'
        . 'l?(?<conversion>[a-zA-Z]))/';

    /** Code without one of these spellings has no int conversion, and is not tokenized. */
    private const MENTIONS = '/\(\s*int(?:eger)?\s*\)|intval|settype|printf/i';

    /** @var \Closure(string, string): ?string the value of a class constant, by class and name */
    private readonly \Closure $classConstant;

    /** @var array<int, array<string, bool>> by function, whether each variable holds a request value */
    private array $tainted = [];

    /** @var array<int, list<array{scope: int, variable: string, tainted: bool}>> assignments by the token where they take effect */
    private array $pending = [];

    /**
     * @param (callable(string, string): ?string)|null $classConstant the
     *     string value of a class constant by class and name, for a format
     *     given as one; null when it is unknown or no string. By default the
     *     scan knows none.
     */
    public function __construct(?callable $classConstant = null)
    {
        $this->classConstant = $classConstant === null
            ? static fn (string $class, string $name): ?string => null
            : \Closure::fromCallable($classConstant);
    }

    /**
     * The application code under $root, relative to it and sorted: the
     * *.php files of DIRECTORIES outside SKIPPED, and ENTRY_SCRIPT.
     *
     * @return list<string>
     */
    public static function sourceFilesIn(string $root): array
    {
        $files = is_file($root . '/' . self::ENTRY_SCRIPT) ? [self::ENTRY_SCRIPT] : [];
        $accept = static function (\SplFileInfo $file) use ($root): bool {
            $path = substr($file->getPathname(), strlen($root) + 1);

            return $file->isDir() ? !in_array($path, self::SKIPPED, true) : $file->getExtension() === 'php';
        };
        foreach (self::DIRECTORIES as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            $tree = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS),
                $accept
            ));
            foreach ($tree as $file) {
                if ($file instanceof \SplFileInfo) {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The 0-based positions of the values that $format converts to an int,
     * in the order it takes them: those of its integer conversions. A `*`
     * width or precision takes a value too, which PHP requires to be an int
     * (a float is a ValueError, whole or not) and does not convert.
     *
     * @return list<int>
     */
    public static function intArgumentsOf(string $format): array
    {
        preg_match_all(self::FORMAT_SPECIFICATION, $format, $specifications, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $positions = [];
        $next = 0;
        foreach ($specifications as $specification) {
            if ($specification[0] === '%%') {
                continue;
            }
            $next += count(array_keys([$specification['width'], $specification['precision']], '*', true));
            $argument = $specification['argument'] === null ? $next++ : (int)$specification['argument'] - 1;
            if (str_contains(self::INT_CONVERSIONS, (string)$specification['conversion'])) {
                $positions[] = $argument;
            }
        }

        return $positions;
    }

    /**
     * Each int conversion in $code that reads a request value, and each one
     * that only a type check guards: its line, its kind and its code.
     *
     * @return list<array{line: int, kind: string, says: string}>
     */
    public function sitesIn(string $code): array
    {
        if (preg_match(self::MENTIONS, $code) === 0) {
            return [];
        }
        $expressions = new PhpExpressions(new PhpCode($code));
        $guards = new PhpGuards($expressions);
        $this->tainted = $this->pending = [];
        $sites = [];
        foreach ($expressions->code->tokens as $i => $token) {
            $this->remember($expressions, $i);
            foreach ($this->conversionsAt($expressions, $i) as [$start, $end, $says]) {
                if ($this->readsRequest($expressions, $start, $end)) {
                    $sites[] = ['line' => $token->line, 'kind' => self::REQUEST_VALUE, 'says' => $says];
                }
                if ($this->onlyTypeChecked($expressions, $guards->of($i), $i, $start, $end)) {
                    $sites[] = ['line' => $token->line, 'kind' => self::TYPE_CHECK, 'says' => $says];
                }
            }
        }

        return $sites;
    }

    /**
     * Takes note of the variables that the token at $i assigns: those that
     * take effect there, and an assignment or a foreach that starts there,
     * in effect after its right side or its head, which still read the
     * values before.
     */
    private function remember(PhpExpressions $expressions, int $i): void
    {
        foreach ($this->pending[$i] ?? [] as $assignment) {
            $this->tainted[$assignment['scope']][$assignment['variable']] = $assignment['tainted'];
        }
        unset($this->pending[$i]);
        $code = $expressions->code;
        $token = $code->tokens[$i];
        $scope = $code->scopes[$i];
        $next = $code->tokens[$i + 1] ?? null;
        $before = $code->tokens[$i - 1] ?? null;
        if ($token->is(T_FOREACH)) {
            $this->rememberForeach($expressions, $i + 1, $scope);
        }
        if (
            !$token->is(T_VARIABLE) || $next === null || !$next->is(['=', T_COALESCE_EQUAL])
            || ($before !== null && $before->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, '$']))
        ) {
            return;
        }
        $end = $expressions->expressionEnd($i + 2);
        $tainted = $this->readsRequest($expressions, $i + 2, $end)
            || ($next->is(T_COALESCE_EQUAL) && ($this->tainted[$scope][$token->text] ?? false));
        $this->pending[$end][] = ['scope' => $scope, 'variable' => $token->text, 'tainted' => $tainted];
    }

    /** Takes note of the variables that `foreach (… as …)` from its `(` at $open assigns, in effect after its head. */
    private function rememberForeach(PhpExpressions $expressions, int $open, int $scope): void
    {
        $close = $expressions->code->closing($open);
        $as = $close === null ? [] : $expressions->parts($open + 1, $close, T_AS);
        if ($close === null || count($as) !== 2) {
            return;
        }
        $tainted = $this->readsRequest($expressions, ...$as[0]);
        [$from, $to] = $as[1];
        $values = $expressions->parts($from, $to, T_DOUBLE_ARROW);
        for ($k = $values[count($values) - 1][0]; $k < $to; $k++) {
            if ($expressions->code->tokens[$k]->is(T_VARIABLE)) {
                $variable = $expressions->code->tokens[$k]->text;
                $this->pending[$close][] = ['scope' => $scope, 'variable' => $variable, 'tainted' => $tainted];
            }
        }
    }

    /**
     * The int conversions that start at the token at $i: the operand
     * each converts, and the code of the conversion.
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private function conversionsAt(PhpExpressions $expressions, int $i): array
    {
        $code = $expressions->code;
        if ($code->tokens[$i]->is(T_INT_CAST)) {
            $end = $expressions->operandEnd($i + 1);

            return [[$i + 1, $end, $code->text($i, $end)]];
        }
        $function = $code->globalCall($i);
        if ($function === null) {
            return [];
        }
        $arguments = $expressions->arguments($i + 1);
        $says = $code->text($i, (int)$code->closing($i + 1) + 1);
        if (isset(self::FORMATS[$function])) {
            return $this->formatConversions($expressions, $function, $arguments, $says);
        }
        $intType = isset($arguments[1]) && $this->namesIntType($expressions, $arguments[1]);
        $intval = isset($arguments[0]) && $this->isIntval($expressions, $arguments[0]);
        $operands = match ($function) {
            'intval' => array_slice($arguments, 0, $this->isCallable($expressions, $arguments) ? 0 : 1),
            'settype' => $intType ? [$arguments[0]] : [],
            'array_map' => $intval ? array_slice($arguments, 1) : [],
            default => [],
        };

        return array_map(static fn (array $operand): array => [$operand[0], $operand[1], $says], $operands);
    }

    /**
     * The values that the format of a printf call converts to an int: the
     * arguments its integer conversions take, for vsprintf() and the like
     * the elements of the list (listedValues()).
     *
     * @param list<array{0: int, 1: int}> $arguments
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private function formatConversions(
        PhpExpressions $expressions,
        string $function,
        array $arguments,
        string $says
    ): array {
        [$formatAt, $listed] = self::FORMATS[$function];
        $format = isset($arguments[$formatAt]) ? $this->formatOf($expressions, ...$arguments[$formatAt]) : null;
        $positions = $format === null ? [] : array_unique(self::intArgumentsOf($format));
        $values = array_slice($arguments, $formatAt + 1);
        if ($listed) {
            $values = isset($values[0]) ? $this->listedValues($expressions, $values[0], $positions) : [];
        }
        $conversions = [];
        foreach ($positions as $position) {
            if (isset($values[$position])) {
                $conversions[] = [$values[$position][0], $values[$position][1], $says];
            }
        }

        return array_values(array_unique($conversions, SORT_REGULAR));
    }

    /**
     * The values in the list of vsprintf() and the like: the value of each
     * element of a literal list ([…] or array(…)), and for any other list
     * the whole list at each of $positions, as each of them takes from it.
     *
     * @param array{0: int, 1: int} $list
     * @param list<int> $positions
     * @return array<int, array{0: int, 1: int}>
     */
    private function listedValues(PhpExpressions $expressions, array $list, array $positions): array
    {
        [$start, $end] = $list;
        $code = $expressions->code;
        $open = $code->tokens[$start]->is(T_ARRAY) ? $start + 1 : $start;
        $bracket = $code->tokens[$open]->text === ($open === $start ? '[' : '(');
        if (!$bracket || $code->closing($open) !== $end - 1) {
            return array_fill_keys($positions, $list);
        }
        $values = [];
        foreach ($expressions->arguments($open) as $element) {
            $parts = $expressions->parts($element[0], $element[1], T_DOUBLE_ARROW);
            $values[] = $parts === [] ? $element : $parts[count($parts) - 1];
        }

        return $values;
    }

    /**
     * The text of a format from $start to before $end: a quoted string, a
     * class constant, or a concatenation of them; null when it is anything
     * else, or a constant the scan does not know.
     */
    private function formatOf(PhpExpressions $expressions, int $start, int $end): ?string
    {
        $code = $expressions->code;
        $format = '';
        foreach ($expressions->parts($start, $end, '.') as [$from, $to]) {
            $literal = $to - $from === 1 ? PhpCode::literal($code->tokens[$from]) : null;
            $isConstant = $to - $from === 3
                && $code->tokens[$from + 1]->is(T_DOUBLE_COLON)
                && $code->tokens[$from + 2]->is(T_STRING);
            $constant = $isConstant
                ? ($this->classConstant)($code->className($code->tokens[$from]->text), $code->tokens[$from + 2]->text)
                : null;
            if ($literal === null && $constant === null) {
                return null;
            }
            $format .= $literal ?? $constant;
        }

        return $format;
    }

    /** Whether one of the values of the expression from $start to before $end reads a request value. */
    private function readsRequest(PhpExpressions $expressions, int $start, int $end): bool
    {
        foreach ($expressions->alternatives($start, $end) as [$from, $to]) {
            $head = $expressions->headEnd($from);
            if ($expressions->linksEnd($head) !== $to) {
                continue;
            }
            if ($this->headReadsRequest($expressions, $from, $head)) {
                return true;
            }
            foreach ($expressions->links($head, $to) as $link) {
                $name = strtolower($link['name']);
                $member = $link['operator'] === '->' || $link['operator'] === '?->';
                $body = $link['called'] ? in_array($name, self::BODY_METHODS, true) : $name === self::BODY_PROPERTY;
                if ($member && $body) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the head of a chain from $start to before $end is a request
     * value: a variable that holds one, json_decode() of one, or a
     * bracketed expression that reads one.
     */
    private function headReadsRequest(PhpExpressions $expressions, int $start, int $end): bool
    {
        $code = $expressions->code;
        $token = $code->tokens[$start];
        if ($token->is(T_VARIABLE)) {
            return in_array($token->text, self::BODY_VARIABLES, true)
                || ($this->tainted[$code->scopes[$start]][$token->text] ?? false);
        }
        if ($token->text === '(') {
            return $this->readsRequest($expressions, $start + 1, $end - 1);
        }
        if (!in_array($code->globalCall($start), self::DECODERS, true)) {
            return false;
        }
        $arguments = $expressions->arguments($start + 1);

        return isset($arguments[0]) && $this->readsRequest($expressions, ...$arguments[0]);
    }

    /**
     * Whether only a type check guards the conversion at $i of the operand
     * from $start to before $end: one of $guards is a type check of it
     * (NO_INT_GUARDS), and none narrows it otherwise (narrows()). A guard
     * that holds has each operand of its && hold; one that does not hold
     * has no operand of its || hold, so a type check there reads
     * !is_numeric(…). An || that holds and an && that does not say nothing
     * of each of their operands.
     *
     * @param list<array{holds: bool, start: int, end: int, since: int}> $guards
     */
    private function onlyTypeChecked(PhpExpressions $expressions, array $guards, int $i, int $start, int $end): bool
    {
        $values = $this->valuesOf($expressions, $start, $end);
        $checked = false;
        foreach ($guards as $guard) {
            if ($values === [] || $this->assignedBetween($expressions, $values, $guard['since'], $i)) {
                continue;
            }
            $holds = $guard['holds'];
            [$from, $to] = $this->unwrapped($expressions, $guard['start'], $guard['end']);
            foreach ($expressions->parts($from, $to, $holds ? T_BOOLEAN_AND : T_BOOLEAN_OR) as $part) {
                [$first, $last] = $this->unwrapped($expressions, ...$part);
                if ($expressions->first($holds ? T_BOOLEAN_OR : T_BOOLEAN_AND, $first, $last) !== null) {
                    continue;
                }
                if (array_intersect($this->typeCheckOf($expressions, $first, $last, $holds), $values) !== []) {
                    $checked = true;
                } elseif ($this->narrows($expressions, $first, $last, $values)) {
                    return false;
                }
            }
        }

        return $checked;
    }

    /**
     * Whether the condition from $start to before $end, or its negation,
     * narrows what one of $values can be, as a range check or a validation
     * does: it reads the value other than through an int conversion, and it
     * is no comparison for (in)equality, no instanceof, not the value itself
     * (its truthiness), and no isset(), empty() or NOT_NARROWING check.
     *
     * @param list<string> $values
     */
    private function narrows(PhpExpressions $expressions, int $start, int $end, array $values): bool
    {
        $code = $expressions->code;
        [$from, $to] = $this->unwrapped($expressions, $start, $end);
        while ($from < $to && $code->tokens[$from]->text === '!') {
            [$from, $to] = $this->unwrapped($expressions, $from + 1, $to);
        }
        if (
            $from >= $to
            || !$this->mentions($expressions, $from, $to, $values)
            || in_array($code->canonical($from, $to), $values, true)
            || $expressions->first(self::NOT_NARROWING_OPERATORS, $from, $to) !== null
        ) {
            return false;
        }
        $check = $code->tokens[$from]->is([T_ISSET, T_EMPTY])
            || in_array($code->globalCall($from), self::NOT_NARROWING, true);

        return !$check || $code->closing($from + 1) !== $to - 1;
    }

    /**
     * The values that the condition from $start to before $end checks with
     * one of NO_INT_GUARDS, as it is when $holds, negated with ! when not;
     * [] when it is no such check.
     *
     * @return list<string>
     */
    private function typeCheckOf(PhpExpressions $expressions, int $start, int $end, bool $holds): array
    {
        [$from, $to] = $this->unwrapped($expressions, $start, $end);
        if (!$holds) {
            if ($from >= $to || $expressions->code->tokens[$from]->text !== '!') {
                return [];
            }
            [$from, $to] = $this->unwrapped($expressions, $from + 1, $to);
        }
        $check = $from < $to ? $expressions->code->globalCall($from) : null;
        if (!in_array($check, self::NO_INT_GUARDS, true) || $expressions->code->closing($from + 1) !== $to - 1) {
            return [];
        }
        $arguments = $expressions->arguments($from + 1);

        return isset($arguments[0]) ? $this->valuesOf($expressions, ...$arguments[0]) : [];
    }

    /**
     * Whether the condition from $start to before $end reads one of
     * $values other than through an int conversion, which guards nothing.
     *
     * @param list<string> $values
     */
    private function mentions(PhpExpressions $expressions, int $start, int $end, array $values): bool
    {
        $code = $expressions->code;
        for ($k = $start; $k < $end; $k++) {
            if ($code->tokens[$k]->is(T_INT_CAST) || $code->globalCall($k) === 'intval') {
                return false;
            }
        }
        $text = ' ' . $code->canonical($start, $end) . ' ';
        foreach ($values as $value) {
            if (str_contains($text, ' ' . $value . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a variable of $values is assigned between the tokens $since
     * and $until.
     *
     * @param list<string> $values
     */
    private function assignedBetween(PhpExpressions $expressions, array $values, int $since, int $until): bool
    {
        $tokens = $expressions->code->tokens;
        for ($k = $since + 1; $k < $until; $k++) {
            $assigns = ($tokens[$k + 1] ?? null)?->is(self::ASSIGNMENTS) ?? false;
            if ($assigns && $tokens[$k]->is(T_VARIABLE) && in_array($tokens[$k]->text, $values, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The values of the expression from $start to before $end that read a
     * variable, each in its canonical form.
     *
     * @return list<string>
     */
    private function valuesOf(PhpExpressions $expressions, int $start, int $end): array
    {
        $values = [];
        foreach ($expressions->alternatives($start, $end) as [$from, $to]) {
            for ($k = $from; $k < $to; $k++) {
                if ($expressions->code->tokens[$k]->is(T_VARIABLE)) {
                    $values[] = $expressions->code->canonical($from, $to);
                    break;
                }
            }
        }

        return $values;
    }

    /**
     * The range from $start to before $end without the parentheses around
     * all of it.
     *
     * @return array{0: int, 1: int}
     */
    private function unwrapped(PhpExpressions $expressions, int $start, int $end): array
    {
        $code = $expressions->code;
        while ($start < $end && $code->tokens[$start]->text === '(' && $code->closing($start) === $end - 1) {
            $start++;
            $end--;
        }

        return [$start, $end];
    }

    /**
     * Whether the arguments of intval( are `...`: intval(...) makes a
     * callable of it and calls nothing.
     *
     * @param list<array{0: int, 1: int}> $arguments
     */
    private function isCallable(PhpExpressions $expressions, array $arguments): bool
    {
        return count($arguments) === 1 && $expressions->code->tokens[$arguments[0][0]]->is(T_ELLIPSIS);
    }

    /**
     * Whether the argument is 'int' or 'integer', as the type of settype().
     *
     * @param array{0: int, 1: int} $argument
     */
    private function namesIntType(PhpExpressions $expressions, array $argument): bool
    {
        $type = $argument[1] - $argument[0] === 1 ? PhpCode::literal($expressions->code->tokens[$argument[0]]) : null;

        return in_array(strtolower((string)$type), ['int', 'integer'], true);
    }

    /**
     * Whether the argument is intval as a callback: 'intval' or intval(...).
     *
     * @param array{0: int, 1: int} $argument
     */
    private function isIntval(PhpExpressions $expressions, array $argument): bool
    {
        [$start, $end] = $argument;
        $code = $expressions->code;
        if ($end - $start === 1) {
            return strtolower(ltrim((string)PhpCode::literal($code->tokens[$start]), '\\')) === 'intval';
        }

        return $code->globalCall($start) === 'intval'
            && $code->closing($start + 1) === $end - 1
            && $this->isCallable($expressions, $expressions->arguments($start + 1));
    }
}
