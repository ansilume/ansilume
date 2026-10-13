<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\FlashAlerts;
use PHPUnit\Framework\TestCase;

/**
 * What the notes of the update to approvals and workflows say about raw
 * tokens shown once is what the code does: the top of a page prints only
 * alerts, so a token's flash never shows there a second time, and the debug
 * toolbar of a development installation stores **** for the flashes and
 * the session value that carry a token or the TOTP secret of a setup.
 *
 * Regression from wave 10 of step 3, fails on the docs as they were before
 * it: the runner group page and the profile's API tokens page printed the
 * raw token a second time, in unstyled boxes at the top of the page, and
 * the debug toolbar kept these tokens in clear; no doc said so.
 */
class TokenShownOnceDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    /** The flashes and session values with a secret that the debug toolbar must not keep. */
    private const CENSORED = [
        'flashes.runner_token',
        'SESSION.runner_token',
        'flashes.new_token',
        'SESSION.new_token',
        'flashes.trigger_token_raw',
        'SESSION.trigger_token_raw',
        'SESSION.totp_setup_secret',
    ];

    public function testRegressionTheDocsSayARawTokenShowsOnce(): void
    {
        $this->assertSame(['success', 'info', 'warning', 'danger'], FlashAlerts::TYPES, 'The top of a page prints alerts only.');
        $debug = require dirname(__DIR__, 3) . '/config/debug.php';
        $this->assertIsArray($debug);
        $censored = $debug['panels']['request']['censoredVariableNames'] ?? null;
        $this->assertIsArray($censored);
        foreach (self::CENSORED as $name) {
            $this->assertContains($name, $censored, "The debug toolbar stores **** for {$name}.");
        }
        $this->assertStringContainsString('>Regen Token</button>', $this->code('views/runner-group/view.php'));
        $this->assertStringContainsString("\$this->title = 'API Tokens';", $this->code('views/profile/tokens.php'));

        $this->assertMentions(
            '- A raw token shows once now, in its card. The token of a runner added on its runner group\'s page, or regenerated with **Regen Token**, and a new token on **API Tokens** appeared a second time on that page, in unstyled boxes at its top (for a runner: its ID, its name and the token). The top of a page shows only alerts now. On installations that run with `YII_ENV=dev`, the debug toolbar\'s data in `runtime/debug/` kept these tokens and the TOTP secret of a two-factor setup in clear; it stores `****` for them now.',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The fixes of updating.md'
        );
        $this->assertMentions('- Runner, API and trigger tokens are stored as SHA-256 hashes — raw tokens are shown exactly once', $this->flat('README.md'), 'README.md');
        $this->assertMentions(
            '2. Copy the generated token from the card on the group\'s page: it is shown only this once (**Regen Token** gives the runner a new one)',
            $this->flat('docs/runners.md'),
            'runners.md'
        );
    }

    private function code(string $file): string
    {
        $code = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertIsString($code, "{$file} cannot be read.");

        return $code;
    }
}
