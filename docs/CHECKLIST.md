# Implementation Checklist

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
- [ ] **BLOCKED** Platform coupon verification via provider coupon APIs (blocked: no coupon adapters/credentials; status stays `merchant_verification_required`)
- [ ] **MOVED** Card #7 E2E 7 real webshop coupon check (blocked as above) and E2E 8 merchant funding enforcement (B12)

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
- [x] Tests: card #19 E2E 2, 3, 4 (fixtures), 5, 6, 7, 9; card #23 test 6 (instant mode) (`37edebf`)
- [x] Docs with real captures; PROGRESS/HANDOFF/TASKS/DECISIONS updated (`37edebf`)
- [ ] **BLOCKED** Live Daisycon/TradeTracker verification and the TradeTracker SOAP transport (no accounts; see docs/integrations/VERIFICATION.md)
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
- [ ] **MOVED** Banner funnel metrics (impressions, CTR, attribution, A/B, holdout) → B14; Super Admin network dashboard → B16
- [ ] **BLOCKED** Staging p95 for dashboard endpoints (no staging)

## B14 Banner campaigns backend (Trello #25, #24 §1–2)

The live editor, renderer and embed script are frontend; the backend owns campaigns, versions, rules, public config, events, attribution and results. Checkout, payment and account pages are always excluded.

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
- [ ] **BLOCKED** AI Create / AI Insights (no AI provider configured; B17 decides the provider) and A/B + holdout experiments (**MOVED** to a follow-up slice B14b)
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
- [ ] **BLOCKED** Card #5 E2E 1 frontend part and E2E 10 staging p95 (frontend; no staging)

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

- [ ] `GET /admin/cockpit` KPI cards from real records with stated definitions (webshops by status, consumers, published offers, vouchers issued/redeemed, inventory, orders received/paid, Answer24, affiliate pending/approved/rejected separately, wallet issued/spent/outstanding per unit, open disputes, incidents); period today/7d/30d/custom (≤ 366 days); daily trends; cached 60 s with `generated_at`
- [ ] Attention lists with links: pending approvals, integration failures, failed jobs, reconciliation flags, paid-unfulfilled checkouts, affiliate problems, security denials
- [ ] `GET /admin/activity` recent audit events (paginated, filters)
- [ ] Users: search/filter (role, status, webshop, registration date; paginated), detail (memberships, roles, 2FA, wallets; no hashes); suspend/reactivate with reason (sessions and tokens end immediately); reset security access (2FA off + tokens revoked; step-up code); revoke membership (last active owner protected); revoke Super Admin (step-up code; last admin protected; grant stays console-only)
- [ ] Webshops: list columns owner, integration status, active offers, last activity; approval checklist in detail; request additional information (notifies the merchant)
- [ ] Tests incl. E2E 1 (cockpit) and E2E 3 (user security), mutation check
- [ ] Docs with real captures

### B16c Financial, integration and Answer24 oversight (Trello #21 "Financial & voucher oversight", "Integrations & Answer24 monitoring")

- [ ] Expand when started

### B16d Incidents, route registry, audit integrity, platform settings (Trello #21 "Central errors & security", "Audit & security controls", "Platform content & settings")

- [ ] Expand when started

- [ ] B17 Customer support backend
- [ ] B18 E2E/security/load/release
- [ ] B19 Integration marketplace listing (BLOCKED: empty card)
