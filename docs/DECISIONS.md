# Decisions and Open Business Questions

All items below are OPEN unless supported by existing owner-approved implementation or written owner confirmation. Record a date, source and decision before closing.

| ID | State | Question / Evidence needed | Conservative interim handling |
| --- | --- | --- | --- |
| OPEN-AFFILIATE-CREDIT-TIMING | OPEN | Trello #19/#22: pending, not spendable until approved. #23: instantly approved/spendable on valid attribution despite future network rejection. Which has priority and what fraud/abuse policy applies? | Do not change live spendable balances on ambiguous events; implement status data model and independent tests first. |
| OPEN-REWARD-UNITS | OPEN | Are EUR redemption credits and integer points the same unit? Conversion rate and per-program rules? | Store explicit unit and integer quantity; no implicit conversion. |
| OPEN-FINANCIAL-LEGAL | OPEN | Seller of record, VAT, invoice obligations, refund/chargeback ownership, merchant settlement arrangements. | Sandbox-only payments / no production enablement until owner/legal decision. |
| OPEN-MARKETING-CONSENT | OPEN | Document lawful basis and scope for Answer24 phone outreach and voucher-reminder communications in NL/EU. | No unsolicited outbound contact; eligibility engine records blocked reason. |
| OPEN-PROVIDER-CAPABILITIES | OPEN | Which adapters have authorized API credentials and actually verified signature, paid state, coupons and redemption? | Report real per-capability limits; no false connected status. |
| OPEN-EXISTING-CODEBASE | OPEN | Existing repo URL, deployment topology, migrations and stable API consumers? | Audit and map first; avoid rewriting. |
| OPEN-EMPTY-CARD-27 | OPEN | Trello card "App store for platforms" is empty. Which platform store and scope? | Do not implement from title alone. |

New decisions: append with owner approval, effective rule version, migrations/compatibility impact, tests and rollback. Never erase previous business-rule versions used by issued vouchers or posted ledger entries.
