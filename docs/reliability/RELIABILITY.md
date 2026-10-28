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

The owner resolved the timing conflict on2026-10-27 (D-089): new affiliate rewards become spendable only after network approval, the existing default. Legacy missing policy evidence remains unknown; do not infer an old regime from the current setting. Future reconciliation must verify the applicable historical snapshots and source evidence without balancing writes or inventing rates/revenue. Existing authorized dual-admin corrections must be reused.

Queue wait/provider observations, current dependency health/operations overview, financial source checks, query/security audit across the listed domains, encrypted/offsite backup jobs, isolated restore proof and all ten full-card scenarios remain unfinished. Production database/hosting/backup access and staging do not exist in this session. Proposed15-minute RPO/four-hour RTO are unapproved targets, not promises. Senior developer configures deployment/providers/secrets and supplies actual production/offsite/restore evidence; no credentials need to be pasted into chat for main-code work.


## B22b prerequisite hardening: wallet scans and financial evidence

The existing wallet reconciliation now reads under the wallet lock, with a scan-start high-watermark, and retains one open balance-mismatch flag per wallet. Its original evidence remains unchanged; each check returns the latest differences separately. Repeat/concurrent checks do not generate duplicate flags, edit cached balances or append balancing entries. Existing authorized flag resolution still refuses a wallet that does not reconcile. This is a prerequisite fix, not the completed cross-source reconciliation runner.

New affiliate reward evaluations freeze `reward_timing` and `reward_terms_snapshot`. Approval uses the frozen terms even if the current rule or environment timing later changes. Crediting freezes a private `credit_evidence` record with the trusted processing source, network state, commission/currency, actual credit amount/unit, rule/timing evidence, ledger public reference and credit timestamp. Public API serializers do not expose this internal evidence automatically. Reward-rule monetary/effective terms cannot be overwritten or deleted; deactivation remains allowed and a new version expresses a new rate. New sponsorship obligations retain `fee_rate_bps`, with financial/source fields immutable while settlement/invoice status can still transition.

Migration `2026_10_27_120000_add_financial_evidence_snapshots.php` adds nullable fields only, with **no legacy backfill**. Legacy null timing/rate evidence is unknown; a referenced historical rule may help comparison but must not be presented as a newly verified original snapshot. Existing timing compatibility behavior is retained for historical/configuration cases; the owner-approved deployment setting for new rewards is `AFFILIATE_CREDIT_TIMING=on_network_approval`. No live rule, balance, commission percentage or provider activation was changed by implementing these fields. Sponsorship is an on-top fee, not an affiliate commission split. Points are never silently treated as EUR.

[Financial evidence fixture and actual HTTP reads](B22_FINANCIAL_EVIDENCE.json): isolated synthetic import shows1000commission minor units with700pending, zero spendable before approval; a later90% rule leaves the old approved credit at700 and gives a new conversion900. A deliberately injected400 cached-balance mismatch produces one flag across two checks while ledger row count remains unchanged. CLI evidence comes from canonical services and HTTP reads use a running Laravel server; no real provider/cash/revenue posting, automatic repair or full-platform reconciliation report is claimed. Rate-change, immutable-evidence, current/stale wallet and four-process flag tests are in the committed tests.


Two source-control regressions were reproduced and fixed during this boundary: increased approved commissions must cross the original review threshold again before credit, and non-EUR commissions must not silently become EUR wallet credit. Acceptance of unsupported currency now returns409 even for Super Admin; actual session/password-confirmed HTTP rejection and authorized increased-commission acceptance are captured. [Finding severity, fixes and regression evidence](FINANCIAL_FINDINGS.md). This does not complete the durable platform-wide reconciliation runner or the full security/financial audit.

Snapshot migration rollback removes original financial evidence. Stop reward processing and preserve that evidence before any production rollback; do not treat a schema rollback as financial correction. Only isolated fixture schemas were rolled back during verification.

## Durable wallet projection runs (B22b foundation, 2026-10-28)

`php artisan shopbonus:reconcile-financials --key=operator:2026-10-28 --limit=100`
starts or advances an OS-operator-only run. Keys are stable ASCII identities (1–100 characters), not secrets. Reuse the same key across retries. The command scans at most100 wallets per invocation. Each wallet is locked before financial aggregates; the check, existing canonical mismatch flag and cursor commit atomically. Multiple workers serialize on the run row and cannot double-count a source. A run captures an initial maximum wallet ID; new wallets belong in a later run. Completion may need one extra invocation after a full batch. Every-minute scheduling drains unfinished work before starting the next UTC-hour run. No new queue/provider dependency, route, package or financial correction is introduced.

`financial_reconciliation_checks` retains public source references, native unit/kind, cached projection, mismatch pairs `[expected, actual]`, existing public flag reference and observation time. Records are append-only through Eloquent and UPDATE/DELETE triggers on supported SQLite/MySQL engines. A later run adds new evidence instead of rewriting an old discrepancy. This is a sequence of per-wallet observations, not a global snapshot. Original financial ledgers and cached balances are never repaired by this command. Existing Super Admin wallet-flag/dual-admin correction workflows remain authoritative.

A failed invocation stops the run, retains committed checks/cursor and stores only the exception class. Investigate the underlying problem, then explicitly retry with the same key and `--resume`. Scheduler does not automatically retry failed runs. Process termination without an exception leaves the last committed cursor resumable. Command JSON reports execution separately from financial coverage: any observed discrepancy is CRITICAL; otherwise overall coverage is UNKNOWN. Empty scans and successful wallet arithmetic never establish affiliate/checkout/funding/sponsorship correctness; those modules and their admin report API remain pending. Existing wallet mismatch reporting/flags provide operator visibility; this foundation adds no duplicate incident channel.

Migration `2026_10_28_100000` adds runs/checks only. Rollback deletes reconciliation evidence: preserve it first and stop this scheduled command. No financial source history is backfilled or rewritten. No new HTTP operation, so existing OpenAPI contract and real examples remain unchanged.

Actual local command replay capture: `B22_WALLET_RUN_CAPTURE.json` (2026-10-28). The local run observed four wallet projections, zero mismatches and honestly reported overall UNKNOWN. This verifies local command/replay behavior, not production financial completeness.
