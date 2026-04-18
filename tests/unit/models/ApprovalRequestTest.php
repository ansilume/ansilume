<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\ApprovalRequest;
use PHPUnit\Framework\TestCase;

class ApprovalRequestTest extends TestCase
{
    public function testStatusConstants(): void
    {
        $this->assertSame('pending', ApprovalRequest::STATUS_PENDING);
        $this->assertSame('approved', ApprovalRequest::STATUS_APPROVED);
        $this->assertSame('rejected', ApprovalRequest::STATUS_REJECTED);
        $this->assertSame('timed_out', ApprovalRequest::STATUS_TIMED_OUT);
    }

    public function testStatusesReturnsAll(): void
    {
        $statuses = ApprovalRequest::statuses();
        $this->assertContains('pending', $statuses);
        $this->assertContains('approved', $statuses);
        $this->assertContains('rejected', $statuses);
        $this->assertContains('timed_out', $statuses);
    }

    public function testIsResolvedForPending(): void
    {
        $request = $this->makeRequest('pending');
        $this->assertFalse($request->isResolved());
    }

    public function testIsResolvedForApproved(): void
    {
        $request = $this->makeRequest('approved');
        $this->assertTrue($request->isResolved());
    }

    public function testIsResolvedForRejected(): void
    {
        $request = $this->makeRequest('rejected');
        $this->assertTrue($request->isResolved());
    }

    public function testIsResolvedForTimedOut(): void
    {
        $request = $this->makeRequest('timed_out');
        $this->assertTrue($request->isResolved());
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function statusLabelData(): array
    {
        return [
            [ApprovalRequest::STATUS_PENDING, 'Pending'],
            [ApprovalRequest::STATUS_APPROVED, 'Approved'],
            [ApprovalRequest::STATUS_REJECTED, 'Rejected'],
            [ApprovalRequest::STATUS_TIMED_OUT, 'Timed Out'],
        ];
    }

    /** @dataProvider statusLabelData */
    public function testStatusLabelMapsToHumanText(string $status, string $expected): void
    {
        $this->assertSame($expected, ApprovalRequest::statusLabel($status));
    }

    public function testStatusLabelFallsBackToInputForUnknownStatus(): void
    {
        $this->assertSame('weird', ApprovalRequest::statusLabel('weird'));
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function statusCssClassData(): array
    {
        return [
            [ApprovalRequest::STATUS_PENDING, 'warning'],
            [ApprovalRequest::STATUS_APPROVED, 'success'],
            [ApprovalRequest::STATUS_REJECTED, 'danger'],
            [ApprovalRequest::STATUS_TIMED_OUT, 'secondary'],
        ];
    }

    /** @dataProvider statusCssClassData */
    public function testStatusCssClassMapsToExpectedBadge(string $status, string $expected): void
    {
        $this->assertSame($expected, ApprovalRequest::statusCssClass($status));
    }

    public function testStatusCssClassFallsBackToSecondaryForUnknownStatus(): void
    {
        $this->assertSame('secondary', ApprovalRequest::statusCssClass('weird'));
    }

    private function makeRequest(string $status): ApprovalRequest
    {
        $request = $this->createPartialMock(ApprovalRequest::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($request, ['status' => $status]);
        return $request;
    }
}
