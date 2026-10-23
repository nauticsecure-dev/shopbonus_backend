# Shopbonus — Backend Agent Handoff Pack

**Scope:** Laravel backend only. Frontend, mobile apps, CSS, React pages, designs and web-store rendering are **not** part of the implementation assignment. The backend must expose contracts that support those clients.

**Source:** Trello export `MS0DLCAX - shopbonus.json` uploaded on 2026-10-23; full card descriptions copied into `requirements/TRELLO_CARDS.md` (with board names and card URLs). The export is a snapshot: current Trello changes are NOT automatically synchronized.

**Status (2026-10-23):** No prior code existed, so this repo is a greenfield Laravel 13 API (see `docs/DECISIONS.md` D-001). Current state lives in `docs/PROGRESS.md` and `docs/HANDOFF.md`. API reference: `docs/api/README.md` and `docs/api/openapi.yaml`.

## Local setup

Requires PHP 8.3+, Composer, MySQL and Redis.

```bash
composer install
cp .env.example .env && php artisan key:generate
mysql -uroot -e "CREATE DATABASE shopbonus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate
php artisan serve                     # http://localhost:8000/api/v1/health
php artisan test                      # SQLite in-memory
vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G
```

## How to use with Claude

1. Copy **all files in this pack** into the existing backend repository root, preserving paths. Review any existing files with the same names first; merge instead of overwriting established project rules.
2. Paste the entire `MASTER_PROMPT_FOR_CLAUDE.md` into Claude (or direct Claude to read it together with these repository files).
3. Ask Claude to audit the current backend before coding. It must document what exists and only implement missing or defective work, in dependency order.
4. Have Claude commit checkpoint changes to the branch you authorize and update `docs/TASKS.md`, `docs/PROGRESS.md`, and `docs/HANDOFF.md` before ending each session.

## How to use with Codex

Open the same repository and read `CODEX_RESUME_PROMPT.md`. Codex must inspect repository code and git history rather than trusting an agent's self-reported completion. Continue only after dependencies and work ownership are clear.

## Suggested reading order

1. `AGENTS.md` / `CLAUDE.md`
2. `docs/BACKEND_SPEC.md`
3. `requirements/TRELLO_CARDS.md` for detailed Trello requirements
4. `docs/TASKS.md`
5. `docs/DECISIONS.md`, `docs/PROGRESS.md`, `docs/HANDOFF.md`

## Collaboration guardrails

- One active writer per branch or per non-overlapping changeset; do not allow two agents to overwrite each other's uncommitted work.
- Git is the code source of truth. Task documentation reports the status of committed, tested work, not a substitute for it.
- Use safe feature branches and pull requests unless the repo owner explicitly chooses a different branch strategy.
- Never print secrets in prompts, commits, Trello comments or handoff logs.
- Never claim that tests passed unless they were executed and results recorded.
