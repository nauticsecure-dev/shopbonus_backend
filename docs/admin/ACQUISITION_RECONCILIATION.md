# Voucher acquisition reconciliation

The cockpit trend uses `consumerAccounts()`: consumer onboarding intent, no active platform role and no configured test-account public ID. Webshop membership does not remove consumer identity. Trend points count registrations in the selected business-timezone date window; current active-account KPIs additionally apply their documented status filter.

An approved transaction belonging to the same consumer/shop is necessary but **not sufficient** evidence that it acquired a specific voucher. Wallet spending, an available balance, the latest conversion, shared timestamps and a network's share of commissions must never be used to invent the link. Without independently reviewed evidence, leave the voucher Other/Unknown.

Where a provider record or independently reviewed reconciliation case explicitly links the voucher acquisition and conversion, an authorized deployment-console operator can record that existing evidence:

```bash
php artisan shopbonus:voucher-acquisition <VOUCHER_PUBLIC_ID> <TRANSACTION_PUBLIC_ID> \
  --actor=<VERIFIED_ACTIVE_SUPER_ADMIN_PUBLIC_ID> \
  --reference=<REVIEWED_ACQUISITION_CASE_REFERENCE> --verified-link
```

Console access is the operational authorization boundary; the supplied actor identifies the responsible verified active Super Admin and is re-read server-side. This is not a public/merchant/consumer endpoint or a browser login substitute. The operator must independently establish the exact link and retain its source in the referenced case before attesting `--verified-link`. The command does not contact a provider, certify the case, backfill by heuristic or establish cash settlement. References must be bounded nonempty case IDs (≤200 bytes, no control characters); never paste secrets, personal data, full provider payloads or voucher codes.

The transaction must be approved, nonduplicate, match the voucher consumer and webshop, and predate or equal issuance. Reserved/unissued vouchers cannot qualify. The link is immutable: different-source retries fail; same-source retries produce no additional event/audit or accounting effect. The first write appends `acquisition_verified` voucher history plus `voucher.acquisition_verified` audit with actor, source public ID and case reference. Existing evidence/reference cannot be overwritten by a retry. Subsequent status, duplicate, consumer or webshop invalidation excludes the source from verified-source reporting through the existing query scope. This operation does not change wallet, commission allocation, spendability, settlement or voucher lifecycle.

Cockpit summaries retain their existing60-second cache per period; wait for expiry when reconciling a cached view. Never flush shared Redis to refresh a report.

Verify using `GET /api/v1/admin/cockpit?period=custom&from=YYYY-MM-DD&to=YYYY-MM-DD` and the returned acquisition-network voucher drill-down. A genuine operational run requires real approved source evidence; synthetic tests only prove mechanics. [B59 HTTP evidence](../qa/B59_COCKPIT_HTTP_CAPTURE.json) captures a local synthetic before/after reconciliation, custom date window and source-filtered drill-down, not provider or deployed browser acceptance. Deployed custom-period behavior, resolved actions and automatic refresh remain NOT RUN here. No frontend work is included.
