<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\controllers\CredentialController;
use app\controllers\InventoryController;
use PHPUnit\Framework\TestCase;

/**
 * What the docs say about the two buttons whose requests the server
 * refuses while the user is signed out, Generate Ed25519 Key Pair on the
 * credential form (views/credential/form.php) and Parse Inventory on an
 * inventory's page (views/inventory/view.php), is what their scripts do:
 * they ask for JSON, and a refusal (401 or 403) shows the sentence the docs
 * quote, with its status, before the answer is read; other failures keep
 * their messages. docs/updating.md adds them to the item of the requests
 * that ask for JSON (the fixes of the section for the update from 2.10.1),
 * and troubleshooting.md has an entry that names the permissions the
 * controllers check. CLAUDE.md's rule for scripts is pinned by
 * GitSyncDocsContractTest; tests/unit/views/FetchAsksForJsonTest.php
 * enforces the header for every fetch.
 */
class RefusedFetchDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const ENTRY = '## Generate Ed25519 Key Pair or Parse Inventory: the server refused the request';

    /** The sentence of a refusal before its status, as both scripts write it. */
    private const BEFORE_STATUS = 'The server refused the request (HTTP ';

    /** The sentence of a refusal after its status. */
    private const AFTER_STATUS = '): your session may have ended, or you are not allowed to do this; reload the page and sign in.';

    /**
     * Both scripts ask for JSON, write the refusal with the status of the
     * answer before they read it, and keep the messages the docs quote for
     * other failures and for what they said up to 2.10.1.
     */
    public function testTheScriptsWriteTheRefusalTheDocsQuote(): void
    {
        $scripts = ['views/credential/form.php' => 'r.status', 'views/inventory/view.php' => 'resp.status'];
        foreach ($scripts as $view => $status) {
            $source = $this->source($view);
            $this->assertStringContainsString("'" . self::BEFORE_STATUS . "' + {$status} + '" . self::AFTER_STATUS . "'", $source, "{$view} writes the refusal with the status.");
            $this->assertStringContainsString("if ({$status} === 401 || {$status} === 403) {", $source, "{$view} says a refusal before it reads the answer.");
            $this->assertStringContainsString("'Accept': 'application/json'", $source, "{$view} asks for JSON.");
        }
        $credential = $this->source('views/credential/form.php');
        $this->assertStringContainsString("alert(e.name === 'RequestRefused' ? e.message : 'Request failed: ' + e);", $credential, 'Other failures keep their message.');
        $this->assertStringContainsString('Generate Ed25519 Key Pair', $credential);
        $inventory = $this->source('views/inventory/view.php');
        $this->assertStringContainsString("'Server response was not JSON'", $inventory, 'Other failures keep their message.');
        $this->assertStringContainsString("\$cached !== null ? 'Refresh' : 'Parse Inventory'", $inventory, 'The labels of the button.');
    }

    /**
     * The fixes of updating.md add the two buttons to the item of the
     * requests that ask for JSON, and quote the refusal with the status 403.
     */
    public function testTheFixesAddTheTwoButtonsToTheRequestsThatAskForJson(): void
    {
        $fixes = $this->between($this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), '**Fixes that change behaviour:**', '**API contract change:**');
        $this->assertMentions(
            'These requests ask for JSON now, and are answered `403` instead of being sent to the login page. So do the requests of two buttons, which were answered with a redirect to the login page while the user was signed out: **Generate Ed25519 Key Pair** on the credential form said `Request failed: SyntaxError: …`, and **Parse Inventory** (or **Refresh**) on an inventory\'s page said `Server response was not JSON` with `HTTP 302`. Both now say `'
                . $this->refusal(403) . '` (`HTTP 401` for a `401`), and keep their messages for other failures.',
            $fixes,
            'The fixes of updating.md'
        );
    }

    /**
     * The troubleshooting entry quotes the refusal and names the
     * permissions the controllers check: the Generate button is on the form
     * of New Credential (credential.create) and of Edit
     * (credential.update), but generating needs credential.create; parsing
     * needs inventory.view and view access to the inventory's project.
     */
    public function testTheTroubleshootingEntryNamesThePermissionsTheControllersCheck(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRY);
        $expected = [
            '**Symptom:** **Generate Ed25519 Key Pair** on the credential form says `' . $this->refusal(403)
                . '`, or **Parse Inventory** (or **Refresh**) on an inventory\'s page shows it in the result card; a `401` reads `HTTP 401` instead.',
            '**Cause:** The session ended, for example after a sign-out in another tab, or the user may not do it: generating a key pair needs `credential.create`, also on the Edit form of a credential, which `credential.update` opens, and parsing an inventory needs `inventory.view` and access to the project of the inventory.',
            'Up to 2.10.1 the buttons said `Request failed: SyntaxError: …` and `Server response was not JSON` for a session that had ended (see [updating.md](updating.md#'
                . WorkflowStepsUpdateDocsContractTest::SECTION_ANCHOR . '), **Fixes that change behaviour**).',
            '**Fix:** Load the page again and sign in. If the refusal stays, an admin gives the permission, or generate the key pair elsewhere and paste its private key into the form.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $entry, 'troubleshooting.md, on a refused button');
        }

        $credential = $this->permissions(CredentialController::class);
        $this->assertSame(['credential.create'], $credential['generate-ssh-key'] ?? null);
        $this->assertSame(['credential.create'], $credential['create'] ?? null);
        $this->assertSame(['credential.update'], $credential['update'] ?? null);
        $this->assertSame(2, substr_count($this->source('controllers/CredentialController.php'), "return \$this->render('form', ['model' => \$model]);"), 'New Credential and Edit render the form with the button.');
        $this->assertSame(['inventory.view'], $this->permissions(InventoryController::class)['parse-hosts'] ?? null);
        $this->assertSame(
            1,
            preg_match('/function actionParseHosts\(int \$id\): Response\s*\{[^}]*\$this->requireChildView\(\$model->project_id\);/', $this->source('controllers/InventoryController.php')),
            'Parsing needs view access to the project of the inventory.'
        );
    }

    /**
     * The sentence a script writes for a refusal with $status.
     */
    private function refusal(int $status): string
    {
        return self::BEFORE_STATUS . $status . self::AFTER_STATUS;
    }

    /**
     * The permission each action of the web controller $class needs, as its
     * access rules say.
     *
     * @param class-string $class
     * @return array<string, list<string>>
     */
    private function permissions(string $class): array
    {
        $controller = new \ReflectionClass($class);
        $rules = $controller->getMethod('accessRules')->invoke($controller->newInstanceWithoutConstructor());
        $this->assertIsArray($rules);
        $permissions = [];
        foreach ($rules as $rule) {
            $this->assertIsArray($rule);
            foreach ((array)($rule['actions'] ?? []) as $action) {
                $permissions[(string)$action] = array_values(array_map('strval', (array)($rule['roles'] ?? [])));
            }
        }

        return $permissions;
    }

    private function source(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }
}
