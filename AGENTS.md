# Shared Agent Instructions — Shopbonus Backend

These instructions apply to **every coding agent**, including Codex and Claude. Scope is **Laravel backend/API only**. No React/mobile/CSS/editor client implementation. Treat frontend-specific Trello cards as API requirements, not work assignments.

1. Codebase and git commits establish current implementation; Trello snapshot establishes intended requirements. Don't infer status from Trello list names, previous agent claims or checkboxes.
2. Read `docs/BACKEND_SPEC.md`, `docs/TASKS.md`, `docs/DECISIONS.md`, `docs/PROGRESS.md`, `docs/HANDOFF.md`; find relevant full Trello card text in `requirements/TRELLO_CARDS.md`.
3. Audit/reuse code before creating routes/tables/packages. Respect current repository architecture and user-authorized branch workflow. Never force push, destructively reset, expose credentials or overwrite uncommitted work.
4. Validate/authenticate/authorize every mutation server-side; strictly enforce per-workspace membership, user ownership, Super Admin permissions and integration scopes. Treat all webhooks as untrusted pending signature/auth proof. Use minor units/points; append-only wallet accounting; idempotent external effects.
5. Separate direct merchant-funded vouchers, affiliate wallet-funded voucher checkout and Answer24 free-code claims. Follow finite status transitions; preserve exact immutable snapshots and source references. Never claim payment success on redirect or code issuance as confirmed redemption.
6. The Trello cards conflict about whether affiliate rewards are immediately spendable. Log/resolve with owner before activation of the affected flow; do not silently pick one.
7. One ticket at a time at small, tested boundaries. A task is `DONE` only when its acceptance evidence and executed tests are recorded; otherwise `PARTIAL`, `BLOCKED`, etc. Record `NOT RUN` rather than guessed results.
8. Write changes to `docs/TASKS.md`, `docs/PROGRESS.md`, `docs/HANDOFF.md` on every coherent checkpoint. Handoff must include git branch and SHA, tests, exact next action, decisions, migrations, blockers and uncommitted status.
9. Avoid concurrent edits on same files/branch; coordinate ownership. Branch/PR if appropriate unless owner specifically authorizes direct commits. Never invent credentials or production provider behavior.
10. Check entire app contract for compatibility and maintain OpenAPI/route docs on changes. Do not skip 401/403/409/419/422/429/500 paths or security negative tests.
11. **API documentation is mandatory (owner rule, 2026-10-23).** Every `/api/v1` endpoint must be documented in `docs/api/openapi.yaml` and `docs/api/README.md` with an example request and an example response **captured from a real running server**, never hand-written. Follow the procedure in `docs/api/README.md`. `tests/Feature/Api/OpenApiCoverageTest.php` fails if a route is undocumented or lacks examples.
