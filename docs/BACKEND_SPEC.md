# Shopbonus — Backend Consolidated Specification

Source: Trello Shopbonus board export, 2026-10-23. This is a synthesis of a **requirements snapshot**, not an audit of existing source. Full verbatim descriptions: `requirements/TRELLO_CARDS.md`.

## Goal and ownership

Laravel 13/PHP 8.3+ (target; verify installed version), versioned `/api/v1` JSON REST API, Sanctum, relational DB with constraints, Redis-backed queues and applicable Reverb broadcasts. Frank owns the **backend**. Hassan's cards are read to extract needed backend endpoints/contracts, not frontend implementation assignments.

## Domain boundaries

| Domain | Boundary, invariants, examples |
| --- | --- |
| Identity | One user identity, NL/EN flows; verified email OTP, password recovery; consumer/merchant roles and admin are server-controlled; consent/versioned legal acceptance. |
| Workspaces | Shop-per-tenant memberships, scoped roles/permissions; owner manages multiple shops; aggregate owner results only for allowed shops; audited and restricted admin support access. |
| Merchant onboarding | Business lookup/verification, legal agreement version and immutable acceptance snapshot, pending/approved state; no unsigned/inaccurate automatic activation. |
| Order adapters | Provider-specific authenticated webhook/API/poll integration into normalized orders, events, paid/refund states and order sheet feeds. No universal script can prove payment. |
| Answer24 | Phone + lawful basis + verified paid order; 1-hour grouping, cancellable contact queues, signed handoffs, 90-day single-use voucher claims. Answer24 handles calls and approved reminder communications. |
| Offers | Multi-offer per shop, constraints, validity, snapshot semantics, unique/reusable code inventory, CSV imports, atomic code allocation, reservations, issue vs redeem distinction, expiry/complaint/replacement. |
| Consumer wallet | One non-cash, nontransferable ledger for virtual redemption credit/points, available/pending/reserved/spent. Immutable entries and refunds/reversals, no negative spendable balances. |
| Merchant funding | Separate shop-funded wallet; €10 reward + 30%-of-reward (€3) fee = €13 funding obligation in the example; Mollie iDEAL funding, Moneybird invoice, sponsor/source reconciliation. |
| Affiliate | Daisycon/TradeTracker adapters, tracking, network selection based on eligible commission, status updates, dedupe, review/reversal, per-consumer reward economics; **timing of spendable credit is unresolved**. |
| Voucher checkout | Atomic wallet reserve/debit and code allocation, optional verified Mollie remainder, webhook replay safety, reconciliation and chargeback handling. |
| Consumer API | Public webshops/offers, profile/preferences, favorites, customer dashboard, wallet, vouchers, support, activation; strict PII ownership. |
| Merchant API | Connections, orders, offers, codes, claims, metrics, campaign configurations and merchant wallet; every lookup filtered by authorized webshop. |
| Admin | Platform totals, search, approval, voucher/payment/affiliate reconciliation, exceptions, security/audit/health, content, AI support settings; all with explicit permission checks. |
| Content/privacy | Versioned multilingual CMS and FAQs; cookie consent and audit; no optional tracking before opt-in where required; marketing preferences distinct from mandatory account mail. |
| Promotion/analytics | Versioned server campaign configurations, safe public embed config and signed editor access, consent-aware events/click attribution, actual paid-order correlation, holdout experiments and audited reporting. UI/editor itself is frontend-owned. |
| AI support | Shopbonus-specific verified answer backend, scoped Laravel tool allowlist, source/freshness checks, one targeted retry, escalation, sanitized broadcasts, feedback and human review. |

## Integration capability matrix

Production-priority adapters mentioned in the board: WooCommerce, Wix, BigCommerce, Ecwid, Shopify, Lightspeed C-Series; additional adapters mentioned: PrestaShop, OpenCart, Lightspeed E-Series, Shopware 6, Magento/Adobe Commerce, Squarespace, Mijnwebwinkel and CCV. Supported order, auth, catalog, coupon, inventory, install, cart and storefront capabilities **must be verified provider-by-provider**. Never mark an integration fully connected until its required real/sandbox verification passes.

Other providers: Answer24 (restricted, signed outreach + voucher handoff), Daisycon and TradeTracker (affiliate attribution and commission APIs), Mollie (server-verifiable payment, iDEAL/shortfall), Moneybird (accounting/invoices), Google business lookup (suggestions never substitute for ownership/contract verification), OpenAI configurable supported Responses API model for verified help. Reuse existing vendor adapters where present.

## Suggested high-level backend contracts

Verify/retain existing compatible routes rather than blindly changing them:

- `/api/v1/auth/...` signup/login/logout/OTP verification/resend/password recovery; `/api/v1/me` and account preferences.
- `/api/v1/public/webshops`, `/api/v1/public/offers`, published legal/FAQ and consent settings.
- `/api/v1/workspaces/...` creation/members/invitations/business verification/agreements/connections/activation.
- `/api/v1/workspaces/current/orders`, `/offers`, `/voucher-codes`, `/campaigns`, `/wallet`, `/integrations/health`.
- `/api/v1/consumer/dashboard`, `/profile`, `/wallet`, `/vouchers`, `/favorites`, `/checkout/quotes`, `/checkout`.
- `/api/v1/admin/...` cockpit, webshops, users, financial/affiliate reconciliation, CMS, security, tickets and diagnostics.
- `/api/v1/ai/chat`, `/api/v1/ai/messages/{message_id}/feedback`, `/api/v1/ai/knowledge/search` (scope constrained), privileged AI admin settings.
- External callbacks: provider-specific, signature-verified, per-provider idempotency keys and stable scoped identifiers.

Contracts require: typed payloads, auth and ownership matrix, precise integer money units, resource visibility, error codes, correlation IDs, documented state transitions, pagination, indexes and tests.

## High-risk non-negotiable rules

1. Consumer wallet != merchant funding wallet; neither is a cash-out product.
2. 30% direct merchant Shopbonus fee is calculated on reward amount, not basket/order value; affiliate network economics are distinct.
3. Paid order alone is not sufficient legal basis for marketing outreach; Answer24 owns outreach execution.
4. Code reserved at Answer24 handoff is different from code issued/claimed by verified consumer; do not expose unclaimed codes.
5. Order starts/clicks/views ≠ paid orders. Attributed revenue ≠ incrementality; use valid consent/experiments.
6. Payment, wallet debit, voucher issue and sponsor obligation are unique and idempotent; recovery/reconciliation tracks exceptional states.
7. A voucher code reported as `issued` is not necessarily accepted or redeemed by provider; store provider verification separately.
8. Refunds/rejections may require adjustments, not deletion or automatic reversal of legitimately spent wallet value.
9. Preserve historical issued voucher terms even if an offer is later paused/edited.
10. Do not automatically publish AI-generated copy or unverified account/money answers.

## Important unresolved requirements

- **OPEN-AFFILIATE-CREDIT-TIMING:** Card #19 and #22 describe spendable credit after commission approval; card #23 describes immediate spendable credit for a valid attributed conversion even when later rejected. Must choose a documented, versioned risk policy before release of affected crediting path; model must be capable of both states while keeping audit ledger intact.
- **OPEN-REWARD-UNITS:** Some cards use integer `points`, others use EUR-denominated `EUR_REDEMPTION_CREDIT`. Define conversion/unit, presentation, expiry and accounting without inventing a rate.
- **OPEN-FINANCIAL-LEGAL:** Confirm Dutch seller of record, VAT, refunds, merchant invoicing, promotion/contact lawful basis and contract terms before accepting live payments or auto-marketing.
- **OPEN-PROVIDER-CAPABILITIES:** Verify current supported provider APIs/webhooks (esp. Shopify, Wix, Lightspeed, Mijnwebwinkel, CCV) and whether real redemption verification is available.
- **OPEN-EMPTY-CARD-27:** "App store for platforms" has no description; don't infer implementation.

## Definition of backend readiness

Correct tenant isolation, contract-compliant versioned endpoints, migrations/indexes/factories, webhook verification, idempotency, audited money operations, replay tests, complete retry-safe queues, monitoring, documented consent choices, measured performance, deployment/rollback plan and actual executed test evidence. External integrations remain `BLOCKED`/`NOT VERIFIED` until authenticated sandbox/production testing has happened.
