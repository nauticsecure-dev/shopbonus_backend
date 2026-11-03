# Review acceptance alignment — 2026-11-03

Applies to the five reported reward, provider, workspace and Answer24 findings. This is an acceptance addendum, not a rewrite of the historical Trello snapshot. Repository base: `e3b25c8`, branch `frank/review-fixes`.

## Affiliate credit timing

D-109 (owner2026-11-03) supersedes D-089/D-107 for new rewards: current default `on_network_settlement` requires both network approval and independently recorded settlement. Pending and approved-but-unsettled conversions remain non-spendable. Approval never proves cash receipt. Historical timing snapshots retain their terms. This addendum was initially verified against an older checkout; D-109 aligns it with newly merged main and the owner’s explicit decision.

Acceptance must assert pending available=0, approval alone remains pending, recorded settlement credits once and replay does not duplicate it. Preserve each transaction's frozen timing/rule and existing rejection/reversal behavior; do not switch pending obligations to instant credit. A brief requiring immediate spendability conflicts with the recorded owner policy and is not satisfied by hiding pending amounts. Immediate credit and its loss/reversal policy require a replacement owner decision before activation.

## Explicit €4.75 fixture

Use EUR wallet credit, commission475minor, versioned `share_bps=10000`, `min_commission_minor=0`, no cap or review threshold that blocks this amount, and a verified eligible attributed program/conversion. The fixture is test-local, not a production configuration change. A pending event produces pending475/available0; approval alone leaves pending475/available0; verified settlement produces pending0/available475; replay leaves475. Changing the active rule later does not change this transaction's frozen100% rule. Allocation remainder is0, with `cash_settlement_verified=true` only after independently recorded settlement.

The platform default remains7000bps (70%). A100% reward must never be inferred from the commission amount alone. Points earning/pricing is separate from this EUR scenario. The remainder remains allocation reporting under D-105, not formal revenue posting.

Executable regression: `AffiliateTest::test_475_commission_scenario_requires_explicit_full_share_and_recorded_settlement`. Existing affiliate tests cover rejection, reversal, review thresholds and frozen timing.

## Workspace status expectations

Foreign, missing, removed-member and suspended-membership workspace access returns the same generic404, with the attempted access audited. Acceptance must check both concealment and audit evidence. An active member lacking an operation's permission receives403; an accessible workspace that is suspended also blocks its members with403. Do not change foreign access to403 merely to match an inconsistent scenario.

Executable coverage: `WorkspaceTest`, especially `test_other_tenants_webshop_is_indistinguishable_from_a_missing_one_and_audited` and the employee/owner-permission tests.

## Answer24 responsibilities

ShopBonus authenticates and normalizes source orders, establishes trustworthy payment evidence and enforces its own eligibility gate: active participating webshop/current agreement, active applicable reward offer and minimum value, complete paid order, enabled outreach, permitted documented lawful basis, required checkout consent and suppression checks. Missing/invalid phone means not callable. Grouping is per customer and webshop; cancellation/refund invalidates pending outreach and sends cancellation/suppression when already dispatched. Dispatch is subject to fresh checks; an eligibility result or paid-order webhook is not blanket permission to contact.

Answer24 is responsible for final outreach decisions and execution within the disclosed purpose, approved channel/consent and contact restrictions, including honoring cancellation/suppression. ShopBonus's gate does not replace Answer24's final checks or prove a call happened. Signed handoff acceptance is distinct from executed contact, consumer claim and voucher redemption.

Executable coverage: `ContactEligibilityTest` (payment, basis/consent, phone, suppression, grouping and cancellation) and `Answer24ChannelTest` (handoff/channel contract). Fixture tests are not live Answer24 acceptance.

## Provider delivery remains open

The live evidence matrix and required run procedure are in [VERIFICATION.md](../integrations/VERIFICATION.md). Passing internal fixtures does not certify an install package or live provider journey.

| Deliverable | Status and next evidence | Delivery responsibility |
| --- | --- | --- |
| PrestaShop module ZIP | OPEN: build/review install/uninstall artifact, then execute signed order/refund/retry journey on an actual supported store | Native integration/package developer; senior executes store acceptance |
| OpenCart extension | OPEN: extension artifact and native order-read capability; execute supported-store install and authenticated order/refund journey | Native integration/package developer; senior executes store acceptance |
| Native storefront/theme/banner artifacts | OPEN where the matrix says pending; actual install/render/uninstall and scope evidence required | Native/storefront developer; Hassan integrates UI where applicable |
| Lightspeed E-Series, Squarespace, Mijnwebwinkel, CCV Shop | UNSUPPORTED in this checkout; retain explicit catalogue blockers. Implement only against verified authentication, canonical store identity and order/payment/refund contracts; no substitute platform or fabricated proof | Backend/native integration developer; senior supplies provider setup and acceptance evidence |
| All provider live journeys | NOT RUN unless an evidence row records actual store, date, steps, limitations, verifier and tested commit | Senior/integration tester; credentials configured outside documentation |

These gaps are not resolved by this addendum. No connector was activated, installation package shipped or live verification status promoted. No staging/live/provider acceptance is claimed.
