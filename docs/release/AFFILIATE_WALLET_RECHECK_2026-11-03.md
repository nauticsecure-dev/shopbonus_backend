# Affiliate tracking and central wallet findings recheck — 2026-11-03

Status: the three reported code findings are resolved in the current working tree; provider-backed acceptance remains PARTIAL. This recheck changed documentation only and preserved existing implementation changes. Both repositories are on HassanDev at bd76ff420cda1aa180e45e44a521ff0707670b3f with substantial pre-existing uncommitted work. No commit, deployment, migration, live import or policy activation was performed.

## Findings and current contract

- Consumer messaging: frontend src/app/mijn-voordelen/page.tsx uses reward-terms wording, displays actual pending cashback separately from available credit, and identifies checkout reservations separately. ShopBonusContext reads pending_cashback_minor with pending_minor fallback. The statement that the backend defaults to approval plus settlement is stale: config/shopbonus.php and the owner decision of 2026-11-02 select on_validated_attribution for new evaluations. Historical frozen approval/settlement obligations retain their original timing. This recheck does not change that policy.
- Points checkout: frontend src/app/afrekenen/[slug]/page.tsx already accepts either positive price_minor or price_points, defaults points-only offers to points, and provides a selector for dual pricing. quoteCheckout/startCheckout send wallet_unit explicitly. The summary formats integer points, insufficient_points blocks purchase, and unit changes clear the quote/terms/idempotency key. View only requests a quote.
- Affiliate evidence: AffiliateTest.php:488 already expects platform_allocation_minor. FinancialSources includes consumer_minor=700, platform_allocation_minor=300 and unrecognized_residual_minor=300 for EUR 10 at a 70% share. The latter two describe the same remaining allocation; they are not two additional EUR 3 amounts. accounting_basis is management_allocation and revenue_recognition_status is unverified. Keep this contract rather than remove the field to match an older expectation.

## Executed verification

- PHP 8.4.15 affiliate directory: 63 tests, 62 passed, one skipped, 478 assertions, exit 0. Initially quiet while running; it ultimately completed successfully.
- PHP 8.5.0 exact reported regression: one passed, 17 assertions, exit 0. Affiliate directory rerun: 63 tests, 62 passed, one skipped, 478 assertions, exit 0. Final JUnit: affiliate-review-2026-11-03.junit.xml. Skip is the existing cover/image cross-reference to MerchantEnrichmentE2ETest, not a passed provider test. PHP 8.5 emits an existing mismatched Xdebug-extension startup warning.
- Playwright with installed Chrome and local Next on port 3025: four passed (points-only, insufficient points, dual-unit selection, pending/available/reserved wallet display). API/CSRF responses are intercepted fixtures. Local server stopped after verification.
- TypeScript passed. Targeted ESLint for checkout, benefits library, ShopBonusContext and both browser specs passed. Including the benefits page reports five existing react-hooks/set-state-in-effect errors at lines 161, 214, 218, 224 and 286, plus one unused suppression warning. No whole-frontend lint pass is claimed.
- Full backend/frontend suites and MySQL concurrency NOT RUN.

## Outstanding provider acceptance

Run against an authorized acceptance account and release SHA, preserving redacted provider references and timestamps:

1. Import a genuine attributable Daisycon conversion and TradeTracker conversion; verify connection/program/store/click ownership and network statuses. Save native payload references, import run IDs and resulting transaction IDs.
2. For a settlement-gated historical obligation, verify approval alone remains pending, attach actual settlement reference/date/amount/currency evidence and prove credit occurs once. New immediate-policy rewards must follow their frozen timing instead.
3. Configure an explicit versioned program-bound points rule; record share, rate, multiplier, rounding and resulting integer points. Verify later rule changes do not rewrite historical calculations and points are not implicitly converted to EUR.
4. Replay identical imports and later rejection/reversal updates. Reconcile one attribution/reward source, ledger entries and available/pending/reserved balances under the frozen policy, including already-spent credit and any review flags. Preserve EUR 7 consumer/EUR 3 management allocation evidence without claiming recognized revenue.

Synthetic fixtures do not establish authentic provider operation, settlement, configured deployment rules or live financial reconciliation. Exact next action: review existing uncommitted fixes, select the acceptance release SHA and collect the four evidence sets above. Checklist remains unchecked until an implementation SHA and applicable acceptance evidence exist, per AGENTS.md.
