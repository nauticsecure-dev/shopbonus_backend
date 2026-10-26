# Latest Agent Handoff

**Session:** 2026-10-25, Codex, owner requests #19 main backend code; senior developer configures credentials.
**Branch/base:** `main` / `a9edfb3` (clean start); B10b implementation `50957b4` pushed; GitHub CI `40202694616` passed all three jobs. Final documentation-only evidence checkpoint follows. No pre-existing work overwritten.
**Status:** B10b TradeTracker main-code slice DONE locally; whole #19 PARTIAL pending genuine provider/staging acceptance. No migrations/new routes/frontend.
**Tests:** sequential SQLite **483 passed + 11 skipped**, 6939 assertions; MySQL **492 passed + 2 skipped**, 6978 assertions (494 total each). Focused affiliate 25 pass/193 assertions; strengthened precision test passed separately. Pint/PHPStan L6/composer audit/composer validation/diff check pass. Concurrent database suite attempts interfered with shared Storage::fake folders; sequential final runs above passed. Run these suites sequentially locally.
**Implementation:** pinned official HTTPS WSDL; native SOAP through Laravel HTTP; per-client cookie isolation; fixed host/no redirects/no retries; 5s connect/20s request; max100 pages of100 per query; registration + assessment polling; safe errors; strict provider data/negative commission checks; cashback enum fix; legacy minimal callbacks/imports retained; atomic snapshot/ingestion and conditional failure update preserve concurrent disables. No new reward policy/activation.
**Configuration:** `TRADETRACKER_API_ENABLED=false`, PHP ext-soap required in composer/CI; encrypted customer_id/passphrase/site_id through existing admin API. Senior developer supplies these, not owner chat. EUR program-mapping limitation and all deployment/verification steps: `docs/integrations/TRADETRACKER.md`. D-070. Genuine network verification NOT RUN.
**Capture cleanup:** six real local HTTP captures used isolated SQLite/database sessions/queue, array cache/mail, stderr logs and synthetic SOAP upstream. Local port8078 server stopped; task-owned temporary credentials/DB/helpers removed. Capture-only MFA bypass. No shared Redis operations.
**Disk:** last 6.1 GiB free; check first and stop below ~5 GiB.
**Next exact action:** final documentation checkpoint push/CI/status check, then hand off configuration to the senior developer. No B10b main-code implementation remains. Senior developer configures authorized account access and records genuine provider/attribution evidence; no owner credentials requested. Existing financial/legal/affiliate-unit decisions unchanged.

---

## Previous #21 implementation context (completed code checkpoint; old closing steps superseded)

**Session:** 2026-10-25, Codex, owner requested work on Trello #21. Laravel backend only.
**Branch / code checkpoint:** `main` / `dea03b5` (pushed; implementation CI `40199851559` passed). Started clean at `e523098`; previous B16c/B16d code `4786e65` / `7b2c8d1` also CI-green. Final checkpoint tightens operational quota to strict JSON integer and refreshes domain-conflict API captures; full rerun passed (details below); final commit/push/CI is the closing action.
**Working tree:** only final strict-quota validation/test, refreshed real API captures and continuity docs after `dea03b5`; final commit contains these. No pre-existing uncommitted work overwritten. No frontend files changed.
**Task:** #21 AI-assisted incident diagnostics + reviewed private operational quota, implementation tested locally; WIP pushed/CI passed; final local validation passed; final documentation/code checkpoint is being committed. Whole card remains PARTIAL pending live API configuration, UI and staging evidence.
**Owner answers:** "chatgpt" for provider (implemented OpenAI Responses); "there isn't" for staging. No API key/model configured. Never infer ChatGPT session access supplies API credentials. Keep disabled until local/deployment API project/key, supported model and budget are configured. No live provider request was made.
**Disk:** started 6.2 GiB free; after full MySQL QA about 5.2 GiB, fluctuates. Run `df -h /System/Volumes/Data` first; below ~5 GiB STOP and ask owner. No binlogs purged, no other project/Redis data deleted.

## B16e implementation / contracts

- Migration `2026_10_25_150000_create_incident_diagnostics`: runs, immutable results, append-only reviews and global UTC-day request quota. Applied/rolled back/reapplied on local MySQL successfully.
- `Services/Admin/Diagnostics/{DiagnosticContext,OpenAiDiagnostics,IncidentDiagnostics,ProviderFailure}`, `Jobs/AnalyzeIncident`, three models and `Admin/IncidentDiagnosticController`; uses existing AuditLogger, incident/public IDs, admin/MFA/password/email-OTP gates, queue and platform-setting workflow.
- New routes: GET/POST `/api/v1/admin/incidents/{incident}/diagnostics`, GET `/api/v1/admin/incident-diagnostics/{diagnostic}`, POST `/api/v1/admin/incident-diagnostics/{diagnostic}/review`. Mutations require password + OTP; request throttle 3/min/admin; private read/list are admin-only. GuardSupportSession already denies all admin routes.
- Strict context allowlist excludes user/customer/workspace/request IDs, arbitrary errors/logs/payloads and private URLs. Only status, known service, registered route/method and count are forwarded, indexed E1–E5. Context hash verified before egress. Strict response/evidence schema; raw tool/refusal/error/incomplete output rejected. No tools, execution, refunds, ledgers, permissions, deployment or auto-close capabilities. Accepted/dismissed reviews are assessment only; results remain unverified advisory hypotheses.
- Single queued provider attempt (no HTTP/queue retry); duplicate execution guarded; current requester/incident eligibility rechecked. Request key scoped per admin, changed incident 409. Daily quota reservation serialized globally; failed runs count; repeats don't. Safe failure codes only, never raw provider errors.
- `operations.diagnostics_daily_request_limit` reuses independent draft/review/publish/rollback. Integer 0–1000; 0 pauses. Effective value ≤ environment hard cap. Operational values/versions excluded from anonymous public settings. No generic provider/model/credential/enable settings and no existing legal/financial/marketing policy activated.
- Config names only: `ADMIN_DIAGNOSTICS_ENABLED=false`, `OPENAI_API_KEY`, `ADMIN_DIAGNOSTICS_MODEL` (empty), `ADMIN_DIAGNOSTICS_DAILY_REQUEST_LIMIT=10`, `ADMIN_DIAGNOSTICS_MAX_OUTPUT_TOKENS=1500` (256–4000 clamp). Fixed official Responses host; 20s HTTP timeout; job 30s, tries=1; persistent workers need same config and retry-after > timeout.
- D-068/D-069; full frontend/config handoff: `docs/admin/INCIDENT_DIAGNOSTICS.md`. B17 support chat remains unbuilt, distinct from #21 diagnostics.

## Executed verification

- `php artisan test`: **469 passed + 11 skipped**, 6841 assertions (MySQL process races skipped).
- `DB_CONNECTION=mysql DB_DATABASE=shopbonus_testing DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test`: **478 passed + 2 skipped**, 6880 assertions, 138s. Two skips are existing destructive trigger-bypass tests run on transactional SQLite; MySQL trigger protections tested without dropping them.
- Pint --test, PHPStan L6 --memory-limit=1G and composer audit: PASS/no advisories.
- Migration `150000` apply/rollback --step=1/reapply: PASS local MySQL.
- Focused diagnostics/settings/contract suite 19 passed; 10 diagnostics feature cases plus one new real MySQL quota race. Privacy, unknown evidence/tool/incomplete/refusal/malformed output, disabled provider, exhausted quota, stale context, duplicate calls/reviews, request-key conflict, changed requester, context-integrity checks and public/private setting separation tested.
- Eight guard mutation checks detected failures and were restored (configuration, quota, execution guard, context hash, review status, eligibility, evidence refs, environment cap).
- Real local-server HTTP captures: isolated SQLite/database sessions/queue/cache, logged mail, MFA bypass only in capture environment. Requests/login/password/OTP/poll/review/settings were real HTTP. Completion generated through the real validator/service using Http::fake; explicitly NOT live OpenAI evidence. OpenAPI/README updated for all new/changed endpoints.
- Capture server stopped; task-owned temporary fixture DB, generated passwords, capture/mutation helpers removed. Logged email-code suffix removed while preserving pre-existing application log content. No capture secrets/fixtures committed.
- Live OpenAI/key/model/account capability: NOT VERIFIED. Staging p95, production alerts and backup/restore: NOT RUN (owner confirms no staging). No frontend implementation.

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

## Next exact actions

1. Check disk cutoff and git status/log. B16e WIP `dea03b5` is pushed/CI-green; strict JSON-integer quota validation and final full-suite rerun passed; final checkpoint must be pushed/CI checked (inspect latest git log/CI if resuming during that closing action). Tests above passed; don't tick the live-provider/staging checklist item.
2. Temporary capture credentials/server/helpers already cleaned; update final clean/uncommitted status. Handoff API/config docs to Hassan; #21 remains PARTIAL until real API setup/verification and UI/staging acceptance.
3. For live verification, owner must configure an OpenAI API key, supported Responses/Structured Outputs model and budget in the environment; do not ask for secrets in chat or invent a default production model. There is no staging; do not claim performance/backup/provider checks passed. B17 support chat/new cards are separate scope. Existing financial/legal/marketing/affiliate OPEN decisions remain unchanged.
