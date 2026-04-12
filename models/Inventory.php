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
 * @property string|null $parsed_hosts  Cached JSON from ansible-inventory
 * @property string|null $parsed_error  Error from last parse attempt
 * @property int|null    $parsed_at     Unix timestamp of last parse
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User        $creator
 * @property Project|null $project
 */
class Inventory extends ActiveRecord
{
    public const TYPE_STATIC = 'static';
    public const TYPE_DYNAMIC = 'dynamic';
    public const TYPE_FILE = 'file';

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
        return [
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
            [['project_id', 'created_by'], 'integer'],
            [['content'], 'validateYaml'],
        ];
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
}
