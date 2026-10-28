# Shopbonus Trello cards — Requirements snapshot

Source: Trello board https://trello.com/b/MS0DLCAX / uploaded export on 2026-10-23. Card descriptions preserved as exported; not every requirement is backend work. Backend agent must NOT implement frontend UI or browser plugins just because a referenced card describes them. Blank cards are unscoped.

## Card index

| Card | List | Title | Details |
| --- | --- | --- | --- |
| #4 | Frank | [Registration & Login](https://trello.com/c/W59a8uF0/4-registration-login) | 9876 chars |
| #5 | Frank | [MULTILINGUAL INLINE CMS & COOKIE CONSENT](https://trello.com/c/J312wM3L/5-multilingual-inline-cms-cookie-consent) | 8132 chars |
| #1 | Frank | [UNIVERSAL WEBSHOP ORDER ADAPTER](https://trello.com/c/hWHy7L8q/1-universal-webshop-order-adapter) | 11503 chars |
| #16 | Frank | [Webshop Onboarding & Workspace Management](https://trello.com/c/UHnIQN5s/16-webshop-onboarding-workspace-management) | 12125 chars |
| #17 | Frank | [Webshop Dashboard, Offers & Voucher Management.](https://trello.com/c/PBDyFIlm/17-webshop-dashboard-offers-voucher-management) | 12450 chars |
| #18 | Frank | [Consumer Platform, Webshop Discovery & Activation](https://trello.com/c/3TxvIq5j/18-consumer-platform-webshop-discovery-activation) | 11022 chars |
| #19 | Frank | [Affiliate Tracking & Central Points Wallet](https://trello.com/c/QBffQWek/19-affiliate-tracking-central-points-wallet) | 12346 chars |
| #20 | Frank | [Voucher Checkout, Mollie & My Vouchers](https://trello.com/c/KPfe6qGp/20-voucher-checkout-mollie-my-vouchers) | 11574 chars |
| #21 | Frank | [Super Admin Cockpit, Security & Monitoring](https://trello.com/c/IsmQwCbL/21-super-admin-cockpit-security-monitoring) | 12580 chars |
| #22 | Frank | [Complete Platform Integration, E2E QA & Production Launch](https://trello.com/c/SxfIv4Ir/22-complete-platform-integration-e2e-qa-production-launch) | 6000 chars |
| #23 | Frank | [Existing System Audit, Integration Improvements & Business Rules](https://trello.com/c/rclMXYPz/23-existing-system-audit-integration-improvements-business-rules) | 13507 chars |
| #25 | Frank | [Live Banner Studio on the Merchant's Own Website](https://trello.com/c/zmIBNaB7/25-live-banner-studio-on-the-merchants-own-website) | 9568 chars |
| #26 | Frank | [Chatbox customers and webshop help openai 6](https://trello.com/c/aWBrCkQK/26-chatbox-customers-and-webshop-help-openai-6) | 12860 chars |
| #27 | Frank | [App store for platforms](https://trello.com/c/OqUwpiPu/27-app-store-for-platforms) | 0 chars |
| #11 | Hassan | [.](https://trello.com/c/WNl2kdvn/11-untitled) | 0 chars |
| #12 | Hassan | [.](https://trello.com/c/bMjOyDbg/12-untitled) | 0 chars |
| #13 | Hassan | [.](https://trello.com/c/yEggKI6u/13-untitled) | 0 chars |
| #15 | Hassan | [.](https://trello.com/c/DfUjJo02/15-untitled) | 0 chars (archived) |
| #10 | Hassan | [CONSUMER PROFILE, ACCOUNT SECURITY & EMAIL PREFERENCES](https://trello.com/c/2Twcoxxt/10-consumer-profile-account-security-email-preferences) | 12053 chars |
| #9 | Hassan | [MERCHANT ONBOARDING: STEP 2 — FIRST VOUCHER SETUP & LIVE PREVIEW](https://trello.com/c/GPj1W9ug/9-merchant-onboarding-step-2-first-voucher-setup-live-preview) | 11892 chars |
| #8 | Hassan | [MERCHANT DASHBOARD: LIVE OVERVIEW, OFFERS, NOTIFICATIONS & CLAIM ANALYTICS](https://trello.com/c/oU8MDcnh/8-merchant-dashboard-live-overview-offers-notifications-claim-analytics) | 12592 chars |
| #7 | Hassan | [MERCHANT OFFERS & DISCOUNT CODE MANAGEMENT](https://trello.com/c/1PbpnAsV/7-merchant-offers-discount-code-management) | 13076 chars |
| #6 | Hassan | [SUPER ADMIN COCKPIT: COMPLETE EXISTING DASHBOARD & VERIFY LIVE DATA](https://trello.com/c/kyD7U0Kv/6-super-admin-cockpit-complete-existing-dashboard-verify-live-data) | 11782 chars |
| #24 | Hassan | [Sales Attribution, Voucher Tracking & Network Analytics](https://trello.com/c/RZmvUIky/24-sales-attribution-voucher-tracking-network-analytics) | 9223 chars |
| #2 | Howto | [FOUNDATION & DEVELOPMENT STANDARDS](https://trello.com/c/kyOXNc2O/2-foundation-development-standards) | 10056 chars |
| #3 | Howto | [COMPLETE PROCESS FLOW](https://trello.com/c/0zb8iXxJ/3-complete-process-flow) | 10461 chars |

---

## Trello #1: UNIVERSAL WEBSHOP ORDER ADAPTER

List: **Frank**. Card: https://trello.com/c/hWHy7L8q/1-universal-webshop-order-adapter. Archived: False.

# ShopBonus — Universal Webshop Order Adapter & Answer24

## OBJECTIVE

Build one centralized webshop integration system. Merchants connect their ecommerce platform during onboarding or through Dashboard → Integrations, verify a real order and view incoming orders in a live Excel-like Order Sheet.

ShopBonus manages connections, orders, vouchers and customer activation. Answer24 handles only legally permitted customer contact.

Use one shared Laravel adapter architecture, not separate applications for each platform. Target approximately two minutes for straightforward self-service connections where technically feasible.

## SUPPORTED PLATFORMS

**Production priority**

- WooCommerce: native webhooks.
- Wix: order automation with HTTP POST.
- BigCommerce: restricted API token, webhook and order lookup.
- Ecwid: store API token and polling where required.
- Shopify: merchant-authorized webhooks; enable only after real-order verification.
- Lightspeed C-Series: confirmation tracking where supported, clearly marked lower reliability.

**Additional integrations**

- PrestaShop: private ZIP module.
- OpenCart: private OCMOD extension.
- Lightspeed E-Series: supported API/confirmation method.
- Squarespace: read-only Orders API with polling.
- Shopware 6: private extension.
- Magento/Adobe Commerce: private module.
- Mijnwebwinkel: approved partner/API route; pending authorization.
- CCV Shop: investigate merchant-accessible API/partner options.

Do not advertise unsupported platforms as Connected. Avoid unnecessary marketplace publication where legitimate direct merchant connections exist.

**Shopify:** Test a genuine paid order, webhook authenticity, retries, payment/cancellation events and availability of order reference, first name, email, amount, currency and optional phone. Document redacted fields. Missing mandatory data means **Additional Integration Required**. Do not assume a browser script can access checkout data.

## MERCHANT EXPERIENCE

Follow the supplied ShopBonus design: blue styling, top navigation, responsive layout, no sidebar.

1. Select a platform from searchable logo cards.
2. View dynamically generated platform-specific instructions.
3. Copy the webhook URL, enter restricted credentials or download a private connector.
4. Click **Verify Connection**.
5. Place a genuine test order.
6. Review a field-level verification checklist.
7. Open the live Order Sheet.

Show: Not Connected, Setup Required, Awaiting Test, Testing, Connected, Connected With Limitations, Error or Disconnected.

Verification checks provider authorization, webhook authenticity/API access, real order delivery, required fields and webshop ownership. Credentials being saved is insufficient.

Display last activity, latest successful order and actionable errors.

## SHARED BACKEND

Create reusable components:

`ConnectorRegistry`, `ConnectionManager`, `WebhookReceiver`, `WebhookVerifier`, `OrderFetcher`, `OrderNormalizer`, `OrderIngestionService`, `OrderEligibilityService`, `Answer24DispatchService`, `ConnectionHealthMonitor`, `OrderSheetBroadcaster`.

Connector interface:

`getSetupInstructions()`, `connect()`, `testConnection()`, `verifyIncomingEvent()`, `fetchOrder()`, `normalizeOrder()`, `syncOrders()`, `disconnect()`.

Support direct webhooks, webhook + API lookup, API polling and private modules. Confirmation-page snippets are lower-reliability fallbacks.

Use transaction-safe upserts, provider-specific mappings, asynchronous processing and consistent errors.

## NORMALIZED ORDERS & DATABASE

**Mandatory:** tenant, webshop, connection, platform, external order ID, original order reference, customer first name, email, amount, currency and timestamps.

**Optional/conditional:** phone, order status, payment status, payment confirmation, consent/contact basis, source event ID, cancellation/refund timestamps, voucher eligibility and Answer24 status.

Use exact decimal/minor-unit money handling. Missing required data → Incomplete. Missing phone → valid order but Not Callable. Never fabricate values.

Create/reuse:

- `webshop_connections`: tenant/shop, platform, method, status, configuration, encrypted credentials, verification/health timestamps.
- `webshop_orders`: normalized order and payment data, eligibility, contact and ingestion statuses.
- `webhook_events`: provider event identity, verification result, retry state, safe error.
- `answer24_contact_jobs`: webshop, grouped eligible orders, permission evidence reference, idempotency key, remote reference, status and timestamps.
- `integration_audit_logs`: actor, tenant, action, result, request ID and timestamp.

Unique order constraint: `(connection_id, external_order_id)`. Prevent duplicated events, rewards and outbound tasks with database constraints and atomic state changes.

Minimize stored personal data. Do not retain raw webhook payloads indefinitely.

## LIVE ORDER SHEET

Each webshop sees only its own orders.

Columns: date/time, reference, first name, email, phone, amount, currency, platform, payment, ingestion, voucher eligibility, consent and Answer24 status.

Include:

- Live updates without refreshing.
- Search, filters, sorting and pagination.
- Status colors and missing-field warnings.
- Daily counters and totals.
- Connection health and failed-import retry.
- Order details and sanitized event history.

Payment, voucher and contact statuses remain separate. Production data must originate from genuine provider events/API calls.

## ANSWER24 — REQUIRED PROCESSING

**Payment first**

1. Receive and authenticate the order event.
2. Normalize, validate and save idempotently.
3. Confirm payment using trustworthy provider status/API evidence.
4. Pending, unpaid, failed or uncertain payment → no Answer24 request.
5. Only confirmed paid orders enter voucher/contact eligibility checks.

**Eligibility**

Before creating or dispatching contact, check:

- Participating webshop and applicable voucher.
- Confirmed payment.
- Order is not cancelled/refunded.
- Valid documented legal contact basis and any required consent.
- No suppression/opt-out or duplicate contact.
- Valid telephone number for telephone outreach.

An order or webhook alone does **not** authorize marketing calls, SMS or email.

**One-hour grouping**

Combine eligible orders from the same identified customer at the same webshop within a rolling one-hour window into one pending contact request.

Keep all order references. Never group across webshops, create duplicate active calls or override contact restrictions.

**Cancellation/refunds**

When an order is cancelled/refunded:

- Immediately invalidate its pending contact eligibility.
- Remove it from a grouped pending request.
- Cancel the request when no eligible orders remain.
- Send suppression/cancellation to Answer24 if already dispatched.
- Recheck eligibility immediately before dispatch and contact execution.
- Record the outcome and handle dispatch/cancellation races safely.

Do not automatically reverse already redeemed points because an affiliate commission is later rejected.

**Answer24 exchange**

Send minimum permitted customer/contact information, webshop/campaign reference, eligible offer, grouped order references, contact-basis evidence reference and unique idempotency key.

Use authenticated signed server-to-server communication.

Receive verified callbacks for accepted, rejected, cancelled, attempted, completed and failed contact, including timestamp and remote reference. Update the Order Sheet without creating duplicate tasks.

Answer24 failures must not interrupt successful order imports.

**Activation**

After permitted engagement, create a short-lived, single-use invitation. Verify the consumer before linking the appropriate voucher and central points wallet. Never issue vouchers or create consumer accounts automatically from order receipt.

## API, SECURITY & AUDIT

Implement typed, documented endpoints for:

- Available platforms and setup instructions.
- Create/read/verify/disconnect webshop connections.
- Read/filter/export orders and retry failed processing.
- Provider webhooks and authorized order synchronization.
- Verified Answer24 status callbacks.

Use versioned `/api/v1/` routes.

Permissions include connection read/create/update/disconnect, order read/export and integration-log read.

Every route must specify HTTP method, actor, permission, tenant/resource scope, input validation, audit and error handling.

Enforce Laravel Policies/Gates, tenant isolation, session/CSRF protection for browser actions, rate limiting, signed webhooks, encrypted/rotatable credentials, replay protection and strict server-side status validation.

Never expose API tokens, raw payloads or customer PII in logs, URLs or frontend configuration.

Record connection changes, tests, imports, retries, eligibility decisions, Answer24 dispatch/callbacks and permission denials.

Centralize 401/403/419/422/429/500 errors with request IDs; log every 403. AI diagnostics receive only redacted technical information and cannot autonomously modify production.

## PERFORMANCE & MONITORING

- Acknowledge valid webhooks rapidly and process asynchronously.
- Use Laravel after-commit queues, bounded retries and dead-letter investigation.
- Use Redis for locks, queues and throttling where beneficial.
- Index tenant/shop, external order ID, time and status.
- Paginate large Order Sheets; broadcast targeted realtime changes.
- Respect provider API limits and avoid excessive polling.
- Measure ingestion latency, backlog, failed events, duplicate rate and API p95.
- Run dependency/security scans and generate API documentation in CI.

Super Admin sees connector health, last sync, orders today, failures, missing fields, queued Answer24 requests and merchant action requirements. Merchants see only their webshops.

Alert on expired credentials, repeated failures, stalled sync, missing mandatory data and unauthorized contact attempts.

## ACCEPTANCE — 10 E2E TESTS

1. Merchant connects WooCommerce through the wizard, verifies a real order and sees it live.
2. Wix, BigCommerce and Ecwid import genuine normalized orders; unsupported connections cannot activate.
3. Shopify real-order test verifies authenticity, protected fields and payment data; incomplete access blocks Connected.
4. Missing mandatory fields prevent verification; missing phone blocks calling but not order import.
5. Duplicate/retried webhooks produce one order, one reward decision and at most one contact task.
6. Unpaid, pending, spoofed or unconfirmed payment never triggers Answer24.
7. Multiple eligible orders from one customer/webshop within one hour produce one grouped contact request.
8. Cancellation/refund removes pending eligibility and safely suppresses already dispatched calls.
9. Authorized Answer24 request, signed callback and single-use consumer activation complete without cross-tenant access or duplicates.
10. Live Sheet, permissions, 403 audit, secret protection, disconnect, failure recovery, monitoring, security scans and measured performance pass in staging.

## DEFINITION OF DONE

One reusable adapter framework is operational. Merchants independently connect supported platforms and see real orders. Payment and cancellation handling, voucher eligibility, one-hour grouping, Answer24 communication, consumer activation, security, audit and monitoring work against actual integrations.

All 10 E2E tests pass. Unsupported connections and unauthorized customer contact are never enabled.

---

## Trello #2: FOUNDATION & DEVELOPMENT STANDARDS

List: **Howto**. Card: https://trello.com/c/kyOXNc2O/2-foundation-development-standards. Archived: False.

FOUNDATION, ARCHITECTURE & SECURITY

**Stack:** Laravel 13 API / PHP 8.3+ / Separate TypeScript frontend

**Priority:** P0 – Complete before individual screen development.

## 1. WHAT IS SHOPBONUS?

ShopBonus is a Netherlands-first loyalty and voucher platform connecting consumers and webshops.

Consumers discover webshops, earn virtual points and redeem them for webshop-funded discount vouchers. Answer24 handles authorized outbound calls through its own API.

**Core rules:**
- No cash withdrawals or cash-equivalent payouts.
- One central consumer wallet, points ledger and voucher system.
- One webshop can offer multiple vouchers.
- Daisycon rejection does not automatically reverse already-redeemed points.
- Only confirmed paid orders qualify for Answer24.
- Cancelled orders leave pending calling queues.
- Valid telephone number and lawful contact basis are mandatory for calls.
- Multiple qualifying orders from one consumer are grouped into one contact moment.
- Mollie allows consumers to pay the missing amount when they lack sufficient points.

Keep the platform small, modular, maintainable and API-first.

## 2. REQUIRED ARCHITECTURE

Create an independent Laravel backend and separate frontend.

**Backend:**
- Laravel 13 / PHP 8.3+.
- MySQL or PostgreSQL with foreign keys and indexes.
- Redis for queues, caching and applicable rate limits.
- Queue workers and scheduler.
- Versioned REST JSON API: `/api/v1/`.
- Laravel Sanctum authentication.
- HTTPS and environment-based configuration.

**Laravel conventions are mandatory:**
- Controllers: HTTP request/response only.
- Form Requests: validation and authorization.
- Policies/Gates: permissions.
- Services/Actions: business logic.
- Models: relationships and persistence.
- API Resources: controlled output.
- Jobs/Events: asynchronous operations.
- Migrations, Seeders and Factories.

No oversized controllers, duplicated logic, unnecessary packages or premature microservices.

## 3. MULTI-WEBSHOP ACCOUNT STRUCTURE

**One login can manage unlimited webshops.**

Example: one owner manages Shopify, WooCommerce, Lightspeed, Wix and PrestaShop stores.

Each webshop has its own:
- Business information and agreement.
- Ecommerce connection.
- Orders and customers.
- Team members and permissions.
- Vouchers and unique discount codes.
- Reporting, settings and audit events.

**Owner dashboard:** Show combined totals for all owned webshops, including orders, revenue, contacts, consumers, points, vouchers and conversion line charts.

Provide date filters, webshop filters, search and accurate aggregation without double counting.

**Workspace dashboard:** Selecting one webshop shows ONLY that webshop's information.

**Top-right switcher:** Clicking the avatar/name opens a searchable popup containing All My Webshops, available workspaces, connection statuses, Add Webshop and Manage Connections.

No additional login is required.

## 4. USERS, ROLES & PERMISSIONS

Initial roles:

1. **Consumer:** Global account, wallet, points and vouchers.
2. **Webshop Owner:** Manage authorized webshops.
3. **Webshop Staff:** Workspace-specific permissions.
4. **Super Admin:** Platform-wide administration.
5. **Service Integration:** Restricted Answer24/provider API access.

Implement reusable roles and permissions.

A user may belong to multiple webshops with different permissions in each.

Every protected API endpoint enforces authentication, action permission, workspace membership and resource ownership.

Never rely on hidden frontend buttons for security.

Use consistent 401, 403 and 404 responses without exposing other workspaces.

## 5. DATABASE FOUNDATION

Design multi-workspace relationships from the first migration.

**Core entities:**
- `users`
- `webshops`
- `webshop_memberships`
- `roles` / `permissions`
- `business_profiles`
- `agreement_templates` / `accepted_agreements`
- `platform_connections`
- `orders` / `order_events`
- `consumers`
- `wallets` / `wallet_ledger`
- `vouchers` / `voucher_codes`
- `payments`
- `answer24_contact_requests`
- `audit_events` / `application_errors`

Use `webshop_id` consistently for webshop-owned records. If `tenant_id` is used, define its meaning unambiguously; do not confuse owner accounts with individual workspaces.

Include relevant `created_by`, `updated_by`, timestamps, foreign keys and unique constraints.

A consumer can have one global ShopBonus account and wallet while orders, voucher codes and originating wallet transactions remain linked to their webshops.

Store money in integer cents with currency codes.

Use transactions for wallet balances, voucher issuance and payments. Maintain immutable financial ledger history.

Enforce unique webshop + external order reference and idempotent processing.

## 6. WEBSHOP ONBOARDING & INTEGRATION FOUNDATION

Prepare reusable API services for:

**Google Places:** Autofill business information. Require owner confirmation of legal registration details.

**Dynamic agreements:** Version-controlled templates, webshop-specific values, digital acceptance and immutable accepted snapshots.

**Ecommerce adapter:** Standardized order intake from supported platforms using approved webhooks, APIs or lightweight plugins. Never assume a universal embed code can prove payment.

**Live Order Sheet:** Realtime-capable, searchable order data scoped to the active webshop.

**Answer24:** Restricted API requests only after payment verification, customer contact checks and deduplication.

**Mollie:** Payment of voucher shortfalls. Verify status server-side before voucher issuance; never trust the browser redirect alone.

Do not activate real integrations before credentials, permissions and test flows are validated.

## 7. API STANDARDS

Every API requires:

- Versioned, predictable routes.
- Authentication and backend policy enforcement.
- Form Request validation.
- Controlled JSON Resources.
- Pagination, filters and sorting.
- Standard error format including request ID.
- Secure input handling and rate limiting.
- OpenAPI documentation.
- Unit/feature tests.

Create reusable permission, workspace-resolution, audit and error middleware.

Never expose internal secrets, unnecessary customer fields or another webshop's records.

## 8. AUDIT, ERRORS & ADMIN SUPPORT ACCESS

Build one reusable monitoring foundation.

Track:
- Authentication failures and 401/403 responses.
- Validation and unexpected server errors.
- Workspace switching and membership changes.
- User/role updates.
- Agreements and ecommerce connections.
- Orders, webhooks and queue failures.
- Answer24 requests/outcomes.
- Wallet transactions, Mollie payments and voucher issuance.

Audit metadata should include request ID, real actor, active webshop, action, resource, outcome, timestamp and HTTP status.

Separate security audit events from technical error logs.

**Super Admin Webshops page:**
- Search owners and webshops.
- View global totals.
- View one owner's combined dashboard.
- Open individual workspaces.
- Inspect errors and audit history.

Implement controlled impersonation/support sessions with a visible banner, original admin identity, target workspace, reason, expiration and complete action history.

Never replace or expose the owner's credentials.

Only sanitized diagnostic information may be sent to an AI analysis service. AI must not autonomously change production permissions or code.

## 9. SECURITY & FRONTEND STANDARDS

**Security:**
- HTTPS, secure sessions and CSRF protection.
- Restricted CORS.
- Encrypted integration credentials.
- Secrets outside source control.
- Signed/verified webhooks and replay protection.
- Backend workspace isolation.
- Least-privilege service credentials.
- Rate limiting and safe error responses.
- Backup, restoration, retention and deletion policies.
- Production debug disabled.
- OWASP API Security Top 10 principles.

**Frontend:**
- One TypeScript framework: React or Vue, agreed before implementation.
- Typed API client and reusable components.
- One consistent ShopBonus blue/white premium design.
- Top navigation rather than permanent sidebar.
- Avatar-based workspace switching.
- Responsive desktop, tablet and mobile layouts.
- Proper loading, empty, success and error states.
- Central handling of 401, 403, 422, 429 and 500.
- No hardcoded business permissions.

For a Sanctum cookie-authenticated SPA, deploy the frontend/API with compatible same-site domain configuration.

## 10. DEVELOPMENT & QUALITY

- Follow Laravel conventions and PSR standards.
- Run Laravel Pint and static analysis.
- PHPUnit/Pest unit and feature tests.
- Automated frontend checks.
- Development, staging and production separation.
- CI checks before deployment.
- Reviewed database migrations and recovery procedures.
- Document important architectural decisions.
- Never deploy untested changes directly to production.

## FOUNDATION ACCEPTANCE CRITERIA

- [ ] Laravel API and separate frontend run.
- [ ] Authentication and permissions work.
- [ ] One owner creates five independent webshops.
- [ ] One user has different roles in different workspaces.
- [ ] Top-right workspace switcher works.
- [ ] Combined owner dashboard returns correct scoped totals.
- [ ] Individual workspace API responses contain only its own data.
- [ ] Cross-workspace ID manipulation is rejected and audited.
- [ ] Super Admin can safely enter/exit audited support sessions.
- [ ] Database migrations, relationships and factories work.
- [ ] Google business lookup and agreement foundations are ready.
- [ ] Ecommerce adapter and realtime Order Sheet foundations are ready.
- [ ] Answer24 uses restricted API access.
- [ ] Central wallet, voucher and Mollie foundations are prepared.
- [ ] Webhook idempotency and queue monitoring work.
- [ ] Standard API errors and audit logging work.
- [ ] Sensitive information is excluded from technical logs.
- [ ] Tests, CI, staging and health monitoring pass.

**DELIVERABLE:** A secure, tested, documented, multi-webshop ShopBonus foundation. Complete this before implementing individual screens.



---

## Trello #3: COMPLETE PROCESS FLOW

List: **Howto**. Card: https://trello.com/c/0zb8iXxJ/3-complete-process-flow. Archived: False.

# SHOPBONUS – COMPLETE PROCESS FLOW & MULTI-WEBSHOP ARCHITECTURE

## OBJECTIVE

Build a modern ShopBonus platform connecting webshop owners, their ecommerce platforms, ShopBonus, Answer24 and consumers.

Use an independent Laravel API with a separate frontend.

**Core principles:**
- One login can manage multiple webshops.
- Every webshop has its own isolated workspace and data.
- Owners can view combined totals across their webshops.
- Webshop connections should take approximately two minutes where technically supported.
- Answer24 handles customer calls.
- Consumers collect virtual points and redeem webshop-funded vouchers.
- One central wallet and voucher system; no cash withdrawals.

## A. WEBSHOP OWNER – ONBOARDING

### 1. SIGN UP

An owner registers once and verifies their email.

- Create the owner account and first webshop workspace.
- Assign workspace-specific permissions.
- Show onboarding progress.
- Allow additional webshops without registering again.

### 2. GOOGLE BUSINESS DETAILS

Search and select a business using Google Places Autocomplete.

- Pre-fill available name, address, website and contact details.
- Allow manual corrections.
- Confirm legal company name, registration and VAT details separately.
- Store the owner's confirmed information.

Each webshop maintains its own business profile, even when multiple webshops have the same owner.

### 3. DYNAMIC AGREEMENT

Generate a version-controlled agreement for the selected webshop.

Include company information, signatory, commercial terms, voucher obligations and effective dates.

- Super Admin manages contract templates.
- Owner reviews and accepts digitally.
- Save the accepted version, timestamp, actor, IP and document hash.
- Preserve the original agreement after later template changes.
- Support separate agreements and settings per webshop.

### 4. CONNECT WEBSHOP

Display ecommerce platform logos.

The owner selects their platform and receives the simplest supported connection method.

- Direct webhook, authorized API integration or small plugin.
- Platform-specific instructions.
- Embed code only where technically suitable.
- Avoid public App Store approval where a legitimate private connection is supported.
- One standardized ShopBonus adapter handles all incoming orders.

Display connection status: **Connected / Testing / Action Required**.

Do not claim that a connection works before a valid order has been received and verified.

### 5. PAID ORDER SENT

The webshop automatically sends confirmed order information.

Required data:
- Webshop ID and external order reference
- Customer name and email
- Telephone number, if available
- Order amount and currency
- Payment and order status

Orders appear in a live Excel-like Order Sheet belonging to that webshop.

Only verified, paid orders can enter the Answer24 workflow.

## B. SHOPBONUS – ORDER PROCESSING

### 6. RECEIVE & VERIFY

Laravel receives orders through the configured adapter.

- Verify webhook origin or authenticated API access.
- Normalize incoming data.
- Associate every order with the correct webshop.
- Show processing status and errors in the Order Sheet.
- Never trust frontend embed events as payment confirmation.

### 7. PAYMENT, DUPLICATES & CANCELLATIONS

- Require confirmed payment before outreach.
- Enforce uniqueness using webshop ID + external order reference.
- Handle webhook retries idempotently.
- Remove cancelled orders from the calling queue before contact.
- Track refunds and status changes.
- Combine multiple orders from one customer within one hour into one Answer24 contact opportunity.

### 8. PHONE & PERMISSION?

**YES:** Valid phone number and verified lawful contact basis.

→ Send the contact request to Answer24.

**NO:** Phone number or lawful basis is missing.

→ Reject from the outreach workflow and record the reason.

A webhook does not automatically provide permission for marketing calls, SMS or emails.

### 9. ANSWER24 CONTACTS CUSTOMER

Answer24 uses its existing calling infrastructure.

- Receive only necessary customer/order details.
- Prevent duplicate contact attempts.
- Explain the voucher offer.
- Record contact outcome.
- Send an authorized, secure activation link when appropriate.
- Return results to ShopBonus.

Do not build a second calling platform.

## C. CONSUMER – WALLET & VOUCHERS

### 10. ACTIVATE ACCOUNT

The consumer follows a signed, expiring, single-use activation link.

- Create an account or log in.
- Verify required contact information.
- Link the relevant order or offer.
- Prevent duplicate registrations and rewards.

### 11. POINTS WALLET

Each consumer has one central ShopBonus wallet, usable across participating webshops.

- Virtual points only.
- Auditable, immutable transaction ledger.
- Available, reserved and spent balances.
- Approved earning rules.
- Atomic transactions; no negative balances.
- No cash withdrawals.
- Daisycon rejection never automatically reverses already-spent points.

### 12. CHOOSE VOUCHER

Consumers browse webshops and their vouchers.

One webshop can offer multiple vouchers.

Show voucher value, points price, availability, expiry and restrictions.

All vouchers must have an identified webshop funding arrangement.

### 13. ENOUGH POINTS?

**YES**

- Reserve and deduct points atomically.
- Issue one unique voucher code.
- Record issuance and wallet transaction.
- Display the code in the consumer account.

**NO**

- Calculate the missing euro amount.
- Show points used and amount payable.
- Temporarily reserve available points and voucher stock.
- Continue to Mollie checkout.

### 14. MOLLIE PAYMENT & UNIQUE CODE

- Create payment through the ShopBonus backend.
- Verify successful payment server-side through Mollie.
- Never rely on browser redirects as payment proof.
- Finalize points deduction and issue the code exactly once.
- Release reservations after failed, cancelled or expired payments.
- Display the code in the wallet.
- Send confirmation only through an authorized channel.

Define seller of record, VAT and refund conditions before enabling paid voucher checkout.

## D. MULTI-WEBSHOP WORKSPACES

### 15. ONE LOGIN, MULTIPLE WEBSHOPS

A single owner can create and manage multiple webshops on different platforms.

Example:
- Shop A: Shopify
- Shop B: WooCommerce
- Shop C: Lightspeed
- Shop D: PrestaShop
- Shop E: Wix

All five belong to one owner account but retain separate orders, customers, connections, agreements, team permissions, campaigns, vouchers and codes.

### 16. WORKSPACE SWITCHER

Clicking the owner's name/avatar in the top-right navigation opens a compact popup.

Show:
- **All My Webshops**
- Searchable webshop list
- Logo, platform and connection status
- Open Workspace
- Add Webshop
- Manage Connections

Switching does not require logging out. Always display the currently active webshop.

Users who belong to multiple workspaces receive the same switcher, limited to their permissions.

### 17. COMBINED OWNER DASHBOARD

**All My Webshops** combines the owner's authorized data.

Show:
- Orders and revenue
- Answer24 contacts and outcomes
- Consumer registrations
- Points issued and spent
- Voucher codes issued and redeemed
- Mollie payment totals
- Conversions and performance line charts

Provide date, webshop, platform and status filters, plus search.

Calculate combined totals accurately without double counting shared consumers or duplicate orders.

### 18. INDIVIDUAL WORKSPACE

Selecting one webshop opens only its own data.

This applies to every page and API:

- Dashboard and charts
- Orders and Order Sheet
- Customers and users
- Agreements
- Integrations
- Wallet-related webshop transactions
- Voucher campaigns and codes
- Payments and reports
- Audit history

No other webshop's data may appear in an individual workspace.

### 19. SUPER ADMIN – WEBSHOPS & SUPPORT ACCESS

Create a dedicated Super Admin Webshops page.

- Search owners and webshops.
- Filter by platform, owner and status.
- View complete platform totals.
- View combined totals for one owner.
- Open an individual webshop workspace.
- Inspect connection failures and audit events.

Allow controlled admin impersonation/support access without using the owner's credentials.

Show a visible support-session banner and exit button. Log the original admin identity, target workspace, reason, timestamps and actions. Enforce permissions for sensitive operations.

## E. TECHNICAL & SECURITY REQUIREMENTS

**Database:** Design multi-workspace relationships from the first migration. Users can belong to multiple workspaces with different roles.

**Scope:** Every webshop-owned record must reference its workspace. Consumer identity and the central wallet remain global, while orders, voucher codes and webshop-related transactions retain their originating webshop.

**API:** Enforce authentication, permissions, tenant isolation and validation server-side on every endpoint. Never trust a workspace ID supplied by the frontend.

**Audit:** Track actors, workspace changes, impersonation, agreements, orders, permission failures, payments and voucher issuance. Mask personal information in technical logs.

**Security:** Encrypt credentials, verify webhooks, rate-limit endpoints and log relevant 401/403 errors.

**Reliability:** Use queues, retries, idempotency, monitoring and reconciliation. Never issue duplicate rewards or codes.

## F. ACCEPTANCE CRITERIA

1. One owner registers and creates five different webshops.
2. Each webshop connects and receives real orders independently.
3. Paid orders appear in the correct live Order Sheet.
4. Unpaid and cancelled orders never enter active outreach.
5. Duplicate webhooks do not duplicate orders or rewards.
6. Missing phone/permission blocks Answer24.
7. Multiple orders are grouped into one contact opportunity.
8. Consumer registration and the central wallet work correctly.
9. Enough points produces one unique voucher.
10. Insufficient points opens Mollie checkout.
11. Failed payment never produces a code.
12. Confirmed payment issues a code exactly once.
13. Workspace switching changes every relevant page and API scope.
14. One owner sees accurate combined totals across all five shops.
15. Individual workspaces show only their own data.
16. Users, permissions, customers and voucher codes remain correctly scoped.
17. Unauthorized cross-workspace access is blocked and audited.
18. Super Admin support sessions are identifiable and fully auditable.



---

## Trello #4: Registration & Login

List: **Frank**. Card: https://trello.com/c/W59a8uF0/4-registration-login. Archived: False.

# ShopBonus — Registration & Login

## OBJECTIVE

Build complete registration, email verification, login, logout and password recovery for consumers, webshop owners and invited employees. One user identity may later act as a consumer and belong to multiple webshops. Deliver working screens, Laravel API, database, permissions and tests—not static mockups.

Follow ShopBonus's clean blue/white design: top navigation, centered form, no sidebar, excellent mobile layout. Exclude company lookup, contracts, phone, vouchers and payments.

## USERS & ACCESS

- Visitor: register, log in, verify email or recover access.
- Consumer: enter consumer area only after email verification.
- Prospective webshop owner: after verification enter webshop setup. Selected onboarding intention grants **no ownership**.
- Employee: same login; roles and webshop access come only from an authorized server-side membership.
- Super Admin: internally provisioned; never available through public registration.
- Unverified accounts may only verify/resend/logout. Suspended/deleted accounts are blocked across API and existing sessions.
- An existing consumer can become a webshop owner without a second user record. Never trust browser-supplied role, scope or destination.

## SCREENS & FLOW

**Registration — /register**
- ShopBonus logo, NL/EN switch, Log in; tabs **Create account | Log in**.
- Selector **Consumer | Webshop owner**; fields **First name, Email, Password**, reveal button and strength guidance.
- Unticked, required **Accept Terms** checkbox with links to published Terms and Privacy. Save exact Terms version and time; marketing consent is optional and separate.
- Blue **Create account** button, clear inline errors, disabled/loading state and duplicate-submit prevention.
- Desktop info panel: **Create account → Connect webshop → Publish offers**; compact on mobile.

**Login — /login**
- Email, password, reveal button, **Log in**, **Forgot password?**, registration link.
- Generic credential errors; redirect from server-verified account state and permissions, never an untrusted URL.

**Email verification — /verify-email**
- Six-digit code input, masked address, paste support, Verify button, resend countdown.
- Hash code at rest; ~10-minute expiry, attempt limits, single use, atomic consumption. Resend supersedes old code; queue email.
- On success update email_verified_at and enter the correct area. Prospective owner receives no webshop permissions yet.
- Changing email requires a secured re-verification flow.

**Recovery — /forgot-password and /reset-password**
- Generic forgot-password response; native expiring reset link and new-password form.
- Reused/expired token fails; successful reset invalidates prior sessions.
- Include success, expired-link and recoverable error states.

Use CMS-published, versioned legal text and NL/EN translation keys. Separate cookie preferences from registration consent. Public URLs contain no internal IDs. Meet keyboard, focus and screen-reader requirements.

## DATABASE

Reuse Laravel's **users** table, not consumer/merchant duplicates:
- id, first_name, normalized case-insensitive unique email, **password** (Laravel hashed field), email_verified_at, status, onboarding_intent, last_login_at and timestamps.
- **email_verification_challenges:** user_id, code_hash, expires_at, consumed_at, attempt_count; index lookup/expiry.
- **legal_acceptances:** user_id, document_type, published_version, accepted_at, minimal evidence; immutable history.
- Use the native Laravel password reset broker. Enforce email uniqueness at DB level under concurrent signup.
- Roles/workspace memberships are separate server-owned records. Never grant memberships from signup intention.
- Never auto-merge users solely because a phone/email looks similar; account linking needs verified ownership and audit.

## AUTH & API IMPLEMENTATION

Use **Laravel Fortify** for headless auth and **Sanctum stateful SPA cookies**; retain Fortify's native endpoints instead of creating duplicate authentication controllers.

**Native endpoints:** GET /sanctum/csrf-cookie; POST /register, /login, /logout, /forgot-password and /reset-password.

**Custom endpoints:**
- POST /api/v1/auth/email/verify-code — verify and atomically consume OTP.
- POST /api/v1/auth/email/resend-code — limited resend, invalidating previous OTP.
- GET /api/v1/me — minimal identity, verification/status, authorized roles/scopes and allowed destination.

Confirm actual Fortify routes and enabled features before coding. **Laravel's native email verification sends signed links, not six-digit codes.** Adapt only that verification step and preserve email_verified_at and verified middleware. Avoid running both delivery methods accidentally.

Typed OpenAPI contracts for custom endpoints; safe JSON errors containing request_id. Configure Sanctum stateful domains, exact CORS allowlist and credentials. SPA gets CSRF cookie before login; enable Laravel 13 origin-aware CSRF checks, Secure/HttpOnly/SameSite cookies, session ID rotation on login, session invalidation on logout and safe 419 recovery. Never store auth tokens in localStorage.

Protect endpoints with auth:sanctum, verified/status middleware, Form Requests and Laravel Policies/Gates. UI visibility never replaces backend permission checks. Use correct 401/403/419/422/429/500 responses; do not leak another webshop's existence or records.

## SECURITY, AUDIT & INCIDENTS

- Default-deny private endpoints; explicitly allowlist public endpoints. Register every API route's method, role, permission, scope and risk. Test the permission matrix automatically.
- Strong adaptive password hashes, validated passwords, generic login/reset responses. Redis-backed throttle per IP + normalized email for signup, login, verify, resend and reset; timed lockouts.
- Recheck account status and memberships on requests; prevent access via forged role, altered shop ID, stale session or unsafe redirects.
- Require MFA for Super Admin and owners with sensitive rights. Design step-up for high-risk actions: fresh password plus emailed OTP, never public admin signup.
- Production: HTTPS/HSTS, restricted CORS, safe cookies, APP_DEBUG=false, no stack traces or credentials in error bodies.
- Append-only integrity-checked audit: actor/reference, event, timestamp, request ID, safe IP and webshop scope. Record signup, OTP, login failures/success, logout, lockouts, resets and denied permissions. Never log codes, passwords, tokens, headers or session secrets.
- Categorized GET/write auditing with filters and configurable retention; capture efficiently without blocking page reads.
- Log **every 403** in the single Errors & Security page; alert on each and group repeated notifications. Categorize 401/419/422/429/500 separately; normal validation errors are not security attacks.
- AI can analyze only minimized/redacted incidents and propose fixes for human approval; never auto-deploy.

## PERFORMANCE, DEPENDENCIES & DOCUMENTATION

- Keep a small native Laravel implementation; no homemade auth stack or microservices.
- Redis mail queues with retries/failed-job visibility. Do not block registration while sending SMTP.
- Index email/challenge lookups; avoid N+1 role queries; keep /me small. Cache published legal/CMS text, never private authorization results.
- Staging p95 targets: GET /api/v1/me **under 250 ms**; signup **under 400 ms**, excluding queued mail. Measure and report actual timings.
- Use supported patched PHP/Laravel packages, optimized Composer autoload, OPcache, php artisan optimize and correct worker restart. Monitor slow requests/jobs with Pulse; restrict Telescope diagnostics.
- Check current **official Laravel docs** and installed versions before implementation. CI: composer audit, frontend dependency scan, lint/static analysis and tests; fail release for known critical vulnerabilities.
- Maintain automatic API/permissions handbook from routes, OpenAPI and test evidence, including errors, access scopes and audit categories.

## ACCEPTANCE — 10 END-TO-END TESTS

1. **Consumer registration:** Current Terms version is stored; OTP email queued; protected access blocked until single successful verification; consumer area opens.
2. **Owner registration:** Correct verification and redirect to webshop setup; no premature ownership or admin membership.
3. **Employee access:** One login identifies existing assigned memberships; a different webshop's data is inaccessible without revealing its existence.
4. **OTP:** Invalid, expired, replayed, superseded and simultaneous duplicate attempts fail safely; one success maximum; 429 on limits; no OTP in logs.
5. **Sessions:** CSRF handshake, secure cookies, session rotation, 419 recovery and logout; protected GET returns 401 after logout.
6. **Recovery:** Known/unknown email gives same response; reset works once; expired/reused token and old sessions fail.
7. **Uniqueness:** Different email casing and concurrent signup attempts create only one user and no duplicate privileges.
8. **Tampering/status:** Forged admin role, shop ID or redirect grants nothing; unverified, suspended or deleted accounts cannot bypass API guards or old sessions.
9. **Real UI:** Desktop/mobile, NL/EN, keyboard/focus, field errors, loading state, current Terms/Privacy links and accessible verification/recovery all work.
10. **Security/operations:** Permission registry, safe request IDs for 401/403/419/422/429/500, each 403 logged, redacted integrity audit, queued mail, clean dependency scans and measured p95 timings demonstrated.

## DEFINITION OF DONE

Real staging API and emails work end to end. Database constraints, permissions, verified account guards, OTP race safety, recovery and sessions are demonstrated. All 10 E2E tests pass. Provide screenshots plus concise QA, route-permission, audit, dependency-scan and performance evidence.

---

## Trello #5: MULTILINGUAL INLINE CMS & COOKIE CONSENT

List: **Frank**. Card: https://trello.com/c/J312wM3L/5-multilingual-inline-cms-cookie-consent. Archived: False.

# SHOPBONUS – MULTILINGUAL INLINE CMS & COOKIE CONSENT

## OBJECTIVE

Build one lightweight content system: Dutch/English throughout ShopBonus, Super Admin editing on live pages, versioned legal pages, multilingual FAQs and cookie consent. Preview and publish without deployment. Use the blue/white ShopBonus style, top navigation and mobile layout.

## I18N – ENTIRE APPLICATION

- Dutch (NL) is default; English (EN) is fully available. Design for additional locales such as German, without requiring it at launch.
- Top-right language selector; persist preference for visitors/users and use it across frontend, API, emails and queued notifications.
- Translate navigation, forms, validation, auth/permission errors, alerts, empty/loading states, consumer wallet, webshop dashboards and administrative screens. A Dutch selection must not display stray English labels.
- Use stable frontend/Laravel translation keys for UI/system labels and localized CMS records for editable content. Do not make business rules or security messages editable.
- Localize dates, numbers, page titles, metadata and public slugs; apply a defined fallback and expose missing/stale translations in Super Admin.
- Assisted translation creates drafts only; never auto-publish, especially legal text.

## LIVE INLINE CONTENT STUDIO

Super Admin clicks **Edit Page** on a published page; editable text, buttons and images become clickable inline.

- Edit text, lists, links, buttons, images (replace/crop/focal point), translated alt text and reusable sections.
- Separate **Save Draft**, **Preview** and **Publish**. Drafts never change public content. Preview desktop/mobile and every locale before publishing.
- Store immutable revisions with editor, date and published version. Restore through a new revision; preserve acceptance evidence.
- Detect concurrent edit conflicts; never silently overwrite changes.
- Use a maintained editor (e.g. Tiptap) with an allowed content schema. No arbitrary scripts or configuration.
- Searchable Content Studio: pages, translations, status, revisions and actions. Lazy-load editor only for admins.

## PAGE TYPES, LEGAL CONTENT & FAQ

Super Admin can create/edit/publish/archive pages using reusable ShopBonus templates:

- Privacy Statement, Terms & Conditions, Cookie Policy, Help/Support, FAQ, information and landing pages.
- Manage content, SEO title/description, localized slug, navigation/footer links, images and mobile preview.
- Legal pages require version, effective date, explicit approval and history. Existing accepted agreements/hashes remain unchanged.
- FAQ: categories, searchable accordion, rich-text answers, NL/EN, drag ordering, deep links, publication and audience visibility. Enforce restrictions in API.
- Do not build a general-purpose website builder; reuse fixed components and safe editable content slots.

## COOKIE BANNER & CONSENT

- Show **Accept All**, **Reject Optional** and **Customize** with clear, equally accessible choices. Essential cookies alone may run without optional consent.
- Categories: Necessary, Preferences, Analytics, Marketing. No optional tracking script, pixel, cookie or SDK loads before the required valid opt-in.
- Save category choices, policy version and time with minimal identifiers/retention; persist across reloads.
- Footer **Cookie Settings** permits withdrawal/change anytime; stop future optional tags and clear controlled optional storage where possible.
- Super Admin edits localized banner, categories, policy versions and approved trackers. Use a consent-aware allowlisted script loader; no pasted code.
- New policy versions trigger renewed choice when legally required. All legal text and tracking classifications require human compliance review.

## API, DATABASE & PERMISSIONS

- `/api/v1/`: published pages/FAQ/consent config (public); protected CMS CRUD/drafts/preview/publish/revisions/media/translations; consent save/update.
- Laravel Form Requests validate/authorize inputs; Policies/Gates authorize resource actions; Services/Actions perform publication logic; API Resources control JSON. Use Sanctum for authenticated sessions.
- Migrate pages, translations, blocks, revisions, media, FAQ, cookie policy/categories and consent; add foreign keys and `(locale, slug, status)` indexes.
- Publish atomically in a transaction; separate public and draft data. Only authorized Super Admin edits global content; enforce shop isolation.
- Consistent 401/403/404/422/429/500 JSON errors with request ID. Enforce backend checks regardless of frontend hiding.

## SECURITY, AUDIT & SPEED

- Secure HTTPS, scoped CORS, HttpOnly/Secure/SameSite sessions and CSRF protection for cookie-authenticated writes; rate-limit public and admin endpoints. Require recent re-authentication/2FA for legal publication and rollback.
- Sanitize rich text server-side and safely render it client-side. Reject script tags, event handlers, dangerous URLs and unsafe file uploads; limit MIME, size and image processing. Use CSP as defense in depth.
- Authorize previews, even signed links; set private/no-store and prevent indexing/caching.
- Document every API route's authentication, permission, resource scope, validation and audit behavior. Audit edits, publication, rollbacks, policy updates, permission denials and failures with request/actor/resource/scope/result IDs; never log secrets or full consent/customer payloads.
- Cache published localized content with precise invalidation; index/paginate, lazy-load editor, optimize images and queue media/translation jobs.
- Target cached public API p95 under 200 ms server time on staging. CI: Pint, static analysis, Pest/PHPUnit, TypeScript, dependency scans and OpenAPI.

## 10 E2E ACCEPTANCE TESTS

1. **Locale consistency:** Switch NL↔EN as visitor, consumer, owner and Super Admin; refresh/login. Assert saved locale plus correctly translated UI, backend 422 errors, emails and notifications without mixed-language labels.

2. **Draft isolation:** Super Admin edits a headline and image, saves draft, opens public page anonymously. Assert draft exists but published text/image and public API response are unchanged.

3. **Preview authorization:** Preview both locales on desktop/mobile, then attempt preview anonymously and as webshop owner. Assert unpublished content is visible only to authorized admin and never publicly cached/indexed.

4. **Publish and cache:** Publish changed NL text/SEO and re-request both locales immediately. Assert only the intended published version changes, proper cache invalidation and an immutable publication audit event.

5. **Concurrency and rollback:** Two admins edit the same revision; second submits stale update, then restore an old version. Assert conflict detection, preserved history and a new rollback publication instead of data loss.

6. **Pages and FAQ:** Create a legal page and three categorized FAQ items, translate, reorder and restrict one audience. Assert correct search/order/URLs and direct API access blocks hidden content.

7. **Authentication/tenant isolation:** Test public, anonymous, consumer, owner and unauthorized staff against CMS mutations and cross-scope IDs. Assert appropriate 401/403/404, zero changes and logged denials.

8. **XSS and validation:** Submit script/event-handler/unsafe-link payloads and fake image MIME uploads. Assert 422 or safe sanitization, no executable code in preview/public output and no stored unsafe media.

9. **Cookie lifecycle:** First visit, reject, customize, accept, refresh and withdraw; publish a relevant policy update. Assert optional scripts blocked until consent, category choices honored and consent versions safely recorded.

10. **Reliability and speed:** Trigger 429 and controlled 500 scenarios, inspect request IDs/sanitized audit logs; load realistic mobile content and run CI. Assert p95 target, no stale cache, passing tests/scans and documented API.

## DELIVERY

Provide deployed staging flows, migrations, API documentation, security/dependency scan results, audit samples, measured performance and pass/fail evidence for all 10 tests. Screenshots alone are not completion evidence.

---

## Trello #6: SUPER ADMIN COCKPIT: COMPLETE EXISTING DASHBOARD & VERIFY LIVE DATA

List: **Hassan**. Card: https://trello.com/c/kyD7U0Kv/6-super-admin-cockpit-complete-existing-dashboard-verify-live-data. Archived: False.

**OBJECTIVE**

Implement and verify the Super Admin Cockpit shown in the attached screenshot.

This is the central operational overview for the entire ShopBonus platform. The Super Admin must immediately understand platform growth, voucher activity, affiliate revenue, wallet liabilities and operational issues.

**Reuse the existing ShopBonus systems. Do not rebuild modules covered by other development work.**

## 1. EXISTING SYSTEMS — AUDIT BEFORE CHANGING

The ShopBonus project already includes development specifications for:

- Webshop registration, Workspaces and approval.
- Consumer registration and profiles.
- Universal Webshop Order Adapter and live Order Sheet.
- Voucher creation, claiming, redemption and expiration.
- Consumer and merchant Wallets.
- Mollie payments and Moneybird accounting.
- Affiliate integrations, including Daisycon and TradeTracker.
- Banner Studio, sales attribution and reporting.
- Platform permissions, audit logging and notifications.

**Required action:** Inspect the actual implementation and identify which services, API endpoints, database models and dashboards already exist.

Classify each requirement as **Working / Partially working / Missing / Blocked**.

Connect the cockpit to these existing services. Do not create separate calculations, voucher engines, wallets, affiliate importers or financial ledgers.

## 2. SCREEN DESIGN

Follow the attached screenshot and the existing modern ShopBonus design system.

Use the white top navigation, blue accents, rounded cards and clean typography.

**TOP NAVIGATION**

- ShopBonus logo → Cockpit.
- Cockpit → This dashboard.
- Webshops → Existing webshop management.
- Affiliate & wallet → Existing financial and affiliate overview.
- Support → Existing support module.
- Instellingen → Platform settings.
- Global search → Webshops, consumers, vouchers, orders and permitted records.
- Notification bell → Unread alerts.
- Super Admin profile → Account menu.

Keep URLs clean and user-friendly.

Every navigation item must work. Hide or clearly mark unavailable destinations; never present nonfunctional buttons.

## 3. SIX LIVE KPI CARDS

Connect the six cards to authoritative backend data.

1. **Active webshops**

Number of approved, enabled merchant Workspaces with active webshop participation. Do not count rejected, pending or disabled webshops as active.

1. **Consumers**

Number of active consumer accounts. Exclude test/deleted accounts according to the established platform rules.

1. **Active vouchers**

Number of currently usable vouchers, respecting claim, redemption, expiration and cancellation states.

1. **Affiliate commission**

Show the applicable reporting-period commission and its status.

Separate confirmed revenue from pending, reversed or rejected commissions in the detailed view. Never present pending affiliate commission as earned cash.

1. **Virtual wallet balance**

Display outstanding customer wallet value accurately.

Separate available, reserved and expired balances where relevant. Customer wallet value is not automatically ShopBonus profit.

1. **Open actions**

Total number of outstanding operational items requiring attention.

Examples: webshop approvals, failed integrations and support cases.

Every KPI is clickable and opens the corresponding filtered management screen.

Percentage changes must compare equivalent periods using the same definitions.

## 4. VOUCHER ACTIVITY CHART

The large chart displays issued vouchers over time.

Required:

- Default: Last 30 days.
- Available periods: 7 / 30 / 90 days and custom range.
- Real API data, not frontend examples.
- Correct daily aggregation and timezone handling.
- Hover tooltip displaying date and exact count.
- Zero-data and loading states.
- Chart updates when the reporting period changes.
- Clicking a data point opens the existing voucher overview filtered to the selected date.

Use the existing reporting data and indexed aggregate queries.

## 5. AFFILIATE CHANNEL DISTRIBUTION

The donut chart displays affiliate-driven voucher activity by network.

Initial channels:

- Daisycon.
- TradeTracker.

Allow additional channels without changing the dashboard architecture.

For every channel show:

- Channel name.
- Voucher count.
- Percentage of total.
- Reporting period.
- Link to filtered affiliate records.

**Important:** Voucher counts and affiliate commissions are different metrics. The chart must clearly indicate which one it displays.

If 1,608 vouchers came from Daisycon and 756 from TradeTracker, display 2,364 vouchers in total with correctly calculated percentages.

Do not fabricate unattributed network information. Include an Other/Unknown category when necessary.

## 6. RECENT ACTIVITY

Display the latest important platform events:

- New webshop registration.
- Webshop approved/rejected.
- Voucher claimed/redeemed.
- Affiliate conversion imported or reversed.
- Failed order synchronization.
- Wallet transaction or funding failure.
- Support ticket created or resolved.

Columns:

**Date/time | Type | Description | Details | Action**

Each row opens the corresponding record.

The activity stream must reuse the platform's existing event/audit infrastructure.

Protect personal information according to the viewer's permissions.

Use efficient pagination or a capped recent-event feed.

## 7. ACTION REQUIRED

The right-hand panel must display actionable operational issues.

Initial categories:

**Webshops awaiting approval**

Click → Open filtered pending-approval list.

**Failed synchronizations**

Click → Open failed webshop order imports, affiliate syncs or connector failures.

**Open support cases**

Click → Open unresolved support tickets.

Each category must:

- Display the real outstanding count.
- Link to the exact affected records.
- Show severity when relevant.
- Update after a problem is resolved.
- Avoid counting the same incident twice within one category.

Use notification and incident statuses from existing modules.

Do not allow the Super Admin to accidentally approve webshops or retry sensitive financial operations merely by clicking the summary card.

## 8. GLOBAL SEARCH

The search field must search permitted platform records, including:

- Webshop name/domain.
- Consumer name or email.
- Order reference.
- Voucher reference/code.
- Affiliate conversion reference.

Requirements:

- Debounced search.
- Grouped results by record type.
- Tenant and permission checks at API level.
- Safe masked results where appropriate.
- No exposed API credentials or protected payment data.
- Empty, loading and error states.
- Selecting a result opens the correct existing detail page.

Use existing search indexes/services when available.

## 9. DATA ACCURACY, SECURITY AND PERFORMANCE

The Super Admin Cockpit is an aggregation interface, not a separate source of truth.

**Data accuracy**

- Use existing order, voucher, wallet and affiliate ledgers.
- Exclude duplicate provider events.
- Process reversals, refunds, cancellations and expirations.
- Show metric definitions and last-updated timestamps.
- Distinguish booked revenue, pending revenue and wallet liabilities.
- Ensure charts and KPI totals are consistent.

**Security**

- Super Admin authorization enforced by Laravel Policies/API middleware.
- No access based only on hidden frontend buttons.
- Validate dates, filters and search input.
- Rate-limit expensive searches and exports.
- Audit sensitive record access and administrative actions.
- Record 401/403/422/429/500 failures with request IDs.
- Protect all tenant data and financial endpoints.

**Performance**

- Reuse existing cached aggregates where possible.
- Use indexed queries and queued aggregation for heavy reporting.
- Avoid loading complete transaction histories on page load.
- Lazy-load secondary charts and activity records.
- Keep the interface responsive during slow integrations.
- Show clear degraded-data warnings when a source is unavailable.

Use supported official Laravel facilities where suitable. Document the existing implementation and any changes.

## 10. EXACTLY 10 END-TO-END TESTS

**E2E 1 — Super Admin access**

Sign in as Super Admin and open Cockpit. Verify all navigation, six KPI cards, charts, activity feed and action panel render. A regular merchant or consumer cannot access the platform-wide dashboard; API returns 403 and records the denial.

**E2E 2 — Webshop and consumer counts**

Create two approved webshops, one pending webshop and three active consumers using test fixtures. Verify the Active Webshops KPI includes only the two approved/active workspaces and the consumer count follows its documented definition. Clicking each KPI opens the correctly filtered existing management page.

**E2E 3 — Voucher lifecycle**

Issue five vouchers. Claim three, redeem one, expire another and leave one unclaimed. Verify the Active Vouchers KPI follows the actual established status rules, the issued-voucher chart counts issuance events correctly, and no voucher is counted twice.

**E2E 4 — Affiliate commission accuracy**

Import one confirmed Daisycon commission, one pending TradeTracker commission and one reversed commission. Verify confirmed, pending and reversed amounts remain separate. The headline commission never treats reversed or pending earnings as confirmed revenue.

**E2E 5 — Wallet liability**

Credit a consumer wallet, reserve part of its balance for a voucher and finalize redemption. Verify available and reserved balances reconcile to the ledger, the cockpit refreshes, and the displayed liability is not incorrectly reported as profit.

**E2E 6 — Reporting filters**

Switch the voucher chart between 7, 30 and 90 days. Verify backend date filtering, exact totals, hover values and timezone boundaries. Clicking a chart date opens the existing filtered voucher list with matching records.

**E2E 7 — Affiliate donut**

Load 1,608 Daisycon and 756 TradeTracker voucher events for the same period. Verify total = 2,364, percentages round consistently to 68% and 32%, labels match the queried metric, and clicking each channel opens its filtered records.

**E2E 8 — Recent activity**

Generate a webshop approval, voucher redemption, affiliate import and failed order synchronization. Verify each appears once, in correct timestamp order, with an appropriate description. Click each row and confirm it opens the authoritative record with permission checks.

**E2E 9 — Action Required**

Create three pending webshop approvals, two failed synchronizations and four open support tickets. Verify counts 3/2/4. Resolve one issue in each category and confirm counts become 2/1/3. Each card opens the appropriate filtered list without performing an unintended administrative action.

**E2E 10 — Search, security and degraded operation**

Search by webshop, email, voucher and order reference. Verify results link correctly and expose no secrets. Simulate a failing affiliate API and slow reporting endpoint: the cockpit remains usable, displays a meaningful warning, preserves other data and records structured errors with request IDs. Verify no cross-tenant data leakage.

## DEFINITION OF DONE

- Screenshot-matching Super Admin Cockpit.
- Existing ShopBonus services reused.
- Six accurate and clickable KPI cards.
- Working voucher chart and affiliate distribution.
- Live recent activity and actionable alerts.
- Functional global search and top navigation.
- Correct commission, voucher and wallet calculations.
- Super Admin permissions, audit and error handling enforced.
- No duplicate financial, voucher or affiliate functionality.
- Exactly 10 passing E2E tests with recorded evidence.

**Deliver:** Implementation-status overview, changed API/frontend components, screenshots of the functioning page and E2E results.

---

## Trello #7: MERCHANT OFFERS & DISCOUNT CODE MANAGEMENT

List: **Hassan**. Card: https://trello.com/c/1PbpnAsV/7-merchant-offers-discount-code-management. Archived: False.

## OBJECTIVE

Audit, complete and test the merchant **Offers & Discount Codes** page shown in the attached screenshot.

A webshop owner must independently create offers, manage discount-code inventory, monitor voucher issuance and verify that the correct codes work in their connected webshop.

**Reuse existing ShopBonus functionality:** Merchant Workspaces, webshop connections, Universal Order Adapter, vouchers, consumer Wallet, merchant Wallet, categories, reporting, Mollie/Moneybird and existing permissions.

This page is the merchant management interface. Do not build a second voucher engine, checkout integration or financial ledger.

## 1. WHAT ALREADY EXISTS — VERIFY FIRST

Inspect the actual codebase and document whether these existing or previously specified components are operational:

- Merchant authentication and Workspace selection.
- Offer configuration and voucher lifecycle.
- Code generation/import and assignment.
- Consumer voucher claiming and redemption.
- Merchant-funded rewards and 30% ShopBonus fee.
- Order verification through the Universal Webshop Order Adapter.
- Existing webshop platform coupon integrations.
- Voucher expiration, reversals and audit history.

Classify each component as **Working / Partial / Missing / Blocked**.

Reuse working functionality and implement only missing connections, UI actions and validation.

Do not claim that functionality is complete merely because the screen exists.

## 2. PAGE DESIGN

Match the supplied ShopBonus screenshot.

**Top navigation**

Overview | Offers & Codes | Notifications | Settings

Show the current authorized webshop and user profile.

**Page title**

Offers & Discount Codes

Subtitle: Manage your webshop promotions and discount codes.

**Offer selector**

A searchable dropdown displays all offers belonging to the selected webshop.

Selecting another offer refreshes its configuration, statistics, filters and code inventory without leaving the page.

Show the number of available offers.

**New Offer**

Opens the existing offer creation flow.

Every visible button and menu must perform its intended action.

## 3. SELECTED OFFER DETAILS

Display one prominent offer summary:

- Webshop logo and name.
- Offer title and description.
- Active / Draft / Paused / Expired status.
- Discount type: percentage or fixed amount.
- Discount value.
- Minimum eligible order value.
- Maximum discount amount where applicable.
- Voucher validity.
- Applicable webshop, categories or products.
- Combination/exclusion rules.
- Edit and Pause/Resume controls.

Example:

**10% discount from €100, maximum €10.**

Clarify whether the promotion is:

A. A voucher earned through an eligible purchase for a **later purchase**.

B. A voucher purchased with existing consumer wallet credit, optionally supplemented through Mollie.

C. An existing merchant promotional code distributed through ShopBonus.

The UI must not confuse these different acquisition methods.

The underlying voucher system determines eligibility and the consumer-facing terms.

## 4. CREATE AND EDIT OFFER

The merchant configures:

- Name and public description.
- Discount type/value.
- Minimum spend.
- Maximum discount.
- Start and expiration date.
- Claim validity and redemption validity where applicable.
- Applicable categories/products.
- One use per code and relevant customer restrictions.
- Combination rules.
- Total code allocation or campaign budget.
- Applicable reward/merchant funding rules.

Use existing branch/category defaults as editable suggestions.

Display a cost preview before activation.

**Example:** A €10 consumer reward with a 30% ShopBonus service fee represents a €13 merchant commitment under the established direct-shop model.

The offer editor must obtain the calculation from the existing financial service. This page must not independently charge a wallet.

Changes to an active offer must not silently alter the contractual conditions of already-issued vouchers.

Apply versioning or restrict edits where needed.

## 5. CODE INVENTORY

Display the Excel-like code inventory shown in the screenshot.

Columns:

- Selection checkbox.
- Discount code.
- Status.
- Assigned customer/reference.
- Batch.
- Date added.
- Actions.

Optional row details:

- Voucher reference.
- Reserved/claimed date.
- Redemption date.
- Expiration date.
- Related verified order.
- Platform synchronization status.

**Status handling**

Distinguish:

- Available.
- Reserved.
- Issued/assigned.
- Claimed.
- Redeemed.
- Expired.
- Invalid.
- Cancelled.

Do not expose a reserved code as Available.

For an Answer24-authorized free voucher, reserve a unique code at authorized handoff and apply the existing 90-day claim rule.

Consumer wallet-funded vouchers follow the existing purchase and assignment process.

A code must never be allocated to two consumers or redeemed twice.

If a code is assigned, show the permitted customer/reference rather than an incorrect dash.

## 6. ADD OR IMPORT CODES

Support the three screenshot actions:

**Paste Codes**

Paste multiple codes, one per line.

**Upload Excel/CSV**

Import a file with supported columns and clear mapping/validation.

**Export**

Download the selected or filtered code inventory, subject to merchant permissions.

Requirements:

- Trim and normalize input safely.
- Detect duplicates inside the upload.
- Detect codes already present in the applicable scope.
- Validate format and allowed lengths.
- Show a preview before committing.
- Report imported, rejected and duplicate totals.
- Support large imports through queues.
- Record batch ID, actor and timestamp.
- Prevent partial failures from creating inconsistent allocations.

**Important:** Uploading a code into ShopBonus does not prove that the webshop checkout accepts it.

Show platform validation status separately.

Use existing platform coupon APIs/connectors when supported.

Where verification is unavailable, display **Merchant verification required** rather than claiming the code is ready.

## 7. COUNTERS, FILTERS AND ACTIONS

The screenshot contains four inventory counters:

- Total codes.
- Available.
- Issued.
- Invalid.

Ensure these counts reconcile with all underlying statuses.

Reserved, claimed, redeemed and expired codes must not disappear from totals or be incorrectly included as Available.

Consider changing the summary to:

**Total | Available | Reserved/Issued | Redeemed/Invalid**

Keep complete state details accessible through filters.

**Filters**

- Search code/reference.
- Status.
- Batch.
- Date range.
- Assigned/unassigned.
- Platform validation state.

**Row actions**

Permitted actions may include:

- View history.
- Copy code where authorized.
- Verify code status.
- Invalidate an unused code.
- Retry supported platform synchronization.

Do not allow manual reassignment or reactivation of redeemed codes.

Bulk actions require confirmation, backend authorization and audit records.

Use server-side sorting and pagination for large inventories.

## 8. REAL WEBSHOP VERIFICATION

A ShopBonus voucher has value only if the connected webshop can apply it.

The system must distinguish:

1. Code exists in ShopBonus.
2. Code is confirmed to exist on the webshop platform.
3. Code is available for the intended customer/order.
4. Code has actually been redeemed in a verified order.

Use the existing Universal Webshop Order Adapter and platform-specific coupon capabilities.

Never assume that order-webhook support also guarantees coupon creation or validation support.

Show:

- Verified.
- Verification pending.
- Merchant action required.
- Unsupported integration.
- Sync error.

When possible, verify discount type, amount, minimum order value, expiration and usage restrictions against the actual provider configuration.

If direct coupon integration is unavailable, provide clear merchant instructions and allow a controlled manual verification workflow.

Do not advertise automatic checkout coupon support for unverified platforms.

## 9. PURCHASE, REDEMPTION AND FINANCIAL INTEGRITY

The first eligible purchase may earn a voucher for a subsequent eligible purchase.

Example:

Customer spends €102 at Woon & Co under an offer requiring €100.

The existing order ingestion and eligibility service verifies the purchase and calculates the reward.

The reward is recorded against the correct offer and merchant.

The customer must complete the established ShopBonus account/claim process before using the voucher.

For a consumer purchasing a €20 voucher with €10 wallet credit, the remaining €10 is handled through the existing Mollie payment flow.

This page only displays the resulting voucher/code status.

**Financial safeguards**

- Never create charges merely by importing codes.
- Never charge twice for duplicate orders/events.
- Respect existing merchant funding/reservation rules.
- Handle refunds, reversals and cancelled orders.
- Preserve original voucher and financial audit history.
- Prevent issuance when the relevant financial/eligibility service rejects it.
- Affiliate points are never paid out as cash and do not require manual payout approval.

## 10. SECURITY AND PERFORMANCE

Every protected API action must enforce:

- Authenticated merchant session.
- Correct Workspace and webshop ownership.
- Laravel Policies and role permissions.
- Server-side input validation.
- CSRF/session security.
- Rate limiting.
- Audit logging and structured errors.
- 401/403/422/429/500 response handling with request IDs.

Never expose another merchant's voucher inventory.

Protect unused discount codes from unauthorized bulk extraction.

Use database uniqueness constraints and atomic reservations for code assignment.

Queue large imports and platform sync operations.

Add indexes for webshop, offer, code, status, batch and creation date.

Reuse Redis/caching only where it preserves accurate inventory counts.

Ensure concurrent assignment cannot allocate the same code twice.

## EXACTLY 10 END-TO-END TESTS

**E2E 1 — Offer selection**

Create three offers for Woon & Co. Select each through the dropdown. Verify title, conditions, status, code inventory and counters refresh correctly without displaying another offer's codes.

**E2E 2 — Offer creation**

Create a 10% offer with €100 minimum, €10 maximum and 90-day validity. Save and reopen it. Verify all fields persist and the existing eligibility service receives the correct configuration.

**E2E 3 — Offer edit protection**

Issue a voucher under the original terms. Edit the offer to a different discount. Verify already-issued vouchers retain their applicable original conditions and new issuance uses the approved updated version.

**E2E 4 — Import validation**

Paste ten codes containing one duplicate and one invalid entry. Verify preview, rejection reasons and successful imports. Repeat with Excel/CSV and confirm batch audit history and accurate totals.

**E2E 5 — Concurrent reservation**

Start two simultaneous requests for the final available code. Verify only one reservation succeeds, no code is assigned twice and all inventory counters remain accurate.

**E2E 6 — Voucher lifecycle**

Move a real test voucher through Available → Reserved → Claimed → Redeemed. Verify status, timestamps, related consumer/order and audit history. Repeated redemption is rejected.

**E2E 7 — Real webshop compatibility**

Verify a coupon against a connected webshop with supported coupon APIs. Check discount, minimum spend and expiration. Simulate unsupported coupon APIs and confirm the UI displays Merchant verification required instead of Verified.

**E2E 8 — Wallet and order integration**

Import a verified €102 order eligible for a €10 next-purchase reward. Verify the existing system records eligibility once, enforces merchant funding and attaches the correct voucher. Replaying the order creates no second reward or charge.

**E2E 9 — Filtering and export**

Import 500 codes across three batches and multiple statuses. Test search, sorting, pagination, status/batch filtering and authorized export. Verify exported records match the active filters and counts.

**E2E 10 — Permissions and operational failures**

Merchant A attempts to access Merchant B's codes through the API and receives 403. Simulate a failed upload, revoked integration token and platform timeout. Verify safe retries, accurate statuses, audit/request IDs and no duplicate codes or unintended wallet transactions.

## DEFINITION OF DONE

- Existing offer/voucher functionality reused.
- Screenshot-matching merchant management page.
- Functional offer selector, New Offer, Edit and Pause.
- Accurate code inventory and status counters.
- Working paste, Excel/CSV import and export.
- Reliable reservation, claiming and redemption state.
- Real webshop coupon compatibility clearly verified.
- Correct connection to existing order and wallet systems.
- Full tenant isolation, auditing and error handling.
- Ten passing E2E tests with evidence.

**Deliver:** A short Working/Partial/Missing audit, implemented fixes, production-like screenshots and E2E results.

---

## Trello #8: MERCHANT DASHBOARD: LIVE OVERVIEW, OFFERS, NOTIFICATIONS & CLAIM ANALYTICS

List: **Hassan**. Card: https://trello.com/c/oU8MDcnh/8-merchant-dashboard-live-overview-offers-notifications-claim-analytics. Archived: False.

## OBJECTIVE

Audit, complete and test the **Merchant Overview Dashboard** shown in the attached screenshot.

This is the homepage for a webshop owner after logging into ShopBonus.

The merchant must immediately see their active offers, available codes, voucher activity, important notifications and recent claim performance.

**Reuse the existing ShopBonus platform.** Do not rebuild offer management, discount-code inventory, voucher processing, merchant Wallet, webshop integrations or reporting.

This page brings existing information together and provides direct access to the correct management screens.

## 1. EXISTING FUNCTIONALITY — AUDIT FIRST

Inspect the real implementation before writing new code.

ShopBonus already has specifications and potentially implemented components for:

- Merchant registration and approved Workspaces.
- Webshop selection and ownership permissions.
- Offers and Discount Codes management.
- Voucher reservation, issuance, claiming and redemption.
- Universal Webshop Order Adapter and verified orders.
- Merchant Wallet and campaign funding.
- Notifications and platform audit events.
- Reporting and campaign statistics.

Determine which components are actually operational.

Classify relevant endpoints and UI components as **Working / Partial / Missing / Blocked**.

Connect the dashboard to authoritative existing services.

No parallel voucher counters, notification stores or offer databases.

## 2. SCREEN DESIGN

Match the attached screenshot and established ShopBonus visual style.

Use:

- White horizontal navigation.
- ShopBonus logo.
- Dark navy and bright-blue typography.
- Large clean heading.
- Rounded white cards.
- Subtle pale-blue backgrounds.
- No unnecessary sidebar.

**TOP NAVIGATION**

Overview | Offers & Codes | Notifications | Settings

Right side:

- Current webshop selector.
- User profile.
- Notification indicator.

All navigation links must work and respect the logged-in merchant's permissions.

**PAGE HEADER**

Example:

"Good morning, Woon & Co"

"Your discount campaigns in one overview."

Use the actual webshop name and an appropriate greeting.

**NEW OFFER**

Prominent blue button opens the existing offer creation flow.

Do not build another offer editor.

## 3. FOUR LIVE KPI CARDS

Display exactly the four primary statistics shown in the screenshot.

### Active Offers

Count currently active offers belonging to the selected webshop.

Exclude draft, paused, expired and disabled offers.

### Available Codes

Count unique unused codes currently available for allocation.

Exclude reserved, assigned, claimed, redeemed, invalid and expired codes.

### Issued Vouchers

Count vouchers actually issued to consumers under the selected webshop.

Use the established voucher state model and documented counting rules.

### Claimed Vouchers

Count vouchers that have been successfully claimed.

Previously claimed vouchers that were subsequently redeemed must be handled consistently according to the documented metric definition.

**Requirements**

- All metrics must be loaded from backend services.
- Counts must reconcile with the detailed Offers & Codes page.
- No hardcoded production figures.
- Each KPI opens the correctly filtered existing management view.
- Show loading, empty and error states.
- Refresh after relevant voucher and offer events.

Use one consistent definition for every KPI throughout ShopBonus.

## 4. YOUR OFFERS — MAIN TABLE

Show the selected webshop's offers.

Columns:

**Offer | Type | Conditions | Inventory | Issued | Status | Manage**

Example:

10% discount
Minimum order €100
250 codes in stock
68 issued
Active

**Offer**

Display the merchant-friendly offer name, discount and minimum-spend summary.

**Type**

Percentage or fixed amount.

**Conditions**

Show the minimum eligible order amount. Full conditions remain available in the detail page.

**Inventory**

Show actual available code stock, not the original imported quantity.

**Issued**

Show the correct issued-voucher count.

**Status**

Active / Draft / Paused / Expired.

**Manage**

Open the selected offer in the existing Offers & Codes interface.

Use server-side pagination for merchants with many offers.

Sort active and attention-required offers appropriately.

Do not silently hide paused or expired offers; provide filtering.

## 5. OFFER ACTIONS

The merchant can:

1. Create an offer.
2. Open an existing offer.
3. View its code inventory.
4. Edit permitted offer settings.
5. Pause or resume an offer.
6. Review current code availability.
7. Navigate to relevant voucher activity.

Use existing APIs and business rules.

**Critical:** Changing or pausing an offer must not silently invalidate previously issued vouchers contrary to their applicable conditions.

Offer versioning, expiry and issued-voucher rights remain controlled by the existing voucher system.

## 6. NOTIFICATIONS PANEL

The screenshot shows:

- Low code inventory.
- New voucher claims.

Extend this using existing notification/event services.

Supported merchant notifications should include:

- Low available-code inventory.
- New voucher claim.
- Offer nearing expiration.
- Invalid or exhausted code batch.
- Webshop connector errors affecting vouchers.
- Merchant funding or balance issues.
- Important offer verification failures.

Each notification needs:

- Type and severity.
- Clear description.
- Related webshop/offer reference.
- Timestamp.
- Read/unread state.
- Direct action to the affected record.

**All Notifications** opens the existing merchant notifications page.

The top navigation unread badge must match the same unread records.

Use deduplication and configurable thresholds.

Example:

"Code inventory running low — 12 codes remaining."

Click → Open that offer's inventory filtered to available codes.

A notification must not expose data from another Workspace.

## 7. CLAIMS CHART

Display **Claims during the last 30 days**.

Use existing voucher claim events.

Requirements:

- Default: Last 30 days.
- Options: 7 / 30 / 90 days and custom range if supported.
- Daily claim counts.
- Correct webshop timezone.
- Exact values on hover.
- Clear zero-data state.
- Backend date filtering.
- Correct chronological ordering.
- Refresh after new claims.

The chart must count actual successful claim events.

Do not confuse:

- Voucher issuance.
- Claiming.
- Redemption.
- Purchase conversion.

Those are different lifecycle events.

Clicking a data point should open the voucher overview filtered to the chosen date and selected webshop.

## 8. MERCHANT WORKSPACE SELECTION

The webshop selector in the top navigation controls the entire dashboard.

When switching from Webshop A to Webshop B:

- All four KPIs update.
- Offer rows update.
- Notifications update.
- Claims chart updates.
- New Offer uses the selected webshop.
- All links remain correctly scoped.

Do not mix data across shops.

A merchant with multiple authorized shops may switch between them.

A merchant with only one shop sees that shop by default.

Unauthorized webshop IDs must be rejected by the backend.

## 9. DATA ACCURACY AND BUSINESS RULES

The dashboard must reflect the existing ShopBonus ecosystem.

**Order and voucher flow**

A verified webshop order may qualify a consumer for a reward.

The existing services determine whether a voucher may be issued, claimed and later redeemed.

This dashboard observes those results. It does not make independent eligibility decisions.

**Code inventory**

Codes may be available, reserved, issued, claimed, redeemed or invalid.

Use the established state model and prevent double counting.

**Merchant funding**

Offer activation and voucher issuance remain subject to existing campaign and merchant Wallet rules.

The dashboard must not independently reserve funds or calculate financial charges.

**Returns and reversals**

Refunds, cancelled orders and revoked eligibility must update the underlying records and relevant statistics according to established business rules.

Maintain a clear audit trail.

## 10. SECURITY, PERFORMANCE AND OPERATIONS

Enforce:

- Authentication.
- Workspace and webshop isolation.
- Laravel Policies and role permissions.
- Ownership checks on every API request.
- Input and date-filter validation.
- CSRF/session protections.
- Rate limiting.
- Structured errors and request IDs.
- Audit logging for sensitive actions.

Record relevant 401/403/422/429/500 failures.

**Performance**

- Reuse existing cached counts and aggregate reporting where suitable.
- Index offer, webshop, voucher status and claim timestamps.
- Avoid fetching complete voucher histories to calculate dashboard totals.
- Use bounded queries and pagination.
- Load independent sections safely.
- Display stale-data warnings if reporting is delayed.
- Keep the page functional during temporary connector/reporting failures.

Prefer supported official Laravel features already present in the project.

Document changed routes, permissions and relevant API contracts.

## EXACTLY 10 END-TO-END TESTS

**E2E 1 — Merchant login and dashboard**

Sign in as the approved owner of Woon & Co. Verify the correct merchant name, webshop selector, top navigation, four KPIs, offers table, notifications and claims chart load using real backend responses.

**E2E 2 — Active offer counting**

Create three active offers, one paused offer and one expired offer. Verify Active Offers = 3. Confirm all five remain discoverable in the appropriate offer management filters and clicking the KPI opens the active-offers view.

**E2E 3 — Code inventory accuracy**

Import 100 unique codes. Reserve 10, issue 15, redeem 5 and invalidate 3 without overlapping code records. Verify Available = 67 under the established state rules. Compare the dashboard count with the actual inventory page and database state.

**E2E 4 — Voucher issue versus claim**

Issue 20 vouchers and successfully claim 8. Verify Issued = 20 and Claimed = 8, subject to the established metric definitions. Redeem 3 claimed vouchers and verify the dashboard does not incorrectly count them as newly issued or newly claimed.

**E2E 5 — Offer navigation**

Click Manage on a 10%-discount offer. Verify the existing Offers & Codes screen opens with that exact offer selected, showing the correct configuration, code inventory and status. Return to the dashboard without losing webshop context.

**E2E 6 — Create and pause offer**

Click New Offer, create an offer using the existing editor and activate it after required validation. Confirm the dashboard adds the row and updates Active Offers. Pause it and verify the active count decreases without improperly invalidating already-issued vouchers.

**E2E 7 — Low inventory alert**

Set the inventory warning threshold to 15 codes and reduce available stock to 12. Verify exactly one actionable low-stock notification appears, the unread badge increments and clicking the notification opens the correct inventory. Replenish stock and verify the active warning resolves.

**E2E 8 — Claim chart integrity**

Create successful claims on three distinct dates and include unsuccessful/repeated claim attempts. Verify the chart counts only valid claim events once, respects the selected date range and timezone, and opens a matching filtered record list when a point is selected.

**E2E 9 — Multiple webshop isolation**

Give one merchant access to Webshop A and Webshop B. Switch between them and verify all cards, rows, notifications and chart data change correctly. Attempt to request an unauthorized Webshop C through the API; verify 403 and an audit entry.

**E2E 10 — Failures, recovery and performance**

Simulate a failed dashboard aggregate request, stale reporting data and a concurrent voucher claim. Verify meaningful error/retry behavior, no fabricated counts, correct refresh after recovery and no duplicate claim totals. Check API authorization, request-ID logging and acceptable page performance with a large voucher inventory.

## DEFINITION OF DONE

- Screenshot-matching Merchant Overview Dashboard.
- Existing backend services reused.
- Four accurate, clickable KPI cards.
- Functional offers list and management navigation.
- Live, relevant merchant notifications.
- Accurate claim analytics with working period filters.
- Correct webshop switching and tenant isolation.
- Proper handling of voucher lifecycle and inventory changes.
- Protected, documented API routes and operational monitoring.
- Exactly 10 passing E2E tests with evidence.

**Deliver:** Implementation audit, required fixes, working page screenshots and ten documented E2E results.

---

## Trello #9: MERCHANT ONBOARDING: STEP 2 — FIRST VOUCHER SETUP & LIVE PREVIEW

List: **Hassan**. Card: https://trello.com/c/GPj1W9ug/9-merchant-onboarding-step-2-first-voucher-setup-live-preview. Archived: False.

## OBJECTIVE

Complete and test **Step 2 of the merchant registration flow**, matching the attached screenshot.

After entering their webshop details, a merchant configures their first ShopBonus offer, reviews its live preview, checks the webshop information and continues to activation.

This screen must be simple enough for merchants to complete independently.

**Reuse existing ShopBonus services:** Merchant onboarding, Workspaces, categories, Universal Webshop Order Adapter, vouchers, discount-code inventory, merchant Wallet, Mollie/Moneybird and platform permissions.

Do not create a separate offer engine or duplicate merchant registration.

## 1. EXISTING SYSTEMS — AUDIT FIRST

Check whether the current implementation already supports:

- Merchant registration and onboarding progress.
- Saved webshop/company details.
- Automatically enriched webshop information.
- Branch/category classification.
- Offer and voucher configuration.
- Voucher preview rendering.
- Discount-code paste and Excel/CSV import.
- Connected webshop verification.
- Merchant funding and activation requirements.

Classify each requirement as **Working / Partial / Missing / Blocked**.

Reuse existing APIs and models. Implement only missing frontend integration, validation and onboarding-specific behavior.

## 2. SCREEN AND NAVIGATION

Match the screenshot and existing ShopBonus visual design.

**Top area**

- ShopBonus logo.
- Merchant registration title.
- Three-step progress indicator.

Steps:

1. Details — completed.
2. Voucher Setup — current.
3. Activate — pending.

Completed steps display a green checkmark.

The merchant must be able to return to previous steps without losing entered information.

Do not mark Step 2 complete until its required validations pass.

**Main layout**

LEFT: Offer configuration.

RIGHT: Live voucher preview and webshop information.

Use responsive layouts without changing the established design language.

## 3. OFFER CONFIGURATION

The form contains:

**Discount Type**

Dropdown:

- Percentage.
- Fixed amount.

**Discount**

Example: 10%.

**Minimum Order Value**

Example: €100.

**Maximum Discount**

Example: €10.

For fixed-amount offers, only display maximum-discount settings where logically applicable.

**Voucher Validity**

Example: 90 days.

Clearly separate:

- How long the consumer has to claim an issued voucher.
- How long a claimed voucher remains usable.
- Any campaign start/end dates.

These periods must not be accidentally treated as identical.

**Additional Conditions**

Editable text with length limits.

Example:

"Cannot be combined with other promotions."

Use structured existing offer rules wherever possible; descriptive text cannot override backend eligibility checks.

## 4. EXISTING SHOPBONUS OFFER RULES

The merchant must understand the actual customer journey.

Example:

**Spend €100, earn a €10 voucher for your NEXT eligible purchase.**

The customer does not automatically receive €10 off the first qualifying order.

Processing:

1. A customer places an eligible order.
2. The Universal Order Adapter receives and verifies it.
3. The existing eligibility service determines the reward.
4. The voucher is issued/reserved according to the configured flow.
5. The customer claims it through a verified ShopBonus account.
6. The customer redeems it on a later eligible order.

Show a clear explanation next to the offer configuration.

Use existing branch-specific defaults, where available, as suggested starting values.

Examples:

- Fashion: a higher cashback percentage may be suitable.
- Electronics: typically a lower suggested percentage.
- Home/interior: configurable spend threshold and maximum reward.

These are suggestions, not mandatory universal rates.

Merchants can change permitted settings.

All calculations are performed by the existing offer service.

## 5. MERCHANT COST PREVIEW

Display a small, understandable cost preview below the configuration.

Example:

Customer voucher: €10
ShopBonus fee: €3 (30% on top)
**Total merchant commitment: €13**

Use the existing financial pricing service to calculate these values.

The preview must update when the discount, minimum spend or applicable category rules change.

For percentage discounts, calculate the actual applicable reward using the eligible order amount and maximum discount cap.

Distinguish an estimated maximum commitment from an actual booked or reserved obligation.

No money is charged simply because the merchant enters an offer.

Final activation remains controlled by existing merchant Wallet, funding and approval rules.

## 6. DISCOUNT CODE IMPORT

Preserve the screenshot's upload area.

Supported methods:

- Paste codes directly.
- Upload CSV.
- Upload Excel.

Use the existing code inventory/import service.

Show:

- Number of valid unique codes found.
- Duplicate count.
- Invalid count.
- Import progress.
- Resulting batch reference.

Example:

"500 unique codes found."

This means the file passed ShopBonus import validation, **not automatically that all 500 codes work at the connected webshop**.

Validate platform coupon compatibility separately.

Do not allow the same code to be assigned twice.

Where existing integrations support authorized coupon creation, provide that option without requiring manual uploads.

If codes cannot yet be verified at checkout, show **Verification required**.

The onboarding may save an unfinished draft, but must not falsely activate unsupported coupon delivery.

## 7. LIVE VOUCHER PREVIEW

The right-hand panel displays an accurate preview of what consumers will see.

Include:

- Webshop name and logo.
- Webshop image.
- Offer title.
- Discount amount.
- Minimum order value.
- Maximum discount.
- Applicable categories.
- Relevant conditions.
- Claim/redemption explanation.
- Validity information.

Every relevant form change updates the preview immediately.

Example:

**Woon & Co — €10 voucher**

"Spend at least €100 and earn €10 for your next eligible purchase."

Avoid misleading copy such as "10% discount today" when the voucher applies only to a future purchase.

The preview must use the same underlying presentation model as the public ShopBonus voucher card.

Preview data must not be publicly published before activation.

## 8. WEBSHOP INFORMATION

Display the company information collected during the previous registration step:

- Webshop name.
- Verified website/domain.
- Logo.
- Categories.
- Description.

Allow **Edit** to return to the relevant form or open the existing authorized editing interface.

Clearly identify unverified or automatically suggested information.

The merchant must review and confirm the relevant details before activation.

Changing the webshop/domain must trigger applicable ownership and integration verification again.

Do not blindly trust external enrichment data.

## 9. SAVE, CONTINUE AND ACTIVATION READINESS

The main button:

**Next: Activate**

Required behavior:

1. Validate the offer form.
2. Save the offer as a draft.
3. Validate available imported codes or supported code-generation route.
4. Verify the merchant and webshop context.
5. Check the configuration against existing voucher rules.
6. Show any missing activation requirements.
7. Continue to Step 3.

Do not activate the offer merely because Step 2 was completed.

Step 3 handles the final activation requirements using existing systems.

Examples of blockers:

- Missing merchant approval.
- Invalid discount configuration.
- No working code delivery mechanism.
- Unverified webshop connection.
- Insufficient funding when required.
- Missing required business information.

Allow saving progress and returning later.

The merchant must never lose a correctly saved draft after refreshing or logging in again.

## 10. SECURITY, AUDIT AND PERFORMANCE

Enforce:

- Authenticated merchant ownership.
- Correct tenant/Workspace scope.
- Laravel Policies.
- Form and upload validation.
- CSRF/session security.
- Rate limits.
- Secure file handling.
- Audit logging for offer changes and code imports.
- Structured 401/403/422/429/500 errors with request IDs.

Encrypt sensitive integration credentials and never include them in frontend previews.

Use queues for large code imports.

Apply uniqueness constraints and atomic operations for code inventory.

Keep the live preview responsive without excessive API requests.

Reuse existing supported Laravel tools for validation, authorization, file storage, queues and caching.

## EXACTLY 10 END-TO-END TESTS

**E2E 1 — Onboarding progress**

Complete merchant registration Step 1 and open Step 2. Verify the progress indicator shows Step 1 completed, Step 2 current and Step 3 pending. Return to Step 1, update an allowed field and reopen Step 2 without losing saved data.

**E2E 2 — Percentage offer**

Configure 10% discount, €100 minimum spend, €10 maximum discount and 90-day validity. Save the draft. Reload the page and verify every configuration field persists correctly.

**E2E 3 — Fixed-value offer**

Change the discount type to €5 fixed discount with €50 minimum spend. Verify the form displays relevant fields only, rejects invalid values and updates the existing offer model correctly.

**E2E 4 — Live preview accuracy**

Enter a 10% offer with €100 minimum and €10 cap. Verify the preview displays the correct webshop logo, name, categories, conditions and next-purchase reward explanation. Change the offer and verify the preview updates immediately without publishing.

**E2E 5 — Merchant cost calculation**

Configure a €10 consumer reward with the established 30% fee. Verify the cost preview shows €10 reward, €3 ShopBonus fee and €13 total commitment. Modify the configuration and verify the authoritative pricing service recalculates the preview without posting a financial charge.

**E2E 6 — Code paste/import**

Paste 100 codes including duplicates and invalid entries. Verify accurate accepted/rejected counts, no duplicate records and a complete import report. Repeat with CSV/Excel and verify the existing inventory service receives the batch.

**E2E 7 — Checkout compatibility**

Import valid-format codes that are not recognized by the connected webshop. Verify the interface does not classify them as checkout-verified. The activation process must block unsupported automatic redemption or require the established merchant verification route.

**E2E 8 — Webshop details**

Load automatically suggested webshop information, change an allowed category and confirm the details. Verify the preview updates and the correct Workspace is saved. Change the store domain and verify ownership/integration checks are required again.

**E2E 9 — Draft and activation gating**

Complete all Step 2 fields but leave a required integration or funding condition unresolved. Verify the draft saves and Step 3 displays the exact blocker without publishing the offer. Resolve the condition and verify the merchant can proceed through the existing activation process.

**E2E 10 — Permissions and recovery**

Attempt to modify another merchant's draft via the API and verify 403 with audit logging. Simulate a failed upload and temporary save timeout. Verify safe error messages, no duplicate codes, preserved previously saved data and successful retry.

## DEFINITION OF DONE

- Screenshot-matching onboarding Step 2.
- Existing ShopBonus systems reused.
- Functional percentage/fixed-discount configuration.
- Correct next-purchase voucher explanation.
- Live, accurate voucher preview.
- Merchant cost preview using existing pricing rules.
- Working paste/CSV/Excel import.
- Correct webshop details and verification.
- Draft persistence and safe Step 3 transition.
- Full tenant isolation, validation and audit handling.
- Exactly 10 passing E2E tests with evidence.

**Deliver:** Implementation audit, fixed frontend/backend connections, working page screenshots and E2E test results.

---

## Trello #10: CONSUMER PROFILE, ACCOUNT SECURITY & EMAIL PREFERENCES

List: **Hassan**. Card: https://trello.com/c/2Twcoxxt/10-consumer-profile-account-security-email-preferences. Archived: False.

## OBJECTIVE

Audit, complete and test the **Consumer Profile** page shown in the attached screenshot.

Consumers must manage their personal details, profile photo, account security and communication preferences from one simple page.

**Reuse existing ShopBonus functionality:** Consumer registration, authentication, email verification, Wallet, vouchers, notification services and existing permissions.

Do not create duplicate user accounts, email systems, voucher reminder engines or wallets.

This page must use the same clean ShopBonus interface as the existing consumer dashboard.

## 1. EXISTING SYSTEMS — AUDIT FIRST

Inspect the actual implementation and identify whether these components are working:

- Consumer authentication and account profiles.
- Email verification.
- Password changes and reset flows.
- Profile image storage.
- Consumer Wallet.
- Voucher ownership and expiration.
- Email notifications and reminders.
- Communication preferences.
- Account data export/deletion flows, if already available.

Classify requirements as **Working / Partial / Missing / Blocked**.

Reuse existing models, API routes, notification templates and security services.

## 2. SCREEN DESIGN

Match the attached screenshot.

**Top navigation**

Webshops | My Benefits | Profile

Right side:

- Current consumer Wallet balance.
- Profile avatar.
- Account dropdown.

**Page title**

My Profile

Subtitle: Manage your personal information and account settings.

**Main layout**

LEFT: Personal information.

RIGHT TOP: Account security.

RIGHT BOTTOM: Email notifications.

Keep the white cards, soft-blue background, bright-blue buttons and rounded design.

Ensure responsive mobile and desktop layouts.

## 3. PERSONAL INFORMATION

Display:

- Profile photo/avatar.
- First name.
- Email address.
- Optional phone number.
- Save Changes button.

Use the existing consumer profile model.

**First name**

Required. Validate length and permitted characters.

**Email address**

Display the current verified email.

Changing an email address must trigger a new verification process.

Do not immediately replace the trusted login email without the existing secure verification workflow.

Keep the account accessible during a pending email change.

**Phone number**

Optional.

Accept international numbers with country code.

Normalize supported numbers consistently.

Never require a telephone number merely to claim a standard voucher unless an existing authorized flow specifically requires it.

**Profile photo**

Allow secure upload, replacement and removal.

Validate file type, size and contents.

Store files through the existing managed storage service.

Use a fallback avatar when no image exists.

Never expose internal storage paths.

**Save Changes**

Save valid changes through the authenticated API.

Display success or field-level validation errors.

Prevent accidental duplicate submissions.

## 4. ACCOUNT SECURITY

The screenshot contains:

- Change Password.
- Email Verification Active.

Both must be functional.

### Change Password

Open the existing secure password-change flow.

Require:

- Current password, unless a verified recovery flow is used.
- New password.
- Password confirmation.
- Existing password policy enforcement.
- Appropriate throttling.
- Secure session/token handling after a successful change.

Never display or log passwords.

### Email Verification

Show the actual verification status:

- Verified.
- Verification pending.
- Verification required.

Allow resending verification emails with rate limiting.

Changing the email address invalidates the new address's verified status until verification is completed.

Do not mark an email as verified based only on frontend state.

### Security Status

Do not display "Your account is fully secure" without sufficient evidence.

Show factual security information, such as:

"Email address verified."

Where the existing authentication system supports additional security features, link to their established management screens.

Do not create a competing authentication system.

## 5. EMAIL NOTIFICATION PREFERENCES

The screenshot shows three categories:

**Vouchers & Discounts**

Optional promotional communication about discounts, new rewards and relevant offers.

**Reminders**

Voucher-related reminders and expiration notifications.

**Offers & Inspiration**

Optional promotional messages about participating webshops and categories.

Each checkbox must:

- Load the actual saved preference.
- Be independently configurable.
- Persist after refresh and login.
- Affect the relevant notification dispatch rules.
- Respect the existing consent records.

Separate mandatory service/security communication from optional marketing subscriptions.

Disabling promotional emails must not prevent essential account-security messages or lawful transactional information.

Do not treat account registration as automatic marketing consent.

Store consent/preference history when required by the existing compliance model.

## 6. VOUCHER REMINDER INTEGRATION

Reuse the existing voucher expiration and notification engine.

For Answer24-origin free vouchers, the established process includes:

- A code reserved at authorized handoff.
- A 90-day claim period.
- Permitted reminders on days 30, 60 and 80.

The profile controls must respect the relevant communication permissions.

Only send reminders through channels and circumstances permitted by the existing consent and notification rules.

Never treat a preference checkbox as permission to contact a consumer through unrelated channels.

Ensure:

- No reminder after a voucher is claimed, expired or cancelled if no longer applicable.
- No duplicate reminders.
- Correct claim deadline.
- Correct webshop/voucher reference.
- Unsubscribe and preference changes are honored.
- Retry-safe notification delivery.

ShopBonus does not perform telemarketing; Answer24 handles its own authorized communications.

## 7. CONSUMER WALLET IN HEADER

The screenshot displays a balance of €12.20.

Use the existing consumer Wallet service.

Clicking the Wallet balance opens the existing My Benefits/Wallet view.

Wallet balance must reflect authoritative ledger data.

Consumer affiliate points and wallet rewards are not cash-withdrawable.

They can be used according to the established ShopBonus rules, including purchasing eligible discount vouchers.

Example:

A voucher costs €20.

Consumer Wallet balance: €10.

Remaining €10 can be paid through the existing Mollie payment flow.

This profile page must not create another checkout or payment implementation.

Avoid showing an incorrect balance after spending, voucher purchase or a reversal.

## 8. PROFILE, CATEGORIES AND FAVORITES

Reuse existing consumer preferences from onboarding and the webshop discovery module.

The wider ShopBonus platform supports:

- Favorite webshops, selected using a heart icon.
- Preferred webshop categories.
- Personalized offer discovery.

If these preferences already have a management location, provide a clean link from the Profile page or account menu.

Do not duplicate their storage or recommendation logic.

Profile changes must not erase favorite webshops, favorite categories, existing vouchers or Wallet history.

## 9. PRIVACY, ACCOUNT DATA AND OPERATIONS

Provide access to existing account privacy controls, where implemented:

- Review account information.
- Access applicable privacy information.
- Request or download personal data.
- Request account deletion.
- Manage marketing preferences.

Account deletion must follow the established retention, financial and audit requirements. Do not directly delete voucher/financial ledger records where they must legitimately be retained.

Never display another consumer's profile or voucher information.

Sensitive changes must be auditable without logging passwords, verification tokens or unnecessary personal data.

## 10. SECURITY AND PERFORMANCE

Every protected API endpoint must enforce:

- Authentication.
- Ownership validation.
- Laravel Policies/authorization.
- Input validation.
- CSRF/session security.
- Appropriate rate limiting.
- Audit logging.
- Structured errors and request IDs.

Handle 401/403/419/422/429/500 responses correctly.

Additional requirements:

- Secure profile image uploads.
- Prevent stored XSS through profile fields.
- Protect email verification and password reset tokens.
- Reject expired/reused verification links.
- Never expose secret tokens in client analytics.
- Avoid unnecessary profile data in logs.
- Ensure preference changes do not create duplicate notifications.

Reuse existing supported Laravel authentication, validation, notification, queue and storage facilities.

## EXACTLY 10 END-TO-END TESTS

**E2E 1 — Profile loading**

Sign in as a verified consumer. Open Profile. Verify the correct first name, email, verification status, optional phone, avatar, notification preferences and authoritative Wallet balance load from existing services.

**E2E 2 — Personal data changes**

Change the first name and phone number. Save and refresh. Verify the updated values persist. Submit invalid fields and confirm clear validation errors without partially corrupting the profile.

**E2E 3 — Profile photo**

Upload a valid image, refresh and verify it appears in the profile and header avatar. Replace and remove it. Attempt an unsupported/oversized file and verify secure rejection.

**E2E 4 — Email change and verification**

Request an email change. Verify the new address remains unverified until the correct verification link is used. Test an expired/reused link and verify rejection. Confirm the final verified email is stored correctly.

**E2E 5 — Password security**

Change the password using the correct current password. Verify the old password no longer authenticates and the new password works. Attempt an incorrect current password and repeated requests; verify secure errors and throttling.

**E2E 6 — Notification preferences**

Disable Vouchers & Discounts and Offers & Inspiration, while leaving Reminders enabled. Refresh and log in again. Verify selections persist and promotional dispatch respects the updated preferences.

**E2E 7 — Voucher reminders**

Create an eligible Answer24-origin voucher with a reserved code and 90-day claim deadline. Test permitted reminder decisions for days 30, 60 and 80. Claim the voucher before the next reminder and verify no inappropriate subsequent claim reminder is sent. Repeated queue delivery must not send duplicate reminders.

**E2E 8 — Wallet consistency**

Start with €12.20 consumer Wallet balance. Perform an authorized voucher purchase or ledger change using existing services. Verify the header balance updates correctly and clicking it opens the existing Wallet details. Ensure no cash withdrawal option is introduced.

**E2E 9 — Favorites and existing benefits**

Change profile details and notification preferences. Verify saved favorite webshops/categories remain unchanged. Confirm previously issued vouchers, claims and Wallet transaction history remain attached to the correct consumer.

**E2E 10 — Access control and failure recovery**

Attempt to read or update another consumer's profile through the API; verify rejection and audit logging. Simulate failed image storage and temporary API errors. Verify safe retry behavior, no exposed secrets, correct error responses/request IDs and no unintended partial account changes.

## DEFINITION OF DONE

- Screenshot-matching consumer Profile page.
- Existing user/authentication services reused.
- Working personal-data and profile-photo editing.
- Secure password and email-verification flows.
- Independently saved email notification preferences.
- Correct integration with existing voucher reminders.
- Accurate consumer Wallet balance.
- Favorites and existing benefits preserved.
- Privacy, ownership checks and audit logging enforced.
- Exactly 10 passing E2E tests with evidence.

**Deliver:** Existing implementation audit, completed fixes, working profile screenshots and E2E results.

---

## Trello #11: .

List: **Hassan**. Card: https://trello.com/c/WNl2kdvn/11-untitled. Archived: False.

_No description in the supplied export; do not infer requirements._

---

## Trello #12: .

List: **Hassan**. Card: https://trello.com/c/bMjOyDbg/12-untitled. Archived: False.

_No description in the supplied export; do not infer requirements._

---

## Trello #13: .

List: **Hassan**. Card: https://trello.com/c/yEggKI6u/13-untitled. Archived: False.

_No description in the supplied export; do not infer requirements._

---

## Trello #15: .

List: **Hassan**. Card: https://trello.com/c/DfUjJo02/15-untitled. Archived: True.

_No description in the supplied export; do not infer requirements._

---

## Trello #16: Webshop Onboarding & Workspace Management

List: **Frank**. Card: https://trello.com/c/UHnIQN5s/16-webshop-onboarding-workspace-management. Archived: False.

# ShopBonus — Webshop Onboarding & Workspace Management

## OBJECTIVE

Build the complete webshop onboarding and workspace management experience.

A registered user must be able to find their webshop, verify business ownership, accept the required agreement and receive access to their own merchant workspace.

Support multiple webshops per user, employee invitations, role-based permissions and switching between authorized workspaces.

One user identity can be a consumer, owner or employee of multiple webshops. Never create duplicate user accounts or grant ownership merely because someone selected “Webshop owner” during registration.

## USER JOURNEY

After verified email registration with webshop-owner intent:

1. Search for an existing business.
2. Select and confirm business details.
3. Verify ownership or submit a review request.
4. Review and accept the current merchant agreement.
5. Complete the webshop profile.
6. Enter the authorized merchant dashboard.
7. Invite employees and manage workspace access.

Show a progress indicator and allow safe draft continuation.

If onboarding is interrupted, resume at the last incomplete step. Users must never skip verification, agreement or approval by changing URLs or API requests.

## BUSINESS SEARCH & DETAILS

Provide a searchable business field using the Google Places API.

Search by company/trading name and location, with autocomplete and a manual entry option.

When selected, retrieve available:

- Business name and formatted address.
- Website/domain.
- Telephone number.
- Google Place ID.
- Business category.
- Location and country.

Only use fields legally available through the provider; request minimal necessary fields.

Prefill the form but let the user review it. External business data is not proof of ownership and must not automatically grant access.

Additional merchant information:

- Registered legal name.
- Trading name.
- Business website.
- Chamber of Commerce/KvK number, if applicable.
- VAT number, if applicable.
- Business contact email and telephone.
- Billing/contact address.
- Preferred language.
- Main contact person.

Validate formats and show which fields are missing.

Never publish personal or sensitive registration details to public pages by default.

## OWNERSHIP VERIFICATION

Support multiple secure verification methods.

**Business-domain email**

Send a short-lived, single-use verification link or code to an authorized mailbox at the webshop's verified business domain.

Do not assume every mailbox at the domain proves control; apply additional checks when needed.

**Website ownership**

Allow verification using a generated DNS TXT record or secure website verification file/token.

Verify the challenge server-side and invalidate it after successful use or expiry.

**Manual Super Admin review**

Required where automated verification is unavailable, ownership is disputed or the merchant uses a free email domain without sufficient alternative proof.

Allow supporting evidence through secure private upload when necessary.

Statuses:

- Draft
- Awaiting Verification
- Verification Failed
- Pending Review
- Approved
- Rejected
- Suspended

Only Approved workspaces become commercially active.

Prevent duplicate webshop claims and conflicting ownership. Existing webshop claims must enter a controlled review process; never silently transfer ownership.

## MERCHANT AGREEMENT

Before activation, show the currently published merchant agreement with links to applicable terms and privacy information.

Required actions:

- Review agreement.
- Explicit unticked acceptance checkbox.
- Confirm the legal/business identity.
- Accept the agreement.

Store:

- Agreement ID and immutable published version.
- Exact accepted document reference/hash.
- User and webshop.
- Acceptance timestamp.
- Relevant audit metadata.

Do not allow client-submitted agreement versions to override the server-selected published version.

If the agreement is updated, apply the configured reacceptance rules before allowing restricted commercial actions.

Do not present acceptance as completed until the server confirms persistence.

## WORKSPACES & MEMBERSHIPS

One webshop equals one independently secured workspace.

A user may have memberships in multiple webshops with different roles.

Use a central `users` identity and a membership pivot/model linking users to workspaces.

Suggested roles:

**Owner**
- Manage authorized webshop settings, integrations, offers and employees.
- Accept merchant agreements and perform sensitive owner actions.

**Manager**
- Manage daily webshop operations according to granted permissions.
- Cannot transfer ownership or assign higher privileges.

**Employee**
- View and perform only explicitly assigned functions.

**Super Admin**
- Review/approve/suspend workspaces and resolve ownership disputes.
- Internal role only; cannot be assigned through merchant interfaces.

Support granular permissions for merchant dashboard, orders, integrations, vouchers, team and settings.

A membership must be explicit, active and bound to the correct webshop. Client-provided `role`, `owner`, `tenant_id` or `workspace_id` must never elevate permissions.

## WORKSPACE SWITCHER

Provide a compact searchable workspace dropdown in the top navigation.

Show only workspaces for which the current user has an active membership.

Display webshop name, logo and relevant access/status indicator.

When switching:

- Set the authorized active workspace server-side.
- Reload scoped dashboard data.
- Clear stale frontend caches from the previous workspace.
- Revalidate permissions for every API request.
- Preserve clean, readable URLs without exposing unnecessary internal IDs.

If access was revoked or the workspace suspended, reject subsequent requests immediately and return to an allowed workspace or safe access screen.

## TEAM MANAGEMENT

Owner or permitted manager can invite employees by email.

Flow:

1. Enter email.
2. Select an allowed role/permission set.
3. Review invitation.
4. Send secure expiring invitation.
5. Invitee signs in or registers using the same central identity.
6. Invitee accepts and receives the authorized membership.

Invitation must be single-use, expiring and bound to the intended webshop and recipient.

Show team list with name, email, role, invitation status, last activity and actions.

Actions:

- Invite.
- Resend or cancel invitation.
- Change permitted roles.
- Suspend/remove membership.
- Review relevant access history.

A manager cannot invite an owner, promote themselves or grant permissions exceeding their own authority.

Prevent removal of the final active owner. Ownership transfer requires verified approval, fresh authentication and a separate audited workflow.

Revoking membership must immediately invalidate workspace access, including active sessions and realtime subscriptions.

## MERCHANT DASHBOARD ENTRY

After successful onboarding, redirect to the merchant dashboard.

Display:

- Webshop name and verified status.
- Profile completion.
- Quick links to Offers, Orders, Integrations, Team and Settings.
- Connection/setup status.
- Relevant notifications and required actions.

Show useful empty states for newly approved webshops.

Do not fabricate sales, order counts, voucher activity or completed integrations.

If onboarding is pending, show a clear status page, submitted information and permitted next action. Do not display active commercial features prematurely.

Use ShopBonus's established blue design with top navigation, responsive cards and accessible forms.

## DATABASE & API

Reuse central `users`.

Create/reuse:

- `webshops`: identity, business details, public slug, status, review metadata.
- `webshop_memberships`: webshop, user, role, permissions, status, timestamps.
- `webshop_verifications`: challenge type, hashed token, expiry, evidence/status.
- `webshop_agreement_acceptances`: published document/version, actor, webshop, timestamp.
- `webshop_invitations`: webshop, recipient, role, token hash, expiry, status.
- `webshop_onboarding_progress`: step completion and draft data.

Enforce unique active membership per user/webshop, unique invitation constraints and protection against duplicate claims.

Provide typed `/api/v1/` endpoints for:

- Business search/details.
- Webshop draft creation/update.
- Ownership challenge, verification and review submission.
- Merchant agreement retrieval/acceptance.
- Onboarding progress.
- Authorized workspace listing/selection.
- Webshop profile and membership management.
- Invitations, acceptance, revocation and role updates.
- Super Admin approval/rejection/suspension.

Use consistent response envelopes, validation errors and OpenAPI documentation.

All mutations must validate current server-side state and permitted state transitions.

## SECURITY, AUDIT & PERFORMANCE

Use Laravel Policies/Gates, Form Requests, database transactions, tenant scoping and least-privilege permissions.

Protect against:

- Unauthorized webshop claims.
- Invitation token reuse.
- Cross-workspace data access.
- Role escalation.
- Duplicate ownership.
- Agreement-version tampering.
- Forged onboarding completion.
- Suspended-member access.

Apply CSRF protection to stateful browser requests, expiring sessions, rate limits on searches/invitations/challenges and step-up authentication for sensitive ownership changes.

Encrypt sensitive verification evidence, restrict uploads, never log secrets, and use secure short-lived signed URLs only where appropriate.

Audit business claims, verification attempts, approvals, agreement acceptance, workspace switches, invitations, role changes and access revocations.

Record every 403 and centrally report 401/403/419/422/429/500 with safe request IDs. AI diagnostics receive redacted errors only; production changes require human approval.

Use database indexes for workspace membership, verification and invitation lookups. Queue emails after transaction commit, cache suitable business-search/configuration data and avoid N+1 queries.

Measure dashboard entry and workspace switching. Target p95 under 300 ms for normal scoped API requests in staging, with actual results documented.

Check official supported Laravel and Google Places capabilities before implementation. Run dependency/security scans and generate API documentation in CI.

## ACCEPTANCE — 10 E2E TESTS

1. **Business search:** Owner searches, selects and reviews Google business data; manual entry also works.
2. **Ownership verification:** Valid domain/DNS challenge succeeds; expired or replayed challenge fails.
3. **Manual approval:** Unverifiable owner remains Pending Review; only authorized Super Admin can approve or reject.
4. **Agreement:** Correct published agreement is accepted and versioned; unchecked, forged or outdated acceptance cannot activate a webshop.
5. **Onboarding recovery:** Interrupted setup resumes correctly; URL/API tampering cannot skip required steps.
6. **Multiple workspaces:** One user owns two webshops, switches between them and sees only the selected webshop's data.
7. **Employee invitation:** Existing/new users accept a secure invitation; expired, reused and mismatched tokens fail.
8. **Permission boundaries:** Manager/employee cannot escalate roles, transfer ownership or access another webshop; denied actions are audited.
9. **Revocation and suspension:** Removing membership or suspending a webshop immediately blocks APIs and realtime access; final owner cannot be removed.
10. **Complete journey:** Verified owner completes onboarding, reaches the responsive merchant dashboard, and staging demonstrates valid audit logs, API documentation, dependency checks and measured response times.

## DEFINITION OF DONE

A webshop owner can register, verify business ownership, accept the correct agreement, activate an approved workspace, invite employees and switch between multiple authorized webshops.

Every role and workspace restriction is enforced by the backend. Onboarding, permissions, audit logging and the merchant dashboard work against real APIs.

All 10 E2E tests pass with recorded evidence.

---

## Trello #17: Webshop Dashboard, Offers & Voucher Management.

List: **Frank**. Card: https://trello.com/c/PBDyFIlm/17-webshop-dashboard-offers-voucher-management. Archived: False.

# ShopBonus — Webshop Dashboard, Offers & Voucher Management

## OBJECTIVE

Build the complete merchant dashboard and voucher management system.

Webshop owners must be able to view performance, create discount offers, upload voucher codes, manage availability and monitor issued/redeemed vouchers.

One webshop may publish multiple offers simultaneously. Each offer has its own discount rules, eligibility, code inventory and lifecycle.

Consumers use their central ShopBonus points wallet to obtain eligible vouchers. Webshops fund the discounts; no points-to-cash withdrawal or cash-equivalent payout is permitted.

Use the existing ShopBonus blue design, top navigation and responsive layout. Every button, filter, status and counter must work against real APIs.

## DASHBOARD

Show a clean overview with:

- Webshop name, logo and account status.
- Active, scheduled and expired offers.
- Available and reserved voucher codes.
- Vouchers issued, redeemed and expired.
- Recent voucher activity.
- Offer performance and conversion indicators where source data exists.
- Order integration/connection health.
- Notifications requiring merchant action.

**Quick actions**

Create Offer, Manage Offers, Upload Codes, View Vouchers, View Orders, Connect Webshop and Settings.

Provide relevant date filters and comparison periods.

All counters must be calculated from real records. No invented revenue, conversion or order statistics.

New webshops display informative empty states and guided first actions.

Employees see only the dashboard functions allowed by their membership.

## OFFERS OVERVIEW

Display a searchable table/cards with:

- Offer title and webshop.
- Discount type and value.
- Consumer points price.
- Minimum order amount where applicable.
- Validity and scheduled dates.
- Available code inventory.
- Issued/redeemed counts.
- Status and actions.

Statuses:

Draft, Scheduled, Active, Paused, Sold Out, Expired and Archived.

Actions depend on role and status: Create, Edit, Duplicate, Preview, Publish, Pause, Resume, Archive and View Performance.

Use safe pagination, sorting and filters by status/date/type.

Status changes must be validated by the backend. A paused, expired or sold-out offer cannot be purchased.

## CREATE / EDIT OFFER

Provide a structured, responsive form.

**General**

- Offer title.
- Short and detailed description.
- Webshop selection restricted to authorized memberships.
- Optional image/logo.
- Offer category and tags.
- Consumer-facing terms and restrictions.

**Discount configuration**

Support:

- Percentage discount.
- Fixed euro discount.
- Free shipping where supported.
- Other explicitly configured discount types.

Fields:

- Discount value.
- Minimum order amount.
- Maximum discount where relevant.
- Points required to obtain the voucher.
- Validity period.
- Total redemption/issuance limit.
- Optional per-consumer limit.
- Combinability and usage restrictions.
- Start/end dates and timezone.

Preview the offer as a consumer would see it.

The frontend must not determine the final points price, discount or redemption eligibility. Validate and calculate these on the server.

Prevent negative values, impossible date ranges, excessive discounts and conflicting rules.

An offer cannot be activated until its required fields and inventory configuration are valid.

**Important:** One webshop may operate multiple independent offers with different code pools and rules.

## VOUCHER CODE MANAGEMENT

Support two code models.

**Unique codes**

Each code may be issued only once. Code allocation must occur atomically at the moment a consumer successfully purchases/redeems the offer using points.

Do not reserve codes merely because a consumer views an offer, opens checkout or adds it to a temporary selection.

**Reusable codes**

One shared code can be issued multiple times, subject to configured issuance and usage limits. Clearly distinguish platform issuance limits from actual webshop redemption limits.

Merchants can:

- Add a single code.
- Bulk-import codes using CSV/XLSX-compatible upload.
- View masked code inventory.
- Download an import template.
- Inspect validation errors before confirming import.
- Add inventory to an existing offer.
- Disable compromised/unissued codes.
- Export permitted inventory reports.

Import validation:

- Trim/normalize input safely while preserving case-sensitive codes where required.
- Reject blank, malformed and duplicate unique codes.
- Detect existing codes across applicable offer inventory.
- Show accepted/rejected row counts and reasons.
- Process large imports asynchronously.
- Never expose full unissued code lists to unauthorized employees.

A published offer must not display available stock that exceeds its genuinely allocatable inventory.

## VOUCHER LIFECYCLE

Track independently:

- Offer publication status.
- Code inventory status.
- Voucher issuance status.
- Consumer redemption/usage status.

Suggested voucher statuses:

Available, Issued, Redeemed, Expired, Cancelled and Disputed.

Preserve issuance timestamp, relevant expiration dates, consumer ownership and source transaction.

Support the agreed 90-day validity/claim windows where applicable, with explicit server-side timestamps and configurable rules. Never silently reset an expired period.

**Key rules**

- Deduct points and allocate a unique code in one atomic transaction.
- Prevent double allocation during simultaneous purchases.
- Never display a unique code to a consumer before successful issuance.
- Once issued, a voucher remains in the consumer's account subject to its recorded terms, even if the merchant later pauses the offer.
- Pausing/archiving an offer stops new issuance but does not erase legitimate historical vouchers.
- Rejected affiliate commissions do not automatically reverse already redeemed points.
- Any compensation, cancellation or dispute adjustment must follow recorded rules and an auditable ledger process.

Do not edit historical voucher terms in place after issuance. Store a snapshot/version of the issued conditions.

## MERCHANT VOUCHER OVERVIEW

Provide searchable and filterable voucher records showing:

- Offer.
- Voucher reference.
- Masked code.
- Issue date.
- Valid-until date.
- Current status.
- Redemption timestamp if available.
- Associated order/reference where available.

Avoid exposing unnecessary consumer personal information.

Merchant actions:

- View voucher details.
- Check validity.
- Mark redeemed only through an authorized, verified flow.
- Report a disputed voucher.
- Download permitted reports.

Never allow a merchant to arbitrarily credit/debit a consumer wallet or change the owner of a voucher.

Support a controlled dispute flow with reason, evidence, current status and review history.

## DATA MODEL

Create/reuse:

**webshop_offers**

Webshop/tenant, title, descriptions, discount type/value, points price, restrictions, start/end dates, quotas, status and version.

**voucher_code_pools**

Offer, code type, encrypted/secure code representation, unique lookup fingerprint, usage limit, allocation status and import reference.

**consumer_vouchers**

Consumer, webshop, offer, allocated code reference, issue/expiry dates, status, purchase/ledger reference and immutable offer-terms snapshot.

**voucher_imports**

File metadata, actor, counts, validation summary, processing status and timestamps.

**voucher_redemptions**

Voucher, verified redemption source, order/reference where available, actor and timestamp.

**voucher_disputes**

Voucher, initiator, reason, evidence reference, status, decision and audit timestamps.

Use indexed foreign keys, uniqueness constraints and transactional code allocation. Never duplicate the central wallet or points ledger.

Historical issued vouchers must remain traceable even when offers are archived.

## API & PERMISSIONS

Use documented typed `/api/v1/` endpoints for:

- Merchant dashboard summary.
- List/create/update/preview/publish/pause/archive offers.
- Code inventory summary and secure code import.
- Import validation/progress/results.
- Voucher listing, details and authorized redemption verification.
- Dispute submission and review.
- Offer/voucher performance summaries.

Suggested permissions:

- `webshop.dashboard.read`
- `webshop.offers.read/create/update/publish`
- `webshop.codes.read/import/manage`
- `webshop.vouchers.read/verify`
- `webshop.vouchers.dispute`
- `webshop.reports.export`

Enforce exact tenant, webshop, role and resource scope on every endpoint. UI visibility is not authorization.

Use clear 401/403/419/422/429/500 responses with request IDs.

Automatically maintain the API handbook from actual routes/contracts and permission tests.

## SECURITY & AUDIT

Use Laravel Policies/Gates, Form Requests, safe file validation, CSRF/session protection and rate limiting.

Security requirements:

- No cross-webshop offers, codes or voucher visibility.
- Never trust frontend-supplied prices, points or inventory.
- Atomic issuance prevents race-condition double spending.
- Mask/encrypt voucher codes at rest where appropriate.
- Use server-generated identifiers and controlled status transitions.
- Validate uploads and keep them outside public executable paths.
- Protect private download/export endpoints.
- Require fresh authentication for sensitive bulk/code-management actions where justified.
- Keep direct wallet adjustment restricted to separately authorized Super Admin workflows.

Audit offer creation/changes, publication, code imports, stock changes, issuance, redemption, disputes and permission denials.

Every 403 must be logged. Centralize API/security errors, redact sensitive details and group repeated alerts.

AI diagnostics may inspect sanitized technical issues but cannot autonomously change vouchers, balances or production records.

## PERFORMANCE & RELIABILITY

- Index webshop, offer status, validity dates and voucher ownership.
- Paginate inventory, issuance and reporting tables.
- Process large imports and exports using Laravel queues.
- Use Redis for applicable short-lived caching/rate limiting.
- Cache public published-offer summaries with correct invalidation.
- Never cache private codes or individual wallet balances across users.
- Use database locking/unique constraints during allocation.
- Queue notifications after successful transaction commit.
- Monitor inventory mismatches, failed imports, slow APIs and allocation errors.

Target typical dashboard/list API p95 under 300 ms in staging. Measure actual timings.

Use supported official Laravel features, update dependencies, run security scans and verify API documentation in CI.

## ACCEPTANCE — 10 E2E TESTS

1. **Dashboard:** Approved merchant sees real, scoped counters and working quick actions; new webshop displays correct empty states.
2. **Offer creation:** Merchant creates a percentage and fixed-discount offer with valid points price, restrictions and dates.
3. **Validation:** Invalid discounts, negative amounts, date conflicts and unauthorized webshop IDs are rejected.
4. **Offer lifecycle:** Draft, Scheduled, Active, Paused, Sold Out, Expired and Archived transitions behave correctly.
5. **Code import:** CSV import validates duplicates/malformed codes, reports rejected rows and correctly updates available inventory.
6. **Atomic issuance:** Two simultaneous consumers competing for one unique code cannot receive the same code or lose points incorrectly.
7. **Reusable codes:** Usage limits and availability behave correctly without treating the shared code as unique stock.
8. **Voucher validity:** Issued vouchers retain their recorded terms, expiry and ownership after an offer is edited or paused.
9. **Permissions and disputes:** Employee restrictions and cross-webshop access are enforced; redemption/dispute actions are audited and every 403 is logged.
10. **Production readiness:** Responsive UI, live inventory changes, queued imports, reporting, safe errors, dependency scans, API documentation and measured performance pass in staging.

## DEFINITION OF DONE

An approved webshop can manage offers, upload codes, publish discounts, track issued vouchers and handle authorized redemptions/disputes.

Consumers can obtain vouchers through the existing central points wallet without duplicate allocations, incorrect deductions or cross-webshop data exposure.

All 10 E2E tests pass against real APIs, with audit, security and performance evidence.

---

## Trello #18: Consumer Platform, Webshop Discovery & Activation

List: **Frank**. Card: https://trello.com/c/3TxvIq5j/18-consumer-platform-webshop-discovery-activation. Archived: False.

# ShopBonus — Consumer Platform, Webshop Discovery & Activation

## OBJECTIVE

Build the complete consumer-facing ShopBonus experience: public homepage, webshop discovery, offer details, secure customer activation, consumer profile and personal dashboard.

Consumers discover participating webshops, earn virtual points through eligible activities and use those points for webshop-funded discount vouchers.

The platform starts Netherlands-first, supports NL/EN and uses ShopBonus's existing modern blue visual style.

No cash withdrawals, cash-equivalent payouts or automatically granted vouchers from unverified orders.

Use real published webshop/offer data. No dummy statistics or inactive buttons in production.

## PUBLIC WEBSITE & NAVIGATION

Responsive top navigation:

- ShopBonus logo → homepage.
- Webshops.
- How it works.
- For webshops.
- Log in / Create account.
- Language switcher.
- Logged-in account menu.

Do not introduce a dashboard sidebar on public pages.

Homepage sections:

- Clear introduction: discover webshops, collect points, obtain discounts.
- Search bar for participating webshops.
- Featured webshops and available offers.
- Simple explanation of how points and vouchers work.
- Benefits for consumers and merchants.
- Registration and webshop onboarding calls to action.
- FAQ and footer with legal pages.

All visible content must use published CMS/i18n content where configured.

Cookie preferences and consent must be respected; optional analytics and tracking cannot run before the applicable consent.

## WEBSHOP DISCOVERY

Create a responsive searchable webshop directory.

Display cards with:

- Verified webshop name and logo.
- Category.
- Short description.
- Relevant active offer.
- Available discount and required points where applicable.
- Link to webshop details.

Functions:

- Search by name/keyword.
- Filter by category and available offers.
- Sort by relevance or other supported criteria.
- Pagination or controlled incremental loading.
- Empty states and clear-filter action.

Only public, approved and active webshops appear.

Suspended, rejected, unpublished or deleted workspaces must never appear in discovery results.

No internal tenant IDs in public URLs. Use stable readable slugs.

## WEBSHOP DETAIL PAGE

Each participating webshop receives a public profile containing:

- Logo, name and description.
- Website link.
- Relevant categories.
- Active, published offers.
- Discount conditions.
- Points required.
- Offer availability and expiry where applicable.
- Clear actions: View Offer, Visit Webshop and Create Account.

Show only merchant-approved public information.

Do not expose ownership evidence, merchant agreements, order data, employee details or private integration settings.

If a webshop becomes unavailable, return an appropriate unavailable/not-found state without exposing internal data.

## OFFER DETAIL PAGE

Show:

- Discount title and description.
- Webshop identity.
- Discount type/value.
- Required points.
- Minimum order amount.
- Validity and usage restrictions.
- Current availability.
- Voucher conditions.
- Primary action to obtain the offer.

For anonymous visitors, the action leads to registration/login and returns to the intended offer after successful authentication.

For authenticated users, check account verification and current eligibility server-side.

Never allocate voucher codes or deduct points merely because someone views an offer.

A paused, expired, sold-out or unpublished offer cannot be purchased, even if its public URL is still known.

## CONSUMER REGISTRATION & ACTIVATION

Use the central user identity system.

A visitor may register directly, or arrive from an authorized ShopBonus invitation following eligible webshop activity.

**Direct registration**

- Register/login.
- Verify email.
- Enter consumer dashboard.
- Preserve a safe intended destination where relevant.

**Personal activation invitation**

An authorized ShopBonus invitation may contain a secure single-use token associated with a specific eligible event and webshop.

Flow:

1. Consumer opens invitation.
2. Show the ShopBonus explanation and originating webshop.
3. Consumer logs in or creates an account.
4. Verify ownership of the intended email/account.
5. Validate invitation token, expiry and eligibility.
6. Associate the permitted event/benefit with the verified consumer.
7. Show clear confirmation and dashboard entry.

Requirements:

- Short-lived, random, single-use tokens stored hashed.
- Token bound to the intended recipient and underlying event.
- No account creation or voucher issuance merely on link opening.
- No customer data or internal identifiers exposed through public URLs.
- Expired, replayed, revoked or mismatched tokens cannot activate.
- Repeated activation attempts cannot duplicate rewards.
- Invalidated/cancelled underlying eligibility prevents new benefits.
- Existing verified users must not receive duplicate identities.

The existence of an imported webshop order does not automatically authorize marketing communication.

## CONSUMER DASHBOARD

After verified login, show a clean personal overview:

- Welcome message and consumer name.
- Current verified points balance.
- Recent points activity.
- Available/owned vouchers summary.
- Recently visited or relevant published offers where available.
- Notifications and account actions.

Navigation:

Dashboard, Discover Webshops, My Points, My Vouchers, Profile and Settings.

Use central wallet/voucher records for summaries. Do not implement a second balance or independent voucher database.

Dashboard totals must distinguish available points from pending activity where applicable.

Do not present unconfirmed affiliate transactions as spendable points.

Provide meaningful empty states for consumers without points or vouchers.

## PROFILE & SETTINGS

Allow consumers to view/update:

- First name and basic profile details.
- Email with re-verification on change.
- Preferred language.
- Communication preferences.
- Password/security settings.
- Privacy/data-rights options.

Record consent choices individually with timestamp, purpose and version where relevant.

Unsubscribing from optional marketing must not disable necessary transactional/security communication.

Account deletion requests must follow controlled retention and ledger-integrity requirements. Do not immediately erase transaction evidence needed for legitimate accounting/fraud obligations.

Make profile settings accessible on mobile and desktop.

## API & DATA MODEL

Use existing central users, authentication, wallet and voucher models.

Create/reuse appropriate public read models for:

- Published webshop profiles.
- Public offer summaries.
- Categories.
- Consumer preferences.
- Invitation/activation tokens.
- Activation event links.
- Consent records.

Suggested API routes:

- `GET /api/v1/public/webshops`
- `GET /api/v1/public/webshops/{slug}`
- `GET /api/v1/public/offers`
- `GET /api/v1/public/offers/{slug}`
- `GET /api/v1/consumer/dashboard`
- `GET /api/v1/consumer/profile`
- `PATCH /api/v1/consumer/profile`
- `POST /api/v1/consumer/activation/verify`
- `POST /api/v1/consumer/activation/complete`
- `PATCH /api/v1/consumer/preferences`

All responses must use typed contracts and consistent safe errors.

Public APIs return only explicitly published fields. Authenticated APIs derive consumer identity from the session, never client-provided user IDs.

## SECURITY, PRIVACY & AUDIT

- Laravel Policies/Gates and server-side access checks.
- Require verified, active accounts for protected consumer actions.
- CSRF protection, safe sessions, rate limiting and validated inputs.
- Protect invitation tokens against guessing, replay and cross-account use.
- Use atomic activation/association to prevent duplicate benefits.
- Strict separation of consumer and merchant/private data.
- Revalidate offer publication/availability on every protected action.
- Protect against open redirects after login and activation.
- Sanitize CMS-derived public content and defend against XSS.
- Record consent changes, activation, profile/security changes and denied access.

Every endpoint must document actor, permission, scope, validation, audit and error behavior.

Centralize 401/403/419/422/429/500 with request IDs. Log every 403 without exposing PII or secrets.

Apply privacy-friendly analytics and data minimization. AI diagnostics receive redacted technical information only.

Generate API documentation and run dependency/security scans in CI.

## PERFORMANCE & ACCESSIBILITY

- Cache published webshop/offer lists with correct invalidation.
- Index publication status, category, slug and search fields.
- Paginate discovery results; avoid expensive unbounded queries.
- Optimize logos/images and load below-the-fold images lazily.
- Keep personal dashboard responses small and scoped.
- Use queued notifications for activation/verification communication.
- Provide accessible labels, keyboard navigation, focus states and contrast.
- Responsive layouts for phone, tablet and desktop.

Measure public directory and dashboard API latency. Target p95 under 300 ms for typical staging requests and report measured results.

Check currently supported official Laravel authentication, authorization, signed URL and caching capabilities before implementation.

## ACCEPTANCE — 10 E2E TESTS

1. **Homepage:** Public navigation, CTAs, published CMS content, language switching and cookie choices work on mobile/desktop.
2. **Discovery:** Search, filters, sorting and pagination return only approved, active webshops and published offers.
3. **Webshop details:** Correct public profile and offers display; private merchant data is never exposed.
4. **Offer detail:** Valid offers show correct conditions/points; paused, expired and sold-out offers cannot be obtained.
5. **Direct registration:** Anonymous visitor registers, verifies email and returns to the intended public offer.
6. **Activation:** Valid invitation links the correct eligible event to a verified consumer exactly once.
7. **Token protection:** Expired, replayed, revoked, mismatched and forged invitations fail without leaking customer information.
8. **Dashboard:** Consumer sees their own verified wallet/voucher summaries; pending points are not spendable.
9. **Profile/security:** Email re-verification, language, consent preferences and account restrictions are enforced; cross-user requests return audited denials.
10. **Operational quality:** Responsive/accessibility checks, public cache invalidation, API documentation, 401/403/422/429/500 reporting, dependency scans and measured staging performance pass.

## DEFINITION OF DONE

Consumers can discover real participating webshops and offers, register or securely activate an eligible invitation, manage their profile and access a personal dashboard.

All data comes from authorized ShopBonus sources. Wallet balances and vouchers remain centralized, private and consistent.

All 10 E2E tests pass against the real API.

---

## Trello #19: Affiliate Tracking & Central Points Wallet

List: **Frank**. Card: https://trello.com/c/QBffQWek/19-affiliate-tracking-central-points-wallet. Archived: False.

# ShopBonus — Affiliate Tracking & Central Points Wallet

## OBJECTIVE

Build ShopBonus's centralized affiliate tracking, commission reconciliation and virtual points wallet.

Consumers visit participating webshops through tracked links, generate eligible affiliate activity and receive ShopBonus points according to configured reward rules.

Integrate Daisycon and TradeTracker through authorized APIs, select the most advantageous eligible commission route where reliable comparable data exists, and maintain one secure points ledger.

**Core rules**

- Points are virtual loyalty units, not money.
- No cash withdrawals, transfers to bank accounts or cash-equivalent payouts.
- One consumer wallet and one append-only transaction ledger.
- Pending commissions do not automatically become spendable points.
- Rejected Daisycon/TradeTracker commissions do not automatically reverse points already redeemed for vouchers.
- Never reward the same underlying transaction twice.
- Merchant-funded vouchers use the same central wallet balance.

## AFFILIATE NETWORK CONNECTIONS

Implement reusable network adapters for Daisycon and TradeTracker.

Support:

- Secure API credentials.
- Authorized merchant/program discovery.
- Available commission information.
- Affiliate tracking-link generation.
- Transaction/conversion imports.
- Commission status synchronization.
- Reconciliation and error handling.

Create a common `AffiliateNetworkAdapter` contract, with network-specific API mappings.

Store network credentials encrypted and accessible only to authorized platform services.

Do not assume both networks expose identical rates, approval statuses, customer identifiers or transaction data.

Verify each provider's current supported API methods and required permissions before enabling production synchronization.

Network statuses: Connected, Limited, Reauthentication Required, Error and Disabled.

## NETWORK SELECTION

When a webshop is available through multiple authorized networks:

1. Identify matching merchant programs.
2. Check that each program is active and permits the intended traffic.
3. Compare available commission rates using a normalized calculation.
4. Consider percentage, fixed-fee, category and applicable restrictions.
5. Select the highest expected eligible commission when the comparison is reliable.
6. Generate and store the selected network's compliant tracking link.
7. Record the decision and commission rule version.

Example:

- Daisycon: 6% eligible commission.
- TradeTracker: 8% eligible commission.
- ShopBonus selects TradeTracker if both programs are applicable and terms permit.

Never compare an unrestricted headline rate against a restricted category rate as though they were equivalent.

If rates or eligibility are uncertain, use a configured approved fallback and record the limitation.

Do not modify network tracking URLs in a way that breaks attribution or violates provider requirements.

## TRACKED CONSUMER JOURNEY

From an eligible ShopBonus webshop/offer page:

1. Consumer clicks **Visit Webshop**.
2. System records the authorized click and selected program.
3. Generate a short-lived internal click reference.
4. Redirect through the selected affiliate network.
5. Network records a qualifying conversion where supported.
6. ShopBonus imports the conversion and matches attribution evidence.
7. Points are credited only according to configured eligibility rules.

A click alone does not create reward points.

Authenticated consumers may receive personalized attribution. Anonymous visitors can only receive subsequent attribution when permitted, technically supportable and linked through an explicit verified flow.

Respect consent choices and applicable privacy rules. Never bypass blocked tracking or invent attribution.

Provide clear consumer messaging that rewards depend on eligible, confirmed purchases.

## COMMISSION RECONCILIATION

Normalize affiliate transactions into:

- Network and merchant/program reference.
- External conversion/transaction ID.
- Related click/attribution reference.
- Webshop and consumer reference where verified.
- Eligible order amount and currency.
- Expected and actual commission.
- Transaction date.
- Provider status and last synchronization.
- Reward calculation and processing state.

Statuses:

Tracked, Pending, Approved, Rejected, Reversed, Unmatched and Disputed.

Import new data incrementally and handle network updates idempotently.

Ensure one underlying order/conversion cannot generate multiple rewards if both networks report it.

Matching must use credible transaction/attribution evidence; do not merge different customer orders solely because amounts or dates match.

Unknown or conflicting transactions enter review, not automatic payout.

Preserve original network references and relevant status history.

## POINTS EARNING RULES

Super Admin configures approved points rules, including:

- Points per eligible commission amount.
- Campaign-specific multiplier where permitted.
- Minimum qualifying commission/transaction.
- Effective start/end dates.
- Rounding behavior.
- Expiry policy, if enabled.
- Fraud/abuse review thresholds.

Use integer points and exact monetary arithmetic.

Never calculate final rewards using untrusted frontend values.

**Pending commission**

Display expected points separately as Pending. These are not spendable.

**Approved commission**

After verified approval and configured eligibility checks, write one credit to the ledger and increase available points.

**Rejected before credit**

Mark the reward rejected. No spendable points are created.

**Reversed after credit**

Apply the configured risk/reversal policy with a linked auditable adjustment. Never silently delete historical credits.

If points were already redeemed, do not automatically reverse that completed redemption or create an unapproved negative wallet balance. Flag the event for controlled reconciliation.

## CENTRAL WALLET

Every consumer has one central ShopBonus points wallet.

Show:

- Available points.
- Pending points.
- Reserved points where checkout reservation is supported.
- Total earned.
- Total spent.
- Expired/adjusted points where applicable.
- Chronological transaction history.

Ledger event types:

Credit, Debit, Reserve, Release, Expire, Adjustment and Reversal.

Each entry stores consumer, amount, event type, source reference, reason, timestamp, actor/system origin and idempotency key.

**Balance integrity**

- Ledger is authoritative.
- Never permit arbitrary frontend balance updates.
- Never delete or overwrite posted ledger entries.
- Corrections require compensating entries.
- Prevent duplicate credits/debits.
- Prevent negative spendable balances.
- Voucher purchases debit points atomically with successful voucher issuance.
- Failed purchases release any reservation without losing points.

A consumer cannot transfer points to another user or request a cash payout.

## CONSUMER SCREENS

**My Points**

Display available balance prominently, with pending points separated.

Transaction table:

- Date.
- Webshop/source.
- Activity.
- Points earned/spent.
- Current status.
- Optional expiry.
- Details action.

Filters: All, Earned, Spent, Pending and Adjustments.

**Affiliate activity**

Show tracked eligible purchases where the consumer can legitimately be associated with the transaction.

Explain Pending, Approved and Rejected using clear language.

Never expose private network commission data or other consumers' transactions.

**Wallet details**

Show transaction references, status progression and an explanation of adjustments.

Use existing ShopBonus top navigation, mobile-responsive tables/cards and NL/EN content.

## SUPER ADMIN CONTROLS

Provide restricted administration for:

- Network connection health.
- Merchant-program matching.
- Commission rules and reward configuration.
- Unmatched or disputed conversions.
- Pending/approved/rejected totals.
- Reconciliation failures.
- Fraud indicators.
- Wallet adjustment requests.
- Network comparison decisions.

Sensitive wallet adjustments require fresh authentication, password plus email OTP, explicit reason and full audit evidence.

Super Admin must not directly edit stored balances or delete historical ledger transactions.

Use separate review and approval where configured for high-risk adjustments.

## DATABASE & API

Create/reuse:

- `affiliate_network_connections`
- `affiliate_merchant_programs`
- `affiliate_clicks`
- `affiliate_transactions`
- `affiliate_reconciliation_events`
- `points_wallets`
- `points_ledger`
- `points_reward_rules`
- `wallet_adjustment_requests`

Enforce unique network transaction identifiers and ledger idempotency keys.

Use indexed consumer, webshop, source, status and timestamp fields.

Provide typed `/api/v1/` endpoints for:

- Webshop affiliate-link resolution/redirect.
- Consumer points summary and paginated history.
- Consumer eligible affiliate activity.
- Network status synchronization/webhooks.
- Super Admin network/program management.
- Reward rules and reconciliation review.
- Controlled wallet adjustments.

Authenticate provider callbacks/signatures where supported. Internal synchronization uses scoped service credentials.

## SECURITY, AUDIT & PERFORMANCE

- Laravel Policies/Gates, tenant scoping and verified consumer ownership.
- CSRF/session protection for browser actions.
- Validate all provider data and block forged conversion callbacks.
- Encrypt API credentials and support rotation/revocation.
- Rate-limit public redirects and protected mutations.
- Use database transactions, uniqueness constraints and atomic balance changes.
- Keep tracking references free of unnecessary personal information.
- Honor cookie consent and provider attribution requirements.
- Log affiliate decisions, imports, credits, debits, failures and permission denials.
- Centralize 401/403/419/422/429/500 with request IDs; log every 403.
- Never log full tokens, customer PII or sensitive provider payloads.
- AI diagnostics receive redacted errors only.

Use queues for commission imports/reconciliation, after-commit events, Redis where beneficial, indexed queries and paginated history.

Monitor network API failures, reconciliation lag, duplicate attempts, ledger inconsistencies and unusual reward patterns.

Measure wallet reads and affiliate processing. Target typical wallet API p95 under 250 ms in staging; report actual figures.

Check current official Laravel/provider APIs before coding. Run dependency/security scans and maintain generated API documentation.

## ACCEPTANCE — 10 E2E TESTS

1. **Network setup:** Daisycon and TradeTracker connect securely; invalid credentials show actionable errors.
2. **Rate selection:** System selects the highest eligible comparable commission route and records the decision; restricted rates are not miscompared.
3. **Tracking:** Consumer click generates a compliant redirect and attribution reference without awarding immediate points.
4. **Import:** Real provider conversions normalize correctly; unmatched or inconsistent records enter review.
5. **Deduplication:** Duplicate callbacks, retries and cross-network reports cannot produce duplicate rewards.
6. **Approval:** Pending commission shows non-spendable points; approved commission credits the central wallet exactly once.
7. **Rejection/reversal:** Rejected transactions do not credit points; reversals are audited and never silently reclaim already redeemed vouchers.
8. **Wallet integrity:** Concurrent credits, debits and failed voucher purchases preserve exact balances, ledger history and non-negative spendable points.
9. **Security:** Consumer isolation, forged callbacks, unauthorized wallet edits and failed step-up authentication are blocked and audited.
10. **Operations:** Responsive wallet, filters, reconciliation jobs, admin monitoring, API docs, security scans and measured staging performance pass.

## DEFINITION OF DONE

Daisycon and TradeTracker integrations support verified tracking and commission reconciliation. ShopBonus selects eligible affiliate routes, credits points only under approved rules and maintains one secure, auditable consumer wallet.

No duplicate rewards, unauthorized cash payouts or silent reversal of redeemed points are possible.

All 10 E2E tests pass against actual integrations and APIs.

---

## Trello #20: Voucher Checkout, Mollie & My Vouchers

List: **Frank**. Card: https://trello.com/c/KPfe6qGp/20-voucher-checkout-mollie-my-vouchers. Archived: False.

# ShopBonus — Voucher Checkout, Mollie & My Vouchers

## OBJECTIVE

Build the complete voucher checkout, issuance, redemption and consumer voucher management experience.

Consumers use their central ShopBonus points wallet to obtain webshop-funded discount vouchers. Where a separately configured offer requires an additional monetary payment, process it securely through Mollie.

A successful checkout must allocate the correct voucher, deduct the correct points and make the code available in My Vouchers.

**Core rules**

- No cash withdrawals or cash-equivalent payouts.
- One central wallet and points ledger.
- One webshop may offer multiple vouchers.
- Unique codes are allocated only when purchase succeeds.
- No double spending, duplicate issuance or negative points balance.
- Failed payments must not issue vouchers.
- Historical issued vouchers retain their agreed terms.
- Rejected affiliate commissions do not automatically reverse already redeemed points.

## VOUCHER CHECKOUT SCREEN

Use the existing ShopBonus blue style, top navigation and responsive design.

Display:

- Webshop name/logo.
- Selected offer and discount.
- Minimum spend and restrictions.
- Voucher validity.
- Points required.
- Current available points.
- Any separately configured euro amount.
- Final order summary.
- Applicable terms.
- **Get Voucher** / **Confirm Purchase** button.

If points are insufficient, clearly explain the shortfall and disable checkout.

If the offer is paused, expired, unavailable or sold out, prevent purchase with an actionable message.

For unauthenticated users, require login/registration and verified email before continuing.

Never expose unissued voucher codes in checkout responses.

## CHECKOUT PROCESS

**Points-only purchase**

1. Load the current offer and validate eligibility.
2. Recalculate points price server-side.
3. Validate consumer, balance, quotas and stock.
4. Start an atomic database transaction.
5. Lock the relevant wallet/inventory records.
6. Deduct points through the central ledger.
7. Allocate one available unique code or permitted reusable code.
8. Create the consumer voucher with immutable terms.
9. Commit and show success.

If any operation fails, roll back the complete purchase.

**Important:** Viewing an offer, loading checkout or pressing Back must not consume inventory or deduct points.

**Concurrent purchases**

Use row-level locking, uniqueness constraints and idempotency keys. Two consumers cannot receive the same unique code.

A repeated click, retry or duplicated request must return the existing successful result, not issue another voucher.

## MOLLIE PAYMENTS

Use Mollie only for offers or payment types explicitly configured to require money. Normal points-only purchases must not invoke Mollie.

When required:

1. Create an internal pending checkout.
2. Validate points eligibility and order amount.
3. Create the payment through Mollie's server-side API.
4. Redirect the consumer to Mollie's hosted payment flow.
5. Receive webhook notification.
6. Retrieve and verify the actual payment status from Mollie.
7. Finalize the voucher exactly once after confirmed success.
8. Display the resulting voucher.

A frontend success redirect is never proof of payment.

Handle paid, pending, failed, cancelled and expired payment states.

Do not permanently deduct points or allocate a unique code before confirmed payment. Use bounded, expiring reservations only where needed for checkout consistency; release them on failure/expiry.

If payment succeeds but inventory becomes unavailable, mark the checkout for controlled compensation/refund and alert administrators. Never charge a customer without recording a recoverable resolution.

Store Mollie payment references, not card numbers or payment credentials.

## VOUCHER ISSUANCE

Support:

**Unique code**

One code is assigned to one successful consumer voucher issuance.

**Reusable code**

One code can be issued repeatedly within configured limits.

For every issued voucher, store:

- Consumer, webshop and offer.
- Unique voucher reference.
- Allocated code reference.
- Points transaction and optional Mollie payment.
- Issued timestamp.
- Valid-from and expiry.
- Discount rules and restrictions.
- Immutable offer terms/version.
- Issuance status.

Voucher code must be revealed only to its verified owner or an authorized redemption process.

Send transactional confirmation asynchronously without exposing unnecessarily sensitive information.

Use configured validity rules, including the agreed 90-day windows where applicable. Calculate actual expiry timestamps server-side and display them clearly.

## MY VOUCHERS PAGE

Consumer navigation: **Dashboard → My Vouchers**.

Display voucher cards/table with:

- Webshop logo/name.
- Discount title/value.
- Voucher reference.
- Issue date and expiry.
- Status.
- View Code / View Details action.
- Visit Webshop action.

Filters: Active, Used, Expired, Cancelled and All.

Voucher details:

- Full code, revealed after authorized access.
- Copy Code button.
- Redemption instructions.
- Applicable webshop URL.
- Minimum order value.
- Restrictions and expiration.
- Transaction/reference information.

Show warning when a voucher is close to expiry. Never invent a replacement code automatically.

Display valid issued vouchers even if the underlying offer is subsequently paused or archived.

Do not display another consumer's codes through URLs, API manipulation or frontend caches.

## REDEMPTION & VALIDATION

Distinguish:

- **Issued:** Code assigned to consumer.
- **Redeemed:** Verified use at the webshop.
- **Expired:** Validity ended without confirmed use.
- **Cancelled:** Valid authorized cancellation.
- **Disputed:** Redemption/validity contested.

Where a webshop integration provides reliable redemption confirmation, update status from authenticated provider evidence.

Otherwise allow an explicitly authorized merchant verification workflow. Do not claim a code was used merely because the consumer clicked Visit Webshop or Copy Code.

Prevent duplicate redemption confirmations. Record source, timestamp, actor and external reference.

An expired code cannot be newly redeemed through ShopBonus validation unless explicitly permitted by an approved exception process.

## CANCELLATIONS, REFUNDS & DISPUTES

Provide consumer actions:

- View transaction.
- Report Voucher Problem.
- Submit a dispute with reason and optional evidence.
- View dispute status and resolution.

Merchant and Super Admin receive appropriately scoped dispute information.

Examples:

- Code invalid at checkout.
- Code already used.
- Webshop refuses valid code.
- Discount differs from advertised terms.
- Paid checkout succeeded but voucher missing.

Dispute statuses: Open, Under Review, Waiting for Merchant, Resolved and Rejected.

Compensation options depend on the documented policy: replacement voucher, points restoration or Mollie refund when legally/contractually appropriate.

Every compensation must use an auditable ledger entry and prevent double compensation.

No automatic points reversal merely because an affiliate commission is rejected after redemption.

Do not allow merchants to directly edit consumer balances or delete historical vouchers.

## DATABASE & API

Create/reuse:

- `voucher_checkouts`: consumer, offer, amount/points, state, idempotency key.
- `voucher_checkout_payments`: checkout, Mollie reference, amount, currency, verified status.
- `consumer_vouchers`: owner, offer, code, immutable terms, issue/expiry and status.
- `voucher_redemptions`: verified use, source and timestamp.
- `voucher_disputes`: parties, reason, evidence, status and resolution.
- Central `points_wallets` and append-only `points_ledger`.

Use existing merchant offers and code inventory; never create separate conflicting voucher pools.

Typed `/api/v1/` endpoints for:

- Checkout preview/eligibility.
- Points-only purchase.
- Optional Mollie payment creation/status.
- Mollie webhook processing.
- Consumer voucher list/details.
- Authorized code reveal.
- Redemption verification.
- Dispute creation/status.
- Admin dispute resolution/refund.

Every route documents roles, permissions, resource scope, validation, audit and error outcomes.

## SECURITY & AUDIT

- Laravel Policies/Gates and verified consumer ownership.
- CSRF protection for browser mutations.
- Strong session/cookie security and rate limiting.
- Server-side points and discount calculations.
- Atomic wallet debit + code allocation.
- Unique idempotency keys and transaction constraints.
- Verify Mollie status server-to-server before fulfillment.
- Strict authorization for code reveal and redemption.
- Encrypt/protect voucher codes at rest.
- Never expose codes, payment secrets or PII in logs.
- Record checkout, debit, issuance, payment, redemption, refund and disputes.
- Centralize 401/403/419/422/429/500 with request IDs; log every 403.
- Sensitive refund/ledger adjustments require fresh Super Admin authentication and an audit reason.
- AI diagnostics receive redacted information only; no autonomous financial changes.

## PERFORMANCE & MONITORING

- Index consumer, offer, status, payment reference and expiry.
- Paginate voucher history and disputes.
- Use database transactions and locks for checkout.
- Queue emails and non-critical notifications after commit.
- Redis for throttling/short-lived coordination where useful.
- Cache public offer information, never private voucher codes.
- Monitor failed checkouts, payment mismatch, exhausted inventory, duplicate attempts and pending refunds.
- Alert administrators on paid-but-unfulfilled purchases.

Target typical voucher-list API p95 under 250 ms in staging. Measure actual performance.

Use supported Laravel transaction/queue features, current Mollie API guidance, dependency scans and generated API documentation.

## ACCEPTANCE — 10 E2E TESTS

1. **Points checkout:** Verified consumer with sufficient points purchases a voucher; wallet debit, ledger and unique code match.
2. **Insufficient funds:** Low balance, inactive offer and exhausted stock block purchase without mutation.
3. **Concurrency:** Two consumers cannot obtain the same final unique code; retries do not duplicate purchases.
4. **Reusable codes:** Configured reuse limits work correctly without premature stock depletion.
5. **Mollie success:** Verified paid webhook finalizes the correct checkout once; frontend redirect alone cannot issue a voucher.
6. **Mollie failure:** Cancelled/failed/expired payment releases reservations and does not issue a voucher or permanently debit points.
7. **My Vouchers:** Consumer sees only their own vouchers, codes, conditions, expiry and history on desktop/mobile.
8. **Redemption:** Verified merchant/provider redemption updates status once; Copy Code or Visit Webshop does not mark it redeemed.
9. **Disputes/refunds:** Invalid-code dispute follows review; permitted compensation is applied once with a complete audit trail.
10. **Security/operations:** Cross-user access, forged payment notifications, replayed requests and unauthorized adjustments fail; central errors, alerts, scans, API documentation and measured performance pass.

## DEFINITION OF DONE

A consumer can securely purchase an eligible voucher with points, optionally complete a required Mollie payment, receive the correct code and manage vouchers in My Vouchers.

Checkout is atomic, payment verification is authoritative, redemption is evidence-based and disputes are auditable.

All 10 E2E tests pass using real APIs and payment sandbox evidence.

---

## Trello #21: Super Admin Cockpit, Security & Monitoring

List: **Frank**. Card: https://trello.com/c/IsmQwCbL/21-super-admin-cockpit-security-monitoring. Archived: False.

# ShopBonus — Super Admin Cockpit, Security & Monitoring

## OBJECTIVE

Build one centralized Super Admin Cockpit to manage, monitor and secure the complete ShopBonus platform.

Super Admin must see the actual operational state of webshops, consumers, offers, vouchers, affiliate networks, orders, integrations, wallets and Answer24 communication.

Provide secure administration, merchant approval, platform-wide reporting, audit history, API error monitoring and controlled incident resolution.

Use the existing ShopBonus bright-blue dashboard design with top navigation, responsive layout and clear KPI cards.

All data must come from actual APIs and database records. No dummy statistics or nonfunctional controls.

## COCKPIT OVERVIEW

Display KPI cards for:

- Total, active, pending and suspended webshops.
- Registered and active consumers.
- Published offers.
- Issued and redeemed vouchers.
- Available voucher inventory.
- Orders received and confirmed paid.
- Answer24 contact requests/results.
- Affiliate commissions: pending, approved and rejected.
- Points issued, spent and outstanding.
- Open disputes and operational incidents.

Show trend charts with selectable periods: Today, 7 Days, 30 Days and Custom.

Include:

- Recent platform activity.
- Pending merchant approvals.
- Integration failures.
- Security alerts.
- Failed background jobs.
- Affiliate reconciliation problems.
- Financial/ledger anomalies.
- Quick access to affected records.

Counters must use consistent definitions. Never combine pending amounts with confirmed totals.

## WEBSHOP ADMINISTRATION

Provide searchable/filterable webshop management.

Columns:

Webshop name, owner, registration date, verification status, account status, integration status, active offers and last activity.

Actions:

- View merchant profile.
- Review ownership evidence.
- Approve or reject registration.
- Request additional information.
- Suspend/reactivate webshop.
- Review memberships and agreements.
- View integration/order health.
- Open scoped offers, vouchers and activity.

Use a clear approval checklist:

- Business identity.
- Ownership verification.
- Required company information.
- Accepted merchant agreement.
- Account/security status.

Approval does not bypass missing mandatory verification.

Rejected/suspended workspaces must lose prohibited API access immediately. Preserve historical transactions and audit evidence.

Record reason, actor, timestamp and status transition.

## USERS, MEMBERSHIPS & ACCESS

Manage:

- Consumer accounts.
- Webshop owners and employees.
- Workspace memberships.
- Platform administrators.
- Suspended/restricted accounts.

Search/filter by role, status, webshop and registration date.

Actions:

View, Suspend, Reactivate, Review Access, Revoke Membership and Reset Security Access where permitted.

Do not directly alter user passwords or expose password hashes.

Super Admin provisioning must be internal only. Public signup cannot create platform administrators.

Prevent accidental removal of the last authorized platform administrator.

**Impersonation**

Allow only explicitly authorized Super Admin users to start a time-limited support session.

Require fresh password verification plus email OTP before impersonation.

Display a permanent **Impersonation Active** indicator with target identity and End Session action.

Every impersonated action must record both the real admin and represented user.

Block sensitive financial, ownership-transfer and security actions during impersonation unless a separately approved workflow explicitly permits them.

## PLATFORM CONTENT & SETTINGS

Provide authorized access to centralized configuration for:

- Platform branding and public settings.
- Supported languages and published content.
- Legal document versions.
- Cookie and communication settings.
- Merchant agreement publication.
- Webshop/offer categories.
- Affiliate and points-rule configuration.
- Voucher issuance/default validity policies.
- Integration availability/feature flags.
- Notifications and operational thresholds.

Reuse the existing inline CMS, translation and agreement systems. Do not build duplicate editors.

Use Draft → Review → Publish for sensitive content/configuration changes.

Record the published version and allow authorized rollback with full audit history.

Changing global settings must not silently rewrite historical transactions or previously issued voucher terms.

## FINANCIAL & VOUCHER OVERSIGHT

Super Admin can inspect:

- Points ledger and wallet reconciliation.
- Affiliate commission mismatches.
- Failed voucher issuance.
- Paid-but-unfulfilled checkouts.
- Mollie payments/refunds.
- Voucher disputes.
- Suspicious code allocation or repeated redemption.

Provide controlled actions for dispute decisions and permitted compensation.

**Wallet adjustments**

Require:

1. Explicit permission.
2. Fresh password plus email OTP.
3. Consumer and transaction verification.
4. Adjustment amount and reason.
5. Confirmation and audit record.
6. Additional reviewer approval where risk policy requires it.

Never directly overwrite wallet balances or delete ledger entries. Use linked compensating transactions.

No cash withdrawals or unauthorized payout functionality.

## INTEGRATIONS & ANSWER24 MONITORING

Central integration overview:

- Webshop and platform.
- Connection status.
- Last successful event.
- Orders received today.
- Failed/missing-field events.
- Synchronization delay.
- Token/authentication errors.
- Required merchant action.

Answer24 overview:

- Eligible paid orders.
- Pending grouped contact requests.
- Accepted, attempted, completed and cancelled contacts.
- Failed dispatches/callbacks.
- Suppressed requests.
- Consent/eligibility failures.

Allow authorized investigation and safe retry of failed technical operations.

A retry must never duplicate orders, rewards, vouchers or contact requests.

Super Admin cannot override absent marketing permission merely to force a call.

## CENTRAL ERRORS & SECURITY PAGE

Create one searchable operational incident page for API errors, permission denials and system failures.

Every registered API route must have an inventory entry containing:

- Method and route.
- Authentication type.
- Allowed roles/permissions.
- Tenant/resource scope.
- Audit category.
- Error handling.
- Relevant monitoring owner/category.

Capture and correlate:

- 401 Unauthorized.
- 403 Forbidden.
- 419 Session/CSRF expired.
- 422 Validation failure.
- 429 Rate limited.
- 500 Server failure.
- Integration/webhook failures.
- Queue/reconciliation failures.

**Every 403 must be recorded**, including denied resource scope and safe request metadata.

Display:

- Severity and category.
- Timestamp and frequency.
- Route/method.
- Request ID.
- Actor/workspace where permitted.
- Sanitized error summary.
- First/last occurrence.
- Affected service.
- Status: New, Investigating, Resolved or Ignored.
- Assigned administrator and action history.

Group repeated incidents into one notification while preserving individual occurrences.

Filters: status, severity, service, time period, workspace, request method and GET/write category.

Do not store credentials, raw customer payloads, payment data or sensitive headers.

Never expose stack traces or infrastructure secrets to ordinary users.

## AUDIT & SECURITY CONTROLS

Implement append-only, integrity-checked audit records for important administrative and financial actions.

Capture actor, real actor during impersonation, workspace, action, before/after safe metadata, request ID, timestamp and result.

Audit:

- Merchant approvals/suspensions.
- Admin provisioning and permissions.
- Impersonation start/end/actions.
- Agreement/configuration publication.
- Wallet adjustments and refunds.
- Voucher dispute decisions.
- Integration changes/retries.
- Security denials and incident handling.

Audit events must be tamper-evident, retention-controlled and exportable only to authorized users.

Apply Laravel Policies/Gates, least privilege, MFA for administrators, fresh verification for sensitive actions, secure sessions and rate limits.

Protect exports, filters and record-detail endpoints against cross-tenant leakage.

Use explicit approval for destructive or irreversible operations. Prefer reversible suspension to deletion.

## AI-ASSISTED DIAGNOSTICS

For eligible technical incidents, display an **Analyze Error** action.

Send only redacted technical context to the configured AI service.

Return:

- Likely cause.
- Evidence supporting the diagnosis.
- Impacted component.
- Suggested investigation.
- Proposed fix.
- Confidence/uncertainty.

Never forward customer PII, passwords, API tokens, webhook secrets or full private payloads.

AI cannot deploy fixes, change permissions, issue refunds, modify ledgers or close incidents automatically.

Human review and explicit approval are mandatory for production changes.

## API & DATABASE

Use secure `/api/v1/admin/` endpoints for:

- Cockpit statistics and trends.
- Webshop/user search and details.
- Merchant approval and suspension.
- Admin/membership management.
- Integration/Answer24 monitoring.
- Points, payments and voucher oversight.
- Incident search/details/status updates.
- Audit history/export.
- Platform configuration.
- Impersonation start/end.
- Controlled adjustment/approval actions.

Use typed responses and server-side pagination.

Create/reuse:

- `admin_audit_events`
- `security_incidents`
- `api_route_registry`
- `admin_approval_requests`
- `impersonation_sessions`
- `platform_setting_versions`

Reuse existing users, webshops, ledgers, transactions, integrations and content records.

Do not create duplicate sources of truth.

Every protected endpoint must enforce Super Admin permission and relevant resource restrictions, including GET endpoints.

## PERFORMANCE & PRODUCTION OPERATIONS

- Aggregate large KPI datasets efficiently.
- Use database indexes, pagination and bounded date filters.
- Cache safe aggregate statistics with explicit freshness indicators.
- Run expensive reports and exports asynchronously.
- Use Redis/queues where beneficial.
- Monitor slow queries, queue failures, webhook lag and API p95.
- Restrict Laravel Horizon/Pulse/Telescope access to approved administrators and safe diagnostics.
- Use HTTPS, secure cookies, `APP_DEBUG=false`, supported patched runtimes and least-privilege infrastructure credentials.
- Run Composer/frontend dependency scans in CI and block known critical vulnerabilities.
- Generate API/permission documentation automatically.
- Verify backups, restoration, job recovery, alert delivery and audit retention.

Target typical cockpit summary API p95 under 400 ms for cached/optimized staging requests. Measure and report actual results.

## ACCEPTANCE — 10 E2E TESTS

1. **Cockpit:** Super Admin sees accurate platform KPIs, trend filters and recent activity based on real records.
2. **Merchant approval:** Verified webshop can be approved; missing evidence blocks approval; rejection/suspension applies immediately.
3. **User security:** Public users cannot create administrators; permission changes and membership revocations are enforced by APIs.
4. **Impersonation:** Fresh password/email OTP required; target and real admin are audited; sensitive actions remain restricted.
5. **Financial controls:** Wallet adjustment requires valid permissions, step-up authentication, reason and ledger entry; direct balance overwrite fails.
6. **Integrations:** Failed webshop connections, orders and Answer24 jobs are visible; permitted retries do not duplicate processing.
7. **API registry:** Every protected route has documented rights/scope/audit; unauthorized requests return correct errors.
8. **Incident handling:** 401/403/419/422/429/500 incidents appear with request IDs; every 403 is logged and repeated alerts are grouped.
9. **Audit/AI:** Sensitive actions produce tamper-evident audit records; AI diagnostics receive redacted information and cannot execute changes.
10. **Go-live:** Responsive cockpit, monitoring, backup/restore evidence, security scans, API docs, queue recovery and measured staging performance pass.

## DEFINITION OF DONE

Super Admin can securely manage and monitor the complete ShopBonus platform from one operational cockpit.

Merchant approvals, permissions, financial controls, integrations, API errors, audit records and platform configuration work against real backend services.

Sensitive actions require appropriate verification; all 10 E2E tests pass with evidence before production release.

---

## Trello #22: Complete Platform Integration, E2E QA & Production Launch

List: **Frank**. Card: https://trello.com/c/SxfIv4Ir/22-complete-platform-integration-e2e-qa-production-launch. Archived: False.

# ShopBonus — Complete Platform Integration, E2E QA & Production Launch

## OBJECTIVE

Audit, connect, complete and verify the entire ShopBonus platform as one working product.

The goal is a production-ready customer journey from webshop registration and integration through order processing, authorized Answer24 contact, consumer activation, points earning, voucher purchase and redemption.

Test real frontend/API/database behavior across all modules. Fix missing connections, inconsistent states, broken permissions and incomplete workflows.

Do not rebuild functioning features or create duplicate systems.

ShopBonus must remain one centralized platform with:

- One identity system.
- One merchant/workspace permission model.
- One webshop integration framework.
- One order system.
- One offers/vouchers system.
- One affiliate tracking layer.
- One consumer wallet and points ledger.
- One Super Admin monitoring and audit system.

## FULL BUSINESS JOURNEY

Verify the complete process:

1. Merchant registers and verifies email.
2. Merchant identifies business and proves ownership.
3. Merchant accepts the current agreement.
4. Super Admin approves the webshop when required.
5. Merchant accesses the correct workspace.
6. Merchant connects a supported ecommerce platform.
7. A real test order appears in the live Order Sheet.
8. Merchant publishes an offer and loads valid voucher inventory.
9. Paid orders are evaluated for voucher eligibility and lawful contact.
10. Answer24 receives only authorized, deduplicated contact requests.
11. Consumer activates securely or registers independently.
12. Consumer discovers webshops and follows eligible affiliate links.
13. Approved affiliate activity credits the central points wallet.
14. Consumer purchases a voucher with available points.
15. A unique code is assigned atomically and shown in My Vouchers.
16. Redemption, expiry, disputes and permitted adjustments are recorded.
17. Super Admin can inspect activity, errors and audit evidence.

Each transition must work using real APIs. No simulated frontend-only success states.

## CROSS-MODULE DATA INTEGRITY

Validate:

- Central user identity across consumer and merchant access.
- Multiple webshops per user with isolated permissions.
- Stable webshop/order/offer/voucher references.
- Correct ownership and foreign-key relationships.
- No duplicated imported orders.
- No duplicated Answer24 contact tasks.
- No duplicated affiliate rewards.
- No duplicate unique-code allocation.
- No negative spendable points balances.
- No orphaned vouchers, payments or ledger entries.

Use transactional updates, database constraints and idempotency keys.

When a multi-step operation fails, safely retry, compensate or flag it for review without corrupting historical records.

## PAYMENT, CONTACT & VOUCHER RULES

Confirm these rules across the complete system:

**Orders**

- Only confirmed paid orders may become eligible for Answer24.
- Pending/unpaid orders must not create contact tasks.
- Cancelled/refunded orders are removed from pending contact.
- Missing phone blocks telephone contact but not order import.
- Eligible orders from the same customer/webshop within one hour are grouped appropriately.
- Marketing contact requires a documented lawful basis and required consent.

**Affiliate points**

- A tracked click alone does not award spendable points.
- Pending commission produces pending, non-spendable rewards.
- Eligible approved commission credits points once.
- Rejected commissions do not automatically reverse already redeemed points.

**Vouchers**

- One webshop may publish multiple vouchers.
- Unique codes are allocated only when successful purchase is finalized.
- Wallet debit and code issuance must be atomic.
- Issued vouchers preserve their original conditions.
- No cash withdrawals or cash-equivalent payouts.
- Refunds, disputes and reversals use auditable compensating actions.

**Mollie**

- Payment success is verified server-side through Mollie.
- Frontend redirects cannot independently mark a payment successful.
- Failed payments cannot issue vouchers.
- Paid-but-unfulfilled transactions require monitored resolution.

## FRONTEND & DESIGN QA

Review all available original ShopBonus screenshots.

Check:

- Blue branding, typography, spacing and buttons.
- Consistent top navigation.
- Clean URLs without unnecessary internal IDs.
- Responsive desktop, tablet and mobile layouts.
- Functional search, filters, tables, forms and dropdowns.
- Working navigation between consumer, merchant and admin areas.
- Correct loading, empty, success and error states.
- Accessible labels, focus and keyboard navigation.
- NL/EN translations.
- No untranslated keys, broken images or placeholder data.
- Correct inline CMS content, legal pages and cookie banner.

Do not redesign approved screens unnecessarily. Correct implementation differences and missing functionality.

Every visible action must either work or be explicitly disabled with an accurate explanation.

## API & PERMISSION AUDIT

Generate a complete inventory of every API route.

For each endpoint verify:

- Method and path.
- Authentication requirement.
- Role and permission.
- Tenant/resource scope.
- Input validation.
- Response contract.
- Audit category.
- Error handling.
- Rate limits where appropriate.

Test access as:

- Anonymous visitor.
- Consumer.
- Webshop owner.
- Webshop manager.
- Webshop employee.
- Suspended/unverified user.
- Super Admin.

Frontend hiding is insufficient. Direct API access must obey the same permissions.

Ensure consumer, merchant and admin data cannot leak across identities or workspaces.

## SECURITY HARDENING

Verify:

- Supported patched PHP/runtime and Laravel dependencies.
- HTTPS, HSTS and secure cookies.
- CSRF and session rotation.
- Strong authentication and email verification.
- MFA/step-up for sensitive administrator actions.
- Strict CORS origins.
- Server-side validation and authorization.
- Signed/verified external webhooks

---

## Trello #23: Existing System Audit, Integration Improvements & Business Rules

List: **Frank**. Card: https://trello.com/c/rclMXYPz/23-existing-system-audit-integration-improvements-business-rules. Archived: False.

Existing System Audit, Integration Improvements & Business Rules

## OBJECTIVE

Audit the existing ShopBonus implementation, identify reusable functionality and complete the missing or incorrect behavior.

We already have specifications and designs covering registration, CMS, webshop onboarding, integrations, orders, merchant offers, consumer accounts, affiliate rewards, wallet, vouchers, Mollie and Super Admin.

**Do not rebuild these systems. Inspect the actual code, database, API and frontend first.**

Classify each component:

- VERIFIED_WORKING: implemented and tested.
- PARTIAL: implemented but missing functionality.
- BROKEN: exists but fails.
- NOT_BUILT: specification exists, implementation missing.

Provide evidence before changing anything. Preserve working code, database relationships and original approved designs.

## EXISTING SYSTEM — WHAT TO CHECK

Inspect these functional areas:

- One Laravel user authentication system.
- Webshop onboarding and membership permissions.
- Multilingual inline CMS and cookie management.
- Universal ecommerce adapter and live Order Sheet.
- Webshop dashboard, offers and Excel-like code inventory.
- Consumer webshop discovery and activation.
- Daisycon/TradeTracker integrations.
- Central noncash wallet and points ledger.
- Voucher issuance, checkout and Mollie.
- Super Admin, error monitoring and audit logging.

For each area document existing models, services, frontend routes, API endpoints, permissions, test coverage and actual status.

Never claim functionality is complete because a page or Trello description exists.

## IMPROVEMENT — AFFILIATE REWARDS

**Existing design**

Daisycon and TradeTracker track attributed transactions and provide commission information. A central consumer wallet records earned credit.

**Required improvement**

Valid attributed affiliate transactions must automatically generate approved, immediately spendable virtual ShopBonus credit.

Do not wait for the advertiser's final commission approval.

Example:

Consumer purchases through a tracked link. Estimated eligible commission is €4.75.

After validating the transaction and attribution, credit €4.75 of nonwithdrawable ShopBonus wallet value immediately.

- No customer cash payouts.
- No IBAN or withdrawal functionality.
- No duplicate credit on retries.
- Later advertiser rejection does not automatically reverse the credit.
- Default credit validity: 12 months.
- Spend oldest-expiring credit first.
- Fraud/duplicate checks remain mandatory.

Keep actual network commission status separate from consumer wallet status.

**Why:** The ShopBonus proposition gives consumers usable discount credit without making them wait for affiliate settlement. Network reconciliation is internal financial reporting.

Use exact integer minor-unit accounting and one immutable ledger. Confirm configurable earning rates and caps.

## IMPROVEMENT — ONE VOUCHER SYSTEM

**Existing design**

Webshops publish offers, supply codes and consumers see issued vouchers in My Vouchers.

**Required improvement**

Use exactly one voucher model with two acquisition methods.

**Answer24 free voucher**

- ShopBonus receives a signed authorized handoff.
- Reserve the applicable unique code immediately.
- Return a short, unguessable, single-use claim URL.
- Answer24 delivers the link.
- Consumer verifies identity and claims the reserved voucher.
- Unclaimed entitlement expires after 90 days.
- Release unclaimed inventory after expiry.
- Answer24 handles permitted day-30/60/80 reminders and communication.

**Wallet/Mollie voucher**

A consumer purchases an offer using wallet value, Mollie or a combination.

Example:

- Voucher purchase price: €20.
- Available wallet credit: €10.
- Remaining amount: €10.
- Consumer pays €10 through Mollie.
- After verified payment, ShopBonus deducts €10 wallet credit and issues one voucher.

Support points-only checkout when the wallet fully covers the purchase price.

For mixed checkout, reserve the intended wallet contribution safely, verify Mollie payment server-side and finalize debit plus code allocation exactly once.

On payment failure, release the reservation; do not issue a voucher or lose wallet credit.

Viewing an offer must never allocate a wallet-purchased code.

**Why:** Both acquisition methods produce the same consumer voucher, while only the acquisition and funding processes differ.

Use one My Vouchers page, one code inventory and consistent validity, status and redemption handling.

## IMPROVEMENT — ANSWER24 RESPONSIBILITIES

**Existing design**

ShopBonus receives webshop orders and exchanges authorized events with Answer24.

**Required improvement**

Keep responsibilities strictly separated.

**Answer24 owns:**
- Telemarketing and contact eligibility.
- Customer consent checks for outreach.
- Calls, agents and campaign execution.
- Initial invitation delivery.
- Contact results and reminder communication.

**ShopBonus owns:**
- Webshop/order data.
- Offers and available codes.
- Signed handoff API.
- Single-use claim links.
- Voucher reservations.
- Consumer identity verification.
- Voucher issuance and wallet updates.
- Redemption/claim status callbacks.

ShopBonus must not implement a second dialer, outbound-call engine or marketing automation system.

Protect handoffs with signatures, scoped credentials, idempotency and expiry.

**Why:** Answer24 already has a dedicated contact platform. Duplicating it increases cost, maintenance and compliance risks.

## IMPROVEMENT — ALL ECOMMERCE PLATFORMS

**Existing design**

One shared connector architecture supports different webhook, API, polling and extension strategies.

**Required improvement**

Verify the actual integration status of every planned platform:

| Platform | Integration approach |
|---|---|
| WooCommerce | Native signed webhooks |
| Shopify | Merchant-authorized webhooks/API; customer-data verification |
| Wix | Orders events/authorized automation |
| BigCommerce | Webhook plus authorized API lookup |
| Ecwid | Store API token and order synchronization |
| Lightspeed C-Series | Supported confirmation/API integration |
| Lightspeed E-Series | Supported API/integration |
| PrestaShop | Private module |
| OpenCart | Private extension |
| Squarespace | Orders API and incremental polling |
| Shopware 6 | Private integration/extension |
| Magento/Adobe Commerce | Authorized API/private module |
| Mijnwebwinkel | Approved API/partner route |
| CCV Shop | Verified merchant/partner API route |

For every platform test:

1. Merchant authorization.
2. Real order receipt.
3. Customer first name and email.
4. Original order reference.
5. Amount and currency.
6. Payment status.
7. Cancellation/refund changes.
8. Retry and duplicate handling.
9. Disconnect/revocation.
10. Missing-field behavior.

Phone is optional for order import.

Do not show Connected if mandatory fields or trustworthy payment status are unavailable.

**Why:** A working webhook does not automatically mean an integration can support the complete ShopBonus workflow.

Prioritize genuinely working self-service connectors. Do not spend time on provider-specific approval processes until actual access requirements are verified.

## IMPROVEMENT — MERCHANTS, OFFERS & CODES

**Existing design**

Merchant workspaces, offers and uploaded codes are managed from the dashboard.

**Required improvement**

Confirm:

- One account can manage multiple approved webshops.
- Employees see only authorized workspaces.
- Ownership verification cannot be bypassed.
- Multiple offers per webshop.
- Separate searchable Excel-like code sheet per offer.
- Manual paste, CSV/XLSX import and validation.
- Unique versus reusable codes.
- Stock and expiry calculations.
- Issued vouchers remain valid after offer pause.
- Code issuance is not misreported as verified webshop redemption.

Verify original merchant-facing screens and interactions.

**Why:** Incorrect code inventory or workspace permissions can directly cause invalid vouchers, duplicate issuance or data exposure.

## IMPROVEMENT — CONSUMER SUPPORT

**Existing design**

Consumers manage vouchers and report problems.

**Required improvement**

Provide a compact **Code Does Not Work** form:

- Select the issued voucher.
- Enter a short explanation.
- Upload a screenshot.
- Show ticket status.

Validate the screenshot securely and connect the case to the correct consumer, offer, webshop and voucher.

AI may analyze redacted evidence and recommend a solution. A verified replacement must use approved inventory and be issued at most once.

Uncertain cases require human review.

**Why:** Invalid merchant codes need a traceable resolution without uncontrolled wallet changes or duplicate compensation.

## SECURITY, API & PERFORMANCE AUDIT

Verify and improve:

- Laravel Policies/Gates and tenant isolation.
- Secure Sanctum sessions and CSRF.
- Input validation and rate limits.
- Signed external webhooks.
- Encrypted credentials.
- Atomic wallet and voucher transactions.
- Protection against replay, IDOR/BOLA and privilege escalation.
- MFA and step-up for sensitive admin actions.
- Central 401/403/419/422/429/500 reporting.
- Audit recording for every 403.
- Append-only financial/security history.
- Safe AI diagnostics without autonomous financial writes.

Register API methods, permissions, scopes and audit requirements in the generated API handbook.

Check current official Laravel and provider documentation before implementing changes.

Use appropriate indexes, Redis, queues, caching and pagination. Measure performance rather than assuming it is fast.

Run dependency scans, verify backups and test failed-job recovery.

## ACCEPTANCE — 10 REAL E2E TESTS

**1. Existing functionality audit**

Open the real application and inspect its API, database and frontend.

Expected: every functional area is classified VERIFIED_WORKING, PARTIAL, BROKEN or NOT_BUILT with evidence. Working functionality is preserved.

**2. Merchant permissions**

Create one user with access to Webshop A and Webshop B, plus an employee allowed only into A.

Switch workspaces and request B's orders directly using the employee session.

Expected: owner sees authorized data; employee receives 403 for B. Every denial is audited.

**3. Ecommerce order import**

Connect a real WooCommerce test shop and submit paid, unpaid and cancelled orders.

Expected: each order appears once, payment/cancellation statuses remain accurate and duplicate webhooks do not create duplicate records.

**4. Platform compatibility**

Connect a second supported platform using its actual authorized API/webhook setup. Import a real order with all required fields.

Expected: full verification succeeds only with complete data. Missing mandatory fields produce Connected With Limitations or Setup Required, never a false Connected status.

**5. Answer24 free voucher**

Send one signed handoff containing a webshop, offer and customer reference; repeat the same request.

Expected: one reserved code and one short claim link. Verified consumer claims once. Expired/unclaimed reservation releases after 90 days; reminder events are deduplicated and handled by Answer24.

**6. Instant affiliate credit**

Import one valid attributed affiliate conversion with €4.75 estimated eligible commission; replay the event and later change its network status to Rejected.

Expected: €4.75 is credited immediately only once. The rejection updates internal reporting but does not automatically remove the consumer credit. Unspent credit follows the 12-month expiry rule.

**7. Wallet-only voucher purchase**

Give Consumer A €20 available credit. Offer costs €20 with one unique code. Submit two simultaneous purchase requests.

Expected: one €20 debit, one issued voucher and one allocated code. The repeated request cannot deduct twice.

**8. Wallet + Mollie combined checkout**

Consumer B has €10 available credit. Voucher costs €20. Start checkout and pay the remaining €10 using Mollie's test environment.

Expected: successful verified payment deducts €10 wallet credit and issues one voucher. Failed payment releases reservations and issues nothing. Duplicate callbacks do not duplicate fulfillment.

**9. Voucher inventory and support**

Import 500 unique codes, including duplicates and invalid rows. Issue one voucher, then submit a Code Does Not Work report with screenshot.

Expected: valid inventory remains accurate, rejected rows are reported, issued codes are unchanged and a verified replacement can be granted once with full audit evidence.

**10. Security and production readiness**

Attempt forged API roles, cross-tenant access, webhook replay and unauthorized wallet adjustment. Simulate queue failures and inspect monitoring.

Expected: attacks fail, errors and audits are recorded, retries remain idempotent and API documentation, dependency scans, backup recovery and measured response times are demonstrated.

## DEFINITION OF DONE

The existing ShopBonus implementation has been audited and improved without duplicating working modules.

Affiliate credits are immediate and noncash. Answer24 owns outreach. Wallet/Mollie split payments work atomically. One voucher model serves both free and paid acquisition.

All supported webshop integrations have documented evidence and accurate availability states.

All 10 E2E scenarios pass with real API/database evidence. Deliver a concise report showing existing functionality, changes made, reasons, remaining limitations and production readiness.

---

## Trello #24: Sales Attribution, Voucher Tracking & Network Analytics

List: **Hassan**. Card: https://trello.com/c/RZmvUIky/24-sales-attribution-voucher-tracking-network-analytics. Archived: False.

## OBJECTIVE / REUSE EXISTING WORK

Prove **real views → CTA clicks → activation → paid orders → voucher redemption → repeat purchases**, including cross-webshop activity, rewards and ShopBonus fees.

**Reuse:** Universal Order Adapter/Sheet, banner embed, accounts, vouchers, Wallets, favorites/categories, Mollie, Moneybird and analytics. Add missing tracking, attribution, reconciliation and reports. **No duplicate import, ledger or editor.**

Why: prove real sales, repeat revenue and correct affiliate/network economics.

## 1. MEASURE WHAT VISITORS REALLY DID

Existing embed emits versioned events:

- **eligible:** display rules matched; not necessarily viewed.
- **viewable_impression:** ≥50% visible ≥1 second (IntersectionObserver), deduplicated.
- **cta_click:** intentional CTA; **dismiss/X** separate, never a conversion.
- **claim\_started, account\_verified, voucher_reserved/issued/redeemed:** backend verified.
- **order\_paid/refunded, repeat\_order:** from trusted Order Adapter/provider, not browser confirmation.

Context: event UUID/UTC, tenant/shop, campaign/version/placement, category/path/device, consent and lawful pseudonymous click reference. No PII/payment data in browser events. Validate origin/schema, bots, retries and idempotency.

Filter by shop/banner/category/device/date/A-B: eligible, viewable, CTA, **CTR = clicks/viewable**, accounts, paid orders, sales/AOV, codes, repeats, refunds, cost, fee and ROI. Unknown stays **Unattributed**.

## 2. LINK POPUP → REAL PAID ORDER

With valid CMP consent create expiring opaque **click_id** in permitted first-party cookie/server bridge. No permission → no tracker/fingerprinting.

Where allowed, carry click_id into cart/order metadata. Join to **existing Order Adapter** paid external\_order\_id/amount/shop. Fallback: verified claim or unique checkout coupon. **Success-page visit ≠ paid order.**

Label **click-, voucher-, view-associated or unattributed**; configurable lookback and documented multi-banner priority. Refund/cancel/chargeback adjusts once; no double counting.

**Attribution ≠ causality.** Test A/B plus randomized **no-banner holdout**, stable lawful assignment; report conversion, AOV and incremental lift separately from attributed revenue.

## 3. TWO SEPARATE CUSTOMER VOUCHER FLOWS

**A. Earn from first order; redeem on a later order**

€100 paid order → earn €10. Existing adapter/rules validate; **atomically reserve one voucher + sponsor liability**. Authorized invitation; **verified ShopBonus account required to claim/reveal**. Redeem on next eligible order with shop/minimum/expiry/single-use limits.

**B. Buy with existing consumer Wallet**

€20 code − €10 Wallet = **€10 Mollie**. Reserve Wallet; issue usable code only after confirmed payment. Failed payment releases funds; full Wallet skips gateway; webhook replay cannot double-charge.

Affiliate points are **auto-approved** on verified eligibility, **never cash-out**; only voucher purchase. Refund abuse may reverse credit. No payout queue.

Code must work at real checkout: authorized WooCommerce, Shopify, BigCommerce, Wix/Ecwid coupon API or unique-code pool. Verify **creation, acceptance, binding if supported, redemption** separately. Unsupported = Limited.

States: **eligible → reserved → claimable → issued → redeemed/expired/cancelled**. Atomic limits, idempotent calls, honest binding limitations and refund reversal.

## 4. MERCHANT WALLET, 30% FEE, INVOICES

Direct merchant fee: **30% ON TOP of customer reward**, not order value:

- Fashion €100 order, 10% reward = **€10 consumer + €3 ShopBonus = €13** merchant funding.
- Electronics €100 order, 4% reward = **€4 consumer + €1.20 ShopBonus = €5.20**.

Branch/shop rules; workspace automatically owns Wallet. Top-up via **Mollie iDEAL**, invoice via **Moneybird**. Show Available/Reserved/Settled/Reversed; stop new rewards when unfunded.

Each qualifying order creates **one idempotent sponsorship obligation**; issuance/redemption cannot bill twice. Refund/expiry adjustments audit. Reconcile **order → voucher → sponsor → redeemer → fee → Wallet → invoice**.

Cross-shop redemption records sponsor and accepting shop; agreed settlement only, no silent deduction from Shop B. No cashout.

## 5. WEBSHOP NETWORK + EXTERNAL AFFILIATE PROGRAMS

Consumers choose favorite shops (heart) and categories at onboarding/later. Track lawful **Shop A banner → member → category → verified Shop B order**. Each shop sees own orders and aggregated network contribution, never other tenants' PII.

For **TradeTracker/Daisycon**, ingest authorized conversion IDs, amounts/status/commissions; separate **pending/confirmed/reversed** and calculate net after customer benefit/refunds. Network contracts differ from **direct 30% fee**; gross ≠ profit.

**Answer24:** Authorized claim link only; free code reserved at handoff, **90-day** claim expiry, permitted reminders **days 30/60/80**. Answer24 owns contact/telemarketing; ShopBonus creates no calls.

## 6. DASHBOARDS AND OPERATIONAL CONTROL

**Merchant:** funnel, clicks/CTR, paid orders, AOV/repeats, redemptions, holdout, ROI, reward cost, Wallet, invoices, attribution confidence. Filter shop/banner/category/URL/device/date. Separate attributed sales from **incremental lift**.

**ShopBonus Super Admin:** 100-shop network overview, referring vs receiving shops, total viewable exposure, unique members, verified GMV, earned vs spent vouchers, confirmed external affiliate revenue, 30% direct fees, wallet liabilities, unmatched events, fraud and reconciliation alerts. Consumer sees only own earned/purchased vouchers, favorites and Wallet.

Click a chart number to see a permission-filtered drill-down to event/order evidence; never expose cross-tenant PII. Display connection/API limitations and freshness timestamps.

## 7. API, PRIVACY, PERFORMANCE & AUDIT

Reuse current models; add only missing **promotion\_events, attribution\_clicks, attribution_links, experiments/assignments, reporting rollups and reconciliation records** where not already implemented. Browser ingest is public but domain/shop-key validated, rate-limited and replay protected. Payment/order/coupon changes require trusted provider callbacks.

Protected APIs for event/funnel reports, order attribution, experiment controls, wallet reconciliation, voucher status, affiliate imports and audit must enforce **Laravel Policies, tenant ownership, scoped roles, CSRF/session security, validation and rate limits**. Audit actor, source evidence, financial mutations and 401/403/419/422/429/500 with request ID; redact PII/secrets. Document OpenAPI and run dependency/security checks.

Use Laravel queues with atomic uniqueness, bounded retries/dead-letter queue and unique keys for (provider, external order/event/transaction). Redis/cache and indexed daily aggregates keep dashboards fast; define consent-aware retention and deletion. **Load-test 100 shops × 10,000 impressions = 1,000,000 events**, without blocking webshops. Monitor ingestion lag, webhook failures, unmatched orders, chargeback reversals and wallet/invoice mismatches.

## EXACTLY 10 END-TO-END TESTS

1. **True impressions:** 100 eligible, 70 actually viewable, 7 CTA clicks and 20 X closes → **70 impressions, 10% CTR**, 20 dismissals; device/category splits reconcile.
2. **Consent and joining:** Consented click_id reaches one genuine paid order via existing adapter; no-consent visit creates no illicit tracking identifier; unmatched order stays Unattributed.
3. **Paid-order accuracy:** Duplicate webhook, two banner clicks and one paid €112 order produce exactly **one** attributed order. Cancellation/partial refund adjusts figures once.
4. **First-order voucher:** Real €100 payment reserves one €10 code; guest cannot claim. Verified ShopBonus account reveals it and second qualifying checkout redeems once.
5. **Real coupon:** Supported WooCommerce/Shopify checkout accepts generated one-time code; unsupported provider is Limited and cannot show successful redemption.
6. **Wallet purchase:** €20 code paid with €10 Wallet + €10 confirmed Mollie activates once; failed payment frees reservation and replay cannot debit twice.
7. **Merchant fees:** Fashion €100 books €10 reward + €3 fee once; Electronics €100 books €4 + €1.20. Mollie top-up/Moneybird invoice reconcile; empty Wallet blocks new sponsorship.
8. **Network/referrals:** Shopper arrives from Shop A and purchases at Shop B; correct sponsor is settled, favorites persist and neither merchant sees the other's personal orders.
9. **Affiliate + Answer24:** Confirmed TradeTracker/Daisycon commission and later reversal change net revenue correctly; authorized Answer24 voucher reserves code, follows 90-day expiry/reminders and creates no ShopBonus calling task.
10. **A/B, scale and security:** A/B + no-banner holdout report attributed vs incremental results; ingest one million events with deduplication, bot filtering, audited permission denials, queue monitoring and acceptable p95.

**DONE:** Real banner-to-order evidence, reliable A/B reporting, working customer voucher paths, reconciled merchant economics, network revenue dashboard and 10 passing E2E tests. No duplicate Order Adapter, Wallet or banner editor.

---

## Trello #25: Live Banner Studio on the Merchant's Own Website

List: **Frank**. Card: https://trello.com/c/zmIBNaB7/25-live-banner-studio-on-the-merchants-own-website. Archived: False.

## OBJECTIVE

Build the screenshot's **live editor on the merchant's real webshop**: design, position, test, measure and publish banners. Include campaign overview, trigger library, branch offers and AI optimization. No developer or reinstall for changes.

Not a chat app or mock webshop. Native products, theme and cart remain untouched; only ShopBonus widgets are editable.

**Reuse:** Workspaces, Order Adapter/Sheet, vouchers, favorites/categories, Wallet, Mollie/Moneybird and reporting. Build no duplicate payments, order or accounting.

**Example:** “Spend €100, earn a €10 voucher for your NEXT purchase.” Merchant controls presentation; existing services verify eligibility/cost.

## 1. SCREEN — IMPLEMENT THE ATTACHED DESIGN

**TOP BLUE BAR:** Logo, webshop, **LIVE EDITOR**, Draft/Live/Paused, device previews, **Preview**, **Save draft**, **Publish campaign**; Undo/Redo, Schedule, Pause, history, Rollback and unsaved-change warning.

**CENTER:** Actual webshop/products/cart, selectable ShopBonus overlays with blue drag/resize handles. Snap to stable corner/ribbon/inline anchors. Missing target after theme change → hide and warn. Native HTML stays untouched.

**LEFT FLOATING TOOLS:**

- **Templates:** ribbon, inline category card, floating reward, chat-style teaser (no chat), popup, exit intent, cart-progress.
- **Text:** editable heading, reward amount, subtitle, CTA, font, color and short terms.
- **Images:** upload/crop merchant logo and imagery, icons, backgrounds and gradients.
- **AI Create:** 2–3 brand-aligned **editable** banner designs.

**RIGHT “ShopBonus Studio” PANEL — THREE TABS:**

1. **Design:** format, size, position, image, text/font, colors, CTA, animation and mobile.
2. **Triggers:** screenshot fields **20 sec, /collections/*, Fashion, Fade in, Once/session**, plus advanced rules and **Why shown/hidden**.
3. **AI Insights:** measured suggestions for location, timing and thresholds; **Optimize with AI / Review suggestion** creates editable tests, never publishes automatically.

Show **“Customers see the published version until you launch”**. Collapse tools on small screens; shoppers never see editor controls.

**CAMPAIGN OVERVIEW:** Search/filter banners by status, URL, category and placement. Show name, thumbnail, Draft/Live/Scheduled/Paused, location, updated date, **viewable impressions, CTA clicks, CTR and attributed orders**. Actions: **Edit on website, Preview, Duplicate, Pause, Results**. Tenant-scoped.

## 2. EXACT MERCHANT EXPERIENCE

1. Select owned webshop → **Edit on my website** → real domain with private editor.
2. Select template/offer; drag, design and test page, category, device, timer/cart. **Label simulations**.
3. **Save draft** survives reload; visitors see old published version.
4. **Preview → Publish/Schedule → Pause/Rollback**; versioned remote update.

Only permitted owners/staff edit; Super Admin audits. Visitors and other tenants cannot see drafts.

## 3. EMBED AND CROSS-DOMAIN LIVE EDITING

Reuse one **async ShopBonus embed** per verified domain/public shop key. Fetch only cached published config; no reinstall. Provide setup guide and **Check installation** diagnostics.

Reuse approved provider installation for WooCommerce, Wix, BigCommerce, Ecwid, Lightspeed, PrestaShop, OpenCart, Shopware, Magento, Squarespace, Mijnwebwinkel and CCV; Shopify needs an authorized **Theme App Embed**. Flag **embed/cart/checkout/coupons** individually Available/Limited/Unsupported.

**Pairing:** Auth remains at ShopBonus origin; single-use expiring permission scoped to user/tenant/shop/origin. Verify postMessage **origin/source/nonce/schema**. No session tokens in script/URL/storage. Laravel Policies protect writes; safe fallback if cross-window pairing blocked.

Public visitors get only a small isolated renderer (e.g. Shadow DOM). Authorized editors load React-Konva/Fabric or equivalent; publish accessible HTML/CSS. Webshop still works during outages.

## 4. RULES, REAL CART AND MERCHANT COST

**Cart €94/€100 → €6 to go** only from a current authorized storefront cart bridge, NOT order webhooks (post-purchase). Mark preview simulations. Missing/stale cart → static truthful offer, no fake progress.

**TRIGGER LIBRARY (AND/OR groups):**

- **Location:** URL/wildcard, homepage, category, specific product ID/SKU, cart; exclude checkout/payment/account.
- **Behavior:** seconds, scroll %, desktop exit, first/returning visitor if lawful, session frequency, cooldown, prior dismiss.
- **Commerce:** verified cart total, eligible items/categories, campaign stock, Wallet budget.
- **Context:** device, language, shop-local day/time, consented favorite categories/shops, acquisition source.
- **Logic:** nested AND/OR, exclusions, priorities, one intrusive popup and **Why shown/hidden**. X ≠ CTA click.

Show **earn on order 1 → verified ShopBonus claim → later redemption**. Read existing threshold, expiry and budget. €10 consumer reward + €3 (30% fee) = **€13 merchant cost**. Show cost; block unfunded Publish. No duplicate coupons or debits.

**BRANCH OFFERS:** Existing categories set defaults plus webshop overrides. Fashion €100 → 10%/€10; Electronics €100 → 4%/€4. Preview threshold, next-order terms, fee, cap and Wallet; eligibility service decides.

**LIVE STATS:** Selecting a banner opens Results: eligible, **viewable impressions** (≥50% visible ≥1 sec), unique CTA clicks, CTR, **verified attributed orders**, revenue/repeat and date/category/device filters. X never counts as CTA; unmatched orders = **Unattributed**. Read reporting aggregates, no new event ledger.

## 5. AI CREATE AND AI INSIGHTS

**AI Create:** From offer, logo and style generate 2–3 editable designs with separate text/image/CTA layers. Owner edits them. Queue with spending limits; check approved Laravel/AI tools.

**AI Optimization button:** Owner chooses “Analyze my last 1,000 verified orders” (or an available date range). Use authorized aggregated Order Sheet, banner results, product categories and basket values. Return **three proposed placements, timing or spending thresholds**, each with sample size, evidence, estimated merchant cost and a clear hypothesis. **Review → editable test campaign → A/B/holdout → human Publish**; results come from the reporting engine. No PII, secrets, invented uplift or automatic publishing.

## 6. DATA, API, SECURITY & OPERATIONS

Add only missing widget/layer config, branch/rule overrides, campaign overview projections, assets, pairing, install health and immutable published versions. Optimistic locking prevents overwrites; only sanitized published config is publicly cached.

Document APIs for installation, pairing, assets/drafts, campaign list/results, branch offer options, rule simulation, preview, publish/schedule/pause/rollback, AI optimize and read-only published config. Protected routes enforce Laravel **Policies, tenant ownership, permissions, CSRF/session security, validation, throttling and audit**; log 401/403/419/422/429/500 with request IDs. Reject XSS, unsafe URLs/uploads, replay, forged messages and cross-tenant reads. CI scans and OpenAPI required.

Performance: async script, CDN/cache, lazy assets, minimal public JS, no checkout slowdown, keyboard/Escape/reduced-motion accessibility, graceful outages. Monitor p95 response, JS errors, missing embed and AI failures.

## EXACTLY 10 END-TO-END TESTS

1. **Actual storefront:** Owner opens their real connected webshop and browses genuine products/cart; only the ShopBonus widget can be selected, dragged or resized.
2. **Studio + overview:** Top bar, left tools, three tabs and searchable campaign list work; selection opens the correct banner with current placement/status/results, including mobile preview.
3. **Draft privacy:** Save/reload a €100/€10 banner draft; merchant sees it privately while anonymous shopper still sees only the last published version.
4. **Lifecycle:** Schedule in shop-local time, publish, pause and rollback; real site shows the expected immutable version and complete actor/time audit.
5. **Trigger library:** Fashion /collections/* + 20s + scroll ≥50% + once/session displays once; wrong SKU, excluded checkout, early timer and cooldown suppress correctly. Returning-visitor/exit and AND/OR priorities are explained.
6. **Cart integrity:** Real €94 cart displays 94% progress/€6 to go; cart change updates it. Unsupported cart falls back to static offer; editor sample is marked simulated.
7. **Results + branch rules:** 100 eligible, 70 viewable, 7 CTA clicks and 20 X closes show 70 impressions, 10% CTR and zero clicks from closes. Fashion €100/€10 and Electronics €100/€4 show correct costs; verified orders only count when attributed.
8. **Security:** Other tenant, visitor, expired pairing and forged-origin postMessage cannot view draft/edit/publish; failures have correct status and audit/request ID.
9. **AI optimization:** Generate three editable designs; analyze 1,000 verified orders and propose three evidence-based location/threshold tests with sample/cost. Owner reviews before A/B launch; no PII or auto-publish.
10. **Integration/operations:** Installation check works on WooCommerce/Wix/BigCommerce and authorized Shopify App Embed; missing/duplicate script, CSP block and API outage produce actionable health notices, not webshop failure. CI/API/performance checks pass.

**DONE:** Screenshot-matching real-site editor plus campaign overview, full trigger library, verified live results, branch offers, AI optimization and 10 passing E2E tests; existing orders/vouchers/wallets remain authoritative.

---

## Trello #26: Chatbox customers and webshop help openai 6

List: **Frank**. Card: https://trello.com/c/aWBrCkQK/26-chatbox-customers-and-webshop-help-openai-6. Archived: False.

# SHOPBONUS — VERIFIED AI CUSTOMER SUPPORT CHAT

**Priority:** High

Build one reliable AI customer-service chat for ShopBonus consumers and Workspace users. Reuse existing Laravel APIs, FAQ, wallets, rewards, vouchers, orders, campaigns, permissions and frontend components.

**Scope:** ShopBonus customer support only. NOT a white-label chatbot, webshop widget, general AI assistant or separate chatbot SaaS.

## 1. CHAT INTERFACE

- Consumer: "Ask ShopBonus" inside their account.
- Workspace users: "Support" inside their Workspace.
- Modern, compact, responsive chat with history and suggested questions.
- Show live progress: Checking account → Verifying → Searching again → Answer.
- Display only verified answers, timestamps and relevant internal action buttons.
- Thumbs up/down beneath each answer.
- Support escalation when necessary.
- Restore conversations after refresh/reconnect.

## 2. VERIFIED ANSWER ENGINE

Every question follows:

1. Authenticate user; resolve authorized role and Workspace.
2. Classify intent and select permitted Laravel tools.
3. Retrieve live API data or approved FAQ.
4. Validate authorization, source, freshness and completeness.
5. Generate answer using OpenAI.
6. Independently verify factual claims against evidence.
7. If incomplete, perform ONE targeted additional research attempt.
8. Return verified answer, specific clarification or support escalation.

**Never publish unverified AI text.**

Laravel remains the source of truth for money, eligibility, payments, permissions and statuses. OpenAI explains verified facts but never invents them.

## 3. CHAT API

`POST /api/v1/ai/chat`

Request:

```
{
  "conversation_id": "conv_8291",
  "message": "Where is my €10 reward?",
  "current_page": "/app/wallet",
  "locale": "en"
}
```

Laravel internally determines `user_id`, `role`, `workspace_id` and `allowed_tools`. Never trust these values from the frontend or model.

Verified response:

```
{
  "message_id": "msg_9102",
  "status": "VERIFIED",
  "answer": "Your €10 reward is currently reserved.",
  "evidence_ids": ["reward_FS20482_v4"],
  "checked_at": "2026-10-23T12:00:00Z",
  "retry_used": true,
  "action": {
    "label": "View wallet",
    "route": "/app/wallet"
  }
}
```

Statuses: `VERIFIED`, `PARTIALLY_VERIFIED`, `NEEDS_CLARIFICATION`, `ESCALATED`, `UNAVAILABLE`.

Partial answers must clearly distinguish verified information from unknown information.

## 4. LARAVEL TOOLS AND EXACT FIELDS

Map these contracts to existing Laravel services/routes. Do not duplicate existing business logic.

ToolEndpointRequired fields`get_my_walletGET /api/v1/me/walletavailable_minor`, `reserved_minor`, `spent_minor`, `currency`, `unitget_my_order_reward_statusGET /api/v1/me/orders/{reference}/rewardpayment_status`, `reward_eligible`, `reward_minor`, `reward_statusget_my_vouchersGET /api/v1/me/vouchersstatus`, `source_type`, `claimed_at`, `expires_atget_voucher_quoteGET /api/v1/me/voucher-quotes/{offer_id}price_minor`, `wallet_usable_minor`, `mollie_due_minor`, `stock_availableget_workspace_walletGET /api/v1/workspaces/current/walletavailable_minor`, `reserved_minor`, `currencyget_offer_costGET /api/v1/workspaces/current/offers/{id}/costconsumer_reward_minor`, `shopbonus_fee_minor`, `merchant_total_economic_cost_minorget_campaign_performanceGET /api/v1/workspaces/current/campaigns/{id}/performanceimpressions`, `clicks`, `tracked_order_starts`, `attributed_paid_ordersget_integration_healthGET /api/v1/workspaces/current/integrations/healthplatform`, `capabilities`, `last_sync_at`, `last_error_codesearch_approved_knowledgePOST /api/v1/ai/knowledge/searchdocument_id`, `scope`, `version`, `approved_at`, `content`

All financial amounts use integer minor units. Each result needs a source ID and retrieval timestamp.

### Example A — Wallet

```
{
  "available_minor": 1000,
  "reserved_minor": 0,
  "spent_minor": 2500,
  "currency": "EUR",
  "unit": "EUR_REDEMPTION_CREDIT"
}
```

Correct: "You have €10 available ShopBonus credit."

Never invent points conversion or additional balances.

### Example B — Missing reward

```
{
  "order_reference": "FS-20482",
  "payment_status": "paid",
  "reward_eligible": true,
  "reward_minor": 1000,
  "reward_status": "reserved"
}
```

Correct: "Your order is paid and the €10 reward is reserved."

Never invent a release date.

### Example C — Voucher checkout

```
{
  "price_minor": 2000,
  "wallet_usable_minor": 1000,
  "mollie_due_minor": 1000,
  "stock_available": true
}
```

Correct: "Use €10 wallet credit and pay €10 through Mollie."

Only existing Laravel checkout may execute payment or issue vouchers.

### Example D — Merchant cost

```
{
  "consumer_reward_minor": 1000,
  "shopbonus_fee_rate": 0.30,
  "shopbonus_fee_minor": 300,
  "merchant_total_economic_cost_minor": 1300
}
```

Correct: "Total economic cost: €13."

Never double-count discounts or fees.

### Example E — Campaign results

```
{
  "impressions": 10000,
  "clicks": 420,
  "tracked_order_starts": 110,
  "attributed_paid_orders": 8
}
```

Correct: "420 clicks and 8 attributed paid orders."

110 order starts are NOT 110 completed sales.

## 5. AUTOMATIC ANSWER CHECKS AND RETRY

Before publishing, Laravel checks:

- `AUTH`: authorized user and data scope.
- `SOURCE`: actual approved evidence exists.
- `FRESHNESS`: current enough for the question.
- `COMPLETENESS`: essential facts are present.
- `CONSISTENCY`: sources do not conflict.
- `CLAIMS`: each factual claim matches evidence.
- `POLICY`: no prohibited disclosure or promise.
- `ACTION`: internal link and permissions valid.

If incomplete, send a diagnostic packet for ONE additional targeted tool round.

Example: User asks "Where is my €10?" Initial wallet API only returns €0 available.

```
{
  "original_question": "Where is my €10?",
  "failure_reason": "MISSING_REWARD_STATUS",
  "known_facts": {
    "available_minor": 0
  },
  "missing_facts": ["order_reward_status"],
  "allowed_next_tools": ["get_my_order_reward_status"],
  "max_additional_tool_rounds": 1
}
```

Retrieve the missing status, verify and answer.

Rules:

- Never simply repeat the same prompt.
- Retry only if another authorized check may help.
- Never retry authorization denials.
- Ask a specific clarification if an identifier is missing.
- Escalate conflicting evidence.
- Apply strict time, token and cost limits.
- Never invent an answer when APIs fail.
- Only claim ticket creation after a successful Laravel response.

Fallback example:

"Your available balance is €0, but I couldn't verify the pending reward. Would you like me to contact support?"

## 6. OPENAI INTEGRATION

Use a verified, supported GPT-6-family model through the existing OpenAI integration, Responses API or Laravel AI SDK.

For direct OpenAI Responses API:

- `POST /v1/responses`
- `model`: configured supported model ID.
- `instructions`: published ShopBonus policy.
- `input`: question plus authorized context.
- `tools`: backend functions with `strict: true`.
- `tool_choice: "required"` when live data is mandatory.
- `parallel_tool_calls: false` when sequential execution is required.
- `text.format`: strict structured answer schema.

Example tool:

```
{
  "type": "function",
  "name": "get_my_order_reward_status",
  "description": "Retrieve the authenticated consumer's order reward status.",
  "parameters": {
    "type": "object",
    "properties": {
      "order_reference": {"type": "string"}
    },
    "required": ["order_reference"],
    "additionalProperties": false
  },
  "strict": true
}
```

Strict JSON validates format, NOT factual correctness. Laravel independently validates all returned facts and ownership.

No API keys in frontend code.

## 7. LARAVEL REVERB — REAL-TIME CHAT

Use Laravel Reverb instead of paid external Pusher Channels where practical. Reuse Laravel Echo/broadcasting; protocol-compatible `pusher-js` may still be needed.

Private authorized channel:

`private-shopbonus.support.conversation.{conversationId}`

Events:

- `ChatResearchStarted`
- `ChatVerificationStarted`
- `ChatRetryStarted`
- `ChatAnswerVerified`
- `ChatAnswerUnavailable`
- `ChatFeedbackSubmitted`
- `ChatReviewRequired`
- `ChatSupportReplyAvailable`

Minimal event payload:

```
{
  "conversation_id": "conv_8291",
  "message_id": "msg_9102",
  "status": "VERIFYING"
}
```

Never broadcast raw API responses, private evidence or unverified drafts. Implement reconnect, missed-message sync and HTTP fallback.

## 8. SUPER ADMIN SETTINGS PAGE

`/app/platform/ai-support/settings`

Five compact tabs:

**General:** enable/disable, languages, model, timeout, max retry (1), budget, escalation.

**Restrictions:** forbidden claims, topics, phrases, sensitive disclosures, required notices; actions `BLOCK`, `REWRITE`, `ESCALATE`, `REVIEW`.

Example:

```
{
  "rule_code": "NO_UNVERIFIED_REFUND_PROMISE",
  "match_type": "semantic_claim",
  "action": "BLOCK",
  "enabled": true
}
```

Enforce critical rules in Laravel; don't depend only on prompts or keyword matching. Restrictions must not suppress legally required information or legitimate complaints.

**Knowledge:** approved FAQ, scope, version, publish/rollback.

**Quality:** negative feedback, failed verification, human corrections, regression cases.

**Operations:** Reverb health, latency, API errors, retries, OpenAI cost and escalation rate.

All settings support draft → test → publish → rollback, restricted to Super Admin with audit history.

## 9. THUMBS UP/DOWN + HUMAN REVIEW

API:

`POST /api/v1/ai/messages/{message_id}/feedback`

```
{
  "rating": "down",
  "reason": "incorrect_information",
  "comment": "My reward was already released."
}
```

Reasons: `incorrect_information`, `did_not_answer`, `outdated_information`, `unclear`, `other`.

A downvote creates an admin review containing:

- Question and answer.
- Evidence/source snapshot.
- Tools and retry history.
- Verification results.
- User feedback.

Reviewer outcomes:

`CONFIRMED_ERROR`, `ANSWER_CORRECT`, `NEEDS_INVESTIGATION`.

Approved corrections update FAQ, policy or code and become regression tests.

**Never automatically treat thumbs-up as factual proof or use unreviewed votes to train/change production behavior.**

## 10. SECURITY, AUDIT AND PERFORMANCE

- Laravel Policies and server-side ownership for every tool/API.
- Consumer/Workspace/Super Admin data isolation.
- Session/CSRF protection, rate limiting and input validation.
- Prompt-injection defenses; tool allowlist.
- Read-only tools by default; existing secured workflows for mutations.
- Redact unnecessary personal/payment data sent to OpenAI.
- Never expose voucher secrets, API credentials or other tenants' data.
- Log route, scope, source, policy, status, retries, errors, latency and cost.
- Cover 401/403/429/500 and tool timeouts.
- Redis/queues, DB indexes, bounded processing, circuit breakers.
- Conversation retention/deletion policies.
- Clean internal links without tracking parameters.

## 11. TEN E2E TESTS

1. **Wallet:** Consumer asks balance; exact €10 API value, correct unit, evidence and wallet link.
2. **Automatic retry:** Initial wallet result is €0; reward status missing. Second tool retrieves `reserved`; verified answer shown.
3. **No invented dates:** Reward lacks release date; user repeatedly asks when it arrives. No fabricated deadline.
4. **Voucher quote:** €20 price, €10 credit, €10 Mollie. Correct explanation; no unauthorized charge or voucher issue.
5. **Workspace isolation:** Workspace A requests B's data using IDs and prompt injection. Denied with zero leakage.
6. **Campaign statistics:** 10,000 impressions, 420 clicks, 110 starts, 8 paid orders. No confusion between starts and sales.
7. **Reverb:** Private channels prevent cross-user access; correct progress, reconnect and HTTP fallback.
8. **Settings:** Publish forbidden refund-promise rule; verify block, audit and successful rollback.
9. **Feedback:** Downvote creates review with evidence. Admin approves correction and regression test; no automatic training.
10. **Failure:** Primary tool and retry fail. Honest fallback, bounded processing, optional verified escalation and complete audit.

## ACCEPTANCE CRITERIA

- One ShopBonus support chat for consumers and Workspace users.
- Existing systems reused; no duplicate business logic.
- Every factual answer checked against authorized evidence.
- One targeted retry; no uncontrolled loops.
- No fabricated financial values or dates.
- Reverb real-time status and secure conversation channels.
- Super Admin settings, restrictions and rollback.
- Thumbs feedback with human review.
- Minimum 100 regression questions.
- Zero cross-Workspace leaks in security tests.
- Target ≥95% correct or appropriately escalated test outcomes.
- All 10 E2E tests passing.

Provide implemented route inventory, sample payloads, test evidence and production monitoring results.

---

## Trello #27: App store for platforms

List: **Frank**. Card: https://trello.com/c/OqUwpiPu/27-app-store-for-platforms. Archived: False.

_No description in the supplied export; do not infer requirements._

---


## Trello #29: CENTRAL NOTIFICATION CENTER, EMAIL, REMINDERS & AI TEMPLATES

Downloaded export 2026-10-25. Card: https://trello.com/c/WcPlsK8w. Ownership list is not completion evidence.

Build one centralized notification system for ShopBonus consumers, Workspace users and Super Admins.

Reuse existing Laravel Notifications, Reverb, Echo, queues, mail, authentication, Policies, audit, translation and monitoring systems. Inspect current implementation first; repair missing functionality without duplicating existing modules.

**Goal:** Every important ShopBonus event creates the correct notification, delivered through the appropriate channels, with persistent history and configurable preferences.

## 1. CENTRAL NOTIFICATION INBOX

Add a notification bell to the existing ShopBonus top navbar.

Required:

- Unread count.
- Compact dropdown showing recent notifications.
- "View all" opens notification center.
- Mark individual notification as read.
- Mark all as read.
- Notification detail/action.
- Pagination.
- Filter: All / Unread.
- Relative timestamps.
- Correct icons and colors.
- Empty/loading/error states.
- Mobile-responsive layout.

The same component must work for consumers, Workspace users and Super Admins, with role-specific content.

Notifications must persist after logout, refresh and reconnect.

Do not create separate notification systems for individual modules.

## 2. REQUIRED NOTIFICATION EVENTS

Implement the following event catalog.

### Consumer

Event codeTriggerIn-appEmail`account_welcome`Registration completedYesYes`email_verified`Email confirmedYesOptional`cashback_credited`Valid conversion creditedYesOptional`voucher_received`Voucher successfully issuedYesYes`voucher_expiring`Configured reminder before expiryYesOptional`voucher_used`Redemption confirmedYesOptional`voucher_cancelled`Voucher invalidatedYesYes`cashback_adjusted`Ledger correction/reversalYesYes`support_reply`New human support responseYesOptional`favorite_merchant_offer`New eligible favorite-store promotionOptionalOptional

### Workspace

Event codeTriggerIn-appEmail`workspace_approved`Workspace approvedYesYes`workspace_rejected`Workspace rejectedYesYes`offer_published`Offer activatedYesOptional`offer_expiring`Offer approaching expirationYesOptional`commission_changed`Approved commission configuration changedYesYes`integration_attention`Workspace integration requires actionYesOptional`workspace_support_reply`New support responseYesOptional

### Super Admin

Event codeTriggerIn-appEmail`workspace_review_required`Workspace requires approvalYesOptional`ai_support_escalated`AI support requires human reviewYesOptional`affiliate_import_failed`Daisycon/TradeTracker import failedYesCritical only`notification_delivery_failed`Delivery exhausted retriesYesCritical only`critical_notification_failure`Important transactional notification unavailableYesYes

Do not notify users for routine clicks, page visits or internal API calls.

Never create a cashback notification before an actual verified wallet credit.

## 3. NOTIFICATION DATA MODEL

Use Laravel's existing database notification infrastructure.

Required normalized payload:

```
{
  "id": "ntf_9021",
  "type": "cashback_credited",
  "event_key": "cashback:tx_123:credited",
  "recipient_type": "consumer",
  "title": "€7,00 ShopBonus-tegoed ontvangen",
  "body": "Je aankoop bij Woon & Co is geregistreerd.",
  "locale": "nl",
  "action_label": "Bekijk mijn voordelen",
  "action_route": "/app/benefits",
  "read_at": null,
  "created_at": "2026-10-23T12:00:00Z"
}
```

Requirements:

- Unique notification ID.
- Idempotent event key.
- Authorized recipient.
- Event type.
- Template version.
- Locale.
- Safe internal action.
- Read timestamp.
- Creation timestamp.
- Delivery-channel status.

Use the existing database model where possible.

## 4. EXACT API CONTRACTS

Audit existing routes first and reuse matching functionality.

### GET /api/v1/notifications

Query:

`?filter=unread&page=1&per_page=20`

Response:

```
{
  "data": [
    {
      "id": "ntf_9021",
      "type": "cashback_credited",
      "title": "€7,00 ShopBonus-tegoed ontvangen",
      "body": "Je aankoop bij Woon & Co is geregistreerd.",
      "action_route": "/app/benefits",
      "read_at": null
    }
  ],
  "meta": {
    "unread_count": 3,
    "current_page": 1,
    "total": 18
  }
}
```

### PATCH /api/v1/notifications/{id}/read

Mark one owned notification as read.

### POST /api/v1/notifications/read-all

Mark all owned notifications as read.

### GET /api/v1/notification-preferences

Retrieve authenticated user's preferences.

### PUT /api/v1/notification-preferences

Example:

```
{
  "locale": "nl",
  "email_enabled": true,
  "preferences": {
    "cashback_credited": {
      "in_app": true,
      "email": false
    },
    "voucher_expiring": {
      "in_app": true,
      "email": true
    },
    "favorite_merchant_offer": {
      "in_app": false,
      "email": false
    }
  }
}
```

Preferences must respect mandatory transactional/security communications and applicable consent requirements.

Do not allow client input to override mandatory delivery rules.

## 5. REAL-TIME REVERB

Use existing Laravel Reverb/Echo broadcasting.

Private user channel:

`private-shopbonus.user.{userId}.notifications`

Authorize subscription using the existing authenticated user and Policies.

Broadcast:

- `NotificationCreated`
- `NotificationRead`
- `NotificationReadAll`
- `NotificationCountUpdated`

After receiving an event:

1. Update notification bell.
2. Update unread count.
3. Display relevant toast.
4. Refresh the affected notification list.

Keep broadcast payload minimal.

Never include secrets, voucher codes or financial API responses in broadcast events.

After reconnect, fetch unread count and missed notifications from Laravel.

## 6. EMAIL NOTIFICATIONS

Reuse the existing Laravel mail configuration, queues and templates.

Requirements:

- Branded ShopBonus emails.
- NL/EN language support.
- Responsive HTML.
- Plain-text alternative.
- Correct subject and sender.
- Safe internal links.
- No tracking parameters.
- No AI/OpenAI references in customer-facing messages.
- Transactional and optional promotional notifications handled separately.
- Respect preferences and consent.
- Queue sending.
- Track delivery attempts and failures.
- Avoid duplicate emails for the same recipient/event/channel.

Email sending must not delay wallet credit or voucher issuance.

## 7. AUTOMATIC REMINDERS

Use Laravel Scheduler and queues.

Required reminder types:

### Voucher expiry

Configurable example:

- 30 days before expiration.
- 7 days before expiration.
- 1 day before expiration.

Only send reminders for active vouchers that are still usable.

A voucher already used, expired or cancelled must not receive an expiry reminder.

### Workspace offer expiry

Notify Workspace owner before an active offer expires.

### Unresolved support cases

Notify appropriate users when a human support reply becomes available. Internal overdue-support alerts may use configurable thresholds.

### Favorite merchant offers

Optional notifications for consumers following a webshop, only when the relevant preference/consent is enabled.

Prevent duplicate reminders for the same event and reminder stage.

## 8. OPENAI 6 — AUTOMATIC NOTIFICATION TEMPLATES

Create centralized notification template management.

**Super Admin route:**

`/app/platform/notifications`

Tabs:

- Overview.
- Templates.
- Events.
- Reminders.
- Delivery logs.
- Settings.

Use the existing OpenAI integration to draft professional notification templates.

The AI receives a structured event definition, allowed variables, audience, purpose and locale.

Example input:

```
{
  "event_type": "cashback_credited",
  "audience": "consumer",
  "locale": "nl",
  "channels": ["database", "mail"],
  "allowed_variables": [
    "merchant_name",
    "amount_formatted",
    "action_url"
  ],
  "tone": "friendly_professional",
  "max_title_chars": 70,
  "max_body_chars": 180
}
```

Example AI output:

```
{
  "event_type": "cashback_credited",
  "locale": "nl",
  "title": "{{amount_formatted}} ShopBonus-tegoed ontvangen",
  "body": "Je hebt ShopBonus-tegoed verdiend met je aankoop bij {{merchant_name}}.",
  "action_label": "Bekijk mijn voordelen"
}
```

### Template rules

- Use OpenAI structured output.
- Generate NL and EN variants.
- Use only approved variables.
- Never invent amounts, deadlines or transaction statuses.
- Validate placeholders against the event schema.
- Validate character limits.
- Escape/sanitize rendered content.
- Preview with realistic sample values.
- Super Admin reviews and approves.
- Draft → Test → Publish → Rollback.
- Record template versions and audit history.

**AI-generated templates must never publish automatically.**

AI is used for writing templates, not deciding whether a financial event occurred.

## 9. SUPER ADMIN TEMPLATE API

Required contracts, mapped to existing routes if present:

`GET /api/v1/platform/notifications/templates`

`POST /api/v1/platform/notifications/templates/generate`

`GET /api/v1/platform/notifications/templates/{id}`

`PUT /api/v1/platform/notifications/templates/{id}`

`POST /api/v1/platform/notifications/templates/{id}/test`

`POST /api/v1/platform/notifications/templates/{id}/publish`

`POST /api/v1/platform/notifications/templates/{id}/rollback`

Additional administration:

`GET /api/v1/platform/notifications/deliveries`

`GET /api/v1/platform/notifications/events`

`GET /api/v1/platform/notifications/settings`

`PUT /api/v1/platform/notifications/settings`

All mutations require existing Super Admin Policies and audit logging.

Test actions must never send emails to real customers unless an explicitly authorized test recipient is selected.

## 10. CENTRAL API DOCUMENTATION

Register every notification API and event in the existing central API documentation.

For every endpoint document:

- HTTP method and path.
- Purpose.
- Authentication.
- Allowed roles.
- Consumer/Workspace/Admin scope.
- Request JSON.
- Response JSON.
- Field definitions.
- Example values.
- Validation.
- 401/403/404/422/429/500 handling.
- Rate limits.
- Audit requirements.
- Idempotency behavior.
- Performance expectations.

For every notification event document:

- Triggering business event.
- Event code.
- Recipient.
- Channels.
- Variables.
- Template version.
- Reminder rules.
- Preference requirements.
- Delivery/retry behavior.

Example:

`cashback_credited`

Trigger: verified wallet ledger credit committed.

Required variables:

```
{
  "merchant_name": "Woon & Co",
  "amount_minor": 700,
  "currency": "EUR"
}
```

Formatted template variable:

`amount_formatted = "€7,00"`

Do not expose raw internal IDs or financial secrets in user-facing templates.

## 11. I18N & LANGUAGE MANAGEMENT

Use the existing Laravel/frontend internationalization architecture.

Requirements:

- NL default.
- EN supported.
- Locale follows user preference.
- Fallback to published default locale when translation is missing.
- Correct currency/date formatting.
- One event schema shared across languages.
- Separate approved template versions per locale.
- No untranslated variable keys shown.
- No runtime AI calls required for ordinary notification delivery.

Add missing-translation monitoring.

## 12. RELIABILITY & SECURITY

Reuse existing global authentication, Policies, rate limiting, 401/403 handling and monitoring.

Notification-specific requirements:

- Durable database persistence.
- Idempotent notification dispatch.
- Unique event/recipient/channel delivery keys.
- Dispatch after successful business transaction commit.
- Queue retries with bounded attempts.
- Failed delivery review/retry.
- Separate in-app and email delivery status.
- Recipient isolation.
- Safe action routes.
- Duplicate reminder prevention.
- Configurable retention and deletion.
- No sensitive information in Reverb events.
- No duplicate notifications after reconnect.
- Audit template/settings changes.

Use a durable outbox for financially critical events if the current architecture does not already guarantee reliable publication after commit.

## 13. TEN END-TO-END TESTS

1. **Cashback notification**

Verified €7 wallet credit generates one persistent notification, correctly formatted in NL, and updates the navbar badge.

1. **Voucher issued**

Successful voucher redemption creates in-app/email notifications using the approved template, without exposing voucher secrets.

1. **Read status**

Open notification center, mark one item and then all items as read. Verify counters and persistence after login.

1. **User preferences**

Disable optional cashback emails. Confirm in-app delivery continues and mandatory transactional rules remain enforced.

1. **Voucher reminders**

Generate 30/7/1-day reminders. Verify correct schedule, no duplicates and no reminders for used/expired vouchers.

1. **Workspace events**

Approve a Workspace and publish an offer. Verify correct owner notifications and no consumer/admin recipient confusion.

1. **AI template generation**

Generate NL/EN templates with OpenAI, validate placeholders, preview values, approve and publish. Verify unapproved drafts are never sent.

1. **Template rollback**

Publish a new version, send a test notification, roll back and confirm the previous version is active with full audit history.

1. **Delivery failure and recovery**

Simulate mail provider failure, queue retry and Reverb disconnect. Verify persistent inbox delivery, accurate failure logs and successful recovery.

**10. Permissions and documentation**
Verify consumer/Workspace/Admin isolation, all notification endpoints documented, correct error responses, locale fallback and valid action links.

## ACCEPTANCE CRITERIA

- One central notification infrastructure.
- Persistent notification inbox and navbar bell.
- All specified consumer/Workspace/Admin events connected.
- Reverb real-time delivery.
- Configurable emails and preferences.
- Scheduled reminders without duplicates.
- Super Admin notification management.
- OpenAI-generated NL/EN templates with human approval.
- Template versioning, testing and rollback.
- Every endpoint/event documented in the central API documentation.
- Notification delivery resilient to queue and WebSocket failures.
- Existing security systems reused.
- All 10 E2E tests passing.

**Deliver:** Final API/event inventory, published templates, notification screenshots, queue/delivery test evidence and E2E results.


## Trello #30: PLATFORM RELIABILITY, FINANCIAL RECONCILIATION, MONITORING & SECURITY

Source: owner export downloaded 2026-10-25. Card: https://trello.com/c/6xHzjOwY/30-platform-reliability-financial-reconciliation-monitoring-security. Ownership is not completion evidence.

Audit and strengthen ShopBonus's existing Laravel API, financial ledger, monitoring, database, audit logging, security and backup infrastructure.

**Important:** Inspect what already exists before implementing anything. Reuse Laravel Policies, authentication, 401/403 handling, rate limiting, Redis, queues, existing audit systems and monitoring tools. Repair incomplete functionality without creating duplicate infrastructure.

The goal is a reliable, secure, measurable and recoverable production platform.

## 1. FINANCIAL LEDGER RECONCILIATION — P0

ShopBonus credits consumer wallets immediately after a valid, attributable conversion is confirmed.

Support:

- Daisycon.
- TradeTracker.
- Direct merchant commissions.
- Configurable consumer/ShopBonus commission split.
- Voucher redemption.
- Adjustments and reversals.

### Reconciliation rules

Run automatic reconciliation against authoritative ledger and transaction records.

Verify:

1. Every credited conversion has valid source evidence.
2. No transaction was credited twice.
3. Commission calculation matches the applicable historical rate.
4. Consumer/ShopBonus shares total 100%.
5. Wallet balance matches ledger movements.
6. Voucher redemption matches wallet debit and allocation.
7. Reversals generate compensating ledger entries.
8. No missing or unexplained financial mutations.

Example:

```
{
  "transaction_id": "tx_123",
  "commission_minor": 1000,
  "consumer_share": 0.70,
  "consumer_credit_minor": 700,
  "shopbonus_revenue_minor": 300,
  "currency": "EUR",
  "status": "credited"
}
```

Expected:

€7 consumer credit + €3 ShopBonus share = €10 commission.

If a discrepancy occurs:

- Record a reconciliation finding.
- Preserve original transaction history.
- Prevent duplicate correction.
- Alert Super Admin.
- Require authorized resolution for non-deterministic corrections.

Never silently overwrite ledger entries or invent balancing transactions.

### Suggested internal APIs

`GET /api/v1/platform/finance/reconciliation`

`POST /api/v1/platform/finance/reconciliation/run`

`GET /api/v1/platform/finance/reconciliation/{id}`

Example finding:

```
{
  "finding_code": "LEDGER_MISMATCH",
  "transaction_id": "tx_123",
  "expected_minor": 700,
  "actual_minor": 1400,
  "difference_minor": 700,
  "status": "open"
}
```

These are proposed contracts. Reuse existing routes/services when present.

## 2. CENTRAL API MONITORING — P1

Monitor all production API routes.

Required metrics:

- Request count.
- Average latency.
- P50/P95/P99 latency.
- 4xx/5xx error rates.
- Timeout rate.
- Queue wait time.
- Failed jobs.
- External provider failures.
- Database query latency.
- Redis/cache health.

Track important operations separately:

- Authentication.
- Wallet.
- Voucher redemption.
- Affiliate conversion import.
- Merchant commission configuration.
- AI support.
- Reverb.
- Notifications.
- Website enrichment.

Use Laravel Pulse and Horizon where compatible with the actual infrastructure. Telescope must not expose sensitive production payloads.

### Example metric

```
{
  "route": "POST /api/v1/ai/chat",
  "requests": 1200,
  "p95_ms": 4200,
  "error_rate": 0.012,
  "period": "24h"
}
```

Example alert thresholds, configurable:

- API 5xx rate above 2% over 5 minutes.
- Critical queue not processing.
- Database unavailable.
- Wallet ledger mismatch.
- Affiliate import repeatedly failing.
- Backup overdue.

Do not create alerts for ordinary expected 401/403 responses unless rates indicate an anomaly.

## 3. CENTRAL AUDIT LOGGING — P1

Reuse the existing platform audit system.

Every important action must be attributable to:

- Actor.
- Role.
- Workspace/user scope.
- Action.
- Resource.
- Timestamp.
- Request/correlation ID.
- Result.
- Relevant before/after values.

Audit:

- Wallet credits/debits.
- Commission configuration changes.
- Voucher issuance/cancellation.
- Workspace approval.
- Affiliate program activation.
- Super Admin settings.
- AI support policy publishing.
- Notification template publishing.
- Permission changes.
- Manual reconciliation corrections.

Example:

```
{
  "actor_id": 1,
  "actor_role": "super_admin",
  "action": "commission_split.updated",
  "resource_type": "merchant",
  "resource_id": 373,
  "before": {
    "shopbonus_share": 0.30
  },
  "after": {
    "shopbonus_share": 0.10
  },
  "result": "success"
}
```

Requirements:

- Search/filter audit history.
- Permission-controlled access.
- Tamper-evident or append-only handling for financial/security events.
- Sensitive fields redacted.
- Defined retention policy.
- No credentials or voucher secrets in logs.

## 4. DATABASE PERFORMANCE & INTEGRITY — P1

Audit actual production schema and query patterns.

Focus on:

- Wallet ledger.
- Affiliate transactions.
- Voucher ownership.
- Commission history.
- Workspaces.
- Offers.
- Notifications.
- Chat conversations.
- Audit logs.

Required:

- Appropriate unique constraints.
- Foreign-key integrity.
- Transactional financial operations.
- Correct indexes for common filters.
- Query-plan inspection.
- N+1 query prevention.
- Controlled pagination.
- Redis caching where useful.
- Cache invalidation after mutations.
- Background processing for expensive jobs.

Examples:

- Unique `(provider, provider_transaction_id)` where appropriate.
- Indexed `(user_id, created_at)` on transaction history.
- Indexed `(workspace_id, status)` for merchant records.
- Indexed notification recipient/read status.

Validate against the existing schema before adding indexes or constraints.

Avoid excessive indexes that slow imports and writes.

Measure performance before and after optimization.

## 5. PLATFORM SECURITY TESTING — P0

Audit existing security controls and test them end to end.

Do not rebuild working authentication, Policies or existing 401/403 handling.

Test:

- Consumer access isolation.
- Workspace isolation.
- Super Admin authorization.
- IDOR prevention.
- Wallet ownership.
- Voucher-code secrecy.
- Financial mutation authorization.
- CSRF/session security.
- Rate limiting.
- Input validation.
- Safe redirects.
- File upload validation.
- Scraping SSRF protection.
- Prompt-injection boundaries.
- API credential protection.
- Sensitive logging.
- Dependency/runtime patch status.

Special attention:

- Affiliate redirect URLs.
- Website scraping.
- OpenAI tools.
- Commission overrides.
- Voucher redemption.
- Reverb private channels.

Any critical finding must have evidence, severity, affected component, exact fix and regression test.

Do not assume the system is compromised or safe without evidence.

## 6. BACKUP & DISASTER RECOVERY — P0

Audit hosting and existing backup processes.

Required coverage:

- Production database.
- Critical uploaded/media files.
- Voucher/transaction records.
- Configuration required for recovery.
- Infrastructure configuration.
- Secrets through secure credential recovery procedures, not plaintext exports.

Implement or verify:

- Automated scheduled backups.
- Encrypted backup storage.
- Offsite copies.
- Restricted access.
- Retention policy.
- Backup integrity checks.
- Failure alerts.
- Documented restore procedure.
- Periodic restore testing.

Define realistic business requirements for:

- RPO: acceptable maximum data loss.
- RTO: acceptable maximum recovery time.

Proposed initial targets for approval:

- Financial records: RPO ≤15 minutes.
- Critical service restoration: RTO ≤4 hours.

These are targets, not promises. Confirm infrastructure capability and cost before adoption.

Test restoration in an isolated environment without overwriting production.

Document dependencies and recovery order.

## 7. SUPER ADMIN OPERATIONS OVERVIEW

Reuse existing Super Admin dashboard where available.

Add a compact operational overview:

- Financial reconciliation status.
- Critical API health.
- Queue health.
- Database health.
- Security findings.
- Backup status.
- Last successful restore test.
- Open incidents.

Display:

`HEALTHY`, `WARNING`, `CRITICAL`, `UNKNOWN`.

A component must never show `HEALTHY` merely because monitoring data is missing.

Provide links to existing detailed monitoring/audit interfaces.

Do not expose sensitive logs or credentials in the dashboard.

## 8. CENTRAL API DOCUMENTATION

Document new/modified routes in the existing API documentation.

For each route:

- Method/path.
- Authentication.
- Allowed roles.
- Resource scope.
- Request/response examples.
- Validation.
- Error responses.
- Idempotency.
- Audit requirements.
- Performance target.
- Related monitoring.

Maintain a route inventory and identify undocumented existing endpoints.

Avoid duplicate route implementations and internal IDs in customer-facing URLs.

## 9. ACCEPTANCE METRICS

Set up reporting for:

- Ledger discrepancy count.
- Duplicate financial transaction count.
- Reconciliation execution time.
- Critical API availability.
- P95 endpoint latency.
- Failed queue jobs.
- Slow database queries.
- Open critical security findings.
- Successful backup percentage.
- Last verified restore date.

Financial discrepancies and failed backups must generate actionable alerts.

Use configurable thresholds based on observed production performance.

## 10. TEN END-TO-END TESTS

1. **Financial reconciliation**

Import a valid €10 commission with 70/30 split. Verify €7 consumer credit, €3 ShopBonus revenue and zero ledger discrepancy.

1. **Duplicate transaction**

Deliver the same affiliate conversion multiple times. Confirm one credit, one revenue allocation and correct duplicate detection.

1. **Commission change**

Change a merchant from 30/70 to 10/90. Verify new transactions use the new split and historical transactions retain their applicable rates.

1. **Ledger mismatch**

Introduce an isolated test discrepancy. Reconciliation identifies the exact difference, creates a finding and prevents silent correction.

1. **API monitoring**

Simulate slow responses, timeouts and 500 errors. Verify route-specific latency/error metrics and alert thresholds.

1. **Audit trail**

Change commission settings, redeem a voucher and modify a Super Admin policy. Verify accurate actor, scope, action, timestamps and redacted sensitive values.

1. **Database integrity**

Test concurrent wallet mutations, invalid references and large paginated datasets. Verify correct constraints, consistency and query performance.

1. **Security isolation**

Attempt unauthorized access to another consumer's wallet, Workspace data and voucher codes. Verify existing protections block access and record relevant findings.

1. **Backup and restore**

Create an encrypted test backup and restore it into an isolated environment. Verify financial records, voucher ownership, file integrity and recorded recovery duration.

**10. Disaster and recovery**
Simulate queue/database/provider failure in a controlled test environment. Verify alerts, safe recovery, no duplicate financial operations and correct operational dashboard status.

## DEFINITION OF DONE

- Financial ledger reconciliation automated and tested.
- Central API monitoring operational.
- Existing audit system complete for critical actions.
- Database queries and integrity verified.
- Platform security tests passing.
- Automated encrypted backups verified.
- Documented and tested disaster recovery.
- Super Admin operational overview working.
- All relevant APIs documented.
- All 10 E2E tests passing.

**Deliver:** Infrastructure inventory, findings/fixes, benchmark results, reconciliation report, security test evidence, backup/restore proof and final E2E report.


## Trello #31: Platform API & Webhook Engineering

Source: owner export. https://trello.com/c/eC0AHVuu/31-platform-api-webhook-engineering

# ShopBonus — Top 10 Commerce Platform API, Webhook & Storefront Integration Engineering

## OBJECTIVE

Research, implement, verify and document the ten priority commerce platform adapters against current official developer documentation.

Extend the existing ShopBonus ConnectorRegistry, ConnectionManager, OrderIngestionService and Workspace App Store. No parallel integration architecture.

For EACH platform deliver:

1. Supported platform/version and installation method.
2. Official developer-documentation links and reviewed date.
3. Authentication, scopes, credentials and rotation.
4. Exact order/product/coupon API endpoints.
5. Subscribed webhook names and sample payloads.
6. Signature verification, retries and deduplication.
7. Order/payment/refund field mappings.
8. Supported banner/widget publication method.
9. Real connection/order/refund verification evidence.
10. Limitations, fallback and capability status.

Never invent an endpoint, webhook, scope or availability. Confirm exact API versions before implementation.

## 1. SHOPIFY

Docs:

- [https://shopify.dev/docs/api/admin-graphql](https://shopify.dev/docs/api/admin-graphql "smartCard-inline")
- [https://shopify.dev/docs/apps/build/webhooks](https://shopify.dev/docs/apps/build/webhooks "smartCard-inline")
- [https://shopify.dev/docs/apps/build/online-store/theme-app-extensions](https://shopify.dev/docs/apps/build/online-store/theme-app-extensions "smartCard-inline")

Install: merchant-authorized Shopify app with OAuth/session-token handling.

Orders: Admin GraphQL `orders` and `order` queries. Request only required scopes; protected customer data may require additional approval. Verify field visibility using a real merchant-authorized test order.

Webhooks: register documented order/payment/update/refund/cancellation topics plus uninstall/privacy events applicable to the selected API version.

Verify Shopify webhook HMAC using the raw request body and app secret. Dedupe event IDs.

Banners: Theme App Extension with App Blocks/App Embed Blocks. Use merchant-controlled activation and theme status verification.

Products/offers: GraphQL product/catalog queries; implement discounts only when proper permissions and API capability are available.

Never rely on storefront JavaScript for authoritative checkout/order data.

## 2. WOOCOMMERCE

Docs:

- [https://developer.woocommerce.com/docs/apis/rest-api/v3/](https://developer.woocommerce.com/docs/apis/rest-api/v3/ "smartCard-inline")
- [https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/](https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/ "smartCard-inline")
- [https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks](https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks "smartCard-inline")

Install: restricted WooCommerce REST credentials or dedicated WordPress plugin.

REST examples:

- `GET /wp-json/wc/v3/orders`
- `GET /wp-json/wc/v3/orders/{id}`
- `GET /wp-json/wc/v3/products`
- `GET /wp-json/wc/v3/coupons`
- `GET /wp-json/wc/v3/orders/{id}/refunds`

Webhooks: `order.created`, `order.updated`, and supported refund/product/coupon topics. Confirm exact topics in the deployed WooCommerce version.

Verify `X-WC-Webhook-Signature` using HMAC-SHA256 over the raw payload and configured webhook secret.

Banners: WordPress block/widget or dedicated plugin with uninstall cleanup. Prefer native WordPress/WooCommerce hooks rather than direct core modifications.

## 3. WIX ECOMMERCE

Docs:

- [https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/introduction](https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/introduction "‌")
- [https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/orders/order-created](https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/orders/order-created "‌")

Install: Wix app authorization or documented site-owner integration.

Use current Wix eCommerce Orders API, not obsolete Stores Orders API examples. Read/search orders and related transaction/refund information with required permissions.

Order-created event: `wix.ecom.v1.order_created`.

Webhook example fields:
`eventType`, `instanceId`, `entityId`, `eventTime`, `id`, `slug`.

Wix event payloads may be JWT-encoded. Verify the signature and instance identity before processing.

Banners: supported Wix app/site extension or approved Wix Blocks/Velo integration. Do not assume arbitrary external HTML can be silently published.

## 4. BIGCOMMERCE

Docs:

- [https://docs.bigcommerce.com/developer/docs/integrations/webhooks/overview](https://docs.bigcommerce.com/developer/docs/integrations/webhooks/overview "smartCard-inline")
- [https://docs.bigcommerce.com/developer/docs/integrations/webhooks/event-reference/events](https://docs.bigcommerce.com/developer/docs/integrations/webhooks/event-reference/events "smartCard-inline")
- [https://docs.bigcommerce.com/developer/docs/admin/widgets-and-scripts/overview](https://docs.bigcommerce.com/developer/docs/admin/widgets-and-scripts/overview "smartCard-inline")

Install: merchant OAuth app or restricted store API credentials.

REST:
`POST https://api.bigcommerce.com/stores/{store_hash}/v3/hooks`

Header: `X-Auth-Token`.

Webhook examples:

- `store/order/created`
- `store/order/updated`
- `store/order/statusUpdated`
- `store/order/refund/created`
- `store/order/transaction/created`

Sample event:
`{"scope":"store/order/created","producer":"stores/abc123","data":{"type":"order","id":250}}`

IMPORTANT: `store/order/created` can include incomplete/unpaid orders. Fetch the full order and confirm payment separately.

Banners: BigCommerce Widgets API with supported placement regions and required content permissions.

## 5. ECWID

Docs:

- [https://docs.ecwid.com/](https://docs.ecwid.com/ "smartCard-inline")
- [https://docs.ecwid.com/webhook-automations](https://docs.ecwid.com/webhook-automations "smartCard-inline")
- [https://docs.ecwid.com/develop-apps/app-settings](https://docs.ecwid.com/develop-apps/app-settings "smartCard-inline")

Install: Ecwid app authorization/token flow appropriate to integration type.

Confirm relevant scopes, including `read_orders` and any catalog permissions. Request write permissions only for approved operations.

Webhook example: `order.created`.

Use webhook notification → authenticated order API lookup → normalized order. Implement polling/backfill when delivery or access is limited.

Banners: documented storefront customization/app extension capability; otherwise merchant-approved manual placement.

Detect store/API tier and unavailable scopes before activating features.

## 6. LIGHTSPEED ECOM C-SERIES

Docs:

- [https://ecom-support.lightspeedhq.com/hc/en-us/articles/220320568-API-documentation](https://ecom-support.lightspeedhq.com/hc/en-us/articles/220320568-API-documentation "smartCard-inline")

Treat C-Series separately from Lightspeed Retail and E-Series. Their APIs and permissions must not be mixed.

First confirm merchant plan eligibility, API access, authentication method and supported order endpoints.

Lightspeed documentation indicates API access may depend on the merchant subscription. Display an actionable setup restriction if access is unavailable.

Prove actual order retrieval, payment fields, refund information and dependable change detection.

If using confirmation-page tracking, mark the capability `LIMITED`; it is not equivalent to verified server-side order ingestion.

Banner placement must be supported by the store's theme/integration capabilities.

## 7. PRESTASHOP

Docs:

- [https://devdocs.prestashop-project.org/9/modules/introduction/](https://devdocs.prestashop-project.org/9/modules/introduction/ "smartCard-inline")
- [https://devdocs.prestashop-project.org/9/modules/concepts/hooks/](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/ "smartCard-inline")
- [https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionvalidateorderafter/](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionvalidateorderafter/ "smartCard-inline")

Install: signed/versioned ShopBonus ZIP module.

Use documented PrestaShop hooks and approved Webservice/API access.

Module responsibilities:

- Secure ShopBonus pairing.
- Order/payment/refund event forwarding.
- Product and offer synchronization.
- Optional banner rendering through storefront display hooks.
- Module disable/uninstall cleanup.

Check `actionValidateOrderAfter` and applicable order-status/refund hooks for the exact deployed version. An order-validation hook is not automatically proof of settled payment.

Verify supported versions, multistore behavior and compatibility.

## 8. OPENCART

Docs:

- [https://docs.opencart.com/developer-guide/extensions](https://docs.opencart.com/developer-guide/extensions "smartCard-inline")

Install: version-specific OpenCart extension, including OCMOD packaging when required.

OpenCart 4 supports extension modules and an Events system. Prefer event listeners; use OCMOD only where supported hooks are insufficient.

Implement:

- Secure merchant pairing.
- Order-created/status-changed event mapping.
- Authenticated server-to-server delivery.
- Product and coupon extraction.
- Storefront banner module.
- Uninstall cleanup.

Never assume OpenCart 3 and 4 share identical event signatures, extension paths or packaging requirements.

Test both initial order and subsequent paid/cancelled statuses.

## 9. SHOPWARE 6

Docs:

- [https://developer.shopware.com/docs/guides/plugins/apps/app-base-guide.html](https://developer.shopware.com/docs/guides/plugins/apps/app-base-guide.html "‌")
- [https://developer.shopware.com/docs/guides/plugins/apps/lifecycle/webhook.html](https://developer.shopware.com/docs/guides/plugins/apps/lifecycle/webhook.html "‌")
- [https://developer.shopware.com/docs/resources/references/app-reference/manifest-reference.html](https://developer.shopware.com/docs/resources/references/app-reference/manifest-reference.html "‌")

Install: Shopware app using `manifest.xml`, registration handshake and declared permissions.

Use documented Admin API entities for orders, products, promotions and customer data where authorized.

Declare webhooks in the app manifest. Example format:
`<webhook name="product-changed" url="https://shopbonus.example/webhook" event="product.written"/>`

Determine the actual order-state and refund-relevant event names from the current webhook reference before subscribing.

Banners: documented Shopware CMS/app extension mechanism.

Verify app-request signatures and credentials according to the Shopware app lifecycle.

## 10. ADOBE COMMERCE / MAGENTO

Docs:

- [https://developer.adobe.com/commerce/extensibility/events/](https://developer.adobe.com/commerce/extensibility/events/ "smartCard-inline")
- [https://developer.adobe.com/commerce/extensibility/webhooks/](https://developer.adobe.com/commerce/extensibility/webhooks/ "smartCard-inline")
- [https://developer.adobe.com/commerce/extensibility/app-management/installation/webhooks](https://developer.adobe.com/commerce/extensibility/app-management/installation/webhooks "smartCard-inline")

Install: compatible Adobe Commerce app/module for the merchant deployment model.

Use Adobe Commerce REST/GraphQL for order/catalog details, subject to integration permissions.

Prefer asynchronous Adobe I/O Events for non-blocking order synchronization where supported.

IMPORTANT: Adobe's synchronous Commerce Webhooks can participate in transaction processing. Do not use them for slow ShopBonus ingestion when asynchronous events are suitable.

Configure relevant subscriptions, payload fields, signatures/authentication, retries and replay handling.

Banners: approved Commerce module/storefront component, compatible with the installed storefront architecture.

Test Adobe Commerce SaaS/PaaS/on-premises differences before declaring feature support.

## SHARED PROVIDER MAPPING

For each provider create a mapping specification:

- `shop_id` ← provider store identity
- `external_order_id` ← provider order ID
- `order_reference` ← human-readable reference
- `customer_first_name` ← authorized order/customer field
- `customer_email` ← authorized email field
- `customer_phone` ← optional; null when missing
- `total_minor` ← normalized amount/currency
- `payment_status` ← verified provider status
- `refund_minor` ← verified refund total
- `products[]` ← ID, SKU, quantity, eligible amount
- `source_event_id` ← provider event identity
- `event_created_at` ← provider timestamp

Document exact provider JSON paths, required permissions and one redacted sample per platform. Distinguish unavailable fields from empty fields.

No invented values, no automatic contact authorization and no cashback merely because an order exists.

## CONNECTION QUALITY GATES

A connector can be `CONNECTED` only after:

1. Authorization is verified.
2. Merchant/store identity matches.
3. A genuine order is received or retrieved.
4. Required order fields are correctly mapped.
5. Payment status can be reliably verified.
6. Duplicate processing is prevented.
7. Changes/refunds have a tested recovery path.

Otherwise use `LIMITED`, `SETUP_REQUIRED` or `ERROR` with the exact reason.

Feature capabilities must be individually verified; a connected order integration does not prove coupon-write or banner-publish capability.

## API AND OPERATIONS

Reuse the central versioned ShopBonus API and Laravel backend.

Create an internal provider capability registry containing:
`platform`, `version`, `auth_method`, `scopes`, `webhook_topics`, `order_api`, `refund_api`, `product_api`, `coupon_api`, `banner_method`, `rate_limit_policy`, `last_verified_at`.

Maintain official doc URLs and a reviewed date for each adapter.

Security: signatures, tenant isolation, least-privilege scopes, encrypted credentials, rotation, CSRF/session protection, Laravel Policies, 403 audit, idempotent queues and sanitized logs.

Monitor provider latency, authentication failures, missed events, sync age and capability regressions.

## TEN E2E TESTS

1. Shopify: OAuth, real paid order, verified webhook, protected fields and banner activation.
2. WooCommerce: REST connection, signed order event, product import and refund synchronization.
3. Wix: verified JWT event, correct order retrieval and no duplicate processing.
4. BigCommerce: created-but-unpaid order is imported but never rewarded; paid-status update is verified.
5. Ecwid: authorized token, real order webhook, full API lookup and missed-event recovery.
6. Lightspeed C-Series: plan/API capability check and honest `LIMITED` state where order proof is incomplete.
7. PrestaShop: install ZIP module, receive paid-order update, publish banner and uninstall cleanly.
8. OpenCart: install compatible extension, verify order-status event and remove hooks on uninstall.
9. Shopware 6: manifest registration, authenticated webhook, order lookup and capability reporting.
10. Adobe Commerce: authenticated order event, asynchronous processing, refund reconciliation and no checkout blocking.

For EVERY test capture the provider request/response, mapped order, auth/signature outcome, UI status and sanitized audit evidence.

## DEFINITION OF DONE

Ten platform integration specifications exist with official sources, exact verified APIs, scopes, event payloads and capability matrices.

Adapters are only marked production-ready when genuine provider-backed tests pass. Unsupported capabilities are explicitly disclosed. The existing ShopBonus order pipeline, wallet, Order Sheet and Answer24 workflows remain intact.
