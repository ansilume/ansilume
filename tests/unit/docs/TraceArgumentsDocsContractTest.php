<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use PHPUnit\Framework\TestCase;

/**
 * What the docs say about the arguments in stack traces is what PHP and the
 * images do: PHP shows the first 15 characters of a text argument, a
 * parameter marked #[\SensitiveParameter] as Object(SensitiveParameterValue),
 * and with zend.exception_ignore_args = On no argument at all. The images
 * set it; runners and servers without them must set it themselves.
 *
 * Regression from fix round 3 of the survey password release: the images
 * set zend.exception_ignore_args = On now, and no doc said that hosts
 * without the images need it too. The test fails on the docs from before.
 */
class TraceArgumentsDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const SETTING = 'zend.exception_ignore_args = On';
    private const UPDATE_SECTION = '## After updating from 2.8.1 or older';

    public function testTracesShowWhatTheDocsSay(): void
    {
        $this->assertSame('15', ini_get('zend.exception_string_param_max_len'), 'PHP shows 15 characters of a text.');
        $shown = $this->traceOfAFailedCall('0');
        $this->assertStringContainsString("(Object(SensitiveParameterValue), 'docs-plain-mark...')", $shown);
        // The first 15 characters, all a trace would show of the unmarked text (review R4V2-3).
        $this->assertStringNotContainsString('docs-trace-mark', $shown);
        $hidden = $this->traceOfAFailedCall('1');
        $this->assertStringNotContainsString('SensitiveParameterValue', $hidden, 'With the setting on, no argument.');
        $this->assertStringNotContainsString('docs-plain', $hidden, 'With the setting on, no argument.');

        $logs = array_values(array_filter(
            $this->units('docs/updating.md', self::UPDATE_SECTION),
            static fn (string $unit): bool => str_starts_with($unit, '- The stack traces of errors in `app.log`')
        ));
        $this->assertCount(1, $logs, 'updating.md says what the stack traces in old logs hold.');
        $statements = [
            'showed the first 15 characters of every text argument',
            '(`Object(SensitiveParameterValue)`)',
            'the images set `' . self::SETTING . '`',
            'so that traces show no arguments at all',
            'Servers and runners that run without these images: set it in their php.ini',
        ];
        foreach ($statements as $statement) {
            $this->assertMentions($statement, $logs[0], 'updating.md, on purging old logs,');
        }
    }

    /**
     * The images set it (docker/php/php.ini for the server images and the
     * dev stack, docker/runner/php.ini for the runner image, the deploy
     * role's template), and the docs tell hosts without them to set it.
     */
    public function testTheImagesSetItAndTheDocsTellOtherHostsTo(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docker/php/php.ini', 'docker/runner/php.ini'] as $file) {
            $ini = parse_ini_file($root . '/' . $file);
            $this->assertIsArray($ini, $file);
            $this->assertSame('1', $ini['zend.exception_ignore_args'] ?? null, "{$file} sets it.");
        }
        $template = (string)file_get_contents($root . '/deploy/roles/ansilume/templates/php.ini');
        $this->assertMatchesRegularExpression('/^zend\.exception_ignore_args = On$/m', $template, 'The role sets it.');

        $statements = [
            'runners.md, on the prerequisites,' => [
                $this->flat('docs/runners.md', '### Prerequisites'),
                '`' . self::SETTING . '` in the runner\'s php.ini, as the runner image sets it',
            ],
            'updating.md, on updating runners without Docker,' => [
                $this->flat('docs/updating.md', self::UPDATE_SECTION),
                'set `' . self::SETTING . '` in the runner\'s php.ini, as the images do',
            ],
            'README.md, on security,' => [$this->flat('README.md'), '`' . self::SETTING . '` in the images'],
        ];
        foreach ($statements as $where => [$text, $statement]) {
            $this->assertMentions($statement, $text, $where);
        }
    }

    /**
     * The trace of a call that failed, with zend.exception_ignore_args set
     * to $setting; the setting is restored after.
     */
    private function traceOfAFailedCall(string $setting): string
    {
        $previous = ini_set('zend.exception_ignore_args', $setting);
        try {
            self::failingCall('docs-trace-marker-5531', 'docs-plain-marker-7720');
        } catch (\RuntimeException $e) {
            return $e->getTraceAsString();
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    /**
     * A call that fails, with a marked and an unmarked text argument.
     *
     * @throws \RuntimeException always
     */
    private static function failingCall(#[\SensitiveParameter] string $secret, string $plain): never
    {
        throw new \RuntimeException(sprintf('A call with arguments of %d and %d characters failed.', strlen($secret), strlen($plain)));
    }
}
