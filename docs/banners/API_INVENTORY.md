# Banner Studio API inventory (Trello #25)

Auth: Sanctum session/CSRF. Workspace routes require membership + `offers.view` / `offers.manage`. Commercial middleware on publish/schedule/resume. Public embed: `pk_` key + Origin allowlist. Editor: pairing token → `X-Banner-Editor-Token` (no session in script/URL/storage).

## Workspace (merchant)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/workspaces/{w}/banners` | Campaign overview (`status`, `search`, `placement`) + `stats_30d` |
| POST | `/api/v1/workspaces/{w}/banners` | Create draft |
| GET | `/api/v1/workspaces/{w}/banners/{id}` | Draft + published config + notice |
| PUT | `/api/v1/workspaces/{w}/banners/{id}` | Save draft (`revision` optimistic lock) |
| POST | `…/duplicate` | Duplicate |
| POST | `…/publish` \| `…/schedule` \| `…/pause` \| `…/resume` \| `…/rollback` | Lifecycle |
| GET | `…/versions` | Immutable version history |
| GET | `…/results` | Eligible, viewable, CTA, dismissals, CTR, attributed orders |
| POST | `…/simulate` | Why shown/hidden (+ simulated cart) |
| GET/PUT | `…/banners/installation` | `pk_`, origins, health, snippet |
| POST | `…/banners/pairing` | Single-use origin-bound pairing |
| POST | `…/banners/assets` | Image upload (png/jpg/webp ≤2MB) |
| POST/GET | `…/ai-requests` + `…/review` | AI Create / Optimize → human review only |
| POST | `…/experiment` | A/B/holdout (published versions) |

## Public embed

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/banners/config?key=` | Cached published config only |
| POST | `/api/v1/banners/events` | Deduped measurement (X ≠ CTA) |
| POST | `/api/v1/banners/assignments` | Experiment assignment (consent) |
| GET | `/api/v1/banners/assets/{id}` | Public image |
| POST | `/api/v1/banners/editor/pair` | Exchange pairing → editor token |
| GET/PUT | `/api/v1/banners/editor/campaigns/{id}` | Draft access with editor token |

## Embed script

`GET {app.url}/embed/v1.js` — async Shadow DOM renderer + postMessage pairing (`schema: shopbonus.banner.v1`). Cart via `window.ShopBonusCart.getTotalMinor()` only.

## Reuse (do not duplicate)

Offers / BranchOfferDefaults, MerchantFunding (reward + 30% fee), Wallet, Order Sheet attribution, CapabilityMatrix (`banner_embed`), Workspaces memberships.
