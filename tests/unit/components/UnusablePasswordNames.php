<?php

declare(strict_types=1);

namespace app\tests\unit\components;

/**
 * Password field names that no caller sends exactly because of a character
 * outside ASCII around or in them, for the data providers of the tests of
 * the survey rules, the launch paths and the backfill.
 */
trait UnusablePasswordNames
{
    /**
     * Characters outside ASCII around or in a name: Unicode spaces and line
     * separators, invisible and format characters (a byte order mark from a
     * CSV file, a zero-width space), C1 control characters, and letters,
     * which have more than one encoding. A name copied from a document or a
     * spreadsheet can carry them unseen. Messages show them escaped.
     *
     * Regression (V2-C1U): only ASCII whitespace and control characters
     * made a password field name unusable, so a name with any of these
     * counted as usable. API, trigger and schedule callers send the visible
     * variable name ("db_password"), so their answers were stored in
     * plaintext.
     *
     * @return array<string, array{0: string, 1: string}> the name, and the name as messages quote it
     */
    public static function unicodeNameProvider(): array
    {
        return [
            'U+00A0 trailing' => ["db_password\u{00A0}", '"db_password\u00a0"'],
            'U+00A0 leading' => ["\u{00A0}db_password", '"\u00a0db_password"'],
            'U+2028' => ["db_password\u{2028}", '"db_password\u2028"'],
            'U+2029' => ["db_password\u{2029}", '"db_password\u2029"'],
            'U+3000' => ["db_password\u{3000}", '"db_password\u3000"'],
            'U+FEFF leading' => ["\u{FEFF}db_password", '"\ufeffdb_password"'],
            'U+FEFF trailing' => ["db_password\u{FEFF}", '"db_password\ufeff"'],
            'U+200B' => ["db_password\u{200B}", '"db_password\u200b"'],
            'U+0085' => ["db_password\u{0085}", '"db_password\u0085"'],
            'U+009F' => ["db_password\u{009F}", '"db_password\u009f"'],
            'inner U+00A0' => ["db\u{00A0}password", '"db\u00a0password"'],
            'a letter outside ASCII' => ["db_p\u{00E4}ssword", '"db_p\u00e4ssword"'],
        ];
    }
}
