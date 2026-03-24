# Monitoring

Ansilume exposes a `/metrics` endpoint for external monitoring systems. No authentication is required — restrict access via network policy or reverse-proxy rules if needed.

## Endpoints

| URL | Format | Use case |
|-----|--------|----------|
| `GET /metrics` | Prometheus OpenMetrics | Prometheus, Grafana, VictoriaMetrics |
| `GET /metrics?format=json` | JSON | Custom dashboards, scripts, Datadog, Zabbix |
| `GET /health` | JSON | Load balancer probes, Docker healthchecks |

## Prometheus setup

Add a scrape target to your `prometheus.yml`:

```yaml
scrape_configs:
  - job_name: ansilume
    static_configs:
      - targets: ['ansilume:8080']
    metrics_path: /metrics
    scrape_interval: 30s
```

## Available metrics

### Infrastructure health

| Metric | Type | Description |
|--------|------|-------------|
| `ansilume_database_up` | gauge | Database reachable (1) or down (0) |
| `ansilume_database_latency_ms` | gauge | Database probe latency in ms |
| `ansilume_redis_up` | gauge | Redis reachable (1) or down (0) |
| `ansilume_redis_latency_ms` | gauge | Redis probe latency in ms |

### Jobs

| Metric | Type | Labels | Description |
|--------|------|--------|-------------|
| `ansilume_jobs_total` | gauge | — | Total number of jobs |
| `ansilume_jobs_by_status` | gauge | `status` | Jobs by status: pending, queued, running, succeeded, failed, canceled, timed_out |
| `ansilume_jobs_avg_duration_seconds` | gauge | — | Average duration of jobs finished in the last hour |
| `ansilume_jobs_with_changes` | gauge | — | Jobs where at least one task made a change |

### Ansible task results

| Metric | Type | Labels | Description |
|--------|------|--------|-------------|
| `ansilume_tasks_total` | gauge | — | Total task results recorded |
| `ansilume_tasks_by_status` | gauge | `status` | All-time task results: ok, changed, failed, skipped, unreachable |
| `ansilume_tasks_last_1h` | gauge | `status` | Task results from the last hour only |

### Host statistics

| Metric | Type | Labels | Description |
|--------|------|--------|-------------|
| `ansilume_host_results_total` | gauge | `result` | Aggregated PLAY RECAP counters: ok, changed, failed, skipped, unreachable, rescued |
| `ansilume_hosts_unique` | gauge | — | Number of unique hosts seen across all jobs |
| `ansilume_hosts_with_changes` | gauge | — | Unique hosts that had at least one change |
| `ansilume_hosts_with_failures` | gauge | — | Unique hosts that had at least one failure |

### Runners

| Metric | Type | Description |
|--------|------|-------------|
| `ansilume_runners_total` | gauge | Total number of registered runners |
| `ansilume_runners_online` | gauge | Runners that checked in within the last 120 seconds |
| `ansilume_runners_offline` | gauge | Registered runners that are not responding |

### Workers and queue

| Metric | Type | Description |
|--------|------|-------------|
| `ansilume_workers_alive` | gauge | Number of alive internal worker processes (Redis heartbeat) |
| `ansilume_workers_stale` | gauge | Workers that stopped sending heartbeats |
| `ansilume_queue_pending` | gauge | Jobs waiting to be picked up |
| `ansilume_queue_running` | gauge | Jobs currently executing |

## Example Grafana queries

```promql
# Job failure rate (last 5 minutes)
rate(ansilume_jobs_by_status{status="failed"}[5m])

# Queue depth
ansilume_queue_pending + ansilume_queue_running

# Change ratio (tasks with changes vs total tasks, last hour)
ansilume_tasks_last_1h{status="changed"} / ignoring(status) ansilume_tasks_last_1h{status="ok"}

# Hosts with failures
ansilume_hosts_with_failures

# Worker availability
ansilume_workers_alive

# Runner availability
ansilume_runners_online / ansilume_runners_total

# Alert: all runners offline
ansilume_runners_online == 0 and ansilume_runners_total > 0
```

## JSON format

`GET /metrics?format=json` returns the same data as a JSON object:

```json
{
  "health": {
    "database_up": true,
    "database_latency_ms": 0.82,
    "redis_up": true,
    "redis_latency_ms": 0.31
  },
  "jobs": {
    "total": 142,
    "by_status": {
      "pending": 0,
      "queued": 1,
      "running": 2,
      "succeeded": 120,
      "failed": 15,
      "canceled": 3,
      "timed_out": 1
    },
    "avg_duration_1h_sec": 34.2
  },
  "tasks": {
    "total": 2840,
    "by_status": { "ok": 2100, "changed": 420, "failed": 80, "skipped": 200, "unreachable": 40 },
    "last_1h": { "ok": 150, "changed": 30, "failed": 5, "skipped": 12, "unreachable": 0 }
  },
  "hosts": {
    "totals": { "ok": 5000, "changed": 1200, "failed": 90, "skipped": 300, "unreachable": 10, "rescued": 5 },
    "unique_hosts": 48,
    "hosts_with_changes": 32,
    "hosts_with_failures": 6,
    "jobs_with_changes": 85
  },
  "workers": { "alive": 2, "stale": 0 },
  "runners": { "total": 4, "online": 2, "offline": 2 },
  "queue": { "pending": 1, "running": 2 }
}
```

## Health endpoint

`GET /health` returns a structured health check for load balancers, Docker healthchecks, and uptime monitors.

### Response

- **HTTP 200** — all checks pass, status `"ok"`
- **HTTP 503** — at least one critical check failed, status `"degraded"`

### Checks performed

| Check | What it does | Failure means |
|-------|-------------|---------------|
| `database` | Executes `SELECT 1` against the database | DB connection lost or server down |
| `redis` | Writes a test key with 5s TTL | Redis connection lost or server down |
| `worker` | Checks for alive workers (Redis heartbeat) and online runners (DB `last_seen_at`) | No workers or runners available to execute jobs |

### Example response

```json
{
  "status": "ok",
  "checks": {
    "database": { "ok": true, "latency_ms": null },
    "redis": { "ok": true },
    "worker": { "ok": true, "count": 3 }
  },
  "workers": {
    "count": 1,
    "workers": [
      {
        "worker_id": "app-container:1",
        "hostname": "app-container",
        "started_at": 1711234567,
        "seen_at": 1711234590,
        "age_s": 12
      }
    ],
    "runners": {
      "total": 4,
      "online": 2,
      "offline": 2
    }
  },
  "queue": {
    "pending": 0,
    "running": 1
  }
}
```

### Notes

- The `worker.count` in `checks` is the sum of alive internal workers and online runners.
- The `workers` section breaks these down separately: `workers` are PHP-FPM processes tracked via Redis heartbeats, `runners` are registered runner agents tracked via `last_seen_at` in the database.
- Runners are considered offline if they haven't checked in within 120 seconds.
- No authentication required — restrict access via network rules if the endpoint should not be public.
