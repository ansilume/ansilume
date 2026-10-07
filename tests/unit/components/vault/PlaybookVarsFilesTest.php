<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\PlaybookVarsFiles;
use PHPUnit\Framework\TestCase;

class PlaybookVarsFilesTest extends TestCase
{
    public function testReadsEveryShapeOfVarsFiles(): void
    {
        $playbook = <<<'YAML'
            %YAML 1.1
            ---
            # Plays with vars_files in the shapes YAML allows.
            - hosts: all
              vars_files:
                - a.yml          # a comment
                - 'b''s.yml'
                - "c\"d.yml"
                -
                  - alt1.yml
                  - alt2.yml
                - [alt3.yml, "alt4.yml"]
              tasks:
                - name: vars_files of a task is no play keyword
                  vars_files: [task.yml]
                  doc: |
                    vars_files:
                      - block-scalar.yml
            - vars_files: [e.yml, 'f.yml'] # the first key on the dash line
              hosts: all
            - hosts: all
              vars_files: g.yml
            - hosts: all
              vars_files:
              - h.yml
              - i.yml
              hosts: web
            - hosts: all
              vars_files: [j.yml,
                # a comment inside
                k.yml]
            - hosts: all
              vars_files: &files
                - l.yml
            - hosts: all
              "vars_files": [m.yml]
            -
              hosts: all
              vars_files : n.yml
            YAML;

        $this->assertSame(
            ['a.yml', "b's.yml", 'c"d.yml', 'alt1.yml', 'alt2.yml', 'alt3.yml', 'alt4.yml', 'e.yml', 'f.yml', 'g.yml', 'h.yml', 'i.yml', 'j.yml', 'k.yml', 'l.yml', 'm.yml', 'n.yml'],
            PlaybookVarsFiles::read($playbook)
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function valueProvider(): array
    {
        return [
            'numbers, booleans and null are no file names' => ["- vars_files: [42, 1.5, 1e3, true, no, null, ~, '42', 1.yml]\n", ['42', '1.yml']],
            'blank quoted values' => ["- vars_files: ['', \"\", ok.yml]\n", ['', '', 'ok.yml']],
            'an unterminated quote' => ["- vars_files:\n    - \"open.yml\n    - ok.yml\n", ['ok.yml']],
            'an alias' => ["- vars_files: *files\n", []],
            'a flow mapping' => ["- vars_files: {a: b.yml}\n", []],
            'nested deeper than alternatives' => ["- vars_files: [a.yml, [b.yml, [c.yml]], {d: e.yml}]\n", ['a.yml', 'b.yml']],
            'a key that only starts like vars_files' => ["- vars_files_extra: [a.yml]\n  my_vars_files: [b.yml]\n", []],
            'no value at all' => ["- hosts: all\n  vars_files:\n  tasks: []\n", []],
            'an indented list of plays' => ["  - hosts: all\n    vars_files: [a.yml]\n  - hosts: web\n    vars_files: [b.yml]\n", ['a.yml', 'b.yml']],
            'a line left of the plays is skipped' => ["  - hosts: all\nvars_files: [x.yml]\n    vars_files: [a.yml]\n", ['a.yml']],
            'CRLF line endings' => ["- hosts: all\r\n  vars_files:\r\n    - a.yml\r\n", ['a.yml']],
            'a hash inside a value is no comment' => ["- vars_files: [a#b.yml, \"c #d.yml\"] # comment\n", ['a#b.yml', 'c #d.yml']],
        ];
    }

    /**
     * @dataProvider valueProvider
     * @param list<string> $expected
     */
    public function testValues(string $playbook, array $expected): void
    {
        $this->assertSame($expected, PlaybookVarsFiles::read($playbook));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noPlaysProvider(): array
    {
        return [
            'a mapping' => ["vars_files: [a.yml]\n"],
            'a scalar' => ["vars_files\n"],
            'only comments' => ["# vars_files: [a.yml]\n---\n"],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider noPlaysProvider
     */
    public function testAFileThatIsNoListOfPlaysHasNone(string $content): void
    {
        $this->assertSame([], PlaybookVarsFiles::read($content));
    }

    public function testAnUnclosedFlowSequenceTakesAtMostAHundredLines(): void
    {
        $playbook = "- hosts: all\n  vars_files: [a.yml,\n" . str_repeat("    more.yml,\n", 150)
            . "- hosts: web\n  vars_files: [b.yml]\n";

        $files = PlaybookVarsFiles::read($playbook);

        $this->assertSame(['a.yml', 'b.yml'], [$files[0], $files[count($files) - 1]], 'the play after the unclosed list is still read');
        $this->assertCount(102, $files);
    }

    /**
     * Regression: symfony/yaml crashed PHP (segfault) on deeply nested flow
     * collections in a repository playbook. The reader is linear, never
     * recurses and skips values too long to be lists of file names.
     */
    public function testDeepNestingAndHugeValuesAreLinearAndSafe(): void
    {
        $nested = str_repeat('[', 20000) . str_repeat(']', 20000);
        $playbook = "- hosts: all\n  vars_files: [a.yml, {$nested}]\n  vars: {deep: {$nested}}\n"
            . '- hosts: all' . "\n" . '  vars_files: [' . str_repeat('[x.yml], ', 100000) . "]\n"
            . "- hosts: all\n  vars_files: [b.yml,\n    " . str_repeat('y', 70000) . "]\n"
            . "- hosts: all\n  vars_files:\n    - " . str_repeat('z', 70000) . "\n    - c.yml\n";

        $started = hrtime(true);
        $files = PlaybookVarsFiles::read($playbook);

        $this->assertSame(['a.yml', 'c.yml'], $files);
        $this->assertLessThan(2.0, (hrtime(true) - $started) / 1e9, 'reading a 1.2 MB playbook takes milliseconds');
    }
}
