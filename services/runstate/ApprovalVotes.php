<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\models\ApprovalDecision;
use app\models\User;
use yii\db\Query;

/**
 * The votes on approval requests: the votes the request page lists (every
 * vote, the launcher's too), in the order cast, with who cast it (user ID
 * and username), the decision, the comment as given (null without one) and
 * when. The votes of any number of requests are read in one query.
 *
 * A vote by the launcher of the request's job (for a workflow approval
 * step: the user who started the run) was cast before self-approval was
 * refused. It counts neither as an approval nor as a rejection: the counts
 * (ApprovalRequest::countedDecisionsOf()) and the decision notes of the run
 * page (ApprovalWaits) leave it out; the request page marks it.
 */
final class ApprovalVotes
{
    /**
     * The votes on each request of $requestIds, by request ID, in the order
     * cast; a request without votes is left out. Who may see them is up to
     * the caller (approval.view).
     *
     * @param list<int> $requestIds
     * @return array<int, list<array{user_id: int, username: string, decision: string, comment: string|null, created_at: int}>>
     */
    public static function of(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }
        /** @var list<array{request_id: int|string, user_id: int|string, username: string, decision: string, comment: string|null, created_at: int|string}> $rows */
        $rows = (new Query())
            ->select([
                'request_id' => 'd.approval_request_id',
                'user_id' => 'd.user_id',
                'username' => 'u.username',
                'decision' => 'd.decision',
                'comment' => 'd.comment',
                'created_at' => 'd.created_at',
            ])
            ->from(['d' => ApprovalDecision::tableName()])
            // The foreign key deletes a user's votes with the user: every vote has its voter.
            ->innerJoin(['u' => User::tableName()], '[[u.id]] = [[d.user_id]]')
            ->where(['d.approval_request_id' => $requestIds])
            ->orderBy(['d.id' => SORT_ASC])
            ->all();
        $votes = [];
        foreach ($rows as $row) {
            $votes[(int)$row['request_id']][] = [
                'user_id' => (int)$row['user_id'],
                'username' => (string)$row['username'],
                'decision' => (string)$row['decision'],
                'comment' => $row['comment'] === null ? null : (string)$row['comment'],
                'created_at' => (int)$row['created_at'],
            ];
        }

        return $votes;
    }
}
