# Continuous integration

Two systems check every push to `main`. They have different jobs, and keeping
them apart is what keeps both of them reliable.

| System | What it runs | Role |
|--------|--------------|------|
| GitHub Actions, `.github/workflows/ci.yaml` | The five suites `bin/tests-lint.sh`, `bin/tests-style.sh`, `bin/tests-phpstan.sh`, `bin/tests-phpunit.sh` and `bin/tests-e2e.sh`, against the docker compose stack with MariaDB, Redis and runners | **The test gate.** Every suite must be green before a release. |
| GitHub Actions, `.github/workflows/release.yml` | Image builds for `v*` tags, multi-arch manifests, the arm64 smoke test of the prebuilt compose file | Release images |
| Scrutinizer | Static analysis of PHP and JavaScript (`php-scrutinizer-run`, `js-scrutinizer-run`, plus PHPMD and PHPCPD from `tools`) and PSR-12 with the project's own phpcs | Code quality signal and the README badges |

**Scrutinizer runs no tests.** It starts no database, no Redis and no other
service, and it runs neither PHPUnit nor migrations. Every test already runs in
GitHub Actions against the real stack, so nothing is lost. The
[history](#why-scrutinizer-runs-no-tests) below explains why this rule exists.

## Rules for `.scrutinizer.yml`

- **This file is the complete config.** The repository settings on
  scrutinizer-ci.com stay empty (see [below](#scrutinizer-web-ui-settings)).
- No `services`, no PHPUnit, no `php yii …`, no `.env`, no `before` or `after`
  steps, and no PECL extensions. The build needs PHP and `composer install`,
  nothing else.
- Exactly two nodes:
  - `analysis` runs `php-scrutinizer-run` and `js-scrutinizer-run`, and its
    `project_setup` does nothing (`'true'`). Defining this node keeps
    Scrutinizer's default `phpcs-run` from running. That command installs phpcs
    3.7.1, which Composer refuses because of a security advisory, so every
    build failed with exit code 127.
  - `tests` runs PSR-12 with `vendor/bin/phpcs`, with the same scope as
    `bin/tests-style.sh`. The name is deliberate: Scrutinizer's default config
    enables a `tests` node with auto-detected commands. That node would run
    PHPUnit without a database, and only a node of the same name replaces it.
- Quote every step that contains `: ` (a colon followed by a space).
  Unquoted, YAML reads it as a mapping, and Scrutinizer aborts the whole
  inspection with `Config Error: Unrecognized option`.

`tests/unit/config/ScrutinizerConfigTest.php` enforces these rules, so PHPUnit
fails in GitHub Actions before a broken config reaches Scrutinizer. If you
really need to change a rule, change the test, this document and the config in
the same commit, and write down why.

## Code that Scrutinizer's analyzer cannot read

Scrutinizer's PHP analyzer is older than the PHP versions it checks.

- **No numeric literal separators.** Write `1048576`, not `1_048_576`. The
  analyzer reports `A parse error occurred: Syntax error, unexpected T_STRING`
  and skips the whole file, so nothing in that file gets analysed.
  `ScrutinizerConfigTest` scans every file that Scrutinizer analyses, which is
  every PHP file outside `filter.excluded_paths`. Tests are excluded, so
  separators are fine there.
- **PHPStan-only doc types are false positives.** Types such as `list<…>`,
  `array-key` and `class-string<…>` produce "doc comment could not be parsed"
  and "type was not found" issues. Issues do not fail the build, because no
  build failure conditions are configured.

## Checking a commit after a push

The build log pages on scrutinizer-ci.com need a login. The API below is
public for this repository and shows the real result. The GitHub commit status
can stay "pending" after the inspection has finished, as it did for v2.7.0.

```bash
SHA=$(git rev-parse HEAD)

# GitHub Actions: every workflow run for the commit
gh api "repos/ansilume/ansilume/actions/runs?head_sha=$SHA" \
  -q '.workflow_runs[] | "\(.name): \(.status) \(.conclusion)"'

# Scrutinizer: the latest inspections (build, tests and analysis status)
curl -s 'https://scrutinizer-ci.com/api/repositories/g/ansilume/ansilume/inspections?per_page=10' \
  | jq -r '._embedded.inspections[] | "\(.created_at[0:16])  \(.metadata.source_reference[0:7])  build=\(.build.status)  tests=\(.build.details.tests.status // "-")  analysis=\(.build.details.analysis.status // "-")"'
```

## When Scrutinizer is red

| Symptom | Cause | Fix |
|---------|-------|-----|
| `tests=failed` | PSR-12 violation | Run `bin/tests-style.sh` and fix what it reports |
| `analysis=errored`, `Config Error` | Invalid `.scrutinizer.yml` (for example an unquoted `: `) | `ScrutinizerConfigTest` should have caught it; extend the test |
| `analysis=errored` in `phpcs-run` | The `analysis` node is missing or was changed | Restore the `analysis` node |
| Fails without any code reason | An outage at Scrutinizer | Retry the inspection in Scrutinizer's UI. Do not add services, tests or wait loops to work around it |

Treat a red Scrutinizer build like a red GitHub Actions run: fix it before
starting new work.

## Scrutinizer web UI settings

Scrutinizer merges two configs before every inspection. It reads the
repository settings on scrutinizer-ci.com first and `.scrutinizer.yml` second.
For a conflicting value, the file wins. A list in the file replaces the whole
list from the website; the two are not merged. Because of that, defining the
`analysis` node in the file to stop `phpcs-run` also dropped the website's
`js-scrutinizer-run`. The JavaScript analysis then stopped on 2026-10-06, and
nobody noticed.

Since then, everything lives in `.scrutinizer.yml`. That includes the PHP and
JavaScript checks, the coding style of suggested fixes and the excluded paths.
The repository's configuration on scrutinizer-ci.com contains only this
comment:

```yaml
# Everything lives in .scrutinizer.yml in the repository, see docs/ci.md.
```

If the form does not accept a config without settings, use this one instead.
The file sets the same list, and its list replaces this one:

```yaml
filter:
    excluded_paths:
        - 'tests/*'
```

Do not keep the old default config there (`tests: true`, `phpcs-run`). It is
harmless only as long as the file defines both nodes. If a later change ever
drops one of them, these defaults come back: PHPUnit without a database, or a
phpcs that Composer refuses.

## Why Scrutinizer runs no tests

- **March 2026:** Scrutinizer ran the PHPUnit Unit suite against its own
  MySQL 5.7 and Redis services, so it could show code coverage. Until May, all
  builds but one passed.
- **May to August 2026:** the tests node failed in 8 of 15 inspections. The
  logs of those builds are no longer available.
- **2026-10-06 and 07:** 10 of 12 inspections did not pass. For every one of
  these commits with a finished GitHub Actions run, the PHPUnit job passed.
  Some failures came from `phpcs-run` and from a YAML error in the config (see
  the rules above). The others came from the database service: it accepted
  TCP connections but never sent the MySQL greeting. mysqlnd waits up to a day
  for that greeting, so builds hung for hours.
- **2026-10-07:** we switched to MariaDB 10.11 and bounded the wait. The next
  two builds happened to get working services and passed, so it looked fixed.
- **v2.8.0 (2026-10-07):** the build failed again.
  - MariaDB and Redis both accepted connections without answering, and
    `php yii migrate` died with `Failed to read from socket`.
  - PHPUnit then hung in a health check test until Scrutinizer killed it
    after 180 seconds without output. That kill also cut off PHPUnit's failure
    summary, which is why every one of these builds looked mysterious.
  - Six new tests failed as well, and they were exactly the ones that call
    `git init -b`. That option needs git 2.28 or newer. The other tests in the
    same classes passed, so Scrutinizer's build image evidently ships an older
    git. GitHub Actions passed the same commit.
- **2026-10-09:** Scrutinizer stopped running tests and starting services.
  Scrutinizer's own services and toolchain are outside our control, and the
  tests gain nothing from running there twice. Scrutinizer's coverage figure
  came from the Unit suite only (about 28 %) and is gone now. The full coverage
  report is in the PHPUnit job of GitHub Actions.
