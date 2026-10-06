# Credentials

Ansilume manages four kinds of credentials — SSH keys, username/password
pairs, Ansible Vault passwords, and generic tokens. They are stored
AES-256-CBC encrypted in the database, decrypted only in-memory by the
runner process for the duration of a single job, and redacted from
every log path (`CredentialService::redact()`).

A job template may attach **multiple credentials** at once. The primary
credential claims the Ansible connection slots (`--user`,
`--private-key`, `--vault-password-file`); additional credentials
contribute secret environment variables that playbooks read at runtime.
This lets a single template combine an SSH key with a 1Password service-
account token with an API key, without juggling multiple templates.

## Credential types

| Type | Purpose | Injected as |
|---|---|---|
| **SSH Key** | Connect to target hosts over SSH. | `--user <username>` + `--private-key <tmpfile>` on the `ansible-playbook` command. |
| **Username / Password** | SSH password auth (where keys aren't an option). | `--user <username>` + the password in a `0600` temp file named by `ANSIBLE_CONNECTION_PASSWORD_FILE`. |
| **Vault Secret** | Decrypt Ansible-Vault-encrypted vars files. | `--vault-password-file <tmpfile>`. |
| **Token** | Arbitrary secret surfaced to the playbook as an env var. Use this for API keys, service-account tokens, webhook secrets — anything that doesn't map onto the three above. | Named environment variable (see below). |

The `username` field is only read for SSH Key and Username/Password
credentials. Vault and Token credentials ignore it — the UI hides the
field when you pick one of those types.

Password logins: ansible-core reads the SSH password from the file in
`ANSIBLE_CONNECTION_PASSWORD_FILE`. Before 2.6.0 the runner only set
`ANSIBLE_SSH_PASS`, which ansible-core never reads, so password logins
failed. The runner still sets `ANSIBLE_SSH_PASS` for inventories that
read it with `lookup('env', 'ANSIBLE_SSH_PASS')`. ansible-core 2.19 and
later, as shipped in the Ansilume images, hand the password to `ssh`
through `SSH_ASKPASS`. Older ansible-core versions, and
`ANSIBLE_SSH_PASSWORD_MECHANISM=sshpass`, need the `sshpass` program on
the runner.

## Secrets are required

Every credential needs the secret of its type: the private key, the
password, the vault password or the token. The form and the API reject
a credential without it, for example with `Token is required for Token
credentials.`

- **Editing:** leave the secret blank to keep the stored one. A secret
  you enter replaces it.
- **Changing the type:** enter the secret of the new type. Otherwise
  the old type's secret would stay behind, so the change is rejected.

The credential page shows the state of the stored secret:

| Status | Meaning | What to do |
|---|---|---|
| Stored | The secret of the type is there. | Nothing. |
| Missing | No secret is stored, as in credentials saved before this check existed. Jobs get nothing from it. | Edit the credential and enter the secret. |
| Cannot be decrypted | The secret was encrypted with a different `APP_SECRET_KEY`. Jobs that use the credential fail before they start. | Restore the old key, or enter the secret again. |

The API reports the same as `secret_status` (`ok`, `incomplete`,
`undecryptable`) on `GET /api/v1/credentials/{id}`.

## Where a credential is used

The credential page has a **Used by** card. It lists the job templates
that use the credential (as primary or additional credential), the
projects that use it to clone their repository, and how many jobs that
have not started yet need it. Templates and projects of teams you don't
belong to are only counted, never named. `GET /api/v1/credentials/{id}`
returns the same as `used_by`.

## Deleting a credential

A credential that nothing uses is deleted after a plain confirmation.

A credential in use is not deleted right away. The page offers
**Delete anyway**, and the confirmation names how many templates,
projects and waiting jobs use it. Deleting it detaches it from all of
them, and waiting jobs that need it fail before they start. The audit
log records the forced delete with those counts.

Over the API, `DELETE /api/v1/credentials/{id}` answers `409` with
`error.used_by` while the credential is in use. Repeat the request with
`?force=1` to delete it anyway. Before 2.6.0 the API deleted it without
asking.

## Token credentials and custom env var names

Every token credential has an optional **Env var name** field. At
runtime Ansilume exports the decrypted token under that name. If you
leave the field empty it falls back to the historical default
`ANSILUME_CREDENTIAL_TOKEN`, which is what a job with a single token
gets for free.

**You must set a distinct env var name when attaching more than one
token to the same template** — two credentials can't both claim
`ANSILUME_CREDENTIAL_TOKEN`. Ansilume will log a warning and keep the
first one if you forget.

Valid names: upper-case letters, digits, and underscores, starting
with a letter or underscore. The UI validates the pattern before save.

## Attaching credentials to a job template

Open a template's edit form. The first select (`Credential`) is the
**primary** credential — the one that takes precedence on single-slot
Ansible args. Below it, an `Additional credentials` checkbox list lets
you pick any number of extras; they are persisted in the
`job_template_credential` pivot table with deterministic sort order.

The template page, the launch page and the job page list the
credentials in this order, with their role. So do the API responses of
`GET /api/v1/job-templates/{id}` (`credentials`, `credential_ids`) and
`GET /api/v1/jobs/{id}` (`credentials`).

What happens with them:

1. At launch, the job records the template's credentials in precedence
   order: the primary first, then the additional ones as listed. It also
   records their names, so the job page still names a credential that
   was deleted later.
2. When a runner claims the job, the server decrypts them. If one was
   deleted since the launch or cannot be decrypted, the job fails before
   it starts, with the reason in the job log. Before 2.6.0 such a
   credential was skipped and the job ran without it.
3. The runner feeds the list through `CredentialInjector::injectAll()`:
   - SSH Key / Username+Password / Vault: first-wins on their slot.
     If you attach two SSH keys, only the primary wins; extras are
     skipped with an info-level log entry.
   - Token: every distinct `env_var_name` becomes its own env var.
4. Runs `ansible-playbook` with the merged args and env.
5. Deletes any temp files (private keys, passwords, vault-password
   files) in a `finally` block so secrets never outlive the process.

The audit log records which runner started the job and which
credentials it got, by name and role, never their secrets.

## Example: 1Password lookup

Goal: a playbook pulls the MariaDB root password from 1Password at
runtime using a service-account token.

### 1. Create the credential

- Type: **Token**
- Name: `1password-service-account`
- Env var name: `OP_SERVICE_ACCOUNT_TOKEN`
- Token: paste the service-account token

### 2. Attach it to your job template

- Primary credential: your **SSH key** (so Ansible can connect to the
  target box).
- Additional credentials: tick the `1password-service-account`
  credential.

### 3. Use the env var in your playbook

> ⚠️ **Token credentials set a process-level environment variable,
> not a Jinja variable.** Reference them via
> `{{ lookup('env', 'OP_SERVICE_ACCOUNT_TOKEN') }}`. A bare
> reference like `OP_SERVICE_ACCOUNT_TOKEN` inside a Jinja
> expression is undefined and will fail with
> `'OP_SERVICE_ACCOUNT_TOKEN' is undefined`. This catches a lot
> of operators who see the `env_var_name` field and expect it to
> produce an Ansible variable directly.


```yaml
- name: Configure MariaDB
  hosts: dbservers
  vars:
    op_service_account_token: "{{ lookup('env', 'OP_SERVICE_ACCOUNT_TOKEN') }}"
    mariadb_mysql_root_password: "{{ lookup('community.general.onepassword',
      'mariadb-cluster-arm',
      service_account_token=op_service_account_token,
      field='password',
      vault='Servers') }}"
  roles:
    - mariadb
```

`community.general.onepassword` requires the `community.general`
Ansible collection and the `op` CLI. Ansilume's official runner image
pre-installs the collection; for the `op` CLI, bake it into your own
runner image or install it as an early task in the playbook.

## Example: multiple tokens

A playbook that provisions a VM needs **two** tokens — one for
1Password (to retrieve secrets) and one for a cloud API (to provision
the VM).

Create two Token credentials:

| Name | Env var name |
|---|---|
| `1password-service-account` | `OP_SERVICE_ACCOUNT_TOKEN` |
| `cloud-api-key` | `HCLOUD_TOKEN` |

Attach both to the template (plus your SSH key as primary). In the
playbook:

```yaml
- name: Provision VM
  hosts: localhost
  vars:
    op_token: "{{ lookup('env', 'OP_SERVICE_ACCOUNT_TOKEN') }}"
  tasks:
    - name: Create Hetzner Cloud VM
      hetzner.hcloud.server:
        api_token: "{{ lookup('env', 'HCLOUD_TOKEN') }}"
        name: new-db
        server_type: cpx21
        image: debian-12
```

Both env vars are set for the run; neither appears in logs.

## Bundled Ansible collections

The Ansilume runner and app images ship with these collections pre-
installed:

- `community.general` — 1Password, HashiCorp Vault, Slack, dozens of
  misc. modules and lookups.
- `ansible.posix` — `ansible.posix.synchronize`, `firewalld`,
  `mount`, `selinux`, etc.
- `community.crypto` — X509 certificate + key generation, OpenSSL.

Anything beyond this list must be installed by your own playbook
(e.g. via `ansible-galaxy collection install <name>` in a pre-task) or
added to a custom runner image.

## Security model

- **At rest:** `credential.secret_data` stores an AES-256-CBC
  encryption of `{"private_key": "...", "password": "...", ...}`.
  The key is derived from `APP_SECRET_KEY`. Changing that key makes the
  stored credentials unreadable; there is no re-encryption command yet,
  so credentials have to be entered again after a key change.
- **In transit:** when a runner claims a job, the server decrypts the
  job's credentials and sends them to that runner in the claim response.
  Use HTTPS for runners that are not on the server host. The runner
  writes private keys, SSH passwords and vault passwords to `0600` temp
  files and puts tokens (and, for compatibility, `ANSIBLE_SSH_PASS`)
  into the playbook environment. Temp files are unlinked in a `finally`
  block after `ansible-playbook` exits.
- **Subprocesses:** `ansible-playbook`, `ansible-lint` and
  `ansible-inventory` can run code from the project repository, so they
  only get an allowlisted environment. Ansilume's own secrets
  (`APP_SECRET_KEY`, database passwords, `RUNNER_BOOTSTRAP_SECRET`, …)
  never reach them. Console workers (queue-worker, runner) mark
  themselves non-dumpable, so these subprocesses cannot read the
  worker's own environment through `/proc` either. On a runner, other
  processes in the container (health check, `docker exec`) still carry
  the container environment; see
  [runners.md](runners.md#what-playbooks-can-see) for what that means
  for `RUNNER_BOOTSTRAP_SECRET`.
- **Vault content on the server:** "Parse Inventory" and ansible-lint
  run on the server, never with a vault password. A repository's
  `ansible.cfg` vault settings (`vault_password_file`,
  `vault_identity_list`, `ask_vault_pass`) are overridden with a random
  decoy, so a committed password file is not used and a password script
  never runs. Vault-encrypted `group_vars`/`host_vars` are skipped with
  a notice, inline `!vault` values are shown as `[vault-encrypted]`, an
  encrypted inventory file is reported as such, and lint of a playbook
  with encrypted `vars_files` shows "not lint-checked" instead of
  findings. Playbooks still decrypt normally on the runner with the
  template's vault credential.
- **In the UI:** the secret inputs are `type="password"` and forms
  never echo stored secrets back to the browser. Audit logs record
  every credential create / update / delete with only the non-secret
  metadata: name, type, user, timestamps, which fields changed and
  whether the secret was replaced.
- **In API responses:** `controllers/api/v1/CredentialsController`
  never exposes `secret_data`; the injector is the only code path
  that touches decrypted material.

## RBAC

| Permission | Who has it by default |
|---|---|
| `credential.view` | viewer, operator, admin |
| `credential.create` | operator, admin |
| `credential.update` | operator, admin |
| `credential.delete` | admin only |

Viewer can see that a credential exists, what type it is and where it
is used, but the secret fields are never rendered. Operator can create
and update but cannot delete. Deleting a credential detaches it from
every template and project that uses it, so deletion is admin-gated and
needs a second confirmation while the credential is in use.

The REST API enforces the same permissions: `GET /api/v1/credentials` and
`GET /api/v1/credentials/{id}` need `credential.view`. Every signed-in user
holds the viewer role implicitly, and operator and admin inherit viewer.
Removing `credential.view` from viewer therefore also removes it from
operator, admin and their API tokens. To hide credentials from read-only
users, first grant `credential.view` directly to operator, admin and every
custom role that needs it, then remove it from viewer. Superadmins keep every
permission.
