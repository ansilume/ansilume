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
docker compose exec app php yii ldap/test-connection

# Look up a single user (no password needed). Shows DN, UID, email,
# group membership, and the resulting Ansilume role assignment.
docker compose exec app php yii ldap/test-user jdoe

# Reconcile every LDAP-backed user with the directory. Updates attributes
# and roles, disables accounts the directory no longer recognises, and
# re-enables ones it does.
docker compose exec app php yii ldap/sync

# Same as above but reports without making changes.
docker compose exec app php yii ldap/sync --dry-run
```

Run `ldap/sync` from cron (e.g. nightly) so accounts that have been removed
or disabled in the directory lose their Ansilume access in a timely fashion.

Suggested crontab entry:

```
0 3 * * * cd /opt/ansilume && docker compose exec -T app php yii ldap/sync >> /var/log/ansilume/ldap-sync.log 2>&1
```

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

| Directory event              | Ansilume reaction                                                       |
|------------------------------|-------------------------------------------------------------------------|
| First successful bind        | Creates local row (if `LDAP_AUTO_PROVISION=true`); maps roles; audited. |
| Subsequent successful bind   | Updates DN/email/display name; reconciles roles; audited.               |
| Group membership changes     | Roles diffed and added/removed at next login or `ldap/sync` run.        |
| User removed from directory  | `ldap/sync` marks the account inactive, rotates `auth_key` (kills      |
|                              | active sessions), deletes API tokens, revokes RBAC. Audited.           |
| User restored in directory   | Next successful bind re-activates the account. Audited.                 |

All lifecycle events emit dedicated audit log entries:

- `ldap.user.provisioned` — new account created
- `ldap.user.synced`      — attributes or role assignments changed
- `ldap.user.disabled`    — disabled by `ldap/sync`
- `ldap.user.reenabled`   — restored after re-appearing in the directory
- `ldap.user.roles_changed` — RBAC diff applied
- `ldap.login.failed`     — directory rejected the bind (separate from local failures)
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

---

## Operational checklist

- [ ] `LDAP_ENABLED=true` and the rest of the block filled in.
- [ ] `php yii ldap/test-connection` returns `service_bind: OK`.
- [ ] `php yii ldap/test-user <known-user>` returns the expected groups + roles.
- [ ] A test login through the web UI succeeds.
- [ ] `ldap/sync --dry-run` reports the user list you expect.
- [ ] `ldap/sync` is scheduled in cron for the lifecycle reconciliation.
- [ ] Local admin account is preserved as a break-glass — never delete it.
