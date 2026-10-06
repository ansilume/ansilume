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

- **Polling** every 5 seconds to claim queued jobs
- **Heartbeat** every 30 seconds to report liveness
- **Log streaming** via POST requests during job execution
- **Job completion** via POST when the playbook finishes

If your server uses HTTPS with a self-signed certificate, ensure the runner's
CA trust store includes it, or set `API_URL` with `http://` for testing.

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

---

## Authentication Flow

### Option A: Self-registration (recommended for automated deployments)

1. Runner starts and finds no `RUNNER_TOKEN` in the environment
2. Runner sends `POST /api/runner/v1/register` with `{ name, bootstrap_secret }`
3. Server validates the secret, creates (or updates) the runner record in the
   "default" runner group, and returns a token
4. Runner caches the token in `runtime/` and uses it for all subsequent requests
5. On restart, the cached token is reused; if invalid, re-registration is attempted

### Option B: Pre-configured token

1. In the Ansilume UI, go to **Runner Groups** → select a group → **Add Runner**
2. Copy the generated token
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

- PHP 8.2+ with extensions: `pcntl`, `zip`, `curl`, and `ffi` (recommended: without it,
  playbooks running as the same user can read the runner's environment, see
  [What playbooks can see](#what-playbooks-can-see))
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

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ansilume-runner
```

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
    proxy_pass http://localhost:8080;
}
```

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
  sends it the job's credentials in decrypted form over this connection.
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
  their credential temp files, and the runner's cached token. Give teams that
  must not trust each other their own runner groups.
