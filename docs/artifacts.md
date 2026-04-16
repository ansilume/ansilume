# Job Artifacts

Job artifacts are files produced by an Ansible playbook during a run that
Ansilume captures, stores, and exposes for download or inline preview.
Typical use cases: generated reports, rendered configs, host facts,
diagnostic dumps, screenshots, JSON/YAML produced by a custom module, etc.

The runner exports a per-job temporary directory via the
`ANSILUME_ARTIFACT_DIR` environment variable. Anything the playbook writes
into that directory is collected after the playbook finishes and persisted to
permanent storage. The temp directory itself is removed.

---

## How it works

```
┌─────────────┐  1. exports ANSILUME_ARTIFACT_DIR  ┌─────────────────┐
│   Runner    │ ──────────────────────────────────►│  ansible-       │
│ (yii worker)│                                    │  playbook       │
└─────┬───────┘                                    └────────┬────────┘
      │                                                     │
      │  4. ArtifactCollector → ArtifactService             │ 2. writes files
      │                                                     │    into the dir
      ▼                                                     ▼
┌─────────────────┐                              ┌─────────────────────┐
│ @runtime/       │ ◄──── 3. playbook ends ──────│ /tmp/ansilume_      │
│ artifacts/      │                              │ artifacts_<id>_…/   │
│ job_<ID>/…      │                              └─────────────────────┘
└─────────────────┘
        │
        │  5. visible in UI + REST API + zip download
        ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Job view page  │  GET /api/v1/jobs/{id}/artifacts                   │
└─────────────────────────────────────────────────────────────────────┘
```

1. When a job starts, `RunAnsibleJob` creates `/tmp/ansilume_artifacts_<job_id>_<rand>/`
   and exports its path in the env block passed to `ansible-playbook`.
2. The playbook can read `$ANSILUME_ARTIFACT_DIR` and write any files into it.
3. After the playbook exits, `ArtifactCollector` is invoked.
4. `ArtifactService::collectFromDirectory()` scans the directory, validates
   each file (size, symlink check, path-traversal guard) and copies eligible
   files into `@runtime/artifacts/job_<ID>/`. A `job_artifact` row is
   inserted per file (filename, display name, MIME type, size, storage path).
5. The temp directory is removed unconditionally — only the persisted copies
   under `@runtime/artifacts/` survive.

The collector never aborts on a single bad file: oversized files, symlinks
and files outside the artifact directory are skipped with a warning so the
rest of the artifacts still land.

---

## Producing artifacts in a playbook

The artifact directory lives **on the runner**, not on the managed targets.
That means you write to it from the controller side via `delegate_to:
localhost` (or the implicit local action of `copy`/`template` with no host
context). For data collected from a remote host, fetch it back first.

### Minimal example

```yaml
- name: Save host facts as a job artifact
  ansible.builtin.copy:
    content: "{{ ansible_facts | to_nice_json }}"
    dest: "{{ lookup('env', 'ANSILUME_ARTIFACT_DIR') }}/facts-{{ inventory_hostname }}.json"
  delegate_to: localhost
```

Each host produces its own `facts-<hostname>.json` on the runner. Ansilume
captures all of them when the playbook finishes.

### Render a templated report

```yaml
- name: Render compliance report
  ansible.builtin.template:
    src: report.j2
    dest: "{{ lookup('env', 'ANSILUME_ARTIFACT_DIR') }}/report.html"
  delegate_to: localhost
  run_once: true
```

`run_once: true` is important when the output does not depend on the host —
it stops Ansible from writing the same file once per host.

### Fetch a file from a remote host

```yaml
- name: Pull /etc/os-release back to the runner
  ansible.builtin.fetch:
    src: /etc/os-release
    dest: "{{ lookup('env', 'ANSILUME_ARTIFACT_DIR') }}/{{ inventory_hostname }}/"
    flat: false
```

`fetch` already runs on the controller — no `delegate_to` needed. The
collector walks the directory recursively, so subdirectories work, but
file display names are flattened in the UI (the recorded `display_name`
is the relative path from the artifact root).

### Capture command output

```yaml
- name: Save free disk report
  ansible.builtin.shell: df -h
  register: df_out

- name: Persist as artifact
  ansible.builtin.copy:
    content: "{{ df_out.stdout }}"
    dest: "{{ lookup('env', 'ANSILUME_ARTIFACT_DIR') }}/disk-{{ inventory_hostname }}.txt"
  delegate_to: localhost
```

### Defensive pattern (env var may be unset in dev runs)

If you sometimes run the playbook outside Ansilume (e.g. locally for
testing), guard the artifact tasks so they only run when the variable
is set:

```yaml
- name: Save artifact only when running inside Ansilume
  ansible.builtin.copy:
    content: "{{ result | to_nice_yaml }}"
    dest: "{{ lookup('env', 'ANSILUME_ARTIFACT_DIR') }}/result.yaml"
  delegate_to: localhost
  when: lookup('env', 'ANSILUME_ARTIFACT_DIR') | length > 0
```

---

## What gets collected

| Rule | Behavior |
|------|----------|
| Plain files | Collected |
| Subdirectories | Walked recursively; `display_name` keeps the relative path |
| Symlinks | Skipped (logged as a warning) |
| Files outside `ANSILUME_ARTIFACT_DIR` (via path traversal) | Skipped |
| Files larger than `ARTIFACT_MAX_FILE_SIZE` | Skipped |
| Files past the per-job byte cap | Skipped, rest of run continues |
| Files past the global byte cap | Skipped, single warning per run |
| More than `maxArtifactsPerJob` (default 50) files | Truncated, warning |

MIME type is detected from the file extension first (`.json`, `.yaml`,
`.txt`, `.log`, `.xml`, `.csv`, `.html`, `.tar`, `.gz`, `.zip` …) and
falls back to PHP's `mime_content_type()` for unknown extensions.

---

## Where to find them

### Web UI

Open any finished (or running) job at **Jobs → click the job**. If the job
produced at least one artifact, an **Artifacts** card appears below the
log. Per row you get:

| Button | Action |
|--------|--------|
| **Preview** (text) | Inline `<pre>` block (text, JSON, XML, YAML — capped at 512 KB) |
| **Preview** (image) | Inline `<img>` for PNG / JPEG / GIF / WebP via `?inline=1` download |
| **Download** | Stream the single file with `Content-Disposition: attachment` |

PDFs are rendered inline in a sandboxed `<iframe>` on the job view. The
server response sets `Content-Security-Policy: default-src 'none';
object-src 'self'; plugin-types application/pdf; sandbox;` together with
`X-Content-Type-Options: nosniff` and `X-Frame-Options: SAMEORIGIN`. The
empty `sandbox` directive disables script execution, form submission,
top-navigation, and pointer-lock, so any JavaScript embedded in the PDF
cannot run. SVG is **not** previewable inline — it can carry executable
script and would be an XSS vector. SVG artifacts can still be downloaded
and opened locally.

The card header offers **Download All (zip)** when the job has more than
one artifact. The zip is created on demand into the system temp dir and
deleted at request shutdown — nothing is left behind on disk.

### REST API

| Endpoint | Purpose |
|----------|---------|
| `GET /api/v1/jobs/{id}/artifacts` | List artifacts (id, display_name, mime_type, size_bytes, previewable, image, created_at) |
| `GET /api/v1/jobs/{id}/artifacts/{aid}/download` | Stream the file (add `?inline=1` for image MIME types to render in `<img>`; ignored otherwise) |
| `GET /api/v1/jobs/{id}/artifacts/{aid}/content` | Inline content for previewable types (`415` for binary) |
| `GET /api/v1/jobs/{id}/artifacts/download-all` | Stream a zip of all artifacts |

The `image` boolean on each artifact tells UI clients whether they may
embed the file via `?inline=1`. The server enforces the same allowlist
even if a client sets `inline=1` on a non-image — the parameter is
ignored to prevent inlining attacker-controlled HTML/SVG.

The job detail response (`GET /api/v1/jobs/{id}`) includes an
`artifact_count` field so clients can decide whether to fetch the list at
all. Authentication uses the same API token / session that any other Job
endpoint requires; access is gated by `job.view` on the underlying job.

The full schema lives in `web/openapi.yaml`; the bundled Swagger UI
(`docker compose up -d swagger`, port `8088`) gives you a clickable
explorer.

---

## Storage layout

```
@runtime/artifacts/
├── job_42/
│   ├── 4d3a…b9.json         ← stored under a random name
│   └── e1f0…77.txt
├── job_43/
│   └── …
└── job_44/
```

The on-disk filename is randomised so collisions are impossible and the
original name cannot leak path information into the filesystem. The
**display name** in the UI is the original relative path the playbook
wrote, kept in the `job_artifact.display_name` column.

Permissions: directories `0750`, files `0640`. Owned by the same user that
runs the queue worker / runner.

---

## Configuration

All knobs live in `.env`:

```bash
# Per-file cap (any single artifact). Default 10 MB.
ARTIFACT_MAX_FILE_SIZE=10485760

# Per-job cap (sum across all artifacts produced by one run). 0 = unlimited.
# Default 50 MB.
ARTIFACT_MAX_BYTES_PER_JOB=52428800

# Global cap across all jobs. 0 = unlimited. Useful as a hard ceiling on
# total artifact storage.
ARTIFACT_MAX_TOTAL_BYTES=0

# Days to keep artifacts. 0 = forever (default — safe for upgrades).
# Expired artifacts are deleted by `php yii artifact/cleanup`.
ARTIFACT_RETENTION_DAYS=0

# Keep only the N most recent jobs that have artifacts. 0 = unlimited (default).
# Combined with ARTIFACT_RETENTION_DAYS as an OR rule: an artifact is removed
# if either trigger matches.
# Warning: when you reduce this from 0 to N, or lower an existing N, the next
# maintenance sweep will delete every artifact belonging to the older jobs.
# Pick a value with headroom.
ARTIFACT_MAX_JOBS_WITH_ARTIFACTS=0
```

Hard-coded defaults that are not env-tunable today:

- `maxArtifactsPerJob = 50` — guards against runaway loops creating
  thousands of tiny files.
- Storage path: `@runtime/artifacts` (resolves to
  `<basePath>/runtime/artifacts/`).

---

## Retention and cleanup

Two cleanup operations are exposed via the console:

```bash
# Delete artifacts older than ARTIFACT_RETENTION_DAYS days, plus any
# orphan files on disk that no longer have a matching DB row.
php yii artifact/cleanup

# Show storage stats: total bytes, artifact count, distinct job count,
# current retention setting.
php yii artifact/stats
```

Both actions are safe to re-run and idempotent. With
`ARTIFACT_RETENTION_DAYS=0`, the expiry sweep is a no-op and only
orphan removal runs.

Every removal — both expired records and orphan files — emits an
audit-log entry (`artifact.expired` / `artifact.orphan-removed`) with
`user_id=null` so operators can later trace what the maintenance job
deleted and why. Orphan entries store the file path in `metadata.storage_path`
because, by definition, no `job_artifact` row exists to point at.

### Retroactive global quota enforcement

When `ARTIFACT_MAX_TOTAL_BYTES` is set to a non-zero value and the current
total storage exceeds that limit, the next maintenance sweep trims whole jobs
oldest-first (by the latest `created_at` among each job's artifacts) until the
total is back under the ceiling. Each trim is audit-logged as
`artifact.quota_trimmed` with metadata including `job_id`, `artifact_count`,
`bytes_freed`, and `reason: 'global_quota'`, so the sweep is fully traceable.

Per-job limits (`ARTIFACT_MAX_BYTES_PER_JOB`) are not enforced retroactively
— they apply only during collection. Retroactively choosing which individual
files inside a completed job to remove would have unclear semantics and is
therefore deliberately excluded.

### Automatic scheduling

The bundled `schedule-runner` container also invokes
`php yii maintenance/run` once a minute. That command consults
`MaintenanceService`, which holds a Redis-backed cooldown per task and
only triggers the actual cleanup when the cooldown has expired. Tune
the cooldown via:

```bash
# Default 86400 (daily). Set to 0 to disable the scheduled sweep
# (you'll then need your own cron entry for `artifact/cleanup`).
MAINTENANCE_ARTIFACT_CLEANUP_INTERVAL=86400
```

Because the cooldown is set with SETNX semantics, two `schedule-runner`
instances launched accidentally in parallel cannot double-trigger a
sweep — the second one observes the existing cooldown key and skips.

---

## Troubleshooting

**“My playbook wrote a file but no artifact appears.”**

- Verify the file was written **into** `$ANSILUME_ARTIFACT_DIR` and not
  into a subdirectory of `/tmp` named like it. The variable must be
  expanded at runtime — check with a debug task:
  ```yaml
  - debug: msg="{{ lookup('env', 'ANSILUME_ARTIFACT_DIR') }}"
  ```
- Check the file size against `ARTIFACT_MAX_FILE_SIZE` and the per-job
  cap.
- Symlinks are intentionally skipped — copy the real file instead.

**“The file shows in the list but Preview returns 415.”**

The MIME type is not in the previewable allowlist (text/\*, JSON, XML,
YAML). Use **Download** instead. To make a custom type previewable,
extend `ArtifactService::PREVIEWABLE_TYPES`.

**“Download All times out for jobs with many large artifacts.”**

The zip is created synchronously in the request thread. Increase
PHP/nginx timeouts or download files individually via the API.

**“Disk fills up over time.”**

Set `ARTIFACT_RETENTION_DAYS` to a value other than 0 and ensure
`php yii artifact/cleanup` runs on a schedule. Use
`php yii artifact/stats` to monitor current usage.
