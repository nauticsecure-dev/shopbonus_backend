# Latest Agent Handoff

**Session:** 2026-10-23, first implementation session (continued; owner said "continue and don't stop")
**Agent:** Claude (Opus 5.5)
**Repo / branch:** this local repo (no remote) / `main` (direct commits authorized, D-002)
**Last code commit:** `e5df771` B14. Earlier: `2bfa953` B13, `1edd8d9` B12, `0dc12d6` CI PHP 8.4, `cd48d26` B11b, `5fca11a` B11a, `37edebf` B10, `862d3d0` B09, `c2dfb15` B08b, `4c4e26c` B08a, `0c6c035` B07b, `5c24240` B07a, `c07f778` B06b, `c6485dc` B06a, `7e391be` B05, `820c6b5` B04, `5acdfbd` B03d, `b230455` B03c, `ec20038` B03b, `2fe8db6` B03a, `c95e1e4` B02, `088fb20` B01b, `a51a9b6` B01a.
**Working tree state at handoff:** clean (verify with `git status -sb`).
**Active task:** B15a IN PROGRESS (work-in-progress commit, see "B15a resume point"). **Next:** B15 CMS, legal pages and cookie consent API (Trello #5, #4, #18).
**Last completed task:** B14 banner campaigns backend.

## Tests actually executed (at `e5df771`)

| Command | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory) | PASS: 403 + 7 skipped (MySQL-only concurrency) |
| `DB_CONNECTION=mysql DB_DATABASE=shopbonus_testing DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test` | PASS: 410 tests (incl. forked-process race tests) |
| `vendor/bin/pint --test` | PASS |
| `vendor/bin/phpstan analyse --memory-limit=1G` (level 6) | PASS, 0 errors |
| `composer audit` | No advisories |
| `php artisan migrate:rollback --step=1 && php artisan migrate` (local MySQL, B14) | OK |
| Real HTTP capture of the whole auth flow (MySQL + Redis sessions) | Done; in `docs/api/README.md` → Auth |
| GitHub Actions CI | PASS for PHP 8.4 (SQLite, MySQL) + lint/static (see `gh run list`) |
| Staging p95, PostgreSQL trigger | NOT RUN (no staging, no PG) |

## Environment / migrations / config

- Local: PHP 8.4.17, MySQL (`root`, no password) with DBs `shopbonus` and `shopbonus_testing`, Redis 127.0.0.1:6379 via predis (D-004).
- **Local Redis is shared with other projects.** Only delete keys matching `shopbonus-*` (databases 0 and 1). Never `FLUSHDB`/`FLUSHALL`; see the incident note below.
- Migrations applied locally: Laravel defaults, `personal_access_tokens`, `2026_10_23_130000/130100` (audit, errors), `2026_10_23_140000..140300` (identity), `2026_10_23_150000` (webshops, memberships, platform roles), `2026_10_23_160000` (invitations), `2026_10_23_170000` (business profiles, verifications, ownership columns), `2026_10_23_180000` (agreements), `2026_10_23_190000` (connections, webhook events), `2026_10_23_200000` (orders, order events), `2026_10_23_210000` (offers, offer versions), `2026_10_23_220000` (voucher codes, batches, vouchers, events), `2026_10_23_230000` (contact suppressions, opportunities, outbox; order customer_key/consent), `2026_10_23_240000` (integration clients, inbound events, opportunity events, voucher claims, claim reminders), `2026_10_23_250000` (public profiles, public categories, offer slugs), `2026_10_23_260000` (users phone/avatar, email change requests, favourites, category preferences, privacy requests), `2026_10_23_270000` (wallets, ledger + triggers, lots, reservations, pending, reconciliation flags), `2026_10_23_280000` (affiliate connections, programs, selections, clicks, reward rules, transactions, reconciliation events), `2026_10_24_100000` (voucher checkouts, checkout payments), `2026_10_24_110000` (voucher redemption columns, disputes, dispute events), `2026_10_24_120000` (funding invoices, sponsorship obligations, top-ups), `2026_10_24_130000` (merchant notifications), `2026_10_24_140000` (banner installations, campaigns, versions, assets, pairings, events, clicks, daily stats; `webshop_orders.attribution_click_id`).
- Env key names added in B02: `LEGAL_TERMS_VERSION`, `LEGAL_PRIVACY_VERSION`, `LEGAL_MARKETING_CONSENT_VERSION` (empty = registration disabled), `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE` (true in prod), `SESSION_SAME_SITE`, `VERIFICATION_CODE_TTL_MINUTES`, `VERIFICATION_CODE_MAX_ATTEMPTS`, `VERIFICATION_RESEND_COOLDOWN_SECONDS`. B07: `ANSWER24_DISPATCH_ENABLED` (false), `ANSWER24_CONTACT_PATH` / `ANSWER24_CANCEL_PATH` / `ANSWER24_CLAIM_STATUS_PATH` (Shopbonus-defined defaults), `ANSWER24_BASE_URL`, `ANSWER24_SIGNING_SECRET` (secret), `ANSWER24_ALLOW_LEGITIMATE_INTEREST` (false), `ANSWER24_RECENT_CONTACT_DAYS` (30). B06: `DIRECT_REWARD_FEE_BPS` (3000). B05: `BUSINESS_TIMEZONE` (default Europe/Amsterdam), `BROADCAST_CONNECTION` (null by default). B04: `WEBHOOK_PAYLOAD_RETENTION_DAYS` (default 14). B03c: `GOOGLE_PLACES_API_KEY` (secret; empty = manual entry only). B12: `FUNDING_REQUIRED` (true), `FUNDING_PERCENTAGE_BASIS` (maximum_discount). B11a: `MOLLIE_API_KEY` (secret; test_ keys only), `MOLLIE_API_BASE_URL`, `MOLLIE_ALLOW_LIVE` (false). B10: `AFFILIATE_CREDIT_TIMING` (on_network_approval), `AFFILIATE_FALLBACK_NETWORK` (daisycon), `DAISYCON_API_BASE_URL`. B09: `WALLET_CREDIT_VALIDITY_MONTHS` (12). B08a: `DISCOVERY_CACHE_TTL_SECONDS` (60), `API_DOCS_ENABLED` (default on outside production). Earlier: `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `CORS_SUPPORTS_CREDENTIALS`, `API_RATE_LIMIT_PER_MINUTE`, `HEALTH_READINESS_TOKEN` (secret).
- Capturing docs: run the server with `QUEUE_CONNECTION=sync MAIL_MAILER=log`, read codes/tokens from `storage/logs/laravel.log`, then **truncate that log**, since it holds live codes and reset tokens.

## Conventions to reuse

- Routes: `routes/api.php` v1 group. Private routes go inside `auth:sanctum` + `account.active`, and inside `verified` unless the email-verification flow itself needs them. Declare `x-access` per operation in `docs/api/openapi.yaml`; `OpenApiCoverageTest` enforces docs, examples and access matching.
- Errors: `ApiException` (supports `$details` and `$headers`). Audit: `AuditLogger::record()` in transactions, `recordSafely()` otherwise. Append-only models: `use App\Models\Concerns\AppendOnly`.
- Public IDs: models exposed to clients get a ULID `public_id` (`HasUlids` + `uniqueIds()`); never expose integer keys.
- Session-flow tests: `use Tests\Concerns\BrowsesLikeSpa` and `$this->spa('POST', ...)`.
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

## B15a resume point (work-in-progress commit)

Committed:
- migration `2026_10_24_150000_create_cms_tables`; models `Cms*`, `Faq*`;
- `Services\Cms\{RichText, CmsService, CmsCache}`; `LegalConsents` now prefers the published CMS legal versions;
- `Admin\CmsController` (pages, drafts, preview, publish, revisions, restore, archive, translations, assets, FAQ) and `Content\PublicContentController` (`public/pages`, `public/pages/{locale}/{slug}`, `public/faq`, `public/content-assets/{id}`).

Pint and PHPStan pass.

Remaining:
1. Write `tests/Feature/Cms/CmsTest.php` covering card #5 E2E 2–8:
   - draft isolation; preview only for admins (no-store/noindex); publish + cache invalidation;
   - stale draft 409 + restore as a new revision; legal publish needs version/date/approval and drives registration's required version;
   - FAQ audience enforced; non-admin 403; XSS payloads 422 and fake image MIME 422.
2. Capture docs, tick the checklist, commit + push.
3. Then B15b (cookie consent + NL/EN API messages): expand the checklist first.

## Next agent's exact first actions

1. `git status -sb && git log --oneline -8 && php artisan test` (expect 403 + 7 skipped on SQLite; 410 on MySQL); `gh run list --limit 3`.
2. B15 CMS / legal / cookie consent (Trello #5, #4 legal docs, #18 CMS content): read card #5 and expand the checklist first.
   - NL/EN page and FAQ revisions with draft → publish → rollback (immutable published revisions).
   - Sanitized rich text (allowlist) and a public content API with cache + ETag.
   - Legal documents tied to the existing `LEGAL_*_VERSION` acceptance flow (B02): publishing a new Terms/Privacy version with re-acceptance.
   - Cookie categories + consent records (anonymous visitor consent ID; append-only; versioned banner text).
   - Super Admin-only editing with step-up; audited.
3. WIP checkpoints and push after each task; check CI.
