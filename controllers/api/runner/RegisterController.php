<?php

declare(strict_types=1);

namespace app\controllers\api\runner;

use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\Response;

/**
 * Runner self-registration API.
 *
 * POST /api/runner/v1/register
 *
 * A runner that has no pre-configured token can call this endpoint with a
 * shared bootstrap secret to obtain a token automatically.  The endpoint
 * creates (or resets the token of) a runner record named after the caller
 * and places it in the "default" runner group.
 *
 * Protected by RUNNER_BOOTSTRAP_SECRET from the environment.  If that
 * variable is not set or empty the endpoint returns 403.
 */
class RegisterController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => ['application/json' => Response::FORMAT_JSON],
            ],
        ];
    }

    /**
     * POST /api/runner/v1/register
     * Body: { name: "runner-1", bootstrap_secret: "..." }
     */
    public function actionRegister(): array
    {
        $bootstrapSecret = $_ENV['RUNNER_BOOTSTRAP_SECRET'] ?? '';
        if ($bootstrapSecret === '') {
            \Yii::$app->response->statusCode = 403;
            return ['ok' => false, 'error' => 'Runner self-registration is not enabled (RUNNER_BOOTSTRAP_SECRET not set).'];
        }

        $body = \Yii::$app->request->bodyParams;
        $name = trim((string)($body['name'] ?? ''));
        $secret = (string)($body['bootstrap_secret'] ?? '');

        if (!hash_equals($bootstrapSecret, $secret)) {
            \Yii::$app->response->statusCode = 403;
            return ['ok' => false, 'error' => 'Invalid bootstrap secret.'];
        }

        if ($name === '') {
            \Yii::$app->response->statusCode = 400;
            return ['ok' => false, 'error' => 'Runner name is required.'];
        }

        $systemUserId = $this->resolveSystemUserId();
        if ($systemUserId === null) {
            \Yii::$app->response->statusCode = 503;
            return ['ok' => false, 'error' => 'No users exist yet. Run setup/admin first.'];
        }

        $group = $this->ensureDefaultGroup($systemUserId);
        [$runner, $rawToken] = $this->upsertRunner($group->id, $name, $systemUserId);

        return [
            'ok' => true,
            'data' => [
                'runner_id' => $runner->id,
                'runner_name' => $runner->name,
                'group_id' => $group->id,
                'group_name' => $group->name,
                'token' => $rawToken,
            ],
        ];
    }

    private function resolveSystemUserId(): ?int
    {
        $user = User::find()
            ->where(['is_superadmin' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->one();

        if ($user === null) {
            $user = User::find()->orderBy(['id' => SORT_ASC])->one();
        }

        return $user?->id;
    }

    private function ensureDefaultGroup(int $createdBy): RunnerGroup
    {
        $group = RunnerGroup::findOne(['name' => 'default']);
        if ($group !== null) {
            return $group;
        }

        $group = new RunnerGroup();
        $group->name = 'default';
        $group->description = 'Default runner group (auto-created)';
        $group->created_by = $createdBy;
        if (!$group->save()) {
            throw new \RuntimeException('Failed to create default runner group: ' . json_encode($group->errors));
        }

        // Backfill seeded templates that were created before the first runner
        // registered (seeds ran when no runner group existed yet).
        $this->assignGroupToUnassignedSeededTemplates($group->id);

        return $group;
    }

    /**
     * Assign the given runner group to seeded templates that have no group set.
     * Restricted to known seeded name prefixes to avoid silently touching
     * user-created templates.
     */
    private function assignGroupToUnassignedSeededTemplates(int $groupId): void
    {
        $prefixes = ['Selftest', 'Demo —'];
        $db = \Yii::$app->db;

        $conditions = array_map(
            fn($p) => 'name LIKE ' . $db->quoteValue($p . '%'),
            $prefixes
        );
        $where = '(' . implode(' OR ', $conditions) . ')';

        $db->createCommand(
            "UPDATE {{%job_template}} SET runner_group_id = :gid WHERE runner_group_id IS NULL AND {$where}",
            [':gid' => $groupId]
        )->execute();
    }

    /**
     * Find or create a runner by name within the given group.
     * Always generates a fresh token so the caller gets one valid credential.
     *
     * @return array{0: Runner, 1: string}  [runner, rawToken]
     */
    private function upsertRunner(int $groupId, string $name, int $createdBy): array
    {
        $token = Runner::generateToken();

        $runner = Runner::findOne(['runner_group_id' => $groupId, 'name' => $name]);
        if ($runner === null) {
            $runner = new Runner();
            $runner->runner_group_id = $groupId;
            $runner->name = $name;
            $runner->created_by = $createdBy;
        }

        $runner->token_hash = $token['hash'];
        if (!$runner->save()) {
            throw new \RuntimeException('Failed to save runner: ' . json_encode($runner->errors));
        }

        return [$runner, $token['raw']];
    }
}
