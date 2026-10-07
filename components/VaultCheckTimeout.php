<?php

declare(strict_types=1);

namespace app\components;

/**
 * A run of vault checks used up its time budget (VaultCheckRun). The check
 * that hits it is stored as incomplete; nothing is lost but the check itself.
 */
final class VaultCheckTimeout extends \RuntimeException
{
}
