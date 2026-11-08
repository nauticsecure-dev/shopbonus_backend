## Remaining native coupon completion — 2026-11-07

OpenCart/PrestaShop live verification and CCV comparison implemented and tested in the working tree. C-Series/Mijnwebwinkel native schemas and CCV complete proof remain blocked on external contracts; see current HANDOFF. Checkboxes remain pending implementation commit.

- [ ] Read remaining provider schemas and identify unprovable constraints without inventing native APIs.
- [ ] Implement live bounded PrestaShop/CCV/C-Series readers using existing credential and identity gates.
- [ ] Implement nonce-bound signed OpenCart module reads, exact native usage/date/restriction evidence and backend verification.
- [ ] Integrate remaining comparisons without weakening existing usage/combination proof requirements.
- [ ] Exercise real endpoint outcomes, negative transport/signature/replay paths and module queries.
- [ ] Capture actual local HTTP and update API/provider setup, packages and handoff; distinguish unresolved partner contracts.

## Remaining coupon verification adapters — 2026-11-07

PARTIAL at base `06d43d9`; three adapters implemented/tested in the working tree. Boxes remain pending implementation commit. See `docs/integrations/COUPON_VERIFICATION.md` for the five remaining provider boundaries.

- [ ] Audit current native contracts, scope, identity, currency and restriction semantics per provider.
- [ ] Add bounded exact-code lookups with ambiguity, pagination and authority checks.
- [ ] Compare known terms conservatively; preserve manual review for unknown restrictions.
- [ ] Exercise matches, mismatches, permissions, malformed data and in-flight changes through the existing flow.
- [ ] Record real local HTTP captures and provider-specific remaining boundaries.

## Quality Center gap closure — 2026-11-07

- [ ] Require exact result-bound acceptance proofs before endpoint PASS, including unmapped cases.
- [ ] Verify complete frontend candidate dispositions with exact source and runtime bindings.
- [ ] Add authorized asynchronous staging execution requests and Quality Center controls.
- [ ] Provide discovery-bound generated runner candidates and reviewed execution integration.
- [ ] Map original 175 checks/60 subruns from the approved source and register reviewed journeys.
- [ ] Wire the actual deployment workflow to required acceptance before promotion.
- [ ] Capture real API examples, run affected checks and record exact remaining external dependencies.

## CMS frontend integration follow-up — 2026-11-07

- [ ] Integrate shared-block versions, draft overrides, detach and paginated usage UI.
- [ ] Replace landing browser cropping with preserved original/versioned recipe queue and exact restoration.
- [ ] Resolve validated immutable media pins in landing rendering; verify ownership and historical version preservation.
- [ ] Integrate responsive component previews and field bindings.
- [ ] Integrate AI backlog, budgets and independent review controls.
- [ ] Register executable content QA mappings and require the gate in deployment.
- [ ] Record executed checks and remaining staging acceptance with commit SHA.

## Publication/refund gap follow-up — 2026-11-07

- [ ] Correct stale C-Series setup instructions and Dutch translation; verify localization and publisher regressions (pending commit SHA).
- [ ] Supply authorized Ecwid/E-Series app registration and configure native storefront script delivery.
- [ ] Establish the selected C-Series PSP read contract and map verified cash refunds.
- [ ] Complete Squarespace merchant Code Injection and observe live storefront delivery; no reviewed automatic injection API.

## Consumer acceptance environment — 2026-11-03 (uncommitted)

- [ ] Record isolated database/configuration guards and idempotent points fixtures in a tested commit.
- [ ] Record dedicated migration/seed and PHP syntax/Pint checks in a tested commit.
- [ ] Record actual queued local SMTP verification and points checkout/replay smoke result.
- [ ] Run all ten complete journeys, provider sandboxes, staging performance and approved visual/accessibility checks.

## #16 workspace/team follow-up — 2026-11-03 (uncommitted)

Executed checks are recorded in PROGRESS.md; boxes remain unticked pending a commit SHA, per repository rule.

- [ ] Ship tenant-scoped team.manage access-history projection and real HTTP examples (implemented/tested; no migration).
- [ ] Ship permission editor and invitation review using existing APIs (implemented; controlled browser tests passed).
- [ ] Ship protected ownership-transfer management UI (implemented; controlled desktop/mobile tests passed).
- [ ] Ship shared workspace state remount/revalidation boundary (implemented; controlled team switch/revoked tests passed).
- [ ] Record live browser socket revocation/reconnect refusal and broadcaster recovery.
- [ ] Record every merchant screen's real-session workspace isolation.
- [ ] Record genuine Places results and staging invitation email delivery.
- [ ] Resolve remaining frontend dev dependency advisories and record staging P95 <300 ms.

## Affiliate detail review — 2026-10-31 (uncommitted)

- [ ] Gate new rewards on approval and settlement evidence; preserve historical credits.
- [ ] Share authoritative reward policy for display and processing.
- [ ] Require trusted advertiser permission for combined promotions.
- [ ] Verify bounded Daisycon pagination and recoverable sync watermark.
- [ ] Repair PHP 8.5 SOAP transport.
- [ ] Expand safe research and published refresh eligibility.
- [ ] Validate/transcode covers and generate responsive variants.
- [ ] Complete localized reveal/shop actions and browser verification.
- [ ] Record provider gaps and executed validation.

2026-11-01 continuation: 80 passed / 1 skipped on PHP 8.5, 5721 assertions;
3 Chromium fixtures pass; TypeScript/ESLint/Pint/PHPStan pass. Evidence:
[review](discovery/AFFILIATE_DETAIL_REVIEW.md). Boxes remain unchecked until a
commit records its SHA, per AGENTS.md.

- [ ] Audited Super Admin permission command creates a new offer version with account/program/reference/terms/expiry.
- [ ] Consumer permission forgery is forbidden; expiry fails closed; merchant edits and duplicates clear permission.
- [ ] Missing homepage metadata never causes evidence-ID collisions or overwritten linked-page research.
- [ ] Real local HTTP captures and cleanup recorded; cover width parameter documented.
- [ ] Normalize and verify provider promotion/exclusion mappings with authorized accounts; complete staging and reference-image acceptance.

## Homepage real-service acceptance — 2026-10-31 (uncommitted)

- [ ] Exercise actual cookie login, CSRF, Super Admin MFA and recent-password gates in local Laravel.
- [ ] Run all ten homepage fixes through real backend/browser journeys, including genuine wallet 500/recovery, failed-save publication blocking, public NL/EN, uploads, ordering and selected history restore.
- [ ] Fix observed application defects, run affected checks and capture redacted HTTP/test evidence.
- [ ] Verify wallet reconciliation and isolated-runtime cleanup; record limitations and exact final results.

## Homepage review corrections — 2026-10-31 (uncommitted)

- [ ] Validate current frontend homepage sections, responsive hero image references and optional copy; reject unsafe links and unknown assets.
- [ ] Verify draft/publish/restore/cache and media behavior with focused CMS tests.
- [ ] Record actual verification and remaining acceptance gaps; no completion/SHA until committed.

# Implementation Checklist

## Sales attribution review follow-up — 2026-10-31

Local checks: PHP 53 passed / 2 skipped / 5638 assertions; embed 8 passed; Chromium fixtures 2 passed; typecheck passed. Base backend 59916e9 / frontend 3d0b0ad on HassanDev; uncommitted. Boxes stay unchecked until committed with evidence/SHA. Live storefront, Moneybird and staging acceptance remain open.

- [ ] Connect analytics UI to scoped reporting APIs and real drill-downs.
- [ ] Gate embed storage, measurement and assignment on current recorded consent; handle withdrawal.
- [ ] Match encrypted voucher codes by per-shop hash; freeze first evidence atomically.
- [ ] Report refunded network amounts from authoritative orders and separate financial units.
- [ ] Propagate experiment assignments through the cart bridge, including holdout.
- [ ] Retry event UUIDs within bounded delivery; unsupported viewability remains unknown.
- [ ] Execute focused regression tests and document provider acceptance limits.
- [ ] Moneybird issued-invoice delivery/reconciliation verified against provider (external acceptance).

## Consumer profile review follow-up — 2026-10-31

Verification checkpoint on `HassanDev`, base `59916e9`; uncommitted. Keep these
unchecked until committed with the associated evidence/SHA (rule 12).

- [ ] Verification OTP absent from logs/audit; retain challenge/expiry/delivery metadata. Verified by EmailVerificationCodeTest.
- [ ] Authenticated password changes reject absent and mismatched confirmation before mutation; registration/reset retain D-014 single-password compatibility. Verified by ConsumerProfileTest and Identity tests.
- [ ] Focused backend integration run: 136 tests, 133 passed, 3 skipped, 6125 assertions (SQLite). Includes ConsumerProfile, Identity, Answer24Channel, Notifications and OpenApiCoverage.
- [ ] Acceptance clarification: journey 4 uses the established six-digit code (D-050); raw Trello export remains historical.
- [ ] Release evidence: ten complete browser journeys using real APIs/email, approved screenshot comparison, real wallet changes and reminder transport. Browser mocks plus backend tests alone do not complete this item.


## Windows local installation — 2026-10-27

Verification passed (86 tests/4,063 assertions; actual HTTP/migrations/platform checks).
Boxes remain unticked pending a task commit, per the checklist's same-commit rule.

- [ ] Install locked Composer dependencies using PHP 8.4 with SOAP.
- [ ] Create local environment/key and apply migrations to the new local MySQL database.
- [ ] Pin the MySQL connection to InnoDB and verify all installed tables use it.
- [ ] Verify local HTTP health/readiness/docs and focused API/identity tests.
- [ ] Record local start commands, verification results and remaining setup limitations.

Granular companion to `docs/TASKS.md`. **Rule (owner, 2026-10-23):** tick a box in the same commit that implements the item, and only once its tests have actually been run and passed. Put the commit SHA (or "this commit") after each ticked item. An unticked box means not done or not verified. Never tick from intent.

Tasks further down are listed at headline level. Expand them into concrete items when the task is started, before writing code.


**Legend:** `[x]` implemented and tested (commit SHA in brackets). `[ ] **BLOCKED**` needs something outside the code (credentials, staging, a git remote, a partner API, or a decision) and cannot be ticked from here. `[ ] **MOVED**` is tracked in the later task named.

## B00 Repository audit

- [x] Inventory of existing code, repos and runtime (`7cc47a8`; see PROGRESS.md)
- [x] Codebase decision recorded: greenfield, direct to main, MySQL (D-001..D-003)

## B01a Foundation: API skeleton

- [x] Laravel 13 + Sanctum scaffold, frontend tooling removed (`48520b7`)
- [x] `/api/v1` route group with configurable `throttle:api` (`a51a9b6`)
- [x] Standard error envelope 400–503 with `request_id`, no internal leakage (`a51a9b6`)
- [x] `ApiException` for domain errors (`a51a9b6`)
- [x] Request ID middleware: safe client reuse, UUIDv7 fallback, logged via Context (`a51a9b6`)
- [x] Force JSON on API, 401 instead of login redirect (`a51a9b6`)
- [x] Health liveness + readiness (DB/cache/Redis), detail gated by token (`a51a9b6`)
- [x] Log redaction on all channels (`a51a9b6`)
- [x] CORS allowlist from env (`a51a9b6`)
- [x] Pint, Larastan level 6, CI workflow file (`a51a9b6`)
- [x] CI run green on GitHub: run 40076266746 at `5da012e` (PHP 8.4 SQLite + MySQL, lint/static analysis); PHP 8.3 dropped (D-055)
- [x] OpenAPI 3.1 + API reference with real captured examples; coverage test (`a51a9b6`)

## B01b Foundation: audit and error store

- [x] `audit_events` migration: append-only, request ID, actor and real actor, webshop, action, resource, outcome, HTTP status, hashed IP, redacted metadata (`088fb20`)
- [x] DB-level guard against UPDATE on `audit_events` (MySQL and SQLite triggers) (`088fb20`)
- [x] `AuditEvent` model refuses update and delete (`088fb20`)
- [x] `AuditLogger` service (`record` throws; `recordSafely` never breaks the request) (`088fb20`)
- [x] 401/403 API responses audited automatically with request ID and route template (`088fb20`)
- [x] An audit write failure never breaks the API response (`088fb20`)
- [x] `application_errors` migration + `ApplicationError` model (`088fb20`)
- [x] Unexpected exceptions (5xx) recorded to `application_errors`, redacted; SQL bindings never stored (`088fb20`)
- [x] Intentional `ApiException`s and 4xx not recorded as application errors (`088fb20`)
- [x] Failed queue jobs recorded with job name, queue, connection and originating request ID (`088fb20`)
- [x] Alert hook event dispatched for recorded errors (`088fb20`)
- [x] Tests on SQLite and MySQL; Pint + PHPStan clean (`088fb20`)
- [x] Docs: PROGRESS, HANDOFF, TASKS, DECISIONS updated (`088fb20`)

## B02 Identity and consent (Trello #4; consent parts of #5 #10)

Auth mode decided by card #4: Fortify + Sanctum stateful SPA cookies (D-011).

- [x] Fortify headless (no views); only registration, password reset and password update enabled; 2FA/passkeys/profile-update disabled with reasons (`c95e1e4`)
- [x] `users` extended: `first_name`, `public_id` (ULID), `status`, `onboarding_intent`, `locale`, `last_login_at`; email stored lower-case with a DB unique index (`c95e1e4`)
- [x] Registration: account type, first name, email, password strength rules, required Terms + Privacy acceptance at the current version, optional marketing opt-in, locale; client-sent role/admin fields ignored (`c95e1e4`)
- [x] Concurrent and case-variant duplicate signups create exactly one user (`c95e1e4`)
- [x] `legal_acceptances`: immutable record of document type, version, time and minimal evidence (`c95e1e4`)
- [x] `consent_records`: append-only marketing consent history, separate from mandatory account mail (`c95e1e4`)
- [x] Email OTP: `email_verification_challenges`, HMAC-hashed 6-digit code, 10-minute expiry, 5 attempts, single use, atomic consumption, resend supersedes (`c95e1e4`)
- [x] OTP mail queued, payload encrypted, NL/EN, sent on registration; code never logged (`c95e1e4`)
- [x] `POST /api/v1/auth/email/verify-code` and `/resend-code` with per-user+IP throttles (429) (`c95e1e4`)
- [x] Unverified accounts limited to me/verify/resend/logout; `verified` guard returns 403 `email_not_verified` (`c95e1e4`)
- [x] Suspended/deleted accounts blocked on every API request and existing sessions; login refused with the generic error (`c95e1e4`)
- [x] `GET /api/v1/me`: minimal identity, status, roles (empty until B03) and server-computed destination (`c95e1e4`)
- [x] Login: active users only, generic failure, throttle per email+IP, session ID rotation, `last_login_at` (`c95e1e4`)
- [x] Forgot password: identical response for known, unknown and throttled emails; reset link points to FRONTEND_URL (`c95e1e4`)
- [x] Password reset works once; expired/reused token fails; other sessions and API tokens invalidated (`c95e1e4`)
- [x] Logout invalidates the session; protected GET afterwards returns 401 (`c95e1e4`)
- [x] Sanctum stateful API, cookie flags (Secure/HttpOnly/SameSite) via env, CSRF 419 tested with enforcement on; origin mismatch returns 403 `origin_not_allowed` (`c95e1e4`)
- [x] Identity audit events (registered, login succeeded/failed, lockout, logout, code sent/failed, email verified, reset requested, password reset) (`c95e1e4`)
- [x] Access registry: every route declares `x-access` in OpenAPI; test checks its middleware matches (default deny) (`c95e1e4`)
- [x] Docs: every new endpoint (Fortify ones too) with real captured request/response (`c95e1e4`)
- [x] Tests mapping card #4 E2E cases 1, 2, 4, 5, 6, 7, 8, 10. Case 3 (memberships) moves to B03; case 9 is frontend-owned (`c95e1e4`)
- [x] Local timing of `GET /api/v1/me` and signup measured and recorded (staging p95 NOT RUN; see PROGRESS) (`c95e1e4`)
- [x] Docs: PROGRESS, HANDOFF, TASKS, DECISIONS updated (`c95e1e4`)
- [x] Found during capture and fixed: guest-only routes now 409 for signed-in JSON clients; Fortify session routes re-check account status; intentional 5xx never include debug data (`c95e1e4`)
- [ ] **BLOCKED** Staging p95 for `/me` (<250 ms) and signup (<400 ms) (blocked: no staging environment)
- [ ] **MOVED** Super Admin/owner MFA (moved to B16 by design; Fortify 2FA re-enabled there)
- [x] Secured email-change re-verification flow (moved to B08; done in `c2dfb15`)

## B03 Workspaces and onboarding (Trello #2 #3 #16; #9 is B06)

Status: DONE (B03a–d). Open items outside this task: staging p95 for scoped requests (no staging), Super Admin MFA and support sessions (B16), combined owner dashboard (B13), ownership transfer workflow (D-021).

Sliced. The combined owner dashboard (card #3 §17) needs orders, vouchers and wallets, so it moves to B13 instead of returning fabricated zeros.

### B03a Workspace core and tenant isolation

- [x] `webshops` (public ULID, name, platform, status per card #16, review metadata) and `webshop_memberships` (role, extra permissions, status; unique per user+webshop) (`2fe8db6`)
- [x] Platform roles: `platform_role_assignments` + `shopbonus:grant-super-admin` command (private provisioning only; no API grants it) (`2fe8db6`)
- [x] Permission registry: `WorkspacePermission` enum and role defaults (owner, manager, employee); effective permissions computed server-side (`2fe8db6`)
- [x] Workspace resolution middleware: `{workspace}` = public ID or `current`; non-members get 404 (no existence leak); inactive membership gets 404; suspended webshop gets 403 `workspace_suspended`; sets `webshop_id` audit context (`2fe8db6`)
- [x] Permission middleware: members lacking a permission get 403 `workspace_permission_denied` (audited) (`2fe8db6`)
- [x] `GET/POST /api/v1/workspaces`: list own memberships; any verified user creates a draft webshop and becomes its owner (signup intent not required, nothing granted from client input); draft cap per user (`2fe8db6`)
- [x] `GET /api/v1/workspaces/{workspace}`, `PATCH` (rename, settings.manage), `POST /api/v1/workspaces/{workspace}/select` (server-side current workspace, audited) (`2fe8db6`)
- [x] `/me` returns real roles, memberships and destination (`workspace`, `webshop_setup`, `admin`) (`2fe8db6`)
- [x] Client-sent `role`, `owner`, `webshop_id`, `status` never elevate anything (`2fe8db6`)
- [x] Tests: one owner with five webshops; one user with different roles in different webshops; cross-workspace ID manipulation gives 404 and is audited; card #4 E2E 3 (`2fe8db6`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS updated (`2fe8db6`)

### B03b Team management

Owner role is never granted by invitation or role change (ownership transfer is a separate step-up workflow, D-021).

- [x] `webshop_invitations`: public ID, normalised recipient email, role, grants, HMAC token hash, 7-day expiry, status, inviter/acceptor, resend count (`ec20038`)
- [x] `POST /workspaces/{w}/invitations` (team.manage): role is manager or employee and never above the inviter's own; grants limited to the inviter's effective permissions; 409 when already an active member or a pending invite exists (`ec20038`)
- [x] Invitation mail queued after commit, encrypted payload, NL/EN, link to FRONTEND_URL; token never stored or logged (`ec20038`)
- [x] `GET /workspaces/{w}/invitations` (team.view), `POST .../{invitation}/resend` (new token, old one dies, cooldown + cap), `DELETE .../{invitation}` (cancel) (`ec20038`)
- [x] `POST /api/v1/invitations/preview` (public, throttled; token in body, not URL): webshop name, role, inviter first name, masked email, expiry (`ec20038`)
- [x] `POST /api/v1/invitations/accept` (verified): token must be pending, unexpired, single use, and match the signed-in user's email; creates or reactivates the membership atomically (`ec20038`)
- [x] `GET /workspaces/{w}/members` (team.view) with role, permissions, status, joined and last activity (`ec20038`)
- [x] `PATCH /workspaces/{w}/members/{user}` (team.manage): no self-change, no owner targets or grants, actor must outrank target, grants within actor's permissions (`ec20038`)
- [x] Suspend / reactivate / remove members; final active owner cannot be removed or suspended; members may leave; removal clears their current-workspace selection; access ends on the next request (`ec20038`)
- [x] Audit every invitation and membership change (`ec20038`)
- [x] Tests: card #16 E2E 7 (existing and new users accept; expired, reused and mismatched tokens fail), E2E 8 (no escalation, denied actions audited), E2E 9 membership half (removal blocks immediately; final owner protected) (`ec20038`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS updated (`ec20038`)

### B03c Business profile, onboarding progress, ownership verification

- [x] `webshop_business_profiles` (1:1): legal/trading name, website + normalised domain, KvK, VAT, contact email/phone/person (encrypted), address, language, Google place ID/category, source, confirmation; private (never in public APIs) (`b230455`)
- [x] `GET/PUT /workspaces/{w}/business-profile` (settings.view / settings.manage): partial drafts allowed, format validation (KvK 8 digits, NL VAT, domain), `missing_fields` reported; `confirm: true` only when complete; domain locked once ownership is verified (`b230455`)
- [x] `GET /workspaces/{w}/onboarding`: steps computed from real state (business details, ownership, agreement, activation) plus the next step; nothing client-settable (`b230455`)
- [x] Business directory interface + Google Places (New) adapter with minimal field masks and caching; 503 `provider_not_configured` without a key; `GET /api/v1/business-directory/search` and `/places/{id}` (verified, throttled) (`b230455`)
- [x] `webshop_verifications`: method, status, domain, public challenge value or HMAC'd email code, attempts, expiry, single use (`b230455`)
- [x] DNS TXT challenge (`_shopbonus-verification.<domain>`) via an injectable resolver (`b230455`)
- [x] Website-file challenge (`https://<domain>/.well-known/shopbonus-verification.txt`) via an SSRF-safe fetcher (HTTPS only, public IPs only, DNS pinned, no redirects, size and time limits) (`b230455`)
- [x] Domain-email challenge: only admin/administrator/webmaster/hostmaster/postmaster at the verified domain; free-mail domains refused (manual review); 6-digit HMAC code, 30 min, 5 attempts; queued encrypted mail (`b230455`)
- [x] Status effects: draft → awaiting_verification on start; failure/expiry → verification_failed (retry allowed); success sets `ownership_verified_at` and returns to draft for the agreement step; starting is refused until business details are confirmed (`b230455`)
- [x] Duplicate claims: a domain already verified by another webshop never auto-verifies; the claim goes to manual review flagged `duplicate_domain_claim` (`b230455`)
- [x] Manual review submission (agreements.accept-level owner action): notes + up to 5 private evidence files (PDF/JPEG/PNG ≤5 MB), encrypted at rest on the private disk; status → pending_review (`b230455`)
- [x] Audit every claim, attempt, success, failure and review submission (`b230455`)
- [x] Tests: card #16 E2E 1 (search/select/manual entry), E2E 2 (valid DNS/file/email challenge succeeds; expired and replayed fail), E2E 5 (resume; steps cannot be skipped by API tampering); SSRF guard unit tests (`b230455`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS updated (`b230455`)
- [ ] **BLOCKED** Google Places adapter verified against the live API (blocked: no `GOOGLE_PLACES_API_KEY`)
- [ ] **BLOCKED** Successful DNS/website-file check captured live (blocked: needs a public domain we control; covered by tests with fakes)

### B03d Agreements, review and activation

No agreement text is seeded: legal content comes from the owner via Super Admin templates (D-028).

- [x] `agreement_templates`: key, version, locale, title, body with whitelisted placeholders, status draft → published → retired, `requires_reacceptance`; published templates immutable (`5acdfbd`)
- [x] `webshop_agreement_acceptances` (append-only): template + version, rendered snapshot, SHA-256 hash, signatory, actor, time, request ID, hashed IP (`5acdfbd`)
- [x] `GET /workspaces/{w}/agreement`: the server-selected current version rendered with the webshop's confirmed details, plus hash and acceptance state (`5acdfbd`)
- [x] `POST /workspaces/{w}/agreement/accept` (agreements.accept): explicit `accept: true`, signatory, identity confirmation; client version and hash must equal the current ones (stale or forged → 409); requires confirmed details and verified ownership or submitted review (`5acdfbd`)
- [x] Lifecycle: auto-approve when ownership is verified, the current agreement accepted and nothing flagged; otherwise stays/moves to pending_review; all transitions audited (`5acdfbd`)
- [x] `workspace.commercial` middleware for later commercial routes: approved and current agreement accepted (403 `workspace_not_active` / `agreement_reacceptance_required`) (`5acdfbd`)
- [x] Platform-role middleware `platform:super_admin`; admin mutations also require recent password confirmation (step-up until MFA in B16) (`5acdfbd`)
- [x] Admin agreement templates: list, create draft, update draft, publish (retires the previous version of the key and locale) (`5acdfbd`)
- [x] Admin webshops: list with status filter and search, detail (profile, verifications, review notes, flags, acceptances), evidence download (audited), approve (requires accepted agreement; duplicate claims need explicit confirmation), reject (reason), suspend/unsuspend (reason) (`5acdfbd`)
- [x] `OnboardingProgress` agreement step (not_started / complete / outdated) (`5acdfbd`)
- [x] Tests: card #16 E2E 3 (unverifiable stays pending; only Super Admin approves/rejects), E2E 4 (correct version accepted; unchecked, forged or outdated acceptance cannot activate), E2E 9 suspension half (suspension blocks members immediately), E2E 10 backend journey; non-admins get 403 on every admin route (`5acdfbd`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS/DECISIONS updated (`5acdfbd`)

## B04–B19

## B04 Ecommerce provider contracts (Trello #1 "Supported platforms", "Shared backend"; #3 A.4)

No provider credentials exist: every real-platform adapter is NOT VERIFIED until tested against a real store or sandbox (OPEN-PROVIDER-CAPABILITIES).

- [x] Capability matrix (`config/integrations.php`): per platform method, auth, signature scheme, order data source, reliability, adapter class or none, verification state; `GET /api/v1/integrations/platforms` (`820c6b5`)
- [x] `webshop_connections`: public ID, webshop, platform, method, status (setup_required, testing, connected, limited, error, disconnected), encrypted credentials, opaque webhook token, limitations, health timestamps, safe last error; one non-disconnected connection per webshop+platform (`820c6b5`)
- [x] Connector interface (`setupInstructions`, `connect`, `testConnection`, `verifyIncomingEvent`, `fetchOrder`, `normalizeOrder`, `syncOrders`, `disconnect`) + `ConnectorRegistry` (`820c6b5`)
- [x] `NormalizedOrder` DTO: integer minor units, ISO currency, mandatory fields (external ID, reference, first name, email, amount, currency, timestamps), optional phone/payment/cancel/refund; missing mandatory → incomplete (never fabricated) (`820c6b5`)
- [x] WooCommerce adapter: `X-WC-Webhook-Signature` base64 HMAC-SHA256 of the raw body, delivery-ID dedupe, ping handling, order payload mapping (NOT VERIFIED live) (`820c6b5`)
- [x] Shopify adapter: `X-Shopify-Hmac-Sha256`, `X-Shopify-Webhook-Id` dedupe, orders/* topics, redacted-field handling → limited (NOT VERIFIED live) (`820c6b5`)
- [x] Generic Shopbonus signed-webhook adapter for private modules: `X-Shopbonus-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "t.body">`, 5-minute replay window, event-ID dedupe; fully specified format (`820c6b5`)
- [x] `webhook_events`: dedupe key unique per connection, verification result, topic, encrypted payload (pruned after 14 days by scheduled command), safe error, processing status (`820c6b5`)
- [x] `POST /api/v1/webhooks/{platform}/{token}`: public, throttled per token, verifies before trusting anything, constant-time compare, 401 on bad signature, 404 on unknown or disconnected token, fast 202 and async processing; duplicates acknowledged without reprocessing (`820c6b5`)
- [x] Event processing job: normalise; the first verified complete order moves the connection to connected (or limited); a ping moves setup_required to testing; never connected from saved credentials alone (`820c6b5`)
- [x] Merchant endpoints (integrations.view / integrations.manage): list, create (secret shown once), show with instructions, rotate secret (shown once), verify checklist, disconnect (credentials wiped), sanitized event history (`820c6b5`)
- [x] Audit connection changes, secret rotations, rejected signatures (rate-limited) (`820c6b5`)
- [x] Tests: signature valid/invalid/replayed/old timestamp per adapter, duplicate delivery processed once, unsupported platform refused, cross-tenant 404, secret never in responses after creation or in logs, card #1 E2E 2 "unsupported connections cannot activate", E2E 3 incomplete access blocks connected (fixture), E2E 4 mandatory/phone rules at normalisation (`820c6b5`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS updated (`820c6b5`)
- [ ] **BLOCKED** Live verification of WooCommerce / Shopify adapters (blocked: no store or sandbox access; checklist in docs/integrations/VERIFICATION.md)
- [ ] **BLOCKED** Adapters for Wix, BigCommerce, Ecwid, Lightspeed, Squarespace, Mijnwebwinkel, CCV (blocked: auth mechanisms and credentials unverified)
- [ ] **BLOCKED** Private modules for PrestaShop/OpenCart/Shopware/Magento (outside backend scope: plugin code; the format they must speak is specified)

## B05 Orders and state transitions (Trello #1 "Normalized orders", "Live order sheet"; #2 §5; #3 B.6–B.7)

- [x] `webshop_orders`: unique (webshop, platform, external order ID); integer minor units + currency; separate payment, ingestion, eligibility and contact statuses; missing-fields list; encrypted customer name/email/phone with a keyed email hash for exact search; provider-updated timestamp (`7e391be`)
- [x] `order_events` (append-only): created, updated, payment_changed, refund_notified, ignored_stale, with from/to payment status and source webhook event (`7e391be`)
- [x] Ingestion listener on `OrderEventVerified`: transaction-safe upsert under row lock; race on first insert handled; never fabricates values; incomplete → `ingestion_status=incomplete` with missing fields; no phone → not callable (order still imported) (`7e391be`)
- [x] Out-of-order safety: an event older than the stored provider update time is recorded as `ignored_stale` and not applied; without timestamps only forward payment transitions apply (refunded/cancelled never revert to paid) (`7e391be`)
- [x] Refund notifications (Shopify `refunds/create`) mark a paid order `partially_refunded` until the order update gives exact amounts (`7e391be`)
- [x] Order Sheet `GET /workspaces/{w}/orders` (orders.view): pagination, filters (payment, ingestion, platform, date range), search (reference or exact email), sorting, today's counters in the business timezone, never cross-tenant (`7e391be`)
- [x] `GET /workspaces/{w}/orders/{order}` with sanitized history (`7e391be`)
- [x] CSV export (`orders.export` permission, audited, throttled, CSV-injection safe) (`7e391be`)
- [x] Failed-event retry `POST /workspaces/{w}/connections/{c}/events/{event}/retry` (integrations.manage) (`7e391be`)
- [x] Live updates: `OrderSheetUpdated` broadcast on private channel `webshops.{id}.orders` (no PII in payload) with channel authorisation by membership + orders.view (`7e391be`)
- [x] Tests: card #1 E2E 4 (incomplete/no-phone), E2E 5 (duplicates → one order), out-of-order and stale events, paid→refunded→late paid, cross-tenant 404, export permission and injection, retry, channel auth; card #3 F3 and F5 (`7e391be`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS updated (`7e391be`)
## B06 Offers and code inventory (Trello #7, #9, #17, #8; #23 "One voucher system")

One voucher model and one code inventory; acquisition methods are typed origins (D-037).

### B06a Offers, versioned terms, cost preview

- [x] `offers` (acquisition: merchant_reward | wallet_purchase | promotional_code; status draft → active ⇄ paused → ended) and `offer_versions` (all terms; locked once activated or used; edits to a locked version create a new version) (`c6485dc`)
- [x] Terms: percentage (basis points) or fixed (minor units), currency, minimum order, maximum discount, start/end, claim validity (default 90 days), redemption validity, categories/products, combinable, code mode unique|reusable, allocation limit, wallet price (wallet_purchase) (`c6485dc`)
- [x] `FeeCalculator`: direct merchant reward fee = 30% of the reward amount (config bps, integer half-up rounding, never on order value); preview per voucher and for the allocation; percentage offers preview on the maximum discount (OPEN-PERCENT-REWARD-FEE-BASIS) (`c6485dc`)
- [x] API: list (with counters), create draft, show (current version + preview), update (in-place while draft, new version once locked), activate (`workspace.commercial`, completeness check), pause, resume, end; audit everything (`c6485dc`)
- [x] Tests: card #7 E2E 1 (three offers, each shows only its own data), E2E 2 (10% from €100 max €10, 90 days persists), E2E 3 (issued vouchers keep original terms after edit; new issuance uses the new version), permissions and cross-tenant (`c6485dc`)
- [x] Docs with real captures (`c6485dc`)

### B06b Code inventory, import, atomic reservation, voucher lifecycle

- [x] `voucher_codes`: encrypted value, keyed hash unique per webshop (case-insensitive), last-4 for masked display, status (available, reserved, issued, redeemed, expired, invalid, cancelled), `platform_validation` (default `merchant_verification_required`), reusable codes with use counter (`c07f778`)
- [x] `voucher_code_batches`: paste or CSV/TXT upload → preview (normalisation, format 4–64 `[A-Za-z0-9_-]`, duplicates in upload, duplicates already in the webshop, totals, row-level reasons with masked codes) → commit in one transaction (large batches queued); XLSX refused with guidance (no spreadsheet dependency, D-040) (`c07f778`)
- [x] `vouchers` (canonical, one model): offer + exact offer version, typed origin (merchant_reward, answer24_claim, wallet_purchase, promotional), code, consumer, source order, idempotency key (unique per webshop), claim expiry, redemption expiry, terms snapshot; status reserved → issued → redeemed / expired / cancelled (`c07f778`)
- [x] `voucher_events` append-only history for vouchers and codes (`c07f778`)
- [x] `VoucherInventory::reserve()`: idempotent by key, offer active + webshop commercially active + within dates + allocation limit, locks the offer version, atomic code pick (`FOR UPDATE SKIP LOCKED` on MySQL/PG + conditional update), 409 `offer_out_of_stock` (`c07f778`)
- [x] `issue()` (reserved → issued to a verified consumer), `release()` (reserved → cancelled, code back to available), `redeem()` (issued → redeemed exactly once with a verified paid order of the same webshop), expiry command (unclaimed reservations released, issued vouchers expire) scheduled (`c07f778`)
- [x] Code sheet API: list with filters (status, batch, search exact code, assigned, platform validation) + counters that reconcile with every status; masked codes; audited reveal; invalidate unused codes only; history; CSV export (`vouchers.export`, audited, throttled) (`c07f778`)
- [x] Tests: card #7 E2E 3 (issued voucher keeps v1 after edit), E2E 4 (paste with duplicate + invalid, CSV, preview, totals, batch audit), **E2E 5 real concurrency (forked processes, MySQL)**, E2E 6 lifecycle + repeated redemption rejected, E2E 9 (500 codes, three batches, filters, export), E2E 10 cross-tenant + failed upload; idempotent reservation per order (`c07f778`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS updated (`c07f778`)
- [x] Verified redemption: codes in paid verified orders (Woo `coupon_lines`, Shopify `discount_codes`, format v1 `coupon_codes`) redeem the issued voucher once (`c07f778`)
- [x] Platform coupon verification: WooCommerce coupon read via `WooCommerceApi::findCouponByCode`; `POST …/codes/{code}/platform-validation` (`provider_check` | `manual_confirm`); statuses `verified` / `mismatch` / `unsupported_integration` / `sync_error` / `merchant_verification_required`; import still defaults to merchant verification
- [x] Card #7 E2E 7 (Woo verify + unsupported fallback) and E2E 8 (funding idempotency) covered in `OffersCodesE2ETest` (+ existing FundingTest / VoucherInventoryTest)
- [x] Trello #17: `scheduled` and `archived` offer statuses (`archived_at`), publish/resume/archive/duplicate, lifecycle display plus inventory on the offer resource, paginated offer filters, code import template, voucher list/show/export (masked, no PII) and merchant-initiated disputes, dashboard connection health / recent activity / quick actions / empty flag, `shopbonus:advance-offer-lifecycle` every minute, and `free_shipping` terms (`WebshopDashboardOffersE2ETest`, 10 cases)

## B07 Answer24 (Trello #1 "Answer24 required processing", #3 B.7–C.10, #23 "Answer24 responsibilities", #10)

Answer24 owns calls, consent checks, invitations and reminders; Shopbonus owns order eligibility, signed handoff, reservations, claim links, identity verification and status (card #23). No Shopbonus dialer or marketing automation. Answer24 has no Shopbonus API yet, so the contract is Shopbonus-defined and **outbound dispatch is disabled by default** (OPEN-ANSWER24-CONTRACT, OPEN-MARKETING-CONSENT).

### B07a Eligibility, grouping, cancellation, outbox

- [x] Per-webshop Answer24 settings (owner attestation): enabled flag, lawful basis (`consent_at_checkout` | `legitimate_interest`), documented by/at; legitimate interest refused unless the config allows it (default off) (`5c24240`)
- [x] `contact_suppressions` (consumer opt-out per webshop or global, by keyed email/phone hash) with an API to add them (`5c24240`)
- [x] `OrderEligibility`: evaluated after each order change; reasons recorded (webshop not active, no active merchant-reward offer, not paid, cancelled/refunded, incomplete, no phone → not callable, Answer24 disabled, no lawful basis, no order consent for consent-based basis, suppressed, recently contacted); no contact without every check passing (`5c24240`)
- [x] `contact_opportunities` + `contact_opportunity_orders`: rolling 1-hour grouping per (webshop, customer key); never across webshops; unique idempotency key (`5c24240`)
- [x] Window close (scheduled every minute): re-check every order immediately before readiness; no eligible orders → cancelled; else ready → outbox event (dispatch disabled → stays queued) (`5c24240`)
- [x] Cancellation/refund: removes the order from a pending/ready opportunity, cancels it when empty, and records a suppression request for an already dispatched one (outbox) (`5c24240`)
- [x] `integration_outbox` (transactional outbox: target, type, payload, idempotency key unique, status, attempts, next attempt); no sending in B07a (`5c24240`)
- [x] Order Sheet shows `eligibility_status` / `contact_status` with reasons (`5c24240`)
- [x] Tests: card #1 E2E 6 (unpaid/pending/spoofed never contact), E2E 7 (grouping), E2E 8 (cancellation removes eligibility, dispatched → suppression queued), E2E 4 no phone; #3 F4, F6, F7 (`5c24240`)
- [x] Docs with real captures (`5c24240`)

### B07b Integration channel, handoff, claim, reminders

Contract is Shopbonus-defined (`shopbonus-answer24-v1`, OPEN-ANSWER24-CONTRACT); outbound dispatch stays off by default.

- [x] `integration_clients` (public key ID, encrypted secret, target, scopes, active/revoked, last used) + `shopbonus:create-integration-client` (secret shown once; `--revoke`) (`0c6c035`)
- [x] `integration.signed:<scope>` middleware: `X-Shopbonus-Key` + `X-Shopbonus-Signature: t=,v1=` HMAC over `t.body`, 5-minute window, constant-time compare; unknown key/bad signature/stale → 401 `integration_signature_invalid`; missing scope → 403 (`0c6c035`)
- [x] Event-ID replay guard (`integration_inbound_events`, unique per client): same event ID with a different body → 409 `event_id_reused`; identical retry is processed idempotently (`0c6c035`)
- [x] `POST /api/v1/integrations/answer24/contact-results`: accepted/rejected/cancelled/attempted/completed/failed with timestamp and remote reference; state machine; stale (older) results recorded but not applied; idempotent; Order Sheet `contact_status` updated; webshop must match (404 otherwise) (`0c6c035`)
- [x] `contact_opportunity_events`: append-only history of every callback (`0c6c035`)
- [x] `POST /api/v1/integrations/answer24/handoffs`: only after permitted engagement (accepted/attempted/completed); reserves one code via `VoucherInventory` (`answer24-handoff:<opportunity>`); returns a short, unguessable, single-use claim URL; idempotent replay returns the same URL; claim expires with the 90-day claim window (`0c6c035`)
- [x] `voucher_claims`: HMAC token hash (lookup), encrypted token (idempotent replay), status open/claimed/expired/cancelled (`0c6c035`)
- [x] `POST /api/v1/integrations/answer24/claims/{claim}/reminders {day}`: day 30/60/80 only; not before the day; atomic slot per day (duplicates → `send:false`); refused once claimed/expired/cancelled or suppressed (`0c6c035`)
- [x] `GET /api/v1/integrations/answer24/claims/{claim}`: claim status for Answer24 (`0c6c035`)
- [x] Consumer `POST /api/v1/claims/preview` (public, throttled): offer/webshop/terms/deadline only; unknown token → 404; used/expired → 410 (`0c6c035`)
- [x] Consumer `POST /api/v1/claims/accept` (verified): account email must match the order email (403 `claim_email_mismatch`, audited); issues the reserved voucher once; second user → 410; outbox `answer24.claim_completed` (`0c6c035`)
- [x] Expiry: unclaimed claims expire with the reservation release (scheduled), outbox `answer24.claim_expired` (`0c6c035`)
- [x] Outbox dispatcher (`shopbonus:dispatch-outbox`, every minute): no-op while disabled; atomic claim of each message; re-checks the opportunity before sending; signed `Answer24Client`; success → `dispatched` + remote reference; failure → exponential backoff, `failed` after max attempts; stuck `sending` reclaimed (`0c6c035`)
- [x] Tests: card #1 E2E 9 (signed request + callback + single-use activation, no cross-tenant, no duplicates); #10 reminders (days 30/60/80, claim before next reminder, repeated delivery no duplicate); #23 Answer24 free voucher; signature/replay/scope negatives; dispatcher with `Http::fake` (`0c6c035`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS/DECISIONS updated (`0c6c035`)
- [ ] **BLOCKED** Real Answer24 end-to-end (blocked: Answer24 has no Shopbonus API yet, OPEN-ANSWER24-CONTRACT)

## B08 Consumer discovery and profile (Trello #18, #10, #5 consent parts)

Sliced: B08a public discovery; B08b consumer profile, preferences, favourites, My Vouchers, dashboard, privacy requests. Wallet balance arrives with B09 (dashboard shows `wallet: null` until then, never a fabricated number).

### B08a Public discovery

- [x] `webshop_public_profiles` (stable readable slug, display name, short description, description, website, logo, categories, published flag/at) + `webshop_public_categories` index table; category taxonomy in config (NL/EN labels) (`4c4e26c`)
- [x] Offer `slug` (stable, readable, unique); backfilled for existing offers (`4c4e26c`)
- [x] Merchant `GET/PUT /workspaces/{w}/public-profile` (settings.view / settings.manage): plain text only (HTML refused), website must be http(s), publish requires name + short description + category; slug editable until first publish; audited (`4c4e26c`)
- [x] Merchant logo upload/remove (PNG/JPEG/WebP, ≤1 MB, ≤2000 px, content checked; SVG refused); served only through the API, never a storage path (`4c4e26c`)
- [x] `GET /api/v1/public/categories` (`4c4e26c`)
- [x] `GET /api/v1/public/webshops` (search, category, has_offers, sort relevance/name/newest, pagination ≤50): only approved webshops with a published profile; never suspended/rejected/draft/unpublished (`4c4e26c`)
- [x] `GET /api/v1/public/webshops/{slug}`: public profile + active offers only; no ownership evidence, agreements, orders, members or integration data; unavailable → 404 (`4c4e26c`)
- [x] `GET /api/v1/public/webshops/{slug}/logo` (`4c4e26c`)
- [x] `GET /api/v1/public/offers` and `/public/offers/{slug}`: only active offers of listed webshops; availability `available` / `sold_out` / `expired` / `upcoming`; paused/ended/draft → 404; viewing never reserves a code (`4c4e26c`)
- [x] Public read cache with version-bump invalidation on profile, offer and webshop status changes (short TTL for stock) (`4c4e26c`)
- [x] Tests: card #18 E2E 2 (search/filter/sort/pagination, only approved+published), E2E 3 (no private data), E2E 4 (paused/expired/sold-out), cache invalidation, logo validation, merchant permission checks (`4c4e26c`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS/DECISIONS updated (`4c4e26c`)
- [x] Browsable API docs at `/docs` (OpenAPI viewer) and `/docs/reference` (README rendered, raw HTML escaped); `API_DOCS_ENABLED`, off in production by default (`4c4e26c`)
- [ ] **BLOCKED** Staging p95 < 300 ms for directory requests (blocked: no staging)

### B08b Consumer profile, preferences and benefits

All routes derive the consumer from the session; no user ID is ever accepted (card #10 E2E 10).

- [x] `GET/PATCH /api/v1/consumer/profile`: first name (letters/spaces/hyphen/apostrophe, no HTML), optional phone normalised to E.164 (stored encrypted), locale; email/status/role fields ignored; invalid input changes nothing (`c2dfb15`)
- [x] Avatar upload/replace/remove + `GET /consumer/profile/avatar` (PNG/JPEG/WebP, ≤2 MB, content-checked; private storage; storage failure → 503 with no partial change) (`c2dfb15`)
- [x] Secured email change: request (current password; code mailed to the new address, notice to the old one) → confirm with code (expiry, attempts, single use) → login email replaced only then; cancel; throttled; audited (`c2dfb15`)
- [x] Password change (`PUT /user/password`) throttled per user (card #10 E2E 5) (`c2dfb15`)
- [x] Notification preferences `vouchers_discounts`, `reminders`, `offers_inspiration` as append-only consent records (purpose, version, source, time); only changes are recorded; `CommunicationPreferences::allows()` for senders; security/transactional mail never depends on them (`c2dfb15`)
- [x] Preferred categories and favourite webshops (listed webshops only; profile edits leave them intact) (`c2dfb15`)
- [x] `GET /consumer/vouchers` (My Vouchers: own vouchers only, code visible to the owner once issued) (`c2dfb15`)
- [x] `GET /consumer/dashboard`: own voucher summary, favourites, verification status; `wallet: null` until B09 (never fabricated) (`c2dfb15`)
- [x] Privacy: `GET /consumer/privacy/export` (own data as JSON, audited), `POST /consumer/privacy/deletion-request` (password; one open request; processing respects retention, D-013), `GET /consumer/privacy/requests` (`c2dfb15`)
- [x] Tests: card #10 E2E 1, 2, 3, 4, 5, 6, 9, 10 (E2E 7 covered by B07b; E2E 8 needs B09); card #18 E2E 9 (`c2dfb15`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS/DECISIONS updated (`c2dfb15`)
- [x] Found during capture and fixed: the "email changed" notice to the old address crashed for on-demand recipients (500 after the change was saved); now rendered in a test (`c2dfb15`)
- [x] Card #10 E2E 8 wallet balance from the ledger (`WalletApiTest`, `862d3d0`)
- [ ] **BLOCKED** #18 E2E 5 return-to-offer after signup (frontend; backend has no redirect state)
- [ ] **MOVED** Deletion request processing (B16 admin, retention rules D-013)

## B09 Wallet ledger core (Trello #19 "Central wallet", #23 "Instant affiliate credit", #20, #2)

Owner 2026-10-23: proceed with the safe defaults for OPEN-REWARD-UNITS and OPEN-AFFILIATE-CREDIT-TIMING. So every amount carries an explicit unit (`eur_credit` minor units or `points`), with no implicit conversion, and pending credit is never spendable.

- [x] `wallets` per (owner, kind, unit): consumer credit now, merchant funding kind reserved for B12; cached available/reserved/pending and lifetime totals, unsigned (DB refuses negatives) (`862d3d0`)
- [x] `wallet_ledger_entries`: append-only (model + DB trigger), types credit/debit/reserve/release/expire/adjustment/reversal, signed deltas and balances after, source, reason, actor and origin, idempotency key unique per wallet (`862d3d0`)
- [x] `wallet_credit_lots`: every credit is a lot with an expiry (default 12 months, card #23); spending/reserving takes the oldest-expiring lot first (`862d3d0`)
- [x] `wallet_reservations`: reserve → capture (debit) or release; expiry releases automatically; idempotent per key (`862d3d0`)
- [x] `wallet_pending_credits`: pending amounts shown separately, never spendable; confirm → exactly one credit; cancel → nothing credited (`862d3d0`)
- [x] `WalletService`: credit, addPending/confirmPending/cancelPending, reserve/capture/release, debit, adjust (reason + actor; never below zero), reverse (only the unspent part; the rest is flagged for reconciliation, never a negative balance), expire (`862d3d0`)
- [x] Scheduled `shopbonus:wallet-expire` (lots, reservations) and `shopbonus:wallet-reconcile` (cached balances = ledger sums; mismatches recorded as application errors) (`862d3d0`)
- [x] Consumer `GET /consumer/wallet`, `GET /consumer/wallet/transactions` (filters all/earned/spent/pending/adjustments, unit, pagination), `GET /consumer/wallet/transactions/{id}`; dashboard shows the real wallet; no transfer or cash-out endpoint exists (`862d3d0`)
- [x] Tests: concurrent debits on MySQL with forked processes (never negative, exact balance); idempotent retries; FIFO-by-expiry; reservation release on failure; reversal of spent credit flagged and never negative; expiry; isolation between consumers; append-only enforced (`862d3d0`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS/DECISIONS updated (`862d3d0`)
- [x] Found while testing and fixed: DatabaseTruncation tests on SQLite marked migrations as done, leaving later in-memory tests without tables (order-dependent); both race tests now skip truncation on SQLite (`862d3d0`)
- [ ] **MOVED** Admin wallet adjustment requests with password + email OTP step-up and four-eyes approval (B16; the service method `adjust()` exists)
- [ ] **BLOCKED** Staging p95 < 250 ms for wallet reads (no staging)

## B10 Affiliate tracking and rewards (Trello #19, #23 "Instant affiliate credit", #24 §5)

No Daisycon/TradeTracker credentials or sandbox: adapters map the networks' documented fields and are **NOT VERIFIED**. Credit timing follows the owner's safe default: `AFFILIATE_CREDIT_TIMING=on_network_approval`, so credit stays pending until the network approves. `on_validated_attribution` (card #23 instant credit) is implemented and tested, but off.

- [x] `affiliate_network_connections` (network, status connected/limited/reauthentication_required/error/disabled, encrypted credentials, postback token hash, last sync/error) (`37edebf`)
- [x] `affiliate_merchant_programs` (external ID, name, matched webshop, active, commission type/value/currency, category restriction, cashback permitted, tracking URL template) (`37edebf`)
- [x] `AffiliateNetworkAdapter` contract + Daisycon and TradeTracker adapters (program/transaction normalisation, tracking link with click reference, connection test); NOT VERIFIED (`37edebf`)
- [x] Network selection: only active, cashback-permitted, matched programs; compare expected commission on a reference basket only when comparable (no restricted-vs-unrestricted comparison); otherwise configured fallback; every decision recorded with candidates and rule version (`37edebf`)
- [x] `GET /api/v1/public/webshops/{slug}/visit`: records a click (consumer if signed in), short opaque click reference, redirect to the selected tracking URL; no points for a click; untracked fallback to the website (`37edebf`)
- [x] `affiliate_transactions` (unique per network + external ID; statuses tracked/pending/approved/rejected/reversed/unmatched/disputed; separate reward status) + append-only `affiliate_reconciliation_events` (`37edebf`)
- [x] Idempotent ingestion (sync, admin import of network exports, tokenised postback that can only create/keep pending), out-of-order safe, cross-network duplicate (same click or same order reference) → disputed, no reward (`37edebf`)
- [x] `affiliate_reward_rules` (versioned; unit, share of commission in basis points, minimum commission, cap, review threshold, validity window); no active rule → no reward (`37edebf`)
- [x] Rewards through `WalletService`: pending → confirmed once; rejected before credit → cancelled; reversed after credit → unspent part reversed, rest flagged (never negative); above review threshold → disputed (`37edebf`)
- [x] Admin (Super Admin; mutations need step-up): connections CRUD/test/sync/import, programs list + match, selection run + decisions, transactions list + resolve (link click, reject), reward rules (`37edebf`)
- [x] Consumer `GET /consumer/affiliate-activity` (own matched transactions, status explained, reward; never commission data) (`37edebf`)
- [x] Tests: card #19 E2E 1 (fixture credential errors), 2, 3, 4 (fixtures), 5, 6, 7, 8 (wallet integrity), 9, 10 (ops/queue/admin/consumer); card #23 test 6 (instant mode); points-unit reward path; marketing-consent visit gate
- [x] Queued `SyncAffiliateConnection` job + `shopbonus:sync-affiliates` (default queue; `--sync` inline); admin sync `async=true` → 202
- [x] Click `expires_at` + marketing consent evidence on tracked visits (policy published → consent required)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS/DECISIONS updated (`37edebf`)
- [ ] **HANDOFF** Live Daisycon/TradeTracker verification (senior developer configures accounts; B10b ships the SOAP transport; see docs/integrations/VERIFICATION.md)
- [ ] **BLOCKED** Card #19 E2E 1 and 4 against real provider data; staging p95 (no staging)
- [ ] **MOVED** Affiliate fraud dashboards, network health monitoring and alerts (B16)

## B11 Voucher checkout, Mollie, My Vouchers (Trello #20, #23 "Wallet/Mollie voucher", tests 7–8)

Prices of `wallet_purchase` offers are euro cents (`price_minor`) paid from the `eur_credit` wallet and/or Mollie; points are never converted (D-051). Mollie runs in **test mode only**: live keys are refused unless `MOLLIE_ALLOW_LIVE=true` (OPEN-FINANCIAL-LEGAL).

### B11a Checkout and Mollie

- [x] `voucher_checkouts` (consumer, offer, price, wallet part, payment part, state, idempotency key unique per consumer, expiry) + `voucher_checkout_payments` (provider reference unique, amount, verified status, refunds) (`5fca11a`)
- [x] `POST /consumer/checkouts/quote`: server-side price, wallet available, wallet/Mollie split, blockers (inactive, sold out, insufficient, payments unavailable); no mutation (`5fca11a`)
- [x] `POST /consumer/checkouts` (`Idempotency-Key` header): wallet-only completes atomically (wallet debit + code + voucher in one transaction); mixed/Mollie reserves wallet part + code (bounded) and returns the Mollie checkout URL; a repeat returns the same result (`5fca11a`)
- [x] Mollie client (create payment, get payment, refund) with amounts as exact decimal strings; test keys only by default; no key → 409 `payments_unavailable` (`5fca11a`)
- [x] `POST /payments/mollie/webhook`: never trusts the body; fetches the payment from Mollie; paid → finalize exactly once; failed/canceled/expired → release wallet + code, no voucher; unknown ID → 200 with nothing done (`5fca11a`)
- [x] Paid but inventory gone → `paid_unfulfilled`, admin alert, recoverable (refund in B11b) (`5fca11a`)
- [x] Scheduled `shopbonus:expire-checkouts`: re-checks Mollie, finalizes late payments, releases the rest (`5fca11a`)
- [x] `GET /consumer/checkouts/{id}`: own checkout status (redirect page never proves payment) (`5fca11a`)
- [x] Tests: card #20 E2E 1, 2, 3 (forked race on the last code), 4 (reusable), 5, 6, 10 (forged webhook, replay, cross-user); card #23 tests 7, 8 (`5fca11a`)
- [x] Docs with real captures (Mollie via a local test-mode stub) (`5fca11a`)
- [x] Found by the real forked-process test and fixed: concurrent checkouts deadlocked (FK shared lock on the offer, then FOR UPDATE); offer locked first, deadlocks retried (`5fca11a`)
- [ ] **BLOCKED** Real Mollie sandbox evidence (no Mollie test account/key); live payments (OPEN-FINANCIAL-LEGAL)

### B11b Voucher details, redemption, disputes

- [x] My Vouchers list shows codes masked (last 4); filters active/used/expired/cancelled/all; `GET /consumer/vouchers/{id}` reveals the full code to its owner (audited) with redemption instructions, website, terms, expiry warning and checkout reference (`cd48d26`)
- [x] Merchant `POST /workspaces/{w}/vouchers/redeem` (vouchers.manage): an issued voucher of this webshop is redeemed once (source merchant, actor, reference); expired/unknown/other webshop refused; viewing, copying or visiting never redeems (`cd48d26`)
- [x] `voucher_disputes` + append-only `voucher_dispute_events`: consumer opens (voucher or checkout; reasons code_invalid, code_already_used, refused_by_webshop, terms_differ, missing_after_payment, other) and follows status open/under_review/waiting_for_merchant/resolved/rejected; one open dispute per voucher/checkout (`cd48d26`)
- [x] Merchant lists own webshop's disputes (vouchers.view) and responds (vouchers.manage); never other tenants' data (`cd48d26`)
- [x] Super Admin: list/show, set status, resolve with **one-time** compensation (wallet credit via `WalletService::adjust`, replacement voucher via `VoucherInventory`, or Mollie refund of the checkout payment); step-up; audited (`cd48d26`)
- [x] Admin refund of `paid_unfulfilled` checkouts (Mollie refund once; state refunded) (`cd48d26`)
- [x] Tests: card #20 E2E 7, 8, 9; compensation never twice; cross-tenant and cross-user denials (`cd48d26`)
- [x] Docs with real captures; B08b My Vouchers examples updated (`cd48d26`)
- [ ] **BLOCKED** Provider redemption confirmation beyond order coupon matching (needs platform coupon APIs; see B06b)
- [ ] **MOVED** Dispute evidence uploads and email notifications to merchants/consumers (B15 templates)

## B12 Merchant funding, 30% fee, invoices (Trello #24 §4, #7 E2E 8, #9)

Fee = 30% **on top of** the consumer reward (card #24: €10 + €3 = €13; €4 + €1.20 = €5.20), via `FeeCalculator`. Percentage rewards use the maximum discount as the basis (`FUNDING_PERCENTAGE_BASIS=maximum_discount`, provisional; OPEN-PERCENT-REWARD-FEE-BASIS stays open). Mollie test mode only; Moneybird is not connected (no credentials; OPEN-FINANCIAL-LEGAL): invoices are prepared as drafts only.

- [x] Merchant funding wallet per webshop (`WalletKind::MerchantFunding`, `eur_credit`, owner webshop) through `WalletService`; credit never expires; no cash-out (`1edd8d9`)
- [x] `sponsorship_obligations` (one per merchant-funded voucher, unique): reward, fee, total, status reserved/settled/released, wallet reservation, invoice link (`1edd8d9`)
- [x] Reserving a merchant-funded voucher (merchant reward, Answer24 claim) reserves reward + fee in the same transaction; insufficient funding → 409 `merchant_funding_insufficient` and no voucher (stop new rewards when unfunded) (`1edd8d9`)
- [x] Redemption settles once (captured = billed); release/expiry of an unredeemed voucher releases the funding; all audited (`1edd8d9`)
- [x] Activating a merchant-reward offer requires funding for at least one reward + fee (409 `funding_insufficient`) (`1edd8d9`)
- [x] Top-up via Mollie (iDEAL, test mode): `POST /workspaces/{w}/funding/top-ups` (finance.manage), webhook shared with checkouts, verified by fetching the payment, credited exactly once (`1edd8d9`)
- [x] `GET /workspaces/{w}/funding` (finance.view): available, reserved, settled, released, top-ups, fee rate; `/funding/transactions`; `/funding/obligations` (`1edd8d9`)
- [x] Monthly invoice drafts of settled rewards + fees per webshop (`funding_invoices`, `shopbonus:prepare-invoices`); Moneybird push disabled until configured; admin and merchant can list them (`1edd8d9`)
- [x] Tests: card #24 E2E 7 (fashion €10+€3, electronics €4+€1.20 once; empty wallet blocks), #7 E2E 8 (funding enforcement), top-up webhook once, cross-tenant isolation, invoice draft totals (`1edd8d9`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS/DECISIONS updated (`1edd8d9`)
- [ ] **BLOCKED** Moneybird push of invoices and real Mollie iDEAL evidence (no credentials; OPEN-FINANCIAL-LEGAL)
- [ ] **BLOCKED** Cross-shop redemption settlement between sponsor and accepting shop (needs the agreed settlement model; OPEN-FINANCIAL-LEGAL)
- [ ] **BLOCKED** Final fee basis for percentage rewards (OPEN-PERCENT-REWARD-FEE-BASIS; provisional maximum-discount basis is configurable)

## B13 Merchant analytics, notifications, owner dashboard (Trello #8, #17, #24 §6, #3 §17)

Every number comes from the authoritative tables (offers, codes, vouchers, claims, orders, funding, Answer24); definitions are returned with the data. Banner/funnel metrics depend on B14 and are reported as `not_available`, never zero.

- [x] `GET /workspaces/{w}/dashboard` (dashboard.view): greeting name; KPIs active offers, available codes, issued vouchers, claimed vouchers (with definitions); offers table (inventory, issued, status; active and attention-needed first); unread notifications; funding balance (`2bfa953`)
- [x] `GET /workspaces/{w}/analytics/claims?days=7|30|90` or `from/to`: daily series in the business timezone with claims, issuance and redemptions kept separate; zero-filled days; chronological (`2bfa953`)
- [x] `GET /workspaces/{w}/analytics/overview?from&to`: paid orders, revenue, AOV, refunds, vouchers reserved/issued/redeemed, redemption rate, reward cost and fees (settled obligations), Answer24 funnel; `banner_metrics: not_available` (`2bfa953`)
- [x] `merchant_notifications` (type, severity, title, body, subject reference, deduplication key, read state) + scheduled `shopbonus:merchant-notifications` for low inventory, new claims (daily digest), offers nearing expiry, connector errors, low funding; configurable thresholds (`2bfa953`)
- [x] `GET /workspaces/{w}/notifications` (unread filter, unread count), `POST .../notifications/{id}/read`, `POST .../notifications/read-all` (`2bfa953`)
- [x] Owner combined dashboard `GET /api/v1/owner/dashboard`: per owned webshop KPIs and totals; only webshops the user owns (`2bfa953`)
- [x] Tests: card #8 KPI definitions reconcile with the inventory, chart timezone/zero days, switching webshops never mixes data, notifications dedupe and stay in their workspace; owner dashboard isolation (`2bfa953`)
- [x] Docs with real captures (`2bfa953`)
- [x] Banner funnel metrics filled by B14; overview adds attribution breakdown, repeats, network contribution and drill-down `GET …/analytics/attribution/orders` (Trello #24)
- [x] Super Admin network overview `GET /api/v1/admin/network` (aggregates only; settlement recorded, never silent cross-shop Wallet deduction)
- [ ] **BLOCKED** Staging p95 for dashboard endpoints (no staging)

## B14 Banner campaigns backend (Trello #25, #24 §1–2)

The live editor UI (`/dashboard/banners`, `/dashboard/banners/{id}/studio`), Shadow DOM renderer and embed script (`public/embed/v1.js`) ship with the frontend/public assets; the backend owns campaigns, versions, rules, public config, events, attribution and results. Checkout, payment and account pages are always excluded. See `docs/banners/E2E_CHECKLIST.md` (exactly 10 E2E tests).

- [x] `banner_campaigns` (draft config with optimistic `draft_revision`, status draft/scheduled/live/paused, linked offer) + immutable `banner_versions` (sanitized published config) (`e5df771`)
- [x] Strict config schema: templates ribbon/inline_card/floating/teaser/popup/exit_intent/cart_progress; anchors; plain-text content with length limits; https CTA URL; colors; fonts; animation; devices; uploaded image asset only (no external URLs) (`e5df771`)
- [x] Trigger engine (nested all/any): URL wildcard, category, product SKU, exclusions, seconds, scroll %, exit intent, device, language, shop-local day/time, frequency once per session, cooldown, prior dismiss, first/returning visitor, verified cart minimum (only with a cart bridge); returns shown/hidden with reasons ("Why shown/hidden"); simulate endpoint marks results as simulated (`e5df771`)
- [x] Merchant API (offers.manage to edit, offers.view to read): list with search/status/placement filters and 30-day stats, create, show, save draft (409 on stale revision), duplicate, publish (blocked when the linked reward is unfunded), schedule (shop-local time; scheduled command publishes), pause, resume, rollback to an earlier version, version history; all audited (`e5df771`)
- [x] Banner assets: image upload (PNG/JPEG/WebP ≤ 2 MB, content-checked), served publicly by opaque ID only (`e5df771`)
- [x] Installation: per-webshop public embed key + allowed origins (verified domain and its subdomains only); `GET /banners/installation` health (last seen origin/time, live campaigns) (`e5df771`)
- [x] Editor pairing: single-use, 10-minute pairing token for a user/webshop/origin; exchange from that origin gives a 30-minute editor token for draft read/save only; replay, wrong origin and expiry refused (`e5df771`)
- [x] Public `GET /api/v1/banners/config?key=` (origin allowlisted, cacheable, ETag): only published versions of live campaigns; never drafts (`e5df771`)
- [x] Public `POST /api/v1/banners/events` (origin allowlisted, throttled, ≤ 50 per batch): eligible, viewable_impression, cta_click, dismiss; event UUID dedupe; no PII (paths stored without query); consented cta_click returns an opaque 30-day click ID (`e5df771`)
- [x] Attribution: the click ID can travel in the order payload (Shopbonus format v1 `order.attribution.click_id`; WooCommerce `meta_data _shopbonus_click_id` and Shopify `note_attributes shopbonus_click_id` NOT VERIFIED); a paid order within 30 days of the click counts once for that campaign; everything else stays unattributed (`e5df771`)
- [x] Results `GET /banners/{id}/results`: eligible, viewable impressions, CTA clicks, dismissals (never clicks), CTR = clicks / viewable, attributed paid orders + revenue; date/device filters; daily rollups; fills B13 `banner_metrics` (`e5df771`)
- [x] Tests: card #25 E2E 3 (draft privacy), 4 (schedule/publish/pause/rollback + audit), 5 (trigger library and reasons), 6 (cart fallback/simulated), 7 (100/70/7/20 → 70 impressions, 10% CTR, 0 clicks from closes; cost €13), 8 (other tenant, visitor, expired pairing, forged origin); card #24 E2E 1–3 (`e5df771`)
- [x] Docs with real captures (`e5df771`)
- [x] AI Create / AI Insights and A/B + holdout backend implemented/tested in B14b; provider credentials/model and storefront/staging verification remain senior/frontend handoff (see B14b evidence below).
- [ ] **BLOCKED** Card #25 E2E 1, 2, 9, 10: real storefront editor, AI designs, platform install checks (frontend/plugins, AI provider, store access)

## B15 CMS, legal pages, FAQ, cookie consent, NL/EN (Trello #5, #4, #18)

Editable content is a strict JSON document (ProseMirror/Tiptap-style node allowlist), never HTML, so stored content cannot carry scripts. Only Super Admins edit; publishing needs step-up.

### B15a Pages, legal documents, FAQ

- [x] `cms_pages` (type privacy/terms/cookie_policy/help/faq/info/landing, stable key, audience, archived) + `cms_page_locales` (NL/EN slug unique per locale, private draft with optimistic revision, published revision) + immutable `cms_revisions` (content, SEO, legal version, effective date, approver) (`4604215`)
- [x] Rich-text schema: doc → paragraph, heading 2–4, bullet/ordered list, list item, text with bold/italic/link (https or relative only), image (own CMS asset + alt), button; unknown nodes, marks, attributes and unsafe URLs → 422 (`4604215`)
- [x] Admin: list/search with translation status (missing, draft newer than published), create, save draft (409 on stale revision), preview (admin only, `no-store`, `noindex`), publish (step-up; legal pages need version, effective date and explicit approval), revisions, restore as a new revision, archive; audited (`4604215`)
- [x] Public: `GET /public/pages/{locale}/{slug}` (published only, NL fallback flagged), `GET /public/pages?type=&locale=`; cached with version-bump invalidation (`4604215`)
- [x] Legal: the published Terms/Privacy versions become the versions registration requires (`LegalConsents`), env config as fallback; existing acceptances unchanged (`4604215`)
- [x] FAQ: categories (NL/EN names, order) and items (NL/EN question + rich-text answer, order, audience public/consumers/merchants, published flag); admin CRUD + reorder; public `GET /public/faq?locale=&search=` where audience restrictions are enforced server-side (`4604215`)
- [x] CMS image assets (admin upload, PNG/JPEG/WebP, content-checked) (`4604215`)
- [x] Tests: card #5 E2E 2, 3, 4, 5, 6, 7, 8 (`4604215`)
- [x] Docs with real captures (`4604215`)
- [x] Found by the MySQL run and fixed: FAQ reorder treated MySQL's 0 affected rows (unchanged position) as a foreign item (`4604215`)
- [ ] **BLOCKED** Assisted translation drafts (needs the AI provider, B17) and staging p95 < 200 ms (no staging)

### B15b Cookie consent and NL/EN API

- [x] `cookie_policies`: version, status draft/published/retired, categories necessary/preferences/analytics/marketing with allowlisted trackers (name, provider, NL/EN purpose, retention), NL/EN banner texts, `requires_reconsent`; Super Admin create/edit draft/publish (step-up; previous published → retired); audited (`b2abb82`)
- [x] Public `GET /public/cookie-policy?locale=`: current published policy in the locale (no policy → only necessary) (`b2abb82`)
- [x] Append-only `cookie_consents` keyed by an opaque visitor consent ID (+ user when signed in): choices, policy version, action accept_all/reject_optional/customize/withdraw, hashed IP; necessary always on (`b2abb82`)
- [x] Public `POST /public/cookie-consents` (only the current policy version, 409 otherwise; throttled) returns the consent ID and the trackers allowed to load; `GET /public/cookie-consents/{id}` with `needs_renewal` (`b2abb82`)
- [x] Locale middleware: `Accept-Language` (nl/en) sets the API locale and `Content-Language`; `lang/nl/validation.php` and `lang/nl/auth.php` for the rules in use; requests without a preference keep English (`b2abb82`)
- [x] Tests: card #5 E2E 9 (cookie lifecycle), E2E 1 backend part (NL/EN 422 messages) (`b2abb82`)
- [x] Docs with real captures (`b2abb82`)
- [x] Error envelope and domain messages pass through `__()`; `lang/nl.json` translates the generic messages and the consumer-facing domain messages (`b2abb82`)
- [ ] **MOVED** Translating every remaining domain error message to NL (add keys to `lang/nl.json`; ongoing, owner/copywriter review)
- [x] Frontend Content Studio (`/admin/cms` pages/FAQ/cookies), inline edit on `/pagina/[slug]`, public `/faq`, cookie settings + allowlisted loader, NL/EN chrome dictionaries + `/me` locale hydrate (2026-10-30)
- [x] Frontend Playwright smoke `e2e/cms-i18n-consent.spec.ts` (locale, draft chrome, preview auth, FAQ, consent settings, admin gate)
- [ ] **BLOCKED** Assisted translation drafts (B17 AI) and staging p95 &lt; 200 ms (no staging)

## B16 Super Admin cockpit, security, monitoring (Trello #21, #6, #23)

### B16a Admin MFA, sensitive step-up, support sessions

- [x] TOTP two-factor via Fortify (enable, confirm, QR, secret, recovery codes, login challenge); secrets never in API resources
- [x] Super Admin routes require confirmed 2FA (`ADMIN_MFA_REQUIRED`, default on; 403 `mfa_required`)
- [x] Sensitive step-up = recent password + email OTP (`step_up_challenges`: HMAC code, 10 min, 5 attempts, single use); `POST /admin/step-up/code`, `POST /admin/step-up/verify`; middleware `step_up.otp` (423 `step_up_code_required`)
- [x] Support sessions (`impersonation_sessions`): start needs reason + password + email OTP; target must be an active non-admin; 30-minute scoped token; every action audited with the real admin as `real_actor_user_id`; `GET /me` shows the active session; financial, security, ownership, team and agreement actions blocked (403 `blocked_during_impersonation`); end revokes immediately; expiry enforced
- [x] Tests: MFA gate, OTP step-up (wrong/expired/reused), impersonation lifecycle and blocked actions, audit real actor
- [x] Docs with real captures (secrets redacted); Fortify 2FA routes covered by OpenApiCoverageTest
- [x] Consumer profile `security.two_factor` reports enabled/disabled

### B16b Cockpit, users and webshop administration (Trello #21 "Cockpit", "Webshop administration", "Users, memberships & access")

- [x] `GET /admin/cockpit` KPI cards from real records with stated definitions (webshops by status, consumers, published offers, vouchers issued/redeemed, inventory, orders received/paid, Answer24, affiliate pending/approved/rejected separately, wallet issued/spent/outstanding per unit, open disputes, incidents); period today/7d/30d/custom (≤ 366 days); daily trends; cached 60 s with `generated_at`
- [x] Attention lists with links: pending approvals, integration failures, failed jobs, reconciliation flags, paid-unfulfilled checkouts, affiliate problems, security denials
- [x] `GET /admin/activity` recent audit events (paginated, filters)
- [x] Users: search/filter (role, status, webshop, registration date; paginated), detail (memberships, roles, 2FA, wallets; no hashes); suspend/reactivate with reason (sessions and tokens end immediately); reset security access (2FA off + tokens revoked; step-up code); revoke membership (last active owner protected); revoke Super Admin (step-up code; last admin protected; grant stays console-only)
- [x] Webshops: list columns owner, integration status, active offers, last activity; approval checklist in detail; request additional information (notifies the merchant)
- [x] Tests incl. E2E 1 (cockpit) and E2E 3 (user security), mutation check
- [x] Docs with real captures

### B16c Financial, integration and Answer24 oversight (Trello #21 "Financial & voucher oversight", "Integrations & Answer24 monitoring")

- [x] Wallet list and per-wallet ledger: paginated, explicit units, public IDs, Super Admin only; audit inspection; no secrets/internal IDs.
- [x] Reconciliation flags: stable public IDs/backfill, list/filter, reasoned audited resolution; reject unresolved balance mismatches; preserve history.
- [x] Adjustment approval requests: immutable amount/unit/source verification and reason; password + OTP on request/approval; requester cannot self-approve; dual-admin-v1 for every amount/unit (D-064); WalletService::adjust only; replay/race tests.
- [x] Financial exceptions: affiliate mismatches, failed issuance, paid-unfulfilled checkout and Mollie payment/refund evidence, suspicious redemption signals; reuse dispute/affiliate APIs.
- [x] Connection monitoring: last successful event, business-day order counts, failed/incomplete events, delay, auth errors and required action; sanitized evidence.
- [x] Answer24 monitoring: grouped contact states, eligibility/suppression failures, failed dispatch/callback evidence; no raw contact payloads.
- [x] Safe retries: locked failed webhook requeue with retained verified payload; locked failed outbox requeue with original idempotency key; current consent/eligibility rechecked before dispatch; dispatch-off remains respected.
- [x] Every new endpoint documented with captured running-server examples and access metadata; error/security negative tests and mutation checks.
- [x] SQLite + MySQL suites, Pint, PHPStan, composer audit, migration rollback/reapply; WIP/final commits and CI; handoff/task/progress evidence.

Evidence: `4786e65` (CI run `40195059904` passed); 2026-10-25 SQLite 445 pass + 8 skip, MySQL 453 pass, 9 guard mutations detected/restored; focused contract/wallet/dispute rerun 12 pass. API captures and test names are in HANDOFF/PROGRESS. Implementation pushed; CI passed. This checkpoint finalizes B16c documentation.

### B16d Incidents, route registry, audit integrity, platform settings (Trello #21 "Central errors & security", "Audit & security controls", "Platform content & settings")

- [x] Group security incidents by route/method/status or technical fingerprint; append-only sanitized occurrences with request IDs for 401/403/419/422/429/500; correlate queue failures; avoid duplicate HTTP recording.
- [x] Admin incident search, detail, filters, assignment and New/Investigating/Resolved/Ignored workflow; reasoned audited transitions and authorization/negative tests.
- [x] Generate API route registry from actual runtime routes and OpenAPI access/permission metadata, including scope, audit and monitoring categories; detect undocumented/mismatched entries.
- [x] Append-only audit hash chain, historical backfill, serialized writer, verify command detecting content edits/gaps/tail deletion; authorized bounded export preserving safe IDs and integrity evidence.
- [x] Allowlisted platform settings versions: immutable draft evidence, independent review, publish, rollback by new version; retain historical snapshots and existing CMS/agreement ownership; no secrets/live-provider/financial/consent activation.
- [x] Real HTTP examples for all added endpoints, OpenAPI/access coverage; mutation/security/concurrency tests and full SQLite/MySQL/Pint/PHPStan/audit/migration gates.
- [x] WIP/final commits, normal push, CI and self-contained handoff; AI remains B17/provider-blocked.

Evidence: `7b2c8d1`, pushed; implementation CI `40196742388` passed; SQLite 459 passed + 10 skipped, MySQL 467 passed + 2 skipped, 3 MySQL process races passed, 9 B16d guard mutations detected/restored; 11 real-server API captures; 256 generated route contracts match; migrations/verify/Pint/PHPStan/audit passed.

### B16e Card #21 AI-assisted incident diagnostics (OpenAI selected by owner, 2026-10-25)

- [x] Eligible technical incidents only; server-built allowlisted evidence excludes messages, raw payloads, credentials, customer/user/workspace/request IDs and unknown routes.
- [x] Disabled-by-default OpenAI Responses transport, configurable model/key, strict structured output, no tools, no automatic retries, bounded token/time and global daily request quota.
- [x] Idempotent audited queued requests and single provider execution; immutable context/result evidence and safe failed states; append-only human review; no business/security/deployment/incident-closing capabilities.
- [x] Admin-only list/detail/request/review routes, password + OTP on mutations, rate limits, validation, stale-context indicator and negative/security/race tests.
- [x] Running-server API captures, OpenAPI and frontend handoff; SQLite/MySQL, Pint/PHPStan/audit, migration rollback/reapply, guard mutation tests, commits/push/CI.
- [ ] Real OpenAI verification (requires API credentials + model/budget); staging p95/recovery (owner confirms no staging) — NOT RUN until prerequisites exist.

Evidence: implementation `dea03b5`, CI `40199851559` passed; final strict-quota validation full rerun SQLite 469 pass + 11 skip, MySQL 478 pass + 2 skip; 8 guard mutants detected/restored, migration rollback/reapply and Pint/PHPStan/audit passed. Final checkpoint records refreshed real domain-conflict captures. Live provider and staging checkbox intentionally remains unchecked.

### B17a — #26 authorized evidence and independent answer verification (2026-10-26)

First small backend boundary; no chat route/model activation yet. Existing wallet, voucher, fee and FAQ records remain authoritative.

- [x] Audit and reuse existing reads; implement a closed read-only tool registry for consumer wallet/voucher, workspace funding/offer costs and published FAQ. (`9caa4fd`; CI `40284066219` passed.)
- [x] Recheck active verified identity, membership, workspace state and individual permissions on each retrieval and answer verification; audit denials without data leakage. (`9caa4fd`; CI `40284066219` passed.)
- [x] Keep private scope/source metadata separate from minimal provider context; exclude voucher codes, customer/contact/payment identifiers and arbitrary actions. (`9caa4fd`; CI `40284066219` passed.)
- [x] Independently render selected facts from freshly authorized evidence; reject invented fields, values, links, stale/conflicting evidence and unknown tools. (`9caa4fd`; CI `40284066219` passed.)
- [x] Execute SQLite/MySQL tests and quality/API contract checks; update TASKS/PROGRESS/HANDOFF and commit/push/verify CI. (`9caa4fd`; CI `40284066219` passed.)
- [x] B17b–f main backend chat/provider/retry/history/private progress/feedback/review/settings/knowledge and100-question/backend E2E coverage delivered. (`d63f616` + `7616e88`; CI `40388179684` passed.) Frontend/live/deployed acceptance remains separate.

- [x] B18 backend slice (B28, 2026-10-29): full business journey test, role access matrix over every route, security headers/proxy trust, launch-check command, API inventory, launch/deploy/rollback runbook, composer audit clean.
- [ ] B18 remainder: staging load test, live provider/Mollie/Answer24 runs, frontend/design QA, deployment and go-live sign-off (external).
- [x] B29 CMS scheduling (#33–#35, 2026-10-29): schedule publish/archive for a future time pinned to the reviewed draft revision; cancel; every-minute `shopbonus:publish-scheduled-content`; stale on later draft edits; legal approval required up front; Terms/Privacy archive refused; scheduler must still be an active Super Admin; interrupted runs reported; all audited.
- [x] B29 CMS "Improve with AI" (#33–#35): improve (same locale) or translate (NL↔EN) suggestions through the Responses API with store:false and a strict schema; segment count/structure, links and facts preserved; daily quota, per-user idempotency, 503 when not configured; apply only writes the draft (never publishes) with revision check; reject; all audited.
- [x] B30 Quality Center roles and discovery (#37, 2026-10-29): `qa_tester`/`developer` platform roles (2FA, console grant, no other admin access); inventory per Git SHA of routes, OpenAPI operations, jobs, events, listeners, commands, schedules, migrations and connectors; new/changed/removed/undocumented diff; nothing deleted; `UNREFERENCED — REVIEW` only after a frontend usage report and never for webhook/integration routes.
- [x] B30 coverage and results (#37): approved test cases only; coverage links and map import; JUnit import with strict status rules (no assertions never PASS, BLOCKED/NOT_IMPLEMENTED prefixes, MOCKED label, STALE after change or age); duplicate reports refused; redacted messages; JSON/JUnit/HTML export; encrypted, checksum-verified, deduplicated evidence with retention.
- [x] B30 findings, AI and schedules (#37): grouped findings assigned to developers; fix commit; independent retest derived from a run imported after the fix; regression plan; advisory GPT analysis restricted to its input and never changing status; Super Admin schedules (production safe suite only); read-only probe every 5 minutes with incident alert; protected OpenAPI JSON.
- [ ] B30 remainder (#37): Quality Center UI and qa.shopbonus.nl portal hosting (Hassan/infra); 25 PDF scenarios, Postman/browser suites and ten provider subruns on staging; CI wiring; live model verification; release approval.
- [ ] B19 Integration marketplace listing (BLOCKED: empty card)

## B10b — #19 credential-independent TradeTracker transport (2026-10-25)

Owner: implement main backend code; senior developer configures credentials. No schema/new routes planned.

- [x] Pin official HTTPS WSDL and implement bounded, read-only SOAP transport with isolated authentication cookies, no redirects/retries and safe errors. (`50957b4`; CI `40202694616` passed.)
- [x] Page campaigns/conversions, include old conversions assessed since last sync, validate provider shapes and reject incomplete pagination. (`50957b4`; CI `40202694616` passed.)
- [x] Correct WSDL policy/status mappings; preserve existing admin imports and wallet/idempotency rules. (`50957b4`; CI `40202694616` passed.)
- [x] Test serialized SOAP, authentication failure, pagination, late assessment, malformed data, secret redaction and disabled connections. (`50957b4`; CI `40202694616` passed.)
- [x] Run SQLite/MySQL suites, Pint, PHPStan, composer audit; capture changed API behavior from real local server. (`50957b4`; CI `40202694616` passed.)
- [x] Update API docs/configuration/verification instructions, TASKS/PROGRESS/HANDOFF; commit/push and verify CI. (`50957b4`; CI `40202694616` passed.)
- [ ] Senior developer: configure credentials and record genuine provider verification (NOT RUN here).

## B14b — #25 AI proposals and experiments (2026-10-26)

Owner authorizes main backend implementation; credentials configured by senior developer. Owner explicitly overrides the ~5 GiB disk cutoff for this task ("continue anyways"); no unrelated cleanup authorized.

- [x] Queue idempotent AI Create/Insights with globally serialized request limits, configured model/key, minimal aggregate context and one provider attempt. (`d561f8d`; CI `40281914885` passed.)
- [x] Validate exactly three editable proposals; preserve offer terms, costs and asset ownership; human review creates private drafts only. (`d561f8d`; CI `40281914885` passed.)
- [x] Recheck workspace membership/permissions/account state before execution; privacy, schema/refusal/tool/timeout, stale review and tenant-negative tests. (`d561f8d`; CI `40281914885` passed.)
- [x] Add immutable experiment versions, consented randomized assignment, validated event linkage and honest A/B/holdout results. (`d561f8d`; CI `40281914885` passed.)
- [x] Document all changed/new APIs using actual local HTTP captures with clearly labelled simulated provider output. (`d561f8d`; CI `40281914885` passed.)
- [x] Execute SQLite/MySQL sequentially, quality/security checks, migration rollback/reapply; checkpoint docs, commits/push and CI. (`d561f8d`; CI `40281914885`: SQLite503 pass+13 skip, MySQL514 pass+2 skip.)
- [ ] Senior developer: live OpenAI and storefront/cart/provider attribution/staging verification (NOT RUN here).

## B17b–f — complete #26 main backend (2026-10-26)

Owner explicitly asks to finish remaining backend; credentials/model configured by senior developer. D-075 disk override persists for #26. One coherent boundary at a time; boxes remain open until executed checks.

- [x] B17b: extend read-only tools for owned order/reward status, vouchers, quotes, campaign and integration health; typed server research plan, missing-reference clarification and one distinct targeted retry; no email-based ownership guesses. (`d63f616` + `7616e88`; CI `40388179684` passed.)
- [x] B17c: encrypted persisted owned conversations/messages/evidence, request idempotency, queued single execution, strict Responses evidence selector, global/per-user quotas, circuit breaker and retention/deletion. (`d63f616` + `7616e88`; CI `40388179684` passed.)
- [x] B17d: authenticated chat/history/knowledge/feedback/escalation APIs, private minimal progress events and Reverb backend configuration; reconnect HTTP sync and negative subscription tests. (`d63f616` + `7616e88`; CI `40388179684` passed.)
- [x] B17e: human review/resolution and executed approved regression cases, operational metrics; reuse independently reviewed settings, preserve immutable FAQ snapshots and published rollback; test-before-publish critical restrictions and immutable server safeguards. (`d63f616` + `7616e88`; CI `40388179684` passed.)
- [x] B17f:100 distinct EN/NL regression questions, ten card backend E2E cases, isolation/error/provider/race tests; real HTTP captures for every endpoint and changed settings; SQLite/MySQL sequential suites, migration apply/rollback/reapply, quality/security checks, commit/push/CI/handoff. (`7616e88`; CI `40388179684` SUCCESS, SQLite658+15skip/MySQL671+2skip.)
- [ ] Senior developer: genuine configured OpenAI/Reverb deployment/provider/production monitoring and staging/frontend acceptance (NOT RUN locally; no credentials requested).


## B20 — #29 central notifications (2026-10-26)

Owner authorizes #29 below the ~5 GiB disk cutoff; senior configures credentials. One tested boundary at a time. Whole card remains PARTIAL until all slices have evidence.

- [x] B20a: reuse Laravel database notifications for an owned persistent inbox, strict pagination/filtering, individual/all read state and NL/EN support-reply templates. (`5c1d8cc`; full SQLite672+16skip / MySQL686+2skip; quality and API coverage pass.)
- [x] B20a: persist human-support notifications atomically with replies; stable recipient/event deduplication, no message/code/financial content in payloads. (`5c1d8cc`; full SQLite672+16skip / MySQL686+2skip; quality and API coverage pass.)
- [x] B20a: preferences reuse user locale and existing communication consent; mandatory in-app rules cannot be disabled. (`5c1d8cc`; full SQLite672+16skip / MySQL686+2skip; quality and API coverage pass.)
- [x] B20a: private minimal Reverb invalidations, owner-only subscription, revoked membership/admin-role filtering and support-token denial. (`5c1d8cc`; full SQLite672+16skip / MySQL686+2skip; quality and API coverage pass.)
- [x] B20a: executed security/rollback/idempotency/read/preferences/realtime tests on SQLite/MySQL, real HTTP examples, quality checks and migration rollback/reapply. (`5c1d8cc`; full SQLite672+16skip / MySQL686+2skip; quality and API coverage pass.)
- [x] B20a closeout: push implementation, verify CI and record SHA/test evidence in handoff. (`5c1d8cc`; CI `40399876856` SUCCESS all3jobs; this docs-only closeout.)
- [x] B20b: migrate/bridge merchant notifications without duplicate inbox systems or changed legacy read semantics; connect full consumer/workspace/admin event catalogue. (`911e053`; local SQLite/backend/HTTP evidence below; CI `40412989638` SUCCESS all3jobs.)
- [x] B20c: durable queued email delivery/outbox, bounded retries/failure recovery, mandatory/optional rules, actual render tests and retention. (`911e053`; local SQLite/backend/HTTP evidence below; CI `40412989638` SUCCESS all3jobs.)
- [x] B20d: deduplicated 30/7/1 voucher and offer reminders; eligibility and consent rechecked at dispatch. (`911e053`; local SQLite/backend/HTTP evidence below; CI `40412989638` SUCCESS all3jobs.)
- [x] B20e: Super Admin NL/EN AI template drafts/test/approval/publish/rollback, strict placeholders, budgets, delivery/settings APIs and audit. (`911e053`; local SQLite/backend/HTTP evidence below; CI `40412989638` SUCCESS all3jobs.)
- [x] B20f: full ten backend E2Es, event/API inventory and handoff; genuine mail/model/deployed Reverb/frontend acceptance remains senior/Hassan-owned. (`911e053`; CI `40412989638` SUCCESS, SQLite701+18skip/MySQL717+2skip,719tests each. Source approval follow-up14local tests375assertions; this closeout.)

### B20b detailed boundary — source outbox and merchant bridge

- [x] Persist event intents in the source transaction; no mail/network/fanout while holding wallet/audit locks. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Wire registration/verification, posted consumer credit/correction, voucher state events, workspace decisions/review, offer publication/favorites, integration/affiliate failures and support escalations. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Capture recipient snapshots, safely revalidate current recipient/workspace/admin eligibility, reject unknown event schemas and preserve source references. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Project existing merchant alerts into the owned inbox once; keep legacy shared-read API behavior and synchronize the acting user's central read state. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Recover pending events with an idempotent bounded scheduler/command; prove source rollback, replay, failures and permission isolation. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Capture changed HTTP contracts; execute local SQLite checks, feasible MySQL checks and CI; update TASKS/PROGRESS/HANDOFF, commit/push and record SHAs. (`911e053`; CI `40412989638` SUCCESS, SQLite701+18skip/MySQL717+2skip,719tests each. Source approval follow-up14local tests375assertions; this closeout.)

### B20c–f remaining detailed acceptance

- [x] Mail: same-transaction delivery rows, single-worker claim/stable message ID, real HTML/plain-text render, mandatory/optional consent recheck and honest ambiguous transport outcome review. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Reminders:30/7/1 calendar stages pinned to expiry, replay/eligibility checks at generation and delivery; reused deduplicated merchant expiry source. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Retention: preserve active/unresolved deliveries and dedupe tombstones; owned deletion with409 safety and recovery of stale sending claims. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Templates: immutable existing settings versions, strict variables/plain text/lengths, synthetic preview, checksum-bound test-before-independent-publish, fresh-version rollback and locale fallback monitoring. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] OpenAI: disabled/configured model, fixed HTTPS strict schema/no redirects/retry/no PII, global UTC budget and per-admin UUID idempotency, safe failures and no auto publication. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Admin: exact template/event/delivery/settings API contracts, existing platform/password/OTP/support-session guards, audited retries without claiming ambiguous mail was unsent. (`911e053`; executed local tests/HTTP captures; real MySQL publication/quota/mail-claim races PASS in CI `40412989638`.)
- [x] Complete source/event inventory and ten backend E2Es; real running-server captures for all APIs, SQLite/MySQL/CI and migration evidence, checkpoint docs and clean committed handoff. (`911e053`; CI `40412989638` SUCCESS, SQLite701+18skip/MySQL717+2skip,719tests each. Source approval follow-up14local tests375assertions; this closeout.)

- [x] B20 final CI: complete exact-code SQLite/MySQL suites, including real4-process publication/mail claims and serialized generation quota; record SHA/results and clean handoff. (`911e053`; CI `40412989638` SUCCESS, SQLite701+18skip/MySQL717+2skip,719tests each. Source approval follow-up14local tests375assertions; this closeout.)

B20 final verified closeout: application911e053 +approval-scope follow-up5a8c36d. CI40414186822 SUCCESS all3jobs (719tests/database; SQLite701+18skip/MySQL717+2skip). All main backend boxes above are evidence-backed; whole-card frontend/live configuration acceptance remains senior/Hassan-owned.

## B21 — frontend integration issue report (2026-10-27)

Owner reports ~/Downloads/BACKEND_API_ISSUES.md and authorizes opt-in local integration fixtures. Report recommendations are evidence, not approval to publish real legal text or activate providers. One tested boundary at a time; no fabricated production content/credentials.

### B21a — funding top-up request identity
- [x] Preserve headerless v1 compatibility; accept/validate optional UUID Idempotency-Key scoped to workspace+actor, reject changed amount409. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
- [x] Persist immutable intent before provider I/O, permit one create attempt, forward distinct stable provider key; replay never creates another request even after provider cache expiry/config changes. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
- [x] Surface creating/created/uncertain creation status honestly; failed/unknown transport never credits a wallet or silently retries. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
- [x] Execute replay/conflict/timeout/security/provider-header/real MySQL race tests; migration apply/rollback/reapply and real HTTP captures. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
- [x] Update API contract/issue resolution TASKS/PROGRESS/HANDOFF and pass quality/full local checks; remote CI is recorded separately below. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
### B21b — public legal discovery
- [x] Publish registration requirement metadata from canonical LegalConsents; identify CMS vs configuration fallback and link only actual published content, no fabricated legal documents. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
- [x] Tests for missing/fallback/published/current versions, publication changes, NL/EN visibility and signup fail-closed; real HTTP docs. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
### B21c — public promotional claims
- [x] Verify full offer/publication/acquisition eligibility and use canonical VoucherInventory for owned promotional claims; never wallet-funded or Answer24 claims. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
- [x] Atomic per-user/offer dedupe, secret-free response linking to owned voucher API; stock/replay/race/paused/unpublished/wrong-acquisition/auth negative tests and captures. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
### B21d — opt-in local fixtures
- [x] Local/testing only, explicit command and safety checks; labelled sample legal/CMS/FAQ/catalog/offer/inventory and verified test identities without resetting existing user accounts or overwriting real content. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
- [x] Use canonical CMS/offer/workspace/account services and inventory, preserve audit/source records, and do not synthesize wallet credits; no live provider activation or real outbound messages. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)
- [x] Re-runnable setup, private credential delivery file, actual browser signup/login/discovery/claim verification and senior setup guide; no sample content presented as approved legal text. (`7991841`; clean-environment regression `a7ebb0e`; CI40559448895 SUCCESS, evidence in PROGRESS.)

### B21 final verification
- [x] Verify pushed implementation SHA in all three CI jobs; record exact SHA/evidence and clean git handoff. (`7991841` + `a7ebb0e`; CI40559448895 SUCCESS all three jobs, this docs-only closeout.)

## B22 — Trello #30 reliability (started 2026-10-27)

### Reliability review follow-up — 2026-11-02 (uncommitted)
- [ ] Reverify code secrecy, PHP 8.4/8.5 SOAP transport and complete OpenAPI coverage.
- [ ] Restore encrypted dispute and QA attachment bytes with their database references intact.
- [ ] Monitor configured connection/queue pairs and worker-loop observations without treating absent evidence as healthy.
- [ ] Connect the existing operations API to the admin cockpit with loading, failure and UNKNOWN states.
- [ ] Record owner-approved management allocation scope; keep formal revenue recognition and infrastructure RPO/RTO acceptance open.

### B22a — observed API monitoring and threshold incidents
- [x] Inventory/reuse existing audit, incidents, readiness, queue failures, ledger reconciliation, scheduler and dependency tooling; preserve unresolved affiliate timing and do not activate financial flows. (`c7e8789`; CI40580008502 SUCCESS all three jobs, evidence in PROGRESS.)
- [x] Opt-in metadata-only request observations with monotonic processing time, DB query timing, privacy-safe route templates, bounded retention and fail-open writes; record rendered error responses without payloads/SQL/credentials/identity. (`c7e8789`; CI40580008502 SUCCESS all three jobs, evidence in PROGRESS.)
- [x] Super Admin monitoring report with bounded time/sample scope, exact observed P50/P95/P99, request/error/observed timeout rates, slow queries, configurable thresholds and UNKNOWN for absent/partial observations. (`c7e8789`; CI40580008502 SUCCESS all three jobs, evidence in PROGRESS.)
- [x] Schedule configurable minimum-sample 5xx/latency alerts into existing grouped incidents with concurrency-safe cooldown; do not alert merely on ordinary401/403. (`c7e8789`; CI40580008502 SUCCESS all three jobs, evidence in PROGRESS.)
- [x] Execute deterministic percentile/error/privacy/retention/failure/security/threshold and real MySQL race tests; local real HTTP captures and mandatory API docs; migration rollback/reapply. (`c7e8789`; CI40580008502 SUCCESS all three jobs, evidence in PROGRESS.)
- [x] Full SQLite/MySQL and local quality checks, measured report query plan/cost, committed checkpoint docs and exact remaining #30 work. Remote CI recorded separately. (`c7e8789`; CI40580008502 SUCCESS all three jobs, evidence in PROGRESS.)

- [x] Verify exact B22a implementation SHA in all three CI jobs and record closeout evidence. (`c7e8789`; CI40580008502 SUCCESS.)

### B22b — source-backed financial reconciliation
- [x] Audit actual financial source/ownership/historical-rate snapshots; preserve separate affiliate, consumer checkout and merchant sponsorship semantics; never infer EUR from points or a missing legacy rate.
- [x] Make existing wallet mismatch detection lock-consistent and deduplicate identical unresolved evidence without touching cached balances/ledger or bypassing authorized correction workflows. (`5aa4177`; CI40587710798 SUCCESS, four-worker MySQL race and full suites passed.)
- [x] Retain immutable new reward timing/rule/credit evidence and sponsorship fee rates; protect monetary versions, preserve legacy nulls and keep owner-approved after-approval default. (`5aa4177`; CI40587710798 SUCCESS; historical-rate/timing/mutation regressions passed.)
- [x] Re-check changed approved commission against its original review threshold before spendable credit; preserve authorized review path. (`5aa4177`; failing regression reproduced then passed; authenticated HTTP200 acceptance; CI40587710798 SUCCESS.)
- [x] Dispute unsupported currency without credit; manual acceptance cannot invent FX and returns409. (`5aa4177`; failing regression reproduced then passed; authenticated HTTP409; CI40587710798 SUCCESS.)
- [x] Durable wallet-run foundation: unique bounded run identity, fixed source watermark, atomic per-wallet cursor/evidence, restart/replay safety, immutable checks and explicit missing-source coverage; command/scheduler and failure tests. (`cfbd770`; CI40657966315 SUCCESS all three jobs. SQLite757total734pass+23skip; MySQL757total755pass+2skip, four-worker race and isolated migration down/up PASS.)
- [x] Durable idempotent reconciliation runs and immutable discrepancy evidence for wallet/affiliate/checkout/funding/sponsorship links; bounded background processing and honest failed/unknown coverage.
- [x] Check historical calculations, source attribution, duplicate credits, compensating reversals and purchase/redemption allocations using existing canonical records; no automatic balancing transactions or policy activation.
- [x] Reuse Super Admin/MFA/password/step-up/incident controls for run/detail/report APIs; audit manual triggers and use public references only; schedule automatic execution with recovery semantics.
- [x] Executed source/change/duplicate/mismatch/legacy/negative/race tests, real HTTP docs, migration rollback/reapply, full suites and exact-code CI; retain original financial history. (`b2ad156` + `64b1848` + `e832cca`; final CI40666899671 SUCCESS all three jobs,768tests/database.)

- [x] Verify exact prerequisite implementation SHA in all three CI jobs; record evidence and clean handoff. (`5aa4177`; CI40587710798 SUCCESS all three jobs; this docs-only closeout. Full B22b/whole #30 remain unfinished.)

### Additional #30 main-code boundaries (implemented after B22a)
- [x] Cross-source historical financial reconciliation with immutable evidence and authorized correction reuse; no new affiliate timing activation.
- [x] Queue wait/provider/cache/DB availability observations, complete operations overview and security/audit coverage evidence.
- [x] Measured schema/query/integrity optimizations on representative isolated data.
- [x] Encrypted isolated backup/restore implementation and evidence; infrastructure-specific offsite/scheduling/RPO/RTO capability verification remains explicit.
- [x] Ten card acceptance scenarios and final main-code/live acceptance mapping; production evidence must never be inferred from local tests.


Checked completion items above refer to **implemented main code/local evidence only**, `b2ad156` with traversal/config follow-ups `64b1848`/`e832cca` (CI40666899671 SUCCESS all three jobs). COMPLETION.md explicitly maps missing real host/offsite/provider/accounting/RPO-RTO acceptance; whole Trello #30 remains pending. Final exact-code CI40666899671 has passed; external acceptance below remains unchecked.

### B22 completion boundaries — owner “do all”, 2026-10-28
- [x] Versioned source-scan cursors/watermarks preserve legacy wallet-only run scope; bounded failure/replay recovery across affiliate, checkout, funding, sponsorship and ledger sources.
- [x] Immutable source findings for historical rules/ownership/attribution/duplicates/reversals/allocation; unknown legacy proof remains explicit, no automatic financial corrections.
- [x] Protected paginated reconciliation trigger/list/detail/resume APIs; stable per-admin request identity, password/OTP, audit and real running-server examples.
- [x] Bounded queue wait/depth/failure observations, provider failure indicators, dependency probes and scheduler heartbeat; operations overview/threshold incidents with UNKNOWN for unavailable evidence.
- [x] Operator-only encrypted authenticated streaming backup with private temporary files, configurable remote disk/retention, manifest/file hashes and recorded failures; no plaintext credential export.
- [x] Isolated restore validation/command and safe target restrictions; encrypted database/media integrity test, corruption/wrong-key/path rejection and recorded recovery duration.
- [x] Security/route/audit matrix, real query plans/representative benchmark, ten backend acceptance mappings, SQLite/MySQL suites/quality/CI, senior deployment/recovery checklist. (`b2ad156` + `64b1848` + `e832cca`; CI40666899671 SUCCESS, evidence in PROGRESS/COMPLETION.md.)

Main-code completion items: `b2ad156` (implementation), `64b1848` (bounded traversal), `e832cca` (isolated env configuration); final CI40666899671 SUCCESS. Entire #30 remains pending the following external evidence.

### #30 external operational acceptance — not claimed by main-code completion
- [ ] Senior configures actual keys/providers/queue/scheduler/TLS/admin MFA and deployed monitoring/alert delivery.
- [ ] Hosting/media/infrastructure inventory and independent offsite encryption/access/retention/readback verification.
- [ ] Production-server/version/volume isolated restore plus full application/queue/provider-outage recovery drill, approved feasible RPO/RTO and cost.
- [ ] Actual network cash settlement/accounting/legal/points conversion approval where applicable; no invented recognized revenue.
- [ ] Whole-card UI/deployed/live acceptance; backend code alone does not mark the entire Trello card Done.

## B23 — #31 provider engineering (started 2026-10-28)

### B23a — existing adapter audit and WooCommerce read API
- [x] Import/review full #31 and overlapping #32 requirements; audit real registry/adapters/status/ingestion before changing contracts. Preserve D-089; no order-created cashback or fabricated provider verification.
- [x] Extend existing catalogue with precise reviewed source/API/capability metadata; distinguish implemented code, merchant configuration and live verification. Do not label generic modules native adapters.
- [x] Authenticated WooCommerce REST v3 read-only order/refund/catalog lookup and bounded modified-order pagination; fixed paths, proper currencies, no arbitrary provider URL or mutation.
- [x] Reuse public-address guard/resolver with HTTPS/domain ownership/address pinning, no redirects/query credentials, capped body/time/pages and redacted provider errors.
- [x] Add optional encrypted read credentials to existing WooCommerce connection; preserve webhook-only compatibility; active API test is managed/scoped/throttled and never promotes fixtures to live-connected.
- [x] Server-side tenant/role/disconnect/missing credentials/auth/429/schema/SSRF/rebinding/secret-privacy/duplicate negative tests; real HTTP examples for added/changed operations, both databases/quality/CI and coherent SHA handoff.

B23a local evidence: SQLite783total760pass+23skip/9880assertions; MySQL783total781pass+2skip/9961assertions, final native API15tests144assertions on both drivers. Pint/PHPStan/audit/contract/20HTTPcaptures PASS. Implementation `161051edd7952afeaa91e14baecb0b03f33177a3`; CI40673100233 SUCCESS all3jobs,783tests/database. Whole #31 stays IN_PROGRESS.

### Later #31 backend boundaries — not covered by B23a
- [ ] Shopify GraphQL/OAuth/scopes/order/refund/catalog and durable credential-bound connection quality gates.
- [ ] Wix, BigCommerce, Ecwid and Lightspeed C authenticated adapters/current official profiles, bounded polling/webhook recovery.
- [ ] Version-specific PrestaShop/OpenCart signed bridge contracts, Shopware app lifecycle and Adobe deployment-specific asynchronous adapter profiles; provider modules/storefront UI are outside this Laravel scope.
- [ ] Ten provider-backed acceptance records and real installation/banner/coupon capability verification; access/sandbox/storefront proof remains external until actually run.

### B23b — Shopify native reads and shared pinned transport (owner continues 2026-10-28)
- [x] Reuse/refactor proven pinned read transport for WooCommerce GET and fixed Shopify read-only GraphQL queries; retain Woo security/circuit regressions.
- [x] Optional encrypted Shopify access token, pinned2026-10 API, observed shop identity/domain binding and actual read scopes; webhook-only compatibility, no credentials in URLs or logs.
- [x] Native order/refund/transaction normalization without double-subtracting refunds or inventing payment timestamps; protected customer fields remain missing, GraphQL errors/version fallback never silently pass.
- [x] Bounded modified-order/catalog cursor reads and explicit unsupported discount/banner write/OAuth lifecycle capabilities; no connected promotion from fixture probes.
- [x] Executed Shopify ownership/scope/GraphQL HTTP200errors/throttle/schema/cursor/secret/isolation tests, actual running-server API contract examples and both database suites/quality; record exact scope remaining for quality gates/providers.

- [x] B23b implementation `295e8b5d8a787d60c69150c20ef83982a30c69da` and test-fixture correction `01666e100a9e9d7f2da13d394dbc7ed179aa40a8` committed/pushed; exact-code CI40677278061 SUCCESS all three jobs (795tests/database).

B23b final local evidence: SQLite795total772pass+23skip/9965assertions/53.934s; MySQL795total793pass+2skip/10049assertions/290.521s; native27tests229assertions, contract3tests3692assertions, Pint/PHPStan/composer validate/audit/platform/diff and11actual HTTP captures PASS. Implementation `295e8b5d8a787d60c69150c20ef83982a30c69da`, fixture correction `01666e100a9e9d7f2da13d394dbc7ed179aa40a8`; CI40677278061 SUCCESS: SQLite772pass+23skip/9965assertions/42.25s, MySQL793pass+2skip/10046assertions/203.38s. Whole #31 IN_PROGRESS.

### B23c — credential-bound lifecycle foundation (2026-10-28)
- [x] Add internal credential generation to connections and accepted events; legacy events stay explicitly unbound, never backfilled from current credentials.
- [x] Serialize credential rotation/disconnect against event intake/processing; clear current readiness timestamps/status on rotation and ignore stale queued events before any order/refund/contact effect.
- [x] Filter verification evidence to current generation, reject stale retries/probes, retain connection-wide provider dedupe and historical encrypted event evidence.
- [x] Test rotation/disconnect/legacy/stale-object and processing-boundary cases on both drivers; execute full suites/quality and isolated migration rollback/reapply.
- [x] Capture changed credential/verify/retry HTTP contracts from a running server, update API and handoff docs;16actual captures and contract3tests3693assertions PASS. Seven-gate promotion/OAuth/recovery remain subsequent work.

- [x] Commit/push B23c `aa316ec741c94fa9dddf967c37bad3ea3990db12`; CI40679706982 SUCCESS all3jobs: SQLite778pass+23skip/10030assertions/49.40s and MySQL799pass+2skip/10113assertions/197.56s (801each).

B23c local: focused69tests556assertions both drivers; full SQLite801total778pass+23skip/10029assertions/53.878s, MySQL801total799pass+2skip/10112assertions/244.766s PASS. Pint/PHPStan/audit/API contract/migration down-up PASS. Implementation `aa316ec741c94fa9dddf967c37bad3ea3990db12` and CI40679706982 verified all3SUCCESS; whole #31 IN_PROGRESS.

### B23d — seven-gate readiness and durable authenticated single-order recovery
- [x] Store generation/domain-bound durable recovery requests with bounded leases/attempts, fixed native connector reads outside database locks, resumable staged ingestion and stable native-event dedupe.
- [x] Reuse canonical order/refund/contact pipeline; stale generation/domain/lease cannot stage or promote; failures stay explicit and signed webhook/native sources remain distinct.
- [x] Replace complete-webhook Connected promotion with seven independently evidenced gates: authentication, store identity, genuine order, required mapping, payment proof, observed duplicate prevention and successful native change/refund recovery.
- [x] Operator-only recovery command and bounded scheduled drain, no new public mutation route, no automatic arbitrary provider calls; passive test-api contract unchanged.
- [x] Current verification API exposes truthful gates; migrate legacy Connected status to unverified Limited without inventing proof. Execute security/replay/failure/rotation/recovery tests, actual HTTP examples, both driver suites/quality/migration proof.
- [x] Preserve exact provider store reference alongside verified domain/generation; unknown historical references cannot qualify and changed references invalidate staged proof.

- [x] B23d `27faa2f73b7ea97cfdb31a86ae2d73a56bdef386` and byte-exact follow-up `03e69a9c41b384f7b2b34de4401ebfe63a02445a` committed/pushed; final CI40768013858 SUCCESS all3jobs: SQLite792pass+23skip/10127assertions/40.09s, MySQL813pass+2skip/10206assertions/166.45s (815each).

B23d final post-MIME8.1.10 local evidence: SQLite814total791pass+23skip/10118assertions/122.057s; MySQL814total812pass+2skip/10201assertions/369.908s PASS sequentially. Final targeted recovery13tests87assertions both drivers; current API contract3tests3694assertions;20actual HTTP captures/redaction, dual-driver full-schema migration down/up twice, Pint/PHPStan/composer validate/audit/diff PASS. Only symfony/mime patched8.1.7→8.1.10 for current audit. Implementation SHA/CI follows; whole #31 remains IN_PROGRESS.

B23d main implementation `27faa2f` CI40767003950 all3SUCCESS/814tests per driver. Byte-exact historical-store comparison follow-up:14recovery tests pass both drivers and controlled MySQL case-folding reproduction PASS; exact follow-up CI pending before remote closeout checkbox.

B23d verified closeout: final03e69a9 CI40768013858 all3SUCCESS/815tests per driver supersedes pending remote notes above. B23d DONE within seven-gate/targeted-known-ID recovery scope; whole #31 IN_PROGRESS.

### B23e–h — owner requests all remaining Laravel engineering, focused verification (2026-10-28)

Implementation SHA: `74608718c15afd96a045c5ee2017a85556548381` (base78642de), committed/pushed. Exact-code CI40777263930 SUCCESS all3jobs/846tests each. Timestamp follow-up `f90860ff5f91d720285b3978f352812190f27a18` verified by5focused tests16assertions and finalCI40778138872 all3SUCCESS/847tests per driver. Full card remains IN_PROGRESS. Detailed scopes/limits: docs/integrations/REMAINING_BACKEND.md.

- [x] Shopify browser authorization-code install/expiring offline refresh: one-time actor/session/workspace state, signed callback, approved scopes/store binding, encrypted pairs, generation guards and disabled defaults. Changed app authority invalidates even unexpired access. Focused lifecycle tests passed.
- [x] Signed Shopify uninstall requires native revocation proof outside locks; a valid current grant survives replay. Tested revoked grant, valid-current grant and duplicate/disconnected intake; actual HTTP accepted/revoked captures recorded.
- [x] Durable bulk fixed-watermark bounded pages/cursors, page journal, resumable leases/idempotent native requests and explicit failed/superseded/incomplete completion. Ordinary reconciliation cannot replace verification sample. Five focused tests plus final integrations passed.
- [x] Wix RSA JWT, BigCommerce Standard Webhooks, Ecwid app-secret notification authentication and generation/store-bound native lookups. Native API identity negatives for six new adapters passed. No notification payment field creates paid order effects.
- [x] Optional PrestaShop9, Shopware6 and Magento2.4 self-hosted/PaaS native read boundaries reuse the registry/pinned transport/canonical pipeline; exact shop/channel/store binding, no invented paid/refund dates, conservative missing-evidence handling. Mapping and binding tests passed; real compatibility remains unverified.
- [x] Exact decimal JSON decoding and bounded Woo/Shopify line mapping; encrypted current projection and immutable event snapshots, unknown eligibility and null-vs-empty semantics. Money/line/stale projection tests passed.
- [x] Actual running-server API examples, OpenAPI/README, targeted tests, sequential broad SQLite/MySQL checks and local migrations recorded. Latest final integrations90tests609assertions MySQL, contract3tests3720assertions, Pint/PHPStan/audit pass. Final exactf90860f CI40778138872 all3SUCCESS/847tests each.
- [ ] Remaining Laravel implementation: Shopify privacy execution after legal retention decision (intake done B23k, hook provisioning done B23i); Wix live verification (removal B23o, token minting B23l, search backfill B23j done); Shopware live verification (handshake B23m, app webhooks B23n done); full native line/catalog/coupon/capture/refund mappings. Do not tick from adapter availability or a mocked probe.
- [ ] Lightspeed C current API/version/payment/refund contract and eligible-plan proof; preserve explicit unavailable restriction until verified. OpenCart/other native merchant modules, storefront activation and genuine provider/install/refund acceptance remain external and NOT RUN.

### B23i — Shopify native webhook provisioning (2026-10-28)

- [x] Fixed reviewed2026-10 list/create queries for seven supported order/refund/uninstall topics; JSON and exact connection callback only, no caller GraphQL/URI.
- [x] Opt-in senior configuration, approved OAuth app authority/signing-secret checks, per-connection lock, generation/store guards before/after external work, audit metadata without secrets.
- [x] Provider-authoritative listing before creation and after ambiguous outcomes/rerun; existing foreign subscriptions untouched, truncated/filtered/duplicate mappings never certified.
- [x] Operator repair command and bounded per-topic OAuth jobs; provisioning cannot promote readiness or ingest orders.
- [x] Focused authorization, stale-generation, unknown outcome, replay and pagination tests; docs/checkpoint updates and final quality evidence (full SQLite 862/839+23skip, MySQL 862/860+2skip, Pint/PHPStan pass; f23f3a9 CI40836390758 all3 SUCCESS). Command tests added. No new public endpoint, no fabricated HTTP examples.

### B23j — Wix durable order discovery (2026-10-28)

- [x] Reviewed current eCommerce Search Orders fixed endpoint/query, UTC update window, bounded cursor and explicit non-initialized status coverage; continuation pages send cursor only (Wix forbids cursor with filter/sort); no transaction-derived settlement date invented. INITIALIZED orders remain unreachable via search.
- [x] Reuse PagedOrderReader/BulkSynchronization/OrderRecovery with canonical UUIDs and native instance/site/domain authentication; no new endpoint/table.
- [x] Focused cursor/store/window/status/invalid-response tests and accurate provider coverage limitations; documentation and checkpoint evidence.

### B23k — Shopify privacy compliance intake (2026-10-29)

- [x] Official contract reviewed (shopify.dev privacy-law-compliance + HTTPS delivery, 2026-10): three topics, TOML `compliance_topics` app-level URI, HMAC with app client secret, 401 on invalid HMAC, 200 ack, dedupe on X-Shopify-Webhook-Id, 30-day completion, shop/redact 48h after uninstall.
- [x] Public route, per-IP limiter 300/min, 1 MiB limit, 503 when secret unconfigured; verification before anything is stored; rejection audit at most once per minute per reason.
- [x] Durable encrypted storage once per webhook ID, redelivery counted, 30-day due date, topic shape validation with authentic-but-unexpected bodies kept for review; audit without customer data.
- [x] Operator command lists pending/overdue requests with matching Shopify connection counts and records completed/retained once (audited). No automatic erasure or export (D-099, OPEN-SHOPIFY-PRIVACY-EXECUTION).
- [x] 7 focused tests / 46 assertions; actual local HTTP captures (200 ×3 topics, duplicate, 401, 413, 503) in B23_COMPLIANCE_HTTP_CAPTURE.json; OpenAPI + README updated; OpenApiCoverageTest passes.
- [ ] Execution of erasure/export once legal defines retention; live Shopify App Store compliance review NOT RUN.

### B23l — Wix app-instance token minting (2026-10-29)

- [x] Current official contract reviewed: client_credentials at /oauth2/token with app ID/secret/instance ID, 4-hour token, no refresh; legacy custom auth deprecated. Response fields confirmed against @wix/sdk AppStrategy source.
- [x] Opt-in config; app-mode connect binds `wix_app_id`, prohibits a manual token; manual mode unchanged.
- [x] On-demand renewal (<5 min left) under a per-connection lock with raw-credential/generation guards, strict response validation, encrypted storage and audit; renewal preserves in-flight authority, manual rotation does not.
- [x] App changed → 409, mode disabled → 503, invalid or rejected mint stores nothing; 5 focused tests / 22 assertions plus existing Wix discovery tests. OpenAPI create/replace descriptions updated (no new endpoint or response shape).
- [ ] App Instance Installed/Removed webhook handling and live Wix app verification NOT RUN.

### B23m — Shopware app registration handshake (2026-10-29)

- [x] Official contract reviewed: registration guide plus shopware/app-php-sdk source (exact query-signature, proof, confirmation-body, dual-signature and rotation rules).
- [x] Opt-in config; public GET registration and POST confirmation, per-IP limiter 60/min, ±600s timestamps, app/shop signatures, consumed pending secret, URL equality, secret rotation with previous secret and rotation time.
- [x] Encrypted shop secrets and Admin API credentials in shopware_app_shops; binding to exactly one app-registration connection on the verified domain, in either order; generation bump and readiness reset; unbinding when the shop moves URL; manual credentials prohibited in app mode.
- [x] 7 focused tests / 60 assertions; actual HTTP captures (200, 401 ×2, 422, 503, confirm 204/401) with an independently re-derived proof; OpenAPI + README; OpenApiCoverageTest passes.
- [ ] Shopware app webhooks/lifecycle events, manifest, action buttons and live Shopware verification NOT RUN.

### B23n — Shopware app webhooks and lifecycle (2026-10-29)

- [x] Contract reviewed: official webhook guide (shop-secret body signature, source.shopId/eventId, timestamp for replay protection, ID-only entity-written payloads) and SDK (previous-secret 60s allowance, app.deleted keepUserData).
- [x] Public endpoint, 600/min per IP, 1 MiB, ±600s, eventId dedupe; `order.written` → ID-only `order_lookup` events on the bound, generation-current connection; other events ignored.
- [x] `app.deleted` revokes binding/credentials (generation bump), deletes the shop unless keepUserData; activation/deactivation audited; Shopware connector extracts lookup IDs only from the derived app hint.
- [x] 5 focused tests / 58 assertions (with B23m: 12/118); actual HTTP captures (order.written, redelivery, ignored, forged 401, app.deleted); OpenAPI + README.
- [ ] checkout.order.placed / order_transaction mapping, action buttons and live Shopware verification NOT RUN.

### B23o — Wix webhook envelope fix and AppRemoved (2026-10-29)

- [x] Official @wix/sdk parseJWT reviewed: JWT `data` is a JSON string; the previous object-only parser rejected genuine webhooks. Fixed; object form refused; fixture corrected.
- [x] AppInstalled/AppRemoved eventTypes confirmed in the official app-instances SDK; content-hash dedupe; AppRemoved → native-proof uninstall processing shared with Shopify; AppInstalled has no effect.
- [x] Tests: WixConnectorTest (2/9) and WixAppRemovedTest (3/13): revoked → disconnect, valid grant → ignored, redelivery deduped, installed → no HTTP or effect.
- [ ] Live Wix removal status codes NOT RUN.

### B24 — "Code does not work" screenshot evidence, Trello #23 (2026-10-29)

- [x] #23 backend audit: wallet expiry/oldest-first, one voucher model, one-time replacement, isolation and audit already implemented; only screenshot upload missing.
- [x] Optional multipart `screenshots[]` (≤3, JPEG/PNG/WebP, ≤5 MB, content-sniffed, must decode); encrypted private storage; files removed on 409/failure; `voucher_disputes.evidence` metadata.
- [x] Super Admin-only audited download (attachment, nosniff, no-store); consumer/merchant 403; merchant view shows count only.
- [x] Focused test (disguised 422, encryption, orphan-free 409, 403, byte-identical download, 404); real-server captures 422/201/403/200/404/401; OpenAPI + README.
- [ ] AI screenshot analysis (optional on card) NOT BUILT; live WooCommerce/Mollie/Answer24 E2E for #23 NOT RUN.

### B25 — Catalog/coupon sync and capability report, Trello #1 (2026-10-29)

- [x] Tables `catalog_syncs`, `connection_products`, `connection_coupons` (migration `2026_10_29_130000`); coupon code encrypted, email restrictions stored only as `customer_restricted`.
- [x] `CatalogReader` contract; WooCommerce reader over official REST v3 `products`, `coupons`, `data/currencies/current` on the existing pinned GET-only transport; page cap `CATALOG_SYNC_MAX_PAGES` (50).
- [x] `CatalogSynchronizer`: queued pass, cache lock, per-page write only at the starting credential generation, unseen items `removed_at` only after a complete pass, `partial`/`catalog_page_limit` otherwise; audited requested/finished.
- [x] Capability report (10 capabilities AVAILABLE/LIMITED/UNSUPPORTED with reason and last_verified_at) from evidence only.
- [x] Routes: capabilities, products, coupons (manage), catalog-syncs GET/POST; focused CatalogSyncTest; real-server captures 401/200/422/202/404/403/409; OpenAPI + README.
- [ ] Catalog/coupon readers for Shopify, Shopware, Wix, Lightspeed, Magento, CCV NOT BUILT (report UNSUPPORTED); live WooCommerce catalog run NOT RUN.
- [ ] Native storefront banner publication, AI Banner Studio, Order Sheet/catalog UI NOT BUILT (frontend/provider scope).

### B26 — Shopify catalog and code-discount reader, Trello #32 (2026-10-29)

- [x] Field list verified on shopify.dev Admin GraphQL 2026-10 (Product, discountNodes, DiscountCodeBasic/FreeShipping, DiscountPercentage 0.00–1.00, DiscountAmount, DiscountMinimumSubtotal, DiscountContext, DiscountItems, DiscountStatus); deprecated `codeDiscountNodes`/`customerSelection`/`featuredImage` not used.
- [x] `CatalogReader` now cursor-based (Woo cursor = page number); synchronizer refuses a repeated cursor (`provider_pagination_invalid`).
- [x] `ShopifyApi::catalog/shopCurrency` fixed queries after the identity/domain/scope gate; `ShopifyCatalog` mappers; shared `CatalogValues` trait; exact-decimal percentage parsing.
- [x] ShopifyCatalogTest (cursor paging, documented mapping, skipped BXGY/bulk, encrypted codes, missing read_discounts → failed pass); real-server capture B26_SHOPIFY_CATALOG_HTTP_CAPTURE.json; OpenAPI + README.
- [ ] Shopware, Wix, Magento, BigCommerce, Lightspeed, CCV, Ecwid, PrestaShop catalog readers NOT BUILT; live Shopify catalog run NOT RUN.

### B27 — BigCommerce, Ecwid, Magento, PrestaShop, Shopware 6, Wix catalog readers, Trello #32 (2026-10-29)

- [x] Official references checked: BigCommerce get-products/get-coupons/get-store-information; Ecwid search-products/search-discount-coupons/store-profile; Magento admin-schema-2.4.8 + RuleInterface constants; PrestaShop 9 webservice products/cart_rules/configurations/currencies + PS_CURRENCY_DEFAULT; Shopware core Product/Promotion definitions and discount constants; Wix catalog V1/V3, catalog version, coupons query, site properties.
- [x] Mappers `BigCommerceCatalog`, `EcwidCatalog`, `MagentoCatalog`, `PrestaShopCatalog`, `ShopwareCatalog`, `WixCatalog`; shared `CatalogValues` (row defaults, exact percent, ISO/epoch dates); connectors implement `CatalogReader` behind their existing identity gates.
- [x] `PinnedJsonReader` opt-in `allowEmpty` for 204 list pages (BigCommerce v2).
- [x] PlatformCatalogTest: one products + coupons pass per platform, unsupported shape skipped, codes encrypted, no write methods; real-server capture B27_PLATFORM_CATALOG_HTTP_CAPTURE.json; OpenAPI + README.
- [ ] Lightspeed C/E, CCV, OpenCart, Squarespace, Mijnwebwinkel: no native read connector (UNSUPPORTED). Live installed-store runs for all platforms NOT RUN. Storefront banner publication NOT BUILT (merchant-side plugin/app scope).
# Merchant overview review follow-up — 2026-10-31

- [ ] Wire overview pagination and effective lifecycle filters.
- [ ] Open date-filtered claim records from KPI/chart/notifications.
- [ ] Resolve recovered inventory alerts across dashboard and central inbox.
- [ ] Show unavailable states, polling and freshness; retain four primary KPIs.
- [ ] Aggregate lifecycle timestamps in SQL with timezone/DST coverage and volume evidence.
- [ ] Verify notification coverage and browser interactions; record remaining acceptance limits.



### B08 discovery integration accuracy review — 2026-10-31 (uncommitted)

- [x] Verify boolean transport and cover without a logo; preserve existing fixes.
- [x] Align obtainable counters/filter/sort; test unavailable and limit boundaries.
- [x] Batch card enrichment and test query count independent of page size.
- [x] Define <=30s cache age, scheduled expiry, inventory/import/allocation and after-commit invalidation.
- [x] Shared complete voucher collection/loading/error and lifecycle-derived count.
- [x] Browser Back/Forward, direct page, filters, retry, image fallbacks and desktop/mobile checks: 10 fixture journeys passed.
- [x] Repeatable representative local P95 measurement, recorded separately from staging.
- [x] Typecheck, targeted ESLint/Pint and Discovery/Vouchers/OpenAPI checks passed; actual HTTP list example recaptured.
- [ ] Supplied design reference and pixel-close comparison (reference absent).
- [ ] All ten platform browser journeys against real services.
- [ ] MySQL/Redis staging P95 <300ms; local cold HTTP-kernel P95 was 431.16ms.

Evidence and limitations: [review](discovery/INTEGRATION_ACCURACY_REVIEW.md).
No commit SHA yet; base 59916e9, existing unrelated dirty files preserved.

Merchant follow-up evidence: 70 backend tests (67 passed, 3 skipped), targeted Pint/PHPStan pass, frontend typecheck/ESLint and 10 browser contract journeys pass. Items above are implemented/verified but remain unchecked pending a commit SHA; reference screenshot, real-service E2E and concurrent staging load acceptance remain open. See docs/analytics/MERCHANT_OVERVIEW_BENCHMARK.json and sibling frontend docs/merchant-overview-acceptance.md.


## Cockpit reporting correction — 2026-10-31

- [ ] Omit unsupported historical comparisons; test period commission comparisons.
- [ ] Count vouchers using explicit verified acquisition evidence; retain Unknown.
- [ ] Exact normalized hash search, shared usable voucher rules and aligned consumer scope.
- [ ] Preserve destination dates and tabs; deduplicate failed operational sources.
- [ ] Custom period, visible-tab refresh, API captures and focused regression evidence.

Boxes remain unchecked until an implementation commit records the executed evidence and SHA.

## Merchant landing conversion / inline CMS review — 2026-10-31

- [ ] Separate published/draft state; stop publish on failed save; preserve preview edits; autosave and revision conflict coverage.
- [ ] Public NL/EN content and metadata; fresh approved publication only.
- [ ] Platform selection retained through onboarding and integration catalogue.
- [ ] On-page section order/visibility, structured collections, navigation/footer, schedule, version picker and media controls.
- [ ] Approved image slots, cropping and mobile variants, reusable media library.
- [ ] Diagnose onboarding contract; execute focused backend/frontend tests and record results.
- [ ] Capture changed API contracts from running server; document remaining acceptance limits.


## AI support repair — 2026-10-31 (HassanDev, base 59916e9, uncommitted)

Items remain unchecked until a commit records its SHA per AGENTS.md; executed local evidence is recorded in TASKS/PROGRESS/HANDOFF and the root repair report.

- [ ] Neutral verified wallet categories in NL/EN; loading/error/verified-empty browser coverage.
- [ ] Typo normalization, bounded conversation subject/reference/unit recovery; natural order reference research and scope negatives.
- [ ] Supported policy controls execute; unsupported API/UI controls locked; immutable verification and financial workflows preserved.
- [ ] Refresh, focus, reconnect catch-up and human reply recovery; transport availability distinguished from service health.
- [ ] Workspace destination mapping and authorized selection gate; unauthorized selection blocked.
- [ ] Five settings tabs; filtered bounded monitoring, percentages, cost coverage and explicit connectivity evidence limits.
- [ ] Locale-consistent shared chat and voucher support panel.
- [ ] Real local HTTP contract captures, OpenAPI coverage, Support tests, PHPStan/Pint, typecheck/ESLint and controlled browser tests.
- [ ] Ten real-service acceptance journeys, live provider/model/worker/Reverb evidence and verified deployment pricing.
# About Us inline CMS follow-up — 2026-10-31 (uncommitted)

- [x] Validate responsive hero/story/team asset references and alt text. AboutUsCmsTest passed; uncommitted on base 59916e9.
- [x] Resolve draft/profile assets and expose selected revision content for comparison. Integration test passed; uncommitted.
- [x] Reject cross-locale restores; verify publication revision conflicts and schedule boundaries without stale public caching. Integration tests passed; uncommitted.
- [x] Run AboutUsCmsTest (including queued scheduler execution) and API contract coverage: 17 tests passed, 5207 assertions; uncommitted.
- [x] Capture changed API examples from an isolated running fixture server: five responses in ABOUT_HTTP_CAPTURE.json; uncommitted.


## Live storefront studio review correction — 2026-10-31 / 2026-11-01

Uncommitted checkpoint; boxes stay unchecked until an implementation SHA is recorded.

- [ ] Actual merchant storefront frame and verified opener fallback; no simulated storefront acceptance.
- [ ] Origin/source/key/schema/nonce/campaign/pairing/expiry checks and listener/timer cleanup.
- [ ] Save failure/conflict and edits-during-save stop Publish.
- [ ] Native cart snapshot schema, currency/freshness validation, subscriptions and stale-display expiry.
- [ ] Bounded published-version refresh with pause/rollback and preserved unchanged widget measurements.
- [ ] Functional resize handles, bounded persisted sizes, corner snapping and inline missing-target recovery.
- [ ] Unsupported viewability explicitly unknown, never an impression.
- [ ] AI ordered editable text/image/CTA layers, validation and private draft acceptance.
- [ ] Focused PHP/JS/browser/TypeScript/lint/static checks plus real HTTP API captures.
- [ ] Real merchant themes, native cart provider adapters and genuine AI/human acceptance.
## Multilingual CMS review repairs — 2026-11-02
- [ ] Preserve the allowed rich document tree while editing, including nested lists, marks and media.
- [ ] Require the reviewed draft revision for manual publish; reject stale/missing revisions.
- [ ] Resolve request locale consistently and follow localized page alternates.
- [ ] Distinguish explicit language selection from automatic/account hydration.
- [ ] Translate dashboard chrome, notification management and checkout statuses.
- [ ] Bypass downstream caching for mutable public CMS/FAQ/consent configuration.
- [ ] Shut down loaded optional trackers and clear controlled cookies/storage on withdrawal.
- [ ] Record focused regression results and API contract evidence. Leave unchecked until an implementation commit exists.

## Notification email links and template UI — 2026-11-02
- [ ] Map legacy email actions to real frontend pages and reject unsafe configuration.
- [ ] Add template editing and readable synthetic previews using existing APIs.
- [ ] Run focused checks and record Hassan's remaining destination confirmation. Leave unchecked until committed.


### B32 — Resume Frank review branch / CI repair (2026-10-31)

- [x] Audit and preserve Claude's uncommitted fixes and current ten Frank card requirements.
- [x] Finish PHPStan cleanup without baselines or disabling checks; retain API contracts.
- [x] Verify canonical enrichment evidence hashing across JSON key ordering and tampering.
- [x] Validate MySQL column-width migration and affected regression tests on isolated test databases.
- [x] Run API coverage, formatting, static analysis and full SQLite/MySQL suites sequentially.
- [x] Record exact results, outstanding backend/frontend/live acceptance and branch/commit in handoff.

Evidence: implementing commit `fix: finish Frank review CI repair on SQLite and MySQL` (SHA recorded in following handoff checkpoint); SQLite 1117 total/1093 passed/24 skipped, MySQL 1120 total/1117 passed/3 skipped; focused hash/enrichment 8/31; API coverage 3/5140; Pint, PHPStan L6, dependency audit PASS.

### B33 — Separate points-priced voucher checkout, #19/#20 (D-103)

- [x] Add optional positive integer points price to immutable offer terms; at least one explicit price before activation.
- [x] Validate merchant pricing and consumer payment unit; preserve existing EUR-credit behavior.
- [x] Persist points price/unit separately from monetary amounts; reserve/capture points atomically through WalletService.
- [x] Block missing points price, insufficient points, monetary fallback and altered idempotent requests.
- [x] Revalidate the current locked offer version and stock; preserve frozen issuance terms and existing tenant/user guards.
- [x] Extend financial reconciliation to prove exact unit, owner, debit and immutable price.
- [x] Test success/replay, insufficient/pending/reserved points, rollback, unit isolation, version changes and security negatives.
- [x] Capture changed public/merchant/consumer API examples from a real isolated HTTP server; update OpenAPI and README.
- [x] Record executed checks and exact commit; whole Trello cards retain independent/frontend/live acceptance.

B33 evidence: implementing commit `feat: support separately priced points voucher checkout`; focused SQLite 55 total/52 passed/3 skipped, MySQL 55 passed including four-process replay; API capture 18 exchanges; PHPStan L6 PASS. SHA recorded in subsequent handoff. B32 SHA: `1a3eee0`.

### B34 — Affiliate purchase identity, fresh selection and consent, #19

- [x] Distinct trustworthy order references on one click remain separate purchases; repeated same purchase stays disputed.
- [x] Reselect when candidates, eligibility, rates or selection configuration change, including a previous empty decision.
- [x] Missing published cookie policy fails closed for affiliate tracking in every environment; explicit current marketing consent required.
- [x] Update regression fixtures to grant explicit consent; test missing/stale/withdrawn consent and no-click/no-reward behavior.
- [x] Capture changed visit behavior from a real server and update API docs; run focused SQLite/MySQL checks.
- [x] Record exact evidence and remaining points earning/business/live acceptance boundaries.

B34 implementing commit `fix: refresh affiliate selection and require tracking consent`; evidence above; B33 SHA `fe699ad`. B34 SHA recorded in following checkpoint.

### B35 — Quality Center CI execution provenance, #37/#36/#22

- [x] Discover general backend services/models/requests and remaining application components; conservatively invalidate endpoint evidence for transitive backend source changes.

- [x] Emit JUnit and actual execution timestamp for both existing CI database jobs, preserving test failure exit codes.
- [x] Import/discover into a separate ephemeral QA database with exact tested SHA and explicit `ci` environment; label synthetic regression runs mocked.
- [x] Retain reports, current route inventory and sanitized QA summary artifacts on success/failure without exposing credentials or DB exports.
- [x] Keep deployment/scheduled staging execution and 175 inaccessible PDF checks explicitly unverified.
- [x] Test CLI CI-environment validation/import/provenance and existing API behavior; capture any API contract changes.
- [x] Document senior-owned deployment hooks and evidence replay; record exact checks and commit.

B35 evidence: SQLite/MySQL QA17/297 each; API3/7505; Pint/PHPStan PASS; real CLI artifact replay and eight HTTP captures. Implementing commit `feat: retain honest CI Quality Center evidence`; remote workflow execution remains pending. B34 SHA `40d8e83`.

### B36 — Git build proof and evidence-backed independent retests, #37/#36

- [x] Verify actual Git ancestry when history is available; a later scan alone cannot prove a fix is present.
- [x] Keep unknown-build endpoint evidence stale and distinguish explicit independent build attestations.
- [x] Reject old executed reports imported after a fix; preserve independent-role restrictions.
- [x] Manual/unlinked PASS needs an executed unmocked assertion and valid, unexpired, checksum-verified result evidence; browser/manual linked tests need the same evidence.
- [x] Test unrelated branches, missing history, stale execution, missing/expired/corrupt evidence and valid independent retests.
- [x] Capture changed QA API results and document conservative limitations; run focused SQLite/MySQL checks.

B36 evidence: SQLite26/7835 and MySQL23/330 PASS; API3/7505; Pint/PHPStan PASS; actual Git branch proof and seven HTTP guard captures. Implementing commit `fix: require build and evidence proof for QA retests`; B35 SHA `1284bc1`.

### B37 — CI locale/fallback regression repair

- [x] Reproduce English validation labels falling back to Dutch under the shipped NL fallback configuration.
- [x] Provide English attribute labels without replacing Laravel's validation messages; preserve configured default locale.
- [x] Test alternating languages and unsupported/missing preferences under explicit NL/EN defaults.
- [x] Capture actual HTTP validation responses; update API docs and record focused SQLite/MySQL/static/format evidence.

B37 evidence: SQLite/MySQL3/58 each; API3/7505; PHPStan/Pint PASS; four actual HTTP captures. Implementing commit `fix: isolate validation labels and request locale defaults`; B36 SHA `05104b0`.

### B38 — Explicit per-program points earning, #19 (D-104)

- [x] Ensure non-destructive points migrations can reapply without dropping contractual data; verify isolated migration replay.

- [x] Add immutable program/rate/calculation/points multiplier/rounding terms on existing reward rules; no new wallet or implicit conversion.
- [x] New points rules remain inactive until a mapped program and explicit positive points-per-EUR-consumer-share rate are configured; reject mismatched source/workshop inputs.
- [x] Select applicable program rules and freeze complete earning terms on new transactions; preserve legacy reward snapshots and EUR behavior.
- [x] Use exact integer arithmetic for configurable points rounding/multipliers/caps, including large amounts.
- [x] Public discovery distinguishes points earning from EUR cashback; financial reconciliation checks the frozen program/rate.
- [x] Test inactive/missing rates, scope isolation, approval/replay, changed rules, legacy snapshots and arithmetic boundaries on SQLite/MySQL.
- [x] Capture real admin/public HTTP examples; update OpenAPI, README and handoff with exact evidence.

B38 checked against executed evidence in this implementation commit (parent130fd24); resulting SHA recorded in the next checkpoint. SQLite65pass+1skip/7959assertions, MySQL62pass+1skip/446assertions; static/style PASS;16 real HTTP captures.

### B49 — Review acceptance alignment (2026-11-03)

- [x] Document approval-based timing under D-089/D-107, separately from cash settlement; preserve historical reversal rules.
- [x] Prove the explicit versioned 100% share awards475minor once after approval, preserves its snapshot and leaves the70% default unchanged.
- [x] Verify existing foreign-workspace404/audit and Answer24 consent/suppression tests; document responsibilities.
- [x] Keep provider installation/native/live gaps explicitly open with delivery ownership and evidence gates.
- [x] Record executed focused tests, API compatibility and branch/SHA/next action in checkpoint docs.

B49 evidence: SQLite61/491 and17/7827, MySQL1/12; Pint/diff PASS. Implementing commit has parent e3b25c8; provider delivery remains OPEN.

### B50 — Recovery scheduling and deployment acceptance gaps (2026-11-03)

- [x] Replace hourly backup scheduling with five-minute attempts while preserving opt-in and overlap protection; verify actual scheduled event.
- [x] Tighten overdue detection to15minutes by default and test healthy/failure/stale boundaries without asserting RPO proof.
- [x] Document independent archive/key recovery and timed infrastructure drill evidence; retain unverified flags until real proof.
- [x] Define production queue inventory and safe per-queue backlog/failure acceptance evidence.
- [x] Run focused recovery/operations/API checks and record results and deployment blockers.

B50 implementing commit parent7027e2c: SQLite14/7660, MySQL operations4/28; PHPStan512MiB/Pint/diff PASS. Checkboxes cover implemented code/runbook only; deployed offsite/key/infrastructure/queue acceptance remains NOT RUN.

Implementation SHAs: B49 `7027e2c`; B50 `fd27a24`. Draft PR#5 contains both; no deployment acceptance promoted.

### B51 — Aggregate wallet support wording (2026-11-03)

- [x] Render pending credit as an aggregate in EN/NL, without attributing a single reward/order or release promise.
- [x] Verify multiple pending entries and order-level evidence boundaries without financial mutations.
- [x] Capture actual HTTP output and update API/support docs and checkpoint evidence.

### B52 — Support model contract and execution spending boundary (2026-11-03)

- [x] Require exact-model verified contract/pricing configuration; omit unverified optional parameters and preserve immutable tools/privacy controls.
- [x] Reserve conservative per-attempt monetary exposure atomically before egress, including failures/crashes, with fail-closed missing pricing and daily cap tests.
- [x] Verify concurrent spending cannot exceed the configured cap and model changes cannot borrow another model's proof.
- [x] Document live-model quality acceptance and template decision D-076 separately from synthetic proof.
- [x] Record focused tests, migrations, actual API captures and remaining acceptance blockers.
## Backend merge completion — 2026-11-02
- [x] Verify pending main -> HassanDev merge has no unresolved entries or conflict markers and preserves HEAD's application tree (parents 0e0a6da / 313dfad; completion commit contains this checkpoint).
- [x] Execute focused affiliate, benefits, dispute, business journey and OpenAPI regressions (70 passed, 1 skipped; 8480 assertions; PHP 8.4.15).
- [ ] Record validation, conclude the local merge and verify clean Git status.

## Backend merge conflict resolution — 2026-11-02
- [ ] Reconcile all 33 conflicted files, preserve independent API paths and captured examples.
- [ ] Verify syntax, notification, support, affiliate, discovery, reporting and integration regressions.
- [ ] Stage resolved files and record remaining validation limitations; no merge commit without request.

## One CMS NL/EN/DE translation quality — 2026-11-02
- [ ] Reuse existing suggestions/revisions; queue bounded provider calls and deduplicate unchanged source/target requests.
- [ ] Enforce source freshness, protected tokens/facts/negation/noncash meaning, legal exclusion and draft-only application.
- [ ] Validate strict Responses identity/hash/locale/segment contract and retain token/model audit metadata.
- [ ] Accept German CMS drafts without seeding English fallback as German; reject other locales.
- [ ] Execute focused regressions and real HTTP captures; document outstanding T01–T23 staging evidence.

## Platform launch acceptance follow-up — 2026-11-02
- [ ] Verify pending affiliate credit remains unavailable and approval requires settlement in the business journey.
- [ ] Re-run API coverage, merchant reporting and financial regressions.
- [ ] Retire frontend sample financial endpoints and verify real HTTP 410 responses.
- [ ] Verify API-driven analytics in Chrome and record local results and outstanding launch blockers.

## Merchant dashboard and voucher review — 2026-11-02
- [ ] Follow-up: capture real isolated HTTP import/counter changes and reservation replay/release recovery; distinguish this from staging/browser acceptance.
- [ ] Follow-up: verify dependency remediation with a fresh audit, typecheck, build and browser regressions.
- [ ] Follow-up: map full #17 acceptance to executed evidence and document remaining staging/Linux requirements.
- [ ] Verify existing reusable redemption, dispute pagination, historical labels and protected queued exports.
- [ ] Expose prices, campaign dates, issuance limit and code mode through the existing editor.
- [ ] Track asynchronous import/export completion and connect dispute pages and voucher references.
- [ ] Show reserved codes / expired vouchers and compare adjacent lifecycle periods using existing aggregates.
- [ ] Verify read-only checkout quote versus explicit payment start; preserve D-106 no per-consumer cap.
- [ ] Record executed regressions and remaining MySQL/staging/performance evidence.

B51/B52 implementation40e0e0c plus main-integration refinement commit (parent40e0e0c/main313dfad): integrated SQLite252pass+4skip/9737assertions, MySQL214pass/1739; final ceiling guardsSQLite138/8895 andMySQL9/40. Six real post-merge HTTP captures; PHPStan/Pint/diff PASS. Live canary/95% quality/product acceptance remain NOT RUN, not checked as complete.

### B53 — Integrated dashboard KPI CI expectation (2026-11-03)

- [x] Reconcile the stale exact KPI assertion with merged reserved-code/expired-voucher/expired-offer fields without removing API behavior.
- [x] Execute the dashboard suite on SQLite and MySQL; record failed full CI honestly.
- [x] Record implementation SHAs, current handoff and exact next CI action.

B53 implementation parentb484cdf: MerchantDashboardTest SQLite11/85 andMySQL11/85 PASS; Pint/diff PASS. Full CI41596390972 SQLite failed only this stale expectation; replacement exact-code CI still required.

### B54 — Request-time impersonation authority (backend only, 2026-11-03)

- [x] Revalidate the initiating administrator's active/verified/Super Admin authority and required MFA from current records on every support-token request.
- [x] Close/revoke invalid sessions, reject stale model relations and token binding mismatches, and keep audit attribution without reviving ended tokens.
- [x] Enforce initiating authority inside the existing start transaction; preserve target restrictions and expiry behavior.
- [x] Block the ownership-transfer write route family during impersonation, including cancellation.
- [x] Test direct status/role/verification/MFA changes bypassing lifecycle hooks, normal tokens, audit and replay; capture actual HTTP evidence.
- [x] Update API/security docs and checkpoint evidence after executed checks.

### B55 — Cockpit API filtering and pagination contract (backend only, 2026-11-03)

- [x] Verify existing detailed KPI and Today behavior without implementing client screens.
- [x] Add voucher webshop scoping and activity webshop alias; reject conflicting/malformed filters without silently broadening results.
- [x] Validate page parameters for all four lists and preserve webshop list filters in pagination links.
- [x] Prove page2/scoped results, unknown shops, authorization and invalid inputs; capture real HTTP examples and document frontend handoff.
- [x] Record focused verification and implementation SHAs.

B54/B55 implementation parent5a84660: SQLite46/8417; MySQL43/637; final principal/race refinement15/299 on each driver; API3/7780. PHPStan/Pint/diff PASS.41 real HTTP captures plus database assertions; no frontend changes. Implementing SHA recorded in the following closeout. Full new CI pending.

B54/B55 implementing SHA: `29c02b2`. Checkbox evidence is in that implementation commit; subsequent docs note records the resolved SHA. New full CI pending.

### B56 — Backend release inventory and evidence alignment (2026-11-03)

- [x] Regenerate runtime route-entry, method/path operation and access/role inventory with explicit HEAD/multi-method counting.
- [x] Provide a repeatable CLI Markdown renderer and fail on runtime/OpenAPI drift; test checked-in inventory consistency.
- [x] Align release acceptance and business-journey wording with D-109 approval plus recorded settlement, preserving historical evidence.
- [x] Record current-build backend CI provenance and separate it from independent E2E/provider/staging acceptance.
- [x] Document missing native install/storefront artifacts and original-PDF/evidence package blockers without inventing mappings or runs.
- [x] Execute focused route/access/journey checks and record test results and exact next actions.

B56 implementing commit parentb3016cd: SQLite38/10982, MySQL4/502; static/style/audit PASS. These checkboxes cover inventory and honest release alignment, not independent/native/staging acceptance.

### B57 — Shared native order mappings and integration specifications (2026-11-03)

- [x] Audit official contracts and current Wix/Ecwid behavior; distinguish record creation/current shop currency from immutable settlement evidence.
- [x] Normalize bounded, order-bound flat product lines for Shopware/Magento with exact money, explicit amount basis and unknown eligibility; refuse mixed/composite/duplicate/invalid shapes.
- [x] Request bounded Shopware line-item association and document its read permission; keep failures fail-closed and provider qualification unchanged.
- [x] Map Ecwid's explicit reported refundedAmount without assuming missing=zero, settlement time or original-order currency; test contradictions/precision/bounds.
- [x] Retain Lightspeed cash refund/settlement and native package/live acceptance gaps where source proof is unavailable.
- [x] Execute focused SQLite/MySQL tests, capture actual affected order HTTP examples and update API/spec/handoff evidence.

B57 implementing parent8fb782e: SQLite49/343 plus final transport2/16; MySQL17/129 plus final wiring3/13 and transport2/16 (overlapping selections); API5/8261, PHPStan/Pint PASS. Four actual local HTTP serialization captures; live/native acceptance remains NOT RUN. Implementing SHA is recorded in the following checkpoint.

B57 implementing SHA: `ffa436d`; pushed to draft PR#5. External provider/native acceptance remains open.

### B58 — About publication reviewed revision enforcement (2026-11-03)

- [x] Require revision on both About publication aliases and retain locked stale-draft comparison.
- [x] Test missing/null/invalid/stale rejection without publication/audit side effects and successful reviewed publication.
- [x] Capture authenticated actual HTTP save/publish/restore and rejected revision responses; update API contract.
- [x] Run focused CMS/API checks and record remaining approved-content/frontend/deployed scheduler acceptance gaps.

B58 parentf57e6e6: SQLite CMS/API71/8904, MySQL About15/135, static/style/diff PASS;14 real local authenticated HTTP exchanges. Checklist covers backend enforcement/evidence only; deployment/content/frontend/design acceptance stays open. Implementing SHA recorded in the subsequent closeout.

B58 implementing SHA: `6ed1395`.

### B59 — Consistent consumer trends and acquisition reconciliation (2026-11-03)

- [x] Use consumerAccounts for daily trend; test memberships, intent, test-account exclusions and period boundaries.
- [x] Connect explicit acquisition recording to privileged console reconciliation, requiring current authorized actor, verified-link attestation and evidence reference.
- [x] Preserve immutable/idempotent links, audit actor/reference and reject mismatched/unapproved/duplicate/future sources; never infer from wallet spending.
- [x] Capture real cockpit HTTP contract, run focused checks and document operational procedure and remaining deployed/client acceptance.

B59 implementing parentee1f2b6: SQLite28/8546 plus final API5/8269; MySQL23/277; PHPStan/Pint/diff PASS. Three real local HTTP exchanges and actual synthetic console reconciliation. Deployed/client/genuine-provider acceptance remains open. SHA recorded in following closeout.

B59 implementing SHA: `f115f44`.

### B60 — Verified domain change and Step2 backend acceptance (2026-11-03)

- [x] Add authorized explicit domain-change reconciliation with expected-domain guard and reason; keep ordinary saves locked.
- [x] Reset ownership/business confirmation/approval, supersede open challenges and disconnect current integration credentials/generations atomically.
- [x] Guard stale profile/challenge races; test authorization, status, concurrency and old/new connection evidence.
- [x] Capture actual HTTP onboarding reset evidence, run focused checks and document coupon-write/deployed/client limitations and foreign-workspace404 policy.

B60 implementing parent0a9ac29: SQLite48/894, MySQL34/305, API5/8269; PHPStan/Pint/diff PASS. Nine actual local HTTP exchanges and console reset. These checks complete domain reset only; coupon-write capability and external/frontend acceptance remain OPEN. Implementing SHA recorded in subsequent closeout.

B60 implementing SHA: `5cf9707`.
## Revised platform brief review — 2026-11-02
- [ ] Verify OTP secrecy, PHP 8.5 SOAP loading and cover OpenAPI coverage.
- [ ] Make new validated affiliate conversions immediately spendable; verify explicit 100% EUR 4.75 rule, replay, rejection and expiry; preserve frozen historical timing.
- [ ] Tie replacement allocation to the original voucher and verify a second report cannot issue another replacement.
- [ ] Diagnose reported onboarding/catalog/connection test failures against current contracts.
- [ ] Record audited 404 tenant policy, Answer24 disclosure safeguards and unverified/missing integration release blockers.
- [ ] Record executed checks, branch/SHA, uncommitted status and exact remaining acceptance work.

## Banner storefront dummy-data follow-up — 2026-11-02
- [ ] Add an opt-in Shopify native-cart reader with bounded refresh, invalidation, freshness and currency validation.
- [ ] Verify add/remove/quantity/currency/error and in-flight invalidation using local dummy carts with the actual embed.
- [ ] Record executed checks and retain genuine merchant themes, installation and live AI acceptance as NOT RUN.

## Structured CMS translation bindings follow-up — 2026-11-02
- [ ] Inventory translatable paths in the three landing schemas; preserve links, identities, media and settings.
- [ ] Reuse queued suggestions with schema-specific limits and validation; verify EN/DE draft application and stale-source rejection.
- [ ] Record local test evidence and outstanding staging acceptance; retain unchecked status pending implementation SHA.

## Voucher checkout review follow-up — 2026-11-02 (uncommitted)

- [ ] Connect consumer points checkout and verify unit selection and blocked shortfalls.
- [ ] Reconcile asynchronous dispute refunds, duplicate protection and failed-refund retry with tests.
- [ ] Record D-053 policy, API changes, migration, executed checks and outstanding genuine Mollie acceptance.

Verification executed for the voucher-checkout review: backend 40 passed + 3 skipped; final recovery 13/13; browser 3/3; TypeScript, targeted ESLint and PHPStan passed. See docs/release/VOUCHER_CHECKOUT_REVIEW_2026-11-02.md. Boxes remain unticked pending a commit SHA and genuine-provider acceptance; no completion claim.

## German locale follow-up — 2026-11-03
- [ ] Add DE selectors/shared labels/authentication/role copy and explicit missing German publication behavior.
- [ ] Accept DE account preferences and identity email resources; verify profile and platform settings compatibility.
- [ ] Verify local login with demo users and record remaining German/runtime/staging gaps.

## Notification review follow-up — 2026-11-03 (uncommitted)

- [ ] Align default email/action destinations and run action-link regressions.
- [ ] Verify shared channel ownership and catch-up after subscription/reconnect.
- [ ] Verify template/delivery pagination and explicit uncertain-retry acknowledgement.
- [ ] Connect both resolution outcomes and verify review notes and step-up.
- [ ] Record executed checks and implementation SHA before ticking items.

## Universal order adapter review — 2026-11-03
- [ ] Reuse the documented Ecwid contract for Lightspeed E-Series; test signature, store binding, native reads and lifecycle.
- [ ] Implement remaining providers only against authenticated, documented contracts; keep unavailable access explicit.
- [ ] Ship and verify merchant-side installation packages and native banner publication sources.
- [ ] Show actual pending rewards and policy-neutral consumer wording without changing spendability.
- [ ] Record local evidence separately from genuine provider acceptance, branch/SHA and outstanding blockers.

- [ ] Verify merchant credential contract fields, field clearing and private connector download actions; browser checks executed, implementation SHA pending.
- [ ] Verify signed PS native lookup rejects malformed IDs and ping topics; focused tests executed, implementation SHA pending.

## Affiliate tracking and central wallet findings recheck — 2026-11-03

- [ ] Verify current consumer copy and pending/available/reserved fixture against the recorded timing policy; implementation SHA required before ticking.
- [ ] Re-run points-only, shortfall and dual-unit checkout browser checks plus TypeScript; retain explicit wallet_unit requests.
- [ ] Re-run affiliate regressions and confirm platform allocation is management evidence, with revenue recognition unverified.
- [ ] Collect genuine Daisycon/TradeTracker imports, settlement references, configured points-rule snapshots and duplicate/reversal ledger reconciliation. Local fixtures do not close this acceptance gap.
### Registration/CSRF review follow-up — 2026-11-03 (uncommitted)

- [ ] Confirm logout 419 remains recoverable; clear local identity only after server logout/401.
- [ ] Test CSRF bootstrap, one bounded retry, concurrent requests and multipart/bearer paths.
- [ ] Execute real local browser registration, verification resumption, MFA and recovery-code journeys.
- [ ] Document every config env key and remove duplicate active example settings.
- [ ] Record actual email, Redis queue/throttle, deployed cookies/CORS and staging P95 evidence.
- [ ] Compare desktop/mobile screenshots to owner-approved references.


## Homepage publication review — 2026-11-03 (uncommitted)

- [ ] Require reviewed draft revision at homepage publication; reject concurrent saves atomically.
- [ ] Queue homepage replacements through existing revision-pinned CMS scheduler.
- [ ] Run real HTTP/browser wallet, concurrency, publication, media and restore journeys.
- [ ] Record exact reference comparison and deployed performance when supplied.

## Offers and discount codes review — 2026-11-03 (uncommitted)

- [ ] Fetch all offer-scoped batch pages independently of inventory pagination; test foreign offers and pagination.
- [ ] Explain exact-code search and filtered-inventory export; connect sorting, inclusive local dates and all export filters.
- [ ] Preserve failed bulk selections and distinguish permission, conflict, session, network and server failures.
- [ ] Separate manual confirmation from provider proof; verify historical vouchers against frozen snapshots without changing shared inventory status.
- [ ] Align tenant acceptance with audited 404 concealment, run targeted backend/frontend checks and capture new API contracts over real HTTP.
- [ ] Obtain real provider checkout acceptance evidence; synthetic Woo HTTP fixtures do not establish provider acceptance.
## Sales attribution statistics follow-up — 2026-11-03 (uncommitted)

- [ ] Add exploratory visitor conversion difference, relative change, 95% interval and two-sided significance with sparse-data guards; preserve causal_uplift null and block rollout winner without provider evidence and a fixed analysis plan.
- [ ] Test equal, positive, negative, sparse, zero-baseline, unequal allocation and repeat/refund visitor outcomes; run existing consent/payment isolation regressions.
- [ ] Capture changed experiment responses through real local HTTP and update API/handoff documentation.
- [ ] Verify existing analytics translations and record real-provider acceptance procedure; Moneybird remains blocked by OPEN-FINANCIAL-LEGAL.
- [ ] Execute genuine storefront consent/retry/holdout/payment/refund acceptance and approved Moneybird issuance/delivery/reconciliation. NOT RUN without resolved prerequisites/provider access.

## Lightspeed C product catalog follow-up — 2026-11-03 (uncommitted)

- [ ] Verify official product/variant resources and pagination contract.
- [ ] Add identity-gated bounded native product reads, exact single-variant money and conservative multi-variant snapshots.
- [ ] Test pagination, malformed responses, identity/currency mismatch, pause, provider failures and failed-pass snapshot retention.
- [ ] Update capability declarations and findings; record executed checks and remaining provider acceptance. Implementation commit SHA pending.

## App Store findings follow-up — 2026-11-03 (uncommitted)

- [ ] Remove static service availability → VERIFIED inference; distinguish catalog synchronization states from backend status.
- [ ] Add tenant-authorized, audited pause/resume preserving credentials and evidence; enforce at native intake, queue and catalog writes; test tenant/auth/permission negatives.
- [ ] Complete supported Shopware/Magento line snapshots and monetary detail without inferring unavailable values.
- [ ] Localize App Store-owned NL/EN copy and verify with controlled browser journeys.
- [ ] Capture real local HTTP pause/resume responses and update API contract; run focused integration/OpenAPI checks.
- [ ] Run genuine provider installation/cart/refund/uninstall/reinstall journeys and five cross-platform journeys; collect provider/HTTP/DB/queue/Order Sheet/audit evidence. Remains NOT RUN without provider access.

## Merchant landing review follow-up — 2026-11-03

- [ ] Expose immutable publication content only when include_content=1 is requested by authorized CMS clients.
- [ ] Verify default history compatibility, stored-content isolation and alias response with focused tests.
- [ ] Capture a real authenticated HTTP response and document the opt-in query.
- [ ] Record real-service editing/upload/scheduler evidence and remaining onboarding/design limits.

Boxes remain unticked pending a tested commit SHA.

## Affiliate detail cover verification — 2026-11-03 (uncommitted)

- [ ] Reproduce reported cover failures on installed PHP 8.4 and 8.5; record exact codec/runtime evidence.
- [ ] Isolate enrichment test storage and verify failed/throwing writes clean partial variants and retain the cover fallback.
- [ ] Run focused enrichment, affiliate, discovery and API contract regressions; record actual results.
- [ ] Normalize reviewed provider offer conditions and approved website assets only after verified schema/rights evidence; raw snapshots grant no eligibility.
- [ ] Collect genuine provider/settlement/wallet acceptance and reference-image/real-service desktop/mobile journeys.

Boxes remain unticked until a tested implementation commit SHA exists. Live acceptance remains separate from local fixtures.

## Consumer platform local acceptance — owner follow-up 2026-11-03 (uncommitted)

- [ ] Record isolated real-HTTP/browser registration, activation/token, points purchase and profile evidence; keep local proof separate from release approval.
- [ ] Retain 100-sample cold/repeated directory and dashboard latency results, runtime settings and earlier failed attempts.
- [ ] Run ConsumerPlatformE2ETest, PointsCheckoutTest and OpenApiCoverageTest serially after browser cleanup.
- [ ] Confirm temporary database/process cleanup and preserve the configured application database and unrelated working-tree changes.
- [ ] Obtain full accessibility, approved-reference, external delivery and staging evidence before full release acceptance; fresh npm audit requires separate external-metadata approval.

### B61 — Merge current main into review branch (2026-11-03)

- [x] Preserve new main features plus review fixes; resolve code/docs/API conflicts and retain latest owner D-109.
- [x] Verify combined connector breakdowns/order binding, financial policy and current route inventory.
- [x] Run combined regression and static checks, recording local codec failures separately.
- [x] Record merge parents/evidence, push review branch and confirm PR mergeability.

B61 parent15e58dc + mainb04ea65: repaired SQLite162/2001, merged focused68/8445, MySQL99/883; PHPStan PASS. Initial broad run failed; local GD failures remain documented. Remote mergeability verification follows push.

B61 implementing merge SHA `be4a3e5`; pushed and GitHub MERGEABLE confirmed. Backend Pint/PHPStan/diff PASS; CI41650765241 pending.

### B62 — Merchant dashboard contract and claim concurrency evidence

- [x] Verify exact current KPI contract and nonzero reserved/expired counters with webshop isolation.
- [x] Assert audited foreign-workspace404 and active-member permission403 without weakening concealment.
- [x] Execute actual MySQL concurrent promotional claims and inventory races; separate correctness from staging latency.
- [x] Record focused SQLite/MySQL results and remaining real browser/visual/staging acceptance; do not claim full suite green.

B62 parentddc698c: SQLite15/127; MySQL20/153 including five real process races; PHPStan/Pint/diff PASS. Test-only backend boundary; full CI/browser/staging/visual acceptance is not claimed. Implementing SHA recorded in next closeout.

B62 implementing SHA: `6394e80`.

### B63 — Full support monitoring and reviewed outcome separation

- [x] Aggregate the complete filtered message dataset in bounded batches; report exact observed latency, usage and cost coverage without a500-row cap.
- [x] Add separate execution-status and resolved human-review outcome percentages with explicit denominators; preserve/deprecate legacy alias honestly.
- [x] Keep unmeasured connectivity explicit and expose available model-contract proof without claiming live availability.
- [x] Test over500 messages, review multiplicity/filter isolation, empty/unknown metrics and authorization; capture actual HTTP and update API/handoff.

B63 parent5491f79: SQLite7/112, MySQL7/112; PHPStan/Pint PASS. Four real HTTP captures over501 synthetic messages; external connectivity remains unverified. Implementing SHA recorded next checkpoint.

### B64 — Bounded support controls and model activation proof

- [x] Implement bounded tone/empathy/detail/word limits, execution deadline and monetary limits without unverified prose or truncating safeguards.
- [x] Allow one explicit configured/probed fallback for transient failures, sharing deadline/budget and retaining all attempt exposure.
- [x] Gate enabled policy publication on exact primary/fallback contract proof and output limits; expose actionable backend errors and capabilities.
- [x] Test actual control behavior, rejected activation, expired/changed contracts, fallback failure/budget/deadline and immutable safety guards.
- [x] Capture real settings/monitoring/message HTTP responses, update API/config/runbook and record external connectivity/quality/frontend gaps.

B63 implementing SHA `25fa46c`. B64 parent25fa46c: Support SQLite180pass+3skip/1470assertions; selected MySQL154/1324; API5/8349; PHPStan/Pint/diff PASS. Eleven real HTTP exchanges with synthetic accounts/local SMTP; live provider/Reverb/quality/frontend acceptance remains OPEN. B64 implementing SHA recorded next closeout.

B64 implementing SHA: `8c95e5c`; B63 `25fa46c`. Both pushed to draft PR#5; no live-provider or whole-suite green claim.

### B65 — Integration per-feature provenance and supported monetary detail

- [x] Expose evidence ID/source URL/error code with explicit declaration versus observed scope; never inherit platform-wide verification into unrelated features.
- [x] Bind connection evidence to workspace, current credential generation/domain/store and feature-specific events/syncs; retain failures and deny stale/paused/disconnected evidence.
- [x] Preserve existing native mappings, add documented Ecwid/Lightspeed totals and unambiguous Shopify current shipping without refund subtraction or invented timestamps.
- [x] Test provenance isolation/negative cases, exact/unknown money and package claims; capture actual HTTP, update specs/API/handoff and keep genuine provider acceptance NOT RUN.

B65 tests: SQLite191/1773; MySQL191/1775; API35/10983; PHPStan/Pint/diff PASS. Parent `516e9d1`; implementing SHA recorded at closeout. Genuine provider/native publication acceptance remains NOT RUN.

B65 implementing SHA: `f44d759`. All four checked items refer to the tested local backend boundary; genuine provider installation/publication/release acceptance remains open.

### Quality Center endpoint evidence

- [ ] Require mapped acceptance proof on the same result used to derive endpoint PASS.
- [ ] Verify missing provider evidence blocks PASS and completed proof restores it.
- [ ] Verify AI proposal approval is checked at staging planning and result import for linked executables.
- [ ] Review, register and execute the portal smoke candidate on deployed staging.
- [ ] Map the original acceptance source and provider journeys and connect the deployment gate to promotion.

### B66 — Executable acceptance manifest and fail-closed deployment gate

- [x] Define source-hashed unique 175-check mapping contracts with assertions, executor, environment, instructions and separately identified required provider subruns; leave unavailable source UNMAPPED.
- [x] Evaluate exact staging-build approved unmocked results and result-bound checksum-valid evidence of each required kind, with independent authorized review; raw JUnit is not acceptance.
- [x] Add a console deployment gate with nonzero blocked exit, safe artifact handling and documented deploy integration; never execute AI-generated commands or claim scheduled staging journeys ran.
- [x] Test missing/duplicate mappings, wrong build/environment/reviewer, incomplete/expired/corrupt evidence and synthetic complete gate; record tests and remaining frontend/provider/staging gaps.

B66 local boundary: QA SQLite27/358; acceptance MySQL4/28; API5/8353; PHPStan/targeted Pint/diff PASS. Parent `ced2fd9`; implementing SHA follows in closeout. Actual175-source mapping, independent real staging/provider runs, controlled scheduled runners and frontend/portal delivery remain OPEN.

B66 implementing SHA: `d27b56c`. Checked items certify the tested backend input/evidence-gate boundary only; the missing actual source mapping, executions, runner pipeline and frontend/portal are not checked as complete.

### B67 — Repair recurring CI failures

- [x] Identify failures from actual GitHub Actions logs and reproduce lint/MySQL failures locally.
- [x] Make the Daisycon raw-permission snapshot assertion independent of JSON object key order while preserving strict values and no-activation guards.
- [x] Fix reported formatting violations and regenerate deterministic merchant archives/manifests without changing native behavior or weakening lint.
- [x] Run focused database/package checks, full lint/static checks and dependency audit.
- [x] Verify the pushed revision's complete GitHub CI jobs and record results/SHA.

B67 implementing SHA `36c9029`: GitHub CI41665566931 SUCCESS (all3jobs), MySQL1364pass+4skip/23124assertions, SQLite1339pass+29skip/23023assertions. Local fullPint/PHPStan/Composer audit PASS; docs/qa/B67_CI_REPAIR_RESULTS.json records actual results.

### B68 — Moneybird funding invoice lifecycle

- [x] Record owner-delegated technical defaults; require actual administration/seller/contact/tax/ledger mappings before egress, with creation/delivery disabled by default.
- [x] Freeze statement/export snapshots and implement bounded create, explicit delivery, read reconciliation and uncertain-result recovery without automatic duplicate writes or wallet postings.
- [x] Enforce active Super Admin authority, immutable cross-shop/provider bindings, exact cents and lifecycle/audit guards; test failures, replay and concurrency boundaries.
- [x] Document configuration/operations, capture actual invoice API serialization, run relevant SQLite/MySQL/static/API checks and record migration/SHA/remaining provider acceptance.

B68 implementing SHA follows at closeout. Parent `92adc46`: Funding SQLite19PASS+2skip/150, MySQL21PASS/156; API5/8353; PHPStan/Pint/diff PASS. Actual Moneybird/legal/provider delivery acceptance remains unverified; defaults OFF.

### B69 — Additional experiment assignment metadata mappings

- [x] Reuse one strict token parser and reject duplicate/conflicting fields; preserve missing metadata as unknown.
- [x] Map documented native custom-field/extension metadata where available, with an explicit optional BigCommerce read path and no invented native fields for unsupported bridges.
- [x] Verify malformed/foreign-source/ambiguity behavior and existing order-assignment isolation/payment semantics on SQLite/MySQL; record exact propagation limits.
- [x] Update provider/bridge contracts, real API evidence if contracts change, tests and checkpoint SHA without claiming installed storefront persistence.

B69 parent `5861751`: SQLite203/1905; MySQL14/148; PHPStan/Pint/diff PASS. B68 implementing SHA `5861751`. B69 implementing SHA follows next checkpoint. Installed cart/storefront and genuine provider evidence remain NOT RUN.

### B70 — Pre-registered experiment protocol and fixed readout

- [x] Register immutable opt-in analysis/measurement snapshots before launch; pin versions, allocation, enrollment/follow-up, sample/effect thresholds and native evidence bindings.
- [x] Stop new enrollment at the fixed cutoff while retaining existing assignments; require mature follow-up, source reconciliation, cohort integrity and sample-ratio checks.
- [x] Finalize one immutable captured-purchase conversion readout with explicit uncertainty/winner criteria and no automatic publication; retain null causal estimates for unverified legacy studies.
- [x] Verify authorization, missing/stale/changed proof, early stop, consent/source/cohort gaps, inadequate samples and replay; capture actual HTTP, update API/spec/runbook and record tests/SHA.

B70 local backend boundary: parent `cfef279`; SQLite302PASS+4skip/13412, MySQL40PASS/251, API5/8353, fullPint/PHPStan/audit/diff PASS. Six actual synthetic local HTTP captures; real native validator/finalizer exercised with explicit synthetic evidence. Implementing SHA `94e3f87`; source integration `d8c9aa9`. Actual provider/study/legal acceptance remains NOT RUN. B69 implementing SHA `cfef279`; B68 `5861751`. New branch `frank/moneybird-experiment-followup` because PR#5 was merged externally; full new-head CI execution is recorded under PR#7 checks.

B68–B70 closeout: `5861751` / `cfef279` / `94e3f87`, integrated source `d8c9aa9`; draft PR#7. Checked items certify tested backend boundaries only. Actual Moneybird, storefront persistence, live experiment measurement and independent release acceptance remain unverified.

### B71 — Additional automatic native coupon verification

Wix follow-up

- [ ] Prove the app instance, site, currency and complete bounded coupon lookup.
- [ ] Compare supported Wix Stores terms and exercise restricted, ambiguous and incomplete responses.
- [ ] Record local HTTP and regression evidence with the implementing SHA.

- [x] Audit native read contracts and add bounded exact-code lookups using existing authenticated store/domain/scope guards.
- [x] Verify supported provider configuration against current or frozen terms without claiming checkout acceptance/redemption; retain manual states for unknown constraints and unsupported types.
- [x] Test native responses, ambiguous/malformed data, currency/term/restriction/lifecycle mismatches, credentials/tenant boundaries and historical isolation.
- [x] Capture actual HTTP responses, update API/provider contracts and checkpoint docs, run relevant database/static/API checks and record the implementing SHA.

B71 tested backend boundary (2026-11-04): parent52ef8c5; SQLite282PASS/13382, final focusedMySQL23PASS/117, earlier selectedMySQL42PASS/469, API5PASS/8353, fullPint/PHPStan/diff PASS. Ten actual local HTTP captures use explicit synthetic provider transport. Implementing SHA `65af0e3`; real provider/checkout/redemption acceptance remains NOT RUN. No migrations.

B71 context follow-up (parentc6c173e): final locked source/code/current-term revalidation rejects in-flight edits/rotations; stable-date canonical comparison and frozen historical scope verified. SQLite284/13390; MySQL25/125; PHPStan PASS; eleven actual local HTTP captures include context-change rejection. Final focused SQLite/native/API30PASS/8478 and fullPint/diff PASS; source SHA follows in the closeout. Provider/checkout/redemption NOT RUN.

B71 final implementing source SHA `d599c40` includes adapters65af0e3 and the tested locked context guard. Checked items certify the backend configuration boundary only; actual provider/checkout/redemption remains NOT RUN.

### B72 — Remaining order connector backend boundary

- [x] Establish primary-source CCV Shop auth/store/order/paging fields and preserve unknown payment/refund evidence.
- [x] Add explicitly labelled Mijnwebwinkel signed partner-bridge ingestion with merchant/domain binding, without inventing a native API contract or promoting Connected.
- [x] Reuse native recovery/discovery and bounded transport; reject unsupported webhooks/foreign stores and retain credential-bound cooldown across signatures.
- [x] Run SQLite/MySQL/API/static checks, capture actual HTTP and document provider/bridge acceptance limits.
- [ ] Implement and independently execute a native Mijnwebwinkel partner API client after its authoritative specification is available; the actual upstream bridge is also NOT RUN.

B72 local boundary: SQLite202/1864, MySQL7/40, API5/8353; fullPint/PHPStan/diff PASS; eight actual synthetic local HTTP responses. No migrations. Implementing SHA follows in B73 checkpoint. Actual provider acceptance remains NOT RUN.

### B73 — Native banner publication gaps

- [x] Audit provider-supported writes, scopes, installations and current publishing/recovery architecture; do not invent remote endpoints.
- [x] Implement supported backend publication/removal with immutable ownership, bounded writes and ambiguous-effect recovery; distinguish publication from storefront delivery.
- [x] Document genuinely unsupported automatic paths and required native app/manual setup; run relevant security/state tests and real local API captures with clear provider acceptance limits.

B73 tested backend boundary (parent6a8c5c3): SQLite271PASS+2skip/10988; final native CLI12/353; MySQL18/356; fullPint/PHPStan/audit/diff PASS. Seven actual local HTTP captures; no migrations. Implementing SHA follows at closeout. Actual provider/native-app/storefront acceptance NOT RUN; automatic C/E-Series/Squarespace and native Mijnwebwinkel remain open.

- [ ] Complete authorized native app/automatic publication paths for Lightspeed C/E-Series and Squarespace after supported installation/write contracts are established; independently execute all provider installation/storefront/uninstall acceptance.

B73 implementing SHA `346b3bb`; B72 implementing SHA `6a8c5c3`. Checked items certify the locally tested backend boundaries only; the explicitly unchecked native/provider acceptance requirements remain OPEN.

### B73 CI follow-up — Merchant landing availability fixture

- [x] Preserve the unsupported-connector marketing rejection scenario with an explicit unavailable fixture; verify the newly registered Mijnwebwinkel bridge reports manual setup rather than native availability.
- [x] Execute the CMS regression on SQLite/MySQL, retain full CI failure provenance and record source SHA; replacement full CI is tracked separately.

B73 CI follow-up parent9abc7af: CMS SQLite14/84 +MySQL14/84; Pint/diff PASS. Historical failed run41787701578 retained; replacement full CI remains pending. Implementing test SHA recorded in following closeout.

CMS regression correction implementing SHA `c595066`; replacement full CI remains pending at PR#7 checks.

### B74 — Reviewed provider promotion evidence

- [x] Normalize explicitly selected stored promotion fields into conditions/exclusions with exact program/source binding; preserve missing/ambiguous evidence as rejection.
- [x] Add authorized propose/approve/reject operator workflow with immutable evidence, expected offer revision, source freshness/recheck and idempotent review.
- [x] Publish approved terms in a new offer version without granting advertiser permission, eligibility or financial effects; preserve historical snapshots.
- [x] Test security/staleness/replay/negative paths on SQLite/MySQL and update contracts/runbook/checkpoint evidence.

B74 parent6c0a696: SQLite70PASS+1skip/522; MySQL11/42; PHPStan/Pint/diff PASS; three real local HTTP responses. Implementing SHA follows at B75 checkpoint. Actual provider acceptance NOT RUN.

### B75 — Reviewed website image import

- [x] Reuse pinned public-DNS transport for bounded same-origin candidate downloads; validate and re-encode supported raster images.
- [x] Store private pending imports with immutable evidence/hash; enforce current active admin, ownership, explicit rights confirmation and review before reusable assets exist.
- [x] Test malicious/oversized/invalid/private-host data, stale source, replay/storage failure and rejection; capture existing real asset API output and update handoff.

B75 tested boundary parent8cc2c72: SQLite selected147PASS+3skip/9730; final import/API12/8397; MySQL18/86; fullPint/PHPStan/diff PASS. Broader local pre-existing GD cover failures retained in results; Linux current-head CI pending. Two actual HTTP captures; B74 source8cc2c72, B75 implementing SHA follows closeout. Real merchant rights/provider/deployment acceptance NOT RUN.

B74 implementing SHA `8cc2c72`; B75 implementing SHA `bf07291`. Checked items certify the tested backend review/import boundaries; full Linux CI and genuine merchant/provider acceptance are tracked separately.

### B76 — Storefront publication finding reconciliation (backend audit)

- [x] Verify current BigCommerce placement/recovery/cleanup code and execute focused native-publication and inventory regressions.
- [x] Review remaining providers' primary installation contracts; record unresolved automatic-write authority without inventing endpoints or treating manual setup as automation.
- [x] Record exact source SHA, executed evidence and remaining acceptance in checkpoint docs; preserve the resolved API inventory.

B76 audited implementation SHA `765bc80` (BigCommerce source346b3bb); focused14PASS/838. Checked items certify audit/reconciliation only; C/E-Series/Squarespace automatic publication and genuine provider acceptance remain open.

### B77 — Adobe asynchronous module and deployment separation

- [x] Add a Magento/PaaS candidate module with local commit-time outbox, scoped configuration, bounded asynchronous delivery/retry and disable/cleanup instructions; no checkout network calls.
- [x] Authenticate and bind ID-only notifications to exact native store scope; reuse native recovery without trusting posted payment/refund claims.
- [x] Audit SaaS REST/event contracts separately and implement only established supported capabilities; keep installation/native runtime acceptance explicit.
- [x] Verify protocol, malformed/foreign scope, recovery and deterministic install archive; capture real local setup/webhook responses and update API/runbooks/checkpoint docs.

B77 parent81a3591: SQLite241/2188, MySQL5/43, package/Runtime/API11/8541, API5/8353, fullPint/PHPStan/diff PASS; four Node tests. Source archives are candidates. Native Magento SDK and Adobe installation/registration/observer/cron/uninstall execution NOT RUN; implementing SHA follows closeout.

- [ ] Execute actual Magento module and private Adobe Runtime installation/registration, worker concurrency/failure/replay and order/refund/uninstall acceptance on the deployed build.

### B78 — Lightspeed C refund evidence

- [x] Verify authoritative credit/return versus cash workflow distinction and record missing cash transaction/order-binding contract.
- [x] Retain unknown cash values and execute negative regressions preventing credit/quantity/manual paid-label inference and double subtraction.
- [ ] Implement a supported authoritative PSP/native cash-refund API mapping with exact order/merchant/currency/transaction binding and actual provider acceptance.

B78 audited source4b8626f: combinedC/Adobe/package/Runtime15PASS/254; Pint/diff PASS. This is regression/audit evidence only, not a cash mapping implementation. Gap remains OPEN; no C runtime/API/migration/financial change.

### B77 CI follow-up — Explicit backend Runtime test toolchain

- [x] Pin Node22 in the backend test jobs to match Adobe Runtime; parse workflow and execute the native action/archive regressions before committing.

B77 implementing source4b8626f; B78 regression/audit89cce64 (cash mapping remains OPEN). Node22 workflow parse/native6PASS/188; current integration/unit242/2195; fullPint/diff PASS. Full current-head matrix pending push; no native provider acceptance inferred.

### B77 capture follow-up — Explicit HTTP storage isolation

- [x] Pass the temporary storage path explicitly to the real HTTP bootstrap, regenerate actual captures and refresh matching OpenAPI examples; rerun API coverage.

B77 runtime CI41799578086 SUCCESS atf217e33: MySQL1457/4skip/24018, SQLite1432/29skip/23913, quality PASS. Final capture isolation/OpenAPI follow-up API5/8353; no runtime source change. Native installation/acceptance and C cash/publisher gaps remain explicitly open.

### B79 — CMS non-destructive media backend

- [x] Centralize CMS uploads; preserve exact private uploaded originals and stable normalized compatibility URLs without rewriting legacy assets.
- [x] Store immutable device-specific crop/rotation/flip/resize recipes; queue bounded, idempotent WebP derivatives and expose version-pinned manifests.
- [x] Implement optimistic recipe updates, private original access and exact restoration of existing derivative bytes with integrity checks.
- [x] Add authorized API contracts/security negatives, real isolated HTTP captures, source-compatible upload regressions, migrations and handoff evidence.

B79 tested source: `0a5afbe`; SQLite media8/70, CMS77/770, API19/8591, static/style PASS; real HTTP17. Deployed acceptance NOT RUN.

### B80 — Backend content/usage bindings

- [x] Derive paginated field bindings directly from authoritative drafts/immutable revisions, including source/hash/path/locale and explicit platform-global scope; reject stale pagination hashes.
- [x] Persist optional per-usage recipe pins inside the existing versioned CMS content, validating exact asset ownership and three ready breakpoints without mutating published snapshots.
- [x] Expose protected component-context preview manifests and bounded draft/history media-usage discovery; keep browser layout/front-end discovery unknown.
- [x] Verify compatibility/security/pinning/history, capture new APIs through real isolated HTTP and document exact remaining management work.

- [x] Inventory saved CMS page source fields with explicit global/tenant scope, locale, immutable version and media usage; preserve unknown frontend coverage.
- [ ] Supply component-context preview data and impact/replace/archive controls without claiming browser layout preview implementation.


B80 implementing SHA `6c01cc0`; CMS84/822, MySQL5/40, API5/8493, static/style/diff PASS, actualHTTP31. Other-source/frontend discovery and management mutations remain open.

### B81 — Consolidated CMS AI execution

- [x] Replace the three legacy Chat Completions implementations with section-scoped requests to the existing queued revision-backed proposal flow; preserve private suggestions/applied=false and expose polling metadata.
- [x] Reserve the configured worst-case verified model-context/output cost under the daily quota lock before provider execution; retain reservations on failures/timeouts and keep pricing/model assumptions explicit.
- [x] Require an independently recorded active-admin language/meaning review bound to the exact proposal/source before apply, including negative/stale/revoked-authority paths.
- [x] Capture changed legacy/review APIs on the real isolated server, document migrations/configuration/frontend polling requirements and execute focused compatibility/security regressions.

- [x] Route legacy homepage/AboutUs/merchant translation callers through the existing queued revision-backed proposal/review flow.
- [x] Enforce configured monetary reservation caps and independent language/semantic review before proposals are applicable.

B81 implementing SHA `bd9b4ec`; SQLite CMS92/879, MySQL AI/schedule27/286, API5/8522, static/style/diff PASS, actualHTTP43. Review is independent human attestation; live model quality/capability and automated classification remain unverified.

### B82 — Bulk content operations and recommended quality gates

- [x] Plan 1,000+ saved-page source records in bounded durable chunks, recording source/target version/hash and excluding legal/archived content.
- [x] Submit and reconcile bounded batches through the canonical queued proposal flow with current authority, stale-source rejection, coalescing, held monetary reservations and no automatic apply/publish.
- [x] Define the five recommended content coverage denominators and T01–T23 executor/evidence ledger without inventing original-source coverage or completed staging runs.
- [x] Connect a fail-closed content-specific deployment check to controlled staging execution; test missing/unknown/stale/fixture evidence and document installation limits.

- [x] Provide resumable bounded 1,000+ source backlog execution with dedupe, stale-source checks and review rather than automatic publication.
- [x] Document five recommended denominators and T01–T23 executor/evidence requirements; block unknown or unexecuted release coverage.

B82 implementing SHA `a5a2dbb`; final CMS/API103/9433, sequential QA36/405, MySQL9/54, static/style/diff PASS. Default actual executor/frontend/provider mappings remain unmapped; these checkboxes certify orchestration/gate source, not genuine acceptance.

### B83 — Remaining central content management

- [x] Add locale-validated FAQ subcategories and optimistic category updates, rejecting cycles/depth violations and preserving existing audience filtering/public compatibility.
- [x] Add soft media archive/reactivation and explicit version-checked selected-draft replacement; preserve published/historical files and prevent new archived references.
- [x] Add immutable reusable rich-text block versions, locale-specific pins and safe text overrides, with protected mount/detach and resolved public/preview source linkage.
- [x] B83c: define immutable single rich-text block versions (paragraph/heading/list/button), exact locale/hash provenance, bounded root-level usages and safe text-only overrides; preserve normal rich-text public compatibility.
- [x] B83c: implement protected library/create-version and revision-checked draft mount/detach; enforce pins in ordinary saves and exclude pinned text from AI improvement, rejecting cross-locale AI borrowing.
- [x] B83c: test stale/foreign locale/unsafe override/tampered body/immutable versions/published stability/security; record actual HTTP contracts and explicit coverage limits.
- [x] Verify mutation authority/concurrency/negative paths and compatibility; capture all added/changed API contracts through real isolated HTTP and record remaining frontend/staging limits.

- [x] Add FAQ subcategories and reviewed shared-block override/version semantics through existing CMS services.

### B84 — Controlled backend staging execution (#37 follow-up)

- [x] Add a disabled-by-default, staging-only reviewed-script runner bound to the exact configured deployed Git SHA, with bounded timeout/output, distributed exclusion and no shell/AI command execution.
- [x] Connect nightly/weekly/deployment entry points; require discovery, actual executed JUnit import and the existing independent acceptance gate before reporting success.
- [x] Test wrong environment/build, missing/unreviewed/tampered runner, concurrency, failure/timeout/missing report and successful execution without converting regression PASS into acceptance PASS.
- [x] Record installation/CI limits and original175/frontend/provider evidence blockers; update checkpoint documentation and commit SHA.

B84 implementing SHA: `f507073`. SQLite/MySQL runner7/31 each; full static/style/shell/diff PASS. Original175/provider/frontend/staging evidence and external deployment wiring remain incomplete; this checks off the controlled runner implementation only.

### B85 — Adobe SaaS IMS renewal and repeated integration review

- [x] Reuse existing asynchronous module/Runtime source; add scoped IMS client-credentials renewal for SaaS with encrypted token storage, current lifecycle/identity checks and serialized renewal.
- [x] Preserve static-token/self-hosted/bridge compatibility, credential-generation authority and fail-closed handling of malformed/expired responses, rotation, pause/disconnect and provider failures.
- [x] Execute focused security/regression tests; capture changed connection setup responses from the real isolated HTTP server and refresh API/integration documentation.
- [x] Recheck remaining native publisher/refund contracts against primary sources; explicitly retain unsupported mappings and unexecuted provider acceptance rather than guessing cash or write endpoints.

B85 remaining scope: genuine Adobe module/Runtime/SaaS installation/renewal/order/refund/uninstall acceptance NOT RUN. BigCommerce lifecycle is existing implemented source346b3bb. C/E-Series/Squarespace automatic publishers remain OPEN; C-Series cash mapping remains OPEN without authoritative PSP transaction binding. Checked implementation items above do not certify those gaps complete.

B85 tested implementing source `1b513f5`: SQLite261/2584, SQLite/MySQL IMS7/36 each, API5/8440, fullPint/PHPStan/diff PASS; actual HTTP14. Genuine provider/installation acceptance and unsupported publishers/refund mapping remain incomplete.

B83a FAQ implementing SHA follows closeout: CMS101/948, API5/8543, MySQL3/37, static/style/diff PASS; actualHTTP30. Other B83 management items remain open.

B83b tested source follows closeout: SQLite CMS/API110/9573, MySQL12/97, full static/style/diff PASS; actualHTTP40. B83a implementing SHA `16b3780`.

B83b implementing SHA `4f222e7`.

B83c tested source follows closeout: SQLite CMS/API/errors131/9919, MySQL21/189, QA36/405, static/style/diff PASS; actualHTTP43. Checkmarks apply to these bounded backend implementations, not universal client discovery, all23 real staging executions or release acceptance.

B83c implementing SHA `3aebd4f`; B83b `4f222e7`; B83a `16b3780`. Final executed checks and remaining independent/deployment acceptance are recorded in HANDOFF.md.

### B86 — Backend QA execution coverage enforcement

- [x] Require reviewed runner mappings to exact approved executable case versions/minimum assertions; reject AI proposals/manual cases as automatic executables.
- [x] Resolve discovered added/changed/removed backend targets to approved coverage, block unmapped impacts before execution and pass explicit required tests to reviewed runners.
- [x] Verify imported runner reports contain required keys, no duplicate/unregistered keys and required assertion counts; retain failed reports without implying independent acceptance.
- [x] Run focused planner/runner/acceptance checks only, update runbooks/handoff and preserve missing original175/frontend/provider/staging limits.
- [x] B86 deployment adapter: supply an opt-in reusable GitHub workflow that runs the existing hook only in the exact deployed staging checkout, preserving failure before promotion; validate YAML and shell without invoking staging.

B86 focused validation:21/101, scoped static/style/diff and workflow YAML/bash syntax PASS. Checkmarks cover backend enforcement/adapter source only; original175 mapping and real staging/provider/frontend acceptance remain incomplete. Implementing SHA follows closeout.

B86 implementing SHA `bf6ae17`; focused21/101 plus scoped static/style/workflow/whitespace PASS. Independent release gaps remain explicitly open.
## Native cart adapters beyond Shopify — 2026-11-04

- [ ] Implement opt-in WooCommerce Store API and BigCommerce Stencil readers with strict units/currency and same-origin validation.
- [ ] Wire WooCommerce plugin reader before renderer, including native mutation notifications and checkout/account exclusions.
- [ ] Exercise actual readers with renderer for progress, failure, freshness, races and lifecycle; rebuild and verify merchant archives.
- [ ] Document installation and per-platform limits; obtain genuine installed-store acceptance. Checkboxes await tested implementation commit SHA.

### Support settings and live connectivity review — 2026-11-04

- [ ] Preserve supported advanced settings and align form choices with backend validation.
- [ ] Localize settings labels, feedback and fixed-control explanations in NL/EN/DE.
- [ ] Measure bounded private-channel Reverb round trips and expire old/configuration-mismatched evidence.
- [ ] Run focused regression checks and record actual results and remaining deployment acceptance (commit SHA pending).

### B87 — Resolve PR7 conflicts against current main (2026-11-05)

- [x] Preserve branch CMS/QA environment settings and staged journeys alongside main's Reverb probe settings/schedule.
- [x] Retain both documentation/checklist histories and every original captured OpenAPI example; combine changed descriptions without inventing responses.
- [x] Verify focused catalogue/connectivity/API/package compatibility and syntax; regenerate inventory only if runtime contracts changed.
- [x] Record main/feature SHAs, validation and unchanged release limits; commit/push resolution on the feature branch only.
- [x] B87 package reconciliation: rebuild deterministic merchant archives/manifests from combined reviewed source so every embedded INSTALL.md matches the merge.

B87: focused22 non-package checks passed; archive rerun5/185 PASS after deterministic rebuild. Syntax/scoped style/semantic OpenAPI/environment preservation/conflict-marker/diff checks PASS. Merge implementation SHA follows closeout.

B87 implementing merge SHA `e14a603`; validation above covers conflict resolution only.

### B88 — CI dependency security audit repair (2026-11-05)

- [x] Confirm exact failing job/advisories; preserve passing tests, lint/static gates and mandatory audit.
- [x] Update only affected Symfony dependency constraints/lock entries to patched compatible releases, with no ignored advisories or unrelated upgrades.
- [x] Verify clean Composer audit/lock validity and focused HTTP/routing/OpenAPI compatibility; record actual CI follow-up and deployment limits.

B88 local validation: Composer audit0 advisories/abandoned, strict validate PASS, focused31/8881 and lock-delta/diff checks PASS. Failed CI41968973988 was audit-only; new CI remains pending until push.

B88 implementing SHA `435d229`; local audit/validation/focused31/8881 PASS; external follow-up CI pending push.

### B89 — Correlated support connectivity receipts (2026-11-07)

- [x] Separate public browser probe IDs from event-only receipt challenges; enforce current-admin ownership, freshness, configuration binding and atomic single use without leaking the challenge through HTTP.
- [x] Bind worker completion to the latest dispatched nonce/time, exact queue connection/name/configuration and actual queued-job context; reject stale/out-of-order/manual completions.
- [x] Keep browser observations per requesting administrator and scope receipt claims accurately; supersede older probes and invalidate changed configuration.
- [x] Execute focused negative tests plus an isolated real HTTP/Reverb/database-worker synthetic client journey; explicitly retain production-browser/frontend/staging limitations.
- [x] Capture changed API examples from the real server; update OpenAPI/runbook/checkpoint docs and record tests/SHA.

B89 tested source: focused20/8920, scoped style/static/whitespace PASS; real isolated HTTP/Reverb/worker protocol PASS with21 HTTP exchanges. Checkmarks certify backend/protocol implementation only, not the missing frontend listener or actual browser/staging delivery. Implementing SHA follows closeout.

B89 implementing SHA `e434296`; focused20/8920 and scoped static/style/diff PASS; actualHTTP/Reverb/worker21-exchange journey PASS. Frontend listener and browser/staging acceptance remain open.

B89 integrated main06d43d9: preserved all checkpoint/NL histories and approved source bytes; focused20/8964 and refreshed21-exchange real protocol journey PASS. Runtime inventory495/496. Frontend/browser/staging acceptance remains open.

### B90 — Provider notices and publication readiness localization (2026-11-07)

- [x] Localize setup native_notice and publisher human-facing reasons/requirements/methods without translating machine states, URLs or identifiers.
- [x] Add Dutch dictionary entries for the reviewed publication/setup notices, verify EN/NL behavior and capture changed API responses through a real isolated server.
- [x] Record provider publication API limits and the OpenCart policy question; preserve current unsupported states until actual write authority exists.

B90 validation: focused localization/OpenAPI 5 PASS / 8309 assertions; scoped PHPStan and Pint PASS. Implementing SHA recorded in the following checkpoint. Genuine provider acceptance NOT RUN.

B90 implementing SHA: `2ce1c2f`.

### B91 — Opt-in Shopify native coupon workflow (2026-11-07)

- [x] Add default-off, per-offer/version/credential-bound authorization and durable encrypted generation intents.
- [x] Implement fixed create/update/deactivate GraphQL operations with live scope/domain validation and owned-code readback; never blindly repeat uncertain writes.
- [x] Provide an authorized backend command for enabling, generating, reconciling, importing verified codes and deactivating unused codes; reject unsupported terms and issued-code changes.
- [x] Execute focused workflow/security/native-transport tests and scoped style/static checks; document migrations, configuration, exact supported subset and genuine-provider acceptance limits.

B91 validation: focused regression56 PASS / 8901 assertions; final provisioning/localization21 PASS / 494 assertions after drift/currency/immutable-intent additions. Scoped PHPStan, Pint and whitespace PASS. Seven real isolated API captures; actual provider writes/checkout/staging/MySQL NOT RUN. Implementation SHA follows closeout.

B91 implementing SHA `60869b4`; B90 localization SHA `2ce1c2f`. Local validation passed as recorded above; current-head CI and genuine provider acceptance remain pending.
### B92 — BigCommerce legacy native cart upgrade (2026-11-07)

- [x] Add a fixed owned-template PUT operation using the documented forced update contract; keep external writes default-off.
- [x] Upgrade matching legacy plans through install/explicit upgrade without rewriting immutable plans or removing widgets/placements; journal uncertain writes and reconcile without replay.
- [x] Verify ownership, current authority, credential/domain binding, idempotency, lost responses and safe cleanup with focused native HTTP tests.
- [x] Record commands, executed checks and remaining native cart/provider acceptance in task/progress/handoff docs.

B92: native publisher17 PASS / 499 assertions; localization3 PASS / 9; scoped PHPStan and Pint/whitespace PASS. Actual BigCommerce propagation/storefront acceptance NOT RUN. SHA follows closeout.

B92 implementing SHA `3180453`.

### B93 — Native package session-cart sources (2026-11-07)

- [x] Add exact bounded private session-cart snapshots for supported PrestaShop9/OpenCart4.1/Magento2.4 packages, without order data, guessed currency or new OpenCart catalogue endpoints.
- [x] Extend the shared native reader with narrowly validated same-origin package routes/header/section contracts, lifecycle/sensitive-route protection and native change notifications.
- [x] Wire ordered reader loading through enabled native packages and rebuild deterministic archives/manifests; preserve existing catalog/order semantics and disabled defaults.
- [x] Run focused runtime/native package/reader negative checks, document exact version/source boundaries, and retain unsupported Lightspeed/Squarespace/Adobe SaaS cases honestly.

B93 validation: combined package/publisher24 PASS / 731 assertions, including Node48 PASS via PHPUnit and four isolated native-framework contract fixtures; PHP syntax18 files and Magento XML well-formedness PASS; Pint/whitespace PASS. Actual native installation/DI/storefront/provider/staging acceptance NOT RUN. No Laravel migration or API route change. Source SHA follows closeout.

B93 implementing SHA `3cc0ef7`; B92 implementing SHA `3180453`. Local tests passed; CI/native deployed acceptance remain pending as recorded.

### B94 — Adobe storefront drop-in cart reader (2026-11-08)

- [x] Add `adobe-storefront` reader branch fed by documented `cart/data` event bridge; exact `total.includingTax` conversion, fail closed.
- [x] Focused Node tests for conversion, rejection, no-fetch and sensitive-page exclusion; document bridge snippet and limits.
- [ ] Live Adobe storefront acceptance (NOT RUN).
- [ ] Ecwid/Lightspeed E, Lightspeed C, Squarespace readers — blocked on missing documented unit/currency/session contracts.
