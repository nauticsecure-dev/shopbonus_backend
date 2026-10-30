# API route inventory (Trello #22)

Generated 2026-10-29 from the running route table and `docs/api/openapi.yaml` with `php artisan shopbonus:api-routes` (all 362 routes: documented access matches the middleware). Regenerate before each release; the command exits non-zero on any contract drift.

Access: admin 163, authenticated 4, integration 4, public 35, verified 156. Every route is exercised by `tests/Feature/Api/RoleAccessMatrixTest.php` as anonymous, suspended, unverified, consumer, owner, manager, employee and Super Admin (Quality Center routes under `/admin/qa` also admit `qa_tester`, and findings/evidence/fix `developer`; see `roles` in `shopbonus:api-routes`); request/response contracts and captured examples are in `docs/api/openapi.yaml`.

| Method | Path | Access | Permission | Scope | Step-up | Rate limit | Audit category |
|---|---|---|---|---|---|---|---|
| GET | `/api/v1/admin/activity` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/affiliate/connections` | admin | - | platform | - | api | financial |
| POST | `/api/v1/admin/affiliate/connections` | admin | - | platform | recent password | api | financial |
| POST | `/api/v1/admin/affiliate/connections/{connection}/disable` | admin | - | platform | recent password | api | financial |
| POST | `/api/v1/admin/affiliate/connections/{connection}/import` | admin | - | platform | recent password | api | financial |
| POST | `/api/v1/admin/affiliate/connections/{connection}/sync` | admin | - | platform | recent password | api | financial |
| POST | `/api/v1/admin/affiliate/connections/{connection}/test` | admin | - | platform | recent password | api | financial |
| GET | `/api/v1/admin/affiliate/programs` | admin | - | platform | - | api | financial |
| PATCH | `/api/v1/admin/affiliate/programs/{program}` | admin | - | platform | recent password | api | financial |
| GET | `/api/v1/admin/affiliate/reward-rules` | admin | - | platform | - | api | financial |
| POST | `/api/v1/admin/affiliate/reward-rules` | admin | - | platform | recent password | api | financial |
| POST | `/api/v1/admin/affiliate/reward-rules/{version}/deactivate` | admin | - | platform | recent password | api | financial |
| GET | `/api/v1/admin/affiliate/transactions` | admin | - | platform | - | api | financial |
| GET | `/api/v1/admin/affiliate/transactions/{transaction}` | admin | - | platform | - | api | financial |
| POST | `/api/v1/admin/affiliate/transactions/{transaction}/resolve` | admin | - | platform | recent password | api | financial |
| GET | `/api/v1/admin/affiliate/webshops/{webshop}/selections` | admin | - | platform | - | api | financial |
| POST | `/api/v1/admin/affiliate/webshops/{webshop}/selections` | admin | - | platform | recent password | api | financial |
| GET | `/api/v1/admin/agreement-templates` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/agreement-templates` | admin | - | platform | recent password | api | admin |
| PATCH | `/api/v1/admin/agreement-templates/{template}` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/agreement-templates/{template}/publish` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/ai/conversations/{conversation}/reply` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/admin/ai/knowledge/{document}/rollback` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/admin/ai/knowledge/{document}/versions` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/ai/operations` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/ai/reviews` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/ai/reviews/{review}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/ai/reviews/{review}/resolve` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/admin/ai/settings/{version}/test` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/admin/answer24` | admin | - | platform | - | api | integration |
| POST | `/api/v1/admin/answer24/messages/{message}/retry` | admin | - | platform | recent password, email code | api | integration |
| GET | `/api/v1/admin/api-routes` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/audit/export` | admin | - | platform | recent password, email code | api,order-export | admin |
| GET | `/api/v1/admin/checkouts` | admin | - | platform | - | api | financial |
| POST | `/api/v1/admin/checkouts/{checkout}/refund` | admin | - | platform | recent password | api | financial |
| POST | `/api/v1/admin/cms/assets` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/cms/cookie-consents/stats` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/cms/cookie-policies` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/cms/cookie-policies` | admin | - | platform | recent password | api | admin |
| PUT | `/api/v1/admin/cms/cookie-policies/{policy}` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/cms/cookie-policies/{policy}/publish` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/cms/faq` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/cms/faq/categories` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/cms/faq/items` | admin | - | platform | recent password | api | admin |
| PUT | `/api/v1/admin/cms/faq/items/{item}` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/cms/faq/reorder` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/cms/pages` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/cms/pages` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/cms/pages/{page}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/archive` | admin | - | platform | recent password | api | admin |
| PUT | `/api/v1/admin/cms/pages/{page}/locales/{locale}` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/cms/pages/{page}/locales/{locale}/preview` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/locales/{locale}/publish` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/locales/{locale}/suggestions` | admin | - | platform | recent password | api,cms-ai | admin |
| GET | `/api/v1/admin/cms/pages/{page}/revisions` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/revisions/{revision}/restore` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/schedules` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/cms/pages/{page}/suggestions` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/cms/schedules` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/cms/schedules/{schedule}/cancel` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/cms/suggestions/{suggestion}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/cms/suggestions/{suggestion}/apply` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/cms/suggestions/{suggestion}/reject` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/cms/translations` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/cockpit` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/disputes` | admin | - | platform | - | api | voucher |
| GET | `/api/v1/admin/disputes/{dispute}` | admin | - | platform | - | api | voucher |
| GET | `/api/v1/admin/disputes/{dispute}/evidence/{file}` | admin | - | platform | - | api | voucher |
| POST | `/api/v1/admin/disputes/{dispute}/resolve` | admin | - | platform | recent password | api | voucher |
| POST | `/api/v1/admin/disputes/{dispute}/status` | admin | - | platform | recent password | api | voucher |
| GET | `/api/v1/admin/financial-oversight` | admin | - | platform | - | api | financial |
| GET | `/api/v1/admin/incident-diagnostics/{diagnostic}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/incident-diagnostics/{diagnostic}/review` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/admin/incidents` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/incidents/{incident}` | admin | - | platform | - | api | admin |
| PATCH | `/api/v1/admin/incidents/{incident}` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/incidents/{incident}/diagnostics` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/incidents/{incident}/diagnostics` | admin | - | platform | recent password, email code | api,admin-diagnostics | admin |
| GET | `/api/v1/admin/integrations` | admin | - | platform | - | api | integration |
| POST | `/api/v1/admin/integrations/events/{event}/retry` | admin | - | platform | recent password, email code | api | integration |
| GET | `/api/v1/admin/integrations/failed-events` | admin | - | platform | - | api | integration |
| GET | `/api/v1/admin/monitoring` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/operations-overview` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/platform-settings` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/platform-settings` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/platform-settings/{version}/publish` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/admin/platform-settings/{version}/review` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/platform-settings/{version}/rollback` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/admin/qa/components` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/qa/coverage` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/qa/endpoints` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/qa/endpoints/{endpoint}` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/qa/evidence/{evidence}` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/qa/findings` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/qa/findings` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/qa/findings/{finding}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/qa/findings/{finding}/analyze` | admin | - | platform | recent password | api,qa-ai | admin |
| POST | `/api/v1/admin/qa/findings/{finding}/fix` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/qa/findings/{finding}/retest` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/qa/frontend-usage` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/qa/inventory/scan` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/qa/openapi.json` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/qa/runs` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/qa/runs` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/qa/runs/{run}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/qa/runs/{run}/evidence` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/qa/runs/{run}/export` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/qa/schedules` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/qa/schedules` | admin | - | platform | recent password | api | admin |
| PATCH | `/api/v1/admin/qa/schedules/{schedule}` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/qa/snapshots` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/qa/summary` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/qa/test-cases` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/qa/test-cases` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/qa/test-cases/approve` | admin | - | platform | recent password | api | admin |
| PUT | `/api/v1/admin/qa/test-cases/{testCase}/coverage` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/reconciliation` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/reconciliation` | admin | - | platform | recent password, email code | api,admin-diagnostics | admin |
| GET | `/api/v1/admin/reconciliation/{run}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/reconciliation/{run}/resume` | admin | - | platform | recent password, email code | api,admin-diagnostics | admin |
| POST | `/api/v1/admin/step-up/code` | admin | - | platform | recent password | api,admin-step-up | admin |
| POST | `/api/v1/admin/step-up/verify` | admin | - | platform | recent password | api,identity-verify-code | admin |
| GET | `/api/v1/admin/support-sessions` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/support-sessions` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/admin/support-sessions/{session}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/support-sessions/{session}/end` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/users` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/users/{user}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/users/{user}/reactivate` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/users/{user}/reset-security` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/admin/users/{user}/revoke-super-admin` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/admin/users/{user}/suspend` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/wallet-adjustments` | admin | - | platform | - | api | financial |
| POST | `/api/v1/admin/wallet-adjustments` | admin | - | platform | recent password, email code | api | financial |
| POST | `/api/v1/admin/wallet-adjustments/{approval}/review` | admin | - | platform | recent password, email code | api | financial |
| GET | `/api/v1/admin/wallet-reconciliation-flags` | admin | - | platform | - | api | financial |
| POST | `/api/v1/admin/wallet-reconciliation-flags/{flag}/resolve` | admin | - | platform | recent password, email code | api | financial |
| GET | `/api/v1/admin/wallets` | admin | - | platform | - | api | financial |
| GET | `/api/v1/admin/wallets/{wallet}/ledger` | admin | - | platform | - | api | financial |
| GET | `/api/v1/admin/webshops` | admin | - | platform | - | api | admin |
| GET | `/api/v1/admin/webshops/{webshop}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/approve` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/admin/webshops/{webshop}/evidence/{file}` | admin | - | platform | - | api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/members/{user}/revoke` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/reject` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/request-information` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/suspend` | admin | - | platform | recent password | api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/unsuspend` | admin | - | platform | recent password | api | admin |
| GET | `/api/v1/affiliate/postback/{token}` | public | - | public | - | webhooks | financial |
| POST | `/api/v1/affiliate/postback/{token}` | public | - | public | - | webhooks | financial |
| POST | `/api/v1/ai/chat` | verified | - | authenticated_user_resource | - | api,ai-support | security_denials |
| GET | `/api/v1/ai/conversations` | verified | - | authenticated_user_resource | - | api | security_denials |
| DELETE | `/api/v1/ai/conversations/{conversation}` | verified | - | authenticated_user_resource | - | api | security_denials |
| GET | `/api/v1/ai/conversations/{conversation}` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/ai/knowledge/search` | verified | - | authenticated_user_resource | - | api,ai-support | security_denials |
| GET | `/api/v1/ai/messages/{message}` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/ai/messages/{message}/escalate` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/ai/messages/{message}/feedback` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/auth/email/resend-code` | authenticated | - | authenticated_user_resource | - | api,identity-resend-code | security_denials |
| POST | `/api/v1/auth/email/verify-code` | authenticated | - | authenticated_user_resource | - | api,identity-verify-code | security_denials |
| GET | `/api/v1/banners/assets/{id}` | public | - | public | - | banner-embed | security_denials |
| POST | `/api/v1/banners/assignments` | public | - | public | - | banner-embed | security_denials |
| GET | `/api/v1/banners/config` | public | - | public | - | banner-embed | security_denials |
| GET | `/api/v1/banners/editor/campaigns/{banner}` | public | - | public | - | banner-embed | security_denials |
| PUT | `/api/v1/banners/editor/campaigns/{banner}` | public | - | public | - | banner-embed | security_denials |
| POST | `/api/v1/banners/editor/pair` | public | - | public | - | banner-embed | security_denials |
| POST | `/api/v1/banners/events` | public | - | public | - | banner-embed | security_denials |
| GET | `/api/v1/business-directory/places/{placeId}` | verified | - | authenticated_user_resource | - | api,business-search | security_denials |
| GET | `/api/v1/business-directory/search` | verified | - | authenticated_user_resource | - | api,business-search | security_denials |
| POST | `/api/v1/claims/accept` | verified | - | authenticated_user_resource | - | api,claims | security_denials |
| POST | `/api/v1/claims/preview` | public | - | public | - | api,claims | security_denials |
| GET | `/api/v1/consumer/affiliate-activity` | verified | - | authenticated_user_resource | - | api | financial |
| POST | `/api/v1/consumer/checkouts` | verified | - | authenticated_user_resource | - | api,checkout | financial |
| POST | `/api/v1/consumer/checkouts/quote` | verified | - | authenticated_user_resource | - | api | financial |
| GET | `/api/v1/consumer/checkouts/{id}` | verified | - | authenticated_user_resource | - | api | financial |
| GET | `/api/v1/consumer/dashboard` | verified | - | authenticated_user_resource | - | api | security_denials |
| GET | `/api/v1/consumer/disputes` | verified | - | authenticated_user_resource | - | api | voucher |
| POST | `/api/v1/consumer/disputes` | verified | - | authenticated_user_resource | - | api,checkout | voucher |
| GET | `/api/v1/consumer/disputes/{id}` | verified | - | authenticated_user_resource | - | api | voucher |
| DELETE | `/api/v1/consumer/email-change` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/consumer/email-change` | verified | - | authenticated_user_resource | - | api,email-change | security_denials |
| POST | `/api/v1/consumer/email-change/confirm` | verified | - | authenticated_user_resource | - | api,identity-verify-code | security_denials |
| GET | `/api/v1/consumer/favorites` | verified | - | authenticated_user_resource | - | api | security_denials |
| DELETE | `/api/v1/consumer/favorites/{slug}` | verified | - | authenticated_user_resource | - | api | security_denials |
| PUT | `/api/v1/consumer/favorites/{slug}` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/consumer/offers/{slug}/claim` | verified | - | authenticated_user_resource | - | api,claims | security_denials |
| GET | `/api/v1/consumer/preferences` | verified | - | authenticated_user_resource | - | api | security_denials |
| PUT | `/api/v1/consumer/preferences` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/consumer/privacy/deletion-request` | verified | - | authenticated_user_resource | - | api,privacy | security_denials |
| GET | `/api/v1/consumer/privacy/export` | verified | - | authenticated_user_resource | - | api,privacy | security_denials |
| GET | `/api/v1/consumer/privacy/requests` | verified | - | authenticated_user_resource | - | api | security_denials |
| GET | `/api/v1/consumer/profile` | verified | - | authenticated_user_resource | - | api | security_denials |
| PATCH | `/api/v1/consumer/profile` | verified | - | authenticated_user_resource | - | api | security_denials |
| DELETE | `/api/v1/consumer/profile/avatar` | verified | - | authenticated_user_resource | - | api | security_denials |
| GET | `/api/v1/consumer/profile/avatar` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/consumer/profile/avatar` | verified | - | authenticated_user_resource | - | api,profile-upload | security_denials |
| GET | `/api/v1/consumer/vouchers` | verified | - | authenticated_user_resource | - | api | voucher |
| GET | `/api/v1/consumer/vouchers/{id}` | verified | - | authenticated_user_resource | - | api,code-reveal | voucher |
| GET | `/api/v1/consumer/wallet` | verified | - | authenticated_user_resource | - | api | financial |
| GET | `/api/v1/consumer/wallet/transactions` | verified | - | authenticated_user_resource | - | api | financial |
| GET | `/api/v1/consumer/wallet/transactions/{id}` | verified | - | authenticated_user_resource | - | api | financial |
| GET | `/api/v1/health` | public | - | public | - | api | security_denials |
| GET | `/api/v1/health/ready` | public | - | public | - | api | security_denials |
| GET | `/api/v1/integrations/answer24/claims/{claim}` | integration | - | integration_scopes | - | integrations | integration |
| POST | `/api/v1/integrations/answer24/claims/{claim}/reminders` | integration | - | integration_scopes | - | integrations | integration |
| POST | `/api/v1/integrations/answer24/contact-results` | integration | - | integration_scopes | - | integrations | integration |
| POST | `/api/v1/integrations/answer24/handoffs` | integration | - | integration_scopes | - | integrations | integration |
| GET | `/api/v1/integrations/platforms` | verified | - | authenticated_user_resource | - | api | integration |
| POST | `/api/v1/integrations/shopify/compliance` | public | - | public | - | shopify-compliance | integration |
| GET | `/api/v1/integrations/shopify/oauth/callback` | verified | - | authenticated_user_resource | - | commerce-probe | integration |
| GET | `/api/v1/integrations/shopware/app/registration` | public | - | public | - | shopware-app | integration |
| POST | `/api/v1/integrations/shopware/app/registration/confirm` | public | - | public | - | shopware-app | integration |
| POST | `/api/v1/integrations/shopware/app/webhook` | public | - | public | - | webhooks-shopware-app | integration |
| POST | `/api/v1/invitations/accept` | verified | - | authenticated_user_resource | - | api,invitation-preview | security_denials |
| POST | `/api/v1/invitations/preview` | public | - | public | - | api,invitation-preview | security_denials |
| GET | `/api/v1/me` | authenticated | - | authenticated_user_resource | - | api | security_denials |
| GET | `/api/v1/notification-preferences` | verified | - | authenticated_user_resource | - | api | security_denials |
| PUT | `/api/v1/notification-preferences` | verified | - | authenticated_user_resource | - | api | security_denials |
| GET | `/api/v1/notifications` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/notifications/read-all` | verified | - | authenticated_user_resource | - | api | security_denials |
| DELETE | `/api/v1/notifications/{notification}` | verified | - | authenticated_user_resource | - | api | security_denials |
| PATCH | `/api/v1/notifications/{notification}/read` | verified | - | authenticated_user_resource | - | api | security_denials |
| GET | `/api/v1/owner/dashboard` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/payments/mollie/webhook` | public | - | public | - | webhooks | integration |
| GET | `/api/v1/platform/notifications/deliveries` | admin | - | platform | - | api | admin |
| POST | `/api/v1/platform/notifications/deliveries/{delivery}/resolve` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/platform/notifications/deliveries/{delivery}/retry` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/platform/notifications/events` | admin | - | platform | - | api | admin |
| POST | `/api/v1/platform/notifications/events/{event}/retry` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/platform/notifications/settings` | admin | - | platform | - | api | admin |
| PUT | `/api/v1/platform/notifications/settings` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/platform/notifications/templates` | admin | - | platform | - | api | admin |
| POST | `/api/v1/platform/notifications/templates` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/platform/notifications/templates/generate` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/platform/notifications/templates/{template}` | admin | - | platform | - | api | admin |
| PUT | `/api/v1/platform/notifications/templates/{template}` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/platform/notifications/templates/{template}/publish` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/platform/notifications/templates/{template}/rollback` | admin | - | platform | recent password, email code | api | admin |
| POST | `/api/v1/platform/notifications/templates/{template}/test` | admin | - | platform | recent password, email code | api | admin |
| GET | `/api/v1/public/categories` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/content-assets/{id}` | public | - | public | - | api | security_denials |
| POST | `/api/v1/public/cookie-consents` | public | - | public | - | api,claims | security_denials |
| GET | `/api/v1/public/cookie-consents/{consentId}` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/cookie-policy` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/faq` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/legal-requirements` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/offers` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/offers/{slug}` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/pages` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/pages/{locale}/{slug}` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/settings` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/webshops` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/webshops/{slug}` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/webshops/{slug}/logo` | public | - | public | - | api | security_denials |
| GET | `/api/v1/public/webshops/{slug}/visit` | public | - | public | - | api,visits | security_denials |
| DELETE | `/api/v1/support-session` | authenticated | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/webhooks/{platform}/{token}` | public | - | public | - | webhooks | integration |
| GET | `/api/v1/workspaces` | verified | - | authenticated_user_resource | - | api | security_denials |
| POST | `/api/v1/workspaces` | verified | - | authenticated_user_resource | - | api,workspace-create | security_denials |
| GET | `/api/v1/workspaces/{workspace}` | verified | - | workspace_membership | - | api | security_denials |
| PATCH | `/api/v1/workspaces/{workspace}` | verified | settings.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/agreement` | verified | settings.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/agreement/accept` | verified | agreements.accept | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/analytics/claims` | verified | dashboard.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/analytics/overview` | verified | dashboard.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/answer24/opportunities` | verified | orders.view | workspace_membership | - | api | integration |
| GET | `/api/v1/workspaces/{workspace}/answer24/settings` | verified | settings.view | workspace_membership | - | api | integration |
| PUT | `/api/v1/workspaces/{workspace}/answer24/settings` | verified | agreements.accept | workspace_membership | - | api | integration |
| POST | `/api/v1/workspaces/{workspace}/answer24/suppressions` | verified | settings.manage | workspace_membership | - | api | integration |
| GET | `/api/v1/workspaces/{workspace}/banners` | verified | offers.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/assets` | verified | offers.manage | workspace_membership | - | api,profile-upload | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/installation` | verified | offers.view | workspace_membership | - | api | security_denials |
| PUT | `/api/v1/workspaces/{workspace}/banners/installation` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/pairing` | verified | offers.manage | workspace_membership | - | api,checkout | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}` | verified | offers.view | workspace_membership | - | api | security_denials |
| PUT | `/api/v1/workspaces/{workspace}/banners/{banner}` | verified | offers.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/ai-requests` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/ai-requests` | verified | offers.manage | workspace_membership | - | api,checkout | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/ai-requests/{run}` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/ai-requests/{run}/review` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/duplicate` | verified | offers.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/experiment` | verified | offers.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/experiment` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/experiment/stop` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/pause` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/publish` | verified | offers.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/results` | verified | offers.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/resume` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/rollback` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/schedule` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/simulate` | verified | offers.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/versions` | verified | offers.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/business-profile` | verified | settings.view | workspace_membership | - | api | security_denials |
| PUT | `/api/v1/workspaces/{workspace}/business-profile` | verified | settings.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/code-imports/{batch}` | verified | vouchers.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/code-imports/{batch}/commit` | verified | vouchers.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/codes/{code}/history` | verified | vouchers.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/codes/{code}/invalidate` | verified | vouchers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/codes/{code}/reveal` | verified | vouchers.manage | workspace_membership | - | api,code-reveal | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections` | verified | integrations.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections` | verified | integrations.manage | workspace_membership | - | api | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/connections/{connection}` | verified | integrations.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}` | verified | integrations.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/capabilities` | verified | integrations.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/catalog-syncs` | verified | integrations.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/catalog-syncs` | verified | integrations.manage | workspace_membership | - | api,commerce-probe | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/coupons` | verified | integrations.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/credentials` | verified | integrations.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/events` | verified | integrations.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/events/{event}/retry` | verified | integrations.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/products` | verified | integrations.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/test-api` | verified | integrations.manage | workspace_membership | - | api,commerce-probe | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/verify` | verified | integrations.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/dashboard` | verified | dashboard.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/disputes` | verified | vouchers.view | workspace_membership | - | api | voucher |
| POST | `/api/v1/workspaces/{workspace}/disputes/{dispute}/respond` | verified | vouchers.manage | workspace_membership | - | api | voucher |
| GET | `/api/v1/workspaces/{workspace}/funding` | verified | finance.view | workspace_membership | - | api | financial |
| GET | `/api/v1/workspaces/{workspace}/funding/obligations` | verified | finance.view | workspace_membership | - | api | financial |
| POST | `/api/v1/workspaces/{workspace}/funding/top-ups` | verified | finance.manage | workspace_membership | - | api,checkout | financial |
| GET | `/api/v1/workspaces/{workspace}/funding/top-ups/{topUp}` | verified | finance.view | workspace_membership | - | api | financial |
| GET | `/api/v1/workspaces/{workspace}/funding/transactions` | verified | finance.view | workspace_membership | - | api | financial |
| POST | `/api/v1/workspaces/{workspace}/integrations/shopify/oauth` | verified | integrations.manage | workspace_membership | - | api,commerce-probe | integration |
| GET | `/api/v1/workspaces/{workspace}/invitations` | verified | team.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/invitations` | verified | team.manage | workspace_membership | - | api,invitation-create | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/invitations/{invitation}` | verified | team.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/invitations/{invitation}/resend` | verified | team.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/invoices` | verified | finance.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/members` | verified | team.view | workspace_membership | - | api | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/members/{member}` | verified | team.manage | workspace_membership | - | api | security_denials |
| PATCH | `/api/v1/workspaces/{workspace}/members/{member}` | verified | team.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/members/{member}/reactivate` | verified | team.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/members/{member}/suspend` | verified | team.manage | workspace_membership | - | api | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/membership` | verified | - | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/notifications` | verified | dashboard.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/notifications/read-all` | verified | dashboard.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/notifications/{notification}/read` | verified | dashboard.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers` | verified | offers.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers` | verified | offers.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers/{offer}` | verified | offers.view | workspace_membership | - | api | security_denials |
| PATCH | `/api/v1/workspaces/{workspace}/offers/{offer}` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/activate` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/code-imports` | verified | vouchers.manage | workspace_membership | - | api,code-import | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers/{offer}/codes` | verified | vouchers.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers/{offer}/codes/export` | verified | vouchers.export | workspace_membership | - | api,order-export | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/end` | verified | offers.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/pause` | verified | offers.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/onboarding` | verified | - | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/orders` | verified | orders.view | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/orders/export` | verified | orders.export | workspace_membership | - | api,order-export | security_denials |
| GET | `/api/v1/workspaces/{workspace}/orders/{order}` | verified | orders.view | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership/review` | verified | ownership.manage | workspace_membership | - | api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/ownership/verifications` | verified | ownership.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership/verifications` | verified | ownership.manage | workspace_membership | - | api,ownership-check | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership/verifications/{verification}/check` | verified | ownership.manage | workspace_membership | - | api,ownership-check | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership/verifications/{verification}/confirm-code` | verified | ownership.manage | workspace_membership | - | api,ownership-check | security_denials |
| GET | `/api/v1/workspaces/{workspace}/public-profile` | verified | settings.view | workspace_membership | - | api | security_denials |
| PUT | `/api/v1/workspaces/{workspace}/public-profile` | verified | settings.manage | workspace_membership | - | api | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/public-profile/logo` | verified | settings.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/public-profile/logo` | verified | settings.manage | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/select` | verified | - | workspace_membership | - | api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/vouchers/redeem` | verified | vouchers.manage | workspace_membership | - | api,code-reveal | voucher |
