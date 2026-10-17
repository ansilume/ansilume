<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\services\GatedJobTemplateLock;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Model;

/**
 * The template lock for job templates gated through their inventory
 * (GatedJobTemplateLock, GatedInventoryChoice): a template whose stored
 * inventory has an approval rule is locked like one with a rule of its own,
 * with its own texts (NOTICE_INVENTORY, CHANGE_REFUSED_INVENTORY), and only
 * users who may assign rules give a template, a new one too, an inventory
 * with a rule (GATED_INVENTORY_REFUSED). An inventory the user may not see
 * is never refused here: the template's validation says it does not exist.
 *
 * Lock order (C16): the template row FOR UPDATE first, then ONE statement
 * that share-locks the stored and the new inventory in ascending ID order,
 * then the write; a new template share-locks its inventory first.
 */
class GatedJobTemplateLockInventoryTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    private User $admin;
    private User $operator;
    private ApprovalRule $rule;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('invlock-admin', 'admin');
        $this->operator = $this->createUserWithRole('invlock-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->template = $this->inventoryGated((int)$this->admin->id, $this->rule);
    }

    // -- notice() and appliesTo() ------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function noticeProvider(): array
    {
        return [
            'an operator, a rule of its own' => ['operator', 'own', 'NOTICE'],
            'an operator, the rule of its inventory' => ['operator', 'inventory', 'NOTICE_INVENTORY'],
            'an operator, the same rule on both: its own counts' => ['operator', 'both', 'NOTICE'],
            'an operator, its own rule and another one of its inventory' => ['operator', 'differ', 'NOTICE'],
            'an operator, no rule' => ['operator', 'none', null],
            'a viewer, the rule of its inventory' => ['viewer', 'inventory', 'NOTICE_INVENTORY'],
            'an admin, the rule of its inventory' => ['admin', 'inventory', null],
            'an admin, a rule of its own' => ['admin', 'own', null],
            'a superadmin, the rule of its inventory' => ['superadmin', 'inventory', null],
            'a custom role with approval-rule.update' => ['assigner', 'inventory', null],
        ];
    }

    /**
     * The notice and appliesTo() follow what gates the stored template, its
     * own rule first, for every user who may not assign rules.
     *
     * @dataProvider noticeProvider
     */
    public function testTheNoticeNamesWhatGatesTheTemplate(string $role, string $gate, ?string $notice): void
    {
        $template = $this->templateGatedBy($gate);
        $user = match ($role) {
            'operator' => $this->operator,
            'viewer' => $this->createUserWithRole('invlock-viewer', 'viewer'),
            'admin' => $this->admin,
            'superadmin' => $this->superadminUser(),
            default => $this->customAssigner(),
        };
        $lock = new GatedJobTemplateLock();

        $expected = $notice === null ? null : constant(GatedJobTemplateLock::class . '::' . $notice);
        $this->assertSame($expected, $lock->notice((int)$user->id, $template));
        $this->assertSame($notice !== null, $lock->appliesTo((int)$user->id, $template));
    }

    public function testATemplateNotSavedYetHasNoNotice(): void
    {
        $new = $this->newTemplateOn((int)$this->template->project_id, (int)$this->template->inventory_id, (int)$this->admin->id);
        $new->setAttribute('approval_rule_id', (int)$this->rule->id);
        $lock = new GatedJobTemplateLock();

        $this->assertNull($lock->notice((int)$this->operator->id, $new));
        $this->assertFalse($lock->appliesTo((int)$this->operator->id, $new));
    }

    /**
     * The notice reads the inventory's rule as stored, and takes no lock: it
     * is asked by pages, outside any save.
     */
    public function testTheNoticeReadsTheStoredInventoryWithoutALock(): void
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $this->setRuleOfInventory((int)$template->inventory_id, $this->rule);
        $notice = null;

        $statements = $this->statementsOf(function () use ($template, &$notice): void {
            $notice = (new GatedJobTemplateLock())->notice((int)$this->operator->id, $template);
        });

        $this->assertSame(GatedJobTemplateLock::NOTICE_INVENTORY, $notice, 'a rule the inventory got after the template was loaded counts');
        $this->assertSame([], preg_grep('~(FOR UPDATE|LOCK IN SHARE MODE)$~', $statements), implode("\n", $statements));
    }

    // -- The stored inventory decides --------------------------------------------------

    public function testAnInventoryThatGetsARuleAfterTheTemplateWasLoadedLocksIt(): void
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $this->setRuleOfInventory((int)$template->inventory_id, $this->rule);
        $template->setAttribute('playbook', 'chosen.yml');

        $this->assertTrue($this->vetoes($template, null, true));
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, GatedJobTemplateLock::refusalOf($template));
    }

    public function testAnInventoryThatLosesItsRuleAfterTheTemplateWasLoadedUnlocksIt(): void
    {
        $template = $this->reloaded($this->template);
        $this->setRuleOfInventory((int)$template->inventory_id, null);
        $template->setAttribute('playbook', 'chosen.yml');

        $this->assertNull((new GatedJobTemplateLock())->notice((int)$this->operator->id, $template));
        $this->assertFalse($this->vetoes($template, null, false));
        $this->assertFalse($this->vetoes($template, null, true));
        $this->assertNull(GatedJobTemplateLock::refusalOf($template));
    }

    // -- What a template gated through its inventory runs is for admins ----------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function lockedColumnProvider(): array
    {
        return [
            'project' => ['project_id'],
            'inventory, to one without a rule' => ['inventory_id'],
            'primary credential' => ['credential_id'],
            'playbook' => ['playbook'],
            'extra vars' => ['extra_vars'],
            'verbosity' => ['verbosity'],
            'forks' => ['forks'],
            'become' => ['become'],
            'become method' => ['become_method'],
            'become user' => ['become_user'],
            'limit' => ['limit'],
            'tags' => ['tags'],
            'skip tags' => ['skip_tags'],
            'timeout' => ['timeout_minutes'],
            'runner group' => ['runner_group_id'],
            'survey' => ['survey_fields'],
        ];
    }

    /**
     * Re-pointing the template to an inventory without a rule would ungate
     * it: a change of a column, refused like any other.
     *
     * @dataProvider lockedColumnProvider
     */
    public function testEveryOtherColumnIsForAdmins(string $column): void
    {
        $template = $this->reloaded($this->template);
        $template->setAttribute($column, $this->changedValue($column));

        $this->assertTrue($this->vetoes($template, null, false));
        $this->assertSame([GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY], $template->getErrors(GatedJobTemplateLock::ERROR_KEY));
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, GatedJobTemplateLock::refusalOf($template));
        $template->clearErrors();
        $this->assertTrue($this->vetoes($template, null, true), 'under the row lock too');
        $template->clearErrors();
        $this->assertFalse($this->vetoes($template, null, true, (int)$this->admin->id), 'an admin may');
    }

    /**
     * Deny by default: every column but name and description, also one
     * added later.
     */
    public function testEveryColumnButNameAndDescriptionIsLocked(): void
    {
        $columns = array_diff(array_keys(JobTemplate::getTableSchema()->columns), GatedJobTemplateLock::OPEN_ATTRIBUTES);
        foreach ($columns as $column) {
            $template = $this->reloaded($this->template);
            $stored = $template->getAttribute($column);
            $phpType = JobTemplate::getTableSchema()->getColumn($column)?->phpType;
            $template->setAttribute($column, $phpType === 'integer' ? (is_numeric($stored) ? (int)$stored : 0) + 1 : $stored . 'x');

            $this->assertTrue($this->vetoes($template, null, true), $column);
            $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, GatedJobTemplateLock::refusalOf($template), $column);
        }
    }

    public function testNameAndDescriptionStayOpen(): void
    {
        $template = $this->reloaded($this->template);
        $template->setAttribute('name', 'renamed-' . uniqid('', true));
        $template->setAttribute('description', 'Another description.');

        $this->assertFalse($this->vetoes($template, null, false));
        $this->assertFalse($this->vetoes($template, null, true));
        $this->assertNull(GatedJobTemplateLock::refusalOf($template));
    }

    public function testTheCredentialListIsForAdmins(): void
    {
        $credential = $this->createCredential((int)$this->admin->id);
        $template = $this->reloaded($this->template);

        $this->assertTrue($this->vetoes($template, [(int)$credential->id], true));
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, GatedJobTemplateLock::refusalOf($template));
        $template->clearErrors();
        $this->assertFalse($this->vetoes($template, [], true), 'the stored list again (none)');
        $this->assertFalse($this->vetoes($template, [(int)$credential->id], true, (int)$this->admin->id), 'an admin may');
    }

    public function testEveryoneWhoMayAssignRulesChangesWhatItRuns(): void
    {
        foreach (['admin' => $this->admin, 'superadmin' => $this->superadminUser(), 'custom role' => $this->customAssigner()] as $kind => $user) {
            $template = $this->reloaded($this->template);
            $template->setAttribute('playbook', 'admin.yml');
            $template->setAttribute('inventory_id', (int)$this->createInventory((int)$this->admin->id)->id);

            $this->assertFalse($this->vetoes($template, null, false, (int)$user->id), $kind);
            $this->assertFalse($this->vetoes($template, null, true, (int)$user->id), $kind . ', under the row lock');
        }
    }

    /**
     * Its own rule comes first: a template with a rule of its own on an
     * inventory with a rule is refused with the template's text.
     */
    public function testATemplateWithItsOwnRuleKeepsTheTemplatesText(): void
    {
        foreach (['the same rule' => $this->rule, 'another rule' => $this->createApprovalRule((int)$this->admin->id)] as $kind => $own) {
            $template = $this->withOwnRule($this->template, $own);
            $template->setAttribute('playbook', 'other.yml');

            $this->assertTrue($this->vetoes($template, null, true), $kind);
            $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, GatedJobTemplateLock::refusalOf($template), $kind);
        }
    }

    // -- Giving a template an inventory with a rule is for admins ----------------------

    public function testAnOperatorMayNotRepointAnUngatedTemplateToAGatedInventory(): void
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $template->setAttribute('inventory_id', (int)$this->inventoryWithRule((int)$this->admin->id, $this->rule)->id);

        $this->assertTrue($this->vetoes($template, null, false));
        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, GatedJobTemplateLock::refusalOf($template));
        $template->clearErrors();
        $this->assertTrue($this->vetoes($template, null, true), 'under the row lock too');
        $template->clearErrors();
        $this->assertFalse($this->vetoes($template, null, true, (int)$this->admin->id), 'an admin may');
    }

    public function testAnOperatorRepointsAnUngatedTemplateToAnUngatedInventory(): void
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $template->setAttribute('inventory_id', (string)$this->createInventory((int)$this->admin->id)->id);

        $this->assertFalse($this->vetoes($template, null, false));
        $this->assertFalse($this->vetoes($template, null, true));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function newTemplateProvider(): array
    {
        return [
            'its ID' => ['int'],
            'its ID as the form posts it' => ['string'],
            'its ID with a leading zero' => ['zero'],
        ];
    }

    /**
     * @dataProvider newTemplateProvider
     */
    public function testAnOperatorMayNotGiveANewTemplateAGatedInventory(string $form): void
    {
        $gated = (int)$this->inventoryWithRule((int)$this->admin->id, $this->rule)->id;
        $new = $this->newTemplateOn((int)$this->template->project_id, $gated, (int)$this->admin->id);
        $new->setAttribute('inventory_id', match ($form) {
            'int' => $gated,
            'string' => (string)$gated,
            default => '0' . $gated,
        });

        $this->assertTrue($this->vetoes($new, [], false));
        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, GatedJobTemplateLock::refusalOf($new));
        $new->clearErrors();
        $this->assertTrue($this->vetoes($new, [], true), 'inside the save too');
        $new->clearErrors();
        $this->assertFalse($this->vetoes($new, [], true, (int)$this->admin->id), 'an admin may');
    }

    public function testANewTemplateOnAnInventoryWithoutARuleOrWithoutAnyIsNotRefused(): void
    {
        $projectId = (int)$this->template->project_id;
        $ownerId = (int)$this->admin->id;
        foreach (
            [
                'an inventory without a rule' => (int)$this->createInventory($ownerId)->id,
                'no inventory' => 0,
                'an inventory that does not exist' => 987654321,
            ] as $kind => $inventoryId
        ) {
            $new = $this->newTemplateOn($projectId, $inventoryId, $ownerId);
            if ($inventoryId === 0) {
                $new->setAttribute('inventory_id', null);
            }

            $this->assertFalse($this->vetoes($new, [], true), $kind);
        }
    }

    /**
     * On a default install no project is team-restricted, so the filter of
     * what the user may see is null: every inventory counts as visible, and
     * the refusal is a 403, never an SQL error.
     */
    public function testOnADefaultInstallEveryGatedInventoryIsRefused(): void
    {
        $this->assertDefaultInstall((int)$this->operator->id);
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $gated = $this->inventoryWithRule((int)$this->admin->id, $this->rule, (int)$this->createProject((int)$this->admin->id)->id);
        $template->setAttribute('inventory_id', (int)$gated->id);

        $this->assertTrue($this->vetoes($template, null, true));
        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, GatedJobTemplateLock::refusalOf($template));
    }

    /**
     * No existence oracle: an inventory the user may not see is left to the
     * template's validation ("does not exist"), whether it has a rule or
     * not. One the user's team only views is visible, so it is refused.
     */
    public function testAHiddenGatedInventoryIsNeverRefusedButAViewedOneIs(): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $own = $scope['own'];
        $hidden = $this->inventoryWithRule((int)$scope['admin']->id, $this->rule, (int)$scope['foreign']->project_id);
        $viewed = $this->inventoryWithRule((int)$scope['admin']->id, $this->rule, (int)$scope['viewed']->project_id);

        $template = $this->reloaded($own);
        $template->setAttribute('inventory_id', (int)$hidden->id);
        $this->assertFalse($this->vetoes($template, null, true, $member), 'a re-point to a hidden inventory');
        $new = $this->newTemplateOn((int)$own->project_id, (int)$hidden->id, (int)$scope['admin']->id);
        $this->assertFalse($this->vetoes($new, [], true, $member), 'a new template on a hidden inventory');

        $template->setAttribute('inventory_id', (int)$viewed->id);
        $this->assertTrue($this->vetoes($template, null, true, $member), 'an inventory of a project the team views');
        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, GatedJobTemplateLock::refusalOf($template));
        $outsider = (int)$scope['outsider']->id;
        $foreign = $this->newTemplateOn((int)$scope['foreign']->project_id, (int)$hidden->id, (int)$scope['admin']->id);
        $this->assertTrue($this->vetoes($foreign, [], true, $outsider), 'the team of that project sees it');
    }

    // -- The save (JobTemplateCredentialService) ------------------------------------------

    public function testARefusedSaveWritesNothingAndSaysWhy(): void
    {
        $this->loginAs($this->operator);
        $template = $this->reloaded($this->template);
        $template->setAttribute('playbook', 'chosen.yml');
        $audits = $this->templateAuditCount();

        $this->assertFalse($this->templateCredentials()->saveWithCredentials($template, null));

        $this->assertSame([GatedJobTemplateLock::ERROR_KEY => GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY], $template->getFirstErrors());
        $this->assertSame('site.yml', $this->reloaded($template)->playbook);
        $this->assertSame($audits, $this->templateAuditCount(), 'no audit entry');
    }

    /**
     * Regression: an operator changed what a job template gated through its
     * inventory runs; the save was written.
     */
    public function testRegressionAnOperatorChangedWhatATemplateGatedThroughItsInventoryRuns(): void
    {
        $template = $this->reloaded($this->template);
        $template->setAttribute('playbook', 'chosen.yml');

        $saved = $this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->operator->id);

        $this->assertFalse($saved, 'the save is refused');
        $this->assertSame('site.yml', $this->reloaded($template)->playbook, 'nothing is written');
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, $template->getFirstError(GatedJobTemplateLock::ERROR_KEY));
    }

    /**
     * Regression: an operator re-pointed an ungated job template to an
     * inventory with an approval rule, and so chose what the gated template
     * runs.
     */
    public function testRegressionAnOperatorRepointedAnUngatedTemplateToAGatedInventory(): void
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $stored = (int)$template->inventory_id;
        $template->setAttribute('inventory_id', (int)$this->inventoryWithRule((int)$this->admin->id, $this->rule)->id);

        $saved = $this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->operator->id);

        $this->assertFalse($saved, 'the save is refused');
        $this->assertSame($stored, (int)$this->reloaded($template)->inventory_id, 'nothing is written');
        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, $template->getFirstError(GatedJobTemplateLock::ERROR_KEY));
    }

    /**
     * Regression: an operator ungated a job template gated through its
     * inventory by re-pointing it to an inventory without a rule.
     */
    public function testRegressionAnOperatorUngatedATemplateByRepointingIt(): void
    {
        $template = $this->reloaded($this->template);
        $stored = (int)$template->inventory_id;
        $template->setAttribute('inventory_id', (int)$this->createInventory((int)$this->admin->id)->id);

        $saved = $this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->operator->id);

        $this->assertFalse($saved, 'the save is refused');
        $this->assertSame($stored, (int)$this->reloaded($template)->inventory_id, 'nothing is written');
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, $template->getFirstError(GatedJobTemplateLock::ERROR_KEY));
    }

    /**
     * Regression: an operator created a job template on an inventory with
     * an approval rule.
     */
    public function testRegressionAnOperatorGaveANewTemplateAGatedInventory(): void
    {
        $gated = (int)$this->inventoryWithRule((int)$this->admin->id, $this->rule)->id;
        $new = $this->newTemplateOn((int)$this->template->project_id, $gated, (int)$this->operator->id);

        $saved = $this->templateCredentials()->saveWithCredentials($new, [], [], (int)$this->operator->id);

        $this->assertFalse($saved, 'the save is refused');
        $this->assertTrue($new->isNewRecord);
        $this->assertFalse(JobTemplate::find()->where(['name' => $new->name])->exists(), 'nothing is written');
        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, $new->getFirstError(GatedJobTemplateLock::ERROR_KEY));
    }

    // -- Races: the decision is taken under the locks ------------------------------------

    /**
     * A rule the stored inventory gets while the save runs (here: during its
     * validation) is seen on the share-locked inventory row.
     */
    public function testAnInventoryThatGetsARuleWhileTheSaveRunsIsSeenUnderTheLock(): void
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $template->setAttribute('playbook', 'raced.yml');
        $template->on(Model::EVENT_AFTER_VALIDATE, function () use ($template): void {
            $this->setRuleOfInventory((int)$template->inventory_id, $this->rule);
        });
        $audits = $this->templateAuditCount();

        $this->assertFalse($this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->operator->id));

        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, GatedJobTemplateLock::refusalOf($template));
        $this->assertSame('site.yml', $this->reloaded($template)->playbook);
        $this->assertSame($audits, $this->templateAuditCount());
    }

    public function testAnInventoryChosenWhileItGetsARuleIsRefusedUnderTheLock(): void
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $stored = (int)$template->inventory_id;
        $target = $this->createInventory((int)$this->admin->id);
        $template->setAttribute('inventory_id', (int)$target->id);
        $template->on(Model::EVENT_AFTER_VALIDATE, function () use ($target): void {
            $this->setRuleOfInventory((int)$target->id, $this->rule);
        });

        $this->assertFalse($this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->operator->id));

        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, GatedJobTemplateLock::refusalOf($template));
        $this->assertSame($stored, (int)$this->reloaded($template)->inventory_id);
    }

    public function testANewTemplateWhoseInventoryGetsARuleWhileTheSaveRunsIsRefused(): void
    {
        $inventory = $this->createInventory((int)$this->admin->id);
        $new = $this->newTemplateOn((int)$this->template->project_id, (int)$inventory->id, (int)$this->operator->id);
        $new->on(Model::EVENT_AFTER_VALIDATE, function () use ($inventory): void {
            $this->setRuleOfInventory((int)$inventory->id, $this->rule);
        });

        $this->assertFalse($this->templateCredentials()->saveWithCredentials($new, [], [], (int)$this->operator->id));

        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, GatedJobTemplateLock::refusalOf($new));
        $this->assertFalse(JobTemplate::find()->where(['name' => $new->name])->exists());
    }

    // -- Lock order (C16) ----------------------------------------------------------------

    /**
     * The template row FOR UPDATE is the first statement of the save's
     * transaction, the share lock of its inventory the next one, and only
     * then the template is written.
     */
    public function testTheSaveLocksTheTemplateRowThenItsInventoryThenWrites(): void
    {
        $template = $this->reloaded($this->template);
        $template->setAttribute('name', 'renamed-' . uniqid('', true));
        $saved = false;

        $statements = $this->statementsOf(function () use ($template, &$saved): void {
            $saved = $this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->operator->id);
        });

        $this->assertTrue($saved);
        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $row = $this->statementIndex($statements, '~^SELECT \* FROM `job_template` WHERE `id` = ' . $template->id . ' FOR UPDATE$~');
        $update = $this->statementIndex($statements, '~^UPDATE `job_template` SET .* WHERE `id`=' . $template->id . '$~s');
        $this->assertSame($savepoint + 1, $row, implode("\n", $statements));
        $this->assertSame(
            [$row + 1 => [(int)$template->inventory_id]],
            $this->inventoryLocks($statements),
            "the inventory's share lock is the next statement, and the only one:\n" . implode("\n", $statements)
        );
        $this->assertGreaterThan($row + 1, $update);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function repointProvider(): array
    {
        return [
            'an admin, the stored inventory has the lower ID' => ['admin', true],
            'an admin, the stored inventory has the higher ID' => ['admin', false],
            'an operator, the stored inventory has the lower ID' => ['operator', true],
            'an operator, the stored inventory has the higher ID' => ['operator', false],
        ];
    }

    /**
     * A re-point share-locks the stored and the new inventory in ONE
     * statement, the lower ID first whichever of them is stored, right after
     * the template row; every later locking read of inventory rows names
     * only rows it locked already: the persist veto reads the new inventory
     * again, for whether it still exists and, with an own rule, for its rule
     * (JobTemplateRuleAssignment::persistVetoes()).
     *
     * @dataProvider repointProvider
     */
    public function testARepointLocksBothInventoriesInOneStatementInAscendingOrder(string $role, bool $storedIsLower): void
    {
        $admin = $role === 'admin';
        $ownerId = (int)$this->admin->id;
        $first = $this->inventoryWithRule($ownerId, $admin ? $this->rule : null);
        $second = $this->inventoryWithRule($ownerId, $admin ? $this->rule : null);
        [$stored, $target] = $storedIsLower ? [$first, $second] : [$second, $first];
        $template = $this->ungatedTemplate($ownerId);
        JobTemplate::updateAll(['inventory_id' => $stored->id, 'approval_rule_id' => $admin ? $this->rule->id : null], ['id' => $template->id]);
        $template = $this->reloaded($template);
        $template->setAttribute('inventory_id', (string)$target->id);
        $saved = false;

        $statements = $this->statementsOf(function () use ($template, $admin, &$saved): void {
            $saved = $this->templateCredentials()->saveWithCredentials($template, null, [], (int)($admin ? $this->admin->id : $this->operator->id));
        });

        $this->assertTrue($saved, (string)json_encode($template->errors));
        $this->assertSame((int)$target->id, (int)$this->reloaded($template)->inventory_id);
        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $row = $this->statementIndex($statements, '~^SELECT \* FROM `job_template` WHERE `id` = ' . $template->id . ' FOR UPDATE$~');
        $update = $this->statementIndex($statements, '~^UPDATE `job_template` SET .* WHERE `id`=' . $template->id . '$~s');
        $this->assertSame($savepoint + 1, $row, implode("\n", $statements));
        $locks = $this->inventoryLocks($statements);
        $this->assertSame([(int)$first->id, (int)$second->id], $locks[$row + 1] ?? null, "one statement, ascending:\n" . implode("\n", $statements));
        foreach ($locks as $index => $ids) {
            $this->assertSame([], array_diff($ids, [(int)$first->id, (int)$second->id]), "a later locking read names only locked rows:\n" . implode("\n", $statements));
            $this->assertLessThan($update, $index);
        }
        $this->assertSame(
            [(int)$target->id],
            array_values($locks)[1] ?? null,
            "the persist veto reads the new inventory again, locked already:\n" . implode("\n", $statements)
        );
        $this->assertCount(2, $locks, 'no other locking read of inventory rows');
    }

    /**
     * A new template has no row to lock: the share lock of its inventory is
     * the first statement of the save's transaction.
     */
    public function testANewTemplateShareLocksItsInventoryFirst(): void
    {
        $inventory = $this->inventoryWithRule((int)$this->admin->id, $this->rule);
        $new = $this->newTemplateOn((int)$this->template->project_id, (int)$inventory->id, (int)$this->admin->id);
        $new->setAttribute('approval_rule_id', (int)$this->rule->id);
        $saved = false;

        $statements = $this->statementsOf(function () use ($new, &$saved): void {
            $saved = $this->templateCredentials()->saveWithCredentials($new, [], [], (int)$this->admin->id);
        });

        $this->assertTrue($saved, (string)json_encode($new->errors));
        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $insert = $this->statementIndex($statements, '~^INSERT INTO `job_template`~');
        $locks = $this->inventoryLocks($statements);
        $this->assertSame([(int)$inventory->id], $locks[$savepoint + 1] ?? null, implode("\n", $statements));
        foreach ($locks as $index => $ids) {
            $this->assertSame([(int)$inventory->id], $ids);
            $this->assertGreaterThan($savepoint, $index);
            $this->assertLessThan($insert, $index);
        }
    }

    // -- Clones -------------------------------------------------------------------------

    /**
     * A clone keeps its source's inventory, and so the lock: copying is not
     * choosing, and the clone asks no lock.
     */
    public function testACloneKeepsItsInventoryAndWithItTheLock(): void
    {
        $this->loginAs($this->operator);
        $source = $this->reloaded($this->template);
        $clone = new JobTemplate();
        foreach ($source->getAttributes(null, ['id', 'created_at', 'updated_at', 'trigger_token', 'trigger_token_created_by', 'deleted_at']) as $name => $value) {
            $clone->setAttribute($name, $value);
        }
        $clone->name = $source->name . ' (copy)';
        $clone->created_by = (int)$this->operator->id;
        $clone->setCopiedFrom($source);

        $this->assertTrue($this->templateCredentials()->copyWithCredentials($clone, $source), (string)json_encode($clone->errors));

        $stored = $this->reloaded($clone);
        $this->assertSame((int)$source->inventory_id, (int)$stored->inventory_id);
        $this->assertSame(GatedJobTemplateLock::NOTICE_INVENTORY, (new GatedJobTemplateLock())->notice((int)$this->operator->id, $stored));
    }

    // -- helpers ------------------------------------------------------------------------

    /**
     * @param list<mixed>|null $additionalIds
     */
    private function vetoes(JobTemplate $template, ?array $additionalIds, bool $forUpdate, ?int $actorId = null): bool
    {
        return (new GatedJobTemplateLock())->vetoes($template, $additionalIds, $actorId ?? (int)$this->operator->id, $forUpdate);
    }

    /**
     * A template gated by $gate: 'own' (a rule of its own), 'inventory' (its
     * inventory's), 'both' (the same rule on both), 'differ' (its own and
     * another one of its inventory) or 'none'.
     */
    private function templateGatedBy(string $gate): JobTemplate
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $other = $gate === 'differ' ? $this->createApprovalRule((int)$this->admin->id) : $this->rule;
        $this->setRuleOfInventory((int)$template->inventory_id, in_array($gate, ['inventory', 'both', 'differ'], true) ? $other : null);

        return $this->withOwnRule($template, in_array($gate, ['own', 'both', 'differ'], true) ? $this->rule : null);
    }

    private function changedValue(string $column): mixed
    {
        $ownerId = (int)$this->admin->id;

        return match ($column) {
            'project_id' => (int)$this->createProject($ownerId)->id,
            'inventory_id' => (int)$this->createInventory($ownerId)->id,
            'credential_id' => (int)$this->createCredential($ownerId)->id,
            'runner_group_id' => (int)$this->createRunnerGroup($ownerId)->id,
            'playbook' => 'other.yml',
            'extra_vars' => '{"chosen_by": "operator"}',
            'verbosity' => 4,
            'forks' => 50,
            'become' => true,
            'become_method' => 'su',
            'become_user' => 'deploy',
            'limit' => 'web*',
            'tags' => 'deploy',
            'skip_tags' => 'slow',
            'timeout_minutes' => 5,
            'survey_fields' => '[]',
            default => $this->fail("No changed value for {$column}"),
        };
    }
}
