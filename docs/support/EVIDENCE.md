# Verified support evidence — B17a / Trello #26

Card: https://trello.com/c/aWBrCkQK. **Historical B17a checkpoint:** the completed chat/history/model/review/policy/knowledge/realtime implementation is documented in [SUPPORT_CHAT.md](SUPPORT_CHAT.md); the original inventory below describes the earlier foundation only. This checkpoint is an internal Laravel service boundary, **not a working chat API**. There are no added routes, migrations, provider calls, jobs, configuration flags or client changes. The existing OpenAPI contract remains unchanged. OpenAI remains the owner's selected provider; the senior developer configures model/key/budget when the transport is implemented.

## Current inventory

| Component | State / implementation |
| --- | --- |
| Authorized read-only evidence | Implemented: `app/Services/Support/SupportTools.php` |
| Minimal provider projection | Implemented: `ToolEvidence::context()`; no provider transport yet |
| Independent answer selection verification | Implemented: `VerifiedSupportAnswer::verify()` |
| Chat classification/research plan and one targeted retry | Not built |
| Consumer reward-to-order ownership/status mapping | Not built; do not guess ownership using email or payment status |
| Voucher quote, campaign, integration-health support tools | Not built; existing business services must be reused |
| Conversation/history/retention and HTTP fallback | Not built |
| OpenAI transport/quota/queue/circuit breaker | Not built; use configured supported Responses model, no model hardcode |
| Private Reverb progress/subscription authorization | Not built; current order channel is unrelated |
| Feedback, human review and correction workflow | Not built |
| Super Admin policy/settings draft/test/publish/rollback | Not built; reuse versioned settings/CMS where applicable |
| Full 100-question regression and ten card E2Es | Not run; focused service tests are not those acceptance tests |
| Live provider, frontend and production monitoring | Not run |

## Tool contracts

The future conversation engine must obtain the actual authenticated User and pin the authorized workspace/locale itself. Workspace references are **not** accepted as model/tool arguments. Every retrieval reloads identity/membership/status/permissions from the database, including during final verification. A Super Admin has no implicit workspace bypass. Support impersonation context is denied.

| Tool | Exact arguments | Scope / authoritative source |
| --- | --- | --- |
| `get_my_wallet` | `unit`: `eur_credit` or `points` | Consumer only; existing `Consumer\WalletController::summary` |
| `get_my_voucher` | `voucher_id`: public ULID | Consumer-owned Voucher only; code relation never loaded |
| `get_workspace_wallet` | none | Active member with `finance.view`; merchant funding Wallet read; no wallet creation |
| `get_offer_cost` | `offer_id`: public ULID | Active member with `offers.view`; current offer version + existing `FeeCalculator::offerPreview` |
| `get_approved_knowledge` | `document_id`: public ULID | Published FaqItem, public + consumers in consumer scope, public + merchants in workspace scope |

These are internal service names, not duplicate business API endpoints. Unknown tools/arguments, role/user/workspace injection, unsupported locales and identifiers longer than64 characters are rejected. Nonmember/nonowned lookups return the same generic404; a member missing the required permission receives403. Fresh disabled/unverified accounts and support sessions are denied. Every retrieval/denial has sanitized audit metadata.

Wallet facts preserve integer `available`, `reserved`, `pending`, `spent`, explicit unit and currency (`EUR` or null for points). Pending/reserved are never treated as spendable. No conversion or money movement occurs. Missing wallets yield zero as the existing consumer API does, without creating rows.

Voucher facts contain only recorded status/origin/issue/expiry/redemption timestamps. Issuance is not proof of redemption. Codes, masked code suffixes, customer data, contact/payment identifiers and raw terms are excluded.

Offer costs use the existing direct merchant-funded fee preview: fixed1000 reward →300 fee →1300 total minor units at the current configured3000bps. Percentage costs retain their maximum-discount basis; a missing maximum, promotion or consumer-funded offer is unavailable, never inferred as zero. These are previews, not charges.

FAQ evidence uses the existing published flag and revision. Its immutable snapshot includes source public ID/revision, actual locale and plain content; no fictitious approval timestamp. Requested EN falls back to NL exactly as the existing FAQ API does. Empty or >4000-character plain content fails closed instead of truncating a policy. The future policy/intent engine must still decide relevance and allowed disclosure; source agreement alone does not prove that a FAQ answers a question.

## Verification contract

`ToolEvidence` is a readonly internal object. **Never hydrate it from HTTP/model JSON and never serialize the whole object in an API response.** It carries private actor/scope/source metadata needed for revalidation. `context()` exposes only an opaque evidence ULID, tool name, retrieval time and allowlisted facts; no actor/workspace/source IDs or arguments. Nothing currently sends this projection outside Laravel.

`VerifiedSupportAnswer::verify($authenticatedUser, $selection, $trustedEvidence, $serverRequiredTools)` accepts exactly:

```
{"evidence_ids": ["<opaque-id-from-context>"]}
```

This is an internal shape, **not an HTTP capture or public API sample**. It rejects model prose, amounts, dates, roles, URLs, actions and fabricated/duplicate IDs. Max5 snapshots/selections; one actor/workspace/locale; freshness30seconds with no future timestamps. Conflicting snapshots for one scoped tool/argument combination invalidate the packet even if the model selects only one.

For each selected snapshot Laravel reloads the source under current authorization and compares exact source/version/facts. Changed balance/terms/FAQ, revoked membership/permission/account or unpublished FAQ prevents output. Server-declared required tools must all be selected; otherwise the result is `NEEDS_CLARIFICATION` with null answer and no evidence. This detects missing **tools** only: the future research planner must establish question-specific completeness and identifiers. No completeness claim is made for arbitrary questions yet.

Successful results use only fixed EN/NL server templates or explicitly labelled published FAQ text. An unavailable cost preview returns `PARTIALLY_VERIFIED`. Actions stay null until an authorized intent/link contract exists. There is no refund/payment/ticket/publication capability. Verification rejection/completeness/success is audited with status/reason/tool names, without question text or account facts.

## Next boundary

B17b: implement persisted owned conversations and server intent/research planning around these services; cover safe unknown/referral states and one different targeted retrieval round. Then add bounded configurable Responses transport, private minimal progress events/HTTP history, feedback/review and versioned admin policy/settings in small tested checkpoints. Expand checklist before each boundary. Every new `/api/v1` operation requires real running-server request/response captures in OpenAPI and README. Do not mark #26 Done or ready for full frontend integration based on this service-only checkpoint.
