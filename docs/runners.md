# Standalone Runners

Ansilume runners are pull-based agents that poll the server for queued jobs,
execute Ansible playbooks locally, and stream results back via HTTP API. They
have **no direct access to the database or Redis** — all communication happens
over HTTP.

This makes it possible to deploy runners in separate networks, data centers, or
cloud regions. A runner only needs outbound HTTP access to the Ansilume server.

---

## Architecture

```
┌─────────────────────────────────┐
│  Ansilume Server                │
│  (app, nginx, db, redis, queue) │
│  Listening on port 8080 (HTTP)  │
│  or 443 behind a reverse proxy  │
├─────────────────────────────────┤
│  Runner API endpoints:          │
│  POST /api/runner/v1/register   │
│  POST /api/runner/v1/heartbeat  │
│  POST /api/runner/v1/jobs/claim │
│  POST /api/runner/v1/jobs/:id/* │
└───────────────▲─────────────────┘
                │ outbound HTTP(S)
                │ (runner → server)
                │
┌───────────────┴─────────────────┐
│  Remote Runner                  │
│  (standalone container or host) │
│  No inbound ports required      │
└─────────────────────────────────┘
```

**All connections are initiated by the runner.** The server never connects to
the runner. This means:

- No inbound firewall rules are needed on the runner side
- The runner can sit behind NAT, a corporate firewall, or a VPN
- Multiple runners in different networks can all reach the same server

---

## Network Requirements

| Direction | Protocol | Port | Purpose |
|-----------|----------|------|---------|
| Runner → Server | HTTP or HTTPS | 80/443 (or custom, e.g. 8080) | API communication |

No other ports or protocols are required. The runner uses:

- **Polling** every 5 seconds to claim queued jobs; the server never hands
  out a job of a deleted job template, or of a workflow run that is no
  longer running
- **Heartbeat** every 30 seconds to report liveness
- **Log streaming** via POST requests during job execution, in whole UTF-8
  characters; bytes that are not UTF-8 arrive as `�` (U+FFFD)
- **Job completion** via POST when the playbook finishes. A completion or
  timeout report for a job that was canceled, or marked failed because the
  runner had stopped reporting, while the playbook ran is noted in the
  job's log and otherwise ignored: the job keeps its status, and the server
  answers with it. There is no cancel channel, so a runner runs a canceled
  job's playbook to its end.

If your server uses HTTPS with a self-signed certificate, ensure the runner's
CA trust store includes it, or set `API_URL` with `http://` for testing.
Claim responses carry decrypted credentials and password survey answers, so a
runner that talks plain HTTP from outside the trusted networks is flagged, see
[Plain HTTP warning](#plain-http-warning).

---

## Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `API_URL` | Yes | Full URL of the Ansilume server, e.g. `https://ansilume.example.com` or `http://192.168.1.10:8080`. No trailing slash. |
| `RUNNER_NAME` | Yes (for bootstrap) | Unique name for this runner. Used during self-registration. |
| `RUNNER_BOOTSTRAP_SECRET` | Yes (for bootstrap) | Shared secret that authorizes self-registration. Must match the server's `RUNNER_BOOTSTRAP_SECRET`. |
| `RUNNER_TOKEN` | Alternative | Pre-configured runner token. If set, skips self-registration. Obtain from the Ansilume UI under Runner Groups. |
| `RUNNER_ENV_PASSTHROUGH` | No | Comma- or space-separated names of runner environment variables that playbooks may see, e.g. `AWS_PROFILE, OP_SERVICE_ACCOUNT_TOKEN`. See [What playbooks can see](#what-playbooks-can-see). |

You need either `RUNNER_BOOTSTRAP_SECRET` + `RUNNER_NAME` (self-registration)
or `RUNNER_TOKEN` (pre-configured). Not both.

### What playbooks can see

Playbooks do **not** inherit the runner's environment. `ansible-playbook`
only receives:

- `PATH`, `TMPDIR`, `TZ`, `HOSTNAME`, `USER`, `LOGNAME`
- locale: `LANG`, `LANGUAGE` and every `LC_*` variable
- proxy settings (`HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`, also lowercase)
- CA settings (`SSL_CERT_FILE`, `SSL_CERT_DIR`, `REQUESTS_CA_BUNDLE`, `CURL_CA_BUNDLE`)
- every `ANSIBLE_*` variable, so Ansible settings on the runner keep working
  (except the vault settings in "Ansilume only" projects, see
  [Vault settings of the repository](#vault-settings-of-the-repository))
- the variables listed in `RUNNER_ENV_PASSTHROUGH`
- the env vars of Token credentials attached to the job template

Ansilume's own secrets (`RUNNER_BOOTSTRAP_SECRET`, `RUNNER_TOKEN`,
`APP_SECRET_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD`,
`SMTP_PASSWORD`, `LDAP_BIND_PASSWORD`, `COOKIE_VALIDATION_KEY`) are never
forwarded, not even when listed in `RUNNER_ENV_PASSTHROUGH`; the runner says so
at start if you list one. `HOME` is set by Ansilume to a writable directory.

At start the runner logs the names of the variables it does not forward, so
you can see what a playbook will miss. Runner settings (`RUNNER_*`,
`API_URL`), Ansilume's server configuration (`DB_*`, `SMTP_*`, `YII_*`, …) and
the PHP image's own variables are left out of that list.

Prefer Token credentials for secrets: they are stored encrypted, attached per
job template, and audited. Use `RUNNER_ENV_PASSTHROUGH` for runner-specific
settings that are not secrets.

The runner process also marks itself non-dumpable, so playbooks cannot read its
environment through `/proc`, not even after `become`, because Docker drops
`CAP_SYS_PTRACE` by default. This needs the PHP FFI extension, which the
official runner image includes. Without it the runner prints a warning at start.

**Limit:** other processes Docker starts in the runner container, such as the
health check and every `docker exec`, carry the full container environment.
In the official image they run as the same user as playbooks, and that user may
use `sudo` without a password for `become`. A playbook can therefore still read
`RUNNER_BOOTSTRAP_SECRET` from such a process. The bootstrap secret lets anyone
register a runner in any runner group. On runners where not everyone who can
run playbooks is trusted with that, configure `RUNNER_TOKEN` instead of
`RUNNER_BOOTSTRAP_SECRET`. A runner token only grants access to that runner's
own group.

### Vault settings of the repository

Each project chooses how runners treat the vault settings of the
repository's `ansible.cfg` ([credentials.md](credentials.md#vault-passwords-on-runners)).
The server sends the choice with every claimed job as
`vault_password_source` (`ansilume` or `repository`). For `ansilume`, the
runner points `ANSIBLE_VAULT_PASSWORD_FILE` and `ANSIBLE_VAULT_IDENTITY_LIST`
at a random `0600` decoy in the temp directory, sets
`ANSIBLE_ASK_VAULT_PASS=False` and starts `ansible-playbook` through
`/usr/bin/env ANSIBLE_VAULT_ID_MATCH=`. These overrides win over the
repository's `ansible.cfg`, over `ANSIBLE_*` variables of the runner host and
over Token credentials with those names. The template's vault password still
arrives as `--vault-password-file`; the decoy is deleted when the job ends. If
the runner cannot create the decoy, the job fails with the reason instead of
running with the repository's settings.

Runners report what they support with every request
(`capabilities: ["vault_password_source", "secret_extra_vars"]`, next to
`software_version`); the Runners API shows it. Runners older than 2.8 report
nothing and apply the repository's vault settings for every project; the
vault card of an "Ansilume only" project lists those of its runner groups. A
missing or unknown value from an older server means "repository" for a new
runner.

### Password survey answers

Answers to password fields of a job template's survey are stored encrypted
on the server, and the job page, the jobs API and the stored command show
`$encrypted$` in their place. When a runner claims a job, the server decrypts
the answers and sends them with the claim, like credentials, so the advice
on [plain HTTP](#plain-http-warning) applies to them too. How they reach
`ansible-playbook` depends on whether the runner reports the capability
`secret_extra_vars`:

| Server | Runner | What happens |
|--------|--------|--------------|
| this release or newer | this release or newer | The claim carries the answers in `secret_extra_vars`, and neither `extra_vars` nor `command` contains them. The runner writes them to a `0600` file in the job's private directory (see below) and adds `--extra-vars @<file>` to the command; the file is removed with the directory when the job ends. The answers show neither in the process list nor in the command line that `ansible-playbook` prints at `-vvvv`. |
| this release or newer | older | The claim carries the answers inline in `extra_vars` and `command`, as before. The server caps the `-v` flags at `-vvv` and starts the command with `/usr/bin/env ANSIBLE_VERBOSITY=0`, because ansible-core prints every command-line argument above verbosity 3 and the effective verbosity also counts a repository's `ansible.cfg` `verbosity` and an `ANSIBLE_VERBOSITY` from a Token credential. When it lowered the verbosity, the job log ends with `[ansilume] Verbosity was lowered from 4 to 3: ...`. The answers show in the process list of the runner host while the job runs. |
| older | this release or newer | The claim has no `secret_extra_vars`; the runner runs `command` as sent, as it always did. |

Jobs without password answers are claimed and run exactly as before.

**The job's private directory.** For every job, the runner creates a new
directory in its temp directory (`TMPDIR`, otherwise `/tmp`), named
`ansilume_job_<pid>_<random>`, that only its own user can open (mode
`0700`). It never uses or follows anything that is already at that path.
The job's own files go there, each readable by the runner's user only
(mode `0600`): a static inventory (`inventory.yml`, which can hold host
passwords), the task records of the callback plugin (`tasks.ndjson`, whose
task names are templated and can carry answers) and, when the job has
password answers, the answers file (`secret_vars.json`). Ansible also takes
variables from files next to an inventory, so the inventory must not sit in
a directory that other users can write to. Playbooks see the directory as
`inventory_dir` of a static inventory. When the job ends, after success,
failure or an error, the runner removes the directory with everything in
it, without following links.

If the runner cannot create the directory, the task records or the
inventory, because its temp directory is missing, not writable, read-only
or full, the job fails before it runs with
`The runner could not create the job's private directory or files in its temp directory.`
If only the answers file cannot be written, the job fails with
`The runner could not write the survey answers file.` instead of running
without the answers or with them inline.

At start, the runner removes the job directories that a runner process
killed during a job left behind, and logs `Removed the private directories
of N job(s) that an earlier runner process left behind.` This check
considers only entries named exactly `ansilume_job_<pid>_<32 hexadecimal digits>`,
with the digits in lower case, as the runner names job directories, and
neither removes nor reports any other entry; the files of older runners are
reported separately (see **Files of older runners** below). It removes only
the directories of processes that no longer run, or of its own process ID
(a restarted container reuses PID 1), only where `/proc` exists, and only
real directories of its own user. In them it enters only directories of its
own user and never follows a link: a directory of another user in a job
directory stays with what it holds, and so does the job directory. It names
every other such match on stderr and starts anyway:

```
WARNING: Left /tmp/ansilume_job_123_0f1e2d3c4b5a69788796a5b4c3d2e1f0 in place (owned by another user). It may hold survey answers or host passwords of an earlier runner process; remove it by hand.
```

The reason is `not a directory`, `owned by another user`, `could not be
removed` (for example a job directory that holds a directory of another
user) or `the runner's user is unknown: the posix extension is missing`.
Without the PHP extension `posix`, the runner cannot tell whose a directory
is, so it removes nothing and names every match at each start; install the
extension and restart the runner, which then removes the directories of its
own user. Remove such a match: it can hold answers or host passwords in
plain text that its owner can read. A runner started by hand as another
user, such as `docker compose exec runner-1 php yii runner/start` in a git
checkout, which runs as root, leaves directories that the regular runner
cannot remove. Runners in different containers must not share a temp
directory: each one is PID 1 in its container, so one would remove the
directory of a job that another one is running. If the check fails
altogether, the runner logs
`WARNING: The check for job directories left behind failed (<class>).`
and starts anyway.

**Files of older runners.** Runners of 2.8.1 or older wrote a static
inventory (`ansilume_inv_<unique>.yml`, which can hold host passwords) and
the task records of a job (`ansilume_tasks_<job id>_<unique>.ndjson`, whose
task names can carry answers) straight into the temp directory, readable by
every local user under the usual umask, and left them there when a
credential could not be set up or the runner was killed. At every start,
the runner names each such file in its temp directory on stderr, until the
file is gone:

```
WARNING: Left /tmp/ansilume_inv_6acbf370bedbd6.68500798.yml in place (a runner of 2.8.1 or older left it). It may hold host passwords or survey answers; remove it by hand once no runner of 2.8.1 or older runs on this host.
```

It removes none of them, as a runner of 2.8.1 or older on the same host may
still be using one. Once every runner on the host is updated, remove them as
their owner or root:
`sudo rm -f -- /tmp/ansilume_inv_*.yml /tmp/ansilume_tasks_*.ndjson` (in
the runner's `TMPDIR` instead, if it has one). Runners older than 1.0 wrote
the inventory without `.yml`, as `ansilume_inv_<unique>`, which the runner
does not name; remove such files too. A runner that got
`PrivateTmp=true` with the update no longer sees the host's `/tmp`, so it
cannot name the files there; remove them all the same. A container runner
gets a new `/tmp` when the container is recreated.

**Masking the output.** The runner replaces the answers with `********` in
the output of the job and in the task names and hosts it reports, before
they reach the server. This is best effort:

- answers shorter than 4 characters, `true`, `false`, `null`, lists and maps
  are not masked; text answers are also masked in their JSON-escaped forms,
  numbers as their digits;
- answers that Ansible prints in another form (hashed, encoded, split,
  quoted differently) stay visible;
- the mask tells where the output equalled an answer: an answer that equals
  a host name masks that host name;
- what the playbook writes elsewhere, to files or to other systems, is not
  touched;
- live output is held back only while its end could still turn out to be
  the start of an answer.

Runners without `secret_extra_vars` do not mask. Keep answers out of the
output in the first place, for example with `no_log: true` on tasks that use
them.

---

## Authentication Flow

### Option A: Self-registration (recommended for automated deployments)

1. Runner starts and finds no `RUNNER_TOKEN` in the environment
2. Runner sends `POST /api/runner/v1/register` with `{ name, bootstrap_secret }`
3. Server validates the secret and returns a token. It creates the runner in
   the requested group ("default" if none), or, when a runner with this name
   already exists in that group, issues it a new token and revokes the old
   one. Both are recorded in the audit log (`runner.created`,
   `runner.reregistered`).
4. Runner caches the token in `runtime/` and uses it for all subsequent requests
5. On restart, the cached token is reused; if invalid, re-registration is attempted

Because re-registration revokes the previous token, the runner group page
shows "Token re-issued by self-registration" under the runner, plus a badge
when that happened more than once within 24 hours. The Runners API reports
the same as `last_reregistered_at` and `reregistrations_24h`. Repeated resets
usually mean that two runners use the same `RUNNER_NAME` in one group, or that
someone else registered with it, so give every runner a unique name. The
bundled runners of the prebuilt compose file and the deploy role keep their
token in a named volume (`runner_N_runtime`), so recreating them during an
update does not re-register them.

### Option B: Pre-configured token

1. In the Ansilume UI, go to **Runner Groups** → select a group → **Add Runner**
2. Copy the generated token from the card on the group's page: it is shown
   only this once (**Regen Token** gives the runner a new one)
3. Set `RUNNER_TOKEN=<token>` in the runner's environment
4. Runner uses this token directly — no registration step

---

## Docker Compose (Standalone Runner)

Create a `docker-compose.yaml` on the remote host:

```yaml
services:
  runner:
    image: ghcr.io/ansilume/ansilume-runner:latest
    restart: unless-stopped
    environment:
      API_URL: "https://ansilume.example.com"
      RUNNER_NAME: "dc2-runner-1"
      RUNNER_BOOTSTRAP_SECRET: "your-bootstrap-secret-here"
    volumes:
      - runner_runtime:/var/www/runtime

volumes:
  runner_runtime:
```

Start with:

```bash
docker compose up -d
```

To run multiple runners on the same host, add more services:

```yaml
services:
  runner-1:
    image: ghcr.io/ansilume/ansilume-runner:latest
    restart: unless-stopped
    environment:
      API_URL: "https://ansilume.example.com"
      RUNNER_NAME: "dc2-runner-1"
      RUNNER_BOOTSTRAP_SECRET: "your-bootstrap-secret-here"
    volumes:
      - runner1_runtime:/var/www/runtime

  runner-2:
    image: ghcr.io/ansilume/ansilume-runner:latest
    restart: unless-stopped
    environment:
      API_URL: "https://ansilume.example.com"
      RUNNER_NAME: "dc2-runner-2"
      RUNNER_BOOTSTRAP_SECRET: "your-bootstrap-secret-here"
    volumes:
      - runner2_runtime:/var/www/runtime

volumes:
  runner1_runtime:
  runner2_runtime:
```

---

## Running Without Docker

If you prefer to run the runner directly on a host:

### Prerequisites

- PHP 8.2+ with extensions: `pcntl`, `posix`, `zip`, `curl`, and `ffi` (recommended: without it,
  playbooks running as the same user can read the runner's environment, see
  [What playbooks can see](#what-playbooks-can-see)). Without `posix` the
  runner cannot tell its own user, so its check at start removes no job
  directory that a killed runner process left behind (see
  [Password survey answers](#password-survey-answers));
  `php -m | grep -x posix` prints `posix` when PHP has it
- `zend.exception_ignore_args = On` in the runner's php.ini, as the runner
  image sets it: PHP then leaves the arguments of calls out of the stack
  traces of errors, which could otherwise show the first 15 characters of a
  password, a token or a survey answer in the runner's logs
- Ansible 2.14+
- `ansible-lint` (optional, for linting jobs)
- OpenSSH client (for SSH-based connections)
- Git (for project sync)

### Setup

```bash
# Clone the repository
git clone https://github.com/ansilume/ansilume.git /opt/ansilume-runner
cd /opt/ansilume-runner

# Install PHP dependencies (no dev)
composer install --no-dev --optimize-autoloader

# Create runtime directories
mkdir -p runtime/projects runtime/artifacts runtime/logs

# Set environment variables
export API_URL="https://ansilume.example.com"
export RUNNER_NAME="bare-runner-1"
export RUNNER_BOOTSTRAP_SECRET="your-bootstrap-secret-here"

# Start the runner
php yii runner/start
```

For production, use a process manager like systemd:

```ini
# /etc/systemd/system/ansilume-runner.service
[Unit]
Description=Ansilume Runner
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=ansilume
WorkingDirectory=/opt/ansilume-runner
Environment=API_URL=https://ansilume.example.com
Environment=RUNNER_NAME=bare-runner-1
Environment=RUNNER_BOOTSTRAP_SECRET=your-bootstrap-secret-here
ExecStart=/usr/bin/php yii runner/start
Restart=always
RestartSec=5
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ansilume-runner
```

`PrivateTmp=true` gives the runner a `/tmp` of its own. The runner keeps a
job's own files in a private directory of the job (see
[Password survey answers](#password-survey-answers)), but it writes other
files straight into its temp directory: the credential files of a job (SSH
keys, passwords, vault passwords), the decoy of the vault settings and the
SSH key of a project sync. They are readable by the runner's user only,
but a runner killed during a job leaves them behind, and its check at start
does not find them. With `PrivateTmp=true` they stay out of reach of other
users and services of the host, and systemd removes what a killed runner
left there whenever the service stops or restarts. Runners older than this
release write the static inventory and the task records of a job straight
into the temp directory too, so for them this is the only protection. An
updated runner does not remove the files they left behind; it names those
in its own temp directory at start (see **Files of older runners** above).
Without systemd, give each runner a temp directory of its own (`TMPDIR`,
mode `0700`).

---

## Firewall Rules

### On the runner host

Allow outbound HTTP/HTTPS to the Ansilume server:

```bash
# iptables example
iptables -A OUTPUT -p tcp -d <server-ip> --dport 443 -j ACCEPT
iptables -A OUTPUT -p tcp -d <server-ip> --dport 8080 -j ACCEPT

# If using DNS resolution
iptables -A OUTPUT -p udp --dport 53 -j ACCEPT
iptables -A OUTPUT -p tcp --dport 53 -j ACCEPT
```

No inbound rules are needed for runner operation.

### On the Ansilume server

The runner API endpoints are under `/api/runner/v1/*`. If you want to restrict
access to known runner IPs, add an `allow`/`deny` block to the nginx location
for that path — for example in a reverse proxy in front of the Ansilume nginx
container:

```nginx
# External reverse proxy — allow only known runner IPs
location /api/runner/v1/ {
    allow 10.0.0.0/8;
    allow 192.168.1.0/24;
    deny all;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_pass http://localhost:8080;
}
```

The two `proxy_set_header` lines let Ansilume see that a runner connected
over HTTPS, see [Plain HTTP warning](#plain-http-warning). Caddy and Traefik
send these headers by default.

## Plain HTTP warning

When a runner claims a job, the claim response carries the job's credentials
and password survey answers in decrypted form, and every request carries the
runner token. The server records how each runner's last request arrived and
shows it in the **Transport** column of the runner group page, in the Runners
API (`transport`, `transport_insecure`, `remote_addr`, `plaintext_seen_at`)
and in `bin/diagnose`:

| Transport | Meaning |
|-----------|---------|
| HTTPS | TLS, directly or through a trusted reverse proxy that sends `X-Forwarded-Proto: https` |
| HTTP, internal | Plain HTTP from a trusted network, such as the bundled runners on the internal Docker network |
| Plain HTTP | Plain HTTP from outside the trusted networks. The runner group page shows a red warning |
| unknown | No request since the upgrade |

The trusted networks come from `RUNNER_TRUSTED_NETWORKS` on the server, a
comma-separated list of IP addresses or ranges. The default covers loopback
and the private ranges `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` and
`fc00::/7`, so bundled runners and a reverse proxy on the same host count as
internal. A value replaces the defaults: narrow it, for example to your Docker
networks, if plain HTTP across your LAN should be flagged too, and keep the
ranges the bundled runners and your reverse proxy connect from. Invalid
entries, such as a prefix longer than 32 (IPv4) or 128 (IPv6) bits or a list
separated by spaces, are ignored; when none is valid the defaults apply.
`bin/diagnose` shows the list in use and names ignored entries.

Forwarding headers (`X-Forwarded-For` with `X-Forwarded-Proto`, and
`Forwarded`) count only when the request comes from a trusted address, so a
remote runner that connects directly cannot hide the warning. Behind a reverse
proxy, the proxy must set those headers itself instead of passing the client's
through: a header the proxy forwards unchanged comes from the client. When the
families disagree, the less secure answer wins, so such a header can add a
warning but not remove one, as long as the proxy sets the family it uses. With
nginx, `proxy_set_header X-Forwarded-Proto $scheme;` and
`proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` (see above)
are enough. Apache needs
`RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}` next to
`ProxyPass`, HAProxy `http-request set-header X-Forwarded-Proto https if { ssl_fc }`
and `http-request set-header X-Forwarded-Proto http if !{ ssl_fc }` next to
`option forwardfor`.

The check relies on the address the server sees. When Docker's userland proxy
relays the published port, for example for IPv6 clients on an IPv4-only
network, every client arrives from the bridge gateway, which is a private
address, so a remote runner on plain HTTP shows as internal. Use HTTPS for
every runner outside the server's host regardless of what the page says.

To fix a flagged runner, point its `API_URL` at an `https://` address behind a
TLS reverse proxy, then rotate the credentials it received. The page keeps
showing when plain HTTP was last seen. The warning is for visibility only: it
does not block runners, and no runner update is needed.

---

## Runner Groups

Runner groups let you organize runners by location, purpose, or capability.
Job templates are assigned to a specific runner group, and only runners in that
group will claim jobs for that template.

Use cases:

- **Geographic**: `eu-west`, `us-east`, `dc2`
- **Network zone**: `dmz`, `internal`, `cloud`
- **Capability**: `linux`, `windows`, `high-memory`

Runners that self-register are placed in the "default" group. To assign a runner
to a different group:

1. Create the group in the UI under **Runner Groups**
2. Either pre-configure the runner with a token from that group, or move the
   runner to the group after it self-registers

A runner group is deleted only while no runner belongs to it and no job
template uses it; the web UI and `DELETE /api/v1/runner-groups/{id}` refuse
it otherwise. Deleting it would delete its runners and take it off the
templates, and no runner claims the jobs of a template without a runner
group. Move or delete the runners and give the templates another group
first, see
[Deleting a runner group is refused](troubleshooting.md#deleting-a-runner-group-is-refused).

---

## Verifying Connectivity

After starting a runner, verify it registered and is online:

1. **UI**: Go to **Runner Groups** → the runner should appear with a green
   "Online" badge (last seen within 60 seconds)
2. **API**: `GET /api/v1/runner-groups` lists groups with their runners
3. **Logs**: The runner logs its registration and polling activity to stdout:
   ```
   Runner registered: dc2-runner-1 (group: default, id: 7)
   Polling for jobs...
   Heartbeat sent.
   ```

### Common issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| `ERROR: API_URL environment variable is required` | `API_URL` not set | Set the environment variable |
| `403 Invalid bootstrap secret` | Secret mismatch | Ensure `RUNNER_BOOTSTRAP_SECRET` matches the server |
| `Server is not ready for runner registration yet (...) — retrying in 5s` | Server answers 503 (no admin user yet) or 502 (app container still booting) | Normal during first boot. The runner retries 12 times at 5 s intervals (about one minute), then exits and lets the container restart policy try again. Finish the initial setup or check `docker compose logs app`. |
| `Registration failed: ... The server never became ready (503)` | Initial setup was never completed | Create the admin user (`php yii setup/admin ...`) — the runner keeps retrying on every restart |
| `Registration failed: ... The app container never answered (502 from nginx)` | App container unhealthy or not started | Check `docker compose ps` and `docker compose logs app` on the server |
| `Connection refused` | Server unreachable | Check network path, firewall, DNS |
| `SSL certificate problem` | Self-signed cert | Add CA to trust store or use HTTP for testing |
| Runner shows "Offline" in UI | Heartbeat not reaching server | Check outbound connectivity, proxy settings |
| Runner online but no jobs claimed | Wrong runner group | Ensure the job template's runner group matches |
| Runner group page shows "Plain HTTP" for a runner | The runner talks plain HTTP from outside the trusted networks | Use an `https://` `API_URL` behind a TLS reverse proxy that sends `X-Forwarded-Proto`, see [Plain HTTP warning](#plain-http-warning) |
| Job fails with `The runner could not create the job's private directory or files in its temp directory.` or `The runner could not write the survey answers file.` | The runner's temp directory (`TMPDIR`) is missing, not writable, read-only or full | Fix the directory or free space; the job did not run. See [Password survey answers](#password-survey-answers) |
| `WARNING: Left <path> in place (<reason>). It may hold survey answers or host passwords of an earlier runner process; remove it by hand.` at start | A job directory of a killed runner process that this runner may not remove: another user's directory, a file or a link with such a name, or a directory it could not empty | Remove it by hand; it can hold answers or host passwords in plain text. See [Password survey answers](#password-survey-answers) |
| `WARNING: Left <path> in place (the runner's user is unknown: the posix extension is missing). ...` at start | PHP has no `posix` extension, so the runner cannot tell whose a job directory is and removes none | Install the extension and restart the runner, or remove the directories by hand. See [Password survey answers](#password-survey-answers) |
| `WARNING: Left <path> in place (a runner of 2.8.1 or older left it). ...` at start | A runner of 2.8.1 or older left a static inventory or task records in the temp directory | Remove the file once every runner on the host is updated; it can hold host passwords that every local user can read. See [Password survey answers](#password-survey-answers) |
| Job log ends with `[ansilume] Verbosity was lowered from ... to 3` | The job has password answers and ran on a runner without `secret_extra_vars` | Update the runner image, see [Password survey answers](#password-survey-answers) |

---

## Security Considerations

- **Bootstrap secret**: Treat this like a password. Anyone with this secret can
  register runners in any runner group. Rotate it periodically and use a strong
  random value (`openssl rand -hex 24`). Playbooks can read it on runners that
  have it set, see [What playbooks can see](#what-playbooks-can-see); use
  `RUNNER_TOKEN` there if playbook authors must not obtain it.
- **Runner token**: The self-registration token is cached in `runtime/`. Protect
  this directory. If compromised, regenerate the runner's token from the UI.
- **Network**: Use HTTPS in production. When a runner claims a job, the server
  sends it the job's credentials and password survey answers in decrypted
  form over this connection. The runner group page flags plain HTTP, see
  [Plain HTTP warning](#plain-http-warning).
- **Runner isolation**: Runners execute Ansible playbooks with whatever system
  privileges they have. Run the container as a non-root user (the Docker image
  defaults to `www-data`). The bundled runners of the prebuilt compose file
  and the Ansible deploy role use the standalone runner image, mount nothing
  from the host and sit on a separate `runners` network that only nginx joins.
  Playbooks therefore cannot reach php-fpm, the database or Redis, and cannot
  read the server's `.env`. The development compose file of a git checkout
  mounts the source tree into its runners, so playbooks there can read `.env`
  and change the code; use it for development only. Give standalone runners
  the same treatment: they need nothing but the Ansilume API (HTTP/HTTPS) and
  the hosts they manage.
- **Shared runners**: All jobs on a runner run as the same user. A playbook can
  read files of other jobs running at the same time on that runner, including
  their credential temp files and password answer files, and the runner's
  cached token; on runners without `secret_extra_vars` it can also read the
  password answers of other jobs from the process list. Give teams that must
  not trust each other their own runner groups.
