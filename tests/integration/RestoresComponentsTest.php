<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\models\User;
use app\tests\integration\commands\ApprovalControllerTest;
use app\tests\integration\commands\LdapSyncLeavesAnsilumeDeactivationTest;
use app\tests\integration\commands\ScheduleControllerTest;
use app\tests\integration\commands\TeardownHelperDatabaseErrorTest;
use app\tests\integration\controllers\api\v1\ApprovalsGateFieldsApiTest;
use app\tests\integration\controllers\api\v1\JobsControllerCancelTest;
use app\tests\integration\controllers\api\v1\JobsInventoryApiTest;
use app\tests\integration\controllers\api\v1\JobTemplatesControllerApprovalRuleTest;
use app\tests\integration\controllers\api\v1\NotificationTemplatesTestActionTest;
use app\tests\integration\controllers\api\v1\ProjectsBranchFormatTest;
use app\tests\integration\controllers\api\v1\ProjectsGitFieldsTest;
use app\tests\integration\controllers\E2eControllerDatabaseErrorTest;
use app\tests\integration\controllers\JobControllerCancelTest;
use app\tests\integration\controllers\JobTemplateControllerDeleteTest;
use app\tests\integration\controllers\NotificationTemplateControllerActionTest;
use app\tests\integration\controllers\NotificationTemplateSecretsTest;
use app\tests\integration\controllers\ProjectBranchFormatTest;
use app\tests\integration\controllers\ProjectGitFieldsTest;
use app\tests\integration\controllers\ProjectScmUrlRedactionTest;
use app\tests\integration\controllers\ProjectSyncPollScriptTest;
use app\tests\integration\controllers\ScheduleRunAsApprovalTest;
use app\tests\integration\controllers\TriggerJobAnswerTest;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\docs\GitSyncBehaviourDocsContractTest;
use app\tests\integration\models\PasswordResetRequestFormTest;
use app\tests\integration\services\ApprovalDeletedTemplateTest;
use app\tests\integration\services\ApprovalEndedWorkflowStepTest;
use app\tests\integration\services\ApprovalPurgedTemplateTest;
use app\tests\integration\services\ProjectServiceTest;
use app\tests\integration\services\ProjectSyncDatabaseErrorTest;
use app\tests\integration\services\ProjectSyncRepositoryUrlChangeTest;
use app\tests\integration\services\ProjectSyncUrlCredentialsTest;
use app\tests\integration\services\ScheduleSecretsConcurrentEditTest;
use app\tests\integration\services\ScheduleSecretsEveryNameTest;
use app\tests\integration\services\ScheduleServiceDatabaseErrorTest;
use app\tests\integration\services\ScheduleServiceSecretsTest;
use app\tests\integration\services\ScheduleServiceTest;
use app\tests\integration\services\WorkflowExecutionLaunchTest;
use PHPUnit\Framework\TestCase;
use yii\base\Event;
use yii\db\BaseActiveRecord;
use yii\db\Connection;
use yii\db\Exception as DbException;

/**
 * A test whose setUp() fails leaves the application's components as it
 * found them (RestoresComponents).
 *
 * PHPUnit runs tearDown() also after a setUp() that failed, and Yii's
 * set($id, null) removes a component. Each case runs setUp() and tearDown()
 * of a real test class as PHPUnit does, with a failure injected into its
 * setUp(): at the BEGIN of its transaction (a lost connection), or at its
 * first write, a user (a deadlock, as in a concurrent run). This class has
 * no transaction of its own, so the BEGIN of the case is the outermost one.
 */
class RestoresComponentsTest extends TestCase
{
    private const AT_BEGIN = 'begin';
    private const AT_FIRST_WRITE = 'first write';

    /** @var array<string, array{0: string, 1: string}> where each failure is injected: the class and its event */
    private const FAILURE_POINTS = [
        self::AT_BEGIN => [Connection::class, Connection::EVENT_BEGIN_TRANSACTION],
        self::AT_FIRST_WRITE => [User::class, BaseActiveRecord::EVENT_BEFORE_INSERT],
    ];

    /** The components WebControllerTestCase installs for each test. */
    private const WEB_COMPONENTS = ['request', 'response', 'session', 'user', 'urlManager'];

    /** A component ID that nothing registers. */
    private const UNREGISTERED = 'restoresComponentsTestProbe';

    /**
     * The tests that wrote users in setUp() before they captured a component.
     *
     * @var array<string, list<string>> the components each one keeps, by test class
     */
    private const WRITING_BEFORE_THE_CAPTURE = [
        ApprovalsGateFieldsApiTest::class => ['notificationDispatcher'],
        JobsInventoryApiTest::class => ['notificationDispatcher'],
        TriggerJobAnswerTest::class => ['notificationDispatcher'],
        JobTemplatesControllerApprovalRuleTest::class => ['notificationDispatcher'],
        JobTemplateControllerDeleteTest::class => ['notificationDispatcher'],
        ApprovalDeletedTemplateTest::class => ['workflowExecutionService', 'notificationDispatcher'],
        ApprovalEndedWorkflowStepTest::class => ['workflowExecutionService', 'notificationDispatcher'],
        ApprovalPurgedTemplateTest::class => ['workflowExecutionService', 'notificationDispatcher'],
    ];

    /**
     * The tests that captured a component right after parent::setUp() and
     * set it in tearDown() also when they had not captured it.
     *
     * @var array<string, list<string>> the components each one keeps, by test class
     */
    private const RESTORING_WHAT_WAS_NOT_CAPTURED = [
        ApprovalControllerTest::class => ['approvalService', 'notificationDispatcher'],
        LdapSyncLeavesAnsilumeDeactivationTest::class => ['ldapService'],
        ScheduleControllerTest::class => ['notificationDispatcher'],
        TeardownHelperDatabaseErrorTest::class => ['authManager'],
        JobsControllerCancelTest::class => ['workflowAccessChecker', 'notificationDispatcher'],
        NotificationTemplatesTestActionTest::class => ['notificationDispatcher'],
        ProjectsBranchFormatTest::class => ['projectService'],
        ProjectsGitFieldsTest::class => ['projectService'],
        E2eControllerDatabaseErrorTest::class => ['cache'],
        JobControllerCancelTest::class => ['workflowAccessChecker', 'notificationDispatcher'],
        NotificationTemplateControllerActionTest::class => ['notificationDispatcher'],
        NotificationTemplateSecretsTest::class => ['notificationDispatcher'],
        ProjectBranchFormatTest::class => ['projectService'],
        ProjectGitFieldsTest::class => ['projectService'],
        ProjectScmUrlRedactionTest::class => ['projectService'],
        ProjectSyncPollScriptTest::class => ['projectService'],
        ScheduleRunAsApprovalTest::class => ['notificationDispatcher'],
        PasswordResetRequestFormTest::class => ['urlManager', 'mailer'],
        ProjectServiceTest::class => ['notificationDispatcher'],
        ProjectSyncDatabaseErrorTest::class => ['notificationDispatcher'],
        ScheduleSecretsConcurrentEditTest::class => ['notificationDispatcher', 'workflowAccessChecker'],
        ScheduleSecretsEveryNameTest::class => ['notificationDispatcher'],
        ScheduleServiceDatabaseErrorTest::class => ['notificationDispatcher'],
        ScheduleServiceSecretsTest::class => ['notificationDispatcher'],
        ScheduleServiceTest::class => ['notificationDispatcher'],
        WorkflowExecutionLaunchTest::class => ['notificationDispatcher'],
        GitSyncBehaviourDocsContractTest::class => ['notificationDispatcher'],
    ];

    /**
     * The tests whose tearDown() stopped at a logger that setUp() had not
     * captured yet, before it set a component it had not captured either.
     *
     * @var array<string, list<string>> the components each one keeps, by test class
     */
    private const STOPPING_BEFORE_THE_RESTORE = [
        ProjectSyncRepositoryUrlChangeTest::class => ['notificationDispatcher'],
        ProjectSyncUrlCredentialsTest::class => ['notificationDispatcher'],
    ];

    /**
     * The tests that write a user in setUp() after their capture.
     *
     * @var array<string, list<string>> the components each one keeps, by test class
     */
    private const WRITING_AFTER_THE_CAPTURE = [
        JobsControllerCancelTest::class => ['workflowAccessChecker', 'notificationDispatcher'],
        JobControllerCancelTest::class => ['workflowAccessChecker', 'notificationDispatcher'],
        ScheduleRunAsApprovalTest::class => ['notificationDispatcher'],
        ScheduleSecretsConcurrentEditTest::class => ['notificationDispatcher', 'workflowAccessChecker'],
        ScheduleSecretsEveryNameTest::class => ['notificationDispatcher'],
        ScheduleServiceDatabaseErrorTest::class => ['notificationDispatcher'],
        ScheduleServiceSecretsTest::class => ['notificationDispatcher'],
    ];

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string}>
     */
    public static function failedBeforeTheCaptureProvider(): array
    {
        return array_merge(
            self::cases(self::WRITING_BEFORE_THE_CAPTURE, self::AT_FIRST_WRITE),
            self::cases(self::WRITING_BEFORE_THE_CAPTURE, self::AT_BEGIN),
            self::cases(self::RESTORING_WHAT_WAS_NOT_CAPTURED, self::AT_BEGIN)
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string}>
     */
    public static function otherFailuresProvider(): array
    {
        return array_merge(
            self::cases(self::STOPPING_BEFORE_THE_RESTORE, self::AT_BEGIN),
            self::cases(self::WRITING_AFTER_THE_CAPTURE, self::AT_FIRST_WRITE)
        );
    }

    /**
     * regression: ApprovalsGateFieldsApiTest, JobsInventoryApiTest and
     * TriggerJobAnswerTest, like five older tests, captured
     * notificationDispatcher (or workflowExecutionService) only after their
     * setUp() had written users, and set it back in tearDown() whatever had
     * happened. A deadlock at such a write in a concurrent run left nothing
     * captured, so tearDown() set the component to null, which removed it:
     * 70 later tests failed with 'Unknown component ID: notificationDispatcher'.
     * The tests that captured right after parent::setUp() set it back also
     * when parent::setUp() itself had failed. Now each one keeps its
     * components before any write and puts back only what it kept.
     *
     * @dataProvider failedBeforeTheCaptureProvider
     * @param list<string> $ids
     */
    public function testRegressionASetUpThatFailedBeforeItsCaptureRemovedTheComponent(string $class, array $ids, string $failAt): void
    {
        $this->assertAFailedSetUpLeavesTheComponents($class, $ids, $failAt);
    }

    /**
     * The other failures of the same tests leave their components as well:
     * a setUp() that fails after its capture, and a tearDown() that stops at
     * state its setUp() never set.
     *
     * @dataProvider otherFailuresProvider
     * @param list<string> $ids
     */
    public function testEveryOtherFailedSetUpLeavesTheComponents(string $class, array $ids, string $failAt): void
    {
        $this->assertAFailedSetUpLeavesTheComponents($class, $ids, $failAt);
    }

    /**
     * A swapped component comes back as it was, however often the test sets
     * it in between; a second restore changes nothing.
     */
    public function testASwappedComponentComesBackAsItWas(): void
    {
        $before = $this->componentsNow()['notificationDispatcher'] ?? null;
        $this->assertNotNull($before, 'the test configuration registers notificationDispatcher');
        $keeper = $this->keeper();
        try {
            $keeper->swapComponent('notificationDispatcher', new \stdClass());
            \Yii::$app->set('notificationDispatcher', new \stdClass());
            $keeper->swapComponent('notificationDispatcher', new \stdClass());
            $keeper->restoreComponents();
            $this->assertSame($before, $this->componentsNow()['notificationDispatcher'] ?? null, 'the first capture counts');

            $later = new \stdClass();
            \Yii::$app->set('notificationDispatcher', $later);
            $keeper->restoreComponents();
            $this->assertSame($later, \Yii::$app->get('notificationDispatcher'), 'nothing is kept after a restore');
        } finally {
            \Yii::$app->set('notificationDispatcher', $before);
        }
    }

    /**
     * Only kept components come back: a restore without a keep changes no
     * component, and a component that was not registered is removed again.
     */
    public function testOnlyKeptComponentsComeBack(): void
    {
        $registered = $this->componentsNow();
        $keeper = $this->keeper();
        $keeper->restoreComponents();
        $this->assertSame($registered, $this->componentsNow(), 'a restore without a keep changes nothing');

        try {
            $keeper->keepComponent(self::UNREGISTERED);
            \Yii::$app->set(self::UNREGISTERED, new \stdClass());
            $this->assertTrue(\Yii::$app->has(self::UNREGISTERED));
            $keeper->restoreComponents();
            $this->assertFalse(\Yii::$app->has(self::UNREGISTERED), 'a component that was not registered is removed again');
        } finally {
            \Yii::$app->set(self::UNREGISTERED, null);
        }
    }

    /**
     * Runs setUp() and tearDown() of $class as PHPUnit does, with a failure
     * injected at $failAt: setUp() fails there, every registered component is
     * still registered, $ids and the components of WebControllerTestCase are
     * as they were, and no transaction is left open.
     *
     * @param list<string> $ids
     */
    private function assertAFailedSetUpLeavesTheComponents(string $class, array $ids, string $failAt): void
    {
        $app = \Yii::$app;
        $this->assertNull($app->db->transaction, 'the case starts outside a transaction');
        $watched = is_subclass_of($class, WebControllerTestCase::class) ? array_merge($ids, self::WEB_COMPONENTS) : $ids;
        $before = $this->componentsNow();
        $globals = $this->globalsNow();
        $params = $app->params;
        [$point, $event] = self::FAILURE_POINTS[$failAt];
        $injected = $failAt === self::AT_BEGIN ? $this->lostConnection() : $this->deadlock();
        $handler = static function () use ($injected): void {
            throw $injected;
        };
        Event::on($point, $event, $handler);
        try {
            $case = new $class('failedSetUp');
            $this->assertInstanceOf(TestCase::class, $case);
            $thrown = $this->runFailedSetUp($case);
            $after = $this->componentsNow();
            $leftOpen = $app->db->transaction !== null;
        } finally {
            Event::off($point, $event, $handler);
            $this->putBack($before, $watched);
            $this->putGlobalsBack($globals);
            $app->params = $params;
            $app->db->transaction?->rollBack();
        }

        $this->assertSame($injected, $thrown, "{$class}::setUp() fails at the {$failAt}");
        $this->assertSame([], array_values(array_diff(array_keys($before), array_keys($after))), 'no component is removed');
        foreach ($ids as $id) {
            $this->assertArrayHasKey($id, $before, "the test configuration registers {$id}");
        }
        foreach ($watched as $id) {
            $this->assertSame(array_key_exists($id, $before), array_key_exists($id, $after), "{$id} is registered as before the case");
            $this->assertSame($before[$id] ?? null, $after[$id] ?? null, "{$id} is as it was before the case");
        }
        $this->assertFalse($leftOpen, 'no transaction is left open');
    }

    /**
     * setUp() of $case, then its tearDown(), as PHPUnit runs them for a test
     * whose setUp() fails: an error of tearDown() is dropped, as PHPUnit
     * drops it after an error of setUp(). What setUp() threw, or null.
     */
    private function runFailedSetUp(TestCase $case): ?\Throwable
    {
        $thrown = null;
        try {
            (new \ReflectionMethod($case, 'setUp'))->invoke($case);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        try {
            (new \ReflectionMethod($case, 'tearDown'))->invoke($case);
        } catch (\Throwable) {
            // Dropped, as PHPUnit drops it.
        }

        return $thrown;
    }

    /**
     * Sets back each component the case removed, and each one of $watched it
     * changed, added or removed, so that a failure here reaches no later test.
     *
     * @param array<string, mixed> $before
     * @param list<string> $watched
     */
    private function putBack(array $before, array $watched): void
    {
        $now = $this->componentsNow();
        foreach ($before as $id => $definition) {
            if (!array_key_exists($id, $now)) {
                \Yii::$app->set($id, $definition);
            }
        }
        foreach ($watched as $id) {
            if (($now[$id] ?? null) !== ($before[$id] ?? null)) {
                \Yii::$app->set($id, $before[$id] ?? null);
            }
        }
    }

    /**
     * The globals that the cases change ($_SESSION null when it is not set).
     *
     * @return array{env: array<mixed>, server: array<mixed>, session: array<mixed>|null}
     */
    private function globalsNow(): array
    {
        return ['env' => $_ENV, 'server' => $_SERVER, 'session' => $_SESSION ?? null];
    }

    /**
     * Sets the globals back as globalsNow() found them.
     *
     * @param array{env: array<mixed>, server: array<mixed>, session: array<mixed>|null} $globals
     */
    private function putGlobalsBack(array $globals): void
    {
        $_ENV = $globals['env'];
        $_SERVER = $globals['server'];
        if ($globals['session'] === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $globals['session'];
        }
    }

    /**
     * @return array<string, mixed> the registered components by ID, instances or definitions
     */
    private function componentsNow(): array
    {
        return \Yii::$app->getComponents(true);
    }

    /**
     * An object with the methods of RestoresComponents, made public.
     */
    private function keeper(): object
    {
        return new class () {
            use RestoresComponents {
                keepComponent as public;
                swapComponent as public;
                restoreComponents as public;
            }
        };
    }

    /**
     * A lost connection, as PDO reports it.
     */
    private function lostConnection(): DbException
    {
        return new DbException(
            'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away',
            ['HY000', 2006, 'MySQL server has gone away']
        );
    }

    /**
     * A deadlock, which MariaDB reports with SQLSTATE 40001 and error 1213.
     */
    private function deadlock(): DbException
    {
        return new DbException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
            ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction']
        );
    }

    /**
     * The data sets of $tests, each failing at $failAt.
     *
     * @param array<string, list<string>> $tests
     * @return array<string, array{0: string, 1: list<string>, 2: string}>
     */
    private static function cases(array $tests, string $failAt): array
    {
        $cases = [];
        foreach ($tests as $class => $ids) {
            $short = substr((string)strrchr($class, '\\'), 1);
            $cases["{$short}, failing at the {$failAt}"] = [$class, $ids, $failAt];
        }

        return $cases;
    }
}
