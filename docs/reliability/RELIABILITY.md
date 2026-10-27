# Trello #30: reliability implementation

Card: https://trello.com/c/6xHzjOwY. Full original requirements are preserved in `requirements/TRELLO_CARDS.md`. **The whole card is in progress.** B22a supplies observed HTTP/query monitoring and threshold incidents. Financial source reconciliation, queue/provider/dependency observations, production schema/security evidence, encrypted backups and disaster recovery remain separate boundaries.

## Existing infrastructure reused

- Sanctum, active/verified identity, Super Admin MFA and support impersonation restrictions.
- `IncidentRecorder`, append-only incident occurrences, audited incident assignment/resolution and existing incident APIs. Existing security-denial recording remains unchanged; new monitoring alerts do not fire merely for ordinary401/403.
- `ErrorRecorder` and final failed-job events, database/cache/Redis readiness checks, scheduler locks, immutable audit and wallet reconciliation/flags. These are not presented as queue wait time, request latency or verified backups.
- No Pulse/Horizon/Telescope dependency is installed in this repository. This slice adds no package/dashboard/auth stack. Hosting/infrastructure compatibility and deployment of specialist monitoring tools remain unverified.

## API and measurement scope

`GET /api/v1/admin/monitoring?minutes=5` is read-only global operational metadata for an active verified Super Admin with normal MFA. Support impersonation is blocked. `minutes` is1–60. No customer/workspace identifiers or raw observations are exposed. Existing API throttling applies.401/403/422/429/500 paths have executed evidence;409/419 are not new domain outcomes for this GET. Existing mutation APIs retain their own CSRF/conflict controls.

Each enabled API/auth request records one metadata-only observation during HTTP termination: method, registered route template (or `unregistered`), rendered status, monotonic elapsed microseconds and database query count/time/slow-query count. No actual URI values, query strings, SQL/bindings, headers, payloads, IPs, user/workspace IDs or request IDs are retained. A scoped accumulator resets on each request and ignores its own write. Auth includes Fortify and CSRF discovery; Reverb auth under `/api` is included. Non-API pages are excluded. Timing starts in global middleware and ends before the observation write; it is not a browser round trip, process bootstrap benchmark or external uptime probe.

Writes fail open with a fixed technical log message and exception class, preserving the original response. Fatal process deaths, client/network disconnects and timeouts that never render cannot be observed through this mechanism. `observed_timeouts` means rendered408/504 only. Absence of records never proves availability. Configure external liveness probes and log-based telemetry failure alerts in deployment; they are not claimed implemented here.

The report uses bounded SQL window queries, exact nearest-rank P50/P95/P99 and average duration across disclosed samples. Routes are grouped by registered template and method. The sample-bound count uses the time index without loading payload columns; percentile windows share ordering. No per-route query loop or N+1 is used. The report discloses sampling truncation and returns UNKNOWN for disabled/empty/insufficient/partial observations. CRITICAL observed breaches take precedence over sparse-route UNKNOWN; HEALTHY applies only to sufficient observed HTTP samples in that window, not every route or the entire platform. Quiet/unobserved endpoints are not inferred healthy. Queue wait, provider failure details, dependency availability and backups explicitly remain UNKNOWN in this report. Current readiness and existing job-error/incident APIs remain available separately.

## Configuration and scheduler

All configuration is operational, non-secret and bounded in code. No business credit/commission rule is changed.

| Environment key | Default | Effective bounds/meaning |
| --- | --- | --- |
| `API_MONITORING_ENABLED` | false | Opt in after migration and workload/storage review. |
| `API_MONITORING_SAMPLE_LIMIT` |50000|100–50000 latest observations; exceeding limit makes the report partial/UNKNOWN. |
| `API_MONITORING_RETENTION_HOURS` |24|1–72hours; scheduler removes older rows in batches. |
| `API_MONITORING_MINIMUM_SAMPLES` |20|1–100000 per route/method before threshold classification. |
| `API_MONITORING_ERROR_PERCENT` |2|0.1–100%; strictly greater triggers5xx breach. |
| `API_MONITORING_P95_MS` |1000|1–60000ms; strictly greater triggers latency breach. |
| `API_MONITORING_SLOW_QUERY_MS` |100|At least1ms; only duration/count, never SQL text. |

Deploy migration `2026_10_27_110000_create_api_observations.php` before enabling observation collection. It creates the indexed metadata table and adds nullable JSON `monitoring_evidence` to existing append-only incident occurrences. Rollback drops observations/evidence; preserve operational evidence before a production rollback. Local SQLite and MySQL apply/rollback/reapply were executed.

```bash
API_MONITORING_ENABLED=true php artisan serve
php artisan shopbonus:monitor-api
php artisan schedule:run
```

The existing scheduler runs evaluation every minute with an overlap lock. Evaluation always uses a five-minute window. Each route+breach uses the existing incident fingerprint/row lock and a300-second cooldown, including concurrent workers. Persistent breaches retain a new immutable occurrence after cooldown; resolved/ignored incidents reopen on a fresh post-cooldown breach. Assignment/resolution remain the existing audited Super Admin workflow. Occurrence `monitoring_evidence` stores only numeric counts/P95, the actual window and thresholds, viewable at `GET /api/v1/admin/incidents/{incident}`. No financial correction or external email dispatch is performed. The command prints breach counts (including cooldown-suppressed breaches), not a fabricated notification-delivery count.

Retention deletes at most ten10000-row batches per command. Sustained traffic above that cleanup rate requires deployment sizing/review; bounded batches do not promise an exact physical retention deadline. Report output caps500 route/method groups and reports truncation. Storage cost is one insert per observed request plus one indexed metadata row, a scoped DB timing callback, bounded reads and scheduled cleanup. Monitoring is opt-in so production load/storage and external alert delivery can be configured explicitly.

## Executed local evidence

- [Real HTTP captures](B22_HTTP_CAPTURE.json): isolated SQLite/file cache+sessions/array mail, real Laravel server, explicitly synthetic10000-row metric dataset. Admin MFA disabled only for that isolated capture; production/main settings unchanged.401/403/422/429/500, UNKNOWN/observed reports, immutable incident evidence and original health200 despite observation storage failure were called. Ten report runs and actual index plan are included.
- [MySQL before/after benchmark](B22_MYSQL_BENCHMARK.json): same10000-row synthetic workload in rolled-back test transactions; ten full report runs each. Median approximately704ms before and261ms after covering-index count/shared ordered-window optimization. Host/load variance applies; no production SLA or representative customer traffic is claimed. SQLite ten-run report timings are in the capture artifact. The time-window index is present in both query plans.
- Tests: `tests/Feature/Reliability/*` cover exact quantiles/error/timeout semantics, numeric evidence, retention, disabled/absent/insufficient/partial observations, safe storage failures, SQL/path privacy, query reset, admin/MFA validation, rendered500/504 and a real four-process MySQL cooldown race. Existing admin security tests also cover support impersonation for this route. Full/CI results are recorded in PROGRESS/CHECKLIST rather than guessed here.

## Remaining card acceptance

The card's immediate-credit wording conflicts with the existing open affiliate timing decision. Monitoring does not activate or change that flow. Future reconciliation must verify the applicable historical snapshots and source evidence without balancing writes or inventing rates/revenue. Existing authorized dual-admin corrections must be reused.

Queue wait/provider observations, current dependency health/operations overview, financial source checks, query/security audit across the listed domains, encrypted/offsite backup jobs, isolated restore proof and all ten full-card scenarios remain unfinished. Production database/hosting/backup access and staging do not exist in this session. Proposed15-minute RPO/four-hour RTO are unapproved targets, not promises. Senior developer configures deployment/providers/secrets and supplies actual production/offsite/restore evidence; no credentials need to be pasted into chat for main-code work.
