<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApprovalRequest;
use app\models\ApprovalRequestSearch;
use app\tests\integration\DbTestCase;

/**
 * The status filter of the approval request lists (?status=): a valid status
 * filters, a missing or empty one lists every request, and an invalid one is
 * an error for the API (validate()) while the web list ignores it (apply()).
 */
class ApprovalRequestSearchTest extends DbTestCase
{
    /** @var array<string, int> request ID by status */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->createUser('search');
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
        $rule = $this->createApprovalRule((int)$user->id);
        foreach (ApprovalRequest::statuses() as $status) {
            $request = new ApprovalRequest();
            $request->job_id = $this->createJob((int)$template->id, (int)$user->id)->id;
            $request->approval_rule_id = $rule->id;
            $request->status = $status;
            $request->requested_at = time();
            $request->save(false);
            $this->requests[$status] = (int)$request->id;
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function statusProvider(): array
    {
        $cases = [];
        foreach (ApprovalRequest::statuses() as $status) {
            $cases[$status] = [$status];
        }

        return $cases;
    }

    /**
     * @dataProvider statusProvider
     */
    public function testAValidStatusListsTheRequestsOfThatStatusOnly(string $status): void
    {
        $search = $this->searchOf(['status' => $status]);

        $this->assertTrue($search->validate());
        $this->assertSame($status, $search->activeStatus());
        $this->assertSame([$this->requests[$status]], $this->listedIds($search));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function noFilterProvider(): array
    {
        return [
            'no status' => [[]],
            'an empty status' => [['status' => '']],
            'a null status' => [['status' => null]],
        ];
    }

    /**
     * @dataProvider noFilterProvider
     * @param array<string, mixed> $params
     */
    public function testAMissingOrEmptyStatusListsEveryRequest(array $params): void
    {
        $search = $this->searchOf($params);

        $this->assertTrue($search->validate());
        $this->assertNull($search->activeStatus());
        $this->assertSame(array_values($this->requests), $this->listedIds($search));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidProvider(): array
    {
        return [
            'an unknown status' => ['waiting'],
            'another case' => ['PENDING'],
            'a status with a space' => ['pending '],
            'a job status' => ['pending_approval'],
            'a list' => [['pending']],
            'a number' => [1],
        ];
    }

    /**
     * The API answers 422 with the first error; the web list ignores the
     * value and lists every request.
     *
     * @dataProvider invalidProvider
     */
    public function testAnInvalidStatusIsAnErrorAndFiltersNothing(mixed $status): void
    {
        $search = $this->searchOf(['status' => $status]);

        $this->assertFalse($search->validate());
        $this->assertSame(
            'status must be one of: pending, approved, rejected, timed_out, canceled.',
            $search->getFirstError('status')
        );
        $this->assertSame(ApprovalRequestSearch::statusError(), $search->getFirstError('status'));
        $this->assertNull($search->activeStatus());
        $this->assertSame(array_values($this->requests), $this->listedIds($search));
    }

    public function testTheErrorListsEveryValidStatus(): void
    {
        $this->assertSame(
            sprintf(ApprovalRequestSearch::INVALID_STATUS, implode(', ', ApprovalRequest::statuses())),
            ApprovalRequestSearch::statusError()
        );
    }

    public function testApplyReturnsTheQueryItFiltered(): void
    {
        $query = ApprovalRequest::find();

        $this->assertSame($query, $this->searchOf(['status' => ApprovalRequest::STATUS_PENDING])->apply($query));
        $this->assertSame($query, $this->searchOf([])->apply($query));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function searchOf(array $params): ApprovalRequestSearch
    {
        $search = new ApprovalRequestSearch();
        $search->load($params, '');

        return $search;
    }

    /**
     * The IDs of this test's requests that the filtered list holds, in ID order.
     *
     * @return list<int>
     */
    private function listedIds(ApprovalRequestSearch $search): array
    {
        $query = $search->apply(ApprovalRequest::find())
            ->andWhere(['approval_request.id' => array_values($this->requests)])
            ->orderBy(['approval_request.id' => SORT_ASC]);

        return array_map('intval', $query->select('approval_request.id')->column());
    }
}
