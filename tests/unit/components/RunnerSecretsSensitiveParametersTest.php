<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerSecretVars;
use app\tests\unit\TraceArguments;
use PHPUnit\Framework\TestCase;

/**
 * Parameters of the claim and the runner that carry password answers, job
 * output, credentials or the runner token are marked #[\SensitiveParameter],
 * so PHP leaves their values out of stack traces. Which parameters are
 * marked, and which must be, is checked for the whole application in
 * tests/unit/SensitiveParametersTest.php; this test shows the effect on the
 * runner.
 */
class RunnerSecretsSensitiveParametersTest extends TestCase
{
    /**
     * What the mark does on the runner: a failed write keeps the claim
     * payload with the answers out of the trace.
     *
     * The runner image sets zend.exception_ignore_args = On, which leaves
     * every argument out of traces. phpunit.xml turns it off for the tests,
     * as on a runner host whose php.ini leaves it off: there the mark alone
     * keeps the answers out. The test turns it off itself too, so it holds
     * under another configuration.
     *
     * Unmarked, the payload shows as "Array" in the string form, so a check
     * of the string form cannot see the answers in it; TraceArguments reads
     * the arguments themselves (fix round 4).
     */
    public function testAFailedWriteKeepsTheAnswersOutOfTheTrace(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            (new RunnerSecretVars('/nonexistent-directory-' . uniqid()))->prepare(['secret_extra_vars' => ['db_password' => 'trace-marker-value-2209']]);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame(RunnerSecretVars::WRITE_FAILED, $e->getMessage());
            $this->assertStringContainsString('RunnerSecretVars->prepare(Object(SensitiveParameterValue))', $e->getTraceAsString());
            TraceArguments::assertSecretAbsent('trace-marker', $e);
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
    }
}
