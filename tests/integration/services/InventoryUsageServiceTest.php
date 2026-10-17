<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\services\InventoryUsageService;
use app\services\ProjectAccessChecker;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Which job templates use an inventory: the live ones, listed as far as
 * the viewer may see them with their own approval rule, and the
 * soft-deleted ones, counted; and which of them have an approval rule of
 * their own other than a given one.
 */
class InventoryUsageServiceTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    private InventoryUsageService $service;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryUsageService();
        $admin = $this->createUser('inventory-usage-admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
    }

    /**
     * config/services.php registers it for the web and console apps; the
     * tests' application, built from config/test.php, has it too.
     */
    public function testIsRegisteredAsAnApplicationComponentEverywhere(): void
    {
        /** @var array<string, array<string, mixed>> $services */
        $services = require dirname(__DIR__, 3) . '/config/services.php';

        $this->assertSame(InventoryUsageService::class, $services['inventoryUsageService']['class'] ?? null);
        $this->assertInstanceOf(InventoryUsageService::class, \Yii::$app->get('inventoryUsageService'));
    }

    // -- forInventory() -----------------------------------------------------------

    public function testTheLiveTemplatesAreListedByNameWithTheirOwnRule(): void
    {
        $inventory = $this->createInventory($this->adminId);
        $inventory->name = 'web-hosts';
        $inventory->save(false);
        $rule = $this->createApprovalRule($this->adminId);
        $gated = $this->templateOn($inventory, 'b-gated', $rule);
        $plain = $this->templateOn($inventory, 'a-plain', null);
        $this->templateOn($this->createInventory($this->adminId), 'other-inventory', null);

        $usage = $this->service->forInventory($inventory, $this->adminId);

        $this->assertTrue($usage->isInUse());
        $this->assertSame('web-hosts', $usage->inventoryName);
        $this->assertSame(2, $usage->jobTemplateTotal);
        $this->assertSame(0, $usage->deletedJobTemplateCount);
        $this->assertSame([
            [
                'id' => (int)$plain->id,
                'name' => 'a-plain',
                'project_id' => (int)$plain->project_id,
                'project_name' => $plain->project->name,
                'approval_rule_id' => null,
                'approval_rule_name' => null,
            ],
            [
                'id' => (int)$gated->id,
                'name' => 'b-gated',
                'project_id' => (int)$gated->project_id,
                'project_name' => $gated->project->name,
                'approval_rule_id' => (int)$rule->id,
                'approval_rule_name' => $rule->name,
            ],
        ], $usage->jobTemplates);
        $this->assertSame('Inventory "web-hosts" is in use by 2 job template(s).', $usage->summary());
    }

    /**
     * A soft-deleted job template keeps its inventory (NOT NULL, RESTRICT)
     * for the history of its jobs: it is counted, never listed.
     */
    public function testDeletedTemplatesAreCountedNotListed(): void
    {
        $inventory = $this->createInventory($this->adminId);
        $live = $this->templateOn($inventory, 'live', null);
        $this->templateOn($inventory, 'deleted-one', null)->softDelete();
        $this->templateOn($inventory, 'deleted-two', null)->softDelete();

        $usage = $this->service->forInventory($inventory, $this->adminId);

        $this->assertSame([(int)$live->id], array_column($usage->jobTemplates, 'id'));
        $this->assertSame(1, $usage->jobTemplateTotal);
        $this->assertSame(2, $usage->deletedJobTemplateCount);
        $this->assertSame(
            'Inventory ' . json_encode($inventory->name) . ' is in use by 1 job template(s) and 2 deleted job template(s).',
            $usage->summary()
        );
    }

    public function testAnInventoryThatOnlyDeletedTemplatesUseIsStillInUse(): void
    {
        $inventory = $this->createInventory($this->adminId);
        $this->templateOn($inventory, 'deleted', null)->softDelete();

        $usage = $this->service->forInventory($inventory, $this->adminId);

        $this->assertTrue($usage->isInUse());
        $this->assertSame([], $usage->jobTemplates);
        $this->assertSame(0, $usage->jobTemplateTotal);
        $this->assertSame(1, $usage->deletedJobTemplateCount);
        $this->assertSame(
            ['in_use' => true, 'job_templates' => [], 'hidden_job_template_count' => 0, 'deleted_job_template_count' => 1],
            $usage->toArray()
        );
    }

    public function testAnInventoryThatNoTemplateUsesIsNotInUse(): void
    {
        $inventory = $this->createInventory($this->adminId);
        $this->templateOn($this->createInventory($this->adminId), 'elsewhere', null);

        $usage = $this->service->forInventory($inventory, $this->adminId);

        $this->assertFalse($usage->isInUse());
        $this->assertSame(0, $usage->jobTemplateTotal);
        $this->assertSame(0, $usage->deletedJobTemplateCount);
        $this->assertSame('Inventory ' . json_encode($inventory->name) . ' is not in use.', $usage->summary());
    }

    /**
     * A team member sees the templates of the projects their teams operate
     * or view and of projects no team has; the others are counted as
     * hidden. Admins see every one; a missing viewer none.
     */
    public function testTheListHoldsOnlyWhatTheViewerMaySee(): void
    {
        $s = $this->teamScope();
        $inventory = $this->inventoryOf($s['own']);

        $usage = $this->service->forInventory($inventory, (int)$s['member']->id);

        $this->assertSame(4, $usage->jobTemplateTotal);
        $this->assertEqualsCanonicalizing(
            [(int)$s['own']->id, (int)$s['viewed']->id, (int)$s['open']->id],
            array_column($usage->jobTemplates, 'id')
        );
        $this->assertSame(1, $usage->hiddenJobTemplateCount());
        $this->assertStringNotContainsString((string)$s['foreign']->name, (string)json_encode($usage->toArray()));

        $this->assertCount(4, $this->service->forInventory($inventory, (int)$s['admin']->id)->jobTemplates, 'admins see every team');

        $nobody = $this->service->forInventory($inventory, null);
        $this->assertSame([], $nobody->jobTemplates);
        $this->assertSame(4, $nobody->hiddenJobTemplateCount());
        $this->assertTrue($nobody->isInUse());
    }

    /**
     * On a default install no project is team-restricted, so the visibility
     * filter is null: an operator gets every template listed, and the list
     * is read without an SQL error.
     */
    public function testWithoutTeamRestrictedProjectsEveryTemplateIsListed(): void
    {
        $this->assertFalse($this->projects()->hasRestrictedProjects(), 'precondition: no team-restricted project');
        $operator = $this->createUserWithRole('inventory-usage-operator', 'operator');
        $inventory = $this->createInventory($this->adminId);
        $first = $this->templateOn($inventory, 'first', null);
        $second = $this->templateOn($inventory, 'second', $this->createApprovalRule($this->adminId));

        $usage = $this->service->forInventory($inventory, (int)$operator->id);

        $this->assertSame([(int)$first->id, (int)$second->id], array_column($usage->jobTemplates, 'id'));
        $this->assertSame(0, $usage->hiddenJobTemplateCount());
        $this->assertSame(
            ['names' => ['second'], 'total' => 1],
            $this->service->templatesWithAnotherRule((int)$inventory->id, (int)$this->createApprovalRule($this->adminId)->id, (int)$operator->id)
        );
    }

    // -- templatesWithAnotherRule() -----------------------------------------------

    /**
     * Templates with the same rule or none, soft-deleted ones and those of
     * other inventories are left out; the names come by name.
     */
    public function testOnlyLiveTemplatesWithAnotherOwnRuleAreFound(): void
    {
        $inventory = $this->createInventory($this->adminId);
        $rule = $this->createApprovalRule($this->adminId);
        $other = $this->createApprovalRule($this->adminId);
        $third = $this->createApprovalRule($this->adminId);
        $this->templateOn($inventory, 'same-rule', $rule);
        $this->templateOn($inventory, 'no-rule', null);
        $this->templateOn($inventory, 'b-other-rule', $other);
        $this->templateOn($inventory, 'a-third-rule', $third);
        $this->templateOn($inventory, 'deleted-other-rule', $other)->softDelete();
        $this->templateOn($this->createInventory($this->adminId), 'elsewhere-other-rule', $other);

        $found = $this->service->templatesWithAnotherRule((int)$inventory->id, (int)$rule->id, $this->adminId);

        $this->assertSame(['names' => ['a-third-rule', 'b-other-rule'], 'total' => 2], $found);
    }

    public function testNoTemplateWithAnotherRule(): void
    {
        $inventory = $this->createInventory($this->adminId);
        $rule = $this->createApprovalRule($this->adminId);
        $this->templateOn($inventory, 'same-rule', $rule);
        $this->templateOn($inventory, 'no-rule', null);

        $this->assertSame(
            ['names' => [], 'total' => 0],
            $this->service->templatesWithAnotherRule((int)$inventory->id, (int)$rule->id, $this->adminId)
        );
    }

    /**
     * The names are those the viewer may see; the total counts them all.
     */
    public function testHiddenTemplatesWithAnotherRuleAreCountedNotNamed(): void
    {
        $s = $this->teamScope();
        $inventory = $this->inventoryOf($s['own']);
        $rule = $this->createApprovalRule((int)$s['admin']->id);
        $other = $this->createApprovalRule((int)$s['admin']->id);
        foreach (['own', 'foreign', 'open'] as $key) {
            $s[$key]->approval_rule_id = (int)$other->id;
            $s[$key]->save(false);
        }
        $s['viewed']->approval_rule_id = (int)$rule->id;
        $s['viewed']->save(false);
        $expected = [(string)$s['own']->name, (string)$s['open']->name];
        sort($expected, SORT_STRING);

        $member = $this->service->templatesWithAnotherRule((int)$inventory->id, (int)$rule->id, (int)$s['member']->id);

        $this->assertSame(['names' => $expected, 'total' => 3], $member);
        $this->assertCount(3, $this->service->templatesWithAnotherRule((int)$inventory->id, (int)$rule->id, (int)$s['admin']->id)['names']);
        $this->assertSame(
            ['names' => [], 'total' => 3],
            $this->service->templatesWithAnotherRule((int)$inventory->id, (int)$rule->id, null)
        );
    }

    // -- Cost ---------------------------------------------------------------------

    /**
     * Both reads cost the same number of statements for 2 and for 12
     * templates: projects and rules come with one query each, not one per
     * template.
     */
    public function testTheCostDoesNotGrowWithTheTemplates(): void
    {
        $rule = $this->createApprovalRule($this->adminId);
        $few = $this->inventoryUsedBy(2);
        $many = $this->inventoryUsedBy(12);
        // The first use of the models loads their table schemas.
        $this->service->forInventory($few, $this->adminId);
        $this->service->templatesWithAnotherRule((int)$few->id, (int)$rule->id, $this->adminId);

        $this->assertSame(
            $this->queriesOf(fn () => $this->service->forInventory($few, $this->adminId)),
            $this->queriesOf(fn () => $this->service->forInventory($many, $this->adminId))
        );
        $this->assertSame(
            $this->queriesOf(fn () => $this->service->templatesWithAnotherRule((int)$few->id, (int)$rule->id, $this->adminId)),
            $this->queriesOf(fn () => $this->service->templatesWithAnotherRule((int)$many->id, (int)$rule->id, $this->adminId))
        );
        $this->assertCount(12, $this->service->forInventory($many, $this->adminId)->jobTemplates);
        $this->assertSame(12, $this->service->templatesWithAnotherRule((int)$many->id, (int)$rule->id, $this->adminId)['total']);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * An inventory that $count templates use, each in a project of its own
     * and with an approval rule of its own.
     */
    private function inventoryUsedBy(int $count): Inventory
    {
        $inventory = $this->createInventory($this->adminId);
        for ($i = 0; $i < $count; $i++) {
            $this->templateOn($inventory, 'cost-template-' . $i, $this->createApprovalRule($this->adminId));
        }

        return $inventory;
    }

    private function templateOn(Inventory $inventory, string $name, ?ApprovalRule $rule): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$inventory->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
        $template->name = $name;
        $template->approval_rule_id = $rule === null ? null : (int)$rule->id;
        $template->save(false);

        return $template;
    }

    private function inventoryOf(JobTemplate $template): Inventory
    {
        $inventory = Inventory::findOne($template->inventory_id);
        $this->assertInstanceOf(Inventory::class, $inventory);

        return $inventory;
    }

    private function projects(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }
}
