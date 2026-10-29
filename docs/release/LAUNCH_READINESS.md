# Launch readiness — backend (Trello #22)

Card: https://trello.com/c/SxfIv4Ir. This file covers what the Laravel backend can prove itself, how to deploy and roll back, and what still needs people, live providers or the frontend. **The card is not Done:** the frontend/design QA, the live provider runs and the deployment itself happen outside this repository.

## 1. Evidence in the repository

| Card requirement | Backend evidence |
|---|---|
| Full business journey, steps 1–17 | `tests/Feature/Journey/BusinessJourneyTest.php`: one continuous run through the real HTTP API, services, queue commands and database. Merchant registers and verifies email, proves DNS ownership, accepts the agreement (auto-approved; Super Admin review covered in `AgreementAndReviewTest`), connects a platform and funds rewards through a Mollie top-up verified server-side. The merchant then publishes two offers with code inventory. A verified paid order appears once in the Order Sheet; a pending order never becomes contactable. After the one-hour window Answer24 receives one minimal, signed request. The consumer registers independently and claims the reward. An affiliate visit gives pending, non-spendable credit, and approval credits it once despite a network retry. The consumer buys a points voucher (idempotent; the code appears in My Vouchers). A later paid order redeems the reward code, a problem report opens, and the Super Admin sees the activity. Audit chain and wallet reconciliation are clean. Synthetic only: DNS, mail, Answer24, Mollie and the affiliate network report. |
| Cross-module integrity | The same test asserts no duplicate orders, Answer24 requests, affiliate rewards or code allocations, no negative balances and no orphaned vouchers. Module tests cover races (`Concurrent*Test`), replay and idempotency. |
| API & permission audit | `docs/release/API_INVENTORY.md`: all 362 routes with access, permission, scope, step-up, rate limit and audit category. Regenerate with `php artisan shopbonus:api-routes`, which fails on drift. `tests/Feature/Api/RoleAccessMatrixTest.php` calls every `/api/v1` route directly as anonymous, suspended, unverified, consumer, owner, manager, employee and Super Admin (with and without 2FA). It asserts the exact denial code, and that each role gets exactly its permissions. Non-members get the deliberate audited 404. `OpenApiCoverageTest` keeps the contract and captured examples in step. |
| Security hardening | `SecurityHeaders` middleware sends nosniff, `X-Frame-Options: DENY` and `Referrer-Policy: no-referrer` on every response, plus HSTS on HTTPS (`HSTS_MAX_AGE`). `TRUSTED_PROXIES` lets HTTPS be detected behind a TLS-terminating proxy, for secure cookies, HSTS and URLs. Covered by `tests/Feature/Security/SecurityHeadersTest.php`. CSRF, session rotation, email verification, admin MFA + step-up, CORS allowlist and signed webhooks already have their own tests. |
| Dependencies | 2026-10-29: `composer audit`: 0 advisories, 0 abandoned packages. PHP 8.4.17, Laravel 13.38.0. Re-run before each release. |
| Launch configuration | `php artisan shopbonus:launch-check [--json]`: 25 checks; prints only names and outcomes, never values. FAIL blocks launch, WARN needs an owner decision, MANUAL is out of configuration. Covered by `tests/Feature/Reliability/LaunchCheckTest.php`. |
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
   - The hourly backup succeeding

## 3. Rollback

- **Code:** redeploy the previous release, then run the cache commands and `queue:restart` again.
- **Schema:** roll back only migrations that the matching docs mark as safe to roll back. Several of them drop evidence; see `docs/reliability/RELIABILITY.md` and the migration notes in `docs/HANDOFF.md`. **Stop reward and payment processing first, and preserve the evidence.** A schema rollback never corrects financial records; compensating actions do.
- **Data:** restore from the encrypted backup into the isolated target first (`shopbonus:restore-isolated`). Promote it only after the owner decides to.

## 4. Still open before go-live (not provable from this repository)

- **Frontend & design QA** (Hassan): screens, responsive layouts, NL/EN, accessibility, CMS/legal/cookie banner, and that every visible action calls these APIs.
- **Live provider runs** (senior developer, staging): real installed stores for the supported platforms, real Mollie test/live payments, real Answer24 contract and endpoint, real affiliate network credentials.
- **Owner/legal decisions:** affiliate credit timing (OPEN-AFFILIATE-CREDIT-TIMING, D-089), Answer24 lawful basis, live Mollie enablement (`MOLLIE_ALLOW_LIVE`).
- **Load test on staging:** run it against the production-like PHP-FPM/MySQL/Redis stack. The local PHP server is single-threaded and is not a valid load target. The MySQL query benchmark is in `docs/reliability/B22_MYSQL_BENCHMARK.json`.
- **Infrastructure:**
  - TLS certificate and redirect
  - `TRUSTED_PROXIES`
  - off-site backup storage and `BACKUP_RESTORE_CONNECTION`
  - mail domain (SPF/DKIM)
  - monitoring and alerting destinations
