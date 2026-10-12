<?php

declare(strict_types=1);

namespace app\tests\unit;

use PHPUnit\Framework\Assert;

/**
 * Reads the #[\SensitiveParameter] marks of the application classes for
 * SensitiveParametersTest: by reflection for methods, and from the source
 * for closures, which class reflection cannot see.
 */
final class SensitiveParameterScan
{
    /** The directories of the application classes (PSR-4 under app\), from the repository root. */
    public const DIRECTORIES = ['commands', 'components', 'controllers', 'helpers', 'jobs', 'models', 'services'];

    /**
     * Every application class, trait, interface and enum, by the file
     * reflection reports for it (a class preloaded from elsewhere is read
     * from there).
     *
     * @return array<string, \ReflectionClass<object>>
     */
    public static function classesByFile(): array
    {
        $root = dirname(__DIR__, 2);
        $classes = [];
        foreach (self::DIRECTORIES as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$directory}", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $name = 'app\\' . str_replace('/', '\\', substr($relative, 0, -4));
                Assert::assertTrue(
                    class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name),
                    "{$relative} declares {$name}"
                );
                $class = new \ReflectionClass($name);
                $classes[(string)$class->getFileName()] = $class;
            }
        }
        ksort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * Every marked method parameter, once: keyed by the file and line that
     * declare its method, so a method that classes take from a trait (or
     * inherit) counts once, under the class-like whose file declares it.
     * Methods declared outside the application, such as Yii's, are skipped.
     *
     * @param array<string, \ReflectionClass<object>> $classes
     * @return array<string, array{file: string, label: string, name: string}>
     */
    public static function markedMethodParameters(array $classes): array
    {
        $marked = [];
        foreach ($classes as $class) {
            foreach ($class->getMethods() as $method) {
                $file = (string)$method->getFileName();
                if (!isset($classes[$file])) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if ($parameter->getAttributes(\SensitiveParameter::class) === []) {
                        continue;
                    }
                    $key = "{$file}:{$method->getStartLine()}:{$method->getName()}:{$parameter->getName()}";
                    $marked[$key] = [
                        'file' => $file,
                        'label' => "{$classes[$file]->getName()}::{$method->getName()}(\${$parameter->getName()})",
                        'name' => $parameter->getName(),
                    ];
                }
            }
        }

        return $marked;
    }

    /**
     * The parameter names $file marks, in source order: for every attribute
     * group that holds SensitiveParameter, the variable after it.
     *
     * @return list<string>
     */
    public static function markedNamesInSource(string $file): array
    {
        $names = [];
        // depth: brackets open inside an attribute group ("#[" opens it, its "]" closes it);
        // marked: the last group held SensitiveParameter and its variable has not come yet.
        $scan = ['depth' => 0, 'marked' => false];
        foreach (token_get_all((string)file_get_contents($file)) as $token) {
            $name = self::markedVariable($token, $scan);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * One token of the scan: the name of the variable that a mark before it
     * applies to, or null.
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     * @param array{depth: int, marked: bool} $scan
     */
    private static function markedVariable(array|string $token, array &$scan): ?string
    {
        if (!is_array($token)) {
            $scan['depth'] = self::depthAfter($token, $scan['depth']);
            return null;
        }
        if ($token[0] === T_ATTRIBUTE) {
            $scan['depth'] = 1;
        } elseif ($scan['depth'] > 0) {
            $scan['marked'] = $scan['marked'] || ltrim($token[1], '\\') === 'SensitiveParameter';
        } elseif ($scan['marked'] && $token[0] === T_VARIABLE) {
            $scan['marked'] = false;
            return substr($token[1], 1);
        }

        return null;
    }

    /**
     * The bracket depth inside an attribute group after a one-character
     * token; outside a group (depth 0) brackets do not count.
     */
    private static function depthAfter(string $token, int $depth): int
    {
        if ($depth === 0) {
            return 0;
        }

        return match ($token) {
            '[' => $depth + 1,
            ']' => $depth - 1,
            default => $depth,
        };
    }
}
