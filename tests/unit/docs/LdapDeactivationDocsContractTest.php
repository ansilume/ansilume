<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\commands\LdapController;
use app\components\LdapSyncDisabledBehavior;
use app\controllers\UserController;
use app\models\AuditLog;
use app\models\LoginForm;
use app\models\User;
use PHPUnit\Framework\TestCase;

/**
 * What the docs say about an LDAP account deactivated in Ansilume is what
 * the code does (CLAUDE.md security rule 15): the section of
 * docs/updating.md for the update from 2.10.1 (its intro, the item
 * **Security: LDAP accounts deactivated in Ansilume**, step 14 of its
 * checklist, the fixes, the API contract change, the known limits and the
 * migration), docs/ldap.md (lifecycle, security model, checklist) and the
 * troubleshooting entry, in the words of LoginForm, LdapController,
 * UserController, LdapSyncDisabledBehavior and the migration
 * m000086_000000_add_user_ldap_sync_disabled. The query of the item, and
 * what the migration decides and prints, run in
 * LdapDeactivationQueryDocsContractTest; the spec in
 * OpenApiUserLdapSyncMarkContractTest; step 13 in LdapSyncDocsContractTest.
 *
 * Regression (wave 5g of 2.11.0): up to 2.10.1, an LDAP sign-in and
 * ldap/sync activated every inactive LDAP account the directory had, one an
 * admin deactivated too, and the docs said so; nothing said how the update
 * decides which accounts the directory activates again. Each test fails on
 * the docs from before.
 */
class LdapDeactivationDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use ReadsTheUpdateSection;

    /** The item of the update section, by its title. */
    private const ITEM = '**Security: LDAP accounts deactivated in Ansilume.**';

    /** How the section refers to the item. */
    private const SEE = '(see **Security: LDAP accounts deactivated in Ansilume**)';

    /** The migration that adds and fills the mark. */
    private const MIGRATION = 'm000086_000000_add_user_ldap_sync_disabled';

    /** The troubleshooting entry. */
    private const ENTRY = '## An LDAP user cannot sign in: the account was deactivated in Ansilume';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
    }

    /**
     * regression: nothing said that an admin could not lock out an LDAP user
     * who was still in the directory. The intro and the item say what a
     * sign-in and ldap/sync did up to 2.10.1 and do now, quoting the refusal
     * of a sign-in (LoginForm), the line of ldap/sync (LdapController) and
     * the notes of the user page (UserController).
     */
    public function testRegressionTheItemSaysWhatASignInAndLdapSyncDoWithADeactivatedAccount(): void
    {
        $this->assertMentions(
            'An LDAP account deactivated in Ansilume stays inactive: the user\'s LDAP sign-in and `php yii ldap/sync` activate again only an account that `ldap/sync` deactivated because the directory no longer had the user '
                . self::SEE . '.',
            $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION),
            'The intro of updating.md'
        );
        $item = $this->item(self::ITEM);
        $expected = [
            'Up to 2.10.1, `php yii ldap/sync` activated again every inactive LDAP account that it found in the directory (`' . AuditLog::ACTION_LDAP_USER_REENABLED
                . '`), and so did each user\'s LDAP sign-in for their own account, also an account that an admin had deactivated with **Deactivate**, the Edit User form or `PUT /api/v1/users/{id}`: an admin could not lock out an LDAP user who was still in the directory.',
            'From 2.11.0 on, a sign-in or `ldap/sync` activates again only an account that `ldap/sync` deactivated because the directory no longer had the user.',
            'The new column `user.' . LdapSyncDisabledBehavior::COLUMN . '` marks such an account, and every other change of an account\'s status clears the mark.',
            'The sign-in of an account deactivated in Ansilume is refused with `' . LoginForm::INCORRECT_CREDENTIALS . '`, as every refused sign-in is; once the directory accepts the password, it is recorded as `'
                . AuditLog::ACTION_LDAP_LOGIN_FAILED . '` with the reason `' . LoginForm::DEACTIVATED_IN_ANSILUME . '`, and nothing of the account changes.',
            '`ldap/sync` takes the account\'s DN, email and roles from the directory, as for every user it finds, but leaves it inactive, prints `'
                . trim(sprintf(LdapController::SYNC_LEFT_INACTIVE, '<username>')) . '` (also with `--dry-run`) and counts it under `updated`, not `re-enabled`.',
            'The page of an inactive LDAP account says under its status which of the two it is, `' . UserController::DEACTIVATED_BY_LDAP_SYNC . '` or `'
                . UserController::DEACTIVATED_IN_ANSILUME . '`, and the users API answers the mark as `' . LdapSyncDisabledBehavior::COLUMN . '` (see **API contract change**).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The item of updating.md');
        }
        $sync = $this->source('commands/LdapController.php');
        $this->assertStringContainsString('$this->stdout(sprintf(self::SYNC_LEFT_INACTIVE, $username));', $sync, 'ldap/sync prints the line the item quotes.');
        $this->assertStringContainsString("\$this->stdout(\"[ldap] Done. updated={\$counts['updated']} disabled={\$counts['disabled']} \"\n            . \"re-enabled={\$counts['reEnabled']}", $sync, 'The counts the item names.');
        $this->assertStringContainsString('if ($existing !== null && $provisioner->staysInactive($existing)) {', $this->source('models/LoginForm.php'), 'A sign-in checks the account before it changes it.');
    }

    /**
     * regression: nothing said how the update decides which inactive
     * accounts the directory activates again. The item names the audit
     * actions the migration weighs, as it weighs them, and quotes what it
     * prints; the database item names the migration, and the rollback drops
     * its column first with migrate/down 1, which reverts it on 2.11.0,
     * where it is the latest migration.
     */
    public function testRegressionTheItemSaysWhatTheMigrationDecides(): void
    {
        $actions = $this->statusActions();
        $this->assertSame(
            [
                AuditLog::ACTION_LDAP_USER_DISABLED,
                AuditLog::ACTION_LDAP_USER_REENABLED,
                AuditLog::ACTION_LDAP_USER_PROVISIONED,
                AuditLog::ACTION_USER_CREATED,
                AuditLog::ACTION_USER_UPDATED,
                AuditLog::ACTION_USER_STATUS_CHANGED,
            ],
            $actions,
            'The audit actions the migration weighs.'
        );
        $last = (string)array_pop($actions);
        $item = $this->item(self::ITEM);
        $expected = [
            'The migration decides the mark of every account from the audit log, and leaves it unset wherever the log does not show that `ldap/sync` deactivated the account: it marks an inactive LDAP account whose latest entry, by ID, among `'
                . implode('`, `', $actions) . '` and `' . $last . '` is `' . AuditLog::ACTION_LDAP_USER_DISABLED . '`, and no other account.',
            'An inactive LDAP account that was changed in Ansilume after `ldap/sync` deactivated it, even without a change of its status, or that has none of these entries, stays inactive until an admin activates it.',
            'The migration prints what it decided to the output of the app container (`docker compose logs app`), for example: ``` ' . trim(sprintf($this->migrationText('MARKED'), 2))
                . ' ' . trim(sprintf($this->migrationText('LEFT_UNMARKED'), 1)) . ' ```',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The item of updating.md');
        }
        $this->assertSame(LdapSyncDisabledBehavior::COLUMN, (new \ReflectionClassConstant(self::MIGRATION, 'COLUMN'))->getValue(), 'The column the migration adds.');

        $section = $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $this->assertMentions(
            'The update has one migration, which adds `user.' . LdapSyncDisabledBehavior::COLUMN . '` and fills it from the audit log ' . self::SEE . ';',
            $section,
            'The intro of updating.md'
        );
        $this->assertStringNotContainsString('The update has no migration', $section);
        $deployment = $this->item('**Database and deployment.**');
        $this->assertMentions(
            '**Database and deployment.** One migration runs, `' . self::MIGRATION . '`: it adds `user.' . LdapSyncDisabledBehavior::COLUMN
                . '` (boolean, default 0) and fills it from the audit log ' . self::SEE . '; no setting is new.',
            $deployment,
            'Database and deployment of updating.md'
        );
        $this->assertMentions(
            'To roll back to 2.10.1, first drop the new column with `docker compose exec -u www-data app php yii migrate/down 1 --interactive=0`, which reverts `' . self::MIGRATION
                . '`, so that the next update fills it from the audit log again (2.10.1 ignores the column but does not keep its mark up to date), then deploy 2.10.1:',
            $deployment,
            'Database and deployment of updating.md'
        );
        $this->assertStringNotContainsString('nothing to undo in the database', $deployment);
        $this->assertMentions(
            'To roll back from 2.11.0 (migration `m000086`), revert its migration before you deploy the older version, see **Database and deployment** under [After updating from 2.10.1 or older](#'
                . WorkflowStepsUpdateDocsContractTest::SECTION_ANCHOR . ').',
            $this->flat('docs/updating.md', '## Rollback'),
            'The Rollback steps of updating.md'
        );
        $this->assertSame(
            1,
            preg_match('/public function down\(\): void\s*\{\s*if \(\$this->hasColumn\(\)\) \{\s*\$this->dropColumn\(self::TABLE, self::COLUMN\);/', $this->source('migrations/' . self::MIGRATION . '.php')),
            'Reverting the migration drops the column.'
        );
    }

    /**
     * Step 14 of the checklist points to the query of the item and quotes
     * the note of the user page; step 4 names the new field; the fixes, the
     * API contract change and the known limits say what changed and what
     * did not.
     */
    public function testTheChecklistTheFixesTheApiAndTheLimitsSayWhatChanged(): void
    {
        $this->assertMentions(
            '14. If LDAP is enabled, run the read-only query under **Security: LDAP accounts deactivated in Ansilume**, and deactivate again every account it lists that should stay inactive.',
            $this->checklist(14),
            'Step 14 of the checklist'
        );
        $this->assertMentions(
            'Then open the page of each inactive LDAP account from the Users page: an account whose page says `' . UserController::DEACTIVATED_IN_ANSILUME
                . '` stays inactive whatever the directory says, so activate it where the user should sign in again.',
            $this->checklist(14),
            'Step 14 of the checklist'
        );
        $this->assertMentions('that every user carries `' . LdapSyncDisabledBehavior::COLUMN . '`,', $this->checklist(4), 'Step 4 of the checklist');

        $section = $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $fixes = $this->between($section, '**Fixes that change behaviour:**', '**API contract change:**');
        $this->assertMentions(
            '- An LDAP account deactivated in Ansilume was activated again by the user\'s next LDAP sign-in or by the next `ldap/sync` that found them; only an account that `ldap/sync` deactivated is activated again now '
                . self::SEE . '.',
            $fixes,
            'The fixes of updating.md'
        );
        $this->assertMentions(
            '- While LDAP was enabled, the sign-in form took the right password of an inactive local user, though the session ended with the next request; it answers `'
                . LoginForm::INCORRECT_CREDENTIALS . '` now, as without LDAP.',
            $fixes,
            'The fixes of updating.md'
        );
        $this->assertStringContainsString('if (!$user->validatePassword($this->password) || !$user->isActive()) {', $this->source('models/LoginForm.php'), 'An inactive local user is refused.');

        $this->assertMentions(
            '- Every user that `GET /api/v1/users`, `GET /api/v1/users/{id}`, `POST /api/v1/users` and `PUT /api/v1/users/{id}` return has `' . LdapSyncDisabledBehavior::COLUMN
                . '` (boolean, read-only): `true` for an inactive LDAP account that `ldap/sync` deactivated because the directory no longer had the user, which the user\'s LDAP sign-in or an `ldap/sync` that finds them activates again; `false` for every other account. `POST` and `PUT` ignore it in the body; a `PUT` that changes `status` clears it',
            $this->between($section, '**API contract change:**', '**Database and deployment.**'),
            'The API contract change of updating.md'
        );
        $this->assertStringContainsString("'ldap_sync_disabled' => (bool)\$u->ldap_sync_disabled,", $this->source('controllers/api/v1/UsersController.php'), 'The API answers the mark as a boolean.');

        $limits = $this->item('**Known limits.**');
        foreach (
            [
                '- An inactive LDAP account that `ldap/sync` deactivated keeps its mark when it is saved without a change of its status (the Edit User form, `PUT /api/v1/users/{id}`), so the directory still activates it again.'
                    . ' To keep such an account inactive whatever the directory says, **Activate** it and **Deactivate** it again; it is active in between.',
                '- Only changes of an account\'s status made through Ansilume clear the mark: a status changed directly in the database keeps it.',
            ] as $limit
        ) {
            $this->assertMentions($limit, $limits, 'The known limits of updating.md');
        }
        $behavior = $this->source('components/LdapSyncDisabledBehavior.php');
        $this->assertStringContainsString('if ($statusChanged && !$markChanged) {', $behavior, 'Only a change of the status clears the mark.');
        $this->assertStringContainsString('BaseActiveRecord::EVENT_BEFORE_UPDATE', $behavior, 'Only saves of the model clear the mark.');
    }

    /**
     * regression: ldap.md said that the next bind or ldap/sync activates
     * every account the directory has again, and named no way to lock out a
     * user who is still in the directory. The lifecycle table, the audit
     * list, the security model and the checklist say so now.
     */
    public function testRegressionLdapMdSaysThatADeactivationInAnsilumeStays(): void
    {
        $lifecycle = $this->flat('docs/ldap.md', '## Lifecycle: enable, disable, re-enable');
        $expected = [
            '| Account deactivated in Ansilume (**Deactivate**, the Edit User form, `PUT /api/v1/users/{id}`) | Stays inactive whatever the directory says: a successful bind is refused with the generic error and audited as `'
                . AuditLog::ACTION_LDAP_LOGIN_FAILED . '` (`' . LoginForm::DEACTIVATED_IN_ANSILUME . '`); `ldap/sync` updates its DN, email and roles and prints `'
                . trim(sprintf(LdapController::SYNC_LEFT_INACTIVE, '<username>')) . '`. The user page says so under the status. |',
            '- `' . AuditLog::ACTION_LDAP_USER_REENABLED . '` — an account `ldap/sync` disabled, activated again by a bind or `ldap/sync` once the directory has the user',
            '- `' . AuditLog::ACTION_LDAP_LOGIN_FAILED . '` — directory rejected the bind, or the account was deactivated in Ansilume (separate from local failures)',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $lifecycle, 'The lifecycle of ldap.md');
        }
        $this->assertStringNotContainsString('restored after re-appearing in the directory', $lifecycle);
        $this->assertMentions(
            '- An account an admin deactivates in Ansilume stays inactive whatever the directory says: to lock out an LDAP user who is still in the directory, deactivate the account in Ansilume.',
            $this->flat('docs/ldap.md', '## Security model'),
            'The security model of ldap.md'
        );
        $this->assertMentions(
            '- [ ] LDAP users who must not sign in, also while the directory still has them, are deactivated in Ansilume: the directory does not activate such an account again.',
            $this->flat('docs/ldap.md', '## Operational checklist'),
            'The checklist of ldap.md'
        );
    }

    /**
     * The troubleshooting entry says that the refused sign-in of an LDAP
     * account deactivated in Ansilume is expected, in the words of the code,
     * and how to let the user in again.
     */
    public function testTheTroubleshootingEntrySaysTheRefusalIsExpected(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRY);
        $expected = [
            '**Symptom:** The sign-in of an LDAP user is refused with `' . LoginForm::INCORRECT_CREDENTIALS
                . '`, though the directory has the user and the password is right: `php yii ldap/test-user <username>` finds them.',
            'The audit log records `' . AuditLog::ACTION_LDAP_LOGIN_FAILED . '` with the reason `' . LoginForm::DEACTIVATED_IN_ANSILUME . '`, `php yii ldap/sync` prints `'
                . trim(sprintf(LdapController::SYNC_LEFT_INACTIVE, '<username>')) . '`, and the user page says `' . UserController::DEACTIVATED_IN_ANSILUME . '` under the status.',
            '**Cause:** This is expected.',
            'From 2.11.0 on, an LDAP sign-in or `ldap/sync` activates again only an account that `ldap/sync` deactivated because the directory no longer had the user (`user.'
                . LdapSyncDisabledBehavior::COLUMN . '`), so that an admin can lock out an LDAP user who is still in the directory.',
            '**Fix:** If the user should sign in again, activate the account on their page (open it from the Users page, then **Activate**) or with `PUT /api/v1/users/{id}` and `status` '
                . User::STATUS_ACTIVE . '.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $entry, 'troubleshooting.md, on a deactivated LDAP account');
        }
    }

    /**
     * CLAUDE.md's rule 15 is what the code does: the column, the writers
     * that clear the mark, and the only accounts a sign-in or ldap/sync
     * activates again.
     */
    public function testClaudeMdRule15IsWhatTheCodeDoes(): void
    {
        $this->assertMentions(
            '15. An account deactivated in Ansilume (the status toggle, the Edit User form, `PUT /api/v1/users/{id}`) stays inactive whatever the directory says: an LDAP sign-in or `ldap/sync` activates again only an account that `ldap/sync` deactivated because the directory no longer had the user (`user.'
                . LdapSyncDisabledBehavior::COLUMN . '`), and every other change of an account\'s status clears that mark.',
            $this->flat('CLAUDE.md'),
            'Rule 15 of CLAUDE.md'
        );
        $this->assertSame('ldap_sync_disabled', LdapSyncDisabledBehavior::COLUMN);
        $this->assertContains(LdapSyncDisabledBehavior::class, (new User())->behaviors(), 'Every save of a user weighs the mark.');
        $provisioner = $this->source('services/ldap/LdapUserProvisioner.php');
        $this->assertStringContainsString('return (int)$user->status !== User::STATUS_ACTIVE && !$user->ldap_sync_disabled;', $provisioner, 'Only a marked account is activated again.');
        $disable = $this->between($provisioner, 'public function disableMissingUser(', 'private function audit(');
        $this->assertStringContainsString("\$user->status = User::STATUS_INACTIVE;\n        \$user->ldap_sync_disabled = true;", $disable, 'ldap/sync marks the account it deactivates.');
        foreach (['controllers/UserController.php' => '$user->save(false);', 'models/UserForm.php' => '$user->save(false)', 'controllers/api/v1/UsersController.php' => '$model->save(false)'] as $file => $save) {
            $this->assertStringContainsString($save, $this->source($file), "{$file} saves the status as a model, so the behaviour clears the mark.");
        }
    }

    /**
     * The text of the constant $name of m000086, the sprintf pattern of a
     * line it prints.
     */
    private function migrationText(string $name): string
    {
        $text = (new \ReflectionClassConstant(self::MIGRATION, $name))->getValue();
        $this->assertIsString($text);

        return $text;
    }

    /**
     * The audit actions m000086 weighs, in its order.
     *
     * @return list<string>
     */
    private function statusActions(): array
    {
        $actions = (new \ReflectionClassConstant(self::MIGRATION, 'STATUS_ACTIONS'))->getValue();
        $this->assertIsArray($actions);

        return array_values(array_map('strval', $actions));
    }

    private function source(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }
}
