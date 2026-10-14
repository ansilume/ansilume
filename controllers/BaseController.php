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
     * The 403 of a page whose user may not change the resource without
     * operator access: to its project (TeamScopingTrait::requireChildOperate())
     * or to the project of every job step of a workflow
     * (TeamScopingTrait::requireWorkflowOperate(); the template page answers
     * it too when the step write path refuses the same with the workflow
     * locked, WorkflowTemplateController). Here and not in the trait:
     * Scrutinizer's parser cannot read a constant in a trait.
     */
    public const MODIFY_FORBIDDEN = 'You do not have permission to modify this resource.';

    /**
     * Return Yii2 access-rule config arrays (without the superadmin rule).
     * Example: [['actions' => ['index'], 'allow' => true, 'roles' => ['@']]]
     *
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [];
    }

    /**
     * Return VerbFilter actions map.
     * Example: ['delete' => ['POST'], 'update' => ['POST', 'PUT']]
     *
     * @return array<string, string[]>
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
                'class' => VerbFilter::class,
                'actions' => $verbs,
            ];
        }

        return $behaviors;
    }

    /**
     * Type-safe accessor for the web session component.
     */
    protected function session(): \yii\web\Session
    {
        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;
        return $session;
    }
}
