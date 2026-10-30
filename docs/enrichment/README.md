# Merchant website enrichment

Approved webshops with a verified domain can receive an SSRF-safe homepage fetch, Dutch public-profile copy derived only from fetched evidence (OpenAI Responses JSON schema), and an optional abstract header image (OpenAI Images). Nothing is applied to discovery until it is auto-published under strict rules or a Super Admin approves it.

## Configuration

See `config/enrichment.php` and `.env.example` (`MERCHANT_ENRICHMENT_*`). Reuses `OPENAI_API_KEY`. Disabled by default.

| Variable | Purpose |
| --- | --- |
| `MERCHANT_ENRICHMENT_ENABLED` | Master switch |
| `MERCHANT_ENRICHMENT_TEXT_MODEL` | Responses model for profile JSON |
| `MERCHANT_ENRICHMENT_IMAGE_MODEL` | Images model for cover art |
| `MERCHANT_ENRICHMENT_AUTO_PUBLISH` | When `false`, all proposals need admin approve |
| `MERCHANT_ENRICHMENT_DAILY_RUN_LIMIT` | Global daily enqueue cap |
| `MERCHANT_ENRICHMENT_STALE_DAYS` | Re-enrich cadence for unpublished profiles |

Auto-publish runs only when `MERCHANT_ENRICHMENT_AUTO_PUBLISH=true`, the model sets `review_required=false`, `risk_level=low`, and empty profile fields are filled (existing merchant copy is not overwritten).

## Operations

- Schedule: `shopbonus:enrich-merchants` daily at 02:30 when enabled (`routes/console.php`).
- Manual: `php artisan shopbonus:enrich-merchants --limit=25`
- Queue job: `ProcessMerchantEnrichment` (sync in tests; Redis in production).

## Admin API (Super Admin)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/v1/admin/merchant-enrichments` | Filter `status`, `webshop` |
| GET | `/api/v1/admin/merchant-enrichments/{run}` | Full research + proposal |
| POST | `/api/v1/admin/merchant-enrichments/{run}/approve` | Requires recent password; publishes |
| POST | `/api/v1/admin/merchant-enrichments/{run}/reject` | Requires recent password |
| POST | `/api/v1/admin/merchant-enrichments/{run}/retry` | New run for failed/rejected |

## Safety

- `SafeUrlFetcher`: HTTPS only, `DomainName` + `PublicAddressGuard`, DNS-pinned curl, no redirects, bounded body.
- AI instructions: Dutch, evidence-only, structured JSON; Laravel validation on output.
- Cover generation failures set `cover_failure_code` but do not fail the text proposal.
- Audited events: `admin.merchant_enrichment_*`.

## Tests

`tests/Feature/Enrichment/MerchantEnrichmentE2ETest.php` fakes HTTP only (no live OpenAI): SSRF refusal, AI completion, admin approve, image failure fallback, auto-publish, retry.
