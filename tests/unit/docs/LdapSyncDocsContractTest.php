<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\commands\LdapController;
use app\models\AuditLog;
use app\services\ldap\LdapService;
use app\services\ldap\PhpLdapClient;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * What docs/ldap.md, troubleshooting.md and the section of docs/updating.md
 * for the update from 2.10.1 say about ldap/sync and a directory that
 * cannot be read is what the code does, in its words: the lines of
 * LdapController (SYNC_LOOKUP_FAILED, SYNC_BIND_FAILED, SYNC_NOT_USABLE),
 * the reasons of LdapService and PhpLdapClient, and the exit codes of the
 * command. SuperadminRecoveryDocsContractTest pins the entry on a lost
 * superadmin and how a lookup decides; LdapSyncQueryDocsContractTest runs
 * the query of the section, and OpenApiLdapContractTest checks what the
 * spec says of the diagnostic.
 *
 * Regression (wave 5f of 2.11.0): ldap/sync took every lookup that failed
 * for a user the directory no longer has, and the docs said nothing of a
 * directory that cannot be read. Each test fails on the docs from before.
 *
 * Regression (wave 5g of 2.11.0): ldap.md and step 13 said that ldap/sync,
 * and each user's sign-in, activate every inactive LDAP account the
 * directory has; they activate only an account that ldap/sync deactivated
 * now (LdapDeactivationDocsContractTest pins the rest). Fails on the docs
 * from before.
 */
class LdapSyncDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use ReadsTheUpdateSection;

    private const LDAP = 'docs/ldap.md';

    /** The entry on a run that counted a failed lookup. */
    private const ENTRY = '## ldap/sync fails: a lookup or the service bind failed';

    /** The reason of a failed user search in the docs' examples. */
    private const SEARCH_ERROR = PhpLdapClient::USER_SEARCH_FAILED . ': No such object';

    /** The reason of a failed service bind in the docs' examples. */
    private const BIND_ERROR = "Service account bind failed: Can't contact LDAP server";

    /**
     * regression: ldap.md said ldap/sync disables the accounts the directory
     * no longer recognises, and nothing of a lookup that fails. It names the
     * lines and the exit codes of a run, and the lifecycle table has a row
     * for a directory that cannot be read.
     */
    public function testRegressionLdapMdSaysWhatASyncDoesWhenTheDirectoryCannotBeRead(): void
    {
        $guide = $this->flat(self::LDAP, '## Console commands');
        $expected = [
            'Reconcile every LDAP-backed user with the directory. Updates attributes and roles, disables accounts whose user search finds no entry, and re-enables the ones it disabled once it finds them again; an account deactivated in Ansilume stays inactive. A lookup that fails changes nothing.',
            'Only a user search that runs and finds no entry disables an account.',
            'A lookup that fails says nothing about the user, so `ldap/sync` leaves that account, its roles and its API tokens as they are, prints `'
                . $this->line(sprintf(LdapController::SYNC_LOOKUP_FAILED, '<username>', '<reason>'))
                . '` and counts it under `errors`: a user or group search that fails or does not run to the end (a referral to another server, a size limit), and a user search that matches more than one entry.',
            'A failed service bind stops the run, as every further lookup would fail too: `'
                . strtr($this->line(LdapController::SYNC_BIND_FAILED), ['%s' => '<reason>', '%d' => '<n>']) . '`',
            'LDAP that is enabled without a host or base DN stops it before the first user: `' . $this->line(LdapController::SYNC_NOT_USABLE) . '`',
            'The command exits with `' . ExitCode::OK . '` when it counted no error, with `' . ExitCode::UNSPECIFIED_ERROR
                . '` when it counted one (a failed lookup or service bind, or an account it could not update), and with `' . ExitCode::CONFIG
                . '` when LDAP is enabled but not fully configured; with `LDAP_ENABLED=false` it prints `[ldap] LDAP is disabled — nothing to sync.` and exits with `0`.',
            '`--dry-run` takes the same paths and changes nothing.',
            'Make a non-zero exit alert someone: cron itself only mails what a job prints, and the entry below writes that to a log (see [troubleshooting.md](troubleshooting.md#ldapsync-fails-a-lookup-or-the-service-bind-failed)).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $guide, 'The console commands of ldap.md');
        }
        $this->assertStringNotContainsString('disables accounts the directory no longer recognises', $guide);
        $this->assertStringNotContainsString('re-enables ones it finds again', $guide, 'ldap/sync re-enables only the accounts it disabled.');

        $lifecycle = $this->flat(self::LDAP, '## Lifecycle: enable, disable, re-enable');
        $rows = [
            '| User removed from directory | `ldap/sync` (its user search runs and finds no entry) marks the account inactive, rotates `auth_key` (kills active sessions), deletes API tokens, revokes RBAC. Audited. |',
            '| Directory cannot be read | `ldap/sync` changes nothing for a user whose lookup fails (service bind, user or group search) and counts an error; a failed service bind stops the run. Exit code `'
                . ExitCode::UNSPECIFIED_ERROR . '`. |',
            '| User restored in directory | Next successful bind, or the next `ldap/sync` that finds the user, re-activates an account that `ldap/sync` deactivated (`user.ldap_sync_disabled`), with the roles their groups map to. Audited. |',
        ];
        foreach ($rows as $row) {
            $this->assertMentions($row, $lifecycle, 'The lifecycle table of ldap.md');
        }
        $this->assertMentions(
            '- [ ] `ldap/sync` is scheduled in cron for the lifecycle reconciliation, and a non-zero exit code alerts someone.',
            $this->flat(self::LDAP, '## Operational checklist'),
            'The checklist of ldap.md'
        );

        $sync = $this->source('commands/LdapController.php');
        $this->assertStringContainsString("return \$counts['errors'] === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;", $sync, 'Any error counted makes the exit code 1.');
        $this->assertStringContainsString('$this->stderr("[ldap] LDAP is disabled — nothing to sync.\n");' . "\n            return ExitCode::OK;", $sync);
        $this->assertStringContainsString("\$this->stderr(\"  - {\$username}: provisionOrUpdate failed\\n\");\n            \$counts['errors']++;", $sync, 'An account it could not update is an error.');
        $this->assertStringContainsString("if (\$this->dryRun) {\n            \$counts['disabled']++;\n            return;", $sync, 'A dry run disables nobody.');
    }

    /**
     * regression: nothing said that ldap/sync deactivated every LDAP account
     * whose lookup failed. The intro, the item with its query, the fixes,
     * the API contract change and step 13 of the checklist say what it did
     * and what it does now, in the words of the code, and what to check.
     */
    public function testRegressionTheUpdateSectionSaysWhatLdapSyncDidAndWhatToCheck(): void
    {
        $section = $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $this->assertMentions(
            '`php yii ldap/sync` no longer deactivates an LDAP account whose lookup fails, only one that the directory no longer has, and ends with a non-zero exit code when a lookup fails (see **ldap/sync and a directory that cannot be read**).',
            $section,
            'The intro of updating.md'
        );
        $item = $this->item('**ldap/sync and a directory that cannot be read.**');
        $expected = [
            'Up to 2.10.1, `php yii ldap/sync` deactivated every LDAP account whose lookup failed, as if the directory no longer had it: every one while the service bind failed or LDAP was enabled without a host or base DN, and each one whose user search failed, was answered with a referral or a size limit, or matched more than one entry.',
            'It signed them out, revoked their roles, deleted their API tokens, kept their superadmin flag, recorded `' . AuditLog::ACTION_LDAP_USER_DISABLED
                . '` with a reason and counted no error, so a directory outage during a scheduled run deactivated every LDAP account, superadmins included.',
            'From 2.11.0 on, only a user search that runs and finds no entry deactivates an account (see **Fixes that change behaviour**).',
            'Up to 2.10.1 the reason was `' . LdapService::NOT_USABLE . '` while LDAP was enabled without a host or base DN, the error of the service bind while that bind failed, such as `'
                . self::BIND_ERROR . '` or `Service account bind failed: Invalid credentials`, and `' . LdapService::USER_NOT_FOUND
                . '` both for a user search that found no entry and for one that failed, was answered with a referral or matched more than one entry.',
            'From 2.11.0 on it is always `' . LdapService::USER_NOT_FOUND . '`',
            'A `status` of 0 means that the account is still inactive, and a deleted account has no `status` (step 13 above).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The ldap/sync item of updating.md');
        }
        $this->assertStringContainsString("\$this->captureError('Service account bind failed');", $this->source('services/ldap/PhpLdapClient.php'), 'The error of a failed service bind.');
        $this->assertStringContainsString("\$this->lastError = \$context . ': ' . \$detail;", $this->source('services/ldap/PhpLdapClient.php'), 'The directory\'s error follows the context.');

        $fixes = $this->between($section, '**Fixes that change behaviour:**', '**API contract change:**');
        $fix = [
            '- `php yii ldap/sync` deactivated every LDAP account whose lookup failed, not only those the directory no longer has (see **ldap/sync and a directory that cannot be read**), and took a failed group search for no group, so it took the roles a user\'s groups map to and left them only `LDAP_DEFAULT_ROLE`, if one is set.',
            'Only a user search that runs and finds no entry deactivates an account now.',
            'A lookup that fails leaves the account, its roles and its API tokens as they are and counts as an error (`'
                . $this->line(sprintf(LdapController::SYNC_LOOKUP_FAILED, 'jdoe', self::SEARCH_ERROR)) . '`), a failed service bind stops the run (`'
                . $this->line(sprintf(LdapController::SYNC_BIND_FAILED, self::BIND_ERROR, 12)) . '`), and LDAP enabled without a host or base DN stops it before the first user (`'
                . $this->line(LdapController::SYNC_NOT_USABLE) . '`, exit code ' . ExitCode::CONFIG . ').',
            'The command exits with ' . ExitCode::UNSPECIFIED_ERROR . ' whenever it counted an error; it exited with 0 and counted no error for all of these.',
            '`php yii ldap/test-user` names the failure, such as `[ldap] Lookup failed: ' . self::SEARCH_ERROR . '`, instead of `[ldap] Lookup failed: ' . LdapService::USER_NOT_FOUND
                . '`, and exits with 1 for a failed group search, where it showed the user without groups and exited with 0.',
        ];
        foreach ($fix as $phrase) {
            $this->assertMentions($phrase, $fixes, 'The fixes of updating.md');
        }
        $this->assertStringContainsString('$this->stderr("[ldap] Lookup failed: " . ($svc->getLastError() ?? \'unknown\') . "\n");' . "\n            return ExitCode::UNSPECIFIED_ERROR;", $this->source('commands/LdapController.php'));

        $api = $this->between($section, '**API contract change:**', '**Database and deployment.**');
        $this->assertMentions(
            '- `POST /api/v1/admin/ldap/test` with a `username` and no `password` answers a failed group search as `user: {found: false, error: "' . PhpLdapClient::GROUP_SEARCH_FAILED
                . ': …"}`, where it answered the user as found, with `groups: []` and, as `mapped_roles`, only `LDAP_DEFAULT_ROLE`, if one is set, and its `'
                . AuditLog::ACTION_LDAP_TEST_PERFORMED . '` entry records `user_found: false`.',
            $api,
            'The API contract change of updating.md'
        );
        $this->assertMentions(
            'For a user search that failed, was answered with a referral or a size limit, or matched more than one entry, `user.error` names that failure, such as `' . self::SEARCH_ERROR
                . '`, instead of `' . LdapService::USER_NOT_FOUND . '` A user the directory does not have still answers `found: false` with `' . LdapService::USER_NOT_FOUND . '`; the shape is unchanged.',
            $api,
            'The API contract change of updating.md'
        );

        $step = $this->checklist(13);
        $steps = [
            '13. If LDAP is enabled, check which accounts `ldap/sync` deactivated with the read-only query under **ldap/sync and a directory that cannot be read**, and make a non-zero exit of `ldap/sync` alert someone.',
            'An entry whose `reason` is not `' . LdapService::USER_NOT_FOUND . '` was written while the lookup failed; up to 2.10.1 a failed user search recorded that reason too, so check with `php yii ldap/test-user <username>` whether the directory still has such a user.',
            'The next `ldap/sync` that can read the directory activates again every account that `ldap/sync` deactivated and that it finds there (`' . AuditLog::ACTION_LDAP_USER_REENABLED
                . '`), and so does each such user\'s next sign-in for their own account, with exactly the roles their groups map to (`LDAP_ROLE_MAPPING`, else `LDAP_DEFAULT_ROLE`, else no assigned role: every signed-in user still holds viewer);'
                . ' an account that was changed in Ansilume after `ldap/sync` had deactivated it stays inactive until an admin activates it (see **Security: LDAP accounts deactivated in Ansilume**).',
            'Their sessions ended, and the API tokens deleted then are gone: create new ones and hand them to the systems that used them.',
        ];
        foreach ($steps as $phrase) {
            $this->assertMentions($phrase, $step, 'Step 13 of the checklist');
        }
        $this->assertStringNotContainsString('activates every inactive LDAP account', $step, 'regression (wave 5g): only accounts that ldap/sync deactivated are activated again.');
    }

    /**
     * regression: nothing said what a run that counted a failed lookup or
     * stopped at the service bind means. The entry quotes its lines and exit
     * codes and says what to check.
     */
    public function testRegressionTheTroubleshootingEntrySaysWhyASyncFailed(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRY);
        $expected = [
            '**Symptom:** `php yii ldap/sync` exits with `' . ExitCode::UNSPECIFIED_ERROR . '` and prints, for a user, `'
                . $this->line(sprintf(LdapController::SYNC_LOOKUP_FAILED, 'jdoe', self::SEARCH_ERROR)) . '`, or stops with `'
                . $this->line(sprintf(LdapController::SYNC_BIND_FAILED, self::BIND_ERROR, 12)) . '`; or it exits with `' . ExitCode::CONFIG . '` and prints `'
                . $this->line(LdapController::SYNC_NOT_USABLE) . '`',
            '**Cause:** The directory could not be read, or LDAP is enabled without a host or base DN, so `ldap/sync` left those accounts, their roles and their API tokens as they are: a lookup that fails says nothing about the user.',
            'Up to 2.10.1 such a run deactivated the accounts (see [updating.md](updating.md#' . WorkflowStepsUpdateDocsContractTest::SECTION_ANCHOR . '), **ldap/sync and a directory that cannot be read**).',
            '**Fix:** Run `php yii ldap/test-connection` and `php yii ldap/test-user <username>`, which name the failure.',
            '`LDAP_USER_FILTER` must match exactly one entry for each user, and `LDAP_GROUP_FILTER` must run without an error.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $entry, 'troubleshooting.md, on a failed ldap/sync');
        }
        $this->assertStringContainsString('$this->stderr("[ldap] Error:             {$diag[\'error\']}\n");', $this->source('commands/LdapController.php'), 'ldap/test-connection names a failed service bind.');
    }

    /**
     * A line of the command as the docs quote it: without the indent of a
     * user's line and without the line break.
     */
    private function line(string $printed): string
    {
        return trim($printed);
    }

    private function source(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }
}
