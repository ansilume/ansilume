# Failed jobs that a later run recovered

Date: 2026-11-03. A customer's feature request, passed on by the product
owner, who asked for it to be built as described.

## Intent

The customer's words, translated:

> Failed jobs should no longer appear prominently on the dashboard when a
> later job of the same job template succeeded. Job 124 shows as failed,
> but 136 succeeded later. One would think the template has a problem, but
> it was just a caching glitch of the playbook.

The dashboard should point at what still needs attention. A failure that a
later run of the same job template already put right is history, not an
open problem.

## Definition

A job is **recovered** when both hold:

- its status is a terminal failure (`Job::terminalFailureStatuses()`:
  failed, timed out);
- a job of the same job template succeeded that **started after the
  failure finished** and was **no dry run** (`check_mode` off).

The recovering job is the first such job by start time. Canceled and
rejected jobs are no failures and stay as they are.

Designer's calls:

- "Later" is the rule the analytics already use for the Mean Time To
  Recovery (`AnalyticsService::computeMttr()`: the first succeeded job of
  the same template that started after the failure finished). It is not a
  greater job ID. With overlapping runs, a failure that ended after a
  success therefore stays an open failure, as it is the newest outcome.
  Both places use one shared definition.
- A dry run checks and changes nothing, so it recovers nothing. MTTR stops
  counting dry runs as recoveries too, so the two stay one rule. The
  release notes name that change of the analytics numbers.
- Limit, inventory and survey answers are not compared, as the request
  says: "a later job of the same job template succeeded".

## Changes

- **Dashboard, "Failed Jobs (last 24h)":**
  - lists only the failures of the last 24 hours that were not recovered;
  - recovered failures of that window leave the red panel. Instead a muted
    line names how many there were, with a link to the job list showing
    them;
  - when every failure was recovered, the panel itself disappears and only
    that line remains.
- **Dashboard, "Jobs Today":** the card turns red only for a failure of
  today that was not recovered.
- **Dashboard, chart:** it keeps counting failures per day. It is history.
- **Job page** of a recovered job: a note that a later run of the same job
  template succeeded, linking to it ("A later run of this job template
  succeeded: #136").
- **Job list:**
  - a recovered failed job shows a muted "recovered by #136" next to its
    status;
  - a filter "recovered: yes/no" for failed jobs, which the dashboard's
    link uses.
- **REST API:**
  - job resources (`GET /api/v1/jobs`, `GET /api/v1/jobs/{id}`) gain
    `recovered_by_job_id`: an integer for a recovered failure, otherwise
    `null`;
  - `GET /api/v1/jobs` takes `recovered=1|0`, which selects the failures
    that were or were not recovered;
  - OpenAPI updated.
- **Scoping:** unchanged. The recovering job belongs to the same job
  template and thus the same project, so whoever sees the failure may see
  it.

## Constraints

- The lookup must stay cheap for the dashboard and for API pages, with
  one query per page and no N+1. Add a composite index on `job` by
  migration if the query needs it, for example (`job_template_id`,
  `status`, `started_at`).
- The new filter follows its endpoint. The filters of `GET /api/v1/jobs`
  and the web job list (`JobSearchForm`) ignore an invalid value by design,
  as the spec documents ("Every filter is optional, and none is refused").
  So `recovered` takes `0` or `1` and ignores anything else, and the spec
  says so.

## Tests

- **Integration:**
  - the recovery rule: same template, succeeded, started after the failure
    finished;
  - a success that started before the failure finished, overlapping it,
    does not count;
  - a dry run does not count, another template does not count;
  - timed out counts; canceled does not;
  - MTTR uses the same rule;
  - the dashboard's queries;
  - the API field and filter, including invalid values.
- **E2E (Playwright):**
  - the dashboard hides a recovered failure from the red panel and shows
    the muted line;
  - the job page shows the note;
  - the list shows the badge and filters;
  - per role, viewer included, as the dashboard is visible to all.

## Docs

- Where the dashboard or job statuses are described (README features,
  docs).
- A note in the release section of `docs/updating.md`.
