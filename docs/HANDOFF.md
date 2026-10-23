# Latest Agent Handoff

**Session:** 2026-10-23, first implementation session (continued; owner said "continue and don't stop")
**Agent:** Claude (Opus 5.5)
**Repo / branch:** this local repo (no remote) / `main` (direct commits authorized, D-002)
**Last code commit:** B06b (`git log --oneline -1 --grep=B06b`). Earlier: `c6485dc` B06a, `7e391be` B05, `820c6b5` B04, `5acdfbd` B03d, `b230455` B03c, `ec20038` B03b, `2fe8db6` B03a, `c95e1e4` B02, `088fb20` B01b, `a51a9b6` B01a.
**Working tree state at handoff:** clean (verify with `git status -sb`).
**Active task:** none in progress. **Next:** B07 Answer24 eligibility, grouping, signed handoff and 90-day claim (Trello #1 Answer24 sections, #3 B.7–C.10, #23).
**Last completed task:** B06 offers and code inventory (DONE; platform coupon verification BLOCKED).

## Tests actually executed (at the B06b commit)

| Command | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory) | PASS: 298 + 3 skipped (MySQL-only concurrency) |
| `DB_CONNECTION=mysql DB_DATABASE=shopbonus_testing DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test` | PASS: 301 tests (incl. forked-process race tests) |
| `vendor/bin/pint --test` | PASS |
| `vendor/bin/phpstan analyse --memory-limit=1G` (level 6) | PASS, 0 errors |
| `composer audit` | No advisories |
| `php artisan migrate:rollback --step=4 && php artisan migrate` (local MySQL, B02) | OK |
| Real HTTP capture of the whole auth flow (MySQL + Redis sessions) | Done; in `docs/api/README.md` → Auth |
| Staging p95, GitHub CI, PostgreSQL trigger | NOT RUN (no staging, no remote, no PG) |

## Environment / migrations / config

- Local: PHP 8.4.17, MySQL (`root`, no password) with DBs `shopbonus` and `shopbonus_testing`, Redis 127.0.0.1:6379 via predis (D-004).
- **Local Redis is shared with other projects.** Only delete keys matching `shopbonus-*` (databases 0 and 1). Never `FLUSHDB`/`FLUSHALL`; see the incident note below.
- Migrations applied locally: Laravel defaults, `personal_access_tokens`, `2026_10_23_130000/130100` (audit, errors), `2026_10_23_140000..140300` (identity), `2026_10_23_150000` (webshops, memberships, platform roles), `2026_10_23_160000` (invitations), `2026_10_23_170000` (business profiles, verifications, ownership columns), `2026_10_23_180000` (agreements), `2026_10_23_190000` (connections, webhook events), `2026_10_23_200000` (orders, order events), `2026_10_23_210000` (offers, offer versions), `2026_10_23_220000` (voucher codes, batches, vouchers, events).
- Env key names added in B02: `LEGAL_TERMS_VERSION`, `LEGAL_PRIVACY_VERSION`, `LEGAL_MARKETING_CONSENT_VERSION` (empty = registration disabled), `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE` (true in prod), `SESSION_SAME_SITE`, `VERIFICATION_CODE_TTL_MINUTES`, `VERIFICATION_CODE_MAX_ATTEMPTS`, `VERIFICATION_RESEND_COOLDOWN_SECONDS`. B06: `DIRECT_REWARD_FEE_BPS` (3000). B05: `BUSINESS_TIMEZONE` (default Europe/Amsterdam), `BROADCAST_CONNECTION` (null by default). B04: `WEBHOOK_PAYLOAD_RETENTION_DAYS` (default 14). B03c: `GOOGLE_PLACES_API_KEY` (secret; empty = manual entry only). Earlier: `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `CORS_SUPPORTS_CREDENTIALS`, `API_RATE_LIMIT_PER_MINUTE`, `HEALTH_READINESS_TOKEN` (secret).
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
- No git remote (CI unverified); no staging (p95 unverified).

## Workspace conventions (B03a)

- Workspace-scoped routes: `Route::prefix('workspaces/{workspace}')->middleware('workspace')`, plus `->middleware('workspace.can:<permission>')` per route. Read the webshop from `CurrentWorkspace`, never from input. Declare `x-permission` in OpenAPI.
- Non-member access is a generic 404 (and audited by the resolver); members lacking a permission get 403.
- Commercial routes (offers, vouchers, integrations, payments) must add `workspace.commercial` after `workspace`.
- Tests needing a commercially active webshop: `use Tests\Concerns\CreatesActiveWebshops` → `$this->activeWebshop($owner)`.
- Fees: always `FeeCalculator` (never inline percentages).
- Vouchers: only through `VoucherInventory` (reserve with an idempotency key such as `order:<id>:offer:<id>` or `answer24-handoff:<ref>`). Never update voucher/code status directly.
- Bulk inserts that bypass Eloquent must generate `public_id` with `strtolower((string) Str::ulid())`.
- Tests that commit real rows (concurrency) must use DatabaseTruncation **and** truncate in tearDown (see `ConcurrentReservationTest`).
- Admin routes: inside the `admin` prefix group (`platform:super_admin`); mutations inside `password.recent`. Test them with a stateful session (`Referer`/`Origin` headers + `withSession(['auth.password_confirmed_at' => time()])`).
- **Never write a denial/security audit inside a DB transaction that then throws**: it rolls back with it. Audit before the transaction, or after commit.
- Never use database `NOW()` in queries or scripts: local MySQL runs in the server's timezone while the app writes UTC. Use PHP `now()`.
- The agent shell is zsh: never name a shell variable `path` (it is tied to `PATH`). Capture scripts are bash files using `caplib.sh` (see the scratchpad pattern described in PROGRESS); keep that pattern.

## Next agent's exact first actions

1. `git status -sb && git log --oneline -8 && php artisan test` (expect 298 + 3 skipped on SQLite; 301 on MySQL).
2. Read Trello #1 "Answer24: required processing", #3 B.7–C.10, #23 "Answer24 responsibilities" and #10 (contact consent). Re-read OPEN-MARKETING-CONSENT: **no outbound contact may be enabled without a documented lawful basis**.
3. Expand B07 in `docs/CHECKLIST.md`:
   - an eligibility engine with recorded blocked reasons (paid, not cancelled/refunded, participating active webshop + active merchant_reward offer, phone, lawful basis evidence, suppression, no duplicate contact);
   - `answer24_contact_requests` with a rolling 1-hour grouping per consumer + webshop, cancellation and suppression on refund, and idempotency keys;
   - an outbound dispatch client behind a config flag, **disabled by default** (no Answer24 credentials; signed HMAC requests);
   - a signed callback endpoint (accepted, rejected, attempted, completed, failed, cancelled; replay-safe);
   - a signed handoff → `VoucherInventory::reserve(origin answer24_claim)` → short single-use claim link (90 days);
   - consumer claim (verify identity → `issue`);
   - a reminder-event contract (days 30/60/80) that Answer24 sends (Shopbonus sends nothing itself).
