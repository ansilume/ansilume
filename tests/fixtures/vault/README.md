# Vault fixtures

Static Ansible vault data for the unit tests of `app\components\vault`. The
tests never run `ansible-vault`, so they also work where it is not installed
(Scrutinizer runs the unit suite without it).

Generated once with `ansible-vault` (ansible-core 2.19.11) in the app
container. Do not edit these files by hand: CRLF line endings and the trailing
space in `malformed-trailing-space.yml` are part of the test data
(`.gitattributes` keeps git from converting them). Regenerate instead.

## Passwords

Dummy passwords, never real secrets:

| Password | Given to ansible-vault as | Vault header |
|---|---|---|
| `ansilume-test-dummy-dev` | `--vault-password-file dev.pw` | `$ANSIBLE_VAULT;1.1;AES256` |
| `ansilume-test-dummy-prod` | `--vault-id prod@prod.pw` | `$ANSIBLE_VAULT;1.2;AES256;prod` |

The plaintexts are dummy values as well (`dummy-dev-value`, `dummy-inline-db`, ...).

## Files

| File | Content |
|---|---|
| `file-1.1.yml` | whole-file vault, format 1.1, dev password |
| `file-1.2-prod.yml` | whole-file vault, format 1.2 with vault ID `prod`, prod password |
| `malformed-trailing-space.yml` | `file-1.1.yml` with a trailing space on body line 2 |
| `inline.yml` | `encrypt_string` output in six shapes: top-level key (line 3), nested key with `\|-` (10), `\|+` with a comment (17), `!vault-encrypted` (25), key in a list item followed by a sibling key (34), bare list item (42) |
| `inline-crlf.yml` | `inline.yml` with CRLF line endings |
| `repo/` | repository skeleton, see below |

`repo/` is a small project:

- `ansible.cfg` with deliberately unsafe vault settings: `vault_password_file = .vault_pass`,
  `ask_vault_pass = True`, `vault_id_match = False` (on: Ansible does not type it) and
  `vault_encrypt_salt`;
- `.vault_pass`, a committed plaintext password file (the dev password);
- `site.yml` (playbook in the root) with `vars_files: [vars/secrets.yml, "vars/{{ env_name }}.yml"]`,
  `vars/secrets.yml` (vault, dev) and `group_vars/all.yml` (inline value, dev);
- `playbooks/deploy.yml` (nested playbook) with `vars_files: [../vars/secrets.yml, deploy-vars.yml]`,
  `playbooks/deploy-vars.yml` and `playbooks/group_vars/web.yml` (vaults, dev);
- `inventories/dev/` and `inventories/prod/` with `hosts.yml` and `group_vars/all/{vars,vault}.yml`;
  the dev vault uses the dev password (1.1), the prod vault the prod password (1.2, `prod`), and
  `inventories/prod/host_vars/prod-web1.yml` holds an inline prod value.

Every vault has its own fixed salt (`ANSIBLE_VAULT_ENCRYPT_SALT`), so salts have different
lengths and the output is byte-identical on every run. Fixed salts are for fixtures only:
a repository that sets `vault_encrypt_salt` reuses key and IV for every file encrypted with
the same password.

## Regenerating

Save the script below as `generate.sh` outside the repository and run, from the repository root:

```sh
docker compose exec -T -w /tmp app sh -s < generate.sh | tar -C tests/fixtures/vault -xf -
find tests/fixtures/vault -type d -exec chmod 755 {} + && find tests/fixtures/vault -type f -exec chmod 644 {} +
```

It works in temporary directories inside the container, with `HOME` pointing at an empty
directory and no `ANSIBLE_CONFIG`, so no `ansible.cfg` applies, and writes a tar stream of the
fixtures to stdout. Two runs gave identical sha256 sums for every file.

```sh
#!/bin/sh
# Generates tests/fixtures/vault. Run inside the app container, outside any
# repository, so that no ansible.cfg applies:
#   docker compose exec -T -w /tmp app sh -s < generate.sh | tar -C tests/fixtures/vault -xf -
# Writes a tar stream of the fixtures to stdout; everything else goes to stderr.
set -eu
unset ANSIBLE_CONFIG ANSIBLE_VAULT_PASSWORD_FILE ANSIBLE_VAULT_IDENTITY_LIST || true
export HOME="$(mktemp -d)"
WORK="$(mktemp -d)"
OUT="$WORK/out"
mkdir -p "$OUT/repo/vars" "$OUT/repo/group_vars" "$OUT/repo/playbooks/group_vars" \
    "$OUT/repo/inventories/dev/group_vars/all" "$OUT/repo/inventories/prod/group_vars/all" \
    "$OUT/repo/inventories/prod/host_vars"
cd "$WORK"
printf 'ansilume-test-dummy-dev' > dev.pw
printf 'ansilume-test-dummy-prod' > prod.pw

# encrypt <salt> <file> <password args...>: whole-file vault, in place.
encrypt() {
    salt="$1"; file="$2"; shift 2
    ANSIBLE_VAULT_ENCRYPT_SALT="$salt" ansible-vault encrypt "$@" "$file" >&2
}

# inline <salt> <plaintext> <indent> <password args...>: a bare envelope from
# encrypt_string, every line prefixed with <indent>.
inline() {
    salt="$1"; value="$2"; pad="$3"; shift 3
    printf '%s' "$value" \
        | ANSIBLE_VAULT_ENCRYPT_SALT="$salt" ansible-vault encrypt_string "$@" --stdin-name v 2>/dev/null \
        | sed -n '2,$p' | sed 's/^ *//' | sed "s/^/$pad/"
}

DEV='--vault-password-file dev.pw'
PROD='--vault-id prod@prod.pw'

# 1. Whole-file vaults: format 1.1 (no vault ID) and 1.2 with the vault ID "prod".
printf 'db_password: dummy-dev-value\n' > "$OUT/file-1.1.yml"
encrypt ansilume-fixture-1.1 "$OUT/file-1.1.yml" $DEV
printf 'db_password: dummy-prod-value\n' > "$OUT/file-1.2-prod.yml"
encrypt ansilume-fixture-1.2-prod "$OUT/file-1.2-prod.yml" $PROD

# 2. Malformed: the 1.1 file with a trailing space on its second body line.
sed '3s/$/ /' "$OUT/file-1.1.yml" > "$OUT/malformed-trailing-space.yml"

# 3. Inline values (encrypt_string output) in the shapes Ansible accepts.
{
    echo '---'
    echo '# Inline vault values in the shapes Ansible accepts. Generated, see README.md.'
    echo 'db_password: !vault |'
    inline ansilume-fixture-inline-db dummy-inline-db '          ' $DEV
    echo 'api:'
    echo '  token: !vault |-'
    inline ansilume-fixture-inline-token dummy-inline-token '    ' $PROD
    echo '  keep: !vault |+  # a comment after the block indicator'
    inline ansilume-fixture-inline-keep dummy-inline-keep '    ' $DEV
    echo ''
    echo 'legacy: !vault-encrypted |'
    inline ansilume-fixture-inline-legacy dummy-inline-legacy '  ' $DEV
    echo 'users:'
    echo '  - name: alice'
    echo '    password: !vault |'
    inline ansilume-fixture-inline-alice dummy-inline-alice '          ' $PROD
    echo '    shell: /bin/bash'
    echo '  - !vault |'
    inline ansilume-fixture-inline-item dummy-inline-item '    ' $DEV
    echo 'plain: not encrypted'
} > "$OUT/inline.yml"
sed 's/$/\r/' "$OUT/inline.yml" > "$OUT/inline-crlf.yml"

# 4. A repository skeleton with deliberately unsafe ansible.cfg vault settings.
R="$OUT/repo"
cat > "$R/ansible.cfg" <<'CFG'
# Deliberately unsafe vault settings for the scanner tests, see ../README.md.
[defaults]
inventory = inventories/dev/hosts.yml
vault_password_file = .vault_pass
ask_vault_pass = True
; untyped in Ansible: any non-empty value, even False, turns matching on
vault_id_match = False
vault_encrypt_salt = ansilume-fixture-salt

[ssh_connection]
pipelining = True
CFG
printf 'ansilume-test-dummy-dev\n' > "$R/.vault_pass"
cat > "$R/site.yml" <<'YML'
---
- name: Site
  hosts: all
  gather_facts: false
  vars:
    env_name: dev
  vars_files:
    - vars/secrets.yml
    - "vars/{{ env_name }}.yml"
  tasks:
    - name: Show that the vault values loaded
      ansible.builtin.debug:
        msg: "{{ db_password | length }} {{ app_secret | length }} {{ group_secret | length }}"
YML
printf 'db_password: dummy-repo-secret\n' > "$R/vars/secrets.yml"
encrypt ansilume-fixture-repo-secrets "$R/vars/secrets.yml" $DEV
printf 'env_label: dev\n' > "$R/vars/dev.yml"
{
    echo '---'
    echo 'ntp_server: ntp.example.org'
    echo 'app_secret: !vault |'
    inline ansilume-fixture-repo-app dummy-repo-app '          ' $DEV
} > "$R/group_vars/all.yml"
cat > "$R/playbooks/deploy.yml" <<'YML'
---
- name: Deploy
  hosts: web
  gather_facts: false
  vars_files:
    - ../vars/secrets.yml
    - deploy-vars.yml
  tasks:
    - name: Show that the vault values loaded
      ansible.builtin.debug:
        msg: "{{ db_password | length }} {{ deploy_token | length }}"
YML
printf 'deploy_token: dummy-deploy-token\n' > "$R/playbooks/deploy-vars.yml"
encrypt ansilume-fixture-repo-deploy "$R/playbooks/deploy-vars.yml" $DEV
printf 'web_secret: dummy-web-secret\n' > "$R/playbooks/group_vars/web.yml"
encrypt ansilume-fixture-repo-web "$R/playbooks/group_vars/web.yml" $DEV
for env in dev prod; do
    cat > "$R/inventories/$env/hosts.yml" <<YML
---
all:
  hosts:
    $env-web1:
      ansible_connection: local
  children:
    web:
      hosts:
        $env-web1:
YML
    printf 'env_label: %s\n' "$env" > "$R/inventories/$env/group_vars/all/vars.yml"
done
printf 'group_secret: dummy-dev-group\n' > "$R/inventories/dev/group_vars/all/vault.yml"
encrypt ansilume-fixture-repo-dev-group "$R/inventories/dev/group_vars/all/vault.yml" $DEV
printf 'group_secret: dummy-prod-group\n' > "$R/inventories/prod/group_vars/all/vault.yml"
encrypt ansilume-fixture-repo-prod-group "$R/inventories/prod/group_vars/all/vault.yml" $PROD
{
    echo '---'
    echo 'host_secret: !vault |'
    inline ansilume-fixture-repo-prod-host dummy-prod-host '          ' $PROD
} > "$R/inventories/prod/host_vars/prod-web1.yml"

tar -C "$OUT" -cf - .
```

## Cross-check

Done once in the app container (ansible-core 2.19.11) on 2026-10-07:

- Every vault in this directory, the whole files and each inline value as
  `InlineVaultExtractor` extracts it (written to its own file), was opened with
  `ansible-vault view` and both passwords. `ansible-vault` opened exactly the
  vaults `VaultEnvelope::opens()` accepts: 22 vaults, 44 comparisons, no
  disagreement.
- `malformed-trailing-space.yml` fails in Ansible with "Vault format unhexlify
  error: Odd-length string"; `file-1.1.yml` with a UTF-8 byte order mark or a
  blank first line fails with "Input is not vault encrypted data."; with CRLF
  line endings it opens.
- A playbook loading `inline.yml` and `inline-crlf.yml` through `vars_files`
  decrypted all six values with both passwords given.
- In `repo/` (with `ANSIBLE_ASK_VAULT_PASS=False`): `site.yml` runs against
  `inventories/dev/hosts.yml` with the committed `.vault_pass`; against
  `inventories/prod/hosts.yml` it fails with the repository password alone and
  with an unlabelled `--vault-password-file` holding the prod password
  (`vault_id_match = False` turns matching on), and runs with
  `--vault-id prod@prod.pw`; `playbooks/deploy.yml` runs against the dev
  inventory. With `ask_vault_pass = True` and stdin closed, as on runners,
  `site.yml` fails with "EOFError (ctrl-d) on prompt for (default)".
- A password file holding the password plus spaces, tabs, CR/LF or a form feed
  still opens `file-1.1.yml`, as `VaultEnvelope::normalizePassword()` expects.
