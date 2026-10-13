<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\ApproverAudience;
use PHPUnit\Framework\TestCase;

/**
 * Who sees what is to be approved, as sets of projects: a user belongs when
 * they may view every project of at least one set.
 */
class ApproverAudienceTest extends TestCase
{
    public function testWithoutProjectsEveryoneBelongs(): void
    {
        $audience = ApproverAudience::ofProjects([]);

        $this->assertTrue($audience->admits(static fn (int $projectId): bool => false));
        $this->assertSame([], $audience->projectIds());
    }

    public function testAUserMustBeAllowedToViewEveryProjectOfTheSet(): void
    {
        $audience = ApproverAudience::ofProjects([4, 9, 4]);

        $this->assertSame([4, 9], $audience->projectIds(), 'each project once');
        $this->assertTrue($audience->admits(static fn (int $projectId): bool => true));
        $this->assertFalse($audience->admits(static fn (int $projectId): bool => $projectId === 4));
        $this->assertFalse($audience->admits(static fn (int $projectId): bool => $projectId === 9));
    }

    public function testOnlyUnrestrictedUsersBelongToAnAudienceWithoutSets(): void
    {
        foreach ([ApproverAudience::unrestrictedOnly(), ApproverAudience::anyOf()] as $audience) {
            $this->assertFalse($audience->admits(static fn (int $projectId): bool => true));
            $this->assertSame([], $audience->projectIds());
        }
    }

    public function testAUserBelongsToAnyOfSeveralAudiencesThroughOneOfThem(): void
    {
        $audience = ApproverAudience::anyOf(
            ApproverAudience::ofProjects([1, 2]),
            ApproverAudience::unrestrictedOnly(),
            ApproverAudience::ofProjects([3, 1])
        );

        $this->assertSame([1, 2, 3], $audience->projectIds());
        $this->assertTrue($audience->admits(static fn (int $projectId): bool => in_array($projectId, [1, 2], true)));
        $this->assertTrue($audience->admits(static fn (int $projectId): bool => in_array($projectId, [1, 3], true)));
        $this->assertFalse($audience->admits(static fn (int $projectId): bool => in_array($projectId, [2, 3], true)));
    }
}
