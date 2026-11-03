# Independent release acceptance — 2026-11-03

B66 provides a [fail-closed acceptance/deployment-hook command](../qa/ACCEPTANCE_GATE.md). It consumes independently reviewed source/check/provider/frontend evidence and never approves a release itself. `requirements/QA_ACCEPTANCE.json` remains UNMAPPED; this is an enforceable input contract, not the missing original175-row executable mapping. Actual isolated CLI output is retained in `docs/qa/B66_GATE_CLI_CAPTURE.json`; no genuine staging/provider run is implied.

**NO-GO.** Backend fixture/CI passes are implementation evidence; independent E2E-01–25 packages, required native installation/order/refund subruns and deployed staging acceptance remain unverified. This register creates no approved Quality Center cases and does not certify175 checks. The original PDF v2 is unavailable in this workspace; attachment access was rechecked2026-11-03 and returnedHTTP401, and no matching local PDF was found. Its contents/check IDs are not reconstructed from filenames, totals or previous agent claims.

## What can be established now

- Runtime inventory:466 route entries,467 non-HEAD method/path operations and408 unique paths; current access/permission annotations agree. Regenerate with `php artisan shopbonus:api-routes --markdown`; the checked-in inventory test detects route/access/role drift.
- Current pushed baseline backend CI is recorded in [LAUNCH_READINESS.md](LAUNCH_READINESS.md) and [actual provenance](BACKEND_CI_2026-11-03.json). This is a backend suite, not frontend TypeScript/browser, deployed worker or performance proof.
- D-109 is the approved financial policy for **new rewards**: pending→network approval (still pending)→independently recorded settlement→single available credit. Legacy frozen timing and reversal history remain. The current BusinessJourneyTest intentionally follows this policy. Immediate-credit/no-pending expectations must be recorded as superseded deviations with D-109, not passed by hiding pending amounts or weakening evidence.
- Existing Laravel API/native-reader/bridge tests remain useful fixture evidence. Missing platform packages and storefront lifecycle components are actual delivery gaps, not merely missing credentials.

## E2E package slots, not a verified PDF mapping

IDs below come only from the requested E2E-01–25 range. Scenario titles, exact check IDs and the175-check allocation are **UNMAPPED** until the original approved PDF is available and hash/version recorded. No equal allocation of checks is assumed. An independent tester must map/review the actual source before execution/import.

| Package slot | Original checklist mapping | Independent run | Evidence package |
| --- | --- | --- | --- |
| E2E-01 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-02 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-03 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-04 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-05 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-06 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-07 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-08 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-09 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-10 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-11 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-12 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-13 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-14 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-15 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-16 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-17 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-18 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-19 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-20 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-21 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-22 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-23 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-24 | UNMAPPED — original PDF required | NOT RUN | MISSING |
| E2E-25 | UNMAPPED — original PDF required | NOT RUN | MISSING |

Each completed package needs the actual checklist version/hash and mapped IDs; exact deployed SHA/environment; execution UTC times; tester identity independent of author/fixer; expected/actual outcomes including failures; sanitized request/response and request IDs; relevant immutable database/source/ledger references; evidence checksums and private storage references. Include package/native platform version and installation artifact SHA where applicable. Client screenshots/video and TypeScript/browser results are separate client evidence, never inferred from API tests. Import actual JUnit/evidence through existing Quality Center APIs/CLI; do not approve cases or fabricate PASS from this register.

For a checklist row that conflicts with D-109, preserve the original wording and attach the approved replacement expectation/decision reference before scoring it. A source-policy mismatch is not resolved by changing runtime processing without authorization.

## Native delivery and provider subruns

The complete live matrix remains [VERIFICATION.md](../integrations/VERIFICATION.md); no NOT RUN row is promoted by this release update.

| Delivery/gate | Current evidence | Required next result |
| --- | --- | --- |
| PrestaShop ZIP/module | NOT SHIPPED in this Laravel checkout; native read fixture tests exist | Versioned native source/build artifact/hash, install/upgrade/configuration, signed paid-order update, full/partial refund, replay and uninstall cleanup evidence |
| OpenCart extension/OCMOD where applicable | NOT SHIPPED; bridge-only/limited capability remains | Explicit supported version and compatible artifact/hash, install/events registration, authenticated order/payment/refund mapping, dedupe and uninstall cleanup evidence |
| Native storefront publish/cleanup components | Pending/limited per platform profile and verification matrix | Approved artifact and real-store publish/render/duplicate prevention/update/uninstall-cleanup evidence; provider scope and version documented |
| Required provider installation/order/refund subruns | NOT RUN | Actual supported store/sandbox, credential generation and canonical store identity, installed artifact SHA, source event/order IDs, timestamps, observed payment/refund state and immutable ShopBonus effects |
| Unsupported connectors in a proposed release scope | No verified connector contract/acceptance | Implement verified contract and deliver required artifacts, or record an explicit owner-approved scope exclusion before release; never silently count as supported |

Laravel backend/API is the authorized code scope (AGENTS.md). Native platform modules/extensions and storefront implementation are separate deliverables; source is not present here to build/install them. A native engineer must supply them before their provider journeys can pass. Credentials configured by the senior developer cannot replace missing code/artifacts. Existing real-shop authentication/signature/identity/payment/refund/rotation/recovery checks must run against the installed artifact; fixture imports and manually staged canonical events are not equivalent.

For each required platform, retain distinct installation, order and refund subruns. Show unpaid/unknown orders staying ineligible, trustworthy paid/refunded source state, partial/full refund handling, duplicate/out-of-order retries without duplicate effects, contact cancellation/suppression and current-source binding where applicable. Record genuine unsupported fields/operations as blockers; do not invent settlement time, currency, refund success or redemption.

## Deployment and independent approval

Deployed workers/queue coverage, production-like performance, TLS/mail/provider configuration and offsite/key/infrastructure recovery are NOT RUN here. Use [recovery deployment acceptance](../reliability/DEPLOYMENT_RECOVERY_ACCEPTANCE.md) and the existing CI/staging handoff. A local launch configuration check and a green backend CI run do not verify those environments. The configuration command's success is not release approval when manual gates remain open.

Hassan supplies client integration/independent scenario evidence as assigned; senior/integration testers supply actual installed-provider and deployment subruns; Martijn retains release approval. The implementing agent cannot certify its own work as independent acceptance. Until the missing PDF mapping, packages and required actual runs exist, whole-card/release acceptance remains blocked.
