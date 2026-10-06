<?php

declare(strict_types=1);

namespace app\helpers;

use yii\helpers\Html;
use yii\helpers\Json;

/**
 * Builds onsubmit/onclick attribute values that ask for confirmation.
 *
 * The message is JSON-encoded for JavaScript (quotes, <, > and & become \u
 * escapes) and the result is HTML-encoded for the attribute, so a name inside
 * the message can leave neither the JavaScript string nor the attribute.
 * addslashes() and Html::encode() alone protect neither: the browser decodes
 * the attribute before JavaScript runs, and a backslash does not escape a
 * quote in HTML.
 */
final class ConfirmHelper
{
    public static function attribute(string $message): string
    {
        return Html::encode('return confirm(' . Json::htmlEncode($message) . ')');
    }
}
