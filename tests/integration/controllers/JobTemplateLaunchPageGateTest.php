<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApproverEligibility;
use app\services\JobLaunchService;
use app\services\JobTemplateGate;
use app\tests\integration\services\TemplateInventoryLockFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;

/**
 * The launch page says what a launch will wait for, and when this user's
 * launch could never be approved, before the click:
 * - #launch-approval-note (role status) with JobTemplateGate::launchNote():
 *   the rule of the job template, or of its inventory with its name;
 * - only on a GET, #launch-refusal (role alert) with JobTemplateGate::refusal(),
 *   the launch's own check: too few approvers besides the launcher, or two
 *   different rules. After a refused POST the danger flash carries the
 *   reason instead. The Launch Job button stays enabled.
 */
class JobTemplateLaunchPageGateTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    /** A name that a page must encode. */
    private const HOSTILE_NAME = '<img src=x onerror=alert(1)> "q" \'a\'';

    private User $admin;
    private User $operator;
    private User $approver;
    /** Approvers: the operator and another user who may decide; one approval. */
    private ApprovalRule $rule;
    /** Approvers: the operator alone; one approval, which nobody but the launcher could give. */
    private ApprovalRule $soloRule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('w3t-launch-admin', 'admin');
        $this->operator = $this->createUserWithRole('w3t-launch-operator', 'operator');
        $this->approver = $this->createUserWithRole('w3t-launch-approver', 'operator');
        $this->rule = $this->usersRule([(int)$this->operator->id, (int)$this->approver->id]);
        $this->soloRule = $this->usersRule([(int)$this->operator->id]);
    }

    public function testATemplateGatedThroughItsInventoryNamesTheRuleAndTheInventory(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->rule);
        $this->loginAs($this->operator);

        $html = $this->launchPage($template);

        $note = $this->element($html, 'launch-approval-note');
        $this->assertNotNull($note);
        $this->assertSame(
            sprintf(JobTemplateGate::LAUNCH_NOTE_INVENTORY, $this->rule->name, $this->inventoryNameOf($template)),
            trim($note->textContent)
        );
        $this->assertSame('alert alert-info', $note->getAttribute('class'));
        $this->assertSame('status', $note->getAttribute('role'));
        $this->assertNull($this->element($html, 'launch-refusal'), 'another approver may decide');
    }

    public function testATemplateWithItsOwnRuleNamesTheRule(): void
    {
        $template = $this->withOwnRule($this->ungatedTemplate((int)$this->admin->id), $this->rule);
        $this->loginAs($this->operator);

        $html = $this->launchPage($template);

        $this->assertSame(sprintf(JobTemplateGate::LAUNCH_NOTE, $this->rule->name), $this->textOf($html, 'launch-approval-note'));
        $this->assertNull($this->element($html, 'launch-refusal'));
    }

    public function testATemplateWithoutARuleSaysNothingAboutApproval(): void
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $this->loginAs($this->operator);

        $html = $this->launchPage($template);

        $this->assertNull($this->element($html, 'launch-approval-note'));
        $this->assertNull($this->element($html, 'launch-refusal'));
    }

    /**
     * Only the launcher could approve: the page says so before the click,
     * with the text the launch would refuse with, and keeps the button.
     */
    public function testALaunchThatCouldNeverBeApprovedIsRefusedBeforeTheClick(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->soloRule);
        $this->loginAs($this->operator);
        $expected = $this->eligibility()->jobLaunchRefusal($this->soloRule, $template, (int)$this->operator->id);
        $this->assertNotNull($expected);

        $html = $this->launchPage($template);

        $refusal = $this->element($html, 'launch-refusal');
        $this->assertNotNull($refusal);
        $this->assertSame($expected, trim($refusal->textContent));
        $this->assertSame('alert alert-warning', $refusal->getAttribute('class'));
        $this->assertSame('alert', $refusal->getAttribute('role'));
        $this->assertSame(sprintf(JobTemplateGate::LAUNCH_NOTE_INVENTORY, $this->soloRule->name, $this->inventoryNameOf($template)), $this->textOf($html, 'launch-approval-note'));
        $this->assertFalse($this->launchButton($html)->hasAttribute('disabled'), 'the button stays enabled');
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count(), 'a page view launches nothing');
    }

    /**
     * Another user, whom another approver may approve, sees no refusal on
     * the same template: the check is the launcher's.
     */
    public function testTheRefusalIsTheLaunchersOwn(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->soloRule);
        $this->loginAs($this->admin);

        $html = $this->launchPage($template);

        $this->assertNull($this->element($html, 'launch-refusal'));
        $this->assertNotNull($this->element($html, 'launch-approval-note'));
    }

    /**
     * After the click the danger flash says why; the page shows no refusal
     * of its own, but still the note.
     */
    public function testAfterARefusedLaunchOnlyTheFlashSaysWhy(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->soloRule);
        $this->loginAs($this->operator);
        $expected = $this->eligibility()->jobLaunchRefusal($this->soloRule, $template, (int)$this->operator->id);
        $this->assertNotNull($expected);
        $this->setPost([]);

        $html = $this->launchPage($template);

        $this->assertNull($this->element($html, 'launch-refusal'), 'only before the click');
        $this->assertNotNull($this->element($html, 'launch-approval-note'));
        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;
        $this->assertSame(['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, $expected)], $session->getAllFlashes());
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    /**
     * Two different rules: no note (the launch waits for neither) and the
     * launch's refusal.
     */
    public function testTwoDifferentRulesAreRefusedBeforeTheClick(): void
    {
        $template = $this->withOwnRule($this->inventoryGated((int)$this->admin->id, $this->rule), $this->soloRule);
        $this->loginAs($this->operator);

        $html = $this->launchPage($template);

        $this->assertNull($this->element($html, 'launch-approval-note'));
        $this->assertSame(
            sprintf(JobLaunchService::RULES_DIFFER, $template->id, $template->inventory_id),
            $this->textOf($html, 'launch-refusal')
        );
    }

    /**
     * The note and the refusal sit in the form's column, above the Template
     * Summary card.
     */
    public function testTheNoteAndTheRefusalSitAboveTheSummary(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->soloRule);
        $this->loginAs($this->operator);

        $html = $this->launchPage($template);

        $column = (int)strpos($html, '<div class="col-lg-7">');
        $note = (int)strpos($html, 'id="launch-approval-note"');
        $refusal = (int)strpos($html, 'id="launch-refusal"');
        $summary = (int)strpos($html, 'Template Summary');
        $this->assertGreaterThan($column, $note);
        $this->assertGreaterThan($note, $refusal);
        $this->assertGreaterThan($refusal, $summary);
    }

    public function testNamesAreEncoded(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->soloRule);
        ApprovalRule::updateAll(['name' => self::HOSTILE_NAME], ['id' => $this->soloRule->id]);
        Inventory::updateAll(['name' => self::HOSTILE_NAME], ['id' => $template->inventory_id]);
        $this->loginAs($this->operator);

        $html = $this->launchPage($this->reloaded($template));

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertSame(sprintf(JobTemplateGate::LAUNCH_NOTE_INVENTORY, self::HOSTILE_NAME, self::HOSTILE_NAME), $this->textOf($html, 'launch-approval-note'));
        $this->assertStringContainsString(
            Html::encode(sprintf(JobTemplateGate::LAUNCH_NOTE_INVENTORY, self::HOSTILE_NAME, self::HOSTILE_NAME)),
            $html
        );
        $refusal = (string)$this->textOf($html, 'launch-refusal');
        $this->assertStringContainsString('"<img src=x onerror=alert(1)> \"q\" \'a\'"', $refusal, 'the rule name, quoted');
    }

    // -- helpers ----------------------------------------------------------------------

    /**
     * The directory of the template views. A check of these tests against
     * other copies of the views overrides it.
     */
    protected function viewPath(): string
    {
        return '@app/views/job-template';
    }

    /**
     * The launch page of $template as the signed-in user gets it: a GET, or
     * a POST when the test set one.
     */
    private function launchPage(JobTemplate $template): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);
        $this->setQueryParams(['id' => (string)$template->id]);
        $ctrl = new JobTemplateController('job-template', $app);
        $ctrl->layout = false;
        $ctrl->setViewPath($this->viewPath());
        $html = $this->withRealViews($ctrl, '/job-template/launch?id=' . $template->id, static fn (): mixed => $ctrl->actionLaunch());
        $this->assertIsString($html);

        return $html;
    }

    /**
     * @param list<int> $userIds
     */
    private function usersRule(array $userIds): ApprovalRule
    {
        return $this->createApprovalRule(
            (int)$this->admin->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => $userIds])
        );
    }

    private function eligibility(): ApproverEligibility
    {
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');

        return $eligibility;
    }

    private function inventoryNameOf(JobTemplate $template): string
    {
        $inventory = Inventory::findOne($template->inventory_id);
        $this->assertNotNull($inventory);

        return (string)$inventory->name;
    }

    private function launchButton(string $html): \DOMElement
    {
        $nodes = $this->xpath($html)->query('//form[@id="launch-form"]//button[@type="submit"]');
        $this->assertInstanceOf(\DOMNodeList::class, $nodes);
        $button = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $button);

        return $button;
    }

    private function textOf(string $html, string $id): ?string
    {
        $element = $this->element($html, $id);

        return $element === null ? null : trim($element->textContent);
    }

    private function element(string $html, string $id): ?\DOMElement
    {
        $nodes = $this->xpath($html)->query('//*[@id="' . $id . '"]');
        $this->assertInstanceOf(\DOMNodeList::class, $nodes);
        $this->assertLessThanOrEqual(1, $nodes->length, "#{$id} at most once");
        $element = $nodes->item(0);

        return $element instanceof \DOMElement ? $element : null;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING));

        return new \DOMXPath($document);
    }
}
