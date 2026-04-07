# Troubleshooting

## Diagnostics script

Before digging into specific issues, run the diagnostics script. It collects system state, container health, image versions, connectivity, migration status, and recent logs — all in one go, with secrets automatically redacted.

```bash
./bin/diagnose
# or remotely, without cloning the repo:
curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/diagnose | bash
```

To save the output for sharing:

```bash
./bin/diagnose > ansilume-diag.txt 2>&1
```

The output is safe to share — no credentials or tokens are included.

**Stuck and can't figure it out?** Open an issue at [github.com/ansilume/ansilume/issues](https://github.com/ansilume/ansilume/issues) and paste the diagnostics output. It gives us everything we need to help.

---

## Container startup race conditions

When multiple containers (app, runner-1, runner-2, ...) start simultaneously on a shared source volume, concurrent writes can cause problems. Ansilume guards against this with file locks (`flock`) in the entrypoint.

### Composer install

**Symptom:** `vendor/autoload.php` missing or corrupt, class-not-found errors after a fresh `docker compose up`.

**Cause:** Multiple containers ran `composer install` at the same time on the same `vendor/` directory.

**Solution:** The entrypoint uses `flock /var/www/.composer.install.lock` to serialize composer installs. Only one container writes at a time; the others wait and then skip through quickly since dependencies are already installed.

If you still see issues:

```bash
# Force a clean reinstall
docker compose exec app rm -rf vendor/
docker compose restart app
```

### Database migrations

**Symptom:** Migration errors like "table already exists" or deadlocks during initial startup.

**Cause:** Multiple app containers ran `php yii migrate` concurrently.

**Solution:** The entrypoint uses `flock /var/www/.migrate.lock` to ensure only one container runs migrations at a time. Migrations only run in containers started with `php-fpm` (the app container), not in runners.

If migrations are stuck:

```bash
# Check migration status
docker compose exec app php yii migrate/history

# Re-run manually
docker compose exec app php yii migrate --interactive=0
```

## Runners show "unknown" name/group

**Symptom:** Runner logs show `Runner 'unknown' started. Group: 'unknown'.`

**Cause:** The runner failed to register or authenticate with the app. Common reasons:
- `RUNNER_BOOTSTRAP_SECRET` mismatch between app and runner
- App container not ready when runners start (DB not migrated yet)
- Stale cached auth token from a previous run

**Solution:**

```bash
# Check runner logs for auth errors
docker compose logs runner-1 --tail 50

# Restart runners (they auto-recover and re-register)
docker compose restart runner-1 runner-2
```

## App returns 502 Bad Gateway

**Symptom:** Nginx returns 502 when accessing the UI.

**Cause:** PHP-FPM is not running or not ready yet (still running composer install or migrations).

**Solution:** Wait a moment for the entrypoint to finish, then check:

```bash
# Check if php-fpm is running
docker compose exec app ps aux | grep php-fpm

# Check entrypoint progress
docker compose logs app --tail 20
```

## Health endpoint reports unhealthy

**Symptom:** `/health` returns `"healthy": false` even though the app seems to work.

**Cause:** The health check verifies both the database connection and that at least one worker process has reported in recently. If no workers are running or have not yet checked in, the endpoint reports unhealthy.

**Solution:**

```bash
# Check worker status
docker compose ps

# Ensure runners are up
docker compose up -d runner-1 runner-2
```
