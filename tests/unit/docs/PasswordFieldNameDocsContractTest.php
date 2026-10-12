<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\SurveyDefinition;
use app\components\SurveySecretTemplateScan;
use PHPUnit\Framework\TestCase;

/**
 * What the operator docs say about password field names that cannot carry
 * an answer is what the code does. The launch refuses their template, but
 * wherever answers are stored, a value under the field's exact name is a
 * password answer all the same: SurveyDefinition::secretNames() gives
 * every password field name, and m000081, the scrub of finished jobs,
 * schedule saves and runs, and the schedule's page, form and API take
 * their keys from it.
 *
 * Regression from fix round 3 of the survey password release [R2V2-1]:
 * names with a letter outside ASCII cannot carry an answer since round 2,
 * and m000081, the scrub and the schedule masks left the answers stored
 * under such a name in plaintext, as the docs said they would for every
 * such name. They are encrypted and masked now, and the docs say so; the
 * test fails on the docs from before the fix.
 */
class PasswordFieldNameDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.8.1 or older';
    private const NAME_SECTION = '## Launch refused: a password field needs another name';
    private const RENAME = 'enter the password under the new name and remove the old key';

    /**
     * Names of every kind that cannot carry an answer: the launch refuses
     * them, and their answers are sealed under the exact name.
     *
     * @return array<string, array{0: string}>
     */
    public static function unusableNameProvider(): array
    {
        return [
            'with [ and ]' => ['db[pw]'],
            'a number' => ['123'],
            'a trailing space' => ['db_password '],
            'a letter outside ASCII' => ["contrase\u{00F1}a_db"],
            'a non-breaking space' => ["db_password\u{00A0}"],
        ];
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testEveryPasswordFieldNameIsASecretKeyAndItsTemplateIsListed(string $name): void
    {
        $survey = (string)json_encode([
            ['name' => 'app_env', 'type' => 'text'],
            ['name' => $name, 'type' => 'password'],
        ]);

        $this->assertSame([], SurveyDefinition::passwordFields($survey)['usable'], 'The launch refuses the template.');
        $this->assertSame([$name], SurveyDefinition::secretNames($survey), 'An answer under the exact name is an answer.');
        $scan = new SurveySecretTemplateScan();
        $scan->add(9, $survey, null);
        $this->assertSame([9 => [$name]], $scan->passwordNames(), 'm000081 encrypts its answers and counts it.');
        $this->assertSame([9], $scan->unusable(), 'm000081 lists the template as one that needs a rename.');
    }

    public function testTheDocsSayThatAnswersUnderTheExactNameAreEncrypted(): void
    {
        $limits = $this->updateUnits('- answers of fields whose name cannot carry an answer');
        $this->assertCount(1, $limits, 'updating.md says once which of their answers the update cannot encrypt.');
        $this->assertStringNotContainsString('whatever key they were stored under', $limits[0]);
        $listed = $this->updateUnits('- **A password field whose name cannot carry an answer**');
        $this->assertCount(1, $listed);
        $statements = [
            'updating.md, on what the update cannot encrypt,' => [
                $limits[0],
                ['that were stored under another key than the exact name', 'answers under the exact name are encrypted'],
            ],
            'updating.md, on the templates m000081 lists,' => [
                $listed[0],
                ["Answers stored under the field's exact name are encrypted all the same and show as `\$encrypted\$`", self::RENAME],
            ],
            'updating.md, on m000081 and schedules,' => [
                $this->flat('docs/updating.md', self::UPDATE_SECTION),
                [
                    'also of a field whose name cannot carry an answer',
                    'the first line of the output counts the templates of such fields among those with password fields',
                    'All of this holds for the answers of every password field, also of one whose name cannot carry an answer',
                ],
            ],
            'troubleshooting.md, on such names,' => [
                $this->flat('docs/troubleshooting.md', self::NAME_SECTION),
                [
                    "Answers that releases up to 2.8.1 stored under the field's exact name are encrypted by the update "
                    . 'and show as `$encrypted$`',
                    self::RENAME,
                ],
            ],
            'credentials.md' => [
                $this->flat('docs/credentials.md', '## Password survey answers'),
                [
                    'A value stored under the exact name of a password field whose name breaks that rule '
                    . 'is handled as a password answer all the same.',
                ],
            ],
        ];
        foreach ($statements as $where => [$text, $needles]) {
            foreach ($needles as $needle) {
                $this->assertMentions($needle, $text, $where);
            }
        }
    }

    /**
     * The paragraphs and list items of the 2.8.1 section of updating.md
     * that start with $start.
     *
     * @return list<string>
     */
    private function updateUnits(string $start): array
    {
        return array_values(array_filter(
            $this->units('docs/updating.md', self::UPDATE_SECTION),
            static fn (string $unit): bool => str_starts_with($unit, $start)
        ));
    }
}
