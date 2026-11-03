# Launch readiness — backend (Trello #22)

2026-11-03 current review: **NO-GO** pending independent E2E/provider evidence, native installation artifacts and deployed staging checks. [Independent acceptance register](INDEPENDENT_ACCEPTANCE.md) lists the unresolved gates. Runtime inventory now distinguishes466 route entries,467 non-HEAD method/path operations and408 unique paths. Backend CI provenance is recorded separately below; it never substitutes for those release gates.

2026-11-02 follow-up: production remains NO-GO. New affiliate rewards require
network approval **and recorded settlement**; the business journey now verifies
both gates before credit/checkout. Focused SQLite regressions: 109 PASS / 8,767
assertions. Frontend analytics/retired-endpoint evidence and outstanding release
steps: sibling `shopbonus_frontend/docs/platform-launch-acceptance.md`.
Sanitized local configuration check: [LAUNCH_CHECK_2026-11-02.json](LAUNCH_CHECK_2026-11-02.json)
(9 PASS, 12 FAIL, 4 WARN, 2 MANUAL); this is not a check of a production host.

Card: https://trello.com/c/SxfIv4Ir. This file covers what the Laravel backend can prove itself, how to deploy and roll back, and what still needs people, live providers or the frontend. **The card is not Done:** the frontend/design QA, the live provider runs and the deployment itself happen outside this repository.

## 1. Evidence in the repository

| Card requirement | Backend evidence |
|---|---|
| Full business journey, steps 1–17 | `tests/Feature/Journey/BusinessJourneyTest.php`: one continuous run through the real HTTP API, services, queue commands and database. Merchant registers and verifies email, proves DNS ownership, accepts the agreement (auto-approved; Super Admin review covered in `AgreementAndReviewTest`), connects a platform and funds rewards through a Mollie top-up verified server-side. The merchant then publishes two offers with code inventory. A verified paid order appears once in the Order Sheet; a pending order never becomes contactable. After the one-hour window Answer24 receives one minimal, signed request. The consumer registers independently and claims the reward. An affiliate visit gives pending, non-spendable credit, approval alone leaves it pending; separately recorded settlement releases it once despite network retries (D-109). The consumer buys a points voucher (idempotent; the code appears in My Vouchers). A later paid order redeems the reward code, a problem report opens, and the Super Admin sees the activity. Audit chain and wallet reconciliation are clean. Synthetic only: DNS, mail, Answer24, Mollie and the affiliate network report. |
| Cross-module integrity | The same test asserts no duplicate orders, Answer24 requests, affiliate rewards or code allocations, no negative balances and no orphaned vouchers. Module tests cover races (`Concurrent*Test`), replay and idempotency. |
| API & permission audit | `docs/release/API_INVENTORY.md`: 466 runtime route entries /467 non-HEAD operations across408 unique paths, with access, accepted roles, permission, scope, step-up, rate-limit middleware and audit classification. Regenerate with `php artisan shopbonus:api-routes --markdown` into a temporary file and replace the checked-in inventory only on exit0; access/permission drift fails generation. `tests/Feature/Api/RoleAccessMatrixTest.php` enumerates routes and checks protected-route middleware denials/permissions for those roles (including Super Admin with/without MFA) with unknown resource IDs. It does not prove each successful business action or public/provider flow. Non-members get the deliberate audited 404. `OpenApiCoverageTest` keeps the contract and captured examples in step. |
| Security hardening | `SecurityHeaders` middleware sends nosniff, `X-Frame-Options: DENY` and `Referrer-Policy: no-referrer` on every response, plus HSTS on HTTPS (`HSTS_MAX_AGE`). `TRUSTED_PROXIES` lets HTTPS be detected behind a TLS-terminating proxy, for secure cookies, HSTS and URLs. Covered by `tests/Feature/Security/SecurityHeadersTest.php`. CSRF, session rotation, email verification, admin MFA + step-up, CORS allowlist and signed webhooks already have their own tests. |
| Dependencies | 2026-11-03: `composer audit --format=json --no-interaction` returned0 advisories and0 abandoned packages. Versions remain pinned in composer.lock; re-run against the actual release build. |
| Launch configuration | `php artisan shopbonus:launch-check [--json]`: named configuration checks; prints only names and outcomes, never values. FAIL blocks launch, WARN needs an owner decision, MANUAL is out of configuration. Covered by `tests/Feature/Reliability/LaunchCheckTest.php`. |
| Quality Center (#37 backend) | `tests/Feature/Qa/QualityCenterTest.php` and `docs/qa/B30_QUALITY_CENTER_HTTP_CAPTURE.json`: inventory per Git SHA, approved coverage, JUnit import with strict status rules, encrypted evidence, findings with independent retest, advisory GPT analysis, read-only production probe. |
| Monitoring, backups, reconciliation | Card #30 work: `docs/reliability/RELIABILITY.md`, `COMPLETION.md` and `B22_*` evidence (API/query monitoring, financial reconciliation, encrypted backups, isolated restore). |

## 2. Deployment runbook

1. **Before:** run `composer audit`, then the full test suite on MySQL (`DB_CONNECTION=mysql php artisan test`), then confirm CI is green on the release commit.
2. **Backup:** run `php artisan shopbonus:backup` and note the archive ULID. Restore it once into the isolated target: `php artisan shopbonus:restore-isolated <archive>`. Do not continue unless the restore succeeds.
3. **Deploy:**
   1. `composer install --no-dev --optimize-autoloader`
   2. `php artisan down --render=errors::503` (only if a migration needs it)
   3. `php artisan migrate --force`
   4. `php artisan config:cache && php artisan route:cache && php artisan event:cache`
   5. `php artisan queue:restart`
   6. `php artisan up`
4. **Verify:**
   - `php artisan shopbonus:launch-check` must have no FAIL.
   - `GET /api/v1/health` and `/api/v1/health/ready` must answer.
   - `php artisan shopbonus:api-routes` must exit 0.
   - `php artisan shopbonus:audit-verify` must pass.
   - `php artisan shopbonus:qa-discover --sha=<release sha>` (set `APP_RELEASE_SHA` too) so the Quality Center marks new and changed routes; CI then imports the staging run with `php artisan shopbonus:qa-import`.
   - Make sure the scheduler (`php artisan schedule:work`, or cron with `schedule:run`) and at least one queue worker are running.
5. **Watch for the first hour:**
   - Admin incidents (`/api/v1/admin/incidents`)
   - Failed integration events (`/api/v1/admin/integrations/failed-events`)
   - `shopbonus:observe-operations` output
   - Five-minute backup attempts and their completed independently durable recovery points; see `docs/reliability/DEPLOYMENT_RECOVERY_ACCEPTANCE.md`

## 3. Rollback

- **Code:** redeploy the previous release, then run the cache commands and `queue:restart` again.
- **Schema:** roll back only migrations that the matching docs mark as safe to roll back. Several of them drop evidence; see `docs/reliability/RELIABILITY.md` and the migration notes in `docs/HANDOFF.md`. **Stop reward and payment processing first, and preserve the evidence.** A schema rollback never corrects financial records; compensating actions do.
- **Data:** restore from the encrypted backup into the isolated target first (`shopbonus:restore-isolated`). Promote it only after the owner decides to.

## 4. Still open before go-live (not provable from this repository)

- **Frontend & design QA** (Hassan): screens, responsive layouts, NL/EN, accessibility, CMS/legal/cookie banner, and that every visible action calls these APIs.
- **Live provider runs** (senior developer, staging): real installed stores for the supported platforms, real Mollie test/live payments, real Answer24 contract and endpoint, real affiliate network credentials.
- **Owner/legal decisions:** D-109 timing is resolved: new rewards require approval plus recorded settlement; retain historical timing snapshots. Open legal/activation decisions include Answer24 lawful basis, live Mollie enablement (`MOLLIE_ALLOW_LIVE`).
- **Load test on staging:** run it against the production-like PHP-FPM/MySQL/Redis stack. The local PHP server is single-threaded and is not a valid load target. The MySQL query benchmark is in `docs/reliability/B22_MYSQL_BENCHMARK.json`.
- **Infrastructure:**
  - TLS certificate and redirect
  - `TRUSTED_PROXIES`
  - off-site backup storage and `BACKUP_RESTORE_CONNECTION`
  - mail domain (SPF/DKIM)
  - monitoring and alerting destinations

## 5. Current backend regression provenance

Full CI [41603012663](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/41603012663) passed all3jobs for head `b3016cdfee86c90dae0103239cbfcd121ecdbbe4`. Actual tested merge SHA `e44ca9a409fd54759b2adc921a94283fb7355816` has the same Git tree `4086f225f86eca1043e6df98e4826c03ecbb3c85` as that head (checked against GitHub commit metadata and local Git). SQLite1252passed+27skipped/21145assertions; MySQL1275passed+4skipped/21236assertions. Lint/static PASS. CI imports are labelled mocked and carry real execution time/SHA; they are not independent native-provider or staging runs. The new inventory-renderer change follows this baseline and requires its own focused checks/new CI; do not label an untested later revision green.

See [backend CI evidence record](BACKEND_CI_2026-11-03.json) for artifact provenance. GitHub retention is finite; preserve sanitized release evidence in the approved private evidence store before expiry. Raw logs, credentials and customer data do not belong in the release bundle.
