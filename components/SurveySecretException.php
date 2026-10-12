<?php

declare(strict_types=1);

namespace app\components;

/**
 * Password answers could not be sealed or opened ({@see SurveySecrets}).
 * The message is fixed: it never contains an answer, a ciphertext or SQL.
 */
final class SurveySecretException extends \RuntimeException
{
}
