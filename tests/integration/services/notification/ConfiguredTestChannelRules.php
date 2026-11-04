<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\services\notification\ChannelConfigRules;

/**
 * The config rules of ConfiguredTestChannel: an optional priority, low or
 * high.
 */
final class ConfiguredTestChannelRules implements ChannelConfigRules
{
    public const PRIORITY_REFUSED = 'priority must be low or high.';

    public static function refusals(#[\SensitiveParameter] array $config): array
    {
        $priority = $config['priority'] ?? null;

        return $priority === null || in_array($priority, ['low', 'high'], true) ? [] : [self::PRIORITY_REFUSED];
    }
}
