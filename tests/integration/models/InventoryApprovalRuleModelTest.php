<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\tests\integration\DbTestCase;
use yii\validators\RequiredValidator;

/**
 * The rules of Inventory around its approval rule (m000088) and its
 * creator: both are set in code only, an unknown rule is refused before the
 * foreign key would answer with a server error, a dynamic inventory with a
 * rule needs a source path, and a model without the column (code running
 * before m000088) validates and saves as before.
 */
class InventoryApprovalRuleModelTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('inventory_rule')->id;
    }

    /**
     * regression: created_by was a safe attribute, so the inventory form
     * (and any load()) set who created an inventory: an edit could make
     * another user, or a user ID that does not exist, its creator.
     */
    public function testRegressionCreatedByWasMassAssignable(): void
    {
        $other = (int)$this->createUser('inventory_rule_other')->id;
        $new = new Inventory();

        $new->load(['Inventory' => $this->formValues() + ['created_by' => $other]]);

        $this->assertNull($new->created_by, 'load() leaves created_by alone');
        $this->assertNotContains('created_by', $new->safeAttributes());

        $stored = new Inventory($this->formValues());
        $stored->created_by = $this->userId;
        $this->assertTrue($stored->save(), implode(' ', $stored->getFirstErrors()));
        $stored->load(['Inventory' => ['name' => 'renamed', 'created_by' => 987654321]]);
        $this->assertSame('renamed', $stored->name, 'the form still sets the open fields');
        $this->assertSame($this->userId, (int)$stored->created_by);
        $this->assertTrue($stored->save(), implode(' ', $stored->getFirstErrors()));
        $stored->refresh();
        $this->assertSame($this->userId, (int)$stored->created_by);
    }

    public function testTheCreatorIsStillValidatedAsAnInteger(): void
    {
        $inventory = new Inventory($this->formValues());
        $inventory->created_by = 'nobody';

        $this->assertFalse($inventory->validate(['created_by']));
        $this->assertArrayHasKey('created_by', $inventory->getErrors());
    }

    /**
     * The rule that gates every launch with an inventory is for admins to
     * choose: no form or body sets it on its own (the write service does,
     * after its check).
     */
    public function testTheApprovalRuleIsSetInCodeOnly(): void
    {
        $rule = $this->createApprovalRule($this->userId);
        $inventory = new Inventory();

        $inventory->load(['Inventory' => $this->formValues() + ['approval_rule_id' => (int)$rule->id]]);

        $this->assertNull($inventory->getAttribute('approval_rule_id'));
        $this->assertNotContains('approval_rule_id', $inventory->safeAttributes());
        $this->assertTrue($inventory->hasAttribute('approval_rule_id'), 'the test database has m000088');
    }

    public function testAnExistingRuleIsAcceptedAndLoadedAsItsRelation(): void
    {
        $rule = $this->createApprovalRule($this->userId);
        $inventory = new Inventory($this->formValues());
        $inventory->created_by = $this->userId;
        $inventory->setAttribute('approval_rule_id', (int)$rule->id);

        $this->assertTrue($inventory->save(), implode(' ', $inventory->getFirstErrors()));

        $stored = Inventory::findOne($inventory->id);
        $this->assertInstanceOf(Inventory::class, $stored);
        $this->assertSame((int)$rule->id, (int)$stored->getAttribute('approval_rule_id'));
        $this->assertInstanceOf(ApprovalRule::class, $stored->approvalRule);
        $this->assertSame((int)$rule->id, (int)$stored->approvalRule->id);
        $this->assertNull($this->createInventory($this->userId)->approvalRule, 'no rule, no relation');
    }

    /**
     * An unknown rule ID is a validation error, not a foreign key failure
     * with a server error.
     */
    public function testAnUnknownRuleIsRefused(): void
    {
        $inventory = new Inventory($this->formValues());
        $inventory->created_by = $this->userId;
        $inventory->setAttribute('approval_rule_id', 987654321);

        $this->assertFalse($inventory->save());
        $this->assertSame(['approval_rule_id' => Inventory::APPROVAL_RULE_NOT_FOUND], $inventory->getFirstErrors());
        $this->assertSame('The selected approval rule does not exist.', $inventory->getFirstError('approval_rule_id'));
    }

    /**
     * The field is labelled as on a job template, so the API and the form
     * say 'Approval rule must be an integer.', not 'Approval Rule Id ...'.
     */
    public function testARuleIdThatIsNoIntegerIsRefusedByTheIntegerRuleOnly(): void
    {
        $inventory = new Inventory($this->formValues());
        $inventory->setAttribute('approval_rule_id', 'three');

        $this->assertFalse($inventory->validate(['approval_rule_id']));
        $this->assertSame(['Approval rule must be an integer.'], $inventory->getErrors('approval_rule_id'));
        $this->assertSame('Approval rule', $inventory->getAttributeLabel('approval_rule_id'));
    }

    /**
     * Without a source path, the jobs of a dynamic inventory would use the
     * default inventory of Ansible or of the repository's ansible.cfg, which
     * no rule gates.
     */
    public function testADynamicInventoryWithARuleNeedsASourcePath(): void
    {
        $rule = $this->createApprovalRule($this->userId);
        $gated = $this->dynamicInventory((int)$rule->id, null);

        $this->assertFalse($gated->validate());
        $this->assertSame([Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED], $gated->getErrors('source_path'));
        $this->assertSame(
            'A dynamic inventory with an approval rule needs a source path: without one, jobs use the default '
            . 'inventory of Ansible or of the repository\'s ansible.cfg, which no rule gates.',
            $gated->getFirstError('source_path')
        );

        $this->assertTrue($this->dynamicInventory((int)$rule->id, 'inventories/aws_ec2.yml')->validate());
        $this->assertTrue($this->dynamicInventory(null, null)->validate(), 'without a rule the path stays optional');
        $this->assertTrue($this->dynamicInventory(null, '')->validate());
    }

    public function testOnlyDynamicInventoriesNeedTheSourcePathForTheirRule(): void
    {
        $rule = $this->createApprovalRule($this->userId);
        $static = new Inventory($this->formValues());
        $static->setAttribute('approval_rule_id', (int)$rule->id);

        $this->assertTrue($static->validate(), implode(' ', $static->getFirstErrors()));
    }

    /**
     * The form checks the same in the browser: a dynamic inventory with a
     * rule chosen.
     */
    public function testTheBrowserChecksTheSourcePathOfAGatedDynamicInventoryToo(): void
    {
        $gatedRule = null;
        foreach ((new Inventory())->getActiveValidators('source_path') as $validator) {
            if ($validator instanceof RequiredValidator && $validator->message === Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED) {
                $gatedRule = $validator;
            }
        }

        $this->assertInstanceOf(RequiredValidator::class, $gatedRule);
        $this->assertSame(
            "function () { return $('#inventory-type').val() === 'dynamic' && ($('#inventory-approval_rule_id').val() || '') !== ''; }",
            $gatedRule->whenClient
        );
    }

    /**
     * Code running before m000088, or with a schema cached from before it:
     * the model has no approval_rule_id. It has none of the rule's rules,
     * reads no rule, and validates as in 2.11.1.
     */
    public function testAModelWithoutTheColumnValidatesAsBefore(): void
    {
        $inventory = new class () extends Inventory {
            public function attributes(): array
            {
                return array_values(array_diff(parent::attributes(), ['approval_rule_id']));
            }
        };
        $inventory->load(array_merge($this->formValues(), ['inventory_type' => Inventory::TYPE_DYNAMIC, 'content' => null]), '');

        $this->assertFalse($inventory->hasAttribute('approval_rule_id'));
        $this->assertNull($inventory->getAttribute('approval_rule_id'));
        $this->assertTrue($inventory->validate(), implode(' ', $inventory->getFirstErrors()));
        $this->assertSame([], $inventory->getActiveValidators('approval_rule_id'));
        $messages = array_map(
            static fn ($validator): mixed => $validator->message ?? null,
            $inventory->getActiveValidators('source_path')
        );
        $this->assertNotContains(Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED, $messages);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * What the inventory form posts for a static inventory without a project.
     *
     * @return array<string, mixed>
     */
    private function formValues(): array
    {
        return [
            'name' => 'gated-hosts-' . uniqid('', true),
            'description' => 'Production',
            'inventory_type' => Inventory::TYPE_STATIC,
            'content' => "all:\n  hosts:\n    web1.example.com:\n",
            'project_id' => '',
        ];
    }

    private function dynamicInventory(?int $ruleId, ?string $sourcePath): Inventory
    {
        $inventory = new Inventory($this->formValues());
        $inventory->inventory_type = Inventory::TYPE_DYNAMIC;
        $inventory->content = null;
        $inventory->source_path = $sourcePath;
        $inventory->setAttribute('approval_rule_id', $ruleId);

        return $inventory;
    }
}
