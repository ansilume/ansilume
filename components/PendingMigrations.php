<?php

declare(strict_types=1);

namespace app\components;

/**
 * Whether the database lags behind the code: the migration files shipped
 * with it against the migrations the migration table records as applied.
 *
 * health/check and GET /health report it. Commands that must not run
 * against an older schema check it first: in the prebuilt setup the
 * schedule runner starts while the app container still migrates, and a
 * bind-mounted checkout runs new code right after `git pull`.
 */
class PendingMigrations
{
    /** The row Yii's migration table always has; it is no migration. */
    private const BASE_VERSION = 'm000000_000000_base';

    /**
     * @param string $migrationPath directory (or alias) of the migration files
     */
    public function __construct(private readonly string $migrationPath = '@app/migrations')
    {
    }

    /**
     * How many migrations have not been applied yet: the migration files
     * beyond the number of applied migrations, 0 when none.
     *
     * @throws \yii\db\Exception when the migration table cannot be read
     */
    public function count(): int
    {
        return max(0, $this->expectedCount() - $this->appliedCount());
    }

    /**
     * The number of migration files.
     */
    public function expectedCount(): int
    {
        $files = glob(rtrim((string)\Yii::getAlias($this->migrationPath), '/') . '/m*.php');

        return $files === false ? 0 : count($files);
    }

    /**
     * The number of applied migrations.
     *
     * @throws \yii\db\Exception when the migration table cannot be read
     */
    public function appliedCount(): int
    {
        return (int)\Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM {{%migration}} WHERE version != :base',
            [':base' => self::BASE_VERSION]
        )->queryScalar();
    }
}
