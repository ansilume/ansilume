<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\commands\E2eDeletedTemplateApprovalSeeder;
use app\controllers\E2eController;
use app\models\ApprovalRule;
use yii\caching\ArrayCache;
use yii\web\NotFoundHttpException;

/**
 * The YII_DEBUG hook POST /e2e/create-deleted-template-request answers 404
 * when the seeder cannot make the waiting job. A PDOException there (the
 * seeder's transaction can raise one at BEGIN or COMMIT) is a
 * RuntimeException too: the 404 names it by class, SQLSTATE and driver
 * error code only (ErrorText), never by its message.
 *
 * Regression: the hook's 404 carried a database error's message, with the
 * value it quotes.
 */
class E2eControllerDatabaseErrorTest extends WebControllerTestCase
{
    private const MARKER = 'e2e-hook-marker-7319';

    private bool $hadDebugEnv = false;
    private mixed $savedDebugEnv = null;
    private mixed $cache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadDebugEnv = array_key_exists('YII_DEBUG', $_ENV);
        $this->savedDebugEnv = $_ENV['YII_DEBUG'] ?? null;
        $_ENV['YII_DEBUG'] = 'true';
        $this->setPost([]);
        $this->cache = \Yii::$app->getComponents(true)['cache'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadDebugEnv) {
            $_ENV['YII_DEBUG'] = $this->savedDebugEnv;
        } else {
            unset($_ENV['YII_DEBUG']);
        }
        \Yii::$app->set('cache', $this->cache);
        parent::tearDown();
    }

    public function testRegressionTheHooks404CarriedADatabaseErrorsMessage(): void
    {
        $this->seedDeletedTemplate();
        // The seeder holds the maintenance sweep off through the cache first:
        // the database error is raised there.
        \Yii::$app->set('cache', new class () extends ArrayCache {
            /**
             * @param mixed $key
             * @param mixed $value
             * @param int|null $duration
             * @param mixed $dependency
             */
            public function set($key, $value, $duration = null, $dependency = null): bool
            {
                $error = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'e2e-hook-marker-7319'");
                $error->errorInfo = ['23000', 1062, "Duplicate entry 'e2e-hook-marker-7319'"];
                throw $error;
            }
        });
        $app = \Yii::$app;
        $this->assertNotNull($app);

        try {
            (new E2eController('e2e', $app))->runAction('create-deleted-template-request');
            $this->fail('expected a 404');
        } catch (NotFoundHttpException $e) {
            $this->assertSame('PDOException (SQLSTATE 23000, driver error 1062)', $e->getMessage());
            $this->assertStringNotContainsString(self::MARKER, $e->getMessage());
        }
    }

    /**
     * What the hook needs: the soft-deleted e2e-deleted-gate-template with
     * its rule, and the launcher e2e-operator.
     */
    private function seedDeletedTemplate(): void
    {
        $admin = $this->createUser('admin');
        $operator = $this->createUser('operator');
        $operator->username = 'e2e-operator';
        $operator->save(false);
        $parent = $this->createJobTemplate(
            (int)$this->createProject((int)$admin->id)->id,
            (int)$this->createInventory((int)$admin->id)->id,
            (int)$this->createRunnerGroup((int)$admin->id)->id,
            (int)$admin->id
        );
        $rule = $this->createApprovalRule((int)$admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [(int)$admin->id]]));
        (new E2eDeletedTemplateApprovalSeeder(static function (string $msg): void {
        }))->seed((int)$admin->id, (int)$parent->id, (int)$rule->id);
    }
}
