<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\PendingMigrations;
use app\tests\integration\DbTestCase;

/**
 * The migration state that health/check, GET /health and schedule/run read:
 * migration files against the migrations the migration table lists.
 */
class PendingMigrationsTest extends DbTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ansilume_pending_migrations_' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testMigrationFilesBeyondTheAppliedOnesArePending(): void
    {
        $applied = $this->appliedInTheDatabase();
        $this->writeMigrationFiles($applied + 2);

        $migrations = new PendingMigrations($this->dir);

        $this->assertSame($applied + 2, $migrations->expectedCount());
        $this->assertSame($applied, $migrations->appliedCount());
        $this->assertSame(2, $migrations->count());
    }

    public function testNothingIsPendingWhenEveryFileIsApplied(): void
    {
        $this->writeMigrationFiles($this->appliedInTheDatabase());

        $this->assertSame(0, (new PendingMigrations($this->dir))->count());
    }

    /**
     * A database that ran migrations the code does not ship (a newer
     * release's, before a downgrade) has none pending.
     */
    public function testMoreAppliedMigrationsThanFilesAreNotPending(): void
    {
        $this->writeMigrationFiles(1);

        $this->assertSame(0, (new PendingMigrations($this->dir))->count());
    }

    /**
     * Only migration files count: other files and a trailing slash in the
     * path do not change the result.
     */
    public function testOnlyMigrationFilesCount(): void
    {
        $this->writeMigrationFiles(3);
        file_put_contents($this->dir . '/README.md', 'not a migration');
        file_put_contents($this->dir . '/helper.php', '<?php');

        $this->assertSame(3, (new PendingMigrations($this->dir . '/'))->expectedCount());
    }

    public function testAMissingDirectoryHasNoMigrationFiles(): void
    {
        $this->assertSame(0, (new PendingMigrations($this->dir . '/missing'))->expectedCount());
    }

    /**
     * By default it reads the application's migrations: every file in
     * @app/migrations, and the test database has run them all.
     */
    public function testByDefaultItReadsTheApplicationsMigrations(): void
    {
        $files = glob((string)\Yii::getAlias('@app/migrations') . '/m*.php');
        $this->assertNotFalse($files);

        $migrations = new PendingMigrations();

        $this->assertSame(count($files), $migrations->expectedCount());
        $this->assertSame($this->appliedInTheDatabase(), $migrations->appliedCount());
    }

    private function appliedInTheDatabase(): int
    {
        return (int)\Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {{%migration}} WHERE version != 'm000000_000000_base'"
        )->queryScalar();
    }

    private function writeMigrationFiles(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            file_put_contents(sprintf('%s/m%06d_000000_test.php', $this->dir, $i), '<?php');
        }
    }
}
