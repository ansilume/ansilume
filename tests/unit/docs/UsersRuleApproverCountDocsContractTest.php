<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\ApproverConfigIds;
use app\components\SubmittedId;
use app\models\ApprovalRule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract: the update notes (docs/updating.md, under Fixes that change
 * behaviour of the section after 2.13.1) and the spec (required_approvals
 * of ApprovalRuleInput in web/openapi.yaml) say which users the count of a
 * users rule's required approvals takes (ApprovalRule::countEligibleApprovers()),
 * and quote its refusal in the words of the code.
 *
 * Regression (verification of the PHP 8.5 move): the count took every entry
 * of user_ids, so a users rule that requires more approvals than the users
 * it names was saved, and every launch it gates was then refused as one
 * that could never be approved. The spec said "at most the number of listed
 * users", and the notes said nothing.
 */
class UsersRuleApproverCountDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATING = 'docs/updating.md';

    private const FIXES = '**Fixes that change behaviour:**';

    private const BULLET = '- The required approvals of a `users` rule are compared with the users of its `user_ids`';

    /**
     * The bullet is one of the fixes that change behaviour of the section
     * after 2.13.1 (Php85UpdateDocsContractTest checks where that section
     * stands).
     */
    public function testTheBulletIsAFixThatChangesBehaviour(): void
    {
        $this->assertMentions(self::BULLET, implode("\n", $this->fixes()), 'the fixes that change behaviour after 2.13.1');
    }

    /**
     * The bullet says which entries count nothing, in the forms the code
     * reads (SubmittedId, ApproverConfigIds), quotes the refusal, and says
     * that a rule saved with such a list still gates its launches and is
     * refused at its next save.
     */
    public function testTheBulletSaysWhatCountsAndQuotesTheRefusal(): void
    {
        $this->assertSame([], ApproverConfigIds::usersOf(['user_ids' => ['abc', true, 12.5]]), 'no ID');
        $this->assertSame(7, SubmittedId::of('07'), '"07" is user 7');
        $bullet = $this->bullet();

        foreach (
            [
                'compared with the users of its `user_ids` who exist, active or not, each counted once, as its approvers are read:',
                'an entry that is no ID (`"abc"`, `true`, `12.5`), a user listed twice (also as `"07"` beside `7`) and a user'
                    . ' who does not exist, such as a deleted one, do not count.',
                'Up to 2.13.1 every entry counted, so `POST` and `PUT /api/v1/approval-rules` and the approval rule form saved'
                    . ' a rule that requires more approvals than that,',
                'They refuse it now with `' . sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 2, 1) . '` (`422` from the'
                    . ' API) and write nothing.',
                'The check runs when a rule is saved, not at a launch: a rule saved with such a list still gates its launches,'
                    . ' and its next save, also one that only renames it, is refused until the rule lists enough users or'
                    . ' requires fewer approvals.',
                'The form the browser sends names each chosen user once, and only active users, so it is checked as before.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $bullet, 'the bullet of the update notes');
        }
    }

    /**
     * The spec says which users count, and that an update which leaves the
     * approvers out is checked too; its example quotes the refusal.
     */
    public function testTheSpecSaysWhichUsersTheCountTakes(): void
    {
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $description = $spec['components']['schemas']['ApprovalRuleInput']['properties']['required_approvals']['description'] ?? null;
        $this->assertIsString($description);
        $text = (string)preg_replace('/\s+/', ' ', $description);

        foreach (
            [
                'For a `users` rule, at most the number of users it lists who exist, active or not, each counted once:',
                'an entry of `user_ids` that is no ID, a user listed twice (also as `"07"` beside `7`) and a user who does'
                    . ' not exist, such as a deleted one, do not count.',
                'More answers 422 `' . sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 3, 1) . '`, also on an update that'
                    . ' leaves `approver_config` out:',
            ] as $needle
        ) {
            $this->assertMentions($needle, $text, 'required_approvals of ApprovalRuleInput');
        }
        $this->assertStringNotContainsString('the number of listed users', $text);
        $this->assertSame(
            sprintf(ApprovalRule::TOO_MANY_REQUIRED_APPROVALS, 3, 1),
            $spec['components']['responses']['ApprovalRuleRejected']['content']['application/json']['examples']['tooFewApprovers']['value']['error']['message'] ?? null
        );
    }

    /**
     * The list items under Fixes that change behaviour in the newest
     * section, each on one line.
     *
     * @return list<string>
     */
    private function fixes(): array
    {
        $units = $this->units(self::UPDATING, Php85UpdateDocsContractTest::UPDATE_SECTION);
        $start = array_search(self::FIXES, $units, true);
        $this->assertIsInt($start, 'The newest section has the list ' . self::FIXES);
        $fixes = [];
        foreach (array_slice($units, $start + 1) as $unit) {
            if (!str_starts_with($unit, '- ')) {
                break;
            }
            $fixes[] = $unit;
        }

        return $fixes;
    }

    private function bullet(): string
    {
        foreach ($this->fixes() as $fix) {
            if (str_starts_with($fix, self::BULLET)) {
                return $fix;
            }
        }
        $this->fail('The newest section has no fix that starts with ' . self::BULLET);
    }
}
