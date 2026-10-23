# Verified Backend Progress

## Project status as of 2026-10-23

Greenfield Laravel 13.38 / PHP 8.4 API in this repository (decision D-001). The foundation (B01a API skeleton, B01b audit and error store) is implemented and tested. **No domain modules exist yet.** Statuses below come from committed code and commands actually run.

## B00 audit inventory (2026-10-23, Claude)

Audit method: checked this folder, every Laravel project under `/Users/kcoder/Projects` (`find -name artisan`), and the owner's GitHub account and org `JOPHAB-BASE-ORG` (`gh repo list`) for any Shopbonus code. Only the requirements pack existed: no `composer.json`, no git history, no migrations, no tests. `Projects/Answer24/answer24-backend` exists, but it is the separate Answer24 provider system and out of scope.

| Module | State | Evidence |
| --- | --- | --- |
| Laravel app/runtime | VERIFIED_WORKING | Laravel 13.38.0, PHP 8.4.17 locally; `composer.json` requires `php ^8.3`. CI matrix covers 8.3 and 8.4. |
| API foundation (versioning, errors, request ID, health, log redaction, CORS, rate limit) | VERIFIED_WORKING | B01a, commit `a51a9b6`. Tests in `tests/Feature/Api/*`, `tests/Feature/Logging/*`, `tests/Unit/Logging/*`. |
| Audit events / application error store | VERIFIED_WORKING | B01b. Tests in `tests/Feature/Audit/*`, `tests/Feature/Errors/*`. The PostgreSQL trigger variant is written but NOT RUN (no PG locally). |
| Auth (signup/login/OTP/recovery), Sanctum tokens/SPA | MISSING (Sanctum installed, `HasApiTokens` on `User`, `personal_access_tokens` migrated, no endpoints) | B02 |
| Workspaces / memberships / roles / isolation | MISSING | B03 |
| Provider adapters, orders, webhooks | MISSING | B04–B05 |
| Offers / voucher codes | MISSING | B06 |
| Answer24 | MISSING | B07 |
| Consumer profile/discovery | MISSING | B08 |
| Wallet ledgers | MISSING | B09 |
| Affiliate (Daisycon/TradeTracker) | MISSING; credit timing BLOCKED on OPEN-AFFILIATE-CREDIT-TIMING | B10 |
| Mollie checkout, merchant funding, Moneybird | MISSING; live money BLOCKED on OPEN-FINANCIAL-LEGAL | B11–B12 |
| Analytics, banners, CMS, admin, AI support | MISSING | B13–B17 |
| CI | PARTIAL | `.github/workflows/ci.yml` written but NOT RUN: no git remote configured. |
| Staging / deployment / backups | MISSING / UNKNOWN | No deployment config or target known. |

## Agent activity log

| Date | Agent | Task | Git SHA | Tests/results | Notes |
| --- | --- | --- | --- | --- | --- |
| 2026-10-23 | Requirements author | Requirements & handoff pack only | N/A | NOT RUN | |
| 2026-10-23 | Claude (Opus 5.5) | B00 audit | `7cc47a8` (pack commit) | N/A | Nothing to audit; greenfield chosen by owner |
| 2026-10-23 | Claude (Opus 5.5) | Scaffold | `48520b7` | 2 stock tests PASS | `laravel/laravel` 13 + `install:api` (Sanctum) |
| 2026-10-23 | Claude (Opus 5.5) | B01a API foundation | `a51a9b6` | `php artisan test`: 28 passed / 196 assertions on SQLite, same on local MySQL; `pint --test` PASS; `phpstan` (level 6) 0 errors; `composer audit` no advisories | Real HTTP captures in `docs/api/README.md` |

### B01a detail

- **Delivered:** `routes/api.php` (v1 group + `throttle:api`); `app/Exceptions/ApiExceptionRenderer.php` (envelope for 400/401/403/404/405/409/415/419/422/429/500/503); `app/Exceptions/ApiException.php`; `app/Http/Middleware/AssignRequestId.php` (global), `ForceJsonResponse.php` (api group); `app/Services/Health/HealthChecker.php` + `Api/V1/HealthController.php`; `app/Logging/SensitiveDataProcessor.php` + `RedactSensitiveData.php` tap on all channels in `config/logging.php`; `config/cors.php` allowlist; `config/shopbonus.php`; `phpstan.neon`, `pint.json`, `.github/workflows/ci.yml`; `docs/api/openapi.yaml` + `docs/api/README.md`.
- **Negative/security tests:** no leakage of model names, policy messages, SQL or paths in 403/404/500; unsafe client request IDs replaced; readiness detail hidden without the token; 429 with `Retry-After`; log redaction verified through a real channel write.
- **Verified outside tests:** readiness against real local MySQL + Redis returned `{"database":"ok","cache":"ok","redis":"ok"}`. A 503 was captured with a missing database. Log lines carry `request_id` via Context.
- **Not done in B01a (moved to B01b/B03):** audit event store, failed-job alerting, the roles/permissions pattern (needs users/workspaces, so B03), workspace-resolution middleware (B03).

### B01b detail

- **Delivered:** migrations `2026_10_23_130000_create_audit_events_table` (with UPDATE-blocking trigger) and `2026_10_23_130100_create_application_errors_table`; models `AuditEvent` (refuses update/delete) and `ApplicationError`; enums `AuditOutcome`, `AuditCategory`, `ErrorSource`; `App\Services\Audit\AuditLogger` (`record` / `recordSafely`, reads `request_id`, `webshop_id`, `real_actor_user_id` from Context); `App\Http\Middleware\AuditAccessDenials` (api group, audits 401/403); `App\Services\Errors\ErrorRecorder` (singleton, re-entrancy guard, redaction, SQL template without bindings, fingerprint); report callback in `bootstrap/app.php` for HTTP faults; `App\Listeners\RecordFailedJob` (auto-discovered, `JobFailed`); alert hook event `App\Events\ApplicationErrorRecorded` (no listener yet, see B16). Added `HasApiTokens` to `User`.
- **Negative/security tests:** raw `UPDATE` rejected by the trigger on SQLite and MySQL; model update/delete refused; audit or error-store outage never changes the API response; query-string PII and IDs never stored (route template only); bound SQL values never stored; `ApiException`/422/404 not recorded as faults; IP stored only as a keyed hash.
- **Known gaps:** console-command failures are not yet recorded (only HTTP and queue). Faults thrown in global middleware before routing are logged but not stored. No retention/pruning yet (B18). No admin read API yet (B16).

## How to update

For each changed module include: evidence (paths/test names), actual test command/result, commit SHA, resolved/unresolved issues, permissions impact, migration impact, and exact next action. Do not use percentages without an auditable denominator.
