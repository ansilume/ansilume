<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\models\User;
use yii\rbac\ManagerInterface;

/**
 * Who looks at a run, a request or a job, and what they may do: the pages,
 * the poll and the API ask may() before they link or show something.
 *
 * A superadmin may do everything (user->can() does not cover them, so this
 * is the one place that adds them); anyone else holds what RBAC grants.
 * Nobody (a guest, a user who no longer exists or whose account is
 * disabled) may do anything. The user is read at most once, and each answer
 * is kept for the lifetime of the object.
 */
final class Viewer
{
    /** @var array<string, bool> */
    private array $answers = [];

    private ?User $user;

    private bool $loaded;

    private function __construct(public readonly int $userId, ?User $user, bool $loaded)
    {
        $this->user = $user;
        $this->loaded = $loaded;
    }

    /**
     * The signed-in user of the web request or API call; nobody (user ID 0,
     * which may do nothing) for a guest or outside a web request.
     */
    public static function current(): self
    {
        $webUser = \Yii::$app->has('user') ? \Yii::$app->get('user') : null;
        $identity = $webUser instanceof \yii\web\User ? $webUser->getIdentity() : null;

        return $identity instanceof User ? new self((int)$identity->id, $identity, true) : new self(0, null, true);
    }

    /**
     * User #$userId, for services that act for a user other than the
     * signed-in one.
     */
    public static function of(int $userId): self
    {
        return new self($userId, null, false);
    }

    /**
     * Whether the viewer may $permission: a superadmin always, anyone else
     * as RBAC grants it; nobody never.
     */
    public function may(string $permission): bool
    {
        if (!array_key_exists($permission, $this->answers)) {
            $this->answers[$permission] = $this->decide($permission);
        }

        return $this->answers[$permission];
    }

    private function decide(string $permission): bool
    {
        $user = $this->user();
        if ($user === null || (int)$user->status !== User::STATUS_ACTIVE) {
            return false;
        }
        if ((bool)$user->is_superadmin) {
            return true;
        }
        /** @var ManagerInterface $auth */
        $auth = \Yii::$app->authManager;

        return $auth->checkAccess($this->userId, $permission);
    }

    private function user(): ?User
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->user = $this->userId > 0 ? User::findOne($this->userId) : null;
        }

        return $this->user;
    }
}
