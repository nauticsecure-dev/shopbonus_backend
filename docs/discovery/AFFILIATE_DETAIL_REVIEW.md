# Affiliate detail, enrichment and cashback review

## Current review follow-up — 2026-11-03

Whole-card acceptance remains PARTIAL. See [current verification](AFFILIATE_DETAIL_FOLLOWUP_2026-11-03.md)
for reproduced runtime results, storage failure fixes and remaining acceptance.
The two reported cover failures did not reproduce on installed PHP 8.4.25 or
8.5.0. This establishes local fixture behavior only. Provider normalization,
approved website assets, genuine synchronization/settlement and complete visual
and real-service journeys remain unfinished.

The November 1 timing description below is historical: the owner's November 2
decision supersedes it for new rewards with `on_validated_attribution`.
Frozen historical settlement/approval rules retain their timing. This follow-up
does not change reward policy or establish live acceptance.

## Historical checkpoint — 2026-11-01

2026-11-01. Local implementation verified; whole-card acceptance PARTIAL.
Backend HassanDev, base 59916e94c26cada8696939845e40deca6d1d4697.
Existing unrelated and task-related uncommitted changes were preserved.
No commit, push, application-database migration or deployment was performed.

## Findings and behavior

1. New rewards snapshot `on_network_settlement`, regardless of legacy instant
   configuration. Approval alone leaves cashback pending. Release requires
   independently recorded settlement matching the conversion, commission and
   currency; fraud/threshold review still applies. Explicit historical timing
   snapshots retain their prior behavior. SettlementRecorder authorizes an
   active Super Admin and records immutable evidence and an audit. Customer
   wording distinguishes approval, received payment and checkout holds.
2. AffiliateRewardPolicy supplies network-source rule selection and calculation
   to public estimates and processing. No applicable rule means no advertised
   cashback or reward. Points, foreign currency, caps and minimums do not
   produce a misleading flat percentage.
3. Combined classification requires permission, reference, terms, network,
   account, program, verifier and validity dates. Merchant requests cannot supply
   permission. The audited operator command creates a new offer version;
   merchant edits and duplication clear permission. Issued terms are preserved.
4. Daisycon snapshots paginate until empty or an explicit total is satisfied,
   including server-capped short pages. Duplicate IDs, inconsistent totals,
   oversized snapshots and page bounds fail closed before ingestion. Failed
   snapshots preserve the sync watermark; retries replay with seven-day overlap
   and idempotent conversion IDs. Recovery restarts the bounded snapshot rather
   than persisting an unsafe mid-snapshot page cursor.
5. The SOAP override includes PHP 8.5's optional uriParserClass parameter.
   Actual TradeTracker transport fixtures passed on installed PHP 8.5.0.
6. Separate promotions, commissions and access-rule reads are queued through
   shopbonus:affiliate-evidence. Durable resource snapshots retain their last
   complete payload on failure and retry independently. Raw imported conditions
   never grant cashback permission automatically. Normalized provider-to-offer
   mappings, comprehensive exclusions and live account acceptance remain OPEN
   until verified provider responses establish their semantics.
7. Research follows up to three relevant same-origin About/category/contact
   pages using the public-DNS-pinned fetcher and retains bounded image/logo URL
   and alt-text evidence. Candidates are not downloaded or approved assets.
   Published profiles become refreshable when stale. Research can be retained
   without AI credentials; generation then records a configuration failure.
   Fixed evidence-ID collisions when homepage metadata is absent, which had
   overwritten linked-page/image facts.
8. Generated JPEG/PNG/WebP bytes are decoded, bounded by dimensions/pixels,
   transcoded to WebP and stored at 640/960/1536 widths without upscaling.
   Failed partial writes are cleaned up. Arbitrary bytes cannot acquire a WebP
   MIME label. The cover route serves supported variants or the original.
9. Detail actions reveal claimed codes inline, allow copying and shop through
   the tracked visit route. Unavailable codes are disabled; guests sign in with
   a return URL. NL/EN UI, categories and dates use the active locale. The
   official-site link explains that it does not establish attribution. Consent
   updates refresh tracked links; slug navigation remounts detail state.

## Operator commands

Review the underlying bank/remittance evidence and advertiser permission first:

```text
php artisan shopbonus:affiliate-settlement CONVERSION_ID --actor=SUPER_ADMIN_ID --reference=REMITTANCE_REFERENCE --settled-at=RECEIVED_DATE
php artisan shopbonus:affiliate-promotion-permission OFFER_ID PROGRAM_ID --actor=SUPER_ADMIN_ID --reference=ADVERTISER_REFERENCE --terms="Applicable code, eligibility and exclusions" --valid-until=EXPIRY
php artisan shopbonus:affiliate-evidence CONNECTION_ID
```

IDs are public IDs. These commands record reviewed evidence; they do not
independently prove that money arrived or that permission was granted.
Pending schema changes in the working tree are
2026_10_31_190000_add_affiliate_settlement_and_promotion_evidence.php and
2026_10_31_190100_create_affiliate_provider_resources.php. Review/apply them
through deployment before using the commands. Only isolated databases migrated.

## Executed evidence

- PHP 8.5.0 / SQLite, Affiliate + Enrichment + WebshopDiscoveryE2E + OpenAPI:
  **81 tests, 80 passed, 1 skipped, 5721 assertions**, 46.640 seconds.
- Final OpenAPI recheck: **3 passed, 5123 assertions**.
- Targeted PHPStan: zero errors. Targeted Pint and whitespace checks pass.
- Frontend TypeScript and targeted ESLint pass. Chromium fixtures: **3 passed**
  (Dutch desktop reveal/tracked link/sold-out, English mobile labels/date/no
  overflow, guest sign-in without claiming inventory). Screenshots inspected.
  Browser API responses are controlled fixtures, not live-provider proof.
- Real local HTTP captures: [AFFILIATE_DETAIL_HTTP_CAPTURE.json](../api/AFFILIATE_DETAIL_HTTP_CAPTURE.json),
  reproduced by scripts/capture-affiliate-detail.php using isolated synthetic
  SQLite records. Temporary server/database removed. Captures include public
  detail, pending-wallet list, English affiliate notice and missing cover.
- Broader Offers + affiliate detail + OpenAPI: **44 tests, 42 passed, 1 skipped,
  1 failed**. Existing VoucherOperationsController::presentVoucher accesses a
  missing Voucher.claimed_at in WebshopDashboardOffersE2ETest::test_e2e7_*
  (line 250). That unrelated controller was preserved; whole-repository green
  is not claimed.
- Installed PHP 8.5 emits an Xdebug DLL/version startup warning. Tests and SOAP
  transport still pass. Host PHP configuration was not changed.

## Remaining acceptance

No authorized provider account or real AI call was used. Verify endpoint aliases,
response schemas, transaction parts, commission/status mappings, promotion
conditions and account-specific permissions before production sync. Existing
legacy singular /publisher/... program/transaction paths remain live-unverified:
the current resource listing uses plural /publishers/..., while the FAQ's
transaction example uses singular /publisher/.... Do not infer equivalence.

Official references checked:
[Daisycon resources](https://docs-publisher.daisycon.com/),
[pagination example](https://faq-publisher.daisycon.com/hc/en-us/articles/31466185933201-Daisycon-API-Sandbox-and-Example-Code),
[PHP SOAP signature](https://www.php.net/manual/en/soapclient.dorequest.php).

The reference image was not attached, so pixel-close matching remains unverified.
MySQL/concurrency, real authenticated browser journeys, external attribution,
live settlement reconciliation and staging acceptance are NOT RUN.
