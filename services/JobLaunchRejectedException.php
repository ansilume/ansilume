<?php

declare(strict_types=1);

namespace app\services;

/**
 * A launch refused for a reason the caller can fix. Nothing was written.
 * The reasons:
 * - a survey field name that cannot carry a password answer, or the
 *   placeholder $encrypted$ sent as a password answer;
 * - the template and its inventory have different approval rules, and a
 *   launch waits for one rule only (JobLaunchService::RULES_DIFFER);
 * - too few eligible approvers besides the launcher for the approval rule
 *   the launch waits for, the template's own or its inventory's, among
 *   those who may see the template's project (ApproverEligibility::REFUSAL_JOB);
 * - that approval rule no longer exists (JobLaunchService::RULE_MISSING
 *   for the template's own rule, INVENTORY_RULE_MISSING for its
 *   inventory's);
 * - the template was deleted, or its approval rule changed, since the
 *   caller loaded it (JobLaunchService::TEMPLATE_DELETED, TEMPLATE_CHANGED);
 * - the template uses another inventory since the caller loaded it, the
 *   approval rule of its inventory changed meanwhile, or its inventory no
 *   longer exists (JobLaunchService::TEMPLATE_INVENTORY_CHANGED,
 *   INVENTORY_CHANGED, INVENTORY_MISSING).
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
