<?php

declare(strict_types=1);

namespace app\components;

/**
 * The IDs that an approval rule's approver config names
 * (ApprovalRule::getParsedConfig()): the team of a team rule (team_id) and
 * the users of a users rule (user_ids), read as the integer rule reads an
 * ID (SubmittedId: "5", "05" and 5.0 are 5), for the rule's approvers and
 * its pages alike.
 *
 * The config is any JSON object that the rule form or the API stored, so a
 * value can be a JSON number beyond the int range (1e30, 1e999), a
 * fraction, text or a list. Since PHP 8.5 an (int) cast of such a float
 * warns, and the error handler turns the warning into an exception: the
 * rule's page, its edit form and every launch the rule gates answered 500.
 * Such a value names nobody, so a rule without other approvers refuses the
 * launches it gates, as one without approvers always did (fail closed).
 */
final class ApproverConfigIds
{
    /**
     * The team that team_id of $config names; null for none, also for a
     * value that is no ID.
     *
     * @param array<string, mixed> $config the parsed approver config
     */
    public static function teamOf(array $config): ?int
    {
        return SubmittedId::of($config['team_id'] ?? null);
    }

    /**
     * The users that the list user_ids of $config names, in its order; a
     * value that is no ID names no user, and user_ids that is no list none.
     *
     * @param array<string, mixed> $config the parsed approver config
     * @return list<int>
     */
    public static function usersOf(array $config): array
    {
        $listed = $config['user_ids'] ?? null;
        if (!is_array($listed)) {
            return [];
        }
        $ids = [];
        foreach ($listed as $value) {
            $id = SubmittedId::of($value);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
