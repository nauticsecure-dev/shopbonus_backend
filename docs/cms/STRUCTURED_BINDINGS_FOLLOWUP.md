# Structured translation bindings and German locale follow-up

Checkpoint: 2026-11-03 (Asia/Karachi). **PARTIAL / release NO-GO.**
Backend HassanDev base `b92f46ab2b471fc4796310e059568bdddde276a4`;
frontend HassanDev base `bd76ff420cda1aa180e45e44a521ff0707670b3f`.
Changes are uncommitted alongside other ongoing work. No commit, push,
deployment, live AI request or new migration file.

## Implemented

- `CmsTextBindings` discovers explicit paths and schema-specific length limits
  for the homepage, About Us and merchant landing schemas, plus generic rich
  documents. The queue now includes those paths in model input and validates
  proposals with the actual page key/type. Previously structured copy was
  omitted and generic validation could discard sections or reject the proposal.
- Replacement changes only registered paths. Hrefs, asset references, schedules,
  visibility, ordering, platform identifiers/names, team names and contact
  identities remain outside translation input. Blank optional strings and
  decorative ALT stay blank; omitted fields are not manufactured. Structured
  media ALT and nonempty rich-image ALT are included where supported by schemas.
- Existing quotas, jobs, strict output/fact checks, legal exclusion, source/target
  freshness, revisions, audit and draft-only application remain in place. The
  request fingerprint is versioned `bindings-v2`; fresh requests do not reuse
  proposals created under the old extraction contract.
- Frontend accepts NL/EN/DE; header/account selectors expose DE. Added German
  shared dictionary, role and authentication copy, German Intl formatting, page
  editor locale selection, FAQ category/question/answer inputs and CMS hub copy.
  German omissions merge blank content rather than Dutch marketing defaults;
  a missing homepage publication displays a German unavailable state.
- Account language accepts DE, German identity email resources are present,
  and platform language validation accepts the three supported account locales.
  Existing OpenAPI locale enums were updated for affected account/profile,
  business preference and agreement template inputs. No new endpoint/envelope.
- CMS hub no longer announces all languages published before the report loads
  or when the report contains no rows. This is not exhaustive coverage evidence.

## Executed local evidence

| Check | Result |
| --- | --- |
| CMS feature/unit suite, `structured-bindings.junit.xml` | 84 passed, 939 assertions |
| Identity/profile/platform settings/onboarding/OpenAPI compatibility, `german-compatibility.junit.xml` | 126 passed, 9,204 assertions |
| Frontend Node CMS review and German locale regressions | 10 passed |
| TypeScript | Passed |
| Targeted application PHPStan | Passed, zero errors |
| Targeted Pint | Passed |
| New/changed locale data and merge helper ESLint | Passed |
| Broader touched UI ESLint | Failed: existing set-state-in-effect findings in account (two) and FAQ (one); two existing unused image-rule disables |

Provider responses in tests are stubs. Full landing defaults round-trip without
field loss; synthetic German structured proposals apply only to DE drafts and
leave NL publications unchanged. Seed inventory tests classify every populated
default string as translatable or explicitly protected. That is **not** an
inventory of every runtime route, optional field, tenant, role or state.

Real local browser, no mocked API: selected DE, reloaded the login, verified
German headings/navigation, signed in with the existing local demo Super Admin,
and opened Content Studio. After the migrations, the translation report listed EN stale / DE missing for homepage, About Us and merchant landing. Desktop CMS layout visually inspected. Browser
screenshots were inspected in-session; no durable browser evidence bundle or
full edit/publish/restore journey is claimed. No real consumer data was edited.

Initial login against port 3025 failed because the configured CORS/stateful
origin is localhost:3000. Retested using an isolated `.next-cms-de` server on
port 3000 without changing the allowlist. Translation report initially returned
500 because the local database lacked CMS suggestion tables/provenance. The
first targeted provenance migration failed before adding columns because its
prerequisite table did not exist. Then applied these **existing** migrations to
local MySQL only, successfully, in order:

1. `2026_10_29_140000_create_cms_schedules_and_suggestions`
2. `2026_11_02_120000_add_cms_suggestion_provenance`

No historical content was rewritten, no AI setting enabled and no publication
changed. An unrelated local notification endpoint also reports a missing
`merchant_notifications.resolved_at` column; no unrelated migration was applied.

Initial new tests exposed an invalid fixture anchor for the homepage schema and
an assumption that every schema enumerated fields in the same order. Fixtures
now use allowed paths and canonical discovery order. Initial PHPStan redundant
type checks were removed; retained evidence is the passing rerun.

## Remaining release work

1. German coverage is partial: page-specific editors, cockpit, support, consent,
   notifications, legal journeys, SEO/slugs and many public page labels still
   contain NL/EN-only text. Request-locale middleware and general validation
   messages still negotiate NL/EN. Identity mail DE does not prove all email DE.
2. Homepage/About Us legacy synchronous Chat Completions helpers remain. Move
   their callers and review UI to the existing queued suggestion contract.
3. Image transforms/workers/private originals, component previews, usage impact
   controls and exact image restore remain unimplemented by this checkpoint.
4. Universal canonical content bindings and five exhaustive coverage denominators
   remain incomplete. No 100% coverage claim; optional-field/schema drift needs
   ongoing registry maintenance and independent runtime discovery.
5. Monetary AI caps, durable deferred recovery, independent semantic/language
   validation, broader sensitive-data screening and 1,000-record execution remain
   open. Deterministic guards are rejection filters, not semantic approval.
6. FAQ subcategories, reusable/shared blocks, centralized media management,
   nightly crawling and deployment gates remain open.
7. T01–T23 remain UNTESTED for the mandatory staging gate. User authorized local
   login users; local regressions and UI observations do not qualify as staging
   edit/restore, storage, audit or live-provider acceptance.

Next coherent boundary: migrate legacy AI callers and editor review to the
revision-backed queue; finish German page/editor/consent/support journeys;
implement the media pipeline and actual component preview with usage bindings;
then run per-field local edit/restore executors before staging acceptance.
