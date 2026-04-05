<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\AuditLogController;
use app\models\AuditLog;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * Exercises AuditLogController::actionIndex() and ::actionView() directly.
 *
 * Uses the shared WebControllerTestCase to install a web Request/Session/User
 * context and an anonymous subclass that stubs render() to capture the view
 * name and the params passed to the template.
 */
class AuditLogControllerActionTest extends WebControllerTestCase
{

    // ── actionIndex() ────────────────────────────────────────────────────────

    public function testIndexRendersWithoutFilters(): void
    {
        $user = $this->createUser();
        $this->createAuditEntry($user->id, 'user.created', 'user', $user->id);
        $this->createAuditEntry($user->id, 'project.created', 'project', 42);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $this->assertArrayHasKey('dataProvider', $ctrl->capturedParams);
        $this->assertInstanceOf(ActiveDataProvider::class, $ctrl->capturedParams['dataProvider']);
        $this->assertNull($ctrl->capturedParams['filterAction']);
        $this->assertNull($ctrl->capturedParams['filterUser']);
        $this->assertNull($ctrl->capturedParams['filterObject']);
        $this->assertIsArray($ctrl->capturedParams['users']);
    }

    public function testIndexFilterByAction(): void
    {
        $user = $this->createUser();
        $this->createAuditEntry($user->id, 'user.created', 'user', 1);
        $this->createAuditEntry($user->id, 'project.created', 'project', 2);
        $this->createAuditEntry($user->id, 'user.updated', 'user', 3);

        $this->setQueryParams(['action' => 'user']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        /** @var ActiveDataProvider $dp */
        $dp = $ctrl->capturedParams['dataProvider'];
        /** @var AuditLog[] $rows */
        $rows = $dp->getModels();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertStringContainsString('user', $row->action);
        }
        $this->assertSame('user', $ctrl->capturedParams['filterAction']);
    }

    public function testIndexFilterByUserId(): void
    {
        $user1 = $this->createUser('a');
        $user2 = $this->createUser('b');
        $this->createAuditEntry($user1->id, 'user.created', 'user', 1);
        $this->createAuditEntry($user2->id, 'user.created', 'user', 2);

        $this->setQueryParams(['user_id' => (string)$user1->id]);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        /** @var ActiveDataProvider $dp */
        $dp = $ctrl->capturedParams['dataProvider'];
        /** @var AuditLog[] $rows */
        $rows = $dp->getModels();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($user1->id, $row->user_id);
        }
    }

    public function testIndexFilterByObjectType(): void
    {
        $user = $this->createUser();
        $this->createAuditEntry($user->id, 'project.created', 'project', 1);
        $this->createAuditEntry($user->id, 'user.created', 'user', 2);

        $this->setQueryParams(['object_type' => 'project']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        /** @var ActiveDataProvider $dp */
        $dp = $ctrl->capturedParams['dataProvider'];
        /** @var AuditLog[] $rows */
        $rows = $dp->getModels();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('project', $row->object_type);
        }
        $this->assertSame('project', $ctrl->capturedParams['filterObject']);
    }

    public function testIndexIgnoresNonDigitUserIdFilter(): void
    {
        $user = $this->createUser();
        $this->createAuditEntry($user->id, 'user.created', 'user', 1);

        // Pass a non-digit user_id — controller must not apply the filter.
        $this->setQueryParams(['user_id' => 'not-a-number']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        /** @var ActiveDataProvider $dp */
        $dp = $ctrl->capturedParams['dataProvider'];
        $this->assertGreaterThanOrEqual(1, count($dp->getModels()));
        $this->assertSame('not-a-number', $ctrl->capturedParams['filterUser']);
    }

    public function testIndexIgnoresEmptyStringFilters(): void
    {
        $user = $this->createUser();
        $this->createAuditEntry($user->id, 'user.created', 'user', 1);

        $this->setQueryParams(['action' => '', 'object_type' => '']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        /** @var ActiveDataProvider $dp */
        $dp = $ctrl->capturedParams['dataProvider'];
        $this->assertGreaterThanOrEqual(1, count($dp->getModels()));
    }

    public function testIndexResolvesUsernamesForUserObjectEntries(): void
    {
        $actor   = $this->createUser('actor');
        $subject = $this->createUser('subject');

        // This entry targets object_type=user, object_id=subject.id — so the
        // controller should fetch subject.username into objectUsernames.
        $this->createAuditEntry($actor->id, 'user.updated', 'user', $subject->id);

        // Restrict to just our entry so the assertion is specific.
        $this->setQueryParams(['user_id' => (string)$actor->id]);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        /** @var array<int, string> $map */
        $map = $ctrl->capturedParams['objectUsernames'];
        $this->assertArrayHasKey($subject->id, $map);
        $this->assertSame($subject->username, $map[$subject->id]);
    }

    public function testIndexObjectUsernamesEmptyWhenNoUserObjectEntries(): void
    {
        $user = $this->createUser();
        // Restrict to only this actor so pre-existing rows don't leak in.
        $this->createAuditEntry($user->id, 'project.created', 'project', 99);
        $this->setQueryParams(['user_id' => (string)$user->id]);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertSame([], $ctrl->capturedParams['objectUsernames']);
    }

    // ── actionView() ─────────────────────────────────────────────────────────

    public function testViewRendersEntry(): void
    {
        $user  = $this->createUser();
        $entry = $this->createAuditEntry($user->id, 'user.created', 'user', $user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$entry->id);

        $this->assertSame('rendered:view', $result);
        $this->assertArrayHasKey('entry', $ctrl->capturedParams);
        $this->assertSame((int)$entry->id, (int)$ctrl->capturedParams['entry']->id);
        $this->assertSame('user.created', $ctrl->capturedParams['entry']->action);
    }

    public function testViewThrowsNotFoundForMissingId(): void
    {
        $ctrl = $this->makeController();

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Audit log entry #9999999 not found.');

        $ctrl->actionView(9999999);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Anonymous AuditLogController subclass that captures render() args
     * instead of actually rendering a template (which would need viewPaths,
     * layouts, etc. that aren't wired in the test app).
     */
    private function makeController(): AuditLogController
    {
        return new class ('audit-log', \Yii::$app) extends AuditLogController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }
        };
    }

    private function createAuditEntry(int $userId, string $action, string $objectType, int $objectId): AuditLog
    {
        $log = new AuditLog();
        $log->user_id     = $userId;
        $log->action      = $action;
        $log->object_type = $objectType;
        $log->object_id   = $objectId;
        $log->created_at  = time();
        $log->save(false);
        return $log;
    }
}
