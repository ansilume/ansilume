<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\EnvFlag;
use PHPUnit\Framework\TestCase;

/**
 * EnvFlag reads on/off settings such as YII_DEBUG from the environment.
 *
 * Regression: yii and web/index.php read YII_DEBUG with (bool), and
 * (bool)'false' is true in PHP, so YII_DEBUG=false in .env turned debug mode
 * (and the web E2E hooks) on.
 */
class EnvFlagTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function onProvider(): array
    {
        return [
            '1' => ['1'],
            'true' => ['true'],
            'on' => ['on'],
            'yes' => ['yes'],
            'upper case' => ['TRUE'],
            'mixed case' => ['On'],
            'surrounding spaces' => [' true '],
            'trailing newline' => ["1\n"],
            'bool true' => [true],
            'int 1' => [1],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function offProvider(): array
    {
        return [
            'false' => ['false'],
            '0' => ['0'],
            'off' => ['off'],
            'no' => ['no'],
            'empty' => [''],
            'upper case FALSE' => ['FALSE'],
            'spaces only' => ['   '],
            'not set' => [null],
            'bool false' => [false],
            'int 0' => [0],
            'int 2' => [2],
            'unrecognized word' => ['enabled'],
            'unrecognized number' => ['2'],
            'negative number' => ['-1'],
            'typo of true' => ['ture'],
            'array' => [['1']],
        ];
    }

    /**
     * @dataProvider onProvider
     */
    public function testRecognizedOnValuesAreOn(mixed $value): void
    {
        $this->assertTrue(EnvFlag::isOn($value));
    }

    /**
     * Everything that is not clearly on is off: a typo or an unknown value
     * must never turn debug mode on.
     *
     * @dataProvider offProvider
     */
    public function testEverythingElseIsOff(mixed $value): void
    {
        $this->assertFalse(EnvFlag::isOn($value));
    }
}
