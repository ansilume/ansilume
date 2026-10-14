<?php

declare(strict_types=1);

namespace app\components;

use app\models\NotificationTemplate;

/**
 * The secrets of a notification template's channel config
 * (NotificationTemplate::SECRET_CONFIG_KEYS and SECRET_CONFIG_MAPS).
 *
 * The template page, its edit form and the API show SurveySecrets::PLACEHOLDER
 * for every secret value (redacted()). A key that is a secret for one channel
 * is redacted in every config, so neither a key left over from another
 * channel nor the config of a channel no handler sends shows a secret. Header
 * names are shown, their values are not.
 *
 * A submitted config that holds the placeholder at a secret's place keeps the
 * stored value there (restore(), which the template's validator runs). Only
 * while the template keeps its channel, and for a header only while every
 * other secret of its channel (the webhook's url) keeps its stored value:
 * whoever may edit a template but not read it could otherwise send the
 * stored header to a server of their own. The placeholder is refused at a
 * place without a stored value, and after such a change.
 *
 * The configs it works on are properties: no method takes a secret as a
 * parameter, so none reaches a stack trace (CLAUDE.md security rule 11).
 */
final class NotificationConfigSecrets
{
    /** A placeholder at a place without a stored value; sprintf(): the place, such as bot_token or headers.Authorization. */
    public const NOT_STORED = '%s has no stored value; enter the real value.';

    /** A placeholder after a channel change; sprintf(): the place. */
    public const CHANNEL_CHANGED = '%s: the channel changed; enter the real value.';

    /** A header placeholder after another secret of the channel changed; sprintf(): the place, then that secret's key (url). */
    public const URL_CHANGED = '%s: %s changed; enter the real value.';

    /** The config with the stored values put back cannot be written as JSON. */
    public const NOT_ENCODABLE = 'The config cannot be stored: it contains a number too large for JSON.';

    /** The note under the config on the template page; sprintf(): the placeholder, as HTML. */
    public const PAGE_NOTE = 'Channel secrets show as %s.';

    /** The hint of the edit form; sprintf(): the placeholder. */
    public const FORM_HINT = 'Secrets show as %s: leave it to keep the stored value, or replace it to change it.';

    /** How a config is written as JSON, as the edit form's script writes it. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @var array<int|string, mixed> the config worked on */
    private array $config = [];

    /** @var array<int|string, mixed> the config as stored; [] for a new template */
    private array $stored = [];

    public function __construct(private readonly NotificationTemplate $template)
    {
    }

    /**
     * The template's config with SurveySecrets::PLACEHOLDER for every secret
     * value that is set (not null or ''); [] for a config that is no JSON
     * object or array.
     *
     * @return array<int|string, mixed>
     */
    public function redacted(): array
    {
        $this->config = $this->template->getParsedConfig();
        foreach (self::secretKeys() as $key) {
            if ($this->holdsValue($key)) {
                $this->config[$key] = SurveySecrets::PLACEHOLDER;
            }
        }
        foreach (self::secretMaps() as $map) {
            $this->redactMap($map);
        }

        return $this->config;
    }

    /**
     * Puts the stored value back in place of every placeholder at a secret's
     * place of the template's config as submitted, and assigns the result as
     * JSON. A config without such a placeholder, or that is no JSON object
     * or array (validateJson reports that), is left as submitted.
     *
     * @return string|null null after assigning; else the error, and nothing is assigned
     */
    public function restore(): ?string
    {
        $submitted = json_decode((string)$this->template->config, true);
        if (!is_array($submitted)) {
            return null;
        }
        $this->config = $submitted;
        $this->stored = $this->storedConfig();
        $places = $this->placeholders();
        if ($places === []) {
            return null;
        }
        foreach ($places as $place) {
            $error = $this->refusal($place);
            if ($error !== null) {
                return $error;
            }
        }
        foreach ($places as $place) {
            $this->putBack($place);
        }
        $json = json_encode($this->config, self::JSON_FLAGS);
        if ($json === false) {
            return self::NOT_ENCODABLE;
        }
        $this->template->config = $json;

        return null;
    }

    /**
     * Every key that is a secret for some channel.
     *
     * @return list<string>
     */
    private static function secretKeys(): array
    {
        return self::union(NotificationTemplate::SECRET_CONFIG_KEYS);
    }

    /**
     * Every key whose values are secrets for some channel.
     *
     * @return list<string>
     */
    private static function secretMaps(): array
    {
        return self::union(NotificationTemplate::SECRET_CONFIG_MAPS);
    }

    /**
     * @param array<string, list<string>> $byChannel
     * @return list<string>
     */
    private static function union(array $byChannel): array
    {
        $keys = [];
        foreach ($byChannel as $channelKeys) {
            foreach ($channelKeys as $key) {
                if (!in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * The secret keys of every channel that has the map $map: what its
     * values go along with (for the headers, the webhook's url).
     *
     * @return list<string>
     */
    private static function keysBeside(string $map): array
    {
        $keys = [];
        foreach (NotificationTemplate::SECRET_CONFIG_MAPS as $channel => $maps) {
            if (in_array($map, $maps, true)) {
                $keys = array_merge($keys, NotificationTemplate::SECRET_CONFIG_KEYS[$channel]);
            }
        }

        return $keys;
    }

    /** Whether the config worked on holds a value at $key: anything but a missing key, null or ''. */
    private function holdsValue(string $key): bool
    {
        $value = $this->config[$key] ?? null;

        return $value !== null && $value !== '';
    }

    /** The placeholder for every value of the map $map that is set; for the whole map when it is no array. */
    private function redactMap(string $map): void
    {
        if (!$this->holdsValue($map)) {
            return;
        }
        $entries = $this->config[$map];
        if (!is_array($entries)) {
            $this->config[$map] = SurveySecrets::PLACEHOLDER;
            return;
        }
        foreach ($entries as $name => $value) {
            if ($value !== null && $value !== '') {
                $entries[$name] = SurveySecrets::PLACEHOLDER;
            }
        }
        $this->config[$map] = $entries;
    }

    /**
     * The config as stored: [] for a new template, and for a stored config
     * that is no JSON object or array.
     *
     * @return array<int|string, mixed>
     */
    private function storedConfig(): array
    {
        $stored = $this->template->getOldAttribute('config');
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Every secret's place that holds the placeholder: [key], [map] or
     * [map, name].
     *
     * @return list<list<int|string>>
     */
    private function placeholders(): array
    {
        $places = [];
        foreach (self::secretKeys() as $key) {
            if (($this->config[$key] ?? null) === SurveySecrets::PLACEHOLDER) {
                $places[] = [$key];
            }
        }
        foreach (self::secretMaps() as $map) {
            $places = array_merge($places, $this->mapPlaceholders($map));
        }

        return $places;
    }

    /**
     * @return list<list<int|string>>
     */
    private function mapPlaceholders(string $map): array
    {
        $entries = $this->config[$map] ?? null;
        if ($entries === SurveySecrets::PLACEHOLDER) {
            return [[$map]];
        }
        $places = [];
        foreach (is_array($entries) ? $entries : [] as $name => $value) {
            if ($value === SurveySecrets::PLACEHOLDER) {
                $places[] = [$map, $name];
            }
        }

        return $places;
    }

    /**
     * Why the placeholder at $place cannot keep the stored value, or null
     * when it can.
     *
     * @param list<int|string> $place
     */
    private function refusal(array $place): ?string
    {
        $label = implode('.', $place);
        $stored = $this->storedAt($place);
        if ($stored === null || $stored === '') {
            return sprintf(self::NOT_STORED, $label);
        }
        if (!$this->keepsChannel()) {
            return sprintf(self::CHANNEL_CHANGED, $label);
        }
        $changed = in_array($place[0], self::secretMaps(), true) ? $this->changedKeyBeside((string)$place[0]) : null;

        return $changed === null ? null : sprintf(self::URL_CHANGED, $label, $changed);
    }

    /**
     * The stored value at $place; null where nothing is stored.
     *
     * @param list<int|string> $place
     */
    private function storedAt(array $place): mixed
    {
        $value = $this->stored[$place[0]] ?? null;
        if (count($place) === 1) {
            return $value;
        }

        return is_array($value) ? ($value[$place[1]] ?? null) : null;
    }

    /** Whether the template keeps its stored channel; a new template has none. */
    private function keepsChannel(): bool
    {
        return $this->template->getOldAttribute('channel') === $this->template->channel;
    }

    /**
     * The first secret key beside the map $map whose submitted value is
     * neither the placeholder nor the stored value; null when none changed.
     */
    private function changedKeyBeside(string $map): ?string
    {
        foreach (self::keysBeside($map) as $key) {
            $value = $this->config[$key] ?? null;
            if ($value !== SurveySecrets::PLACEHOLDER && $value !== ($this->stored[$key] ?? null)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The stored value in place of the placeholder at $place.
     *
     * @param list<int|string> $place
     */
    private function putBack(array $place): void
    {
        $value = $this->storedAt($place);
        if (count($place) === 1) {
            $this->config[$place[0]] = $value;
            return;
        }
        $entries = $this->config[$place[0]] ?? null;
        if (is_array($entries)) {
            $entries[$place[1]] = $value;
            $this->config[$place[0]] = $entries;
        }
    }
}
