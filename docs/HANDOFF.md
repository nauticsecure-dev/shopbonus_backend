# B53 — Full-CI dashboard contract repair (2026-11-03, Codex)

Branch `frank/review-fixes`; integrated implementation b484cdf (support40e0e0c, main313dfad); draft PR#5 https://github.com/nauticsecure-dev/shopbonus_backend/pull/5. Conflicts resolved, pushed and full CI41596390972 started. Lint/static PASS; SQLite FAILED solely on stale exact MerchantDashboardTest KPI array: merged API legitimately adds reserved_codes/expired_vouchers/expired_offers. SQLite1268total1240pass+27skip+1fail/20972assertions. All support/canary/budget tests passed. Do not label that run green. Failed JUnit remains retained by the existing workflow.

Corrected only the test's exact expected KPI keys/values; no API behavior removed or response example invented. Local SQLite MerchantDashboardTest11pass/85assertions; MySQL11pass/85assertions (46.014s). No runtime changes or new migrations in B53. Pint targeted/diff checks PASS. B51/B52 final integrated focused evidence remains in the previous checkpoint. Clarified current fixed support policy UI value versus lower environment execution cap; no additional monetary activation or pricing assumption.

D-109 owner explicitly requires approval plus recorded settlement for NEW rewards, superseding D-107; historical snapshots remain. Support templates stay D-076; no free-form generation implementation or≥95% live-quality proof. New support guards need senior migration/profile/keys and actual budgeted canary. Main DB NOT migrated. Offsite/key/infrastructure/queue/native-provider acceptance remains NOT RUN/open. Exact next: commit/push this assertion/docs closeout and inspect replacement full CI. No main merge/deploy/customer messages/Trello changes; uncommitted scope B53 test/docs only.

---

# Integrated B51/B52 checkpoint — support guards and D-109 (2026-11-03, Codex)

Branch `frank/review-fixes`; support implementation40e0e0c; integrating main313dfaddba4a622aa4e0675cd8cb6215f4ba21a4 into draft PR#5. Preserved newer merged provider estimates/last-success telemetry, stricter support setting controls, wallet contract, recovery flags and all other main work. Resolved only overlapping implementation/docs/test expectations. Minor Pint normalization of six merged files is formatting only. No frontend implementation authored.

Owner explicitly selected **approval plus recorded settlement** after the main/D-107 conflict was surfaced. D-109 now supersedes approval-alone policy for new rewards; existing snapshots retained. The475minor/10000bps regression proves pending→approved-but-still-pending→independently recorded settlement→single credit/replay and frozen rate; allocation remainder0 and cash_settlement_verified true only after settlement. B49's older approval-alone wording was superseded in current acceptance addendum.

Support: aggregate pending EN/NL wording, separate order evidence, exact model/key/context/pricing/output-ceiling/version-bound canary (default24h expiry), omitted optional parameters, immutable required tools/storefalse/parallelfalse. Conservative UTC-day monetary reservation happens before HTTP, includes canary and retains exposure after timeout/crash. Proof changes/expiry, missing pricing, policy output above the canary-tested ceiling and lower budget all fail closed. Full-context pricing upper bounds are operator-verified; this is not provider invoice/account-wide cap or a live conversational-quality guarantee. Legacy unknown-spend days closed; rollback/reapply cannot reset allowance. Migration2026_11_03_100000 NOT applied to main DB. Stop old workers before deployment; senior securely configures profile and runs budgeted live canary.

Executed integrated SQLite256total252passed+4DB-only skips/9737assertions (30.250s); MySQL support+affiliate+operations214passed/1739assertions (79.495s), including4-process monetary race. Final output-ceiling refinement: SQLite provider/guards/chat/API138passed/8895assertions; MySQL guards/concurrency9passed/40assertions. Independent migration replay test passed in the integrated SQLite suite. PHPStan L6/512MiB PASS; Pint/diff PASS. Six real HTTP exchanges recaptured after main integration, synthetic two-entry wallet/no provider call; API examples updated. Temporary capture DB/storage/secrets/server cleaned. No production/provider credentials or customer delivery.

Status: main-code B51/B52 DONE against focused executed evidence; live-model canary and≥95% representative quality NOT RUN, template conversational acceptance remains a product acceptance gap under existing D-076. Documentation states free-form model explanations are not implemented. B50 offsite/key/infrastructure/production-queue proof and missing native provider deliveries remain open. Exact next: finish merge commit, push PR#5, inspect newly unblocked exact-code CI. Full local suites NOT RUN; CI not claimed green before execution. Checkpoint uncommitted scope is integrated merge/refinements/docs only; no deploy/merge to main/Trello changes.

---

# B51/B52 — Support aggregate wording and execution guards (2026-11-03, Codex)

Branch `frank/review-fixes`, parent9a548d8, draft PR#5. Added aggregate EN/NL pending-credit wording, actual two-entry regression and six actual HTTP owner200/foreign404/unauthenticated401 captures. Implemented exact-model/key/profile/version-bound expiring strict-tool canary via operator CLI and conservative micro-EUR reservation before each provider attempt. Missing contract/prices/proof or exhausted budget blocks egress; retained reservations cover failures/crashes. Immutable store/parallel/tool controls, optional model parameters omitted. New migration2026_11_03_100000 adds spend counter/proof table, preserves rollback/reapply and closes historical unknown-spend days. Main DB NOT migrated. Senior configures limits/rates and runs live canary; no live call made by agent.

Executed before main integration: SQLite support+API171total168pass+3skip/8926assertions; final focused guards/migration/evidence/provider/API46pass/7798assertions. MySQL final guards/evidence/concurrency32pass/162assertions, including4-process monetary race. PHPStan512MiB/Pint/diff PASS. Earlier MySQL suite167pass/1failure was a stale test expectation after failed canary invalidation; corrected focused checks pass. docs/support/SUPPORT_CHAT.md documents setup, conservative exposure semantics, D-076 templates and actual-provider≥95% quality rubric. Actual quality and brief acceptance remain NOT RUN; no free-form generated explanation implemented.

Checkpoint scope B51/B52 implementation; test fixtures explicitly synthetic. Found remote main has newer merged changes and PR#5 DIRTY, preventing CI. Exact next: integrate origin/main preserving newer work, resolve overlaps with guards, rerun affected tests, update PR. This checkpoint is not final integrated/CI acceptance. No deploy/Trello move/external customer message. Decisions D-076/D-077 remain; new monetary guard enforces existing policy cap rather than inventing pricing/model support.

---

# B50 — Recovery cadence and deployment acceptance (2026-11-03, Codex)

B49 acceptance implementation `7027e2c`; B50 recovery implementation `fd27a24`, both pushed. Previous PR#3 is merged; new draft PR#5: https://github.com/nauticsecure-dev/shopbonus_backend/pull/5. Full CI triggered by the new PR; result not yet verified. Working tree clean before this documentation closeout.

Branch `frank/review-fixes`, parent `7027e2c` (B49). User supplies four recovery/queue acceptance findings. Implemented five-minute backup attempts (was hourly), preserving opt-in and60-minute overlap lock. Default overdue15minutes (was120), minimum1 (was30), so a15-minute threshold is honored. No RPO/RTO approval or actual infrastructure guarantee inferred from this schedule. Existing deployments must update overrides/config cache and capacity-plan dump duration/retention before enabling.

Added docs/reliability/DEPLOYMENT_RECOVERY_ACCEPTANCE.md: independent disk/failure-domain retrieval, separate APP_KEY/backup-key escrow and recovery, measured data cutoff/durable availability, full infrastructure rebuild timeline and inventory of actual producer/worker queue connections with pause/backlog/failure/recovery acceptance. Updated stale hourly runbook statements and remaining-work inventory. offsite_verified remains false; no deployment or positive verification attestation invented. Default queue coverage stays default+affiliate; additional connections and Redis worker/ready-age proof require deployment telemetry.

Executed SQLite recovery+operations+API14passed/7660assertions (19.012s); focused MySQL operations4passed/28assertions (54.574s). Recovery tests intentionally use isolated SQLite, not MySQL restore proof. Schedule test verifies cron expression, overlap lock and enabled/disabled gates; freshness test verifies14minuteWARNING→16minuteCRITICAL with15minute threshold. Initial schedule test needed console kernel boot via schedule:list; corrected final suite passed. Pint dirty check PASS, diff check PASS. PHPStan L6 PASS with512MiB (initial128MiB attempt exhausted memory). No route/response schema changes, no migrations, no credentials or primary DB writes.

Status PARTIAL overall: code cadence/freshness DONE after tests; offsite durability/key recovery/full infrastructure drill and production queue acceptance NOT RUN without deployed infrastructure. Proposed RPO≤15minutes/RTO≤4hours remain UNAPPROVED. Exact next: push review checkpoint and have senior execute the documented isolated deployment acceptance against the actual topology; do not mark #30 complete. Full local suites NOT RUN; exact commit CI pending. Uncommitted scope at checkpoint B50 only; no merge/deploy/Trello edits.

---

# B49 — Review acceptance alignment (2026-11-03, Codex)

Branch `frank/review-fixes`, parent `e3b25c8dc1b5a0dbe653269a329b34314aec295d` (CI41295450472 SUCCESS). User asks to fix five review findings. D-089/D-107 already resolve timing: approval, not settlement or instant attribution. D-105 allocation-only remains. No replacement financial policy assumed.

Added docs/qa/REVIEW_ACCEPTANCE_ALIGNMENT.md, linked from consolidated spec/provider verification. Defines pending/approval versus settlement, explicit475minor/10000bps scenario,404 foreign-workspace concealment versus403 member permissions, ShopBonus eligibility versus Answer24 final execution, and open native-install/provider acceptance deliverables with responsibilities. Corrected misleading legacy/immediate test names/comments. Added executable475minor pending→approval/replay/frozen-rule regression without altering the7000bps default. No runtime behavior/routes/schema/API payload changes, no migration required, no fabricated HTTP examples.

Executed SQLite: AffiliateTest + ContactEligibilityTest + WorkspaceTest61passed/491assertions; Answer24ChannelTest + OpenApiCoverageTest17passed/7827assertions. Pint targeted PASS; git diff check PASS. Focused MySQL475minor regression1passed/12assertions (24.934s). Existing safeguards tests cover cross-tenant audit, missing consent, suppression and cancellation. Main DB untouched. No provider calls or install/acceptance claims.

Status: acceptance alignment DONE after recorded tests; provider packages/connectors/live journeys remain OPEN, not fixed by documentation. Exact next action: push this review checkpoint; provider/native implementation remains the next separate scoped engineering task using verified contracts. Full CI for this commit pending; full local suites NOT RUN (focused boundary only). Changes at checkpoint are B49 docs/test only; implementing SHA recorded in commit history/follow-up. No deployment/merge/Trello move.

---

# Consumer profile review handoff — 2026-10-31

Branch `HassanDev`, base `59916e9`. Uncommitted; no commit/push performed.
Many unrelated existing edits are preserved. No migration or new endpoint.

OTP log removal and password-change confirmation were already in the dirty
workspace. Verified them and added explicit missing-confirmation coverage.
Password confirmation applies only to authenticated changes; registration/reset
keep single-password entry (D-014). Verification codes remain the established
email-change flow (D-050); acceptance should say codes rather than links.

Executed SQLite checks: ConsumerProfileTest + Identity + Answer24ChannelTest +
Notifications + OpenApiCoverageTest: **136 tests, 133 passed, 3 skipped, 6125
assertions**. Focused initial profile/OTP/session run: 38 passed, 294 assertions.
Targeted Pint passed for the password rules/action, OTP service and profile
test. Sibling frontend: TypeScript passed; targeted ESLint passed with two
existing unused image-rule suppression warnings; all 10 controlled browser
regression scenarios passed. Sibling frontend documents outstanding release
evidence in `docs/consumer-profile-acceptance.md`.

Exact next release action: run the ten full profile acceptance journeys against
real APIs and email transport, including Answer24 decisions/delivery, wallet
ledger changes, existing favorites/benefits and ownership/recovery; compare
desktop/mobile screenshots with the approved design. These are not proven by
mocked browser responses or separate backend integration tests. Keep #10 PARTIAL.

---


# Continuation checkpoint — B48 ownership transfer (D-108), cashback timing kept (D-107), suspension cuts sockets (2026-11-01, Claude)

Branch `frank/review-fixes`, parent `8d7e71e` (B47, CI run 41203696904 PASS). Owner said "use the recommended decisions":
- **D-107:** keep D-089 (affiliate cashback spendable only after network approval; pending balance stays shown). Conflicts with the brief's "no displayed pending balance" (Oluwasola, Mijn voordelen); the client may overrule, which needs a backend change, not only frontend.
- **D-108 ownership transfer (#16 finding 5):** `ownership_transfers` table (migration 2026_11_01_100000), `OwnershipTransfer` model, `OwnershipTransfers` service, `OwnershipTransferController`. Routes under `workspaces/{workspace}/ownership-transfer`: GET (owner/target see the pending one, others `null`), POST (owner only: `ownership.manage` + `password.recent`), POST `/accept` (target, `password.recent`), DELETE (owner cancels / target declines, no step-up). One pending per workspace (webshop row lock), TTL `shopbonus.workspaces.ownership_transfer_ttl_hours` (72). Accept rechecks under locks and voids on any change (409 `transfer_invalid`); atomic swap target→owner, previous owner→manager; all steps audited. Expiry/void are written outside the refusing transaction so a rollback cannot undo them. No email notification yet (frontend shows GET); add one if wanted.
- **Realtime (#16 follow-up):** `WebshopLifecycle::suspend` terminates every active member's sockets; `UserAdministration::endAccess` (account suspend/revoke) terminates the user's sockets.
- OpenAPI: four operations; examples from a test run (marked), two hand-written error examples use `<request id>`.

Executed: new tests/Feature/Workspaces/OwnershipTransferTest.php (4) and a RealtimeRevocationTest suspension case. Full SQLite 1171 / 1146 pass + 25 skipped; MySQL Workspaces+Admin 113/113; full PHPStan, Pint PASS. Main DB not migrated.

#16 backend now has no open reviewer finding; remaining is Hassan (team permission editor, invite review, access history, ownership transfer UI, Echo user sign-in). Next exact: check CI.

---

# Continuation checkpoint — B47 order monetary breakdown; Wix product lines (2026-10-31, Claude)

Branch `frank/review-fixes`, parent `5fbbb8c` (D-106 recorded: no per-consumer voucher limit; B46 CI run 41202587978 PASS). Card #32 (Commerce Platform API Integrations), Oluwasola findings 3 and 5 (partial):
- New nullable `webshop_orders.amount_breakdown` JSON (migration 2026_10_31_140000) via `App\Integrations\AmountBreakdown`: `original_total_minor`, `current_total_minor`, `tax_minor`, `shipping_minor`, `discount_minor`, `tax_included`, `source`, `currency`. Each part null unless the provider reports it; never derived. It sits beside `amount_minor` (gross) and `refunded_minor`, which are unchanged, so refunds are not subtracted twice (the reviewer's concern). Exposed in OrderResource (sheet + detail).
- Shopify: pinned query adds `totalTaxSet`, `totalDiscountsSet`, `taxesIncluded`; breakdown original=`totalPriceSet`, current=`currentTotalPriceSet`, tax, discount; same shop currency only; shipping null (docs describe `totalShippingPriceSet` as shipping returned to the customer).
- Wix: breakdown from `priceSummary.{total,tax,shipping,discount}`; product lines via `LineItems::wix` (`totalPriceBeforeTax` = after discounts, before tax; basis `discounted_ex_tax`; any bad row → whole list null).
- Field names verified 2026-10-31 against the official Shopify Admin GraphQL Order and Wix eCom Order object pages. NOT verified, so NOT mapped: WooCommerce order-level totals (docs page section unreachable), BigCommerce (reference pages unreachable), Shopware (Admin API reference 404; lineItems association unconfirmed), Magento (names confirmed but discount sign and configurable/bundle parent/child rows undocumented, so line items could double count). Docs: PLATFORM_API_SPECS.md (also fixed stale Wix backfill line), OpenAPI order detail.

Executed: new ShopifyApiTest breakdown test (through the pinned query), WixConnectorTest price summary/lines test, OrderRecoveryTest asserts stored original/current totals end to end. Full SQLite 1166 / 1141 pass + 25 skipped; MySQL Integrations+Orders 174/174; PHPStan, Pint PASS. Main DB not migrated.

#32 still open: Woo/BigCommerce/Shopware/Magento breakdown and lines once schemas are verified (official reference pages or a real store sample), Ecwid amount/currency, Lightspeed refunds, install packages, provider acceptance runs.

---

# Continuation checkpoint — B46 large voucher exports are queued (2026-10-31, Claude)

Branch `frank/review-fixes`, parent `a959e3e` (B45, CI run 41200758806 PASS). Card #17 (Webshop Dashboard, Offers & Voucher Management), Oluwasola finding 8: `GET /workspaces/{workspace}/vouchers/export` streamed every export inside the HTTP request. Now:
- Up to `shopbonus.offers.voucher_export_sync_max_rows` (env VOUCHER_EXPORT_SYNC_MAX_ROWS, default 5000) it streams exactly as before (same CSV, same audit).
- Above it: 202 with a `voucher_exports` row (migration 2026_10_31_130000; `VoucherExport` model); `BuildVoucherExport` job writes the same CSV to the private local disk `voucher-exports/{id}.csv`.
- `GET .../vouchers/exports/{export}` (status) and `.../download` (requester only, same webshop, `vouchers.export`, throttled, audited `vouchers.export_downloaded`, no-store/nosniff). 409 `export_not_ready`, 410 `export_expired` after `voucher_export_ttl_hours` (default 24); 404 for anyone else.
- `shopbonus:prune-voucher-exports` (hourly) deletes expired files. Export files are transient and deliberately not in backups (masked codes only, no consumer identity).
- Query/CSV moved into `App\Services\Vouchers\VoucherExports` so both paths write the same file. OpenAPI: 202 on export plus two new operations; examples from the test suite (marked as such), not a live capture.

Frontend (Hassan): on 202, poll the export status, then download; show queued/failed/expired.

Executed: new VoucherDisputeTest export tests (queued vs streamed content identical, requester-only, expiry/prune, not-ready). Full SQLite 1164 / 1139 pass + 25 skipped; MySQL VoucherDisputeTest + WebshopDashboardOffersE2ETest 22/22; full PHPStan and Pint PASS. Main DB not migrated.

#17 backend still open: only the per-consumer issuance limit (product decision). Next exact: check CI; then #31/#32 line items once provider order schemas are verified, or #16 ownership transfer once designed.

---

# Continuation checkpoint — B45 realtime revocation terminates open sockets (2026-10-31, Claude)

Branch `frank/review-fixes`, parent `0c75e1b` (B44, CI run 41199933613 PASS: lint, MySQL, SQLite). Card #16 (Webshop Onboarding & Workspace Management), Oluwasola finding 2: channel auth (routes/channels.php) is rechecked on subscribe, but a removed member's already-open subscription to `webshops.{id}.orders` kept receiving events. Now:
- `POST /api/broadcasting/user-auth` (Laravel `Broadcast::userRoutes`, same middleware as channel auth) signs a Pusher/Reverb connection in as the user's **public** ID (`RealtimeAccess::signInIdentity`); support sessions 403.
- `TeamService` calls `RealtimeAccess::terminate()` after commit when a membership is suspended/removed/admin-revoked (`changeStatus`) or an edit drops `orders.view`; it calls Reverb `terminate_connections` for that user. The client reconnects and channel auth refuses the lost channel. A broadcaster failure is reported and never blocks the revocation. log/null drivers: no-op.
- Docs: docs/api/README.md section (described from tests, not a live capture).

Frontend (Hassan): enable Echo/pusher-js user authentication against `/api/broadcasting/user-auth` (call `signin()` after connect). Without it the server cannot find the user's sockets and an open subscription lasts until the client reconnects. Not covered: webshop suspension and account deactivation do not terminate sockets yet (same mechanism would apply).

Executed: new tests/Feature/Workspaces/RealtimeRevocationTest.php (4 tests; 3 fail without the TeamService wiring); full SQLite suite 1162 / 1137 pass + 25 skipped; MySQL tests/Feature/Workspaces 42/42. Pint, PHPStan PASS. Not verified against a running Reverb server.

#16 backend still open: ownership-transfer workflow (needs approved design). Next exact: push, check CI; then #17 queued large exports, or #31/#32 line items once provider order schemas are verified.

---

# Continuation checkpoint — B44 honest integration capabilities, refund gate, Shopware state events (2026-10-31, Claude)

Branch `frank/review-fixes`, parent `e0f9f23` (B43). Card #31 (Platform API & Webhook Engineering), Oluwasola findings 1, 2 (from #32), 5 and 6:
- **Capabilities (finding 1):** `CapabilityMatrix` marked refunds AVAILABLE whenever a `refund_api` note existed (even "unsupported"), and payments/backfill from the native-read flag alone. Each platform profile in `config/integrations.php` now states what its order mapping really fills: `refund_amounts_mapped`, `payment_time_mapped` (set from the connector code: refunds mapped by WooCommerce, Shopify, Wix, BigCommerce, Magento, OpenCart payload; not Ecwid, Lightspeed C, PrestaShop, Shopware; payment time missing for Ecwid, Lightspeed C). Refunds/payments are AVAILABLE only from those flags (the LIMITED reason quotes the API note); backfill only when the connector implements `PagedOrderReader`.
- **Refund gate (finding 5):** `changes_refunds_recoverable` passed on any recovered financial change. `ProcessWebhookEvent` now also records `native_refund_recovered` (refunded amount increased, or payment status entered partially refunded/refunded); `ConnectionReadiness` uses it. Consequence: a platform that cannot report refunds at all (Lightspeed C) cannot reach Connected; documented in docs/integrations/READINESS_AND_RECOVERY.md.
- **Shopware (finding 2):** `state_enter.order_transaction.state.{paid,paid_partially,refunded,refunded_partially,cancelled,failed,chargeback}` app webhooks now queue the same ID-only native order lookup as `order.written` (only `payload.order.id` used; topic stored). The Shopware app manifest (separate package, not in this repo) must subscribe to these events.
- **Stale docs (finding 6):** Wix config limitation and VERIFICATION.md row corrected (orders/search backfill exists; paid_at is latest approved transaction createdDate).

Executed: new tests `CapabilityAndCatalogTest::test_refund_payment_and_backfill_claims_follow_the_actual_mapping`, `OrderRecoveryTest::test_a_recovered_non_refund_change_does_not_prove_refund_recovery`, `ShopwareAppWebhookTest::test_payment_and_refund_state_events_queue_a_lookup_of_their_order`; each fails on the old code. Full SQLite suite 1158 tests / 1133 pass + 25 skipped / 19862 assertions; MySQL tests/Feature/Integrations 157/157. Pint, PHPStan PASS. Captured HTTP examples in docs/integrations/B23_* and docs/api/README.md predate this (Ecwid/Lightspeed refunds were shown AVAILABLE there); not re-captured.

#31 still open, not code here: line items for Wix/Shopware/Magento (shared with #32; needs verified provider order schemas, e.g. Shopware lineItems association), Ecwid amount/currency and Lightspeed refunds (provider contracts), PrestaShop/OpenCart packages, Shopify theme extension, Adobe async delivery, and the ten real provider acceptance runs.

---

# Continuation checkpoint — B43 lost Mollie payments are recovered by their webhook (2026-10-31, Claude)

Branch `frank/review-fixes`, parent `4271f04` (B42). Card #20 (Voucher Checkout, Mollie & My Vouchers), Oluwasola finding 4. Already in place before this: the checkout row (intent) is committed before Mollie is called, Mollie gets the stable `Idempotency-Key: checkout:<public_id>`, an unknown outcome (timeout/5xx) keeps the checkout pending and a client retry recovers the same payment. Gap closed now: if the client never retried, Mollie's later webhook for that payment found no `provider_ref` and was ignored. `CheckoutService::recoverPayment` now asks Mollie for an unknown ID and links it only when its `metadata.checkout_id` names a checkout with no payment yet and exactly the same amount/currency; then the normal `sync` runs (paid → finalise, or `paid_unfulfilled` if reservations were already released by expiry). A second payment naming an already-linked checkout is reported, never linked. Mollie 4xx for a forged ID → still 200 with nothing revealed; Mollie unreachable → exception (non-200) so Mollie retries. Audit `checkout.payment_recovered`. OpenAPI webhook description updated. No migrations/routes.

Executed: new CheckoutTest `test_a_payment_whose_creation_answer_was_lost_is_linked_by_its_webhook` (fails on old code) and `test_a_lost_payment_of_another_amount_is_not_linked`. SQLite Checkout+Funding+Journey+SalesAttribution+Api 89 tests / 86 pass + 3 pre-existing MySQL-only skips; MySQL CheckoutTest+FundingTest 24/24. Pint, PHPStan PASS. All webhook-posting tests use faked Mollie (checked: no real HTTP).

#20 backend now has no open reviewer finding; remaining is Hassan (Checkout/My Vouchers UI) and real Mollie TEST acceptance. Not covered: a payment that never gets any status change after creation (stays `open` at Mollie until it expires, which does send a webhook). Next exact: push, check CI; then #16 realtime revocation or #31 capability claims.

---

# Continuation checkpoint — B42 merchant voucher rows keep the issued offer name (2026-10-31, Claude)

Branch `frank/review-fixes`, parent `f2d8d5a` (B41). Card #17, Oluwasola finding 9: merchant voucher list/detail/export rows took `offer_name` from the offer's current version, so editing an offer relabelled vouchers already issued. `presentVoucher` now uses `terms_snapshot.name` (frozen at reservation), falling back to the current name only if a snapshot lacks one. Search still matches the current offer name (documented). OpenAPI description updated. No migrations/routes.

Executed: new `VoucherDisputeTest::test_merchant_voucher_list_keeps_the_name_the_voucher_was_issued_under` fails on the old code (old voucher shown as the new name) and passes now. SQLite Checkout+Api+Offers 96 tests / 93 pass + 3 pre-existing MySQL-only skips; MySQL VoucherDisputeTest 10/10. Pint PASS.

#17 backend still open: per-consumer issuance limit (product decision: is it wanted, and per offer?), queued large exports. Next exact: push, check CI; then #20 durable Mollie payment intent, or #16 realtime revocation.

---

# Continuation checkpoint — B41 merchant redemption of reusable codes names the voucher (2026-10-31, Claude)

Branch `frank/review-fixes`, parent `f16b9c1` (B40). Card #17 (Webshop Dashboard, Offers & Voucher Management), Oluwasola finding 2: `POST /workspaces/{workspace}/vouchers/redeem` redeemed the oldest issued voucher behind a code, so with a reusable code it could mark another consumer's voucher used. Unique codes are unchanged (one voucher per code). A reusable code now needs `voucher_id` (the public ID the customer shows): without it 422 `voucher_id_required`; an ID not issued on that code (or another webshop's) is the existing 404 `voucher_not_found`. Expiry/once-only rules are unchanged (`VoucherInventory::redeemByMerchant`). OpenAPI request field, description and 422 example (from the test run) updated. Provider-confirmed redemption already attributes reusable codes by buyer email (`RedemptionMatcher::buyersVoucher`). No migrations/routes/packages.

Frontend impact (Hassan): the merchant redeem form must send `voucher_id` for reusable-code offers; consumers already see the voucher ID on My Vouchers.

Executed: new `CheckoutTest::test_merchant_redeems_a_reusable_code_only_for_the_named_voucher` fails on the old code (200 for code alone) and passes now. SQLite Checkout+Offers+Api+Vouchers 119 tests / 113 pass + 6 skipped (pre-existing MySQL-only concurrency skips) / 10892 assertions; MySQL `shopbonus_testing` Checkout 33/33 (310 assertions). Pint, PHPStan PASS.

#17 backend still open: per-consumer issuance limit (needs product decision), queued large exports, voucher list rows showing current offer name instead of `terms_snapshot` name. Next exact: push, check CI; then the #17 list-row snapshot name (small), or #20 durable Mollie payment intent.

---

# Continuation checkpoint — B40 invitation acceptance revalidates every grant (2026-10-31, Claude)

Branch `frank/review-fixes`, parent `0b7f6fc` (B39). Card #16 (Webshop Onboarding & Workspace Management), Oluwasola finding 1: acceptance checked the inviter's `team.manage` and role rank but applied the invitation's stored permissions without checking that the inviter still holds each one. `TeamService::acceptInvitation` now reruns the same `assertCanGrant` rule used when inviting (role rank, owner-only, every permission held) against the inviter's current membership, locked for update in the acceptance transaction; any failure is the existing 410 `invitation_invalid` and the invitation stays pending. OpenAPI description updated. No migrations, routes or packages.

Executed: new `TeamTest::test_invitation_dies_when_inviter_loses_a_granted_permission_or_rank` fails without the fix (200 instead of 410) and passes with it. tests/Feature/Workspaces 38/38 (255 assertions) PASS on SQLite and on MySQL `shopbonus_testing`; Pint and PHPStan (TeamService) PASS. Main DB untouched.

#16 backend still open: realtime subscription revocation for removed members (finding 2) and a dedicated ownership-transfer workflow (finding 5, needs approved design). Frontend items (permission editor, invite review, access history) are Hassan's. Next exact: push, check CI; then #17 merchant manual redemption of reusable codes (VoucherOperationsController picks the oldest issued voucher) or #20 durable Mollie payment intent.

---

# Continuation checkpoint — B39 merchant dispute history paginated (2026-10-31, Claude)

Branch `frank/review-fixes`, parent `52c8046` (B38). Source: `MS0DLCAX - shopbonus (4).json`, Oluwasola's review of #17 (Webshop Dashboard, Offers & Voucher Management), finding 7: `GET /workspaces/{workspace}/disputes` returned at most 200 rows with no way to reach older ones. Now server-paginated newest first (`page`, `per_page` 1–100, default 25) with the same `meta` shape as the voucher list; `data` stays an array, so existing callers keep working. OpenAPI parameters/example/description updated. No migrations, routes, packages or provider calls.

Triage of the (4) export's easiest findings against the code: OTP logging (e171ca5), PHP 8.5 SOAP signature (e171ca5), `/public/webshops/{slug}/cover` OpenAPI (5546489), backup evidence dirs, multi-queue monitoring, provider-redemption expiry and checkout-history snapshot title are already fixed on this branch; reviewers ran against an older checkout.

Executed: SQLite VoucherDisputeTest 9/9 (new `test_merchant_dispute_history_is_paginated_newest_first`), API + dispute + dashboard-offers 52 tests/10337 assertions PASS; Pint PASS. MySQL not run for this change; no real HTTP capture (example `meta` matches the one-row example). Frontend dispute screen (Hassan) should read `meta` to page.

Next exact: push, check CI; then continue with B38's next item (durable checkout creation/uncertain-payment recovery, #20).

---

# Continuation checkpoint — B38 explicit points earning verified (2026-10-31, Codex)

Branch `frank/review-fixes`, parent `130fd24056bf1166052feaee26e79ff03136be84`, draft PR https://github.com/nauticsecure-dev/shopbonus_backend/pull/3. Parent exact CI41184941391 SUCCESS across quality, SQLite and MySQL. B38 implementation commit follows this checkpoint; no claim that parent CI covers these changes.

D-104 implemented: immutable per-program points rate, multiplier and rounding on existing reward rules. Incomplete/unmapped rules stay inactive; scope/source/currency mismatches rejected. New awards select only configured points rules and freeze complete terms; legacy pending snapshots retain their original calculation. Exact integer arithmetic avoids float/overflow errors. Public discovery labels points without a EUR cashback percentage. D-105 remains allocation reporting; points monetary valuation remains explicitly unknown, not invented. No dependencies/new endpoints.

Executed: focused SQLite66 total/65passed+1skipped/7959assertions; MySQL63 total/62passed+1skipped/446assertions. Includes arithmetic boundaries, immutable/legacy terms, program isolation, approval timing, source/currency negatives and non-destructive migration replay. PHPStan L6, Pint and diff checks PASS. Sixteen real HTTP exchanges in docs/qa/B38_POINTS_EARNING_HTTP_CAPTURE.json; OpenAPI/README updated. Capture server stopped and isolated DB/storage/credentials removed. Migration120000 adds earning terms; migration110000 now guards reapplication; both preserve contractual data on rollback. Main shopbonus DB NOT migrated.

Next exact: commit/push B38, inspect its CI; then audit existing funding intents and implement durable checkout creation/uncertain-payment recovery (#20). Current uncommitted changes are B38 only. No deploy/merge/Trello changes. Whole Frank cards remain PARTIAL pending remaining code and independent/staging/provider/UI acceptance; see docs/qa/FRANK_BACKEND_REMAINING.md.

---

# Continuation checkpoint — B37 locale CI repair verified (2026-10-31, Codex)

Branch `frank/review-fixes`, PR https://github.com/nauticsecure-dev/shopbonus_backend/pull/3 (draft). B36 `05104b0` is pushed. First exact PR merge-build CI41184307319: quality PASS; both test jobs failed on the same locale regression. SQLite1142total/1116pass+25skip+1fail/19699assertions. Failure: English validation used Dutch “beleidsversie” under shipped APP_FALLBACK_LOCALE=nl. New QA pipeline correctly retained the failure and uploaded sanitized artifacts; tested merge SHA74f638a99d88b38af5e80120c69cd363f9b958d2. Never call that run green.

B37 adds explicit English attribute labels (Laravel messages retained) and restores the configured default after each request, including worker reuse. Regression tests force NL fallback and alternate NL/EN/unsupported/no-preference plus configurable EN default. Symfony test requests inject English unless explicitly blank; the absence fixture now accounts for that.

Executed: SQLite CookieConsentTest3/58 PASS with NL config; MySQL same3/58 PASS (14.048s). API3/7505 PASS, PHPStan L6 and Pint checks PASS. Four real HTTP422 captures with NL defaults in docs/qa/B37_LOCALE_HTTP_CAPTURE.json; OpenAPI/README updated. Capture server stopped; isolated DB/storage removed. No migrations/provider calls/packages.

Owner also resolved D-104: require explicit per-program points earning rate before new points rules activate, preserving existing reward snapshots. D-105: keep commission remainder allocation reporting, no formal revenue posting. Next: commit/push B37 and implement D-104 as the next backend boundary. Other remaining work stays in docs/qa/FRANK_BACKEND_REMAINING.md. Native APIs were researched only: E-Series uses Ecwid officially, but source-namespace/duplicate-effect guards must be audited before enabling that alias. Mijnwebwinkel needs partner API access/contract, CCV current webshop docs and Squarespace schemas need further review. No connector was activated from a name alone. Main DB migrations still not applied.

---

# Continuation checkpoint — B36 independent retest proof verified (2026-10-31, Codex)

Branch `frank/review-fixes`; B32 `1a3eee0`, points B33 `fe699ad`, affiliate B34 `40d8e83`, CI B35 `1284bc1`. B36 corrects remaining #37/#36 false-positive proof paths: read-only bounded Git ancestry distinguishes descendants/unrelated builds; later scan chronology is never positive build proof. Unknown endpoint ancestry remains time_only/STALE even when freshly imported. Unknown finding builds require explicit independent attestation, separately audited. Both execution and import must follow the recorded fix. Unlinked/manual PASS requires executed unmocked assertions plus unexpired, decryptable, checksum-verified evidence attached to a passing result. Linked browser/manual results and endpoint PASS use the same valid-evidence requirement; expired/corrupt files cannot certify success. Existing independent-role restrictions retained. Added Dutch error translations, including earlier points/schedule errors. No routes, migrations, provider calls or packages.

Executed: final focused SQLite QA/build-proof/API 26 tests/7835 assertions PASS (32.057s), latest standalone API 3/7505 PASS after documentation changes. MySQL QA/build-proof 23 tests/330 assertions PASS (55.280s). Real temporary Git repository test covers descendants and unrelated branches. Negative cases cover old execution, missing/expired/corrupt proof, mocked/unlinked manual results, and unknown-build freshness. PHPStan L6 and Pint --test PASS. Seven actual HTTP captures in docs/qa/B36_RETEST_PROOF_HTTP_CAPTURE.json use real cookie/CSRF/password flow with explicitly synthetic report/finding/evidence fixtures; these are API guard demonstrations, not staging/provider acceptance. Capture server stopped and isolated DB/storage/credentials removed. Full remote workflow still pending PR. No local main DB migrations applied.

Next: commit B36, push review branch and create PR to main; inspect exact-code CI. Remaining Frank work is tracked in docs/qa/FRANK_BACKEND_REMAINING.md: policy-dependent points earning configuration/formal revenue, native connector gaps, real staging/provider/browser/offsite/restore acceptance and inaccessible175-check PDF. Do not call all cards Done or automatically transfer them based on this checkpoint. Current uncommitted B36 only; no external messages/deployment/Trello edits.

---

# Continuation checkpoint — B35 Quality Center CI/inventory verified locally (2026-10-31, Codex)

Branch `frank/review-fixes`; B32 `1a3eee0`, B33 `fe699ad`, B34 `40d8e83`. CI now emits actual execution start/checked-out SHA/JUnit, preserves failed-test exit codes, discovers/imports into a separate ephemeral QA database and retains only sanitized JSON/normalized JUnit/current routes/provenance artifacts. No raw report, DB or credentials uploaded. Run environment `ci` is explicitly distinct and always mocked; schedules remain staging/production and must match imported suite/environment. CLI --output refuses overwrites and records real times/hash/inventory. General backend services/models/controllers/requests/resources/rules/enums/providers/support/logging/integrations are inventoried. Conservative backend source digest invalidates route evidence even for transitive dependencies. No new routes/migrations/packages.

Executed SQLite QA 17/297 PASS (31.496s); MySQL same 17/297 PASS (48.581s). API coverage 3/7505 PASS. PHPStan L6 and Pint --test PASS. Workflow YAML parses. Actual CLI replay of the executed MySQL report produced private sanitized JSON/JUnit with 17 mocked results and current inventory. Eight actual HTTP captures include cookie/CSRF login + recent password, CI import/list, service/model inventory, 401/403 and schedule mismatch409; docs/qa/B35_CI_HTTP_CAPTURE.json and API docs. Capture base is40d8e83+WIP, not exact released-build proof. See docs/qa/CI_AND_STAGING.md. Remote workflow execution pending PR, not claimed passed.

Remaining QA review: scan chronology alone still does not prove Git ancestry on different branches; unlinked/manual finding PASS needs stricter attached-evidence enforcement. Address next coherent B36 boundary. Missing native credentials/staging, inaccessible175-check PDF and all UI/provider acceptance stay explicit. Main DB not migrated for B32/B33. Current uncommitted B35 only; no push/PR yet.

---

# Continuation checkpoint — B34 affiliate review fixes verified (2026-10-31, Codex)

Branch `frank/review-fixes`; B32 `1a3eee0`, points checkout B33 `fe699ad`. B34 repairs #19's remaining verified duplicate/selection/consent defects. Credible distinct order references can share a click; repeated order references and ambiguous legacy click-only reports remain deduped. ProgramSelector recomputes eligible candidates and stores a new decision only when inputs/outcome/rule version change, including recovery from an empty candidate set. No-policy tracking now fails closed in every environment, as do missing/unknown/withdrawn marketing consent. Regression journeys now explicitly publish synthetic policy and grant consent.

Executed: focused SQLite + API coverage 62 total /61 passed +1 skipped /8060 assertions /10.285s. Same business selection MySQL 59 total /58 passed +1 skipped /555 assertions /39.368s. PHPStan L6 and Pint --test PASS. MySQL first exposed an overlong synthetic consent ID in the new test helper; shortened to fit the existing UUID-width column, then rerun passed. No migration or public response shape change; four actual HTTP redirect captures in docs/qa/B34_AFFILIATE_CONSENT_HTTP_CAPTURE.json. API coverage now checks examples for every declared media type, allowing actual HTML redirects instead of fake JSON redirect bodies. Server stopped and isolated capture DB removed. No native network calls.

Next: commit B34; #37 CI JUnit/discovery/import artifacts with honest CI provenance, then complete further verifiable main-code gaps from current Frank inventory. Whole cards retain UI, independent staging/provider acceptance and policy boundaries. Points earning-rate/multiplier/rounding policy remains separate from approved D-103 voucher prices. Main DB NOT migrated for B32/B33. Current uncommitted work: B34 only. No push/PR yet.

---

# Continuation checkpoint — B33 separate points checkout verified (2026-10-31, Codex)

Branch `frank/review-fixes`; B32 commit `1a3eee0` preserves and completes Claude's pending CI fixes. B33 follows owner D-103: optional versioned `price_points`, explicit consumer `wallet_unit=points`, separate checkout points fields. Points purchases never convert units or call Mollie; insufficient/pending/reserved points cannot fund them. Existing EUR-credit behavior remains D-053. Checkout revalidates offer/version under locks, freezes price/unit/identity, and captures through WalletService + VoucherInventory atomically. Financial reconciliation verifies the actual unit and immutable voucher price. Same key with another offer/unit returns 409. No new routes/packages.

Migration `2026_10_31_110000_add_separate_points_checkout_prices` adds nullable offer price_points and checkout wallet_unit (legacy default eur_credit), price_points and wallet_part_points. Deliberately non-destructive down() retains contractual prices/units. Applied only in isolated test/capture DBs; main local shopbonus NOT migrated.

Executed focused SQLite: 55 total /52 passed +3 skipped /5605 assertions /5.969s. Same selection MySQL: 55 passed /5617 assertions /35.248s, including real four-process points replay and existing checkout races. Includes API coverage, offers and financial reconciliation. PHPStan L6 PASS. Pint --test PASS. No repeated full suite for this boundary; full B32 SQLite/MySQL evidence is recorded below and does not cover B33. 18 actual HTTP exchanges in docs/qa/B33_POINTS_HTTP_CAPTURE.json; OpenAPI/README updated. Capture server stopped and task-owned DB/credentials removed; no real provider call. Protected PDF download returned401; 175 individual checks remain unverified.

Next: commit B33; repair remaining verified #19 selection/deduplication/missing-cookie-policy defects, then #37 CI report/discovery wiring. Current uncommitted files are B33 only. Whole Frank cards are not Done; docs/qa/FRANK_BACKEND_REMAINING.md tracks boundaries. Senior configures credentials/deployment; Hassan owns UI and independent provider/staging acceptance. No push/PR yet.

---

# Continuation checkpoint — B32 CI repair verified (2026-10-31, Codex)

Branch `frank/review-fixes`, starting HEAD `b607592`. Preserved Claude's uncommitted code/migration/formatter cleanup and staged deletion of three stray tools scripts. Current export has ten active Frank cards (#19/#20/#21/#22/#23/#26/#29/#30/#36/#37). See `docs/qa/FRANK_BACKEND_REMAINING.md`; reviewers' policy requests do not override D-089/D-053.

Finished remaining 45 PHPStan findings without baselines/suppression/config relaxation, correcting relation and collection types and redundant branches. Retained Claude's MySQL column-width and canonical JSON evidence-hash fixes; added hash ordering/type/list/tampering regression coverage. No route or intended response contract change at this checkpoint. D-103 owner approved separate points pricing; checklist expanded, implementation follows B32.

Executed: full SQLite 1117 total /1093 passed +24 skipped /17205 assertions /139.406s (before three added hash/tampering tests); focused enrichment +hash 8 passed /31 assertions. PHPStan L6 0 errors. Pint --test PASS; Composer audit PASS. Full MySQL: 1120 total /1117 passed +3 skipped /17295 assertions /473.678s against isolated `shopbonus_testing`, including all three added tests. Main DB migrations not applied. No credentials/provider-live/deployment acceptance. API coverage 3 tests/5140 assertions PASS; final PHPStan L6 PASS. B32 is committed in `fix: finish Frank review CI repair on SQLite and MySQL`; no push/PR yet. Next: implement D-103 through existing offer/checkout/wallet architecture. Whole cards remain partial.

---

# Active handoff — B31 Trello review fixes for the 10 cards in the Frank list (2026-10-31, Claude)

Branch `frank/review-fixes` (not merged, not pushed), base `main` `59916e9`. Commits: `e171ca5` regressions, `5546489` OpenAPI recovery, `1175fe1` money/vouchers, `322d538` cockpit/support/notifications, `52adf2a` Quality Center, plus a docs commit. Source: the reviewer (Oluwasola Peter) comments on #19 #20 #21 #22 #23 #26 #29 #30 #36 #37. Each item was checked against the code before it was changed. D-089 unchanged and re-applied. Credentials senior-owned.

Fixed (each with a regression test):
- Regressions from the HassanDev merge (`9f927ef`/`4aa26c2`/`2b0db78`/`04ecbfd`/`05a47b8`):
  - Plaintext OTP log removed.
  - `AFFILIATE_CREDIT_TIMING` default restored to `on_network_approval` (D-089). Config, `.env.example` and docs had been switched to instant credit.
  - `TradeTrackerSoapClient::__doRequest` accepts PHP 8.5's optional `$uriParserClass`.
  - OnboardingTest now expects `kvk_number` (country defaults to NL).
  - CapabilityAndCatalogTest targets the canonical `catalog-syncs` API. HassanDev's parallel catalog code (`CatalogSync` service, `CatalogProduct`, `CatalogCoupon`, `CapabilityMatrix`) has no routes left; it is still used by LiveBannerStudioE2ETest. Remove or merge it in a later task.
- OpenAPI: merge `9f927ef` dropped the 92 operations HassanDev had documented. They are restored with paths, parameters and schemas, and every example was re-captured on a real local server (168 exchanges, `docs/qa/B31_MERGE_RECOVERY_HTTP_CAPTURE.json`, README section).
  - Not captured, labelled illustrative: AI suggest 200 (live OpenAI key) and enrichment retry 202 (503 captured).
- Cover endpoint 404'd for shops without a logo (looked up via `logo()`). Fixed.
- #19:
  - The direct/affiliate duplicate check uses an indexed `merchant_commission_conversions.order_reference_hash` (migration `2026_10_30_200000`, backfilled). It previously checked only the first 500 rows.
- #20/#23 vouchers:
  - A reusable code redeems only the buyer's own voucher (order email hash).
  - An order placed after expiry never redeems (`voucher_expired`).
  - One compensation per voucher across consumer, merchant and checkout reports (409 `voucher_already_compensated`, voucher row lock).
  - Mollie refunds are tracked by provider status (migration `2026_10_30_210000`): checkout `refund_pending` → `refunded`, or back to `paid_unfulfilled` on failed/canceled. Retries use a new idempotency key. Synced by `shopbonus:expire-checkouts`.
  - Payment creation sends `Idempotency-Key: checkout:<id>`. Timeouts and 5xx keep the checkout for a retry; 4xx fails it.
  - Checkout history shows the purchased title.
- #21:
  - Suspending an admin ends the support sessions they started.
  - Cockpit `change_percent` compares with totals as of the end of the previous period; open actions → null.
  - One consumer definition (`User::consumers()` scope) for the cards, the user list and search.
- #26:
  - Order references like `FS-20482`, `#1042` and `bestelling 77812` are recognised.
  - Pending and reserved credit are worded separately.
  - Action labels follow the locale.
  - Deterministic FAQ matching for free-text questions.
  - `provider_cost_estimate` comes from `SUPPORT_AI_PRICE_EUR_PER_MILLION_*` (null when unset; billed cost still null).
- #29:
  - Templates may use `{{webshop_name}}` and `{{first_name}}` (plain text, bounded).
  - SPA paths come from `shopbonus.identity.frontend_paths` (`FRONTEND_PATH_*`; defaults unchanged `/app/...`).
- #30:
  - Backups include dispute, ownership-verification and QA evidence.
  - The operations overview watches every `RELIABILITY_MONITORED_QUEUES` queue (default `default,affiliate`).
- #36/#37 Quality Center:
  - Freshness follows the tested build: `provenance` is sha_verified, sha_outdated or time_only.
  - Runs accept `executed_at` / `--executed-at`.
  - Fingerprints include the controller class, type-hinted App classes and route middleware.
  - Discovery adds middleware, policies and the affiliate adapters.
  - Retests need a build containing the fix (`retest_build_outdated` / `retest_build_unverified` unless `build_includes_fix`).
  - Browser/manual PASS needs attached evidence (`QA_EVIDENCE_REQUIRED_SOURCES`).

Not changed (owner or other input needed):
- Reviewers asked for instant credit (#23, #36) and pending credit (#19, #22). D-089 stands.
- Insufficient wallet → Mollie remainder is D-053, not a bug.
- Points funding checkout is still OPEN-REWARD-UNITS.
- Admin direct-merchant commission credit (`commission-credits`) credits immediately. Check it against D-089 with the owner.
- The frontend paths for emails and support actions must be confirmed by the frontend; the defaults remain unverified.
- Hourly backups vs RPO ≤ 15 min, offsite verification, connectors for 4 platforms, CI/Postman wiring, the 175 PDF v2 checks, E2E evidence packages, and all frontend items: not in this batch.
- PHPStan: 97 errors repository-wide, all in HassanDev code from `4aa26c2` (none in lines changed here). They need a separate cleanup task.
- PHP 8.5 is not installed locally; the SOAP signature is verified on 8.4 only.

Evidence:
- Full SQLite suite 1117 tests (1093 passed + 24 skipped) after the last code commit. Full MySQL run: see PROGRESS.
- OpenApiCoverageTest PASS.
- Pint PASS.
- Capture environment (isolated SQLite, local server) and capture uploads/sessions removed afterwards.

Main local `shopbonus` DB: migrations `2026_10_30_200000` and `210000` NOT applied there.

Exact next:
- Push the branch and open a PR after the owner/senior review.
- Answer the reviewers on the D-089/D-053 items.
- Separate tasks: HassanDev PHPStan cleanup, and the duplicate catalog code.

---
# Active handoff — `shopbonus:create-admin` command (2026-10-30, Claude)

Owner request: a command to create an admin with name, email and password. `php artisan shopbonus:create-admin [--name=] [--email=] [--password=]` prompts for missing values (password hidden, asked twice), validates with the signup rules (`Password::defaults()`: min 10, letters + numbers, breached-password check in production; unique normalized email), then creates a verified active user, grants Super Admin (`granted_via: console`) and audits `admin.super_admin_granted` (`created: true`) in one transaction. Existing email → fails and points to `shopbonus:grant-super-admin`. 2FA is still required before the admin API opens. Test: `tests/Feature/Admin/CreateSuperAdminCommandTest.php` (3 PASS on SQLite and MySQL); PHPStan 0. No migration or route. Previous: B30 `a412e89`, [CI 40929686025](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40929686025) SUCCESS on all 3 jobs.

---
# Active handoff — #37 B30 Quality Center backend; backend slice done, card not Done (2026-10-29, Claude)

Branch `main`. Base `2276fe6` (B29, [CI 40895099427](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40895099427) SUCCESS on all 3 jobs). Owner: "do it" (build #33–#35, then #37's "Laravel Backend: Frank" part). D-089 unchanged. Credentials senior-owned. #12 still waits for the owner's cashback-timing decision.

B30:
- Roles: `PlatformRole::QaTester` (`qa_tester`) and `PlatformRole::Developer` (`developer`); `EnsurePlatformRole` takes several roles (`platform:super_admin,qa_tester`) and still requires confirmed 2FA. Every QA route group includes `super_admin`, so the RoleAccessMatrix rule (Super Admin passes every platform route) holds. Neither QA role reaches any other admin route. Grant: `php artisan shopbonus:qa-role <email> qa_tester|developer [--revoke]` (audited). `ApiRouteRegistry` now reports the real roles per route.
- Migration `2026_10_29_150000_create_qa_tables`: qa_inventory_snapshots, qa_endpoints, qa_components, qa_test_cases, qa_test_coverage, qa_schedules, qa_test_runs, qa_test_results, qa_evidence, qa_findings, qa_ai_quotas.
- `App\Services\Qa\QaInventory` (discovery): routes (fingerprint = method, path, middleware, handler source lines), OpenAPI summary/documented flag, jobs, events, listeners, commands, schedules, migrations, connectors (webshop connectors + Mollie/Moneybird/Answer24/Daisycon/TradeTracker clients). Diff vs previous scan (added/changed/removed/undocumented); removed rows keep `removed_at`, nothing is deleted. Cache lock, one scan at a time. `php artisan shopbonus:qa-discover --sha=<sha>` after every deploy.
- `QaStatus`: per-endpoint status from approved test cases' latest results only: FAIL > NOT_IMPLEMENTED > BLOCKED > STALE > UNTESTED > PASS; evidence older than the endpoint's last change or `QA_STALE_AFTER_DAYS` is STALE; mocked passes are labelled. `UNREFERENCED — REVIEW` only after a frontend usage report and never for webhook/integration/external routes. Dashboard summary (totals, coverage %, P0–P3 open findings, last run/probe/scan, next run).
- `QaRuns`: JUnit import (no DOCTYPE, LIBXML_NONET; failure/error → fail, `BLOCKED:`/`NOT_IMPLEMENTED:` skipped prefixes, no assertions → never PASS, "MOCKED" names or `mocked=true`); report SHA-256 dedupe; keys use the PHP `class` attribute (dotted `classname` converted); unknown keys become draft cases; redacted messages (contact data, bearer tokens, IBANs, configured secrets). Coverage map import. JSON/JUnit/HTML export (escaped, attachment, no-store, noindex, CSP none). Encrypted evidence with SHA-256 check on download, per-run dedupe, retention; `shopbonus:qa-probe --prune` daily.
- `QaFindings`: grouped by routes+tests fingerprint (409 `finding_exists`); owner must be a developer; developer fixes with commit; retest by someone other than owner/fixer, with a run imported strictly after the fix; outcome derived from that run (every linked test PASS, unmocked). Regression plan (tests on affected routes/same controllers, money/tenant/webhook first). Advisory GPT analysis via Responses API (`store:false`, strict schema with the card's fields), references restricted to the input, never changes status/severity, proposals become draft `ai` test cases; quota + 5/min throttle `qa-ai`; 503 until `QA_AI_*` is set.
- `QaProbe` + `shopbonus:qa-probe` every 5 minutes (no-op until `QA_PROBE_ENABLED`): read-only HealthChecker + latency, run with source `probe`; failure → grouped incident via IncidentRecorder::monitoring.
- Schedules (Super Admin): production only allows the `safe` suite. Staging suites are executed by CI/staging runners, which import with `shopbonus:qa-import --schedule=<id>`.
- `GET /api/v1/admin/qa/openapi.json`: the OpenAPI contract for the self-hosted Swagger/Stoplight portal; no-store, noindex, audited.
- 28 routes under `/api/v1/admin/qa`; inventory now 362 routes (admin 163). Env: QA_AI_ENABLED/API_KEY/MODEL/DAILY_LIMIT, QA_EVIDENCE_RETENTION_DAYS, QA_STALE_AFTER_DAYS, QA_PROBE_ENABLED, QA_PROBE_SLOW_MS, APP_RELEASE_SHA.

Executed evidence:
- QualityCenterTest 10 PASS (231 assertions); OpenApiCoverageTest PASS.
- Full SQLite: 931 tests, 908 passed + 23 skipped, 13667 assertions.
- Full MySQL 8: 931 tests, 929 passed + 2 skipped, 13750 assertions, 421.9s.
- Pint PASS; PHPStan 0 errors. Migration rollback/reapply on isolated SQLite PASS (11 tables).
- Real local HTTP server capture (61 requests; imports a real PHPUnit 12 JUnit log; model stub synthetic): `docs/qa/B30_QUALITY_CENTER_HTTP_CAPTURE.json`; examples in OpenAPI and README. The capture found and fixed one defect: a run imported in the same second as the fix was accepted as a retest (now 409 `retest_run_outdated`). Some 409/410/422/429/503 examples are illustrative (`<request id>`).

Main local `shopbonus` DB: migrations 2026_10_29_100000–150000 NOT applied there.

Not done (#37 is not Done): Quality Center UI and the qa.shopbonus.nl Swagger/Stoplight hosting (Hassan/infra; backend serves the protected spec); executing the 25 PDF scenarios, Postman/browser suites and the ten provider install/order/refund subruns on staging with real Mollie TEST/provider sandboxes (Hassan + senior credentials); CI wiring of `shopbonus:qa-import` and a coverage map generator; real GPT model verification (senior sets QA_AI_*); Martijn's release approval. Exact next: #12 after the owner decides D-089, or staging verification.

---
# Active handoff — #33/#34/#35 B29 CMS scheduled publishing and "Improve with AI"; backend slice done (2026-10-29, Claude)

Branch `main`. Base `2bd4ba5` (B28, CI SUCCESS on all 3 jobs). Owner: "do it" after the board review (build #33–#35 CMS gaps, then #37 backend; #12 waits for the owner decision on D-089). D-089 unchanged. Credentials senior-owned.

B29:
- Migration `2026_10_29_140000_create_cms_schedules_and_suggestions`: `cms_publication_schedules`, `cms_ai_suggestions`, `cms_ai_quotas`.
- `App\Services\Cms\CmsScheduler`: schedule publish (per locale, pinned `draft_revision`) or archive for `due_at` > now + 1 min. Refuses archived pages, duplicate pending schedules, stale revision (409 `draft_revision_conflict`), legal publish without version/effective date/approve (422), Terms/Privacy archive (409). `runDue()` claims rows (scheduled→running), re-checks the scheduler is an active Super Admin, publishes through `CmsService::publish(..., expectedDraftRevision)` (new parameter, 409 `draft_changed` inside the lock) → `stale` when the draft changed. Rows stuck in `running` > 10 min become `failed/interrupted`. Audits `cms.schedule_created|cancelled|executed|stale|failed`.
- `shopbonus:publish-scheduled-content`, scheduled every minute without overlap.
- `App\Services\Cms\CmsSuggestions`: `improve` (same locale) or `translate` (other locale) of the current draft (or published content). Extracts text segments (title, SEO, text nodes, button labels, image alts), sends them to the OpenAI Responses API (`store:false`, strict `{segments: string[]}`), rejects wrong count, empty/too long segments, `<>`, any URL/email/number/currency not in the source, or the API key; re-inserts segments into the original structure and validates with the CMS rich-text allowlist. Idempotent per user + `request_key` (409 on different payload), daily quota (429 + Retry-After), per-user throttle `cms-ai` 5/min, 503 `cms_ai_not_configured` when off. `apply` saves the draft only (revision checked, never publishes); `reject`. Audited.
- Config `config/cms.php`; env `CMS_AI_ENABLED` (default false), `CMS_AI_API_KEY` (falls back to `OPENAI_API_KEY`), `CMS_AI_MODEL`, `CMS_AI_DAILY_LIMIT`=20.
- 8 routes under `/api/v1/admin/cms` (reads: schedules, page suggestions, suggestion detail; `password.recent`: create/cancel schedule, request/apply/reject suggestion). Inventory now 334 routes.

Executed evidence:
- CmsScheduleAndSuggestionTest 9 PASS; all CMS tests 19 PASS (244 assertions).
- Full SQLite: 921 tests, 898 passed + 23 skipped, 12912 assertions.
- Full MySQL 8: 921 tests, 919 passed + 2 skipped, 12996 assertions, 343.3s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.
- Real local HTTP server capture (23 requests, model upstream faked): `docs/cms/B29_CMS_SCHEDULE_AI_HTTP_CAPTURE.json`; examples in OpenAPI and README. Some 409/422/429/503 examples are illustrative.

Main local `shopbonus` DB: migrations 2026_10_29_100000–140000 NOT applied there.

Not done: Hassan's UI (schedule picker, suggestion diff/apply); senior sets `CMS_AI_*` and verifies a real model response. Exact next: #37 Quality Center backend (B30).

---
# Active handoff — #22 B28 backend release slice (B18); card not Done (2026-10-29, Claude)

Branch `main`. Base `8352e08` (B27, [CI 40880825276](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40880825276) SUCCESS on all 3 jobs). Owner: "do all you can do" on #22; frontend testing goes to Hassan. D-089 unchanged. Credentials senior-owned.

B28 (closes the backend part of B18):
- `tests/Feature/Journey/BusinessJourneyTest.php`: card #22 steps 1–17 through the real API with cross-module integrity assertions. Only DNS, mail, Answer24, Mollie and the affiliate report are synthetic. A Mollie top-up funds rewards (an offer can't activate without funding).
- `tests/Feature/Api/RoleAccessMatrixTest.php`: every `/api/v1` route as anonymous/suspended/unverified/consumer/owner/manager/employee/Super Admin (±2FA), asserting exact denial codes and exact per-role permissions. No defect found.
- `App\Http\Middleware\SecurityHeaders` (global, appended): nosniff, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, HSTS on HTTPS (`shopbonus.security.hsts_max_age`, env `HSTS_MAX_AGE`, default 1 year). Existing controller headers are kept. Proxy trust via `shopbonus.security.trusted_proxies` (`TRUSTED_PROXIES`), applied in `AppServiceProvider::boot` with `TrustProxies::at`; default off, so behaviour is unchanged.
- `shopbonus:launch-check [--json]` (25 checks, no values printed; FAIL exits 1).
- `docs/release/API_INVENTORY.md` (334 routes, from `shopbonus:api-routes`) and `docs/release/LAUNCH_READINESS.md` (evidence map, deploy/rollback runbook, open items).
- `composer audit`: 0 advisories/abandoned (PHP 8.4.17, Laravel 13.38.0).
- No migration, no new route. `.env.example` gains TRUSTED_PROXIES and HSTS_MAX_AGE.

Executed evidence:
- BusinessJourneyTest 1 PASS (95 assertions); RoleAccessMatrixTest 4 PASS (1637 assertions); SecurityHeadersTest 2 PASS; LaunchCheckTest 2 PASS.
- Full SQLite: 912 tests, 889 passed + 23 skipped, 12649 assertions.
- Full MySQL 8: 912 tests, 910 passed + 2 skipped, 12734 assertions, 339.0s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.

Main local `shopbonus` DB: migrations 2026_10_29_100000–130000 NOT applied there.

#22 remains not Done: frontend/design QA (Hassan); live store/Mollie/Answer24/affiliate runs and a staging load test (senior); owner/legal decisions (affiliate credit timing D-089, Answer24 lawful basis, live Mollie); deployment/infrastructure (TLS, TRUSTED_PROXIES, off-site backups, mail DNS, alerting). All of Frank's backend cards (#1, #22, #23, #32) now have their buildable backend delivered; the next work is staging verification with real credentials.

---
# Active handoff — #32 B27 remaining native catalog/coupon readers; #32 buildable backend complete, card not Done (2026-10-29, Claude)

Branch `main`. Base `5816192` (B26, [CI 40875885780](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40875885780) SUCCESS on all 3 jobs). Owner: "do it all" for #32. D-089 unchanged. Credentials senior-owned.

B27 adds `CatalogReader` to every remaining connector that has a native read transport, each mapping checked against the official reference or core source:
- BigCommerce: `GET /v3/catalog/products?include=primary_image` (meta.pagination), `GET /v2/coupons` (page/limit, empty page 204), currency from `/v2/store`. Types percentage_discount/per_total_discount/per_item_discount/free_shipping; shipping_discount/promotion skipped.
- Ecwid: `/products`, `/discount_coupons` (offset/limit/total), `profile.formatsAndUnits.currency`. ABS/PERCENT/SHIPPING and *_AND_SHIPPING.
- Magento 2.4 (admin-schema-2.4.8): `products` (total_count), `salesRules/search` coupon_type SPECIFIC_COUPON + primary `coupons/search`; by_percent/by_fixed/cart_fixed; buy_x_get_y and auto-generated codes skipped; to_date → end of day in store timezone; currency from storeConfigs.
- PrestaShop 9: `products`, `cart_rules` (limit offset,100), currency via `configurations` PS_CURRENCY_DEFAULT → `currencies/{id}`. Price stored as returned (tax basis not documented). Codeless rules skipped.
- Shopware 6: `search/product` (main products visible in the bound sales channel) and `search/promotion` (sales channel, discounts association); system currency `b7d2554b…`. Individual codes, multiple/set-group/fixed-price discounts skipped.
- Wix: Catalog version via `/stores/v3/provision/version`; V3 `/stores/v3/products/query` (cursor) or V1 `/stores-reader/v1/products/query` (offset); coupons `/stores/v2/coupons/query`; currency `/site-properties/v4/properties`. Fixed-price and buyXGetY skipped.
- `PinnedJsonReader::read(..., allowEmpty: true)` returns an empty list for 204. Shared helpers moved to `CatalogValues` (row defaults, enum, exact percent, ISO/epoch dates).
- No migration, no new route.

Executed evidence:
- PlatformCatalogTest 6 PASS (one products + coupons pass per platform, unsupported shape skipped, encrypted codes, no write methods).
- Full SQLite: 903 tests, 880 passed + 23 skipped, 10898 assertions.
- Full MySQL 8: 903 tests, 901 passed + 2 skipped, 10980 assertions, 331.0s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.
- Real local HTTP server capture with synthetic official-schema fixtures: docs/integrations/B27_PLATFORM_CATALOG_HTTP_CAPTURE.json, OpenAPI examples, README.

Main local `shopbonus` DB: migrations 2026_10_29_100000–130000 NOT applied there.

Whole #32 remains not Done (external/out of scope): real installed stores and live evidence for all ten integrations (senior credentials); Lightspeed C/E, CCV, OpenCart, Squarespace, Mijnwebwinkel have no native read connector (no verified contract/credentials) and report UNSUPPORTED; storefront banner publication is merchant-side plugin/app scope. Exact next: #22 (release QA, mostly external), or live verification on staging when the senior configures store credentials.

---
# Active handoff — #32 B26 Shopify catalog/discount reader; whole #32 not Done (2026-10-29, Claude)

Branch `main`. Base `9d5281d` (B25, [CI 40851533268](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40851533268) SUCCESS on all 3 jobs). Owner: #1 backend done → moved to frontend (Hassan); continue #32 then #22. D-089 unchanged. Credentials senior-owned.

B26:
- `CatalogReader::catalogPage(..., ?string $cursor, ...)` now returns `{items, next}` (cursor-based). WooCommerce uses the REST page number as cursor. `CatalogSynchronizer` follows `next`, refuses a repeated cursor (`provider_pagination_invalid`).
- `ShopifyApi::catalog()` / `shopCurrency()`: fixed Admin GraphQL 2026-10 queries `ShopbonusCatalogProducts` (products first 50, read_products), `ShopbonusCatalogDiscounts` (discountNodes first 25, `method:code`, read_discounts), `ShopbonusCurrency`; identity/domain gate first (`identity()` accepts a null scope for currency only).
- `ShopifyCatalog` mappers (fields verified on shopify.dev 2026-10 reference; deprecated codeDiscountNodes/customerSelection/featuredImage avoided). Single-code DiscountCodeBasic/FreeShipping → coupon; BXGY/app/bulk skipped. Percentage parsed from the exact decimal string the pinned reader returns (0.00–1.00). Discount targets first 10 (`*_partial` keys when more).
- Shared `CatalogValues` trait (extracted from WooCommerceCatalog). `ConnectionCoupon::isActive` accepts Shopify `active`.
- No migration, no new route.

Executed evidence:
- ShopifyCatalogTest 3 PASS; CatalogSyncTest 4 PASS; Shopify suites PASS.
- Full SQLite: 897 tests, 874 passed + 23 skipped, 10798 assertions.
- Full MySQL 8: 897 tests, 895 passed + 2 skipped, 10881 assertions, 322.5s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.
- Real local HTTP server capture with synthetic Shopify GraphQL stubs: docs/integrations/B26_SHOPIFY_CATALOG_HTTP_CAPTURE.json, OpenAPI examples, README, SHOPIFY_READ_API.md.

Main local `shopbonus` DB: migrations 2026_10_29_100000–130000 NOT applied there.

Whole #32 remains not Done: catalog/coupon readers for Shopware, Wix, Magento, BigCommerce, Lightspeed, CCV, Ecwid, PrestaShop (only from verified official schemas); real installed stores for all ten integrations; storefront banner publication; live lifecycle evidence. Exact next: Shopware 6 Admin API products/promotions reader (verify the official Admin API schema first), then Wix Stores products + coupons, then #22.

---
# Active handoff — #1 B25 catalog/coupon sync + capability report; #1 backend slice complete, whole card not Done (2026-10-29, Claude)

Branch `main`. Base `425571b` (B24, [CI 40847704631](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40847704631) SUCCESS on all 3 jobs). Owner request: continue remaining Frank cards (#1, #22, #32) easiest first; #23 backend done and moved to frontend (Hassan) by the owner. D-089 unchanged. Credentials senior-owned.

B25 adds the missing #1 backend pieces (catalog/coupon sync, per-connection capability report):
- Migration `2026_10_29_130000`: `catalog_syncs`, `connection_products`, `connection_coupons` (coupon `code` encrypted cast; email restrictions stored only as boolean `customer_restricted`).
- `App\Integrations\Contracts\CatalogReader`; `WooCommerceConnector` implements it via `WooCommerceCatalog` mappers over official REST v3 `products`, `coupons` and `data/currencies/current` (added to the `WooCommerceApi` allowlist), using the existing pinned GET-only transport. `CATALOG_SYNC_MAX_PAGES` (default 50) × 100 per page.
- `App\Services\Catalog\CatalogSynchronizer`: `start()` (409 `capability_unsupported` / `connection_disconnected` / `catalog_sync_running`), queued `SyncConnectionCatalog` job runs `run()`: cache lock, generation check, each page stored under the connection row lock only at the starting credential generation, unseen items marked `removed_at` only after a complete pass; page cap → `partial` + `catalog_page_limit`. Audits `integration.catalog_sync_requested/finished`.
- `ConnectionCapabilities::report()`: orders, payments, refunds, products, categories, coupons_read, coupons_write (always UNSUPPORTED), banner_embed (LIMITED, manual embed), webhooks, backfill — AVAILABLE only on evidence.
- Routes under `workspaces/{workspace}/connections/{connection}/`: `GET capabilities`, `GET products`, `GET catalog-syncs` (integrations.view); `GET coupons`, `POST catalog-syncs` (integrations.manage, commerce-probe throttle).

Executed evidence:
- CatalogSyncTest 4 tests PASS (sync + encrypted codes + no PII + GET-only, removal on complete pass but not partial, generation-change discard, 422/409/403/404 isolation).
- Full SQLite: 894 tests, 871 passed + 23 skipped, 10740 assertions.
- Full MySQL 8: 894 tests, 892 passed + 2 skipped, 10824 assertions, 326.1s.
- Pint PASS; PHPStan 0 errors; OpenApiCoverageTest PASS.
- Real local HTTP server captures (synthetic WooCommerce stubs, isolated SQLite): 401, 200, 422, 202, 404, 403, 409 in docs/integrations/B25_CATALOG_HTTP_CAPTURE.json, OpenAPI and docs/api/README.md.

Main local `shopbonus` DB: migrations 2026_10_29_100000, 110000, 120000, 130000 are NOT applied there.

Whole #1 remains not Done: live provider connections/orders, catalog/coupon readers for Shopify/Shopware/Wix/Lightspeed/Magento/CCV (#32), native storefront banner publication, AI Banner Studio, Order Sheet/catalog/coupon frontend and real Answer24 E2E. Exact next: #32 per-provider line-item/catalog/coupon mappings from official schemas (start with Shopify GraphQL products/discounts as a second `CatalogReader`), then #22 (release QA, mostly external).

---
# Active handoff — #23 B24 "Code does not work" screenshot evidence; #23 backend slice complete, whole card not Done (2026-10-29, Claude)

Branch `main`. Base `737219f` (B23o + CI evidence). Owner request: continue all remaining Frank cards (#1, #22, #23, #32) easiest first. #31 was moved to Testing by the owner. D-089 unchanged. Credentials senior-owned.

Audit of #23 against the code: every backend requirement already existed (12-month lots, oldest-expiry-first spend, one voucher model with Answer24 handoff and wallet/Mollie purchase, one-time compensation, workspace isolation, signed webhooks, audit chain) except the screenshot on the "Code Does Not Work" form. B24 adds it:
- `POST /api/v1/consumer/disputes` accepts optional multipart `screenshots[]` (max 3, JPEG/PNG/WebP, 5 MB, content-sniffed and must decode with 16–8000 px dimensions).
- Files are encrypted (`Crypt`) under `dispute-evidence/{dispute}/{uuid}.enc` before the row is created; a duplicate-report 409 or any failure removes them. Metadata in new `voucher_disputes.evidence` JSON (migration `2026_10_29_120000`).
- New `GET /api/v1/admin/disputes/{dispute}/evidence/{file}`: Super Admin only, audited `admin.dispute_evidence_downloaded`, attachment + nosniff + no-store. Merchant view exposes only a screenshot count.
- Not implemented: AI analysis of screenshots (card says "may"); replacement stays an admin decision applied at most once (existing).

Executed evidence:
- VoucherDisputeTest 6 tests PASS (new test: disguised file 422 with no stored file, encrypted at rest, orphan-free 409, consumer 403, merchant count only, admin byte-identical audited download, unknown/traversal 404).
- Full SQLite: 890 tests, 867 passed + 23 skipped, 10619 assertions.
- Full MySQL 8: 890 tests, 888 passed + 2 skipped, 10702 assertions, 319.8s.
- Pint --test PASS; PHPStan L6 0 errors; OpenApiCoverageTest PASS.
- Actual HTTP captures (php artisan serve, isolated SQLite, real session login + TOTP challenge): 422, 201, 403, 200 (byte-identical), 404, 401 in docs/support/B24_DISPUTE_SCREENSHOT_HTTP_CAPTURE.json, OpenAPI and README.

Main local `shopbonus` DB: migrations 2026_10_29_100000, 110000 and 120000 are NOT applied there.

Whole #23 remains not Done: real WooCommerce/second-platform/Mollie/Answer24 runs, frontend audit, backups and production evidence are external. Exact next (easiest first): #1 remaining backend gaps (catalog/coupon sync API, capability report per connection), then #32 per-provider line/catalog/coupon mappings from official schemas, then #22 (release QA, mostly external).

---
# Active handoff — #31 B23o Wix webhook envelope fix + AppRemoved, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `daecc72` (B23n, [CI 40842608297](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40842608297) SUCCESS on all 3 jobs). Session commits: `f23f3a9` B23i/j, `c478831` B23k, `0274a77` B23l, `9f0d7fa` B23m, `daecc72` B23n; all CI green. D-089 unchanged. Credentials senior-owned.

B23o fixes a real defect from B23e–h: `WixConnector::signedData` required the JWT `data` claim to be an object, but the official `@wix/sdk` parseJWT shows it is a JSON string (inner `data` also a JSON string). Every genuine Wix webhook would have been rejected (401 `store_mismatch`). The connector now parses the string envelope only, and the test fixture uses it. `AppRemoved` → `KIND_DISCONNECT`, processed by `ShopifyUninstall` (now Shopify + Wix): native Get App Instance outside locks, disconnect only on a definite 401/403, replay with a valid grant ignored. `AppInstalled` is stored without effect. App-instance events dedupe on a content hash (no event id). No migration, no endpoint or response change.

Executed evidence for this commit's tree:
- WixConnectorTest 2/9 and WixAppRemovedTest 3/13 PASS; Integrations + Api folders 162 tests PASS.
- Full SQLite: 889 tests, 866 passed + 23 skipped, 10584 assertions, 68.5s.
- Full MySQL 8: 889 tests, 887 passed + 2 skipped, 10666 assertions, 311.9s.
- Pint --test PASS; PHPStan L6 0 errors.
- CI for f14afc8: [run 40843782709](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40843782709) SUCCESS on all 3 jobs (SQLite, MySQL, lint/static analysis).

Main local `shopbonus` DB: migrations 2026_10_29_100000 (B23k) and 110000 (B23m) are NOT applied there.

Exact next (remaining #31 Laravel gaps): remaining provider line/catalog/coupon/payment/refund mappings, per provider and only from official schemas (Wix lineItems from the Order object; BigCommerce products; Shopware lineItems; Magento items). Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). Live provider verification for every platform is NOT RUN. The B23n handoff is below.

---
# Active handoff — #31 B23n Shopware app webhooks, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `9f0d7fa` (B23m, [CI 40841263271](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40841263271) SUCCESS on all 3 jobs). Session commits: `f23f3a9` B23i/j, `c478831` B23k, `0274a77` B23l, `9f0d7fa` B23m; all CI green. D-089 unchanged. Credentials senior-owned.

B23n: `ShopwareAppWebhookController` at public `POST /api/v1/integrations/shopware/app/webhook` (limiter `webhooks-shopware-app` 600/min per IP). It verifies the shop-secret signature via `ShopwareAppRegistration::signedByShop` (previous secret within 60s of rotation), requires a confirmed `source.shopId` and a ±600s timestamp, and dedupes on eventId. `order.written` stores one derived `order_lookup` WebhookEvent per order ID (max 25) on the bound, generation-current connection; `ShopwareConnector` now implements `OrderNotification`, reading only that derived hint. `app.deleted` → `ShopwareAppRegistration::revoke` (unbind, strip credentials, generation bump; delete the shop unless keepUserData). Activation/deactivation are audited; other events are ignored. No migration.

Executed evidence for this commit's tree:
- ShopwareAppWebhookTest: 5 tests, 58 assertions; with ShopwareAppRegistrationTest 12/118 PASS. OpenApiCoverageTest PASS.
- Full SQLite: 886 tests, 863 passed + 23 skipped, 10569 assertions, 61.3s.
- Full MySQL 8: 886 tests, 884 passed + 2 skipped, 10654 assertions, 305.6s.
- Pint --test PASS; PHPStan L6 0 errors.
- Actual HTTP captures: docs/integrations/B23_SHOPWARE_APP_WEBHOOK_HTTP_CAPTURE.json, OpenAPI, README (database queue; no outbound reads).
- CI for daecc72: run 40842608297 SUCCESS on all 3 jobs.

Main local `shopbonus` DB: migrations 2026_10_29_100000 (B23k) and 110000 (B23m) are NOT applied there; run `php artisan migrate` when the senior is ready.

Exact next (remaining #31 Laravel gaps): Wix App Instance Installed/Removed webhooks (verify the current Wix contract and its app-level JWT payload first); remaining provider line/catalog/coupon/payment/refund mappings (per provider, only from official schemas); Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). Live provider verification for every platform remains NOT RUN. The B23m handoff is below.

---
# Active handoff — #31 B23m Shopware app registration, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `0274a77` (B23l, [CI 40839539332](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40839539332) SUCCESS on all 3 jobs). Session commits so far: `f23f3a9` B23i/j, `c478831` B23k, `0274a77` B23l; all CI green. D-089 unchanged. Credentials senior-owned.

B23m: `App\Integrations\ShopwareAppRegistration`, `ShopwareAppController`, model `ShopwareAppShop`, public routes `GET /api/v1/integrations/shopware/app/registration` and `POST …/registration/confirm` (limiter `shopware-app` 60/min per IP). Opt-in `SHOPWARE_APP_ENABLED/NAME/SECRET`. The signature/proof/rotation algorithms match the official shopware/app-php-sdk source (downloaded and read on 2026-10-29); re-registration always requires the dual signature. Shopware connections accept `app_registration: true` (+ `store_url`, `sales_channel_id`; client credentials prohibited). The confirmed handshake's Admin API credentials are bound into exactly one such connection on the verified domain, whichever comes first (`ConnectionManager::create/replaceCredentials` call `attach()`), bumping the generation and resetting readiness. Lock order: connections, then the app shop row. Details: docs/integrations/REMAINING_BACKEND.md.

Migration: `2026_10_29_110000_create_shopware_app_shops` (new table, FK to webshop_connections nullOnDelete). Applied only by test suites and the isolated capture DB; **not to the main local `shopbonus` DB or production.** The B23k migration `2026_10_29_100000` is likewise not applied to the main DB.

Executed evidence for this commit's tree:
- ShopwareAppRegistrationTest: 7 tests, 60 assertions PASS. Integrations folder plus OpenApiCoverageTest PASS.
- Full SQLite: 881 tests, 858 passed + 23 skipped, 10505 assertions, 61.3s.
- Full MySQL 8: 881 tests, 879 passed + 2 skipped, 10585 assertions, 300.3s.
- Pint --test PASS; PHPStan L6 0 errors.
- Actual HTTP captures: docs/integrations/B23_SHOPWARE_APP_HTTP_CAPTURE.json, OpenAPI, README (proof re-derived with openssl and matched).
- CI for 9f0d7fa: run 40841263271 SUCCESS on all 3 jobs.

Exact next (remaining #31 Laravel gaps): (1) Shopware app webhooks: app-level endpoint, `shopware-shop-signature` with the current secret (previous within 60s of `secrets_rotated_at`), route by `source.shopId` to the bound connection into the existing WebhookEvent pipeline, `app.deleted`/`app.deactivated` lifecycle → revoke the binding and credentials; (2) Wix App Instance Installed/Removed webhooks; (3) remaining provider line/catalog/coupon/payment/refund mappings; (4) Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). The B23l handoff is below.

---
# Active handoff — #31 B23l Wix app-instance tokens, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `c478831` (B23k, [CI 40838253435](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40838253435) SUCCESS on all 3 jobs). Earlier `f23f3a9` CI 40836390758 also passed all 3. D-089 unchanged. Credentials senior-owned.

B23l: new `App\Integrations\WixAppTokens`; opt-in `WIX_APP_ENABLED/WIX_APP_ID/WIX_APP_SECRET` (`config('integrations.wix_app')`). With app mode on, Wix connect/replace prohibits `access_token` and stores `wix_app_id`. `WixConnector::read` gets its Authorization from `WixAppTokens::accessToken()`: a manual token when not app-bound, otherwise a token minted via `POST https://www.wixapis.com/oauth2/token` (client_credentials, 4h). It re-mints when less than 5 minutes are left, under a cache lock and transaction guard, stores the token encrypted with `access_token_expires_at`, and audits it. `WebshopConnection::hasSameCredentialAuthority` treats app-bound Wix renewals as the same authority (same app ID, instance, site, public key). Errors: `wix_app_changed` 409, `wix_app_not_configured` 503, `provider_response_invalid`/`provider_authentication_failed` with credentials untouched. No migration, no new endpoint; OpenAPI create/replace descriptions updated.

Executed evidence for this commit's tree:
- WixAppTokensTest: 5 tests, 22 assertions PASS. WixDiscoveryTest still passes.
- Full SQLite: 874 tests, 851 passed + 23 skipped, 10422 assertions, 64.0s.
- Full MySQL 8: 874 tests, 872 passed + 2 skipped, 10505 assertions, 307.5s.
- Pint --test PASS; PHPStan L6 0 errors.
- CI for 0274a77: run 40839539332 SUCCESS on all 3 jobs.

Exact next (remaining #31 Laravel gaps): Shopware 6 native app registration handshake/signatures/lifecycle (verify the current official app-system docs: registration GET with shopware-app-signature, confirmation POST, app-secret HMAC on webhooks and action buttons). Then Wix App Instance Installed/Removed webhooks; remaining provider line/catalog/coupon/payment/refund mappings; Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). The B23k handoff is below.

---
# Active handoff — #31 B23k Shopify privacy compliance intake, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `f23f3a96d4199ccf0a7aa75b62881d96d3645ea4` (B23i/B23j, [CI 40836390758](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40836390758) SUCCESS on all 3 jobs: SQLite, MySQL, lint/static). This commit adds B23k. D-089 is unchanged. Credentials are senior-owned.

B23k: public `POST /api/v1/integrations/shopify/compliance` (route `api.v1.integrations.shopify.compliance`, limiter `shopify-compliance` 300/min per IP) for `customers/data_request`, `customers/redact` and `shop/redact`. Order of checks: app client secret configured (else 503 `shopify_app_not_configured`), body ≤1 MiB (else 413), base64 HMAC-SHA256 of the raw body with `SHOPIFY_CLIENT_SECRET` (else 401 `webhook_rejected`/`signature_invalid`), topic is one of the three and webhook ID is present (else 401 `invalid_event_headers`). The request is stored once per `X-Shopify-Webhook-Id` in the new `shopify_compliance_requests` table (encrypted raw body, payload hash, event ID, topic, shop domain/ID when the shape is valid, `payload_valid`, status `pending`, `due_at` = received + 30 days). Redelivery returns 200 `duplicate:true` and increments `delivery_count`. An authentic body that doesn't fit its topic is kept with `payload_valid=false`. Audit events record receipt, resolution and rate-limited rejections, with no customer data. Operator command `shopbonus:shopify-compliance` lists pending requests (with the matching Shopify connection count and an overdue flag) and `--resolve=<ID> --outcome=completed|retained` records the outcome once.

**Nothing is erased or exported automatically.** See D-099 and OPEN-SHOPIFY-PRIVACY-EXECUTION. Legal/owner must decide which fields may be erased before this is automated, and someone must work the queue within 30 days. The senior must add the URI under `compliance_topics` in `shopify.app.toml`.

Migration: `2026_10_29_100000_create_shopify_compliance_requests` (additive, new table only). Applied by the test suites and the isolated capture DB only. **Not applied to the main local `shopbonus` DB or production.**

Executed evidence for this commit's tree:
- ShopifyComplianceTest: 7 tests, 46 assertions PASS. OpenApiCoverageTest PASS.
- Full SQLite: 869 tests, 846 passed + 23 skipped, 10401 assertions, 56.8s.
- Full MySQL 8: 869 tests, 867 passed + 2 skipped, 10483 assertions, 520.1s.
- Pint --test PASS; PHPStan L6 0 errors.
- Actual HTTP captures (php artisan serve, isolated SQLite, openssl-signed synthetic bodies): 200 for each of the three topics, duplicate 200, 401, 413, 503. Stored in docs/integrations/B23_COMPLIANCE_HTTP_CAPTURE.json, docs/api/openapi.yaml and docs/api/README.md. Rate-limit headers omitted (array cache). No live Shopify store or App Store review.
- CI for c478831: run 40838253435 SUCCESS on all 3 jobs.

Limitations: no dual-secret window during client-secret rotation (Shopify can take up to 1h to switch digests; its 8 retries over 4h should cover that). `X-Shopify-Shop-Domain` is not trusted; the signed body's `shop_domain` is used.

Exact next (remaining #31 Laravel gaps, in order): Wix app token install/renewal (verify the current official Wix OAuth/app-instance token contract first); Shopware native app registration handshake/signatures/lifecycle; remaining provider line/catalog/coupon/payment/refund mappings; Lightspeed C only once its current official contract is verified. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION. Previous B23i/B23j handoff is below.

---
# Active handoff — #31 B23i/B23j committed checkpoint, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`, base `8f76727b9ee9463963c89da9fd8f0b4bb6e97531`. This commit finishes the Codex WIP for B23i (Shopify webhook provisioning) and B23j (Wix durable order discovery); nothing was reset or rebuilt. No new migration, public route, package or live provider action. Entire #31 IN_PROGRESS. D-089 affiliate timing unchanged. Credentials senior-owned.

Review fixes made by Claude on top of the WIP:
- **Wix pagination bug (fixed):** the WIP sent filter+sort together with `cursorPaging.cursor` on page 2+. The official Search Orders doc states "`cursor` can not be used together with `filter` or `sort`" and its 2nd-request example sends only `cursorPaging`. Continuation pages now send only `{cursorPaging:{limit:25,cursor}}`; every returned row is still status/window/UUID validated. Test asserts both request shapes.
- Wix cursor maxLength is 16000 natively; the existing journal column is 2048 chars, so a longer cursor fails closed (`provider_pagination_invalid`) rather than truncating. The doc example cursor for a comparable filter is ~720 chars. Widening the column needs a migration and was not done.
- Command `shopbonus:provision-shopify-webhooks` now prints an error for an unknown connection; added tests for the command (invalid ULID/topic/review flags → exit 2, sync queue without --inline refused, inline create, async 7-job dispatch, disabled refusal) and for a review ID with no outstanding intent (`webhook_intent_review_invalid`, no mutation).
- Reviewed and kept: after-commit OAuth dispatch (audited failure never relabels the grant), durable create intent before mutation with list-first recovery, generation/authority/store rechecks under row lock before intent and observation, cache lock owner-safe release, dedicated committing OAuth fixture resets `RefreshDatabaseState` (verified under both drivers with RefreshDatabase tests running after it).

Executed evidence (this machine, exact working tree committed here):
- Focused SQLite: ShopifyWebhookSetupTest + WixDiscoveryTest 13 tests/56 assertions PASS; with ShopifyOAuthProvisionDispatchTest + BulkSynchronizationTest 20 tests PASS.
- Focused MySQL 8 (`shopbonus_testing`): same 4 files 20 tests/83 assertions PASS/44.0s.
- Full SQLite: 862 tests, 839 passed + 23 skipped, 10343 assertions, 61.1s.
- Full MySQL: 862 tests, 860 passed + 2 skipped, 10426 assertions, 301.9s.
- Pint --test PASS; PHPStan L6 0 errors. OpenAPI coverage test included in full suites (no endpoint change, no new HTTP captures needed).
- CI for f23f3a9: run 40836390758 SUCCESS on all 3 jobs (SQLite, MySQL, lint/static analysis).

Known limitations (documented in docs/integrations/REMAINING_BACKEND.md): Wix Search never returns INITIALIZED orders (webhook/Get Order only); Shopify provisioning not verified against a live store and does not inspect TOML-declared app subscriptions; `--review-intent` must only follow manual confirmation in Shopify. Historical reconciliation migration down-index failure observed under DatabaseMigrations teardown remains unfixed and unrelated; do not claim historical rollback works.

Exact next: Shopify mandatory privacy compliance webhooks (customers/data_request, customers/redact, shop/redact). These are app-level (single configured URI, HMAC with app client secret, shop/redact ~48h after uninstall when the connection may be disconnected), so they need a new public route + OpenAPI entry with real captured examples per AGENTS rule 11, and mapping onto existing PrivacyRequest/retention rules (D-013) without deleting accounting evidence. Then Wix app token install/renewal, Shopware native app lifecycle/signatures, remaining provider line/catalog/coupon/payment/refund mappings, Lightspeed C only with a verified current official contract.

---

# Active handoff — #31 B23e–h backend checkpoint, whole card IN_PROGRESS (2026-10-28)

Branch `main`, base `78642dedf9ccfd02b544f7460f85ab989b189577`. Application `74608718c15afd96a045c5ee2017a85556548381` and timestamp follow-up `f90860ff5f91d720285b3978f352812190f27a18` committed/pushed. [Final CI40778138872](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40778138872) SUCCESS all3jobs for exactf90860f: quality/audit, SQLite824pass+23skip/10278assertions/52.29s and MySQL845pass+2skip/10361assertions/218.01s (847tests/database). Prior7460871 CI40777263930 also all3SUCCESS/846each. This is a docs-only closeout; no uncommitted application/test changes. Timestamp follow-up rejects ambiguous PrestaShop DST times and leaves Wix payment/refund record-created timestamps null, preserving authoritative current status/amount without inventing settlement-time proof. No pre-existing user changes overwritten. Owner requests all main code and focused testing. **OAuth/bulk/Woo+Shopify line boundary implemented; remaining provider/lifecycle/catalog gaps are still PARTIAL, not whole-card Done.** D-089/D-098 apply.

Delivered disabled-by-default browser-bound Shopify OAuth/expiring offline pair renewal, immediate invalidation on changed app authority, same-grant generation continuity, and signed uninstall with native revocation proof outside locks (valid current grant survives old uninstall replay). Durable fixed-window page journal/leases/UUID-bound native recovery, separate reconciliation vs verification purpose and opt-in allowlisted automatic scans. Added authenticated native read boundaries for Wix, BigCommerce, Ecwid, PrestaShop9, Shopware6 and Magento2.4 self-hosted/PaaS through existing connectors/pinned transport/canonical ingestion. ID notifications never supply payment values. Exact JSON decimal lexemes and bounded encrypted immutable Woo/Shopify line snapshots; existing order list/show additive line_items field. No provider/financial policy/mail activation, package change, storefront/plugin implementation or production deploy.

Executed local full SQLite842total819pass+23skip/10247assertions/57.509s, then MySQL843total841pass+2skip/10344assertions/269.076s sequentially. One native binding test was added between driver runs; final config-rotation/uninstall changes additionally verified with latest MySQL integrations90tests609assertions/14.502s, SQLite lifecycle23tests139assertions/1.503s, contract3tests3720assertions/1.003s. Pint/PHPStanL6/composer validate/audit (0advisories)/diff PASS. No repeated local full suites per edit. Final exactf90860f CI40778138872 passed all3jobs/847tests each; no additional local broad rerun for this docs-only closeout.17 actual running-server captures: normal login/CSRF/file sessions, isolated SQLite, explicitly synthetic provider HTTP/DNS, successful auth/exchange/replay rejection and401/409/419/422/429/503, current catalogue/order snapshots and uninstall revocation. Generated secrets/state/code/signatures redacted. Common403/500 envelopes reuse prior real-kernel examples; provider-live/multi-process acceptance NOT RUN.

Migrations180000/190000/200000 applied successfully to local main MySQL. Isolated SQLite exact latest3 down/up PASS, canonical order retained, removed line evidence remains null on reapply; MySQL full suite exercises schema creation. No main/production rollback. Quiesce old workers/intake for rollout/rollback and preserve encrypted source history/keys. Main DB and private B21 fixture credentials preserved; approximately36GiB free. Task capture helper/server/temp DB/sessions and downloaded task source HTML removed; no shared Redis flush or unrelated cleanup.

Exact next: normal docs-closeout commit/push/check clean; then continue remaining **Laravel** gaps listed in docs/integrations/REMAINING_BACKEND.md: Shopify privacy callbacks/automatic hook registration, Wix token install/renewal/backfill, Shopware native app registration/signatures/lifecycle, full native catalog/coupon/detailed line/capture/refund mappings. Lightspeed C current official contract/plan/API verification is unresolved; do not invent it. OpenCart merchant extension and provider storefront/app activation are outside Laravel scope. Senior configures real credentials locally; no credentials needed in chat. Whole #31 cannot be marked Done or represented as backend-complete for Hassan yet.

---

# Active handoff — #31 B23d verified, whole card IN_PROGRESS (2026-10-28)

Branch main. Application `27faa2f73b7ea97cfdb31a86ae2d73a56bdef386` and byte-exact follow-up `03e69a9c41b384f7b2b34de4401ebfe63a02445a` committed/pushed. [Final CI40768013858](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40768013858) SUCCESS all3jobs for exact03e69a9: quality/audit, SQLite792pass+23skip/10127assertions/40.09s and MySQL813pass+2skip/10206assertions/166.45s (815tests/database). Main CI40767003950 also all3SUCCESS/814each. This is a docs-only closeout; no uncommitted application/test changes. **B23d DONE within seven-gate/targeted known-ID recovery scope; whole #31 IN_PROGRESS.** D-089/D-093–097 apply.

Delivered all-seven-gate Connected policy replacing complete-webhook promotion. Complete webhooks remain Limited; generation/domain/exact-store-bound authenticated native ingestion, mapping/payment proof, observed native dedupe and actual recovered later state/refund are required. Existing encrypted event and canonical order/refund/contact pipeline reused. Durable UUID requests, lease60s/worker55s/3claims, network outside locks, stale result checks, canonical payload dedupe, staged resumption and explicit failed processing. Operator-only shopbonus:recover-integration-order command and bounded existing-request scheduler drain; no new public recovery mutation or automatic store discovery. Passive test-api still never ingests/persists readiness/promotes. Verify adds quality_gates/missing_gates. Store switches cannot silently merge canonical namespaces, including case-distinct paths under MySQL collation; normal same-store rotation remains supported. Generic setup no longer implies a shipped module. Guide docs/integrations/READINESS_AND_RECOVERY.md.

Local post-MIME-patch full814tests/database PASS: SQLite791pass+23skip/10118assertions/122.057s, MySQL812pass+2skip/10201assertions/369.908s, sequential. Final byte-exact recovery14tests92assertions SQLite and14tests94assertions MySQL PASS, plus controlled MySQL case-folding proof1test7assertions; exact CI runs all815. API contract3tests3694assertions, Pint/PHPStan L6/composer validate/audit/diff PASS.20actual HTTP captures use real PHP server/isolated SQLite/database cache/file sessions/normal login+CSRF/actual operator+canonical worker, with synthetic provider HTTP/DNS; include all7 progression, passive probe, failure downgrade, rotation, source-switch409 and401/500. Seven current OpenAPI/README path blocks updated, keys/tokens redacted. Registry live verification stays unverified; no real-provider acceptance claim.

Migrations160000/170000 applied local main. Full-schema isolated SQLite/MySQL exact targets down/up twice PASS: legacy Connected downgraded, other limitations/encrypted payload retained, unknown provenance/store refs remain null, qualified status invalidated before proof removal. No main/production rollback/deploy. Quiesce old workers/intake and preserve staged evidence for rollout/rollback. Reapply never restores fictitious qualification. Only dependency change is symfony/mime8.1.7→8.1.10 for the current security advisory; official compatible patch, audit clean. Native recovery can update canonical orders/voucher matching/lawful contact plans under existing rules; it is an operator action, not a passive probe. No affiliate timing/financial/provider/mail setting change.

Exact next: normal docs closeout commit/push/check clean, then expand Shopify OAuth install/refresh checklist and review current official docs before code. Use senior-configured app credentials, existing encrypted connection lifecycle, validated OAuth state/scopes/store ownership and current-generation guards; no credentials needed in chat. Bulk durable missed-new-order discovery/cursors, detailed line-item mapping and remaining native provider adapters still require backend code. Genuine platform install/protected-data/storefront/banner/coupon acceptance NOT RUN. Full #31 not ready to mark Done/move remaining backend to Hassan.

Task capture servers/temp DB/sessions/proof schemas/helpers removed; generic /tmp logs retained. Main MySQL/private B21 fixtures preserved, approximately38GiB free. No shared Redis flush/unrelated cleanup/production deploy. Older pending B23d notes below are historical and superseded; B23c aa316ec CI40679706982 all3SUCCESS remains verified history.

---

# Active handoff — #31 B23c verified, whole card IN_PROGRESS (2026-10-28)

Branch main. Application `aa316ec741c94fa9dddf967c37bad3ea3990db12` committed/pushed; [CI40679706982](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40679706982) SUCCESS all3jobs for exact SHA: quality, SQLite778pass+23skip/10030assertions/49.40s and MySQL799pass+2skip/10113assertions/197.56s (801tests/database). This is a docs-only closeout, no uncommitted application/test changes. **B23c credential-lifecycle foundation DONE; whole #31 IN_PROGRESS.** D-089/D-093/D-094/D-095 apply.

Delivered internal credential generations on connections/accepted events, with explicit null legacy binding. Connection-first row locks serialize lifecycle/intake/processing and merchant/admin retries. Rotation clears readiness; disconnect removes credentials and prevents stale credential writers; queued stale/unbound proof ignored/audited before order/refund/contact effects. Intake rechecks credential snapshot/generation; rejection metadata generation-bound. Verification reloads/locks and filters current accepted/processed proof; probes reject generation changes. Provider dedupe and historical encrypted events/orders retained. No new routes/packages/financial/provider activation. Legacy complete-current-webhook Connected promotion remains compatibility until separate seven-gate replacement, not full-readiness proof.

Local focused69tests556assertions PASS SQLite/3.597s and MySQL/14.073s; full SQLite801total778pass+23skip/10029assertions/53.878s and MySQL801total799pass+2skip/10112assertions/244.766s PASS sequentially. Pint/PHPStan L6/composer audit/diff PASS; API contract3tests3693assertions PASS.16actual HTTP application captures use isolated SQLite/database cache/file sessions/normal login+CSRF+password+seeded synthetic OTP. MFA disabled only in capture fixture; deterministic intake-race hook explicitly synthetic, not claimed multi-process proof. Secrets/tokens redacted and readiness/stale/privacy checks verified.8existing OpenAPI/README paths updated. Guide docs/integrations/CREDENTIAL_LIFECYCLE.md.

Migration150000 isolated full-schema SQLite/MySQL exact target down/up PASS, legacy event binding stays null and encrypted payload preserved; applied local main successfully. No production/main rollback. Quiesce old intake/workers for deployment/rollback and preserve binding evidence; mixed old receivers omit generation and old workers lack guards. Never rebind old proof to new credentials. Recovery requires fresh authenticated provider data/new signed delivery, not replaying an old dedupe ID.

Exact next action: normal closeout docs commit/push/check clean, then expand next checklist for durable generation-bound seven-gate evidence and safe status promotion through existing webhook/audit/ingestion machinery, native missed-order/refund reconciliation, OAuth lifecycle, detailed line-item mapping and remaining providers. Existing ProcessWebhookEvent promotion/ConnectionManager checklist are not all seven #31 gates. No credentials needed from owner for coding; senior configures locally. Genuine provider/install/protected-data/storefront/banner/coupon acceptance NOT RUN; full card not ready to mark Done/move remaining backend to Hassan.

Task capture server/temp DB/sessions/proof schemas/helpers removed; generic /tmp result logs retained. Main MySQL/private B21 fixtures preserved, approximately47GiB free. No shared Redis flush/unrelated cleanup/credential/provider/mail/financial timing changes. Previous B23b295e8b5/test01666e1 and CI40677278061 remain verified all3SUCCESS (795tests/database).

---

# Active handoff — #31 B23b verified, whole card IN_PROGRESS (2026-10-28)

Branch main. Application `295e8b5d8a787d60c69150c20ef83982a30c69da` and test-fixture correction `01666e100a9e9d7f2da13d394dbc7ed179aa40a8` committed/pushed. [Final CI40677278061](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40677278061) SUCCESS all3jobs for exact01666e1: quality, SQLite772pass+23skip/9965assertions/42.25s and MySQL793pass+2skip/10046assertions/203.38s (795tests/database). Application CI40677077084 also SUCCESS all3jobs. This is a docs-only closeout; no uncommitted application/test changes. **B23b DONE within read-only scope; whole #31 IN_PROGRESS**, not ready for full-card completion/handoff.

Delivered optional encrypted Shopify approved app token, fixed2026-10 identity/scopes/order/transaction/refund/basic product reads, domain ownership/version checks and bounded cursor/transaction lookahead. Shared pinned JSON transport preserves Woo safety, Redis numeric-string cooldown and consecutive-failure resets. Gross total before returns + separate native refunds; successful transaction times only; protected fields scrubbed/missing, GraphQL schema/financial/version/throttle errors fail explicitly. Existing scoped/throttled/audited probe returns only PII-free summaries and never promotes status, ingests orders or posts financial/contact effects. Manual webhook compatibility retained. D-089/D-093/D-094 authoritative. No migrations/dependency upgrade/provider or credential activation; ext-curl now explicit Composer platform requirement.

Local final full SQLite795total772pass+23skip/9965assertions/53.934s and MySQL795total793pass+2skip/10049assertions/290.521s PASS sequentially. Corrected native27tests229assertions PASS SQLite/2.049s and MySQL/14.226s; one callback now isolates all three invalid-order cases after detecting Laravel accumulated fakes. Pint/PHPStan L6/composer validate/audit/platform/diff PASS.11actual HTTP captures use isolated SQLite/database cache/file sessions/normal login+CSRF and synthetic upstream/DNS. OpenAPI/README five existing paths updated; redaction/no PII/status/live-promotion checks PASS. Guide docs/integrations/SHOPIFY_READ_API.md. No further full rerun for this docs-only status closeout.

Exact next action: commit/push this closeout and confirm clean; start credential-generation-bound durable quality evidence and invalidation on rotation/disconnect, then safe seven-gate promotion using existing webhook/audit/ingestion architecture with stale/concurrent-job tests. Legacy ProcessWebhookEvent complete-order Connected logic and ConnectionManager checklist are compatibility behavior requiring that next boundary. OAuth install/refresh, durable missed-event/order/refund recovery, detailed line-item mapping and remaining native providers still require main code. Expand next checklist before implementation. No credentials needed from owner for coding; senior configures locally. Real provider/protected-data/OAuth/install/storefront/banner/coupon acceptance NOT RUN.

No schema apply/rollback needed. Task capture server/temp DB/sessions/helpers removed; generic /tmp logs retained. Private B21 fixtures and main MySQL preserved, approximately47GiB free. No shared Redis flush/unrelated cleanup/financial timing/provider/mail changes. B23a `161051e` CI40673100233 already SUCCESS all3jobs; older pending notes below are historical and superseded.

---

# Active work — #31 B23a WooCommerce native reads (2026-10-28)

Branch main, base fb81279. B23a task-owned application/tests/docs locally verified and ready for this implementation commit; no pre-existing edits overwritten. Owner asks to work on #31, not to move more backend to Hassan. Full #31 imported, #32 overlap noted. B23a extends existing WooCommerceConnector/registry/connection API, not a new order/ledger architecture. Native read-only order/refund/catalog and bounded modified-order pagination, optional encrypted credentials, ownership/public-DNS pin/TLS/no redirects or proxy, capped time/body/pages/circuit, scoped managed audit-only test endpoint implemented. No provider credentials, frontend/module/storefront implementation, migration/package or financial/spendability change. D-089/D-093 apply.

Evidence:15native API tests passed before final malformed-ID/audit assertions; existing adapters/webhook tests passed.20actual HTTP server captures in docs/integrations/B23_WOO_HTTP_CAPTURE.json include200/201/401/403/404/409/419/422/429/500/502/503, changed catalogue/create/show/credential and new test-api. Synthetic upstream/DNS only, array mail, actual normal browser auth/CSRF; credential/generated-webhook values redacted. OpenAPI/registry5tests3713assertions PASS after documentation. Initial pre-doc full run only failed missing-new-route/registry coverage; final full SQLite now running. MySQL/full CI final quality pending; no guessed passes.

Final local evidence: SQLite783total760pass+23skip/9880assertions/68.363s; MySQL783total781pass+2skip/9961assertions/239.303s PASS. Final Woo15tests144assertions both drivers after Redis numeric-string bypass reproduced/fixed; quality/contract/audit/redaction PASS. Exact next: commit/push/check B23a exact-code CI, then continue Shopify native read API and credential-bound quality gates per owner “continue”. No schema apply/rollback needed. Guide docs/integrations/WOO_READ_API.md documents current native read scope/official sources and remaining work. Whole #31 remains IN_PROGRESS, not done or ready to move backend to Hassan. Next main-code phase Shopify/native authenticated transport + runtime credential-bound seven-gate readiness/durable recovery, then other providers. Genuine store/module/banner tests remain NOT RUN, credentials senior-owned.

Temporary capture/docs helpers removed; temporary capture DB/server/private credentials already removed by harness. Generic /tmp/shopbonus-b23* logs retained. Main MySQL and private B21 fixture credentials preserved; no Redis flush/unrelated deletion, approximately47GiB free. #30 main code remains CI-verified and unchanged except shared existing registry/provider infrastructure extension for this new ticket.

---

# Active handoff — #30 main backend DONE, external acceptance pending (2026-10-28)

Branch main. Main implementation b2ad1561d0a26c6c75553dac32e71afe59e1f172, traversal guard64b1848aa0fc3aad61098b8ecc6dd9be550d49fa and isolated configuratione832ccabb05586f5abdb895391f4c3e440eab316 committed/pushed. This is a docs-only verified closeout; no uncommitted application/test changes remain. **B22/#30 MAIN BACKEND DONE; whole Trello card/deployed release still pending external acceptance.**

[Final CI40666899671](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40666899671) SUCCESS all three jobs for exact e832cca: quality, SQLite745pass+23skip/9723assertions/32.61s and MySQL766pass+2skip/9800assertions/142.10s (768tests each), all three real four-worker races PASS. Main/guard CI40665978721/40666345157 also SUCCESS. Local full767test suites plus final source/provider/recovery/API focused checks, Pint/PHPStan L6/composer validate/audit/diff/API coverage all PASS. Exact final CI includes the expanded768test suite and supersedes earlier pending notes below.

Delivered version2 durable source scans/watermarks/immutable findings while legacyv1 stays scoped; wallet/affiliate/historical rule/duplicate/orphan/reversal/purchase/top-up/sponsorship checks and existing authorized correction reuse. Five protected admin trigger/list/detail/resume/operations endpoints, fresh MFA/password/OTP/CSRF/rate/audit controls, timestamps/scheduling-inclusive elapsed time, bounded pagination/no list N+1. Default-queue ready wait, scheduler/dependency and opt-in allowlisted numeric HTTP provider observations plus existing incidents. Disabled authenticated encrypted DB/media backup/readback/retention/hourly and weekly isolated verification; separate MySQL connection/server/UUID guard and env-only mysql_restore configuration. Reproduced/fixed zero-approved-commission false pending state; no credit. Original immutable credit evidence now records exact unrecognized EUR split, never fabricated cash/revenue or points conversion.

D-089/D-091/D-092 apply: new rewards spendable after network approval, no policy/rate/provider/credential activation. No frontend, arbitrary balancing entry or customer communication. Source missing/legacy/independent settlement/offsite proof remains UNKNOWN rather than false healthy. Backups and HTTP/provider monitoring default disabled; normal main admin MFA unchanged. Senior configures independent backup key/destination and isolated restore DB/TLS environment values without sending credentials to owner/chat.

Evidence: docs/reliability/COMPLETION.md has configuration/commands/security matrix/ten local-backend-scenario mappings and external limitations; B22_COMPLETION_HTTP_CAPTURE.json19actual HTTPcalls including401/403/404/409/419/422/423/429/500 and current OpenAPI/README examples, isolated synthetic SQLite/array mail/seeded OTP and capture-only MFA disablement. Secret redaction verified. B22_MYSQL_RECOVERY_PROOF.json real two-server local MySQL encrypted restore matched wallet/voucher ownership hashes/media plus final transactional-engine/authentication/readback smoke. B22_RECONCILIATION_QUERY_PLANS.json10000checks/20runs/actual plans and filtered filesort removal; not production SLA. No actual offsite/production-size or RPO/RTO claim.

Migrations2026_10_28_110000/120000/130000/140000 applied local shopbonus batch40, foundation100000 batch39. Isolated SQLite/MySQL down/up and reapply scope2 run PASS. Rollback removes original source/recovery/operational evidence: preserve and quiesce commands first. Task servers/isolated schemas/private capture/helper files removed; generic /tmp/shopbonus-b22* logs remain. Main MySQL and gitignored private B21 local-integration credentials preserved. Approximately46GiB free, no unrelated cleanup/Redis flush or resource stop.

Exact next: normal docs-only closeout commit/push/check clean. No further authorized main-code boundary remains for #30. Hassan integrates the five documented APIs using docs/api/README.md. Senior configures deployed host/media/infrastructure inventory, queue/scheduler/TLS/admin MFA/provider/alert settings, private independent offsite storage/retention/credential escrow, actual production-version/volume isolated and full recovery drill, feasible approved RPO/RTO/cost and cash/accounting/legal/unit acceptance. These external checklist items remain NOT RUN; do not mark the full Trello card or production release Done from backend/local evidence.

---

# Active handoff — #30 wallet-run foundation verified (2026-10-28)

Branch main. Application cfbd770ff4d3d5af75666cebd0545ad7d04edda6 committed/pushed. This is a docs-only closeout; no uncommitted application/test changes remain. **Durable wallet-run foundation DONE; B22b PARTIAL; whole #30 IN_PROGRESS.**

[CI40657966315](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40657966315) SUCCESS all three jobs: quality, SQLite734pass+23skip/9570assertions/40.03s, MySQL755pass+2skip/9650assertions/182.80s (757tests each). All three real four-worker monitoring/wallet/run races passed. Local full SQLite734pass+23skip/9570assertions/51.354s, MySQL755pass+2skip/9652assertions/240.109s plus final focused SQLite5tests41assertions PASS. Pint/PHPStan L6/composer audit/diff PASS. Existing HTTP contract coverage passed; no new HTTP operation.

Delivered stable run identity/fixed wallet watermark, atomic per-wallet check/cursor,100-wallet bounded command/every-minute scheduler, interrupted-process continuation and explicit failed-run resume, original mismatch proof with native units/projections and SQLite/MySQL append-only triggers. Reuses canonical WalletService flags/reporting/authorized corrections; no balance repair, financial ledger mutation or new correction path. A completed wallet scan remains overall UNKNOWN without source-wide proof; observed mismatch is CRITICAL. Individual locked observations are not a platform-wide point-in-time snapshot. D-089/D-090 authoritative; no affiliate timing/rate/provider/mail setting change.

Migration2026_10_28_100000 applied to local shopbonus; isolated SQLite/MySQL apply/down/up + command after reapply PASS. Rollback drops run/check evidence, preserve it and quiesce the command first. Actual local command/replay observed4wallets/0differences and correctly reported UNKNOWN; raw artifact docs/reliability/B22_WALLET_RUN_CAPTURE.json. Temporary helper/schemas removed; generic /tmp/shopbonus-b22-run* logs retained. Private B21 fixture credentials remain gitignored/preserved. Main monitoring still defaults disabled; no Redis flush/unrelated cleanup. Approximately49GiB free.

Exact next main-code action: add source inspectors for affiliate/checkout/top-up/sponsorship/ledger evidence (rates, ownership, attribution, duplicate credits, compensating reversals and allocations), extend durable run coverage without relabelling old wallet-only runs, then admin run/report APIs with existing Super Admin/MFA/password/step-up controls and actual HTTP captures. Expand checklist before each tested boundary. No arbitrary balancing entries, fake recognized revenue or points-to-EUR conversion; missing legacy snapshots remain UNKNOWN. Continue operations/dependency/security/query/backup/recovery work afterward. No staging/production/offsite access or actual RPO/RTO/live acceptance evidence. Do not mark #30 complete or ready to move wholly to Hassan. No credential request is needed for the next main-code boundary.

---

# Active handoff — Trello #30 IN_PROGRESS, verified checkpoints (2026-10-27)

Branch main. Monitoring application c7e878905e6ab7ff80bff27b2b4bdbb3f3692ebe and financial prerequisite application5aa4177f4160ba41d19e8b54046765e1693cabc5 committed/pushed. This is a docs-only closeout; no uncommitted application/test changes remain. **B22a DONE; B22b PARTIAL; whole #30 is NOT DONE.**

**CI evidence:** monitoring40580008502 SUCCESS all three jobs (743tests/database). Financial prerequisites [40587710798](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40587710798) SUCCESS all three jobs for exact5aa4177: SQLite729pass+22skip/9528assertions/38.49s; MySQL749pass+2skip/9603assertions/176.09s (751each), four-worker alert-cooldown and wallet-flag races passed. Local final suites, Pint/PHPStan/composer audit, API coverage/diff checks, ten monitoring plus eight financial real HTTP calls, synthetic source evidence/query-plan benchmarks and migration rollback/reapply passed. No real provider/cash/revenue/production availability claim.

**Delivered:** opt-in metadata-only request/query observations, bounded exact percentiles/UNKNOWN coverage and existing incident threshold alerts with immutable evidence/retention/cooldown. Wallet reconciliation locks before reading, bounds its standalone scan by initial max ID, retains one original open flag and does not repair cached balances/ledger. New reward evaluations freeze policy/rule terms; credits freeze source/commission/unit/ledger evidence; sponsorships freeze original on-top fee rate. Monetary rule/evidence changes are blocked; deactivation/lifecycle transitions remain. Increased approved commission re-checks original review limit; non-EUR rewards dispute without invented FX and acceptance409. Existing role/MFA/password/CSRF/dual-admin machinery reused. Scoped findings/regressions: docs/reliability/FINANCIAL_FINDINGS.md.

**Owner decision resolved:** new affiliate rewards become spendable only after network approval, the existing configured default (D-089). Frozen earlier regimes are not rewritten. Legacy missing timing/rate evidence stays unknown, no backfill from current configuration; points are not EUR. Existing compatibility code is not approval to activate instant credit. No main setting/rule percentage/balance/provider/mail flag changed; monitoring still defaults off. Main admin MFA remains enforced.

**Schema/local state:** migrations110000(api observations/immutable incident evidence) and120000(nullable reward/credit/fee snapshots) applied to shopbonus; SQLite/MySQL rollback/reapply PASS. Production rollback drops original evidence: quiesce affected processing/preserve first. Only isolated fixture schemas rolled back in tests. Temporary capture servers/DBs/cookies/harnesses removed; generic /tmp/shopbonus-b22* result logs retained. B21 private local fixture credentials intentionally remain gitignored at storage/app/private/local-integration/credentials.json. No unrelated cleanup/Redis flush; resource threshold not triggered.

**Exact next main-code work:** implement durable idempotent platform-wide reconciliation runs, immutable per-source findings/checks with bounded background processing and honest failed/unknown coverage. Compare existing source/owner/historical snapshot/duplicate-credit/compensating reversal/checkout and sponsorship allocation evidence. Reuse WalletService and authorized correction workflows; never post arbitrary balancing entries. Standalone wallet checks must acquire current wallet state before financial reads; avoid wrapping the entire audit in a stale global transaction snapshot. Then queue/provider/dependency operational overview, broader schema/query/security evidence, encrypted backup and isolated restore/main-code recovery. Expand/check off remaining B22b/B22 checklist only with actual executed evidence. No new package/route/table without reuse audit.

**External acceptance still missing:** production/staging/provider/offsite access, actual hosting backup inventory, adopted RPO/RTO capability/cost and full ten-card E2E/release evidence. Proposed15-minute RPO/four-hour RTO are not promises. Senior configures deployment/provider credentials without posting them in chat. Never mark #30 complete or ready to move wholly to Hassan from these partial backend checkpoints.

---

# Current handoff — B21 complete, 2026-10-27

Branch: `main`. Application SHA: `7991841c32307fa96b5011c3ac719a1f13fb6368`. Test-only clean-environment correction: `a7ebb0e9c1ecd286540f9ab1c6057c45f7df2a95`. Both pushed. This is a docs-only closeout; no uncommitted application/test changes remain. **B21 main backend is DONE.**

[CI40559448895](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40559448895) passed all three jobs for exact `a7ebb0e`: quality, SQLite (714 passed + 20 skips, 9417 assertions, 44.00s), MySQL (732 passed + 2 skips, 9484 assertions, 115.88s). Each database ran 734 tests; real six-process funding replay and promotional stock races passed. Local full suites, final API coverage, Pint/PHPStan L6/composer audit/OpenAPI references/diff checks also passed. The first CI exposed one test relying on local Privacy configuration; explicit test fixture correction passes with empty legal environment settings on both databases and in final CI. Application fail-closed behavior stays enforced.

Implemented optional UUID funding request identity scoped to workspace and actor, immutable amount/provider UUID, at most one provider create attempt and honest creating/created/uncertain state. Legal discovery returns canonical current versions and only matching published document links from the same page. Verified promotional claims use canonical inventory, one voucher per account/offer, with secret-free responses and support impersonation blocked. New opt-in local fixture command preserves existing identities/content and keeps normal MFA; default seeding is empty. D-086/087 record policy choices; affiliate credit timing remains unresolved and unchanged.

Migration `2026_10_27_100000_add_funding_request_identity.php` applied to local `shopbonus`; rollback/reapply passed on isolated SQLite and twice on MySQL. InnoDB rollback restores the original supporting FK index before dropping the new composite index. Rollback removes request history: quiesce funding writes and preserve identities before production rollback. Creating/uncertain payments without a provider reference require manual provider reconciliation, never automatic resubmission.

Local setup and safe rerun succeeded: four verified LOCAL SAMPLE accounts (consumer, merchant, two admins), labelled NL/EN legal/CMS/FAQ, approved public sample shop and an active promotional offer with ten codes. Both legal version/document availability checks pass. Generated passwords and IDs are only in `storage/app/private/local-integration/credentials.json` (gitignored, file0600/directory0700). Keep it private; admin MFA remains enforced. No wallet credits, live integrations or outbound mail enabled.24 real local HTTP captures include browser signup/login and auth/CSRF/rate/error/replay paths, using a synthetic payment upstream. Capture harnesses/servers/private ephemeral databases removed; generic test logs retained in `/tmp`. Local disk was approximately48GiB free at task start; no stop-rule exception was needed.

**Next:** Hassan uses [LOCAL_INTEGRATION.md](integration/LOCAL_INTEGRATION.md) and the API examples for frontend integration. Senior developer configures approved real legal content, mail/providers/models and deployment, then records actual live financial/inbox/staging acceptance. Those remain NOT RUN and are not claimed complete. No B21 main-code blocker or work remains. Finish pushing this tracking-only closeout and check git is clean.

---

# Latest handoff — #29 main backend complete

**Branch/SHA:** main; implementation911e053 +approval-scope follow-up5a8c36d committed/pushed; CI40412989638 and final40414186822 SUCCESS all3jobs. This final docs-only evidence closeout follows; no application code change or pre-existing edits overwritten. Owner explicitly “do it”; all remaining #29 main code complete. D-081 below-cutoff authorization persists for this task, no unrelated cleanup/Redis flush/binlog purge, subagents, frontend/NPM or external customer messages.
**Status:** B20a–f **DONE (main backend)**; ready for Hassan frontend integration. Whole Trello card still needs its UI and senior configured real mail/model/deployed Reverb/monitoring/staging acceptance. Those are NOT RUN, not guessed passes.
**Delivered:** full23-event catalogue, encrypted transactional source outbox and current eligibility; legacy merchant projection/shared-read compatibility with personal pre-projection receipts; full preferences/current public workspace context; durable queued mail claims/stable IDs/actual MIME, bounded safe pre-send retry and explicit ambiguous delivery review; configured default30/7/1 voucher/default7day offer reminders and last-moment checks; retention/deletion locks/minimal dedupe tombstones; email-only promotion support with public/favorite/consent checks. Immutable existing settings templates/test/independent publish/rollback/NL fallback; strict capped per-admin-idempotent configured Responses drafts, no provider/source PII, no auto publication or economic/contact policy activation.16new operations (15admin +owned DELETE);21 notification ops including prior5;104 actual HTTP captures.
**Configuration:** NOTIFICATION_EMAIL_ENABLED=false, RETENTION_DAYS90; AI_ENABLED=false, model/key empty, default10generations/day hard100. Senior configures actual MAIL/model/key/budget, queue retry_after>60, scheduler/Reverb/TLS/origins and records live acceptance. Reviewed notifications.policy controls bounded retention/email pause/reminder windows. No credentials needed from owner for coding.
**Migrations:**150000 outbox/legacy link/personal read receipts/in_app_enabled;160000 delivery records;170000 tests/AI quotas/generations/fallbacks. Final local MySQL apply/rollback/reapply PASS, currently applied shopbonus. SQLite and MySQL CI down/up regression PASS. Only empty task schemas were used during local rollback checks; no unrelated data deleted.
**Exact CI40412989638:**719tests/database. SQLite701pass+18skip/9278assertions/40.77s; MySQL717pass+2skip/9340assertions/144.63s. Publication4-worker/global quota2-worker/mail claim4-worker races explicitly PASS on MySQL. Pint/PHPStan L6/composer audit green. Full local SQLite718total700pass+18skip/9262assertions before final recovery test;9-source-test45assertions after it; final contract13pass3669. Full local MySQL NOT RUN for this checkpoint (low disk), actual MySQL CI provides final executed evidence.
**Final follow-up:** actual stepped-up approval journey now explicitly asserts owner-only approved notice/correct workspace reference and admin-only review notice; AgreementAndReviewTest14pass375assertions locally. No app-code change from911e053. Follow-up5a8c36d CI40414186822 SUCCESS all3jobs: SQLite701pass+18skip/9281assertions/43.05s; MySQL717pass+2skip/9341assertions/101.94s. Actual approval-to-notification scope assertions passed on both databases.
**Evidence/setup:** docs/notifications/NOTIFICATIONS.md has exact APIs/fields/events/defaults/ten backend acceptance groups. B29_HTTP_CAPTURE.json +README/OpenAPI:104 real local HTTP requests, actual SPA password/seededOTP and DB queue worker; array-mail sent/attempts2 after reviewed synthetic prior uncertain attempt. Model completions/malformed output and one storage500 are explicitly temporary local fixtures; no live-provider/customer/deployed WebSocket claim. phpunit test-only512MiB after observed128MiB limit; production unchanged. D-083–085.
**Cleanup/status:** all task capture fixture DBs/env/keys/passwords/cookies/router/logs/helper scripts removed;8079/8089 stopped and no fixture switch shipped. Only generic verification/CI logs /tmp/shopbonus-b29-* retained. Working tree clean after this normal docs/test commit/push; no open main-code blocker.
**Next exact:** Hassan builds frontend using notification handoff/API examples; senior configures/enables/independently publishes tested policy/templates and records real mail/OpenAI/Reverb/deployment/staging acceptance. No further #29 main-code implementation required. Main code and follow-up tests already CI-verified; final docs-only normal commit/push precedes the report. No further backend action remains.

---

# Previous implementation checkpoint — #29

**Authorization/branch:** owner “do it” completes #29 main backend; senior configures credentials. D-081 below-cutoff authorization persists. main/deea998 clean start, all current code/docs task-owned and ready for implementation commit/push; no pre-existing work overwritten. No unrelated cleanup/Redis flush/binlog purge, subagents, frontend/NPM or external customer messages.
**Status:** B20b–e main implementation locally verified; final B20f/CI/MySQL acceptance pending before backend Done. Full card frontend/live/deployed acceptance is never claimed from local mocks.
**Features:** durable encrypted source intents and current recipient eligibility; canonical merchant compatibility projection/personal read receipts; full23-event registry/preferences and16 new operations (15 admin+owned DELETE,21 notification operations including prior5); same-transaction mail rows, queued claim/stable Message-ID, actual MIME render, bounded pre-send retry and explicit uncertain outcome review; configurable30/7/1 voucher/default7day offer reminders, last-moment source/stage checks, retention/deletion locks/tombstones; email-only promotions hidden from inbox with current consent/public/favorite eligibility. Immutable existing settings template versions/test/independent publish/rollback and metered NL fallback; strict fixed-host/no-PII configured Responses drafts, per-admin UUID/global daily cap, no automatic publication or finance/contact policy activation.
**Migrations:**150000 outbox/legacy link/read receipts/in_app_enabled;160000 mail deliveries;170000 template tests/AI quota/generations/fallbacks. Exact final local MySQL apply/rollback/reapply PASS, currently applied shopbonus; empty-task-table guard preserved all unrelated data. SQLite new-migration rollback/reapply test PASS. Initially overlong fallback index fixed; no unrelated tables removed.
**Evidence:** full SQLite718total700pass+18skip/9262assertions/75.523s. Final new outbox failure/recovery regression subsequently passes9 source tests/45assertions, current total719 expected CI. Final API+diagnostics13pass/3669assertions after104 actual HTTP captures. Full Pint/PHPStan L6/composer audit/diff PASS. Full local MySQL/races NOT RUN due~300MiB free; exact-code CI must execute complete MySQL and3 real process races. phpunit test memory512MiB after observed128MiB failure; production limit unchanged. Generated empty YAML artifact fixed and registry-dependent regression suite passes.
**HTTP:**104 captures in B29_HTTP_CAPTURE.json +README/OpenAPI, actual sessions/password/seededOTP, actual DB queue worker/array transport; synthetic Responses completion/refusal/malformed and kernel500 fixtures labelled. Reviewed synthetic uncertain mail accepted by actual worker (sent/attempts2, previous attempt explicitly synthetic). No live model/customer inbox/deployedReverb/frontend/staging claims. Templates/roles/private policy safeguards tested; docs/notifications/NOTIFICATIONS.md has complete contract/event/setup/limits. D-083–085.
**Cleanup:** all local capture databases/env/keys/passwords/cookies/router/logs removed;8079/8089 stopped, no fixture switch in app/config/routes. Temporary helper/generic verification logs /tmp/shopbonus-b29-* remain for final closeout cleanup.
**Next exact:** commit/push implementation main normally; verify all CI jobs including actual MySQL source publication, AI-quota and mail-claim races. Fix real failures if any. Then replace this pending status with exact SHA/CI evidence, tick final B20f/CI items only after pass, docs-only closeout commit/push and clean status. Hassan frontend +senior config/live verification follow; no credential request needed.

---

# Previous working checkpoint — #29

**Branch/base:** main/deea998 clean start. All current implementation/docs changes are task-owned and uncommitted; no user work overwritten. Owner says “do it”; finish #29 main backend. D-081 authorizes below-cutoff disk continuation for all #29, no repeated permission request. No unrelated cleanup, Redis flush/binlog purge, subagents, frontend/NPM or external customer messages.
**Current:** B20b–f main source/outbox/merchant bridge, mail, reminder/retention/delete, strict reviewed AI templates/admin API code implemented; final verification/API docs still IN_PROGRESS. Parent card not Done or ready frontend-only yet.
**New code:** NotificationSources/Catalogue/Dispatcher/Delivery/Mail/Maintenance/Policy/Templates/TemplateAi, DeliverNotificationMail, DispatchNotifications, NotificationEvent/Delivery, new NotificationController under /api/v1/platform/notifications; User-owned DELETE notification; existing preferences/catalogue/platform-settings expanded. No economic/credit timing/contact policy activation. Source intents persist with financial transaction; no inline mail during posting. Ambiguous SMTP delivery requires human review, not automatic retry or a false exactly-once provider claim.
**Migrations:**150000 notification_events/legacy link,160000 notification_deliveries,170000 template tests/generation quotas/fallbacks, all MySQL apply/rollback/reapply PASS and currently applied shopbonus. Initial overlong fallback index fixed; removed only2 empty newly created task tables from failed170000, no unrelated deletion.
**Executed:** focused37tests34pass+3MySQL-only skips/248assertions before last support-review/deactivation source refinement. Actual multipart mail through array transport and unsafe/pre-send/uncertain/retry/template lifecycle/source/reminder tests pass.74 real local HTTP captures (B29_HTTP_CAPTURE.json), actual session/password/seededOTP, actual DB queue worker +arraymail, synthetic provider success/failure and isolated kernel storage500 fixture. Temporary router/DB/env/keys/credentials/cookies/logs removed. No genuine provider/mail/deployed Reverb/frontend evidence.
**Exact next:** finish API README/OpenAPI from74 captures; final focused/full SQLite and feasible local MySQL tests (free disk~370MiB), quality/audit; record unchecked scopes only after passing, commit/push main normally and verify all CI jobs. Expand/mend any actual gaps discovered; full card frontend/live acceptance remains senior/Hassan. Preserve source evidence and do not claim missing tests as pass. Generic task logs /tmp/shopbonus-b20-* and /tmp/shopbonus-b29-* remain.

---

# Previous #29 B20a checkpoint

**Session/authorization:** 2026-10-26. Owner asks to start #29; senior configures credentials. Explicit “Continue #29 below the limit” is recorded in D-081; do not request permission again for this task's ~5GiB cutoff. No unrelated deletion/Redis flush/binlog purge, subagents, frontend/NPM or external customer messages.
**Branch/base:** main/d2fb5ed clean start; implementation5c1d8cc committed/pushed, CI40399876856 SUCCESS all3jobs. This docs-only closeout updates evidence; no pre-existing work overwritten.
**Status:** whole #29 PARTIAL. B20a owned inbox/support-reply foundation DONE and exact-code CI-verified. No full-card Done or frontend-only handoff yet.
**Code:** NotificationInbox/NotificationPreferences, encrypted CentralNotification (Laravel DatabaseNotification), NotificationInboxChanged;5 new verified owner routes, InboxController; User notifications relation; SupportReviews same-transaction support notification; support-session guards/channel callback. Source/card preserved and B20a–f checklist expanded first.
**Behavior:** generic server NL/EN text, no reply body/codes/financial facts; per-user/source dedupe+rowlock, current membership/non-suspended workspace and current admin-role visibility, idempotent read/read-all, shared locale/preferences without granting consent. Only support_reply/workspace_support_reply wired. Email unavailable/not_implemented explicit. Minimal queued after-commit hints, durable HTTP reconnect; queue failure cannot roll back committed reply/read state. Legacy merchant APIs unchanged until explicit B20b compatibility bridge.
**Migration:**2026_10_26_140000_create_central_notifications adds notifications +notification_preferences; local MySQL apply/rollback/reapply PASS, currently applied shopbonus. Test DB shopbonus_testing isolated.
**Tests/evidence:** full SQLite688total672pass+16skip/8873assertions/71.284s. Final MySQL688total686pass+2skip/8927assertions/273.128s. Earlier focused MySQL11pass98assertions includes actual4-worker replay; final4 negative tests included in full SQLite. Pint/PHPStan L6/composer audit PASS; OpenAPI3pass3358assertions.24 actual local HTTP captures, synthetic users/source reply and isolated SQLite/database queue/sessions; actual Reverb-configured channel auth owner200/other403/support403.500 temporary kernel-bound storage failure labelled; ephemeral signature redacted. No deployed WebSocket/live mail/model/frontend proof. docs/notifications/NOTIFICATIONS.md and B20A_HTTP_CAPTURE.json; README/OpenAPI updated. Task capture fixture files/credentials/environment/database/router logs removed; generic /tmp/shopbonus-b20-* verification logs remain.
**Next exact:** B20a implementation/CI complete; closing docs-only commit/push records5c1d8cc and green CI. Next implementation boundary B20b: centralize merchant notification compatibility/event wiring; B20c email outbox/recovery/retention, B20d reminders, B20e approved AI templates/admin, B20f full10 backend E2Es remain unchecked. Credentials are not a coding prerequisite.

**Exact implementation CI:**40399876856 SUCCESS all3jobs,688tests/database. SQLite672pass+16skip/8877assertions/39.76s; MySQL686pass+2skip/8927assertions/126.47s; quality green. Dispatch-failure recovery fixture throws an event listener at dispatch; genuine deployed queue/email/Reverb outages NOT RUN. Local server/router/capture fixtures/credentials cleaned; no8079 listener remains. Last disk~168MiB, no ENOSPC; no unrelated cleanup. Closing docs-only changes are the only uncommitted work at this checkpoint, then normal commit/push.

---

# Previous checkpoint — #26 complete main backend

**Session:** 2026-10-26, Codex. Owner explicitly asks to finish remaining support backend; model/key/budget configured by senior developer. D-075 authorizes continuing #26 below~5GiB; do not ask again on that cutoff. No unrelated cleanup, Redis flush or binlog purge. No subagents/frontend/NPM/external customer messages.
**Branch/base:** main /7c1657d clean start. Implementation d63f616 +final corpus guard7616e88 committed/pushed; final CI40388179684 SUCCESS all3jobs. Final docs-only evidence closeout follows; no pre-existing work overwritten. No pre-existing work overwritten.
**Status:** complete #26 **main backend implementation**, **DONE**, committed and exact-code CI-verified.16 new operations and all remaining chat/history/model/retry/Reverb/feedback/review/settings/knowledge main-code features delivered. Whole Trello card retains Hassan frontend and senior live-model/deployed TLS/production monitoring/staging acceptance. Do not label those unexecuted checks as passed.
**Code:** SupportChat queued scoped/idempotent CAS engine, SupportPlanner, SupportProvider, SupportPolicy, SupportReviews, SupportKnowledge, expanded SupportTools/VerifiedSupportAnswer;7 Support* models; ProcessSupportMessage/SupportProgress; Support/ChatController +Admin/AiSupportController; existing CmsController mutations delegate to immutable FAQ service. Config support_ai/reverb, routes.api/channels/console, PruneSupport command. Existing quote now truly readonly; campaign paid attribution excludes cancelled/refunded/incomplete sources. Unknown starts remain null; no reward-unit/payment/date guesses.100 static EN/NL questions+bounded500 approved human regressions actually run; changed corpus/failed cases gate publication, not automatic learning. FAQ snapshots/rollback preserve provenance/checksum/newrevision/reason, legacy approved_at unknown/null.
**Security:** fresh identity/workspace/individual permissions before egress and rendering; Super Admin no tenant bypass; support impersonation blocked for all chat APIs/private channel. Model gets only opaque selected evidence plus allowlisted facts; raw question/IDs/contact/payment/codes excluded. FAQ questions/prose/contact text stays in Laravel. Strict required Responses function, fixed host/no redirects/retries, one attempt, bounded tokens/time; server templates only, no financial actions/model prose. One distinct targeted research round; integrity rereads are bounded checks. Encrypted scoped private transcripts/evidence/comments/replies, default30d retention (1–90), explicit deletion/scheduler recovery. Actual human review reference only after DB success.
**Configuration:** envSUPPORT_AI_ENABLED=false default plus independently published support.ai enablement, configurable model/key; global100/peruser20 requests/day defaults, output1000 hard256–2000, timeout20 hard5–20; model/key/project budget senior-owned. Queue tries1/timeout60, retry-after>60. Reverb1.12 installed, loopback/default-deny origins, credentials empty; Guzzle7/PSR7+Promises2 resolve supported dependency ranges, full app tests pass. No real OpenAI call/model account claim.
**Migrations:**120000_create_support_chat and130000_create_support_knowledge_versions both apply/rollback/reapply PASS local MySQL; applied local shopbonus. Test suites manage shopbonus_testing. No ledger/legal/affiliate/contact activation.
**Validation:** final full SQLite672total657pass+15skip/8690assertions/60.13s; sequential MySQL672total670pass+2skip/8752assertions/149.91s. Both include FAQ rollback/corpus gates. Final small provider metadata-only privacy refinement+added test:13provider tests39assertions PASS; Exact d63f616 CI673total: SQLite658pass+15skip/8706assertions/19.41s; MySQL671pass+2skip/8753assertions/77.80s; all3jobs SUCCESS. Final before/after corpus fingerprint guard proved with mid-read human-case insertion:5admin tests68assertions passed on both databases, plus quality. Exact final7616e88 CI673total: SQLite658pass+15skip/8704assertions/31.67s; MySQL671pass+2skip/8755assertions/94.99s; all3jobs SUCCESS.2 real MySQL process races pass (UUID idempotency, global quota). Focused Support+CMS+API169total167pass+2skip/4649assertions before final provider privacy test; latest API coverage after8 extra route-specific409 captures also checked. Final Pint/PHPStan L6/composer audit/diff check PASS.
**Actual integration evidence:**75 real local HTTP captures in API README/OpenAPI for16new ops+changed settings/FAQ/quote/banner behavior; actual browser/bearer/password+seededOTP and database queue worker, synthetic upstream explicitly labelled. Real local Reverb WebSocket/subscription/signed broadcast test passed owner200/other403, Research→Verification→Verified, only conversation/message/status payload. Sanitized docs/support/REVERB_LOCAL_CAPTURE.json. No live email/OpenAI/deployedReverb/staging/frontend claim. All task capture fixture databases/credentials/environment/harness/logs deleted; ports8079/8089 stopped. Generic test logs remain /tmp/shopbonus-b17-*.log.
**Handoff:** docs/support/SUPPORT_CHAT.md has route inventory, config, client flow, source limits, retention and all10backend acceptance mappings. EVIDENCE.md original B17a inventory is marked historical/superseded. D-076–080 record choices.
**Next exact:** no B17 main-code work remains. Closing turn commits/pushes this docs-only evidence checkpoint and checks status. Hassan integrates frontend with docs/support/SUPPORT_CHAT.md and API examples. Senior developer configures supported model/key/budget, publishes tested support.ai settings, deploys queue/scheduler/Reverb/TLS/origins and records actual provider/production monitoring/staging acceptance. Do not request credentials from owner as a coding prerequisite. Whole Trello/front-end/live acceptance is still not marked Done. Platform B18release/live integrations are separate unfinished acceptance, not claimed completed.

---

## Environment / migrations / config

- Local: PHP 8.4.17, MySQL (`root`, no password) with DBs `shopbonus` and `shopbonus_testing`, Redis 127.0.0.1:6379 via predis (D-004).
- **Local Redis is shared with other projects.** Only delete keys matching `shopbonus-*` (databases 0 and 1). Never `FLUSHDB`/`FLUSHALL`; see the incident note below.
- Migrations applied locally: Laravel defaults, `personal_access_tokens`, `2026_10_23_130000/130100` (audit, errors), `2026_10_23_140000..140300` (identity), `2026_10_23_150000` (webshops, memberships, platform roles), `2026_10_23_160000` (invitations), `2026_10_23_170000` (business profiles, verifications, ownership columns), `2026_10_23_180000` (agreements), `2026_10_23_190000` (connections, webhook events), `2026_10_23_200000` (orders, order events), `2026_10_23_210000` (offers, offer versions), `2026_10_23_220000` (voucher codes, batches, vouchers, events), `2026_10_23_230000` (contact suppressions, opportunities, outbox; order customer_key/consent), `2026_10_23_240000` (integration clients, inbound events, opportunity events, voucher claims, claim reminders), `2026_10_23_250000` (public profiles, public categories, offer slugs), `2026_10_23_260000` (users phone/avatar, email change requests, favourites, category preferences, privacy requests), `2026_10_23_270000` (wallets, ledger + triggers, lots, reservations, pending, reconciliation flags), `2026_10_23_280000` (affiliate connections, programs, selections, clicks, reward rules, transactions, reconciliation events), `2026_10_24_100000` (voucher checkouts, checkout payments), `2026_10_24_110000` (voucher redemption columns, disputes, dispute events), `2026_10_24_120000` (funding invoices, sponsorship obligations, top-ups), `2026_10_24_130000` (merchant notifications), `2026_10_24_150000` (CMS pages, locales, revisions, assets, FAQ), `2026_10_24_160000` (cookie policies, cookie consents), `2026_10_24_170000` (users two-factor columns, step-up challenges, impersonation sessions). `2026_10_24_140000` (banner installations, campaigns, versions, assets, pairings, events, clicks, daily stats; `webshop_orders.attribution_click_id`).
- Env key names added in B02: `LEGAL_TERMS_VERSION`, `LEGAL_PRIVACY_VERSION`, `LEGAL_MARKETING_CONSENT_VERSION` (empty = registration disabled), `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE` (true in prod), `SESSION_SAME_SITE`, `VERIFICATION_CODE_TTL_MINUTES`, `VERIFICATION_CODE_MAX_ATTEMPTS`, `VERIFICATION_RESEND_COOLDOWN_SECONDS`. B07: `ANSWER24_DISPATCH_ENABLED` (false), `ANSWER24_CONTACT_PATH` / `ANSWER24_CANCEL_PATH` / `ANSWER24_CLAIM_STATUS_PATH` (Shopbonus-defined defaults), `ANSWER24_BASE_URL`, `ANSWER24_SIGNING_SECRET` (secret), `ANSWER24_ALLOW_LEGITIMATE_INTEREST` (false), `ANSWER24_RECENT_CONTACT_DAYS` (30). B06: `DIRECT_REWARD_FEE_BPS` (3000). B05: `BUSINESS_TIMEZONE` (default Europe/Amsterdam), `BROADCAST_CONNECTION` (null by default). B04: `WEBHOOK_PAYLOAD_RETENTION_DAYS` (default 14). B03c: `GOOGLE_PLACES_API_KEY` (secret; empty = manual entry only). B12: `FUNDING_REQUIRED` (true), `FUNDING_PERCENTAGE_BASIS` (maximum_discount). B11a: `MOLLIE_API_KEY` (secret; test_ keys only), `MOLLIE_API_BASE_URL`, `MOLLIE_ALLOW_LIVE` (false). B10: `AFFILIATE_CREDIT_TIMING` (on_network_approval), `AFFILIATE_FALLBACK_NETWORK` (daisycon), `DAISYCON_API_BASE_URL`. B09: `WALLET_CREDIT_VALIDITY_MONTHS` (12). B16a: `ADMIN_MFA_REQUIRED` (true; false in phpunit.xml), `ADMIN_STEP_UP_CODE_TTL_MINUTES` (10), `ADMIN_STEP_UP_MAX_ATTEMPTS` (5), `ADMIN_STEP_UP_VALID_MINUTES` (10), `ADMIN_SUPPORT_SESSION_MINUTES` (30). B08a: `DISCOVERY_CACHE_TTL_SECONDS` (60), `API_DOCS_ENABLED` (default on outside production). Earlier: `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `CORS_SUPPORTS_CREDENTIALS`, `API_RATE_LIMIT_PER_MINUTE`, `HEALTH_READINESS_TOKEN` (secret).
- Capturing docs: run the server with `QUEUE_CONNECTION=sync MAIL_MAILER=log`, read codes/tokens from `storage/logs/laravel.log`, then **truncate that log**, since it holds live codes and reset tokens.
- **Admin captures since B16a:** admin routes need confirmed 2FA. Either start the server with `ADMIN_MFA_REQUIRED=false`, or enrol 2FA in the script and disable it again at the end (`DELETE /user/two-factor-authentication`), as the B16a capture did. TOTP codes can be computed with `php -r "require 'vendor/autoload.php'; echo (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp('<secret>');"`. Never put the secret, QR code, recovery codes or support tokens in docs.

## Conventions to reuse

- Routes: `routes/api.php` v1 group. Private routes go inside `auth:sanctum` + `account.active`, and inside `verified` unless the email-verification flow itself needs them. Declare `x-access` per operation in `docs/api/openapi.yaml`; `OpenApiCoverageTest` enforces docs, examples and access matching.
- Errors: `ApiException` (supports `$details` and `$headers`). Audit: `AuditLogger::record()` in transactions, `recordSafely()` otherwise. Append-only models: `use App\Models\Concerns\AppendOnly`.
- Public IDs: models exposed to clients get a ULID `public_id` (`HasUlids` + `uniqueIds()`); never expose integer keys.
- Session-flow tests: `use Tests\Concerns\BrowsesLikeSpa` and `$this->spa('POST', ...)`. Bearer-token requests after `spa()` must `flushHeaders()` and flush the session store (see `AdminSecurityTest::asSupport`), or the test client leaks the SPA origin.
- Sensitive admin actions: `password.recent` then `step_up.otp` (emailed code, `StepUpCodes`). New sensitive non-admin routes must be added to the `GuardSupportSession` denylist so support sessions cannot use them.
- Index names over 64 characters fail on MySQL only: give composite indexes explicit short names.

## Incident note (2026-10-23)

While capturing docs, an early script ran `redis-cli FLUSHDB` on local Redis **database 0 twice**. Database 0 may have held other local projects' data, which would be lost. Database 1 (other projects' cache keys) was not touched. The capture script now deletes only `shopbonus-*` keys.

## Blockers / open decisions

- OPEN-SIGNUP-ENUMERATION (new), OPEN-AFFILIATE-CREDIT-TIMING, OPEN-REWARD-UNITS, OPEN-FINANCIAL-LEGAL, OPEN-MARKETING-CONSENT, OPEN-PROVIDER-CAPABILITIES, OPEN-EMPTY-CARD-27 (see `docs/DECISIONS.md`). None block B03.
- Remote: `origin` = https://github.com/nauticsecure-dev/shopbonus_backend (owner-requested one-time force push on 2026-10-24 replaced an unrelated "first commit" `7d7790c`, kept locally as tag `backup/remote-first-commit-7d7790c`). Normal pushes only from now on. GitHub CI runs; PHP 8.4 only (D-055). No staging (p95 unverified).

## Workspace conventions (B03a)

- Workspace-scoped routes: `Route::prefix('workspaces/{workspace}')->middleware('workspace')`, plus `->middleware('workspace.can:<permission>')` per route. Read the webshop from `CurrentWorkspace`, never from input. Declare `x-permission` in OpenAPI.
- Non-member access is a generic 404 (and audited by the resolver); members lacking a permission get 403.
- Commercial routes (offers, vouchers, integrations, payments) must add `workspace.commercial` after `workspace`.
- Tests needing a commercially active webshop: `use Tests\Concerns\CreatesActiveWebshops` → `$this->activeWebshop($owner)`.
- Fees: always `FeeCalculator` (never inline percentages).
- Vouchers: only through `VoucherInventory` (reserve with an idempotency key such as `order:<id>:offer:<id>` or `answer24-handoff:<ref>`). Never update voucher/code status directly.
- Bulk inserts that bypass Eloquent must generate `public_id` with `strtolower((string) Str::ulid())`.
- Sponsored vouchers move merchant funding automatically inside `VoucherInventory`; tests get funded webshops from `CreatesActiveWebshops` (drain with `WalletService::adjust` to test unfunded cases).
- User-facing messages: write them in English and add the Dutch text to `lang/nl.json` (keyed by the English text); `__()` is applied in the error renderer.
- MySQL returns 0 affected rows when an UPDATE changes nothing: never treat `update() !== 1` as "not found" unless the update always changes a column (status/revision).
- Push with `git push origin main` (never force) after each task; CI must stay green.
- Checkouts only through `CheckoutService`; lock order offer → checkout → code → wallet. Test Mollie with an `Http::fake` callback driven by test properties (the first registered fake wins, so re-faking inside a test does nothing).
- Wallet changes only through `WalletService` (idempotency key on every call; never touch `wallets` columns directly). Choose the unit explicitly.
- Tests that commit real rows (concurrency) use `Tests\Concerns\RacesProcesses` + DatabaseTruncation, call `truncateAfterRace()` in tearDown, and override `truncateDatabaseTables()` to skip on SQLite (see `ConcurrentWalletTest`).
- Partner (server-to-server) routes: `->middleware('integration.signed:<scope>')`, declared `x-access: integration` with `security: [{ integrationSignature: [] }]`. Credentials only via `php artisan shopbonus:create-integration-client`. Tests sign with `App\Support\SignatureV1::header()` (see `Answer24ChannelTest::signed()`).
- Claim links: only through `App\Services\Answer24\ClaimLinks` (handoff/accept/reminder/expire). Outbox delivery only through `OutboxDispatcher` (`shopbonus:dispatch-outbox`).
- Public read endpoints: under `Route::prefix('public')`, data through `PublicCatalog` only (published fields, cached via `DiscoveryCache`). Models whose change affects public data must call `DiscoveryCache::flush()` on save.
- Consumer routes: under `Route::prefix('consumer')` in the verified group; always `$request->user()`, never an ID from input. Optional mail must check `CommunicationPreferences::allows()`.
- Notifications sent with `Notification::route(...)` must type `toMail(object $notifiable)` (an on-demand recipient is not a `User`); `Notification::fake()` never renders mails, so add a render test.
- API docs browsable at `/docs` and `/docs/reference` (local: `php artisan serve` → http://127.0.0.1:8000/docs).
- Admin routes: inside the `admin` prefix group (`platform:super_admin`); mutations inside `password.recent`. Test them with a stateful session (`Referer`/`Origin` headers + `withSession(['auth.password_confirmed_at' => time()])`).
- **Never write a denial/security audit inside a DB transaction that then throws**: it rolls back with it. Audit before the transaction, or after commit.
- Never use database `NOW()` in queries or scripts: local MySQL runs in the server's timezone while the app writes UTC. Use PHP `now()`.
- The agent shell is zsh: never name a shell variable `path` (it is tied to `PATH`). Capture scripts are bash files using `caplib.sh` (see the scratchpad pattern described in PROGRESS); keep that pattern.


## Discovery integration accuracy checkpoint — 2026-10-31

Branch HassanDev, backend base 59916e9, frontend base 3d0b0ad; uncommitted.
Reuse/fixes and exact evidence: [Discovery review](discovery/INTEGRATION_ACCURACY_REVIEW.md).
Unified obtainability sort/filter/count, actual batching (including null/zero cases),
stock/import/allocation and after-commit invalidation, <=30s cache with scheduled
expiry, shared voucher list/status, URL Back/Forward synchronization. No migration
or new endpoint. Existing unrelated edits preserved; no commit/push/deployment.
Validation: Discovery+Vouchers+OpenAPI 47 tests / 43 passed / 4 skipped / 5490 assertions;
benchmark separately 1 passed / 201 assertions; 10 Chromium directory fixture
journeys passed; frontend typecheck/targeted ESLint and backend targeted Pint passed.
Broader Affiliate+Vouchers+OpenAPI: 67 passed, 4 skipped, 2 unrelated pending-activity
failures (existing WalletController/AffiliateActivityController edits); not green.
Local 200-shop/1000-offer/2000-code HTTP-kernel benchmark: 100 cold + 100 warm
samples, P95 431.16ms cold / 62.97ms warm. Not staging/network/load evidence.
Status PARTIAL: missing design reference, full real-service platform browser
journeys and staging P95 remain open. Next: obtain reference, run full live
journeys and MySQL/Redis staging search load measurements; investigate cold P95.

Additional discovery evidence: live local API browser smoke passed (1 test, 26.3s), search/category/has-offers/sort and clean-slug detail; desktop/mobile captures in root docs/discovery/directory-live-*.png. Post-documentation OpenAPI coverage 3 passed / 5101 assertions. Full platform acceptance remains partial.


## Merchant overview review follow-up — 2026-10-31

Implementation checkpoint, PARTIAL acceptance. Backend branch HassanDev, base 59916e9; frontend HassanDev, base 3d0b0ad. Changes remain uncommitted; unrelated existing changes were preserved. No production migration or deployment performed.

Implemented overview pagination (10/page), lifecycle filters before pagination, four primary KPI cards, secondary operational details, unavailable/error states, request cancellation, 30-second visible-tab polling, focus refresh and generated_at freshness text. KPI/chart/claim notices open existing voucher records using claimed=1 and business-timezone claim-day filters, including subsequent redemption; list adds claimed_at. No new route or duplicate claim store.

Recovered stock alerts resolve on generator runs and dashboard/workspace-notification reads; resolved legacy records remain stored but are excluded from dashboard, workspace list and central inbox. Count-change signals refresh central badges; pending outbox/mail delivery skips resolved entries. Invalid import batches and explicit provider mismatch/sync_error results now produce deduplicated offer alerts. Normal unverified/manual-required codes are not treated as verification failures.

Effective lifecycle is shared by SQL filters/KPIs and displayed rows: archived, expired, scheduled, draft, paused, sold_out and active, including allocation limits. Active excludes sold-out/future/expired offers. Existing platform access policy retained: nonmember 404 (existence concealed), member lacking permission 403. API contract and real HTTP captures are in docs/api/MERCHANT_OVERVIEW_CAPTURE.json and docs/api/README.md.

Executed: focused Analytics/Notifications/OpenApiCoverage run 70 tests, 67 passed, 3 skipped, 5587 assertions (SQLite); targeted Pint and PHPStan pass (0 errors); frontend typecheck and targeted ESLint pass; ten Playwright Chrome browser contract journeys pass. Browser responses are controlled fixtures, not ten real-service E2E journeys. Desktop/mobile screenshots were visually inspected. Backend historical E2E naming now explicitly describes API integration evidence; sequential claims no longer described as concurrency.

Volume evidence: 10,000 available codes + 10,000 accepted claims/issued vouchers, 30 days, three SQL aggregate queries, 463.7 ms on local SQLite. See docs/analytics/MERCHANT_OVERVIEW_BENCHMARK.json. Daily SQL grouping uses UTC boundaries of each local day (DST tested), returning at most one row/day/source. Added workspace/timestamp indexes. This is not a production P95 or MySQL/Redis load result.

Migrations: 2026_10_31_160000_add_resolution_to_merchant_notifications and 2026_10_31_160100_index_merchant_lifecycle_analytics. Applied only to isolated test/capture SQLite databases. Apply both before running this code against an existing database. The synthetic HTTP capture server and temporary credentials/database were removed afterward.

Exact next acceptance work: supply the original screenshot for a reference comparison; exercise all ten journeys with real sessions/services and a real concurrent claim workload; benchmark representative MySQL/Redis deployment and inspect plans. Full repository test/build suite and production provider verification NOT RUN. Checklist items stay unchecked until a commit records evidence/SHA, per AGENTS.md rule 12.


## AI support repair — 2026-10-31

Status: implementation verified locally; live-service acceptance PARTIAL. Backend branch HassanDev/base 59916e9; frontend HassanDev/base 3d0b0ad. Uncommitted; unrelated existing changes preserved. No commit, push, migration or deployment.

Removed unsupported reserved-reward/release claims from both sidebar and verified NL template. Wallet request state separates loading/error/verified empty; missing categories stay unavailable. Planner now normalizes unambiguous one-edit typos, preserves subject/reference/unit for bounded follow-ups in the owned conversation, extracts FS-20482-style references, and exposes ORDER_STATUS/PAYMENT_QUESTION through the existing authorized order evidence tool. This is conservative contextual routing, not a general semantic assistant; ambiguous requests clarify.

Pending history renders immediately and HTTP sync recovers answers/human replies on refresh, focus, browser online and authorized Reverb resubscription (3s pending / 15s idle while visible). Transport acknowledgements are labelled live updates, never provider/worker availability. Private event payloads and evidence rechecks remain unchanged.

Settings use Behavior/OpenAI/Rules/Quality/Monitoring. Published progress/badge/action controls execute; request/time/token/retry controls remain bounded. Unsupported prose/word-limit/deadline/cost-ceiling/fallback/model-specific controls are locked in UI and reject non-default API writes. Store/parallel execution remain false and verification tool choice required. Language selection is available; human escalation remains enabled. Restrictions respect scope. No guessed model capability list or default provider pricing.

Workspace action routes preserve destination/workspace. A destination gate validates the accessible workspace list and awaits the existing authorized selection API before mounting page queries. Financial actions remain read-only navigation. Funding opens the existing overview; offers/campaigns/integrations retain their pages.

Monitoring supports dates, scope, locale, status, model, retry and review outcome filters; bounded sample percentages and matching totals; timestamped provider-success/worker-execution/dispatch evidence. Reverb live health explicitly not measured. EUR cost estimates require deployment-owned exact-model rates in config/support_ai.php; null and priced-request coverage distinguish missing pricing from zero. No billed cost or enforced spend ceiling is claimed.

API contract/examples: docs/api/SUPPORT_REPAIR_HTTP_CAPTURE.json (five real local HTTP requests on isolated SQLite with synthetic actor), docs/api/README.md and docs/api/openapi.yaml. No route or migration added. Capture server and generated token/database are temporary and removed after capture.

Validation: full Support + OpenApiCoverageTest: 185 tests, 183 passed, 2 database-specific skips, 6653 assertions at prior checkpoint. Added final context/unit checks and final frontend checks recorded in the root docs/ai-support-frontend-repair.md. Targeted PHPStan zero errors; Pint, frontend typecheck/targeted ESLint pass. Twelve controlled browser regressions passed before the settings case was added. Final results recorded in root repair report.

Exact next release action: run original ten real-service support journeys with verified consumer/workspace/admin sessions, real worker, Reverb disconnect/resubscribe and configured provider; verify real model compatibility, supply verified pricing, and verify deployed connectivity. Credentials/services not supplied; controlled browser tests are not proof of production connectivity. No AI financial activation or cashback-timing decision changed.


## Super Admin cockpit reporting correction — 2026-10-31

Implementation complete locally; production/provider acceptance remains PARTIAL. Backend HassanDev base 59916e9; sibling frontend HassanDev base 3d0b0ad. Uncommitted; no commit/push/deployment. Existing unrelated edits preserved.

Current-state cards omit unsupported comparisons; the previous-period query now reads only commission events and uses equal business-calendar days across DST. Voucher distribution counts issued vouchers using explicit immutable acquisition_transaction_id evidence, approved/nonduplicate transactions and matching consumer/webshop; additional networks are dynamic. Missing evidence remains Other/Unknown, including direct/legacy issuance. No wallet-funding or selected-network inference. VoucherInventory::recordAcquisition is a trusted internal evidence writer, with no public mutation endpoint; no existing affiliate conversion flow issues vouchers automatically. Do not backfill links without evidence.

Voucher search uses normalized webshop-scoped exact hashes with a code_hash index. Shared usable scope and consumer code-copy eligibility exclude invalidated/invalid/mismatched codes, expired vouchers and invalid unique-code states. Consumer intent is independent of merchant memberships; active platform staff and explicit ADMIN_TEST_ACCOUNT_PUBLIC_IDS are excluded consistently. Failed webhook imports deduplicate with connector errors per connection; failed affiliate connections include reauthentication. Source status controls resolution; incident occurrences and failed jobs are diagnostic, not additive. Capped source-item links and attention=1 operations preserve exact affected connections.

Commission and voucher drill-downs preserve inclusive business-timezone from/to dates, status/network and tab; transactions totals use the same query as rows. Missing transaction boundary returns 422. UI supports custom dates with validation, visible-tab 60-second polling and focus refresh. Existing cache TTL remains 60 seconds.

Executed: CockpitTest + OpenApiCoverageTest **20 passed / 5270 assertions** (17 cockpit + 3 contract tests; SQLite). Includes encrypted-code search, invalid code/provider mismatch, mixed memberships/test exclusions, source deduplication/resolution, period dates and DST. **5 Chromium browser contract tests passed** for custom range validation, donut/commission drill-downs, usable-card filters and automatic polling; these use controlled responses from recorded HTTP fixtures, not real-service E2E. Frontend TypeScript and targeted ESLint passed. Targeted backend Pint and PHPStan (0 errors, 512M worker limit) passed.

11 actual local HTTP captures include 200/401/403/422, generated by scripts/capture-cockpit.php using an isolated SQLite database and temporary PHP HTTP server. Task migration 170000 apply/rollback/reapply passed there. It was also selectively applied to the configured existing local MySQL shopbonus database at 127.0.0.1; other pending migrations were not applied by this task. Real stored local aggregates reconcile: active webshops 8, consumers 3, usable vouchers 0, approved period commission EUR 0, wallet liability EUR 0, open actions 1. All sources report ok, no degraded warnings. No production/provider numbers are claimed.

Evidence: docs/api/COCKPIT_HTTP_CAPTURE.json, docs/api/COCKPIT_LOCAL_MYSQL.json, docs/api/README.md and sibling frontend docs/admin-cockpit-reporting.md. New migration: 2026_10_31_170000_add_voucher_acquisition_evidence.php. Frontend tests carry their own captured fixture in e2e/fixtures/admin-cockpit.json and do not depend on a sibling checkout.

Next release action: review the uncommitted patch, apply the task migration in the target environment before deployment, configure explicit test-account IDs, verify real authenticated browser/provider journeys and record acquisition links only from independently verified voucher-source relationships. Full repository suites, production load and live provider attribution NOT RUN. Checklist boxes remain unchecked pending an implementation commit/SHA per AGENTS.md.


AI support final local evidence (2026-10-31): Support + OpenApiCoverageTest 185 tests, 183 passed, 2 database-specific skips, 6659 assertions. Final focused policy/context/admin regression 10 passed/130 assertions (overlapping subset). All 13 controlled Chrome browser regressions passed. Frontend typecheck/targeted ESLint, targeted Pint/PHPStan and diff whitespace checks passed. Historical actions now respect current suggestion policy. Temporary capture server/credentials/database removed. No live E2E credentials supplied; real provider/worker/Reverb/MySQL acceptance remains open. Uncommitted on HassanDev; no commit SHA or deployment. Exact next release action remains the real-service acceptance documented above and in ../docs/ai-support-frontend-repair.md.

## Merchant landing / inline CMS review — 2026-10-31

Implementation checkpoint on HassanDev (backend base 59916e9, frontend base 3d0b0ad); changes remain uncommitted alongside pre-existing and concurrent work. No migration or deployment.

Implemented separate published/draft snapshots, failure-gated serialized saves, debounced autosave with unload protection, stable preview transitions, and atomic expected-revision checking at Publish. Cancel restores the approved publication while saved drafts remain on the server. NL/EN public content follows the shared locale and a persisted cookie; server metadata refreshes with that cookie. Public fetches use no-store; failures retain an already loaded approved snapshot or show an unavailable state, never fallback marketing defaults.

The on-page settings panel provides section drag/drop and keyboard ordering, visibility, structured collection addition/removal/reordering, platform descriptions, benefit/step icons, navigation/footer links, FAQ authoring, SEO text, schedule controls and an explicit historical publication picker. Shared CMS images have an authenticated paginated library, uploads, crop ratios/focal positions, alt text and desktop/mobile variants. Main imagery uses approved media slots with labeled code-native illustrative fallbacks; external stock photographs were removed. The integration query is retained through onboarding and validated against the live catalogue when opening App Store.

Schedule publication uses the existing CmsScheduler API and pins the saved revision, keeping the previous publication live until execution. Further draft saves invalidate that pinned schedule; pending schedule cancellation remains in Content Studio. The existing visibility-window fields can also be applied immediately through Publish. The existing shopbonus:publish-scheduled-content scheduler command must run in the deployment. Schedule visibility checks now run outside the publication cache.

Onboarding diagnosis: BusinessProfile already defaults country_code to NL and explicitly requires KvK for Dutch businesses. Updated the stale missing_fields assertion to kvk_number; no business-verification or activation rule relaxed.

API captures: docs/api/MERCHANT_LANDING_CAPTURE.json, from a local HTTP server with isolated SQLite and synthetic actor/session context (not browser-auth proof). New media GET aliases and optional Publish revision are documented in OpenAPI/README. No production credentials used. Temporary capture router is removed after capture.

Validation results are recorded in frontend docs/merchant-landing-acceptance.md. Checklist items remain unticked until a commit records a SHA, per AGENTS.md. Exact next action: review these uncommitted changes separately from other work, run deployment acceptance with real sessions/media and confirm the existing scheduled-content command is running. Full repository backend suite, production build, MySQL/load and deployment/provider acceptance NOT RUN.


Final verification note: a later full frontend typecheck reported TS2307 in src/components/over-ons/AboutUsInlineCms.tsx:27 (missing ./AboutUsCmsControls). This is outside the support changes; earlier full typechecks passed. Targeted support lint and browser checks are separate evidence. No unrelated About Us edit was made.


## Sales attribution review follow-up — 2026-10-31

Local implementation, acceptance PARTIAL. Backend HassanDev base 59916e9; frontend HassanDev base 3d0b0ad. All changes remain uncommitted alongside pre-existing work; no commit, push, migration or deployment.

API-driven merchant analytics replaces sample figures and toast-only actions, with 7/30/90-day business-timezone sales series, currency selection, scoped order drill-down and actual voucher/banner navigation. Embed tracking now requires the latest recorded analytics+marketing CMP decision; storage/assignment/measurement fail closed and withdrawal clears metadata and revokes assignments. Assignment tokens (including holdout) and retry-safe click IDs reach the cart metadata bridge. Unknown viewability emits no impression; bounded queues preserve UUIDs across retries. Registered storefront origins get credential-free embed CORS.

Voucher attribution matches per-shop hashes. First linked evidence and the order label/click source stay consistent inside an order-row lock; unattributed orders may gain first evidence. Network totals read authoritative order refunds and separate currencies, wallet kinds and units, including available consumer credit. Moneybird is explicitly provider_not_verified; internal drafts/references are not promoted into issued invoices.

Final affected PHP run: 55 tests, 53 passed, 2 skipped, 5638 assertions / 70.749s (SalesAttributionE2E + Banners + OpenApiCoverage, SQLite). The 2000-event timing assertion passed with its original 60-second budget after batch-local consent/campaign/version/assignment lookup caching and once-per-batch daily-stat initialization. Earlier runs failed the timing budget (80.97s, 111.00s, 60.66s); no sample/threshold was weakened. The broader Analytics/Banners/CMP/OpenAPI run earlier passed all 75 application tests with 2 skips, but had 2 YAML errors; those were corrected and final OpenAPI tests passed in the 55-test run.

Public embed lifecycle harness: 8/8 passed. Frontend Chromium contract fixtures: 2/2 passed (API totals, date requests, currencies, drill-down, links, error/retry); these are fixtures, not provider evidence. Frontend typecheck passed. Targeted Pint and PHPStan passed before the final batch-stat refinement; its final check is recorded below. git diff --check passed on touched files. Six existing API contracts contain actual isolated local HTTP captures in docs/api/SALES_ATTRIBUTION_CAPTURE.json; temporary loopback fixture router/server removed.

Details and storefront contracts: docs/analytics/SALES_ATTRIBUTION_REVIEW.md. No new route or migration. Checklist intentionally remains unchecked until a commit provides the required SHA. Remaining: actual host CMP/storefront adapters must implement getConsentId and setAttribution and be verified through signed paid orders; live randomized holdout completeness, MySQL and one-million-event staging acceptance NOT RUN. Moneybird has no delivery/reconciliation client or verified credentials/account/legal acceptance; provider integration remains open. Exact next action: wire and verify each supported storefront bridge, then implement and authenticate Moneybird issuance/delivery/status reconciliation under OPEN-FINANCIAL-LEGAL and execute staging/provider acceptance.

Verified backend checkpoint: focused MerchantLandingCmsTest / OnboardingTest / OpenApiCoverageTest: 36 passed, 5,358 assertions (SQLite). Targeted PHPStan: 0 errors. Targeted Pint passed after formatting the added CMS tests. Earlier OpenAPI parse failures were resolved before the successful final run; concurrent unrelated changes are preserved.

Final quality closeout: targeted Pint and PHPStan passed after the final batch-stat refinement; frontend typecheck passed. Temporary capture router is absent and its server was stopped. Local source changes are ready for review; the explicitly listed storefront and Moneybird implementation/acceptance work remains open.

Final API documentation recheck after request-example updates: 3 passed / 5123 assertions / 16.608s.


Final rerun resolution: the unrelated AboutUsCmsControls module subsequently appeared and the full frontend typecheck passed again. Targeted support ESLint passed. After the final stale-history generation guard, human-reply and reconnect browser checks passed; the refresh check first timed out in page.goto (ERR_ABORTED before support assertions), then passed alone on retry. The earlier complete 13-case browser suite passed. Shared local test-results also contained concurrent unrelated homepage/CMS runs; no unrelated files or processes were changed. Final backend PHPStan remains zero errors, and the final policy-focused regression passed. Current release limitation is live-service acceptance, not a known support test failure.


## Homepage + inline CMS review correction — 2026-10-31

Backend HassanDev/base 59916e9; frontend HassanDev/base 3d0b0ad. Uncommitted, unrelated edits preserved. No migration, commit/push or deployment. Frontend report: sibling frontend docs/homepage-cms-review.md.

Backend validates the rendered benefits/CTA/FAQ sections, their plain text/safe links, extended order/visibility keys and existing responsive image asset references. Admin history includes resolved revision content for inspect/select/confirm restoration. Homepage public reads check schedules every request; homepage/coupon HTTP responses use no-store, matching uncached Next.js/client requests. Existing service publication/restore audit and authorization paths retained.

Executed: HomepageCmsTest 10 passed/66 assertions; expanded HomepageCmsTest + CmsScheduleAndSuggestionTest + OpenApiCoverageTest 22 passed/5293 assertions (SQLite). Five real local HTTP captures in docs/api/HOMEPAGE_HTTP_CAPTURE.json (public EN homepage/coupons, admin history 200/401/403), isolated fixture DB and synthetic actors; temporary server/database/tokens removed. TypeScript passed at checkpoint. Targeted Pint formatted two task-owned files; frontend browser/lint final results are recorded in the frontend report. No production/provider acceptance is claimed.

Next: finish controlled browser regression verification, review uncommitted patch, then run real authenticated Super Admin/public NL/EN journeys and compare the approved design reference when available. Checklist stays unchecked without implementation commit/SHA.

Homepage final verification: TypeScript PASS; targeted frontend ESLint PASS (zero errors, five unused suppression warnings); targeted backend Pint PASS; 22 backend tests/5293 assertions PASS after API documentation changes. Eleven core Chrome browser regressions and responsive layout PASS; coupon destination/expiry isolated rerun PASS, timing now controlled by the browser clock. Desktop/mobile screenshots inspected. Real-service acceptance remains unverified; full report and final deterministic-test result are in sibling frontend docs/homepage-cms-review.md.

Final deterministic coupon clock check PASS (1 test, 11.8s); isolated desktop/mobile rerun PASS (1 test, 6.4s). The earlier parallel layout retry timed out awaiting navigation load; fresh preview and explicit DOM/content readiness resolved the verification run. All 13 browser scenarios have passing evidence across the main run and these isolated reruns.

Merchant inline CMS final browser evidence: 14 cases have passing focused executions (controlled APIs), including save-before-navigation/failure retention. Targeted ESLint and PHPStan pass; 36 focused backend tests/5,358 assertions pass. Desktop/mobile fixtures were visually reviewed; see frontend docs/merchant-landing-acceptance.md. Full typecheck has reported unrelated concurrent About Us/discovery errors; no production acceptance claim. Changes remain uncommitted, no migrations.

Final typecheck limitation update: the last whole-frontend run reports missing generated .next-about-cms/dev/types files during concurrent test cleanup (TS6053). Earlier About Us/discovery source errors were also outside this task. Do not report whole-repository typecheck/build as green.
# About Us inline CMS review handoff — 2026-10-31

Branch HassanDev, base SHA 59916e9; all changes uncommitted, no push. Preserved
unrelated work. No migration, new endpoint or deployment configuration change.
Frontend publishes only after a successful save and sends its saved revision;
AboutUsCmsController passes the optional revision to the existing atomic
CmsService publish guard. Draft races return 409. Responsive media validation
and URL resolution are additive. History includes comparison content; restore
is constrained to the requested locale. Public About Us is no-store and reads
visibility windows on every request. Scheduling reuses the generic CMS queue.

Executed checks: AboutUsCmsTest + OpenApiCoverageTest, 17 passed, 5207 assertions;
targeted Pint passed. Five real HTTP responses captured by scripts/capture-about.php
using an isolated temporary SQLite database; no live company content published.
docs/api examples updated via scripts/document-about-capture.cjs.

Exact next release action: supply approved company/team images and verified
records through the new inline controls, compare desktop/mobile against the
approved design, and verify the existing CMS scheduler worker in deployment.
Keep release acceptance PARTIAL until that evidence exists. Sibling frontend
docs/about-inline-cms-acceptance.md distinguishes fixture tests from release
evidence. No business facts or team identities were invented.



## Live storefront studio review correction — 2026-10-31 / 2026-11-01

PARTIAL whole-card acceptance; implementation and focused validation are being finalized. Backend HassanDev base 59916e9, frontend HassanDev base 3d0b0ad; all changes uncommitted alongside existing work. No migration, commit/push or deployment. Actual storefront frame plus opener fallback, strict authenticated messaging, failure-gated Save/Publish, timestamped currency-aware native cart contract, bounded publication refresh, functional size handles/corner snapping/inline missing-target warning, explicit unsupported viewability and editable AI text/image/CTA layers. Details and exact next acceptance actions: docs/banners/STOREFRONT_REPAIR.md. Real storefront adapters/themes and genuine model/merchant acceptance NOT RUN. API examples captured on a real isolated local HTTP server in docs/api/BANNER_STUDIO_HTTP_CAPTURE.json; fixture server/database removed by the script. Final test results follow below.


Final local verification — 2026-11-01: 35 focused PHP tests, 33 passed and 2 existing database-specific skips, 5,452 assertions. Twelve embed lifecycle tests passed. All eight Chromium fixture regressions passed (1.0m): Save 409/500 failure gates, real iframe embed/live edits/publish and mobile frame, resized dimensions/layers including frame-exit completion, inline target recovery, forged origin/source rejection, native popup/opener pairing and stable corner snapping. Whole frontend TypeScript and targeted ESLint passed. Targeted PHPStan: zero errors; targeted Pint and diff whitespace checks passed. Five real HTTP captures include public config, authorized/unauthorized editor draft and completed AI show/index (explicitly synthetic provider; no live model claim). Capture database/server removed; dedicated frontend test server stopped. Whole-repository suites/build/MySQL/real storefront and provider acceptance NOT RUN. Uncommitted on HassanDev, backend base 59916e9/frontend base 3d0b0ad; no migration or deployment. Exact next action: review the isolated patch, deploy/configure FRONTEND_URL and verify actual merchant themes/opener policy, platform cart adapters and genuine model/human design acceptance.

Final cleanup recheck: frontend TypeScript passed after removing only this task's temporary Next.js type include entries. Final OpenAPI rerun passed (3 tests, 5,123 assertions; overlapping the focused suite). Final touched-file whitespace check passed. No banner capture SQLite database or capture log remains.

## Affiliate detail review — 2026-11-01

PARTIAL whole-card acceptance; local implementation verified on HassanDev, base 59916e94c26cada8696939845e40deca6d1d4697. Existing uncommitted edits preserved, no commit/push/deployment or application-database migration. Settlement gate, shared policy, bounded pagination, PHP 8.5 SOAP, image validation/variants and enrichment refresh were already in the working tree and verified. This continuation fixed research evidence-ID collisions, added audited/versioned Super Admin advertiser-permission recording and invalidation on merchant edits/duplication, and corrected detail consent/state lifecycle.

Executed PHP 8.5 SQLite: 81 tests, 80 passed, 1 skipped, 5721 assertions; final OpenAPI 3 passed/5123 assertions. Targeted Pint/PHPStan (zero errors), frontend TypeScript/ESLint and 3 Chromium fixture journeys pass. Four real local HTTP captures in docs/api/AFFILIATE_DETAIL_HTTP_CAPTURE.json; temporary server/database removed. Broader Offers run has an unrelated voucher claimed_at failure (44 tests, 42 passed, 1 skipped, 1 failed); full repository green is not claimed.

Exact next action: review patch and the two pending affiliate migrations, verify provider endpoints/response fields and promotion/exclusion mappings with authorized accounts, then execute authenticated browser/MySQL/staging and reference-image acceptance. New operator commands and all limitations: docs/discovery/AFFILIATE_DETAIL_REVIEW.md. Checklist remains unchecked pending a commit SHA; provider and full-card acceptance remain open.

## Handoff — multilingual CMS review, 2026-11-02
Branches HassanDev; backend HEAD 59916e9, frontend HEAD 3d0b0ad. Uncommitted review repair on top of extensive unrelated changes. No migrations/new packages/deployment. Decision: require revision in manual CMS publish; upgrade all consumers alongside backend. Preview returns draft_revision. Legal version/date/approval remain mandatory. Mutable CMS/FAQ/consent public responses use no-store while the existing Laravel versioned cache remains. Optional scripts should register actual enable/disable lifecycle hooks; a loaded script without a shutdown contract causes document reload on revocation. Explicit locale selections have a separate persistence marker; legacy unmarked values are treated as automatic.
Tests: 20 focused CMS/scheduling/consent PASS (278 assertions), API coverage 3 PASS (5123 assertions), targeted Pint PASS; frontend typecheck and targeted ESLint PASS, six node regressions PASS. Nine real local HTTP captures in docs/api/CMS_REVIEW_HTTP_CAPTURE.json. Browser/production/actual-provider acceptance NOT RUN; exhaustive role-wide language audit not claimed.
Next action: review the scoped patch and run authenticated browser journeys in both languages, including legal preview/concurrent-edit/Publish conflict and actual configured SDK withdrawal; then coordinate the revision-required API/client release. Existing checked-out changes remain uncommitted; no commits made. CHECKLIST items intentionally remain unchecked until a tested implementation commit provides its SHA.

Final multilingual CMS validation checkpoint: combined backend CMS/scheduling/consent/OpenAPI run 23 PASS, 5,401 assertions; frontend six node regressions PASS, two real Chrome consent lifecycle regressions PASS (including unmanaged SDK document replacement and repeated re-consent), TypeScript and targeted ESLint PASS; diff whitespace checks PASS. This supersedes the earlier browser NOT RUN note for the focused consent fixtures only. Full authenticated app journeys, exhaustive role-wide translation coverage and real tracker providers remain unverified. No new commits or migrations; unrelated working-tree changes preserved. Exact next action remains review scoped changes and run authenticated NL/EN acceptance before coordinated client/API release.

## Notification email destinations and template UI — 2026-11-02
PARTIAL release acceptance. HassanDev, backend base 59916e94c26cada8696939845e40deca6d1d4697; frontend base 3d0b0adb3c72abc8d1680bc2aa062cae00349e29. Uncommitted; unrelated working-tree edits preserved. No migration, API contract change, commit, push, live configuration change, template publication or customer email.
Email rendering now maps legacy action roots through config/notifications.php to real Next.js pages; environment overrides are documented in .env.example. Workspace support opens /dashboard/support with conversation and workspace context; configured paths reject external destinations and injected queries. Existing FRONTEND_URL supplies the origin. Template UI adds versioned checksum-aware editing, field limits/approved variables/audit reason, readable synthetic preview and existing password/OTP recovery. Independent publication remains enforced by existing backend APIs.
Executed: 18 focused notification action-link/delivery/template PHP tests PASS, 119 assertions; targeted Pint PASS; frontend TypeScript and targeted ESLint PASS; three real Chrome browser tests with intercepted API fixtures PASS (versioned save + preview without publish, stale edit preservation, password/OTP retry). Screenshot visually inspected: sibling frontend docs/notification-template-preview.png. Browser fixtures are not real-provider/authentication acceptance. Initial browser runs failed because the bundled Chromium executable was absent and fixture CSRF interception was missing; final run used installed Chrome and corrected fixtures. Full ten business journeys and real mail transport NOT RUN.
Exact next action: Hassan confirms the five destination defaults and deployed FRONTEND_URL listed in sibling frontend docs/notification-template-review.md, applies approved deployment configuration/cache rebuild, and verifies authenticated email navigation including multiple workspaces. Live provider and full-card acceptance remain pending. Checklist remains unchecked until a commit SHA exists.
Browser harness note: all three cases reported ok, but Playwright remained waiting during Windows webServer teardown after port 3327 had closed. The wrapper was interrupted after completion; no clean aggregate runner exit is claimed. Test assertions passed; server teardown needs follow-up if this dedicated config is used in CI.

## Backend merge resolution — 2026-11-02
Resolved all 33 unmerged paths on HassanDev, HEAD d8a85f9d7b3653133f190fbee3c1bf7c881863c3, incoming MERGE_HEAD ad1166b9783d90f3902aa6fc4adae098c4ad77d8. Existing merge additions and local notification configuration retained; no merge commit, push, migration or deployment performed. The merge remains ready for final review/commit after staging the verification checkpoint.
Preserved independent additions on both sides: notification path environment variables, points pricing/program-specific reward rules, canonical research hashes and same-origin research helpers, support reference parsing/conversation follow-up/FAQ matching, both support cost estimate fields, bounded program-selection refresh, mixed-currency reporting, and queued voucher exports with the claim-date filters shared by list/detail/export. Current HassanDev settlement gating and historical snapshot behavior remain; resolving this merge does not authorize instant credit or relax financial eligibility. Current-state Cockpit cards retain unavailable historical comparisons, and consumer signup identity remains independent of workspace membership. Old fixtures were reconciled with those existing invariants rather than changing production gates to satisfy older assertions. QA inventory now produces repository-relative component names on Windows and Unix.
OpenAPI merged structurally: 419 current paths + 418 incoming paths -> 423 distinct paths. Captured example responses remain intact; independent incoming README capture sections retained. Original YAML formatting preserved for unchanged paths. API coverage: 3 passed, 7,780 assertions. PHP syntax checks on conflicted PHP files passed; full application PHPStan passed with zero errors; targeted Pint and staged whitespace checks passed.
Initial whole-suite run: 1,230 tests; 1,164 passed, 14 failed, 25 errored, 27 skipped (20,561 assertions). It preceded the final compatibility repairs; it is not a final green-suite claim. Focused reruns use OPENSSL_CONF pointing to the installed PHP 8.5 extras/ssl/openssl.cnf, without changing system environment or production config. A Windows POSIX-filemode assertion remains an environment-specific validation limit. Final focused results are appended below.
Exact next action: review the staged merge and final test evidence, run Linux CI/MySQL checks, and create the merge commit when desired. No new schema was authored by the resolution; incoming migrations remain part of the pending merge and were exercised only in isolated test databases. Checklist remains unchecked without a commit SHA.
Final focused rerun: 200 tests, 196 passed, 1 failed, 3 skipped, no errors; 1,832 assertions. The sole failure is QaCiImportTest::test_ci_import_retains_failures_sha_execution_time_and_redacts_artifacts: Windows fileperms returns decimal 438 (0666), while the test requires decimal 384 (0600). Import/redaction assertions pass before that assertion. No test was disabled and production permission checks were not relaxed. Evidence: docs/qa/MERGE_RESOLUTION_2026-11-02.json. Initial full-suite failures are historical; full 1,230-test suite has not been rerun on the final state, so whole-suite green is not claimed. Final API coverage 3 PASS/7,780 assertions and PHPStan zero errors. Follow-up: execute Linux CI (including the POSIX permission assertion) and MySQL before release.
Supplemental conflicted-module regressions: 68 PASS, 565 assertions (support evidence, capabilities/catalog, merchant overview, public discovery, onboarding, research hashing). Combined focused regressions: 268 tests, 264 passed, 1 Windows POSIX-permission assertion failed, 3 skipped, no errors; 2,397 assertions. API coverage is an additional 3 PASS/7,780 assertions. Final staged whitespace check PASS and zero unmerged entries. All resolution changes staged; merge commit and push not performed.

## One CMS NL/EN/DE translation-quality checkpoint — 2026-11-02

PARTIAL / release NO-GO. Backend HassanDev base 926ab34; frontend HassanDev base 5abab50; all implementation changes remain uncommitted. No push, deployment or persistent application DB migration. Owner brief and detailed source findings: docs/cms/ONE_CMS_ACCEPTANCE.md.

Reused the existing CMS suggestion/revision/quota/audit flow: bounded database queue, configurable gpt-6.1-sol Responses JSON contract, source/target freshness and legacy-proposal rejection, protected facts/placeholders/negation/noncash/glossary rejection, model/token/hash provenance (including rejected billed output), NL-save EN/DE enqueue with save-preserving deferral, German CMS/FAQ acceptance, and immutable NL master revision references for stale translation reporting and restore. Frontend change is limited to the translation-status type. AI remains disabled by default and never publishes.

Executed local SQLite CMS/OpenAPI/unit suite: 81 passed, 8,378 assertions; JUnit docs/cms/translation-regression.junit.xml. Targeted PHPStan/Pint and frontend npm run typecheck passed at checkpoint (final status in evidence manifest). Eleven isolated real loopback HTTP captures in docs/cms/TRANSLATION_HTTP_CAPTURE.json; synthetic Sanctum actor/recent-password sessions, not real browser/CSRF or staging proof. Temporary router/server/SQLite fixture removed. No live provider request. Checklist stays unticked without an implementation commit, per AGENTS.md.

Pending migration: 2026_11_02_120000_add_cms_suggestion_provenance (suggestion source hash/model/tokens, immutable revision source_master_revision). Worker: php artisan queue:work database --queue=cms-translations --tries=1 --timeout=45; monitor that queue and keep retry_after >45 seconds. Do not apply to an unspecified deployment.

Exact next action: extend CmsSuggestions segment extraction/replacement with canonical structured field paths for HomepageSchema, MerchantLandingSchema and AboutUsSchema; validate against the original page type/key; route legacy landing AI callers through this existing review queue. Then finish German frontend/editor/email/SEO, the existing media pipeline and Quality Center field/usage executors. No complete discovery or five exhaustive coverage denominators exist yet. All mandatory T01–T23 staging cases remain UNTESTED, and 1,000-record automation, monetary budgets, semantic/language review, real image editing/WebP/restore and release approval remain open. Wallet/voucher/order/affiliate/Answer24/Banner Studio engines untouched.

## Platform launch acceptance follow-up — 2026-11-02
PARTIAL / production NO-GO. Backend HassanDev base 926ab34068e18cca081b7b168cc2ed6f7622fe29; frontend HassanDev base 5abab5083ce7036177d7cbef27688f449d6b0f35. Uncommitted; pre-existing CMS edits preserved. Current code already enforces new affiliate settlement timing and documents public webshop cover; merchant analytics already calls scoped APIs. Updated BusinessJourney to verify pending/approved credit stays unavailable until synthetic settlement and replay credits once. Frontend retires sample transactions/vouchers endpoints with 410/no-store and keys analytics responses by current selection, resolving React effect lint errors.
Executed PHP 8.5 SQLite: 109 PASS / 8,767 assertions (Journey, Affiliate, OpenAPI, Analytics, Wallet, Checkout); targeted Pint, TypeScript and ESLint PASS. Four Chrome assertion cases PASS on rerun; initial cold-compilation navigation timeout recorded. Read-only LOCAL launch check: 9 PASS, 12 FAIL, 4 WARN, 2 MANUAL; docs/release/LAUNCH_CHECK_2026-11-02.json. No deployment, persistent DB migration, provider activation, commit or push. Checklist remains unchecked without implementation commit/SHA.
Exact next action: finish production build verification and review the patch; then run Linux/full CI, MySQL and authentic staging/provider/payment/restore acceptance against a clean release SHA before production rollout. Production target/access and acceptance evidence remain outstanding. Full details: sibling frontend docs/platform-launch-acceptance.md. Historical GO_NO_GO evidence is preserved, not rewritten as a current full audit.

Final launch follow-up validation: frontend production webpack build PASS (exit 0, 65 pages); Chrome aggregate 4 PASS in 1.0 minute (exit 0). Existing metadataBase warning uses localhost for social image URLs and remains a deployment configuration review item. Dedicated port-3331 dev server stopped; generated test/build tsconfig includes removed. No production deployment. Next action is patch review and Linux/MySQL/authentic staging/provider acceptance against a clean SHA; local launch-check failures remain open.

## Merchant dashboard and voucher review — 2026-11-02

PARTIAL release acceptance; local implementation verified. HassanDev backend base 926ab34/frontend base 5abab50; scoped changes remain uncommitted alongside pre-existing CMS and launch edits. No deployment, provider activation, new package or migration. Owner confirmed reserve at explicit payment start; existing quote/Confirm flow already follows that boundary. D-106 no-per-consumer-cap preserved. Reused existing reusable voucher resolution, paginated disputes, protected queued exports and historical snapshots; connected missing UI and added scoped stock/expiry KPIs plus adjacent-period lifecycle comparisons.

Executed: PHP 8.5 SQLite 75 tests, 72 PASS/3 SKIP, 8,462 assertions; PHPStan zero errors; targeted Pint; frontend TypeScript/ESLint; five Chrome fixture journeys PASS (50.5s, exit 0). Three real HTTP captures in docs/api/VOUCHER_DASHBOARD_HTTP_CAPTURE.json. Composer audit clean; npm audit 25 vulnerabilities (22 moderate, 2 high, 1 critical). Full findings, commands/evidence and validation history: docs/merchant-voucher-review.md; JUnit docs/merchant-voucher-review.junit.xml.

Exact next action: review scoped patch and npm advisories, remediate dependencies, run the three pcntl/database race cases on Linux and genuine staging merchant/mobile/worker flows; measure staging P95 below 300 ms on a clean SHA. Do not report concurrency or production readiness as fully passing. Checklist remains unticked without an implementation commit/SHA.

Final review checkpoint: OpenAPI coverage rerun 3 PASS/7,780 assertions; targeted Pint PASS; final TypeScript/ESLint PASS. Five final Chrome cases PASS with clean exit. Dedicated port-3332 dev server stopped; only task-added tsconfig include entries removed. No fixture SQLite database/server/log remains. No commit or deployment.

## Local CSRF endpoint restored — 2026-11-02

Branch `HassanDev`, HEAD `926ab34`; existing uncommitted changes preserved. The reported `http://localhost:8010/sanctum/csrf-cookie` failure was reproduced as connection refused: no backend listener on port 8010. Started PHP 8.4.15 in a hidden background process using `artisan serve --host=127.0.0.1 --port=8010 --no-reload` (launcher PID 16200). No application/configuration changes or migrations.

Executed real HTTP verification with `Origin: http://localhost:3000`: **204 No Content**, both `XSRF-TOKEN` and `shopbonus-session` cookies, `Access-Control-Allow-Origin: http://localhost:3000`, and credentials allowed. Cookie values excluded from recorded output. Runtime repair complete; full login/browser journey NOT RUN. Next action: retry the frontend request; after a backend stop/reboot, restart the same command from the backend directory. Runtime logs: `storage/logs/csrf-server.out.log` and `storage/logs/csrf-server.err.log`. No commit made; documentation checkpoint uncommitted alongside pre-existing work.

## Platform reliability review — 2026-11-02

PARTIAL production acceptance. Backend HassanDev base 926ab34068e18cca081b7b168cc2ed6f7622fe29;
frontend HassanDev base 5abab5083ce7036177d7cbef27688f449d6b0f35. Uncommitted;
existing unrelated edits preserved. No commit, push, deployment or application DB migration.
Full finding dispositions and exact next release actions: docs/reliability/REVIEW_2026-11-02.md.

Verified already-present OTP logging removal, PHP 8.5 SOAP signature, private
backup roots and cover OpenAPI path. Added restored encrypted dispute/QA
attachment integrity/reference regression; it exposed and fixed Windows nested
archive separators. Extended existing observations to configured connection/queue
pairs, worker polling, Redis ready counts and explicitly labelled enqueue age.
Exact Redis ready wait remains UNKNOWN. Native Redis interface mocked; real
Redis infrastructure acceptance NOT RUN. Added admin cockpit operations panel,
missing/error/refresh states and removed the old inferred Online indicators.
Owner explicitly confirmed management allocation reporting; formal revenue
recognition remains unverified. API recovery targets are explicitly UNAPPROVED;
offsite/key recovery/infrastructure drill remain false/unverified. Hourly logical
backups do not establish a 15-minute RPO.

Executed: PHP 8.4 combined Reliability/SOAP/OTP/OpenAPI 76 tests: 72 passed,
4 skipped, 8188 assertions. Skips: three MySQL concurrency cases and Windows
symlink privileges. Final operations/OpenAPI rerun: 10 passed, 7831 assertions
(includes final worker-event/throttle and recovery-target guards). PHP 8.5.0
SOAP: 14 passed, 98 assertions; exact PHP 8.5.11 unavailable/NOT RUN; existing
WAMP Xdebug DLL startup warning. Targeted Pint passed; targeted application
PHPStan zero errors. Frontend TypeScript/targeted ESLint passed; real Chrome
fixture regression passed, screenshot visually inspected. Three actual local
HTTP captures in docs/api/RELIABILITY_REVIEW_HTTP_CAPTURE.json; fixture DB,
server and log removed. JUnit evidence: docs/reliability/review-*.junit.xml.
Full repository, production-volume restore, live Redis/provider and deployed
browser acceptance NOT RUN. No migration needed. Checklist stays unticked until
an implementation commit/SHA exists, per AGENTS.md.

Exact next action: review scoped patch, configure/restart real workers with the
JSON target map, verify the authenticated combined UI and alert delivery, then
independently verify offsite copies, secure APP_KEY/backup-key recovery and a
timed production-version/volume infrastructure recovery drill. Establish costs
and feasible data-loss/recovery evidence before requesting RPO/RTO approval.
Do not mark whole Trello #30 DONE.
