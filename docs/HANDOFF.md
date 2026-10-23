# Latest Agent Handoff

**Session:** 2026-10-23, first implementation session (continued; owner said "continue and don't stop")
**Agent:** Claude (Opus 5.5)
**Repo / branch:** this local repo (no remote) / `main` (direct commits authorized, D-002)
**Last code commit:** B03a workspace core (`git log --oneline -1 --grep=B03a`). Earlier: B02 `c95e1e4` (+ style `49f059d`), `088fb20` B01b, `a51a9b6` B01a.
**Working tree state at handoff:** clean (verify with `git status -sb`).
**Active task:** none in progress. **Next:** B03b team management (invitations, roles, removal, final-owner protection).
**Last completed task:** B03a workspace core (DONE, tested).

## Tests actually executed (at the B03a commit)

| Command | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory) | PASS: 125 tests |
| `DB_CONNECTION=mysql DB_DATABASE=shopbonus_testing DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test` | PASS: 125 tests |
| `vendor/bin/pint --test` | PASS |
| `vendor/bin/phpstan analyse --memory-limit=1G` (level 6) | PASS, 0 errors |
| `composer audit` | No advisories |
| `php artisan migrate:rollback --step=4 && php artisan migrate` (local MySQL, B02) | OK |
| Real HTTP capture of the whole auth flow (MySQL + Redis sessions) | Done; in `docs/api/README.md` → Auth |
| Staging p95, GitHub CI, PostgreSQL trigger | NOT RUN (no staging, no remote, no PG) |

## Environment / migrations / config

- Local: PHP 8.4.17, MySQL (`root`, no password) with DBs `shopbonus` and `shopbonus_testing`, Redis 127.0.0.1:6379 via predis (D-004).
- **Local Redis is shared with other projects.** Only delete keys matching `shopbonus-*` (databases 0 and 1). Never `FLUSHDB`/`FLUSHALL`; see the incident note below.
- Migrations applied locally: Laravel defaults, `personal_access_tokens`, `2026_10_23_130000/130100` (audit, errors), `2026_10_23_140000..140300` (identity), `2026_10_23_150000` (webshops, memberships, platform roles).
- Env key names added in B02: `LEGAL_TERMS_VERSION`, `LEGAL_PRIVACY_VERSION`, `LEGAL_MARKETING_CONSENT_VERSION` (empty = registration disabled), `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE` (true in prod), `SESSION_SAME_SITE`, `VERIFICATION_CODE_TTL_MINUTES`, `VERIFICATION_CODE_MAX_ATTEMPTS`, `VERIFICATION_RESEND_COOLDOWN_SECONDS`. Earlier: `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `CORS_SUPPORTS_CREDENTIALS`, `API_RATE_LIMIT_PER_MINUTE`, `HEALTH_READINESS_TOKEN` (secret).
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

## Next agent's exact first actions

1. `git status -sb && git log --oneline -8 && php artisan test` (expect 125 passing).
2. Re-read Trello #16 "Team management" and "Workspaces & memberships".
3. Expand B03b in `docs/CHECKLIST.md`: `webshop_invitations` (hashed token, expiry, bound to webshop and recipient email, single use), invite/resend/cancel/accept, member list, role/permission changes (no granting above own authority; managers cannot invite owners), suspend/remove (immediate loss of access; final active owner cannot be removed), audit for each.
