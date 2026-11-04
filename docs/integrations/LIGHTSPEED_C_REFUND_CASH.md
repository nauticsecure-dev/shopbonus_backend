# Lightspeed C cash-refund boundary — B78

Status: **OPEN — authoritative cash-refund API mapping not established**. B78 adds negative regression evidence; it does not implement or certify cash mapping. Source parent `4b8626f`, branch `frank/moneybird-experiment-followup`.

Current native C-Series reads expose the verified shop/order and its documented payment status. The order resource does not establish a settled refund amount/time in this connector. `refundedMinor` and `refundedAt` remain null, not invented zero or a credit amount. Payment/refund quality gates remain limited accordingly.

Primary provider documentation distinguishes the operations:

- Outside payment providers: creating a credit invoice records the credit; the merchant separately refunds the transaction through the PSP. Credit invoice status can be manually selected as Paid/Not Paid.
- Lightspeed Payments: creating a credit invoice requires an explicit Refund switch to return money. Financial Services refunds can also occur without being reflected in other reports.
- Cancellation while awaiting capture can release a preauthorization; it is not evidence of a cash refund.

Consequently, neither credit totals, return quantities, manual paid labels, order cancellation nor undocumented order refund properties establish actual cash returned. They also cannot justify subtracting the same amount again from the original gross order total. The existing connector does not claim that it ingests authoritative PSP refund transactions.

Executed: `LightspeedCConnectorTest` now injects synthetic credit/return/paid/refunded claims and verifies cash/time remain unknown, original5035minor units remain unchanged, and undocumented paymentStatus=refunded remains Unknown. Combined Adobe/package/Runtime/C-Series run: **15PASS/254 assertions**. The test values do not claim provider API field mappings. No C-Series runtime/API/config/financial behavior changed.

To complete the mapping, establish the actual merchant payment-provider read contract: native order/payment reference binding, authorized merchant account, unique refund IDs, successful cash status, exact currency/amount and completion timestamp, with complete pagination/partial-read handling. Map cumulative unique successful refunds without adding credit invoices again; keep incomplete/ambiguous evidence unknown and re-use existing idempotent order reconciliation. Native C-Series credits and PSP cash must remain separate. No guessed endpoint, field, credential or confirmation flag is introduced here. Genuine PSP/native order/refund acceptance is NOT RUN.

Sources reviewed2026-11-04:

- https://ecom-support.lightspeedhq.com/hc/en-us/articles/229806867-Refunding-orders-for-outside-payment-providers
- https://ecom-support.lightspeedhq.com/hc/en-us/articles/1260800115029-Refunding-orders-for-Lightspeed-Payments
- https://ecom-support.lightspeedhq.com/hc/en-us/articles/12011036864155-Managing-the-Klarna-app
