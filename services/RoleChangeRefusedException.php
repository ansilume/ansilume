<?php

declare(strict_types=1);

namespace app\services;

/**
 * A role not created, changed or deleted because the user may not
 * (RoleChangePolicy), with nothing written. The message is meant for users:
 * RoleChangePolicy::CHANGE_REFUSED, or PERMISSION_REFUSED with the
 * permission's name. The web shows a refused permission as an error of the
 * Permissions field; the API answers 403 with the message.
 */
final class RoleChangeRefusedException extends \RuntimeException
{
}
