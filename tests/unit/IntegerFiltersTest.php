<?php

declare(strict_types=1);

namespace app\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Every use of an integer filter of PHP's filter extension
 * (FILTER_VALIDATE_INT, FILTER_SANITIZE_NUMBER_INT) in the application code
 * (RequestIntCastScan::sourceFilesIn()) is one of ALLOWED, which names the
 * reason why it never reads a float or a boolean. filter_var() makes text
 * of its value first, and PHP writes a float with 14 significant digits:
 * FILTER_VALIDATE_INT takes 2.9999999999999996 (text "3") for 3 and true
 * (text "1") for 1, trims spaces (" 5" is 5) and refuses "05", which the
 * integer rule of the models reads as 5. An ID is read with
 * SubmittedId::of() and another whole number with SubmittedInteger::of(),
 * as the integer rule reads them, so that a check, such as the lock of a
 * gated job template, and the save it guards read one value. A use that
 * ALLOWED does not name fails, and so does an entry of ALLOWED that names
 * no use any more. RequestIntCastsTest, which finds the int conversions of
 * request values, does not count filter_var() as one.
 *
 * Regression: JobTemplateCredentialService::checkedIds() and
 * GatedJobTemplateLock::changesCredentials() read the entries of a job
 * template's credential_ids with FILTER_VALIDATE_INT, so POST and PUT
 * /api/v1/job-templates and the job template form posted as JSON took
 * [2.9999999999999996] for credential #3 and [true] for #1, an ID followed
 * by a fraction in its 15th digit for that ID, and refused "05".
 *
 * What the scan cannot see: a filter given as its number (257) or as its
 * name in a text (constant('FILTER_VALIDATE_INT')). A constant of our own
 * that holds a filter is found where it is declared.
 */
class IntegerFiltersTest extends TestCase
{
    /** The integer filters of the filter extension, which read a value by its text. */
    private const FILTERS = ['FILTER_VALIDATE_INT', 'FILTER_SANITIZE_NUMBER_INT'];

    /**
     * The uses that never read a float or a boolean: by file and the named
     * function or method that holds the use ("path::method"; the path alone
     * for code outside every one), with the reason. Each entry names one use.
     */
    private const ALLOWED = [
        'components/WorkflowStepsInput.php::toId' => 'returns null first for a value that is neither an int nor a text (is_int(), is_string())',
        'models/WorkflowJobSearch.php::activeWorkflowTemplateId' => 'reads an int on its own, and a text only when it is digits (preg_match(\'/^[0-9]+$/\')), a query parameter',
        'services/VaultCredentialAssignmentService.php::checkedIds' => 'filters an int or a text only (is_int() || is_string()), and takes false for anything else',
    ];

    /** Tokens before a name that make it no use of the global constant: a member, a declaration, a class. */
    private const BEFORE_NO_CONSTANT = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];

    /** @var list<string>|null the uses of the application code, as "path::method", once per test run */
    private static ?array $uses = null;

    public function testNoOtherApplicationCodeUsesAnIntegerFilter(): void
    {
        $unlisted = self::usesOfTheApplicationCode();
        foreach (array_keys(self::ALLOWED) as $allowed) {
            $first = array_search($allowed, $unlisted, true);
            if ($first !== false) {
                unset($unlisted[$first]);
            }
        }

        $this->assertSame(
            [],
            array_values($unlisted),
            'filter_var() reads a float by its text of 14 significant digits and takes true for 1, so FILTER_VALIDATE_INT'
            . ' takes 2.9999999999999996 for 3: read an ID with SubmittedId::of() instead, and a whole number with'
            . ' SubmittedInteger::of(), as the integer rule reads them; only a use that never reads a float or a boolean'
            . ' goes into ALLOWED, with the reason'
        );
    }

    public function testEveryAllowedUseIsStillThere(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff(array_keys(self::ALLOWED), self::usesOfTheApplicationCode())),
            'ALLOWED names a use that is gone: remove its entry'
        );
    }

    /**
     * Each way the scan finds: either filter, by its name or fully
     * qualified, in a method, a closure of a method (the method's use), an
     * import, code outside every function and a constant that holds it; not
     * a class constant of that name, a property, a text, a comment or
     * another filter.
     */
    public function testTheScanFindsEachUse(): void
    {
        $code = <<<'PHP'
            <?php
            namespace app\sample;

            use const FILTER_VALIDATE_INT;

            final class Sample
            {
                public const FILTER_VALIDATE_INT = 5;

                public function a(mixed $value): mixed
                {
                    return filter_var($value, FILTER_VALIDATE_INT);
                }

                public function &b(mixed $value): mixed
                {
                    $read = static fn (mixed $v): mixed => filter_var($v, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                    return $read($value) ?? filter_var($value, FILTER_SANITIZE_NUMBER_INT);
                }

                public function c(mixed $value): array
                {
                    // filter_var($value, FILTER_VALIDATE_INT) in a comment
                    return [self::FILTER_VALIDATE_INT, static::FILTER_VALIDATE_INT, $this->FILTER_VALIDATE_INT, $value?->FILTER_VALIDATE_INT,
                        'FILTER_VALIDATE_INT', "FILTER_VALIDATE_INT {$value}", FILTER_VALIDATE_FLOAT, FILTER_VALIDATE_BOOLEAN];
                }
            }

            $top = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

            final class Holder
            {
                public const FILTER = FILTER_VALIDATE_INT;
            }
            PHP;

        $this->assertSame(
            [
                ['line' => 4, 'function' => '', 'filter' => 'FILTER_VALIDATE_INT'],
                ['line' => 12, 'function' => 'a', 'filter' => 'FILTER_VALIDATE_INT'],
                ['line' => 17, 'function' => 'b', 'filter' => '\FILTER_VALIDATE_INT'],
                ['line' => 19, 'function' => 'b', 'filter' => 'FILTER_SANITIZE_NUMBER_INT'],
                ['line' => 30, 'function' => '', 'filter' => 'FILTER_VALIDATE_INT'],
                ['line' => 34, 'function' => '', 'filter' => 'FILTER_VALIDATE_INT'],
            ],
            self::usesIn($code)
        );
    }

    /**
     * The uses in the application code, as "path::method" or the path
     * alone, in the order of the files and of their code.
     *
     * @return list<string>
     */
    private static function usesOfTheApplicationCode(): array
    {
        if (self::$uses !== null) {
            return self::$uses;
        }
        $root = dirname(__DIR__, 2);
        $uses = [];
        foreach (RequestIntCastScan::sourceFilesIn($root) as $path) {
            $code = file_get_contents($root . '/' . $path);
            self::assertIsString($code, $path);
            foreach (self::usesIn($code) as ['function' => $function]) {
                $uses[] = $function === '' ? $path : "{$path}::{$function}";
            }
        }

        return self::$uses = $uses;
    }

    /**
     * Where $code names an integer filter as the global constant: the line,
     * the named function or method that holds it ('' for none) and the name
     * as written.
     *
     * @return list<array{line: int, function: string, filter: string}>
     */
    private static function usesIn(string $code): array
    {
        $php = new PhpCode($code);
        $uses = [];
        foreach ($php->tokens as $i => $token) {
            $before = $php->tokens[$i - 1] ?? null;
            if (
                !$token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                || !in_array(ltrim($token->text, '\\'), self::FILTERS, true)
                || ($before !== null && $before->is(self::BEFORE_NO_CONSTANT))
                || ($before !== null && $before->is(T_CONST) && !($php->tokens[$i - 2] ?? null)?->is(T_USE))
            ) {
                continue;
            }
            $uses[] = ['line' => $token->line, 'function' => self::functionOf($php, $php->scopes[$i]), 'filter' => $token->text];
        }

        return $uses;
    }

    /**
     * The name of the function or method whose `function` token is at
     * $function (after a `&` of a reference return), '' for -1: no named
     * function.
     */
    private static function functionOf(PhpCode $php, int $function): string
    {
        if ($function === -1) {
            return '';
        }
        $name = $php->tokens[$function + 1]->text === '&' ? $php->tokens[$function + 2] : $php->tokens[$function + 1];

        return $name->text;
    }
}
