# Latest Agent Handoff

**Session:** 2026-10-23, first implementation session
**Agent:** Claude (Opus 5.5)
**Repo / branch:** this local repo (no remote) / `main` (direct commits authorized, D-002)
**Last code commit:** `a51a9b6` B01a API foundation. The docs-only commit after it records this handoff; check `git log --oneline`.
**Working tree state at handoff:** clean after the docs commit (verify with `git status -sb`).
**Active task:** none in progress. **Next:** B01b (READY).
**Last completed task:** B01a (DONE, tested).

## What happened this session

1. B00 audit: no Shopbonus code existed anywhere (local or GitHub), so every module was MISSING. Owner chose greenfield, direct-to-main, MySQL (D-001..D-003).
2. `git init`; requirements pack committed unchanged (`7cc47a8`).
3. Laravel 13.38 skeleton + Sanctum (`install:api`) (`48520b7`). Skeleton's generic AGENTS.md/CLAUDE.md and frontend tooling were dropped (D-007).
4. B01a foundation (`a51a9b6`). See `docs/PROGRESS.md` → "B01a detail".
5. Owner rule added: every endpoint documented with an example request and a **real captured** response (AGENTS.md rule 11, D-006, enforced by `OpenApiCoverageTest`).

## Tests actually executed (at `a51a9b6`)

| Command | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory, phpunit.xml default) | PASS: 28 tests, 196 assertions |
| `DB_CONNECTION=mysql DB_DATABASE=shopbonus_testing DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test` | PASS: 28 tests |
| `vendor/bin/pint --test` | PASS |
| `vendor/bin/phpstan analyse --memory-limit=1G` (Larastan level 6) | PASS, 0 errors |
| `composer audit` | No advisories |
| GitHub Actions CI | NOT RUN: no remote configured |

## Environment / migrations / config

- Local: PHP 8.4.17, Composer 2.8.9, MySQL (`root`, no password) with databases `shopbonus` and `shopbonus_testing`, Redis on 127.0.0.1:6379. `phpredis` is not installed, so `REDIS_CLIENT=predis` (D-004).
- Migrations applied to local `shopbonus`: Laravel defaults (users, cache, jobs) + `2026_10_23_122113_create_personal_access_tokens_table`. No Shopbonus domain migrations yet.
- Env key names introduced (values not secret unless noted): `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `CORS_SUPPORTS_CREDENTIALS`, `API_RATE_LIMIT_PER_MINUTE`, `HEALTH_READINESS_TOKEN` (secret).
- No provider credentials exist or were used.

## Conventions a new agent must reuse (don't reinvent)

- Domain errors: `throw new App\Exceptions\ApiException($msg, $status, $code, $details)` or `ApiException::conflict(...)`. Never hand-build error JSON.
- Request ID: `App\Http\Middleware\AssignRequestId::resolve($request)`, or `Context::get('request_id')` (also present in queued jobs via Context).
- Routes go inside the `Route::prefix('v1')` group in `routes/api.php`. Then add the path to `docs/api/openapi.yaml` and `docs/api/README.md` with real captures (procedure at the top of `docs/api/README.md`).
- Log context keys holding secrets/PII are auto-masked; still avoid logging PII deliberately.
- `Model::shouldBeStrict()` is on outside production, so eager-load relations.

## Blockers / open decisions

- See `docs/DECISIONS.md`: OPEN-AFFILIATE-CREDIT-TIMING, OPEN-REWARD-UNITS, OPEN-FINANCIAL-LEGAL, OPEN-MARKETING-CONSENT, OPEN-PROVIDER-CAPABILITIES, OPEN-EMPTY-CARD-27. None block B01b–B03.
- No git remote, so CI is unverified. Owner to provide a remote when ready.
- Sanctum mode (SPA cookie vs bearer tokens vs both) to be decided in B02. Card #2 §9 implies a cookie SPA. Mobile apps would need tokens.

## Next agent's exact first actions

1. `git status -sb && git log --oneline -5`, then `php artisan test` to confirm the 28 pass.
2. Start **B01b** (see the TASKS.md row): migration `audit_events` (append-only, with `request_id`, `actor_user_id`, `real_actor_user_id` for impersonation, nullable `webshop_id` without an FK until B03 creates `webshops`, `action`, `resource_type/id`, `outcome`, `http_status`, `ip_hash`, JSON `metadata` passed through `SensitiveDataProcessor::redactArray`, `created_at` only). Add `App\Services\Audit\AuditLogger`; a terminating middleware or exception listener that records 401/403; and a `Queue::failing` listener writing to `application_errors`. Test that audit rows can't be updated/deleted through the model, and that 401/403 are audited with the request ID.
3. Then **B02** identity (Trello #4).
