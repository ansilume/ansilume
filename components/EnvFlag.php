<?php

declare(strict_types=1);

namespace app\components;

/**
 * Reads an on/off setting from the environment, such as YII_DEBUG.
 *
 * On are 1, true, on and yes, in any case and with surrounding whitespace.
 * Everything else is off: false, 0, off, no, an empty or missing value, and
 * any value it does not recognize, so a typo never turns debug mode on.
 * (A plain (bool) cast is wrong here: (bool)'false' is true in PHP.)
 *
 * Plain PHP without Yii: yii and web/index.php call it before Yii is loaded,
 * right after the Composer autoloader.
 */
final class EnvFlag
{
    public static function isOn(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }
}
