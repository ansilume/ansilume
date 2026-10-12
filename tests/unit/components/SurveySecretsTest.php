<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SecretCipher;
use app\components\SecretCipherException;
use app\components\SurveySecretException;
use app\components\SurveySecrets;
use app\tests\unit\TraceArguments;
use PHPUnit\Framework\TestCase;

/**
 * SurveySecrets: splitting password answers off the extra vars, and sealing
 * and opening them.
 */
class SurveySecretsTest extends TestCase
{
    private const MARKER = 'survey-secret-marker-9931';

    private mixed $originalKey;

    protected function setUp(): void
    {
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'survey-secrets-test-key-0123456789';
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
    }

    // -- split ------------------------------------------------------------------

    public function testSplitReplacesSecretValuesInPlaceAndKeepsTheOrder(): void
    {
        $split = SurveySecrets::split(
            ['env' => 'prod', 'db_password' => self::MARKER, 'region' => 'eu', 'api_key' => ['nested' => true]],
            ['db_password', 'api_key', 'not_present']
        );

        $this->assertSame(
            ['env' => 'prod', 'db_password' => SurveySecrets::PLACEHOLDER, 'region' => 'eu', 'api_key' => SurveySecrets::PLACEHOLDER],
            $split['public']
        );
        $this->assertSame(['db_password' => self::MARKER, 'api_key' => ['nested' => true]], $split['secret'], 'any JSON type, as given');
    }

    public function testSplitWithoutSecretKeysChangesNothing(): void
    {
        $vars = ['env' => 'prod', 7 => 'numbered'];

        $this->assertSame(['public' => $vars, 'secret' => []], SurveySecrets::split($vars, []));
        $this->assertSame(['public' => [], 'secret' => []], SurveySecrets::split([], ['db_password']));
    }

    public function testSplitComparesNumberedKeysAsText(): void
    {
        $split = SurveySecrets::split([5 => 'five', 'x' => 'y'], ['5']);

        $this->assertSame([5 => SurveySecrets::PLACEHOLDER, 'x' => 'y'], $split['public']);
        $this->assertSame(['5' => 'five'], $split['secret']);
    }

    // -- seal / open ------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function valueProvider(): array
    {
        return [
            'text' => [['pw' => 'p@ss w0rd "quoted" \\ back/slash']],
            'empty text' => [['pw' => '']],
            'unicode' => [['pw' => 'pässwörd 🔑 密码']],
            'integer' => [['pin' => 4711]],
            'negative integer' => [['pin' => -42]],
            'float' => [['ratio' => 3.25]],
            'big integer' => [['big' => json_decode('123456789012345678901234567890', true)]],
            'boolean and null' => [['flag' => false, 'unset' => null]],
            'empty list' => [['list' => []]],
            'nested lists and maps' => [['tree' => ['a' => [1, 2, ['b' => 'c']], 'd' => [['e' => null]]]]],
            'several keys' => [['one' => '1', 'two' => [2], 'three' => ['3' => 3]]],
        ];
    }

    /**
     * open(seal($x)) === $x for every value json_decode(..., true) gives.
     *
     * @dataProvider valueProvider
     * @param array<string, mixed> $secret
     */
    public function testOpenReturnsWhatSealSealed(array $secret): void
    {
        $sealed = SurveySecrets::seal($secret);

        $this->assertIsString($sealed);
        $this->assertSame($secret, SurveySecrets::open($sealed));
    }

    /**
     * Floats without a fraction come back as integers, exactly as the inline
     * --extra-vars JSON passes them (no JSON_PRESERVE_ZERO_FRACTION): both
     * runner generations see the same value.
     */
    public function testAWholeFloatComesBackAsTheInlineJsonPassesIt(): void
    {
        $inline = json_decode((string)json_encode(['n' => 10.0], JSON_UNESCAPED_UNICODE), true);

        $this->assertSame(['n' => 10], SurveySecrets::open(SurveySecrets::seal(['n' => 10.0])));
        $this->assertSame($inline, SurveySecrets::open(SurveySecrets::seal(['n' => 10.0])));
    }

    public function testNothingToSealGivesNull(): void
    {
        $this->assertNull(SurveySecrets::seal([]));
        $this->assertSame([], SurveySecrets::open(null));
        $this->assertSame([], SurveySecrets::open(''));
    }

    public function testTheSealedValueHidesTheAnswerAndDiffersEachTime(): void
    {
        $first = (string)SurveySecrets::seal(['pw' => self::MARKER]);
        $second = (string)SurveySecrets::seal(['pw' => self::MARKER]);

        $this->assertStringNotContainsString(self::MARKER, $first);
        $this->assertStringNotContainsString(self::MARKER, (string)base64_decode($first, true));
        $this->assertNotSame($first, $second);
        $this->assertSame('{"pw":"' . self::MARKER . '"}', (new SecretCipher())->decrypt($first), 'sealed is the shared cipher format');
    }

    public function testAnswersThatAreNotUtf8CannotBeSealed(): void
    {
        try {
            SurveySecrets::seal(['pw' => "invalid \xB1\x31 " . self::MARKER]);
            $this->fail('expected a SurveySecretException');
        } catch (SurveySecretException $e) {
            $this->assertSame('The password answers cannot be stored: they contain characters that are not valid UTF-8.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            TraceArguments::assertSecretAbsent(self::MARKER, $e);
        }
    }

    /**
     * JSON allows 1e999; PHP reads it as INF and cannot write it back.
     *
     * Regression: seal() called such a number "characters that are not
     * valid UTF-8" (the message a schedule's form showed for it).
     */
    public function testAnAnswerWithANumberTooLargeForJsonCannotBeSealed(): void
    {
        try {
            SurveySecrets::seal(['pw' => [self::MARKER, json_decode('1e999')]]);
            $this->fail('expected a SurveySecretException');
        } catch (SurveySecretException $e) {
            $this->assertSame('The password answers cannot be stored: they contain a number too large for JSON.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            TraceArguments::assertSecretAbsent(self::MARKER, $e);
        }
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function encodeErrorProvider(): array
    {
        return [
            'INF or NaN' => [JSON_ERROR_INF_OR_NAN, 'The extra vars cannot be stored: they contain a number too large for JSON.'],
            'not UTF-8' => [JSON_ERROR_UTF8, 'The extra vars cannot be stored: they contain characters that are not valid UTF-8.'],
            'too deep' => [JSON_ERROR_DEPTH, 'The extra vars cannot be stored as JSON.'],
            'anything else' => [JSON_ERROR_UNSUPPORTED_TYPE, 'The extra vars cannot be stored as JSON.'],
        ];
    }

    /**
     * @dataProvider encodeErrorProvider
     */
    public function testUnencodableNamesWhyTheValuesCannotBeStored(int $jsonError, string $message): void
    {
        $this->assertSame($message, SurveySecrets::unencodable('extra vars', $jsonError));
    }

    public function testAnswersNestedTooDeeplyCannotBeSealed(): void
    {
        $deep = self::MARKER;
        for ($level = 0; $level < 600; $level++) {
            $deep = [$deep];
        }

        try {
            SurveySecrets::seal(['pw' => $deep]);
            $this->fail('expected a SurveySecretException');
        } catch (SurveySecretException $e) {
            $this->assertSame('The password answers cannot be stored as JSON.', $e->getMessage());
            TraceArguments::assertSecretAbsent(self::MARKER, $e);
        }
    }

    public function testSealingWithoutAUsableKeyFailsWithoutTheAnswer(): void
    {
        $_ENV['APP_SECRET_KEY'] = 'short';

        try {
            SurveySecrets::seal(['pw' => self::MARKER]);
            $this->fail('expected a SurveySecretException');
        } catch (SurveySecretException $e) {
            $this->assertSame('The password answers cannot be encrypted. Check APP_SECRET_KEY.', $e->getMessage());
            $this->assertInstanceOf(SecretCipherException::class, $e->getPrevious());
            TraceArguments::assertSecretAbsent(self::MARKER, $e);
        }
    }

    /**
     * @return array<string, array{0: callable(): string}>
     */
    public static function unopenableProvider(): array
    {
        return [
            'not base64' => [static fn (): string => '###'],
            'another key' => [static function (): string {
                $iv = str_repeat("\x02", 16);
                $key = hash('sha256', 'the-key-before-rotation', true);

                return base64_encode($iv . (string)openssl_encrypt('{"pw":"x"}', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
            }],
            'not JSON' => [static fn (): string => (new SecretCipher())->encrypt('not json')],
            'JSON text, not a map' => [static fn (): string => (new SecretCipher())->encrypt('"text"')],
        ];
    }

    /**
     * @dataProvider unopenableProvider
     * @param callable(): string $sealed
     */
    public function testWhatCannotBeOpenedFailsWithTheFixedMessage(callable $sealed): void
    {
        $this->expectExceptionObject(new SurveySecretException('The encrypted password answers cannot be decrypted (was APP_SECRET_KEY changed?).'));
        SurveySecrets::open($sealed());
    }

    public function testOpeningWithoutAUsableKeyFails(): void
    {
        $sealed = SurveySecrets::seal(['pw' => 'x']);
        unset($_ENV['APP_SECRET_KEY']);

        try {
            SurveySecrets::open($sealed);
            $this->fail('expected a SurveySecretException');
        } catch (SurveySecretException $e) {
            $this->assertInstanceOf(SecretCipherException::class, $e->getPrevious());
        }
    }

    // -- reveal / withoutKeys ---------------------------------------------------

    public function testRevealPutsTheAnswersBackInPlace(): void
    {
        $public = ['env' => 'prod', 'db_password' => SurveySecrets::PLACEHOLDER, 'region' => 'eu'];

        $this->assertSame(
            ['env' => 'prod', 'db_password' => self::MARKER, 'region' => 'eu', 'carried' => [1]],
            SurveySecrets::reveal($public, ['db_password' => self::MARKER, 'carried' => [1]])
        );
        $this->assertSame($public, SurveySecrets::reveal($public, []));
    }

    public function testWithoutKeysRemovesTheGivenKeys(): void
    {
        $vars = ['env' => 'prod', 'db_password' => SurveySecrets::PLACEHOLDER, 5 => 'five'];

        $this->assertSame(['env' => 'prod'], SurveySecrets::withoutKeys($vars, ['db_password', '5', 'absent']));
        $this->assertSame(['env' => 'prod', 'db_password' => SurveySecrets::PLACEHOLDER], SurveySecrets::withoutKeys($vars, [5]));
        $this->assertSame($vars, SurveySecrets::withoutKeys($vars, []));
    }

    // -- reencode -----------------------------------------------------------------

    /**
     * Extra vars, and what they become once the value of the key "0" is
     * masked: a password field may be named "0".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function maskedShapeProvider(): array
    {
        return [
            'an object whose only key is 0' => ['{"0":"x"}', '{"0":"$encrypted$"}'],
            'an object keyed 0 and 1' => ['{"0":"x","1":"y"}', '{"0":"$encrypted$","1":"y"}'],
            'an object with other keys too' => ['{"env":"prod","0":"x"}', '{"env":"prod","0":"$encrypted$"}'],
            'an object without the key' => ['{"1":"x"}', '{"1":"x"}'],
            'an object after whitespace' => ["\r\n\t {\"0\":\"x\"}", '{"0":"$encrypted$"}'],
            'a list' => ['["x","y"]', '["$encrypted$","y"]'],
            'a list after whitespace' => ["\n [\"x\"]", '["$encrypted$"]'],
            'an empty object' => ['{}', '{}'],
            'an empty list' => ['[]', '[]'],
        ];
    }

    /**
     * Regression (task 3 of R2V2-1): json_decode() turns the key "0" into 0,
     * and json_encode() writes an array whose keys are exactly 0 to n-1 as a
     * list, so {"0": ...} came back as [...].
     *
     * @dataProvider maskedShapeProvider
     */
    public function testReencodeKeepsTheTopLevelShape(string $json, string $expected): void
    {
        $vars = json_decode($json, true);
        $this->assertIsArray($vars);

        $this->assertSame($expected, SurveySecrets::reencode($json, SurveySecrets::split($vars, ['0'])['public'], JSON_UNESCAPED_UNICODE));
    }

    public function testReencodeWithoutTheTextWritesAnObject(): void
    {
        $this->assertSame('{"0":"x"}', SurveySecrets::reencode(null, ['x'], 0));
        $this->assertSame('{}', SurveySecrets::reencode(null, [], 0));
    }

    /**
     * Only a list is cast to an object: a cast drops a key that begins with
     * a NUL byte, which an object with other keys can hold.
     */
    public function testReencodeKeepsAKeyThatBeginsWithANulByte(): void
    {
        $json = '{"\u0000a":"x","0":"y"}';
        $vars = json_decode($json, true);
        $this->assertIsArray($vars);

        $this->assertSame('{"\u0000a":"x","0":"$encrypted$"}', SurveySecrets::reencode($json, SurveySecrets::split($vars, ['0'])['public'], 0));
    }

    public function testReencodeTakesTheFlags(): void
    {
        $this->assertSame("{\n    \"0\": \"pfad/ä\"\n}", SurveySecrets::reencode('{"0":"x"}', ['pfad/ä'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * A number too large for JSON (1e999 decodes as INF): false, as for
     * json_encode(), and no exception that would carry the values.
     */
    public function testReencodeIsFalseForANumberTooLargeForJson(): void
    {
        $this->assertFalse(SurveySecrets::reencode('{"0":1e999}', [INF], 0));
    }
}
