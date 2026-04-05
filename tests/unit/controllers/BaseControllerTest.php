<?php

declare(strict_types=1);

namespace app\tests\unit\controllers;

use app\components\SuperadminAccessRule;
use app\controllers\BaseController;
use PHPUnit\Framework\TestCase;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;

/**
 * Unit tests for the BaseController composition logic.
 *
 * BaseController::behaviors() turns accessRules()/verbRules() into Yii2
 * filter behaviors and automatically prepends the SuperadminAccessRule.
 * These tests exercise all four branches (with/without rules × with/without verbs).
 */
class BaseControllerTest extends TestCase
{
    public function testBehaviorsEmptyWhenNoRulesAndNoVerbs(): void
    {
        $ctrl = $this->makeController([], []);

        $behaviors = $ctrl->behaviors();

        $this->assertArrayNotHasKey('access', $behaviors);
        $this->assertArrayNotHasKey('verbs', $behaviors);
    }

    public function testBehaviorsAddsAccessControlWhenRulesPresent(): void
    {
        $rules = [
            ['actions' => ['index'], 'allow' => true, 'roles' => ['user.view']],
        ];
        $ctrl = $this->makeController($rules, []);

        $behaviors = $ctrl->behaviors();

        $this->assertArrayHasKey('access', $behaviors);
        $this->assertSame(AccessControl::class, $behaviors['access']['class']);

        // Superadmin rule must be prepended as the first rule.
        $this->assertCount(2, $behaviors['access']['rules']);
        $this->assertSame(SuperadminAccessRule::class, $behaviors['access']['rules'][0]['class']);
        $this->assertSame(['index'], $behaviors['access']['rules'][1]['actions']);
        $this->assertSame(['user.view'], $behaviors['access']['rules'][1]['roles']);
    }

    public function testBehaviorsAddsVerbFilterWhenVerbsPresent(): void
    {
        $verbs = ['delete' => ['POST'], 'update' => ['POST', 'PUT']];
        $ctrl  = $this->makeController([], $verbs);

        $behaviors = $ctrl->behaviors();

        $this->assertArrayHasKey('verbs', $behaviors);
        $this->assertSame(VerbFilter::class, $behaviors['verbs']['class']);
        $this->assertSame($verbs, $behaviors['verbs']['actions']);
        $this->assertArrayNotHasKey('access', $behaviors);
    }

    public function testBehaviorsAddsBothWhenBothPresent(): void
    {
        $rules = [['actions' => ['delete'], 'allow' => true, 'roles' => ['user.delete']]];
        $verbs = ['delete' => ['POST']];
        $ctrl  = $this->makeController($rules, $verbs);

        $behaviors = $ctrl->behaviors();

        $this->assertArrayHasKey('access', $behaviors);
        $this->assertArrayHasKey('verbs', $behaviors);
        $this->assertSame(SuperadminAccessRule::class, $behaviors['access']['rules'][0]['class']);
        $this->assertSame(['delete'], $behaviors['access']['rules'][1]['actions']);
        $this->assertSame(['POST'], $behaviors['verbs']['actions']['delete']);
    }

    public function testDefaultAccessRulesAreEmpty(): void
    {
        $ctrl = new class ('test', \Yii::$app) extends BaseController {
            public function exposeAccessRules(): array
            {
                return $this->accessRules();
            }
            public function exposeVerbRules(): array
            {
                return $this->verbRules();
            }
        };

        $this->assertSame([], $ctrl->exposeAccessRules());
        $this->assertSame([], $ctrl->exposeVerbRules());
    }

    public function testSessionAccessorReturnsAppSession(): void
    {
        \Yii::$app->set('session', [
            'class' => \yii\web\Session::class,
        ]);

        $ctrl = new class ('test', \Yii::$app) extends BaseController {
            public function exposeSession(): \yii\web\Session
            {
                return $this->session();
            }
        };

        $this->assertInstanceOf(\yii\web\Session::class, $ctrl->exposeSession());

        \Yii::$app->clear('session');
    }

    /**
     * Build an anonymous BaseController subclass with injected rules/verbs.
     *
     * @param array<int, array<string, mixed>> $rules
     * @param array<string, string[]> $verbs
     */
    private function makeController(array $rules, array $verbs): BaseController
    {
        return new class ('test', \Yii::$app, $rules, $verbs) extends BaseController {
            /** @var array<int, array<string, mixed>> */
            private array $fakeRules;
            /** @var array<string, string[]> */
            private array $fakeVerbs;

            public function __construct($id, $module, array $rules, array $verbs)
            {
                parent::__construct($id, $module);
                $this->fakeRules = $rules;
                $this->fakeVerbs = $verbs;
            }

            protected function accessRules(): array
            {
                return $this->fakeRules;
            }

            protected function verbRules(): array
            {
                return $this->fakeVerbs;
            }
        };
    }
}
