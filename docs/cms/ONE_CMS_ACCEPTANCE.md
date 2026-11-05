Current backend follow-up (2026-11-04): B79/B80 media and saved-page bindings, B81 unified AI/budgets/independent human review, B82 durable backlog/content release gate and B83 [FAQ/media/shared-text management](CENTRAL_MANAGEMENT.md) are implemented at their recorded tested checkpoints. Remaining statements below are historical unless explicitly carried forward. German frontend dictionaries, real responsive rendering, universal other-source discovery, actual23 staging executors/provider quality and independent release acceptance remain incomplete. Backend-only source completion does not establish full-card PASS.

B82 follow-up: [Durable saved-page backlog and recommended coverage gate](BULK_AND_COVERAGE.md) add bounded1000+ planning and mandatory five-denominator/T01–T23 proof evaluation to controlled staging commands. Definition/mapping ledger is not23 implemented browser executors; actual staging/front-end/provider evidence remains UNMAPPED/UNTESTED.

B81 follow-up: [Unified queued AI, monetary reservations and independent human language/meaning review](AI_EXECUTION.md) replace legacy page Chat Completions clients. Human review is not an automated semantic/language classifier or proof of live-model quality. Bulk processing and content-specific coverage/execution remain incomplete.

B80 follow-up: [Saved backend field/source bindings, per-usage immutable recipe pins and component-context media manifests](CONTENT_BINDINGS.md) extend the [B79 original/recipe/derivative/restore pipeline](MEDIA.md). These backend additions supersede earlier “no image pipeline” statements. Universal frontend/other-source discovery, actual responsive component rendering, AI/bulk safeguards and T01–T23 staging acceptance remain incomplete.

# One inline CMS, NL/EN/DE and GPT-6 translation quality

Status: **PARTIAL implementation; NO-GO for the requested release acceptance**.

Latest local follow-up (2026-11-03):
[structured bindings and German locale evidence](STRUCTURED_BINDINGS_FOLLOWUP.md).
Structured extraction and shared DE UI are now partly implemented. Two existing
CMS migrations were applied to local MySQL for the authorized local login checks.
The earlier checkpoint below remains historical; T01–T23 staging status remains
UNTESTED and the image pipeline/coverage/release gaps remain open.
Owner brief: pasted requirements supplied 2026-11-02, SHA256
`e178fffa9e52dfb955e0268c62edd9375d92ee223dd2430b6d94940aa34433f3`.
Backend: HassanDev, base `926ab34`; frontend: HassanDev, base `5abab50`.
Changes are uncommitted. No deployment or persistent application database migration.

## Implemented checkpoint

- Reused `CmsSuggestions`, `CmsService`, existing suggestion routes/tables, revisions,
  quotas, jobs and audit. Added provenance columns through one pending migration.
- The revision-backed suggestion flow defaults to configurable `gpt-6.1-sol`, uses
  Responses `text.format` strict JSON and `store:false`, and remains disabled until
  `CMS_AI_ENABLED` and an API key are configured. No live provider call was made.
- Requests enqueue on database connection / `cms-translations`; poll existing
  `GET /api/v1/admin/cms/suggestions/{suggestion}`. Different idempotency keys reuse
  pending work for the same source revision and target revision/locale. The quota
  row serializes deduplication before charging a request. Duplicate job deliveries
  claim the queued row once. One attempt, 45-second job timeout, 30-second HTTP
  timeout, 24,000 source characters, 200 segments, 4,000 output tokens, capped
  daily request count. Ambiguous timeouts are not silently retried or recharged.
- NL draft saves request EN/DE proposals when enabled. Deferred translation work
  is audited and cannot undo an already committed human draft. This is not a
  1,000-record batch/backfill executor or automatic publication.
- Worker checks active Super Admin authority and source revision/hash. Apply
  checks source and target freshness. Legal pages are excluded from this flow.
  Applying a proposal creates a draft through the existing optimistic-lock path.
- Independent per-segment checks reject changed/removed/duplicated facts and
  placeholders, brand changes, negation changes, new payout language, HTML,
  overlong/empty text and obvious untranslated long segments. Provider identity,
  source hash, locale, review flag, warnings and integer token usage are validated.
  Email/obvious secret patterns are blocked before transmission. Tokens/model/hash
  are retained in the suggestion and proposal audit; failure audit contains no
  provider response or source text.
- CMS pages, schedules and FAQ records accept NL/EN/DE; other locales are rejected.
  Built-in landing seeds remain NL/EN: English defaults are not inserted as German.
  Missing DE appears in the existing Content Studio translation report.
- Immutable publications record their NL master draft revision. A same-second NL
  edit invalidates translated publication freshness. Unknown historical provenance
  is stale. Restore preserves the old master revision rather than claiming a new
  review. The frontend translation-status contract accepts DE and `stale`.

These deterministic checks are rejection filters, **not language identification,
semantic verification, human approval, or Quality Center PASS evidence**.

## Executed evidence

- Backend SQLite: CMS feature tests, API OpenAPI coverage and CMS unit tests;
  **81 passed, 8,378 assertions**. Targeted PHPStan/Pint and frontend typecheck
  passed. Exact source/evidence hashes and execution limits:
  [TRANSLATION_EVIDENCE_MANIFEST.json](TRANSLATION_EVIDENCE_MANIFEST.json). Machine-readable
  assertions: [translation-regression.junit.xml](translation-regression.junit.xml).
- Targeted PHPStan and Pint executed; final results recorded in HANDOFF.
- Eleven real loopback HTTP exchanges: [TRANSLATION_HTTP_CAPTURE.json](TRANSLATION_HTTP_CAPTURE.json).
  PHP 8.4.25, isolated synthetic SQLite records, synthetic Sanctum actor and
  recent-password session injected by a temporary fixture router. Actual routing,
  authorization and controller/service code executed. No real login, cookie,
  CSRF or staging browser verification is implied. Queued 201, deduplicated 200,
  missing/stale translation 200, anonymous 401, consumer 403 and FR 422 captured.
  English fixture content was deliberately synthetic and is not translation
  quality evidence. Initial capture-server cache configuration errors were fixed
  before the retained captures. Temporary router/server/database are removed.
- Frontend: only the translation-report TypeScript contract was changed. No visual
  editor or German frontend implementation is claimed.
- QA acceptance: no staging UI/DB/storage/render/audit journey was executed.
  Unit tests with provider stubs do not satisfy the owner's T01–T23 evidence gate.

## Verified gaps and next implementation boundary

1. `CmsSuggestions` currently extracts generic rich-document/title/SEO segments.
   The three structured landing schemas have additional editable fields requiring
   canonical field-path discovery and translation; they must not be counted as
   translated. Existing Homepage/AboutUs legacy AI helpers still use a separate
   configuration and Chat Completions; consolidating those callers into the
   revision-backed queue remains required. Automatic jobs on unsupported document
   shapes fail safely and preserve approved content.
2. Frontend application locales/dictionaries and language selector remain NL/EN.
   German content editing/review, portal labels, emails, notifications, SEO/slugs,
   and context-preserving locale journeys need implementation and verification.
3. Existing inline wrappers are page-specific; no full route/key/field/state/role
   binding inventory or universal text/media interaction has been proven. FAQ
   optional subcategories and the complete rich block/editor workflow remain open.
4. Current CMS asset upload stores an original with dimensions. The requested
   non-destructive editor, usage-specific transformations, private-original/public
   derivative separation, WebP workers, real component previews, library impact
   controls and pristine image restore proof remain open. Banner Studio untouched.
5. Quality Center evidence/provenance services are reused and unchanged. Five
   exhaustive content coverage denominators and the 23 staging executors are not
   implemented by this checkpoint. Their totals are **unknown**, not zero or 100%.
6. Monetary cost caps, durable deferred backlog recovery, provider retry policy,
   batch 1,000+ record backfill, full PII screening, glossary administration,
   independent semantic/language review and deployment/nightly gates remain open.
   Daily request/input/output bounds are not a monetary budget guarantee.

Next: add canonical structured field extraction/bindings to the existing CMS
flow, migrate legacy AI callers, wire queued review in the existing editor, then
extend the existing asset pipeline and Quality Center with actual per-field
executors. Provision an explicit staging URL and authorized test actors before
any mutating acceptance run; production remains read-only.

## Mandatory staging ledger

All entries are **UNTESTED**: no qualifying staging execution exists in this chat.
Local tests above provide supporting regressions only.

| ID | Required staging proof | Status |
| --- | --- | --- |
| T01 | Exhaustive route/locale/role/state/content/image discovery | UNTESTED |
| T02 | Hardcoded visible copy injection detected and CI fails | UNTESTED |
| T03 | Translated t() without binding detected | UNTESTED |
| T04 | Hero edit, exact DB/reload/audit/restore and field isolation | UNTESTED |
| T05 | FAQ category/subcategory/answer, order, visibility, locale isolation | UNTESTED |
| T06 | Exit editing restores normal navigation | UNTESTED |
| T07 | Contact email display and mailto update together | UNTESTED |
| T08 | Blog/case rich text/blocks/SEO/schema/sitemap/revisions | UNTESTED |
| T09 | NL change produces validated GPT-6 EN/DE review drafts/cost evidence | UNTESTED |
| T10 | Reject token, voucher meaning, negation and payout mutations | UNTESTED |
| T11 | Locale changes preserve shop/wallet/claim/order/codes | UNTESTED |
| T12 | Merchant/tenant isolation and real 401/403/419 | UNTESTED |
| T13 | Legal versions/consent, localized email/SEO/cache publication | UNTESTED |
| T14 | Outage, monetary budget cap and duplicate-job safety | UNTESTED |
| T15 | Changed source invalidates old evidence and reruns impacted tests | UNTESTED |
| T16 | JPG transformations, private original and responsive WebP | UNTESTED |
| T17 | Actual component text/crop/focus preview at three sizes | UNTESTED |
| T18 | Usage-specific changes, replace-here/everywhere, safe delete | UNTESTED |
| T19 | Cancel/reset/restore with identical storage/DB/page recovery | UNTESTED |
| T20 | Upload/worker/storage/CSRF/oversize failure preserves publication | UNTESTED |
| T21 | Every media/ALT edit audited; added locales invalidate missing ALT | UNTESTED |
| T22 | Queued 1,000+ content/AI/SEO/GEO records and review backlog | UNTESTED |
| T23 | Safe rich text/forms/video/download/consent-script save/render | UNTESTED |

## Deployment notes

Apply the pending `2026_11_02_120000_add_cms_suggestion_provenance` migration only
in the approved deployment workflow. It adds nullable suggestion model/hash/token
columns and a nullable immutable revision master-source reference. No historical
content or legal acceptance is rewritten.

Worker: `php artisan queue:work database --queue=cms-translations --tries=1 --timeout=45`.
Keep database `retry_after` above 45 seconds and use bounded worker memory.
Add `cms-translations` to operational monitoring for existing deployments; the
example env now includes it. A stalled processing row is not silently recharged;
investigate failed/stuck work before explicitly requesting another proposal.

Official API contract references checked 2026-11-02:
[Structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses),
[GPT-6.1 Sol](https://developers.openai.com/api/docs/models/gpt-6.1-sol).
Deployment-account access and live translation quality remain unverified.
