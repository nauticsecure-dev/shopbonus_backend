# #30 backend implementation and operational handoff

2026-10-28. Scope: Laravel main code and locally executed acceptance. Production rollout, independent offsite proof, actual provider settlement and adopted recovery objectives remain senior-owned acceptance. Do not mark the entire Trello card Done from local evidence.

## Financial reconciliation

New runs have scope_version2 and initial per-source ID watermarks/cursors. Existing scope_version1 wallet-only runs are never relabelled. Each source observation locks its canonical row before reading its related financial snapshot; no stale platform-wide repeatable-read transaction wraps a scan. Source checks and cursor/counters commit atomically. One invocation handles at most100 sources; scheduler advances interrupted work each minute. Failed runs require investigated explicit resume. Multiple workers serialize on the run; unique run/source keys and immutable checks prevent duplicate evidence. Source rows created after initial watermarks belong in the next run. A changing platform is not a single point-in-time snapshot.

| Source | Executed local invariants |
| --- | --- |
| Wallet | Cached available/reserved/pending vs ledger/lots/pending credits/active reservations; original discrepancy flag reused; no balance repair. |
| Affiliate | Exact credit/type/owner/unit/source identity; click/user/shop/program/connection attribution; duplicate positive credit; immutable historical terms/version/rounding/cap; approval timing and trusted sync/import/admin source only; postback never establishes credit; commission drift; original reversal plus unrecovered flag. |
| Checkout | Price=wallet+provider parts, native EUR/nonnegative; completed voucher owner/shop/offer/wallet-purchase origin; captured reservation and unique canonical debit; verified matching provider payment; paid-unfulfilled needs review. Issuance is not redemption. |
| Funding | Paid top-up matches merchant funding wallet/native EUR/source/amount/ledger/paid timestamp/provider reference; duplicate or unpaid credits flagged; uncertain provider creation is UNKNOWN and never resubmitted here. |
| Sponsorship | Original reward+on-top fee=total, frozen fee-rate rounding, source voucher/shop/order, wallet reservation/capture/release, settlement requires redemption evidence. This is distinct from affiliate commission shares. |
| Ledger | Arithmetic/nonnegative projections; orphan affiliate/top-up credits; duplicate/incorrect typed purchase capture; reversal ownership/reference/sign; adjustment actor/reason. Untyped historical credit is UNKNOWN, never silently approved or corrected. |

Exact native EUR commission split is retained in credit_evidence allocation: €10 approved commission/70% rule =>700consumer+300platform_unrecognized. The existing immutable credit snapshot is reused rather than duplicating an accounting ledger. cash_settlement_verified=false: network approval is not cash receipt, VAT treatment or recognized revenue. Points never imply a money conversion. Missing legacy rates/policy/evidence stay UNKNOWN; no backfill from today's settings. Aggregate financial status remains UNKNOWN without independent settlement/accounting coverage; a known discrepancy is CRITICAL. Source coverage OBSERVED means checks executed, EMPTY means no applicable rows, not provider correctness.

Findings reuse existing incidents and canonical wallet flags. No new correction endpoint or automatic balancing entry exists. Correct through existing Super Admin/MFA/password/OTP/dual-admin wallet adjustment, affiliate review and checkout/dispute workflows. Original financial/source history remains intact. D-089 keeps new rewards spendable only after network approval. B22-F005 fixes a reproduced zero-approved-commission state bug; it closes the original pending reward without credit.

APIs: GET/POST `/api/v1/admin/reconciliation`, GET `/api/v1/admin/reconciliation/{run}`, POST `/api/v1/admin/reconciliation/{run}/resume`, GET `/api/v1/admin/operations-overview`. Run timestamps and elapsed_including_scheduling_ms disclose scheduling delay instead of pretending CPU execution time. Read pagination25/max100, page>=1; source-check filter matched/discrepancy/unknown. All require active verified Super Admin plus normal admin MFA and prohibit support impersonation. Writes additionally require current SPA password+OTP+CSRF and existing3/minute diagnostics limiter. UUID request_key is scoped to the admin; replay returns original run. Resume requires investigated failure and reason5–500chars; other states409. Trigger/resume audit uses existing immutable audit chain. Requests never accept a wallet owner, source table or correction amount. Responses use public references, no-store and no credentials/voucher secrets. Bounded list serialization avoids per-run refresh queries. Actual 19HTTP captures include401/403/404/409/419/422/423/429/500, successful/replayed trigger, list/detail/resume/overview. See OpenAPI and API README for actual captured examples.

## Operations and monitoring

Existing metadata-only HTTP monitoring remains opt-in; no Pulse/Horizon/Telescope/auth stack is added. Existing health checker probes configured database/cache/Redis, with redacted failure classes. New overview observes default background queue size; database ready wait excludes delayed jobs. JobProcessing retains only an enqueue-age number/time for the configured default queue; enqueue age includes intentional delays and is labelled separately from ready wait. Redis eligible-job wait and worker liveness remain UNKNOWN without proof. Default queue stall threshold300seconds, clamped60–3600 via QUEUE_STALL_SECONDS. Job payloads, names, recipients and provider bodies are not retained.

Every-minute shopbonus:observe-operations records a scheduler heartbeat, evaluates critical dependency/ready-queue/finance/backup states into existing cooldown incidents, and removes at most10000 observations older than24hours. Heartbeat older than5minutes is CRITICAL; absent is UNKNOWN. This heartbeat only proves the observation command ran, not every scheduler task or external uptime. Persisted affiliate errors are WARNING indicators, never independent provider probes. Opt-in API_MONITORING_ENABLED also records allowlisted Laravel HTTP provider labels/status/available transfer duration, never URL/path/query/body/header/exception message. Latest1000 observations over5minutes yield per-provider request/failure/mean-observed-latency counts; connection failure is status0, not invented HTTP500. Default3failures (PROVIDER_FAILURE_THRESHOLD, clamped1–100) is CRITICAL; positive lower counts WARNING. SOAP/unobserved services and independent availability remain UNKNOWN. Existing failed_jobs counts/technical incidents remain authoritative. Failure to read an operational component is CRITICAL; it is not displayed healthy. Database outage alerts cannot be durably stored in that same unavailable database: an external uptime/dependency monitor is still required at deployment.

Backup status includes latest backup/restore result,30day observed success percentage and last verified restore. Disabled/unconfigured is UNKNOWN; enabled missing/failed/overdue backup or latest failed restore is CRITICAL. A successful configured-disk backup remains WARNING until independent offsite/recovery acceptance, not a false whole-system HEALTHY.

## Encrypted backup and isolated recovery

Operator CLI only:

```
php artisan shopbonus:backup
php artisan shopbonus:restore-isolated <ARCHIVE_ULID>
php artisan shopbonus:verify-latest-backup
```

BACKUPS_ENABLED defaults false. Senior configures BACKUP_ENCRYPTION_KEY as an independent base64-encoded random32-byte secret, escrows it separately from archives and APP_KEY, selects private BACKUP_DISK/destination and enables the scheduler. Never put real keys in source/chat. ext-sodium is now explicit in Composer. No additional vendor package was installed. Default local recovery disk is private, not claimed offsite. A mounted private offsite directory or an installed/configured Laravel filesystem adapter can supply destination storage; real access/adapter/provider acceptance remains senior-owned. BACKUP_STORAGE_PATH must never be public web storage.

Hourly scheduled encrypted backups and weekly isolated verification are wired behind enablement, with overlap locks. Default retention14days (1–365), overdue120minutes (minimum30); max input512MiB (hard5GiB), max20000files (hard100000). Configure limits against actual database/media volume and disk budget before enabling. Hourly logical backups do not meet a15-minute RPO; use infrastructure PITR/binlogs if that objective is approved. No RPO/RTO promise is made.

SQLite uses a consistent VACUUM INTO snapshot. MySQL uses mysqldump --single-transaction --quick --skip-lock-tables --hex-blob, requires InnoDB tables and no concurrent schema migration/DDL, and includes triggers. Client/mysql executables must be installed by deployment. Private temporary client option files are0600 within0700 work directories, excluded from archives and removed in finally; source TLS CA is forwarded with VERIFY_IDENTITY. The helper never exposes database passwords in process arguments. Source DB exports and media are temporary private files and removed on normal failure/success; abrupt OS termination requires operator cleanup of abandoned private recovery-work directories after confirming no command is active.

Media allowlist: storage/app/public plus private cms, banners, avatars and public-profiles. Voucher/code/financial/account/source records and database migrations are in the DB snapshot. .env, APP_KEY, backup key, private local-integration credentials, arbitrary private files and provider configuration are not bundled. Restore code/config from the reviewed git release and recover credentials separately. Inventory additional hosting/media/infrastructure stores before rollout; unknown hosting assets are not falsely claimed backed up. Media may change during a dump; exact per-file hashes detect concurrent copy changes, but cross-database/media snapshot consistency still requires an upload freeze/application snapshot procedure at deployment.

Archive is a versioned length-bounded manifest and file stream encrypted with sodium XChaCha20-Poly1305 secretstream; final authentication tag and EOF are mandatory. The implementation follows the [PHP secretstream API](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-push.php). It verifies full authentication and every file checksum before publication, then reads stored encrypted bytes back and verifies their SHA256 before recording success. Restores authenticate the complete stream before unpacking; reject truncation, wrong key, trailing data, oversized frames/files, traversal/duplicate paths and symlink media. Private fresh directories and fixed allowlisted member paths prevent archive overwrite of active files. Failed attempts record exception class only, not stderr/source values; failures and retention results append immutable recovery events. Retention processes at most100 successful expired archives per pass and retains event tombstones.

SQLite restores into a fresh private recovery-restores directory, runs integrity/foreign-key checks and records wallet/ledger/voucher counts and wallet/voucher-ownership hashes. No application/jobs/providers start against restored data. MySQL restore requires an explicitly configured separate connection/server: reject the active connection, same host/port and same server UUID even through an alias. It creates a random shopbonus_restore_* database, imports there, measures core financial/voucher hashes, and drops that verification database in finally. A dedicated restore account/server with no production access and disabled event scheduler is required. MySQL import executes trusted authenticated SQL/triggers; never use an untrusted key/archive or a restore account with production access. MySQL scripts do not auto-promote a restored database. Restored plaintext files from an explicit restore-isolated command remain privately available for operator inspection; verify-latest-backup removes its private verification output.

Local proof B22_MYSQL_RECOVERY_PROOF.json used two separate local MySQL26.7 servers, synthetic wallets/one owned voucher, encrypted archive225268bytes, backup917ms/restore3660ms at capture time. Wallet/voucher hashes matched and media matched; isolated source/restore databases, server and private files were removed. This is local evidence, not production-size recovery time or MySQL8-specific disaster acceptance. SQLite automated restore/wrong-key/truncation/traversal/symlink tests run in both database CI jobs using isolated SQLite files.

MySQL consistency limitations follow the [official mysqldump documentation](https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html): transactional tables and no concurrent DDL are required. Deployment must confirm exact server/client/privileges/TLS behavior and independently run the restore procedure on its target version.

## Schema, performance and security evidence

Migrations2026_10_28_110000 (versioned source state),120000 (operational metadata),130000 (append-only recovery events),140000 (filtered report pagination index). Rollback removes scope/operational/recovery evidence and report index: preserve first, pause commands and archive original proof. Isolated MySQL down/up passed; SQLite new migrations exercised in every isolated capture/recovery fixture and automated suite. Main apply is recorded in PROGRESS/HANDOFF.

B22_RECONCILIATION_QUERY_PLANS.json records10000synthetic checks/20runs, actual projected report fields, twenty measured reads before/after and actual EXPLAIN plans. The filtered run/status/id index removes its filesort. Default unfiltered run sorting showed no material plan change; an unnecessary second index was removed. Report list avoids N+1 refreshes. Measurements are local noise-sensitive evidence, not a production SLA. Existing source_type/source_ref, wallet-owner, ledger wallet/idempotency and primary/FK indexes are reused. Append-only triggers and per-source unique constraints remain enforced.

| Security concern | Existing/new executable evidence |
| --- | --- |
| Consumer/workspace IDOR, financial scope, code secrecy | Wallet/Checkout/Funding/Vouchers/Workspaces feature suites; public IDs, existing policies and canonical services reused. |
| Super Admin/MFA/OTP/password/support/CSRF/rate limits | Admin security suites, OperationsAndReconciliationApiTest and actual HTTP401/403/419/423/429. No new auth bypass. |
| Untrusted callbacks/duplicate effects | Affiliate postback signature/trust boundaries, duplicate-source scanner test, existing payment/funding/webhook replay and multi-process wallet tests. |
| Immutable finance/audit/findings | Existing ledger/audit seal tests; FinancialReconciliationTest raw DB update denial and original flag retention; recovery events protected by UPDATE/DELETE triggers. |
| Input/SQL/path/secret handling | Request validation/allowlisted source types, bound queries, public response mapping, private encrypted archives, authenticated traversal rejection and no arbitrary restore target. |
| Redirect/SSRF/upload/prompt/Reverb | Existing Affiliate/Onboarding/CMS/Banners/Support/Admin feature suites; no new scraper, redirect or LLM tool. |
| Dependencies/runtime | Pint, PHPStan L6, Composer audit/platform check plus full SQLite/MySQL suites and exact-code CI; actual versions recorded, no claim about unseen production host. |

B22-F001/F002/F005 have reproduced regressions and fixes. No assumption of compromise or broad security certification is made. Existing audit logger captures actor/real actor/scope/request/action/outcome and redacts metadata; trigger/resume uses it, report evidence remains admin-only. Deployment must set retention/access and independent alert delivery. No customer email/message is sent by this task.

## Ten card acceptance mappings

| Card scenario | Main-code/local evidence | External acceptance still required |
| --- | --- | --- |
| 1. €10/70-30 | Affiliate frozen-rule test:700credit+300immutable unrecognized allocation; source inspector matched | Actual network cash settlement/accounting recognition policy, no fictional revenue posting |
| 2. Duplicate | Existing import replay1credit; injected duplicate detected; no correction; run/process race dedupes checks | Real provider repeat deliveries |
| 3. Rate change | Frozen700vs new900; original snapshots preserved; sponsorship3000vs1000bps stays separate | Approved deployment policy/config |
| 4. Ledger mismatch | Injected1000ledger/1400cache retains exact finding, repeated scans1flag, no silent correction; real HTTPdetail | Production alert/correction operating procedure |
| 5. API monitoring | Existing opt-in route metrics, timeouts408/504 and5xx/latency incidents with immutable evidence | Real traffic/independent unobserved timeout and uptime monitoring |
| 6. Audit | Existing commission/settings/voucher workflows; new run/resume chained audit and redacted captures | Deployment audit retention/access/alert routing |
| 7. DB integrity | SQLite/MySQL suites, four-worker races, source FK/ownership checks, query plans and bounded pages | Production-size load/schema/host verification |
| 8. Isolation | Existing tenant/user/admin/MFA/support negative suites and new API denials | Deployed origins/session/TLS verification |
| 9. Encrypted restore | Automated SQLite integrity tests; actual separate-server MySQL wallet/voucher/media hashes matched | Actual offsite location/access/retention and production-version restore drill |
| 10. Failure/recovery | Failed run resumes without duplicate checks, ready-queue stall/scheduler incidents, disabled/missing data UNKNOWN, invalid backup rejection | Real infrastructure outage/failover/full application restart exercise and adopted RPO/RTO |

## Senior rollout sequence

1. Review and deploy the recorded git SHA; install required PHP extensions/client tools. Preserve old evidence and apply migrations before scheduled commands.
2. Configure existing API monitoring/queue/scheduler/Redis/TLS/origins/normal admin MFA. Run workers with retry_after greater than job timeouts. External monitor must detect total DB/process/scheduler loss.
3. Keep affiliate new rewards on_network_approval. Configure real providers without exposing credentials; complete legal/revenue/points policy and live acceptance separately.
4. Escrow APP_KEY and independent backup key through restricted credential recovery; select private offsite storage and exact restore-server connection. Verify client TLS, limits, InnoDB/no-DDL snapshot window, media/infrastructure inventory and retention cost.
5. Run backup, readback verification and isolated restore on the deployed server version; verify financial/voucher ownership hashes/media and independent offsite availability. Archive results, investigate incidents and only then enable hourly/weekly schedules.
6. Adopt achievable RPO/RTO with infrastructure/cost evidence; proposed15minutes/4hours remain unapproved targets. Run a controlled full recovery drill without provider/customer delivery and record real elapsed recovery/data loss.

Hassan may integrate the five documented admin APIs. Whole #30 stays pending deployment/operational acceptance; main-code completion is recorded only after final suites/CI pass.
