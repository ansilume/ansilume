<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\SuperadminAccessRule;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * Base controller.
 *
 * Subclasses implement:
 *   - accessRules(): array   — plain Yii2 access-rule configs (no superadmin rule needed)
 *   - verbRules(): array     — map of action => allowed HTTP verbs (optional)
 *
 * BaseController::behaviors() composes these into proper filters and
 * automatically prepends the SuperadminAccessRule so superadmins bypass all checks.
 */
abstract class BaseController extends Controller
{
    /**
     * Return Yii2 access-rule config arrays (without the superadmin rule).
     * Example: [['actions' => ['index'], 'allow' => true, 'roles' => ['@']]]
     */
    protected function accessRules(): array
    {
        return [];
    }

    /**
     * Return VerbFilter actions map.
     * Example: ['delete' => ['POST'], 'update' => ['POST', 'PUT']]
     */
    protected function verbRules(): array
    {
        return [];
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $rules = $this->accessRules();
        if ($rules !== []) {
            array_unshift($rules, ['class' => SuperadminAccessRule::class]);
            $behaviors['access'] = [
                'class' => AccessControl::class,
                'rules' => $rules,
            ];
        }

        $verbs = $this->verbRules();
        if ($verbs !== []) {
            $behaviors['verbs'] = [
                'class'   => VerbFilter::class,
                'actions' => $verbs,
            ];
        }

        return $behaviors;
    }
}
