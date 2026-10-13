<?php

declare(strict_types=1);

namespace app\services;

/**
 * A workflow launch refused for a reason the caller can fix, before the run
 * is written: extra vars that are not a JSON object or that JSON cannot hold,
 * the placeholder $encrypted$ sent as a password answer of a job step's
 * template, or an approval step or approval-gated job step whose rule leaves
 * too few approvers besides the user who launches.
 *
 * The message is meant for users, as with JobLaunchRejectedException: the
 * API and workflow triggers answer 422 with it, and the web launch flashes
 * it. It extends RuntimeException, so every caller that handles launch
 * failures handles it too.
 */
final class WorkflowLaunchRejectedException extends \RuntimeException
{
}
