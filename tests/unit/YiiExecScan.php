<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * Finds the execs that run Yii in a container of the app image, with the
 * user each of them runs as: in the text of a script, a workflow, a
 * template or a document, and in the tasks of an Ansible role. For
 * YiiExecUserTest.
 *
 * - An exec is docker compose exec, also docker-compose and a variable
 *   that holds compose ($COMPOSE_CMD, ${compose}), docker exec, a call of a
 *   function of the scripts that execs its arguments in the app container
 *   (wrappersIn(): dc() of bin/tests-common.sh), and a task of the Ansible
 *   modules docker_container_exec and docker_compose_v2_exec.
 * - It runs in the app image when it names one of its services (app,
 *   queue-worker, schedule-runner, and the runners, which run the app image
 *   in a git checkout) or one of their containers (bin/diagnose's
 *   $app_container, ansilume_app).
 * - It runs Yii when its command runs php yii, or the yii script by its
 *   path (./yii, /var/www/yii, also through require), or boots a console
 *   application: bin/diagnose's PHP_BOOT prelude, a yii\console\Application
 *   of its own.
 * - It runs as the user of its -u or --user option, as www-data through
 *   gosu www-data, and as root, the user of the app image, otherwise; a
 *   wrapper's call runs as the user of the exec in the wrapper.
 *
 * Its command runs from the exec to the end of the shell command: a line
 * end outside quotes (a line continuation goes on), ;, &, | or the ) of a
 * command substitution. An exec quoted in a string (a hint the script
 * prints: info "… docker compose exec app php yii …") ends where the string
 * ends, one in a comment at the line end, one in a code span of a document
 * at the closing backtick; quotes in a document do not span lines.
 *
 * It cannot see an exec whose command comes from a variable or a
 * function, a container named by a variable other than $app_container*, a
 * wrapper defined outside the scripts, an exec through ssh, kubectl or a
 * here-document, or nested quotes in a quoted hint. docker compose run
 * starts the image's entrypoint, which runs the command as www-data: it is
 * no exec here.
 *
 * It also finds the console routes that a text names (routesIn()), for
 * NamedConsoleRoutesExistTest, and lists the files that both guards read
 * (filesIn()).
 */
final class YiiExecScan
{
    /** The user of the app image, as whom an exec runs without -u. */
    public const ROOT = 'root';

    /** The user of php-fpm's workers, the queue-worker and the schedule-runner. */
    public const WWW_DATA = 'www-data';

    /** The compose services of the app image (the runners run it in a git checkout). */
    private const APP_SERVICE = '/^(?:app|queue-worker|schedule-runner|runner-\d+)$/';

    /** The containers of the app image: bin/diagnose's variables, the names compose and the deploy role give them. */
    private const APP_CONTAINER = '/^(?:\$\{?app_container\w*\}?|.*[_-](?:app|queue[_-]worker|schedule[_-]runner|runner[_-]\d+)(?:[_-]\d+)?)$/';

    /** Where an exec starts: compose (named or in a variable), or docker before exec. */
    private const START = '/(?<![\w$\/.-])(?:(?<compose>docker[ \t]+compose(?![\w.-])|docker-compose(?![\w.-])'
        . '|\$\{?(?:COMPOSE_CMD|compose)\}?(?![\w}]))|docker(?=[ \t]+exec\b(?!-)))/';

    /** A word of a shell command at the offset, after blanks and line continuations. */
    private const WORD = '/\G(?:[ \t]|\\\\\r?\n)*((?:[^\s;&|`\'"\\\\()<>]|\\\\.|"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\')+)/s';

    /** How many words compose may take before exec: -f <file>, -p <name>, --profile <name>, $COMPOSE_ARGS. */
    private const COMPOSE_WORDS = 8;

    /** The options of docker exec and docker compose exec that take a value, besides -u and --user. */
    private const VALUE_OPTIONS = ['-e', '--env', '-w', '--workdir', '--index', '--detach-keys', '--env-file'];

    /** -u with the short flags that may come before it in one word, its value in the next word (-Tu www-data). */
    private const SHORT_USER = '/^-[dDitT]*u$/';

    /** -u with the short flags that may come before it, its value attached (-uwww-data, -u=www-data). */
    private const SHORT_USER_VALUE = '/^-[dDitT]*u=?(.+)$/';

    /** What runs Yii in a command. */
    private const RUNS_YII = [
        // php [options] yii, the yii script by its path: php yii, php /var/www/yii, php -d memory_limit=1G yii
        '/(?<![\w.-])(?:\/[\w.\/-]*\/)?php(?:[ \t]+-\S+(?:[ \t]+[^\s-]\S*)?)*[ \t]+(?:\.\/|\/var\/www\/)?yii(?![\w\/.-])/',
        // the yii script run or required by its path: ./yii, /var/www/yii, require "/var/www/yii"
        '/(?<![\w.-])(?:\.\/|\/var\/www\/)yii(?![\w\/.-])/',
        // bin/diagnose's prelude, which boots a console application
        '/(?<!\w)PHP_BOOT(?!\w)/',
        // a console application of its own
        '/yii\\\\+console\\\\+Application/',
    ];

    /** gosu www-data in a command: the rest runs as www-data. */
    private const GOSU_WWW_DATA = '/(?<![\w-])gosu[ \t]+www-data(?:[ \t]|$)/';

    /** The Ansible modules that exec in a container. */
    private const ANSIBLE_EXEC = '/^(?:community\.docker\.)?docker_(?:container|compose_v2)_exec$/';

    /**
     * A console route that a command names: php with its options before the
     * yii script, or the yii script by its path (./yii, /var/www/yii), then
     * the route, its next word. The words may stand on two lines (a code
     * span that wraps) or in a YAML flow sequence (["php", "yii",
     * "worker/start"]). The route ends where a shell word ends, so that a
     * placeholder (php yii …, php yii <command>), a variable ("$@") or a
     * pattern (ldap/*) is none, nor a word that prose ends with a full
     * stop, a comma or a colon (./yii does: …).
     */
    private const ROUTE = '/(?:(?<![\w.-])(?:\/[\w.\/-]*\/)?php(?:' . self::GAP . '-\S+(?:' . self::GAP . '[^\s-]\S*)?)*'
        . self::GAP . '(?:\.\/|\/var\/www\/)?|(?<![\w.-])(?:\.\/|\/var\/www\/))yii' . self::GAP
        . '(?<route>[a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)*)(?=[\s"\'`;&|)<>]|$)/';

    /** What stands between two words of a command for ROUTE: blanks and line ends, or the ", " of a YAML flow sequence. */
    private const GAP = '(?:\s+|"\s*,\s*")';

    /**
     * The functions that exec their arguments in the app container, with
     * the user they run as.
     *
     * @var array<string, string>
     */
    private array $wrappers;

    /**
     * @param array<string, string> $wrappers what wrappersIn() found
     */
    public function __construct(array $wrappers = [])
    {
        $this->wrappers = $wrappers;
    }

    /**
     * The functions of a script that exec their arguments ("$@") in a
     * container of the app image, such as dc() of bin/tests-common.sh, with
     * the user they run as.
     *
     * @return array<string, string> function => user
     */
    public static function wrappersIn(string $script): array
    {
        $wrappers = [];
        preg_match_all('/^([A-Za-z_]\w*)\(\)[ \t]*\{[ \t]*\n(.*?)^\}/ms', $script, $functions, PREG_SET_ORDER);
        foreach ($functions as $function) {
            foreach ((new self())->appExecsIn($function[2], false) as $exec) {
                if ($exec['command'] === '"$@"') {
                    $wrappers[$function[1]] = $exec['user'];
                }
            }
        }

        return $wrappers;
    }

    /**
     * The execs of $text that run Yii in a container of the app image.
     * $markdown: the text is a document, whose commands stand in code
     * spans and code blocks, and whose quotes do not span lines.
     *
     * @return list<array{line: int, user: string, exec: string}>
     */
    public function yiiExecsIn(string $text, bool $markdown): array
    {
        $found = [];
        foreach ($this->appExecsIn($text, $markdown) as $exec) {
            if (self::runsYii($exec['command'])) {
                $found[] = ['line' => $exec['line'], 'user' => $exec['user'], 'exec' => $exec['exec']];
            }
        }

        return $found;
    }

    /**
     * The console routes that $text names (ROUTE), each with the line its
     * command starts on and the command up to the route.
     *
     * @return list<array{line: int, route: string, command: string}>
     */
    public static function routesIn(string $text): array
    {
        preg_match_all(self::ROUTE, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $routes = [];
        foreach ($matches as $match) {
            $routes[] = [
                'line' => substr_count($text, "\n", 0, $match[0][1]) + 1,
                'route' => $match['route'][0],
                'command' => self::oneLine($match[0][0]),
            ];
        }

        return $routes;
    }

    /**
     * The files of $paths, from $root: a file itself, and every file of a
     * directory, in docs/ the documents (Markdown) only; sorted.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public static function filesIn(string $root, array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($root . '/' . $path)) {
                $files[] = $path;
                continue;
            }
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $path, \FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                $file = substr((string)$entry, strlen($root) + 1);
                if ($entry instanceof \SplFileInfo && $entry->isFile() && ($path !== 'docs' || str_ends_with($file, '.md'))) {
                    $files[] = $file;
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The execs of the tasks of an Ansible file (a parsed playbook, tasks or
     * handlers file) that run Yii in a container of the app image, by the
     * name of their task.
     *
     * @return list<array{task: string, user: string, exec: string}>
     */
    public static function ansibleYiiExecsIn(mixed $node, string $task = ''): array
    {
        if (!is_array($node)) {
            return [];
        }
        $name = is_string($node['name'] ?? null) ? $node['name'] : $task;
        $found = [];
        foreach ($node as $key => $value) {
            if (is_string($key) && preg_match(self::ANSIBLE_EXEC, $key) === 1) {
                $exec = self::ansibleExec($key, $value);
                if ($exec !== null) {
                    $found[] = ['task' => $name] + $exec;
                }
                continue;
            }
            $found = array_merge($found, self::ansibleYiiExecsIn($value, $name));
        }

        return $found;
    }

    /**
     * Every exec of $text into a container of the app image, Yii or not.
     *
     * @return list<array{line: int, user: string, exec: string, command: string}>
     */
    private function appExecsIn(string $text, bool $markdown): array
    {
        $execs = [];
        preg_match_all(self::START, $text, $starts, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($starts as $start) {
            $exec = self::execAt($text, $start[0][1], $start[0][1] + strlen($start[0][0]), isset($start['compose']) && $start['compose'][1] >= 0, $markdown);
            if ($exec !== null) {
                $execs[] = $exec;
            }
        }
        if ($markdown || $this->wrappers === []) {
            return $execs;
        }
        $names = implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_keys($this->wrappers)));
        preg_match_all('/(?<![\w$\/.-])(' . $names . ')(?=[ \t])/', $text, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($calls as $call) {
            $offset = $call[0][1];
            if (self::enclosure($text, $offset, false) !== '') {
                // In a string or a comment: no call.
                continue;
            }
            $begin = $offset + strlen($call[0][0]);
            $end = self::commandEnd($text, $begin, '', false);
            $execs[] = self::execSpan($text, $offset, $begin, $end, $this->wrappers[$call[1][0]]);
        }

        return $execs;
    }

    /**
     * The exec that starts at $start, whose compose or docker ends at $offset,
     * when it execs in a container of the app image.
     *
     * @return array{line: int, user: string, exec: string, command: string}|null
     */
    private static function execAt(string $text, int $start, int $offset, bool $compose, bool $markdown): ?array
    {
        $offset = self::afterExec($text, $offset, $compose ? self::COMPOSE_WORDS : 1);
        if ($offset === null) {
            return null;
        }
        $options = self::execOptions($text, $offset);
        if ($options === null || preg_match($compose ? self::APP_SERVICE : self::APP_CONTAINER, $options['target']) !== 1) {
            return null;
        }
        $end = self::commandEnd($text, $options['offset'], self::enclosure($text, $start, $markdown), $markdown);

        return self::execSpan($text, $start, $options['offset'], $end, $options['user']);
    }

    /**
     * @return array{line: int, user: string, exec: string, command: string}
     */
    private static function execSpan(string $text, int $start, int $begin, int $end, string $user): array
    {
        $command = self::oneLine(substr($text, $begin, $end - $begin));
        if ($user === '') {
            $user = preg_match(self::GOSU_WWW_DATA, $command) === 1 ? self::WWW_DATA : self::ROOT;
        }

        return [
            'line' => substr_count($text, "\n", 0, $start) + 1,
            'user' => $user,
            'exec' => self::oneLine(substr($text, $start, $end - $start)),
            'command' => $command,
        ];
    }

    /**
     * The offset after the word exec, among the next $words words.
     */
    private static function afterExec(string $text, int $offset, int $words): ?int
    {
        for ($i = 0; $i < $words; $i++) {
            $word = self::wordAt($text, $offset);
            if ($word === null) {
                return null;
            }
            $offset = $word['offset'];
            if ($word['word'] === 'exec') {
                return $offset;
            }
        }

        return null;
    }

    /**
     * The options of an exec, its user (empty: none given) and its target.
     *
     * @return array{user: string, target: string, offset: int}|null
     */
    private static function execOptions(string $text, int $offset): ?array
    {
        $user = '';
        while (($word = self::wordAt($text, $offset)) !== null) {
            $offset = $word['offset'];
            $option = $word['word'];
            $takesUser = $option === '--user' || preg_match(self::SHORT_USER, $option) === 1;
            if ($takesUser || in_array($option, self::VALUE_OPTIONS, true)) {
                $value = self::wordAt($text, $offset);
                if ($value === null) {
                    return null;
                }
                $offset = $value['offset'];
                $user = $takesUser ? $value['word'] : $user;
            } elseif (str_starts_with($option, '--user=')) {
                $user = substr($option, strlen('--user='));
            } elseif (preg_match(self::SHORT_USER_VALUE, $option, $short) === 1) {
                $user = $short[1];
            } elseif (!str_starts_with($option, '-')) {
                return ['user' => explode(':', $user)[0], 'target' => $option, 'offset' => $offset];
            }
        }

        return null;
    }

    /**
     * The word at $offset, without its quotes, and the offset after it.
     *
     * @return array{word: string, offset: int}|null
     */
    private static function wordAt(string $text, int $offset): ?array
    {
        if (preg_match(self::WORD, $text, $match, 0, $offset) !== 1) {
            return null;
        }

        return [
            'word' => (string)preg_replace('/\\\\(.)|["\']/s', '$1', $match[1]),
            'offset' => $offset + strlen($match[0]),
        ];
    }

    /**
     * What the exec at $start stands in: a double-quoted or a single-quoted
     * string ("\"", "'"), a comment ("\n"), a code span of a document ("`"),
     * or nothing ('').
     */
    private static function enclosure(string $text, int $start, bool $markdown): string
    {
        $lineStart = self::logicalLineStart($text, $start);
        $before = substr($text, $lineStart, $start - $lineStart);
        if ($markdown) {
            return substr_count($before, '`') % 2 === 1 ? '`' : '';
        }
        $quote = '';
        $length = strlen($before);
        for ($i = 0; $i < $length; $i++) {
            $char = $before[$i];
            if ($quote === "'") {
                $quote = $char === "'" ? '' : $quote;
            } elseif ($char === '\\') {
                $i++;
            } elseif ($quote === '"') {
                $quote = $char === '"' ? '' : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '#' && ($i === 0 || ctype_space($before[$i - 1]))) {
                return "\n";
            }
        }

        return $quote;
    }

    /**
     * The start of the line of $offset, before the lines it continues (\ at
     * the end of a line).
     */
    private static function logicalLineStart(string $text, int $offset): int
    {
        $i = $offset;
        while ($i > 0) {
            if ($text[$i - 1] === "\n") {
                if ($i < 2 || $text[$i - 2] !== '\\') {
                    break;
                }
                $i--;
            }
            $i--;
        }

        return $i;
    }

    /**
     * Where the command that starts at $offset ends: in a string or a
     * comment where that ends (enclosedEnd()); else, in a code span at its
     * closing backtick, and at a line end outside quotes (in a document at
     * any line end), ;, &, |, a backtick, a comment, or the ) of the command
     * substitution it stands in.
     */
    private static function commandEnd(string $text, int $offset, string $enclosure, bool $markdown): int
    {
        if ($enclosure !== '' && $enclosure !== '`') {
            return self::enclosedEnd($text, $offset, $enclosure);
        }
        $quote = '';
        $depth = 0;
        $length = strlen($text);
        for ($i = $offset; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '\\' && $quote !== "'") {
                // An escaped character, a line continuation.
                $i++;
            } elseif ($char === '`' && ($enclosure === '`' || $quote === '')) {
                return $i;
            } elseif ($quote !== '') {
                if ($char === "\n" && $markdown) {
                    return $i;
                }
                $quote = $char === $quote ? '' : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif (($char === ')' && $depth === 0) || strpbrk($char, ";&|\n") !== false || ($char === '#' && ctype_space($text[$i - 1]))) {
                return $i;
            } elseif ($char === ')') {
                $depth--;
            }
        }

        return $length;
    }

    /**
     * Where a command quoted in a string ("\"", "'") or written in a comment
     * ("\n") ends: where the string or the comment ends, or at ;, &, |, a
     * line end or a backtick. Quotes in it are not told apart from the end
     * of the string.
     */
    private static function enclosedEnd(string $text, int $offset, string $enclosure): int
    {
        $length = strlen($text);
        for ($i = $offset; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '\\' && $enclosure === '"') {
                $i++;
            } elseif ($char === $enclosure || strpbrk($char, ";&|\n`") !== false) {
                return $i;
            }
        }

        return $length;
    }

    private static function runsYii(string $command): bool
    {
        foreach (self::RUNS_YII as $pattern) {
            if (preg_match($pattern, $command) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The exec of the parameters of an Ansible exec module, when it runs Yii
     * in a container of the app image.
     *
     * @return array{user: string, exec: string}|null
     */
    private static function ansibleExec(string $module, mixed $parameters): ?array
    {
        if (!is_array($parameters)) {
            return null;
        }
        $target = $parameters['container'] ?? $parameters['service'] ?? '';
        $pattern = isset($parameters['container']) ? self::APP_CONTAINER : self::APP_SERVICE;
        $argv = $parameters['argv'] ?? null;
        $command = is_array($argv)
            ? implode(' ', array_map(static fn (mixed $arg): string => is_scalar($arg) ? (string)$arg : '', $argv))
            : (is_string($parameters['command'] ?? null) ? $parameters['command'] : '');
        if (!is_string($target) || preg_match($pattern, $target) !== 1 || !self::runsYii($command)) {
            return null;
        }
        $user = is_string($parameters['user'] ?? null) ? explode(':', $parameters['user'])[0] : '';
        if ($user === '') {
            $user = preg_match(self::GOSU_WWW_DATA, $command) === 1 ? self::WWW_DATA : self::ROOT;
        }

        return ['user' => $user, 'exec' => $module . ' ' . $target . ': ' . $command];
    }

    /** $text on one line, its continuations and runs of blanks as one space. */
    private static function oneLine(string $text): string
    {
        return trim((string)preg_replace('/(?:\\\\\r?\n|\s)+/', ' ', $text));
    }
}
