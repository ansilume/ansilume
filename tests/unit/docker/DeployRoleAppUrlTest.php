<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;

/**
 * The deploy role sets APP_URL, the base of every link that leaves
 * Ansilume (notifications, the Mattermost status posts, webhook payloads,
 * password-reset mails), from ansilume_app_url: its .env writes the line
 * only when the variable holds an address, as an empty APP_URL would leave
 * the links of the console processes without one (config/params.php takes
 * an empty value as it is, not as unset).
 *
 * Regression (plan Task 14 of the workflow notifications): the role had no
 * way to set APP_URL, so every installation it deployed linked
 * http://localhost:8080, which bin/diagnose now warns about, with the role's
 * variable as the fix.
 */
final class DeployRoleAppUrlTest extends TestCase
{
    use RendersDeployRole;

    public function testTheEnvSetsAppUrlFromTheVariable(): void
    {
        $env = $this->renderDeployFile('env', ['ansilume_app_url' => 'https://ansilume.example.com']);

        if (preg_match('/^APP_URL=(.*)$/m', $env, $match) !== 1) {
            self::fail('The .env sets no APP_URL.');
        }
        $this->assertSame('https://ansilume.example.com', $match[1]);
    }

    /**
     * By default the role writes no APP_URL, so the app keeps its default;
     * an empty APP_URL= would be worse than none.
     */
    public function testTheEnvLeavesAppUrlOutByDefault(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^APP_URL=/m', $this->renderDeployFile('env'));
        $this->assertDoesNotMatchRegularExpression('/^APP_URL=/m', $this->renderDeployFile('env', ['ansilume_app_url' => '']));
    }

    /**
     * The default is empty, and its comment says what the variable is for
     * and who warns while it is left out.
     */
    public function testTheDefaultIsDocumented(): void
    {
        $defaults = (string)file_get_contents(dirname(__DIR__, 3) . '/deploy/roles/ansilume/defaults/main.yaml');

        $this->assertStringContainsString("\nansilume_app_url: \"\"\n", $defaults);
        $comment = $this->commentAbove($defaults, 'ansilume_app_url: ""');
        foreach (['APP_URL', 'notifications', 'http://localhost:8080', 'bin/diagnose'] as $needle) {
            $this->assertStringContainsString($needle, $comment, "The comment of ansilume_app_url names {$needle}.");
        }
    }

    /**
     * The comment lines right above $line in $text, without their #.
     */
    private function commentAbove(string $text, string $line): string
    {
        $lines = explode("\n", substr($text, 0, (int)strpos($text, "\n" . $line . "\n")));
        $comment = [];
        while ($lines !== [] && str_starts_with((string)end($lines), '#')) {
            array_unshift($comment, ltrim(substr((string)array_pop($lines), 1)));
        }

        return implode(' ', $comment);
    }
}
