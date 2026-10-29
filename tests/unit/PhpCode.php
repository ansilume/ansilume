<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * The code of one PHP file as RequestIntCastScan reads it: its tokens
 * without whitespace, comments and the text of strings, the brackets that
 * close and open each other and the one around each token, the named
 * function or method each token belongs to, and the class a name stands for
 * in the file.
 *
 * Strings are no code: an interpolated string, a heredoc or nowdoc and a
 * command in backticks keep their opening token only, so nothing they hold
 * reads as an operand. A quoted string without interpolation is one token
 * anyway (T_CONSTANT_ENCAPSED_STRING), whose value literal() gives. Code
 * that does not parse throws a ParseError (TOKEN_PARSE).
 */
final class PhpCode
{
    /** Tokens that open a bracket; `#[` of an attribute is closed by `]`. */
    private const OPENING = ['(', '[', '{', T_ATTRIBUTE];

    private const CLOSING = [')', ']', '}'];

    /** Tokens that open a string, whose text up to the closing one is no code. */
    private const STRING_OPENING = ['"', '`', T_START_HEREDOC];

    private const STRING_CLOSING = ['"', '`', T_END_HEREDOC];

    /** Tokens that open code inside a string: "{$…}" and "${…}". */
    private const STRING_CODE = [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

    /** Tokens before a name that make it no call of a global function: a member, a declaration, a class. */
    private const BEFORE_NO_FUNCTION_CALL = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_FUNCTION,
        T_NEW,
        T_CONST,
        T_ATTRIBUTE,
    ];

    private const IN_STRING = 'string';
    private const IN_CODE = 'code';

    /** @var list<\PhpToken> */
    public readonly array $tokens;

    /**
     * For each token, the index of the `function` token of the named
     * function or method whose body holds it, -1 outside every one: a
     * closure and an arrow function belong to the function around them.
     *
     * @var list<int>
     */
    public readonly array $scopes;

    /** @var array<int, int> the index of the bracket that closes each opening one */
    private readonly array $closing;

    /** @var array<int, int> the index of the bracket that opens each closing one */
    private readonly array $opening;

    /** @var array<int, int> for each token, the index of the innermost bracket around it, -1 for none */
    private readonly array $parents;

    /** @var array<int, true> the `{` of each function, method and closure body */
    private array $functionBodies = [];

    /** @var array<string, string> the classes the file imports, by their alias in lower case */
    private array $imports = [];

    private string $namespace = '';

    private string $class = '';

    public function __construct(private readonly string $code)
    {
        $this->tokens = self::codeTokens($code);
        $brackets = $this->matchBrackets();
        $this->closing = $brackets['closing'];
        $this->opening = array_flip($brackets['closing']);
        $this->parents = $brackets['parents'];
        $this->scopes = $this->findScopes();
        $this->readNames();
    }

    /** The index of the bracket that closes the one at $open, null when no bracket opens there. */
    public function closing(int $open): ?int
    {
        return $this->closing[$open] ?? null;
    }

    /** The index of the bracket that opens the one at $close, null when no bracket closes there. */
    public function opening(int $close): ?int
    {
        return $this->opening[$close] ?? null;
    }

    /** The index of the innermost bracket around the token at $i, -1 for a token outside every one. */
    public function parent(int $i): int
    {
        return $this->parents[$i] ?? -1;
    }

    /** Whether the `{` at $open opens the body of a function, a method or a closure. */
    public function isFunctionBody(int $open): bool
    {
        return isset($this->functionBodies[$open]);
    }

    /** The source text of the tokens from $start to before $end, its whitespace as single spaces. */
    public function text(int $start, int $end): string
    {
        if ($start >= $end) {
            return '';
        }
        $first = $this->tokens[$start];
        $last = $this->tokens[$end - 1];
        $text = substr($this->code, $first->pos, $last->pos + strlen($last->text) - $first->pos);

        return (string)preg_replace('/\s+/', ' ', $text);
    }

    /**
     * The tokens from $start to before $end in one form for comparing, each
     * separated by a space: a quoted string by its value, so '5' and "5" are
     * the same.
     */
    public function canonical(int $start, int $end): string
    {
        $texts = [];
        for ($i = $start; $i < $end; $i++) {
            $literal = self::literal($this->tokens[$i]);
            $texts[] = $literal === null ? $this->tokens[$i]->text : var_export($literal, true);
        }

        return implode(' ', $texts);
    }

    /** The value of a quoted string without interpolation, null for every other token. */
    public static function literal(\PhpToken $token): ?string
    {
        if (!$token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }
        $quoted = ltrim($token->text, 'bB');
        $inner = substr($quoted, 1, -1);

        return $quoted[0] === "'" ? strtr($inner, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($inner);
    }

    /**
     * The global function that the token at $i calls, in lower case and
     * without a leading backslash: intval( and \intval(, but not a method,
     * a declaration, an attribute or `new` of that name; null when it calls
     * none.
     */
    public function globalCall(int $i): ?string
    {
        $token = $this->tokens[$i];
        $name = $token->is(T_NAME_FULLY_QUALIFIED) ? substr($token->text, 1) : $token->text;
        if (
            !$token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
            || str_contains($name, '\\')
            || ($this->tokens[$i + 1] ?? null)?->text !== '('
        ) {
            return null;
        }
        $before = $this->tokens[$i - 1] ?? null;
        if ($before !== null && $before->text === '&') {
            $before = $this->tokens[$i - 2] ?? null;
        }

        return $before !== null && $before->is(self::BEFORE_NO_FUNCTION_CALL) ? null : strtolower($name);
    }

    /**
     * The class that $name stands for in the file, fully qualified without
     * the leading backslash: self and static name the class the file
     * declares, an imported name its import, any other name one of the
     * file's namespace.
     */
    public function className(string $name): string
    {
        $lower = strtolower($name);
        if ($lower === 'self' || $lower === 'static') {
            return $this->class;
        }
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        if (str_starts_with($lower, 'namespace\\')) {
            return ltrim($this->namespace . substr($name, strlen('namespace')), '\\');
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($this->imports[$first])) {
            return $this->imports[$first] . substr($name, strlen($first));
        }

        return ltrim($this->namespace . '\\' . $name, '\\');
    }

    /**
     * The tokens of $code that are code: no whitespace and comments, and of
     * a string only the token that opens it.
     *
     * @return list<\PhpToken>
     */
    private static function codeTokens(string $code): array
    {
        $tokens = [];
        // IN_STRING for each string that is open, IN_CODE for each brace in code inside a string.
        $frames = [];
        foreach (\PhpToken::tokenize($code, TOKEN_PARSE) as $token) {
            if ($token->isIgnorable()) {
                continue;
            }
            if ($frames === []) {
                $tokens[] = $token;
            }
            self::follow($token, $frames);
        }

        return $tokens;
    }

    /**
     * Updates $frames, the strings and the braces inside them that are
     * open, for $token.
     *
     * @param list<string> $frames
     */
    private static function follow(\PhpToken $token, array &$frames): void
    {
        if (end($frames) === self::IN_STRING) {
            if ($token->is(self::STRING_CLOSING)) {
                array_pop($frames);
            } elseif ($token->is(self::STRING_CODE)) {
                $frames[] = self::IN_CODE;
            }

            return;
        }
        if ($token->is(self::STRING_OPENING)) {
            $frames[] = self::IN_STRING;
        } elseif ($frames !== [] && $token->text === '{') {
            $frames[] = self::IN_CODE;
        } elseif ($frames !== [] && $token->text === '}') {
            array_pop($frames);
        }
    }

    /** @return array{closing: array<int, int>, parents: array<int, int>} */
    private function matchBrackets(): array
    {
        $closing = [];
        $parents = [];
        $open = [];
        foreach ($this->tokens as $i => $token) {
            if ($token->is(self::CLOSING) && $open !== []) {
                $closing[array_pop($open)] = $i;
            }
            $parents[$i] = $open === [] ? -1 : $open[count($open) - 1];
            if ($token->is(self::OPENING)) {
                $open[] = $i;
            }
        }

        return ['closing' => $closing, 'parents' => $parents];
    }

    /** @return list<int> */
    private function findScopes(): array
    {
        $scopes = array_fill(0, count($this->tokens), -1);
        foreach ($this->tokens as $i => $token) {
            $body = $token->is(T_FUNCTION) ? $this->bodyOf($i) : null;
            if ($body === null) {
                continue;
            }
            $this->functionBodies[$body] = true;
            $name = $this->tokens[$i + 1]->text === '&' ? $i + 2 : $i + 1;
            if (!$this->tokens[$name]->is(T_STRING)) {
                continue;
            }
            for ($k = $body, $end = $this->closing[$body]; $k <= $end; $k++) {
                $scopes[$k] = $i;
            }
        }

        return $scopes;
    }

    /**
     * The index of the `{` that opens the body of the function, method or
     * closure whose `function` token is at $function; null for a method
     * without a body.
     */
    private function bodyOf(int $function): ?int
    {
        // `&`, a name, the parameters, `use (…)` of a closure, a return type,
        // whose (A&B)|null is skipped: then the body or `;`.
        for ($i = $function + 1; isset($this->tokens[$i]); $i++) {
            $text = $this->tokens[$i]->text;
            if ($text === '{' || $text === ';') {
                return $text === '{' && isset($this->closing[$i]) ? $i : null;
            }
            $i = $this->closing[$i] ?? $i;
        }

        return null;
    }

    /**
     * Reads the namespace, the imports (`use` outside every bracket, so no
     * trait and no closure) and the first class, interface, trait or enum
     * that the file declares.
     */
    private function readNames(): void
    {
        foreach ($this->tokens as $i => $token) {
            if ($this->parents[$i] !== -1) {
                continue;
            }
            if ($token->is(T_NAMESPACE)) {
                $name = $this->tokens[$i + 1] ?? null;
                $this->namespace = $name !== null && $name->is([T_STRING, T_NAME_QUALIFIED]) ? $name->text : '';
            } elseif ($token->is(T_USE)) {
                $this->readImports($i + 1);
            } elseif ($this->class === '' && $this->declaresClass($i)) {
                $this->class = ltrim($this->namespace . '\\' . $this->tokens[$i + 1]->text, '\\');
            }
        }
    }

    /** Reads the imports of `use A\B, C as D;` from the token after `use`. */
    private function readImports(int $i): void
    {
        while (($this->tokens[$i] ?? null)?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $name = ltrim($this->tokens[$i]->text, '\\');
            $parts = explode('\\', $name);
            $alias = end($parts);
            $i++;
            if (($this->tokens[$i] ?? null)?->is(T_AS)) {
                $alias = $this->tokens[$i + 1]->text ?? $alias;
                $i += 2;
            }
            $this->imports[strtolower($alias)] = $name;
            if (($this->tokens[$i] ?? null)?->text !== ',') {
                return;
            }
            $i++;
        }
    }

    /** Whether the token at $i declares a named class, interface, trait or enum. */
    private function declaresClass(int $i): bool
    {
        $before = $this->tokens[$i - 1] ?? null;

        return $this->tokens[$i]->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])
            && ($this->tokens[$i + 1] ?? null)?->is(T_STRING)
            && ($before === null || !$before->is([T_DOUBLE_COLON, T_NEW]));
    }
}
