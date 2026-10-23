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
| Workspaces, onboarding, agreements, admin review | VERIFIED_WORKING (B03a–d); staging p95 NOT RUN | `tests/Feature/Workspaces/*` |
| Provider adapters and webhooks | PARTIAL: framework + WooCommerce/Shopify/Shopbonus-v1 adapters VERIFIED_WORKING against fixtures (B04); live provider behaviour NOT VERIFIED; 7 platforms BLOCKED | `tests/Unit/Integrations/*`, `tests/Feature/Integrations/*` |
| Normalised orders, Order Sheet | VERIFIED_WORKING (B05) against signed fixture webhooks; live stores NOT VERIFIED | `tests/Feature/Orders/*` |
| Offers / voucher codes | PARTIAL: offers with versioned terms + fee preview VERIFIED_WORKING (B06a); code inventory MISSING (B06b) | `tests/Feature/Offers/*` |
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
| 2026-10-23 | Claude (Opus 5.5) | B03a workspace core | `2fe8db6` | 125 tests / 880 assertions pass on SQLite and MySQL; Pint, PHPStan L6, composer audit clean; real captures against MySQL + Redis | Tenant isolation, permissions, switcher |
| 2026-10-23 | Claude (Opus 5.5) | B03b team management | `ec20038` | 144 tests / 1130 assertions pass on SQLite and MySQL; Pint, PHPStan L6, composer audit clean; real captures against MySQL + Redis | Invitations, member management |
| 2026-10-23 | Claude (Opus 5.5) | B03c business profile & ownership | `b230455` | 199 tests / 1444 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; real captures (Google 200 and DNS/file success NOT live) | SSRF-safe verification |
| 2026-10-23 | Claude (Opus 5.5) | B03d agreements, review, activation | `5acdfbd` | 213 tests / 1772 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; real captures incl. session-based admin step-up and evidence round-trip | B03 complete |
| 2026-10-23 | Claude (Opus 5.5) | B04 connector framework | `820c6b5` | 255 tests / 2046 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; webhook signatures exercised with openssl-computed HMACs against the real server | No live provider tested |
| 2026-10-23 | Claude (Opus 5.5) | B05 orders & Order Sheet | `7e391be` | 270 tests / 2190 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; end-to-end capture via signed webhooks | Out-of-order safe ingestion |
| 2026-10-23 | Claude (Opus 5.5) | B06a offers | `c6485dc` | 279 tests / 2355 assertions pass on SQLite and MySQL; Pint, PHPStan L6, audit clean; real captures | Versioned terms, 30% fee preview |

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

### B03b detail

- **Delivered:** migration `2026_10_23_160000_create_webshop_invitations_table`; `WebshopInvitation`, `InvitationStatus`; `TeamService` (invite/resend/cancel/findOpen/accept, updateMember/suspend/reactivate/remove, with all authority rules in one place); `InvitationController`, `MemberController`; `WorkspaceInvitationMail` (queued, encrypted, NL/EN, token in the URL fragment); routes under `workspaces/{workspace}` (members, invitations, membership) plus public `invitations/preview` and verified `invitations/accept`; limiters `invitation-create`, `invitation-preview`.
- **Card #16 mapping:** E2E 7 → `TeamTest::test_e2e7_*` (existing and new user accept; reused, expired, cancelled, unknown and mismatched tokens fail); E2E 8 → `test_e2e8_*` (owner never grantable, manager limits, no grants beyond own, other webshop's members and invitations give 404, denials audited); E2E 9 membership half → `test_e2e9_*` (removal and suspension block immediately; current selection cleared; final owner protected). Webshop suspension half → B03d.
- **Bugs found and fixed while building:** the recipient-mismatch audit was written inside a transaction and rolled back with it (now audited before the transaction); new invitations returned `resend_count: null` (seen only in the real capture).

### B03c detail

- **Delivered:** migration `2026_10_23_170000_create_onboarding_tables` (webshop ownership columns, `webshop_business_profiles` with encrypted contact fields, `webshop_verifications` with encrypted evidence); models `BusinessProfile`, `WebshopVerification`; `DomainName` normaliser; `Network\{HostResolver, SystemHostResolver, PublicAddressGuard, WebsiteFileFetcher, SafeWebsiteFileFetcher}`; `OwnershipVerification` (start/check/confirmEmailCode/submitManualReview); `BusinessProfileService`; `OnboardingProgress`; `Directory\{BusinessDirectory, GooglePlacesDirectory}`; `OwnershipVerificationCode` mail; controllers in `Api/V1/Onboarding`; `Support\Mask`; app-wide immutable dates.
- **Card #16 mapping:** E2E 1 → `OnboardingTest::test_e2e1_*` (manual entry, Google prefill with faked HTTP); E2E 2 → `test_e2e2_*` (DNS, file and email succeed; expired, superseded and replayed fail; attempt cap); E2E 5 → `test_e2e5_*` (derived progress, skipping refused, resume). Plus duplicate-claim, evidence-encryption and cross-tenant tests. SSRF: `tests/Unit/Onboarding/NetworkSafetyTest.php` (21 blocked address forms, domain normalisation) and `OnboardingTest::test_safe_fetcher_*` (no request to private IPs; redirects ignored; 1 KB cap).
- **Bugs found while building:** the race-loss failure was marked inside a transaction that then threw (now committed first); `firstOrNew` tripped the `webshop_id` mass-assignment guard; the Places adapter froze its API key at construction, so a rotated key would be ignored by cached controllers.
- **Not verified live:** Google Places responses (no key); DNS/file success (needs a controlled public domain).

### B03d detail

- **Delivered:** migration `2026_10_23_180000_create_agreement_tables` (`agreement_templates`, `webshop_agreement_acceptances`, `webshops.rejected_at`); models `AgreementTemplate` (immutable once published), `AgreementAcceptance` (append-only); `AgreementService` (server-selected version, escaped rendering, SHA-256, accept); `WebshopLifecycle` (auto-approval, approve/reject/suspend/unsuspend); middleware `EnsurePlatformRole` (`platform:`), `RequireRecentPassword` (`password.recent`), `EnsureWorkspaceCommerciallyActive` (`workspace.commercial`); `AgreementController`; `Admin\WebshopController`, `Admin\AgreementTemplateController`; `AdminWebshopResource`, `AgreementTemplateResource`; `OnboardingProgress` agreement step; registry `x-access: admin`.
- **Card #16 mapping:** E2E 3 → `AgreementAndReviewTest::test_e2e3_*`; E2E 4 → `test_e2e4_*` (escaped rendering, auto-activation, unticked/forged/outdated refused, re-acceptance); E2E 9 suspension half → `test_e2e9_*`; E2E 10 backend journey → `test_e2e10_backend_journey_*`; every admin route refuses non-admins (`test_every_admin_route_refuses_non_admins`).
- **Card #3 F18 / #2 "support sessions"** are not done: impersonation/support sessions are B16.

### B04 detail

- **Delivered:** migration `2026_10_23_190000_create_integration_tables` (`webshop_connections`, `webhook_events`); `config/integrations.php` capability matrix; `App\Integrations\{ConnectorRegistry, ConnectionManager}`; `Contracts\Connector`; `Connectors\{WebhookConnector, WooCommerceConnector, ShopifyConnector, GenericSignedWebhookConnector}`; `Data\{NormalizedOrder, PaymentStatus, VerifiedEvent}`; `Support\Money` (exact, never rounds); `WebhookController` (public intake), `ConnectionController`; `ProcessWebhookEvent` job; `OrderEventVerified` event (B05 hook); `shopbonus:prune-webhook-payloads` scheduled daily; docs: webhook format v1 spec and `docs/integrations/VERIFICATION.md`.
- **Card #1 mapping (backend, fixtures):** E2E 1 partial (WooCommerce verified order → connected; live Order Sheet is B05); E2E 2 "unsupported connections cannot activate" → `test_card1_e2e2_*`; E2E 3 Shopify redacted → limited → `test_card1_e2e3_*` (unit + feature); E2E 4 normalisation rules → `ConnectorTest::test_card1_e2e4_*`; E2E 5 duplicate deliveries processed once (`test_card1_e2e1_*`). Real-store parts of E2E 1–3 are NOT RUN.

### B05 detail

- **Delivered:** migration `2026_10_23_200000_create_order_tables` (`webshop_orders`, `order_events`); `WebshopOrder` (encrypted PII, email hash), `OrderEvent` (append-only); `Services\Orders\{OrderIngestion, PaymentTransitions}`; `IngestOrderEvents` listener (inside the processing transaction); `RefundEventVerified` + Shopify `refundTarget`; `NormalizedOrder::providerUpdatedAt`; `OrderController` (sheet, detail, CSV export); `OrderResource`; event retry endpoint; `OrderSheetUpdated` broadcast + `routes/channels.php`; `orders.export` permission; `shopbonus.business_timezone`.
- **Card mapping:** #1 E2E 4 → `test_card1_e2e4_*`; E2E 5 and #3 F5 → `test_card1_e2e5_and_card3_f5_*`; #3 F3 → `test_card3_f3_*`; out-of-order, non-forward and redaction rules → `test_out_of_order_*`, `test_without_timestamps_*`, `test_redacted_*`; isolation → `test_orders_are_never_visible_across_webshops`; export → `test_export_*`; retry → `test_failed_event_can_be_retried`; channel auth → `test_broadcast_channel_authorisation`.
- **Not done here:** Answer24 eligibility/contact (B07; columns exist with `not_evaluated`); connection health monitor and alerts (B16); staging performance (B18).

## How to update

For each changed module include: evidence (paths/test names), actual test command/result, commit SHA, resolved/unresolved issues, permissions impact, migration impact, and exact next action. Do not use percentages without an auditable denominator.
