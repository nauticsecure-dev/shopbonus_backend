# Provider promotion evidence review (B74)

Raw `affiliate_provider_resources` payloads are evidence only. The new operator workflow consumes them into a pending, immutable source/mapping/normalized review, then explicitly approves or rejects it. No automatic schema guesses, permission grants, network eligibility, offer activation, reward rules or wallet changes occur. Current backend scope is stored Daisycon promotions/access-rules with an explicit matching native program field. A resource that lacks the required fields cannot pass this mapping workflow.

Run migrations, then resync legacy evidence: snapshots must have the new fetch-time `credential_hash`, no fetch error, and a timestamp within seven days. In-flight fetches cannot publish an old-account snapshot after credentials change. Old snapshots without credential binding remain unreviewable until refreshed.

A trusted operator with current active Super Admin authority supplies a local mapping file (16 KiB maximum). The following names/paths are **synthetic examples, not claims about native provider fields**. Inspect the actual authorized stored record and map its existing fields:

```json
{"resource":"promotions","record":"/0","program":"/program_id","conditions":"/conditions","exclusions":"/exclusions"}
```

Pointers select one record, then fields relative to that record. JSON pointer `~0`/`~1` escaping is supported. The program must exactly match the selected program's native ID and offer's webshop. Conditions/exclusions must be explicit bounded plain text or lists of plain text. Empty exclusion lists mean explicitly none; absent/null/malformed exclusions mean unknown and block approval. Provider HTML requires a separately reviewed plain-text source, not silent tag stripping. This boundary does not parse prose into automated product/category eligibility rules.

```sh
php artisan shopbonus:promotion-evidence propose OFFER_ULID --actor=ADMIN_ULID --program=PROGRAM_ULID --file=/absolute/source-map.json
php artisan shopbonus:promotion-evidence show REVIEW_ULID --actor=ADMIN_ULID
php artisan shopbonus:promotion-evidence approve REVIEW_ULID --actor=ADMIN_ULID --note='Reviewed selected terms and exclusions against provider evidence'
php artisan shopbonus:promotion-evidence reject REVIEW_ULID --actor=ADMIN_ULID --note='Source is incomplete or inappropriate'
```

Reviewers see exact normalized text, selected source record, source hash/time, mapping and program binding. Approval rechecks current admin, connected Daisycon authority/credentials, active same-shop program, complete/fresh unchanged snapshot and unchanged offer version under locks. It appends a new offer version with the normalized conditions/exclusions in the existing `public_description` contract; historical voucher/offer versions remain untouched. Existing combination permission is cleared and `combinable=false` so changed terms require a fresh explicit `shopbonus:affiliate-promotion-permission` decision. That separate workflow retains its own advertiser/eligibility prerequisites. Pending/rejected records cannot update offer terms. Repeated identical final decisions are idempotent; contrary decisions conflict. No new HTTP endpoint or automatic offer creation is introduced.

SQLite/MySQL security/state tests and three actual private Laravel TCP captures are recorded in `docs/qa/B74_TEST_RESULTS.json` and `B74_PROMOTION_HTTP_CAPTURE.json`. Reproduce captures with `python3 scripts/capture-promotion-review.py`. Seeded evidence is explicitly synthetic; real Daisycon permission/promotion acceptance remains **NOT RUN**. Migration: `2026_11_04_080000_create_affiliate_promotion_reviews` (review table + nullable fetch authority hash). No frontend changes.
