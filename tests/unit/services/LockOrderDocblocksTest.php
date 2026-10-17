<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\GatedJobTemplateLock;
use app\services\JobLaunchService;
use app\services\JobTemplateGate;
use PHPUnit\Framework\TestCase;

/**
 * The docblocks that state the lock order (C16) and say that the orders
 * cannot cross name the one path that crosses them: a project delete
 * (ProjectDeletionService) locks the project row and then, through the
 * foreign key's ON DELETE SET NULL (fk_inventory_project_id), the rows of
 * the project's inventories, while a template save share-locks its
 * inventories first and the project row only when it writes the template.
 * A save that moves a template into a project being deleted, while its
 * inventory belongs to that project, can therefore deadlock with the
 * delete: it writes nothing and answers a server error.
 */
class LockOrderDocblocksTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string|null}>
     */
    public static function docblockProvider(): array
    {
        return [
            'the launch (JobLaunchService)' => [JobLaunchService::class, null],
            'the template save (GatedJobTemplateLock)' => [GatedJobTemplateLock::class, null],
            'the reader of inventory rows (JobTemplateGate::readInventoryRules())' => [JobTemplateGate::class, 'readInventoryRules'],
        ];
    }

    /**
     * Regression (2.12.0 review, LOCKS-4): these docblocks said that the
     * orders cannot cross, but a project delete's cascade onto its
     * inventories crosses the order of a template save, which locks its
     * inventories before the project row: the two can deadlock.
     *
     * @dataProvider docblockProvider
     * @param class-string $class
     */
    public function testRegressionTheLockOrderNamesTheProjectDeleteThatCrossesIt(string $class, ?string $method): void
    {
        $text = self::docblock($class, $method);

        $this->assertStringContainsString('cannot cross', $text, 'the docblock states the lock order');
        foreach (['ProjectDeletionService', 'fk_inventory_project_id', 'ON DELETE SET NULL', 'deadlock'] as $part) {
            $this->assertStringContainsString($part, $text, "{$class}: the exception names {$part}");
        }
    }

    /**
     * The launch locks no project row (a job refers to no project), so it
     * stays out of the exception, and its docblock says so.
     */
    public function testTheLaunchIsNotPartOfTheException(): void
    {
        $this->assertStringContainsString('a launch locks no project row', self::docblock(JobLaunchService::class, null));
    }

    /**
     * The docblock of $class, or of its method $method, as one line: the
     * leading asterisks and the line breaks become single spaces.
     *
     * @param class-string $class
     */
    private static function docblock(string $class, ?string $method): string
    {
        $reflection = $method === null ? new \ReflectionClass($class) : new \ReflectionMethod($class, $method);

        return (string)preg_replace('~\s+~', ' ', (string)preg_replace('~^\s*/?\*+/?~m', ' ', (string)$reflection->getDocComment()));
    }
}
