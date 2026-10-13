<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property int|null    $job_template_id      unused: job_template.approval_rule_id gates a template; set in code only
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

    /**
     * Shown on the rule form when the required approvals reach the number of
     * approvers: the launcher's own approval never counts.
     */
    public const LAUNCHER_NOTE = 'The user who launches a job never approves it: a launch is refused '
        . 'unless enough other approvers may decide.';

    /** Validation message of an approver config that is no valid JSON; sprintf(): the attribute's label. */
    public const INVALID_JSON = '%s must be valid JSON.';

    /**
     * Validation message of an approver config that is JSON, but no object
     * (a list, a number, a string, null): it names no approvers.
     * sprintf(): the attribute's label.
     */
    public const NOT_A_JSON_OBJECT = '%s must be a JSON object.';

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
            // approver_config has no default in the database: without it the
            // insert failed with a server error instead of a validation error.
            [['name', 'approver_type', 'approver_config'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string', 'max' => 1000],
            [['required_approvals'], 'integer', 'min' => 1, 'max' => 50],
            [['timeout_minutes'], 'integer', 'min' => 1, 'max' => 10080],
            [['timeout_action'], 'in', 'range' => [self::TIMEOUT_ACTION_REJECT, self::TIMEOUT_ACTION_APPROVE]],
            [['approver_type'], 'in', 'range' => [self::APPROVER_TYPE_ROLE, self::APPROVER_TYPE_TEAM, self::APPROVER_TYPE_USERS]],
            [['approver_config'], 'string', 'max' => 4096],
            [['approver_config'], 'validateJson'],
            // Set in code only: the creator is whoever creates the rule, and
            // job_template_id never gated a template (job_template.approval_rule_id
            // does), so a form must not set either.
            [['!created_by', '!job_template_id'], 'integer'],
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

    /**
     * The approver config is a JSON object, such as {"role": "operator"} or
     * {} (no approvers chosen yet). A list or any other JSON value names no
     * approvers, and the API documents the config as an object.
     */
    public function validateJson(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_string($value) || $value === '') {
            // The required and string rules report these.
            return;
        }
        $decoded = json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError($attribute, sprintf(self::INVALID_JSON, $this->getAttributeLabel($attribute)));
        } elseif (!$decoded instanceof \stdClass) {
            $this->addError($attribute, sprintf(self::NOT_A_JSON_OBJECT, $this->getAttributeLabel($attribute)));
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
     * The approver config as an array with its keys; [] when it is empty,
     * no valid JSON, or no JSON object (a list stored by an older version
     * names no approvers either).
     *
     * @return array<string, mixed>
     */
    public function getParsedConfig(): array
    {
        if (empty($this->approver_config)) {
            return [];
        }
        $decoded = json_decode($this->approver_config, true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
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
