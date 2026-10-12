<?php

declare(strict_types=1);

namespace app\components;

/**
 * The private files of one job on the runner, and the password survey
 * answers among them.
 *
 * Every job gets a new directory, <temp directory>/ansilume_job_<pid>_<random>
 * with 32 lowercase hex digits as <random>, that only the runner's user can
 * open (0700). The runner writes the job's own files into it: the callback
 * plugin's task records, a static inventory and the answers file. Ansible
 * loads group_vars/ and host_vars/ from the directory of an inventory,
 * whoever owns them; in the shared temp directory any local user could add
 * variables, connection variables included, to every job with a static
 * inventory.
 *
 * Servers since this release send the answers in payload['secret_extra_vars']
 * to runners that report Runner::CAPABILITY_SECRET_EXTRA_VARS, instead of
 * inline in the command. They go to a 0600 file in the job's directory that
 * ansible-playbook reads through `--extra-vars @<file>`, so they show neither
 * in the process list nor in the command line ansible-playbook prints from
 * verbosity 4 on.
 *
 * - jobDirectory() creates the directory on first use. mkdir() fails when
 *   anything is at the path, a link included, so a path someone else put
 *   there is never reused or followed; the job then fails.
 * - prepare() writes the answers file and returns the arguments to append to
 *   the command; [] when the payload carries no answers, which includes every
 *   older server: the command then runs as sent. A file that cannot be
 *   written fails the job. It never runs without the answers, and the answers
 *   never go inline instead.
 * - writePrivateFile() creates a new file only the runner's user can read;
 *   the runner writes the task records file and the static inventory with it.
 * - cleanup() removes the directory with everything in it, without following
 *   links; the runner calls it after every job. removeStale() removes the
 *   directories of runner processes that were killed during a job, which
 *   never got to clean up, as far as they are the runner user's own. It
 *   considers only names of exactly that form and leaves every other name
 *   alone, unreported.
 * - filesOfOlderRunners() names the files that runners of 2.8.1 or older
 *   wrote straight into the temp directory and may have left there. It
 *   removes none of them: such a runner may still run on the same host.
 */
final class RunnerSecretVars
{
    /** Payload field with the answers by name, any JSON type. */
    public const PAYLOAD_KEY = 'secret_extra_vars';

    /** Name prefix of a job's directory; the runner's process ID, an underscore and 32 lowercase hex digits follow. */
    public const DIRECTORY_PREFIX = 'ansilume_job_';

    /** The answers file, in the job's directory. */
    public const ANSWERS_FILE = 'secret_vars.json';

    public const WRITE_FAILED = 'The runner could not write the survey answers file.';

    /** Why removeStale() leaves a stale match in place. */
    public const SKIP_NOT_A_DIRECTORY = 'not a directory';
    public const SKIP_OTHER_OWNER = 'owned by another user';
    public const SKIP_NOT_REMOVED = 'could not be removed';
    public const SKIP_UNKNOWN_USER = "the runner's user is unknown: the posix extension is missing";

    /** uniqid('', true): 13 hex digits, then a number with 8 decimals. */
    private const UNIQID = '[0-9a-f]{13}[0-9]+\.[0-9]{8}';

    /**
     * The names of the files that runners of 2.8.1 or older wrote straight
     * into the temp directory: a static inventory,
     * ansilume_inv_<uniqid>.yml, and the task records of a job,
     * ansilume_tasks_<job id>_<uniqid>.ndjson.
     */
    private const OLDER_RUNNER_FILE = '/^(?:ansilume_inv_' . self::UNIQID . '\.yml|ansilume_tasks_[0-9]+_' . self::UNIQID . '\.ndjson)$/D';

    /** The file type bits of a stat() mode, and their value for a directory. */
    private const TYPE_MASK = 0o170000;
    private const TYPE_DIRECTORY = 0o040000;

    private string $directory;

    /** @var \Closure(): ?int the runner's effective user ID; null when it cannot be told */
    private \Closure $effectiveUid;

    /** @var \Closure(): string the random part of a job directory's name */
    private \Closure $randomPart;

    private ?string $jobDirectory = null;

    /**
     * @param string|null $directory where the job's directory goes; the system temp directory by default
     * @param (\Closure(): ?int)|null $effectiveUid the runner's effective user ID, null when unknown, and then the sweep removes nothing; posix_geteuid() by default
     * @param (\Closure(): string)|null $randomPart the random part of the directory's name; 32 lowercase hex digits from random_bytes() by default, the only form removeStale() considers
     */
    public function __construct(?string $directory = null, ?\Closure $effectiveUid = null, ?\Closure $randomPart = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir();
        $this->effectiveUid = $effectiveUid
            ?? static fn (): ?int => function_exists('posix_geteuid') ? posix_geteuid() : null;
        $this->randomPart = $randomPart ?? static fn (): string => bin2hex(random_bytes(16));
    }

    /**
     * The answers of the payload by name; [] when it carries none.
     *
     * @param array<string, mixed> $payload
     * @return array<array-key, mixed>
     */
    public static function answers(#[\SensitiveParameter] array $payload): array
    {
        $answers = $payload[self::PAYLOAD_KEY] ?? null;

        return is_array($answers) ? $answers : [];
    }

    /**
     * Creates the file $path, readable and writable by the runner's user
     * only (umask 0177), and writes $content to it. The file must not exist
     * yet ('x'), so a file or link someone else put at that path is never
     * followed or reused. Warnings stay local: Yii would turn them into an
     * exception whose trace shows the arguments. A partly written file is
     * removed.
     *
     * The callback plugin opens its task records file with open(path, 'w'),
     * which truncates the file this created and keeps its mode.
     *
     * @return bool whether the whole content was written
     */
    public static function writePrivateFile(string $path, #[\SensitiveParameter] string $content): bool
    {
        $previousUmask = umask(0o177);
        set_error_handler(static fn (): bool => true);
        try {
            $handle = fopen($path, 'x');
            if ($handle === false) {
                return false;
            }
            $written = fwrite($handle, $content) === strlen($content);
            $written = fclose($handle) && $written;
            if (!$written) {
                unlink($path);
            }

            return $written;
        } finally {
            restore_error_handler();
            umask($previousUmask);
        }
    }

    /**
     * The job's directory, created on the first call: a new directory with
     * mode 0700 (umask 0077). mkdir() fails when anything is at the path, a
     * link included, so nothing someone else put there is reused or
     * followed. Warnings stay local.
     *
     * @return string|null the directory; null when it cannot be created
     */
    public function jobDirectory(): ?string
    {
        if ($this->jobDirectory !== null) {
            return $this->jobDirectory;
        }
        try {
            $path = $this->directory . '/' . self::DIRECTORY_PREFIX . (int)getmypid() . '_' . ($this->randomPart)();
        } catch (\Random\RandomException) {
            return null;
        }
        $previousUmask = umask(0o077);
        set_error_handler(static fn (): bool => true);
        try {
            if (mkdir($path, 0o700)) {
                $this->jobDirectory = $path;
            }
        } finally {
            restore_error_handler();
            umask($previousUmask);
        }

        return $this->jobDirectory;
    }

    /**
     * Writes the answers file into the job's directory.
     *
     * @param array<string, mixed> $payload the claim payload
     * @return list<string> the arguments to append to the command
     * @throws \RuntimeException WRITE_FAILED when the file cannot be written
     */
    public function prepare(#[\SensitiveParameter] array $payload): array
    {
        $answers = self::answers($payload);
        if ($answers === []) {
            return [];
        }
        // A JSON object at the top level, as -e expects; no JSON_THROW_ON_ERROR,
        // whose exception would carry the answers in its trace.
        $json = json_encode((object)$answers, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException(self::WRITE_FAILED);
        }
        $directory = $this->jobDirectory();
        if ($directory === null || !self::writePrivateFile($directory . '/' . self::ANSWERS_FILE, $json)) {
            throw new \RuntimeException(self::WRITE_FAILED);
        }

        return ['--extra-vars', '@' . $directory . '/' . self::ANSWERS_FILE];
    }

    /**
     * Removes the job's directory with everything in it; links in it are
     * removed, never followed. Safe to call more than once, and when no
     * directory was created. Best effort: what cannot be removed stays for
     * removeStale() at the runner's next start.
     */
    public function cleanup(): void
    {
        if ($this->jobDirectory === null) {
            return;
        }
        set_error_handler(static fn (): bool => true);
        try {
            self::remove($this->jobDirectory);
        } finally {
            restore_error_handler();
            $this->jobDirectory = null;
        }
    }

    /**
     * Removes the job directories of runner processes that no longer run,
     * and of this one: a runner calls it when it starts, before it has
     * created any, and its process ID may be the one of the killed process
     * (PID 1 in a restarted container). Directories of runners that still
     * run stay. Without $proc (no procfs) nothing tells whose a directory is,
     * so nothing goes.
     *
     * It considers only entries named exactly as jobDirectory() names them,
     * ansilume_job_<pid>_<32 lowercase hex digits>, and neither removes nor
     * reports any other name: every path it reports is the temp directory
     * and such a name. Another user of a shared temp directory can still
     * create an entry with such a name; it is reported, never entered or
     * removed.
     *
     * Best effort, so it never keeps a runner from starting: only
     * directories (no links) of the runner's own user are removed, and in
     * them it enters only directories of that user; links are never
     * followed, warnings stay local, and every stale match left in place is
     * reported with the reason. When the runner's user is unknown (no posix
     * extension), nothing tells whose a match is: none is removed, every one
     * is reported. Such a match may still hold answers or host passwords;
     * the operator removes it.
     *
     * @param string $proc the procfs mount
     * @return array{removed: int, skipped: array<string, string>} how many directories were removed, and why each stale match that is left stays
     */
    public function removeStale(string $proc = '/proc'): array
    {
        $sweep = ['removed' => 0, 'skipped' => []];
        if (!is_dir($proc . '/self')) {
            return $sweep;
        }
        set_error_handler(static fn (): bool => true);
        try {
            foreach (scandir($this->directory) ?: [] as $name) {
                if (!$this->isStale($name, $proc)) {
                    continue;
                }
                $path = $this->directory . '/' . $name;
                $reason = $this->removeStaleDirectory($path);
                if ($reason === null) {
                    $sweep['removed']++;
                } elseif (self::exists($path)) {
                    // Not reported when it is gone: another runner's sweep was faster.
                    $sweep['skipped'][$path] = $reason;
                }
            }
        } finally {
            restore_error_handler();
        }

        return $sweep;
    }

    /**
     * The files that runners of 2.8.1 or older wrote straight into the temp
     * directory, readable by every local user under the usual umask, and
     * left there when a credential could not be set up or the runner was
     * killed: a static inventory, which can hold host passwords, and the
     * task records of a job, whose task names can carry answers. Regular
     * files and links with exactly those names, whoever owns them. None is
     * removed or followed, because a runner of 2.8.1 or older on the same
     * host may still be using one; the operator removes them. Warnings stay
     * local.
     *
     * @return list<string> the paths, sorted by name
     */
    public function filesOfOlderRunners(): array
    {
        $files = [];
        set_error_handler(static fn (): bool => true);
        try {
            clearstatcache();
            foreach (scandir($this->directory) ?: [] as $name) {
                $path = $this->directory . '/' . $name;
                if (preg_match(self::OLDER_RUNNER_FILE, $name) === 1 && (is_link($path) || is_file($path))) {
                    $files[] = $path;
                }
            }
        } finally {
            restore_error_handler();
        }

        return $files;
    }

    /**
     * Whether $name is the name of a job directory exactly as jobDirectory()
     * makes it, the prefix, a process ID, an underscore and the 32 lowercase
     * hex digits of the default random part, with nothing after it (/D: not
     * even a line break), of a process that no longer runs or of this one.
     * Any other name is not the runner's, whoever made it: the sweep neither
     * removes nor reports it.
     */
    private function isStale(string $name, string $proc): bool
    {
        $pattern = '/^' . preg_quote(self::DIRECTORY_PREFIX, '/') . '(\d+)_[0-9a-f]{32}$/D';
        if (preg_match($pattern, $name, $match) !== 1) {
            return false;
        }
        $pid = (int)$match[1];

        return $pid === getmypid() || !is_dir($proc . '/' . $pid);
    }

    /**
     * Removes one stale match if it is a directory of the runner's user,
     * told without following a link. When that user is unknown, nothing
     * tells whose a match is, so none goes.
     *
     * @return string|null null when it was removed, otherwise why it stays
     */
    private function removeStaleDirectory(string $path): ?string
    {
        $uid = ($this->effectiveUid)();
        if ($uid === null) {
            return self::SKIP_UNKNOWN_USER;
        }
        $entry = self::entry($path);
        if ($entry === null || !$entry['directory']) {
            return self::SKIP_NOT_A_DIRECTORY;
        }
        if ($entry['owner'] !== $uid) {
            return self::SKIP_OTHER_OWNER;
        }

        return self::removeOwn($path, $uid) ? null : self::SKIP_NOT_REMOVED;
    }

    /**
     * Removes what is at $path in a stale job directory of user $uid. It
     * enters only directories of that user, told without following a link;
     * anything else that is not a directory, a link included, is unlinked,
     * never followed. A directory of another user is left with what it
     * holds, and so are the directories above it. Runs under the caller's
     * error handler.
     *
     * @return bool whether it was removed
     */
    private static function removeOwn(string $path, int $uid): bool
    {
        $entry = self::entry($path);
        if ($entry === null || !$entry['directory']) {
            return unlink($path);
        }
        if ($entry['owner'] !== $uid) {
            return false;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeOwn($path . '/' . $name, $uid);
            }
        }

        return rmdir($path);
    }

    /**
     * What is at $path itself, never what a link there points to (lstat()):
     * whether it is a directory, and its owner. Null when nothing is there.
     *
     * @return array{directory: bool, owner: int}|null
     */
    private static function entry(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if ($stat === false) {
            return null;
        }

        return ['directory' => ($stat['mode'] & self::TYPE_MASK) === self::TYPE_DIRECTORY, 'owner' => $stat['uid']];
    }

    /**
     * Removes what is at $path in the job's own directory: a directory with
     * everything in it, whoever made it during the job, anything else, a
     * link included, by itself. Links are removed, never followed. Runs
     * under the caller's error handler.
     *
     * @return bool whether it was removed
     */
    private static function remove(string $path): bool
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) {
            return unlink($path);
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path . '/' . $name);
            }
        }

        return rmdir($path);
    }

    /**
     * Whether anything, a dangling link included, is at $path now.
     */
    private static function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path);
    }
}
