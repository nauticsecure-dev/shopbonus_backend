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

B85 recheck (2026-11-04, parent2e7da03): the official [Order resource](https://developers.lightspeedhq.com/ecom/endpoints/order/) documents not_paid/partially_paid/paid/cancelled paymentStatus and no cash-refund mapping. Official [C-Series refunds](https://retail-support.lightspeedhq.com/hc/en-us/articles/228840187-Refunding-eCom-C-Series-orders) explicitly separates credit invoices from refunding via the payment provider. The unknown cash/time mapping remains intentional and incomplete, not a missing arithmetic fallback. Existing negative regression passed again with the Adobe/BigCommerce integration regressions. No PSP account/transaction-binding contract or genuine refund evidence was established; no financial behavior changed.

Recheck 2026-11-05 (Claude, branch `review-fixes`, after reviewer finding "Lightspeed C refund cash mapping remains missing"): the developer reference was read directly. [Invoice](https://developers.lightspeedhq.com/ecom/endpoints/invoice/) has `isCreditNote`/`creditNote`, negative `priceIncl`/`priceExcl` on credit notes, `status` `not_paid|paid`, `createdAt`, and an `order` link; the [Order](https://developers.lightspeedhq.com/ecom/endpoints/order/) links invoices via `invoices?order={id}` and documents no refunded amount, no `credits` link, and `paymentStatus` `not_paid|partially_paid|paid|cancelled`; [OrderCredit](https://developers.lightspeedhq.com/ecom/endpoints/ordercredit/) only creates a credit invoice; [Return](https://developers.lightspeedhq.com/ecom/endpoints/return/) has `status` including `refunded` and `priceIncl`, no currency. None of these carries a PSP refund ID, settlement time or confirmation that money was returned, and the credit-invoice `paid` status is the manually selectable label described above. So the finding is accepted as **blocked on a provider/PSP refund contract**, not fixed: `refundedMinor`/`refundedAt` stay null by design and capability `refunds` stays LIMITED (`refund_amounts_mapped: false`). Mapping credit notes would risk reporting refunds that were never paid out.
