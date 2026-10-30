# Webshop-owner landing CMS APIs (`/voor-webshops`)

Structured `landing` CMS page with stable key `voor-webshops`. Public route on the Next app: `/voor-webshops`. Merchant registration wizard: `/voor-webshops/aanmelden`.

Authorization: Super Admin (`platform:super_admin`). Mutations require `password.recent`. Guests have read-only access to **published** content.

## Public

| Method | Path | Auth | Notes |
|--------|------|------|--------|
| `GET` | `/api/v1/content/voor-webshops?locale=nl\|en` | none | Published only, `CmsCache`, `Cache-Control: public, max-age=60` |
| `GET` | `/api/v1/content/pages/voor-webshops?locale=` | none | Alias of the above |

**404** when unpublished / outside schedule. **422** not applicable (GET). Platform tiles are enriched from `ConnectorRegistry` so CMS cannot claim a blocked connector is `AVAILABLE`.

## Admin (super_admin)

| Method | Path | Notes |
|--------|------|--------|
| `GET` | `/api/v1/admin/content/voor-webshops?locale=` | Draft + published + `draft_revision` |
| `PUT` | `/api/v1/admin/content/voor-webshops` | Full draft `{ revision, content }` — optimistic lock **409** on stale revision |
| `PATCH` | `/api/v1/admin/content/voor-webshops/sections/{sectionKey}` | `{ revision, section }` |
| `POST` | `/api/v1/admin/content/voor-webshops/preview` | Draft preview, `private, no-store` |
| `POST` | `/api/v1/admin/content/voor-webshops/publish` | Publish locale; flushes `CmsCache` |
| `GET` | `/api/v1/admin/content/voor-webshops/versions` | Revision history |
| `POST` | `/api/v1/admin/content/voor-webshops/restore` | `{ revision_id }` |
| `POST` | `/api/v1/admin/content/voor-webshops/schedule` | `{ revision, publish_at?, unpublish_at? }` |
| `POST` | `/api/v1/admin/content/voor-webshops/ai-suggest` | Suggestions only; never applied; **503** when disabled |
| `POST` | `/api/v1/admin/content/voor-webshops/assets` | Image upload (`image` file) |

Aliases under `/api/v1/admin/content/pages/voor-webshops/*` mirror the same handlers.

### Error behaviour

| Status | When |
|--------|------|
| **401** | Unauthenticated admin call |
| **403** | Authenticated but not Super Admin |
| **404** | Unknown locale / missing published page / unknown revision |
| **409** | Stale `draft_revision` |
| **422** | Validation (HTML, unsafe href, banned wording, unknown section) |
| **500/503** | Storage / AI unavailable |

Audit events: `cms.page_created`, `cms.draft_saved`, `cms.published`, `cms.restored`, `cms.asset_uploaded`.

## Content schema

Sections: `hero`, `platforms`, `benefits`, `offer_preview`, `onboarding`, `bottom_cta`, `faq` plus meta `nav`, `footer`, `section_order`, `section_visibility`, `schedule`, SEO fields.

Platform tile statuses: `AVAILABLE` | `BETA` | `COMING_SOON` | `MANUAL_SETUP` (overridden by live connector catalogue on read).

Commercial copy must not invent guaranteed sales, free cash withdrawals, or unsupported live integrations. Default FAQ documents 70/30 consumer/ShopBonus affiliate split and approval-gated activation.

`CmsService::ensureMerchantLanding()` creates NL+EN defaults.

## Seeders / tests

- `MerchantLandingSeeder` — local/testing ensure + publish
- `tests/Feature/Cms/MerchantLandingCmsTest.php`
