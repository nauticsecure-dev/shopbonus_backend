# Homepage CMS & related public APIs

Consumer homepage content is a structured `landing` CMS page with stable key `homepage`. Dynamic modules (webshops, discount codes, search) are separate public endpoints.

## Public

| Method | Path | Notes |
|--------|------|--------|
| `GET` | `/api/v1/content/homepage?locale=nl\|en` | Published only, `CmsCache`, `Cache-Control: public, max-age=60` |
| `GET` | `/api/v1/public/search?q=&locale=nl&limit=8` | Grouped autocomplete: webshops, categories, offers, reusable discount codes. Throttle `public-search` |
| `GET` | `/api/v1/public/discount-codes?limit=10&search=` | Active reusable promo codes only; plaintext code returned. Unique codes never exposed |
| `GET` | `/api/v1/public/webshops` | Cards include optional `badge_label` / `cashback_label` (consumer cashback share, not raw commission). Optional `slugs=a,b,c` returns those published shops in CMS featured order |

## Admin (super_admin; mutations need `password.recent`)

| Method | Path | Notes |
|--------|------|--------|
| `GET` | `/api/v1/admin/content/homepage?locale=` | Draft + published + `draft_revision` + page id |
| `PUT` | `/api/v1/admin/content/homepage` | Full draft save: `{ revision, content, locale? }` |
| `PATCH` | `/api/v1/admin/content/homepage/sections/{sectionKey}` | Body: `{ revision, section }` — optimistic lock |
| `POST` | `/api/v1/admin/content/homepage/preview?locale=` | Draft preview, `private, no-store` |
| `POST` | `/api/v1/admin/content/homepage/publish?locale=` | Publish locale |
| `GET` | `/api/v1/admin/content/homepage/versions?locale=` | Revision history |
| `POST` | `/api/v1/admin/content/homepage/restore` | Body: `{ revision_id }` → `CmsService::restore` |
| `POST` | `/api/v1/admin/content/homepage/schedule?locale=` | Body: `{ revision, publish_at?, unpublish_at? }` on draft metadata |
| `POST` | `/api/v1/admin/content/homepage/ai-suggest` | Suggestions only; disabled by default (`CMS_AI_SUGGEST_ENABLED`) |
| `POST` | `/api/v1/admin/content/assets` | Same rules as `/admin/cms/assets` |

## Content schema

See structured sections: `section_order`, `section_visibility`, `nav`, `hero`, `search`, `popular_webshops`, `how_it_works`, `discount_codes`, `wallet_preview`, `footer`. Validation strips HTML (`not_regex /[<>]/`), allowlists hrefs (relative `/` or https on configured hosts), rejects withdrawal wording, and forbids live wallet/cashback figure fields in CMS.

`CmsService::ensureHomepage()` auto-creates NL+EN defaults when the page is missing.

## Seeders

- `HomepageSeeder` — ensure + publish homepage; optional reusable `WELKOM10` code
- `DiscoveryDirectorySeeder` — calls `HomepageSeeder` after demo shops

## Tests

`tests/Feature/Cms/HomepageCmsTest.php`
