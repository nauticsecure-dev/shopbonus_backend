# Verified Backend Progress

## Project status as of 2026-10-23

Greenfield Laravel 13.38 / PHP 8.4 API in this repository (decision D-001). The foundation (B01) and identity (B02: registration, email codes, sessions, recovery) are implemented and tested. **No domain modules exist yet.** Statuses below come from committed code and commands actually run.

## B00 audit inventory (2026-10-23, Claude)

Audit method: checked this folder, every Laravel project under `/Users/kcoder/Projects` (`find -name artisan`), and the owner's GitHub account and org `JOPHAB-BASE-ORG` (`gh repo list`) for any Shopbonus code. Only the requirements pack existed: no `composer.json`, no git history, no migrations, no tests. `Projects/Answer24/answer24-backend` exists, but it is the separate Answer24 provider system and out of scope.

| Module | State | Evidence |
| --- | --- | --- |
| Laravel app/runtime | VERIFIED_WORKING | Laravel 13.38.0, PHP 8.4.17 locally; `composer.json` requires `php ^8.3`. CI matrix covers 8.3 and 8.4. |
| API foundation (versioning, errors, request ID, health, log redaction, CORS, rate limit) | VERIFIED_WORKING | B01a, commit `a51a9b6`. Tests in `tests/Feature/Api/*`, `tests/Feature/Logging/*`, `tests/Unit/Logging/*`. |
| Audit events / application error store | VERIFIED_WORKING | B01b. Tests in `tests/Feature/Audit/*`, `tests/Feature/Errors/*`. The PostgreSQL trigger variant is written but NOT RUN (no PG locally). |
| Auth (signup/login/OTP/recovery), Sanctum SPA + bearer | VERIFIED_WORKING | B02. Tests in `tests/Feature/Identity/*`. Real captures in `docs/api/README.md` → Auth. Staging p95 NOT RUN. |
| Workspaces / memberships / roles / isolation | PARTIAL: core VERIFIED_WORKING (B03a); team, onboarding, verification and agreements MISSING (B03b–d) | `tests/Feature/Workspaces/*` |
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
| 2026-10-23 | Claude (Opus 5.5) | B01b audit & error store | `088fb20` | `php artisan test`: 47 passed / 271 assertions on SQLite and on local MySQL; Pint PASS; PHPStan L6 0 errors; `migrate:rollback --step=2` + `migrate` on MySQL OK (trigger dropped and recreated) | Owner-requested `docs/CHECKLIST.md` added |
| 2026-10-23 | Claude (Opus 5.5) | B02 identity | `c95e1e4` | `php artisan test`: 107 passed / 690+ assertions on SQLite and on local MySQL; Pint PASS; PHPStan L6 0 errors; `composer audit` clean; `migrate:rollback --step=4` + `migrate` OK; full real HTTP flow captured against MySQL + Redis sessions | Fortify + Sanctum SPA |
| 2026-10-23 | Claude (Opus 5.5) | B03a workspace core | B03a commit | 125 tests / 880 assertions pass on SQLite and MySQL; Pint, PHPStan L6, composer audit clean; real captures against MySQL + Redis | Tenant isolation, permissions, switcher |

### B01a detail

- **Delivered:** `routes/api.php` (v1 group + `throttle:api`); `app/Exceptions/ApiExceptionRenderer.php` (envelope for 400/401/403/404/405/409/415/419/422/429/500/503); `app/Exceptions/ApiException.php`; `app/Http/Middleware/AssignRequestId.php` (global), `ForceJsonResponse.php` (api group); `app/Services/Health/HealthChecker.php` + `Api/V1/HealthController.php`; `app/Logging/SensitiveDataProcessor.php` + `RedactSensitiveData.php` tap on all channels in `config/logging.php`; `config/cors.php` allowlist; `config/shopbonus.php`; `phpstan.neon`, `pint.json`, `.github/workflows/ci.yml`; `docs/api/openapi.yaml` + `docs/api/README.md`.
- **Negative/security tests:** no leakage of model names, policy messages, SQL or paths in 403/404/500; unsafe client request IDs replaced; readiness detail hidden without the token; 429 with `Retry-After`; log redaction verified through a real channel write.
- **Verified outside tests:** readiness against real local MySQL + Redis returned `{"database":"ok","cache":"ok","redis":"ok"}`. A 503 was captured with a missing database. Log lines carry `request_id` via Context.
- **Not done in B01a (moved to B01b/B03):** audit event store, failed-job alerting, the roles/permissions pattern (needs users/workspaces, so B03), workspace-resolution middleware (B03).

### B01b detail

- **Delivered:** migrations `2026_10_23_130000_create_audit_events_table` (with UPDATE-blocking trigger) and `2026_10_23_130100_create_application_errors_table`; models `AuditEvent` (refuses update/delete) and `ApplicationError`; enums `AuditOutcome`, `AuditCategory`, `ErrorSource`; `App\Services\Audit\AuditLogger` (`record` / `recordSafely`, reads `request_id`, `webshop_id`, `real_actor_user_id` from Context); `App\Http\Middleware\AuditAccessDenials` (api group, audits 401/403); `App\Services\Errors\ErrorRecorder` (singleton, re-entrancy guard, redaction, SQL template without bindings, fingerprint); report callback in `bootstrap/app.php` for HTTP faults; `App\Listeners\RecordFailedJob` (auto-discovered, `JobFailed`); alert hook event `App\Events\ApplicationErrorRecorded` (no listener yet, see B16). Added `HasApiTokens` to `User`.
- **Negative/security tests:** raw `UPDATE` rejected by the trigger on SQLite and MySQL; model update/delete refused; audit or error-store outage never changes the API response; query-string PII and IDs never stored (route template only); bound SQL values never stored; `ApiException`/422/404 not recorded as faults; IP stored only as a keyed hash.
- **Known gaps:** console-command failures are not yet recorded (only HTTP and queue). Faults thrown in global middleware before routing are logged but not stored. No retention/pruning yet (B18). No admin read API yet (B16).

### B02 detail

- **Delivered:** Fortify headless (`config/fortify.php`, `FortifyServiceProvider`): login only for active accounts with timing-equalised failures, per-endpoint rate limiters, a reset URL pointing to `FRONTEND_URL`. Actions `CreateNewUser` (atomic user, legal acceptances and consent; race-safe 422), `ResetUserPassword` (revokes tokens, deletes DB sessions), `UpdateUserPassword` (keeps this session, others fail Sanctum's password-hash check). Migrations `2026_10_23_1400xx_*` (users identity columns + public ULID, `email_verification_challenges`, `legal_acceptances`, `consent_records`). Services `EmailVerificationCodes`, `LegalConsents`, `DestinationResolver`. Middleware `EnsureAccountIsActive` (`account.active`, also on Fortify routes), `EnsureEmailIsVerified` (replaces `verified`), `ThrottleIdentityEndpoints` (+ Lockout event), `RejectAuthenticatedJsonRequests` (replaces `guest`). Endpoints `GET /api/v1/me`, `POST /api/v1/auth/email/verify-code`, `POST /api/v1/auth/email/resend-code`. Queued + encrypted NL/EN notifications `EmailVerificationCode`, `ResetPasswordLink`. Listeners `SendVerificationCodeOnRegistration`, `AuditIdentityEvents`. `AppendOnly` trait shared by audit/legal/consent models.
- **Card #4 E2E mapping:** 1 → `RegistrationTest::test_e2e1_*`; 2 → `test_e2e2_*`; 3 → B03 (needs memberships); 4 → `EmailVerificationCodeTest` (invalid/expired/superseded/attempts/replay/race/429/no-log); 5 → `SessionTest` (CSRF cookie, 419 with enforcement on, session rotation, cookie flags, logout → 401); 6 → `PasswordRecoveryTest`; 7 → `RegistrationTest::test_e2e7_*` + DB unique test; 8 → `RegistrationTest::test_e2e8_*`, `AccountStatusTest`; 9 → frontend-owned (UI, a11y, NL/EN screens); 10 → `OpenApiCoverageTest` (access registry, default deny), envelope tests, audit tests, `composer audit`, local timings below.
- **Session tests use a real cookie jar** (`tests/Concerns/BrowsesLikeSpa.php`). Laravel's test client otherwise neither sends cookies on JSON requests nor resets cached users, which made the first logout test pass for the wrong reason. The jar exposed a real bug: the current device was signed out after a password change, because the session hash was stored under the wrong key.
- **Local timings** (2026-10-23, `php artisan serve` single process, MySQL + Redis, mail queued): signup n=5 p50 251 ms / p95 256 ms (dominated by bcrypt cost 12); `GET /api/v1/me` n=100 p50 12 ms / p95 14 ms. Card targets (staging p95 <400 ms / <250 ms) are **NOT RUN** on staging.
- **Known gaps:** no MFA (B16); no email change flow (B08); no public bearer-token issuance; registration reveals existing emails (OPEN-SIGNUP-ENUMERATION); "deleted" accounts are not anonymised (D-013); validation messages are English only (NL in B15).

### B03a detail

- **Delivered:** migration `2026_10_23_150000_create_webshops_table` (`webshops`, `webshop_memberships` unique per user+webshop, `users.current_webshop_id`, `platform_role_assignments`); enums `WebshopStatus`, `MembershipRole`, `MembershipStatus`, `WorkspacePermission`, `PlatformRole`, `EcommercePlatform`; models `Webshop`, `WebshopMembership` (server-side effective permissions), `PlatformRoleAssignment`; `CurrentWorkspace` (request-scoped), middleware `ResolveWorkspace` (`workspace`) and `RequireWorkspacePermission` (`workspace.can:<perm>`); `WorkspaceService`; `WorkspaceController` (index/store/show/update/select); `/me` roles and memberships; `DestinationResolver` adds `workspace` and `admin`; console `shopbonus:grant-super-admin`; `WebshopFactory`.
- **Isolation evidence:** `WorkspaceTest::test_other_tenants_webshop_is_indistinguishable_from_a_missing_one_and_audited` (same 404 body for foreign/unknown/garbage IDs; GET/PATCH/select all blocked; 5 `workspace.access_denied` audits). Removed/suspended memberships lose access immediately; `current` re-validated on each use; permission denials audited with `webshop_id`.
- **Card mapping:** #2 acceptance "one owner creates five webshops" and "one user has different roles in different workspaces"; #3 F1 (create part), F13 (switching scope), F16–F17 (scoping, blocked + audited); #4 E2E 3 (`test_card4_e2e3_*`).
- **Registry:** `OpenApiCoverageTest` now also checks `x-permission` against `workspace.can:` middleware.

## How to update

For each changed module include: evidence (paths/test names), actual test command/result, commit SHA, resolved/unresolved issues, permissions impact, migration impact, and exact next action. Do not use percentages without an auditable denominator.
