# LDAP / Active Directory Integration

Ansilume can authenticate users against an external LDAP or Active Directory
server in addition to its built-in local accounts. This is an **optional addon**
— with `LDAP_ENABLED=false` (the default) Ansilume behaves exactly as before
and only the local password path is active.

When enabled, Ansilume:

- Looks up users in the directory by `sAMAccountName` (AD) or `uid` (OpenLDAP).
- Verifies passwords by attempting an LDAP bind as the user.
- Auto-provisions a local `user` row on first successful bind (configurable).
- Maps directory group membership to Ansilume RBAC roles.
- Re-syncs attributes and group membership on every login, plus an optional
  cron-driven full sync that disables users removed from the directory.

Local accounts and LDAP accounts coexist. The `auth_source` column on each
user record decides which path is consulted, and it is **immutable once set**.

---

## Container images

LDAP needs PHP's `ldap` extension in the container that signs users in and
runs `php yii ldap/*`: the app container, whose image the queue-worker and
the schedule-runner share.

- The prebuilt app image `ghcr.io/ansilume/ansilume` has it from 2.11.0
  on. Up to 2.10.1 it had none: with `LDAP_ENABLED=true` and a host and
  base DN set, the sign-in of LDAP users showed the error page, and
  `ldap/test-connection`, `ldap/test-user` and `ldap/sync` stopped with
  `Error: PHP ldap extension is not loaded.` (see
  [troubleshooting.md](troubleshooting.md#ldap-fails-php-ldap-extension-is-not-loaded)).
  This concerns `docker-compose.prebuilt.yml`, both modes of the quickstart,
  which both run `docker-compose.prebuilt.yml`, and the Ansible deploy role
  with `ansilume_use_prebuilt_images: true`. Use 2.11.0 or later with LDAP.
- A git checkout (`docker-compose.yml`, built from `docker/php/Dockerfile`)
  and the deploy role's default build mode have had it since 2.1.11, the
  release that brought LDAP.
- The runner image `ghcr.io/ansilume/ansilume-runner` has no `ldap`
  extension and needs none: runners never contact the directory.

Check a running installation with
`docker compose exec app php -m | grep -x ldap`, which prints `ldap`.

---

## How it works

```
┌──────────┐   1. submit credentials   ┌──────────────┐
│  User    │ ────────────────────────► │   Ansilume   │
└──────────┘                           │  LoginForm   │
                                       └──────┬───────┘
                                              │ 2. local row?
                                ┌─────────────┴────────────┐
                                ▼                          ▼
                       auth_source=local          auth_source=ldap
                                │                          │
                                │                          │ 3. LdapService
                                ▼                          ▼
                         bcrypt verify              ┌──────────────┐
                                                    │  Directory   │
                                                    │ (AD/OpenLDAP)│
                                                    └──────┬───────┘
                                                           │ 4. service bind
                                                           │ 5. find user
                                                           │ 6. bind as user
                                                           │ 7. read groups
                                                           ▼
                                                    LdapUserProvisioner
                                                           │
                                                           │ 8. upsert user
                                                           │ 9. reconcile RBAC
                                                           ▼
                                                       Local DB
```

The directory password is **never stored** in Ansilume. The `password_hash`
column for an LDAP user contains the sentinel value `!ldap` against which
`password_verify()` always returns false, so the local bcrypt path can never
authenticate an LDAP account, regardless of how the row was tampered with.

---

## Configuration

All LDAP settings are environment variables. See the `# LDAP / Active
Directory` block in `.env.example` and `.env.prod.example` for the full list.

### Minimum viable config (Active Directory)

```env
LDAP_ENABLED=true
LDAP_HOST=dc01.corp.example.com
LDAP_PORT=636
LDAP_ENCRYPTION=ldaps
LDAP_BASE_DN=DC=corp,DC=example,DC=com
LDAP_BIND_DN=CN=svc-ansilume,OU=Service Accounts,DC=corp,DC=example,DC=com
LDAP_BIND_PASSWORD=...
LDAP_USER_FILTER=(&(objectClass=user)(sAMAccountName=%s))
LDAP_ATTR_USERNAME=sAMAccountName
LDAP_ATTR_EMAIL=mail
LDAP_ATTR_DISPLAY_NAME=displayName
LDAP_ATTR_UID=objectGUID
LDAP_GROUP_FILTER=(&(objectClass=group)(member=%s))
LDAP_GROUP_NAME_ATTR=cn
LDAP_AUTO_PROVISION=true
LDAP_DEFAULT_ROLE=viewer
LDAP_ROLE_MAPPING={"AnsilumeAdmins":"admin","AnsilumeOps":"operator"}
```

### Minimum viable config (OpenLDAP)

```env
LDAP_ENABLED=true
LDAP_HOST=ldap.corp.example.com
LDAP_PORT=389
LDAP_ENCRYPTION=starttls
LDAP_BASE_DN=ou=people,dc=corp,dc=example,dc=com
LDAP_BIND_DN=cn=svc-ansilume,ou=services,dc=corp,dc=example,dc=com
LDAP_BIND_PASSWORD=...
LDAP_USER_FILTER=(&(objectClass=inetOrgPerson)(uid=%s))
LDAP_ATTR_USERNAME=uid
LDAP_ATTR_EMAIL=mail
LDAP_ATTR_DISPLAY_NAME=cn
LDAP_ATTR_UID=entryUUID
LDAP_GROUP_FILTER=(&(objectClass=groupOfNames)(member=%s))
LDAP_GROUP_NAME_ATTR=cn
```

### Notes

- **`LDAP_ENCRYPTION`** must be one of `none`, `starttls`, or `ldaps`. Plain
  `none` is intended for local test directories only — never use it in
  production. `starttls` upgrades a plaintext connection on port 389;
  `ldaps` opens an SSL-wrapped connection on port 636.
- **`%s`** in the filter strings is replaced with the submitted username,
  always escaped via `ldap_escape(LDAP_ESCAPE_FILTER)` to prevent filter
  injection.
- **`LDAP_ATTR_UID`** should resolve to a stable, never-recycled
  identifier — Active Directory's `objectGUID` or OpenLDAP's `entryUUID`.
  This is what Ansilume uses to recognise the same user across renames.
- **`LDAP_ROLE_MAPPING`** is a JSON object; keys are directory group names
  (case-insensitive), values are Ansilume RBAC role names. Invalid JSON
  silently falls back to an empty map — verify with `php yii ldap/test-user
  <username>`.
- **`LDAP_AUTO_PROVISION=false`** disables on-the-fly creation. In that mode
  an admin must first create a user with `auth_source=ldap` before that
  account can log in. Useful when you want strict pre-registration.

---

## Console commands

```bash
# Verify the directory is reachable and the service-account bind works.
docker compose exec -u www-data app php yii ldap/test-connection

# Look up a single user (no password needed). Shows DN, UID, email,
# group membership, and the resulting Ansilume role assignment.
docker compose exec -u www-data app php yii ldap/test-user jdoe

# Reconcile every LDAP-backed user with the directory. Updates attributes
# and roles, disables accounts whose user search finds no entry, and
# re-enables the ones it disabled once it finds them again; an account
# deactivated in Ansilume stays inactive. A lookup that fails changes
# nothing.
docker compose exec -u www-data app php yii ldap/sync

# Same as above but reports without making changes.
docker compose exec -u www-data app php yii ldap/sync --dry-run
```

Run `ldap/sync` from cron (e.g. nightly) so accounts that have been removed
or disabled in the directory lose their Ansilume access in a timely fashion.

Only a user search that runs and finds no entry disables an account. A
lookup that fails says nothing about the user, so `ldap/sync` leaves that
account, its roles and its API tokens as they are, prints
`- <username>: lookup failed → left unchanged (<reason>)` and counts it
under `errors`: a user or group search that fails or does not run to the
end (a referral to another server, a size limit), and a user search that
matches more than one entry. A failed service bind stops the run, as every
further lookup would fail too:
`[ldap] Service bind failed (<reason>): sync stopped, <n> user(s) left unchanged.`
LDAP that is enabled without a host or base DN stops it before the first
user:
`[ldap] LDAP is enabled but not fully configured (host or base DN missing): sync stopped, no user changed.`
The command exits with `0` when it counted no error, with `1` when it
counted one (a failed lookup or service bind, or an account it could not
update), and with `78` when LDAP is enabled but not fully configured; with
`LDAP_ENABLED=false` it prints `[ldap] LDAP is disabled — nothing to sync.`
and exits with `0`. `--dry-run` takes the same paths and changes nothing.
Make a non-zero exit alert someone: cron itself only mails what a job
prints, and the entry below writes that to a log (see
[troubleshooting.md](troubleshooting.md#ldapsync-fails-a-lookup-or-the-service-bind-failed)).

Suggested crontab entry:

```
0 3 * * * cd /opt/ansilume && docker compose exec -T -u www-data app php yii ldap/sync >> /var/log/ansilume/ldap-sync.log 2>&1
```

The entry runs the sync as `www-data`, the user of the web server, as
every console command of this guide does. Run as root, a sync that finds
no `runtime/logs/app.log` creates it as root's, so the errors of the web
UI are lost; one that rotates a full log leaves copies that root owns (see
[troubleshooting.md](troubleshooting.md#app-or-queue-worker-container-is-unhealthy-the-log-file-cannot-be-written)).

---

## REST API

| Method | Path | Description |
|--------|------|-------------|
| `GET`  | `/api/v1/admin/ldap/test` | Connection diagnostic (admin only). |
| `POST` | `/api/v1/admin/ldap/test` | Diagnostic + optional user lookup or full bind verification (admin only). |

Example: verify that user `jdoe` resolves and which roles they would be granted.

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"username":"jdoe"}' \
     https://ansilume.example.com/api/v1/admin/ldap/test
```

The submitted password is **never** logged, persisted, or returned.

---

## Lifecycle: enable, disable, re-enable

| Directory event | Ansilume reaction |
|---|---|
| First successful bind | Creates local row (if `LDAP_AUTO_PROVISION=true`); maps roles; audited. |
| Subsequent successful bind | Updates DN/email/display name; reconciles roles; audited. |
| Group membership changes | Roles diffed and added/removed at next login or `ldap/sync` run. |
| User removed from directory | `ldap/sync` (its user search runs and finds no entry) marks the account inactive, rotates `auth_key` (kills active sessions), deletes API tokens, revokes RBAC. Audited. |
| Directory cannot be read | `ldap/sync` changes nothing for a user whose lookup fails (service bind, user or group search) and counts an error; a failed service bind stops the run. Exit code `1`. |
| User restored in directory | Next successful bind, or the next `ldap/sync` that finds the user, re-activates an account that `ldap/sync` deactivated (`user.ldap_sync_disabled`), with the roles their groups map to. Audited. |
| Account deactivated in Ansilume (**Deactivate**, the Edit User form, `PUT /api/v1/users/{id}`) | Stays inactive whatever the directory says: a successful bind is refused with the generic error and audited as `ldap.login.failed` (`Account deactivated in Ansilume.`); `ldap/sync` updates its DN, email and roles and prints `- <username>: deactivated in Ansilume, not by ldap/sync → left inactive`. The user page says so under the status. |

All lifecycle events emit dedicated audit log entries:

- `ldap.user.provisioned` — new account created
- `ldap.user.synced`      — attributes or role assignments changed
- `ldap.user.disabled`    — disabled by `ldap/sync`
- `ldap.user.reenabled`   — an account `ldap/sync` disabled, activated again by a bind or `ldap/sync` once the directory has the user
- `ldap.user.roles_changed` — RBAC diff applied
- `ldap.login.failed`     — directory rejected the bind, or the account was deactivated in Ansilume (separate from local failures)
- `ldap.test.performed`   — `/admin/ldap/test` was invoked

---

## Security model

- The bind password is read from the environment and held only in memory.
- The user's submitted password is forwarded to the directory once per bind
  attempt and never echoed back, logged, or persisted.
- Empty DNs and empty passwords are rejected before they reach the wire,
  defending against RFC 4513 unauthenticated-bind exposure where a server
  treats `(dn, "")` as anonymous and replies success.
- All filter parameters are escaped with `ldap_escape(LDAP_ESCAPE_FILTER)`.
- Failed logins always show the same "Incorrect username or password"
  error so the form does not leak whether a username exists locally,
  exists in the directory, or was rejected.
- Local users with `auth_source=local` cannot be flipped to `ldap` (and
  vice versa) via either the web UI or the API — the field is locked
  after creation. Letting it flip would either orphan the bcrypt hash
  or expose a directory-managed account to local password login.
- An account an admin deactivates in Ansilume stays inactive whatever the
  directory says: to lock out an LDAP user who is still in the directory,
  deactivate the account in Ansilume. A sign-in or `ldap/sync` activates
  again only an account that `ldap/sync` deactivated because the directory
  no longer had the user. Up to 2.10.1 `ldap/sync` activated every inactive
  LDAP account the directory had, and a sign-in the user's own.

---

## Operational checklist

- [ ] `docker compose exec app php -m | grep -x ldap` prints `ldap` (prebuilt images: 2.11.0 or later).
- [ ] `LDAP_ENABLED=true` and the rest of the block filled in.
- [ ] `php yii ldap/test-connection` exits with `0`, and its `Service bind:` line reads `OK`.
- [ ] `php yii ldap/test-user <known-user>` returns the expected groups + roles.
- [ ] A test login through the web UI succeeds.
- [ ] `ldap/sync --dry-run` reports the user list you expect.
- [ ] `ldap/sync` is scheduled in cron for the lifecycle reconciliation, and a non-zero exit code alerts someone.
- [ ] LDAP users who must not sign in, also while the directory still has them, are deactivated in Ansilume: the directory does not activate such an account again.
- [ ] Local admin account is preserved as a break-glass — never delete it.
