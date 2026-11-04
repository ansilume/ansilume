<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

/**
 * The mentions Mattermost finds in a text, as its server's mention parser
 * reads a message and the texts of its attachments
 * (StandardMentionParser.ProcessText of server v12): the text is split into
 * words at every character that is no letter, digit, ':', '.', '-', '_' or
 * '@'; a word ':name:' is an emoji; leading ':', '.', '-' and '_' are cut;
 * a word that starts with '@' mentions the name after it (without a '.',
 * '-' or ':' that ends a sentence), @all, @channel and @here the whole
 * channel. A backslash splits words, so it stops no mention.
 *
 * For tests: what a message of the Mattermost channel would make
 * Mattermost notify.
 */
final class MattermostMentions
{
    /**
     * The names $text mentions, in order, lower case, without the '@':
     * all, channel and here for the whole channel.
     *
     * @return list<string>
     */
    public static function in(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}:.\-_@]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $mentions = [];
        foreach ($words === false ? [] : $words as $word) {
            if (strlen($word) > 1 && $word[0] === ':' && $word[strlen($word) - 1] === ':') {
                continue;
            }
            $word = ltrim($word, ':.-_');
            if (!str_starts_with($word, '@') || strlen($word) < 2) {
                continue;
            }
            $mentions[] = strtolower(rtrim(substr($word, 1), '.-:'));
        }

        return array_values(array_filter($mentions, static fn (string $name): bool => $name !== ''));
    }
}
