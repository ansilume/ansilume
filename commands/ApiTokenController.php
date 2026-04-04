<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\services\AuditService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * API token management from the console.
 *
 * Useful for bootstrapping after a fresh setup when no browser session
 * exists yet to create tokens through the Profile UI.
 */
class ApiTokenController extends Controller
{
    /**
     * Print only the raw token value (suitable for shell capture).
     */
    public bool $raw = false;

    /**
     * @param string $actionID
     * @return string[]
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'create' ? ['raw'] : []);
    }

    /**
     * @return array<string, string>
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['r' => 'raw']);
    }

    /**
     * Create an API token for a user and print the raw value once.
     *
     * Usage: php yii api-token/create <username> [name] [ttl-days] [--raw]
     *
     *   username  Existing user to own the token (required).
     *   name      Human-readable label. Default: "console".
     *   ttl-days  Days until expiration. 0 = never expires. Default: 0.
     *   --raw     Print only the raw token value (for `TOKEN=$(... --raw)`).
     */
    public function actionCreate(string $username, string $name = 'console', int $ttlDays = 0): int
    {
        /** @var User|null $user */
        $user = User::find()->where(['username' => $username])->one();
        if ($user === null) {
            $this->stderr("User '{$username}' not found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $expiresAt = $ttlDays > 0 ? time() + ($ttlDays * 86400) : null;
        $result = ApiToken::generate($user->id, $name, $expiresAt);
        $token = $result['token'];
        $raw = $result['raw'];

        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(
            AuditLog::ACTION_API_TOKEN_CREATED,
            'api_token',
            $token->id,
            $user->id,
            ['name' => $name, 'source' => 'console:api-token/create']
        );

        if ($this->raw) {
            // Script-friendly: raw token on stdout, nothing else.
            // Human info goes to stderr so `TOKEN=$(... --raw)` still shows progress.
            $this->stderr("API token created for '{$username}' (id {$token->id}, expires: "
                . ($expiresAt === null ? 'never' : date('Y-m-d H:i:s', $expiresAt)) . ")\n");
            fwrite(STDOUT, $raw . "\n");
            return ExitCode::OK;
        }

        $this->stdout("API token created for user '{$username}' (id {$user->id}).\n");
        $this->stdout("Name:       {$name}\n");
        $this->stdout("Expires:    " . ($expiresAt === null ? 'never' : date('Y-m-d H:i:s', $expiresAt)) . "\n");
        $this->stdout("Token ID:   {$token->id}\n");
        $this->stdout("\n");
        $this->stdout("Raw token (shown ONCE — save it now):\n");
        $this->stdout("{$raw}\n");

        return ExitCode::OK;
    }

    /**
     * List API tokens for a user (or all if no user given). Never prints raw values.
     *
     * Usage: php yii api-token/list [username]
     */
    public function actionList(?string $username = null): int
    {
        $query = ApiToken::find()->orderBy(['created_at' => SORT_DESC]);

        if ($username !== null) {
            /** @var User|null $user */
            $user = User::find()->where(['username' => $username])->one();
            if ($user === null) {
                $this->stderr("User '{$username}' not found.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $query->andWhere(['user_id' => $user->id]);
        }

        /** @var ApiToken[] $tokens */
        $tokens = $query->all();

        if ($tokens === []) {
            $this->stdout("No API tokens found.\n");
            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-6s %-20s %-30s %-20s %-20s\n", 'ID', 'USER', 'NAME', 'CREATED', 'EXPIRES'));
        foreach ($tokens as $t) {
            $this->stdout(sprintf(
                "%-6d %-20s %-30s %-20s %-20s\n",
                $t->id,
                (string)($t->user->username ?? '?'),
                $t->name,
                date('Y-m-d H:i:s', (int)$t->created_at),
                $t->expires_at === null ? 'never' : date('Y-m-d H:i:s', (int)$t->expires_at)
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Revoke (delete) an API token by ID.
     *
     * Usage: php yii api-token/revoke <token-id>
     */
    public function actionRevoke(int $id): int
    {
        /** @var ApiToken|null $token */
        $token = ApiToken::findOne($id);
        if ($token === null) {
            $this->stderr("Token #{$id} not found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $userId = $token->user_id;
        $name = $token->name;
        $token->delete();

        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(
            AuditLog::ACTION_API_TOKEN_DELETED,
            'api_token',
            $id,
            $userId,
            ['name' => $name, 'source' => 'console:api-token/revoke']
        );

        $this->stdout("Token #{$id} revoked.\n");
        return ExitCode::OK;
    }
}
