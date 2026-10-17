<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\models\ApprovalRule;
use PHPUnit\Framework\TestCase;

/**
 * What the fixes of docs/updating.md (the section for the update from
 * 2.10.1) say about the approver count of the approval rule form is what
 * views/approval-rule/form.php does: its warning, as the script writes it,
 * and the note ApprovalRule::LAUNCHER_NOTE compare the required approvals
 * with the count of a role rule's approvers, which takes only the active
 * users assigned the role directly. Since 2.12.0 the controller counts them
 * (ApproverCounts: the direct assignments of every role in one query, and of
 * those users the ones ApproverFilter lets decide: an active account, and a
 * superadmin or holding approval.decide), and the form writes the counts
 * into its script. ApprovalRuleFormRoleCountTest renders the form and checks
 * the counts.
 *
 * Regression (wave 5f of 2.11.0): the count took inactive users too, so a
 * rule that needed more approvals than the role has active users could get
 * no warning, and nothing said so. Fails on the docs from before.
 */
class ApproverCountDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    /**
     * regression: the fixes did not name the count of a role rule's
     * approvers. They quote the warning with the numbers of an example and
     * the note, as the form shows them.
     */
    public function testRegressionTheFixesSayARoleRuleCountsItsActiveApproversOnly(): void
    {
        $fixes = $this->between($this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), '**Fixes that change behaviour:**', '**API contract change:**');
        $this->assertMentions(
            '- On the approval rule form, the count of a role rule\'s approvers, which the warning `' . $this->warning(3, 2) . '` and the note `' . ApprovalRule::LAUNCHER_NOTE
                . '` compare with the required approvals, takes only the active users assigned the role directly; it took inactive users too, so a rule that needed more approvals than the role has active users could get no warning.',
            $fixes,
            'The fixes of updating.md'
        );

        $form = $this->form();
        $this->assertStringContainsString("if (count > 0 && required > count) {\n            warningDiv.textContent = ", $form, 'The warning shows while the required approvals exceed the count.');
        $this->assertStringContainsString("launcherNote.style.display = count > 0 && required >= count ? '' : 'none';", $form, 'The note shows from as many required approvals as the count.');
        $this->assertStringContainsString('<?= Html::encode(ApprovalRule::LAUNCHER_NOTE) ?>', $form, 'The note is ApprovalRule::LAUNCHER_NOTE.');
        $this->assertStringContainsString("count = roleSelect.value ? (roleCounts[roleSelect.value] || 0) : 0;", $form, 'A role rule takes the count of its role.');
        $this->assertStringContainsString("var roleCounts = <?= Json::htmlEncode(\$approverCounts['roles']) ?>;", $form, 'The counts come from the controller.');
        $counts = $this->source('services/ApproverCounts.php');
        foreach (
            [
                '->from($this->auth->assignmentTable)',
                "->where(['item_name' => array_map('strval', array_keys(\$holders))])",
                '$filter->mayDecide(',
            ] as $part
        ) {
            $this->assertStringContainsString($part, $counts, 'The count takes the users assigned the role directly who may decide, all roles in one query.');
        }
        $this->assertStringContainsString(
            "->where(['id' => \$userIds, 'status' => User::STATUS_ACTIVE])",
            $this->source('services/ApproverFilter.php'),
            'Of them only active accounts may decide.'
        );
        $this->assertStringNotContainsString('getUserIdsByRole', $form, 'The count of 2.10.1, which took every assignment.');
        $this->assertStringNotContainsString('->getUserIdsByRole(', $counts, 'ApproverCounts reads the assignments of all roles at once.');
    }

    /**
     * The warning of the form for $required approvals and a count of
     * $count, built from the literal of its script.
     */
    private function warning(int $required, int $count): string
    {
        $literal = "'Warning: Required approvals (' + required + ') exceeds eligible approvers (' + count + ').'";
        $this->assertStringContainsString('warningDiv.textContent = ' . $literal . ';', $this->form(), 'The warning as the script writes it.');

        return 'Warning: Required approvals (' . $required . ') exceeds eligible approvers (' . $count . ').';
    }

    private function form(): string
    {
        return $this->source('views/approval-rule/form.php');
    }

    /**
     * The file $path of the repository, as it is.
     */
    private function source(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }
}
