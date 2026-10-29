<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\models\User;

/**
 * The user_ids of a users rule that name one user who exists, as the
 * rule's approvers read them (ApproverConfigIds::usersOf()), next to an
 * entry that adds no approver: a value that is no ID, that user again, or
 * a user who does not exist. Use in DbTestCase subclasses.
 *
 * Regression: ApprovalRule::countEligibleApprovers() counted every entry
 * of user_ids, so a rule with such a list and 2 required approvals was
 * saved, and every launch it gates was then refused as one that could
 * never be approved.
 */
trait ApproverListFixtures
{
    /**
     * The lists, as JSON text: {user} stands for the ID of the user who
     * exists, {deleted} for the ID of a user who was deleted, and {unknown}
     * for an ID far above every user's, which no user created later in the
     * test gets.
     *
     * @return array<string, array{0: string}>
     */
    public static function oneUserListedProvider(): array
    {
        return [
            'text' => ['[{user}, "abc"]'],
            'true' => ['[{user}, true]'],
            'a fraction' => ['[{user}, 12.5]'],
            'the user twice' => ['[{user}, {user}]'],
            'the user twice, once with a leading zero' => ['[{user}, "0{user}"]'],
            'a deleted user' => ['[{user}, {deleted}]'],
            'an ID no user has' => ['[{user}, {unknown}]'],
            'user 0, which no user is' => ['[{user}, 0]'],
            'a negative ID' => ['[{user}, -1]'],
        ];
    }

    /**
     * The approver config of a users rule whose user_ids is $list of
     * oneUserListedProvider(), with a user created for {user}.
     */
    protected function oneUserConfig(string $list): string
    {
        $deleted = $this->createUser('rule-count-deleted');
        $deletedId = (int)$deleted->id;
        $this->assertSame(1, $deleted->delete(), 'the user is deleted');
        $listed = $this->createUser('rule-count-listed');
        $unknown = (int)User::find()->max('id') + 1000000;

        return '{"user_ids": ' . strtr($list, [
            '{user}' => (string)$listed->id,
            '{deleted}' => (string)$deletedId,
            '{unknown}' => (string)$unknown,
        ]) . '}';
    }
}
