<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\NotificationConfigUrls;
use yii\base\InvalidConfigException;

/**
 * A channel of notification templates (spec section 6), as the template's
 * rules (refusals()), its secrets (NotificationConfigSecrets), whom it
 * delivers as (NotificationIdentity) and the dispatcher (handler()) read
 * it: the built-in channels (ChannelRegistry::BUILT_IN) and those an
 * installation configures (config/notification-channels.php) alike, each
 * made from an array of these settings (of()):
 *
 * - label: what the form and the pages call it;
 * - class: its handler, a class that implements ChannelInterface;
 * - requiredKeys: the keys its config must hold a value at;
 * - secretKeys: the keys whose values are secrets, and secretMaps: the keys
 *   whose every value is one (the names are shown). The pages and the API
 *   show them only as SurveySecrets::PLACEHOLDER, in every config;
 * - boundTo: a secret key or map => the key of the config whose stored
 *   value it goes along with: the placeholder keeps the stored secret only
 *   while that key keeps its own (NotificationConfigSecrets);
 * - urlSchemes: a key that holds a URL the channel sends to => the schemes
 *   it may use, http, https or both (NotificationConfigUrls), as the
 *   outbound HTTP client sends to no other;
 * - destinationKeys: the keys that say where it delivers, a change of which
 *   makes the editor the user the template delivers as; not set (null):
 *   every key;
 * - configRules: a class that implements ChannelConfigRules, for the rules
 *   the other settings cannot say; not set (null): none.
 *
 * A setting left out is empty. of() refuses a definition it cannot take
 * with InvalidConfigException, whose message names the channel and what is
 * wrong: the application does not start with it.
 */
final class ChannelDefinition
{
    /** The settings of a definition. */
    public const SETTINGS = ['label', 'class', 'requiredKeys', 'secretKeys', 'secretMaps', 'boundTo', 'urlSchemes', 'destinationKeys', 'configRules'];

    /** The schemes that a URL of a config may use. */
    public const SCHEMES = ['http', 'https'];

    /** A name of another form; sprintf(): the name. */
    public const NAME_FORM = 'Notification channel "%s": a channel name is a lower-case letter, then up to 31 lower-case letters, digits, - or _.';

    /** A definition that is no array; sprintf(): the channel. */
    public const NOT_A_DEFINITION = 'Notification channel "%s": its definition must be an array of settings.';

    /** A key of a definition that is no setting; sprintf(): the channel, the key. */
    public const UNKNOWN_SETTING = 'Notification channel "%s": %s is no setting of a channel; its settings are label, class, requiredKeys, '
        . 'secretKeys, secretMaps, boundTo, urlSchemes, destinationKeys and configRules.';

    /** A definition without a label; sprintf(): the channel. */
    public const LABEL_MISSING = 'Notification channel "%s" needs a label.';

    /** A definition without a class; sprintf(): the channel. */
    public const CLASS_MISSING = 'Notification channel "%s" needs a class.';

    /** A class that does not exist; sprintf(): the channel, the class. */
    public const UNKNOWN_CLASS = 'Notification channel "%s": its class %s does not exist.';

    /** A class that is no channel; sprintf(): the channel, the class. */
    public const NOT_A_CHANNEL = 'Notification channel "%s": its class %s does not implement app\services\notification\ChannelInterface.';

    /** A setting of keys that is no list of keys; sprintf(): the channel, the setting. */
    public const KEYS_FORM = 'Notification channel "%s": %s must be a list of config keys.';

    /** A boundTo of another form; sprintf(): the channel. */
    public const BOUND_TO_FORM = 'Notification channel "%s": boundTo must map each of its secret keys and maps to another key of its config.';

    /** A urlSchemes of another form; sprintf(): the channel. */
    public const URL_SCHEMES_FORM = 'Notification channel "%s": urlSchemes must map config keys to http, https or both.';

    /** A configRules that names no rules; sprintf(): the channel. */
    public const RULES_FORM = 'Notification channel "%s": configRules must name a class that implements app\services\notification\ChannelConfigRules.';

    /** A required key of a config without a value; sprintf(): the key, the channel's label. */
    public const REQUIRED = '%s is required for %s.';

    private const NAME = '/^[a-z][a-z0-9_-]{0,31}$/';

    public readonly string $name;

    public readonly string $label;

    /** @var class-string<ChannelInterface> */
    public readonly string $class;

    /** @var list<string> */
    public readonly array $requiredKeys;

    /** @var list<string> */
    public readonly array $secretKeys;

    /** @var list<string> */
    public readonly array $secretMaps;

    /** @var array<string, string> */
    public readonly array $boundTo;

    /** @var array<string, list<string>> */
    public readonly array $urlSchemes;

    /** @var list<string>|null */
    public readonly ?array $destinationKeys;

    /** @var class-string<ChannelConfigRules>|null */
    public readonly ?string $configRules;

    /**
     * @param array{label: string, class: class-string<ChannelInterface>, requiredKeys: list<string>, secretKeys: list<string>, secretMaps: list<string>, boundTo: array<string, string>, urlSchemes: array<string, list<string>>, destinationKeys: list<string>|null, configRules: class-string<ChannelConfigRules>|null} $settings
     */
    private function __construct(string $name, array $settings)
    {
        $this->name = $name;
        $this->label = $settings['label'];
        $this->class = $settings['class'];
        $this->requiredKeys = $settings['requiredKeys'];
        $this->secretKeys = $settings['secretKeys'];
        $this->secretMaps = $settings['secretMaps'];
        $this->boundTo = $settings['boundTo'];
        $this->urlSchemes = $settings['urlSchemes'];
        $this->destinationKeys = $settings['destinationKeys'];
        $this->configRules = $settings['configRules'];
    }

    /**
     * The definition of channel $name from $definition, its settings (see
     * the class).
     *
     * @throws InvalidConfigException when it cannot be taken, with what is wrong
     */
    public static function of(int|string $name, mixed $definition): self
    {
        $name = (string)$name;
        if (preg_match(self::NAME, $name) !== 1) {
            throw self::refused(self::NAME_FORM, $name);
        }
        if (!is_array($definition)) {
            throw self::refused(self::NOT_A_DEFINITION, $name);
        }
        foreach (array_keys($definition) as $setting) {
            if (!in_array($setting, self::SETTINGS, true)) {
                throw self::refused(self::UNKNOWN_SETTING, $name, (string)$setting);
            }
        }
        $secretKeys = self::keys($name, $definition, 'secretKeys');
        $secretMaps = self::keys($name, $definition, 'secretMaps');

        return new self($name, [
            'label' => self::label($name, $definition),
            'class' => self::channelClass($name, $definition),
            'requiredKeys' => self::keys($name, $definition, 'requiredKeys'),
            'secretKeys' => $secretKeys,
            'secretMaps' => $secretMaps,
            'boundTo' => self::boundTo($name, $definition, [...$secretKeys, ...$secretMaps]),
            'urlSchemes' => self::urlSchemes($name, $definition),
            'destinationKeys' => ($definition['destinationKeys'] ?? null) === null ? null : self::keys($name, $definition, 'destinationKeys'),
            'configRules' => self::configRules($name, $definition),
        ]);
    }

    /**
     * What is wrong with $config, the config of a template of this channel:
     * a required key without a value (REQUIRED), a URL of a scheme its key
     * does not allow (NotificationConfigUrls), and what configRules says;
     * [] for nothing.
     *
     * @param array<int|string, mixed> $config
     * @return list<string>
     */
    public function refusals(#[\SensitiveParameter] array $config): array
    {
        $errors = [];
        foreach ($this->requiredKeys as $key) {
            if (empty($config[$key])) {
                $errors[] = sprintf(self::REQUIRED, $key, $this->label);
            }
        }
        $rules = $this->configRules === null ? [] : $this->configRules::refusals($config);

        return array_merge($errors, NotificationConfigUrls::refusals($config, $this->urlSchemes), $rules);
    }

    /**
     * A new handler of the channel, its class made by Yii's container, so
     * that the container's definitions can set its properties.
     *
     * @throws InvalidConfigException when the container makes no channel of it
     */
    public function handler(): ChannelInterface
    {
        $handler = \Yii::createObject($this->class);
        if (!$handler instanceof ChannelInterface) {
            throw self::refused(self::NOT_A_CHANNEL, $this->name, $this->class);
        }

        return $handler;
    }

    /**
     * @param array<int|string, mixed> $definition
     */
    private static function label(string $name, array $definition): string
    {
        $label = $definition['label'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            throw self::refused(self::LABEL_MISSING, $name);
        }

        return $label;
    }

    /**
     * @param array<int|string, mixed> $definition
     * @return class-string<ChannelInterface>
     */
    private static function channelClass(string $name, array $definition): string
    {
        $class = $definition['class'] ?? null;
        if (!is_string($class) || $class === '') {
            throw self::refused(self::CLASS_MISSING, $name);
        }
        if (!class_exists($class)) {
            throw self::refused(self::UNKNOWN_CLASS, $name, $class);
        }
        if (!is_subclass_of($class, ChannelInterface::class)) {
            throw self::refused(self::NOT_A_CHANNEL, $name, $class);
        }

        return $class;
    }

    /**
     * The keys of the setting $setting: a list of keys that are text; [] for
     * a setting not set.
     *
     * @param array<int|string, mixed> $definition
     * @return list<string>
     */
    private static function keys(string $name, array $definition, string $setting): array
    {
        $keys = $definition[$setting] ?? [];
        if (!is_array($keys) || !array_is_list($keys)) {
            throw self::refused(self::KEYS_FORM, $name, $setting);
        }
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw self::refused(self::KEYS_FORM, $name, $setting);
            }
        }

        /** @var list<string> $keys */
        return $keys;
    }

    /**
     * @param array<int|string, mixed> $definition
     * @param list<string> $secrets the secret keys and maps
     * @return array<string, string>
     */
    private static function boundTo(string $name, array $definition, array $secrets): array
    {
        $boundTo = $definition['boundTo'] ?? [];
        if (!is_array($boundTo)) {
            throw self::refused(self::BOUND_TO_FORM, $name);
        }
        foreach ($boundTo as $secret => $key) {
            if (!in_array($secret, $secrets, true) || !is_string($key) || $key === '' || $key === $secret) {
                throw self::refused(self::BOUND_TO_FORM, $name);
            }
        }

        /** @var array<string, string> $boundTo */
        return $boundTo;
    }

    /**
     * @param array<int|string, mixed> $definition
     * @return array<string, list<string>>
     */
    private static function urlSchemes(string $name, array $definition): array
    {
        $urlSchemes = $definition['urlSchemes'] ?? [];
        if (!is_array($urlSchemes)) {
            throw self::refused(self::URL_SCHEMES_FORM, $name);
        }
        foreach ($urlSchemes as $key => $schemes) {
            if (!is_string($key) || !self::isSchemes($schemes)) {
                throw self::refused(self::URL_SCHEMES_FORM, $name);
            }
        }

        /** @var array<string, list<string>> $urlSchemes */
        return $urlSchemes;
    }

    /** Whether $schemes is a list of http, https or both. */
    private static function isSchemes(mixed $schemes): bool
    {
        if (!is_array($schemes) || $schemes === [] || !array_is_list($schemes)) {
            return false;
        }
        foreach ($schemes as $scheme) {
            if (!in_array($scheme, self::SCHEMES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $definition
     * @return class-string<ChannelConfigRules>|null
     */
    private static function configRules(string $name, array $definition): ?string
    {
        $rules = $definition['configRules'] ?? null;
        if ($rules === null) {
            return null;
        }
        if (!is_string($rules) || !is_subclass_of($rules, ChannelConfigRules::class)) {
            throw self::refused(self::RULES_FORM, $name);
        }

        return $rules;
    }

    private static function refused(string $format, string ...$values): InvalidConfigException
    {
        return new InvalidConfigException(sprintf($format, ...$values));
    }
}
