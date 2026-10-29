<?php

declare(strict_types=1);

namespace app\components;

/**
 * The CSV text of an analytics report, as the web export and every
 * GET /api/v1/analytics/{report}?format=csv send it: a header row with the
 * keys of the first row, then a line for each row, each line ending with a
 * line feed, as in 2.13.1, not with the carriage return and line feed of
 * RFC 4180.
 *
 * Its fields are quoted as RFC 4180 says, without an escape character: a
 * quote inside a field is doubled, and a backslash is an ordinary
 * character. Spreadsheet tools and other RFC 4180 readers read every field
 * back as it was, a name with a backslash in front of a quote (C:\x\"y)
 * included. fputcsv()'s
 * default escape character, the backslash, left a quote after a backslash
 * undoubled, and such readers read the field wrong. Since PHP 8.4 that
 * default is deprecated ("fputcsv(): the $escape parameter must be provided
 * as its default value will change"), and the error handlers turn the
 * deprecation into an exception: every export with a row answered 500. A
 * field without a backslash is written as before; one with a backslash is
 * no longer quoted for the backslash alone.
 */
final class CsvText
{
    /**
     * What of() throws when PHP cannot open the stream it writes the text
     * to. Under the application's error handlers fopen()'s warning becomes
     * an ErrorException first; of() throws this where warnings are no
     * exceptions (CsvTextTest::testAStreamThatCannotBeOpenedIsAnError()).
     */
    public const NO_STREAM = 'Could not open a temporary stream for the CSV text.';

    /** fputcsv()'s escape character: none, as in RFC 4180. */
    private const NO_ESCAPE = '';

    /**
     * The CSV text of $rows; an empty string when there is no row, which
     * leaves no column names for a header row either.
     *
     * @param array<int|string, array<int|string, scalar|null>> $rows
     */
    public static function of(array $rows): string
    {
        $first = reset($rows);
        if ($first === false) {
            return '';
        }
        $stream = fopen('php://temp', 'r+') ?: throw new \RuntimeException(self::NO_STREAM);
        self::writeLine($stream, array_keys($first));
        foreach ($rows as $row) {
            self::writeLine($stream, $row);
        }
        rewind($stream);
        $text = (string)stream_get_contents($stream);
        fclose($stream);

        return $text;
    }

    /**
     * @param resource $stream
     * @param array<int|string, scalar|null> $fields
     */
    private static function writeLine($stream, array $fields): void
    {
        fputcsv($stream, $fields, ',', '"', self::NO_ESCAPE);
    }
}
