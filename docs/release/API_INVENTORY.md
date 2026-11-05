# API route inventory (Trello #22)

Generated 2026-11-05 using `php artisan shopbonus:api-routes --markdown` from runtime routes and OpenAPI.

Runtime route entries: **492**. Method/path operations (HEAD aliases excluded): **493**. Unique paths: **431**.

Access counts are per operation: admin 248, authenticated 4, integration 4, public 45, verified 192. Runtime access and workspace permission annotations match OpenAPI for every listed operation.

A route entry may accept multiple methods. GET and POST `/api/v1/affiliate/postback/{token}` are separate operations on one entry; HEAD aliases are not additional operations.

`admin` means the platform-role middleware, not necessarily Super Admin alone: the Roles column lists accepted roles, including QA/developer routes. Workspace permissions and scopes remain server-enforced; foreign workspaces use the audited404 policy. Public means no Sanctum role gate; provider signature/authentication checks may still occur inside handlers.

Rate-limit cells name expanded middleware, not deployment-specific numeric limits. Authentication, scope and audit-category columns are registry classifications, not proof of successful authentication, audited execution, provider installation or release approval. See `docs/release/INDEPENDENT_ACCEPTANCE.md` for outstanding gates.

Regenerate before release using a temporary file, check command exit0, then replace this document. JSON output without `--markdown` is unchanged. `RoleAccessMatrixTest` checks protected-route middleware outcomes with unknown resource IDs; it is not every successful business action or independent provider evidence.

| Method | Path | Access | Roles | Authentication (route gate) | Permission | Scope | Step-up | Rate-limit middleware | Audit classification |
|---|---|---|---|---|---|---|---|---|---|
| GET | `/api/v1/admin/activity` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/affiliate/connections` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/connections` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/connections/{connection}/disable` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/connections/{connection}/import` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/connections/{connection}/sync` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/connections/{connection}/test` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/affiliate/programs` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| PATCH | `/api/v1/admin/affiliate/programs/{program}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/affiliate/reward-rules` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/reward-rules` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/reward-rules/{version}/deactivate` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/affiliate/transactions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/affiliate/transactions/{transaction}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/transactions/{transaction}/resolve` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/webshops/{webshop}/commission-agreements` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/webshops/{webshop}/commission-agreements/{version}/deactivate` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/webshops/{webshop}/commission-credits` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/affiliate/webshops/{webshop}/selections` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/affiliate/webshops/{webshop}/selections` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/agreement-templates` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/agreement-templates` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/agreement-templates/{template}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/agreement-templates/{template}/publish` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/ai/connectivity/browser-probe` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api, ThrottleRequests:admin-diagnostics | admin |
| POST | `/api/v1/admin/ai/connectivity/browser-probe/{nonce}/ack` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/ai/conversations/{conversation}/reply` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/ai/knowledge/{document}/rollback` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/ai/knowledge/{document}/versions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/ai/operations` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/ai/reviews` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/ai/reviews/{review}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/ai/reviews/{review}/resolve` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/ai/settings/{version}/test` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/answer24` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | integration |
| POST | `/api/v1/admin/answer24/messages/{message}/retry` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | integration |
| GET | `/api/v1/admin/api-routes` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/audit/export` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api, ThrottleRequests:order-export | admin |
| GET | `/api/v1/admin/checkouts` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/checkouts/{checkout}/refund` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/cms/assets` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/assets` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/assets/{asset}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/cms/assets/{asset}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/assets/{asset}/original` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/assets/{asset}/recipes` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/assets/{asset}/replace` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/assets/{asset}/restore` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/assets/{asset}/usages` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/cookie-consents/stats` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/cookie-policies` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/cookie-policies` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PUT | `/api/v1/admin/cms/cookie-policies/{policy}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/cookie-policies/{policy}/publish` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/faq` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/faq/categories` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/cms/faq/categories/{category}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/faq/items` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PUT | `/api/v1/admin/cms/faq/items/{item}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/faq/reorder` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/pages` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/pages` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/pages/{page}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/archive` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PUT | `/api/v1/admin/cms/pages/{page}/locales/{locale}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/pages/{page}/locales/{locale}/bindings` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/pages/{page}/locales/{locale}/media-preview` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/pages/{page}/locales/{locale}/preview` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/locales/{locale}/publish` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/locales/{locale}/shared-blocks` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/locales/{locale}/shared-blocks/detach` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/locales/{locale}/suggestions` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api, ThrottleRequests:cms-ai | admin |
| GET | `/api/v1/admin/cms/pages/{page}/revisions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/revisions/{revision}/restore` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/pages/{page}/schedules` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/pages/{page}/suggestions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/schedules` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/schedules/{schedule}/cancel` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/shared-blocks` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/shared-blocks` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/shared-blocks/{block}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/shared-blocks/{block}/usages` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/shared-blocks/{block}/versions` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/suggestions/{suggestion}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/suggestions/{suggestion}/apply` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/suggestions/{suggestion}/reject` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/cms/suggestions/{suggestion}/review` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cms/translations` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/cockpit` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/homepage` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| PUT | `/api/v1/admin/content/homepage` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/homepage/ai-suggest` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/homepage/assets` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/homepage/preview` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/homepage/publish` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/homepage/restore` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/homepage/schedule` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/content/homepage/sections/{sectionKey}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/homepage/versions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/over-ons` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| PUT | `/api/v1/admin/content/over-ons` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/over-ons/ai-suggest` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/over-ons/assets` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/over-ons/preview` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/over-ons/publish` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/over-ons/restore` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/over-ons/schedule` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/content/over-ons/sections/{sectionKey}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/over-ons/versions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/pages/over-ons` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| PUT | `/api/v1/admin/content/pages/over-ons` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/over-ons/ai-suggest` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/over-ons/assets` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/over-ons/preview` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/over-ons/publish` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/over-ons/restore` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/over-ons/schedule` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/content/pages/over-ons/sections/{sectionKey}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/pages/over-ons/versions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/pages/voor-webshops` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| PUT | `/api/v1/admin/content/pages/voor-webshops` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/voor-webshops/ai-suggest` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/pages/voor-webshops/assets` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/voor-webshops/assets` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/voor-webshops/preview` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/voor-webshops/publish` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/voor-webshops/restore` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/pages/voor-webshops/schedule` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/content/pages/voor-webshops/sections/{sectionKey}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/pages/voor-webshops/versions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/voor-webshops` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| PUT | `/api/v1/admin/content/voor-webshops` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/voor-webshops/ai-suggest` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/voor-webshops/assets` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/voor-webshops/assets` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/voor-webshops/preview` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/voor-webshops/publish` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/voor-webshops/restore` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/content/voor-webshops/schedule` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/content/voor-webshops/sections/{sectionKey}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/content/voor-webshops/versions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/disputes` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/admin/disputes/{dispute}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/admin/disputes/{dispute}/evidence/{file}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | voucher |
| POST | `/api/v1/admin/disputes/{dispute}/resolve` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | voucher |
| POST | `/api/v1/admin/disputes/{dispute}/status` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | voucher |
| GET | `/api/v1/admin/financial-oversight` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/incident-diagnostics/{diagnostic}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/incident-diagnostics/{diagnostic}/review` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/incidents` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/incidents/{incident}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/incidents/{incident}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/incidents/{incident}/diagnostics` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/incidents/{incident}/diagnostics` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api, ThrottleRequests:admin-diagnostics | admin |
| GET | `/api/v1/admin/integrations` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | integration |
| POST | `/api/v1/admin/integrations/events/{event}/retry` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | integration |
| GET | `/api/v1/admin/integrations/failed-events` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | integration |
| GET | `/api/v1/admin/merchant-enrichments` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/merchant-enrichments/{run}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/merchant-enrichments/{run}/approve` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/merchant-enrichments/{run}/reject` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/merchant-enrichments/{run}/retry` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/monitoring` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/network` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/operations-overview` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/platform-settings` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/platform-settings` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/platform-settings/{version}/publish` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/platform-settings/{version}/review` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/platform-settings/{version}/rollback` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/components` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/coverage` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/endpoints` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/endpoints/{endpoint}` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/evidence/{evidence}` | admin | super_admin, qa_tester, developer | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/findings` | admin | super_admin, qa_tester, developer | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/findings` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/findings/{finding}` | admin | super_admin, qa_tester, developer | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/findings/{finding}/analyze` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api, ThrottleRequests:qa-ai | admin |
| POST | `/api/v1/admin/qa/findings/{finding}/fix` | admin | super_admin, developer | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/findings/{finding}/retest` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/frontend-usage` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/inventory/scan` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/openapi.json` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/runs` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/runs` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/runs/{run}` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/runs/{run}/evidence` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/runs/{run}/export` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/schedules` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/schedules` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PATCH | `/api/v1/admin/qa/schedules/{schedule}` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/snapshots` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/summary` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/qa/test-cases` | admin | super_admin, qa_tester | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/test-cases` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/qa/test-cases/approve` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| PUT | `/api/v1/admin/qa/test-cases/{testCase}/coverage` | admin | super_admin, qa_tester | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/reconciliation` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/reconciliation` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api, ThrottleRequests:admin-diagnostics | admin |
| GET | `/api/v1/admin/reconciliation/{run}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/reconciliation/{run}/resume` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api, ThrottleRequests:admin-diagnostics | admin |
| GET | `/api/v1/admin/search` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api, ThrottleRequests:business-search | admin |
| POST | `/api/v1/admin/step-up/code` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api, ThrottleRequests:admin-step-up | admin |
| POST | `/api/v1/admin/step-up/verify` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api, ThrottleRequests:identity-verify-code | admin |
| GET | `/api/v1/admin/support-sessions` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/support-sessions` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/support-sessions/{session}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/support-sessions/{session}/end` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/users` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/users/{user}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/users/{user}/reactivate` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/users/{user}/reset-security` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/users/{user}/revoke-super-admin` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/users/{user}/suspend` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/vouchers` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/admin/wallet-adjustments` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/wallet-adjustments` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/wallet-adjustments/{approval}/review` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/wallet-reconciliation-flags` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| POST | `/api/v1/admin/wallet-reconciliation-flags/{flag}/resolve` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/wallets` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/wallets/{wallet}/ledger` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | financial |
| GET | `/api/v1/admin/webshops` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/webshops/{webshop}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/approve` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/webshops/{webshop}/commission-agreements` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| GET | `/api/v1/admin/webshops/{webshop}/evidence/{file}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/members/{user}/revoke` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/reject` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/request-information` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/suspend` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| POST | `/api/v1/admin/webshops/{webshop}/unsuspend` | admin | super_admin | session_cookie | - | platform | recent password | ThrottleRequests:api | admin |
| GET | `/api/v1/affiliate/postback/{token}` | public | - | none | - | public | - | ThrottleRequests:webhooks | financial |
| POST | `/api/v1/affiliate/postback/{token}` | public | - | none | - | public | - | ThrottleRequests:webhooks | financial |
| POST | `/api/v1/ai/chat` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:ai-support | security_denials |
| GET | `/api/v1/ai/conversations` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| DELETE | `/api/v1/ai/conversations/{conversation}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/ai/conversations/{conversation}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/ai/knowledge/search` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:ai-support | security_denials |
| GET | `/api/v1/ai/messages/{message}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/ai/messages/{message}/escalate` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/ai/messages/{message}/feedback` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/auth/email/resend-code` | authenticated | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:identity-resend-code | security_denials |
| POST | `/api/v1/auth/email/verify-code` | authenticated | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:identity-verify-code | security_denials |
| POST | `/api/v1/auth/step-up/code` | verified | - | session_cookie | - | authenticated_user_resource | recent password | ThrottleRequests:api, ThrottleRequests:admin-step-up | security_denials |
| POST | `/api/v1/auth/step-up/verify` | verified | - | session_cookie | - | authenticated_user_resource | recent password | ThrottleRequests:api, ThrottleRequests:identity-verify-code | security_denials |
| GET | `/api/v1/banners/assets/{id}` | public | - | none | - | public | - | ThrottleRequests:banner-embed | security_denials |
| POST | `/api/v1/banners/assignments` | public | - | none | - | public | - | ThrottleRequests:banner-embed | security_denials |
| GET | `/api/v1/banners/config` | public | - | none | - | public | - | ThrottleRequests:banner-embed | security_denials |
| GET | `/api/v1/banners/editor/campaigns/{banner}` | public | - | none | - | public | - | ThrottleRequests:banner-embed | security_denials |
| PUT | `/api/v1/banners/editor/campaigns/{banner}` | public | - | none | - | public | - | ThrottleRequests:banner-embed | security_denials |
| POST | `/api/v1/banners/editor/pair` | public | - | none | - | public | - | ThrottleRequests:banner-embed | security_denials |
| POST | `/api/v1/banners/events` | public | - | none | - | public | - | ThrottleRequests:banner-embed | security_denials |
| GET | `/api/v1/business-directory/places/{placeId}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:business-search | security_denials |
| GET | `/api/v1/business-directory/search` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:business-search | security_denials |
| POST | `/api/v1/claims/accept` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:claims | security_denials |
| POST | `/api/v1/claims/preview` | public | - | none | - | public | - | ThrottleRequests:api, ThrottleRequests:claims | security_denials |
| POST | `/api/v1/consumer/activation/complete` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:claims | security_denials |
| POST | `/api/v1/consumer/activation/verify` | public | - | none | - | public | - | ThrottleRequests:api, ThrottleRequests:claims | security_denials |
| GET | `/api/v1/consumer/affiliate-activity` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | financial |
| POST | `/api/v1/consumer/checkouts` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:checkout | financial |
| POST | `/api/v1/consumer/checkouts/quote` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | financial |
| GET | `/api/v1/consumer/checkouts/{id}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | financial |
| GET | `/api/v1/consumer/dashboard` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/consumer/disputes` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | voucher |
| POST | `/api/v1/consumer/disputes` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:checkout | voucher |
| GET | `/api/v1/consumer/disputes/{id}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | voucher |
| DELETE | `/api/v1/consumer/email-change` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/consumer/email-change` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:email-change | security_denials |
| POST | `/api/v1/consumer/email-change/confirm` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:identity-verify-code | security_denials |
| GET | `/api/v1/consumer/favorites` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| DELETE | `/api/v1/consumer/favorites/{slug}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| PUT | `/api/v1/consumer/favorites/{slug}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/consumer/offers/{slug}/claim` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:claims | security_denials |
| GET | `/api/v1/consumer/preferences` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| PATCH | `/api/v1/consumer/preferences` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| PUT | `/api/v1/consumer/preferences` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/consumer/privacy/deletion-request` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:privacy | security_denials |
| GET | `/api/v1/consumer/privacy/export` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:privacy | security_denials |
| GET | `/api/v1/consumer/privacy/requests` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/consumer/profile` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| PATCH | `/api/v1/consumer/profile` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| DELETE | `/api/v1/consumer/profile/avatar` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/consumer/profile/avatar` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/consumer/profile/avatar` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:profile-upload | security_denials |
| GET | `/api/v1/consumer/vouchers` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/consumer/vouchers/{id}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:code-reveal | voucher |
| GET | `/api/v1/consumer/wallet` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | financial |
| GET | `/api/v1/consumer/wallet/transactions` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | financial |
| GET | `/api/v1/consumer/wallet/transactions/{id}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | financial |
| GET | `/api/v1/content/homepage` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/content/over-ons` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/content/pages/over-ons` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/content/pages/voor-webshops` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/content/voor-webshops` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/health` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/health/ready` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/integrations/answer24/claims/{claim}` | integration | - | hmac_signature | - | integration_scopes | - | ThrottleRequests:integrations | integration |
| POST | `/api/v1/integrations/answer24/claims/{claim}/reminders` | integration | - | hmac_signature | - | integration_scopes | - | ThrottleRequests:integrations | integration |
| POST | `/api/v1/integrations/answer24/contact-results` | integration | - | hmac_signature | - | integration_scopes | - | ThrottleRequests:integrations | integration |
| POST | `/api/v1/integrations/answer24/handoffs` | integration | - | hmac_signature | - | integration_scopes | - | ThrottleRequests:integrations | integration |
| GET | `/api/v1/integrations/platforms` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | integration |
| POST | `/api/v1/integrations/shopify/compliance` | public | - | none | - | public | - | ThrottleRequests:shopify-compliance | integration |
| GET | `/api/v1/integrations/shopify/oauth/callback` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:commerce-probe | integration |
| GET | `/api/v1/integrations/shopware/app/registration` | public | - | none | - | public | - | ThrottleRequests:shopware-app | integration |
| POST | `/api/v1/integrations/shopware/app/registration/confirm` | public | - | none | - | public | - | ThrottleRequests:shopware-app | integration |
| POST | `/api/v1/integrations/shopware/app/webhook` | public | - | none | - | public | - | ThrottleRequests:webhooks-shopware-app | integration |
| POST | `/api/v1/invitations/accept` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:invitation-preview | security_denials |
| POST | `/api/v1/invitations/preview` | public | - | none | - | public | - | ThrottleRequests:api, ThrottleRequests:invitation-preview | security_denials |
| GET | `/api/v1/me` | authenticated | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/me/orders/{reference}/reward` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/me/voucher-quotes/{offerId}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/me/vouchers` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/me/wallet` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | financial |
| GET | `/api/v1/notification-preferences` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| PUT | `/api/v1/notification-preferences` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/notifications` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/notifications/read-all` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| DELETE | `/api/v1/notifications/{notification}` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| PATCH | `/api/v1/notifications/{notification}/read` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/owner/dashboard` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/payments/mollie/webhook` | public | - | none | - | public | - | ThrottleRequests:webhooks | integration |
| GET | `/api/v1/platform/notifications/deliveries` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/platform/notifications/deliveries/{delivery}/resolve` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/platform/notifications/deliveries/{delivery}/retry` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/platform/notifications/events` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/platform/notifications/events/{event}/retry` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/platform/notifications/settings` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| PUT | `/api/v1/platform/notifications/settings` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/platform/notifications/templates` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| POST | `/api/v1/platform/notifications/templates` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/platform/notifications/templates/generate` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/platform/notifications/templates/{template}` | admin | super_admin | session_cookie, bearer_token | - | platform | - | ThrottleRequests:api | admin |
| PUT | `/api/v1/platform/notifications/templates/{template}` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/platform/notifications/templates/{template}/publish` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/platform/notifications/templates/{template}/rollback` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| POST | `/api/v1/platform/notifications/templates/{template}/test` | admin | super_admin | session_cookie | - | platform | recent password, email code | ThrottleRequests:api | admin |
| GET | `/api/v1/public/categories` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/content-assets/{id}` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/content-assets/{id}/recipes/{recipe}/{breakpoint}` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/public/cookie-consents` | public | - | none | - | public | - | ThrottleRequests:api, ThrottleRequests:claims | security_denials |
| GET | `/api/v1/public/cookie-consents/{consentId}` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/cookie-policy` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/discount-codes` | public | - | none | - | public | - | ThrottleRequests:api, ThrottleRequests:public-search | security_denials |
| GET | `/api/v1/public/faq` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/legal-requirements` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/offers` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/offers/{slug}` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/pages` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/pages/{locale}/{slug}` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/search` | public | - | none | - | public | - | ThrottleRequests:api, ThrottleRequests:public-search | security_denials |
| GET | `/api/v1/public/settings` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/webshops` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/webshops/{slug}` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/webshops/{slug}/cover` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/webshops/{slug}/logo` | public | - | none | - | public | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/public/webshops/{slug}/visit` | public | - | none | - | public | - | ThrottleRequests:api, ThrottleRequests:visits | security_denials |
| DELETE | `/api/v1/support-session` | authenticated | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/webhooks/{platform}/{token}` | public | - | none | - | public | - | ThrottleRequests:webhooks | integration |
| GET | `/api/v1/workspaces` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces` | verified | - | session_cookie, bearer_token | - | authenticated_user_resource | - | ThrottleRequests:api, ThrottleRequests:workspace-create | security_denials |
| GET | `/api/v1/workspaces/{workspace}` | verified | - | session_cookie, bearer_token | - | workspace_membership | - | ThrottleRequests:api | security_denials |
| PATCH | `/api/v1/workspaces/{workspace}` | verified | - | session_cookie, bearer_token | settings.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/access-history` | verified | - | session_cookie, bearer_token | team.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/agreement` | verified | - | session_cookie, bearer_token | settings.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/agreement/accept` | verified | - | session_cookie | agreements.accept | workspace_membership | recent password, email code | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/analytics/attribution/orders` | verified | - | session_cookie, bearer_token | dashboard.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/analytics/claims` | verified | - | session_cookie, bearer_token | dashboard.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/analytics/overview` | verified | - | session_cookie, bearer_token | dashboard.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/answer24/opportunities` | verified | - | session_cookie, bearer_token | orders.view | workspace_membership | - | ThrottleRequests:api | integration |
| GET | `/api/v1/workspaces/{workspace}/answer24/settings` | verified | - | session_cookie, bearer_token | settings.view | workspace_membership | - | ThrottleRequests:api | integration |
| PUT | `/api/v1/workspaces/{workspace}/answer24/settings` | verified | - | session_cookie | agreements.accept | workspace_membership | recent password, email code | ThrottleRequests:api | integration |
| POST | `/api/v1/workspaces/{workspace}/answer24/suppressions` | verified | - | session_cookie, bearer_token | settings.manage | workspace_membership | - | ThrottleRequests:api | integration |
| GET | `/api/v1/workspaces/{workspace}/banners` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/assets` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:profile-upload | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/installation` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| PUT | `/api/v1/workspaces/{workspace}/banners/installation` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/pairing` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:checkout | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| PUT | `/api/v1/workspaces/{workspace}/banners/{banner}` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/ai-requests` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/ai-requests` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:checkout | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/ai-requests/{run}` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/ai-requests/{run}/review` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/duplicate` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/experiment` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/experiment` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/experiment/stop` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/pause` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/publish` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/results` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/resume` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/rollback` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/schedule` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/banners/{banner}/simulate` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/banners/{banner}/versions` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/business-profile` | verified | - | session_cookie, bearer_token | settings.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| PUT | `/api/v1/workspaces/{workspace}/business-profile` | verified | - | session_cookie, bearer_token | settings.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/campaigns/{campaign}/performance` | verified | - | session_cookie, bearer_token | orders.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/code-imports/{batch}` | verified | - | session_cookie, bearer_token | vouchers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/code-imports/{batch}/commit` | verified | - | session_cookie, bearer_token | vouchers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/codes/{code}/history` | verified | - | session_cookie, bearer_token | vouchers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/codes/{code}/invalidate` | verified | - | session_cookie, bearer_token | vouchers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/codes/{code}/platform-validation` | verified | - | session_cookie, bearer_token | vouchers.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:code-reveal | security_denials |
| POST | `/api/v1/workspaces/{workspace}/codes/{code}/reveal` | verified | - | session_cookie, bearer_token | vouchers.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:code-reveal | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections` | verified | - | session_cookie, bearer_token | integrations.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/connections/{connection}` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}` | verified | - | session_cookie, bearer_token | integrations.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/capabilities` | verified | - | session_cookie, bearer_token | integrations.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/catalog-syncs` | verified | - | session_cookie, bearer_token | integrations.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/catalog-syncs` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:commerce-probe | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/coupons` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/credentials` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/events` | verified | - | session_cookie, bearer_token | integrations.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/events/{event}/retry` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/health` | verified | - | session_cookie, bearer_token | integrations.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/pause` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/connections/{connection}/products` | verified | - | session_cookie, bearer_token | integrations.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/resume` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/sync/coupons` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:commerce-probe | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/sync/products` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:commerce-probe | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/test-api` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:commerce-probe | security_denials |
| POST | `/api/v1/workspaces/{workspace}/connections/{connection}/verify` | verified | - | session_cookie, bearer_token | integrations.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/dashboard` | verified | - | session_cookie, bearer_token | dashboard.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/disputes` | verified | - | session_cookie, bearer_token | vouchers.view | workspace_membership | - | ThrottleRequests:api | voucher |
| POST | `/api/v1/workspaces/{workspace}/disputes/{dispute}/respond` | verified | - | session_cookie, bearer_token | vouchers.manage | workspace_membership | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/workspaces/{workspace}/funding` | verified | - | session_cookie, bearer_token | finance.view | workspace_membership | - | ThrottleRequests:api | financial |
| GET | `/api/v1/workspaces/{workspace}/funding/obligations` | verified | - | session_cookie, bearer_token | finance.view | workspace_membership | - | ThrottleRequests:api | financial |
| POST | `/api/v1/workspaces/{workspace}/funding/top-ups` | verified | - | session_cookie | finance.manage | workspace_membership | recent password, email code | ThrottleRequests:api, ThrottleRequests:checkout | financial |
| GET | `/api/v1/workspaces/{workspace}/funding/top-ups/{topUp}` | verified | - | session_cookie, bearer_token | finance.view | workspace_membership | - | ThrottleRequests:api | financial |
| GET | `/api/v1/workspaces/{workspace}/funding/transactions` | verified | - | session_cookie, bearer_token | finance.view | workspace_membership | - | ThrottleRequests:api | financial |
| GET | `/api/v1/workspaces/{workspace}/integrations/health` | verified | - | session_cookie, bearer_token | integrations.view | workspace_membership | - | ThrottleRequests:api | integration |
| POST | `/api/v1/workspaces/{workspace}/integrations/shopify/oauth` | verified | - | session_cookie, bearer_token | integrations.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:commerce-probe | integration |
| GET | `/api/v1/workspaces/{workspace}/invitations` | verified | - | session_cookie, bearer_token | team.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/invitations` | verified | - | session_cookie, bearer_token | team.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:invitation-create | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/invitations/{invitation}` | verified | - | session_cookie, bearer_token | team.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/invitations/{invitation}/resend` | verified | - | session_cookie, bearer_token | team.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/invoices` | verified | - | session_cookie, bearer_token | finance.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/members` | verified | - | session_cookie, bearer_token | team.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/members/{member}` | verified | - | session_cookie, bearer_token | team.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| PATCH | `/api/v1/workspaces/{workspace}/members/{member}` | verified | - | session_cookie, bearer_token | team.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/members/{member}/reactivate` | verified | - | session_cookie, bearer_token | team.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/members/{member}/suspend` | verified | - | session_cookie, bearer_token | team.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/membership` | verified | - | session_cookie, bearer_token | - | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/notifications` | verified | - | session_cookie, bearer_token | dashboard.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/notifications/read-all` | verified | - | session_cookie, bearer_token | dashboard.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/notifications/{notification}/read` | verified | - | session_cookie, bearer_token | dashboard.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers/{offer}` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| PATCH | `/api/v1/workspaces/{workspace}/offers/{offer}` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/activate` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/archive` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers/{offer}/code-batches` | verified | - | session_cookie, bearer_token | vouchers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/code-imports` | verified | - | session_cookie, bearer_token | vouchers.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:code-import | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers/{offer}/codes` | verified | - | session_cookie, bearer_token | vouchers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers/{offer}/codes/export` | verified | - | session_cookie, bearer_token | vouchers.export | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:order-export | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers/{offer}/codes/import-template` | verified | - | session_cookie, bearer_token | vouchers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/offers/{offer}/cost` | verified | - | session_cookie, bearer_token | offers.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/duplicate` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/end` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/pause` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/publish` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/offers/{offer}/resume` | verified | - | session_cookie, bearer_token | offers.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/onboarding` | verified | - | session_cookie, bearer_token | - | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/orders` | verified | - | session_cookie, bearer_token | orders.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/orders/export` | verified | - | session_cookie, bearer_token | orders.export | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:order-export | security_denials |
| GET | `/api/v1/workspaces/{workspace}/orders/{order}` | verified | - | session_cookie, bearer_token | orders.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/ownership-transfer` | verified | - | session_cookie, bearer_token | - | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/ownership-transfer` | verified | - | session_cookie, bearer_token | - | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership-transfer` | verified | - | session_cookie | ownership.manage | workspace_membership | recent password | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership-transfer/accept` | verified | - | session_cookie | - | workspace_membership | recent password | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership/review` | verified | - | session_cookie | ownership.manage | workspace_membership | recent password, email code | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/ownership/verifications` | verified | - | session_cookie, bearer_token | ownership.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership/verifications` | verified | - | session_cookie | ownership.manage | workspace_membership | recent password, email code | ThrottleRequests:api, ThrottleRequests:ownership-check | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership/verifications/{verification}/check` | verified | - | session_cookie | ownership.manage | workspace_membership | recent password, email code | ThrottleRequests:api, ThrottleRequests:ownership-check | security_denials |
| POST | `/api/v1/workspaces/{workspace}/ownership/verifications/{verification}/confirm-code` | verified | - | session_cookie | ownership.manage | workspace_membership | recent password, email code | ThrottleRequests:api, ThrottleRequests:ownership-check | security_denials |
| GET | `/api/v1/workspaces/{workspace}/public-profile` | verified | - | session_cookie, bearer_token | settings.view | workspace_membership | - | ThrottleRequests:api | security_denials |
| PUT | `/api/v1/workspaces/{workspace}/public-profile` | verified | - | session_cookie, bearer_token | settings.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| DELETE | `/api/v1/workspaces/{workspace}/public-profile/logo` | verified | - | session_cookie, bearer_token | settings.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/public-profile/logo` | verified | - | session_cookie, bearer_token | settings.manage | workspace_membership | - | ThrottleRequests:api | security_denials |
| POST | `/api/v1/workspaces/{workspace}/select` | verified | - | session_cookie, bearer_token | - | workspace_membership | - | ThrottleRequests:api | security_denials |
| GET | `/api/v1/workspaces/{workspace}/vouchers` | verified | - | session_cookie, bearer_token | vouchers.view | workspace_membership | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/workspaces/{workspace}/vouchers/export` | verified | - | session_cookie, bearer_token | vouchers.export | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:order-export | voucher |
| GET | `/api/v1/workspaces/{workspace}/vouchers/exports/{export}` | verified | - | session_cookie, bearer_token | vouchers.export | workspace_membership | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/workspaces/{workspace}/vouchers/exports/{export}/download` | verified | - | session_cookie, bearer_token | vouchers.export | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:order-export | voucher |
| POST | `/api/v1/workspaces/{workspace}/vouchers/redeem` | verified | - | session_cookie, bearer_token | vouchers.manage | workspace_membership | - | ThrottleRequests:api, ThrottleRequests:code-reveal | voucher |
| GET | `/api/v1/workspaces/{workspace}/vouchers/{voucher}` | verified | - | session_cookie, bearer_token | vouchers.view | workspace_membership | - | ThrottleRequests:api | voucher |
| POST | `/api/v1/workspaces/{workspace}/vouchers/{voucher}/dispute` | verified | - | session_cookie, bearer_token | vouchers.manage | workspace_membership | - | ThrottleRequests:api | voucher |
| GET | `/api/v1/workspaces/{workspace}/wallet` | verified | - | session_cookie, bearer_token | finance.view | workspace_membership | - | ThrottleRequests:api | financial |
