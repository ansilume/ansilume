<?php

declare(strict_types=1);

namespace app\services\ldap;

/**
 * Thrown for unrecoverable LDAP operations: missing extension, malformed
 * configuration, broken response. Auth failures (wrong password, missing
 * user) are NOT exceptions — they are normal results returned via the
 * LdapClient API.
 */
class LdapException extends \RuntimeException
{
}
