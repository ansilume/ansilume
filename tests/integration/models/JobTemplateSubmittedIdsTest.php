<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\controllers\JobTemplateController;
use app\models\Credential;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobTemplateRuleAssignment;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\TemplateInventoryLockFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\AssetManager;
use yii\web\View;

/**
 * The IDs a job template holds as a request submitted them, read where they
 * are used before or without the template's validation: the primary
 * credential of the credential snapshot (JobTemplate::credentialSnapshot())
 * and of the edit form (views/job-template/form.php), and the own rule and
 * the inventory of the one-rule-per-launch check
 * (JobTemplateRuleAssignment::vetoesDifferentRules()). Each reads them as
 * the integer rule reads an ID (SubmittedId): a JSON number beyond the int
 * range is no ID, every form of an ID ("05", 5.0) is that ID.
 *
 * Regression: they cast with (int), which warns on PHP 8.5 for such a
 * number ("The float 1.0E+30 is not representable as an int, cast
 * occurred"); the error handler turns the warning into an exception. The
 * controller empties such values before it renders a rejected form
 * (JobTemplateController::renderRejected()), and the integer rule runs
 * before the veto, so these reads were safe only through other code.
 */
class JobTemplateSubmittedIdsTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('submitted-ids-admin', 'admin');
        $this->loginAs($this->admin);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testASnapshotWithACredentialBeyondTheIntRangeHasNoPrimary(string $number): void
    {
        ['template' => $template, 'primary' => $primary, 'additional' => $additional] = $this->templateWithCredentials();
        $template->setAttribute('credential_id', $this->decoded($number));
        $this->assertIsFloat($template->getAttribute('credential_id'));

        $snapshot = $template->credentialSnapshot();

        $this->assertSame([[(int)$additional->id, Credential::ROLE_ADDITIONAL]], self::roles($snapshot));
        $this->assertNotContains((int)$primary->id, array_column($snapshot, 'id'), 'the relation finds no credential for it');
    }

    /**
     * Every form of the ID names the primary credential, as before.
     *
     * @return array<string, array{0: string}>
     */
    public static function idFormProvider(): array
    {
        return ['the ID' => ['%d'], 'the ID as digits' => ['"%d"'], 'a leading zero' => ['"0%d"'], 'a whole float' => ['%d.0']];
    }

    /**
     * @dataProvider idFormProvider
     */
    public function testEveryFormOfTheIdNamesThePrimary(string $form): void
    {
        ['template' => $template, 'primary' => $primary, 'additional' => $additional] = $this->templateWithCredentials();
        $template->setAttribute('credential_id', $this->decoded(sprintf($form, (int)$primary->id)));

        $this->assertSame(
            [[(int)$primary->id, Credential::ROLE_PRIMARY], [(int)$additional->id, Credential::ROLE_ADDITIONAL]],
            self::roles($template->credentialSnapshot())
        );
    }

    /**
     * The edit form, rendered for such a submission without the
     * controller's reset: every credential is offered as an additional one,
     * as for no primary credential.
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testTheFormWithACredentialBeyondTheIntRangeOffersEveryCredential(string $number): void
    {
        ['template' => $template, 'primary' => $primary, 'additional' => $additional] = $this->templateWithCredentials();
        $template->setAttribute('credential_id', $this->decoded($number));

        $html = $this->renderedForm($template);

        $this->assertStringContainsString('id="cred-extra-' . (int)$primary->id . '"', $html);
        $this->assertStringContainsString('id="cred-extra-' . (int)$additional->id . '"', $html);
    }

    public function testTheFormLeavesThePrimaryCredentialOutOfTheAdditionalOnes(): void
    {
        ['template' => $template, 'primary' => $primary, 'additional' => $additional] = $this->templateWithCredentials();
        $template->setAttribute('credential_id', (string)$primary->id);

        $html = $this->renderedForm($template);

        $this->assertStringNotContainsString('id="cred-extra-' . (int)$primary->id . '"', $html);
        $this->assertStringContainsString('id="cred-extra-' . (int)$additional->id . '"', $html);
    }

    /**
     * The veto, asked without the integer rules that run before it in the
     * template's validation: an own rule beyond the int range is no rule,
     * an inventory beyond it no inventory, and neither vetoes.
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testTheVetoReadsANumberBeyondTheIntRangeAsNoId(string $number): void
    {
        $rule = $this->createApprovalRule((int)$this->admin->id);
        $other = $this->createApprovalRule((int)$this->admin->id);
        $template = $this->inventoryGated((int)$this->admin->id, $rule);
        $template->setAttribute('approval_rule_id', $this->decoded($number));

        $this->assertFalse(JobTemplateRuleAssignment::vetoesDifferentRules($template, false), 'an own rule that is no ID');
        $template->setAttribute('approval_rule_id', (int)$other->id);
        $template->setAttribute('inventory_id', $this->decoded($number));
        $this->assertFalse(JobTemplateRuleAssignment::vetoesDifferentRules($template, false), 'an inventory that is no ID');
        $this->assertSame([], $template->getErrors());
    }

    /**
     * Every form of the IDs keeps its answer: another own rule than the
     * inventory's is vetoed.
     *
     * @dataProvider idFormProvider
     */
    public function testTheVetoReadsEveryFormOfTheIds(string $form): void
    {
        $rule = $this->createApprovalRule((int)$this->admin->id);
        $other = $this->createApprovalRule((int)$this->admin->id);
        $template = $this->inventoryGated((int)$this->admin->id, $rule);
        $template->setAttribute('approval_rule_id', $this->decoded(sprintf($form, (int)$other->id)));
        $template->setAttribute('inventory_id', $this->decoded(sprintf($form, (int)$template->inventory_id)));

        $this->assertTrue(JobTemplateRuleAssignment::vetoesDifferentRules($template, false));
        $this->assertCount(1, $template->getErrors('approval_rule_id'));
        $template->clearErrors();
        $template->setAttribute('approval_rule_id', $this->decoded(sprintf($form, (int)$rule->id)));
        $this->assertFalse(JobTemplateRuleAssignment::vetoesDifferentRules($template, false), 'the inventory\'s own rule');
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * A stored template with a primary and an additional credential.
     *
     * @return array{template: JobTemplate, primary: Credential, additional: Credential}
     */
    private function templateWithCredentials(): array
    {
        $ownerId = (int)$this->admin->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
        $primary = $this->createCredential($ownerId);
        $additional = $this->createCredential($ownerId);
        JobTemplate::updateAll(['credential_id' => $primary->id], ['id' => $template->id]);
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => $template->id,
            'credential_id' => $additional->id,
            'sort_order' => 0,
        ])->execute();
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);

        return ['template' => $stored, 'primary' => $primary, 'additional' => $additional];
    }

    /**
     * @param list<array{id: int, name: string, credential_type: string, role: string}> $snapshot
     * @return list<array{0: int, 1: string}>
     */
    private static function roles(array $snapshot): array
    {
        return array_map(static fn (array $entry): array => [$entry['id'], $entry['role']], $snapshot);
    }

    /**
     * The JSON value $json as the request's JSON parser decodes it.
     */
    private function decoded(string $json): mixed
    {
        return $this->sendJsonText('{"value": ' . $json . '}')['value'];
    }

    /**
     * views/job-template/form.php for $template, with the parameters the
     * controller gives it, without the layout.
     */
    private function renderedForm(JobTemplate $template): string
    {
        $originals = \Yii::$app->getComponents(true);
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $previous = \Yii::$app->controller;
        $ctrl = new JobTemplateController('job-template', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setUrl('/job-template/update');
        try {
            /** @var array<string, mixed> $params */
            $params = (new \ReflectionMethod(JobTemplateController::class, 'formData'))->invoke($ctrl, $template, null);
            $html = $ctrl->renderPartial('form', $params);
        } finally {
            \Yii::$app->controller = $previous;
            \Yii::$app->set('view', $originals['view'] ?? null);
            \Yii::$app->set('assetManager', $originals['assetManager'] ?? null);
        }

        return $html;
    }
}
