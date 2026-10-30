# Notification API / event inventory

Authoritative contracts: `docs/api/openapi.yaml` + `docs/notifications/NOTIFICATIONS.md`.
Frontend wiring: `shopbonus_frontend/docs/notifications/FRONTEND.md`.

## Client APIs

| Method | Path | Roles |
| --- | --- | --- |
| GET | `/api/v1/notifications` | verified owner |
| PATCH | `/api/v1/notifications/{id}/read` | verified owner |
| POST | `/api/v1/notifications/read-all` | verified owner |
| DELETE | `/api/v1/notifications/{id}` | verified owner |
| GET/PUT | `/api/v1/notification-preferences` | verified owner |
| GET/POST | `/api/v1/platform/notifications/templates` | Super Admin |
| POST | `/api/v1/platform/notifications/templates/generate` | Super Admin + step-up |
| GET/PUT | `/api/v1/platform/notifications/templates/{id}` | Super Admin (+ step-up mutate) |
| POST | `.../test` `.../publish` `.../rollback` | Super Admin + step-up |
| GET | `/api/v1/platform/notifications/events` | Super Admin |
| POST | `/api/v1/platform/notifications/events/{id}/retry` | Super Admin + step-up |
| GET | `/api/v1/platform/notifications/deliveries` | Super Admin |
| POST | `.../retry` `.../resolve` | Super Admin + step-up |
| GET/PUT | `/api/v1/platform/notifications/settings` | Super Admin (+ step-up mutate) |

## Reverb

- Channel: `private-shopbonus.user.{userPublicId}.notifications`
- Events: `NotificationCreated` | `NotificationRead` | `NotificationReadAll` | `NotificationCountUpdated`
- Payload: `{ notification_id }` only

## Events

| Code | Audience | In-app | Email |
| --- | --- | --- | --- |
| account_welcome | consumer | yes | mandatory |
| email_verified | consumer | yes | optional |
| cashback_credited | consumer | yes | optional |
| voucher_received | consumer | yes | mandatory |
| voucher_expiring | consumer | yes | optional (reminders 30/7/1) |
| voucher_used | consumer | yes | optional |
| voucher_cancelled | consumer | yes | mandatory |
| cashback_adjusted | consumer | yes | mandatory |
| support_reply | consumer | yes | optional |
| favorite_merchant_offer | consumer | optional | optional |
| workspace_approved | workspace | yes | mandatory |
| workspace_rejected | workspace | yes | mandatory |
| offer_published | workspace | yes | optional |
| offer_expiring | workspace | yes | optional |
| commission_changed | workspace | yes | mandatory |
| integration_attention | workspace | yes | optional |
| workspace_support_reply | workspace | yes | optional |
| workspace_review_required | admin | yes | optional |
| ai_support_escalated | admin | yes | optional |
| affiliate_import_failed | admin | yes | critical path |
| notification_delivery_failed | admin | yes | critical path |
| critical_notification_failure | admin | yes | mandatory |
| merchant_attention | workspace | yes | optional (compat) |

## Evidence

- Backend Feature suite: `tests/Feature/Notifications` — 43 passed / 3 skipped (MySQL race skips when SQLite).
- OpenAPI registry covers all routes above.
- Frontend E2E: `e2e/notifications.spec.ts` (credentials required for live run).
- Screenshots / live Reverb+SMTP: senior/Hassan deployment verification (not claimed from local code alone).
