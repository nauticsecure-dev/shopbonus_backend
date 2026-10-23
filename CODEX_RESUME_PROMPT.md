# Codex — Resume Shopbonus Laravel Backend Development

You are taking over backend development from another agent (possibly Claude) because it reached its usage/context limit. Your job is to **continue and verify** already-started work, not restart, rewrite working code, or assume claimed task completion is accurate.

**Scope:** Laravel API/backend only. No frontend, React, CSS, visual UI or mobile changes.

1. Read `AGENTS.md`, `CLAUDE.md`, `docs/BACKEND_SPEC.md`, `docs/TASKS.md`, `docs/PROGRESS.md`, `docs/HANDOFF.md`, `docs/DECISIONS.md`, and relevant `requirements/TRELLO_CARDS.md` sections.
2. Inspect `git status`, current branch, latest commits, open PRs where accessible, uncommitted changes, affected tests, current schema and app version. Determine what was actually committed and passes locally; never overwrite unfinished human/Claude edits.
3. Cross-check prior `HANDOFF.md` against code and tests. Report any mismatch and correct documentation.
4. Locate the next `READY` task whose dependencies pass and whose files do not conflict with another active contributor. For an `IN_PROGRESS` task, resume it only after verifying partial work. Preserve backend conventions and interfaces used by frontend developers.
5. Identify any open money/consent/affiliate timing decisions in `docs/DECISIONS.md`. Do not authorize a spendable-credit behavior or live payments before business/legal decisions and provider verification. Work on independent, safe modules instead.
6. Implement the smallest correct backend slice with tests. Run Pint/feature/unit tests/static analysis as supported. Use provider sandboxes only with authorized credentials and record evidence. Never claim a test passed if it wasn't run.
7. Update docs with verification evidence, exact tests and result, branch/commit, touched files, risks/blockers and next task; commit at a safe boundary on the authorized branch. Coordinate via Git, not conversational memory.
8. Before hitting your own context limit, write a fresh `docs/HANDOFF.md` so Claude or another Codex session can pick up seamlessly.

Critical invariants: tenant isolation, global consumer wallet versus per-workspace merchant funding, append-only financial ledger, no double order/reward/voucher allocations, verified paid-order webhooks, Answer24 only under lawful verified eligibility, server-side Mollie verification, and explicit resolution of contradictory affiliate reward policies. Do not silently alter these.

**Now audit the current state, state the very next safe task, and continue implementation.**
