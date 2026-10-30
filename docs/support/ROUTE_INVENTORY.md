# ShopBonus verified AI support — route inventory

Scope: ShopBonus customer support only (consumers + Workspace users). Not a white-label chatbot SaaS.

## Chat & feedback

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/api/v1/ai/chat` | Idempotency-Key required. Returns **202** `{ id, conversation_id, status }`. Poll message or subscribe to Reverb. |
| GET | `/api/v1/ai/conversations` | Owned conversation list (restore after refresh). |
| GET | `/api/v1/ai/conversations/{conversation}` | History + human replies; HTTP fallback after reconnect. |
| DELETE | `/api/v1/ai/conversations/{conversation}` | Owner delete when idle. |
| GET | `/api/v1/ai/messages/{message}` | Contract projection: `message_id`, `status`, `answer`, `evidence_ids`, `checked_at`, `retry_used`, `action` (+ nested `result`). |
| POST | `/api/v1/ai/messages/{message}/feedback` | `rating` up/down; downvote creates review. |
| POST | `/api/v1/ai/messages/{message}/escalate` | Persisted human review. |
| POST | `/api/v1/ai/knowledge/search` | Approved FAQ search. |

### Sample chat request

```json
{
  "conversation_id": "01h…",
  "message": "Where is my €10 reward?",
  "current_page": "/app/wallet",
  "locale": "en"
}
```

Laravel resolves `user_id`, role, `workspace_id` and `allowed_tools` server-side. Client values for those fields are prohibited.

### Sample verified message (`GET …/messages/{id}`)

```json
{
  "data": {
    "message_id": "01h…",
    "conversation_id": "01h…",
    "status": "VERIFIED",
    "answer": "Available ShopBonus credit: EUR 10.00; reserved: EUR 0.00; …",
    "evidence_ids": ["01h…"],
    "checked_at": "2026-10-30T12:00:00+00:00",
    "retry_used": false,
    "action": { "label": "Bekijk mijn tegoed", "route": "/app/wallet" }
  }
}
```

Statuses: `VERIFIED`, `PARTIALLY_VERIFIED`, `NEEDS_CLARIFICATION`, `ESCALATED`, `UNAVAILABLE` (plus in-flight `PENDING`/`RESEARCHING`/`VERIFYING`/`RETRYING`).

## Tool HTTP aliases (exact contract fields)

All aliases call `SupportTools` (existing services). No duplicate financial logic. Each payload includes `source_id` + `checked_at`.

| Tool | Method | Path |
| --- | --- | --- |
| get_my_wallet | GET | `/api/v1/me/wallet` |
| get_my_order_reward_status | GET | `/api/v1/me/orders/{reference}/reward` |
| get_my_vouchers | GET | `/api/v1/me/vouchers` |
| get_voucher_quote | GET | `/api/v1/me/voucher-quotes/{offerId}` |
| get_workspace_wallet | GET | `/api/v1/workspaces/{workspace}/wallet` (`current` OK) |
| get_offer_cost | GET | `/api/v1/workspaces/{workspace}/offers/{offer}/cost` |
| get_campaign_performance | GET | `/api/v1/workspaces/{workspace}/campaigns/{campaign}/performance` |
| get_integration_health | GET | `/api/v1/workspaces/{workspace}/integrations/health` |
| search_approved_knowledge | POST | `/api/v1/ai/knowledge/search` |

Amounts are integer minor units. Campaign `tracked_order_starts` remains `null` until a measured source exists (never invented as sales).

## Reverb

- Private channel: `private-shopbonus.support.conversation.{conversationId}`
- Events: `ChatResearchStarted`, `ChatVerificationStarted`, `ChatRetryStarted`, `ChatAnswerVerified`, `ChatAnswerUnavailable`, `ChatFeedbackSubmitted`, `ChatReviewRequired`, `ChatSupportReplyAvailable`
- Payload: `{ conversation_id, message_id, status }` only — never raw API evidence or drafts.

## Super Admin

- UI: `/app/platform/ai-support/settings` — tabs General, Restrictions, Knowledge, Quality, Operations
- Draft → test → publish → rollback via platform settings `support.ai`
- Reviews: `/admin/support` + `/api/v1/admin/ai/*`

## Verification engine

1. Authenticate + authorize role/workspace  
2. Plan intent → permitted tools  
3. Retrieve live evidence / approved FAQ  
4. Named checks: AUTH, SOURCE, FRESHNESS, COMPLETENESS, CONSISTENCY, CLAIMS, POLICY, ACTION  
5. OpenAI Responses selects opaque evidence IDs only (`verify_support_evidence`, `strict: true`)  
6. Laravel re-reads sources and renders server templates  
7. At most **one** diagnostic retry packet (`MISSING_REWARD_STATUS` → `get_my_order_reward_status`)

## Tests & monitoring

- Backend Feature suite: `tests/Feature/Support/*` (10 card E2Es + 100 regressions + tool aliases)
- Playwright: `shopbonus_frontend/e2e/support-chat.spec.ts` (requires credentials)
- Operations: `GET /api/v1/admin/ai/operations` — latency, tokens, retries, circuit, escalation rate
- Live OpenAI ≥95% accuracy and production Reverb health require staging credentials (see `SUPPORT_CHAT.md` acceptance map)
