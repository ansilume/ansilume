<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\OutputMasker;
use app\components\PythonFloatText;
use PHPUnit\Framework\TestCase;

/**
 * Contract (CLS-1): among the fixes that change behaviour, the update notes
 * for PHP 8.5 say that the runner masks a password answer sent as a JSON
 * number in every form in which Ansible prints it, quote those forms as
 * PythonFloatText writes them, and say that a runner gets the fix with its
 * own update. What 2.13.1 did is history and quoted as fixed text: PHP's
 * text with 14 significant digits, and the fragment its mask left.
 */
class FloatAnswerMaskDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const FIXES = '**Fixes that change behaviour:**';

    private const ITEM = '- A password survey answer sent as a JSON number';

    /** The answers the item quotes, sent as JSON numbers. */
    private const QUOTED = [0.12345678901234568, 3.141592653589793, 1e20, 1.5e-7, 123456789012345678901234.0];

    public function testTheFixesSayThatAFloatAnswerIsMaskedInEveryFormAnsiblePrints(): void
    {
        $item = $this->fixItem();

        $this->assertMentions('is masked in the job log and in task names in every form in which Ansible prints it.', $item, 'The fix');
        foreach (self::QUOTED as $answer) {
            $this->assertMentions('`' . PythonFloatText::repr($answer) . '`', $item, 'The fix');
        }
        $this->assertMentions('`123456789012345678901234`', $item, 'The fix');
        $this->assertMentions('`' . PythonFloatText::yaml(1e20) . '`', $item, 'The fix');
        $this->assertMentions('`yaml` result format and the `to_yaml` and `to_nice_yaml` filters', $item, 'The fix');
        $this->assertMentions("only in PHP's text with 14 significant digits, such as `1.0E+20`", $item, 'The fix');
        $this->assertMentions('`12345678901234.5` appeared as `' . OutputMasker::MASK . '.5`', $item, 'The fix');
        $this->assertMentions('a runner gets this with its own update: a runner of 2.13.1 keeps the old masking', $item, 'The fix');
    }

    /**
     * The item of the newest section's list of fixes that change behaviour
     * that starts with ITEM, on one line.
     */
    private function fixItem(): string
    {
        $units = $this->units('docs/updating.md', Php85UpdateDocsContractTest::UPDATE_SECTION);
        $fixes = array_search(self::FIXES, $units, true);
        $this->assertIsInt($fixes, 'The newest section lists the fixes that change behaviour.');
        foreach (array_slice($units, $fixes + 1) as $unit) {
            if (!str_starts_with($unit, '- ')) {
                break;
            }
            if (str_starts_with($unit, self::ITEM)) {
                return $unit;
            }
        }
        $this->fail('The fixes that change behaviour have no item that starts with: ' . self::ITEM);
    }
}
