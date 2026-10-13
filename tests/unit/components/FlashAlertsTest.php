<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\FlashAlerts;
use PHPUnit\Framework\TestCase;
use yii\web\Session;

/**
 * The layout prints the flashes FlashAlerts::of() hands it: the alerts of
 * the types success, info, warning and danger, in the order they were set,
 * each with its messages as a list. Every other flash is left out: a type
 * Bootstrap does not style, and a data flash, which carries a token to one
 * page and must never be printed by the layout.
 */
final class FlashAlertsTest extends TestCase
{
    public function testOnlyTheAlertTypesAreHandedToTheLayout(): void
    {
        $session = self::sessionWith([
            'success' => 'Saved.',
            'error' => 'An unstyled box.',
            'runner_token' => ['runner_id' => 7, 'runner_name' => 'r', 'raw_token' => 'secret-runner-token'],
            'danger' => ['First problem.', 'Second problem.'],
            'new_token' => 'secret-api-token',
            'info' => 'Note.',
            'trigger_token_raw' => 'secret-trigger-token',
            'warning' => 'Careful.',
            0 => 'a flash under a numeric key',
        ]);

        $this->assertSame(
            [
                'success' => ['Saved.'],
                'danger' => ['First problem.', 'Second problem.'],
                'info' => ['Note.'],
                'warning' => ['Careful.'],
            ],
            FlashAlerts::of($session)
        );
    }

    public function testNoSessionNoAlerts(): void
    {
        $this->assertSame([], FlashAlerts::of(null));
        $this->assertSame([], FlashAlerts::of(self::sessionWith([])));
    }

    public function testTheTypesAreTheOnesBootstrapStyles(): void
    {
        $this->assertSame(['success', 'info', 'warning', 'danger'], FlashAlerts::TYPES);
    }

    /**
     * A session whose getAllFlashes() answers $flashes.
     *
     * @param array<int|string, mixed> $flashes
     */
    private static function sessionWith(array $flashes): Session
    {
        return new class ($flashes) extends Session {
            /**
             * @param array<int|string, mixed> $flashes
             */
            public function __construct(private readonly array $flashes)
            {
                parent::__construct();
            }

            /**
             * @param bool $delete
             * @return array<int|string, mixed>
             */
            public function getAllFlashes($delete = false): array
            {
                return $this->flashes;
            }
        };
    }
}
