<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * One encrypted file or inline vault value the last scan found in a project
 * checkout. Holds where it is and how it is labelled, never plaintext and
 * never a password; the fingerprint identifies the encrypted content.
 *
 * @property int         $id
 * @property int         $project_id
 * @property string      $path           relative to the checkout root
 * @property string      $kind           'file' or 'inline'
 * @property int|null    $line           first line of an inline value
 * @property string|null $var_key        variable name of an inline value
 * @property string|null $vault_id       vault ID from the header (format 1.2)
 * @property string|null $format_version vault format, e.g. 1.1
 * @property string|null $fingerprint    sha256 of the encrypted content
 * @property string|null $error          why Ansible cannot read it, if so, or ERROR_NAME_NOT_UTF8
 *
 * @property Project     $project
 */
class ProjectVaultEntry extends ActiveRecord
{
    public const KIND_FILE = 'file';
    public const KIND_INLINE = 'inline';

    /**
     * The error of an entry whose file name is not valid UTF-8: it is stored
     * under a replaced name, so Ansilume cannot read it again to check it.
     * Ansible itself reads such files.
     */
    public const ERROR_NAME_NOT_UTF8 = 'The file name is not valid UTF-8, so Ansilume cannot check this file.';

    public static function tableName(): string
    {
        return '{{%project_vault_entry}}';
    }

    public function getProject(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }
}
