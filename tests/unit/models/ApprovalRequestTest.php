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

    public function testStatusLabelReturnsStringForAll(): void
    {
        foreach (ApprovalRequest::statuses() as $status) {
            $label = ApprovalRequest::statusLabel($status);
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function testStatusCssClassReturnsStringForAll(): void
    {
        foreach (ApprovalRequest::statuses() as $status) {
            $class = ApprovalRequest::statusCssClass($status);
            $this->assertIsString($class);
            $this->assertNotEmpty($class);
        }
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
