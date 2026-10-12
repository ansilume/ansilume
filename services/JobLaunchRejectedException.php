<?php

declare(strict_types=1);

namespace app\services;

/**
 * A launch refused for a reason the caller can fix, such as a survey field
 * name that cannot carry a password answer, or the placeholder $encrypted$
 * sent as a password answer. Nothing was written.
 *
 * The message is meant for users: names in it are quoted with json_encode,
 * and HTML escaping is left to whoever renders it. The API and job triggers
 * answer 422 with it, the web launch flashes it, a workflow job step and a
 * schedule record it as the failure reason. It extends RuntimeException, so
 * every caller that handles launch failures handles it too.
 */
final class JobLaunchRejectedException extends \RuntimeException
{
}
