<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\OutboundHttpClient;

/**
 * How the Mattermost channel writes into a post what it did not write
 * itself: workflow, step, template and user names, and errors. Mattermost
 * renders the message of a post and the title, text, pretext, footer and
 * fields of its attachments as Markdown, and its server looks for mentions
 * in them.
 *
 * - escape(): such a value as plain text on one line. Each character that
 *   Mattermost's Markdown renderer reads (MARKDOWN) is escaped with a
 *   backslash; each line break, tab, other control character and Unicode
 *   line or paragraph separator becomes a space, so that no value starts a
 *   heading, a list or a table on a line of its own; and each @ is followed
 *   by a zero-width space (AFTER_AT). Mattermost reads a mention as an @
 *   followed by a name, in the text before its Markdown, so a backslash
 *   would not stop it: the zero-width space ends the word, and no value
 *   mentions a user, a group or the channel (@all, @channel, @here).
 * - plain(): such a value for a field that Mattermost shows as it is (an
 *   attachment's fallback, which notifications show): one line, @
 *   neutralised, nothing escaped.
 * - link(): a Markdown link, only to an absolute http or https address.
 * - truncate(): a message cut to MAX_LENGTH characters, well below
 *   Mattermost's limit, with a link to the rest in Ansilume.
 */
final class MattermostText
{
    /** The most characters Mattermost takes in the message of a post. */
    public const MATTERMOST_LIMIT = 16383;

    /**
     * The most characters of a message, and of an attachment's text, that
     * the channel posts: well below MATTERMOST_LIMIT, so that a mention
     * fits before it, and so that Mattermost's answer, which repeats the
     * post, stays within what the outbound HTTP client reads of it (65536
     * bytes) for any characters.
     */
    public const MAX_LENGTH = 8000;

    /** What ends a message that truncate() cut; sprintf(): the link to the rest (link()). */
    public const CUT = "\n\n… %s";

    /** The label of the link to the rest of a message that truncate() cut. */
    public const REST = 'More in Ansilume';

    /** The characters that Mattermost's Markdown renderer reads, which escape() escapes with a backslash. */
    private const MARKDOWN = '/[\\\\`*_{}\[\]()#+\-.!|>~]/';

    /** What every @ is followed by: a zero-width space, which ends a mention. */
    private const AFTER_AT = "\u{200B}";

    /** The characters of an address that would end a Markdown link, encoded. */
    private const URL_ENCODED = [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', '\\' => '%5C'];

    /**
     * $userText as plain text in a Markdown field of a post (see the class).
     */
    public static function escape(string $userText): string
    {
        return self::neutralise((string)preg_replace(self::MARKDOWN, '\\\\$0', self::line($userText)));
    }

    /**
     * $userText as plain text in a field that Mattermost shows as it is:
     * one line, every @ neutralised.
     */
    public static function plain(string $userText): string
    {
        return self::neutralise(self::line($userText));
    }

    /**
     * A Markdown link labelled $label (escaped) to $url; only the label
     * when $url is no absolute http or https address.
     */
    public static function link(string $label, string $url): string
    {
        $text = self::escape($label);
        if (!self::isAddress($url)) {
            return $text;
        }

        return '[' . $text . '](' . strtr($url, self::URL_ENCODED) . ')';
    }

    /**
     * Whether $url is an absolute http or https address, which a link and
     * an attachment's title_link may go to.
     */
    public static function isAddress(string $url): bool
    {
        return in_array(OutboundHttpClient::schemeOf($url), ['http', 'https'], true);
    }

    /**
     * $message as posted: as it is up to MAX_LENGTH characters; longer, cut
     * so that it ends with CUT and the link to the rest at $url (the run,
     * the job, the approval request) within MAX_LENGTH, without a
     * backslash left at the cut, which would escape the CUT.
     */
    public static function truncate(string $message, string $url): string
    {
        if (mb_strlen($message) <= self::MAX_LENGTH) {
            return $message;
        }
        $suffix = sprintf(self::CUT, self::link(self::REST, $url));

        return rtrim(mb_substr($message, 0, self::MAX_LENGTH - mb_strlen($suffix)), '\\') . $suffix;
    }

    /**
     * $text on one line: valid UTF-8, each control character and Unicode
     * line or paragraph separator a space.
     */
    private static function line(string $text): string
    {
        return (string)preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]/u', ' ', mb_scrub($text, 'UTF-8'));
    }

    private static function neutralise(string $text): string
    {
        return str_replace('@', '@' . self::AFTER_AT, $text);
    }
}
