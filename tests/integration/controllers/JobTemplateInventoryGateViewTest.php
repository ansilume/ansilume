<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveyDefinition;
use app\controllers\JobTemplateController;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\services\GatedJobTemplateLock;
use app\services\JobTemplateInventoryOptions;
use app\services\JobTemplateRuleAssignment;
use app\services\LintService;
use app\services\ProjectAccessChecker;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\TemplateInventoryLockFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;

/**
 * The job template page and form for templates gated through their
 * inventory (JobTemplateGate: the template's own rule first, else its
 * inventory's):
 * - the page names the inventory's rule in its own row
 *   (#job-template-inventory-rule), beside the template's own rule, which
 *   #job-template-approval-rule keeps showing;
 * - users the template lock applies to get its notice as notice() words it
 *   (#job-template-locked-notice: NOTICE_INVENTORY for a template gated
 *   through its inventory) on the page and the locked form;
 * - the form offers inventories with a rule only to admins, marked with
 *   GATED_OPTION_SUFFIX; everyone else gets the inventories without a rule
 *   plus the stored one, and an unlocked form says gated ones were left out
 *   (#job-template-gated-inventories-hint); admins get the inventory's rule
 *   at the rule select (#job-template-inventory-rule-hint).
 */
class JobTemplateInventoryGateViewTest extends WebControllerTestCase
{
    use CountsQueries;
    use RendersRealViews;
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    /** A name that a page must encode. */
    private const HOSTILE_NAME = '<img src=x onerror=alert(1)> "q" \'a\'';

    /** @var array<string, mixed> component definitions the test replaced */
    private array $replaced = [];

    private User $admin;
    private User $operator;
    private ApprovalRule $rule;
    /** A job template without a rule of its own, on an inventory with $rule. */
    private JobTemplate $gated;
    /** A job template without any rule. */
    private JobTemplate $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replaced['lintService'] = \Yii::$app->getComponents(true)['lintService'] ?? null;
        \Yii::$app->set('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        $this->admin = $this->createUserWithRole('w3t-admin', 'admin');
        $this->operator = $this->createUserWithRole('w3t-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->gated = $this->inventoryGated((int)$this->admin->id, $this->rule);
        $this->plain = $this->ungatedTemplate((int)$this->admin->id);
    }

    protected function tearDown(): void
    {
        foreach ($this->replaced as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replaced = [];
        parent::tearDown();
    }

    // -- Regressions ----------------------------------------------------------------

    /**
     * regression: the form offered operators every inventory they could
     * see, inventories with an approval rule included, so they picked one
     * and the save was refused with a 403 (GATED_INVENTORY_REFUSED). Fails
     * on the controller and form of 3007a3b, which list the gated inventory.
     */
    public function testRegressionTheFormOfferedOperatorsInventoriesWithARule(): void
    {
        $gatedInventoryId = (int)$this->gated->inventory_id;
        $this->loginAs($this->operator);

        $pages = [
            'the create form' => $this->page('/job-template/create', static fn (JobTemplateController $c): mixed => $c->actionCreate()),
            'the edit form of a template without a rule' => $this->page(
                '/job-template/update?id=' . $this->plain->id,
                fn (JobTemplateController $c): mixed => $c->actionUpdate((int)$this->plain->id)
            ),
        ];

        foreach ($pages as $page => $html) {
            $options = $this->inventoryOptions($html);
            $this->assertArrayNotHasKey($gatedInventoryId, $options, "{$page}: the inventory with a rule is not offered");
            $this->assertArrayHasKey((int)$this->plain->inventory_id, $options, "{$page}: an inventory without a rule is");
            $this->assertSame(JobTemplateInventoryOptions::GATED_INVENTORIES_HINT, $this->textOf($html, 'job-template-gated-inventories-hint'), $page);
        }
    }

    /**
     * regression: the edit form of a job template gated only through its
     * inventory was open to operators, who could change what it runs.
     * Fails on the controller, form and GatedJobTemplateLock of 3007a3b,
     * which knew only the template's own rule.
     */
    public function testRegressionTheFormOfATemplateGatedThroughItsInventoryWasOpen(): void
    {
        $this->loginAs($this->operator);

        $html = $this->page('/job-template/update?id=' . $this->gated->id, fn (JobTemplateController $c): mixed => $c->actionUpdate((int)$this->gated->id));

        $this->assertStringContainsString('<fieldset id="job-template-run-fields" disabled>', $html, 'only name and description are enabled');
        $this->assertSame(GatedJobTemplateLock::NOTICE_INVENTORY, $this->textOf($html, 'job-template-locked-notice'));
    }

    /**
     * regression: the page of a job template showed only its own approval
     * rule ("None"), not the rule of its inventory that every launch waits
     * for. Fails on the view of 3007a3b, which has no such row.
     */
    public function testRegressionThePageDidNotNameTheRuleOfTheInventory(): void
    {
        $this->loginAs($this->admin);

        $html = $this->viewPage($this->gated);

        $row = $this->element($html, 'job-template-inventory-rule');
        $this->assertNotNull($row, 'the row of the inventory\'s rule');
        $this->assertSame(JobTemplateRuleAssignment::label($this->rule), trim($row->textContent));
        $this->assertSame("Inventory's approval rule", $this->labelOf($html, 'job-template-inventory-rule'));
        $this->assertSame('None', $this->textOf($html, 'job-template-approval-rule'), 'the template has no rule of its own');
    }

    // -- The page -------------------------------------------------------------------

    public function testThePageLinksTheRuleOfTheInventoryForUsersWhoMayViewRules(): void
    {
        $this->loginAs($this->operator);

        $html = $this->viewPage($this->gated);

        $links = $this->xpath($html)->query('//dd[@id="job-template-inventory-rule"]/a');
        $this->assertInstanceOf(\DOMNodeList::class, $links);
        $this->assertSame(1, $links->length);
        $link = $links->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame('/approval-rule/view?id=' . $this->rule->id, $link->getAttribute('href'));
        $this->assertSame(JobTemplateRuleAssignment::label($this->rule), $link->textContent);
        $this->assertStringContainsString(
            Html::a(Html::encode(JobTemplateRuleAssignment::label($this->rule)), ['/approval-rule/view', 'id' => $this->rule->id]),
            $html
        );
    }

    public function testThePageNamesTheRuleOfTheInventoryWithoutALinkForUsersWhoMayNotViewRules(): void
    {
        $this->loginAs($this->createUser('w3t-plain'));

        $html = $this->viewPage($this->gated);

        $this->assertSame(JobTemplateRuleAssignment::label($this->rule), $this->textOf($html, 'job-template-inventory-rule'));
        $this->assertStringNotContainsString('/approval-rule/view', $html);
    }

    public function testThePageOfATemplateWhoseInventoryHasNoRuleHasNoSuchRow(): void
    {
        $own = $this->withOwnRule($this->plain, $this->rule);
        $this->loginAs($this->admin);

        foreach (['own rule' => $own, 'no rule' => $this->plain] as $case => $template) {
            $html = $this->viewPage($template);
            $this->assertNull($this->element($html, 'job-template-inventory-rule'), $case);
            $this->assertStringNotContainsString('Inventory&#039;s approval rule', $html, $case);
        }
    }

    /**
     * The same rule on both: both rows show it, so removing the template's
     * own rule clearly keeps the gate.
     */
    public function testThePageShowsBothRowsWhenTheTemplateHasItsInventorysRule(): void
    {
        $both = $this->withOwnRule($this->gated, $this->rule);
        $this->loginAs($this->admin);

        $html = $this->viewPage($both);

        $label = JobTemplateRuleAssignment::label($this->rule);
        $this->assertSame($label, $this->textOf($html, 'job-template-approval-rule'));
        $this->assertSame($label, $this->textOf($html, 'job-template-inventory-rule'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function pageNoticeProvider(): array
    {
        return [
            'an operator, a template gated through its inventory' => ['operator', 'inventory', GatedJobTemplateLock::NOTICE_INVENTORY],
            'an operator, a template with its own rule and its inventory\'s' => ['operator', 'both', GatedJobTemplateLock::NOTICE],
            'an admin' => ['admin', 'inventory', null],
            'a viewer, who may change nothing' => ['viewer', 'inventory', null],
            'an operator, a template without a rule' => ['operator', 'none', null],
        ];
    }

    /**
     * @dataProvider pageNoticeProvider
     */
    public function testThePageTellsWhoMayNotChangeWhatTheTemplateRunsAndWhy(string $role, string $gate, ?string $notice): void
    {
        $template = match ($gate) {
            'inventory' => $this->gated,
            'both' => $this->withOwnRule($this->gated, $this->rule),
            default => $this->plain,
        };
        $this->loginAs(match ($role) {
            'admin' => $this->admin,
            'operator' => $this->operator,
            default => $this->createUserWithRole('w3t-viewer', 'viewer'),
        });

        $html = $this->viewPage($template);

        $this->assertSame($notice, $this->textOf($html, 'job-template-locked-notice'));
    }

    // -- The form -------------------------------------------------------------------

    /**
     * The locked form of an operator keeps the stored inventory selected,
     * marked, in the disabled select, and says nothing about inventories
     * left out: its select is disabled anyway.
     */
    public function testTheLockedFormKeepsTheStoredInventorySelected(): void
    {
        $this->loginAs($this->operator);

        $html = $this->page('/job-template/update?id=' . $this->gated->id, fn (JobTemplateController $c): mixed => $c->actionUpdate((int)$this->gated->id));

        $options = $this->inventoryOptions($html);
        $stored = $options[(int)$this->gated->inventory_id] ?? null;
        $this->assertNotNull($stored, 'the stored inventory is offered');
        $this->assertTrue($stored['selected']);
        $this->assertSame($this->inventoryOf($this->gated)->name . sprintf(JobTemplateInventoryOptions::GATED_OPTION_SUFFIX, $this->rule->name), $stored['label']);
        $this->assertNull($this->element($html, 'job-template-gated-inventories-hint'));
        $this->assertNull($this->element($html, 'job-template-inventory-rule-hint'));
    }

    public function testAdminsAreOfferedInventoriesWithARuleMarked(): void
    {
        $this->loginAs($this->admin);

        $html = $this->page('/job-template/create', static fn (JobTemplateController $c): mixed => $c->actionCreate());

        $options = $this->inventoryOptions($html);
        $this->assertSame(
            $this->inventoryOf($this->gated)->name . sprintf(JobTemplateInventoryOptions::GATED_OPTION_SUFFIX, $this->rule->name),
            $options[(int)$this->gated->inventory_id]['label'] ?? null
        );
        $this->assertSame((string)$this->inventoryOf($this->plain)->name, $options[(int)$this->plain->inventory_id]['label'] ?? null);
        $this->assertNull($this->element($html, 'job-template-gated-inventories-hint'));
        $this->assertNull($this->element($html, 'job-template-inventory-rule-hint'), 'a new template names no inventory yet');
    }

    public function testAdminsAreToldTheRuleOfTheTemplatesInventoryAtTheRuleSelect(): void
    {
        $this->loginAs($this->admin);

        $html = $this->page('/job-template/update?id=' . $this->gated->id, fn (JobTemplateController $c): mixed => $c->actionUpdate((int)$this->gated->id));

        $this->assertSame(
            sprintf(
                JobTemplateRuleAssignment::INVENTORY_RULE_HINT,
                SurveyDefinition::quote((string)$this->inventoryOf($this->gated)->name),
                SurveyDefinition::quote((string)$this->rule->name)
            ),
            $this->textOf($html, 'job-template-inventory-rule-hint')
        );
        $this->assertTrue($this->inventoryOptions($html)[(int)$this->gated->inventory_id]['selected'] ?? false);
        $this->assertNull($this->element($html, 'job-template-locked-notice'));

        $plain = $this->page('/job-template/update?id=' . $this->plain->id, fn (JobTemplateController $c): mixed => $c->actionUpdate((int)$this->plain->id));
        $this->assertNull($this->element($plain, 'job-template-inventory-rule-hint'), 'an inventory without a rule');
    }

    /**
     * An admin's rule other than the inventory's: the form again, with
     * RULES_DIFFER under the rule select and the inventory's rule beside it.
     */
    public function testARuleOtherThanTheInventorysShowsTheFormWithTheErrorAndTheHint(): void
    {
        $other = $this->createApprovalRule((int)$this->admin->id);
        $this->loginAs($this->admin);
        $this->setPost([
            'JobTemplate' => ['approval_rule_id' => (string)$other->id],
            JobTemplateController::KEEP_CREDENTIALS => '1',
        ]);

        $html = $this->page('/job-template/update?id=' . $this->gated->id, fn (JobTemplateController $c): mixed => $c->actionUpdate((int)$this->gated->id));

        $inventoryName = SurveyDefinition::quote((string)$this->inventoryOf($this->gated)->name);
        $ruleName = SurveyDefinition::quote((string)$this->rule->name);
        $this->assertStringContainsString(Html::encode(sprintf(JobTemplateRuleAssignment::RULES_DIFFER, $inventoryName, $ruleName)), $html);
        $this->assertSame(sprintf(JobTemplateRuleAssignment::INVENTORY_RULE_HINT, $inventoryName, $ruleName), $this->textOf($html, 'job-template-inventory-rule-hint'));
        $this->assertNull($this->reloaded($this->gated)->approval_rule_id, 'nothing saved');
    }

    /**
     * An inventory the operator may not see is neither offered nor counted
     * (no oracle): with no other inventory with a rule in sight, the form
     * says nothing about inventories left out.
     */
    public function testAnInventoryTheUserMayNotSeeIsNeitherOfferedNorCounted(): void
    {
        $hiddenProject = $this->createProject((int)$this->admin->id);
        $this->createTeamProject((int)$this->createTeam((int)$this->admin->id)->id, (int)$hiddenProject->id);
        Inventory::updateAll(['project_id' => $hiddenProject->id], ['id' => $this->gated->inventory_id]);
        $this->assertSame([], $this->visibleGatedInventoryIds((int)$this->operator->id), 'precondition: no inventory with a rule in sight');
        $this->loginAs($this->operator);

        $html = $this->page('/job-template/create', static fn (JobTemplateController $c): mixed => $c->actionCreate());

        $this->assertArrayNotHasKey((int)$this->gated->inventory_id, $this->inventoryOptions($html));
        $this->assertNull($this->element($html, 'job-template-gated-inventories-hint'));
    }

    /**
     * The form loads the inventories with their rules: more inventories
     * with a rule cost no more queries.
     */
    public function testTheFormCostsTheSameWithMoreInventoriesWithARule(): void
    {
        $this->loginAs($this->admin);
        $form = fn (): string => $this->page('/job-template/create', static fn (JobTemplateController $c): mixed => $c->actionCreate());
        $this->gatedInventories(2);
        $form();
        $few = $this->queriesOf($form);

        $this->gatedInventories(10);

        $this->assertSame($few, $this->queriesOf($form));
    }

    // -- Encoding -------------------------------------------------------------------

    /**
     * Names come out encoded: in the options, the hint at the rule select
     * and the page's row, as a link and as text.
     */
    public function testNamesAreEncodedInOptionsHintsAndRows(): void
    {
        ApprovalRule::updateAll(['name' => self::HOSTILE_NAME], ['id' => $this->rule->id]);
        Inventory::updateAll(['name' => self::HOSTILE_NAME], ['id' => $this->gated->inventory_id]);
        $rule = ApprovalRule::findOne($this->rule->id);
        $this->assertNotNull($rule);
        $this->loginAs($this->admin);

        $pages = [
            'the edit form' => $this->page('/job-template/update?id=' . $this->gated->id, fn (JobTemplateController $c): mixed => $c->actionUpdate((int)$this->gated->id)),
            'the page' => $this->viewPage($this->gated),
        ];
        $this->loginAs($this->createUser('w3t-plain-xss'));
        $pages['the page, as text'] = $this->viewPage($this->gated);

        foreach ($pages as $page => $html) {
            $this->assertStringNotContainsString('<img src=x', $html, $page);
        }
        $this->assertStringContainsString(
            Html::encode(self::HOSTILE_NAME . sprintf(JobTemplateInventoryOptions::GATED_OPTION_SUFFIX, self::HOSTILE_NAME)),
            $pages['the edit form']
        );
        $quoted = SurveyDefinition::quote(self::HOSTILE_NAME);
        $this->assertSame(sprintf(JobTemplateRuleAssignment::INVENTORY_RULE_HINT, $quoted, $quoted), $this->textOf($pages['the edit form'], 'job-template-inventory-rule-hint'));
        $this->assertSame(JobTemplateRuleAssignment::label($rule), $this->textOf($pages['the page'], 'job-template-inventory-rule'));
        $this->assertSame(JobTemplateRuleAssignment::label($rule), $this->textOf($pages['the page, as text'], 'job-template-inventory-rule'));
    }

    public function testTheHintOfOthersIsEncoded(): void
    {
        $this->loginAs($this->operator);

        $html = $this->page('/job-template/create', static fn (JobTemplateController $c): mixed => $c->actionCreate());

        $this->assertStringContainsString(
            '<div class="form-text mb-3" id="job-template-gated-inventories-hint">' . Html::encode(JobTemplateInventoryOptions::GATED_INVENTORIES_HINT) . '</div>',
            $html
        );
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
     * The page $action renders at $url, with the real views, without the
     * layout.
     *
     * @param callable(JobTemplateController): mixed $action
     */
    private function page(string $url, callable $action): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);
        $ctrl = new JobTemplateController('job-template', $app);
        $ctrl->layout = false;
        $ctrl->setViewPath($this->viewPath());
        $html = $this->withRealViews($ctrl, $url, static fn (): mixed => $action($ctrl));
        $this->assertIsString($html);

        return $html;
    }

    private function viewPage(JobTemplate $template): string
    {
        return $this->page('/job-template/view?id=' . $template->id, static fn (JobTemplateController $c): mixed => $c->actionView((int)$template->id));
    }

    private function inventoryOf(JobTemplate $template): Inventory
    {
        $inventory = Inventory::findOne($template->inventory_id);
        $this->assertNotNull($inventory);

        return $inventory;
    }

    /**
     * $count inventories with $this->rule, without a project.
     */
    private function gatedInventories(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        }
    }

    /**
     * The inventories with a rule that $userId may see, by the form's filter.
     *
     * @return list<int>
     */
    private function visibleGatedInventoryIds(int $userId): array
    {
        $query = Inventory::find()->select('inventory.id')->where(['not', ['inventory.approval_rule_id' => null]]);
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        $filter = $checker->buildChildResourceFilter($userId, 'inventory.project_id');
        $this->assertNotNull($filter, 'a team-restricted project exists');
        $query->andWhere($filter);

        return array_map('intval', $query->column());
    }

    /**
     * The options of the inventory select, by inventory ID.
     *
     * @return array<int, array{label: string, selected: bool}>
     */
    private function inventoryOptions(string $html): array
    {
        $nodes = $this->xpath($html)->query('//select[@id="jobtemplate-inventory_id"]/option[@value!=""]');
        $this->assertInstanceOf(\DOMNodeList::class, $nodes);
        $options = [];
        foreach ($nodes as $node) {
            $this->assertInstanceOf(\DOMElement::class, $node);
            $options[(int)$node->getAttribute('value')] = ['label' => $node->textContent, 'selected' => $node->hasAttribute('selected')];
        }

        return $options;
    }

    /**
     * The trimmed text of the element with $id; null when there is none.
     */
    private function textOf(string $html, string $id): ?string
    {
        $element = $this->element($html, $id);

        return $element === null ? null : trim($element->textContent);
    }

    /**
     * The text of the dt before the element with $id.
     */
    private function labelOf(string $html, string $id): string
    {
        $nodes = $this->xpath($html)->query('//*[@id="' . $id . '"]/preceding-sibling::dt[1]');
        $this->assertInstanceOf(\DOMNodeList::class, $nodes);
        $label = $nodes->item(0);
        $this->assertNotNull($label);

        return trim($label->textContent);
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
