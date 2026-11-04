<?php

declare(strict_types=1);

namespace app\components;

use app\models\NotificationTemplate;
use app\services\notification\ChannelRegistry;

/**
 * The secrets of a notification template's channel config: the secretKeys
 * and secretMaps of the channels of the installation (ChannelDefinition,
 * ChannelRegistry).
 *
 * The template page, its edit form and the API show SurveySecrets::PLACEHOLDER
 * for every secret value (redacted()). A key that is a secret for one channel
 * is redacted in every config, so neither a key left over from another
 * channel nor the config of a channel no handler sends shows a secret. Header
 * names are shown, their values are not. The config of a channel that the
 * installation does not have (a channel of its own taken out of its
 * configuration) shows no value at all: what was a secret of that channel
 * is no longer known as one.
 *
 * A submitted config that holds the placeholder at a secret's place keeps the
 * stored value there (restore(), which the template's validator runs). Only
 * while the template keeps its channel; and for a secret bound to another
 * key (the boundTo of its channel's definition: the headers and the signing
 * secret of a webhook template to its url, the bot token of a Mattermost
 * template to its server_url)
 * only while that key keeps its stored value, typed again or, for a key
 * that is a secret itself, sent back as the placeholder: whoever may edit a
 * template but not read it could otherwise send the stored header or token
 * to a server of their own, or have the stored signing secret sign for one.
 * The placeholder is refused at a place without a stored value, and after
 * such a change.
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

    /**
     * A bound secret's placeholder after the key it is bound to (the
     * channel's boundTo) changed; sprintf(): the place, then that key (url,
     * server_url).
     */
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
        if (ChannelRegistry::definitionOf((string)$this->template->channel) === null) {
            $this->redactEveryOtherValue();
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
     * Every key that is a secret for some channel of the installation.
     *
     * @return list<string>
     */
    private static function secretKeys(): array
    {
        $keys = [];
        foreach (ChannelRegistry::installed() as $definition) {
            $keys = array_merge($keys, $definition->secretKeys);
        }

        return array_values(array_unique($keys));
    }

    /**
     * Every key whose values are secrets for some channel of the
     * installation.
     *
     * @return list<string>
     */
    private static function secretMaps(): array
    {
        $maps = [];
        foreach (ChannelRegistry::installed() as $definition) {
            $maps = array_merge($maps, $definition->secretMaps);
        }

        return array_values(array_unique($maps));
    }

    /**
     * The placeholder for every other value of the config that is set: of
     * a channel the installation does not have, no key is known to hold no
     * secret.
     */
    private function redactEveryOtherValue(): void
    {
        $handled = array_merge(self::secretKeys(), self::secretMaps());
        foreach (array_keys($this->config) as $key) {
            if (!in_array($key, $handled, true) && $this->holdsValue((string)$key)) {
                $this->config[$key] = SurveySecrets::PLACEHOLDER;
            }
        }
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
        $changed = $this->changedBinding((string)$place[0]);

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
     * The key that the secret key or map $key of the template's channel is
     * bound to (its definition's boundTo), when it did not keep its stored value; null when
     * it kept it, and for a key bound to none. A key keeps its stored value
     * when it is submitted as stored, and a key that is a secret itself
     * (the webhook's url) also when it is submitted as the placeholder,
     * which restore() refuses unless the stored value can stay.
     */
    private function changedBinding(string $key): ?string
    {
        $bound = ChannelRegistry::definitionOf((string)$this->template->channel)?->boundTo[$key] ?? null;
        if ($bound === null) {
            return null;
        }
        $value = $this->config[$bound] ?? null;
        $kept = $value === ($this->stored[$bound] ?? null)
            || ($value === SurveySecrets::PLACEHOLDER && in_array($bound, self::secretKeys(), true));

        return $kept ? null : $bound;
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
