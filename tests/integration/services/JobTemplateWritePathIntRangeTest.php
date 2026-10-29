<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\services\GatedInventoryChoice;
use app\services\GatedJobTemplateLock;
use app\services\JobTemplateGate;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Regression: the helpers of the job template write path read an attribute
 * that a form or a JSON body assigned, before the save validates it, with
 * is_numeric($x) ? (int)$x : ... (GatedJobTemplateLock::positiveId(),
 * GatedInventoryChoice::positiveId(), JobTemplateCredentialService::
 * positiveIntOrNull()), and the lock compared a column after its type cast,
 * which is an (int) cast for an integer column. A JSON number beyond the int
 * range (1e30, 9223372036854775808, -1e30, 1e999 = infinity) is assigned as
 * a float, and on PHP 8.5 its (int) cast warns: the save answered 500
 * (critic CR-01: JobTemplate::load(['JobTemplate' => ['inventory_id' =>
 * 1e30]]), then GatedJobTemplateLock::vetoes()).
 *
 * Now these helpers read an ID as the integer rule reads it (SubmittedId),
 * so the lock and the save use one value: such a number is no ID, which the
 * integer rule then refuses, and for the lock of a gated template it is a
 * change like any value but the stored one. Every form of an ID that the
 * integer rule accepts is checked as before.
 */
class JobTemplateWritePathIntRangeTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    /** JSON numbers beyond the int range, as a client writes them. */
    private const NUMBERS = ['1e30', '9223372036854775808', '-1e30', '1e999'];

    private User $admin;
    private User $operator;
    private ApprovalRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('ir-path-admin', 'admin');
        $this->operator = $this->createUserWithRole('ir-path-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function numberProvider(): array
    {
        $cases = [];
        foreach (self::NUMBERS as $number) {
            $cases[$number] = [$number];
        }

        return $cases;
    }

    /**
     * critic CR-01: a new template loaded with such IDs is no case for the
     * lock (no inventory, so none with a rule), and its save is refused by
     * the integer rules, with nothing written.
     *
     * @dataProvider numberProvider
     */
    public function testANewTemplateLoadedWithIdsBeyondTheIntRangeIsRefusedByItsRules(string $number): void
    {
        $ownerId = (int)$this->admin->id;
        $new = $this->newTemplateOn((int)$this->createProject($ownerId)->id, (int)$this->createInventory($ownerId)->id, $ownerId);
        $value = $this->decoded($number);
        $this->assertTrue($new->load(['JobTemplate' => ['inventory_id' => $value, 'credential_id' => $value]]));
        $audits = $this->templateAuditCount();

        $this->assertFalse((new GatedJobTemplateLock())->vetoes($new, [], (int)$this->operator->id, false));
        $this->assertFalse((new GatedJobTemplateLock())->vetoes($new, [], (int)$this->operator->id, true), 'inside the save too');
        $this->assertFalse($this->templateCredentials()->saveWithCredentials($new, [], [], (int)$this->operator->id));

        $this->assertFalse(GatedJobTemplateLock::refused($new));
        $this->assertSame('Inventory Id must be an integer.', $new->getFirstError('inventory_id'));
        $this->assertSame('Credential Id must be an integer.', $new->getFirstError('credential_id'));
        $this->assertTrue($new->isNewRecord, 'nothing was saved');
        $this->assertFalse(JobTemplate::find()->where(['name' => $new->name])->exists());
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
    }

    /**
     * For a user who may not change what a gated template runs, such a
     * number in any integer column is a change: no stored value is one. An
     * admin's is left to the validation.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lockedColumnProvider(): array
    {
        $cases = [];
        foreach (['project_id', 'inventory_id', 'credential_id', 'runner_group_id', 'verbosity', 'forks', 'timeout_minutes', 'become'] as $column) {
            foreach (self::NUMBERS as $number) {
                $cases["{$column}: {$number}"] = [$column, $number];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider lockedColumnProvider
     */
    public function testAGatedTemplateGivenANumberBeyondTheIntRangeIsLockedForOperators(string $column, string $number): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->rule);
        $template->setAttribute($column, $this->decoded($number));

        $this->assertTrue((new GatedJobTemplateLock())->vetoes($template, null, (int)$this->operator->id, false));
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, GatedJobTemplateLock::refusalOf($template));
        $template->clearErrors();
        $this->assertTrue((new GatedJobTemplateLock())->vetoes($template, null, (int)$this->operator->id, true), 'inside the save too');
        $template->clearErrors();
        $this->assertFalse((new GatedJobTemplateLock())->vetoes($template, null, (int)$this->admin->id, true), 'an admin is left to the validation');
    }

    /**
     * A whole float, as a JSON body gives 5.0, is compared after the
     * column's type cast as before: sent with the stored value, it is no
     * change, also in an ID column.
     */
    public function testAWholeFloatSentAsStoredIsNoChange(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->rule);
        $template->setAttribute('forks', (float)$template->forks);
        $template->setAttribute('verbosity', (float)$template->verbosity);
        $template->setAttribute('inventory_id', (float)$template->inventory_id);
        $template->setAttribute('become', 0.0);

        $this->assertFalse((new GatedJobTemplateLock())->vetoes($template, null, (int)$this->operator->id, true));

        $template->setAttribute('forks', (float)$template->forks + 1);
        $this->assertTrue((new GatedJobTemplateLock())->vetoes($template, null, (int)$this->operator->id, true), 'another whole float is a change');
    }

    /**
     * An ungated template given such an inventory or primary credential is
     * no case for the lock either: its rules refuse the number, and the
     * stored row and its credentials stay as they are.
     *
     * @dataProvider numberProvider
     */
    public function testAnUngatedTemplateGivenIdsBeyondTheIntRangeIsRefusedByItsRules(string $number): void
    {
        $template = $this->ungatedTemplate((int)$this->admin->id);
        $credential = $this->createCredential((int)$this->admin->id);
        JobTemplate::updateAll(['credential_id' => $credential->id], ['id' => $template->id]);
        $template = $this->reloaded($template);
        $before = $template->getAttributes();
        $audits = $this->templateAuditCount();
        $template->setAttribute('inventory_id', $this->decoded($number));
        $template->setAttribute('credential_id', $this->decoded($number));

        $this->assertFalse($this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->operator->id));

        $this->assertFalse(GatedJobTemplateLock::refused($template));
        $this->assertSame('Inventory Id must be an integer.', $template->getFirstError('inventory_id'));
        $this->assertSame('Credential Id must be an integer.', $template->getFirstError('credential_id'));
        $this->assertSame($before, $this->reloaded($template)->getAttributes(), 'nothing was saved');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
    }

    /**
     * Every form of an ID that the integer rule accepts is the ID it stores,
     * so the lock reads it as such: an operator may give a new template a
     * gated inventory in none of them.
     *
     * @return array<string, array{0: string}>
     */
    public static function acceptedFormProvider(): array
    {
        return [
            'the ID' => ['%d'],
            'its digits' => ['"%d"'],
            'its digits with a plus sign' => ['"+%d"'],
            'its digits with a leading zero' => ['"0%d"'],
            'a whole float' => ['%d.0'],
        ];
    }

    /**
     * @dataProvider acceptedFormProvider
     */
    public function testEveryFormOfAGatedInventorysIdIsStillRefused(string $form): void
    {
        $ownerId = (int)$this->admin->id;
        $gated = (int)$this->inventoryWithRule($ownerId, $this->rule)->id;
        $new = $this->newTemplateOn((int)$this->createProject($ownerId)->id, $gated, $ownerId);
        $new->setAttribute('inventory_id', $this->decoded(sprintf($form, $gated)));

        $this->assertFalse($this->templateCredentials()->saveWithCredentials($new, [], [], (int)$this->operator->id));

        $this->assertSame(GatedJobTemplateLock::GATED_INVENTORY_REFUSED, GatedJobTemplateLock::refusalOf($new));
        $this->assertTrue($new->isNewRecord, 'nothing was saved');
    }

    /**
     * A form that the integer rule refuses is no ID, so the lock does not
     * read the inventory it starts with, which the (int) cast had read; the
     * save is refused all the same, by the integer rule: no such value is
     * ever saved, so none passes the lock unchecked.
     *
     * @return array<string, array{0: string}>
     */
    public static function refusedFormProvider(): array
    {
        return [
            'a space before the digits' => ['" %d"'],
            'a space after the digits' => ['"%d "'],
            'a fraction' => ['%d.5'],
            'a fraction as text' => ['"%d.5"'],
            'an exponent as text' => ['"%de0"'],
        ];
    }

    /**
     * @dataProvider refusedFormProvider
     */
    public function testAFormOfAGatedInventorysIdThatTheIntegerRuleRefusesIsNeverSaved(string $form): void
    {
        $ownerId = (int)$this->admin->id;
        $gated = (int)$this->inventoryWithRule($ownerId, $this->rule)->id;
        $new = $this->newTemplateOn((int)$this->createProject($ownerId)->id, $gated, $ownerId);
        $new->setAttribute('inventory_id', $this->decoded(sprintf($form, $gated)));

        $this->assertFalse($this->templateCredentials()->saveWithCredentials($new, [], [], (int)$this->operator->id));

        $this->assertFalse(GatedJobTemplateLock::refused($new));
        $this->assertSame('Inventory Id must be an integer.', $new->getFirstError('inventory_id'));
        $this->assertTrue($new->isNewRecord, 'nothing was saved');
    }

    /**
     * The inventory side of the lock on its own: such a number names no
     * rule and no inventory, so it is no reason to refuse.
     *
     * @dataProvider numberProvider
     */
    public function testGatedInventoryChoiceReadsSuchANumberAsNoId(string $number): void
    {
        $ownerId = (int)$this->admin->id;
        $gated = (int)$this->inventoryWithRule($ownerId, $this->rule)->id;
        $new = $this->newTemplateOn((int)$this->createProject($ownerId)->id, $gated, $ownerId);
        $new->setAttribute('inventory_id', $this->decoded($number));

        $this->assertNull(GatedInventoryChoice::refusal((int)$this->operator->id, $new, null, [$gated => (int)$this->rule->id]));
        $this->assertNull(GatedInventoryChoice::storedSource($this->decoded($number), $gated, []), 'no rule of its own');
        $this->assertSame(
            JobTemplateGate::SOURCE_JOB_TEMPLATE,
            GatedInventoryChoice::storedSource((float)$this->rule->id, 0, []),
            'a whole float is the rule it names'
        );
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * $json as the request's JSON parser gives it: a JSON number beyond the
     * int range as a float, infinity for 1e999.
     */
    private function decoded(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
