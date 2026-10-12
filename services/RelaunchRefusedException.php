<?php

declare(strict_types=1);

namespace app\services;

/**
 * A job that cannot be re-launched. The message is meant for the user who
 * asked for it: it says why, and what to do instead.
 */
final class RelaunchRefusedException extends \RuntimeException
{
}
