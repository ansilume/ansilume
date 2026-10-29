<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApprovalRule;

/**
 * The fixtures of the E2E specs for approval rules whose approver config
 * names its approvers by a JSON number beyond the int range
 * (tests/e2e/tests/approval-rules/crud.spec.ts and rbac.spec.ts). The rule
 * form and the API store such a config: ApprovalRule::validateJson() takes
 * any JSON object. On PHP 8.5 the rule's page and its edit form answered 500
 * for it, as their (int) casts of the number warned; such a number names
 * nobody now (app\components\ApproverConfigIds). So the users rule, whose
 * only user is such a number, requires more approvals than its users: the
 * rule form and the API refuse it (ApprovalRule::countEligibleApprovers()),
 * where 2.13.1 and older stored it, and the seeder writes it as they left
 * it, without the rules.
 *
 * - e2e-team-beyond-int-rule: a team rule, {"team_id": 1e30}.
 * - e2e-users-beyond-int-rule: a users rule, {"user_ids": [1e30]}.
 *
 * Neither gates anything. Both are restored by name on every seed, as
 * e2e-admin's, with the config text as a client sends it.
 */
final class E2eApproverConfigSeeder
{
    public const TEAM_RULE = 'e2e-team-beyond-int-rule';

    public const USERS_RULE = 'e2e-users-beyond-int-rule';

    public const TEAM_CONFIG = '{"team_id": 1e30}';

    public const USERS_CONFIG = '{"user_ids": [1e30]}';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore both rules, which $userId (e2e-admin) created.
     */
    public function seed(int $userId): void
    {
        $team = $this->restore(self::TEAM_RULE, ApprovalRule::APPROVER_TYPE_TEAM, self::TEAM_CONFIG, $userId);
        $users = $this->restore(self::USERS_RULE, ApprovalRule::APPROVER_TYPE_USERS, self::USERS_CONFIG, $userId);

        ($this->logger)(sprintf(
            "  Restored approver config fixtures: %s (ID %d) and %s (ID %d), whose approvers are numbers beyond the int range.\n",
            self::TEAM_RULE,
            $team->id,
            self::USERS_RULE,
            $users->id
        ));
    }

    private function restore(string $name, string $type, string $config, int $userId): ApprovalRule
    {
        $rule = ApprovalRule::findOne(['name' => $name]) ?? new ApprovalRule();
        $rule->name = $name;
        $rule->description = 'E2E fixture: its approver config names its approvers by a number beyond the int range.';
        $rule->approver_type = $type;
        $rule->approver_config = $config;
        $rule->required_approvals = 1;
        $rule->timeout_minutes = null;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $userId;
        // Without the rules: a rule an earlier version stored keeps its config too.
        $rule->save(false);

        return $rule;
    }
}
