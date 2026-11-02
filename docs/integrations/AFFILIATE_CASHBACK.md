# Affiliate cashback discovery and wallet pending

Public discovery exposes affiliate consumer cashback (never raw network commission) alongside voucher offers.

## Consumer cashback label

- Selected program: `ProgramSelector::current()` on an active, cashback-allowed, webshop-linked `AffiliateProgram`.
- Rate: `commission_value_bps × share_bps / 10_000_000` using the active `AffiliateRewardRule` (default 70% consumer / 30% platform).
- Copy: `Tot X% cashback` when the program is category-restricted; otherwise `X% cashback`.
- Direct merchant agreements (`MerchantCommissionAgreement`) still win over affiliate labels on cards.

## Webshop detail fields

| Field | Meaning |
| --- | --- |
| `benefits[]` | Affiliate cashback benefit plus offer-backed benefits |
| `offers[].offer_kind` | `cashback` (synthetic), `discount_code`, `combined`, or `voucher_purchase` |
| `offers[].cta_hint` | `shop_and_earn`, `view_code_and_shop`, or `claim_both` |

`combined` is only used for combinable promotional-code offers when affiliate cashback is available. Wallet-purchase offers are `voucher_purchase` and are sorted after cashback/discount cards.

## Wallet API

- `GET /api/v1/consumer/wallet` and `GET /api/v1/me/wallet` include `pending_cashback_minor` and `reserved_minor`.
- Pending sums open `WalletPendingCredit` rows; it is visible but not spendable (`available_minor` excludes it).
- `GET /api/v1/consumer/wallet/transactions?filter=pending` lists pending affiliate credits.

## Operations

- `php artisan shopbonus:sync-affiliates` pulls programs and conversions via `AffiliateSync` for connected/limited networks.
- Scheduled hourly in `routes/console.php`.

## Credit timing

- Default `AFFILIATE_CREDIT_TIMING=on_network_approval` (D-089): pending, not spendable, until the network approves. `on_validated_attribution` credits immediately after attribution and is off.
- `on_network_approval` holds rewards in pending until the network approves (legacy/admin override).

## Tests

`tests/Feature/Affiliate/AffiliateCashbackOffersE2ETest.php`
