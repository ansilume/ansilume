<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * Finds the uses in PHP code of what the running PHP deprecates
 * (PhpDeprecations), from the tokens of the code: for DeprecatedPhpCallsTest.
 *
 * - FUNCTION: a call of a deprecated internal function, however it is
 *   spelled (`curl_close(`, `\curl_close (`, `CURL_CLOSE(`), but not a method
 *   or a declaration of that name, nor `new` of a class of that name.
 * - METHOD: a call `->name(`, `?->name(` or `::name(` of a method name that
 *   PhpDeprecations knows.
 * - CONSTANT: a deprecated global constant (`E_STRICT`, `\E_STRICT`), or
 *   `::NAME` of a class constant name that PhpDeprecations knows; not a
 *   declaration, a named argument or a label of that name.
 * - HTTP_RESPONSE_HEADER: the variable $http_response_header, which PHP 8.5
 *   deprecates when it compiles a plain read of it.
 *
 * Strings and comments are no code: in "$levels[E_STRICT]" the key is the
 * string 'E_STRICT', so of a string only the variables it reads count.
 */
final class DeprecatedPhpUseScan
{
    public const FUNCTION = 'function';
    public const METHOD = 'method';
    public const CONSTANT = 'constant';
    public const HTTP_RESPONSE_HEADER = 'http_response_header';

    /** The variable that HTTP_RESPONSE_HEADER finds. */
    public const HTTP_RESPONSE_HEADER_VARIABLE = '$http_response_header';

    /** What PHP 8.5 says when it compiles a read of $http_response_header. */
    public const HTTP_RESPONSE_HEADER_SAYS = 'The predefined locally scoped $http_response_header variable is'
        . ' deprecated, call http_get_last_response_headers() instead';

    /** The directories that sourceFilesIn() skips, from the root: dependencies, runtime data, images. */
    public const SKIPPED = ['.composer', '.git', '@runtime', 'docker', 'runtime', 'vendor', 'web/assets'];

    /** A directory of this name is skipped wherever it is. */
    private const NODE_MODULES = 'node_modules';

    /** Tokens before a name that make it a member, so a method call or a class constant. */
    private const MEMBER_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    /** Tokens before `name(` that make it a declaration or a class: function name(, new name(, #[name(. */
    private const BEFORE_NO_CALL = [T_FUNCTION, T_NEW, T_ATTRIBUTE];

    /** Tokens before a name that declare it or make it a class, an attribute, a namespace or a label. */
    private const BEFORE_NO_CONSTANT = [
        T_AS,
        T_ATTRIBUTE,
        T_CLASS,
        T_CONST,
        T_ENUM,
        T_EXTENDS,
        T_FUNCTION,
        T_GOTO,
        T_IMPLEMENTS,
        T_INSTANCEOF,
        T_INSTEADOF,
        T_INTERFACE,
        T_NAMESPACE,
        T_NEW,
        T_TRAIT,
        T_USE,
    ];

    /** Tokens after a name that make it declared (NAME =), a class (NAME::) or a type (NAME $variable). */
    private const AFTER_NO_CONSTANT = ['=', T_DOUBLE_COLON, T_VARIABLE];

    /** Tokens before `NAME:` that make NAME a named argument or a label. */
    private const BEFORE_A_NAMED_ARGUMENT_OR_LABEL = ['(', ',', ';', '{', '}'];

    private const IN_STRING = 'string';
    private const IN_CODE = 'code';

    /** A pattern that matches every name of $deprecations as a whole word, in any case. */
    private readonly string $mentions;

    public function __construct(private readonly PhpDeprecations $deprecations)
    {
        $names = array_merge(
            array_keys($deprecations->functions),
            array_keys($deprecations->methods),
            array_keys($deprecations->constants),
            array_keys($deprecations->classConstants),
            [substr(self::HTTP_RESPONSE_HEADER_VARIABLE, 1)]
        );
        $this->mentions = '/\b(?:' . DeclaredNames::alternation(array_map('strval', $names)) . ')\b/i';
    }

    /**
     * The PHP files under $root, relative to it and sorted: every *.php file
     * and the console entry script `yii`, outside SKIPPED and node_modules.
     *
     * @return list<string>
     */
    public static function sourceFilesIn(string $root): array
    {
        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $file) use ($root): bool {
                $path = substr($file->getPathname(), strlen($root) + 1);
                if ($file->isDir()) {
                    return !in_array($path, self::SKIPPED, true) && $file->getFilename() !== self::NODE_MODULES;
                }

                return $file->getExtension() === 'php' || $path === 'yii';
            }
        );
        $files = [];
        foreach (new \RecursiveIteratorIterator($directories) as $file) {
            if ($file instanceof \SplFileInfo) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Each use in $code: its line, its kind and what PHP says.
     *
     * @return list<array{line: int, kind: string, says: string}>
     */
    public function usesIn(string $code): array
    {
        // Only code that has one of the names as a word can use it: every
        // use is a token whose text is the name (\curl_close ends with it),
        // and the characters around a token cannot belong to a word. Most
        // files have none, and tokenizing them all took a second. A match
        // that fails (false) scans the file all the same.
        if (preg_match($this->mentions, $code) === 0) {
            return [];
        }
        $tokens = self::codeTokens($code);
        $uses = [];
        foreach ($tokens as $i => $token) {
            $use = $this->useAt($tokens, $i);
            if ($use !== null) {
                $uses[] = ['line' => $token->line, 'kind' => $use['kind'], 'says' => $use['says']];
            }
        }

        return $uses;
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return array{kind: string, says: string}|null
     */
    private function useAt(array $tokens, int $i): ?array
    {
        $token = $tokens[$i];
        if ($token->is(T_VARIABLE)) {
            return $token->text === self::HTTP_RESPONSE_HEADER_VARIABLE
                ? ['kind' => self::HTTP_RESPONSE_HEADER, 'says' => self::HTTP_RESPONSE_HEADER_SAYS]
                : null;
        }
        $name = self::globalName($token);
        if ($name === null) {
            return null;
        }
        $before = $tokens[$i - 1] ?? null;
        $after = $tokens[$i + 1] ?? null;
        if ($before !== null && $before->is(self::MEMBER_OPERATORS)) {
            return $this->memberUse($name, $before, $after);
        }
        if ($after !== null && $after->text === '(') {
            return $this->functionCall($name, $tokens, $i);
        }

        return $this->constantUse($name, $before, $after);
    }

    /**
     * A method call `->name(`, or a class constant `::NAME`.
     *
     * @return array{kind: string, says: string}|null
     */
    private function memberUse(string $name, \PhpToken $operator, ?\PhpToken $after): ?array
    {
        if ($after !== null && $after->text === '(') {
            $says = $this->deprecations->methods[strtolower($name)] ?? null;

            return $says === null ? null : ['kind' => self::METHOD, 'says' => $says];
        }
        $says = $operator->is(T_DOUBLE_COLON) ? $this->deprecations->classConstants[$name] ?? null : null;

        return $says === null ? null : ['kind' => self::CONSTANT, 'says' => $says];
    }

    /**
     * `name(`: a call of the function, unless the name is declared there
     * (also `function &name(`) or names a class (BEFORE_NO_CALL).
     *
     * @param list<\PhpToken> $tokens
     * @return array{kind: string, says: string}|null
     */
    private function functionCall(string $name, array $tokens, int $i): ?array
    {
        $says = $this->deprecations->functions[strtolower($name)] ?? null;
        $before = $tokens[$i - 1] ?? null;
        if ($before !== null && $before->text === '&') {
            $before = $tokens[$i - 2] ?? null;
        }
        if ($says === null || ($before !== null && $before->is(self::BEFORE_NO_CALL))) {
            return null;
        }

        return ['kind' => self::FUNCTION, 'says' => $says];
    }

    /**
     * A name that is neither a member nor followed by `(`: a global
     * constant, unless the tokens around it make it something else.
     *
     * @return array{kind: string, says: string}|null
     */
    private function constantUse(string $name, ?\PhpToken $before, ?\PhpToken $after): ?array
    {
        $says = $this->deprecations->constants[$name] ?? null;
        if ($says === null || self::namesNoConstant($before, $after)) {
            return null;
        }

        return ['kind' => self::CONSTANT, 'says' => $says];
    }

    /**
     * Whether a name between $before and $after is no constant read: it is
     * declared (const NAME, NAME = in a list of constants, case NAME; of an
     * enum), names a class, a type, a namespace or a label, or is a named
     * argument (NAME: after an opening parenthesis or a comma). `case NAME:`
     * of a switch reads the constant.
     */
    private static function namesNoConstant(?\PhpToken $before, ?\PhpToken $after): bool
    {
        if (
            ($after !== null && $after->is(self::AFTER_NO_CONSTANT))
            || ($before !== null && $before->is(self::BEFORE_NO_CONSTANT))
        ) {
            return true;
        }
        if ($before !== null && $before->is(T_CASE)) {
            return $after !== null && $after->text === ';';
        }

        return $after !== null && $after->text === ':'
            && ($before === null || $before->is(self::BEFORE_A_NAMED_ARGUMENT_OR_LABEL));
    }

    /**
     * The name a token can give a global function or constant: an
     * unqualified name, or a fully qualified one in the global namespace
     * (`\curl_close`) without its backslash; null for every other token.
     */
    private static function globalName(\PhpToken $token): ?string
    {
        if ($token->is(T_STRING)) {
            return $token->text;
        }
        if (!$token->is(T_NAME_FULLY_QUALIFIED)) {
            return null;
        }
        $name = substr($token->text, 1);

        return str_contains($name, '\\') ? null : $name;
    }

    /**
     * The tokens of $code that are code: without whitespace, comments and
     * the text of strings, of which only the variables an interpolation
     * reads stay. {$…} in a string is code again.
     *
     * @return list<\PhpToken>
     */
    private static function codeTokens(string $code): array
    {
        $tokens = [];
        // IN_STRING for each string that is open, IN_CODE for each brace (and {$ of a string).
        $frames = [];
        foreach (\PhpToken::tokenize($code, TOKEN_PARSE) as $token) {
            if (!$token->isIgnorable() && self::isCode($token, $frames)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Whether $token is code, after which $frames is the state of the
     * strings and braces that are open.
     *
     * @param list<string> $frames
     */
    private static function isCode(\PhpToken $token, array &$frames): bool
    {
        if (end($frames) === self::IN_STRING) {
            if ($token->is(['"', '`', T_END_HEREDOC])) {
                array_pop($frames);
            } elseif ($token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $frames[] = self::IN_CODE;
            }

            return $token->is(T_VARIABLE);
        }
        if ($token->is(['"', '`', T_START_HEREDOC])) {
            $frames[] = self::IN_STRING;
        } elseif ($token->text === '{') {
            $frames[] = self::IN_CODE;
        } elseif ($token->text === '}') {
            array_pop($frames);
        }

        return true;
    }
}
