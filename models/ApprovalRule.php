<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property int|null    $job_template_id
 * @property int         $required_approvals
 * @property int|null    $timeout_minutes
 * @property string      $timeout_action       reject or approve
 * @property string      $approver_type        role, team, or users
 * @property string|null $approver_config      JSON
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User $creator
 * @property JobTemplate|null $jobTemplate
 * @property ApprovalRequest[] $approvalRequests
 */
class ApprovalRule extends ActiveRecord
{
    public const APPROVER_TYPE_ROLE = 'role';
    public const APPROVER_TYPE_TEAM = 'team';
    public const APPROVER_TYPE_USERS = 'users';

    public const TIMEOUT_ACTION_REJECT = 'reject';
    public const TIMEOUT_ACTION_APPROVE = 'approve';

    public static function tableName(): string
    {
        return '{{%approval_rule}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name', 'approver_type'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string'],
            [['required_approvals'], 'integer', 'min' => 1, 'max' => 50],
            [['timeout_minutes'], 'integer', 'min' => 1, 'max' => 10080],
            [['timeout_action'], 'in', 'range' => [self::TIMEOUT_ACTION_REJECT, self::TIMEOUT_ACTION_APPROVE]],
            [['approver_type'], 'in', 'range' => [self::APPROVER_TYPE_ROLE, self::APPROVER_TYPE_TEAM, self::APPROVER_TYPE_USERS]],
            [['approver_config'], 'string'],
            [['approver_config'], 'validateJson'],
            [['job_template_id', 'created_by'], 'integer'],
            [['required_approvals'], 'validateApproverCount'],
        ];
    }

    /**
     * Validate that required_approvals does not exceed the number of eligible approvers.
     * Only enforced for APPROVER_TYPE_USERS where the count is deterministic.
     * For roles/teams, membership is dynamic — enforced as a JS warning only.
     */
    public function validateApproverCount(string $attribute): void
    {
        if ($this->approver_type !== self::APPROVER_TYPE_USERS) {
            return;
        }
        $count = $this->countEligibleApprovers();
        if ($count !== null && $this->$attribute > $count) {
            $this->addError($attribute, sprintf(
                'Required approvals (%d) exceeds eligible approvers (%d).',
                $this->$attribute,
                $count
            ));
        }
    }

    /**
     * Count eligible approvers based on current config, or null if indeterminate.
     */
    public function countEligibleApprovers(): ?int
    {
        $config = $this->getParsedConfig();

        switch ($this->approver_type) {
            case self::APPROVER_TYPE_USERS:
                $ids = $config['user_ids'] ?? [];
                return is_array($ids) ? count($ids) : 0;
            case self::APPROVER_TYPE_ROLE:
                return count($this->resolveRoleIds($config));
            case self::APPROVER_TYPE_TEAM:
                return count($this->resolveTeamIds($config));
            default:
                return null;
        }
    }

    public function validateJson(string $attribute): void
    {
        if (!empty($this->$attribute)) {
            json_decode((string)$this->$attribute);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addError($attribute, ucfirst($attribute) . ' must be valid JSON.');
            }
        }
    }

    /**
     * @return string[]
     */
    public static function approverTypes(): array
    {
        return [
            self::APPROVER_TYPE_ROLE => 'Role',
            self::APPROVER_TYPE_TEAM => 'Team',
            self::APPROVER_TYPE_USERS => 'Specific Users',
        ];
    }

    /**
     * @return string[]
     */
    public static function timeoutActions(): array
    {
        return [
            self::TIMEOUT_ACTION_REJECT => 'Reject',
            self::TIMEOUT_ACTION_APPROVE => 'Approve',
        ];
    }

    /**
     * Resolve the list of user IDs who are eligible to approve.
     *
     * @return int[]
     */
    public function getApproverUserIds(): array
    {
        $config = $this->getParsedConfig();

        switch ($this->approver_type) {
            case self::APPROVER_TYPE_USERS:
                return $this->resolveUserIds($config);
            case self::APPROVER_TYPE_ROLE:
                return $this->resolveRoleIds($config);
            case self::APPROVER_TYPE_TEAM:
                return $this->resolveTeamIds($config);
            default:
                return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedConfig(): array
    {
        if (empty($this->approver_config)) {
            return [];
        }
        $decoded = json_decode($this->approver_config, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getCreator(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getJobTemplate(): ActiveQuery
    {
        return $this->hasOne(JobTemplate::class, ['id' => 'job_template_id']);
    }

    public function getApprovalRequests(): ActiveQuery
    {
        return $this->hasMany(ApprovalRequest::class, ['approval_rule_id' => 'id']);
    }

    /**
     * @param array<string, mixed> $config
     * @return int[]
     */
    private function resolveUserIds(array $config): array
    {
        if (!isset($config['user_ids']) || !is_array($config['user_ids'])) {
            return [];
        }
        return array_map('intval', $config['user_ids']);
    }

    /**
     * @param array<string, mixed> $config
     * @return int[]
     */
    private function resolveRoleIds(array $config): array
    {
        $roleName = $config['role'] ?? '';
        if ($roleName === '') {
            return [];
        }
        $auth = \Yii::$app->authManager;
        if ($auth === null) {
            return [];
        }
        $assignments = $auth->getUserIdsByRole((string)$roleName);
        return array_map('intval', $assignments);
    }

    /**
     * @param array<string, mixed> $config
     * @return int[]
     */
    private function resolveTeamIds(array $config): array
    {
        $teamId = $config['team_id'] ?? null;
        if ($teamId === null) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $rows */
        $rows = (new \yii\db\Query())
            ->select('user_id')
            ->from('{{%team_member}}')
            ->where(['team_id' => (int)$teamId])
            ->all();
        return array_map(fn (array $row): int => (int)$row['user_id'], $rows);
    }
}
