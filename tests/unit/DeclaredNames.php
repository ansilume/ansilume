<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * Which of some method and class constant names PHP code declares, from its
 * tokens: for PhpDeprecations::userlandDeclarations().
 *
 * A method name is declared by `function name(` (also `function &name(`, in
 * a class, an interface, a trait or as a function) and by a doc comment that
 * documents a magic method of that name (`@method mixed name(...)`); a class
 * constant name by `const` (typed too, and each of a list) and by an enum
 * case. Names in strings and other comments are no declarations.
 */
final class DeclaredNames
{
    /** @var array<string, true> lower-case method names */
    private readonly array $methods;

    /** @var array<string, true> class constant names */
    private readonly array $constants;

    /** Matches each name as a word, in any case: code without one declares none. */
    private readonly string $mentions;

    /** Matches a magic method of one of the names in a doc comment. */
    private readonly string $documented;

    /**
     * @param list<string> $methods lower-case method names
     * @param list<string> $constants class constant names
     */
    public function __construct(array $methods, array $constants)
    {
        $this->methods = array_fill_keys($methods, true);
        $this->constants = array_fill_keys($constants, true);
        $this->mentions = '/\b(?:' . self::alternation(array_merge($methods, $constants)) . ')\b/i';
        $this->documented = '/@method\b[^\n(]*?\b(' . self::alternation($methods) . ')\s*\(/i';
    }

    /**
     * The names that $code declares.
     *
     * @return array{methods: list<string>, classConstants: list<string>}
     */
    public function in(string $code): array
    {
        $declared = ['methods' => [], 'classConstants' => []];
        if (preg_match($this->mentions, $code) === 0) {
            return $declared;
        }
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT])
        ));
        foreach ($tokens as $i => $token) {
            if ($token->is([T_DOC_COMMENT, T_FUNCTION])) {
                array_push($declared['methods'], ...$this->methodsAt($tokens, $i));
            } elseif ($token->is([T_CASE, T_CONST])) {
                array_push($declared['classConstants'], ...$this->constantsAt($tokens, $i));
            }
        }

        return [
            'methods' => array_values(array_unique($declared['methods'])),
            'classConstants' => array_values(array_unique($declared['classConstants'])),
        ];
    }

    /**
     * The method names that the doc comment or `function` at $i declares:
     * the magic methods of the comment, or the name after `function` (and
     * `&`).
     *
     * @param list<\PhpToken> $tokens without whitespace and plain comments
     * @return list<string>
     */
    private function methodsAt(array $tokens, int $i): array
    {
        $token = $tokens[$i];
        if ($token->is(T_DOC_COMMENT)) {
            return preg_match_all($this->documented, $token->text, $matches) > 0
                ? array_map('strtolower', $matches[1])
                : [];
        }
        $name = $tokens[$i + 1] ?? null;
        if ($name !== null && $name->text === '&') {
            $name = $tokens[$i + 2] ?? null;
        }
        $lowerCase = strtolower((string)$name?->text);

        return isset($this->methods[$lowerCase]) ? [$lowerCase] : [];
    }

    /**
     * The class constant names that the `case` or `const` at $i declares:
     * the name of an enum case (`case NAME;`, `case NAME = …`; `case NAME:`
     * of a switch reads it), or each name before an `=` of a `const` list,
     * outside brackets.
     *
     * @param list<\PhpToken> $tokens without whitespace and plain comments
     * @return list<string>
     */
    private function constantsAt(array $tokens, int $i): array
    {
        if ($tokens[$i]->is(T_CASE)) {
            $name = $tokens[$i + 1] ?? null;
            $after = $tokens[$i + 2] ?? null;
            $declares = $name !== null && $after !== null && in_array($after->text, [';', '='], true);

            return $declares && isset($this->constants[$name->text]) ? [$name->text] : [];
        }
        $names = [];
        $depth = 0;
        for ($j = $i + 1; isset($tokens[$j]) && ($depth > 0 || $tokens[$j]->text !== ';'); $j++) {
            $depth += match ($tokens[$j]->text) {
                '(', '[', '{' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };
            if ($depth === 0 && $tokens[$j]->text === '=' && isset($this->constants[$tokens[$j - 1]->text])) {
                $names[] = $tokens[$j - 1]->text;
            }
        }

        return $names;
    }

    /**
     * $names as the alternatives of a pattern; one that matches nothing when
     * there are none.
     *
     * @param list<string> $names
     */
    public static function alternation(array $names): string
    {
        return $names === []
            ? '(?!)'
            : implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names));
    }
}
