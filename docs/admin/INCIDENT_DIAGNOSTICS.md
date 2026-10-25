# Card #21 — incident diagnostics backend handoff

OpenAI was selected by the owner on 2026-10-25 ("chatgpt"). The owner confirms there is **no staging environment**. This implements incident diagnostics, not the consumer/merchant support-chat system in B17.

## What is implemented

- Only active HTTP 500 or background-job incidents are eligible. Resolved/ignored incidents and normal access/validation denials are refused.
- Context is built server-side from a fixed schema: HTTP status, allowlisted service, registered route template/method and occurrence count. E1–E5 identify those facts. Unknown routes/services are omitted/generalized. No customer details, actor/workspace/request IDs, exception messages, raw logs, headers, credentials, private URLs, webhook/order/payment payloads or human review notes go to the model.
- A queued run captures immutable context/hash/model. Its idempotency key is scoped to the requesting admin; reuse for a different incident returns 409. The worker atomically claims queued → running once, checks context integrity and current requester/incident eligibility, and makes at most one provider attempt.
- OpenAI Responses uses a fixed `https://api.openai.com/v1/responses` destination, `store: false`, a strict JSON schema, no tools, 5-second connection/20-second request timeout and a bounded output-token setting. There are no automatic provider retries. Refusal, incomplete, tool-call, unknown-field, malformed, oversize or unsupported-evidence outputs fail safely without retaining raw responses/errors.
- Completed results contain likely cause, evidence references, impacted component, suggested investigation, proposed fix, self-assessed confidence and uncertainty. These are **unverified hypotheses**, never proof of a root cause or actions taken. Text is redacted and HTML tags removed. Context staleness is exposed when the incident's occurrence count changes.
- Results and human reviews are append-only through their models. Audit records link requests, completions, safe failures and reviews. A review accepts/dismisses an advisory result; it cannot execute production changes or close the incident. Wallets, refunds, permissions and deployment have no connection to this service.

Official API references consulted 2026-10-25: [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs), [Responses create](https://developers.openai.com/api/reference/resources/responses/methods/create). `store: false` is not a claim of zero provider retention; verify the account's data controls before enabling real use.

## Enablement configuration

Set these **locally in the deployment environment**, never in committed files or chat:

```dotenv
ADMIN_DIAGNOSTICS_ENABLED=false
OPENAI_API_KEY=
ADMIN_DIAGNOSTICS_MODEL=
ADMIN_DIAGNOSTICS_DAILY_REQUEST_LIMIT=10
ADMIN_DIAGNOSTICS_MAX_OUTPUT_TOKENS=1500
```

Leave disabled until an authorized OpenAI API project/key, an account-supported Responses model with Structured Outputs and the desired budget are configured. No production model ID or account capability is assumed. A configured flag means the required configuration exists, **not** that credentials/model access have passed a live test.

The effective global UTC-day request limit is the lower of the environment cap and the independently published `operations.diagnostics_daily_request_limit` setting. Both are bounded 0–1000; 0 pauses new requests. Requests reserve quota at enqueue time, including runs that later fail; idempotent replays consume no extra quota. The cap bounds requests, not currency expenditure. Output tokens are clamped to 256–4000. Per-admin generation rate limit is 3/minute, in addition to the normal API limit.

Use a persistent queue worker in the configured environment. The job has one attempt and a 30-second timeout; ensure the queue connection's retry-after exceeds the job timeout and restart workers after configuration changes. No queue worker was connected to a real OpenAI account during this implementation.

## Hassan's API integration

All paths below are under `/api/v1/admin`; verified active Super Admin + MFA when enabled is required. Request/review mutations also require recent password and email OTP through the existing step-up endpoints. Support sessions cannot call admin routes.

| Method/path | Purpose |
| --- | --- |
| `GET /incidents/{incident}/diagnostics` | Paginated history (`per_page` 1–100), configured flag and effective daily request limit. |
| `POST /incidents/{incident}/diagnostics` | Send only `request_key` (8–64 ASCII letters/numbers/`._:-`); returns 202. Preserve the same key when retrying the same request. Extra client input is not used or forwarded as a prompt/context override. |
| `GET /incident-diagnostics/{diagnostic}` | Poll `queued`/`running` until `completed`/`failed`; display the safe failure code, or the advisory analysis and evidence. |
| `POST /incident-diagnostics/{diagnostic}/review` | `decision: accepted|dismissed` and a 5–500 character `note`. Completed only; one append-only review per admin/result. |

Render analysis as plain text, show its uncertainty/confidence and a persistent advisory/human-review notice. `accepted` is a review record, not authorization to execute a proposed production fix. `context_stale` means the evidence no longer reflects the incident's current count. Never present an emitted suggestion as an implemented fix.

Use the existing platform-setting draft/review/publish/rollback endpoints for `operations.diagnostics_daily_request_limit`. It requires independent publication with OTP and cannot exceed the environment's effective cap. Operational values and version IDs are excluded from the anonymous `/api/v1/public/settings` output; credentials, provider model and the enable switch are not generic editable settings.

Error handling: shared 401/403/404/419/422/423/429/500 envelope; 503 `diagnostics_not_configured`; 409 for ineligible incidents, changed-key reuse, unfinished or duplicate reviews; 429 for per-admin rate limits or exhausted/paused global quota. See real HTTP examples and complete contract in `docs/api/README.md` and `docs/api/openapi.yaml`.

## Verification status and remaining card acceptance

Executed test results/commit references are in `docs/PROGRESS.md` and `docs/HANDOFF.md`. Live captures used a real local Laravel server with an isolated SQLite database, database sessions/queue/cache and logged mail. Completion was generated through the real service/validator with an explicit fake OpenAI transport; **this is not live OpenAI evidence**.

Still required before the full Trello card can be marked Done:

1. Configure a real API project/key, supported model and owner budget; enable in a controlled environment and verify one successful analysis plus failure handling and actual usage. NOT RUN: no API credentials/model configured.
2. Hassan implements and tests the admin UI against these endpoints; no frontend code was changed here.
3. Provision staging and verify cached cockpit p95, monitoring/alert delivery and backup/recovery evidence. NOT RUN: owner confirms no staging. Existing provider/financial/legal/marketing decisions remain unchanged; generic settings must not activate those flows.
4. Further sensitive platform settings must reuse the existing domain services and published policies; this slice adds only bounded operational diagnostics quota, without rewriting historical transactions or legal/consent rules.
