<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Schedule;
use app\models\User;
use app\services\UserDeletionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\BaseActiveRecord;
use yii\rbac\DbManager;

/**
 * Regression: deleting a user whom other records still refer to (jobs
 * they launched, rows they created, a trigger token they generated) failed
 * on the RESTRICT foreign keys with a server error. The API had revoked the
 * user's roles before, so their schedules and triggers stopped launching;
 * the web UI never revoked the roles of a deleted user at all.
 */
class UserDeletionServiceTest extends DbTestCase
{
    use TeamScopeFixtures;

    public function testDeletesAnUnreferencedUserTogetherWithTheirRoles(): void
    {
        $user = $this->createUserWithRole('del_free', 'operator');

        $this->assertTrue($this->service()->delete($user));

        $this->assertNull(User::findOne($user->id));
        $this->assertSame([], $this->roleNames($user->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function referenceProvider(): array
    {
        return [
            'a job they launched' => ['job'],
            'a project they created' => ['project'],
            'a schedule that runs as them' => ['schedule'],
            'a job template trigger token they generated' => ['job-trigger'],
            'a workflow trigger token they generated' => ['workflow-trigger'],
        ];
    }

    /**
     * @dataProvider referenceProvider
     */
    public function testKeepsAReferencedUserAndTheirRoles(string $reference): void
    {
        $user = $this->createUserWithRole('del_referenced', 'operator');
        $this->referTo($user, $reference);

        $this->assertFalse($this->service()->delete($user));

        $this->assertNotNull(User::findOne($user->id));
        $this->assertSame(['operator'], $this->roleNames($user->id));
    }

    public function testTheUserCanBeDeletedOnceNothingRefersToThemAnyMore(): void
    {
        $user = $this->createUserWithRole('del_revoked', 'operator');
        $template = $this->templateOf($this->createUser('del_owner'));
        $template->generateTriggerToken($user->id);
        $this->assertFalse($this->service()->delete($user));

        $template->revokeTriggerToken();

        $this->assertTrue($this->service()->delete($user));
        $this->assertNull(User::findOne($user->id));
    }

    public function testAVetoedDeleteKeepsTheRoles(): void
    {
        $user = $this->createUserWithRole('del_vetoed', 'operator');
        $veto = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(User::class, BaseActiveRecord::EVENT_BEFORE_DELETE, $veto);
        try {
            $deleted = $this->service()->delete($user);
        } finally {
            Event::off(User::class, BaseActiveRecord::EVENT_BEFORE_DELETE, $veto);
        }

        $this->assertFalse($deleted);
        $this->assertNotNull(User::findOne($user->id));
        $this->assertSame(['operator'], $this->roleNames($user->id));
    }

    public function testAFailureAfterTheDeleteRollsItBack(): void
    {
        $user = $this->createUserWithRole('del_failing', 'operator');
        $original = \Yii::$app->getComponents(true)['authManager'] ?? null;
        \Yii::$app->set('authManager', new class () extends DbManager {
            public function revokeAll($userId): bool
            {
                throw new \RuntimeException('assignments unavailable');
            }
        });
        try {
            $this->service()->delete($user);
            $this->fail('expected the failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('assignments unavailable', $e->getMessage());
        } finally {
            \Yii::$app->set('authManager', $original);
        }

        $this->assertNotNull(User::findOne($user->id), 'the delete was rolled back');
        $this->assertSame(['operator'], $this->roleNames($user->id));
    }

    /**
     * The roles are revoked in the transaction of the delete only because
     * the RBAC manager writes through the same connection.
     */
    public function testTheRbacManagerWritesThroughTheConnectionOfTheUserTable(): void
    {
        $auth = \Yii::$app->authManager;

        $this->assertInstanceOf(DbManager::class, $auth);
        $this->assertSame(User::getDb(), $auth->db);
    }

    /**
     * The user controllers get the service from the application, so the web
     * and console configuration (config/services.php) and the tests' must
     * both register it.
     */
    public function testIsRegisteredAsAnApplicationComponentEverywhere(): void
    {
        /** @var array<string, array<string, mixed>> $services */
        $services = require dirname(__DIR__, 3) . '/config/services.php';

        $this->assertSame(UserDeletionService::class, $services['userDeletionService']['class'] ?? null);
        $this->assertInstanceOf(UserDeletionService::class, \Yii::$app->get('userDeletionService'));
    }

    public function testTheRefusalNamesTheUserAndWhatToDoInstead(): void
    {
        $user = $this->createUser('del_message');

        $this->assertSame(
            'User "' . $user->username . '" cannot be deleted: other records still refer to them, such as jobs '
                . 'they launched, resources they created or a trigger token they generated. '
                . 'Deactivate the account instead.',
            $this->service()->refusalMessage($user)
        );
    }

    // -- Helpers --------------------------------------------------------------

    private function service(): UserDeletionService
    {
        return new UserDeletionService();
    }

    private function referTo(User $user, string $reference): void
    {
        $owner = $this->createUser('del_owner');
        match ($reference) {
            'job' => $this->createJob((int)$this->templateOf($owner)->id, $user->id),
            'project' => $this->createProject($user->id),
            'schedule' => $this->scheduleOf($user),
            'job-trigger' => $this->templateOf($owner)->generateTriggerToken($user->id),
            default => $this->createWorkflowTemplate($owner->id)->generateTriggerToken($user->id),
        };
    }

    private function templateOf(User $owner): \app\models\JobTemplate
    {
        return $this->createJobTemplate(
            $this->createProject($owner->id)->id,
            $this->createInventory($owner->id)->id,
            $this->createRunnerGroup($owner->id)->id,
            $owner->id
        );
    }

    private function scheduleOf(User $user): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'del-schedule-' . uniqid('', true);
        $schedule->job_template_id = (int)$this->templateOf($this->createUser('del_owner'))->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $user->id;
        $schedule->save(false);
        return $schedule;
    }

    /**
     * @return list<string>
     */
    private function roleNames(int $userId): array
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $names = array_keys($auth->getRolesByUser((string)$userId));
        sort($names);
        return $names;
    }
}
