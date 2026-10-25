# Latest Agent Handoff

**Session:** 2026-10-25, Codex continuing B16c; backend only.
**Repo / branch:** `/Users/kcoder/Projects/Shopbonus` / `main`; direct commits and normal pushes authorized.
**Baseline SHA:** `856244f` (origin/main at start); B16c WIP checkpoint being committed; obtain exact checkpoint SHA with `git log -1 --oneline`.
**Working tree:** B16c implementation, tests, API captures and continuity docs are the intended checkpoint changes; no pre-existing uncommitted work was present.
**Active task:** B16c locally verified; WIP/final commit and CI checkpoint pending. Then B16d.
**Disk:** started at 6.1 GiB free; after QA about 5.3 GiB. Run `df -h /System/Volumes/Data` BEFORE further work; if below about 5 GiB STOP and ask owner. Do not purge binlogs or other projects' data.
**Decision:** D-064, owner answered "whatever you think": all wallet adjustments require a different Super Admin reviewer, for every amount/unit (`dual-admin-v1`). No numeric risk threshold/points conversion invented.
**B16c delivered:** 13 admin routes for wallet/ledger/flags, dual-admin requests/reviews, financial evidence, connection/Answer24 monitoring and safe retries. Two migrations `2026_10_25_100000` (flag ULIDs/backfill/review fields) and `110000` (`admin_approval_requests`) applied locally and rollback/reapply verified. Current consent/payment/suppression eligibility rechecked by dispatcher. Existing affiliate/dispute/checkout flows reused. Original outbox identity, payload and attempts preserved on retry; dispatch remains off by config. Redemption transition denials are safely audited outside the failed transaction.
**Acceptance:** Trello #21 E2E 5/6 covered locally; real Answer24/Mollie/affiliate providers and staging NOT VERIFIED. B16d/E2E 7–9 remain; AI belongs to B17, blocked on provider choice. UI/E2E 10 frontend/staging parts out of backend scope.

## Tests actually executed (B16c checkpoint)

- `php artisan test`: **445 passed + 8 skipped**, 6380 assertions (MySQL-only races skipped).
- `DB_CONNECTION=mysql DB_DATABASE=shopbonus_testing DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test`: **453 passed**, 6413 assertions, 117 s.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`: PASS.
- `composer audit`: no security advisories.
- `php artisan migrate --force && php artisan migrate:rollback --step=2 --force && php artisan migrate --force`: both B16c migrations OK on local MySQL.
- Focused MySQL admin/race tests: 15 passed. Concurrent approvals by different admins post one compensating entry and reconcile.
- Nine guard mutation checks: self-review, consumer/source matching, unresolved mismatch, resolved flag replay, recent password, OTP, current consent, webhook retry, outbox retry all detected failures; source restored.
- Real running-server capture: isolated temporary SQLite, database sessions, port 8076; two real password/emailed-OTP sessions; 13 routes and domain conflicts. Committed to `docs/api/{README.md,openapi.yaml}`. Secrets excluded; temporary mail log must be truncated/removed at cleanup.
- Local server process still needs cleanup at this checkpoint (port 8076; created only for captures). Temporary fixtures/scripts in `/tmp/shopbonus-b16.h1xjUO` contain generated test passwords, DB and email codes: remove after capture verification. Do not commit them. Existing application log was not used for email; capture log is `server.log` in that temporary directory.
- B16c GitHub CI pending push/check; staging p95, live providers and PostgreSQL NOT RUN.

## Environment / migrations / config

- Local: PHP 8.4.17, MySQL (`root`, no password) with DBs `shopbonus` and `shopbonus_testing`, Redis 127.0.0.1:6379 via predis (D-004).
- **Local Redis is shared with other projects.** Only delete keys matching `shopbonus-*` (databases 0 and 1). Never `FLUSHDB`/`FLUSHALL`; see the incident note below.
- Migrations applied locally: Laravel defaults, `personal_access_tokens`, `2026_10_23_130000/130100` (audit, errors), `2026_10_23_140000..140300` (identity), `2026_10_23_150000` (webshops, memberships, platform roles), `2026_10_23_160000` (invitations), `2026_10_23_170000` (business profiles, verifications, ownership columns), `2026_10_23_180000` (agreements), `2026_10_23_190000` (connections, webhook events), `2026_10_23_200000` (orders, order events), `2026_10_23_210000` (offers, offer versions), `2026_10_23_220000` (voucher codes, batches, vouchers, events), `2026_10_23_230000` (contact suppressions, opportunities, outbox; order customer_key/consent), `2026_10_23_240000` (integration clients, inbound events, opportunity events, voucher claims, claim reminders), `2026_10_23_250000` (public profiles, public categories, offer slugs), `2026_10_23_260000` (users phone/avatar, email change requests, favourites, category preferences, privacy requests), `2026_10_23_270000` (wallets, ledger + triggers, lots, reservations, pending, reconciliation flags), `2026_10_23_280000` (affiliate connections, programs, selections, clicks, reward rules, transactions, reconciliation events), `2026_10_24_100000` (voucher checkouts, checkout payments), `2026_10_24_110000` (voucher redemption columns, disputes, dispute events), `2026_10_24_120000` (funding invoices, sponsorship obligations, top-ups), `2026_10_24_130000` (merchant notifications), `2026_10_24_150000` (CMS pages, locales, revisions, assets, FAQ), `2026_10_24_160000` (cookie policies, cookie consents), `2026_10_24_170000` (users two-factor columns, step-up challenges, impersonation sessions). `2026_10_24_140000` (banner installations, campaigns, versions, assets, pairings, events, clicks, daily stats; `webshop_orders.attribution_click_id`).
- Env key names added in B02: `LEGAL_TERMS_VERSION`, `LEGAL_PRIVACY_VERSION`, `LEGAL_MARKETING_CONSENT_VERSION` (empty = registration disabled), `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE` (true in prod), `SESSION_SAME_SITE`, `VERIFICATION_CODE_TTL_MINUTES`, `VERIFICATION_CODE_MAX_ATTEMPTS`, `VERIFICATION_RESEND_COOLDOWN_SECONDS`. B07: `ANSWER24_DISPATCH_ENABLED` (false), `ANSWER24_CONTACT_PATH` / `ANSWER24_CANCEL_PATH` / `ANSWER24_CLAIM_STATUS_PATH` (Shopbonus-defined defaults), `ANSWER24_BASE_URL`, `ANSWER24_SIGNING_SECRET` (secret), `ANSWER24_ALLOW_LEGITIMATE_INTEREST` (false), `ANSWER24_RECENT_CONTACT_DAYS` (30). B06: `DIRECT_REWARD_FEE_BPS` (3000). B05: `BUSINESS_TIMEZONE` (default Europe/Amsterdam), `BROADCAST_CONNECTION` (null by default). B04: `WEBHOOK_PAYLOAD_RETENTION_DAYS` (default 14). B03c: `GOOGLE_PLACES_API_KEY` (secret; empty = manual entry only). B12: `FUNDING_REQUIRED` (true), `FUNDING_PERCENTAGE_BASIS` (maximum_discount). B11a: `MOLLIE_API_KEY` (secret; test_ keys only), `MOLLIE_API_BASE_URL`, `MOLLIE_ALLOW_LIVE` (false). B10: `AFFILIATE_CREDIT_TIMING` (on_network_approval), `AFFILIATE_FALLBACK_NETWORK` (daisycon), `DAISYCON_API_BASE_URL`. B09: `WALLET_CREDIT_VALIDITY_MONTHS` (12). B16a: `ADMIN_MFA_REQUIRED` (true; false in phpunit.xml), `ADMIN_STEP_UP_CODE_TTL_MINUTES` (10), `ADMIN_STEP_UP_MAX_ATTEMPTS` (5), `ADMIN_STEP_UP_VALID_MINUTES` (10), `ADMIN_SUPPORT_SESSION_MINUTES` (30). B08a: `DISCOVERY_CACHE_TTL_SECONDS` (60), `API_DOCS_ENABLED` (default on outside production). Earlier: `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `CORS_SUPPORTS_CREDENTIALS`, `API_RATE_LIMIT_PER_MINUTE`, `HEALTH_READINESS_TOKEN` (secret).
- Capturing docs: run the server with `QUEUE_CONNECTION=sync MAIL_MAILER=log`, read codes/tokens from `storage/logs/laravel.log`, then **truncate that log**, since it holds live codes and reset tokens.
- **Admin captures since B16a:** admin routes need confirmed 2FA. Either start the server with `ADMIN_MFA_REQUIRED=false`, or enrol 2FA in the script and disable it again at the end (`DELETE /user/two-factor-authentication`), as the B16a capture did. TOTP codes can be computed with `php -r "require 'vendor/autoload.php'; echo (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp('<secret>');"`. Never put the secret, QR code, recovery codes or support tokens in docs.

## Conventions to reuse

- Routes: `routes/api.php` v1 group. Private routes go inside `auth:sanctum` + `account.active`, and inside `verified` unless the email-verification flow itself needs them. Declare `x-access` per operation in `docs/api/openapi.yaml`; `OpenApiCoverageTest` enforces docs, examples and access matching.
- Errors: `ApiException` (supports `$details` and `$headers`). Audit: `AuditLogger::record()` in transactions, `recordSafely()` otherwise. Append-only models: `use App\Models\Concerns\AppendOnly`.
- Public IDs: models exposed to clients get a ULID `public_id` (`HasUlids` + `uniqueIds()`); never expose integer keys.
- Session-flow tests: `use Tests\Concerns\BrowsesLikeSpa` and `$this->spa('POST', ...)`. Bearer-token requests after `spa()` must `flushHeaders()` and flush the session store (see `AdminSecurityTest::asSupport`), or the test client leaks the SPA origin.
- Sensitive admin actions: `password.recent` then `step_up.otp` (emailed code, `StepUpCodes`). New sensitive non-admin routes must be added to the `GuardSupportSession` denylist so support sessions cannot use them.
- Index names over 64 characters fail on MySQL only: give composite indexes explicit short names.

## Incident note (2026-10-23)

While capturing docs, an early script ran `redis-cli FLUSHDB` on local Redis **database 0 twice**. Database 0 may have held other local projects' data, which would be lost. Database 1 (other projects' cache keys) was not touched. The capture script now deletes only `shopbonus-*` keys.

## Blockers / open decisions

- OPEN-SIGNUP-ENUMERATION (new), OPEN-AFFILIATE-CREDIT-TIMING, OPEN-REWARD-UNITS, OPEN-FINANCIAL-LEGAL, OPEN-MARKETING-CONSENT, OPEN-PROVIDER-CAPABILITIES, OPEN-EMPTY-CARD-27 (see `docs/DECISIONS.md`). None block B03.
- Remote: `origin` = https://github.com/nauticsecure-dev/shopbonus_backend (owner-requested one-time force push on 2026-10-24 replaced an unrelated "first commit" `7d7790c`, kept locally as tag `backup/remote-first-commit-7d7790c`). Normal pushes only from now on. GitHub CI runs; PHP 8.4 only (D-055). No staging (p95 unverified).

## Workspace conventions (B03a)

- Workspace-scoped routes: `Route::prefix('workspaces/{workspace}')->middleware('workspace')`, plus `->middleware('workspace.can:<permission>')` per route. Read the webshop from `CurrentWorkspace`, never from input. Declare `x-permission` in OpenAPI.
- Non-member access is a generic 404 (and audited by the resolver); members lacking a permission get 403.
- Commercial routes (offers, vouchers, integrations, payments) must add `workspace.commercial` after `workspace`.
- Tests needing a commercially active webshop: `use Tests\Concerns\CreatesActiveWebshops` → `$this->activeWebshop($owner)`.
- Fees: always `FeeCalculator` (never inline percentages).
- Vouchers: only through `VoucherInventory` (reserve with an idempotency key such as `order:<id>:offer:<id>` or `answer24-handoff:<ref>`). Never update voucher/code status directly.
- Bulk inserts that bypass Eloquent must generate `public_id` with `strtolower((string) Str::ulid())`.
- Sponsored vouchers move merchant funding automatically inside `VoucherInventory`; tests get funded webshops from `CreatesActiveWebshops` (drain with `WalletService::adjust` to test unfunded cases).
- User-facing messages: write them in English and add the Dutch text to `lang/nl.json` (keyed by the English text); `__()` is applied in the error renderer.
- MySQL returns 0 affected rows when an UPDATE changes nothing: never treat `update() !== 1` as "not found" unless the update always changes a column (status/revision).
- Push with `git push origin main` (never force) after each task; CI must stay green.
- Checkouts only through `CheckoutService`; lock order offer → checkout → code → wallet. Test Mollie with an `Http::fake` callback driven by test properties (the first registered fake wins, so re-faking inside a test does nothing).
- Wallet changes only through `WalletService` (idempotency key on every call; never touch `wallets` columns directly). Choose the unit explicitly.
- Tests that commit real rows (concurrency) use `Tests\Concerns\RacesProcesses` + DatabaseTruncation, call `truncateAfterRace()` in tearDown, and override `truncateDatabaseTables()` to skip on SQLite (see `ConcurrentWalletTest`).
- Partner (server-to-server) routes: `->middleware('integration.signed:<scope>')`, declared `x-access: integration` with `security: [{ integrationSignature: [] }]`. Credentials only via `php artisan shopbonus:create-integration-client`. Tests sign with `App\Support\SignatureV1::header()` (see `Answer24ChannelTest::signed()`).
- Claim links: only through `App\Services\Answer24\ClaimLinks` (handoff/accept/reminder/expire). Outbox delivery only through `OutboxDispatcher` (`shopbonus:dispatch-outbox`).
- Public read endpoints: under `Route::prefix('public')`, data through `PublicCatalog` only (published fields, cached via `DiscoveryCache`). Models whose change affects public data must call `DiscoveryCache::flush()` on save.
- Consumer routes: under `Route::prefix('consumer')` in the verified group; always `$request->user()`, never an ID from input. Optional mail must check `CommunicationPreferences::allows()`.
- Notifications sent with `Notification::route(...)` must type `toMail(object $notifiable)` (an on-demand recipient is not a `User`); `Notification::fake()` never renders mails, so add a render test.
- API docs browsable at `/docs` and `/docs/reference` (local: `php artisan serve` → http://127.0.0.1:8000/docs).
- Admin routes: inside the `admin` prefix group (`platform:super_admin`); mutations inside `password.recent`. Test them with a stateful session (`Referer`/`Origin` headers + `withSession(['auth.password_confirmed_at' => time()])`).
- **Never write a denial/security audit inside a DB transaction that then throws**: it rolls back with it. Audit before the transaction, or after commit.
- Never use database `NOW()` in queries or scripts: local MySQL runs in the server's timezone while the app writes UTC. Use PHP `now()`.
- The agent shell is zsh: never name a shell variable `path` (it is tied to `PATH`). Capture scripts are bash files using `caplib.sh` (see the scratchpad pattern described in PROGRESS); keep that pattern.

## Next agent's exact first actions

1. Check disk cutoff, git status/log. Finish B16c WIP/final commit and normal push/CI verification, record exact SHAs and tick CHECKLIST only for executed acceptance evidence. Full QA above passed; final flag unit field/capture refresh passed the targeted OpenAPI/wallet/dispute regression check (12 tests).
2. B16d: expand its checklist before implementation. Reuse audit_events/application_errors; incidents grouped by fingerprint or route+status, preserve request-ID occurrences for every 401/403/419/422/429/500; assignment and New/Investigating/Resolved/Ignored workflow. Generate API route registry from runtime routes + OpenAPI x-access. Tamper-evident audit chain and verification command, authorized audit export. Versioned platform settings draft/review/publish/rollback; reuse CMS/agreement systems and keep sensitive provider/financial/consent policy disabled until explicit versioned owner decisions. AI diagnostics B17 only.
3. Keep all existing provider/legal/marketing/affiliate open decisions; outbound Answer24 off, Mollie test-only, Moneybird not connected. Do not implement client UI.
