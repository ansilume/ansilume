<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * What the running PHP deprecates among its own functions, methods and
 * constants, read by reflection (the #[\Deprecated] attribute, and the flag
 * of older deprecations), so that no list has to name what a PHP line
 * deprecates: for DeprecatedPhpCallsTest.
 *
 * Each map takes a name to what PHP says when code uses it, such as
 * 'curl_close' => 'Function curl_close() is deprecated since 8.5, as it has
 * no effect since PHP 8.0'. Function and method names are lower case, as PHP
 * matches them so; constant names keep their case.
 *
 * A scan of the source cannot tell which class a method call or a class
 * constant is on, so those are known by name only: a name counts when every
 * internal class that declares it deprecates it (SplObjectStorage::attach()
 * is deprecated, SplSubject::attach() is not), and without() leaves out the
 * names that userland code declares too (userlandDeclarations()).
 */
final class PhpDeprecations
{
    /**
     * @param array<string, string> $functions deprecated internal functions, by lower-case name
     * @param array<string, string> $methods method names (lower case) that each internal class
     *     declaring one deprecates
     * @param array<string, string> $constants deprecated global constants
     * @param array<string, string> $classConstants class constant names that each internal class
     *     declaring one deprecates
     */
    public function __construct(
        public readonly array $functions,
        public readonly array $methods,
        public readonly array $constants,
        public readonly array $classConstants
    ) {
    }

    public static function ofRunningPhp(): self
    {
        $classes = [];
        foreach (array_merge(get_declared_classes(), get_declared_interfaces()) as $name) {
            $class = new \ReflectionClass($name);
            if ($class->isInternal()) {
                $classes[] = $class;
            }
        }

        return new self(
            self::deprecatedFunctions(),
            self::deprecatedEverywhere(self::methodsOf($classes)),
            self::deprecatedConstants(),
            self::deprecatedEverywhere(self::classConstantsOf($classes))
        );
    }

    /**
     * The method and class constant names of these deprecations that userland
     * code declares as well (DeclaredNames), with the paths that declare them.
     *
     * @param iterable<string, string> $codes the PHP code of each userland file, by path
     * @return array{methods: array<string, list<string>>, classConstants: array<string, list<string>>}
     */
    public function userlandDeclarations(iterable $codes): array
    {
        $names = new DeclaredNames(array_keys($this->methods), array_keys($this->classConstants));
        $declared = ['methods' => [], 'classConstants' => []];
        foreach ($codes as $path => $code) {
            foreach ($names->in($code) as $kind => $found) {
                foreach ($found as $name) {
                    $declared[$kind][$name][] = (string)$path;
                }
            }
        }
        ksort($declared['methods']);
        ksort($declared['classConstants']);

        return $declared;
    }

    /**
     * These deprecations without the method and class constant names of
     * $declarations (userlandDeclarations()).
     *
     * @param array{methods: array<string, list<string>>, classConstants: array<string, list<string>>} $declarations
     */
    public function without(array $declarations): self
    {
        return new self(
            $this->functions,
            array_diff_key($this->methods, $declarations['methods']),
            $this->constants,
            array_diff_key($this->classConstants, $declarations['classConstants'])
        );
    }

    /**
     * @return array<string, string>
     */
    private static function deprecatedFunctions(): array
    {
        $deprecated = [];
        foreach (get_defined_functions()['internal'] as $name) {
            $function = new \ReflectionFunction($name);
            if ($function->isDeprecated()) {
                $deprecated[strtolower($name)] = self::says("Function {$function->getName()}()", $function);
            }
        }
        ksort($deprecated);

        return $deprecated;
    }

    /**
     * @return array<string, string>
     */
    private static function deprecatedConstants(): array
    {
        $deprecated = [];
        foreach (get_defined_constants(true) as $extension => $constants) {
            if ($extension === 'user') {
                continue;
            }
            foreach (array_keys($constants) as $name) {
                $constant = new \ReflectionConstant((string)$name);
                if ($constant->isDeprecated()) {
                    $deprecated[(string)$name] = self::says("Constant {$name}", $constant);
                }
            }
        }
        ksort($deprecated);

        return $deprecated;
    }

    /**
     * Every method the internal classes declare, by lower-case name: what
     * PHP says when it is called, or null where it is not deprecated.
     *
     * @param list<\ReflectionClass<object>> $classes
     * @return array<string, list<string|null>>
     */
    private static function methodsOf(array $classes): array
    {
        $methods = [];
        foreach ($classes as $class) {
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $class->getName()) {
                    $methods[strtolower($method->getName())][] = $method->isDeprecated()
                        ? self::says("Method {$class->getName()}::{$method->getName()}()", $method)
                        : null;
                }
            }
        }

        return $methods;
    }

    /**
     * Every constant the internal classes declare, enum cases included, by
     * name: what PHP says when it is read, or null where it is not deprecated.
     *
     * @param list<\ReflectionClass<object>> $classes
     * @return array<string, list<string|null>>
     */
    private static function classConstantsOf(array $classes): array
    {
        $constants = [];
        foreach ($classes as $class) {
            foreach ($class->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === $class->getName()) {
                    $constants[$constant->getName()][] = $constant->isDeprecated()
                        ? self::says("Constant {$class->getName()}::{$constant->getName()}", $constant)
                        : null;
                }
            }
        }

        return $constants;
    }

    /**
     * The names that every declaration deprecates, with what PHP says for
     * each declaration, sorted.
     *
     * @param array<string, list<string|null>> $declarations
     * @return array<string, string>
     */
    private static function deprecatedEverywhere(array $declarations): array
    {
        $deprecated = [];
        foreach ($declarations as $name => $says) {
            if (!in_array(null, $says, true)) {
                sort($says);
                $deprecated[(string)$name] = implode('; ', $says);
            }
        }
        ksort($deprecated);

        return $deprecated;
    }

    /**
     * What PHP says when code uses $what: "<what> is deprecated", then the
     * version and the message of its #[\Deprecated] attribute, if it has them.
     */
    private static function says(
        string $what,
        \ReflectionFunctionAbstract|\ReflectionClassConstant|\ReflectionConstant $deprecated
    ): string {
        // ReflectionConstant has getAttributes() since PHP 8.5, which PHPStan 1.12 does not know.
        $attributes = method_exists($deprecated, 'getAttributes') ? $deprecated->getAttributes(\Deprecated::class) : [];
        $arguments = $attributes === [] ? [] : $attributes[0]->getArguments();
        $message = $arguments['message'] ?? $arguments[0] ?? '';
        $since = $arguments['since'] ?? $arguments[1] ?? '';

        return $what . ' is deprecated'
            . (is_string($since) && $since !== '' ? " since {$since}" : '')
            . (is_string($message) && $message !== '' ? ", {$message}" : '');
    }
}
