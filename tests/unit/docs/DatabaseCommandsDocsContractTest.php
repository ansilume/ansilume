<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract: the database commands that the docs and the quickstart give an
 * operator work as given.
 *
 * The backup before an update and the restore of the rollback take the
 * user, the password and the database from the db container's environment
 * (MYSQL_USER, MYSQL_PASSWORD and MYSQL_DATABASE, which the compose files
 * set from DB_USER, DB_PASSWORD and DB_NAME of .env): in single quotes, the
 * container's shell expands them. The operator's shell does not read .env.
 * Regression (verification of the PHP 8.5 move): docs/updating.md and the
 * quickstart's hint passed -p"$DB_PASSWORD", which the operator's shell
 * expanded to nothing, so the backup before an update was empty (exit 2,
 * "Access denied ... (using password: NO)"), or waited for a password, and
 * the restore read the dump's first line as the password.
 *
 * An external database keeps the db service, without its profile: the
 * other services name it in depends_on. Regression (same verification):
 * installation.md said to remove the db: service, and Docker Compose then
 * refuses the file (`service "app" depends on undefined service "db":
 * invalid compose project`).
 */
class DatabaseCommandsDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    /** The backup before an update, as docs/updating.md and the quickstart give it. */
    public const BACKUP = 'docker compose exec -T db sh -c \'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"\' > backup.sql';

    /** The restore of the rollback in docs/updating.md. */
    public const RESTORE = 'docker compose exec -T db sh -c \'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"\' < backup.sql';

    /** The variables of the db container (the mariadb image) that a command may give as the password. */
    private const CONTAINER_PASSWORDS = ['MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD'];

    /** A MySQL or MariaDB client, then its -p or --password= option in the same command. */
    private const PASSWORD_OPTION = '/(?<![\w.-])(?:mysqldump|mariadb-dump|mysqladmin|mariadb-admin|mariadb|mysql)(?![\w-])[^\n;|&]*?\s(?:-p|--password=)/';

    /** The compose files of the stack, with a db service. */
    private const COMPOSE_FILES = ['docker-compose.yml', 'docker-compose.prebuilt.yml'];

    private const EXTERNAL_DATABASE = '## External database';

    public function testTheBackupAndTheRestoreTakeTheDbContainersCredentials(): void
    {
        $this->assertContains(self::BACKUP, $this->lines($this->section('docs/updating.md', '## Before you update')), 'the backup before an update');
        $this->assertContains(self::RESTORE, $this->lines($this->section('docs/updating.md', '## Rollback')), 'the restore of the rollback');
        $this->assertSame(['  ' . self::BACKUP], $this->printedBackupHints(), 'bin/quickstart prints the backup as the docs give it');
        $section = $this->flat('docs/updating.md', Php85UpdateDocsContractTest::UPDATE_SECTION);
        foreach (
            [
                '**The backup and the restore take the database container\'s credentials.** The backup of'
                    . ' [Before you update](#before-you-update), the restore of [Rollback](#rollback) and the backup that the'
                    . ' quickstart suggests when it updates passed `-p"$DB_PASSWORD"`, which your shell expands to nothing,'
                    . ' as it does not read `.env`: the backup came out empty',
                'They take the user, the password and the database from the database container now: `' . self::BACKUP . '`.'
                    . ' Change scripts and cron entries that copied the old command, and check the backups they made.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }

        foreach (self::COMPOSE_FILES as $file) {
            $compose = Yaml::parseFile($this->root() . '/' . $file);
            $this->assertIsArray($compose);
            $environment = $compose['services']['db']['environment'] ?? null;
            $this->assertIsArray($environment, "{$file}: the db service");
            foreach (['MYSQL_USER' => 'DB_USER', 'MYSQL_PASSWORD' => 'DB_PASSWORD', 'MYSQL_DATABASE' => 'DB_NAME'] as $variable => $setting) {
                $this->assertStringStartsWith('${' . $setting, (string)($environment[$variable] ?? ''), "{$file}: {$variable} comes from {$setting} of .env");
            }
        }
    }

    /**
     * No command of docs/, README.md and bin/ gives a MySQL or MariaDB
     * client a password from a variable that the shell which expands it
     * cannot have: a variable of the operator's shell (in the docs, or
     * printed by a script), or one that a script does not set itself.
     */
    public function testNoCommandPassesAVariableOfTheHostShellAsTheDatabasePassword(): void
    {
        $this->assertSame([], $this->hostPasswords());
    }

    public function testAnExternalDatabaseKeepsTheDbServiceWithoutItsProfile(): void
    {
        $section = $this->flat('docs/installation.md', self::EXTERNAL_DATABASE);
        $this->assertContains('COMPOSE_PROFILES=', $this->lines($this->section('docs/installation.md', self::EXTERNAL_DATABASE)), 'the .env lines');
        foreach (
            [
                'set the following in `.env`, with `COMPOSE_PROFILES` empty, as the quickstart does when you choose an external database:',
                'Without the `internal-db` profile, the bundled MariaDB container (the `db` service) does not start.',
                'Leave the `db:` service in `docker-compose.yml`: the other services name it in `depends_on`, and Docker'
                    . ' Compose refuses a file without it (`service "app" depends on undefined service "db": invalid compose'
                    . ' project`, or another service in place of `app`).',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'installation.md');
        }
        $this->assertStringNotContainsString('remove the `db:` service', $section, 'The db service stays.');

        foreach (self::COMPOSE_FILES as $file) {
            $compose = Yaml::parseFile($this->root() . '/' . $file);
            $this->assertIsArray($compose);
            $services = $compose['services'] ?? [];
            $this->assertIsArray($services);
            $this->assertSame(['internal-db'], $services['db']['profiles'] ?? null, "{$file}: the db service starts only with the internal-db profile");
            $dependents = 0;
            foreach ($services as $name => $service) {
                $this->assertIsArray($service, "{$file}: {$name}");
                $dependsOn = $service['depends_on'] ?? [];
                $this->assertIsArray($dependsOn, "{$file}: {$name}");
                // A service of the same profile goes with the db service.
                if (($service['profiles'] ?? null) === ['internal-db'] || $name === 'db') {
                    continue;
                }
                $this->assertNotContains('db', $dependsOn, "{$file}: {$name} names the db service as required (list form)");
                if (array_key_exists('db', $dependsOn)) {
                    $dependents++;
                    $this->assertFalse($dependsOn['db']['required'] ?? true, "{$file}: {$name} runs without the db service");
                }
            }
            $this->assertGreaterThan(0, $dependents, "{$file}: services name the db service in depends_on");
        }
        $this->assertStringContainsString('COMPOSE_PROFILES=""', $this->read('bin/quickstart'), 'The quickstart empties it for an external database.');
    }

    /**
     * Each command that gives a client a password from a variable no shell
     * that expands it can have, as "<file>:<line>: <text>".
     *
     * @return list<string>
     */
    private function hostPasswords(): array
    {
        $found = [];
        foreach ($this->markdownFiles() as $file) {
            foreach ($this->codeOf($file) as $line => $fragments) {
                foreach ($fragments as $code) {
                    foreach (self::passwordVariables($code) as $variable) {
                        if (!self::inContainer($code, $variable)) {
                            $found[] = "{$file}:{$line}: {$code}";
                        }
                    }
                }
            }
        }
        foreach (glob($this->root() . '/bin/*') ?: [] as $path) {
            $file = 'bin/' . basename($path);
            $script = $this->read($file);
            foreach (preg_split('/\R/', $script) ?: [] as $index => $line) {
                foreach (self::passwordVariables($line) as $variable) {
                    if (!self::scriptCanHave($script, $line, $variable)) {
                        $found[] = "{$file}:" . ($index + 1) . ": {$line}";
                    }
                }
            }
        }

        return $found;
    }

    /**
     * The variables $code gives a client as its password: the name, and
     * the offset of its "$" (or of the backslash that escapes it).
     *
     * @return list<array{name: string, offset: int}>
     */
    private static function passwordVariables(string $code): array
    {
        $variables = [];
        preg_match_all(self::PASSWORD_OPTION, $code, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$option, $offset]) {
            $valueStart = $offset + strlen($option);
            $value = preg_match('/\G\S*/', $code, $token, 0, $valueStart) === 1 ? $token[0] : '';
            if (preg_match('/\\\\?\$\{?([A-Za-z_][A-Za-z0-9_]*)/', $value, $reference, PREG_OFFSET_CAPTURE) === 1) {
                $variables[] = ['name' => $reference[1][0], 'offset' => $valueStart + $reference[0][1]];
            }
        }

        return $variables;
    }

    /**
     * Whether $variable is expanded by the db container's shell: it is one
     * of the container's and stands in single quotes of $command, as in
     * `docker compose exec -T db sh -c '... -p"$MYSQL_PASSWORD" ...'`.
     *
     * @param array{name: string, offset: int} $variable
     */
    private static function inContainer(string $command, array $variable): bool
    {
        return in_array($variable['name'], self::CONTAINER_PASSWORDS, true)
            && (self::quoting($command)[$variable['offset']] ?? '') === "'";
    }

    /**
     * Whether the shell that expands $variable of $line in $script has it:
     * a "$" that the script escapes is printed for the operator's shell,
     * which needs it in the single quotes of a command for the container;
     * one in the script's own single quotes is for the container too; any
     * other the script expands itself, so it must set the variable.
     *
     * @param array{name: string, offset: int} $variable
     */
    private static function scriptCanHave(string $script, string $line, array $variable): bool
    {
        $offset = $variable['offset'];
        $quoting = self::quoting($line);
        if ($line[$offset] === '\\') {
            [$printed, $at] = self::printed($line, $quoting, $offset);

            return self::inContainer($printed, ['name' => $variable['name'], 'offset' => $at]);
        }
        if (($quoting[$offset] ?? '') === "'") {
            return self::inContainer($line, $variable);
        }

        return preg_match('/^\s*(?:export\s+|local\s+|readonly\s+)?' . $variable['name'] . '=/m', $script) === 1;
    }

    /**
     * The text that the double-quoted string around $offset of $line
     * prints, and where the byte at $offset lands in it.
     *
     * @param list<string> $quoting the quoting of $line (quoting())
     * @return array{0: string, 1: int}
     */
    private static function printed(string $line, array $quoting, int $offset): array
    {
        $start = $offset;
        while ($start > 0 && $quoting[$start - 1] === '"') {
            $start--;
        }
        $end = $offset;
        while ($end + 1 < count($quoting) && $quoting[$end + 1] === '"') {
            $end++;
        }
        // $start is the opening quote, $end the closing one.
        $content = substr($line, $start + 1, $end - $start - 1);
        $before = substr($line, $start + 1, $offset - $start - 1);

        return [self::unescape($content), strlen(self::unescape($before))];
    }

    /**
     * A double-quoted string's text as the shell prints it: a backslash
     * before $, `, " or another backslash is dropped.
     */
    private static function unescape(string $text): string
    {
        return (string)preg_replace('/\\\\([\\\\"$`])/', '$1', $text);
    }

    /**
     * How a POSIX shell quotes each byte of $code: "'" inside single quotes,
     * '"' inside double quotes, the quotes themselves included, and '' for
     * the rest. A backslash outside single quotes and the byte it escapes
     * keep the quoting around them.
     *
     * @return list<string>
     */
    private static function quoting(string $code): array
    {
        $quoting = [];
        $state = '';
        $length = strlen($code);
        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];
            if ($state !== "'" && $char === '\\' && $i + 1 < $length) {
                array_push($quoting, $state, $state);
                $i++;
                continue;
            }
            if ($state === '' && ($char === "'" || $char === '"')) {
                $state = $char;
                $quoting[] = $state;
                continue;
            }
            $quoting[] = $state;
            if ($char === $state) {
                $state = '';
            }
        }

        return $quoting;
    }

    /**
     * The lines that bin/quickstart prints with the backup hint, as it
     * prints them when Docker Compose is `docker compose`.
     *
     * @return list<string>
     */
    private function printedBackupHints(): array
    {
        $hints = [];
        foreach (preg_split('/\R/', $this->read('bin/quickstart')) ?: [] as $line) {
            if (preg_match('/^\s*info "(.*backup\.sql.*)"$/', $line, $match) === 1) {
                $hints[] = str_replace('$COMPOSE_CMD', 'docker compose', self::unescape($match[1]));
            }
        }

        return $hints;
    }

    /**
     * The markdown files of docs/ (all levels) and README.md.
     *
     * @return list<string>
     */
    private function markdownFiles(): array
    {
        $files = ['README.md'];
        $docs = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/docs', \FilesystemIterator::SKIP_DOTS));
        foreach ($docs as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'md') {
                $files[] = substr($entry->getPathname(), strlen($this->root()) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The code of a markdown file, by line number: a line of a code block
     * whole, and the inline code spans of any other line.
     *
     * @return array<int, list<string>>
     */
    private function codeOf(string $file): array
    {
        $code = [];
        $inBlock = false;
        foreach (preg_split('/\R/', $this->read($file)) ?: [] as $index => $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $inBlock = !$inBlock;
                continue;
            }
            if ($inBlock) {
                $code[$index + 1] = [$line];
            } elseif (preg_match_all('/`([^`]+)`/', $line, $spans) > 0) {
                $code[$index + 1] = $spans[1];
            }
        }

        return $code;
    }

    /**
     * The lines of $text, trimmed.
     *
     * @return list<string>
     */
    private function lines(string $text): array
    {
        return array_map('trim', preg_split('/\R/', $text) ?: []);
    }
}
