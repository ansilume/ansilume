<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $inventory_type
 * @property string|null $content
 * @property string|null $source_path
 * @property int|null    $project_id
 * @property int|null $approval_rule_id the approval rule every launch with this inventory waits for (m000088);
 *     set in code only, and read with getAttribute(): a schema cached from before m000088 has no such column
 * @property string|null $parsed_hosts  Cached JSON from ansible-inventory
 * @property string|null $parsed_error  Error from last parse attempt
 * @property int|null    $parsed_at     Unix timestamp of last parse
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User        $creator
 * @property Project|null $project
 * @property ApprovalRule|null $approvalRule
 */
class Inventory extends ActiveRecord
{
    public const TYPE_STATIC = 'static';
    public const TYPE_DYNAMIC = 'dynamic';
    public const TYPE_FILE = 'file';

    /** The error of an approval rule ID that names no rule. */
    public const APPROVAL_RULE_NOT_FOUND = 'The selected approval rule does not exist.';

    /** The error of a dynamic inventory with an approval rule but without a source path. */
    public const SOURCE_PATH_REQUIRED_WHEN_GATED = 'A dynamic inventory with an approval rule needs a source path: '
        . 'without one, jobs use the default inventory of Ansible or of the repository\'s ansible.cfg, '
        . 'which no rule gates.';

    public static function tableName(): string
    {
        return '{{%inventory}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return array_merge([
            // The form posts '' for "no project".
            [['project_id'], 'default', 'value' => null],
            [['name', 'inventory_type'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string', 'max' => 1000],
            [['content'], 'string', 'max' => 262144],
            [['inventory_type'], 'in', 'range' => [self::TYPE_STATIC, self::TYPE_DYNAMIC, self::TYPE_FILE]],
            [['source_path'], 'string', 'max' => 512],
            [['source_path'], 'validateSourcePath'],
            [['content'], 'required',
                'when' => fn ($m) => $m->inventory_type === self::TYPE_STATIC,
                'whenClient' => "function(attr, val) { return $('#inventory-type').val() === 'static'; }",
            ],
            [['source_path', 'project_id'], 'required',
                'when' => fn ($m) => $m->inventory_type === self::TYPE_FILE,
                'whenClient' => "function(attr, val) { return $('#inventory-type').val() === 'file'; }",
            ],
            [['project_id'], 'integer'],
            // Set in code only: a form or a request body must not choose who created the inventory.
            [['!created_by'], 'integer'],
            // An unknown id would otherwise fail on the foreign key with a server error.
            [
                ['project_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => Project::class,
                'targetAttribute' => ['project_id' => 'id'],
                'message' => 'The selected project does not exist.',
            ],
            // Content a request brings: a static inventory whose stored content is INI, as the seeded selftest
            // and demo inventories hold, keeps it while its name, description or approval rule change.
            [['content'], 'validateYaml', 'when' => fn (self $m): bool => $m->isNewRecord
                || $m->isAttributeChanged('inventory_type', false) || self::contentChanged($m)],
            [['project_id'], 'validateTemplateProjects', 'skipOnEmpty' => false,
                'when' => fn (self $m): bool => !$m->isNewRecord
                    && ($m->isAttributeChanged('inventory_type', false) || $m->isAttributeChanged('project_id', false)),
            ],
        ], $this->approvalRuleRules());
    }

    /**
     * The rules of the approval rule, once m000088 added its column: code
     * that runs before the migration (or with a schema cached from before
     * it) validates and saves inventories as before. Set in code only: the
     * rule that gates every launch with the inventory is for admins to
     * choose, so a form or a request body never sets it on its own.
     *
     * @return list<array<int|string, mixed>>
     */
    private function approvalRuleRules(): array
    {
        if (!$this->hasAttribute('approval_rule_id')) {
            return [];
        }

        return [
            [['!approval_rule_id'], 'integer'],
            // An unknown id would otherwise fail on the foreign key with a server error.
            [
                ['approval_rule_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => ApprovalRule::class,
                'targetAttribute' => ['approval_rule_id' => 'id'],
                'message' => self::APPROVAL_RULE_NOT_FOUND,
            ],
            // Without a source path a dynamic inventory's jobs would use hosts that no rule gates.
            [['source_path'], 'required',
                'when' => fn (self $m): bool => $m->inventory_type === self::TYPE_DYNAMIC
                    && $m->getAttribute('approval_rule_id') !== null,
                'whenClient' => "function () { return $('#inventory-type').val() === 'dynamic' && ($('#inventory-approval_rule_id').val() || '') !== ''; }",
                'message' => self::SOURCE_PATH_REQUIRED_WHEN_GATED,
            ],
        ];
    }

    /**
     * The approval rule reads as on job templates: "Approval rule must be an
     * integer."
     */
    public function attributeLabels(): array
    {
        return [
            'approval_rule_id' => 'Approval rule',
        ];
    }

    /**
     * File and dynamic inventories live in a project's checkout; static ones
     * stand alone and work for any project.
     */
    public function isProjectBound(): bool
    {
        return in_array($this->inventory_type, [self::TYPE_FILE, self::TYPE_DYNAMIC], true);
    }

    /**
     * True for a file or dynamic inventory of a project other than
     * $projectId. The runner checks out only the job template's project and
     * looks for the inventory there, so it never reads this one. A dynamic
     * inventory without a project counts as the template's own.
     */
    public function belongsToOtherProjectThan(int $projectId): bool
    {
        $boundTo = $this->boundProjectId();

        return $boundTo !== null && $boundTo !== $projectId;
    }

    /**
     * Changing the type or the project must not turn this inventory into a
     * project-bound inventory of another project for the job templates that
     * already use it.
     */
    public function validateTemplateProjects(string $attribute): void
    {
        $boundTo = $this->boundProjectId();
        if ($boundTo === null) {
            return;
        }
        // andWhere(): where() would replace the scope that hides deleted templates.
        $count = (int)JobTemplate::find()
            ->andWhere(['inventory_id' => $this->id])
            ->andWhere(['<>', 'project_id', $boundTo])
            ->count();
        if ($count > 0) {
            $this->addError(
                $attribute,
                "{$count} job template(s) of other projects use this inventory. File and dynamic inventories can only "
                . 'be used by job templates of their own project; switch those templates to another inventory first.'
            );
        }
    }

    /**
     * The project of a file or dynamic inventory; null for static ones and
     * for dynamic ones without a project (also an empty form value).
     */
    private function boundProjectId(): ?int
    {
        $projectId = (int)$this->project_id;

        return $this->isProjectBound() && $projectId > 0 ? $projectId : null;
    }

    public function validateSourcePath(string $attribute): void
    {
        $path = $this->$attribute;
        if (empty($path)) {
            return;
        }
        if (preg_match('#(?:^|/)\.\.(?:/|$)#', (string)$path)) {
            $this->addError($attribute, 'Source path must not contain path traversal sequences (..).');
        }
    }

    /**
     * Whether the content differs from the stored content in more than its
     * line endings: CRLF, CR and LF count as the same, as a browser posts a
     * textarea's lines with CRLF, so content sent back unchanged differs in
     * those only.
     */
    private static function contentChanged(self $model): bool
    {
        $content = $model->content;
        $stored = $model->getOldAttribute('content');
        if (!is_string($content) || !is_string($stored)) {
            return $content !== $stored;
        }

        return str_replace(["\r\n", "\r"], "\n", $content) !== str_replace(["\r\n", "\r"], "\n", $stored);
    }

    /**
     * A static inventory's content must be a YAML mapping. rules() runs it
     * for new or changed content only (contentChanged()), and when the type
     * changes.
     */
    public function validateYaml(string $attribute): void
    {
        if ($this->inventory_type !== self::TYPE_STATIC || empty($this->$attribute)) {
            return;
        }

        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse((string)$this->$attribute);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            $this->addError($attribute, 'Invalid YAML: ' . $e->getMessage());
            return;
        }

        if (!is_array($parsed)) {
            $this->addError($attribute, 'Inventory must be a YAML mapping (not a scalar).');
        }
    }

    /**
     * True if this inventory contains a host that resolves to the runner itself.
     *
     * A "yes" means any playbook run here will mutate the runner container —
     * installing packages, writing files, creating users, etc. — instead of a
     * remote target. Views use this to render a prominent warning on launch
     * and detail pages so operators do not accidentally brick their runner or
     * leak files into the bind-mounted project directory.
     *
     * Matches the common ways localhost shows up in Ansible inventories:
     *   - the bare hostname "localhost"
     *   - loopback addresses 127.0.0.1 / ::1
     *   - any host using ansible_connection=local
     *
     * Only static inventories are inspected — file-based and dynamic
     * inventories have no content to scan, and we prefer a clean false over a
     * speculative match.
     */
    public function targetsLocalhost(): bool
    {
        if ($this->inventory_type !== self::TYPE_STATIC || $this->content === null || $this->content === '') {
            return false;
        }
        // Negative lookarounds excluding [\w.-] so we do not match inside a
        // larger hostname (e.g. "my-localhost-dev.example.com") or a larger IP
        // (e.g. "127.0.0.15"). \b alone is wrong here because `-` and `:` are
        // not word characters.
        return preg_match(
            '/(?<![\w.\-])(?:localhost|127\.0\.0\.1|::1)(?![\w.\-])|ansible_connection\s*=\s*local(?![\w.\-])/i',
            $this->content
        ) === 1;
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getProject(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }

    /**
     * The approval rule every launch with this inventory waits for.
     */
    public function getApprovalRule(): \yii\db\ActiveQuery
    {
        return $this->hasOne(ApprovalRule::class, ['id' => 'approval_rule_id']);
    }
}
