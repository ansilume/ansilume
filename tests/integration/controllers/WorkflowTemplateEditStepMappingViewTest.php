<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepFormView;
use app\services\WorkflowTemplatePage;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;

/**
 * The variable mapping on the page that edits a step
 * (views/workflow-template/edit-step.php), rendered with real views: a step
 * with a mapping shows it read-only and encoded, as only the API changes
 * it; a step without one (WorkflowStepMapping::isNone()) shows nothing, also
 * when a version before 2.11.0 stored its "none" as '[]' or '{}'.
 */
class WorkflowTemplateEditStepMappingViewTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use WorkflowStepsViews;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('mapping-view', 'admin');
        $this->loginAs($this->admin);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function noMappingProvider(): array
    {
        return [
            'null' => [null],
            'empty text' => [''],
            '[], as versions before 2.11.0 stored none' => ['[]'],
            '{}, as versions before 2.11.0 stored none' => ['{}'],
            'JSON null' => ['null'],
            '[] with spaces' => [" [ ]\n"],
        ];
    }

    /**
     * regression: a step whose mapping a version before 2.11.0 stored as
     * '[]' or '{}', its way of storing none, showed "Variable mapping
     * (change it through the API):" with [] or {}, as if the step had a
     * mapping. The API answers [] for it and runs map nothing from it.
     *
     * @dataProvider noMappingProvider
     */
    public function testRegressionAStepWithoutAMappingShowsNone(?string $stored): void
    {
        $step = $this->deployStep($stored);

        $html = $this->editPage($step);
        $xpath = $this->xpathOf($html);

        $this->one($xpath, '//form[@id="edit-step-form"]');
        $this->assertSame([], $this->all($xpath, '//*[@id="edit-step-extra-vars"]'));
        $this->assertStringNotContainsString(Html::encode(WorkflowStepFormView::EXTRA_VARS_READONLY), $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mappingProvider(): array
    {
        return [
            'a mapping' => ['{"app_version": "build_version", "target": "<prod>"}'],
            'a list an older version stored' => ['["build_version"]'],
        ];
    }

    /**
     * A step with a mapping shows it as stored, read-only and encoded.
     *
     * @dataProvider mappingProvider
     */
    public function testAStepWithAMappingShowsItReadOnly(string $stored): void
    {
        $step = $this->deployStep($stored);

        $html = $this->editPage($step);
        $xpath = $this->xpathOf($html);

        $block = $this->one($xpath, '//div[@id="edit-step-extra-vars"]');
        $this->assertSame(WorkflowStepFormView::EXTRA_VARS_READONLY, $this->textOf($this->one($xpath, './div', $block)));
        $this->assertSame($stored, $this->one($xpath, './pre', $block)->textContent);
        $this->assertSame([], $this->all($xpath, './/input|.//textarea|.//select', $block), 'read-only');
        $this->assertStringContainsString('<pre class="border rounded p-2 mb-0">' . Html::encode($stored) . '</pre>', $html);
    }

    /**
     * Deploy, the second job step of a new workflow, after Build, with the
     * variable mapping $stored as an earlier version wrote it to the column,
     * without the model's rules.
     */
    private function deployStep(?string $stored): WorkflowStep
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->admin->id)->id,
            (int)$this->createInventory($this->admin->id)->id,
            (int)$this->createRunnerGroup($this->admin->id)->id,
            $this->admin->id
        );
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $this->namedStep($workflow, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$template->id);
        $deploy = $this->namedStep($workflow, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$template->id);
        WorkflowStep::updateAll(['extra_vars_template' => $stored], ['id' => $deploy->id]);
        $this->assertSame($stored, WorkflowStep::find()->select('extra_vars_template')->where(['id' => $deploy->id])->scalar());

        return $deploy;
    }

    /**
     * The page that edits $step, as the admin sees it, with the step as
     * stored.
     */
    private function editPage(WorkflowStep $step): string
    {
        $workflow = WorkflowTemplate::findOne($step->workflow_template_id);
        $this->assertInstanceOf(WorkflowTemplate::class, $workflow);
        $params = (new WorkflowTemplatePage((int)$this->admin->id))->editParams($workflow, $step, null, null);

        return $this->renderStepsPartial('edit-step', $params);
    }
}
