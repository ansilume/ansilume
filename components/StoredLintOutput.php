<?php

declare(strict_types=1);

namespace app\components;

/**
 * The output of a lint run as LintService stores it (lint_output of
 * job_template and project): whole when it fits the column, else its start
 * and its end with a note between them that says how many bytes were left
 * out (LEFT_OUT).
 *
 * The column is TEXT (m000029, m000031), which holds 65,535 bytes, and the
 * database runs in strict mode (STRICT_TRANS_TABLES): a longer output failed
 * the UPDATE that stores the result and ends the requests of the run, and
 * the lint card showed "Lint queued" for good. ansible-lint wrote 71,194
 * bytes for a playbook of 60 legacy tasks: its findings first (stdout) and
 * their summary last (stderr: the Rule Violation Summary and the line that
 * counts the failures), so both ends are kept.
 *
 * Measured in bytes, as the column counts them. Each part is cut at a line
 * break, which keeps the ANSI colour codes and links of ansible-lint's lines
 * whole; a part without one is cut at a character boundary: never inside a
 * multibyte character (UTF-8), which the column would refuse.
 */
final class StoredLintOutput
{
    /** The most a stored output holds, in bytes: what a TEXT column holds. */
    public const MAX_BYTES = 65535;

    /**
     * How much of its end an output that was cut keeps, at most, in bytes:
     * ansible-lint's summary (about 1 KB, 5 KB for many rules) and the last
     * findings before it. The start keeps the rest.
     */
    public const END_BYTES = 16384;

    /**
     * The line between the start and the end of an output that was cut;
     * sprintf(): how many bytes were left out, MAX_BYTES.
     */
    public const LEFT_OUT = '[%d bytes of the output left out here: a lint result keeps at most %d bytes, the start and the end of the output.]';

    /** The first two bits of a UTF-8 continuation byte (10xxxxxx), which no character starts with. */
    private const CONTINUATION = 0x80;

    public static function of(string $output): string
    {
        $size = strlen($output);
        if ($size <= self::MAX_BYTES) {
            return $output;
        }
        // The note is sized for the most digits it can take: less than the whole output is left out.
        $room = self::MAX_BYTES - strlen(sprintf(self::LEFT_OUT, $size, self::MAX_BYTES)) - 2;
        $end = self::endOf($output, min(self::END_BYTES, intdiv($room, 2)));
        $start = self::startOf($output, $room - strlen($end));
        $leftOut = $size - strlen($start) - strlen($end);

        return $start . "\n" . sprintf(self::LEFT_OUT, $leftOut, self::MAX_BYTES) . "\n" . $end;
    }

    /**
     * The start of $output, at most $bytes: up to the last line break in
     * them (without it), else up to the last whole character.
     */
    private static function startOf(string $output, int $bytes): string
    {
        $start = substr($output, 0, $bytes);
        $break = strrpos($start, "\n");

        return $break === false ? mb_strcut($output, 0, $bytes, 'UTF-8') : substr($start, 0, $break);
    }

    /**
     * The end of $output, at most $bytes: from the first line break in them
     * (without it), else from the first byte that starts a character.
     */
    private static function endOf(string $output, int $bytes): string
    {
        $from = strlen($output) - $bytes;
        $break = strpos($output, "\n", $from);
        if ($break !== false) {
            return substr($output, $break + 1);
        }
        while ($from < strlen($output) && (ord($output[$from]) & 0xC0) === self::CONTINUATION) {
            $from++;
        }

        return substr($output, $from);
    }
}
