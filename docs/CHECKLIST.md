# Implementation Checklist

Granular companion to `docs/TASKS.md`. **Rule (owner, 2026-10-23):** tick a box in the same commit that implements the item, and only once its tests have actually been run and passed. Put the commit SHA (or "this commit") after each ticked item. An unticked box means not done or not verified. Never tick from intent.

Tasks further down are listed at headline level. Expand them into concrete items when the task is started, before writing code.

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
- [ ] CI actually run green on GitHub (blocked: no git remote)
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
- [ ] Staging p95 for `/me` (<250 ms) and signup (<400 ms) (blocked: no staging environment)
- [ ] Super Admin/owner MFA (moved to B16 by design; Fortify 2FA re-enabled there)
- [ ] Secured email-change re-verification flow (moved to B08)

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
- [ ] Google Places adapter verified against the live API (blocked: no `GOOGLE_PLACES_API_KEY`)
- [ ] Successful DNS/website-file check captured live (blocked: needs a public domain we control; covered by tests with fakes)

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

- [ ] Live verification of WooCommerce / Shopify adapters (blocked: no store or sandbox access; checklist in docs/integrations/VERIFICATION.md)
- [ ] Adapters for Wix, BigCommerce, Ecwid, Lightspeed, Squarespace, Mijnwebwinkel, CCV (blocked: auth mechanisms and credentials unverified)
- [ ] Private modules for PrestaShop/OpenCart/Shopware/Magento (outside backend scope: plugin code; the format they must speak is specified)

## B04–B19

## B04 Ecommerce provider contracts (Trello #1 "Supported platforms", "Shared backend"; #3 A.4)

No provider credentials exist: every real-platform adapter is NOT VERIFIED until tested against a real store or sandbox (OPEN-PROVIDER-CAPABILITIES).

- [ ] Capability matrix (`config/integrations.php`): per platform method, auth, signature scheme, order data source, reliability, adapter class or none, verification state; `GET /api/v1/integrations/platforms`
- [ ] `webshop_connections`: public ID, webshop, platform, method, status (setup_required, testing, connected, limited, error, disconnected), encrypted credentials, opaque webhook token, limitations, health timestamps, safe last error; one non-disconnected connection per webshop+platform
- [ ] Connector interface (`setupInstructions`, `connect`, `testConnection`, `verifyIncomingEvent`, `fetchOrder`, `normalizeOrder`, `syncOrders`, `disconnect`) + `ConnectorRegistry`
- [ ] `NormalizedOrder` DTO: integer minor units, ISO currency, mandatory fields (external ID, reference, first name, email, amount, currency, timestamps), optional phone/payment/cancel/refund; missing mandatory → incomplete (never fabricated)
- [ ] WooCommerce adapter: `X-WC-Webhook-Signature` base64 HMAC-SHA256 of the raw body, delivery-ID dedupe, ping handling, order payload mapping (NOT VERIFIED live)
- [ ] Shopify adapter: `X-Shopify-Hmac-Sha256`, `X-Shopify-Webhook-Id` dedupe, orders/* topics, redacted-field handling → limited (NOT VERIFIED live)
- [ ] Generic Shopbonus signed-webhook adapter for private modules: `X-Shopbonus-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "t.body">`, 5-minute replay window, event-ID dedupe; fully specified format
- [ ] `webhook_events`: dedupe key unique per connection, verification result, topic, encrypted payload (pruned after 14 days by scheduled command), safe error, processing status
- [ ] `POST /api/v1/webhooks/{platform}/{token}`: public, throttled per token, verifies before trusting anything, constant-time compare, 401 on bad signature, 404 on unknown or disconnected token, fast 202 and async processing; duplicates acknowledged without reprocessing
- [ ] Event processing job: normalise; the first verified complete order moves the connection to connected (or limited); a ping moves setup_required to testing; never connected from saved credentials alone
- [ ] Merchant endpoints (integrations.view / integrations.manage): list, create (secret shown once), show with instructions, rotate secret (shown once), verify checklist, disconnect (credentials wiped), sanitized event history
- [ ] Audit connection changes, secret rotations, rejected signatures (rate-limited)
- [ ] Tests: signature valid/invalid/replayed/old timestamp per adapter, duplicate delivery processed once, unsupported platform refused, cross-tenant 404, secret never in responses after creation or in logs, card #1 E2E 2 "unsupported connections cannot activate", E2E 3 incomplete access blocks connected (fixture), E2E 4 mandatory/phone rules at normalisation
- [ ] Docs with real captures; PROGRESS/HANDOFF/TASKS updated
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

- [ ] Expand when started
- [ ] B07 Answer24 handoff/claim
- [ ] B08 Consumer profile/discovery
- [ ] B09 Wallet ledger core
- [ ] B10 Affiliate integrations (credit timing decision required)
- [ ] B11 Voucher checkout/Mollie
- [ ] B12 Merchant wallet/funding
- [ ] B13 Merchant analytics
- [ ] B14 Campaign/banner backend
- [ ] B15 CMS/legal/cookie API
- [ ] B16 Super Admin APIs
- [ ] B17 Customer support backend
- [ ] B18 E2E/security/load/release
- [ ] B19 Integration marketplace listing (BLOCKED: empty card)
