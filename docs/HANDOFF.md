# Latest Agent Handoff

**Session:** 2026-10-25, Codex; Laravel backend only.
**Branch:** `main` (direct commits/normal push authorized). **Last committed code:** `7b2c8d1` B16d, pushed; CI `40196742388` passed (MySQL, SQLite, lint/static/audit). Earlier `4786e65` B16c; CI `40195059904` passed. This final documentation checkpoint follows those implementation commits.
**Working tree:** clean at `7b2c8d1`; final documentation-only checkpoint updates continuity and the Frank-card report. After committing, `git status -sb` must be clean. No pre-existing user changes were overwritten.
**Current work:** B16c/B16d backend implementation complete within documented safe scope; implementation CI passed. Final documentation-only commit/push and its CI check are the closing actions. Owner additionally asked which Frank cards can be ticked from a newly downloaded Trello export. Read-only report: `docs/TRELLO_FRANK_AUDIT_2026-10-25.md`. None of 14 full Frank cards meet all acceptance criteria; several backend slices are done. New #29–#32 and revised #1 must be triaged separately; old requirements file was not silently replaced.
**Disk:** last measured 6.3 GiB free, fluctuates between 5.3 and 6.3. Always run `df -h /System/Volumes/Data` first; below about 5 GiB STOP and ask owner. Never purge binlogs or other projects' data.
**Decisions:** D-064 dual-admin-v1 for every wallet adjustment (owner delegated choice); D-065 audit chain/anchoring limits; D-066 safe public metadata allowlist; D-067 incident grouping/no raw payloads. All provider/legal/marketing/affiliate OPEN decisions remain.

## Delivered and limitations

- B16c (13 routes): wallet/ledger/flags, dual-admin source-linked corrections, financial evidence, connection/Answer24 oversight and locked original-identity retries. Current consent/suppression/payment eligibility rechecked before contact dispatch. No payouts; WalletService remains the sole wallet writer; dispatch off/test-only settings preserved.
- B16d (11 routes): grouped incident/status/assignment/history API; occurrence request IDs for HTTP 401/403/419/422/429/500 and queue failures; generated runtime/OpenAPI registry with 256 matching operations and CLI; SHA256 chain companion seals/head, verify command and bounded authorized export; immutable public-setting draft/review/independent publish/new-draft rollback with public published settings output.
- Settings allowlist only public.brand_name, public.support_email, public.languages; sensitive legal/consent/provider/financial policies remain in existing systems, never generic settings activation. UI/AI/staging not delivered; whole Trello #21 is still PARTIAL.
- Audit migration seals historical rows as observed, not retrospectively authenticated. Exported heads need independent off-host retention to detect a privileged DB administrator rewriting every row/hash/head. No retention deletion policy invented. Export is a bounded safe projection (≤500/page, ≤31 UTC days), not an unbounded bulk-report job; metadata/internal resource IDs/private payloads omitted.
- No live provider claims: Answer24/Mollie/affiliate NOT VERIFIED; Moneybird not connected; AI diagnostics B17/provider decision blocked; new centralized-notification/reliability cards not implemented by existing merchant notification/incident slices.

## Tests actually executed

B16c: SQLite **445 passed + 8 skipped**; MySQL **453 passed**; Pint/PHPStan clean; composer audit clear; two migrations rollback/reapply passed; nine guard mutation checks detected/restored; real-server captures for 13 routes; pushed CI passed.

B16d final executed evidence (`7b2c8d1`, implementation CI passed):
- Final SQLite **459 passed + 10 skipped**, 6676 assertions. Ten SQLite skips are MySQL-only process races (8 B16c races + 2 new B16d races).
- Final MySQL suite **467 passed + 2 skipped**, 6711 assertions, 129 s. The two skips are explicit destructive trigger-bypass simulations exercised on SQLite; MySQL tests trigger enforcement without dropping protections. Two old audit-outage tests now inject a QueryException instead of dropping an FK-referenced shared table; targeted MySQL rerun 9 passed.
- Focused B16d MySQL **14 passed + 2 skipped**; destructive trigger-bypass tamper simulations use transactional SQLite only. MySQL separately tests trigger enforcement and unsealed/head-corruption detection.
- Real-process MySQL race group **3 passed**: wallet approval, eight audit appends, same-version concurrent setting publication. Initial INSERT IGNORE → FOR UPDATE shared-lock upgrade deadlock found and fixed by exclusive no-op head upsert; rerun passed.
- Pint/PHPStan pass; composer audit no advisories. Nine B16d guard mutations detected failures; original source restored.
- Local MySQL migrations `120000` incidents, `130000` audit chain/backfill, `140000` platform settings all applied/rolled back/reapplied; `shopbonus:audit-verify` passed (230 historical entries at check).
- Runtime `shopbonus:api-routes` command generated **256 operations**, all x-access/permission contracts match. Real HTTP captures for all 11 B16d operations and domain conflicts, in OpenAPI/README.
- Capture server stopped; task-owned temporary SQLite fixture, generated passwords, email-code log and helper artifacts removed. Main application log was not used for mail. No secrets/capture fixtures committed.
- Staging p95, encrypted backup/restore, production alerting, PostgreSQL and real providers NOT RUN.

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

1. Disk cutoff and git status/log first. B16c/B16d local suites, migrations, captures and implementation CI passed. Final doc checkpoint must be pushed/CI checked; inspect latest git log and CI if resuming during that final action. Capture server/temp credentials already removed.
2. Owner's requested Frank-card audit is `docs/TRELLO_FRANK_AUDIT_2026-10-25.md`: none of 14 full cards Done; list completed backend subtasks separately. No Trello changes were authorized or made.
3. After this checkpoint, B17 is blocked on AI provider/model decision; B18 needs staging/provider/backup evidence; B19 has empty requirements. New downloaded cards #29–#32 and revised #1 need explicit queue triage; do not silently call them done or implement UI. Maintain all OPEN decisions; no live money/marketing activation.
