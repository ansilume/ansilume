<?php

declare(strict_types=1);

namespace app\components;

use yii\web\Session;

/**
 * The flashes the layout prints: alerts of the types success, info, warning
 * and danger, which Bootstrap styles, each as
 * <div class="alert alert-<type>">.
 *
 * Every other flash is left out. That covers the data flashes, which carry
 * a secret to the one page that shows it in a card of its own (a new
 * trigger token, the token of a new runner, a new API token): that page
 * reads its flash with getFlash($key, null, true), and even a page that did
 * not cannot make the layout print the secret.
 *
 * Regression: the layout printed every flash that was left, and the runner
 * group page and the profile's API tokens page read their token's flash
 * without deleting it: the raw runner token and the raw API token showed a
 * second time on the page, in an unstyled alert.
 */
final class FlashAlerts
{
    /** The flash types the layout prints. */
    public const TYPES = ['success', 'info', 'warning', 'danger'];

    /**
     * The alerts left in $session, by type in the order they were set, each
     * with its messages as a list. Like the layout before, this marks every
     * flash for deletion with the next request (getAllFlashes()).
     *
     * @return array<string, array<mixed>>
     */
    public static function of(?Session $session): array
    {
        $alerts = [];
        foreach ($session?->getAllFlashes() ?? [] as $type => $messages) {
            if (in_array($type, self::TYPES, true)) {
                $alerts[$type] = (array)$messages;
            }
        }

        return $alerts;
    }
}
