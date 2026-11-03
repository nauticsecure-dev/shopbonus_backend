# Deployment recovery acceptance — 2026-11-03

Status: **PARTIAL / deployment evidence NOT RUN**. Proposed financial RPO≤15minutes and RTO≤4hours remain **UNAPPROVED** pending owner adoption and measured infrastructure evidence. Local isolated restores cannot establish either objective.

## Implemented scheduling change

The opt-in backup task now attempts every5minutes, with the existing overlap lock retained. Default overdue alert is15minutes (minimum1minute), replacing120minutes with a30minute floor. Existing deployments must explicitly update any old BACKUP_OVERDUE_MINUTES override, rebuild config cache and restart scheduler/workers against the reviewed release. BACKUPS_ENABLED remains false until destination, key, resource budget and isolated verification are configured. Weekly verification remains an isolated archive restore, not an infrastructure drill.

A five-minute schedule is not a15-minute RPO guarantee. Long-running dumps, failed writes, missed scheduler runs and stale overlap locks can prevent fresh independently durable recovery points. Measure the snapshot's actual data cutoff and completion/durable-availability times, not just the last command attempt. Validate database size, dump runtime, media consistency, storage throughput, retention/cost and scheduler outage alerts before enablement. If measured logical backups cannot meet the adopted target, deploy independently retained database PITR/binlogs and demonstrate replay to the target. No PITR implementation is claimed here.

## Independent archive and key recovery

Senior infrastructure operator configures the existing private recovery disk against independently administered durable storage (a real mounted remote filesystem or an installed supported Laravel disk adapter). A path with “offsite” in its name is not proof of independence. Never point BACKUP_STORAGE_PATH at public storage. Confirm driver availability before selecting a disk; an S3 config entry alone does not install its adapter.

Required acceptance evidence, retained privately with a redacted reference in the release record:

1. Record release SHA, environment, storage provider/location/failure domain, encryption/retention policy and separate administrative access. Prove that primary-host loss does not remove archive access. Do not record keys or credentials in Git/chat.
2. Run `php artisan shopbonus:backup` on the deployed release after secure configuration. Record archive public reference, source snapshot cutoff, completion time, independent durable availability and integrity metadata. Verify retrieval from a separate recovery host using recovery-only access, not the primary host's mounted cache.
3. Escrow APP_KEY, independent BACKUP_ENCRYPTION_KEY, database/provider credentials and required infrastructure configuration separately from archives. Verify key-version identification and historical key retention across rotation; do not rotate away keys needed for retained archives.
4. On the recovery host, obtain both encryption keys through the documented restricted recovery process without relying on the lost host or its environment. An archive-only decrypt test using the original host's resident key is insufficient. Demonstrate failure with a wrong/missing key without exposing secret values.
5. Configure a separate isolated restore target with no customer/provider delivery. Restore the selected archive with `php artisan shopbonus:restore-isolated <archive-public-reference>` on the reviewed release. Compare immutable financial/ownership/source records, voucher state and private-media hashes. Delete private verification output after evidence capture. Confirm the target is not the primary application database.

`offsite_verified` remains false in backend status; this patch does not manufacture a verification flag from configured disk writes. Key/infrastructure verification has no automated positive attestation in the current status payload. Missing fields must not be treated as verified.

## Timed infrastructure drill

Use an approved isolated recovery environment. Record actual UTC timestamps for simulated primary-host loss, operator start, replacement compute/network/storage provisioning, release/config/key recovery, database/archive/PITR restore, reconciliation, queue/scheduler startup and usable service readiness. Restore the intended release and schema versions; confirm financial idempotency before allowing external effects. Keep provider/customer delivery disabled for the drill.

Measure RTO from incident to defined service readiness; measure RPO from incident to the latest recovered committed financial source cutoff. Include detection, operator access, provisioning and key retrieval time. Retain run logs, restore/hash/reconciliation results, failed steps, measured data loss and an independent verifier's evidence reference. Adopt the proposed targets only after approval and successful measured evidence; otherwise record the actual result and remediation. A successful local `restore-isolated` run alone does not pass this drill.

## Production queue acceptance

Current monitor observes configured database/Redis queues on the default queue connection. `RELIABILITY_MONITORED_QUEUES` defaults to `default,affiliate`; financial, notification, integration and translation work may share default or be assigned differently in deployment. Inventory actual producer connection/queue routing and Supervisor/systemd/container worker command lines before claiming coverage. Additional connections need separate monitoring; adding their queue names to the default connection does not cover them.

For every deployed queue/connection, record release SHA, job family, producer route, worker process configuration, retry-after/timeout and actual queue backend. Compare this inventory to the monitored queue list. Then execute approved synthetic, non-customer work and retain:

- Enqueue, worker start and completion evidence on the intended queue, with timestamps and queue name.
- Controlled worker pause/backlog: verify observed size, database ready-wait/stall warning and recovery after workers resume. Redis ready-wait/worker availability remain explicitly unknown in this implementation; use deployment-native telemetry to fill that gap.
- Controlled job failure/retry/exhaustion: verify failed-job observation, incident/alert delivery and recovery without duplicate financial/provider effects. Do not inject failing financial/customer jobs into production.
- Idle/delayed queue behavior: absence of recent starts is not a healthy-worker proof; delayed jobs must not be mistaken for a ready backlog.
- Scheduler failure detection and a monitored queue missing all workers. Record alert receipt and response, not merely API rendering.

Run `php artisan shopbonus:observe-operations` and inspect the authenticated operations report alongside actual worker/backend observations. Store redacted evidence references per queue. Until these checks execute against the deployed topology, production monitoring acceptance remains NOT RUN.
