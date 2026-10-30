# Mijn voordelen — API inventory

Authoritative consumer surface for wallet, vouchers, transactions, cashback and AI support.
Financial mutations always go through `WalletService` / `CheckoutService` / `AffiliateProcessor` / `DirectMerchantCashback`.

## Wallet

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/me/wallet` | Normalised alias: `available_minor`, `total_earned_minor`, `total_spent_minor`, `currency`, `unit=EUR_REDEMPTION_CREDIT`. Reuses `WalletController::summary`. |
| GET | `/api/v1/consumer/wallet` | Full per-unit balances + same `*_minor` top-level fields. **Pending cashback is always `0` for consumers.** |
| GET | `/api/v1/consumer/wallet/transactions` | Filters: `all`, `earned`, `spent`, `adjustments`, `holds`. `pending` returns empty (no consumer pending cashback). Supports `q`, pagination. |
| GET | `/api/v1/consumer/wallet/transactions/{id}` | Owned ledger entry detail. |

No withdraw / transfer / cash-out routes exist.

## Vouchers & checkout

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/consumer/vouchers` | Filters: `all`, `active`, `used`, `expired`, `cancelled`. Status labels: ACTIVE / USED / EXPIRED / CANCELLED. |
| GET | `/api/v1/consumer/vouchers/{id}` | Owner-only code reveal (audited). `can_copy_code` false when unusable. |
| GET | `/api/v1/consumer/dashboard` | Aggregate wallet + voucher KPIs. |
| POST | `/api/v1/consumer/checkouts/quote` | Backend-calculated voucher quote. |
| POST | `/api/v1/consumer/checkouts` | Atomic wallet debit + voucher allocation (idempotent). |

## Cashback / commission

| Method | Path | Notes |
|--------|------|-------|
| (internal) | `AffiliateProcessor` | Daisycon / TradeTracker verified conversions → immediate credit (`AFFILIATE_CREDIT_TIMING=on_validated_attribution` default). Default share 70% consumer (`share_bps=7000`). |
| GET/POST | `/api/v1/admin/affiliate/reward-rules` | Super Admin versioned splits; optional `webshop_id` + `commission_source`. |
| GET | `/api/v1/admin/webshops/{id}/commission-agreements` | Direct-merchant agreements. |
| POST | `/api/v1/admin/affiliate/webshops/{id}/commission-agreements` | Create versioned 12%/90-10 style agreement (shares must total 100%). |
| POST | `/api/v1/admin/affiliate/webshops/{id}/commission-credits` | Ops credit of a **verified** direct conversion (never from a click). |

## AI support

| Method | Path | Notes |
|--------|------|-------|
| POST | `/api/v1/ai/chat` | `reference` = voucher public id, `current_page` = `/mijn-voordelen` or `/app/benefits`. Ownership verified server-side. |
| GET | `/api/v1/ai/conversations/{id}` | History. |
| POST | `/api/v1/ai/messages/{id}/feedback` | Existing feedback. |
| POST | `/api/v1/ai/messages/{id}/escalate` | Existing escalation. |

## Realtime

| Channel | Event | Notes |
|---------|-------|-------|
| `private-shopbonus.user.{userPublicId}.wallet` | `WalletBalanceChanged` | Invalidation only — clients must re-fetch `/me/wallet`. |
| Auth | `POST /api/broadcasting/auth` | Sanctum; proxied by Next at `/api/backend/api/broadcasting/auth`. |

## E2E coverage

`tests/Feature/Benefits/MyBenefitsE2ETest.php` — 10 scenarios (wallet KPIs, 70/30 affiliate credit, direct 10.8% cashback, click≠credit, duplicate idempotency, voucher statuses/copy, redemption debit, reversal audit, AI voucher context, Reverb channel isolation).
