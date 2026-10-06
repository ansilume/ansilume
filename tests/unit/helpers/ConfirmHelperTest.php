<?php

declare(strict_types=1);

namespace app\tests\unit\helpers;

use app\helpers\ConfirmHelper;
use PHPUnit\Framework\TestCase;

/**
 * Regression: confirm() dialogs interpolated names with addslashes() or
 * Html::encode(). A runner name with a double quote (runner names come from
 * self-registration) broke out of the onsubmit attribute, and an apostrophe
 * in a token, role or team name broke out of the JavaScript string, because
 * the browser decodes the attribute before it runs the script.
 */
class ConfirmHelperTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileNameProvider(): array
    {
        return [
            'double quote ends the attribute' => ['x" onmouseover="alert(1)" data-injected="1'],
            'apostrophe ends the JS string' => ["x');alert(1);('"],
            'closing tags' => ['</form><script>alert(1)</script>'],
            'character references' => ['&quot;&#039;&amp;'],
            'backslashes' => ['x\\\'\\"'],
            'unicode' => ['Ünïcödé runner ✓'],
        ];
    }

    /**
     * @dataProvider hostileNameProvider
     */
    public function testHostileNamesStayInsideTheMessage(string $name): void
    {
        $message = 'Delete runner "' . $name . '"?';

        $attribute = ConfirmHelper::attribute($message);

        // Nothing may end a double-quoted attribute or open a tag.
        $this->assertStringNotContainsString('"', $attribute);
        $this->assertStringNotContainsString('<', $attribute);
        $this->assertStringNotContainsString('>', $attribute);

        // What JavaScript gets after the browser decodes the attribute: one
        // string literal without raw quotes that decodes to the exact message.
        $script = html_entity_decode($attribute, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame(1, preg_match('/^return confirm\((?<literal>".*")\)$/s', $script, $m), $script);
        $inner = substr($m['literal'], 1, -1);
        $this->assertStringNotContainsString('"', $inner);
        $this->assertStringNotContainsString("'", $inner);
        $this->assertSame($message, json_decode($m['literal'], false, 512, JSON_THROW_ON_ERROR));
    }

    public function testPlainMessage(): void
    {
        $this->assertSame('return confirm(&quot;Change status?&quot;)', ConfirmHelper::attribute('Change status?'));
    }
}
