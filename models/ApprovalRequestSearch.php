<?php

declare(strict_types=1);

namespace app\models;

use yii\base\Model;
use yii\db\ActiveQuery;

/**
 * The status filter of the approval request lists: ?status= on the web list
 * (/approval/index) and on the API (GET /api/v1/approvals).
 *
 * A status is one of ApprovalRequest::statuses(), compared exactly (lower
 * case, as stored). A missing or empty status lists every request. Load the
 * query parameters with load($params, ''). The web list ignores an invalid
 * value and lists every request, so an old or mistyped link still opens:
 * apply() filters only by a valid status. The API calls validate() first and
 * answers 422 with the first error (INVALID_STATUS), so a typo in a script
 * never passes for "no filter".
 */
class ApprovalRequestSearch extends Model
{
    /** sprintf(): the valid statuses, joined with ', ' (statusError()). */
    public const INVALID_STATUS = 'status must be one of: %s.';

    /** The requested status as sent (?status=); null or '' lists every request. */
    public mixed $status = null;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    public function rules(): array
    {
        return [
            [['status'], 'in', 'range' => ApprovalRequest::statuses(), 'strict' => true, 'message' => self::statusError()],
        ];
    }

    /**
     * The status the list is filtered by: the requested status when it is
     * valid, else null (every request), also for an invalid one.
     */
    public function activeStatus(): ?string
    {
        $status = $this->status;

        return is_string($status) && in_array($status, ApprovalRequest::statuses(), true) ? $status : null;
    }

    /**
     * $query (of ApprovalRequest::find(), without a table alias), filtered by
     * activeStatus(): unchanged when the requested status is missing, empty
     * or invalid.
     *
     * @param ActiveQuery<ApprovalRequest> $query
     * @return ActiveQuery<ApprovalRequest>
     */
    public function apply(ActiveQuery $query): ActiveQuery
    {
        $status = $this->activeStatus();
        if ($status !== null) {
            $query->andWhere(['approval_request.status' => $status]);
        }

        return $query;
    }

    /**
     * INVALID_STATUS with the valid statuses, the error of an invalid one:
     * "status must be one of: pending, approved, rejected, timed_out, canceled."
     */
    public static function statusError(): string
    {
        return sprintf(self::INVALID_STATUS, implode(', ', ApprovalRequest::statuses()));
    }
}
