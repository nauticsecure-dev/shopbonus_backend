# #29 central notification backend

Main implementation is locally verified; final exact-code MySQL/CI verification is pending before completion. [Card #29](https://trello.com/c/WcPlsK8w); full requirements in `requirements/TRELLO_CARDS.md`. Laravel/API/mail views only. Hassan owns the inbox bell, pages, visual states, browser reconnect and screenshots. Senior developer owns actual mail/OpenAI/Reverb deployment credentials and live verification. No live-provider/customer delivery is claimed here.

## Client APIs

All routes require Sanctum, an active verified user and the existing API throttle. Support impersonation is denied. Admin routes additionally require the existing Super Admin/MFA gate; mutations require a real recent password-confirmed, OTP-stepped browser session. Bearer tokens cannot substitute for that step-up.

| Method/path | Contract |
| --- | --- |
| `GET /api/v1/notifications` | `filter=all|unread`, page1–10000, per_page1–100. Owned visible history, created_at/id descending; unread_count covers all visible entries. Delivery rows eager loaded. |
| `PATCH /api/v1/notifications/{notification}/read` | Owned UUID; first read timestamp preserved on replay. Foreign/hidden/missing404. |
| `POST /api/v1/notifications/read-all` | Owned currently visible unread rows; returns marked_read. |
| `DELETE /api/v1/notifications/{notification}` | Owned visible entry only. Locks notification and delivery;409 for sending/uncertain/failed or mandatory mail pending/retry. Optional queued delivery is cancelled by deletion. |
| `GET /api/v1/notification-preferences` | Shared User locale NL/EN, email_enabled and complete registered per-event switches. email_delivery_available reflects deployment/published-policy enablement. |
| `PUT /api/v1/notification-preferences` | Partial update; unknown event/channel422. Mandatory in_app/email cannot be disabled; favorite promotion in_app is optional/defaultfalse; email-only promotion preferences persist a mail record without exposing an unread inbox item. Does not grant marketing/reminder consent. |

Inbox fields: `id` UUID; `type` event code; `recipient_type` consumer/workspace/admin; plain `title`, `body`, `locale`; fixed relative `action_route`, `action_label`; `workspace_id` optional owned public workspace reference; `template_version` immutable setting public ID or explicit committed builtin version; `locale_fallback`; independent `delivery_channels.database/email`; `read_at`, `created_at`. No internal user/workspace/source IDs, event keys, reply transcripts, voucher codes or full financial API responses are returned. Internal reminder voucher references are removed from API payloads.

Hassan must map `/app/benefits`, `/app/platform`, `/app/workspaces?workspace=<public-reference>` and `/app/support?conversation=<owned-public-reference>` to their appropriate pages/selector. No arbitrary client/model action links are accepted. Active workspace membership/non-suspended shop and current admin role are rechecked for historical reads/counts/read-all; Super Admin is never a tenant bypass.

## Event inventory and authoritative sources

| Event | Trigger/source and recipient |
| --- | --- |
| account_welcome | Actual Fortify registration transaction; registered identity. Can persist before verification; inbox remains verified-only. |
| email_verified | Consumed valid verification challenge and successful identity update in the same transaction. |
| cashback_credited | Posted positive affiliate ledger credit linked to the same consumer's actual AffiliateTransaction.wallet_entry_id. Pending/unmatched/arbitrary credits produce no cashback notice. Exact minor units/explicit points or EUR; no unit conversion. |
| cashback_adjusted | Posted consumer ledger adjustment/reversal, never reservation/debit or merchant funding. |
| voucher_received | Actual canonical issued VoucherEvent with an owned consumer. Issuance is not redemption. |
| voucher_used | Actual canonical redeemed VoucherEvent; never a redirect or a copied code. |
| voucher_cancelled | Canonical released/cancelled VoucherEvent only if it has an owned consumer. Unclaimed/unowned reservation releases notify nobody. This adds no new financial cancellation mechanism. |
| voucher_expiring | Usable issued owned voucher at30/7/1 calendar days in business timezone, pinned expiry and stage. State/code/expiry/exact calendar stage rechecked before inbox and mail. |
| support_reply / workspace_support_reply | Persisted human reply in a pinned owned support conversation, same transaction; generic text, never transcript. |
| favorite_merchant_offer | Actual active offer transition; source-time favorite recipients, current favorite/public obtainable offer, explicit event in_app preference plus existing inspiration consent. Optional email checks both again. |
| workspace_approved / workspace_rejected | Actual workspace decision audit source; active workspace owners captured at source time. |
| workspace_review_required | Actual ownership review submission; current active verified Super Admins. |
| offer_published | Actual transition to active; active workspace owners. |
| offer_expiring | Existing deduplicated merchant expiry source (default7days); no parallel competing offer reminder generator. |
| commission_changed | Existing global affiliate reward-rule creation/activation/deactivation; approved workspace owners. Generic configuration-change notice, never a new commission policy/financial posting. |
| integration_attention | Actual connection error transition or existing merchant connector-error alert. No claim that a fixture is a genuine installed integration. |
| ai_support_escalated | Every actual SupportReview creation, including automatic escalation, negative feedback and explicit user request. One intent per persisted review. |
| affiliate_import_failed | Existing recorded affiliate sync/import failure; current admins. |
| notification_delivery_failed | Exhausted optional/uncertain delivery; admin review needed. |
| critical_notification_failure | Inbox outbox exhaustion or mandatory mail failure/unavailability; current admins. Failure alerts do not recursively create email-alert storms. |
| merchant_attention | Compatibility projection of existing low-inventory, funding, claims and information-request notices. Generic text; details remain in scoped source APIs. |

The full catalogue/default channels/allowed variables/stages is also `GET /api/v1/platform/notifications/events`. Mandatory email: welcome, voucher received/cancelled, cashback adjustment, workspace approval/rejection, commission change and critical notification failure. Optional email defaultsfalse; favorite in-app defaultsfalse. Global email switch and private policy can pause the transport, but user preferences never disable mandatory delivery. Existing consent records remain authoritative for promotional mail and reminder mail. No affiliate credit-timing/contact/economic policy is activated or changed.

## Durability, compatibility, retries

Financial sources write encrypted notification_events intents in the source transaction; fanout/mail/network do not hold wallet/audit locks. Recipient snapshots and stable source keys preserve provenance; processing rechecks current eligibility. A per-event lock, per-user lock and unique recipient/event key prevent duplicate inbox entries. Pending events recover from DB after a crash; inbox processing failures retry after5minutes up to3attempts, then become reviewable and alert admins. Explicit audited retries stop after5attempts.

MerchantNotification is retained solely as a legacy source/shared-read compatibility record. Existing workspace endpoints retain their shared state and response shape. The acting user's central projection is marked read independently; a small compatibility read receipt preserves that action even before projection. Backfill imports the source-time shared historical read state, never a later different recipient's read. `shopbonus:dispatch-notifications --bridge` idempotently backfills eligible recent legacy sources; old expired sources are not re-alerted. New merchant alerts automatically produce intents. Central inbox is authoritative for the new UI.

Each new inbox record creates a same-transaction unique mail delivery row. Pending jobs survive queue-dispatch failure and are rescheduled. Workers claim one attempt before sending; a stable Message-ID is reused for an explicitly reviewed retry. Mail uses existing Laravel transport, escaped branded responsive HTML and a real plain-text alternative, NL/EN, no tracking parameters/customer-facing AI references. Array-transport tests exercise actual MIME rendering.

Pre-transport rendering failures safely retry up to3times. Once the transport may have accepted, errors become `uncertain`; stale sending claims after5minutes also become uncertain. They are **never automatically resent**. Admin retry requires a note and explicit duplicate-risk acknowledgment; human resolution records confirmed_sent or do_not_resend. This is not a false SMTP exactly-once guarantee. Pending optional delivery older7days is skipped; mandatory delivery expires into failed review/critical alert. Successful local `sent` means transport acceptance, not proof of customer inbox receipt.

## Templates and admin APIs

Base `/api/v1/platform/notifications`:

| Method/path | Behavior |
| --- | --- |
| GET/POST templates | Paginated immutable versions / create manual private event+locale draft. |
| POST templates/generate | Configured strict OpenAI creates NL/EN private drafts. Required per-admin UUID Idempotency-Key; completed replay returns same drafts, changed/pending/failed request409. |
| GET templates/{template} | Version, key, checksum, status, value, publication/rollback references; no secrets. |
| PUT templates/{template} | Expected checksum/latest-version check; creates a new draft, never overwrites history. |
| POST templates/{template}/test | Validates text/variables/lengths, renders HTML/plain text with server-owned synthetic values, records checksum-bound test. Sends no customer email. |
| POST templates/{template}/publish | Test required; different administrator, existing immutable draft/review/publication workflow. |
| POST templates/{template}/rollback | New draft from verified published history; must test and independently publish before active. |
| GET events | Complete catalogue plus up to50 failed outbox references/codes for review. |
| POST events/{event}/retry | Audited bounded retry of failed inbox intent; recipient/source keys remain deduplicated. |
| GET deliveries | Filtered status/paginated delivery history, attempts/code/sent_at, failed-event and locale-fallback counts. No recipient emails/transcripts. |
| POST deliveries/{delivery}/retry | Reviewed bounded retry; explicit acknowledgment required for uncertain outcomes. |
| POST deliveries/{delivery}/resolve | Reviewed uncertain result, distinguishing human confirmation from transport proof. |
| GET/PUT settings | Current private policy / create a policy draft. Existing `/admin/platform-settings/{version}/review` and independent `/publish` activate it. |

Template value fields: title≤70, body≤180, action_label≤40. Only `{{amount_formatted}}` for cashback_credited; no other variables. Literal amounts/dates/digits/currency, email addresses/URLs/HTML/control characters and provider names are rejected. Exact factual amounts are inserted by Laravel only after source evidence. Human review remains responsible for copy meaning; model prose never establishes a financial event. Templates are immutable existing PlatformSettingVersion rows (`notifications.template.<event>.<locale>`), with checksums and test-before-independent-publish enforced even through generic settings APIs. Requested approved locale → approved NL fallback → committed server builtin templates. Fallback to NL is metered and uses NL amount formatting. Drafts never affect delivery; no runtime AI is needed for ordinary events. Public settings remain the original strict whitelist.

OpenAI uses the existing strict Responses pattern, fixed official HTTPS, no redirects/tools/automatic retry, storefalse,20-second timeout,1000 output tokens. Input only contains registered event/audience/allowed-variable schema; no recipient, merchant/source identifiers, transcript, amount or live financial data. Configured supported model/key is senior-owned; no model availability/cost invented. Default global10generations/day, capped100, serialized UTC quota (failed calls consume). A CAS reservation permits one provider attempt per admin UUID; pending crash recovery marks failure, never silently calls again. Both locales are validated before any draft persists; actor eligibility is rechecked after the call. [Official structured-output contract](https://developers.openai.com/api/docs/guides/structured-outputs#structured-outputs-vs-json-mode).

## Reverb, retention and setup

Existing private channel: `private-shopbonus.user.{userPublicId}.notifications`. Owner-only active verified auth; support sessions denied. Events NotificationCreated/Read/ReadAll/CountUpdated carry only notification_id or null. Treat as invalidation; refetch owned inbox/count on events/reconnect/poll. Queue/WebSocket outages do not roll back committed state. Actual local authorization was captured in B20a; deployed TLS/WebSocket/browser integration remains NOT RUN.

Private reviewed policy can configure up to5 distinct voucher reminder windows (1–90days; default30/7/1) and offer expiry warning1–30days (default7). The scheduler and last-moment delivery use the same policy.

Config: NOTIFICATION_EMAIL_ENABLED=false; NOTIFICATION_RETENTION_DAYS=90 (1–365, private reviewed policy override); NOTIFICATION_AI_ENABLED=false; NOTIFICATION_AI_API_KEY/NOTIFICATION_AI_MODEL empty, daily limit10. Keep application encryption key stable. Senior configures existing MAIL/queue/scheduler/Reverb, actual provider key/model and budget; credentials are not a coding prerequisite. Queue retry_after must exceed60-second worker timeout. Run scheduler and queue workers; existing notifications command is scheduled each minute without overlap. Mail enabling does not prove an operational provider.

Retention removes old terminal inbox entries, preserves pending/unresolved delivery records, strips completed event recipient snapshots after the configured period and retains minimal source dedupe tombstones to prevent old source replays. Explicit owned deletion observes delivery locks. Migration150000 adds outbox/legacy link/read receipts;160000 mail deliveries;170000 template tests/AI quotas/generations/fallback metrics. Apply all migrations before deploying new code. Local MySQL apply/rollback/reapply evidence and final tests/CI are recorded in HANDOFF/PROGRESS. No unrelated cleanup or Redis/binlog operations.

## Acceptance evidence and limits

Actual responses: `B29_HTTP_CAPTURE.json`, API README/OpenAPI. Temporary router-generated OpenAI responses/malformed output and one storage500 are explicitly synthetic; genuine sessions, password/seeded OTP, DB queue worker and array mail are real local execution. No real customer messages or live model/payment/provider claims. Tests in Notifications cover wallet source→exact NL notice, issuance/redemption/reminder stages, reads/ownership/preferences, actual MIME transport, template schema/approval/rollback, queue/render/uncertain recovery, retention/deletion/legacy timing, and real MySQL publication/quota/mail claim races (SQLite skips). Existing module suites cover the underlying verified conversion/order/workspace transitions; they are not a live integrated frontend/staging run. Final full-suite/quality/CI results must be recorded before marking B20b–f complete. Whole-card frontend/live acceptance remains Hassan/senior-owned.

## Local verification checkpoint

Full SQLite718tests:700passed+18skipped,9262assertions,75.523s. Final added outbox failure/reviewed retry regression passed9 source tests/45assertions; total719 is expected in CI. Final API+diagnostics13passed/3669assertions. Full Pint/PHPStan/composer audit/diff checks pass. Three new migrations pass local MySQL apply/rollback/reapply plus SQLite down/up regression. Full local MySQL/races NOT RUN due low disk; CI must verify them before main backend completion.104 real HTTP captures include actual browser step-up and DB queue/array-mail acceptance; live provider/customer/staging verification stays separate. Exact commit and CI results follow in HANDOFF.
