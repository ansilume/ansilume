<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs bin/diagnose as an operator does when the stack does not start: from
 * the install directory, here one of the test's own with its .env and
 * docker-compose.yml, with stand-ins for docker, free, df, uptime and curl.
 * The stand-in for docker answers as a running stack of app and nginx; each
 * test makes one part fail, as it fails on an operator's host.
 *
 * Regression: set -euo pipefail ended the run at the first command whose
 * failure diagnose means to tolerate, mostly without a word: free or df that
 * cannot read the host, after the Uptime line; an .env without any of the
 * non-secret keys it lists, or one the user may not read, after "Non-secret
 * configuration:"; a compose configuration that compose cannot load, after
 * "Found: docker-compose.yml", with compose's reason sent to /dev/null; an
 * .env without DB_HOST, REDIS_HOST or REDIS_PORT, whose defaults diagnose
 * meant to use, and an app container that refuses docker exec while it
 * restarts, both after the Network Connectivity header; a container that
 * vanished between its listing and its docker inspect. Lost each time: every
 * section after it, among them the Recent Logs that name why the app does
 * not start, and the Diagnosis Summary with its verdict.
 *
 * Regression: run outside the install directory, as with curl … | bash from
 * a home directory, diagnose found no compose file there, every compose
 * command failed, and it reported the running stack as not running, app and
 * nginx as "NOT RUNNING (critical)". It took an .env it may not read, as
 * the deploy role writes it 0600 for its own user, for one without any key:
 * "No CHANGE-ME defaults remaining", then every required variable "is
 * missing from .env".
 */
class DiagnoseReachesItsEndTest extends TestCase
{
    /** A value of the test's .env that diagnose must never print. */
    private const SECRET = 'not-a-real-secret-7c1e';

    /** The .env of an install: the keys diagnose reads. */
    private const ENV = [
        'APP_ENV' => 'prod',
        'DB_HOST' => 'mariadb',
        'DB_NAME' => 'ansilume',
        'DB_USER' => 'ansilume',
        'DB_PASSWORD' => self::SECRET,
        'REDIS_HOST' => 'cache',
        'REDIS_PORT' => '6380',
        'COOKIE_VALIDATION_KEY' => self::SECRET,
        'APP_SECRET_KEY' => self::SECRET,
        'RUNNER_BOOTSTRAP_SECRET' => self::SECRET,
    ];

    /** The keys diagnose lists as its non-secret configuration, as far as ENV has them. */
    private const LISTED_KEYS = ['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'REDIS_HOST', 'REDIS_PORT'];

    /** A line of Container Status: icon, name, state, health, restarts. */
    private const STATUS_LINE = "  %s %-30s state=%-10s health=%-12s restarts=%s\n";

    /** A line of Image Versions: name, image, created. */
    private const IMAGE_LINE = "  %-30s %s  (created: %s)\n";

    /** What the sections that list containers through compose say when it loads no configuration, and why. */
    private const STATE_UNKNOWN = 'Container state unknown: %s (see Docker Compose File)';

    /** Why compose loads no configuration although the directory has a compose file. */
    private const CANNOT_LOAD = 'docker compose cannot load the compose configuration';

    /** Why compose loads no configuration in a directory without a compose file. */
    private const NO_COMPOSE_FILE = 'there is no compose file in %s';

    /** What real compose says in a directory without a compose file. */
    private const NO_CONFIGURATION = 'no configuration file provided: not found';

    /** What the stand-in for compose says when it loads no configuration of a compose file. */
    private const UNPARSABLE_ENV = 'failed to read .env: line 2: key cannot contain a space';

    /** Why compose cannot tell the state of the containers when the user may not reach the daemon. */
    private const NO_DAEMON = 'docker cannot reach the Docker daemon';

    private const STUBS = [
        'docker' => <<<'BASH'
            #!/usr/bin/env bash
            # docker for DiagnoseReachesItsEndTest: a running stack of app (appcid) and
            # nginx (nginxcid). STUB_CONFIG_FAILS: compose loads no configuration and says
            # so, so every compose command but its version fails, as with real compose;
            # STUB_EXEC_REFUSED: app restarts, so docker exec is refused; STUB_INSPECT_FAILS:
            # the listed containers are gone; STUB_STACK_DIRS: the working directories of
            # the schedule-runner containers; STUB_NO_DAEMON: the user may not reach the
            # daemon, so only what compose does on its own works. Other calls print nothing.
            if [ -n "${STUB_CONFIG_FAILS:-}" ] && [ "$1" = compose ] && [ "${2:-}" != version ]; then
                echo "$STUB_CONFIG_FAILS" >&2
                exit 1
            fi
            if [ -n "${STUB_NO_DAEMON:-}" ]; then
                case "$*" in
                    "compose version" | "compose version --short" | "compose config --services") ;;
                    *)
                        echo "permission denied while trying to connect to the docker API at unix:///var/run/docker.sock" >&2
                        exit 1 ;;
                esac
            fi
            case "$*" in
                'ps -a --filter label=com.docker.compose.service=schedule-runner --format {{.Label "com.docker.compose.project.working_dir"}}')
                    printf '%s' "${STUB_STACK_DIRS:-}" ;;
                "version --format {{.Server.Version}}") echo "27.3.1" ;;
                "compose version") echo "Docker Compose version v2.29.7" ;;
                "compose version --short") echo "2.29.7" ;;
                "compose config --services") printf 'nginx\napp\n' ;;
                "compose ps -q") printf 'appcid\nnginxcid\n' ;;
                "compose ps -q app") echo appcid ;;
                "compose ps -q nginx") echo nginxcid ;;
                "compose port nginx 80") echo "0.0.0.0:8080" ;;
                "exec "*)
                    if [ -n "${STUB_EXEC_REFUSED:-}" ]; then
                        echo "Error response from daemon: Container $2 is restarting," \
                            "wait until the container is running" >&2
                        exit 1
                    fi
                    case "$*" in
                        *"new PDO("* | *"new Redis()"*) echo ok ;;
                    esac ;;
                "inspect "*)
                    if [ -n "${STUB_INSPECT_FAILS:-}" ]; then
                        echo "Error: No such object: $4" >&2
                        exit 1
                    fi
                    state=running health=healthy restarts=0
                    if [ -n "${STUB_EXEC_REFUSED:-}" ] && [ "$4" = appcid ]; then
                        state=restarting health=starting restarts=14
                    fi
                    case "$3" in
                        "{{.Name}}") echo "/ansilume-${4%cid}-1" ;;
                        "{{.State.Status}}") echo "$state" ;;
                        *"{{.State.Health.Status}}"*) echo "$health" ;;
                        "{{.RestartCount}}") echo "$restarts" ;;
                        "{{.Config.Image}}") echo "ghcr.io/ansilume/ansilume:2.13.0" ;;
                        "{{.Created}}") echo "2026-10-24T08:00:00.123456789Z" ;;
                    esac ;;
                "logs --tail 30 appcid")
                    if [ -n "${STUB_EXEC_REFUSED:-}" ]; then
                        echo "[entrypoint] ERROR: database credentials rejected (Access denied)"
                    fi ;;
            esac
            BASH,
        'free' => <<<'BASH'
            #!/usr/bin/env bash
            # free for DiagnoseReachesItsEndTest; STUB_FREE_FAILS: /proc/meminfo cannot be read.
            if [ -n "${STUB_FREE_FAILS:-}" ]; then
                echo "Error: /proc must be mounted" >&2
                exit 102
            fi
            echo "               total        used        free      shared  buff/cache   available"
            echo "Mem:           15843        4987        1234         456        9621       10531"
            echo "Swap:           2047           0        2047"
            BASH,
        'df' => <<<'BASH'
            #!/usr/bin/env bash
            # df for DiagnoseReachesItsEndTest; STUB_DF_FAILS: the directory cannot be read.
            if [ -n "${STUB_DF_FAILS:-}" ]; then
                echo "df: .: Permission denied" >&2
                exit 1
            fi
            echo "Filesystem      Size  Used Avail Use% Mounted on"
            echo "/dev/sda1        98G   40G   54G  43% /"
            BASH,
        'uptime' => "#!/usr/bin/env bash\necho 'up 3 days, 2 hours'",
        'curl' => "#!/usr/bin/env bash\n# curl for DiagnoseReachesItsEndTest: nothing answers.\nexit 7",
    ];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/diagnose-reaches-its-end-' . uniqid('', true);
        mkdir($this->directory . '/stubs', 0o755, true);
        // Readable by others: one test runs diagnose as nobody.
        chmod($this->directory, 0o755);
        chmod($this->directory . '/stubs', 0o755);
        copy(dirname(__DIR__, 3) . '/bin/diagnose', $this->directory . '/diagnose');
        chmod($this->directory . '/diagnose', 0o644);
        file_put_contents(
            $this->directory . '/docker-compose.yml',
            "services:\n  app:\n    image: ghcr.io/ansilume/ansilume:2.13.0\n"
        );
        foreach (self::STUBS as $command => $script) {
            file_put_contents($this->directory . '/stubs/' . $command, $script . "\n");
            chmod($this->directory . '/stubs/' . $command, 0o755);
        }
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    /**
     * free exits 102 when it cannot read /proc/meminfo, df exits 1 when it
     * cannot statfs the directory: the run ended after the Uptime line.
     */
    public function testRegressionFreeAndDfThatFailAreReportedAndTheRunGoesOn(): void
    {
        $this->writeEnv(self::env());

        [$status, $output] = $this->runDiagnose(['STUB_FREE_FAILS' => '1', 'STUB_DF_FAILS' => '1']);

        $this->assertStringContainsString(
            "  ⚠ Could not get memory usage (free -m)\n"
            . "  ⚠ Could not get disk usage of the current directory (df -h .)\n"
            . "\n═══ Docker ═══\n",
            $output
        );
        $this->assertStringNotContainsString('Memory: MB available / MB total', $output, 'no line without figures');
        $this->assertStringNotContainsString('Disk:    available ( used)', $output, 'no line without figures');
        $this->assertStringContainsString("  Services defined: app nginx \n", $output);
        $this->assertStringContainsString(
            "  ✓ app → db (mariadb): reachable\n  ✓ app → redis (cache:6380): reachable\n",
            $output,
            'the hosts of the .env'
        );
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * The listing was a pipeline whose status is grep's: 1 when the .env has
     * none of the keys, 2 when the user may not read it (the deploy role
     * writes it 0600 for its own user). The run ended after "Non-secret
     * configuration:".
     */
    public function testRegressionAnEnvWithoutTheListedKeysGoesOnToTheComposeFile(): void
    {
        $this->writeEnv("TZ=UTC\n" . self::env(self::LISTED_KEYS));

        [$status, $output] = $this->runDiagnose();

        $this->assertStringContainsString("  Non-secret configuration:\n\n═══ Docker Compose File ═══\n", $output);
        $this->assertStringContainsString(
            "  ✓ app → db (db): reachable\n  ✓ app → redis (redis:6379): reachable\n",
            $output,
            'no host from the .env: the defaults'
        );
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * See testRegressionAnEnvWithoutTheListedKeysGoesOnToTheComposeFile: the
     * run ended at the listing. Once it went on, diagnose took the .env for
     * one without any key. It now says that the user may not read it and how
     * to run diagnose so that it can. Compose cannot read the .env either, so
     * it loads no configuration (review finding: the test let compose answer
     * as if it could), and the port of the stack is unknown: review finding,
     * the HTTP checks probed 8080 all the same and reported a stack on
     * another port as unreachable.
     */
    public function testRegressionAnEnvTheUserMayNotReadIsReportedAsSuch(): void
    {
        $this->writeEnv(self::env());
        chmod($this->directory . '/.env', 0);

        [$status, $output] = $this->runDiagnose(
            ['STUB_CONFIG_FAILS' => 'open ' . $this->directory . '/.env: permission denied'],
            true
        );

        $this->assertStringContainsString(
            "═══ Configuration (.env) ═══\n\n"
            . '  ✗ .env is not readable for user ' . self::unprivilegedUser()
            . ", and docker compose needs it too: run diagnose with sudo\n"
            . "    curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/diagnose | sudo bash\n"
            . "\n═══ Docker Compose File ═══\n",
            $output
        );
        $this->assertStringNotContainsString('is missing from .env', $output);
        $this->assertStringNotContainsString('No CHANGE-ME defaults remaining', $output);
        $this->assertStringNotContainsString('.env file exists', $output);
        $this->assertSame(
            4,
            substr_count($output, '  ⚠ ' . sprintf(self::STATE_UNKNOWN, self::CANNOT_LOAD) . "\n"),
            $output
        );
        $this->assertPortUnknown(self::CANNOT_LOAD, $output);
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * Run outside the install directory, as with curl … | bash from a home
     * directory: no .env and no compose file, and compose loads none. The
     * containers of the stack run all the same; their state is unknown here,
     * and so is its port. diagnose names the directories Ansilume's
     * containers were started from, each once.
     */
    public function testRegressionARunOutsideTheInstallDirectoryNamesWhereTheStackRuns(): void
    {
        unlink($this->directory . '/docker-compose.yml');
        $here = (string)realpath($this->directory);
        $noComposeFile = sprintf(self::NO_COMPOSE_FILE, $here);

        [$status, $output] = $this->runDiagnose([
            'STUB_CONFIG_FAILS' => self::NO_CONFIGURATION,
            'STUB_STACK_DIRS' => "/opt/ansilume\n/srv/ansilume-staging\n/opt/ansilume\n",
        ]);

        $this->assertStringContainsString(
            "  ✗ .env file not found\n\n═══ Docker Compose File ═══\n\n"
            . "  ✗ No docker-compose file found in {$here}\n"
            . "  Run diagnose in the install directory, the one with .env and the compose file.\n"
            . "  Ansilume's containers were started from: /opt/ansilume /srv/ansilume-staging\n"
            . "\n═══ Container Status ═══\n",
            $output,
            'no hint to create an .env in a directory that is no install directory'
        );
        $this->assertSame(
            4,
            substr_count($output, '  ⚠ ' . sprintf(self::STATE_UNKNOWN, $noComposeFile) . "\n"),
            'Recent Logs, Migration Status, Runner and Application Integrity say so once each: ' . $output
        );
        $this->assertStringContainsString(
            "═══ Diagnosis Summary ═══\n\n  ✗ containers: state unknown — {$noComposeFile}\n",
            $output
        );
        $this->assertStringNotContainsString('not running', $output, 'no running container is reported as not running');
        $this->assertStringNotContainsString('NOT RUNNING', $output);
        $this->assertPortUnknown($noComposeFile, $output);
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * Review finding: run from a directory removed after the shell entered
     * it, pwd fails there. The message took its path from $(pwd) in a plain
     * assignment, so set -e ended the run after "No docker-compose file
     * found in", without the summary.
     */
    public function testRegressionARunInADirectoryRemovedMeanwhileReachesItsEnd(): void
    {
        mkdir($this->directory . '/removed');
        $noComposeFile = sprintf(self::NO_COMPOSE_FILE, 'a directory that no longer exists');

        [$status, $output] = $this->runDiagnose(
            ['STUB_CONFIG_FAILS' => 'getwd: no such file or directory', 'STUB_STACK_DIRS' => "/opt/ansilume\n"],
            false,
            $this->directory . '/removed'
        );

        $this->assertStringContainsString(
            "  ✗ No docker-compose file found in a directory that no longer exists\n"
            . "  Run diagnose in the install directory, the one with .env and the compose file.\n"
            . "  Ansilume's containers were started from: /opt/ansilume\n",
            $output
        );
        $this->assertSame(
            4,
            substr_count($output, '  ⚠ ' . sprintf(self::STATE_UNKNOWN, $noComposeFile) . "\n"),
            $output
        );
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * Review finding: in a subdirectory of the install directory compose
     * still finds the compose file of the parent directory, and the state of
     * the containers is known; diagnose reads .env from the directory it runs
     * in, though, so it says where to run it all the same.
     */
    public function testARunInASubdirectoryOfTheInstallDirectorySaysWhereToRunIt(): void
    {
        unlink($this->directory . '/docker-compose.yml');
        $here = (string)realpath($this->directory);

        [$status, $output] = $this->runDiagnose(['STUB_STACK_DIRS' => dirname($here) . "\n"]);

        $this->assertStringContainsString(
            "  ✗ No docker-compose file found in {$here}\n"
            . "  Run diagnose in the install directory, the one with .env and the compose file.\n"
            . "  Ansilume's containers were started from: " . dirname($here) . "\n"
            . "  Services defined: app nginx \n",
            $output
        );
        $this->assertStringContainsString("  ✓ nginx: healthy\n  ✓ app: healthy\n", $output, 'compose knows the state');
        $this->assertStringNotContainsString('state unknown', $output);
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * Review finding: a user who may not reach the Docker daemon (not in the
     * docker group) got a running stack reported as not running, app and
     * nginx as "NOT RUNNING (critical)", and its own published port as taken
     * by another process: compose reads its files without the daemon, so
     * diagnose went on as if it could ask for containers. The checks from
     * the host still run: .env names the port.
     */
    public function testRegressionARunWithoutAccessToTheDaemonDoesNotReportTheStackAsNotRunning(): void
    {
        $this->writeEnv(self::env());

        [$status, $output] = $this->runDiagnose(['STUB_NO_DAEMON' => '1']);

        $this->assertStringContainsString(
            '  ✗ ' . self::NO_DAEMON . ': permission denied while trying to connect to the docker API'
            . " at unix:///var/run/docker.sock\n"
            . '  Run diagnose with sudo or as a member of the docker group, and check that the daemon runs'
            . " (systemctl status docker).\n",
            $output
        );
        $this->assertStringContainsString("  ✓ Found: docker-compose.yml\n  Services defined: app nginx \n", $output);
        $unknown = sprintf('Container state unknown: %s (see Docker)', self::NO_DAEMON);
        $this->assertSame(4, substr_count($output, "  ⚠ {$unknown}\n"), $output);
        $this->assertStringContainsString(
            '  ⚠ Port 8080: cannot tell whether this stack listens on it — ' . self::NO_DAEMON . "\n",
            $output
        );
        $this->assertStringContainsString(
            "═══ Diagnosis Summary ═══\n\n  ✗ containers: state unknown — " . self::NO_DAEMON . "\n",
            $output
        );
        $this->assertStringContainsString(
            '  ✗ GET / → HTTP unreachable on http://127.0.0.1:8080',
            $output,
            'the checks from the host run'
        );
        $this->assertStringNotContainsString('not running', $output, 'no running container is reported as not running');
        $this->assertStringNotContainsString('NOT RUNNING', $output);
        $this->assertStringNotContainsString('also used by another process', $output);
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * Without containers of an Ansilume stack on the host, diagnose names no
     * directory.
     */
    public function testARunOutsideTheInstallDirectoryWithoutAStackNamesNoDirectory(): void
    {
        unlink($this->directory . '/docker-compose.yml');

        [$status, $output] = $this->runDiagnose(['STUB_CONFIG_FAILS' => self::NO_CONFIGURATION]);

        $this->assertStringContainsString(
            "  Run diagnose in the install directory, the one with .env and the compose file.\n"
            . "\n═══ Container Status ═══\n",
            $output
        );
        $this->assertStringNotContainsString('were started from', $output);
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * An install directory without .env: diagnose says how to create it.
     */
    public function testAnInstallDirectoryWithoutAnEnvSaysHowToCreateIt(): void
    {
        [$status, $output] = $this->runDiagnose();

        $this->assertStringContainsString(
            "  ✗ .env file not found\n  Create it with: cp .env.prod.example .env\n",
            $output
        );
        $this->assertStringContainsString("  ✓ Found: docker-compose.yml\n  Services defined: app nginx \n", $output);
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * @return array<string, array{0: string|null, 1: string, 2: string}>
     */
    public static function envWithoutHostsProvider(): array
    {
        $commentedOut = str_replace("\nREDIS_HOST=", "\n#REDIS_HOST=", self::env());

        return [
            'no DB_HOST' => [self::env(['DB_HOST']), 'db', 'cache:6380'],
            'REDIS_HOST commented out' => [$commentedOut, 'mariadb', 'redis:6380'],
            'no REDIS_PORT' => [self::env(['REDIS_PORT']), 'mariadb', 'cache:6379'],
            'no .env' => [null, 'db', 'redis:6379'],
        ];
    }

    /**
     * Without DB_HOST, REDIS_HOST or REDIS_PORT in the .env the app uses its
     * defaults (config/db.php, RedisSettings), and so meant diagnose. Its
     * lookups ended the run after the Network Connectivity header, before
     * they fell back to them.
     */
    #[DataProvider('envWithoutHostsProvider')]
    public function testRegressionTheDbAndRedisHostsFallBackToDefaults(?string $env, string $db, string $redis): void
    {
        if ($env !== null) {
            $this->writeEnv($env);
        }

        [$status, $output] = $this->runDiagnose();

        $this->assertStringContainsString(
            "  ✓ app → db ({$db}): reachable\n  ✓ app → redis ({$redis}): reachable\n",
            $output
        );
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * A YAML error, an .env line compose cannot parse, a missing compose
     * plugin or only docker-compose.prebuilt.yml: compose exits 1, and its
     * reason goes to /dev/null. The run ended after "Found:" without a word.
     * Review finding: once it went on, every compose command failed the same
     * way, and diagnose reported the running containers as not running, app
     * and nginx as "NOT RUNNING (critical)"; it now says their state is
     * unknown.
     */
    public function testRegressionAComposeConfigurationThatCannotBeLoadedIsReported(): void
    {
        $this->writeEnv(self::env());

        [$status, $output] = $this->runDiagnose(['STUB_CONFIG_FAILS' => self::UNPARSABLE_ENV]);

        $this->assertStringContainsString(
            "  ✓ Found: docker-compose.yml\n"
            . '  ✗ ' . self::CANNOT_LOAD . " — see why with: docker compose config --quiet\n",
            $output
        );
        $this->assertStringNotContainsString('Services defined', $output, 'no empty list of services');
        $this->assertStringNotContainsString('Run diagnose in the install directory', $output, 'it runs there');
        $this->assertStringContainsString("\n═══ Container Status ═══\n\n  ⚠ Could not list containers\n", $output);
        $this->assertSame(
            4,
            substr_count($output, '  ⚠ ' . sprintf(self::STATE_UNKNOWN, self::CANNOT_LOAD) . "\n"),
            'Recent Logs, Migration Status, Runner and Application Integrity say so once each: ' . $output
        );
        $this->assertStringContainsString(
            '  ⚠ Port 8080: cannot tell whether this stack listens on it — ' . self::CANNOT_LOAD . "\n",
            $output
        );
        $this->assertStringContainsString(
            "═══ Diagnosis Summary ═══\n\n  ✗ containers: state unknown — " . self::CANNOT_LOAD . "\n",
            $output
        );
        $this->assertStringNotContainsString('not running', $output, 'no running container is reported as not running');
        $this->assertStringNotContainsString('NOT RUNNING', $output);
        $this->assertStringContainsString("Issues detected. Share this output with developers:\n", $output);
        $this->assertStringContainsString(
            "  Memory: 10531MB available / 15843MB total\n  Disk:   54G available (43% used)\n",
            $output
        );
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * An app container that its entrypoint ends at once, here on rejected
     * database credentials, restarts with a growing delay and is listed while
     * it restarts, but docker exec is refused. The run ended after the
     * Network Connectivity header, before the log line that names the cause.
     */
    public function testRegressionAnAppContainerThatRefusesDockerExecIsReportedToTheEnd(): void
    {
        $this->writeEnv(self::env());

        [$status, $output] = $this->runDiagnose(['STUB_EXEC_REFUSED' => '1']);

        $this->assertStringContainsString(
            "  ⚠ app → db (mariadb): uncertain — no answer from the app container\n"
            . "  ⚠ app → redis (cache:6380): may not be reachable\n",
            $output
        );
        $this->assertStringContainsString(
            "  App health check:\n"
            . "    Error response from daemon: Container appcid is restarting, wait until the container is running\n",
            $output,
            "the daemon's reason"
        );
        $this->assertStringContainsString(
            "  ── app ──\n    [entrypoint] ERROR: database credentials rejected (Access denied)\n",
            $output,
            'the log line that names why the app does not start'
        );
        $this->assertStringContainsString("  ✗ Migration check failed\n", $output);
        $this->assertStringContainsString(
            sprintf(self::STATUS_LINE, '✗', 'ansilume-app-1', 'restarting', 'starting', '14'),
            $output
        );
        $this->assertStringContainsString("  ✓ nginx: healthy\n  ✗ app: stopped (state=restarting)\n", $output);
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * A concurrent docker compose up, --force-recreate or down removes a
     * listed container before its docker inspect, which then exits 1: in
     * Container Status, Image Versions, the Diagnosis Summary and the nginx
     * deadlock check.
     */
    public function testRegressionContainersThatVanishWhileDiagnoseRunsAreReportedToTheEnd(): void
    {
        $this->writeEnv(self::env());

        [$status, $output] = $this->runDiagnose(['STUB_INSPECT_FAILS' => '1']);

        $this->assertSame(
            2,
            substr_count($output, sprintf(self::STATUS_LINE, '✗', '', '', '', '')),
            'Container Status: a failed line for each container: ' . $output
        );
        $this->assertSame(
            2,
            substr_count($output, sprintf(self::IMAGE_LINE, '', '', '')),
            'Image Versions: a line for each container: ' . $output
        );
        $this->assertStringContainsString(
            "  ✗ nginx: stopped (state=)\n  ✗ app: stopped (state=)\n",
            $output,
            'Diagnosis Summary'
        );
        $this->assertStringNotContainsString('Likely cause: Nginx healthcheck deadlock', $output);
        $this->assertStringContainsString("Issues detected. Share this output with developers:\n", $output);
        $this->assertReachesItsEnd($status, $output);
    }

    /**
     * The run printed its last line and ended with it, without shell errors
     * and without a secret of the .env.
     */
    private function assertReachesItsEnd(int $status, string $output): void
    {
        $this->assertStringEndsWith("\nEnd of diagnostics.\n", $output, $output);
        $this->assertSame(0, $status, $output);
        $this->assertDoesNotMatchRegularExpression('/diagnose: line \d+: /', $output, 'no shell errors');
        $this->assertStringNotContainsString(self::SECRET, $output, 'diagnose never prints a secret');
    }

    /**
     * The .env of an install without the keys $without.
     *
     * @param list<string> $without
     */
    private static function env(array $without = []): string
    {
        $env = '';
        foreach (self::ENV as $key => $value) {
            if (!in_array($key, $without, true)) {
                $env .= $key . '=' . $value . "\n";
            }
        }

        return $env;
    }

    private function writeEnv(string $content): void
    {
        file_put_contents($this->directory . '/.env', $content);
    }

    /**
     * The user runDiagnose() runs diagnose as when $unprivileged: nobody
     * where the test runs as root, the test's own user otherwise.
     */
    private static function unprivilegedUser(): string
    {
        if (posix_geteuid() === 0) {
            return 'nobody';
        }
        $user = posix_getpwuid(posix_geteuid());

        return $user === false ? (string)posix_geteuid() : $user['name'];
    }

    /**
     * Where the port of the stack is unknown, diagnose probes no guessed
     * port, names none and reports no reachability issue: it says why.
     * Final-check finding: the port check named the guessed port 8080 right
     * after the HTTP section had called the port unknown.
     */
    private function assertPortUnknown(string $why, string $output): void
    {
        $this->assertStringContainsString(
            "═══ HTTP Reachability (host) ═══\n\n  ⚠ HTTP checks skipped: the port of the stack is unknown — {$why}\n",
            $output
        );
        $this->assertStringContainsString(
            "═══ Port Conflicts ═══\n\n  ⚠ Port conflicts not checked: the port of the stack is unknown — {$why}\n",
            $output
        );
        $this->assertStringNotContainsString('GET /', $output);
        $this->assertStringNotContainsString('host-side reachability issues', $output);
        $this->assertStringNotContainsString('Port 8080', $output);
    }

    /**
     * Runs the copy of bin/diagnose from the test's directory with the
     * stand-ins first on PATH and $env. $unprivileged runs it as nobody
     * where the test runs as root, which reads every file whatever its mode.
     * With $removed it runs in that empty directory, removed after the shell
     * entered it.
     *
     * @param array<string, string> $env
     * @return array{0: int, 1: string} exit code, output without colours
     */
    private function runDiagnose(array $env = [], bool $unprivileged = false, string $removed = ''): array
    {
        $command = ['bash', $this->directory . '/diagnose'];
        if ($removed !== '') {
            $command = ['bash', '-c', 'cd "$1" && rmdir "$1" && exec bash "$2"', 'bash', $removed, $command[1]];
        }
        if ($unprivileged && posix_geteuid() === 0) {
            $command = array_merge(['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups'], $command);
        }
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory,
            ['PATH' => $this->directory . '/stubs:' . (string)getenv('PATH'), 'HOME' => $this->directory] + $env
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), (string)preg_replace('/\e\[[0-9;]*m/', '', $output)];
    }
}
