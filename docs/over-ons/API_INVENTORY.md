# About Us landing CMS APIs (`/over-ons`)

Structured `landing` CMS page with stable key `over-ons` and type `landing`. Public route on the Next app: `/over-ons`.

Authorization: Super Admin (`platform:super_admin`). Mutations require `password.recent`. Guests have read-only access to **published** content.

Info-type CMS pages may historically use key `over-ons` with `type=info`; those use the generic info schema. Only `key=over-ons` **and** `type=landing` use this structured About Us schema.

## Public

| Method | Path | Auth | Notes |
|--------|------|------|--------|
| `GET` | `/api/v1/content/over-ons?locale=nl\|en` | none | Published only, `CmsCache` key `over-ons:{locale}`, `Cache-Control: public, max-age=60` |
| `GET` | `/api/v1/content/pages/over-ons?locale=` | none | Alias of the above |

**404** when unpublished / outside schedule / page missing.

## Admin (super_admin)

| Method | Path | Notes |
|--------|------|--------|
| `GET` | `/api/v1/admin/content/over-ons?locale=` | Draft + published + `draft_revision` |
| `PUT` | `/api/v1/admin/content/over-ons` | Full draft `{ revision, content }` — optimistic lock **409** on stale revision |
| `PATCH` | `/api/v1/admin/content/over-ons/sections/{sectionKey}` | `{ revision, section }` |
| `POST` | `/api/v1/admin/content/over-ons/preview` | Draft preview, `private, no-store` |
| `POST` | `/api/v1/admin/content/over-ons/publish` | Publish locale; flushes `CmsCache` |
| `GET` | `/api/v1/admin/content/over-ons/versions` | Revision history |
| `POST` | `/api/v1/admin/content/over-ons/restore` | `{ revision_id }` |
| `POST` | `/api/v1/admin/content/over-ons/schedule` | `{ revision, publish_at?, unpublish_at? }` |
| `POST` | `/api/v1/admin/content/over-ons/ai-suggest` | Suggestions only; never applied; **503** when disabled |
| `POST` | `/api/v1/admin/content/over-ons/assets` | Image upload (`image` file) |

Aliases under `/api/v1/admin/content/pages/over-ons/*` mirror the same handlers.

### Error behaviour

| Status | When |
|--------|------|
| **401** | Unauthenticated admin call |
| **403** | Authenticated but not Super Admin |
| **404** | Unknown locale / missing published page / unknown revision |
| **409** | Stale `draft_revision` |
| **422** | Validation (HTML, unsafe href, banned wording, unknown section, purchase-amount cashback claims) |
| **500/503** | Storage / AI unavailable |

Audit events: `cms.page_created`, `cms.draft_saved`, `cms.published`, `cms.restored`, `cms.asset_uploaded`.

## Content schema

Sections: `hero`, `story`, `revenue_model`, `values`, `team`, `contact`, `bottom_cta` plus meta `nav`, `footer`, `section_order`, `section_visibility`, `schedule`, SEO fields.

Team defaults: `show_profiles=false`, `profiles=[]` — never seed fake people. Company legal fields (`legal_name`, `address`, `kvk`, `email`) default to empty strings; never invent KvK/address.

## Financial accuracy rules

- `revenue_model.consumer_percent` / `platform_percent` are **not** authoritative CMS fields. They are stripped on validate and injected on read from `config('shopbonus.affiliate.default_consumer_share_bps')` (default `7000` → 70/30).
- Copy must never claim cashback as a percentage of purchase/order amount (banned regex). The 70/30 split refers to the applicable **commission**, not the aankoopbedrag.
- Empty `contact.email` is enriched from PlatformSettings published `public.support_email` on read.

`CmsService::ensureAboutUs()` creates NL+EN defaults (slugs `over-ons` / `over-ons-en`).

## Seeders / tests

- `AboutUsSeeder` — local/testing ensure + publish
- `tests/Feature/Cms/AboutUsCmsTest.php`
