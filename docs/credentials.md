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
| **Username / Password** | SSH password auth (where keys aren't an option). | `--user <username>` + `ANSIBLE_SSH_PASS` env var. |
| **Vault Secret** | Decrypt Ansible-Vault-encrypted vars files. | `--vault-password-file <tmpfile>`. |
| **Token** | Arbitrary secret surfaced to the playbook as an env var. Use this for API keys, service-account tokens, webhook secrets — anything that doesn't map onto the three above. | Named environment variable (see below). |

The `username` field is only read for SSH Key and Username/Password
credentials. Vault and Token credentials ignore it — the UI hides the
field when you pick one of those types.

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

When a job is launched the runner:

1. Fetches all linked credentials, primary first, extras in pivot order.
2. Decrypts them with the app's encryption key.
3. Feeds the list through `CredentialInjector::injectAll()`:
   - SSH Key / Username+Password / Vault: first-wins on their slot.
     If you attach two SSH keys, only the primary wins; extras are
     skipped with an info-level log entry.
   - Token: every distinct `env_var_name` becomes its own env var.
4. Runs `ansible-playbook` with the merged args and env.
5. Deletes any temp files (private keys, vault-password files) in a
   `finally` block so secrets never outlive the process.

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
  The key lives in `APP_SECRET_KEY`; rotate it via
  `php yii credential/rotate-key` (the command re-encrypts every row).
- **In transit:** decryption happens inside the runner process only,
  after the job has been claimed. The decrypted material is written to
  `tempfile(mode=0600)` for private keys / vault passwords, or into
  the process environment for passwords and tokens. Temp files are
  unlinked in a `finally` block after `ansible-playbook` exits.
- **In the UI:** the secret inputs are `type="password"` and forms
  never echo stored secrets back to the browser. Audit logs record
  every credential create / update / delete with only the non-secret
  metadata (name, type, user, timestamps).
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

Viewer can see that a credential exists and what type it is, but the
secret fields are never rendered. Operator can create and update but
cannot delete — once a credential is attached to a template it would
disappear from there on delete, so deletion is admin-gated.
