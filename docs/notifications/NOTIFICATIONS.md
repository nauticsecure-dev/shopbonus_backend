# #29 central notifications — B20a client/backend handoff

Status: **PARTIAL whole card; first backend boundary implemented**. Source: [Trello #29](https://trello.com/c/WcPlsK8w), full downloaded description preserved in `requirements/TRELLO_CARDS.md`. B20a does not complete #29. No frontend work or external customer messages.

## Implemented contract

All five endpoints use the existing Sanctum bearer/SPA session authentication, active verified account checks, global API throttle and support-session restrictions. Super Admins only see their own inbox; there is no tenant bypass.

| Method/path | Behavior |
| --- | --- |
| `GET /api/v1/notifications?filter=all&page=1&per_page=20` | Owned visible inbox; `all`/`unread`, page1–10000, per_page1–100. `meta.unread_count` spans the visible inbox regardless of page/filter. Stable created_at/id descending order. |
| `PATCH /api/v1/notifications/{notification}/read` | Owned UUID, first read timestamp preserved on replay. Same404 for foreign, hidden and missing entries. |
| `POST /api/v1/notifications/read-all` | Mark currently visible owned unread entries; returns `data.marked_read`. Never changes another user's read state. |
| `GET /api/v1/notification-preferences` | Shared account locale NL/EN, global email switch and registered per-event switches. |
| `PUT /api/v1/notification-preferences` | Partial updates; currently registered `support_reply` and `workspace_support_reply` only. `in_app` is mandatory true; unknown event/channel keys or false mandatory setting422. Locale updates the existing User locale. |

Each inbox item has `id` (UUID), `type`, `recipient_type`, `title`, `body`, `locale`, `action_label`, `action_route`, immutable built-in `template_version`, `delivery_channels`, `read_at`, `created_at`. No raw user/workspace/source IDs, event deduplication key, human reply body, voucher secret or financial response is exposed. The conversation public reference is available only in the recipient's internal action URL. Payloads are encrypted at rest through Laravel's existing application key; standard Laravel database notification relationship/read helpers use the custom encrypted model.

Historical workspace entries require current active membership and a non-suspended shop. Historical admin entries require the current Super Admin role. Both history and unread counts/read-all apply these conditions. This first slice produces only support events; admin entries are a tested authorization boundary for later event wiring. Support impersonation tokens cannot read/update either API or subscribe to the private channel.

## Currently wired events

| Event | Authoritative trigger | Recipient | Channels/template | Dedupe |
| --- | --- | --- | --- | --- |
| `support_reply` | Successful persisted human reply in an owned consumer support conversation | Conversation user, active and verified | Database inbox; generic NL/EN `support-reply-v1` using current account locale | User + actual reply public reference |
| `workspace_support_reply` | Same, pinned to a workspace | Conversation user with current active membership; shop not suspended | Same generic template; workspace visibility rechecked later | Same per-recipient key |

Creation occurs inside the existing human reply transaction. Source reply, audit and inbox persist or roll back together. Per-user row lock plus database unique recipient/event index prevents duplicates under concurrent publication. Repeated HTTP human-reply requests still create separate human replies under the existing #26 contract; publication replay for one persisted reply creates one inbox item.

Text only says that a reply is available; transcripts stay in owned support history. The fixed relative action is `/app/support?conversation=<owned-conversation-public-id>`; Hassan must map that application route to the support conversation. No arbitrary caller/model action links are accepted.

Preferences store optional email intent but **`email_delivery_available=false`** and `delivery_channels.email=not_implemented` explicitly report that B20a sends no mail. Optional email defaults false. The global email switch does not disable mandatory inbox delivery. These preferences do not write or grant marketing/reminder consent; existing `CommunicationPreferences` remains authoritative for future optional delivery. No financial reward policy changes.

## Reverb and reconnect

Subscribe via existing `/api/broadcasting/auth` to:

`private-shopbonus.user.{userPublicId}.notifications`

Use the public User identifier, never an internal integer. Active, verified owner only; support sessions denied. Existing queue/Reverb environment configuration is reused; no new credentials required to implement this slice.

Events: `NotificationCreated`, `NotificationRead`, `NotificationReadAll`, `NotificationCountUpdated`. Payload is only `{ "notification_id": "<UUID-or-null>" }`. Treat it as invalidation: refetch the authenticated inbox/count. Duplicate/delayed events are harmless. Reconnect fetches current HTTP state; polling works while Reverb is unavailable. Events enqueue after commit, and enqueue failures are reported through existing error handling while committed reply/read state stays durable. Broadcast hints are not a durable email outbox and do not claim customer delivery.

Actual local HTTP auth captures prove owner200/other403/support403 with synthetic Reverb configuration. Callback/payload tests and queue failure/commit tests run locally. Deployed TLS/WebSocket transport and frontend behavior are NOT RUN for #29.

## Errors/documentation

401 unauthenticated;403 inactive/unverified/support session;404 foreign/missing/hidden notification;419 browser CSRF;422 invalid filters/preferences;429 existing throttle;500 unexpected storage failure. Read operations are idempotent and do not intentionally produce409. Examples are actual local HTTP in `docs/api/README.md` and `docs/api/openapi.yaml`; sanitized source artifact `B20A_HTTP_CAPTURE.json` contains24 real requests. The500 capture used a temporary router-bound service failure through the real kernel, never shipped. Ephemeral channel auth signature is explicitly redacted. All capture users/data are synthetic; no provider/mail calls.

Migration `2026_10_26_140000_create_central_notifications.php` adds standard Laravel `notifications` columns plus recipient/event/scope constraints and `notification_preferences`. Existing merchant storage/endpoints and shared workspace read state remain compatible pending the explicit B20b migration/bridge; do not present those legacy notifications as already unified.

## Remaining card work

B20b: bridge merchant events/legacy reads and wire the complete consumer/workspace/admin catalogue. B20c: durable email outbox/channel idempotency, bounded retry/recovery, consent/render tests, retention/deletion. B20d: eligible deduplicated30/7/1 voucher and offer reminders. B20e: reviewed NL/EN AI template drafts/test/publish/rollback, strict variables and admin delivery/settings APIs. B20f: full ten backend E2Es and complete event inventory. Senior developer configures and verifies actual email/model/deployed Reverb; Hassan implements the UI. This card is not ready for full frontend-only handoff yet.

## B20a executed verification

Full SQLite688tests:672passed+16skipped,8873assertions,71.284s. Sequential MySQL688tests:686passed+2skipped,8927assertions,273.128s. Includes15 new tests and one real4-process publication race (SQLite skip). Full Pint, PHPStan L6, composer audit and OpenAPI coverage pass. Migration140000 apply/rollback/reapply PASS locally on MySQL. Implementation commit/push/CI SHA recorded in latest HANDOFF after closeout; these local checks establish only the B20a boundary.

Implementation `5c1d8cc` is pushed; CI `40399876856` SUCCESS all3jobs. Exact688tests/database: SQLite672pass+16skip/8877assertions/39.76s; MySQL686pass+2skip/8927assertions/126.47s; quality checks green. Dispatch-failure recovery is a simulated throwing event listener at the dispatch boundary; genuine deployed queue/mail/Reverb outage recovery is NOT RUN.
