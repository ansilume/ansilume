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
        $this->assertSame('canceled', ApprovalRequest::STATUS_CANCELED);
    }

    public function testStatusesReturnsAll(): void
    {
        $statuses = ApprovalRequest::statuses();
        $this->assertContains('pending', $statuses);
        $this->assertContains('approved', $statuses);
        $this->assertContains('rejected', $statuses);
        $this->assertContains('timed_out', $statuses);
        $this->assertContains('canceled', $statuses);
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

    public function testIsResolvedForCanceled(): void
    {
        $request = $this->makeRequest('canceled');
        $this->assertTrue($request->isResolved());
    }

    /**
     * A request expires at expires_at, not a second before; one without
     * expires_at never does.
     */
    public function testHasExpiredAtItsExpiresAt(): void
    {
        $request = $this->makeRequest('pending');
        $this->assertFalse($request->hasExpired(PHP_INT_MAX), 'no expires_at: never');

        $request->expires_at = 1000;
        $this->assertFalse($request->hasExpired(999));
        $this->assertTrue($request->hasExpired(1000));
        $this->assertTrue($request->hasExpired(1001));
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function statusLabelData(): array
    {
        return [
            [ApprovalRequest::STATUS_PENDING, 'Pending'],
            [ApprovalRequest::STATUS_APPROVED, 'Approved'],
            [ApprovalRequest::STATUS_REJECTED, 'Rejected'],
            [ApprovalRequest::STATUS_TIMED_OUT, 'Timed Out'],
            [ApprovalRequest::STATUS_CANCELED, 'Canceled'],
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
            [ApprovalRequest::STATUS_CANCELED, 'secondary'],
        ];
    }

    /** @dataProvider statusCssClassData */
    public function testStatusCssClassMapsToExpectedBadge(string $status, string $expected): void
    {
        $this->assertSame($expected, ApprovalRequest::statusCssClass($status));
    }

    /**
     * regression: a canceled approval request's badge was nearly invisible
     * on the dark theme. The layout sets data-bs-theme="dark", whose page
     * background is the colour of text-bg-dark, so no status may use the
     * css "dark" (nor "light", its counterpart on a light theme).
     */
    public function testNoStatusBadgeUsesAColourTheThemeHides(): void
    {
        $this->assertSame('secondary', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_CANCELED));
        foreach (ApprovalRequest::statuses() as $status) {
            $this->assertNotContains(
                ApprovalRequest::statusCssClass($status),
                ['dark', 'light'],
                "the badge of a {$status} request"
            );
        }
    }

    public function testStatusCssClassFallsBackToSecondaryForUnknownStatus(): void
    {
        $this->assertSame('secondary', ApprovalRequest::statusCssClass('weird'));
    }

    private function makeRequest(string $status): ApprovalRequest
    {
        $request = $this->createPartialMock(ApprovalRequest::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setValue($request, ['status' => $status]);
        return $request;
    }
}
