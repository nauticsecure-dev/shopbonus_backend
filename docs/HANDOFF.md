# Latest Agent Handoff

**Session:** 2026-10-23, first implementation session (continued)
**Agent:** Claude (Opus 5.5)
**Repo / branch:** this local repo (no remote) / `main` (direct commits authorized, D-002)
**Last code commit:** the B01b commit (`git log --oneline --grep=B01b`). Earlier: `a51a9b6` B01a, `48520b7` scaffold, `7cc47a8` pack.
**Working tree state at handoff:** clean (verify with `git status -sb`).
**Active task:** none in progress. **Next:** B02, after the owner answers the Sanctum-mode question below.
**Last completed task:** B01b (DONE, tested), which completes B01.

## Session summary

1. B00 audit: no Shopbonus code existed anywhere, so greenfield, direct-to-main, MySQL (D-001..D-003).
2. Scaffold (`48520b7`) and B01a API foundation (`a51a9b6`).
3. Owner rules added to AGENTS.md: rule 11 (every endpoint documented with a real captured request/response) and rule 12 (**`docs/CHECKLIST.md`**: expand a task's items before starting, tick each in the commit that implements it, only after tests pass).
4. B01b audit and error store. Details in `docs/PROGRESS.md` → "B01b detail".

## Tests actually executed (at the B01b commit)

| Command | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory) | PASS: 47 tests, 271 assertions |
| `DB_CONNECTION=mysql DB_DATABASE=shopbonus_testing DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test` | PASS: 47 tests |
| `vendor/bin/pint --test` | PASS |
| `vendor/bin/phpstan analyse --memory-limit=1G` (level 6) | PASS, 0 errors |
| `php artisan migrate:rollback --step=2 && php artisan migrate` (local MySQL) | OK; trigger `audit_events_block_update` dropped and recreated |
| PostgreSQL trigger variant | NOT RUN: no PostgreSQL locally |
| GitHub Actions CI | NOT RUN: no remote |

## Environment / migrations / config

- Local: PHP 8.4.17, MySQL (`root`, no password) with DBs `shopbonus` and `shopbonus_testing`, Redis 127.0.0.1:6379 via predis (D-004).
- Applied to local `shopbonus`: Laravel defaults, `personal_access_tokens`, `2026_10_23_130000_create_audit_events_table`, `2026_10_23_130100_create_application_errors_table`.
- Env key names: `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `CORS_SUPPORTS_CREDENTIALS`, `API_RATE_LIMIT_PER_MINUTE`, `HEALTH_READINESS_TOKEN` (secret). `APP_KEY` also keys the IP hashes (D-008).
- **Deployment caveat (D-010):** on MySQL with binary logging, the migration user needs `SUPER` or `log_bin_trust_function_creators=1` to create the trigger.

## Conventions to reuse (don't reinvent)

- Domain errors: `App\Exceptions\ApiException` / `ApiException::conflict(...)`.
- Audit: `app(AuditLogger::class)->record(action: 'domain.verb', category: AuditCategory::X, resource: $model, metadata: [...])`. Inside money/voucher transactions use `record()` so a failed audit aborts the transaction; use `recordSafely()` for observational events. Action names are `domain.past_tense_verb` (e.g. `workspace.member_added`).
- Request-scoped audit facts come from Context: B03 workspace middleware must `Context::add(AuditLogger::CONTEXT_WEBSHOP_ID, $webshop->id)`; B16 support sessions must set `AuditLogger::CONTEXT_REAL_ACTOR_ID`.
- Error alerting: listen to `App\Events\ApplicationErrorRecorded`.
- New routes go in the `v1` group in `routes/api.php`, plus `docs/api/openapi.yaml` and `docs/api/README.md` with real captures.

## Blockers / open decisions

- **Needed from owner for B02:** Sanctum mode. Options: SPA cookie sessions only (card #2 §9 implies this), bearer tokens only (needed for mobile apps and third parties), or both.
- `docs/DECISIONS.md` open items (affiliate credit timing, reward units, financial/legal, marketing consent, provider capabilities, card #27) do not block B02/B03.
- No git remote, so CI is unverified.

## Next agent's exact first actions

1. `git status -sb && git log --oneline -6 && php artisan test` (expect 47 passing).
2. Read Trello #4 (`requirements/TRELLO_CARDS.md` around line 840), plus the identity parts of #5 and #10.
3. Expand the B02 section of `docs/CHECKLIST.md` into concrete items before writing code.
4. Implement B02: signup, login, logout, email OTP verification/resend, password recovery, `GET /api/v1/me`, login throttling, and audit events (`identity.*`). Capture real responses for the docs.
