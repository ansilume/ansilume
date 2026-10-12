<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\CredentialResolutionException;
use PHPUnit\Framework\TestCase;

class CredentialResolutionExceptionTest extends TestCase
{
    public function testTheMessageTellsOperatorsWhatToDo(): void
    {
        $failures = [
            ['id' => 12, 'name' => 'prod-vault', 'role' => 'additional', 'reason' => CredentialResolutionException::REASON_MISSING],
            ['id' => 13, 'name' => null, 'role' => 'scm', 'reason' => CredentialResolutionException::REASON_UNDECRYPTABLE],
        ];

        $e = CredentialResolutionException::fromFailures($failures);

        $this->assertSame($failures, $e->failures);
        $this->assertSame(
            "Job aborted before execution: 2 credential(s) could not be used.\n"
            . 'Credential #12 "prod-vault" (additional) no longer exists. It was deleted after the job was launched. '
            . "Attach a replacement to the job template and relaunch.\n"
            . 'Credential #13 (scm) cannot be decrypted. APP_SECRET_KEY has probably changed since it was saved. '
            . "Re-enter its secret on the credential page, then relaunch.\n"
            . 'The job did not start; no credentials were used.',
            $e->getMessage()
        );
    }

    /**
     * Relaunching the job would read the same answers, so the message sends
     * the operator to the template.
     */
    public function testUndecryptableSurveyAnswersTellOperatorsWhatToDo(): void
    {
        $e = CredentialResolutionException::surveyAnswersUndecryptable();

        $this->assertSame([], $e->failures);
        $this->assertSame(
            "The job's encrypted survey answers cannot be decrypted (was APP_SECRET_KEY changed?). "
                . 'Launch the job template again and enter the password answers.',
            $e->getMessage()
        );
        $this->assertNull($e->getPrevious());
    }
}
