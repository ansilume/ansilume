<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ProcessStarter;
use app\components\PythonFloatText;
use PHPUnit\Framework\TestCase;

/**
 * PythonFloatText writes a float as Python does, so that the runner's
 * OutputMasker masks a float password answer in each text in which Ansible
 * prints it (CLS-1). The table holds what python3 3.13 and PyYAML 6.0.2 of
 * the images write; testPythonWritesTheSameTextsForManyFloats() asks the
 * python3 that runs the tests about many more floats.
 */
class PythonFloatTextTest extends TestCase
{
    /**
     * Reads big-endian doubles in hex, one per line, from the file named by
     * the first argument, and writes for each repr(), PyYAML's text and
     * json.dumps(), separated by spaces.
     */
    private const PYTHON = <<<'PY'
        import json, struct, sys
        import yaml.representer
        represent = yaml.representer.SafeRepresenter().represent_float
        with open(sys.argv[1]) as values:
            for line in values:
                value = struct.unpack('>d', bytes.fromhex(line.strip()))[0]
                print(repr(value), represent(value).value, json.dumps(value))
        PY;

    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    /**
     * python3's repr(), PyYAML's text (SafeRepresenter, which Ansible's YAML
     * dumper takes floats from) and json.dumps() of each value.
     *
     * @return array<string, array{0: float, 1: string, 2: string, 3: string}>
     */
    public static function pythonTextProvider(): array
    {
        return [
            'zero' => [0.0, '0.0', '0.0', '0.0'],
            'negative zero' => [-0.0, '-0.0', '-0.0', '-0.0'],
            'one' => [1.0, '1.0', '1.0', '1.0'],
            'short' => [0.5, '0.5', '0.5', '0.5'],
            'a tenth' => [0.1, '0.1', '0.1', '0.1'],
            'a sum that is no tenth' => [0.1 + 0.2, '0.30000000000000004', '0.30000000000000004', '0.30000000000000004'],
            'a third' => [1 / 3, '0.3333333333333333', '0.3333333333333333', '0.3333333333333333'],
            'with a fraction' => [1234.5, '1234.5', '1234.5', '1234.5'],
            'negative' => [-2.5, '-2.5', '-2.5', '-2.5'],
            'whole' => [100.0, '100.0', '100.0', '100.0'],
            'negative whole' => [-1234.0, '-1234.0', '-1234.0', '-1234.0'],
            '17 significant digits' => [0.12345678901234568, '0.12345678901234568', '0.12345678901234568', '0.12345678901234568'],
            '16 significant digits' => [3.141592653589793, '3.141592653589793', '3.141592653589793', '3.141592653589793'],
            '15 significant digits' => [12345678901234.5, '12345678901234.5', '12345678901234.5', '12345678901234.5'],
            'whole, 16 digits' => [1e15, '1000000000000000.0', '1000000000000000.0', '1000000000000000.0'],
            'the largest in fixed notation' => [1e16 - 2, '9999999999999998.0', '9999999999999998.0', '9999999999999998.0'],
            'beyond the exact integers' => [9007199254740993.0, '9007199254740992.0', '9007199254740992.0', '9007199254740992.0'],
            'the smallest with a positive exponent' => [1e16, '1e+16', '1.0e+16', '1e+16'],
            'exponent' => [1e20, '1e+20', '1.0e+20', '1e+20'],
            'negative with an exponent' => [-1e20, '-1e+20', '-1.0e+20', '-1e+20'],
            '1e23, no exact float' => [1e23, '1e+23', '1.0e+23', '1e+23'],
            'beyond the int range' => [123456789012345678901234.0, '1.2345678901234569e+23', '1.2345678901234569e+23', '1.2345678901234569e+23'],
            '17 digits with an exponent' => [123456789012345680.0, '1.2345678901234568e+17', '1.2345678901234568e+17', '1.2345678901234568e+17'],
            'the smallest in fixed notation' => [0.0001, '0.0001', '0.0001', '0.0001'],
            'the largest below 0.0001' => [9.999999999999999e-05, '9.999999999999999e-05', '9.999999999999999e-05', '9.999999999999999e-05'],
            'small' => [0.00001, '1e-05', '1.0e-05', '1e-05'],
            'small with a fraction' => [1.5e-7, '1.5e-07', '1.5e-07', '1.5e-07'],
            'negative, small' => [-1.5e-7, '-1.5e-07', '-1.5e-07', '-1.5e-07'],
            'the smallest' => [5e-324, '5e-324', '5.0e-324', '5e-324'],
            'the smallest normal' => [2.2250738585072014e-308, '2.2250738585072014e-308', '2.2250738585072014e-308', '2.2250738585072014e-308'],
            'the largest' => [1.7976931348623157e308, '1.7976931348623157e+308', '1.7976931348623157e+308', '1.7976931348623157e+308'],
            // Above a power of two the nearest decimal of 16 digits lies below
            // and reads back as another float, the next one up does not.
            '2 ** -24' => [2.0 ** -24, '5.960464477539063e-08', '5.960464477539063e-08', '5.960464477539063e-08'],
            '-(2 ** -44)' => [-(2.0 ** -44), '-5.684341886080802e-14', '-5.684341886080802e-14', '-5.684341886080802e-14'],
            '2 ** 89' => [2.0 ** 89, '6.189700196426902e+26', '6.189700196426902e+26', '6.189700196426902e+26'],
            '2 ** -1017' => [2.0 ** -1017, '7.120236347223045e-307', '7.120236347223045e-307', '7.120236347223045e-307'],
            'infinity' => [INF, 'inf', '.inf', 'Infinity'],
            'negative infinity' => [-INF, '-inf', '-.inf', '-Infinity'],
            'not a number' => [NAN, 'nan', '.nan', 'NaN'],
        ];
    }

    /**
     * @dataProvider pythonTextProvider
     */
    public function testTheTextsAreThoseOfPython(float $value, string $repr, string $yaml, string $json): void
    {
        $this->assertSame($repr, PythonFloatText::repr($value), 'repr()');
        $this->assertSame($yaml, PythonFloatText::yaml($value), "PyYAML's text");
        $this->assertSame($json, PythonFloatText::json($value), 'json.dumps()');
    }

    /**
     * The digits come from sprintf() and the float casts, not from the
     * precision PHP writes floats with: (string) and var_export() write
     * 0.1 with 17 digits under serialize_precision 17.
     */
    public function testTheTextsDoNotDependOnThePrecisionSettingsOfPhp(): void
    {
        $precision = ini_set('precision', '5');
        $serializePrecision = ini_set('serialize_precision', '17');
        try {
            $this->assertSame('0.10000000000000001', var_export(0.1, true), 'the setting is in effect');
            $this->assertSame('0.1', PythonFloatText::repr(0.1));
            $this->assertSame('0.12345678901234568', PythonFloatText::repr(0.12345678901234568));
            $this->assertSame('1.0e+20', PythonFloatText::yaml(1e20));
        } finally {
            if ($precision !== false) {
                ini_set('precision', $precision);
            }
            if ($serializePrecision !== false) {
                ini_set('serialize_precision', $serializePrecision);
            }
        }
    }

    /**
     * Every power of two (where a nearest decimal can miss), and 10,000
     * random bit patterns and 10,000 numbers as people write them, up to 8
     * digits with an exponent: python3 writes each as PythonFloatText does.
     */
    public function testPythonWritesTheSameTextsForManyFloats(): void
    {
        if ($this->python(['-c', 'import yaml'])['rc'] !== 0) {
            $this->markTestSkipped('python3 with PyYAML is needed to compare the texts.');
        }
        $values = [];
        for ($exponent = -1074; $exponent <= 1023; $exponent++) {
            $values[] = 2.0 ** $exponent;
        }
        mt_srand(20261026);
        for ($i = 0; $i < 10000; $i++) {
            $bits = unpack('E', pack('J', mt_rand(0, PHP_INT_MAX) | (mt_rand(0, 1) << 63)));
            $random = is_array($bits) ? $bits[1] : null;
            if (is_float($random) && is_finite($random)) {
                $values[] = $random;
            }
            $values[] = (float)(mt_rand(1, 99999999) . 'e' . mt_rand(-30, 30));
        }
        $this->file = sys_get_temp_dir() . '/python_float_text_' . uniqid('', true) . '.txt';
        file_put_contents($this->file, implode("\n", array_map(static fn (float $value): string => bin2hex(pack('E', $value)), $values)) . "\n");

        $result = $this->python(['-c', self::PYTHON, $this->file]);
        $this->assertSame(0, $result['rc'], $result['err']);
        $lines = explode("\n", rtrim($result['out'], "\n"));
        $this->assertCount(count($values), $lines);
        $mismatches = [];
        foreach ($values as $index => $value) {
            $ours = PythonFloatText::repr($value) . ' ' . PythonFloatText::yaml($value) . ' ' . PythonFloatText::json($value);
            if ($ours !== $lines[$index] && count($mismatches) < 10) {
                $mismatches[] = bin2hex(pack('E', $value)) . ": python {$lines[$index]}, ours {$ours}";
            }
        }
        $this->assertSame([], $mismatches);
    }

    /**
     * @param list<string> $args
     * @return array{rc: int, out: string, err: string}
     */
    private function python(array $args): array
    {
        // -I: neither the working directory nor PYTHON* variables reach the import path.
        $process = ProcessStarter::open(['python3', '-I', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === null) {
            return ['rc' => 127, 'out' => '', 'err' => 'python3 not available'];
        }
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['rc' => proc_close($process), 'out' => $out, 'err' => $err];
    }
}
