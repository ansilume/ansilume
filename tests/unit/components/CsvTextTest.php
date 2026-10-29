<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\CsvText;
use PHPUnit\Framework\TestCase;

/**
 * CsvText writes the rows of an analytics report as CSV, with RFC 4180
 * quoting: a quote inside a field is doubled, a backslash is an ordinary
 * character. Each line ends with a line feed, as in 2.13.1, not with the
 * carriage return and line feed of RFC 4180. The tests run under the
 * application's error handler, which turns a deprecation into an exception,
 * so a deprecation here fails them.
 *
 * Regression: on PHP 8.4 and later every analytics CSV export with a row
 * answered 500, as fputcsv() without its escape argument is deprecated
 * there ("fputcsv(): the $escape parameter must be provided as its default
 * value will change").
 */
class CsvTextTest extends TestCase
{
    /** The CSV functions of PHP, and the CSV methods of SplFileObject, in lower case. */
    private const CSV_FUNCTIONS = ['fputcsv', 'fgetcsv', 'str_getcsv', 'setcsvcontrol'];

    /** The directories of the application's PHP files. */
    private const APPLICATION_DIRECTORIES = [
        'commands',
        'components',
        'config',
        'controllers',
        'helpers',
        'jobs',
        'mail',
        'migrations',
        'models',
        'services',
        'views',
        'web',
    ];

    /** The tokens the scan leaves out. */
    private const NO_CODE = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    public function testNoRowIsAnEmptyText(): void
    {
        $this->assertSame('', CsvText::of([]));
    }

    public function testAHeaderLineWithTheKeysOfTheFirstRowThenALinePerRow(): void
    {
        $rows = [
            ['template_name' => 'Deploy', 'total' => 3, 'success_rate' => 66.67, 'last_seen_at' => null],
            ['template_name' => 'Backup', 'total' => 1, 'success_rate' => 100.0, 'last_seen_at' => 1761436800],
        ];

        $this->assertSame(
            "template_name,total,success_rate,last_seen_at\n"
            . "Deploy,3,66.67,\n"
            . "Backup,1,100,1761436800\n",
            CsvText::of($rows)
        );
    }

    /**
     * Every field reads back as it was with an RFC 4180 reader, as
     * spreadsheet tools read the export: str_getcsv() without an escape
     * character.
     *
     * @return array<string, array{0: string}>
     */
    public static function nameProvider(): array
    {
        return [
            'a quote, a backslash and a backslash in front of a quote' => ['Deploy "prod" C:\x\"y'],
            'a backslash' => ['C:\temp\x'],
            'a backslash at the end' => ['x\\'],
            'a backslash in front of the last quote' => ['x\"'],
            'two backslashes in front of a quote' => ['a\\\\"b'],
            'JSON with an escaped quote' => ['{"a": "b\"c"}'],
            'quotes' => ['"quoted"'],
            'a comma' => ['a,b'],
            'a line break' => ["first\nsecond"],
            'a carriage return and a line break' => ["first\r\nsecond"],
            'a tab' => ["a\tb"],
            'a leading space' => [' lead'],
            'UTF-8' => ['Grüße'],
            'plain' => ['Deploy'],
        ];
    }

    /**
     * @dataProvider nameProvider
     */
    public function testEveryNameReadsBackAsItWas(string $name): void
    {
        $csv = CsvText::of([['template_name' => $name, 'total' => 3]]);

        $this->assertStringStartsWith("template_name,total\n", $csv);
        $this->assertStringEndsWith(",3\n", $csv);
        $row = substr($csv, strlen("template_name,total\n"), -1);
        $this->assertSame([$name, '3'], str_getcsv($row, ',', '"', ''));
    }

    /**
     * A field without a backslash is written as 2.13.1 wrote it, with
     * fputcsv()'s default escape at the time, the backslash.
     *
     * @return array<string, array{0: string|int|float|null, 1: string}>
     */
    public static function unchangedFieldProvider(): array
    {
        return [
            'quotes' => ['Deploy "prod"', '"Deploy ""prod"""'],
            'a comma' => ['a,b', '"a,b"'],
            'a line break' => ["a\nb", "\"a\nb\""],
            'a carriage return' => ["a\rb", "\"a\rb\""],
            'a tab' => ["a\tb", "\"a\tb\""],
            'a leading space' => [' lead', '" lead"'],
            'plain' => ['Deploy', 'Deploy'],
            'UTF-8' => ['Grüße', 'Grüße'],
            'empty' => ['', ''],
            'null' => [null, ''],
            'int' => [3, '3'],
            'float' => [66.67, '66.67'],
            'whole float' => [75.0, '75'],
            'zero' => [0.0, '0'],
        ];
    }

    /**
     * @dataProvider unchangedFieldProvider
     */
    public function testAFieldWithoutABackslashKeepsTheBytesOf2131(string|int|float|null $value, string $written): void
    {
        $this->assertSame("name,total\n" . $written . ",1\n", CsvText::of([['name' => $value, 'total' => 1]]));
    }

    /**
     * A field with a backslash is quoted as RFC 4180 says, which changes
     * its bytes: a backslash alone no longer makes it quoted, and a quote
     * after a backslash is doubled like any other. 2.13.1 wrote
     * "Deploy ""prod"" C:\x\"y", which RFC 4180 readers read as
     * Deploy "prod" C:\x\y".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function backslashFieldProvider(): array
    {
        return [
            'a quote after a backslash is doubled' => ['Deploy "prod" C:\x\"y', '"Deploy ""prod"" C:\x\""y"'],
            'a backslash alone is not quoted' => ['C:\temp\x', 'C:\temp\x'],
            'a backslash at the end' => ['x\\', 'x\\'],
        ];
    }

    /**
     * @dataProvider backslashFieldProvider
     */
    public function testAFieldWithABackslashIsQuotedAsRfc4180Says(string $value, string $written): void
    {
        $this->assertSame("name\n" . $written . "\n", CsvText::of([['name' => $value]]));
    }

    /**
     * Each line ends with a line feed, as 2.13.1 wrote it, not with the
     * carriage return and line feed of RFC 4180: the exports take RFC
     * 4180's quoting, not its line ends (a decision of the review, which
     * found that the docs claimed RFC 4180 as a whole). A carriage return
     * and line feed inside a field stay inside its quotes.
     */
    public function testEachLineEndsWithALineFeed(): void
    {
        $csv = CsvText::of([['name' => 'Deploy', 'note' => "first\r\nsecond"], ['name' => 'Backup', 'note' => '']]);

        $this->assertSame("name,note\nDeploy,\"first\r\nsecond\"\nBackup,\n", $csv);
        $this->assertSame(1, substr_count($csv, "\r\n"), 'Only the line break inside the quoted field has a carriage return.');
        $this->assertSame(4, substr_count($csv, "\n"), 'Three lines and the line break inside the field.');
    }

    /**
     * Where PHP cannot open the temporary stream that of() writes to and its
     * warning is no exception, of() throws NO_STREAM rather than answering
     * with an empty text. Under the application's error handlers fopen()'s
     * warning becomes an ErrorException first, so the export answers 500
     * either way. The php:// wrapper is gone for the call, and the warning
     * is kept from the error handler, as nothing else makes fopen() fail.
     */
    public function testAStreamThatCannotBeOpenedIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(CsvText::NO_STREAM);

        $this->assertTrue(stream_wrapper_unregister('php'), 'The php:// wrapper is unregistered for the test.');
        set_error_handler(static fn (): bool => true);
        try {
            CsvText::of([['name' => 'Deploy']]);
        } finally {
            restore_error_handler();
            stream_wrapper_restore('php');
        }
    }

    /**
     * Only CsvText calls a CSV function of PHP: a new call of fputcsv(),
     * fgetcsv() or str_getcsv(), or of the CSV methods of SplFileObject,
     * without the escape argument would fail again, and with the backslash
     * as its escape it would write names that RFC 4180 readers read wrong.
     */
    public function testNothingElseCallsACsvFunction(): void
    {
        $calls = [];
        foreach (self::applicationFiles() as $path) {
            $tokens = array_values(array_filter(
                token_get_all((string)file_get_contents(self::root() . '/' . $path)),
                static fn ($token): bool => !is_array($token) || !in_array($token[0], self::NO_CODE, true)
            ));
            foreach ($tokens as $i => $token) {
                if (self::callsACsvFunction($tokens, $i)) {
                    $calls[] = $path . ':' . $token[2];
                }
            }
        }

        $elsewhere = array_values(array_filter(
            $calls,
            static fn (string $call): bool => !str_starts_with($call, 'components/CsvText.php:')
        ));
        $this->assertSame([], $elsewhere, 'Write CSV with app\components\CsvText, which gives fputcsv() its escape');
        $this->assertCount(1, $calls, 'The scan finds the call in CsvText.');
    }

    /**
     * Whether the token at $i calls a CSV function or method: its name,
     * then an opening parenthesis, and no function declaration.
     *
     * @param list<mixed> $tokens without whitespace and comments
     */
    private static function callsACsvFunction(array $tokens, int $i): bool
    {
        $token = $tokens[$i];
        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }
        $name = strtolower(ltrim((string)$token[1], '\\'));
        $before = $tokens[$i - 1] ?? null;

        return in_array($name, self::CSV_FUNCTIONS, true)
            && ($tokens[$i + 1] ?? null) === '('
            && !(is_array($before) && $before[0] === T_FUNCTION);
    }

    /**
     * The PHP files of the application, relative to the repository root.
     *
     * @return list<string>
     */
    private static function applicationFiles(): array
    {
        $root = self::root();
        $files = [];
        foreach (self::APPLICATION_DIRECTORIES as $directory) {
            $directoryFiles = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($directoryFiles as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
