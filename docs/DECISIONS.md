# Decisions and Open Business Questions

All items below are OPEN unless supported by existing owner-approved implementation or written owner confirmation. Record a date, source and decision before closing.

| ID | State | Question / Evidence needed | Conservative interim handling |
| --- | --- | --- | --- |
| OPEN-AFFILIATE-CREDIT-TIMING | OPEN | Trello #19/#22: pending, not spendable until approved. #23: instantly approved/spendable on valid attribution despite future network rejection. Which has priority and what fraud/abuse policy applies? | Do not change live spendable balances on ambiguous events; implement status data model and independent tests first. |
| OPEN-REWARD-UNITS | OPEN | Are EUR redemption credits and integer points the same unit? Conversion rate and per-program rules? | Store explicit unit and integer quantity; no implicit conversion. |
| OPEN-FINANCIAL-LEGAL | OPEN | Seller of record, VAT, invoice obligations, refund/chargeback ownership, merchant settlement arrangements. | Sandbox-only payments / no production enablement until owner/legal decision. |
| OPEN-MARKETING-CONSENT | OPEN | Document lawful basis and scope for Answer24 phone outreach and voucher-reminder communications in NL/EU. | No unsolicited outbound contact; eligibility engine records blocked reason. |
| OPEN-PROVIDER-CAPABILITIES | OPEN | Which adapters have authorized API credentials and actually verified signature, paid state, coupons and redemption? | Report real per-capability limits; no false connected status. |
| OPEN-EXISTING-CODEBASE | **CLOSED 2026-10-23** | Existing repo URL, deployment topology, migrations and stable API consumers? | No Shopbonus code existed locally or in the owner's GitHub account/org. Owner chose a **greenfield** Laravel app in this repository (see D-001). Deployment topology still unknown. |
| OPEN-EMPTY-CARD-27 | OPEN | Trello card "App store for platforms" is empty. Which platform store and scope? | Do not implement from title alone. |

## Closed decisions

| ID | Date | Source | Decision | Impact |
| --- | --- | --- | --- | --- |
| D-001 | 2026-10-23 | Owner (Claude session prompt) | Greenfield Laravel 13 app in this repo; `git init` here. | Fresh skeleton; no legacy contracts to preserve. |
| D-002 | 2026-10-23 | Owner | Commit directly to `main` (no feature branches). No pushing without instruction; no remote configured yet. | Agents commit coherent, tested checkpoints straight to `main`. |
| D-003 | 2026-10-23 | Owner | MySQL is the target database. Tests run on SQLite in-memory by default; CI also runs the suite on MySQL 8.4. | Migrations must stay portable across SQLite and MySQL. |
| D-004 | 2026-10-23 | Agent (reversible) | Redis client `predis/predis`, because `phpredis` is not installed locally. Production may switch to `REDIS_CLIENT=phpredis` once the extension is installed. | One extra composer package. |
| D-005 | 2026-10-23 | Agent (reversible) | Error envelope `{"error":{status,code,message,request_id[,fields][,details][,debug]}}`, with fixed messages per framework status. Documented in `docs/api/README.md`. | Every client consumes this one shape. Changing it is a breaking change. |
| D-006 | 2026-10-23 | Owner | Every endpoint documented with an example request and a real captured response (see AGENTS.md rule 11). | Enforced by `OpenApiCoverageTest`. |
| D-007 | 2026-10-23 | Agent (reversible) | Frontend asset tooling (Vite, npm, `resources/css`, `resources/js`, welcome view) removed from the skeleton. `GET /` returns a JSON service descriptor. | Backend-only scope. |

New decisions: append with owner approval, effective rule version, migrations/compatibility impact, tests and rollback. Never erase previous business-rule versions used by issued vouchers or posted ledger entries.
