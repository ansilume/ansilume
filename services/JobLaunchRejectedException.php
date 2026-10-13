<?php

declare(strict_types=1);

namespace app\services;

/**
 * A launch refused for a reason the caller can fix. Nothing was written.
 * The reasons:
 * - a survey field name that cannot carry a password answer, or the
 *   placeholder $encrypted$ sent as a password answer;
 * - too few eligible approvers besides the launcher for the template's
 *   approval rule (ApproverEligibility::REFUSAL_JOB);
 * - the template's approval rule no longer exists
 *   (JobLaunchService::RULE_MISSING);
 * - the template was deleted, or its approval rule changed, since the
 *   caller loaded it (JobLaunchService::TEMPLATE_DELETED, TEMPLATE_CHANGED).
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
