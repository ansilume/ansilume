<?php

declare(strict_types=1);

namespace app\services;

use app\models\TeamMember;
use yii\db\Connection;
use yii\db\Query;
use yii\rbac\DbManager;

/**
 * The approver counts of the approval rule form: how many of the approvers
 * that a rule of each role or team would have may decide, and which of the
 * users the form offers may. A role rule's approvers are the users assigned
 * the role directly, a team rule's the team's members, a users rule's the
 * users listed (ApprovalRule::getApproverUserIds()). Of them those may
 * decide who have an active account and are a superadmin or hold
 * approval.decide (ApproverFilter): the set ApproverEligibility finds for a
 * launch of a job template whose project no team restricts, by a user who
 * is no approver.
 *
 * The form knows neither who will launch nor what the rule will gate. At a
 * launch the launcher never approves, and under team scoping only the
 * approvers who may see the job template's project, or the workflow, count
 * (ApproverEligibility): a count is the most a launch can find, and a
 * launch whose approval could never be granted is still refused then.
 *
 * The cost does not grow with the roles, teams and users: their direct
 * role assignments (one query), the teams' members (one query) and one
 * ApproverFilter::mayDecide() for all of them. Without any, nothing is read.
 */
final class ApproverCounts
{
    public function __construct(private readonly DbManager $auth)
    {
    }

    /**
     * What the rule form's script compares with the required approvals:
     * - roles: every role of $roles, by name, with the number of users
     *   assigned it directly who may decide, 0 included;
     * - teams: every team of $teams, by ID, with the number of its members
     *   who may decide, 0 included;
     * - users: the users of $users who may decide, each once, in the order
     *   given.
     *
     * @param list<string> $roles role names
     * @param list<int> $teams team IDs
     * @param list<int> $users user IDs
     * @return array{roles: array<string, int>, teams: array<string, int>, users: list<int>}
     */
    public function forRuleForm(array $roles, array $teams, array $users): array
    {
        $roleHolders = $this->roleHolders($roles);
        $teamMembers = self::teamMembers($teams);
        $deciders = $this->deciders(array_merge($users, ...array_values($roleHolders), ...array_values($teamMembers)));

        return [
            'roles' => self::counted($roleHolders, $deciders),
            'teams' => self::counted($teamMembers, $deciders),
            'users' => array_values(array_filter(
                array_values(array_unique($users)),
                static fn (int $userId): bool => isset($deciders[$userId])
            )),
        ];
    }

    /**
     * The users assigned each role of $roles directly, by role name, as a
     * role rule names its approvers (ApprovalRule::getApproverUserIds(), by
     * DbManager::getUserIdsByRole()): one query for all roles. Every role is
     * a key, with [] when nobody holds it directly.
     *
     * @param list<string> $roles
     * @return array<string, list<int>>
     */
    private function roleHolders(array $roles): array
    {
        $holders = [];
        foreach ($roles as $role) {
            $holders[$role] = [];
        }
        if ($holders === []) {
            return [];
        }
        /** @var Connection $db DbManager::init() turns the configured connection into an object */
        $db = $this->auth->db;
        /** @var list<array{item_name: string, user_id: string}> $rows */
        $rows = (new Query())
            ->select(['item_name', 'user_id'])
            ->from($this->auth->assignmentTable)
            ->where(['item_name' => array_map('strval', array_keys($holders))])
            ->all($db);
        foreach ($rows as $row) {
            $holders[$row['item_name']][] = (int)$row['user_id'];
        }

        return $holders;
    }

    /**
     * The members of each team of $teams, by team ID, as a team rule names
     * its approvers: one query for all teams. Every team is a key, with []
     * for a team without members.
     *
     * @param list<int> $teams
     * @return array<int, list<int>>
     */
    private static function teamMembers(array $teams): array
    {
        $members = [];
        foreach ($teams as $teamId) {
            $members[$teamId] = [];
        }
        if ($members === []) {
            return [];
        }
        /** @var list<array{team_id: int|string, user_id: int|string}> $rows */
        $rows = (new Query())
            ->select(['team_id', 'user_id'])
            ->from(TeamMember::tableName())
            ->where(['team_id' => array_keys($members)])
            ->all();
        foreach ($rows as $row) {
            $members[(int)$row['team_id']][] = (int)$row['user_id'];
        }

        return $members;
    }

    /**
     * The users of $candidates who may decide, as keys: an active account,
     * and a superadmin or holding approval.decide. What the rule will gate
     * is unknown here, so everyone counts as allowed to see it, as for a job
     * template whose project no team restricts. No query without candidates.
     *
     * @param list<int> $candidates
     * @return array<int, true>
     */
    private function deciders(array $candidates): array
    {
        $filter = new ApproverFilter(new PermissionHolders($this->auth));
        $deciders = $filter->mayDecide(
            array_values(array_unique($candidates)),
            static fn (): ApproverAudience => ApproverAudience::ofProjects([])
        );

        return array_fill_keys($deciders, true);
    }

    /**
     * For each key of $approvers, how many of its approvers are $deciders,
     * each user once.
     *
     * @param array<int|string, list<int>> $approvers
     * @param array<int, true> $deciders
     * @return array<string, int>
     */
    private static function counted(array $approvers, array $deciders): array
    {
        $counts = [];
        foreach ($approvers as $key => $userIds) {
            $counts[(string)$key] = count(array_filter(
                array_unique($userIds),
                static fn (int $userId): bool => isset($deciders[$userId])
            ));
        }

        return $counts;
    }
}
