# Shopbonus API reference

Machine-readable contract: [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1). This page is the readable companion: every endpoint shows an example request and a **real response captured from a running server**.


Current connection policy: `Connected` requires all seven native verification gates. Complete webhooks and passive API probes stay `Limited`; see [B23d readiness and recovery](../integrations/READINESS_AND_RECOVERY.md). Earlier checkpoint captures below are historical.

## Documentation rule (mandatory for every agent)

1. Every route under `/api/v1` must have an entry in `openapi.yaml` and in this file. `tests/Feature/Api/OpenApiCoverageTest.php` fails the build if a route is missing, has no example request (`x-codeSamples`), or has a response without an example.
2. Example responses must be **captured from a real running server**, never written by hand. Run `php artisan serve`, call the endpoint with `curl -i`, and paste the output. Strip `Date`, `Host`, `Connection`, `X-Powered-By`, `Cache-Control`, `Vary` and CORS headers.
3. Use seeded or obviously fake data only. Never paste real customer data, tokens, voucher codes or secrets. Replace a real secret header value with a `<NAME_OF_ENV_VAR>` placeholder in the request.
4. Record what you captured against (for example "local MySQL 8 + Redis, commit `abc1234`").
5. For an error status the endpoint cannot yet produce, use an envelope rendered through the real app kernel, and say so.

## Conventions

| Topic | Rule |
| --- | --- |
| Base path | `/api/v1`. A breaking change gets `/api/v2`; never change a released v1 contract in place. |
| Format | JSON only. `Accept: application/json` is forced on API routes, so auth failures never redirect to HTML. |
| Correlation | Every response has `X-Request-Id`. Send your own (8–128 chars `A-Za-z0-9._-`) to trace across systems; anything else is replaced by a UUIDv7. The same ID appears in server logs. |
| Errors | One envelope, below. Framework messages are fixed per status. A 404 is identical whether a record is missing or belongs to another workspace. |
| Money | Integer minor units plus explicit `currency`/`unit`. Never floats. |
| Rate limit | `API_RATE_LIMIT_PER_MINUTE` (default 60) per user, or per IP when unauthenticated. Headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `Retry-After` on 429. |
| Audit | Every 401/403 API response is written to the security audit trail (`audit_events`) with the request ID, route template and a hashed IP. Unexpected 5xx errors go to `application_errors`. Quote the `request_id` in support tickets. |
| CORS | Only origins in `CORS_ALLOWED_ORIGINS`, with credentials (for the Sanctum SPA). Exposed headers: `X-Request-Id`, `Retry-After`. |

### Error envelope

```json
{
  "error": {
    "status": 422,
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "request_id": "01a168bd-f895-73d8-881b-0f4ac0620658",
    "fields": { "email": ["The email field must be a valid email address."] }
  }
}
```

`fields` appears only on 422. `details` appears only on domain errors that define it. On 5xx with `APP_DEBUG=true` (never in production) a `debug` block is added.

| Status | `code` | Message |
| --- | --- | --- |
| 400 | `bad_request` | The request could not be processed. |
| 401 | `unauthenticated` | Authentication is required. |
| 403 | `forbidden` | You are not allowed to perform this action. |
| 404 | `not_found` | The requested resource was not found. |
| 405 | `method_not_allowed` | This method is not allowed for the requested resource. |
| 409 | `conflict` (or a domain code) | Domain-specific |
| 415 | `unsupported_media_type` | The request content type is not supported. |
| 419 | `csrf_token_mismatch` | The session or CSRF token has expired. Refresh and try again. |
| 422 | `validation_failed` | The given data was invalid. |
| 429 | `too_many_requests` | Too many requests. Please slow down. |
| 500 | `server_error` | An unexpected error occurred. |
| 503 | `service_unavailable` | The service is temporarily unavailable. |

Domain code raises `App\Exceptions\ApiException` (for example `ApiException::conflict('Voucher code already reserved.', 'voucher_code_reserved')`). Its message is shown to the client, so it must never contain secrets, PII or another tenant's data.

---

## Health

Captured 2026-10-23 against local MySQL + Redis (predis), Laravel 13.38, PHP 8.4.

### `GET /api/v1/health` — liveness

Public. No dependency checks. Use it for process liveness.

**Request**

```bash
curl -i -H 'Accept: application/json' http://localhost:8000/api/v1/health
```

**Response 200**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-RateLimit-Limit: 3
X-RateLimit-Remaining: 2
X-Request-Id: 01a168bc-3180-72b7-b3eb-05b0fd701ba4

{"status":"ok","service":"shopbonus-api","time":"2026-10-23T12:28:46+00:00"}
```

(The capture used `API_RATE_LIMIT_PER_MINUTE=3`, which is why the limit shows as 3.)

**Response 405** (wrong method)

```bash
curl -i -X POST -H 'Accept: application/json' http://localhost:8000/api/v1/health
```

```http
HTTP/1.0 405 Method Not Allowed
Allow: GET, HEAD
X-Request-Id: 01a168bc-40c7-7329-ab27-cb1f1a89d354
Content-Type: application/json

{"error":{"status":405,"code":"method_not_allowed","message":"This method is not allowed for the requested resource.","request_id":"01a168bc-40c7-7329-ab27-cb1f1a89d354"}}
```

**Response 429** (rate limited)

```http
HTTP/1.1 429 Too Many Requests
X-RateLimit-Limit: 1
X-RateLimit-Remaining: 0
Retry-After: 43
X-RateLimit-Reset: 1792758586
X-Request-Id: 01a168bc-72ce-7350-8cbb-acff7fa4bd42
Content-Type: application/json

{"error":{"status":429,"code":"too_many_requests","message":"Too many requests. Please slow down.","request_id":"01a168bc-72ce-7350-8cbb-acff7fa4bd42"}}
```

### `GET /api/v1/health/ready` — readiness

Public. Checks the database, the cache store and, when cache or queue use Redis, Redis. Returns 200 if all pass, 503 if any fail. Per-component `checks` appear only when `X-Health-Token` matches `HEALTH_READINESS_TOKEN`. Failure causes are logged server-side (exception class only) and never returned.

**Request: public, no detail**

```bash
curl -i -H 'Accept: application/json' http://localhost:8000/api/v1/health/ready
```

**Response 200**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-RateLimit-Limit: 3
X-RateLimit-Remaining: 1
X-Request-Id: 01a168bc-3211-72f2-84a1-1256bea9be95

{"status":"ok"}
```

**Request: monitoring, with detail and a caller-supplied request ID**

```bash
curl -i -H 'Accept: application/json' \
  -H 'X-Health-Token: <HEALTH_READINESS_TOKEN>' \
  -H 'X-Request-Id: ops-probe-20261023-01' \
  http://localhost:8000/api/v1/health/ready
```

**Response 200**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-RateLimit-Limit: 3
X-RateLimit-Remaining: 0
X-Request-Id: ops-probe-20261023-01

{"status":"ok","checks":{"database":"ok","cache":"ok","redis":"ok"}}
```

**Response 503** (captured with `DB_DATABASE` pointing to a database that does not exist)

```http
HTTP/1.1 503 Service Unavailable
Content-Type: application/json
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 56
X-Request-Id: 01a168bc-7fad-73f8-a660-43253d40b85d

{"status":"fail","checks":{"database":"fail","cache":"ok","redis":"ok"}}
```

Without the token, the same failure returns only `{"status":"fail"}`.

---

## Auth (B02, Trello #4)

Captured 2026-10-23 against local MySQL + Redis sessions, Laravel 13.38, PHP 8.4, using a curl cookie jar with `Origin`/`Referer: http://localhost:3000` and `X-XSRF-TOKEN` set from the `XSRF-TOKEN` cookie. Rate-limit headers are trimmed. The verification code shown was a real one-time code, consumed during the capture.

### How a browser client signs in

1. `GET /sanctum/csrf-cookie`: sets `XSRF-TOKEN` and the HttpOnly `shopbonus-session` cookie.
2. Every POST/PUT/DELETE sends `X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>` and cookies (`credentials: 'include'`). It must come from an origin in `SANCTUM_STATEFUL_DOMAINS`, which must also be in `CORS_ALLOWED_ORIGINS`.
3. `POST /register` or `POST /login` returns the `/me` payload. Route on `data.destination` (`verify_email`, `consumer_dashboard`, `webshop_setup`), never on a URL from the query string.
4. Unverified users can only call `/api/v1/me`, `verify-code`, `resend-code` and `/logout`. Every other private endpoint returns 403 `email_not_verified`.
5. On a 419, fetch `/sanctum/csrf-cookie` again and retry once. On a 401, show the login screen. On 403 `account_inactive`, the session has already been ended.

`/api/v1` also accepts `Authorization: Bearer <Sanctum token>`. There is no public token-issuing endpoint yet.

### Access levels (`x-access` in openapi.yaml)

| Level | Meaning |
| --- | --- |
| `public` | Anyone |
| `guest` | Signed-out only. A signed-in JSON client gets 409 `already_authenticated` |
| `authenticated` | Active account (verified or not). Status is re-checked on every request |
| `verified` | Active and email-verified |

### Identity error codes

| Status | `code` | When |
| --- | --- | --- |
| 403 | `account_inactive` | Account suspended or deleted. The session or token is ended |
| 403 | `email_not_verified` | Verified-only endpoint called before verification |
| 403 | `origin_not_allowed` | The browser reported a cross-site request and origin-only CSRF mode is on. Retrying cannot fix it |
| 409 | `already_authenticated` | Guest-only route while signed in |
| 409 | `email_already_verified` | Verify or resend after verification |
| 422 | `verification_code_invalid` / `verification_code_expired` / `verification_code_attempts_exceeded` | Code problems. After 5 wrong tries the code is burned |
| 429 | `verification_resend_too_soon` | Within the 60 s resend cooldown (`Retry-After`, `details.retry_after_seconds`) |
| 503 | `legal_documents_unavailable` | No published Terms/Privacy version, so registration is disabled |

### `GET /sanctum/csrf-cookie` (public)

`GET /sanctum/csrf-cookie `

```http
HTTP/1.1 204 No Content
X-Request-Id: 01a16918-602d-7233-983b-e584ec5663e8
```

Captured `Set-Cookie` attributes (values removed). `Secure` is added when `SESSION_SECURE_COOKIE=true`, which is required in production:

```http
Set-Cookie: XSRF-TOKEN=<redacted>; expires=Fri, 23 Oct 2026 16:09:27 GMT; Max-Age=7200; path=/; samesite=lax
Set-Cookie: shopbonus-session=<redacted>; expires=Fri, 23 Oct 2026 16:09:27 GMT; Max-Age=7200; path=/; httponly; samesite=lax
```

### `POST /register` (guest)

Body fields: `account_type` (`consumer`|`merchant`), `first_name`, `email`, `password` (≥10 chars, letters and numbers), `accept_terms`, `terms_version` and `privacy_version` (must equal the current published versions), and optionally `marketing_opt_in` and `locale` (`nl`|`en`). Anything else is ignored.

**201 Created**

`POST /register {"account_type":"consumer","first_name":"Jan","email":"jan@example.nl","password":"Sterk-wachtwoord-2026","accept_terms":true,"terms_version":"dev-2026-10-23","privacy_version":"dev-2026-10-23","marketing_opt_in":true,"locale":"nl"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16918-6116-70f1-bd5c-28ce1d003497
{"data":{"id":"01m5mhgrhg7apq2p6wt7084kzn","first_name":"Jan","email":"jan@example.nl","email_verified":false,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":[],"workspaces":[],"destination":"verify_email"}}
```

**422** (validation, stale Terms version)

`POST /register {"account_type":"admin","first_name":"","email":"JAN@example.nl","password":"short","accept_terms":false,"terms_version":"old","privacy_version":"dev-2026-10-23"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16918-6094-7056-8caf-0b8b5c49d766
Content-Type: application/json
{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16918-6094-7056-8caf-0b8b5c49d766","fields":{"account_type":["The selected account type is invalid."],"first_name":["The first name field is required."],"password":["The password field must be at least 10 characters.","The password field must contain at least one number."],"accept_terms":["The accept terms field must be accepted."],"terms_version":["The Terms have been updated. Reload the page and review them."]}}}
```

**409** (already signed in)

`POST /register {}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16918-62f7-7298-a3b9-97ecd027302e
Content-Type: application/json
{"error":{"status":409,"code":"already_authenticated","message":"You are already signed in.","request_id":"01a16918-62f7-7298-a3b9-97ecd027302e"}}
```

**503** (no published legal version). Captured with `LEGAL_TERMS_VERSION` empty:

```http
HTTP/1.1 503 Service Unavailable

{"error":{"status":503,"code":"legal_documents_unavailable","message":"Registration is temporarily unavailable.","request_id":"01a1691a-31a2-7040-bc08-83a295239267"}}
```

(That capture also showed a `debug` block because `APP_DEBUG=true`. That was a bug for intentional errors and is fixed, so intentional 5xx errors never carry debug data.)

### `GET /api/v1/me` (authenticated)

**200**, unverified:

`GET /api/v1/me `

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16918-633c-7231-bcdc-30b8fa48c7da
{"data":{"id":"01m5mhgrhg7apq2p6wt7084kzn","first_name":"Jan","email":"jan@example.nl","email_verified":false,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":[],"workspaces":[],"destination":"verify_email"}}
```

**200**, verified:

`GET /api/v1/me `

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16918-6808-704d-975c-4a54eb4bd04c
{"data":{"id":"01m5mhgrhg7apq2p6wt7084kzn","first_name":"Jan","email":"jan@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":[],"workspaces":[],"destination":"consumer_dashboard"}}
```

**401** (after logout):

`GET /api/v1/me `

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a16918-650f-721a-b787-30280f4825eb
Content-Type: application/json
{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a16918-650f-721a-b787-30280f4825eb"}}
```

**403** (account suspended while signed in):

`GET /api/v1/me `

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16918-755d-7150-95c8-7297b7efa823
Content-Type: application/json
{"error":{"status":403,"code":"account_inactive","message":"This account is not active.","request_id":"01a16918-755d-7150-95c8-7297b7efa823"}}
```


### `POST /api/v1/auth/email/verify-code` (authenticated)

**422** (wrong code):

`POST /api/v1/auth/email/verify-code {"code":"000000"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16918-6384-73d9-b292-51e9fbb6fd50
Content-Type: application/json
{"error":{"status":422,"code":"verification_code_invalid","message":"This code is not valid.","request_id":"01a16918-6384-73d9-b292-51e9fbb6fd50"}}
```

**200** (verified; destination changes):

`POST /api/v1/auth/email/verify-code {"code":"257424"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16918-6425-73dd-83d1-6306ee24d0d4
{"data":{"id":"01m5mhgrhg7apq2p6wt7084kzn","first_name":"Jan","email":"jan@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":[],"workspaces":[],"destination":"consumer_dashboard"}}
```

**409** (already verified):

`POST /api/v1/auth/email/verify-code {"code":"123456"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16918-6481-71ce-807c-ffc0b282fa7c
Content-Type: application/json
{"error":{"status":409,"code":"email_already_verified","message":"This email address is already verified.","request_id":"01a16918-6481-71ce-807c-ffc0b282fa7c"}}
```


### `POST /api/v1/auth/email/resend-code` (authenticated)

**429** (inside the 60 s cooldown that starts when the signup code is sent):

`POST /api/v1/auth/email/resend-code `

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 60
X-Request-Id: 01a16918-63d9-7335-a770-2ce4d868bd98
Content-Type: application/json
{"error":{"status":429,"code":"verification_resend_too_soon","message":"Please wait before requesting a new code.","request_id":"01a16918-63d9-7335-a770-2ce4d868bd98","details":{"retry_after_seconds":60}}}
```

**202**. Captured on a server with `VERIFICATION_RESEND_COOLDOWN_SECONDS=0`; by default `resend_available_in_seconds` is 60:

```http
HTTP/1.1 202 Accepted
X-Request-Id: 01a1691a-3b5e-7098-bf08-345e9903e219

{"data":{"sent":true,"expires_at":"2026-10-23T14:21:29+00:00","resend_available_in_seconds":0}}
```

### `POST /login` (guest)

**200**:

`POST /login {"email":"jan@example.nl","password":"Sterk-wachtwoord-2026"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16918-66dc-73d0-aea9-f8fab2495084
{"data":{"id":"01m5mhgrhg7apq2p6wt7084kzn","first_name":"Jan","email":"jan@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":[],"workspaces":[],"destination":"consumer_dashboard"}}
```

**422**. Identical for a wrong password, an unknown email and a suspended account:

`POST /login {"email":"jan@example.nl","password":"Wrong-password-2026"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16918-65b4-7353-ba75-c5c007bf9609
Content-Type: application/json
{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16918-65b4-7353-ba75-c5c007bf9609","fields":{"email":["These credentials do not match our records."]}}}
```

**429** (lockout after 5 failures per email+IP per minute):

`POST /login {"email":"piet@example.nl","password":"Wrong-password-2026"}`

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 58
X-Request-Id: 01a16918-7fde-71d5-a9d1-bae3ad781cda
Content-Type: application/json
{"error":{"status":429,"code":"too_many_requests","message":"Too many requests. Please slow down.","request_id":"01a16918-7fde-71d5-a9d1-bae3ad781cda"}}
```

**419** (no CSRF token, cross-site):

`POST /login (no X-XSRF-TOKEN, Sec-Fetch-Site: cross-site)`

```http
HTTP/1.1 419 unknown status
X-Request-Id: 01a16918-6531-73b7-aec9-8f6355a03ffb
Content-Type: application/json
{"error":{"status":419,"code":"csrf_token_mismatch","message":"The session or CSRF token has expired. Refresh and try again.","request_id":"01a16918-6531-73b7-aec9-8f6355a03ffb"}}
```


### `POST /logout` (authenticated)

`POST /logout `

```http
HTTP/1.1 204 No Content
X-Request-Id: 01a16918-64c6-70ae-9e87-c0d5c4141d89
```


### `PUT /user/password` (authenticated)

Keeps this session, signs out other sessions and revokes API tokens. The 200 body is Fortify's native `""`.

**200**:

`PUT /user/password {"current_password":"Sterk-wachtwoord-2026","password":"Nieuw-wachtwoord-2027"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16918-6974-70f5-ac80-432cd307aaa9
""
```

**422**:

`PUT /user/password {"current_password":"wrong","password":"Nieuw-wachtwoord-2027"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16918-6854-716a-8180-2c96f1b97489
Content-Type: application/json
{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16918-6854-716a-8180-2c96f1b97489","fields":{"current_password":["The provided password does not match your current password."]}}}
```


### `POST /user/confirm-password` and `GET /user/confirmed-password-status` (authenticated)

Fresh password confirmation (valid for 3 hours), the basis for later step-up checks.

`POST /user/confirm-password {"password":"Nieuw-wachtwoord-2027"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16918-6b7c-7278-ada8-735ebbac9c5e
""
```

`POST /user/confirm-password {"password":"wrong"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16918-6c9b-7052-a57b-48f8cb5c431f
Content-Type: application/json
{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16918-6c9b-7052-a57b-48f8cb5c431f","fields":{"password":["The provided password was incorrect."]}}}
```

`GET /user/confirmed-password-status `

```http
HTTP/1.1 200 OK
X-Retry-After: 1
Content-Type: application/json
X-Request-Id: 01a16918-6db9-7311-b4fa-eb62d8c108a8
{"confirmed":true}
```


### `POST /forgot-password` (guest)

Known and unknown emails get byte-identical responses:

`POST /forgot-password {"email":"jan@example.nl"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16918-6e8e-728e-9c79-bfa5100f7a75
{"message":"If an account exists for this email address, a password reset link has been sent."}
```

`POST /forgot-password {"email":"nobody@example.nl"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16918-6fc8-70eb-9b6b-9f883721b0e0
{"message":"If an account exists for this email address, a password reset link has been sent."}
```


### `POST /reset-password` (guest)

The token comes from the emailed link (`FRONTEND_URL/reset-password?token=…&email=…`). It is shown as a placeholder here.

**200**:

`POST /reset-password {"token":"<token from the email link>","email":"jan@example.nl","password":"Derde-wachtwoord-2028"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16918-70e8-73bf-b2e8-5060d571d551
{"message":"Your password has been reset."}
```

**422** (same token reused):

`POST /reset-password {"token":"<token from the email link>","email":"jan@example.nl","password":"Vierde-wachtwoord-2029"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16918-7300-70d4-9ff9-711fc215d4b5
Content-Type: application/json
{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16918-7300-70d4-9ff9-711fc215d4b5","fields":{"email":["This password reset token is invalid."]}}}
```


---

## Workspaces (B03a, Trello #2 §3-4, #16)

Captured 2026-10-23 against local MySQL + Redis with Sanctum bearer tokens (`Authorization: Bearer <token>`); browser clients use the session cookie flow from the Auth section instead. Three users: Jan (owner), Piet (employee in Jan's first shop, extra `orders.view`) and Eva (no memberships). The server ran with `WORKSPACES_MAX_UNAPPROVED_PER_USER=3` to show the cap. Rate-limit headers trimmed.

### Rules

- `{workspace}` is a webshop public ID or `current` (the caller's server-side selection).
- A webshop the caller isn't an active member of returns **the same 404 as a non-existent one**, and the attempt is audited (`workspace.access_denied`). Removed and suspended memberships behave the same.
- A member of a suspended webshop gets 403 `workspace_suspended`.
- A member without the route's `x-permission` gets 403 `workspace_permission_denied`, audited with the webshop in context.
- Roles: `owner` (everything, including the owner-only `agreements.accept`, `ownership.manage` and `finance.manage`), `manager` (everything except owner-only), `employee` (`dashboard.view` plus explicit grants). Owner-only permissions never apply to non-owners, even if stored.
- Creating a webshop makes the caller its owner. Nothing in a request body (`role`, `owner`, `status`, `created_by`, `webshop_id`) grants anything.

### Workspace error codes

| Status | `code` | When |
| --- | --- | --- |
| 403 | `workspace_suspended` | Member of a suspended webshop |
| 403 | `workspace_permission_denied` | Member lacks `details.required_permission` |
| 404 | `not_found` | Not a member, or no such webshop (indistinguishable) |
| 409 | `no_current_workspace` | `current` used before selecting one |
| 409 | `workspace_draft_limit_reached` | Too many unapproved webshops |

### `POST /api/v1/workspaces` (verified)

**201**. The request also sent `status: approved` and `role: super_admin`; both were ignored:

`POST /api/v1/workspaces {"name":"Fietsenwinkel Utrecht","platform":"woocommerce","status":"approved","role":"super_admin"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16923-d4e3-7151-9f3f-efffeed78324

{"data":{"id":"01m5mj7namw3ysnwzvha9rkbxf","name":"Fietsenwinkel Utrecht","platform":"woocommerce","status":"draft","commercially_active":false,"is_current":true,"membership":{"role":"owner","permissions":["dashboard.view","orders.view","orders.manage","integrations.view","integrations.manage","offers.view","offers.manage","vouchers.view","vouchers.manage","team.view","team.manage","settings.view","settings.manage","finance.view","finance.manage","agreements.accept","ownership.manage"],"joined_at":"2026-10-23T14:21:58+00:00"},"created_at":"2026-10-23T14:21:58+00:00"}}
```

**422**:

`POST /api/v1/workspaces {"name":"x","platform":"myspace"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16923-d591-708d-ab15-c8ba0a877d9a
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16923-d591-708d-ab15-c8ba0a877d9a","fields":{"name":["The name field must be at least 2 characters."],"platform":["The selected platform is invalid."]}}}
```

**409** (cap reached):

`POST /api/v1/workspaces {"name":"Vierde winkel"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16923-d5fa-7221-959b-4f0e672e5b04
Content-Type: application/json

{"error":{"status":409,"code":"workspace_draft_limit_reached","message":"You can have at most 3 webshops awaiting approval.","request_id":"01a16923-d5fa-7221-959b-4f0e672e5b04"}}
```

### `GET /api/v1/workspaces` (verified)

With `?search=boek`:

`GET /api/v1/workspaces?search=boek`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16923-d76a-71b5-9b41-dd7fee07093c

{"data":[{"id":"01m5mj7ndsfx9z4r1fx0gqhgmq","name":"Boekhandel Amsterdam","platform":"shopify","status":"draft","commercially_active":false,"is_current":false,"membership":{"role":"owner","permissions":["dashboard.view","orders.view","orders.manage","integrations.view","integrations.manage","offers.view","offers.manage","vouchers.view","vouchers.manage","team.view","team.manage","settings.view","settings.manage","finance.view","finance.manage","agreements.accept","ownership.manage"],"joined_at":"2026-10-23T14:21:58+00:00"},"created_at":"2026-10-23T14:21:58+00:00"}]}
```

### `GET /api/v1/workspaces/{workspace}` (verified)

As owner:

`GET /api/v1/workspaces/01m5mj7namw3ysnwzvha9rkbxf`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16923-d787-730b-b618-5e9babefbd08

{"data":{"id":"01m5mj7namw3ysnwzvha9rkbxf","name":"Fietsenwinkel Utrecht","platform":"woocommerce","status":"draft","commercially_active":false,"is_current":true,"membership":{"role":"owner","permissions":["dashboard.view","orders.view","orders.manage","integrations.view","integrations.manage","offers.view","offers.manage","vouchers.view","vouchers.manage","team.view","team.manage","settings.view","settings.manage","finance.view","finance.manage","agreements.accept","ownership.manage"],"joined_at":"2026-10-23T14:21:58+00:00"},"created_at":"2026-10-23T14:21:58+00:00"}}
```

The same webshop as employee Piet (role defaults plus an `orders.view` grant):

`GET /api/v1/workspaces/01m5mj7namw3ysnwzvha9rkbxf`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16923-d7a3-708c-85be-dfb3657debf2

{"data":{"id":"01m5mj7namw3ysnwzvha9rkbxf","name":"Fietsenwinkel Utrecht","platform":"woocommerce","status":"draft","commercially_active":false,"is_current":false,"membership":{"role":"employee","permissions":["dashboard.view","orders.view"],"joined_at":"2026-10-23T14:21:58+00:00"},"created_at":"2026-10-23T14:21:58+00:00"}}
```

As Eva, who is not a member. This is byte-for-byte the same as an unknown ID:

`GET /api/v1/workspaces/01m5mj7namw3ysnwzvha9rkbxf`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16923-d803-7076-b994-877ecad6f7a3
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a16923-d803-7076-b994-877ecad6f7a3"}}
```

After the webshop was suspended:

`GET /api/v1/workspaces/01m5mj7namw3ysnwzvha9rkbxf`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16923-d8bc-710a-8381-3ffeeb3572e2
Content-Type: application/json

{"error":{"status":403,"code":"workspace_suspended","message":"This workspace is suspended.","request_id":"01a16923-d8bc-710a-8381-3ffeeb3572e2"}}
```

### `PATCH /api/v1/workspaces/{workspace}` (verified, `settings.manage`)

`PATCH /api/v1/workspaces/01m5mj7namw3ysnwzvha9rkbxf {"name":"Fietsenwinkel Utrecht Centrum"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16923-d7bf-7048-b67e-38fa1b5fa7dc

{"data":{"id":"01m5mj7namw3ysnwzvha9rkbxf","name":"Fietsenwinkel Utrecht Centrum","platform":"woocommerce","status":"draft","commercially_active":false,"is_current":true,"membership":{"role":"owner","permissions":["dashboard.view","orders.view","orders.manage","integrations.view","integrations.manage","offers.view","offers.manage","vouchers.view","vouchers.manage","team.view","team.manage","settings.view","settings.manage","finance.view","finance.manage","agreements.accept","ownership.manage"],"joined_at":"2026-10-23T14:21:58+00:00"},"created_at":"2026-10-23T14:21:58+00:00"}}
```

As employee:

`PATCH /api/v1/workspaces/01m5mj7namw3ysnwzvha9rkbxf {"name":"Overgenomen"}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16923-d7e5-7235-a3a5-67e8214491cd
Content-Type: application/json

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a16923-d7e5-7235-a3a5-67e8214491cd","details":{"required_permission":"settings.manage"}}}
```

### `POST /api/v1/workspaces/{workspace}/select` and `GET /api/v1/workspaces/current`

`POST /api/v1/workspaces/01m5mj7ndsfx9z4r1fx0gqhgmq/select`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16923-d83c-70df-9dd4-8238b52dfe11

{"data":{"id":"01m5mj7ndsfx9z4r1fx0gqhgmq","name":"Boekhandel Amsterdam","platform":"shopify","status":"draft","commercially_active":false,"is_current":true,"membership":{"role":"owner","permissions":["dashboard.view","orders.view","orders.manage","integrations.view","integrations.manage","offers.view","offers.manage","vouchers.view","vouchers.manage","team.view","team.manage","settings.view","settings.manage","finance.view","finance.manage","agreements.accept","ownership.manage"],"joined_at":"2026-10-23T14:21:58+00:00"},"created_at":"2026-10-23T14:21:58+00:00"}}
```

`GET /api/v1/workspaces/current`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16923-d861-726f-8a3d-6ebb603d1d44

{"data":{"id":"01m5mj7ndsfx9z4r1fx0gqhgmq","name":"Boekhandel Amsterdam","platform":"shopify","status":"draft","commercially_active":false,"is_current":true,"membership":{"role":"owner","permissions":["dashboard.view","orders.view","orders.manage","integrations.view","integrations.manage","offers.view","offers.manage","vouchers.view","vouchers.manage","team.view","team.manage","settings.view","settings.manage","finance.view","finance.manage","agreements.accept","ownership.manage"],"joined_at":"2026-10-23T14:21:58+00:00"},"created_at":"2026-10-23T14:21:58+00:00"}}
```

Eva has nothing selected:

`GET /api/v1/workspaces/current`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16923-d821-7077-9ca3-9413256a6571
Content-Type: application/json

{"error":{"status":409,"code":"no_current_workspace","message":"No workspace is selected.","request_id":"01a16923-d821-7077-9ca3-9413256a6571"}}
```

### `GET /api/v1/me` with memberships

`GET /api/v1/me`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16923-d882-702d-a2f1-d2058f06e579

{"data":{"id":"01m5mj7mc9f0pkxdznfx5nfz0s","first_name":"Jan","email":"jan@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":[],"workspaces":[{"id":"01m5mj7ndsfx9z4r1fx0gqhgmq","name":"Boekhandel Amsterdam","status":"draft","role":"owner","is_current":true},{"id":"01m5mj7namw3ysnwzvha9rkbxf","name":"Fietsenwinkel Utrecht Centrum","status":"draft","role":"owner","is_current":false},{"id":"01m5mj7nf06ed40b0gc2d6qkm2","name":"Kaaswinkel Gouda","status":"draft","role":"owner","is_current":false}],"destination":"workspace"}}
```

---

## Team management (B03b, Trello #16)

Captured 2026-10-23 against local MySQL + Redis with bearer tokens. Fietsenwinkel Utrecht has owner Jan, manager Mia, and employee Piet (extra `orders.view`). Eva and Ann have accounts but no membership. Invitation tokens are shown as `<token>`.

### Authority rules

- The **owner role is never granted** through invitations or role changes (403 `owner_role_not_grantable`). Ownership transfer will be a separate step-up workflow.
- You can grant a role only up to your own rank, and only permissions you hold yourself. Owner-only permissions (`agreements.accept`, `ownership.manage`, `finance.manage`) are never grantable.
- You can change, suspend or remove only members you outrank. Owners are never targets, and you can't change yourself (you can leave).
- The final active owner can't leave or be removed (409 `final_owner`).
- An invitation dies if, by acceptance time, the inviter has lost `team.manage` or the webshop is suspended.

| Status | `code` | When |
| --- | --- | --- |
| 403 | `owner_role_not_grantable` / `role_above_own` / `permission_above_own` | Granting beyond your authority |
| 403 | `cannot_manage_self` / `owner_not_manageable` / `member_not_below_you` | Target not manageable by you |
| 403 | `invitation_recipient_mismatch` | Signed in with a different email than invited (audited) |
| 409 | `already_member` / `invitation_pending` / `invitation_not_pending` / `member_not_suspended` / `final_owner` | State conflicts |
| 410 | `invitation_invalid` | Token unknown, used, cancelled, expired, or the inviter lost authority |
| 422 | `unknown_permission` | Permission name not in the registry |
| 429 | `invitation_resend_too_soon` / `invitation_resend_limit` | Resend cooldown (60 s) or cap (5) |

### GET /workspaces/{workspace}/members

`GET /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/members`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1692d-d932-73e0-8f2a-fd51e914fe2a

{"data":[{"id":"01m5mjvnc2qm5v4trb0y8pcbh5","first_name":"Jan","email":"jan@example.nl","role":"owner","permissions":["dashboard.view","orders.view","orders.manage","integrations.view","integrations.manage","offers.view","offers.manage","vouchers.view","vouchers.manage","team.view","team.manage","settings.view","settings.manage","finance.view","finance.manage","agreements.accept","ownership.manage"],"granted_permissions":[],"status":"active","joined_at":"2026-10-23T14:32:53+00:00","last_activity_at":"2026-10-23T14:32:53+00:00"},{"id":"01m5mjvnc7d8cm4qahhag2pvjw","first_name":"Mia","email":"mia@example.nl","role":"manager","permissions":["dashboard.view","orders.view","orders.manage","integrations.view","integrations.manage","offers.view","offers.manage","vouchers.view","vouchers.manage","team.view","team.manage","settings.view","settings.manage","finance.view"],"granted_permissions":[],"status":"active","joined_at":"2026-10-23T14:32:53+00:00","last_activity_at":null},{"id":"01m5mjvnc9bchqs7612nwyczba","first_name":"Piet","email":"piet@example.nl","role":"employee","permissions":["dashboard.view","orders.view"],"granted_permissions":["orders.view"],"status":"active","joined_at":"2026-10-23T14:32:53+00:00","last_activity_at":null}]}
```

### POST /workspaces/{workspace}/invitations, as manager Mia

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations {"email":"Eva@Example.nl","role":"employee","permissions":["orders.view","offers.view"]}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a1692d-d989-7125-a6bb-a5200647006f

{"data":{"id":"01m5mjvpdm5nmzjsj1aqm6hfbj","email":"eva@example.nl","role":"employee","permissions":["orders.view","offers.view"],"status":"pending","expires_at":"2026-10-30T14:32:54+00:00","last_sent_at":"2026-10-23T14:32:54+00:00","resend_count":0,"created_at":"2026-10-23T14:32:54+00:00"}}
```

### Inviting an owner

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations {"email":"x@example.nl","role":"owner"}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a1692d-da35-7291-88f3-73ffb54fb4b4
Content-Type: application/json

{"error":{"status":403,"code":"owner_role_not_grantable","message":"The owner role cannot be granted this way.","request_id":"01a1692d-da35-7291-88f3-73ffb54fb4b4"}}
```

### Manager granting an owner-only permission

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations {"email":"x@example.nl","role":"employee","permissions":["agreements.accept"]}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a1692d-da5b-7326-acc6-190ac61421ea
Content-Type: application/json

{"error":{"status":403,"code":"permission_above_own","message":"You cannot grant a permission you do not hold.","request_id":"01a1692d-da5b-7326-acc6-190ac61421ea","details":{"permission":"agreements.accept"}}}
```

### Unknown permission

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations {"email":"x@example.nl","role":"employee","permissions":["root.everything"]}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a1692d-da81-7319-97b2-08f6d404d9da
Content-Type: application/json

{"error":{"status":422,"code":"unknown_permission","message":"Unknown permission.","request_id":"01a1692d-da81-7319-97b2-08f6d404d9da","details":{"permission":"root.everything"}}}
```

### Already invited

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations {"email":"eva@example.nl","role":"employee"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a1692d-daa4-73b0-931f-6a31d0b6ff65
Content-Type: application/json

{"error":{"status":409,"code":"invitation_pending","message":"An invitation for this email is already pending. Resend it instead.","request_id":"01a1692d-daa4-73b0-931f-6a31d0b6ff65"}}
```

### Already a member

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations {"email":"piet@example.nl","role":"employee"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a1692d-dac9-736a-b37c-58d5ed68b75b
Content-Type: application/json

{"error":{"status":409,"code":"already_member","message":"This person is already a member of this workspace.","request_id":"01a1692d-dac9-736a-b37c-58d5ed68b75b"}}
```

### Employee without team.manage

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations {"email":"x@example.nl","role":"employee"}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a1692d-daee-7114-9ea4-4777a39c1ade
Content-Type: application/json

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a1692d-daee-7114-9ea4-4777a39c1ade","details":{"required_permission":"team.manage"}}}
```

### GET /workspaces/{workspace}/invitations

`GET /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1692d-db17-723a-9fb1-b680d70dab43

{"data":[{"id":"01m5mjvpdm5nmzjsj1aqm6hfbj","email":"eva@example.nl","role":"employee","permissions":["orders.view","offers.view"],"status":"pending","expires_at":"2026-10-30T14:32:54+00:00","last_sent_at":"2026-10-23T14:32:54+00:00","resend_count":0,"created_at":"2026-10-23T14:32:54+00:00"}]}
```

### Resend within the cooldown

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations/01m5mjvpdm5nmzjsj1aqm6hfbj/resend`

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 59
X-Request-Id: 01a1692d-db38-70fb-9716-56dea92fc6ce
Content-Type: application/json

{"error":{"status":429,"code":"invitation_resend_too_soon","message":"Please wait before resending.","request_id":"01a1692d-db38-70fb-9716-56dea92fc6ce","details":{"retry_after_seconds":59}}}
```

### Resend after the cooldown (new invitation for Ann)

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations/01m5mjvr97ak60p5zvcg7xzgvm/resend`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1692d-e1b9-706e-8d10-bc9b5c58596f

{"data":{"id":"01m5mjvr97ak60p5zvcg7xzgvm","email":"ann@example.nl","role":"employee","permissions":[],"status":"pending","expires_at":"2026-10-30T14:32:57+00:00","last_sent_at":"2026-10-23T14:32:57+00:00","resend_count":1,"created_at":"2026-10-23T14:32:56+00:00"}}
```

### Resend a cancelled invitation

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations/01m5mjvr97ak60p5zvcg7xzgvm/resend`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a1692d-e371-72ac-8733-ad4be94f5c61
Content-Type: application/json

{"error":{"status":409,"code":"invitation_not_pending","message":"Only pending invitations can be resent.","request_id":"01a1692d-e371-72ac-8733-ad4be94f5c61"}}
```

### POST /invitations/preview (public)

`POST /api/v1/invitations/preview {"token":"<token>"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1692d-db56-714e-926b-aa31d2d63ae8

{"data":{"webshop":{"name":"Fietsenwinkel Utrecht"},"role":"employee","invited_by":"Mia","email_hint":"e**@example.nl","expires_at":"2026-10-30T14:32:54+00:00"}}
```

### POST /invitations/accept signed in as Ann

`POST /api/v1/invitations/accept {"token":"<token>"}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a1692d-db74-73b2-b80f-1e8b0124f6ef
Content-Type: application/json

{"error":{"status":403,"code":"invitation_recipient_mismatch","message":"This invitation was sent to a different email address. Sign in with that address to accept it.","request_id":"01a1692d-db74-73b2-b80f-1e8b0124f6ef"}}
```

### POST /invitations/accept signed in as Eva

`POST /api/v1/invitations/accept {"token":"<token>"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1692d-db97-73d2-8eae-558ceb863d00

{"data":{"id":"01m5mjvndq5spwdz5ny31z452d","name":"Fietsenwinkel Utrecht","platform":"woocommerce","status":"draft","commercially_active":false,"is_current":false,"membership":{"role":"employee","permissions":["dashboard.view","orders.view","offers.view"],"joined_at":"2026-10-23T14:32:55+00:00"},"created_at":"2026-10-23T14:32:53+00:00"}}
```

### Same token again

`POST /api/v1/invitations/accept {"token":"<token>"}`

```http
HTTP/1.1 410 Gone
X-Request-Id: 01a1692d-dbbf-73d4-9530-78d274a76438
Content-Type: application/json

{"error":{"status":410,"code":"invitation_invalid","message":"This invitation is invalid or has expired.","request_id":"01a1692d-dbbf-73d4-9530-78d274a76438"}}
```

### Accepting while already a member

`POST /api/v1/invitations/accept {"token":"<token>"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a1692d-e32b-717f-b300-60c3054c7bb5
Content-Type: application/json

{"error":{"status":409,"code":"already_member","message":"You are already a member of this workspace.","request_id":"01a1692d-e32b-717f-b300-60c3054c7bb5"}}
```

### DELETE /workspaces/{workspace}/invitations/{invitation}

`DELETE /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/invitations/01m5mjvpzc51y78v6494r67r0m`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1692d-dc18-703f-9bc1-c35a4fea7f1d

{"data":{"id":"01m5mjvpzc51y78v6494r67r0m","email":"ann@example.nl","role":"employee","permissions":[],"status":"cancelled","expires_at":"2026-10-30T14:32:55+00:00","last_sent_at":"2026-10-23T14:32:55+00:00","resend_count":0,"created_at":"2026-10-23T14:32:55+00:00"}}
```

### PATCH /workspaces/{workspace}/members/{member}, Mia changing Piet's grants

`PATCH /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/members/01m5mjvnc9bchqs7612nwyczba {"permissions":["orders.view","vouchers.view"]}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1692d-dc3c-71cf-8931-2e0146d9cf54

{"data":{"id":"01m5mjvnc9bchqs7612nwyczba","first_name":"Piet","email":"piet@example.nl","role":"employee","permissions":["dashboard.view","orders.view","vouchers.view"],"granted_permissions":["orders.view","vouchers.view"],"status":"active","joined_at":"2026-10-23T14:32:53+00:00","last_activity_at":null}}
```

### Mia changing herself

`PATCH /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/members/01m5mjvnc7d8cm4qahhag2pvjw {"role":"manager"}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a1692d-dc60-73b7-bc92-8857d1f110a2
Content-Type: application/json

{"error":{"status":403,"code":"cannot_manage_self","message":"You cannot change your own membership this way.","request_id":"01a1692d-dc60-73b7-bc92-8857d1f110a2"}}
```

### Mia removing the owner

`DELETE /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/members/01m5mjvnc2qm5v4trb0y8pcbh5`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a1692d-dc83-7192-8ee3-56d35554f982
Content-Type: application/json

{"error":{"status":403,"code":"owner_not_manageable","message":"Owners can only be changed through the ownership workflow.","request_id":"01a1692d-dc83-7192-8ee3-56d35554f982"}}
```

### POST .../members/{member}/suspend

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/members/01m5mjvnc9bchqs7612nwyczba/suspend`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1692d-dca4-71d2-862f-9e585e5f0355

{"data":{"id":"01m5mjvnc9bchqs7612nwyczba","first_name":"Piet","email":"piet@example.nl","role":"employee","permissions":["dashboard.view","orders.view","vouchers.view"],"granted_permissions":["orders.view","vouchers.view"],"status":"suspended","joined_at":"2026-10-23T14:32:53+00:00","last_activity_at":null}}
```

### Piet while suspended

`GET /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a1692d-dcc9-722f-85d1-afd52a7722df
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a1692d-dcc9-722f-85d1-afd52a7722df"}}
```

### POST .../members/{member}/reactivate

`POST /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/members/01m5mjvnc9bchqs7612nwyczba/reactivate`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1692d-dceb-73bd-9d5e-1fc394d89d39

{"data":{"id":"01m5mjvnc9bchqs7612nwyczba","first_name":"Piet","email":"piet@example.nl","role":"employee","permissions":["dashboard.view","orders.view","vouchers.view"],"granted_permissions":["orders.view","vouchers.view"],"status":"active","joined_at":"2026-10-23T14:32:53+00:00","last_activity_at":null}}
```

### DELETE .../members/{member}

`DELETE /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/members/01m5mjvnc9bchqs7612nwyczba`

```http
HTTP/1.1 204 No Content
X-Request-Id: 01a1692d-dd0f-71e9-b3c8-8190caa2077c
```

### DELETE /workspaces/{workspace}/membership as the only owner

`DELETE /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/membership`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a1692d-dd33-73c1-a07d-0058beb3a2c6
Content-Type: application/json

{"error":{"status":409,"code":"final_owner","message":"The last owner cannot leave or be removed.","request_id":"01a1692d-dd33-73c1-a07d-0058beb3a2c6"}}
```

### Mia leaving

`DELETE /api/v1/workspaces/01m5mjvndq5spwdz5ny31z452d/membership`

```http
HTTP/1.1 204 No Content
X-Request-Id: 01a1692d-dd4e-7085-89ad-f52ab238eb2a
```

---

## Onboarding: business details and ownership (B03c, Trello #16)

Captured 2026-10-23 against local MySQL + Redis with bearer tokens, `GOOGLE_PLACES_API_KEY` empty and the log mailer (the ownership code was read from the local mail log, which was wiped afterwards). Jan owns *Fietsenwinkel Utrecht*, with Piet as employee. Eva owns *Fietsen Overname* and claims the same domain.

**Not captured live:** the Google Places 200 responses (no API key; the examples are test fixtures, NOT VERIFIED) and a successful DNS/file check (that needs a public domain we control; the success response is identical to the domain-email 200 below).

### Error codes

| Status | `code` | When |
| --- | --- | --- |
| 409 | `business_details_incomplete` | Verification or review before details are confirmed |
| 409 | `ownership_already_verified` / `review_in_progress` / `not_in_onboarding` | Wrong lifecycle state |
| 409 | `domain_already_claimed` | Another webshop already verified this domain. Use manual review |
| 409 | `domain_locked` | Changing the website domain after verification |
| 409 | `verification_not_pending` / `wrong_verification_method` | Challenge superseded, used, or of another type |
| 410 | `verification_expired` | Challenge past its expiry; the webshop becomes `verification_failed` |
| 422 | `business_details_incomplete` | `confirm: true` with missing fields (`details.missing_fields`) |
| 422 | `invalid_domain` | IP literal, internal or otherwise non-public domain |
| 422 | `challenge_not_found` | TXT record or file not (yet) found; the challenge stays pending |
| 422 | `mailbox_not_allowed` / `free_email_domain` | Domain-email restrictions |
| 422 | `verification_code_invalid` / `verification_attempts_exceeded` | Domain-email code problems |
| 502 / 503 | `provider_error` / `provider_not_configured` | Google Places unavailable or not configured |

### GET /business-directory/search (no API key)

`GET /api/v1/business-directory/search?query=fietsenwinkel%20utrecht`

```http
HTTP/1.1 503 Service Unavailable
X-Request-Id: 01a16938-d260-70fa-88b5-97fcb0a66bf5
Content-Type: application/json

{"error":{"status":503,"code":"provider_not_configured","message":"Business search is not configured. Enter the details manually.","request_id":"01a16938-d260-70fa-88b5-97fcb0a66bf5"}}
```

### GET /workspaces/{workspace}/onboarding, new webshop

`GET /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/onboarding`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16938-d2e2-72f0-b6e7-b2c3a722beb7

{"data":{"steps":[{"key":"business_details","status":"not_started"},{"key":"ownership","status":"not_started"},{"key":"agreement","status":"not_started"},{"key":"activation","status":"not_started"}],"next_step":"business_details","webshop_status":"draft"}}
```

### GET /workspaces/{workspace}/business-profile, new webshop

`GET /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/business-profile`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16938-d306-739e-ab48-e37984f19619

{"data":{"legal_name":null,"trading_name":null,"website_url":null,"website_domain":null,"kvk_number":null,"vat_number":null,"contact_email":null,"contact_phone":null,"main_contact_name":null,"address_line":null,"postal_code":null,"city":null,"country_code":null,"preferred_language":"nl","google_place_id":null,"business_category":null,"source":"manual","confirmed":false,"confirmed_at":null,"missing_fields":["legal_name","website_url","contact_email","address_line","postal_code","city","country_code"],"domain_locked":false}}
```

### PUT business-profile, partial draft

`PUT /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/business-profile {"legal_name":"Fietsen Utrecht B.V.","country_code":"NL"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16938-d325-70db-8138-8ae63b209cc1

{"data":{"legal_name":"Fietsen Utrecht B.V.","trading_name":null,"website_url":null,"website_domain":null,"kvk_number":null,"vat_number":null,"contact_email":null,"contact_phone":null,"main_contact_name":null,"address_line":null,"postal_code":null,"city":null,"country_code":"NL","preferred_language":"nl","google_place_id":null,"business_category":null,"source":"manual","confirmed":false,"confirmed_at":null,"missing_fields":["website_url","contact_email","address_line","postal_code","city","kvk_number"],"domain_locked":false}}
```

### PUT business-profile, confirm with fields missing

`PUT /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/business-profile {"confirm":true}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16938-d352-708b-b87f-73a1a5ca0901
Content-Type: application/json

{"error":{"status":422,"code":"business_details_incomplete","message":"Some required business details are missing.","request_id":"01a16938-d352-708b-b87f-73a1a5ca0901","details":{"missing_fields":["website_url","contact_email","address_line","postal_code","city","kvk_number"]}}}
```

### PUT business-profile, invalid KvK and VAT

`PUT /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/business-profile {"kvk_number":"123","vat_number":"NL12","website_url":"http://127.0.0.1/admin"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16938-d370-7248-8b3b-d65f3e782e3e
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16938-d370-7248-8b3b-d65f3e782e3e","fields":{"kvk_number":["The KvK number must have 8 digits."],"vat_number":["Enter a valid EU VAT number (for example NL123456789B01)."]}}}
```

### PUT business-profile, internal IP as website

`PUT /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/business-profile {"website_url":"http://127.0.0.1/admin"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16938-d38f-739f-82a8-d9c637b8430d
Content-Type: application/json

{"error":{"status":422,"code":"invalid_domain","message":"Enter a valid public website domain.","request_id":"01a16938-d38f-739f-82a8-d9c637b8430d"}}
```

### Starting verification before confirming details

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications {"method":"dns_txt"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16938-d3b1-73c5-b90d-d723b66e3cea
Content-Type: application/json

{"error":{"status":409,"code":"business_details_incomplete","message":"Confirm the business details first.","request_id":"01a16938-d3b1-73c5-b90d-d723b66e3cea"}}
```

### PUT business-profile, full profile confirmed (note the normalised domain and KvK)

`PUT /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/business-profile {"legal_name":"Fietsen Utrecht B.V.","trading_name":"Fietsenwinkel Utrecht","website_url":"https://www.Fietsen-Utrecht.nl/winkel","kvk_number":"1234 5678","vat_number":"NL123456789B01","contact_email":"info@fietsen-utrecht.nl","contact_phone":"+31 30 123 4567","main_contact_name":"Jan de Vries","address_line":"Oudegracht 1","postal_code":"3511 AA","city":"Utrecht","country_code":"NL","confirm":true}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16938-d3d2-7172-b196-e74f54110637

{"data":{"legal_name":"Fietsen Utrecht B.V.","trading_name":"Fietsenwinkel Utrecht","website_url":"https:\/\/www.Fietsen-Utrecht.nl\/winkel","website_domain":"fietsen-utrecht.nl","kvk_number":"12345678","vat_number":"NL123456789B01","contact_email":"info@fietsen-utrecht.nl","contact_phone":"+31 30 123 4567","main_contact_name":"Jan de Vries","address_line":"Oudegracht 1","postal_code":"3511 AA","city":"Utrecht","country_code":"NL","preferred_language":"nl","google_place_id":null,"business_category":null,"source":"manual","confirmed":true,"confirmed_at":"2026-10-23T14:44:54+00:00","missing_fields":[],"domain_locked":false}}
```

### Employee reading the profile

`GET /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/business-profile`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16938-d403-7390-bd0a-0c03e44a3ac4
Content-Type: application/json

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a16938-d403-7390-bd0a-0c03e44a3ac4","details":{"required_permission":"settings.view"}}}
```

### POST /ownership/verifications, DNS TXT

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications {"method":"dns_txt"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16938-d425-71bf-be2c-8c7af30d5954

{"data":{"id":"01m5mkhn1pmt95hs1y4kw4fr36","method":"dns_txt","status":"pending","domain":"fietsen-utrecht.nl","instructions":{"record_type":"TXT","record_name":"_shopbonus-verification.fietsen-utrecht.nl","record_value":"shopbonus-verification=a470a8f0f140e9469b28a588809327e1"},"attempt_count":0,"expires_at":"2026-10-30T14:44:54+00:00","verified_at":null,"failure_reason":null,"created_at":"2026-10-23T14:44:54+00:00"}}
```

### Check before the record exists (real DNS lookup)

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications/01m5mkhn1pmt95hs1y4kw4fr36/check`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16938-d459-70e0-92df-bd7d4a67c776
Content-Type: application/json

{"error":{"status":422,"code":"challenge_not_found","message":"The verification value was not found yet. DNS changes can take a while; try again later.","request_id":"01a16938-d459-70e0-92df-bd7d4a67c776"}}
```

### POST /ownership/verifications, website file

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications {"method":"website_file"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16938-d4e3-7267-af63-d7e01a870b9a

{"data":{"id":"01m5mkhn7mfpr3gh8g4h5r065b","method":"website_file","status":"pending","domain":"fietsen-utrecht.nl","instructions":{"url":"https:\/\/fietsen-utrecht.nl\/.well-known\/shopbonus-verification.txt","file_contents":"shopbonus-verification=4c99116a214526c2674c8bf208bcbe86"},"attempt_count":0,"expires_at":"2026-10-30T14:44:54+00:00","verified_at":null,"failure_reason":null,"created_at":"2026-10-23T14:44:54+00:00"}}
```

### Checking the superseded DNS challenge

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications/01m5mkhn1pmt95hs1y4kw4fr36/check`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16938-d50f-7368-aeb7-6aa357073a6d
Content-Type: application/json

{"error":{"status":409,"code":"verification_not_pending","message":"This verification is no longer open.","request_id":"01a16938-d50f-7368-aeb7-6aa357073a6d"}}
```

### Domain email with a non-administrative mailbox

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications {"method":"domain_email","mailbox":"info"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16938-d52f-7006-8d49-02c0ac9d627f
Content-Type: application/json

{"error":{"status":422,"code":"mailbox_not_allowed","message":"Choose one of the administrative mailboxes at your domain.","request_id":"01a16938-d52f-7006-8d49-02c0ac9d627f","details":{"allowed":["admin","administrator","webmaster","hostmaster","postmaster"]}}}
```

### Domain email to admin@

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications {"method":"domain_email","mailbox":"admin"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16938-d553-7091-9069-5e165a9eab16

{"data":{"id":"01m5mkhnb2h68qk1487k0m057x","method":"domain_email","status":"pending","domain":"fietsen-utrecht.nl","instructions":{"sent_to":"a****@fietsen-utrecht.nl"},"attempt_count":0,"expires_at":"2026-10-23T15:14:54+00:00","verified_at":null,"failure_reason":null,"created_at":"2026-10-23T14:44:54+00:00"}}
```

### Progress while the challenge is pending

`GET /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/onboarding`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16938-d652-729e-a60f-1af50290640a

{"data":{"steps":[{"key":"business_details","status":"complete"},{"key":"ownership","status":"in_progress"},{"key":"agreement","status":"not_started"},{"key":"activation","status":"not_started"}],"next_step":"ownership","webshop_status":"awaiting_verification"}}
```

### Wrong code

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications/01m5mkhnb2h68qk1487k0m057x/confirm-code {"code":"000000"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16938-d670-7023-b883-ea7a9e10e3e9
Content-Type: application/json

{"error":{"status":422,"code":"verification_code_invalid","message":"This code is not valid.","request_id":"01a16938-d670-7023-b883-ea7a9e10e3e9"}}
```

### Correct code: ownership verified

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications/01m5mkhnb2h68qk1487k0m057x/confirm-code {"code":"693024"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16938-d695-726b-bb5c-0edc94a0d89a

{"data":{"id":"01m5mkhnb2h68qk1487k0m057x","method":"domain_email","status":"verified","domain":"fietsen-utrecht.nl","instructions":{"sent_to":"a****@fietsen-utrecht.nl"},"attempt_count":1,"expires_at":"2026-10-23T15:14:54+00:00","verified_at":"2026-10-23T14:44:55+00:00","failure_reason":null,"created_at":"2026-10-23T14:44:54+00:00"}}
```

### GET /ownership/verifications

`GET /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16938-d6bf-734f-92c8-c6deacfbea5a

{"data":[{"id":"01m5mkhnb2h68qk1487k0m057x","method":"domain_email","status":"verified","domain":"fietsen-utrecht.nl","instructions":{"sent_to":"a****@fietsen-utrecht.nl"},"attempt_count":1,"expires_at":"2026-10-23T15:14:54+00:00","verified_at":"2026-10-23T14:44:55+00:00","failure_reason":null,"created_at":"2026-10-23T14:44:54+00:00"},{"id":"01m5mkhn7mfpr3gh8g4h5r065b","method":"website_file","status":"superseded","domain":"fietsen-utrecht.nl","instructions":{"url":"https:\/\/fietsen-utrecht.nl\/.well-known\/shopbonus-verification.txt","file_contents":"shopbonus-verification=4c99116a214526c2674c8bf208bcbe86"},"attempt_count":0,"expires_at":"2026-10-30T14:44:54+00:00","verified_at":null,"failure_reason":null,"created_at":"2026-10-23T14:44:54+00:00"},{"id":"01m5mkhn1pmt95hs1y4kw4fr36","method":"dns_txt","status":"superseded","domain":"fietsen-utrecht.nl","instructions":{"record_type":"TXT","record_name":"_shopbonus-verification.fietsen-utrecht.nl","record_value":"shopbonus-verification=a470a8f0f140e9469b28a588809327e1"},"attempt_count":1,"expires_at":"2026-10-30T14:44:54+00:00","verified_at":null,"failure_reason":null,"created_at":"2026-10-23T14:44:54+00:00"}]}
```

### Starting again after verification

`POST /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/ownership/verifications {"method":"dns_txt"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16938-d6de-73be-a663-eed3cecf3c25
Content-Type: application/json

{"error":{"status":409,"code":"ownership_already_verified","message":"Ownership is already verified.","request_id":"01a16938-d6de-73be-a663-eed3cecf3c25"}}
```

### Changing the domain after verification

`PUT /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/business-profile {"website_url":"https://ander-domein.nl"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16938-d6fe-733e-a530-c583cf7db3cf
Content-Type: application/json

{"error":{"status":409,"code":"domain_locked","message":"The website domain is locked after ownership verification.","request_id":"01a16938-d6fe-733e-a530-c583cf7db3cf"}}
```

### Progress after verification

`GET /api/v1/workspaces/01m5mkhkqpc76qtepcdx39ymn1/onboarding`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16938-d71b-70d3-8541-11238176c3ab

{"data":{"steps":[{"key":"business_details","status":"complete"},{"key":"ownership","status":"complete"},{"key":"agreement","status":"not_started"},{"key":"activation","status":"not_started"}],"next_step":"agreement","webshop_status":"draft"}}
```

### Eva claiming the already-verified domain

`POST /api/v1/workspaces/01m5mkhkr2csb13kw773vf2x97/ownership/verifications {"method":"dns_txt"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16938-d75e-7150-9788-007347328fe3
Content-Type: application/json

{"error":{"status":409,"code":"domain_already_claimed","message":"This domain is already verified for another webshop. Submit a manual review instead.","request_id":"01a16938-d75e-7150-9788-007347328fe3"}}
```

### Eva submits a manual review with a PDF

`POST /api/v1/workspaces/01m5mkhkr2csb13kw773vf2x97/ownership/review (multipart: notes, evidence[]=koopovereenkomst.pdf)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16938-d77f-7127-80fc-73adb7733901

{"data":{"id":"01m5mkhnx5yhpk64tw2mtqvpq0","method":"manual_review","status":"submitted","domain":"fietsen-utrecht.nl","instructions":{"message":"A Shopbonus administrator will review the submitted information."},"attempt_count":0,"expires_at":null,"verified_at":null,"failure_reason":null,"created_at":"2026-10-23T14:44:55+00:00"}}
```

### Eva's progress (in review, flagged duplicate_domain_claim server-side)

`GET /api/v1/workspaces/01m5mkhkr2csb13kw773vf2x97/onboarding`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16938-d7c3-72e2-b334-a10b7c1e5ca5

{"data":{"steps":[{"key":"business_details","status":"complete"},{"key":"ownership","status":"in_review"},{"key":"agreement","status":"not_started"},{"key":"activation","status":"not_started"}],"next_step":"agreement","webshop_status":"pending_review"}}
```

### Submitting a second review

`POST /api/v1/workspaces/01m5mkhkr2csb13kw773vf2x97/ownership/review {"notes":"Nogmaals graag beoordelen."}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16938-d7e2-7280-b7d6-3cdd362251a4
Content-Type: application/json

{"error":{"status":409,"code":"review_in_progress","message":"A review is already in progress.","request_id":"01a16938-d7e2-7280-b7d6-3cdd362251a4"}}
```

---

## Agreements, review and activation (B03d, Trello #16)

Captured 2026-10-23 against local MySQL + Redis. Super Admin Lotte was granted by `php artisan shopbonus:grant-super-admin` and uses a **browser session** (cookie jar + `X-XSRF-TOKEN`) with a password confirmation, because admin mutations refuse bearer tokens. Merchants use bearer tokens.
- Jan: ownership verified by domain email.
- Eva: claims the same domain through manual review with a PDF.
- Piet: manual review with no evidence.

The agreement text below is **placeholder sample text written for the capture, not legal content**. Shopbonus seeds no agreement; the owner's legal team supplies it.

### Lifecycle

| From | Event | To |
| --- | --- | --- |
| draft (ownership verified by challenge, nothing flagged) | owner accepts current agreement | **approved** (automatic, audited `automatic: true`) |
| pending_review | owner accepts agreement | pending_review (waits for Super Admin) |
| pending_review / draft / awaiting_verification / verification_failed | Super Admin approve (agreement accepted) | approved |
| same | Super Admin reject (reason) | rejected |
| any except suspended | Super Admin suspend (reason) | suspended (members get 403 immediately) |
| suspended | Super Admin unsuspend (reason) | previous approved / rejected / draft |

Commercial routes (offers, vouchers and so on, from B06) use `workspace.commercial`. It returns 403 `workspace_not_active` unless the webshop is approved, and 403 `agreement_reacceptance_required` when a newer version that requires re-acceptance has been published.

| Status | `code` | When |
| --- | --- | --- |
| 403 | `platform_role_required` | Not a Super Admin (audited) |
| 423 | `password_confirmation_required` | Admin mutation without a recent password confirmation in a browser session |
| 409 | `agreement_outdated` | Accepting a version or hash that isn't current (`details.current_version`) |
| 409 | `ownership_not_verified` / `agreement_not_accepted` / `duplicate_claim_unconfirmed` / `invalid_status_transition` / `template_not_draft` / `template_version_exists` | Lifecycle and template rules |
| 503 | `agreement_unavailable` | No published merchant agreement |

### A merchant calling the admin API

`GET /api/v1/admin/webshops`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16941-5d6e-7359-9671-2839d8dcac47
Content-Type: application/json

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"01a16941-5d6e-7359-9671-2839d8dcac47"}}
```

### Admin mutation before confirming the password

`POST /api/v1/admin/agreement-templates {"key":"merchant_agreement","version":"2026.1","locale":"nl","title":"Merchantovereenkomst","body":"x"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 423 Locked
X-Request-Id: 01a16941-5e14-70c6-9bbf-778b1af35937
Content-Type: application/json

{"error":{"status":423,"code":"password_confirmation_required","message":"Confirm your password to continue.","request_id":"01a16941-5e14-70c6-9bbf-778b1af35937"}}
```

### Template with an unknown placeholder

`POST /api/v1/admin/agreement-templates {"key":"merchant_agreement","version":"2026.1","locale":"nl","title":"Merchantovereenkomst","body":"Overeenkomst met {{legal_name}}, IBAN {{iban}}"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16941-5fca-72ee-b101-187b427b213f
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16941-5fca-72ee-b101-187b427b213f","fields":{"body":["Unknown placeholders: iban. Allowed: webshop_name, legal_name, trading_name, kvk_number, vat_number, website_domain, address."]}}}
```

### Create a draft version

`POST /api/v1/admin/agreement-templates {"key":"merchant_agreement","version":"2026.1","locale":"nl","title":"Merchantovereenkomst (voorbeeld)","body":"# Merchantovereenkomst (VOORBEELDTEKST, geen juridische inhoud)\n\nPartijen: Shopbonus en {{legal_name}} (KvK {{kvk_number}}), gevestigd te {{address}}, handelend onder {{webshop_name}} ({{website_domain}})."}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16941-6013-7373-81c6-c0b83ecc4e9f

{"data":{"id":"01m5mm2r0zjz3qv3ycdnfbp1n1","key":"merchant_agreement","version":"2026.1","locale":"nl","title":"Merchantovereenkomst (voorbeeld)","body":"# Merchantovereenkomst (VOORBEELDTEKST, geen juridische inhoud)\n\nPartijen: Shopbonus en {{legal_name}} (KvK {{kvk_number}}), gevestigd te {{address}}, handelend onder {{webshop_name}} ({{website_domain}}).","status":"draft","requires_reacceptance":null,"published_at":null,"retired_at":null,"created_at":"2026-10-23T14:54:14+00:00"}}
```

### Edit the draft

`PATCH /api/v1/admin/agreement-templates/01m5mm2r0zjz3qv3ycdnfbp1n1 {"title":"Merchantovereenkomst 2026 (voorbeeld)"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-6068-737b-917d-e3a0ffc16a04

{"data":{"id":"01m5mm2r0zjz3qv3ycdnfbp1n1","key":"merchant_agreement","version":"2026.1","locale":"nl","title":"Merchantovereenkomst 2026 (voorbeeld)","body":"# Merchantovereenkomst (VOORBEELDTEKST, geen juridische inhoud)\n\nPartijen: Shopbonus en {{legal_name}} (KvK {{kvk_number}}), gevestigd te {{address}}, handelend onder {{webshop_name}} ({{website_domain}}).","status":"draft","requires_reacceptance":true,"published_at":null,"retired_at":null,"created_at":"2026-10-23T14:54:14+00:00"}}
```

### Publish

`POST /api/v1/admin/agreement-templates/01m5mm2r0zjz3qv3ycdnfbp1n1/publish    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-60b4-716b-805a-838813579efc

{"data":{"id":"01m5mm2r0zjz3qv3ycdnfbp1n1","key":"merchant_agreement","version":"2026.1","locale":"nl","title":"Merchantovereenkomst 2026 (voorbeeld)","body":"# Merchantovereenkomst (VOORBEELDTEKST, geen juridische inhoud)\n\nPartijen: Shopbonus en {{legal_name}} (KvK {{kvk_number}}), gevestigd te {{address}}, handelend onder {{webshop_name}} ({{website_domain}}).","status":"published","requires_reacceptance":true,"published_at":"2026-10-23T14:54:14+00:00","retired_at":null,"created_at":"2026-10-23T14:54:14+00:00"}}
```

### Editing published text

`PATCH /api/v1/admin/agreement-templates/01m5mm2r0zjz3qv3ycdnfbp1n1 {"body":"Gewijzigd"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16941-610e-7070-be16-be7ea6648b06
Content-Type: application/json

{"error":{"status":409,"code":"template_not_draft","message":"Published templates cannot be edited. Create a new version.","request_id":"01a16941-610e-7070-be16-be7ea6648b06"}}
```

### List templates

`GET /api/v1/admin/agreement-templates    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-615a-720c-80cc-4d0bd48aea8b

{"data":[{"id":"01m5mm2r0zjz3qv3ycdnfbp1n1","key":"merchant_agreement","version":"2026.1","locale":"nl","title":"Merchantovereenkomst 2026 (voorbeeld)","body":"# Merchantovereenkomst (VOORBEELDTEKST, geen juridische inhoud)\n\nPartijen: Shopbonus en {{legal_name}} (KvK {{kvk_number}}), gevestigd te {{address}}, handelend onder {{webshop_name}} ({{website_domain}}).","status":"published","requires_reacceptance":true,"published_at":"2026-10-23T14:54:14+00:00","retired_at":null,"created_at":"2026-10-23T14:54:14+00:00"}]}
```

### GET /workspaces/{workspace}/agreement (Jan)

`GET /api/v1/workspaces/01m5mm2n20560s3vdpcf8mbsd9/agreement`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-6179-72b4-bb77-02ed9c58d04b

{"data":{"title":"Merchantovereenkomst 2026 (voorbeeld)","version":"2026.1","locale":"nl","body":"# Merchantovereenkomst (VOORBEELDTEKST, geen juridische inhoud)\n\nPartijen: Shopbonus en Fietsen Utrecht B.V. (KvK 12345678), gevestigd te Oudegracht 1, 3511 AA Utrecht, NL, handelend onder Fietsenwinkel Utrecht (fietsen-utrecht.nl).","document_hash":"54d8d27e5acaf0c46d52c407b373277e8249ea10fb7d5b594d67d4ed825fa8ee","published_at":"2026-10-23T14:54:14+00:00","status":"not_started","latest_acceptance":null}}
```

### Accepting with the checkbox unticked

`POST /api/v1/workspaces/01m5mm2n20560s3vdpcf8mbsd9/agreement/accept {"accept":false,"confirm_identity":true,"signatory_name":"Jan de Vries","version":"2026.1","document_hash":"54d8d27e5acaf0c46d52c407b373277e8249ea10fb7d5b594d67d4ed825fa8ee"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16941-61a1-7352-9559-1a9e50bcd4ab
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16941-61a1-7352-9559-1a9e50bcd4ab","fields":{"accept":["The accept field must be accepted."]}}}
```

### Accepting with a forged document hash

`POST /api/v1/workspaces/01m5mm2n20560s3vdpcf8mbsd9/agreement/accept {"accept":true,"confirm_identity":true,"signatory_name":"Jan de Vries","version":"2026.1","document_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16941-61c1-7063-9692-188d10fac403
Content-Type: application/json

{"error":{"status":409,"code":"agreement_outdated","message":"The agreement has changed. Reload it, review the current version and accept again.","request_id":"01a16941-61c1-7063-9692-188d10fac403","details":{"current_version":"2026.1"}}}
```

### Accepting the current agreement: auto-approved

`POST /api/v1/workspaces/01m5mm2n20560s3vdpcf8mbsd9/agreement/accept {"accept":true,"confirm_identity":true,"signatory_name":"Jan de Vries","version":"2026.1","document_hash":"54d8d27e5acaf0c46d52c407b373277e8249ea10fb7d5b594d67d4ed825fa8ee"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16941-61e5-73c6-846e-3b9f62988b80

{"data":{"id":"01m5mm2rfp3cq75b47mf52qd41","version":"2026.1","document_hash":"54d8d27e5acaf0c46d52c407b373277e8249ea10fb7d5b594d67d4ed825fa8ee","signatory_name":"Jan de Vries","accepted_at":"2026-10-23T14:54:15+00:00","webshop_status":"approved"}}
```

### Jan’s onboarding afterwards

`GET /api/v1/workspaces/01m5mm2n20560s3vdpcf8mbsd9/onboarding`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-6215-7065-b3d7-c12fbf6d2282

{"data":{"steps":[{"key":"business_details","status":"complete"},{"key":"ownership","status":"complete"},{"key":"agreement","status":"complete"},{"key":"activation","status":"complete"}],"next_step":null,"webshop_status":"approved"}}
```

### Admin: webshops pending review

`GET /api/v1/admin/webshops?status=pending_review    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-6264-71b7-9f91-279fe70f4a4a

{"data":[{"id":"01m5mm2n2jfdwbeywxaz2myhd8","name":"Onbekende Shop","platform":"other","status":"pending_review","status_reason":null,"review_flags":[],"ownership_verified":false,"ownership_method":null,"verified_domain":null,"website_domain":"onbekende-shop.nl","owners":[{"id":"01m5mm2n1swp40qhtv0r2k4cvk","first_name":"Piet","email":"piet@example.nl"}],"review_requested_at":"2026-10-23T14:54:13+00:00","approved_at":null,"created_at":"2026-10-23T14:54:11+00:00"},{"id":"01m5mm2n2ezmg4a8w7jm7cs61k","name":"Fietsen Overname","platform":"shopify","status":"pending_review","status_reason":null,"review_flags":["duplicate_domain_claim"],"ownership_verified":false,"ownership_method":null,"verified_domain":null,"website_domain":"fietsen-utrecht.nl","owners":[{"id":"01m5mm2mtvvzrq38shzrw3rv99","first_name":"Eva","email":"eva@example.nl"}],"review_requested_at":"2026-10-23T14:54:13+00:00","approved_at":null,"created_at":"2026-10-23T14:54:11+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8779\/api\/v1\/admin\/webshops?page=1","last":"http:\/\/127.0.0.1:8779\/api\/v1\/admin\/webshops?page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8779\/api\/v1\/admin\/webshops?page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8779\/api\/v1\/admin\/webshops","per_page":25,"to":2,"total":2}}
```

### Admin: review detail (Eva)

`GET /api/v1/admin/webshops/01m5mm2n2ezmg4a8w7jm7cs61k    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-62b6-70e7-8806-97899253b69b

{"data":{"id":"01m5mm2n2ezmg4a8w7jm7cs61k","name":"Fietsen Overname","platform":"shopify","status":"pending_review","status_reason":null,"review_flags":["duplicate_domain_claim"],"ownership_verified":false,"ownership_method":null,"verified_domain":null,"website_domain":"fietsen-utrecht.nl","owners":[{"id":"01m5mm2mtvvzrq38shzrw3rv99","first_name":"Eva","email":"eva@example.nl"}],"review_requested_at":"2026-10-23T14:54:13+00:00","approved_at":null,"created_at":"2026-10-23T14:54:11+00:00","business_profile":{"legal_name":"Fietsen Overname B.V.","trading_name":null,"website_url":"https:\/\/fietsen-utrecht.nl","website_domain":"fietsen-utrecht.nl","kvk_number":"12345678","vat_number":null,"contact_email":"info@fietsen-utrecht.nl","contact_phone":null,"main_contact_name":null,"address_line":"Oudegracht 1","postal_code":"3511 AA","city":"Utrecht","country_code":"NL","preferred_language":"nl","google_place_id":null,"business_category":null,"source":"manual","confirmed":true,"confirmed_at":"2026-10-23T14:54:13+00:00","missing_fields":[],"domain_locked":false},"verifications":[{"id":"01m5mm2pv9ht4x57wb81dac66f","method":"manual_review","status":"submitted","domain":"fietsen-utrecht.nl","instructions":{"message":"A Shopbonus administrator will review the submitted information."},"attempt_count":0,"expires_at":null,"verified_at":null,"failure_reason":null,"created_at":"2026-10-23T14:54:13+00:00","notes":"Wij hebben deze webshop per 1 oktober 2026 overgenomen","evidence_files":[{"id":"01a16941-5b54-728c-b089-950bbac6038a","name":"koopovereenkomst.pdf","mime":"application\/pdf","size":47}]}],"agreement_acceptances":[]}}
```

### Admin: evidence download

`GET /api/v1/admin/webshops/01m5mm2n2ezmg4a8w7jm7cs61k/evidence/01a16941-5b54-728c-b089-950bbac6038a   (session cookie)`

```http
HTTP/1.1 200 OK
Content-Type: application/pdf
Content-Disposition: attachment; filename="koopovereenkomst.pdf"
X-Content-Type-Options: nosniff
Cache-Control: no-store, private
X-Request-Id: 01a16941-62e6-738f-85a7-65dc45e66fca
(body: the original PDF, 47 bytes, identical to the upload: yes)
```

### Approving before the agreement is accepted

`POST /api/v1/admin/webshops/01m5mm2n2ezmg4a8w7jm7cs61k/approve {}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16941-6344-727d-883b-d80f6cef3a55
Content-Type: application/json

{"error":{"status":409,"code":"agreement_not_accepted","message":"The current merchant agreement has not been accepted.","request_id":"01a16941-6344-727d-883b-d80f6cef3a55"}}
```

### Eva accepts: stays pending_review

`POST /api/v1/workspaces/01m5mm2n2ezmg4a8w7jm7cs61k/agreement/accept {"accept":true,"confirm_identity":true,"signatory_name":"Eva Jansen","version":"2026.1","document_hash":"99620510c3f1efbdf122d16a2a91df7d15e3cdcc71c8636188fbea340f389672"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16941-639e-7047-8c1f-9ae427a89eb4

{"data":{"id":"01m5mm2rxbhegpqc4twd694t9b","version":"2026.1","document_hash":"99620510c3f1efbdf122d16a2a91df7d15e3cdcc71c8636188fbea340f389672","signatory_name":"Eva Jansen","accepted_at":"2026-10-23T14:54:15+00:00","webshop_status":"pending_review"}}
```

### Approving a duplicate claim without confirming it

`POST /api/v1/admin/webshops/01m5mm2n2ezmg4a8w7jm7cs61k/approve {}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16941-63f3-71e8-9830-e0e1d6976359
Content-Type: application/json

{"error":{"status":409,"code":"duplicate_claim_unconfirmed","message":"This webshop claims a domain verified by another webshop. Confirm the duplicate claim explicitly to approve.","request_id":"01a16941-63f3-71e8-9830-e0e1d6976359"}}
```

### Approving with the duplicate confirmed

`POST /api/v1/admin/webshops/01m5mm2n2ezmg4a8w7jm7cs61k/approve {"confirm_duplicate_claim":true,"reason":"Overname bevestigd met koopovereenkomst."}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-6444-73fd-84ef-c00b7f15a6b8

{"data":{"id":"01m5mm2n2ezmg4a8w7jm7cs61k","name":"Fietsen Overname","platform":"shopify","status":"approved","status_reason":"Overname bevestigd met koopovereenkomst.","review_flags":["duplicate_domain_claim"],"ownership_verified":true,"ownership_method":"manual_review","verified_domain":null,"website_domain":"fietsen-utrecht.nl","owners":[{"id":"01m5mm2mtvvzrq38shzrw3rv99","first_name":"Eva","email":"eva@example.nl"}],"review_requested_at":"2026-10-23T14:54:13+00:00","approved_at":"2026-10-23T14:54:15+00:00","created_at":"2026-10-23T14:54:11+00:00"}}
```

### Rejecting without a reason

`POST /api/v1/admin/webshops/01m5mm2n2jfdwbeywxaz2myhd8/reject {}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16941-64a3-7183-bd76-47209abcc3c0
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16941-64a3-7183-bd76-47209abcc3c0","fields":{"reason":["The reason field is required."]}}}
```

### Rejecting Piet’s webshop

`POST /api/v1/admin/webshops/01m5mm2n2jfdwbeywxaz2myhd8/reject {"reason":"Eigendom kon niet worden vastgesteld."}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-64ea-726d-8fc8-65868387a50b

{"data":{"id":"01m5mm2n2jfdwbeywxaz2myhd8","name":"Onbekende Shop","platform":"other","status":"rejected","status_reason":"Eigendom kon niet worden vastgesteld.","review_flags":[],"ownership_verified":false,"ownership_method":null,"verified_domain":null,"website_domain":"onbekende-shop.nl","owners":[{"id":"01m5mm2n1swp40qhtv0r2k4cvk","first_name":"Piet","email":"piet@example.nl"}],"review_requested_at":"2026-10-23T14:54:13+00:00","approved_at":null,"created_at":"2026-10-23T14:54:11+00:00"}}
```

### Suspending Jan’s webshop

`POST /api/v1/admin/webshops/01m5mm2n20560s3vdpcf8mbsd9/suspend {"reason":"Onderzoek naar terugboekingen."}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-653b-708b-9c89-4de2e0ce2edd

{"data":{"id":"01m5mm2n20560s3vdpcf8mbsd9","name":"Fietsenwinkel Utrecht","platform":"woocommerce","status":"suspended","status_reason":"Onderzoek naar terugboekingen.","review_flags":[],"ownership_verified":true,"ownership_method":"domain_email","verified_domain":"fietsen-utrecht.nl","website_domain":"fietsen-utrecht.nl","owners":[{"id":"01m5mm2mm147atqbmbca8ftese","first_name":"Jan","email":"jan@example.nl"}],"review_requested_at":null,"approved_at":"2026-10-23T14:54:15+00:00","created_at":"2026-10-23T14:54:11+00:00"}}
```

### Jan while suspended

`GET /api/v1/workspaces/01m5mm2n20560s3vdpcf8mbsd9`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16941-6562-7111-931a-e358789a9aa4
Content-Type: application/json

{"error":{"status":403,"code":"workspace_suspended","message":"This workspace is suspended.","request_id":"01a16941-6562-7111-931a-e358789a9aa4"}}
```

### Lifting the suspension

`POST /api/v1/admin/webshops/01m5mm2n20560s3vdpcf8mbsd9/unsuspend {"reason":"Onderzoek afgerond."}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16941-65ac-730c-af61-63c8fad70d81

{"data":{"id":"01m5mm2n20560s3vdpcf8mbsd9","name":"Fietsenwinkel Utrecht","platform":"woocommerce","status":"approved","status_reason":"Onderzoek afgerond.","review_flags":[],"ownership_verified":true,"ownership_method":"domain_email","verified_domain":"fietsen-utrecht.nl","website_domain":"fietsen-utrecht.nl","owners":[{"id":"01m5mm2mm147atqbmbca8ftese","first_name":"Jan","email":"jan@example.nl"}],"review_requested_at":null,"approved_at":"2026-10-23T14:54:15+00:00","created_at":"2026-10-23T14:54:11+00:00"}}
```

---

## Integrations: connections and webhooks (B04, Trello #1)

Captured 2026-10-23 against local MySQL + Redis with `APP_URL=https://api.shopbonus.example` and a sync queue. Webhook signatures were computed independently with `openssl`, so the server's verification was exercised for real. **Secrets, webhook tokens and signatures are redacted** in these examples. The provider payloads are realistic fixtures sent by curl; **no real WooCommerce or Shopify store was involved** (adapters NOT VERIFIED; see `docs/integrations/VERIFICATION.md`).

### Connection status

| Status | Meaning |
| --- | --- |
| `setup_required` | Created; waiting for the shop to send anything |
| `testing` | A verified ping arrived (e.g. WooCommerce sends one when a webhook is saved) |
| `connected` | A verified order with all mandatory fields arrived |
| `limited` | The first verified order lacked mandatory fields (`limitations: ["additional_integration_required"]`, e.g. Shopify redacted customer data) |
| `error` | Reserved for the health monitor (B05/B16) |
| `disconnected` | Credentials wiped; the webhook URL returns 404 |

Mandatory order fields: external ID, reference, first name, email, amount (minor units), currency, placed-at. A missing phone keeps the order valid but not callable.

### Shopbonus webhook format v1

For private modules (PrestaShop, OpenCart, Shopware 6, Magento) and custom shops. POST JSON to the connection's webhook URL with:

| Header | Value |
| --- | --- |
| `X-Shopbonus-Signature` | `t=<unix seconds>,v1=<hex HMAC-SHA256(signing_secret, "<t>.<raw body>")>`. Several `v1=` values are allowed (during secret rotation). Requests more than 300 s from server time are rejected (`timestamp_out_of_tolerance`) |
| `X-Shopbonus-Event-Id` | Unique per event, at most 100 chars. Retries **must** reuse it (dedupe) |
| `X-Shopbonus-Topic` | `order.created`, `order.updated`, `order.paid`, `order.cancelled`, `order.refunded` or `ping` |

Body:

```json
{
  "order": {
    "id": "PS-1001",                 // shop's internal order ID (string)
    "reference": "XKBKNABJK",        // reference shown to the customer
    "currency": "EUR",               // ISO 4217, upper case
    "total_minor": 5995,             // integer minor units — never a float or string
    "customer": { "first_name": "Anna", "email": "anna@example.nl", "phone": "+31612345678" },
    "payment_status": "paid",        // paid | pending | failed | cancelled | refunded | partially_refunded
    "status": "payment_accepted",    // shop's own status (informational)
    "created_at": "2026-10-23T10:00:00Z",
    "paid_at": "2026-10-23T10:01:00Z",
    "cancelled_at": null,
    "refunded_at": null,
    "refunded_minor": 0
  }
}
```

Signing example (bash):

```bash
T=$(date +%s)
SIG=$(printf '%s' "$T.$(cat order.json)" | openssl dgst -sha256 -hmac "$SIGNING_SECRET" | awk '{print $NF}')
curl -X POST "$WEBHOOK_URL" -H 'Content-Type: application/json' \
  -H "X-Shopbonus-Signature: t=$T,v1=$SIG" -H 'X-Shopbonus-Event-Id: evt_01' -H 'X-Shopbonus-Topic: order.paid' \
  --data-binary @order.json
```

Responses: 202 (accepted), 200 `duplicate: true` (already received; do not retry), 401 (fix signing; do not retry blindly), 404 (wrong or disconnected URL), 429 (back off). Sign the exact bytes you send.

### GET /integrations/platforms

`GET /api/v1/integrations/platforms`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b485-7046-af8d-e334541ef11a

{"data":[{"platform":"woocommerce","name":"WooCommerce","priority":"production","available":true,"method":"webhook","auth":"shared secret (HMAC-SHA256, base64)","reliability":"high","limitations":[],"verification":"unverified","blocked_reason":null},{"platform":"shopify","name":"Shopify","priority":"production","available":true,"method":"webhook","auth":"webhook signing secret (HMAC-SHA256, base64)","reliability":"high","limitations":["customer fields may be redacted without protected customer data access"],"verification":"unverified","blocked_reason":null},{"platform":"wix","name":"Wix","priority":"production","available":false,"method":"automation HTTP POST","auth":"to be determined (verify whether automations can sign or authenticate requests)","reliability":"unknown","limitations":[],"verification":"unverified","blocked_reason":"Request authentication for Wix automations not yet verified."},{"platform":"bigcommerce","name":"BigCommerce","priority":"production","available":false,"method":"webhook + API lookup","auth":"restricted API token","reliability":"unknown","limitations":[],"verification":"unverified","blocked_reason":"Requires API credentials and order lookup (webhooks carry only IDs)."},{"platform":"ecwid","name":"Ecwid","priority":"production","available":false,"method":"webhook\/polling + API","auth":"store API token","reliability":"unknown","limitations":[],"verification":"unverified","blocked_reason":"Requires store API token and app registration."},{"platform":"lightspeed_c","name":"Lightspeed C-Series","priority":"production","available":false,"method":"confirmation tracking where supported","auth":"to be determined","reliability":"lower","limitations":["lower reliability"],"verification":"unverified","blocked_reason":"Supported mechanism not yet verified."},{"platform":"lightspeed_e","name":"Lightspeed E-Series","priority":"additional","available":false,"method":"API\/confirmation (to be determined)","auth":"to be determined","reliability":"unknown","limitations":[],"verification":"unverified","blocked_reason":"Supported API method not yet verified."},{"platform":"prestashop","name":"PrestaShop","priority":"additional","available":true,"method":"private_module","auth":"Shopbonus signed webhook v1","reliability":"high (module not yet built)","limitations":["private ZIP module must be installed"],"verification":"unverified","blocked_reason":null},{"platform":"opencart","name":"OpenCart","priority":"additional","available":true,"method":"private_module","auth":"Shopbonus signed webhook v1","reliability":"high (module not yet built)","limitations":["private OCMOD extension must be installed"],"verification":"unverified","blocked_reason":null},{"platform":"shopware6","name":"Shopware 6","priority":"additional","available":true,"method":"private_module","auth":"Shopbonus signed webhook v1","reliability":"high (extension not yet built)","limitations":["private extension must be installed"],"verification":"unverified","blocked_reason":null},{"platform":"magento","name":"Magento \/ Adobe Commerce","priority":"additional","available":true,"method":"private_module","auth":"Shopbonus signed webhook v1","reliability":"high (module not yet built)","limitations":["private module must be installed"],"verification":"unverified","blocked_reason":null},{"platform":"squarespace","name":"Squarespace","priority":"additional","available":false,"method":"polling (read-only Orders API)","auth":"API key","reliability":"polling delay","limitations":["polling"],"verification":"unverified","blocked_reason":"Polling adapter not built; requires API key."},{"platform":"mijnwebwinkel","name":"Mijnwebwinkel","priority":"additional","available":false,"method":"partner API","auth":"partner authorisation","reliability":"unknown","limitations":[],"verification":"unverified","blocked_reason":"Partner\/API authorisation pending."},{"platform":"ccv","name":"CCV Shop","priority":"additional","available":false,"method":"to be investigated","auth":"to be determined","reliability":"unknown","limitations":[],"verification":"unverified","blocked_reason":"Merchant-accessible API options not yet investigated."},{"platform":"other","name":"Other \/ custom shop","priority":"additional","available":true,"method":"webhook","auth":"Shopbonus signed webhook v1","reliability":"depends on the custom integration","limitations":[],"verification":"unverified","blocked_reason":null}]}
```

### Connecting an unsupported platform

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections {"platform":"wix"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a1694b-b4d7-72f9-976d-87a0751a79b6
Content-Type: application/json

{"error":{"status":409,"code":"platform_not_available","message":"This platform cannot be connected yet.","request_id":"01a1694b-b4d7-72f9-976d-87a0751a79b6","details":{"reason":"Request authentication for Wix automations not yet verified."}}}
```

### POST /workspaces/{workspace}/connections (WooCommerce)

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections {"platform":"woocommerce"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a1694b-b513-71ff-9097-343af429202e

{"data":{"id":"01m5mmqd944fxvw6ndwyxmd8qs","platform":"woocommerce","method":"webhook","status":"setup_required","external_shop_ref":null,"limitations":[],"last_event_at":null,"last_verified_order_at":null,"connected_at":null,"disconnected_at":null,"last_error":null,"created_at":"2026-10-23T15:05:31+00:00","setup":{"format":"woocommerce-webhooks","webhook_url":"https:\/\/api.shopbonus.example\/api\/v1\/webhooks\/woocommerce\/<token>","steps":["In WordPress go to WooCommerce \u2192 Settings \u2192 Advanced \u2192 Webhooks \u2192 Add webhook.","Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.","Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.","Save, then place a real paid test order."]},"secrets":{"webhook_secret":"<secret shown once>"},"secrets_notice":"Shown only once. Store it in your shop settings now; it cannot be retrieved later."}}
```

### Second WooCommerce connection

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections {"platform":"woocommerce"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a1694b-b54f-71a4-a22e-92820b37f4af
Content-Type: application/json

{"error":{"status":409,"code":"connection_exists","message":"This webshop already has a connection for this platform.","request_id":"01a1694b-b54f-71a4-a22e-92820b37f4af"}}
```

### Shopify with invalid input

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections {"platform":"shopify","shop_domain":"evil.com","signing_secret":"short"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a1694b-b570-728a-ab48-991f09afe195
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a1694b-b570-728a-ab48-991f09afe195","fields":{"shop_domain":["The shop domain field format is invalid."],"signing_secret":["The signing secret field must be at least 16 characters."]}}}
```

### Shopify with merchant-supplied secret

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections {"platform":"shopify","shop_domain":"fietsen.myshopify.com","signing_secret":"shpss_merchant_supplied_secret"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a1694b-b592-7055-bc33-ae998b7d31c9

{"data":{"id":"01m5mmqdcww6d106jp3f92yez8","platform":"shopify","method":"webhook","status":"setup_required","external_shop_ref":"fietsen.myshopify.com","limitations":[],"last_event_at":null,"last_verified_order_at":null,"connected_at":null,"disconnected_at":null,"last_error":null,"created_at":"2026-10-23T15:05:31+00:00","setup":{"format":"shopify-webhooks","webhook_url":"https:\/\/api.shopbonus.example\/api\/v1\/webhooks\/shopify\/<token>","shop_domain":"fietsen.myshopify.com","steps":["In Shopify admin open Settings \u2192 Notifications \u2192 Webhooks and create webhooks (JSON) for \"Order creation\", \"Order payment\", \"Order update\" and \"Order cancellation\" to the webhook URL.","Copy the signing secret shown on that page into this connection.","Place a genuine paid test order. Shopify may hide customer details; if so the connection shows \"Connected with limitations\" until protected customer data access is arranged."]}}}
```

### PrestaShop (Shopbonus format, private module)

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections {"platform":"prestashop"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a1694b-b5c0-7246-9f72-2f135275dd6c

{"data":{"id":"01m5mmqdeessjag26x40n5dfk2","platform":"prestashop","method":"private_module","status":"setup_required","external_shop_ref":null,"limitations":[],"last_event_at":null,"last_verified_order_at":null,"connected_at":null,"disconnected_at":null,"last_error":null,"created_at":"2026-10-23T15:05:31+00:00","setup":{"format":"shopbonus-webhook-v1","webhook_url":"https:\/\/api.shopbonus.example\/api\/v1\/webhooks\/prestashop\/<token>","steps":["Install the private Shopbonus module for your shop (provided by Shopbonus support).","Enter the webhook URL and the signing secret shown once when the connection was created (or after rotating it).","Place a real test order. The connection becomes Connected after a verified order with all required fields."],"documentation":"docs\/api\/README.md#shopbonus-webhook-format-v1"},"secrets":{"signing_secret":"<secret shown once>"},"secrets_notice":"Shown only once. Store it in your shop settings now; it cannot be retrieved later."}}
```

### GET connection (no secret)

`GET /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections/01m5mmqd944fxvw6ndwyxmd8qs`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b5fc-726d-bfb1-5849086b35cf

{"data":{"id":"01m5mmqd944fxvw6ndwyxmd8qs","platform":"woocommerce","method":"webhook","status":"setup_required","external_shop_ref":null,"limitations":[],"last_event_at":null,"last_verified_order_at":null,"connected_at":null,"disconnected_at":null,"last_error":null,"created_at":"2026-10-23T15:05:31+00:00","setup":{"format":"woocommerce-webhooks","webhook_url":"https:\/\/api.shopbonus.example\/api\/v1\/webhooks\/woocommerce\/<token>","steps":["In WordPress go to WooCommerce \u2192 Settings \u2192 Advanced \u2192 Webhooks \u2192 Add webhook.","Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.","Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.","Save, then place a real paid test order."]}}}
```

### Verify checklist before any event

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections/01m5mmqd944fxvw6ndwyxmd8qs/verify`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b61a-704d-90f7-adda9afb2730

{"data":{"status":"setup_required","checks":{"credentials_configured":true,"event_received":false,"order_received":false,"mandatory_fields_present":false,"phone_available":false,"webshop_ownership_verified":false},"missing_fields":[],"limitations":[],"next_action":"configure_webhook"}}
```

### Webhook: signed WooCommerce ping

`POST /api/v1/webhooks/woocommerce/<token> (provider headers: X-WC-Webhook-Signature: <base64 signature>)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a1694b-b664-7191-abc7-aac52da29d68

{"data":{"received":true,"duplicate":false,"event_id":"01m5mmqdkcfye2x1tad9r4fgk5"}}
```

### Connection after the ping (testing)

`GET /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections/01m5mmqd944fxvw6ndwyxmd8qs`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b691-711e-a227-386b10b45053

{"data":{"id":"01m5mmqd944fxvw6ndwyxmd8qs","platform":"woocommerce","method":"webhook","status":"testing","external_shop_ref":null,"limitations":[],"last_event_at":"2026-10-23T15:05:32+00:00","last_verified_order_at":null,"connected_at":null,"disconnected_at":null,"last_error":null,"created_at":"2026-10-23T15:05:31+00:00","setup":{"format":"woocommerce-webhooks","webhook_url":"https:\/\/api.shopbonus.example\/api\/v1\/webhooks\/woocommerce\/<token>","steps":["In WordPress go to WooCommerce \u2192 Settings \u2192 Advanced \u2192 Webhooks \u2192 Add webhook.","Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.","Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.","Save, then place a real paid test order."]}}}
```

### Webhook: forged signature

`POST /api/v1/webhooks/woocommerce/<token> (provider headers: X-WC-Webhook-Signature: Zm9yZ2VkCg== X-WC-Webhook-Topic: order.created X-WC-Webhook-Delivery-ID: 9a1)`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a1694b-b6ba-71d1-87a3-9d4babe84347
Content-Type: application/json

{"error":{"status":401,"code":"webhook_rejected","message":"The webhook could not be verified.","request_id":"01a1694b-b6ba-71d1-87a3-9d4babe84347","details":{"reason":"invalid_signature"}}}
```

### Webhook: signed WooCommerce order

`POST /api/v1/webhooks/woocommerce/<token> (provider headers: X-WC-Webhook-Signature: <base64 signature> X-WC-Webhook-Topic: order.created X-WC-Webhook-Delivery-ID: 9a1)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a1694b-b6d9-73c6-bf3b-b4c92f425e30

{"data":{"received":true,"duplicate":false,"event_id":"01m5mmqdpyrwt78trs3gc64x0z"}}
```

### Webhook: same delivery again

`POST /api/v1/webhooks/woocommerce/<token> (provider headers: X-WC-Webhook-Signature: <base64 signature> X-WC-Webhook-Topic: order.created X-WC-Webhook-Delivery-ID: 9a1)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b6fc-73e0-b65b-198cb1476ed6

{"data":{"received":true,"duplicate":true}}
```

### Connection after the verified order (connected)

`GET /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections/01m5mmqd944fxvw6ndwyxmd8qs`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b71c-70c5-8862-3ecad83aa5df

{"data":{"id":"01m5mmqd944fxvw6ndwyxmd8qs","platform":"woocommerce","method":"webhook","status":"connected","external_shop_ref":null,"limitations":[],"last_event_at":"2026-10-23T15:05:32+00:00","last_verified_order_at":"2026-10-23T15:05:32+00:00","connected_at":"2026-10-23T15:05:32+00:00","disconnected_at":null,"last_error":{"code":"webhook_invalid_signature","at":"2026-10-23T15:05:32+00:00"},"created_at":"2026-10-23T15:05:31+00:00","setup":{"format":"woocommerce-webhooks","webhook_url":"https:\/\/api.shopbonus.example\/api\/v1\/webhooks\/woocommerce\/<token>","steps":["In WordPress go to WooCommerce \u2192 Settings \u2192 Advanced \u2192 Webhooks \u2192 Add webhook.","Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.","Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.","Save, then place a real paid test order."]}}}
```

### Verify checklist when connected

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections/01m5mmqd944fxvw6ndwyxmd8qs/verify`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b73a-7302-9a2e-b416b9afa7ac

{"data":{"status":"connected","checks":{"credentials_configured":true,"event_received":true,"order_received":true,"mandatory_fields_present":true,"phone_available":true,"webshop_ownership_verified":false},"missing_fields":[],"limitations":[],"next_action":"verify_ownership"}}
```

### Sanitised event history

`GET /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections/01m5mmqd944fxvw6ndwyxmd8qs/events`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b759-7083-9822-066bde3d89db

{"data":[{"id":"01m5mmqdpyrwt78trs3gc64x0z","topic":"order.created","status":"processed","delivery_count":2,"result":{"kind":"order","complete":true,"currency":"EUR","has_phone":true,"amount_minor":5995,"missing_fields":[],"payment_status":"paid"},"error_code":null,"received_at":"2026-10-23T15:05:32+00:00","processed_at":"2026-10-23T15:05:32+00:00"},{"id":"01m5mmqdkcfye2x1tad9r4fgk5","topic":null,"status":"processed","delivery_count":1,"result":{"kind":"ping"},"error_code":null,"received_at":"2026-10-23T15:05:32+00:00","processed_at":"2026-10-23T15:05:32+00:00"}]}
```

### Webhook: Shopify order with redacted customer data

`POST /api/v1/webhooks/shopify/<token> (provider headers: X-Shopify-Hmac-Sha256: <base64 signature> X-Shopify-Topic: orders/paid X-Shopify-Webhook-Id: b54557e4 X-Shopify-Shop-Domain: fietsen.myshopify.com)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a1694b-b783-70b5-9363-bc5fccefc693

{"data":{"received":true,"duplicate":false,"event_id":"01m5mmqdw90z1qbbefx4pedxjs"}}
```

### Shopify checklist (limited)

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections/01m5mmqdcww6d106jp3f92yez8/verify`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b7a9-72e6-9323-7891909cf529

{"data":{"status":"limited","checks":{"credentials_configured":true,"event_received":true,"order_received":true,"mandatory_fields_present":false,"phone_available":false,"webshop_ownership_verified":false},"missing_fields":["first_name","email"],"limitations":["additional_integration_required"],"next_action":"additional_integration_required"}}
```

### Webhook: Shopbonus format, replayed old timestamp

`POST /api/v1/webhooks/prestashop/<token> (provider headers: X-Shopbonus-Signature: t=1792767332,v1=<hex signature> X-Shopbonus-Event-Id: evt_01 X-Shopbonus-Topic: order.paid)`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a1694b-b7d9-703d-ad76-5701d98c5a04
Content-Type: application/json

{"error":{"status":401,"code":"webhook_rejected","message":"The webhook could not be verified.","request_id":"01a1694b-b7d9-703d-ad76-5701d98c5a04","details":{"reason":"timestamp_out_of_tolerance"}}}
```

### Webhook: Shopbonus format order

`POST /api/v1/webhooks/prestashop/<token> (provider headers: X-Shopbonus-Signature: t=1792767932,v1=<hex signature> X-Shopbonus-Event-Id: evt_01 X-Shopbonus-Topic: order.paid)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a1694b-b7f8-70c7-8e03-bdedd9cd1884

{"data":{"received":true,"duplicate":false,"event_id":"01m5mmqdzyrhrwwww5gza26p7s"}}
```

### Rotate the PrestaShop secret

`POST /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections/01m5mmqdeessjag26x40n5dfk2/credentials`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b81e-722e-a33c-7bf334f5d13a

{"data":{"id":"01m5mmqdeessjag26x40n5dfk2","platform":"prestashop","method":"private_module","status":"connected","external_shop_ref":null,"limitations":[],"last_event_at":"2026-10-23T15:05:32+00:00","last_verified_order_at":"2026-10-23T15:05:32+00:00","connected_at":"2026-10-23T15:05:32+00:00","disconnected_at":null,"last_error":{"code":"webhook_timestamp_out_of_tolerance","at":"2026-10-23T15:05:32+00:00"},"created_at":"2026-10-23T15:05:31+00:00","setup":{"format":"shopbonus-webhook-v1","webhook_url":"https:\/\/api.shopbonus.example\/api\/v1\/webhooks\/prestashop\/<token>","steps":["Install the private Shopbonus module for your shop (provided by Shopbonus support).","Enter the webhook URL and the signing secret shown once when the connection was created (or after rotating it).","Place a real test order. The connection becomes Connected after a verified order with all required fields."],"documentation":"docs\/api\/README.md#shopbonus-webhook-format-v1"},"secrets":{"signing_secret":"<secret shown once>"},"secrets_notice":"Shown only once. Store it in your shop settings now; it cannot be retrieved later."}}
```

### GET /workspaces/{workspace}/connections

`GET /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b83f-7307-8ad1-1208e9251518

{"data":[{"id":"01m5mmqdeessjag26x40n5dfk2","platform":"prestashop","method":"private_module","status":"connected","external_shop_ref":null,"limitations":[],"last_event_at":"2026-10-23T15:05:32+00:00","last_verified_order_at":"2026-10-23T15:05:32+00:00","connected_at":"2026-10-23T15:05:32+00:00","disconnected_at":null,"last_error":{"code":"webhook_timestamp_out_of_tolerance","at":"2026-10-23T15:05:32+00:00"},"created_at":"2026-10-23T15:05:31+00:00"},{"id":"01m5mmqdcww6d106jp3f92yez8","platform":"shopify","method":"webhook","status":"limited","external_shop_ref":"fietsen.myshopify.com","limitations":["additional_integration_required"],"last_event_at":"2026-10-23T15:05:32+00:00","last_verified_order_at":"2026-10-23T15:05:32+00:00","connected_at":null,"disconnected_at":null,"last_error":null,"created_at":"2026-10-23T15:05:31+00:00"},{"id":"01m5mmqd944fxvw6ndwyxmd8qs","platform":"woocommerce","method":"webhook","status":"connected","external_shop_ref":null,"limitations":[],"last_event_at":"2026-10-23T15:05:32+00:00","last_verified_order_at":"2026-10-23T15:05:32+00:00","connected_at":"2026-10-23T15:05:32+00:00","disconnected_at":null,"last_error":{"code":"webhook_invalid_signature","at":"2026-10-23T15:05:32+00:00"},"created_at":"2026-10-23T15:05:31+00:00"}]}
```

### Employee without integrations.view

`GET /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a1694b-b85c-72b8-b65d-c14aabb9109f
Content-Type: application/json

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a1694b-b85c-72b8-b65d-c14aabb9109f","details":{"required_permission":"integrations.view"}}}
```

### Disconnect Shopify

`DELETE /api/v1/workspaces/01m5mmqc8df2zzngh4vm4ab2gb/connections/01m5mmqdcww6d106jp3f92yez8`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1694b-b87c-710a-afcc-5a1298b7311f

{"data":{"id":"01m5mmqdcww6d106jp3f92yez8","platform":"shopify","method":"webhook","status":"disconnected","external_shop_ref":"fietsen.myshopify.com","limitations":["additional_integration_required"],"last_event_at":"2026-10-23T15:05:32+00:00","last_verified_order_at":"2026-10-23T15:05:32+00:00","connected_at":null,"disconnected_at":"2026-10-23T15:05:32+00:00","last_error":null,"created_at":"2026-10-23T15:05:31+00:00"}}
```

### Webhook to the disconnected connection

`POST /api/v1/webhooks/shopify/<token> (provider headers: X-Shopify-Hmac-Sha256: <base64 signature> X-Shopify-Topic: orders/paid X-Shopify-Webhook-Id: b54557e5 X-Shopify-Shop-Domain: fietsen.myshopify.com)`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a1694b-b8a3-7215-936d-10bce88e346d
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a1694b-b8a3-7215-936d-10bce88e346d"}}
```

### Shopify privacy compliance webhooks — `POST /api/v1/integrations/shopify/compliance` (B23k)

Captured 2026-10-29 with `php artisan serve` on an isolated SQLite file, `CACHE_STORE=array`, sync queue, `APP_DEBUG=false`. Bodies were signed independently with `openssl dgst -sha256 -hmac` using a synthetic client secret; HMACs and delivery IDs are redacted. Synthetic data only, no Shopify store. Rate-limit headers omitted (array cache). Full evidence: [B23_COMPLIANCE_HTTP_CAPTURE.json](../integrations/B23_COMPLIANCE_HTTP_CAPTURE.json).

App-level URI for the three mandatory topics in `shopify.app.toml` (`compliance_topics = ["customers/data_request", "customers/redact", "shop/redact"]`). Public, called only by Shopify. HMAC = base64 HMAC-SHA256(raw body, `SHOPIFY_CLIENT_SECRET`). A verified delivery is stored once per `X-Shopify-Webhook-Id`, encrypted, due in 30 days. **Nothing is erased or exported automatically**; operators list and resolve requests with `php artisan shopbonus:shopify-compliance` (D-099).

```http
POST /api/v1/integrations/shopify/compliance
Content-Type: application/json
X-Shopify-Topic: customers/redact
X-Shopify-Webhook-Id: <UUID>
X-Shopify-Hmac-Sha256: <HMAC>

{"shop_id":954889,"shop_domain":"fietsen.myshopify.com","customer":{"id":191167,"email":"jan@example.com","phone":"555-625-1199"},"orders_to_redact":[299938,280263]}
```

**Response 200 (stored; the data_request and shop/redact captures are identical)**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1869a-aab3-724f-bd7c-9b5dc487cd39

{"data":{"received":true,"duplicate":false}}
```

**Response 200 (same X-Shopify-Webhook-Id again)**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1869a-ab8e-7382-9dca-0500289c2ab7

{"data":{"received":true,"duplicate":true}}
```

**Response 401 (signed with a different secret)**

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a1869a-abbe-7081-b912-71306e093290

{"error":{"status":401,"code":"webhook_rejected","message":"The webhook could not be verified.","request_id":"01a1869a-abbe-7081-b912-71306e093290","details":{"reason":"signature_invalid"}}}
```

**Response 413 (body of 1,048,577 bytes)**

```http
HTTP/1.1 413 Content Too Large
Content-Type: application/json
X-Request-Id: 01a1869b-f942-7079-85cc-fd60386b9a53

{"error":{"status":413,"code":"payload_too_large","message":"The request body is too large.","request_id":"01a1869b-f942-7079-85cc-fd60386b9a53"}}
```

**Response 503 (`SHOPIFY_CLIENT_SECRET` empty)**

```http
HTTP/1.1 503 Service Unavailable
Content-Type: application/json
X-Request-Id: 01a1869a-e1d2-737a-a324-a4e1c07c1572

{"error":{"status":503,"code":"shopify_app_not_configured","message":"The Shopify app is not configured.","request_id":"01a1869a-e1d2-737a-a324-a4e1c07c1572"}}
```

### Shopware app registration — `GET /api/v1/integrations/shopware/app/registration`, `POST …/registration/confirm` (B23m)

Captured 2026-10-29 with `php artisan serve` on an isolated SQLite file, `APP_URL=https://api.shopbonus.example`, `SHOPWARE_APP_NAME=ShopbonusApp` and a synthetic app secret. A bash client computed every signature independently with `openssl`, and the returned `proof` was re-derived with openssl and matched. The shop secret and `secretKey` are redacted. No Shopware installation was involved. Evidence: [B23_SHOPWARE_APP_HTTP_CAPTURE.json](../integrations/B23_SHOPWARE_APP_HTTP_CAPTURE.json).

Manifest `<setup>`: `registrationUrl` = `https://<api>/api/v1/integrations/shopware/app/registration`, plus the app secret from the Shopware Account. The merchant creates the Shopbonus connection with `{"app_registration": true, "store_url": "https://<verified domain>", "sales_channel_id": "<32 hex>"}`; the order of installing the app and creating the connection doesn't matter.

```http
GET /api/v1/integrations/shopware/app/registration?shop-id=sHoP1a2B3c4D5e6F&shop-url=https%3A%2F%2Ffietsen-utrecht.nl&timestamp=<UNIX>
shopware-app-signature: <hex HMAC-SHA256("shop-id=…&shop-url=…&timestamp=…", app secret)>
sw-version: 6.7.0.0
```

**Response 200 (bare handshake object, as Shopware expects)**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186b6-4e32-707a-93de-cafa3f9ee7ac

{"proof":"ad71680dad376115bbc824da36669503d706b66743b33dc7ae066263732fd568","secret":"<64-character shop secret>","confirmation_url":"https://api.shopbonus.example/api/v1/integrations/shopware/app/registration/confirm"}
```

**Response 401 (signed with another app secret)**

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a186b6-4ed0-72e3-90f9-f48e269ea1b4

{"error":{"status":401,"code":"registration_rejected","message":"The registration request could not be verified.","request_id":"01a186b6-4ed0-72e3-90f9-f48e269ea1b4","details":{"reason":"signature_invalid"}}}
```

**Response 401 (already-confirmed shop re-registers without shopware-shop-signature)**

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a186b6-4faf-72a5-a02a-87879cbafcbe

{"error":{"status":401,"code":"registration_rejected","message":"The registration request could not be verified.","request_id":"01a186b6-4faf-72a5-a02a-87879cbafcbe","details":{"reason":"signature_invalid"}}}
```

**Response 422 (validly signed, but shop-url is http)**

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json
X-Request-Id: 01a186b7-7f97-700c-a7db-c2563036175c

{"error":{"status":422,"code":"store_url_invalid","message":"Use an HTTPS store URL without credentials, query, fragment or a custom port.","request_id":"01a186b7-7f97-700c-a7db-c2563036175c"}}
```

**Response 503 (SHOPWARE_APP_ENABLED=false)**

```http
HTTP/1.1 503 Service Unavailable
Content-Type: application/json
X-Request-Id: 01a186b6-5ced-7290-b16a-581931977739

{"error":{"status":503,"code":"shopware_app_not_configured","message":"The Shopware app is not configured.","request_id":"01a186b6-5ced-7290-b16a-581931977739"}}
```

```http
POST /api/v1/integrations/shopware/app/registration/confirm
Content-Type: application/json
shopware-shop-signature: <hex HMAC-SHA256(raw body, pending shop secret)>

{"apiKey":"SWIASYNTHETICAPIKEY01","secretKey":"<redacted>","timestamp":"<UNIX>","shopUrl":"https://fietsen-utrecht.nl","shopId":"sHoP1a2B3c4D5e6F"}
```

**Response 204**

```http
HTTP/1.1 204 No Content
X-Request-Id: 01a186b6-4f78-715a-bab2-bb9a0fa1b453
```

**Response 401 (body signed with a wrong shop secret)**

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a186b6-4f09-72f8-af45-e20fd074d0f3

{"error":{"status":401,"code":"registration_rejected","message":"The registration request could not be verified.","request_id":"01a186b6-4f09-72f8-af45-e20fd074d0f3","details":{"reason":"signature_invalid"}}}
```

### Shopware app webhooks — `POST /api/v1/integrations/shopware/app/webhook` (B23n)

Captured 2026-10-29 with `php artisan serve` on an isolated SQLite file and the database queue (jobs queued, no worker, so no store reads). A verified webshop with an app-registration connection was seeded and the handshake ran over HTTP first. Signatures were computed independently with openssl. Evidence: [B23_SHOPWARE_APP_WEBHOOK_HTTP_CAPTURE.json](../integrations/B23_SHOPWARE_APP_WEBHOOK_HTTP_CAPTURE.json).

Manifest `<webhooks>` should point `order.written`, `app.deleted`, `app.activated` and `app.deactivated` at this URL. Order hints only queue authenticated Admin API reads.

```http
POST /api/v1/integrations/shopware/app/webhook
Content-Type: application/json
shopware-shop-signature: <hex HMAC-SHA256(raw body, shop secret)>

{"data":{"payload":[{"entity":"order","operation":"insert","primaryKey":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},{"entity":"order","operation":"update","primaryKey":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}],"event":"order.written"},"source":{"url":"https://fietsen-utrecht.nl","appVersion":"1.0.0","shopId":"sHoP1a2B3c4D5e6F","eventId":"0d1f6a4e-0000-4000-8000-000000000001"},"timestamp":"<UNIX>"}
```

**Response 200 (two lookups queued)**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186c2-1888-7051-9d51-0d2936dbe6ce

{"data":{"received":true,"lookups_queued":2}}
```

**Response 200 (same eventId again: nothing new)**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186c2-18ca-7208-aef2-b3d24d9af75a

{"data":{"received":true,"lookups_queued":0}}
```

**Response 200 (product.written: ignored)**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186c2-18ff-7337-bcae-e6dd9096784a

{"data":{"received":true,"ignored":true}}
```

**Response 401 (wrong shop secret)**

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a186c2-1929-70a3-b7cd-1cf3ce584e80

{"error":{"status":401,"code":"webhook_rejected","message":"The webhook could not be verified.","request_id":"01a186c2-1929-70a3-b7cd-1cf3ce584e80","details":{"reason":"signature_invalid"}}}
```

**Response 200 (app.deleted, keepUserData false: binding and credentials revoked)**

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186c2-1960-72db-baae-190f01e8c1c2

{"data":{"received":true,"revoked":true}}
```

---

## Orders: the live Order Sheet (B05, Trello #1)

Captured 2026-10-23 against local MySQL + Redis. Orders were created by **signed WooCommerce webhooks sent with curl/openssl** to the real server (realistic payloads; no real store), so the whole pipeline ran for real: webhook → verification → ingestion → API. Sequence sent:

1. W-4711 pending, then paid (sent twice: the duplicate is ignored).
2. W-4712 paid without a phone (valid, not callable).
3. W-4713 paid without a first name (incomplete).
4. W-4714: the refund (newer) arrived **before** an older "processing/paid" event. The older event was ignored as stale.

Retry: a signed event with an invalid JSON body was stored as `failed` and then retried (202; it failed again because the stored payload is still invalid). A processed event can't be retried (409).

### GET /workspaces/{workspace}/orders

`GET /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/orders?per_page=10`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16955-530c-7269-a438-664da4573cd5

{"data":[{"id":"01m5mnamp49vamgcy42sq7wdhq","reference":"W-4714","external_order_id":"4714","platform":"woocommerce","customer":{"first_name":"=Dirk","email":"dirk@example.nl","phone":"+31600000000"},"amount_minor":8000,"refunded_minor":8000,"currency":"EUR","payment_status":"refunded","provider_status":"refunded","ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T12:00:00+00:00","cancelled_at":null,"refunded_at":"2026-10-24T09:00:00+00:00","updated_at":"2026-10-23T15:16:01+00:00"},{"id":"01m5mnammgce7s4p3m5mz9c7vp","reference":"W-4713","external_order_id":"4713","platform":"woocommerce","customer":{"first_name":null,"email":"cees@example.nl","phone":"+31687654321"},"amount_minor":1200,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":"processing","ingestion_status":"incomplete","missing_fields":["first_name"],"callable":true,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T11:30:00+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:16:01+00:00"},{"id":"01m5mnamjxe87dfwdk5znm0t2w","reference":"W-4712","external_order_id":"4712","platform":"woocommerce","customer":{"first_name":"Bram","email":"bram@example.nl","phone":null},"amount_minor":2450,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":"processing","ingestion_status":"complete","missing_fields":[],"callable":false,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T11:00:00+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:16:01+00:00"},{"id":"01m5mnamdmmh96jz8x9j1g5qtf","reference":"W-4711","external_order_id":"4711","platform":"woocommerce","customer":{"first_name":"Anna","email":"anna@example.nl","phone":"+31612345678"},"amount_minor":5995,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":"processing","ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T10:05:00+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:16:01+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=10&page=1","last":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=10&page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=10&page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders","per_page":10,"to":4,"total":4,"today":{"timezone":"Europe\/Amsterdam","orders":4,"paid_orders":3,"incomplete_orders":1,"paid_revenue_minor":{"EUR":9645}}}}
```

### Paid orders, largest first

`GET /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/orders?payment_status=paid&sort=-amount_minor`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16955-5338-71b3-bf56-34dd92efac09

{"data":[{"id":"01m5mnamdmmh96jz8x9j1g5qtf","reference":"W-4711","external_order_id":"4711","platform":"woocommerce","customer":{"first_name":"Anna","email":"anna@example.nl","phone":"+31612345678"},"amount_minor":5995,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":"processing","ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T10:05:00+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:16:01+00:00"},{"id":"01m5mnamjxe87dfwdk5znm0t2w","reference":"W-4712","external_order_id":"4712","platform":"woocommerce","customer":{"first_name":"Bram","email":"bram@example.nl","phone":null},"amount_minor":2450,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":"processing","ingestion_status":"complete","missing_fields":[],"callable":false,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T11:00:00+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:16:01+00:00"},{"id":"01m5mnammgce7s4p3m5mz9c7vp","reference":"W-4713","external_order_id":"4713","platform":"woocommerce","customer":{"first_name":null,"email":"cees@example.nl","phone":"+31687654321"},"amount_minor":1200,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":"processing","ingestion_status":"incomplete","missing_fields":["first_name"],"callable":true,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T11:30:00+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:16:01+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?payment_status=paid&sort=-amount_minor&page=1","last":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?payment_status=paid&sort=-amount_minor&page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?payment_status=paid&sort=-amount_minor&page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders","per_page":25,"to":3,"total":3,"today":{"timezone":"Europe\/Amsterdam","orders":4,"paid_orders":3,"incomplete_orders":1,"paid_revenue_minor":{"EUR":9645}}}}
```

### Exact email search (case-insensitive)

`GET /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/orders?search=BRAM@example.nl`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16955-535e-708a-a0dd-de25da094a1f

{"data":[{"id":"01m5mnamjxe87dfwdk5znm0t2w","reference":"W-4712","external_order_id":"4712","platform":"woocommerce","customer":{"first_name":"Bram","email":"bram@example.nl","phone":null},"amount_minor":2450,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":"processing","ingestion_status":"complete","missing_fields":[],"callable":false,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T11:00:00+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:16:01+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?search=BRAM%40example.nl&page=1","last":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?search=BRAM%40example.nl&page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?search=BRAM%40example.nl&page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders","per_page":25,"to":1,"total":1,"today":{"timezone":"Europe\/Amsterdam","orders":4,"paid_orders":3,"incomplete_orders":1,"paid_revenue_minor":{"EUR":9645}}}}
```

### Incomplete orders

`GET /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/orders?ingestion_status=incomplete`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16955-5382-7203-83c1-c3d19b7496d8

{"data":[{"id":"01m5mnammgce7s4p3m5mz9c7vp","reference":"W-4713","external_order_id":"4713","platform":"woocommerce","customer":{"first_name":null,"email":"cees@example.nl","phone":"+31687654321"},"amount_minor":1200,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":"processing","ingestion_status":"incomplete","missing_fields":["first_name"],"callable":true,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T11:30:00+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:16:01+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?ingestion_status=incomplete&page=1","last":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?ingestion_status=incomplete&page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?ingestion_status=incomplete&page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders","per_page":25,"to":1,"total":1,"today":{"timezone":"Europe\/Amsterdam","orders":4,"paid_orders":3,"incomplete_orders":1,"paid_revenue_minor":{"EUR":9645}}}}
```

### Order W-4711 history: pending → paid

`GET /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/orders/01m5mnamdmmh96jz8x9j1g5qtf`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16955-53b0-7227-80a9-4fe57d78f8a3

{"data":{"id":"01m5mnamdmmh96jz8x9j1g5qtf","reference":"W-4711","external_order_id":"4711","platform":"woocommerce","customer":{"first_name":"Anna","email":"anna@example.nl","phone":"+31612345678"},"amount_minor":5995,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":"processing","ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T10:05:00+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:16:01+00:00"},"history":[{"type":"created","from_payment_status":null,"to_payment_status":"pending","summary":{"topic":"order.updated","complete":true},"provider_updated_at":null,"recorded_at":"2026-10-23T15:16:01+00:00"},{"type":"payment_changed","from_payment_status":"pending","to_payment_status":"paid","summary":{"topic":"order.updated","complete":true},"provider_updated_at":"2026-10-23T10:05:00+00:00","recorded_at":"2026-10-23T15:16:01+00:00"}]}
```

### Order W-4714: older event ignored

`GET /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/orders/01m5mnamp49vamgcy42sq7wdhq`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16955-53cf-715e-81fc-2b5835277eba

{"data":{"id":"01m5mnamp49vamgcy42sq7wdhq","reference":"W-4714","external_order_id":"4714","platform":"woocommerce","customer":{"first_name":"=Dirk","email":"dirk@example.nl","phone":"+31600000000"},"amount_minor":8000,"refunded_minor":8000,"currency":"EUR","payment_status":"refunded","provider_status":"refunded","ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T12:00:00+00:00","cancelled_at":null,"refunded_at":"2026-10-24T09:00:00+00:00","updated_at":"2026-10-23T15:16:01+00:00"},"history":[{"type":"created","from_payment_status":null,"to_payment_status":"refunded","summary":{"topic":"order.updated","complete":true},"provider_updated_at":null,"recorded_at":"2026-10-23T15:16:01+00:00"},{"type":"ignored_stale","from_payment_status":"refunded","to_payment_status":"paid","summary":{"topic":"order.updated","reason":"older_than_stored"},"provider_updated_at":"2026-10-23T12:00:00+00:00","recorded_at":"2026-10-23T15:16:01+00:00"}]}
```

### Sorting by a non-sortable column

`GET /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/orders?sort=customer_email`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16955-53ed-735e-a15f-5b48b43658bf
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16955-53ed-735e-a15f-5b48b43658bf","fields":{"sort":["The selected sort is invalid."]}}}
```

### Employee with orders.view

`GET /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/orders?per_page=1`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16955-540e-715d-a22a-423c0037fb14

{"data":[{"id":"01m5mnamp49vamgcy42sq7wdhq","reference":"W-4714","external_order_id":"4714","platform":"woocommerce","customer":{"first_name":"=Dirk","email":"dirk@example.nl","phone":"+31600000000"},"amount_minor":8000,"refunded_minor":8000,"currency":"EUR","payment_status":"refunded","provider_status":"refunded","ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"not_evaluated","contact_status":"not_evaluated","placed_at":"2026-10-23T15:16:01+00:00","paid_at":"2026-10-23T12:00:00+00:00","cancelled_at":null,"refunded_at":"2026-10-24T09:00:00+00:00","updated_at":"2026-10-23T15:16:01+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=1&page=1","last":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=1&page=4","prev":null,"next":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=1&page=2"},"meta":{"current_page":1,"from":1,"last_page":4,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=1&page=1","label":"1","page":1,"active":true},{"url":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=1&page=2","label":"2","page":2,"active":false},{"url":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=1&page=3","label":"3","page":3,"active":false},{"url":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=1&page=4","label":"4","page":4,"active":false},{"url":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders?per_page=1&page=2","label":"Next &raquo;","page":2,"active":false}],"path":"http:\/\/127.0.0.1:8781\/api\/v1\/workspaces\/01m5mnak2kkmds3dmby9nt7tya\/orders","per_page":1,"to":1,"total":4,"today":{"timezone":"Europe\/Amsterdam","orders":4,"paid_orders":3,"incomplete_orders":1,"paid_revenue_minor":{"EUR":9645}}}}
```

### Employee export without orders.export

`GET /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/orders/export`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16955-5432-739a-8298-b9a73e45fb2c
Content-Type: application/json

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a16955-5432-739a-8298-b9a73e45fb2c","details":{"required_permission":"orders.export"}}}
```

### CSV export (owner)

`GET /api/v1/workspaces/<webshop id>/orders/export`

```http
HTTP/1.1 200 OK
Content-Type: text/csv; charset=UTF-8
Cache-Control: no-store, private
Content-Disposition: attachment; filename=orders-fietsenwinkel-utrecht-20261023-151602.csv
X-Request-Id: 01a16955-5451-7222-b348-2e40fb7d5a30
placed_at,reference,platform,first_name,email,phone,amount,currency,payment_status,ingestion_status,eligibility_status,contact_status
2026-10-23T15:16:01+00:00,W-4711,woocommerce,Anna,anna@example.nl,'+31612345678,59.95,EUR,paid,complete,not_evaluated,not_evaluated
2026-10-23T15:16:01+00:00,W-4712,woocommerce,Bram,bram@example.nl,,24.50,EUR,paid,complete,not_evaluated,not_evaluated
2026-10-23T15:16:01+00:00,W-4713,woocommerce,,cees@example.nl,'+31687654321,12.00,EUR,paid,incomplete,not_evaluated,not_evaluated
2026-10-23T15:16:01+00:00,W-4714,woocommerce,'=Dirk,dirk@example.nl,'+31600000000,80.00,EUR,refunded,complete,not_evaluated,not_evaluated
```

Note the apostrophe before `=Dirk` and before phone numbers starting with `+`: the formula-injection guard.

### Another webshop requesting the order

`GET /api/v1/workspaces/01m5mnak339tkhype3pt8hjnkc/orders/01m5mnamdmmh96jz8x9j1g5qtf`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16955-5473-7251-a737-846397f545b2
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a16955-5473-7251-a737-846397f545b2"}}
```

### Retrying a failed event

`POST /api/v1/workspaces/<webshop id>/connections/<id>/events/<event id>/retry`

```http
HTTP/1.1 202 Accepted

{"data":{"id":"01m5mndypk7pjesy520sztrypa","status":"failed"}}
```

### Retrying a processed event

`POST /api/v1/workspaces/01m5mnak2kkmds3dmby9nt7tya/connections/01m5mnam5rfbkbk8f494krmmw4/events/01m5mnamqe5rqyz7w64xqx8y14/retry`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16955-54bc-709c-ade8-6daa811743f2
Content-Type: application/json

{"error":{"status":409,"code":"event_not_retryable","message":"Only failed events with a retained payload can be retried.","request_id":"01a16955-54bc-709c-ade8-6daa811743f2"}}
```

---

## Offers (B06a, Trello #7)

Captured 2026-10-23 against local MySQL + Redis. *Woon & Co* is an approved webshop with an accepted agreement.

- **Terms are versioned.** Edits to a draft change version 1. After activation, every change creates a new version, and vouchers keep the version they were issued under.
- **Status machine:** draft → active ⇄ paused → ended. `/activate` also resumes a paused offer. Ended is final.
- **Cost preview** (merchant rewards only): fee = 30% of the reward, half-up to the cent. €10 reward → €3 fee → €13 commitment per voucher, times `allocation_limit` for the total. Percentage offers are previewed on `max_discount_minor` (`basis: maximum_discount`), pending OPEN-PERCENT-REWARD-FEE-BASIS.

### Percentage above 100%

`POST /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers {"acquisition":"merchant_reward","name":"Woon & Co: 10% korting","discount_type":"percentage","discount_value":15000}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a1695b-71d8-73ef-a160-dbb3bbe1d78e
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a1695b-71d8-73ef-a160-dbb3bbe1d78e","fields":{"discount_value":["A percentage discount is in basis points and cannot exceed 10000 (100%)."]}}}
```

### Create a 10% from €100 draft (no maximum yet)

`POST /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers {"acquisition":"merchant_reward","name":"Woon & Co: 10% korting","public_description":"Op je volgende aankoop vanaf €100","discount_type":"percentage","discount_value":1000,"min_order_minor":10000,"redemption_validity_days":90,"categories":["wonen"],"allocation_limit":500}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a1695b-7269-7293-876d-c148d72b0675

{"data":{"id":"01m5mnpwkmz9v9bkhspfsznzns","acquisition":"merchant_reward","status":"draft","terms":{"version":1,"locked":false,"summary":"10% from \u20ac100.00","name":"Woon & Co: 10% korting","public_description":"Op je volgende aankoop vanaf \u20ac100","discount_type":"percentage","discount_value":1000,"currency":null,"min_order_minor":10000,"max_discount_minor":null,"price_minor":null,"starts_at":null,"ends_at":null,"claim_validity_days":90,"redemption_validity_days":90,"categories":["wonen"],"products":[],"combinable":null,"code_mode":null,"allocation_limit":500},"missing_for_activation":["max_discount_minor"],"cost_preview":{"applies":true,"available":false,"reason":"maximum_discount_required"},"activated_at":null,"paused_at":null,"ended_at":null,"created_at":"2026-10-23T15:22:43+00:00"}}
```

### Activating without a maximum discount

`POST /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers/01m5mnpwkmz9v9bkhspfsznzns/activate`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a1695b-729e-732a-b52d-3ed7afa26f9c
Content-Type: application/json

{"error":{"status":422,"code":"offer_incomplete","message":"The offer is incomplete.","request_id":"01a1695b-729e-732a-b52d-3ed7afa26f9c","details":{"missing_fields":["max_discount_minor"]}}}
```

### Draft edit: maximum €10 (preview appears)

`PATCH /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers/01m5mnpwkmz9v9bkhspfsznzns {"max_discount_minor":1000}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1695b-72c4-71b3-9d06-33117cc493eb

{"data":{"id":"01m5mnpwkmz9v9bkhspfsznzns","acquisition":"merchant_reward","status":"draft","terms":{"version":1,"locked":false,"summary":"10% from \u20ac100.00, maximum \u20ac10.00","name":"Woon & Co: 10% korting","public_description":"Op je volgende aankoop vanaf \u20ac100","discount_type":"percentage","discount_value":1000,"currency":"EUR","min_order_minor":10000,"max_discount_minor":1000,"price_minor":null,"starts_at":null,"ends_at":null,"claim_validity_days":90,"redemption_validity_days":90,"categories":["wonen"],"products":[],"combinable":false,"code_mode":"unique","allocation_limit":500},"missing_for_activation":[],"cost_preview":{"applies":true,"available":true,"basis":"maximum_discount","currency":"EUR","fee_rate_bps":3000,"per_voucher":{"reward_minor":1000,"fee_minor":300,"merchant_commitment_minor":1300},"allocation":{"vouchers":500,"merchant_commitment_minor":650000},"note":"Preview only. Funding and invoicing are handled by the merchant wallet; nothing is charged here."},"activated_at":null,"paused_at":null,"ended_at":null,"created_at":"2026-10-23T15:22:43+00:00"}}
```

### Activate (version 1 locked)

`POST /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers/01m5mnpwkmz9v9bkhspfsznzns/activate`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1695b-72ec-70a7-94f0-dd1e948c2591

{"data":{"id":"01m5mnpwkmz9v9bkhspfsznzns","acquisition":"merchant_reward","status":"active","terms":{"version":1,"locked":true,"summary":"10% from \u20ac100.00, maximum \u20ac10.00","name":"Woon & Co: 10% korting","public_description":"Op je volgende aankoop vanaf \u20ac100","discount_type":"percentage","discount_value":1000,"currency":"EUR","min_order_minor":10000,"max_discount_minor":1000,"price_minor":null,"starts_at":null,"ends_at":null,"claim_validity_days":90,"redemption_validity_days":90,"categories":["wonen"],"products":[],"combinable":false,"code_mode":"unique","allocation_limit":500},"missing_for_activation":[],"cost_preview":{"applies":true,"available":true,"basis":"maximum_discount","currency":"EUR","fee_rate_bps":3000,"per_voucher":{"reward_minor":1000,"fee_minor":300,"merchant_commitment_minor":1300},"allocation":{"vouchers":500,"merchant_commitment_minor":650000},"note":"Preview only. Funding and invoicing are handled by the merchant wallet; nothing is charged here."},"activated_at":"2026-10-23T15:22:43+00:00","paused_at":null,"ended_at":null,"created_at":"2026-10-23T15:22:43+00:00"}}
```

### Edit an active offer: version 2

`PATCH /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers/01m5mnpwkmz9v9bkhspfsznzns {"discount_value":1500}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1695b-7314-7097-84cd-93743337ca57

{"data":{"id":"01m5mnpwkmz9v9bkhspfsznzns","acquisition":"merchant_reward","status":"active","terms":{"version":2,"locked":true,"summary":"15% from \u20ac100.00, maximum \u20ac10.00","name":"Woon & Co: 10% korting","public_description":"Op je volgende aankoop vanaf \u20ac100","discount_type":"percentage","discount_value":1500,"currency":"EUR","min_order_minor":10000,"max_discount_minor":1000,"price_minor":null,"starts_at":null,"ends_at":null,"claim_validity_days":90,"redemption_validity_days":90,"categories":["wonen"],"products":[],"combinable":false,"code_mode":"unique","allocation_limit":500},"missing_for_activation":[],"cost_preview":{"applies":true,"available":true,"basis":"maximum_discount","currency":"EUR","fee_rate_bps":3000,"per_voucher":{"reward_minor":1000,"fee_minor":300,"merchant_commitment_minor":1300},"allocation":{"vouchers":500,"merchant_commitment_minor":650000},"note":"Preview only. Funding and invoicing are handled by the merchant wallet; nothing is charged here."},"activated_at":"2026-10-23T15:22:43+00:00","paused_at":null,"ended_at":null,"created_at":"2026-10-23T15:22:43+00:00"}}
```

### Show with versions

`GET /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers/01m5mnpwkmz9v9bkhspfsznzns`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1695b-733c-73c2-8dc5-a5d70464b532

{"data":{"id":"01m5mnpwkmz9v9bkhspfsznzns","acquisition":"merchant_reward","status":"active","terms":{"version":2,"locked":true,"summary":"15% from \u20ac100.00, maximum \u20ac10.00","name":"Woon & Co: 10% korting","public_description":"Op je volgende aankoop vanaf \u20ac100","discount_type":"percentage","discount_value":1500,"currency":"EUR","min_order_minor":10000,"max_discount_minor":1000,"price_minor":null,"starts_at":null,"ends_at":null,"claim_validity_days":90,"redemption_validity_days":90,"categories":["wonen"],"products":[],"combinable":false,"code_mode":"unique","allocation_limit":500},"missing_for_activation":[],"cost_preview":{"applies":true,"available":true,"basis":"maximum_discount","currency":"EUR","fee_rate_bps":3000,"per_voucher":{"reward_minor":1000,"fee_minor":300,"merchant_commitment_minor":1300},"allocation":{"vouchers":500,"merchant_commitment_minor":650000},"note":"Preview only. Funding and invoicing are handled by the merchant wallet; nothing is charged here."},"activated_at":"2026-10-23T15:22:43+00:00","paused_at":null,"ended_at":null,"created_at":"2026-10-23T15:22:43+00:00"},"versions":[{"version":1,"locked":true,"created_at":"2026-10-23T15:22:43+00:00"},{"version":2,"locked":true,"created_at":"2026-10-23T15:22:43+00:00"}]}
```

### Pause

`POST /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers/01m5mnpwkmz9v9bkhspfsznzns/pause`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1695b-735b-72fd-be5e-1bdf57529da1

{"data":{"id":"01m5mnpwkmz9v9bkhspfsznzns","acquisition":"merchant_reward","status":"paused","terms":{"version":2,"locked":true,"summary":"15% from \u20ac100.00, maximum \u20ac10.00","name":"Woon & Co: 10% korting","public_description":"Op je volgende aankoop vanaf \u20ac100","discount_type":"percentage","discount_value":1500,"currency":"EUR","min_order_minor":10000,"max_discount_minor":1000,"price_minor":null,"starts_at":null,"ends_at":null,"claim_validity_days":90,"redemption_validity_days":90,"categories":["wonen"],"products":[],"combinable":false,"code_mode":"unique","allocation_limit":500},"missing_for_activation":[],"cost_preview":{"applies":true,"available":true,"basis":"maximum_discount","currency":"EUR","fee_rate_bps":3000,"per_voucher":{"reward_minor":1000,"fee_minor":300,"merchant_commitment_minor":1300},"allocation":{"vouchers":500,"merchant_commitment_minor":650000},"note":"Preview only. Funding and invoicing are handled by the merchant wallet; nothing is charged here."},"activated_at":"2026-10-23T15:22:43+00:00","paused_at":"2026-10-23T15:22:43+00:00","ended_at":null,"created_at":"2026-10-23T15:22:43+00:00"}}
```

### Pause again

`POST /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers/01m5mnpwkmz9v9bkhspfsznzns/pause`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a1695b-737f-7307-b934-144963fbe078
Content-Type: application/json

{"error":{"status":409,"code":"invalid_status_transition","message":"An offer cannot go from paused to paused.","request_id":"01a1695b-737f-7307-b934-144963fbe078"}}
```

### Wallet-purchase offer (no merchant fee preview)

`POST /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers {"acquisition":"wallet_purchase","name":"Cadeaubon €20","discount_type":"fixed","discount_value":2000,"price_minor":2000,"redemption_validity_days":365}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a1695b-739e-7113-b523-65c096141bff

{"data":{"id":"01m5mnpwx7zxe9e9crkg1e0rvd","acquisition":"wallet_purchase","status":"draft","terms":{"version":1,"locked":false,"summary":"\u20ac20.00","name":"Cadeaubon \u20ac20","public_description":null,"discount_type":"fixed","discount_value":2000,"currency":null,"min_order_minor":null,"max_discount_minor":null,"price_minor":2000,"starts_at":null,"ends_at":null,"claim_validity_days":null,"redemption_validity_days":365,"categories":[],"products":[],"combinable":null,"code_mode":null,"allocation_limit":null},"missing_for_activation":[],"cost_preview":{"applies":false,"reason":"consumer_funded"},"activated_at":null,"paused_at":null,"ended_at":null,"created_at":"2026-10-23T15:22:43+00:00"}}
```

### List offers

`GET /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1695b-73c3-73d5-8d70-f764b180f68c

{"data":[{"id":"01m5mnpwx7zxe9e9crkg1e0rvd","acquisition":"wallet_purchase","status":"draft","terms":{"version":1,"locked":false,"summary":"\u20ac20.00","name":"Cadeaubon \u20ac20","public_description":null,"discount_type":"fixed","discount_value":2000,"currency":"EUR","min_order_minor":null,"max_discount_minor":null,"price_minor":2000,"starts_at":null,"ends_at":null,"claim_validity_days":null,"redemption_validity_days":365,"categories":[],"products":[],"combinable":false,"code_mode":"unique","allocation_limit":null},"missing_for_activation":[],"cost_preview":{"applies":false,"reason":"consumer_funded"},"activated_at":null,"paused_at":null,"ended_at":null,"created_at":"2026-10-23T15:22:43+00:00"},{"id":"01m5mnpwkmz9v9bkhspfsznzns","acquisition":"merchant_reward","status":"paused","terms":{"version":2,"locked":true,"summary":"15% from \u20ac100.00, maximum \u20ac10.00","name":"Woon & Co: 10% korting","public_description":"Op je volgende aankoop vanaf \u20ac100","discount_type":"percentage","discount_value":1500,"currency":"EUR","min_order_minor":10000,"max_discount_minor":1000,"price_minor":null,"starts_at":null,"ends_at":null,"claim_validity_days":90,"redemption_validity_days":90,"categories":["wonen"],"products":[],"combinable":false,"code_mode":"unique","allocation_limit":500},"missing_for_activation":[],"cost_preview":{"applies":true,"available":true,"basis":"maximum_discount","currency":"EUR","fee_rate_bps":3000,"per_voucher":{"reward_minor":1000,"fee_minor":300,"merchant_commitment_minor":1300},"allocation":{"vouchers":500,"merchant_commitment_minor":650000},"note":"Preview only. Funding and invoicing are handled by the merchant wallet; nothing is charged here."},"activated_at":"2026-10-23T15:22:43+00:00","paused_at":"2026-10-23T15:22:43+00:00","ended_at":null,"created_at":"2026-10-23T15:22:43+00:00"}]}
```

### End

`POST /api/v1/workspaces/01m5mnpvkabhh0p48hb4q2h0ct/offers/01m5mnpwkmz9v9bkhspfsznzns/end`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1695b-73e7-71d2-a297-3dc4dd1b11ef

{"data":{"id":"01m5mnpwkmz9v9bkhspfsznzns","acquisition":"merchant_reward","status":"ended","terms":{"version":2,"locked":true,"summary":"15% from \u20ac100.00, maximum \u20ac10.00","name":"Woon & Co: 10% korting","public_description":"Op je volgende aankoop vanaf \u20ac100","discount_type":"percentage","discount_value":1500,"currency":"EUR","min_order_minor":10000,"max_discount_minor":1000,"price_minor":null,"starts_at":null,"ends_at":null,"claim_validity_days":90,"redemption_validity_days":90,"categories":["wonen"],"products":[],"combinable":false,"code_mode":"unique","allocation_limit":500},"missing_for_activation":[],"cost_preview":{"applies":true,"available":true,"basis":"maximum_discount","currency":"EUR","fee_rate_bps":3000,"per_voucher":{"reward_minor":1000,"fee_minor":300,"merchant_commitment_minor":1300},"allocation":{"vouchers":500,"merchant_commitment_minor":650000},"note":"Preview only. Funding and invoicing are handled by the merchant wallet; nothing is charged here."},"activated_at":"2026-10-23T15:22:43+00:00","paused_at":null,"ended_at":"2026-10-23T15:22:43+00:00","created_at":"2026-10-23T15:22:43+00:00"}}
```

---

## Code inventory (B06b, Trello #7 §5–8, #23)

Captured 2026-10-23 against local MySQL + Redis. *Woon & Co* offer "€10 korting vanaf €100". Two codes were then taken through the inventory service: one reserved and issued to a consumer (merchant reward for order 4711), one reserved for an Answer24 handoff. The consumer claim and checkout APIs that drive this come in B07, B08 and B11. All codes shown are fake samples.

- **One inventory, one voucher model.** Origins: merchant_reward, answer24_claim, wallet_purchase, promotional.
- **Code states:** available → reserved → issued → redeemed, plus expired, invalid and cancelled. Counters cover every state.
- **Issued is not redeemed.** A voucher becomes redeemed only when its code appears in a **verified paid order** of the same webshop (WooCommerce `coupon_lines`, Shopify `discount_codes`, format v1 `order.coupon_codes`), exactly once.
- **Concurrency.** Reservation is atomic. It is proven with real forked processes on MySQL (`tests/Feature/Vouchers/ConcurrentReservationTest.php`), and mutation-tested: removing the guards makes it fail.

Large imports (more than 2,000 codes) are committed by a queued job. Captured with 2,100 codes:

```http
POST /code-imports/<batch id>/commit → HTTP/1.1 202 Accepted

{"data":{"id":"01m5mppysn5brk8ytj9nj4jmwk","status":"committing","source":"paste","totals":{"valid":2100,"invalid":0,"submitted":2100,"duplicate_existing":0,"duplicate_in_upload":0},"rejections":[],"expires_at":null,"committed_at":null}}

GET /code-imports/<batch id> (after the job ran) → HTTP/1.1 200 OK

{"data":{"id":"01m5mppysn5brk8ytj9nj4jmwk","status":"committed","source":"paste","totals":{"valid":2100,"invalid":0,"imported":2100,"submitted":2100,"duplicate_existing":0,"duplicate_at_commit":0,"duplicate_in_upload":0},"rejections":[],"expires_at":null,"committed_at":"2026-10-23T15:40:14+00:00"}}
```

### Paste preview: one duplicate, one invalid

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/offers/01m5mpjewf73zwcf12me43kaw0/code-imports {"codes":"WOON-7F3K-0001\nWOON-7F3K-0002\n  WOON-7F3K-0003  \nwoon-7f3k-0001\nbad code!\n\nWOON-7F3K-0004\nWOON-7F3K-0005"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16969-3f8b-70aa-8f33-0fcfc366f002

{"data":{"id":"01m5mpjg1tt6q0vjbaw3an5j6j","status":"preview","source":"paste","totals":{"submitted":7,"valid":5,"invalid":1,"duplicate_in_upload":1,"duplicate_existing":0},"rejections":[{"line":4,"code":"********0001","reason":"duplicate_in_upload"},{"line":5,"code":"*****ode!","reason":"invalid_format"}],"expires_at":"2026-10-23T16:37:47+00:00","committed_at":null}}
```

### Commit

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/code-imports/01m5mpjg1tt6q0vjbaw3an5j6j/commit`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16969-4067-7152-b6aa-0a75dae451e0

{"data":{"id":"01m5mpjg1tt6q0vjbaw3an5j6j","status":"committed","source":"paste","totals":{"valid":5,"invalid":1,"submitted":7,"duplicate_existing":0,"duplicate_in_upload":1,"imported":5,"duplicate_at_commit":0},"rejections":[{"code":"********0001","line":4,"reason":"duplicate_in_upload"},{"code":"*****ode!","line":5,"reason":"invalid_format"}],"expires_at":null,"committed_at":"2026-10-23T15:37:47+00:00"}}
```

### Commit twice

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/code-imports/01m5mpjg1tt6q0vjbaw3an5j6j/commit`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16969-40a5-7142-8c99-a2f7af0f69a6
Content-Type: application/json

{"error":{"status":409,"code":"batch_not_committable","message":"This import preview was already committed, discarded or has expired.","request_id":"01a16969-40a5-7142-8c99-a2f7af0f69a6"}}
```

### CSV upload preview (code column, one duplicate of an existing code)

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/offers/01m5mpjewf73zwcf12me43kaw0/code-imports (multipart file=codes.csv)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16969-40d8-720c-852e-4f7ccd120241

{"data":{"id":"01m5mpjg7g1qfb396kz5bf87a7","status":"preview","source":"file","totals":{"submitted":3,"valid":2,"invalid":0,"duplicate_in_upload":0,"duplicate_existing":1},"rejections":[{"line":3,"code":"********0002","reason":"duplicate_existing"}],"expires_at":"2026-10-23T16:37:48+00:00","committed_at":null}}
```

### CSV commit

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/code-imports/01m5mpjg7g1qfb396kz5bf87a7/commit`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16969-411a-715e-9546-22f9bd51cc44

{"data":{"id":"01m5mpjg7g1qfb396kz5bf87a7","status":"committed","source":"file","totals":{"valid":2,"invalid":0,"submitted":3,"duplicate_existing":1,"duplicate_in_upload":0,"imported":2,"duplicate_at_commit":0},"rejections":[{"code":"********0002","line":3,"reason":"duplicate_existing"}],"expires_at":null,"committed_at":"2026-10-23T15:37:48+00:00"}}
```

### XLSX upload

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/offers/01m5mpjewf73zwcf12me43kaw0/code-imports (multipart file=codes.xlsx)`

```http
HTTP/1.1 415 Unsupported Media Type
X-Request-Id: 01a16969-415b-7100-a383-293a6ea24685
Content-Type: application/json

{"error":{"status":415,"code":"unsupported_media_type","message":"Spreadsheet files are not supported. Save the sheet as CSV and upload that.","request_id":"01a16969-415b-7100-a383-293a6ea24685"}}
```

### Code sheet with counters

`GET /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/offers/01m5mpjewf73zwcf12me43kaw0/codes?per_page=10&sort=created_at`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16969-449e-7335-8e6e-c4f3b8ee3e88

{"data":[{"id":"01m5mpjg3wfgpg70c2k2rzh6rz","code":"****0001","status":"issued","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg1tt6q0vjbaw3an5j6j","assigned":{"voucher_id":"01m5mpjh2vjze9pr6e3nvttgmq","origin":"merchant_reward","voucher_status":"issued","consumer":"c********@example.nl","claim_expires_at":"2027-01-21T15:37:48+00:00","expires_at":"2027-01-21T15:37:48+00:00"},"reserved_at":"2026-10-23T15:37:48+00:00","issued_at":"2026-10-23T15:37:48+00:00","redeemed_at":null,"invalidated_at":null,"invalid_reason":null,"created_at":"2026-10-23T15:37:47+00:00"},{"id":"01m5mpjg3xtp01ppyq71dapsfx","code":"****0002","status":"reserved","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg1tt6q0vjbaw3an5j6j","assigned":{"voucher_id":"01m5mpjh3fmvekcmygnjyabnee","origin":"answer24_claim","voucher_status":"reserved","consumer":null,"claim_expires_at":"2027-01-21T15:37:48+00:00","expires_at":null},"reserved_at":"2026-10-23T15:37:48+00:00","issued_at":null,"redeemed_at":null,"invalidated_at":null,"invalid_reason":null,"created_at":"2026-10-23T15:37:47+00:00"},{"id":"01m5mpjg3xtp01ppyq71dapsfy","code":"****0003","status":"available","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg1tt6q0vjbaw3an5j6j","assigned":null,"reserved_at":null,"issued_at":null,"redeemed_at":null,"invalidated_at":null,"invalid_reason":null,"created_at":"2026-10-23T15:37:47+00:00"},{"id":"01m5mpjg3xtp01ppyq71dapsfz","code":"****0004","status":"available","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg1tt6q0vjbaw3an5j6j","assigned":null,"reserved_at":null,"issued_at":null,"redeemed_at":null,"invalidated_at":null,"invalid_reason":null,"created_at":"2026-10-23T15:37:47+00:00"},{"id":"01m5mpjg3xtp01ppyq71dapsg0","code":"****0005","status":"available","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg1tt6q0vjbaw3an5j6j","assigned":null,"reserved_at":null,"issued_at":null,"redeemed_at":null,"invalidated_at":null,"invalid_reason":null,"created_at":"2026-10-23T15:37:47+00:00"},{"id":"01m5mpjg9hymgs43c01a0b4n74","code":"****0001","status":"available","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg7g1qfb396kz5bf87a7","assigned":null,"reserved_at":null,"issued_at":null,"redeemed_at":null,"invalidated_at":null,"invalid_reason":null,"created_at":"2026-10-23T15:37:48+00:00"},{"id":"01m5mpjg9hymgs43c01a0b4n75","code":"****0002","status":"available","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg7g1qfb396kz5bf87a7","assigned":null,"reserved_at":null,"issued_at":null,"redeemed_at":null,"invalidated_at":null,"invalid_reason":null,"created_at":"2026-10-23T15:37:48+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes?per_page=10&sort=created_at&page=1","last":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes?per_page=10&sort=created_at&page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes?per_page=10&sort=created_at&page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes","per_page":10,"to":7,"total":7,"counters":{"total":7,"available":5,"reserved":1,"issued":1,"redeemed":0,"expired":0,"invalid":0,"cancelled":0}}}
```

### Issued codes

`GET /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/offers/01m5mpjewf73zwcf12me43kaw0/codes?status=issued`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16969-44e5-73f7-84dc-4b585630bff9

{"data":[{"id":"01m5mpjg3wfgpg70c2k2rzh6rz","code":"****0001","status":"issued","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg1tt6q0vjbaw3an5j6j","assigned":{"voucher_id":"01m5mpjh2vjze9pr6e3nvttgmq","origin":"merchant_reward","voucher_status":"issued","consumer":"c********@example.nl","claim_expires_at":"2027-01-21T15:37:48+00:00","expires_at":"2027-01-21T15:37:48+00:00"},"reserved_at":"2026-10-23T15:37:48+00:00","issued_at":"2026-10-23T15:37:48+00:00","redeemed_at":null,"invalidated_at":null,"invalid_reason":null,"created_at":"2026-10-23T15:37:47+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes?status=issued&page=1","last":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes?status=issued&page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes?status=issued&page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes","per_page":50,"to":1,"total":1,"counters":{"total":7,"available":5,"reserved":1,"issued":1,"redeemed":0,"expired":0,"invalid":0,"cancelled":0}}}
```

### Exact code search

`GET /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/offers/01m5mpjewf73zwcf12me43kaw0/codes?search=woon-9q2l-0002`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16969-451a-7190-adbf-cbf4703ed479

{"data":[{"id":"01m5mpjg9hymgs43c01a0b4n75","code":"****0002","status":"available","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg7g1qfb396kz5bf87a7","assigned":null,"reserved_at":null,"issued_at":null,"redeemed_at":null,"invalidated_at":null,"invalid_reason":null,"created_at":"2026-10-23T15:37:48+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes?search=woon-9q2l-0002&page=1","last":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes?search=woon-9q2l-0002&page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes?search=woon-9q2l-0002&page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8784\/api\/v1\/workspaces\/01m5mpjevbdanbh28agkfdq7y2\/offers\/01m5mpjewf73zwcf12me43kaw0\/codes","per_page":50,"to":1,"total":1,"counters":{"total":7,"available":5,"reserved":1,"issued":1,"redeemed":0,"expired":0,"invalid":0,"cancelled":0}}}
```

### Batch

`GET /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/code-imports/01m5mpjg1tt6q0vjbaw3an5j6j`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16969-4551-70d2-b7e4-7be0d253f756

{"data":{"id":"01m5mpjg1tt6q0vjbaw3an5j6j","status":"committed","source":"paste","totals":{"valid":5,"invalid":1,"imported":5,"submitted":7,"duplicate_existing":0,"duplicate_at_commit":0,"duplicate_in_upload":1},"rejections":[{"code":"********0001","line":4,"reason":"duplicate_in_upload"},{"code":"*****ode!","line":5,"reason":"invalid_format"}],"expires_at":null,"committed_at":"2026-10-23T15:37:47+00:00"}}
```

### Reveal one code (audited)

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/codes/01m5mpjg9hymgs43c01a0b4n75/reveal`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16969-457a-71ef-b3af-2231c61f0fa3

{"data":{"id":"01m5mpjg9hymgs43c01a0b4n75","code":"WOON-9Q2L-0002"}}
```

### Invalidate an unused code

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/codes/01m5mpjg9hymgs43c01a0b4n75/invalidate {"reason":"Code wordt niet geaccepteerd in de kassa"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16969-45aa-717a-91ff-1d86c89ace84

{"data":{"id":"01m5mpjg9hymgs43c01a0b4n75","code":"****0002","status":"invalid","reusable":false,"uses_count":0,"platform_validation":"merchant_verification_required","batch_id":"01m5mpjg7g1qfb396kz5bf87a7","assigned":null,"reserved_at":null,"issued_at":null,"redeemed_at":null,"invalidated_at":"2026-10-23T15:37:49+00:00","invalid_reason":"Code wordt niet geaccepteerd in de kassa","created_at":"2026-10-23T15:37:48+00:00"}}
```

### Invalidate again

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/codes/01m5mpjg9hymgs43c01a0b4n75/invalidate {"reason":"Nogmaals"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16969-45e1-72e1-8857-681b9b09b091
Content-Type: application/json

{"error":{"status":409,"code":"code_not_available","message":"Only available codes can be invalidated.","request_id":"01a16969-45e1-72e1-8857-681b9b09b091","details":{"status":"invalid"}}}
```

### History of the issued code

`GET /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/codes/01m5mpjg3wfgpg70c2k2rzh6rz/history`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16969-4612-7303-bb6d-e1d1d465b2b4

{"data":[{"type":"reserved","from_status":null,"to_status":"reserved","summary":{"origin":"merchant_reward","version":1},"recorded_at":"2026-10-23T15:37:48+00:00"},{"type":"code_issued","from_status":"reserved","to_status":"issued","summary":null,"recorded_at":"2026-10-23T15:37:48+00:00"},{"type":"issued","from_status":"reserved","to_status":"issued","summary":null,"recorded_at":"2026-10-23T15:37:48+00:00"}]}
```

### Employee without vouchers.manage

`POST /api/v1/workspaces/01m5mpjevbdanbh28agkfdq7y2/codes/01m5mpjg9hymgs43c01a0b4n75/reveal`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16969-4676-73ab-93e1-114c8bdd16e8
Content-Type: application/json

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a16969-4676-73ab-93e1-114c8bdd16e8","details":{"required_permission":"vouchers.manage"}}}
```

### Another merchant

`GET /api/v1/workspaces/01m5mpjevv5d0p88mph8y0qzp9/offers/01m5mpjewf73zwcf12me43kaw0/codes`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16969-46a4-703d-bdf8-7cd442a56086
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a16969-46a4-703d-bdf8-7cd442a56086"}}
```

### CSV export of the CSV batch (full codes; audited)

```http
HTTP/1.1 200 OK
Content-Type: text/csv; charset=UTF-8
Cache-Control: no-store, private
Content-Disposition: attachment; filename=codes-01m5mpjewf73zwcf12me43kaw0.csv
X-Request-Id: 01a16969-463f-7019-a894-d726e7605113
code,status,batch,platform_validation,added_at,issued_at,redeemed_at
WOON-9Q2L-0001,available,01m5mpjg7g1qfb396kz5bf87a7,merchant_verification_required,2026-10-23T15:37:48+00:00,,
WOON-9Q2L-0002,invalid,01m5mpjg7g1qfb396kz5bf87a7,merchant_verification_required,2026-10-23T15:37:48+00:00,,
```

---

## Answer24: eligibility, grouping, cancellation (B07a, Trello #1, #3, #23)

Captured 2026-10-23 against local MySQL + Redis. *Woon & Co* is active, with an active "€10 korting from €50" reward offer, Answer24 enabled with `consent_at_checkout`, and phone `06 9999 8888` on the do-not-contact list. Orders were sent as **signed Shopbonus-format webhooks** (with `contact_consent`), so ingestion and eligibility ran for real.

Outcome per order (from the Order Sheet capture below):

| Order | Situation | eligibility | contact_status | reasons |
| --- | --- | --- | --- | --- |
| PS-1001 | Anna, paid, consent, phone | eligible | grouped → queued | — |
| PS-1002 | Anna again 20 min later (email in different case) | eligible | grouped (same opportunity) → queued | — |
| PS-1003 | Bram, no consent | ineligible | blocked | no_contact_consent |
| PS-1004 | Cees, no phone | eligible | not_callable | — |
| PS-1005 | Dirk, suppressed phone | ineligible | blocked | suppressed |
| PS-1006 | Eva, payment pending | ineligible | blocked | not_paid |
| PS-1007 | Fenna, paid then refunded | ineligible | removed (opportunity cancelled) | refunded |

To show the hour-long window closing without waiting an hour, the capture moved `window_ends_at` into the past in the database and ran the real command `php artisan shopbonus:close-contact-windows` (output: `Queued 1 contact opportunity(ies).`). It is scheduled every minute in production.

Queued outbox payload (`answer24.contact_requested`, contract `shopbonus-answer24-v1`). It is **not sent**, because `ANSWER24_DISPATCH_ENABLED=false` and Answer24 hasn't confirmed the contract yet. Only first name and phone, never email:

```json
{
  "contract": "shopbonus-answer24-v1",
  "opportunity_id": "01m5mq4wyj15qcxh4ysp2weaz2",
  "idempotency_key": "contact:17:01m5mq4wyhpj4kxeqe5wytxq27",
  "webshop": {
    "id": "01m5mq4vmycsgnv6bxa7vcz4vg",
    "name": "Woon & Co"
  },
  "offer": {
    "id": "01m5mq4vncgypz8x9knxj5mv4x",
    "summary": "€10.00 from €50.00",
    "terms": {
      "version": 1,
      "locked": true,
      "summary": "€10.00 from €50.00",
      "name": "€10 korting",
      "public_description": null,
      "discount_type": "fixed",
      "discount_value": 1000,
      "currency": "EUR",
      "min_order_minor": 5000,
      "max_discount_minor": null,
      "price_minor": null,
      "starts_at": null,
      "ends_at": null,
      "claim_validity_days": 90,
      "redemption_validity_days": 90,
      "categories": [],
      "products": [],
      "combinable": false,
      "code_mode": "unique",
      "allocation_limit": null
    }
  },
  "customer": {
    "first_name": "Anna",
    "phone": "+31612345678"
  },
  "orders": [
    {
      "reference": "PS-1001",
      "placed_at": "2026-10-23T15:47:50+00:00",
      "amount_minor": 7500,
      "currency": "EUR"
    },
    {
      "reference": "PS-1002",
      "placed_at": "2026-10-23T15:47:50+00:00",
      "amount_minor": 9900,
      "currency": "EUR"
    }
  ],
  "lawful_basis": {
    "basis": "consent_at_checkout",
    "evidence_ref": "webshop:01m5mq4vmycsgnv6bxa7vcz4vg:answer24_settings"
  }
}
```

### Eligibility reasons

`webshop_not_active`, `no_active_reward_offer`, `below_minimum_order_value`, `not_paid`, `cancelled`, `refunded`, `incomplete_order`, `answer24_disabled`, `no_lawful_basis`, `lawful_basis_not_permitted`, `no_contact_consent`, `suppressed`, `recently_contacted` (within `ANSWER24_RECENT_CONTACT_DAYS`, default 30). A missing or invalid phone is not a reason: the order stays eligible but `not_callable`.

### GET answer24/settings (default)

`GET /api/v1/workspaces/01m5mq4vmycsgnv6bxa7vcz4vg/answer24/settings`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16972-7207-712e-8abc-bfea2501a639

{"data":{"enabled":false,"lawful_basis":null,"documented_by":null,"documented_at":null,"allowed_bases":["consent_at_checkout"],"platform_dispatch_enabled":false,"notes":"An order alone never authorises contact. Consent-based contact requires the shop to report contact consent per order."}}
```

### Legitimate interest refused

`PUT /api/v1/workspaces/01m5mq4vmycsgnv6bxa7vcz4vg/answer24/settings {"enabled":true,"lawful_basis":"legitimate_interest","attestation":true}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16972-725a-7284-abc2-bead2c22905d
Content-Type: application/json

{"error":{"status":422,"code":"lawful_basis_not_permitted","message":"Legitimate interest is not currently accepted as a basis for calls. Use consent captured at checkout.","request_id":"01a16972-725a-7284-abc2-bead2c22905d"}}
```

### A manager attesting

`PUT /api/v1/workspaces/01m5mq4vmycsgnv6bxa7vcz4vg/answer24/settings {"enabled":true,"lawful_basis":"consent_at_checkout","attestation":true}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16972-7286-72a2-a6aa-9a4f3366fac7
Content-Type: application/json

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a16972-7286-72a2-a6aa-9a4f3366fac7","details":{"required_permission":"agreements.accept"}}}
```

### Owner enables consent-based contact

`PUT /api/v1/workspaces/01m5mq4vmycsgnv6bxa7vcz4vg/answer24/settings {"enabled":true,"lawful_basis":"consent_at_checkout","attestation":true}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16972-72ad-71e5-9e50-541bc4ac40a7

{"data":{"enabled":true,"lawful_basis":"consent_at_checkout","documented_by":"01m5mq4vmf52rtd7nqtdmrhp08","documented_at":"2026-10-23T15:47:50+00:00","allowed_bases":["consent_at_checkout"],"platform_dispatch_enabled":false,"notes":"An order alone never authorises contact. Consent-based contact requires the shop to report contact consent per order."}}
```

### Add a phone to the do-not-contact list

`POST /api/v1/workspaces/01m5mq4vmycsgnv6bxa7vcz4vg/answer24/suppressions {"phone":"06 9999 8888","reason":"Wil niet gebeld worden"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16972-72d0-7371-94cf-2e7d4c5b6d51

{"data":{"suppressed":true,"new_entries":1}}
```

### Order Sheet with eligibility and contact status

`GET /api/v1/workspaces/01m5mq4vmycsgnv6bxa7vcz4vg/orders?sort=placed_at&per_page=10`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16972-75c3-7013-9c37-41aa99abf926

{"data":[{"id":"01m5mq4wy0bv91hspkq03pap3q","reference":"PS-1001","external_order_id":"1001","platform":"prestashop","customer":{"first_name":"Anna","email":"anna@example.nl","phone":"06 1234 5678"},"amount_minor":7500,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":null,"ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"eligible","eligibility_reasons":[],"contact_status":"grouped","placed_at":"2026-10-23T15:47:50+00:00","paid_at":"2026-10-23T15:47:50+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:47:50+00:00"},{"id":"01m5mq4x0y5gq82spzqbcagdq2","reference":"PS-1002","external_order_id":"1002","platform":"prestashop","customer":{"first_name":"Anna","email":"ANNA@example.nl","phone":"0612345678"},"amount_minor":9900,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":null,"ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"eligible","eligibility_reasons":[],"contact_status":"grouped","placed_at":"2026-10-23T15:47:50+00:00","paid_at":"2026-10-23T15:47:50+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:47:50+00:00"},{"id":"01m5mq4x31p9kmbaa12nvaa3xb","reference":"PS-1003","external_order_id":"1003","platform":"prestashop","customer":{"first_name":"Bram","email":"bram@example.nl","phone":"0611112222"},"amount_minor":7500,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":null,"ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"ineligible","eligibility_reasons":["no_contact_consent"],"contact_status":"blocked","placed_at":"2026-10-23T15:47:50+00:00","paid_at":"2026-10-23T15:47:50+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:47:51+00:00"},{"id":"01m5mq4x516hgpjxag1m102bd3","reference":"PS-1004","external_order_id":"1004","platform":"prestashop","customer":{"first_name":"Cees","email":"cees@example.nl","phone":null},"amount_minor":7500,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":null,"ingestion_status":"complete","missing_fields":[],"callable":false,"eligibility_status":"eligible","eligibility_reasons":[],"contact_status":"not_callable","placed_at":"2026-10-23T15:47:50+00:00","paid_at":"2026-10-23T15:47:50+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:47:51+00:00"},{"id":"01m5mq4x6zkpaff1qney8mfky9","reference":"PS-1005","external_order_id":"1005","platform":"prestashop","customer":{"first_name":"Dirk","email":"dirk@example.nl","phone":"06 9999 8888"},"amount_minor":7500,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":null,"ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"ineligible","eligibility_reasons":["suppressed"],"contact_status":"blocked","placed_at":"2026-10-23T15:47:50+00:00","paid_at":"2026-10-23T15:47:50+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:47:51+00:00"},{"id":"01m5mq4x8yg25afj2byhvg18w9","reference":"PS-1006","external_order_id":"1006","platform":"prestashop","customer":{"first_name":"Eva","email":"eva@example.nl","phone":"0633334444"},"amount_minor":7500,"refunded_minor":0,"currency":"EUR","payment_status":"pending","provider_status":null,"ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"ineligible","eligibility_reasons":["not_paid"],"contact_status":"blocked","placed_at":"2026-10-23T15:47:50+00:00","paid_at":"2026-10-23T15:47:50+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:47:51+00:00"},{"id":"01m5mq4xawh1wzcv8v5sqeqj7b","reference":"PS-1007","external_order_id":"1007","platform":"prestashop","customer":{"first_name":"Fenna","email":"fenna@example.nl","phone":"0655556666"},"amount_minor":7500,"refunded_minor":0,"currency":"EUR","payment_status":"refunded","provider_status":null,"ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"ineligible","eligibility_reasons":["refunded"],"contact_status":"removed","placed_at":"2026-10-23T15:47:50+00:00","paid_at":"2026-10-23T15:47:50+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:47:51+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8786\/api\/v1\/workspaces\/01m5mq4vmycsgnv6bxa7vcz4vg\/orders?sort=placed_at&per_page=10&page=1","last":"http:\/\/127.0.0.1:8786\/api\/v1\/workspaces\/01m5mq4vmycsgnv6bxa7vcz4vg\/orders?sort=placed_at&per_page=10&page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8786\/api\/v1\/workspaces\/01m5mq4vmycsgnv6bxa7vcz4vg\/orders?sort=placed_at&per_page=10&page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8786\/api\/v1\/workspaces\/01m5mq4vmycsgnv6bxa7vcz4vg\/orders","per_page":10,"to":7,"total":7,"today":{"timezone":"Europe\/Amsterdam","orders":7,"paid_orders":5,"incomplete_orders":0,"paid_revenue_minor":{"EUR":39900}}}}
```

### Opportunities while the window is open

`GET /api/v1/workspaces/01m5mq4vmycsgnv6bxa7vcz4vg/answer24/opportunities`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16972-75f1-737f-bfa4-64d7ed3c468d

{"data":[{"id":"01m5mq4xb8f0btfqpgym3r01nh","status":"cancelled","lawful_basis":"consent_at_checkout","orders":0,"window_ends_at":"2026-10-23T16:47:51+00:00","ready_at":null,"dispatched_at":null,"cancelled_at":"2026-10-23T15:47:51+00:00","cancel_reason":"refunded","created_at":"2026-10-23T15:47:51+00:00"},{"id":"01m5mq4wyj15qcxh4ysp2weaz2","status":"pending","lawful_basis":"consent_at_checkout","orders":2,"window_ends_at":"2026-10-23T16:47:50+00:00","ready_at":null,"dispatched_at":null,"cancelled_at":null,"cancel_reason":null,"created_at":"2026-10-23T15:47:50+00:00"}],"meta":{"current_page":1,"total":2,"dispatch_enabled":false}}
```

### Opportunities after the window closed

`GET /api/v1/workspaces/01m5mq4vmycsgnv6bxa7vcz4vg/answer24/opportunities`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16972-7745-7014-95ea-de4617a57f96

{"data":[{"id":"01m5mq4xb8f0btfqpgym3r01nh","status":"cancelled","lawful_basis":"consent_at_checkout","orders":0,"window_ends_at":"2026-10-23T16:47:51+00:00","ready_at":null,"dispatched_at":null,"cancelled_at":"2026-10-23T15:47:51+00:00","cancel_reason":"refunded","created_at":"2026-10-23T15:47:51+00:00"},{"id":"01m5mq4wyj15qcxh4ysp2weaz2","status":"ready","lawful_basis":"consent_at_checkout","orders":2,"window_ends_at":"2026-10-23T15:46:51+00:00","ready_at":"2026-10-23T15:47:51+00:00","dispatched_at":null,"cancelled_at":null,"cancel_reason":null,"created_at":"2026-10-23T15:47:50+00:00"}],"meta":{"current_page":1,"total":2,"dispatch_enabled":false}}
```

### Order PS-1001 after queueing

`GET /api/v1/workspaces/01m5mq4vmycsgnv6bxa7vcz4vg/orders?search=PS-1001`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16972-7766-7015-a615-80ac2187b02a

{"data":[{"id":"01m5mq4wy0bv91hspkq03pap3q","reference":"PS-1001","external_order_id":"1001","platform":"prestashop","customer":{"first_name":"Anna","email":"anna@example.nl","phone":"06 1234 5678"},"amount_minor":7500,"refunded_minor":0,"currency":"EUR","payment_status":"paid","provider_status":null,"ingestion_status":"complete","missing_fields":[],"callable":true,"eligibility_status":"eligible","eligibility_reasons":[],"contact_status":"queued","placed_at":"2026-10-23T15:47:50+00:00","paid_at":"2026-10-23T15:47:50+00:00","cancelled_at":null,"refunded_at":null,"updated_at":"2026-10-23T15:47:51+00:00"}],"links":{"first":"http:\/\/127.0.0.1:8786\/api\/v1\/workspaces\/01m5mq4vmycsgnv6bxa7vcz4vg\/orders?search=PS-1001&page=1","last":"http:\/\/127.0.0.1:8786\/api\/v1\/workspaces\/01m5mq4vmycsgnv6bxa7vcz4vg\/orders?search=PS-1001&page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8786\/api\/v1\/workspaces\/01m5mq4vmycsgnv6bxa7vcz4vg\/orders?search=PS-1001&page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8786\/api\/v1\/workspaces\/01m5mq4vmycsgnv6bxa7vcz4vg\/orders","per_page":25,"to":1,"total":1,"today":{"timezone":"Europe\/Amsterdam","orders":7,"paid_orders":5,"incomplete_orders":0,"paid_revenue_minor":{"EUR":39900}}}}
```

---

## Answer24: signed channel, handoff, claim, reminders (B07b, Trello #1, #10, #23)

Captured 2026-10-23 against local MySQL + Redis. Setup: *Woon & Co* active with the "€10 korting from €50" offer and two codes (`WOON-A24-0001`, `-0002`), Answer24 enabled (`consent_at_checkout`). Anna's paid, consented order PS-1001 arrived as a signed webhook. Its grouping window was moved into the past in the database, then the real `shopbonus:close-contact-windows` ran.

**Credentials.** Run `php artisan shopbonus:create-integration-client "Answer24"`. It prints `Key ID: sbk_…` and `Secret: …` **once**; the secret is stored encrypted. Pass `--scope=answer24.claims` (repeatable) to limit a key, and `--revoke=<key id>` to revoke one. The capture also created a key limited to `answer24.claims` to show the 403.

**Signing (both directions).** Each request carries:

- `X-Shopbonus-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>`, valid for 5 minutes;
- `X-Shopbonus-Event-Id`, unique per request;
- inbound requests only: `X-Shopbonus-Key`.

Retrying with the same event ID and body is processed idempotently. The same event ID with a different body returns 409 `event_id_reused`.

### Outbound: contact request (dispatcher)

`php artisan shopbonus:dispatch-outbox` (scheduled every minute) does nothing while `ANSWER24_DISPATCH_ENABLED=false`, which is the default. For this capture it ran **once with dispatch enabled against a local stand-in for Answer24** (a stub that logs the request and answers `202 {"reference":"A24-778812"}`). Answer24 has no published API yet (OPEN-ANSWER24-CONTRACT), so this shows Shopbonus's side of the contract, not a real Answer24 response.

- Command output: `Sent 1, retrying 0, failed 0, skipped 0.`
- Request received by the stand-in (`POST /api/shopbonus/v1/contact-requests`), with the signature value elided:

```json
{
  "headers": {
    "X-Shopbonus-Signature": "t=<unix>,v1=<hex>",
    "X-Shopbonus-Event-Id": "01m5n4ga1qrasn6a6t1pgdzgjm",
    "X-Shopbonus-Topic": "answer24.contact_requested",
    "Idempotency-Key": "contact_requested:01m5n4g9mbtc52yzynyrp1p239",
    "Content-Type": "application/json"
  },
  "body": {
    "event": "answer24.contact_requested",
    "event_id": "01m5n4ga1qrasn6a6t1pgdzgjm",
    "contract": "shopbonus-answer24-v1",
    "opportunity_id": "01m5n4g9mbtc52yzynyrp1p239",
    "idempotency_key": "contact:20:01m5n4g9m9gma5jkcpb7ygx4a6",
    "webshop": {
      "id": "01m5n4g702emasm3dc33ydvwwb",
      "name": "Woon & Co"
    },
    "offer": {
      "id": "01m5n4g70w95ckv85vdx00t3pk",
      "summary": "€10.00 from €50.00",
      "terms": {
        "version": 1,
        "locked": true,
        "summary": "€10.00 from €50.00",
        "name": "€10 korting",
        "public_description": null,
        "discount_type": "fixed",
        "discount_value": 1000,
        "currency": "EUR",
        "min_order_minor": 5000,
        "max_discount_minor": null,
        "price_minor": null,
        "starts_at": null,
        "ends_at": null,
        "claim_validity_days": 90,
        "redemption_validity_days": 90,
        "categories": [],
        "products": [],
        "combinable": false,
        "code_mode": "unique",
        "allocation_limit": null
      }
    },
    "customer": {
      "first_name": "Anna",
      "phone": "+31612345678"
    },
    "orders": [
      {
        "reference": "PS-1001",
        "placed_at": "2026-10-23T19:41:15+00:00",
        "amount_minor": 7500,
        "currency": "EUR"
      }
    ],
    "lawful_basis": {
      "basis": "consent_at_checkout",
      "evidence_ref": "webshop:01m5n4g702emasm3dc33ydvwwb:answer24_settings"
    }
  }
}
```

After this the opportunity is `dispatched` with `remote_reference` `A24-778812`, and the Order Sheet shows `contact_status: dispatched`.

### Inbound: contact results

**Handoff before the customer accepted → 409**

`POST /handoffs {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0001)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16a48-2e3f-71db-b5c8-7086972b7757
Content-Type: application/json

{"error":{"status":409,"code":"handoff_not_permitted","message":"A voucher can only be handed off after the customer accepted the contact.","request_id":"01a16a48-2e3f-71db-b5c8-7086972b7757","details":{"status":"dispatched"}}}
```

**`accepted` → applied**

`POST /contact-results {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"accepted","occurred_at":"2026-10-23T19:41:17Z","remote_reference":"A24-778812"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0002)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-2e92-7042-b266-3e012a83b2ca

{"data":{"opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"accepted","outcome":"applied"}}
```

**Same event again → duplicate, nothing changes**

`POST /contact-results {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"accepted","occurred_at":"2026-10-23T19:41:17Z","remote_reference":"A24-778812"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0002)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-2edf-7380-a5fb-04c5fc88069b

{"data":{"opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"accepted","outcome":"duplicate"}}
```

**Same event ID, different body → 409**

`POST /contact-results {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"completed","occurred_at":"2026-10-23T19:41:17Z","remote_reference":"A24-778812"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0002)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16a48-2f27-7226-a132-534f9a92ea38
Content-Type: application/json

{"error":{"status":409,"code":"event_id_reused","message":"This event ID was already used for a different request.","request_id":"01a16a48-2f27-7226-a132-534f9a92ea38"}}
```

**Older result arriving late → recorded as stale, not applied**

`POST /contact-results {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"attempted","occurred_at":"2026-01-01T00:00:00Z","remote_reference":"A24-778812"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0003)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-2f71-7039-80ce-fe299948613d

{"data":{"opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"accepted","outcome":"stale"}}
```

**Impossible transition → 409**

`POST /contact-results {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"rejected","occurred_at":"2026-10-23T19:41:17Z","remote_reference":"A24-778812"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0004)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16a48-2fcd-70cc-a3e3-e8331a232bc5
Content-Type: application/json

{"error":{"status":409,"code":"invalid_transition","message":"This result cannot be applied in the current state.","request_id":"01a16a48-2fcd-70cc-a3e3-e8331a232bc5","details":{"status":"accepted","result":"rejected"}}}
```

**Opportunity addressed under another webshop → 404**

`POST /contact-results {"webshop_id":"01jbzzzzzzzzzzzzzzzzzzzzzz","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"completed","occurred_at":"2026-10-23T19:41:17Z"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0005)`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16a48-300e-71aa-bead-9a6d276e41a9
Content-Type: application/json

{"error":{"status":404,"code":"opportunity_not_found","message":"Contact request not found.","request_id":"01a16a48-300e-71aa-bead-9a6d276e41a9"}}
```

**No event ID → 400**

`POST /contact-results {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"completed","occurred_at":"2026-10-23T19:41:17Z","remote_reference":"A24-778812"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: )`

```http
HTTP/1.1 400 Bad Request
X-Request-Id: 01a16a48-304a-72ed-b93d-cecc7186aeb4
Content-Type: application/json

{"error":{"status":400,"code":"event_id_required","message":"X-Shopbonus-Event-Id is required (max 120 characters).","request_id":"01a16a48-304a-72ed-b93d-cecc7186aeb4"}}
```

**Wrong signature → 401 (audited)**

`POST /contact-results {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"completed","occurred_at":"2026-10-23T19:41:17Z","remote_reference":"A24-778812"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0006)`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a16a48-3081-7027-9b84-5d1adad35e88
Content-Type: application/json

{"error":{"status":401,"code":"integration_signature_invalid","message":"The request signature is missing or invalid.","request_id":"01a16a48-3081-7027-9b84-5d1adad35e88"}}
```

**Key without the scope → 403**

`POST /contact-results {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239","status":"completed","occurred_at":"2026-10-23T19:41:17Z","remote_reference":"A24-778812"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0007)`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16a48-30bb-732e-95a0-2f643062223a
Content-Type: application/json

{"error":{"status":403,"code":"integration_scope_denied","message":"This integration key may not call this endpoint.","request_id":"01a16a48-30bb-732e-95a0-2f643062223a","details":{"required_scope":"answer24.contact_results"}}}
```

### Handoff → claim link

**Handoff: one code reserved, claim link returned**

`POST /handoffs {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0008)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16a48-30f4-715c-ba07-7135490010d5

{"data":{"claim_id":"01m5n4gc8vk6t23v5n3xqh5tsm","status":"open","expires_at":"2027-01-21T19:41:18+00:00","claimed_at":null,"reminder_days":[30,60,80],"claim_url":"http:\/\/localhost:3000\/claim\/<claim-token>"}}
```

**Repeated handoff: same claim, same link, no second code**

`POST /handoffs {"webshop_id":"01m5n4g702emasm3dc33ydvwwb","opportunity_id":"01m5n4g9mbtc52yzynyrp1p239"}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0009)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-314f-70fb-921d-a230403d722e

{"data":{"claim_id":"01m5n4gc8vk6t23v5n3xqh5tsm","status":"open","expires_at":"2027-01-21T19:41:18+00:00","claimed_at":null,"reminder_days":[30,60,80],"claim_url":"http:\/\/localhost:3000\/claim\/<claim-token>"}}
```

**Claim status**

`GET /claims/01m5n4gc8vk6t23v5n3xqh5tsm    (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0010)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-319e-724f-8ce0-fe3dfea2b74d

{"data":{"claim_id":"01m5n4gc8vk6t23v5n3xqh5tsm","status":"open","expires_at":"2027-01-21T19:41:18+00:00","claimed_at":null,"reminder_days":[30,60,80]}}
```

**Key without `answer24.claims` → 403**

`GET /claims/01m5n4gc8vk6t23v5n3xqh5tsm    (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0015)`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16a48-31d9-713d-ab83-2fe62535ac2a
Content-Type: application/json

{"error":{"status":403,"code":"integration_scope_denied","message":"This integration key may not call this endpoint.","request_id":"01a16a48-31d9-713d-ab83-2fe62535ac2a","details":{"required_scope":"answer24.claims"}}}
```

**Reminder for day 30 asked on day 0**

`POST /claims/01m5n4gc8vk6t23v5n3xqh5tsm/reminders {"day":30}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0011)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-32d7-7179-a2f1-e0ef9e94311c

{"data":{"claim_id":"01m5n4gc8vk6t23v5n3xqh5tsm","day":30,"send":false,"reason":"not_due","claim_expires_at":"2027-01-21T19:41:18+00:00"}}
```

**Not a reminder day**

`POST /claims/01m5n4gc8vk6t23v5n3xqh5tsm/reminders {"day":45}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0012)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a48-3320-7354-9d56-ff7adb282a03
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a48-3320-7354-9d56-ff7adb282a03","fields":{"day":["The selected day is invalid."]}}}
```

The real claim token is replaced by `<claim-token>` throughout.

### Consumer claim

**Preview (public)**

`POST /api/v1/claims/preview {"token":"<claim-token>"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-334e-71db-9fe9-025c405adae4

{"data":{"status":"open","expires_at":"2027-01-21T19:41:18+00:00","webshop":{"name":"Woon & Co"},"offer":{"name":"\u20ac10 korting","ends_at":null,"summary":"\u20ac10.00 from \u20ac50.00","currency":"EUR","products":[],"categories":[],"combinable":false,"discount_type":"fixed","discount_value":1000,"min_order_minor":5000,"max_discount_minor":null,"public_description":null,"redemption_validity_days":90},"requirements":{"verified_account":true,"email_matches_order":true}}}
```

**Unknown token**

`POST /api/v1/claims/preview {"token":"AAAAAAAAAAAAAAAAAAAAAAAA"}`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16a48-3374-73b6-b069-283eb179e9f7
Content-Type: application/json

{"error":{"status":404,"code":"claim_not_found","message":"This claim link is not valid.","request_id":"01a16a48-3374-73b6-b069-283eb179e9f7"}}
```

**Accept without signing in**

`POST /api/v1/claims/accept {"token":"<claim-token>"}`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a16a48-3397-71c1-a3d3-a722bdbe6744
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a16a48-3397-71c1-a3d3-a722bdbe6744"}}
```

**Accept as Bob (bob@example.nl; the order was anna@example.nl) → 403, audited**

`POST /api/v1/claims/accept {"token":"<claim-token>"}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16a48-33ba-7073-a70c-05c629b40d03
Content-Type: application/json

{"error":{"status":403,"code":"claim_email_mismatch","message":"Sign in with the email address used for the order to claim this voucher.","request_id":"01a16a48-33ba-7073-a70c-05c629b40d03"}}
```

**Accept as Anna → voucher issued with its code**

`POST /api/v1/claims/accept {"token":"<claim-token>"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-33e9-71f6-b95f-974931407398

{"data":{"voucher_id":"01m5n4gc8jpx16113paeaea3cf","status":"issued","code":"WOON-A24-0001","issued_at":"2026-10-23T19:41:19+00:00","expires_at":"2027-01-21T19:41:19+00:00","webshop":{"name":"Woon & Co"},"offer":{"name":"\u20ac10 korting","ends_at":null,"summary":"\u20ac10.00 from \u20ac50.00","currency":"EUR","products":[],"categories":[],"combinable":false,"discount_type":"fixed","discount_value":1000,"min_order_minor":5000,"max_discount_minor":null,"public_description":null,"redemption_validity_days":90}}}
```

**Anna again → same voucher**

`POST /api/v1/claims/accept {"token":"<claim-token>"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-3452-7305-abbd-0e3fc9fd5a18

{"data":{"voucher_id":"01m5n4gc8jpx16113paeaea3cf","status":"issued","code":"WOON-A24-0001","issued_at":"2026-10-23T19:41:19+00:00","expires_at":"2027-01-21T19:41:19+00:00","webshop":{"name":"Woon & Co"},"offer":{"name":"\u20ac10 korting","ends_at":null,"summary":"\u20ac10.00 from \u20ac50.00","currency":"EUR","products":[],"categories":[],"combinable":false,"discount_type":"fixed","discount_value":1000,"min_order_minor":5000,"max_discount_minor":null,"public_description":null,"redemption_validity_days":90}}}
```

**Link used → 410**

`POST /api/v1/claims/preview {"token":"<claim-token>"}`

```http
HTTP/1.1 410 Gone
X-Request-Id: 01a16a48-3480-71b1-9929-76fd949dc598
Content-Type: application/json

{"error":{"status":410,"code":"claim_already_used","message":"This voucher was already claimed.","request_id":"01a16a48-3480-71b1-9929-76fd949dc598"}}
```

**Answer24 checks the claim**

`GET /claims/01m5n4gc8vk6t23v5n3xqh5tsm    (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0013)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-34bb-71fe-87df-acbcf667e72c

{"data":{"claim_id":"01m5n4gc8vk6t23v5n3xqh5tsm","status":"claimed","expires_at":"2027-01-21T19:41:18+00:00","claimed_at":"2026-10-23T19:41:19+00:00","reminder_days":[30,60,80]}}
```

**Reminder after the claim → not sent**

`POST /claims/01m5n4gc8vk6t23v5n3xqh5tsm/reminders {"day":30}   (X-Shopbonus-Key, X-Shopbonus-Signature, X-Shopbonus-Event-Id: a24-evt-0014)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a48-34fb-73ac-a018-832a5925cf9d

{"data":{"claim_id":"01m5n4gc8vk6t23v5n3xqh5tsm","day":30,"send":false,"reason":"claimed","claim_expires_at":"2027-01-21T19:41:18+00:00"}}
```

Outbox message queued at claim (`answer24.claim_completed`; delivered by the dispatcher once dispatch is enabled):

```json
{
  "contract": "shopbonus-answer24-v1",
  "claim_id": "01m5n4gc8vk6t23v5n3xqh5tsm",
  "opportunity_id": "01m5n4g9mbtc52yzynyrp1p239",
  "remote_reference": "A24-778812",
  "status": "claimed",
  "at": "2026-10-23T19:41:19+00:00"
}
```

**Expiry.** `shopbonus:expire-vouchers` runs hourly. After the claim window it releases the reserved code back to the inventory, marks the claim `expired` and queues `answer24.claim_expired`. Preview then returns 410 `claim_expired`, and reminders return `send: false, reason: expired`. This is covered by `Answer24ChannelTest::test_unclaimed_entitlement_expires_after_90_days_and_releases_the_code`; it was not captured over HTTP, because that would mean waiting 90 days.

---

## Discovery: public webshops and offers (B08a, Trello #18)

Captured 2026-10-23 against local MySQL + Redis. Two approved webshops: *Fietsen Utrecht*, with an active "€10 korting" offer and two codes, and *Woon & Co*, whose "15% op meubels" offer has no codes left. Eva is an employee of Woon & Co.

Browsable docs: when `API_DOCS_ENABLED` is on (the default outside production), `/docs` shows this spec in an OpenAPI viewer and `/docs/reference` shows this README rendered.

### Merchant: public profile

**Before the first save**

`GET /api/v1/workspaces/01m5n5artq8hxe2kt1j9rfy2jc/public-profile`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-683f-7160-af17-174733518203

{"data":{"slug":null,"display_name":"Fietsen Utrecht","short_description":null,"description":null,"website_url":null,"categories":[],"has_logo":false,"published":false,"published_at":null,"slug_locked":false,"listed":false,"listing_blockers":["profile_not_published"],"public_url_path":null}}
```

**Publishing an incomplete profile → 422**

`PUT /api/v1/workspaces/01m5n5artq8hxe2kt1j9rfy2jc/public-profile {"published":true}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a55-68dc-7050-84ed-082a1487bc91
Content-Type: application/json

{"error":{"status":422,"code":"public_profile_incomplete","message":"Complete the public profile before publishing it.","request_id":"01a16a55-68dc-7050-84ed-082a1487bc91","details":{"missing":["short_description","categories"]}}}
```

**HTML is refused → 422**

`PUT /api/v1/workspaces/01m5n5artq8hxe2kt1j9rfy2jc/public-profile {"description":"<script>alert(1)</script>"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a55-6941-73f4-a7c5-3d2f0546f0e5
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a55-6941-73f4-a7c5-3d2f0546f0e5","fields":{"description":["The description field format is invalid."]}}}
```

**Complete and publish**

`PUT /api/v1/workspaces/01m5n5artq8hxe2kt1j9rfy2jc/public-profile {"short_description":"Fietsen, onderdelen en reparatie in Utrecht","description":"Familiebedrijf sinds 1988.","website_url":"https://fietsen-utrecht.nl","categories":["sports_outdoor"],"published":true}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-697f-72bd-9f37-9806538d1359

{"data":{"slug":"fietsen-utrecht","display_name":"Fietsen Utrecht","short_description":"Fietsen, onderdelen en reparatie in Utrecht","description":"Familiebedrijf sinds 1988.","website_url":"https:\/\/fietsen-utrecht.nl","categories":["sports_outdoor"],"has_logo":false,"published":true,"published_at":"2026-10-23T19:55:44+00:00","slug_locked":true,"listed":true,"listing_blockers":[],"public_url_path":"\/webshops\/fietsen-utrecht"}}
```

**Changing the slug after publishing → 409**

`PUT /api/v1/workspaces/01m5n5artq8hxe2kt1j9rfy2jc/public-profile {"slug":"fietsen-nl"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16a55-69ca-714c-912d-581f04d05685
Content-Type: application/json

{"error":{"status":409,"code":"slug_locked","message":"The public address cannot change after the profile was first published.","request_id":"01a16a55-69ca-714c-912d-581f04d05685"}}
```

**Employee without settings.manage → 403**

`PUT /api/v1/workspaces/01m5n5arva9sbqgpew11z4kndj/public-profile {"short_description":"x"}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16a55-69ff-703b-972c-d67613bf0813
Content-Type: application/json

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a16a55-69ff-703b-972c-d67613bf0813","details":{"required_permission":"settings.manage"}}}
```

**Logo upload: SVG refused (multipart `logo=@logo.svg`)**

`POST /api/v1/workspaces/{workspace}/public-profile/logo (multipart: logo=@logo.svg;type=image/svg+xml)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a55-6a82-70d3-bc92-59c8c798040f
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a55-6a82-70d3-bc92-59c8c798040f","fields":{"logo":["The logo field must be a file of type: png, jpg, jpeg, webp.","The logo field must be a file of type: image\/png, image\/jpeg, image\/webp."]}}}
```

**Logo upload: 160×160 PNG (multipart `logo=@logo.png`)**

`POST /api/v1/workspaces/{workspace}/public-profile/logo (multipart: logo=@logo.png;type=image/png)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-6afc-71e5-9811-37893da8dc7e

{"data":{"slug":"fietsen-utrecht","display_name":"Fietsen Utrecht","short_description":"Fietsen, onderdelen en reparatie in Utrecht","description":"Familiebedrijf sinds 1988.","website_url":"https:\/\/fietsen-utrecht.nl","categories":["sports_outdoor"],"has_logo":true,"published":true,"published_at":"2026-10-23T19:55:44+00:00","slug_locked":true,"listed":true,"listing_blockers":[],"public_url_path":"\/webshops\/fietsen-utrecht"}}
```

**Remove the logo**

`DELETE /api/v1/workspaces/01m5n5artq8hxe2kt1j9rfy2jc/public-profile/logo`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-6d8e-7005-ae03-c5e7847e6d20

{"data":{"slug":"fietsen-utrecht","display_name":"Fietsen Utrecht","short_description":"Fietsen, onderdelen en reparatie in Utrecht","description":"Familiebedrijf sinds 1988.","website_url":"https:\/\/fietsen-utrecht.nl","categories":["sports_outdoor"],"has_logo":false,"published":true,"published_at":"2026-10-23T19:55:44+00:00","slug_locked":true,"listed":true,"listing_blockers":[],"public_url_path":"\/webshops\/fietsen-utrecht"}}
```

### Public discovery (no authentication)

**Categories**

`GET /api/v1/public/categories`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-6b56-7146-9727-c25ae618e4b0

{"data":[{"key":"fashion","label":{"nl":"Mode","en":"Fashion"}},{"key":"home_living","label":{"nl":"Wonen","en":"Home & living"}},{"key":"electronics","label":{"nl":"Elektronica","en":"Electronics"}},{"key":"beauty_health","label":{"nl":"Beauty & gezondheid","en":"Beauty & health"}},{"key":"sports_outdoor","label":{"nl":"Sport & outdoor","en":"Sports & outdoor"}},{"key":"kids_toys","label":{"nl":"Kinderen & speelgoed","en":"Kids & toys"}},{"key":"food_drinks","label":{"nl":"Eten & drinken","en":"Food & drinks"}},{"key":"books_media","label":{"nl":"Boeken & media","en":"Books & media"}},{"key":"garden_diy","label":{"nl":"Tuin & klussen","en":"Garden & DIY"}},{"key":"pets","label":{"nl":"Dieren","en":"Pets"}},{"key":"travel_leisure","label":{"nl":"Reizen & vrije tijd","en":"Travel & leisure"}},{"key":"other","label":{"nl":"Overig","en":"Other"}}]}
```

**All listed webshops**

`GET /api/v1/public/webshops?per_page=10`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-6b82-7111-9de1-de582cdc3b91

{"data":[{"slug":"fietsen-utrecht","name":"Fietsen Utrecht","short_description":"Fietsen, onderdelen en reparatie in Utrecht","logo_url":"http:\/\/127.0.0.1:8788\/api\/v1\/public\/webshops\/fietsen-utrecht\/logo?v=1792785345","categories":[{"key":"sports_outdoor","label":{"nl":"Sport & outdoor","en":"Sports & outdoor"}}],"offer_count":1,"featured_offer":{"slug":"eur10-korting-op-je-volgende-fiets-9taast","title":"\u20ac10 korting op je volgende fiets","summary":"\u20ac10.00 from \u20ac50.00","how_to_obtain":"earn_with_purchase","discount_type":"fixed","discount_value":1000,"currency":"EUR","min_order_minor":5000,"price_minor":null,"ends_at":null,"availability":"available","webshop":{"slug":"fietsen-utrecht","name":"Fietsen Utrecht"}}},{"slug":"woon-co","name":"Woon & Co","short_description":"Meubels en woonaccessoires","logo_url":null,"categories":[{"key":"home_living","label":{"nl":"Wonen","en":"Home & living"}}],"offer_count":1,"featured_offer":{"slug":"15-op-meubels-0wvxcf","title":"15% op meubels","summary":"15%, maximum \u20ac50.00","how_to_obtain":"earn_with_purchase","discount_type":"percentage","discount_value":1500,"currency":"EUR","min_order_minor":null,"price_minor":null,"ends_at":null,"availability":"sold_out","webshop":{"slug":"woon-co","name":"Woon & Co"}}}],"meta":{"current_page":1,"last_page":1,"per_page":10,"total":2}}
```

**Search + only webshops with an obtainable offer**

`GET /api/v1/public/webshops?search=fiets&has_offers=1`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-6bc1-704f-a1aa-58535673ab87

{"data":[{"slug":"fietsen-utrecht","name":"Fietsen Utrecht","short_description":"Fietsen, onderdelen en reparatie in Utrecht","logo_url":"http:\/\/127.0.0.1:8788\/api\/v1\/public\/webshops\/fietsen-utrecht\/logo?v=1792785345","categories":[{"key":"sports_outdoor","label":{"nl":"Sport & outdoor","en":"Sports & outdoor"}}],"offer_count":1,"featured_offer":{"slug":"eur10-korting-op-je-volgende-fiets-9taast","title":"\u20ac10 korting op je volgende fiets","summary":"\u20ac10.00 from \u20ac50.00","how_to_obtain":"earn_with_purchase","discount_type":"fixed","discount_value":1000,"currency":"EUR","min_order_minor":5000,"price_minor":null,"ends_at":null,"availability":"available","webshop":{"slug":"fietsen-utrecht","name":"Fietsen Utrecht"}}}],"meta":{"current_page":1,"last_page":1,"per_page":20,"total":1}}
```

**Unknown category → 422**

`GET /api/v1/public/webshops?category=weapons`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a55-6bfc-70bc-a404-8ba1ea474f03
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a55-6bfc-70bc-a404-8ba1ea474f03","fields":{"category":["The selected category is invalid."]}}}
```

**Webshop detail**

`GET /api/v1/public/webshops/fietsen-utrecht`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-6c23-73bc-a2d4-69ccb3cff3c7

{"data":{"slug":"fietsen-utrecht","name":"Fietsen Utrecht","short_description":"Fietsen, onderdelen en reparatie in Utrecht","logo_url":"http:\/\/127.0.0.1:8788\/api\/v1\/public\/webshops\/fietsen-utrecht\/logo?v=1792785345","categories":[{"key":"sports_outdoor","label":{"nl":"Sport & outdoor","en":"Sports & outdoor"}}],"description":"Familiebedrijf sinds 1988.","website_url":"https:\/\/fietsen-utrecht.nl","offers":[{"slug":"eur10-korting-op-je-volgende-fiets-9taast","title":"\u20ac10 korting op je volgende fiets","summary":"\u20ac10.00 from \u20ac50.00","how_to_obtain":"earn_with_purchase","discount_type":"fixed","discount_value":1000,"currency":"EUR","min_order_minor":5000,"price_minor":null,"ends_at":null,"availability":"available","webshop":{"slug":"fietsen-utrecht","name":"Fietsen Utrecht"}}]}}
```

**Unknown or unlisted webshop → 404**

`GET /api/v1/public/webshops/bestaat-niet`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16a55-6c52-73d0-b824-177c21fce736
Content-Type: application/json

{"error":{"status":404,"code":"webshop_not_found","message":"This webshop is not available.","request_id":"01a16a55-6c52-73d0-b824-177c21fce736"}}
```

**All offers**

`GET /api/v1/public/offers`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-6ccc-71c4-a30e-761d7c9dcc3e

{"data":[{"slug":"eur10-korting-op-je-volgende-fiets-9taast","title":"\u20ac10 korting op je volgende fiets","summary":"\u20ac10.00 from \u20ac50.00","how_to_obtain":"earn_with_purchase","discount_type":"fixed","discount_value":1000,"currency":"EUR","min_order_minor":5000,"price_minor":null,"ends_at":null,"availability":"available","webshop":{"slug":"fietsen-utrecht","name":"Fietsen Utrecht"}},{"slug":"15-op-meubels-0wvxcf","title":"15% op meubels","summary":"15%, maximum \u20ac50.00","how_to_obtain":"earn_with_purchase","discount_type":"percentage","discount_value":1500,"currency":"EUR","min_order_minor":null,"price_minor":null,"ends_at":null,"availability":"sold_out","webshop":{"slug":"woon-co","name":"Woon & Co"}}],"meta":{"current_page":1,"last_page":1,"per_page":20,"total":2}}
```

**Only obtainable offers**

`GET /api/v1/public/offers?available=1`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-6cff-72bc-a09b-7c72ba68fd62

{"data":[{"slug":"eur10-korting-op-je-volgende-fiets-9taast","title":"\u20ac10 korting op je volgende fiets","summary":"\u20ac10.00 from \u20ac50.00","how_to_obtain":"earn_with_purchase","discount_type":"fixed","discount_value":1000,"currency":"EUR","min_order_minor":5000,"price_minor":null,"ends_at":null,"availability":"available","webshop":{"slug":"fietsen-utrecht","name":"Fietsen Utrecht"}}],"meta":{"current_page":1,"last_page":1,"per_page":20,"total":1}}
```

**Offer detail**

`GET /api/v1/public/offers/eur10-korting-op-je-volgende-fiets-9taast`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a55-6d33-70b4-8f10-577a9c384c33

{"data":{"slug":"eur10-korting-op-je-volgende-fiets-9taast","title":"\u20ac10 korting op je volgende fiets","summary":"\u20ac10.00 from \u20ac50.00","how_to_obtain":"earn_with_purchase","discount_type":"fixed","discount_value":1000,"currency":"EUR","min_order_minor":5000,"price_minor":null,"ends_at":null,"availability":"available","webshop":{"slug":"fietsen-utrecht","name":"Fietsen Utrecht"},"description":"Geldig op alle fietsen en accessoires.","max_discount_minor":null,"starts_at":null,"redemption_validity_days":90,"categories":[],"products":[],"combinable":false,"terms_version":1}}
```

**Unknown, paused or ended offer → 404**

`GET /api/v1/public/offers/bestaat-niet-abc123`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16a55-6d63-7075-b3d5-8959ec9bde0d
Content-Type: application/json

{"error":{"status":404,"code":"offer_not_found","message":"This offer is not available.","request_id":"01a16a55-6d63-7075-b3d5-8959ec9bde0d"}}
```

**Logo** (`GET /api/v1/public/webshops/fietsen-utrecht/logo`): binary, response headers only:

```http
HTTP/1.1 200 OK
Content-Type: image/png
Cache-Control: max-age=86400, public
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'
Content-Length: 471
Content-Disposition: inline; filename=logo
X-Request-Id: 01a16a55-6c99-72a5-9d81-c544a8284c54
```

---

## Consumer: profile, preferences, benefits, privacy (B08b, Trello #10, #18)

Captured 2026-10-23 against local MySQL + Redis. Anna (`anna@example.nl`) is verified, opted out of marketing at registration, and owns one issued Answer24 voucher of *Fietsen Utrecht*. Emails went to the log mailer. The email-change code was read from the log, and the log was truncated afterwards. The code is shown as `<code from the email>`.

**Profile**

`GET /api/v1/consumer/profile`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-b30d-7147-b0a2-fe9356d930ef

{"data":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna@example.nl","email_verification":"verified","pending_email":null,"phone":null,"locale":"nl","avatar_url":null,"security":{"email_verified":true,"two_factor":"not_available"},"notifications":{"vouchers_discounts":false,"reminders":true,"offers_inspiration":false}}}
```

**Update name, phone (normalised), locale**

`PATCH /api/v1/consumer/profile {"first_name":"Anna","phone":"06 1234 5678","locale":"nl"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-b478-73f4-bb4d-ad8849a4428f

{"data":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna@example.nl","email_verification":"verified","pending_email":null,"phone":"+31612345678","locale":"nl","avatar_url":null,"security":{"email_verified":true,"two_factor":"not_available"},"notifications":{"vouchers_discounts":false,"reminders":true,"offers_inspiration":false}}}
```

**Invalid name/phone → 422, nothing saved**

`PATCH /api/v1/consumer/profile {"first_name":"<b>Anna</b>","phone":"abc"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a61-b5bd-71ff-b7d4-17354bf1fe35
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a61-b5bd-71ff-b7d4-17354bf1fe35","fields":{"first_name":["The first name field format is invalid."]}}}
```

**Photo upload: SVG refused (multipart `avatar=@logo.svg`)**

`POST /api/v1/consumer/profile/avatar (multipart: avatar=@logo.svg;type=image/svg+xml)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a61-b651-718c-94c8-3e346246af29
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a61-b651-718c-94c8-3e346246af29","fields":{"avatar":["The avatar field must be a file of type: png, jpg, jpeg, webp.","The avatar field must be a file of type: image\/png, image\/jpeg, image\/webp."]}}}
```

**Photo upload: JPEG (multipart `avatar=@avatar.jpg`)**

`POST /api/v1/consumer/profile/avatar (multipart: avatar=@avatar.jpg;type=image/jpeg)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-b71a-736d-af6e-87ec56896aa3

{"data":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna@example.nl","email_verification":"verified","pending_email":null,"phone":"+31612345678","locale":"nl","avatar_url":"http:\/\/127.0.0.1:8789\/api\/v1\/consumer\/profile\/avatar?v=00819dc8","security":{"email_verified":true,"two_factor":"not_available"},"notifications":{"vouchers_discounts":false,"reminders":true,"offers_inspiration":false}}}
```

**Remove photo**

`DELETE /api/v1/consumer/profile/avatar`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-b87a-72da-ab80-d801074dd3bc

{"data":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna@example.nl","email_verification":"verified","pending_email":null,"phone":"+31612345678","locale":"nl","avatar_url":null,"security":{"email_verified":true,"two_factor":"not_available"},"notifications":{"vouchers_discounts":false,"reminders":true,"offers_inspiration":false}}}
```

**Email change with a wrong password → 422**

`POST /api/v1/consumer/email-change {"email":"anna.nieuw@example.nl","current_password":"fout"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a61-b95b-70f5-9138-af0a175c35b7
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a61-b95b-70f5-9138-af0a175c35b7","fields":{"current_password":["The provided password is incorrect."]}}}
```

**Email change requested (code to the new address)**

`POST /api/v1/consumer/email-change {"email":"anna.nieuw@example.nl","current_password":"Sterk-wachtwoord-2026"}`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a16a61-bd66-704b-9ba7-6fc652a6615b

{"data":{"pending_email":"anna.nieuw@example.nl","expires_at":"2026-10-23T20:19:13+00:00","message":"Enter the code we sent to the new address. Your current address stays active until then."}}
```

**Again within the cooldown → 429**

`POST /api/v1/consumer/email-change {"email":"anna.nieuw@example.nl","current_password":"Sterk-wachtwoord-2026"}`

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 58
X-Request-Id: 01a16a61-c331-7044-91ef-8e2ba23288c9
Content-Type: application/json

{"error":{"status":429,"code":"verification_resend_too_soon","message":"Please wait before requesting a new code.","request_id":"01a16a61-c331-7044-91ef-8e2ba23288c9","details":{"retry_after_seconds":58}}}
```

**Profile while the change is pending (still the old, verified address)**

`GET /api/v1/consumer/profile`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-c730-7159-b7dd-f3e1f681ec0f

{"data":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna@example.nl","email_verification":"verified","pending_email":"anna.nieuw@example.nl","phone":"+31612345678","locale":"nl","avatar_url":null,"security":{"email_verified":true,"two_factor":"not_available"},"notifications":{"vouchers_discounts":false,"reminders":true,"offers_inspiration":false}}}
```

**Wrong code → 422**

`POST /api/v1/consumer/email-change/confirm {"code":"000000"}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a61-c806-7096-bb13-e911f00aa5ab
Content-Type: application/json

{"error":{"status":422,"code":"verification_code_invalid","message":"This code is not valid.","request_id":"01a16a61-c806-7096-bb13-e911f00aa5ab"}}
```

**Correct code → email replaced**

`POST /api/v1/consumer/email-change/confirm {"code":"<code from the email>"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-c8b4-7149-956b-12c136783b74

{"data":{"email":"anna.nieuw@example.nl","email_verification":"verified"}}
```

**Same code again → 409**

`POST /api/v1/consumer/email-change/confirm {"code":"<code from the email>"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16a61-c9cc-70b4-aa4d-892a76697f4d
Content-Type: application/json

{"error":{"status":409,"code":"email_change_not_pending","message":"There is no pending email change.","request_id":"01a16a61-c9cc-70b4-aa4d-892a76697f4d"}}
```

**Cancel (nothing pending)**

`DELETE /api/v1/consumer/email-change`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-caa5-73d0-a3d6-2b7566765f85

{"data":{"pending_email":null}}
```

**Preferences**

`GET /api/v1/consumer/preferences`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-cb31-719a-b2f4-691335d050fa

{"data":{"notifications":{"vouchers_discounts":false,"reminders":true,"offers_inspiration":false},"categories":[],"mandatory_mail":"Account, security and transaction emails are always sent and do not depend on these settings."}}
```

**Change switches and categories**

`PUT /api/v1/consumer/preferences {"notifications":{"vouchers_discounts":true,"reminders":true,"offers_inspiration":false},"categories":["sports_outdoor","pets"]}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-cbc0-710f-82fb-4a2e7548949b

{"data":{"notifications":{"vouchers_discounts":true,"reminders":true,"offers_inspiration":false},"categories":["pets","sports_outdoor"],"mandatory_mail":"Account, security and transaction emails are always sent and do not depend on these settings."}}
```

**Unknown switch → 422**

`PUT /api/v1/consumer/preferences {"notifications":{"sms":true}}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a61-cca9-7198-9df7-e212cc8c58f0
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a61-cca9-7198-9df7-e212cc8c58f0","fields":{"notifications":["Unknown notification setting. Allowed: vouchers_discounts, reminders, offers_inspiration."]}}}
```

**Favourite a webshop**

`PUT /api/v1/consumer/favorites/fietsen-utrecht`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16a61-cd41-709a-abe3-2469564539bb

{"data":[{"slug":"fietsen-utrecht","name":"Fietsen Utrecht","short_description":"Fietsen, onderdelen en reparatie","logo_url":null,"categories":[{"key":"sports_outdoor","label":{"nl":"Sport & outdoor","en":"Sports & outdoor"}}]}]}
```

**Unknown webshop → 404**

`PUT /api/v1/consumer/favorites/bestaat-niet`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16a61-cdfd-73b8-808f-0a88fe276fb1
Content-Type: application/json

{"error":{"status":404,"code":"webshop_not_found","message":"This webshop is not available.","request_id":"01a16a61-cdfd-73b8-808f-0a88fe276fb1"}}
```

**Favourites**

`GET /api/v1/consumer/favorites`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-cea1-7315-b32e-b5d7882c17e8

{"data":[{"slug":"fietsen-utrecht","name":"Fietsen Utrecht","short_description":"Fietsen, onderdelen en reparatie","logo_url":null,"categories":[{"key":"sports_outdoor","label":{"nl":"Sport & outdoor","en":"Sports & outdoor"}}]}]}
```

**My Vouchers** (re-captured in B11b: codes are masked in the list)

`GET /api/v1/consumer/vouchers?filter=active`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-241a-710c-a808-20da117a3890

{"data":[{"id":"01m5pdr4zv96djgyr0ybmg0j51","status":"issued","origin":"wallet_purchase","code_masked":"\u2022\u2022\u2022\u202225-C","webshop":{"name":"Woon & Co","slug":null},"title":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","min_order_minor":10000,"issued_at":"2026-10-24T07:42:05+00:00","expires_at":"2027-01-22T07:42:05+00:00","redeemed_at":null},{"id":"01m5pd8g6wwgnnb2qdty25kc2p","status":"issued","origin":"wallet_purchase","code_masked":"\u2022\u2022\u2022\u202225-B","webshop":{"name":"Woon & Co","slug":null},"title":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","min_order_minor":10000,"issued_at":"2026-10-24T07:33:32+00:00","expires_at":"2027-01-22T07:33:32+00:00","redeemed_at":null},{"id":"01m5pd8g05ak30hy67ndss1s4v","status":"issued","origin":"wallet_purchase","code_masked":"\u2022\u2022\u2022\u202225-A","webshop":{"name":"Woon & Co","slug":null},"title":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","min_order_minor":10000,"issued_at":"2026-10-24T07:33:31+00:00","expires_at":"2027-01-22T07:33:31+00:00","redeemed_at":null},{"id":"01m5n639gjt0r0h2rbvj3aegm3","status":"issued","origin":"answer24_claim","code_masked":"\u2022\u2022\u2022\u20227Q2K","webshop":{"name":"Fietsen Utrecht","slug":"fietsen-utrecht"},"title":"\u20ac10 korting op je volgende fiets","summary":"\u20ac10.00 from \u20ac50.00","min_order_minor":5000,"issued_at":"2026-10-23T20:09:06+00:00","expires_at":"2027-01-21T20:09:06+00:00","redeemed_at":null}]}
```

**Unknown filter → 422**

`GET /api/v1/consumer/vouchers?filter=reserved`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16cdc-2459-71c9-a85d-02d5d6727b94
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16cdc-2459-71c9-a85d-02d5d6727b94","fields":{"filter":["The selected filter is invalid."]}}}
```

**Dashboard** (re-captured in B11b)

`GET /api/v1/consumer/dashboard`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-24c1-70cb-a9ce-d4d17570d3b3

{"data":{"first_name":"Anna","email_verified":true,"wallet":{"eur_credit":{"unit":"eur_credit","available":0,"pending":0,"reserved":0,"total_earned":3100,"total_spent":3100,"total_expired":0,"next_expiry":null},"points":{"unit":"points","available":0,"pending":0,"reserved":0,"total_earned":0,"total_spent":0,"total_expired":0,"next_expiry":null}},"vouchers":{"issued":4,"redeemed":0,"expiring_within_30_days":0},"recent_vouchers":[{"id":"01m5pdr4zv96djgyr0ybmg0j51","status":"issued","origin":"wallet_purchase","code_masked":"\u2022\u2022\u2022\u202225-C","webshop":{"name":"Woon & Co","slug":null},"title":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","min_order_minor":10000,"issued_at":"2026-10-24T07:42:05+00:00","expires_at":"2027-01-22T07:42:05+00:00","redeemed_at":null},{"id":"01m5pd8g6wwgnnb2qdty25kc2p","status":"issued","origin":"wallet_purchase","code_masked":"\u2022\u2022\u2022\u202225-B","webshop":{"name":"Woon & Co","slug":null},"title":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","min_order_minor":10000,"issued_at":"2026-10-24T07:33:32+00:00","expires_at":"2027-01-22T07:33:32+00:00","redeemed_at":null},{"id":"01m5pd8g05ak30hy67ndss1s4v","status":"issued","origin":"wallet_purchase","code_masked":"\u2022\u2022\u2022\u202225-A","webshop":{"name":"Woon & Co","slug":null},"title":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","min_order_minor":10000,"issued_at":"2026-10-24T07:33:31+00:00","expires_at":"2027-01-22T07:33:31+00:00","redeemed_at":null}],"favorites":[]}}
```

**Data export**

`GET /api/v1/consumer/privacy/export`

```http
HTTP/1.1 200 OK
Content-Disposition: attachment; filename="shopbonus-data-2026-10-23.json"
Content-Type: application/json
X-Request-Id: 01a16a61-d17c-723f-9899-75ab36d79f32

{"data":{"exported_at":"2026-10-23T20:09:17+00:00","account":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl","phone":"+31612345678","locale":"nl","status":"active","email_verified_at":"2026-10-23T20:09:15+00:00","created_at":"2026-10-23T20:09:06+00:00","last_login_at":null,"has_profile_photo":false},"consents":[{"purpose":"marketing_email","granted":false,"policy_version":"dev-2026-10-23","source":"registration","recorded_at":"2026-10-23T20:09:06+00:00"},{"purpose":"marketing_email","granted":true,"policy_version":"marketing-2026-10","source":"profile","recorded_at":"2026-10-23T20:09:16+00:00"}],"legal_acceptances":[],"vouchers":[{"id":"01m5n639gjt0r0h2rbvj3aegm3","status":"issued","origin":"answer24_claim","code":"FIETS-7Q2K","title":"\u20ac10 korting op je volgende fiets","issued_at":"2026-10-23T20:09:06+00:00","redeemed_at":null}],"favorite_webshops":["Fietsen Utrecht"],"preferred_categories":["pets","sports_outdoor"],"workspace_memberships":[],"privacy_requests":[]}}
```

**Deletion request with a wrong password → 422**

`POST /api/v1/consumer/privacy/deletion-request {"current_password":"fout","confirm":true}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a61-d259-7214-a24d-e7c9e0a534c4
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a61-d259-7214-a24d-e7c9e0a534c4","fields":{"current_password":["The provided password is incorrect."]}}}
```

**Deletion request recorded**

`POST /api/v1/consumer/privacy/deletion-request {"current_password":"Sterk-wachtwoord-2026","confirm":true}`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a16a61-d66e-724b-ac55-0f5b2aa6e22a

{"data":{"id":"01m5n63pn10404cq8kqwab9zba","type":"deletion","status":"open","requested_at":"2026-10-23T20:09:20+00:00","completed_at":null,"message":"Your request was recorded and will be handled by Shopbonus. Records we must keep by law (vouchers, payments, audit) are retained for their legal period."}}
```

**Second request → 409**

`POST /api/v1/consumer/privacy/deletion-request {"current_password":"Sterk-wachtwoord-2026","confirm":true}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16a61-e416-71c0-a2dc-f0536c5e6ad6
Content-Type: application/json

{"error":{"status":409,"code":"deletion_already_requested","message":"A deletion request is already being processed.","request_id":"01a16a61-e416-71c0-a2dc-f0536c5e6ad6"}}
```

**Privacy requests**

`GET /api/v1/consumer/privacy/requests`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-e837-709b-97d0-486554739589

{"data":[{"id":"01m5n63pn10404cq8kqwab9zba","type":"deletion","status":"open","requested_at":"2026-10-23T20:09:20+00:00","completed_at":null}]}
```

**Remove favourite**

`DELETE /api/v1/consumer/favorites/fietsen-utrecht`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-e8e3-7063-9442-5faae32472ba

{"data":[]}
```

**Not signed in → 401**

`GET /api/v1/consumer/profile`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a16a61-e97f-707e-a1aa-cb3eb61e4c12
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a16a61-e97f-707e-a1aa-cb3eb61e4c12"}}
```

**Password change: sixth attempt within a minute → 429 (session flow; first five had a wrong current password)**

`PUT /user/password {"current_password":"fout","password":"Nieuw-wachtwoord-2027"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 54
X-Request-Id: 01a16a62-0acc-7050-a1ef-73742f0652fa
Content-Type: application/json

{"error":{"status":429,"code":"too_many_requests","message":"Too many requests. Please slow down.","request_id":"01a16a62-0acc-7050-a1ef-73742f0652fa"}}
```

**Own photo** (`GET /api/v1/consumer/profile/avatar`): binary, response headers only:

```http
HTTP/1.1 200 OK
Content-Type: image/jpeg
Cache-Control: max-age=300, private
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'
Content-Length: 1593
Content-Disposition: inline; filename=avatar
X-Request-Id: 01a16a61-b807-71bc-9f12-86252cbbef65
```

---

## Wallet: central non-cash ledger (B09, Trello #19, #23)

Captured 2026-10-23 against local MySQL. Anna's euro-credit wallet received a €12.20 credit (affiliate conversion `conv-8841`) and a €3.00 pending credit, then spent €10.00 on a voucher purchase through `WalletService::debit`. Bob has his own wallet. All amounts are integer cents (`eur_credit`) or integer points (`points`), and the two are never converted.

Ledger rules:

- Every change is one append-only entry, blocked from updates and deletes in the model and by database triggers.
- Every operation is idempotent per key, and balances can never go negative: the service refuses, and the unsigned columns refuse too.
- Credits are lots valid for 12 months; the oldest-expiring lot is spent first.
- A reversal only takes the unspent part of a credit; any remainder is flagged in `wallet_reconciliation_flags`.
- Housekeeping and proof run on a schedule: `shopbonus:wallet-expire` every five minutes, and `shopbonus:wallet-reconcile` nightly. Captured output:

```
All wallets reconcile.
Expired 0 credit lot(s); released 0 reservation(s).
```

**Balances**

`GET /api/v1/consumer/wallet`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a6f-aad2-7027-ac88-0105508fde52

{"data":{"balances":{"eur_credit":{"unit":"eur_credit","available":220,"pending":300,"reserved":0,"total_earned":1220,"total_spent":1000,"total_expired":0,"next_expiry":{"amount":220,"at":"2027-10-23T20:24:21+00:00"}},"points":{"unit":"points","available":0,"pending":0,"reserved":0,"total_earned":0,"total_spent":0,"total_expired":0,"next_expiry":null}},"withdrawable":false,"notice":"ShopBonus credit and points are not money: they cannot be withdrawn or transferred."}}
```

**History**

`GET /api/v1/consumer/wallet/transactions`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a6f-acbe-707d-8c08-00af4f059687

{"data":[{"id":"01m5n6z742bdngd1zmms9g23tm","type":"debit","amount":-1000,"unit":"eur_credit","available_after":220,"source":{"type":"voucher_purchase","ref":"demo"},"description":null,"expires_at":null,"created_at":"2026-10-23T20:24:21+00:00"},{"id":"01m5n6z73t0yzvtspczc4tp4er","type":"reserve","amount":0,"unit":"eur_credit","available_after":220,"source":{"type":"voucher_purchase","ref":"demo"},"description":null,"expires_at":null,"created_at":"2026-10-23T20:24:21+00:00"},{"id":"01m5n6z72892ybxx5vkqcpexsa","type":"credit","amount":1220,"unit":"eur_credit","available_after":1220,"source":{"type":"affiliate","ref":"conv-8841"},"description":"Purchase at Fietsen Utrecht","expires_at":"2027-10-23T20:24:21+00:00","created_at":"2026-10-23T20:24:21+00:00"}],"meta":{"current_page":1,"last_page":1,"per_page":25,"total":3}}
```

**Pending (not spendable)**

`GET /api/v1/consumer/wallet/transactions?filter=pending`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a6f-add6-72c4-bfb0-b08a61aae675

{"data":[{"id":"01m5n6z7383xyt8nd7g8jpv96v","type":"pending_credit","status":"pending","amount":300,"unit":"eur_credit","description":"Purchase at Woon & Co (awaiting confirmation)","source":{"type":"affiliate","ref":"conv-8842"},"spendable":false,"created_at":"2026-10-23T20:24:21+00:00"}],"meta":{"current_page":1,"last_page":1,"per_page":25,"total":1}}
```

**Unknown filter → 422**

`GET /api/v1/consumer/wallet/transactions?filter=cash`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a6f-ae82-7212-855f-7c1d4b1686f3
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a6f-ae82-7212-855f-7c1d4b1686f3","fields":{"filter":["The selected filter is invalid."]}}}
```

**One entry**

`GET /api/v1/consumer/wallet/transactions/01m5n6z72892ybxx5vkqcpexsa`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a6f-af1b-73d3-beea-a6933cc6506c

{"data":{"id":"01m5n6z72892ybxx5vkqcpexsa","type":"credit","amount":1220,"unit":"eur_credit","available_after":1220,"source":{"type":"affiliate","ref":"conv-8841"},"description":"Purchase at Fietsen Utrecht","expires_at":"2027-10-23T20:24:21+00:00","created_at":"2026-10-23T20:24:21+00:00","remaining":220,"related_to":null}}
```

**Another consumer's entry → 404**

`GET /api/v1/consumer/wallet/transactions/01m5n6z74p2ew6yvsw8ee30s79`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16a6f-af92-704c-bcf6-ce5167d38dc4
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"Transaction not found.","request_id":"01a16a6f-af92-704c-bcf6-ce5167d38dc4"}}
```

**Not signed in → 401**

`GET /api/v1/consumer/wallet`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a16a6f-b001-7195-a525-f98115037cb5
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a16a6f-b001-7195-a525-f98115037cb5"}}
```

---

## Affiliate tracking and rewards (B10, Trello #19, #23, #24)

Captured 2026-10-24 against local MySQL. **The Daisycon and TradeTracker adapters are NOT VERIFIED.** There are no network accounts, so:
- Daisycon was pointed at a local stub that rejects every token;
- TradeTracker data came through the admin import, in TradeTracker's export field names.

Admin calls ran in a real browser session as a Super Admin, after `POST /user/confirm-password` (step-up). The consumer is Anna, using a bearer token. Reward rule: 50% of commission as `eur_credit`, at most €25, with admin review above €50.

Credit timing is the owner's safe default (`AFFILIATE_CREDIT_TIMING=on_network_approval`): a reward is pending until the network approves. `on_validated_attribution` (card #23 instant credit) exists and is tested, but is off.

**Invalid credentials → 422**

`POST /api/v1/admin/affiliate/connections {"network":"daisycon","name":"Daisycon NL","credentials":{"publisher_id":"abc"}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16cc9-6136-70a1-8b19-98be08e2e621
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16cc9-6136-70a1-8b19-98be08e2e621","fields":{"publisher_id":["The publisher id field format is invalid."],"access_token":["The access token field is required."],"media_id":["The media id field is required."]}}}
```

**Connect Daisycon (stub rejects the token)**

`POST /api/v1/admin/affiliate/connections {"network":"daisycon","name":"Daisycon NL","credentials":{"publisher_id":"381234","access_token":"<oauth token>","media_id":"55001"}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16cc9-6197-7242-82cc-808298491375

{"data":{"id":"01m5pcjrd3segw8wk28cdw83nq","network":"daisycon","name":"Daisycon NL","status":"reauthentication_required","last_synced_at":null,"last_error":"credentials_rejected","verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES).","postback_url":"http:\/\/127.0.0.1:8791\/api\/v1\/affiliate\/postback\/DJrIyfrq4IUdzJS5sQd7NhyRENcRYVCa0St2lAQw","postback_url_notice":"Shown once. Postbacks can only record conversions as tracked; rewards need the network API or an import."}}
```

**Connect TradeTracker (limited: no verified transport)**

`POST /api/v1/admin/affiliate/connections {"network":"tradetracker","name":"TradeTracker NL","credentials":{"customer_id":"98765","passphrase":"<passphrase>","site_id":"441"}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16cc9-621c-72f6-87fd-d659d08c91d7

{"data":{"id":"01m5pcjrhbp0asmpwtj19hjnzk","network":"tradetracker","name":"TradeTracker NL","status":"limited","last_synced_at":null,"last_error":"soap_transport_not_verified","verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES).","postback_url":"http:\/\/127.0.0.1:8791\/api\/v1\/affiliate\/postback\/<postback token>","postback_url_notice":"Shown once. Postbacks can only record conversions as tracked; rewards need the network API or an import."}}
```

**Reward rule**

`POST /api/v1/admin/affiliate/reward-rules {"unit":"eur_credit","share_bps":5000,"min_commission_minor":50,"max_reward_minor":2500,"review_above_minor":5000}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16cc9-62dc-7231-b110-76f2dd128de0

{"data":{"version":1,"unit":"eur_credit","share_bps":5000,"min_commission_minor":50,"max_reward_minor":2500,"review_above_minor":5000,"starts_at":null,"ends_at":null,"active":true}}
```

**Import TradeTracker programs (auto-matched on the verified domain)**

`POST /api/v1/admin/affiliate/connections/01m5pcjrhbp0asmpwtj19hjnzk/import {"programs":[{"ID":"T1","name":"Fietsen Utrecht","URL":"https://fietsen-utrecht.nl","info":{"assignmentStatus":"accepted","policyCashbackStatus":true,"commission":{"saleCommissionVariable":"8","categoryRestricted":false},"trackingURL":"https://tc.tradetracker.net/?c=1&m=2&a=3"}}]}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-63d2-7137-a116-f234b080614b

{"data":{"programs":1,"transactions":0,"rejected":[]}}
```

**Programs**

`GET /api/v1/admin/affiliate/programs    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-6424-7001-bb52-081562c0b8e8

{"data":[{"id":"01m5pcjrwbh2tssdty9kg3nsx5","network":"daisycon","external_id":"P1","name":"Fietsen Utrecht","domain":"www.fietsen-utrecht.nl","webshop_id":"01m5n6399tnc5h1vtfyf3gsgv4","active":true,"commission":{"type":"percentage","value":600,"currency":"EUR","category_restricted":false},"cashback_allowed":true},{"id":"01m5pcjryx25vs5fc4g1rn3wx5","network":"tradetracker","external_id":"T1","name":"Fietsen Utrecht","domain":"fietsen-utrecht.nl","webshop_id":"01m5n6399tnc5h1vtfyf3gsgv4","active":true,"commission":{"type":"percentage","value":800,"currency":"EUR","category_restricted":false},"cashback_allowed":true}]}
```

**Run selection**

`POST /api/v1/admin/affiliate/webshops/01m5n6399tnc5h1vtfyf3gsgv4/selections    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-6471-70fd-8c4b-36848102e382

{"data":{"program_id":"01m5pcjryx25vs5fc4g1rn3wx5","method":"single","limitation":null,"candidates":[{"network":"tradetracker","program":"01m5pcjryx25vs5fc4g1rn3wx5","currency":"EUR","commission_type":"percentage","commission_value":800,"category_restricted":false,"expected_commission_minor":400}],"rule_version":"2026-10-23.1","at":"2026-10-24 07:21:40"}}
```

**Visit webshop as Anna** (`GET /api/v1/public/webshops/fietsen-utrecht/visit`, bearer token): headers only:

```http
HTTP/1.1 302 Found
Location: https://tc.tradetracker.net/?c=1&m=2&a=3&r=<click reference>
X-Request-Id: 01a16cc9-64e8-720d-a226-828790cde6f9
```

**Import two pending conversions (one with Anna’s click reference, one without)**

`POST /api/v1/admin/affiliate/connections/01m5pcjrhbp0asmpwtj19hjnzk/import {"transactions":[{"ID":"TT-1001","campaign":{"ID":"T1"},"reference":"<click reference>","orderID":"ORD-1","orderAmount":"79.95","commission":"6.40","transactionStatus":"pending","registrationDate":"2026-10-24T07:21:40Z","assessmentDate":"2026-10-24T08:00:00Z"},{"ID":"TT-1002","campaign":{"ID":"T1"},"reference":"","orderID":"ORD-2","orderAmount":"79.95","commission":"3.20","transactionStatus":"pending","registrationDate":"2026-10-24T07:21:40Z","assessmentDate":"2026-10-24T08:00:00Z"}]}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-6559-71e6-8e5e-b20bf8ef9704

{"data":{"programs":0,"transactions":2,"rejected":[]}}
```

**Anna: tracked purchase, reward pending**

`GET /api/v1/consumer/affiliate-activity`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-659d-7240-ac51-df6481dd2270

{"data":[{"id":"01m5pcjsb9az5awhgh6s41sbdd","webshop":"Fietsen Utrecht","purchased_at":"2026-10-24T07:21:40+00:00","order_amount_minor":7995,"currency":"EUR","status":"pending","explanation":"The webshop has not confirmed this purchase yet. Your reward is pending and cannot be spent yet.","reward":{"amount":320,"unit":"eur_credit","status":"pending"}}],"meta":{"current_page":1,"last_page":1,"per_page":25,"total":1}}
```

**Anna: pending is not spendable**

`GET /api/v1/consumer/wallet`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-65be-7392-9359-3f0da1b11ab0

{"data":{"balances":{"eur_credit":{"unit":"eur_credit","available":0,"pending":320,"reserved":0,"total_earned":0,"total_spent":0,"total_expired":0,"next_expiry":null},"points":{"unit":"points","available":0,"pending":0,"reserved":0,"total_earned":0,"total_spent":0,"total_expired":0,"next_expiry":null}},"withdrawable":false,"notice":"ShopBonus credit and points are not money: they cannot be withdrawn or transferred."}}
```

**Network approves → credited once**

`POST /api/v1/admin/affiliate/connections/01m5pcjrhbp0asmpwtj19hjnzk/import {"transactions":[{"ID":"TT-1001","campaign":{"ID":"T1"},"reference":"<click reference>","orderID":"ORD-1","orderAmount":"79.95","commission":"6.40","transactionStatus":"accepted","registrationDate":"2026-10-24T07:21:40Z","assessmentDate":"2026-10-24T09:00:00Z"}]}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-660a-72bc-90f7-1f10303cc6a4

{"data":{"programs":0,"transactions":1,"rejected":[]}}
```

**Anna: credited**

`GET /api/v1/consumer/wallet`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-6644-71eb-bcb4-e45c366ac6cd

{"data":{"balances":{"eur_credit":{"unit":"eur_credit","available":320,"pending":0,"reserved":0,"total_earned":320,"total_spent":0,"total_expired":0,"next_expiry":{"amount":320,"at":"2027-10-24T07:21:40+00:00"}},"points":{"unit":"points","available":0,"pending":0,"reserved":0,"total_earned":0,"total_spent":0,"total_expired":0,"next_expiry":null}},"withdrawable":false,"notice":"ShopBonus credit and points are not money: they cannot be withdrawn or transferred."}}
```

**Postback reporting the same click again → disputed duplicate, no reward**

`POST /api/v1/affiliate/postback/<postback token> {"ID":"TT-1003","campaign":{"ID":"T1"},"reference":"<click reference>","orderID":"ORD-3","orderAmount":"79.95","commission":"2.00","transactionStatus":"accepted","registrationDate":"2026-10-24T07:21:40Z","assessmentDate":"2026-10-24T09:00:00Z"}`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a16cc9-6663-71f9-a62a-0082308061df

{"data":{"received":true,"status":"disputed"}}
```

**Unknown postback token → 404**

`POST /api/v1/affiliate/postback/wrong-token {}`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16cc9-6688-7352-bcfd-6f6d16fb0655
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"Unknown postback endpoint.","request_id":"01a16cc9-6688-7352-bcfd-6f6d16fb0655"}}
```

**Conversions**

`GET /api/v1/admin/affiliate/transactions    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-66e3-7312-ba56-3cc9bea594f4

{"data":[{"id":"01m5pcjskennn0zwmtpvm5qs8z","network":"tradetracker","external_id":"TT-1003","status":"disputed","network_status":"approved","review_reason":"duplicate","webshop_id":"01m5n6399tnc5h1vtfyf3gsgv4","consumer_attributed":true,"order_amount_minor":7995,"commission_minor":200,"currency":"EUR","reward":{"status":"none","amount":null,"unit":null,"rule_version":null},"duplicate_of":"01m5pcjsb9az5awhgh6s41sbdd","transaction_at":"2026-10-24T07:21:40+00:00"},{"id":"01m5pcjsbzb23dsxwhh87mr70e","network":"tradetracker","external_id":"TT-1002","status":"unmatched","network_status":"pending","review_reason":"no_click_reference","webshop_id":"01m5n6399tnc5h1vtfyf3gsgv4","consumer_attributed":false,"order_amount_minor":7995,"commission_minor":320,"currency":"EUR","reward":{"status":"none","amount":null,"unit":null,"rule_version":null},"duplicate_of":null,"transaction_at":"2026-10-24T07:21:40+00:00"},{"id":"01m5pcjsb9az5awhgh6s41sbdd","network":"tradetracker","external_id":"TT-1001","status":"approved","network_status":"approved","review_reason":null,"webshop_id":"01m5n6399tnc5h1vtfyf3gsgv4","consumer_attributed":true,"order_amount_minor":7995,"commission_minor":640,"currency":"EUR","reward":{"status":"credited","amount":320,"unit":"eur_credit","rule_version":1},"duplicate_of":null,"transaction_at":"2026-10-24T07:21:40+00:00"}],"meta":{"current_page":1,"last_page":1,"per_page":50,"total":3},"totals":{"approved":{"count":1,"commission_minor":640},"disputed":{"count":1,"commission_minor":200},"unmatched":{"count":1,"commission_minor":320}}}
```

**One conversion with history**

`GET /api/v1/admin/affiliate/transactions/01m5pcjsb9az5awhgh6s41sbdd    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-6768-70d8-ae7d-e86c804a5464

{"data":{"id":"01m5pcjsb9az5awhgh6s41sbdd","network":"tradetracker","external_id":"TT-1001","status":"approved","network_status":"approved","review_reason":null,"webshop_id":"01m5n6399tnc5h1vtfyf3gsgv4","consumer_attributed":true,"order_amount_minor":7995,"commission_minor":640,"currency":"EUR","reward":{"status":"credited","amount":320,"unit":"eur_credit","rule_version":1},"duplicate_of":null,"transaction_at":"2026-10-24T07:21:40+00:00","events":[{"source":"import","from":null,"to":"pending","network_status":"pending","note":null,"at":"2026-10-24T07:21:40+00:00"},{"source":"import","from":"pending","to":"approved","network_status":"approved","note":null,"at":"2026-10-24T07:21:40+00:00"}]}}
```

**Resolving one that is not in review → 409**

`POST /api/v1/admin/affiliate/transactions/01m5pcjsb9az5awhgh6s41sbdd/resolve {"action":"accept","note":"x"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16cc9-67ba-7269-bb33-913f5f8c9735
Content-Type: application/json

{"error":{"status":409,"code":"not_in_review","message":"Only unmatched, disputed or tracked conversions need review.","request_id":"01a16cc9-67ba-7269-bb33-913f5f8c9735","details":{"status":"approved"}}}
```

**Reject the conversion without a click**

`POST /api/v1/admin/affiliate/transactions/01m5pcjsbzb23dsxwhh87mr70e/resolve {"action":"reject","note":"No click reference; customer not identifiable"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-680c-728b-90a9-de7dc8bcf91d

{"data":{"id":"01m5pcjsbzb23dsxwhh87mr70e","network":"tradetracker","external_id":"TT-1002","status":"rejected","network_status":"pending","review_reason":"resolved:reject","webshop_id":"01m5n6399tnc5h1vtfyf3gsgv4","consumer_attributed":false,"order_amount_minor":7995,"commission_minor":320,"currency":"EUR","reward":{"status":"none","amount":null,"unit":null,"rule_version":null},"duplicate_of":null,"transaction_at":"2026-10-24T07:21:40+00:00"}}
```

**TradeTracker sync → limited**

`POST /api/v1/admin/affiliate/connections/01m5pcjrhbp0asmpwtj19hjnzk/sync    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-6862-735c-948c-130e9eec22c4

{"data":{"programs":0,"transactions":0,"error":"TradeTracker sync is not available yet (SOAP transport not verified); use the import.","connection":{"id":"01m5pcjrhbp0asmpwtj19hjnzk","network":"tradetracker","name":"TradeTracker NL","status":"limited","last_synced_at":null,"last_error":"TradeTracker sync is not available yet (SOAP transport not verified); use the import.","verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES)."}}}
```

**Re-test Daisycon**

`POST /api/v1/admin/affiliate/connections/01m5pcjrd3segw8wk28cdw83nq/test    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-68bb-73c0-8846-281702d55a34

{"data":{"id":"01m5pcjrd3segw8wk28cdw83nq","network":"daisycon","name":"Daisycon NL","status":"reauthentication_required","last_synced_at":null,"last_error":"credentials_rejected","verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES)."}}
```

**Withdraw cashback permission for a program**

`PATCH /api/v1/admin/affiliate/programs/01m5pcjrwbh2tssdty9kg3nsx5 {"cashback_allowed":false}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-6935-7319-b9ed-28b84b54e2ca

{"data":{"id":"01m5pcjrwbh2tssdty9kg3nsx5","network":"daisycon","external_id":"P1","name":"Fietsen Utrecht","domain":"www.fietsen-utrecht.nl","webshop_id":"01m5n6399tnc5h1vtfyf3gsgv4","active":true,"commission":{"type":"percentage","value":600,"currency":"EUR","category_restricted":false},"cashback_allowed":false}}
```

**Deactivate rule version 1**

`POST /api/v1/admin/affiliate/reward-rules/1/deactivate    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-6996-72e4-8f20-2966b21dd961

{"data":{"version":1,"unit":"eur_credit","share_bps":5000,"min_commission_minor":50,"max_reward_minor":2500,"review_above_minor":5000,"starts_at":null,"ends_at":null,"active":false}}
```

**Disable a connection**

`POST /api/v1/admin/affiliate/connections/01m5pcjrd3segw8wk28cdw83nq/disable    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cc9-69ec-70cd-a139-863ff729da6e

{"data":{"id":"01m5pcjrd3segw8wk28cdw83nq","network":"daisycon","name":"Daisycon NL","status":"disabled","last_synced_at":null,"last_error":"credentials_rejected","verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES)."}}
```

**Consumer → admin route: 403**

`GET /api/v1/admin/affiliate/connections`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16cc9-6a37-7048-a6fc-67761beca117
Content-Type: application/json

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"01a16cc9-6a37-7048-a6fc-67761beca117"}}
```

---

## Voucher checkout: wallet + Mollie (B11a, Trello #20, #23)

Captured 2026-10-24 against local MySQL. **Mollie was a local test-mode stub** (`MOLLIE_API_BASE_URL` pointed at it); no real Mollie account was used.

The setup:
- *Woon & Co* sells "€25 korting op meubels" for €20.00 (`price_minor` 2000).
- Anna has €31.00 `eur_credit`; Bob has none.
- Prices are euro cents from the `eur_credit` wallet. Points are never converted (D-051).

What Shopbonus sent to Mollie (key redacted):

```json
{
  "headers": {
    "Authorization": "Bearer test_<redacted>"
  },
  "body": {
    "amount": {
      "currency": "EUR",
      "value": "20.00"
    },
    "description": "ShopBonus voucher: €25 korting op meubels",
    "redirectUrl": "http://localhost:3000/checkout/01m5pd8gpxtf8jd7rs1863kk89",
    "webhookUrl": "http://127.0.0.1:8792/api/v1/payments/mollie/webhook",
    "metadata": {
      "checkout_id": "01m5pd8gpxtf8jd7rs1863kk89"
    }
  }
}
```

**Quote: the wallet covers it**

`POST /api/v1/consumer/checkouts/quote {"offer":"eur25-korting-op-meubels-air4ck"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-3f11-72dd-a5ba-64cb088d54a3

{"data":{"price_minor":2000,"currency":"EUR","wallet_available":3100,"wallet_part_minor":2000,"payment_part_minor":0,"blockers":[]}}
```

**Bob's quote without the wallet: all via Mollie**

`POST /api/v1/consumer/checkouts/quote {"offer":"eur25-korting-op-meubels-air4ck","use_wallet":false}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-3fa7-706a-9111-37949fdce6ef

{"data":{"price_minor":2000,"currency":"EUR","wallet_available":0,"wallet_part_minor":0,"payment_part_minor":2000,"blockers":[]}}
```

**Wallet-only purchase: completed in one transaction**

`POST /api/v1/consumer/checkouts {"offer":"eur25-korting-op-meubels-air4ck"}   (Idempotency-Key: checkout-2026-10-24-0001)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16cd4-3fe2-7368-a5d2-1d4a6dc38be7

{"data":{"id":"01m5pd8fzpy18bajpnwcqnkecz","state":"completed","offer":{"slug":"eur25-korting-op-meubels-air4ck","title":"\u20ac25 korting op meubels"},"price_minor":2000,"wallet_part_minor":2000,"payment_part_minor":0,"currency":"EUR","payment":null,"voucher_id":"01m5pd8g05ak30hy67ndss1s4v","failure_reason":null,"expires_at":"2026-10-24T08:03:31+00:00","completed_at":"2026-10-24T07:33:31+00:00"}}
```

**Same Idempotency-Key again: same checkout, no second debit**

`POST /api/v1/consumer/checkouts {"offer":"eur25-korting-op-meubels-air4ck"}   (Idempotency-Key: checkout-2026-10-24-0001)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-4063-717e-86ea-7f6213ad7475

{"data":{"id":"01m5pd8fzpy18bajpnwcqnkecz","state":"completed","offer":{"slug":"eur25-korting-op-meubels-air4ck","title":"\u20ac25 korting op meubels"},"price_minor":2000,"wallet_part_minor":2000,"payment_part_minor":0,"currency":"EUR","payment":null,"voucher_id":"01m5pd8g05ak30hy67ndss1s4v","failure_reason":null,"expires_at":"2026-10-24T08:03:31+00:00","completed_at":"2026-10-24T07:33:31+00:00"}}
```

**No Idempotency-Key → 400**

`POST /api/v1/consumer/checkouts {"offer":"eur25-korting-op-meubels-air4ck"}`

```http
HTTP/1.1 400 Bad Request
X-Request-Id: 01a16cd4-4093-72b1-9983-a1d986b110fc
Content-Type: application/json

{"error":{"status":400,"code":"idempotency_key_required","message":"Send a unique Idempotency-Key header (8\u2013100 characters) with each purchase.","request_id":"01a16cd4-4093-72b1-9983-a1d986b110fc"}}
```

**Mixed: €11.00 wallet (all that was left) + €9.00 Mollie**

`POST /api/v1/consumer/checkouts {"offer":"eur25-korting-op-meubels-air4ck"}   (Idempotency-Key: checkout-2026-10-24-0002)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16cd4-40be-701c-84e9-076b3feb43e7

{"data":{"id":"01m5pd8g6jxyja6prwr6bcwm98","state":"pending_payment","offer":{"slug":"eur25-korting-op-meubels-air4ck","title":"\u20ac25 korting op meubels"},"price_minor":2000,"wallet_part_minor":1100,"payment_part_minor":900,"currency":"EUR","payment":{"provider":"mollie","status":"open","test_mode":true,"checkout_url":"https:\/\/www.mollie.com\/checkout\/select-method\/f3b34a800b"},"voucher_id":null,"failure_reason":null,"expires_at":"2026-10-24T08:03:31+00:00","completed_at":null}}
```

**Back from Mollie, not paid yet**

`GET /api/v1/consumer/checkouts/01m5pd8g6jxyja6prwr6bcwm98`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-4184-703a-b453-f553e6b12c1c

{"data":{"id":"01m5pd8g6jxyja6prwr6bcwm98","state":"pending_payment","offer":{"slug":"eur25-korting-op-meubels-air4ck","title":"\u20ac25 korting op meubels"},"price_minor":2000,"wallet_part_minor":1100,"payment_part_minor":900,"currency":"EUR","payment":{"provider":"mollie","status":"open","test_mode":true,"checkout_url":"https:\/\/www.mollie.com\/checkout\/select-method\/f3b34a800b"},"voucher_id":null,"failure_reason":null,"expires_at":"2026-10-24T08:03:31+00:00","completed_at":null}}
```

**Webhook while Mollie still says open: nothing happens**

`POST /api/v1/payments/mollie/webhook id=tr_f3b34a800b   (application/x-www-form-urlencoded, as Mollie sends it)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-41b1-736a-b742-c1be09e66099

{"data":{"received":true}}
```

**Webhook after Mollie says paid: finalised**

`POST /api/v1/payments/mollie/webhook id=tr_f3b34a800b   (application/x-www-form-urlencoded, as Mollie sends it)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-41dc-71b7-834a-de118eeb1a7b

{"data":{"received":true}}
```

**Mollie retries: still once**

`POST /api/v1/payments/mollie/webhook id=tr_f3b34a800b   (application/x-www-form-urlencoded, as Mollie sends it)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-4229-7314-b063-5c056790ccb8

{"data":{"received":true}}
```

**Completed**

`GET /api/v1/consumer/checkouts/01m5pd8g6jxyja6prwr6bcwm98`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-4252-72f0-a0ae-2f04dc82ea45

{"data":{"id":"01m5pd8g6jxyja6prwr6bcwm98","state":"completed","offer":{"slug":"eur25-korting-op-meubels-air4ck","title":"\u20ac25 korting op meubels"},"price_minor":2000,"wallet_part_minor":1100,"payment_part_minor":900,"currency":"EUR","payment":{"provider":"mollie","status":"paid","test_mode":true,"checkout_url":null},"voucher_id":"01m5pd8g6wwgnnb2qdty25kc2p","failure_reason":null,"expires_at":"2026-10-24T08:03:31+00:00","completed_at":"2026-10-24T07:33:32+00:00"}}
```

**Unknown payment ID: 200, nothing done**

`POST /api/v1/payments/mollie/webhook id=tr_UNKNOWN0000   (application/x-www-form-urlencoded, as Mollie sends it)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-427f-71e9-8869-6a4c10543ece

{"data":{"received":true}}
```

**Malformed ID → 422**

`POST /api/v1/payments/mollie/webhook id=x'1   (application/x-www-form-urlencoded, as Mollie sends it)`

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json
X-Request-Id: 01a16cd4-42a3-719e-93ec-5df4b757859b

{"error":{"status":422,"code":"invalid_payment_id","message":"Invalid payment ID.","request_id":"01a16cd4-42a3-719e-93ec-5df4b757859b"}}
```

**Bob pays everything via Mollie…**

`POST /api/v1/consumer/checkouts {"offer":"eur25-korting-op-meubels-air4ck"}   (Idempotency-Key: checkout-bob-0001)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16cd4-42c3-72fb-8a0e-fca3ddca8103

{"data":{"id":"01m5pd8gpxtf8jd7rs1863kk89","state":"pending_payment","offer":{"slug":"eur25-korting-op-meubels-air4ck","title":"\u20ac25 korting op meubels"},"price_minor":2000,"wallet_part_minor":0,"payment_part_minor":2000,"currency":"EUR","payment":{"provider":"mollie","status":"open","test_mode":true,"checkout_url":"https:\/\/www.mollie.com\/checkout\/select-method\/d93237ac6b"},"voucher_id":null,"failure_reason":null,"expires_at":"2026-10-24T08:03:32+00:00","completed_at":null}}
```

**…and cancels at Mollie**

`POST /api/v1/payments/mollie/webhook id=tr_d93237ac6b   (application/x-www-form-urlencoded, as Mollie sends it)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-433d-7396-bfa7-66dcc818fedc

{"data":{"received":true}}
```

**Failed: code back in stock, nothing issued**

`GET /api/v1/consumer/checkouts/01m5pd8gpxtf8jd7rs1863kk89`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-4378-72ba-a6ac-856791c4ea06

{"data":{"id":"01m5pd8gpxtf8jd7rs1863kk89","state":"failed","offer":{"slug":"eur25-korting-op-meubels-air4ck","title":"\u20ac25 korting op meubels"},"price_minor":2000,"wallet_part_minor":0,"payment_part_minor":2000,"currency":"EUR","payment":{"provider":"mollie","status":"canceled","test_mode":true,"checkout_url":null},"voucher_id":null,"failure_reason":"payment_canceled","expires_at":"2026-10-24T08:03:32+00:00","completed_at":null}}
```

**Anna's checkout as Bob → 404**

`GET /api/v1/consumer/checkouts/01m5pd8g6jxyja6prwr6bcwm98`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16cd4-43a2-7063-b260-97ac61d3f4a7
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"Checkout not found.","request_id":"01a16cd4-43a2-7063-b260-97ac61d3f4a7"}}
```

**Anna's wallet afterwards (€20 + €11 spent)**

`GET /api/v1/consumer/wallet`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cd4-43c9-7388-97f9-96ea0379a97a

{"data":{"balances":{"eur_credit":{"unit":"eur_credit","available":0,"pending":0,"reserved":0,"total_earned":3100,"total_spent":3100,"total_expired":0,"next_expiry":null},"points":{"unit":"points","available":0,"pending":0,"reserved":0,"total_earned":0,"total_spent":0,"total_expired":0,"next_expiry":null}},"withdrawable":false,"notice":"ShopBonus credit and points are not money: they cannot be withdrawn or transferred."}}
```

---

## My Vouchers details, redemption, disputes (B11b, Trello #20)

Captured 2026-10-24 against local MySQL, continuing the B11a data. Anna owns *Woon & Co* vouchers; Jan owns Woon & Co; the Super Admin used a session with step-up. Mollie refunds went to the local test-mode stub; what it received:

```json
{
  "path": "/v2/payments/tr_lost1708de/refunds",
  "idempotency_key": "refund:checkout:01m5pdr69v44a0e6p83qxvtxrx",
  "body": {
    "amount": {
      "currency": "EUR",
      "value": "20.00"
    },
    "description": "ShopBonus voucher could not be delivered"
  }
}
```

**Voucher details: the code, revealed to its owner (audited)**

`GET /api/v1/consumer/vouchers/01m5pdr4zv96djgyr0ybmg0j51`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-2488-71a5-b75f-9ee1d71b0916

{"data":{"id":"01m5pdr4zv96djgyr0ybmg0j51","status":"issued","origin":"wallet_purchase","code_masked":"\u2022\u2022\u2022\u202225-C","webshop":{"name":"Woon & Co","slug":null},"title":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","min_order_minor":10000,"issued_at":"2026-10-24T07:42:05+00:00","expires_at":"2027-01-22T07:42:05+00:00","redeemed_at":null,"code":"MEUBEL-25-C","expires_soon":false,"instructions":"Enter the code in the discount field at checkout on the webshop. Minimum order and conditions below apply.","website_url":null,"terms":{"name":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","version":1,"currency":"EUR","products":[],"categories":[],"combinable":false,"discount_type":"fixed","discount_value":2500,"min_order_minor":10000,"max_discount_minor":null,"public_description":null},"purchase":{"checkout_id":"01m5pdr4zgkbfrnm2hpt5z6mte","price_minor":2000,"wallet_part_minor":0,"payment_part_minor":2000},"redemption":null}}
```

**Merchant verifies a redemption**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/vouchers/redeem {"code":"MEUBEL-25-A","reference":"Order WC-5531"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-24f4-70c7-82f1-08fd3eadc680

{"data":{"voucher_id":"01m5pd8g05ak30hy67ndss1s4v","status":"redeemed","redeemed_at":"2026-10-24T07:42:09+00:00","source":"merchant","reference":"Order WC-5531"}}
```

**Same code again → 409**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/vouchers/redeem {"code":"MEUBEL-25-A"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16cdc-2544-73ed-9aad-d70499dff64d
Content-Type: application/json

{"error":{"status":409,"code":"voucher_not_redeemable","message":"This voucher was already redeemed or is not issued.","request_id":"01a16cdc-2544-73ed-9aad-d70499dff64d","details":{"status":"redeemed"}}}
```

**Unknown code → 404**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/vouchers/redeem {"code":"NOPE-0000"}`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16cdc-2581-715f-929d-d5e61cf61d0e
Content-Type: application/json

{"error":{"status":404,"code":"voucher_not_found","message":"No issued voucher of this webshop has that code.","request_id":"01a16cdc-2581-715f-929d-d5e61cf61d0e"}}
```

**Anna reports an invalid code**

`POST /api/v1/consumer/disputes {"voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","reason":"code_invalid","description":"The webshop says this code does not exist."}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16cdc-25ce-7282-9f9a-b87133025655

{"data":{"id":"01m5pdr9f7d6zf69g911xryy6x","status":"open","reason":"code_invalid","description":"The webshop says this code does not exist.","webshop":"Woon & Co","voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","checkout_id":null,"resolution":null,"created_at":"2026-10-24T07:42:09+00:00"}}
```

**Second report for the same voucher → 409**

`POST /api/v1/consumer/disputes {"voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","reason":"other","description":"Second report about the same voucher"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16cdc-261a-70cf-ac65-2c30f55ed593
Content-Type: application/json

{"error":{"status":409,"code":"dispute_already_open","message":"There is already an open report for this voucher.","request_id":"01a16cdc-261a-70cf-ac65-2c30f55ed593"}}
```

**Her reports**

`GET /api/v1/consumer/disputes`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-265a-72c7-bbc4-e8b4f56e05e1

{"data":[{"id":"01m5pdr9f7d6zf69g911xryy6x","status":"open","reason":"code_invalid","description":"The webshop says this code does not exist.","webshop":"Woon & Co","voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","checkout_id":null,"resolution":null,"created_at":"2026-10-24T07:42:09+00:00"}]}
```

**Admin: all reports**

`GET /api/v1/admin/disputes    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-26b5-71ca-b157-a3614fac23fa

{"data":[{"id":"01m5pdr9f7d6zf69g911xryy6x","status":"open","reason":"code_invalid","description":"The webshop says this code does not exist.","webshop":"Woon & Co","voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","checkout_id":null,"resolution":null,"created_at":"2026-10-24T07:42:09+00:00"}]}
```

**Admin asks the merchant**

`POST /api/v1/admin/disputes/01m5pdr9f7d6zf69g911xryy6x/status {"status":"waiting_for_merchant","note":"Asked Woon & Co to check the code"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-2719-7154-9ab7-5e4a49605c27

{"data":{"id":"01m5pdr9f7d6zf69g911xryy6x","status":"waiting_for_merchant","reason":"code_invalid","description":"The webshop says this code does not exist.","webshop":"Woon & Co","voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","checkout_id":null,"resolution":null,"created_at":"2026-10-24T07:42:09+00:00","timeline":[{"by":"consumer","type":"opened","note":null,"at":"2026-10-24T07:42:09+00:00"},{"by":"admin","type":"status_changed","note":"waiting_for_merchant Asked Woon & Co to check the code","at":"2026-10-24T07:42:09+00:00"}]}}
```

**Merchant view (no consumer identity)**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/disputes`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-2754-73d9-8638-a0159a356b01

{"data":[{"id":"01m5pdr9f7d6zf69g911xryy6x","status":"waiting_for_merchant","reason":"code_invalid","description":"The webshop says this code does not exist.","webshop":"Woon & Co","voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","resolution":null,"created_at":"2026-10-24T07:42:09+00:00","code_last4":"25-C"}]}
```

**Merchant responds**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/disputes/01m5pdr9f7d6zf69g911xryy6x/respond {"message":"This code was not imported correctly, sorry."}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-277b-721e-8dac-f227083922fb

{"data":{"id":"01m5pdr9f7d6zf69g911xryy6x","status":"under_review","reason":"code_invalid","description":"The webshop says this code does not exist.","webshop":"Woon & Co","voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","resolution":null,"created_at":"2026-10-24T07:42:09+00:00","timeline":[{"by":"consumer","type":"opened","note":null,"at":"2026-10-24T07:42:09+00:00"},{"by":"admin","type":"status_changed","note":"waiting_for_merchant Asked Woon & Co to check the code","at":"2026-10-24T07:42:09+00:00"},{"by":"merchant","type":"merchant_response","note":"This code was not imported correctly, sorry.","at":"2026-10-24T07:42:09+00:00"},{"by":"system","type":"status_changed","note":"under_review","at":"2026-10-24T07:42:09+00:00"}],"code_last4":"25-C"}}
```

**Admin: report with timeline**

`GET /api/v1/admin/disputes/01m5pdr9f7d6zf69g911xryy6x    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-27d6-7098-9efc-e829af9836b0

{"data":{"id":"01m5pdr9f7d6zf69g911xryy6x","status":"under_review","reason":"code_invalid","description":"The webshop says this code does not exist.","webshop":"Woon & Co","voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","checkout_id":null,"resolution":null,"created_at":"2026-10-24T07:42:09+00:00","timeline":[{"by":"consumer","type":"opened","note":null,"at":"2026-10-24T07:42:09+00:00"},{"by":"admin","type":"status_changed","note":"waiting_for_merchant Asked Woon & Co to check the code","at":"2026-10-24T07:42:09+00:00"},{"by":"merchant","type":"merchant_response","note":"This code was not imported correctly, sorry.","at":"2026-10-24T07:42:09+00:00"},{"by":"system","type":"status_changed","note":"under_review","at":"2026-10-24T07:42:09+00:00"}]}}
```

**Admin resolves with a refund of the €9.00 card part (once)**

`POST /api/v1/admin/disputes/01m5pdr9f7d6zf69g911xryy6x/resolve {"outcome":"resolved","compensation":"refund","note":"Invalid code confirmed by the webshop; card payment refunded"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-283d-71b2-87a2-366128cc4359

{"data":{"id":"01m5pdr9f7d6zf69g911xryy6x","status":"resolved","reason":"code_invalid","description":"The webshop says this code does not exist.","webshop":"Woon & Co","voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","checkout_id":null,"resolution":{"note":"Invalid code confirmed by the webshop; card payment refunded","compensation":"refund","amount_minor":2000,"at":"2026-10-24T07:42:10+00:00"},"created_at":"2026-10-24T07:42:09+00:00","timeline":[{"by":"consumer","type":"opened","note":null,"at":"2026-10-24T07:42:09+00:00"},{"by":"admin","type":"status_changed","note":"waiting_for_merchant Asked Woon & Co to check the code","at":"2026-10-24T07:42:09+00:00"},{"by":"merchant","type":"merchant_response","note":"This code was not imported correctly, sorry.","at":"2026-10-24T07:42:09+00:00"},{"by":"system","type":"status_changed","note":"under_review","at":"2026-10-24T07:42:09+00:00"},{"by":"admin","type":"resolved","note":"resolved: Invalid code confirmed by the webshop; card payment refunded","at":"2026-10-24T07:42:10+00:00"},{"by":"admin","type":"compensated","note":"refund 2000","at":"2026-10-24T07:42:10+00:00"}]}}
```

**Resolving again → 409, no second compensation**

`POST /api/v1/admin/disputes/01m5pdr9f7d6zf69g911xryy6x/resolve {"outcome":"resolved","compensation":"wallet_credit","amount_minor":2000,"note":"again"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16cdc-291a-71fd-9109-e3a9468d574c
Content-Type: application/json

{"error":{"status":409,"code":"dispute_closed","message":"This report is already closed.","request_id":"01a16cdc-291a-71fd-9109-e3a9468d574c","details":{"status":"resolved"}}}
```

**Anna sees the resolution and timeline**

`GET /api/v1/consumer/disputes/01m5pdr9f7d6zf69g911xryy6x`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-2944-72d6-814f-10080476ed4a

{"data":{"id":"01m5pdr9f7d6zf69g911xryy6x","status":"resolved","reason":"code_invalid","description":"The webshop says this code does not exist.","webshop":"Woon & Co","voucher_id":"01m5pdr4zv96djgyr0ybmg0j51","checkout_id":null,"resolution":{"note":"Invalid code confirmed by the webshop; card payment refunded","compensation":"refund","amount_minor":2000,"at":"2026-10-24T07:42:10+00:00"},"created_at":"2026-10-24T07:42:09+00:00","timeline":[{"by":"consumer","type":"opened","note":null,"at":"2026-10-24T07:42:09+00:00"},{"by":"admin","type":"status_changed","note":"waiting_for_merchant Asked Woon & Co to check the code","at":"2026-10-24T07:42:09+00:00"},{"by":"merchant","type":"merchant_response","note":"This code was not imported correctly, sorry.","at":"2026-10-24T07:42:09+00:00"},{"by":"system","type":"status_changed","note":"under_review","at":"2026-10-24T07:42:09+00:00"},{"by":"admin","type":"resolved","note":"resolved: Invalid code confirmed by the webshop; card payment refunded","at":"2026-10-24T07:42:10+00:00"},{"by":"admin","type":"compensated","note":"refund 2000","at":"2026-10-24T07:42:10+00:00"}]}}
```

**"Code does not work" with a screenshot (Trello #23, B24)** — captured on `php artisan serve`, isolated SQLite, real session login and TOTP challenge for the admin. Multipart, up to 3 `screenshots[]` (JPEG/PNG/WebP ≤5 MB, must decode). Encrypted at rest; Super Admin download only, audited (`admin.dispute_evidence_downloaded`); merchants see only a count. Responses captured before B24 omit `screenshots`.

`POST /api/v1/consumer/disputes  multipart: voucher_id, reason=code_invalid, description, screenshots[]=<PHP source renamed .png>`

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json
X-Request-Id: 01a186e4-fedf-71b8-a7db-cfafc8e97601

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a186e4-fedf-71b8-a7db-cfafc8e97601","fields":{"screenshots.0":["The screenshots.0 field must be a file of type: image\/jpeg, image\/png, image\/webp.","The screenshots.0 field has invalid image dimensions."]}}}
```

`POST /api/v1/consumer/disputes  multipart: voucher_id, reason=code_invalid, description, screenshots[]=@"checkout error.png"`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a186e4-ff72-7359-bcc6-af1c417b0ed5

{"data":{"id":"01m63e9zvtfsbcpexa7yh3kp5n","status":"open","reason":"code_invalid","description":"The webshop rejects this code at checkout.","screenshots":[{"id":"01a186e4-ff7a-730d-b093-2e8e830ffe65","name":"checkout_error.png","mime":"image\/png","size":99}],"webshop":"Woon & Co","voucher_id":"01m63e8y3b5xys4p4xw9rcedc9","checkout_id":null,"resolution":null,"created_at":"2026-10-29T09:01:56+00:00"}}
```

`GET /api/v1/admin/disputes/01m63e9zvtfsbcpexa7yh3kp5n/evidence/01a186e4-ff7a-730d-b093-2e8e830ffe65    (consumer session)`

```http
HTTP/1.1 403 Forbidden
Content-Type: application/json
X-Request-Id: 01a186e5-4a07-7276-ab90-95c7daf44462

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"01a186e5-4a07-7276-ab90-95c7daf44462"}}
```

`GET /api/v1/admin/disputes/01m63e9zvtfsbcpexa7yh3kp5n/evidence/01a186e4-ff7a-730d-b093-2e8e830ffe65    (admin session after 2FA)`

```http
HTTP/1.1 200 OK
Content-Type: image/png
Content-Disposition: attachment; filename="checkout_error.png"
X-Content-Type-Options: nosniff
Cache-Control: no-store, private
X-Request-Id: 01a186e5-4a31-7148-9197-0eb1def87eeb

<99-byte PNG, byte-identical to the upload>
```

`GET /api/v1/admin/disputes/01m63e9zvtfsbcpexa7yh3kp5n/evidence/00000000-0000-4000-8000-000000000000    (admin)`

```http
HTTP/1.1 404 Not Found
Content-Type: application/json
X-Request-Id: 01a186e5-4a5e-7342-8502-80274f493bde

{"error":{"status":404,"code":"not_found","message":"Screenshot not found.","request_id":"01a186e5-4a5e-7342-8502-80274f493bde"}}
```

`GET /api/v1/admin/disputes/01m63e9zvtfsbcpexa7yh3kp5n/evidence/01a186e4-ff7a-730d-b093-2e8e830ffe65    (no session)`

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a186e5-4a7d-7311-b3de-2a946ba9022c

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a186e5-4a7d-7311-b3de-2a946ba9022c"}}
```

**Admin: paid but unfulfilled checkouts**

`GET /api/v1/admin/checkouts?state=paid_unfulfilled    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-299c-7117-8b08-0a8bd50fa6a1

{"data":[{"id":"01m5pdr69v44a0e6p83qxvtxrx","state":"paid_unfulfilled","offer":{"slug":"eur25-korting-op-meubels-air4ck","title":"\u20ac25 korting op meubels"},"price_minor":2000,"wallet_part_minor":0,"payment_part_minor":2000,"currency":"EUR","payment":{"provider":"mollie","status":"paid","test_mode":true,"checkout_url":null},"voucher_id":null,"failure_reason":"reservation_lost","expires_at":"2026-10-24T07:42:06+00:00","completed_at":null}]}
```

**Refund one**

`POST /api/v1/admin/checkouts/01m5pdr69v44a0e6p83qxvtxrx/refund {"note":"Paid after the reservation lapsed"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cdc-29fc-73c6-a029-38143b0be1b6

{"data":{"id":"01m5pdr69v44a0e6p83qxvtxrx","state":"refunded","offer":{"slug":"eur25-korting-op-meubels-air4ck","title":"\u20ac25 korting op meubels"},"price_minor":2000,"wallet_part_minor":0,"payment_part_minor":2000,"currency":"EUR","payment":{"provider":"mollie","status":"paid","test_mode":true,"checkout_url":null},"voucher_id":null,"failure_reason":"reservation_lost","expires_at":"2026-10-24T07:42:06+00:00","completed_at":null}}
```

**Again → 409**

`POST /api/v1/admin/checkouts/01m5pdr69v44a0e6p83qxvtxrx/refund {"note":"again please"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16cdc-2a64-7263-9bef-14c13fd2d20f
Content-Type: application/json

{"error":{"status":409,"code":"checkout_not_refundable","message":"Only paid but unfulfilled checkouts are refunded here.","request_id":"01a16cdc-2a64-7263-9bef-14c13fd2d20f","details":{"state":"refunded"}}}
```

**Merchant on an admin route → 403**

`GET /api/v1/admin/disputes`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16cdc-2a8f-73e0-83ac-09ac69790070
Content-Type: application/json

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"01a16cdc-2a8f-73e0-83ac-09ac69790070"}}
```

---

## Merchant funding: reward + 30% fee (B12, Trello #24 §4, #7)

Captured 2026-10-24 against local MySQL. **Mollie was a local test-mode stub**, and Moneybird is not connected. Jan owns *Woon & Co*, whose funding wallet starts at €0. The new merchant reward "€10 korting op je volgende bestelling" costs €10 reward + €3 fee = €13 per voucher.

**Funding: empty**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/funding`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-d9b9-7269-87fc-9fd3993be7ba

{"data":{"currency":"EUR","available_minor":0,"reserved_minor":0,"settled_minor":0,"released_minor":0,"topped_up_minor":0,"obligations":{"reserved":0,"settled":0,"released":0},"fee_rate_bps":3000,"fee_basis":{"fixed":"reward","percentage":"maximum_discount"},"funding_required":true,"withdrawable":false}}
```

**Activating the reward offer while unfunded → 409**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/offers/01m5pefmgdjxy9q4fa1r7pk7cx/activate`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16ce7-da1e-7239-ae39-6a638ca985a2
Content-Type: application/json

{"error":{"status":409,"code":"funding_insufficient","message":"Top up the reward funding before activating this offer.","request_id":"01a16ce7-da1e-7239-ae39-6a638ca985a2","details":{"required_minor":1300}}}
```

**Top-up below the minimum → 422**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/funding/top-ups {"amount_minor":500}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16ce7-da5a-739d-9127-9528c6522252
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16ce7-da5a-739d-9127-9528c6522252","fields":{"amount_minor":["The amount minor field must be at least 1000."]}}}
```

**Top-up €500 via Mollie iDEAL**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/funding/top-ups {"amount_minor":50000}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16ce7-da94-7061-8a5b-3acd99ac6e76

{"data":{"id":"01m5pefpna4hvspc2gxpg25yhk","state":"pending","amount_minor":50000,"currency":"EUR","checkout_url":"https:\/\/www.mollie.com\/checkout\/select-method\/861cd71ef5","paid_at":null}}
```

**Mollie webhook (payment paid at the stub)**

`POST /api/v1/payments/mollie/webhook id=tr_861cd71ef5`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-db26-70f6-b12e-8f28afb3ced0

{"data":{"received":true}}
```

**Top-up credited once**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/funding/top-ups/01m5pefpna4hvspc2gxpg25yhk`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-db64-7250-b808-df92c8546602

{"data":{"id":"01m5pefpna4hvspc2gxpg25yhk","state":"paid","amount_minor":50000,"currency":"EUR","checkout_url":null,"paid_at":"2026-10-24T07:54:56+00:00"}}
```

**Now the offer activates**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/offers/01m5pefmgdjxy9q4fa1r7pk7cx/activate`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-db88-732f-a021-b5cbd6f00a53

{"data":{"id":"01m5pefmgdjxy9q4fa1r7pk7cx","acquisition":"merchant_reward","status":"active","terms":{"version":1,"locked":true,"summary":"\u20ac10.00 from \u20ac50.00","name":"\u20ac10 korting op je volgende bestelling","public_description":null,"discount_type":"fixed","discount_value":1000,"currency":"EUR","min_order_minor":5000,"max_discount_minor":null,"price_minor":null,"starts_at":null,"ends_at":null,"claim_validity_days":90,"redemption_validity_days":90,"categories":[],"products":[],"combinable":false,"code_mode":"unique","allocation_limit":null},"missing_for_activation":[],"cost_preview":{"applies":true,"available":true,"basis":"fixed_value","currency":"EUR","fee_rate_bps":3000,"per_voucher":{"reward_minor":1000,"fee_minor":300,"merchant_commitment_minor":1300},"allocation":null,"note":"Preview only. Funding and invoicing are handled by the merchant wallet; nothing is charged here."},"activated_at":"2026-10-24T07:54:56+00:00","paused_at":null,"ended_at":null,"created_at":"2026-10-24T07:54:54+00:00"}}
```

Two sponsored vouchers were then reserved through `VoucherInventory` (one issued to Anna), which reserved 2 × €13.

**Funding: €26 reserved**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/funding`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-dd6d-72d6-8e0c-9a243b45c34a

{"data":{"currency":"EUR","available_minor":47400,"reserved_minor":2600,"settled_minor":0,"released_minor":0,"topped_up_minor":50000,"obligations":{"reserved":2,"settled":0,"released":0},"fee_rate_bps":3000,"fee_basis":{"fixed":"reward","percentage":"maximum_discount"},"funding_required":true,"withdrawable":false}}
```

**Jan verifies Anna's redemption → one €13 obligation settled**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/vouchers/redeem {"code":"NEXT-10-A","reference":"Order WC-7001"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-dd95-700b-a072-a7cb1d6ae7f9

{"data":{"voucher_id":"01m5pefq8db6ph9n4xkrrkxtmh","status":"redeemed","redeemed_at":"2026-10-24T07:54:57+00:00","source":"merchant","reference":"Order WC-7001"}}
```

**Obligations**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/funding/obligations`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-ddd8-716d-8ea2-be8b6227c33b

{"data":[{"id":"01m5pefq9ybtdw3vzgtx599mvh","voucher_id":"01m5pefq9nvj291fxq2dvx08wk","status":"reserved","basis":"fixed","reward_minor":1000,"fee_minor":300,"total_minor":1300,"created_at":"2026-10-24T07:54:57+00:00","settled_at":null,"released_at":null},{"id":"01m5pefq93nw9qtdrjhfk9d443","voucher_id":"01m5pefq8db6ph9n4xkrrkxtmh","status":"settled","basis":"fixed","reward_minor":1000,"fee_minor":300,"total_minor":1300,"created_at":"2026-10-24T07:54:57+00:00","settled_at":"2026-10-24T07:54:57+00:00","released_at":null}]}
```

**Funding ledger**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/funding/transactions`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-ddff-72a3-80f5-e12f74c80b0f

{"data":[{"id":"01m5pefqdnf0ej2r9ngay98eh0","type":"debit","amount_minor":-1300,"available_after_minor":47400,"reserved_after_minor":1300,"source":{"type":"sponsorship","ref":"01m5pefq8db6ph9n4xkrrkxtmh"},"description":null,"created_at":"2026-10-24T07:54:57+00:00"},{"id":"01m5pefq9x5mhvsraxbtywnnkk","type":"reserve","amount_minor":0,"available_after_minor":47400,"reserved_after_minor":2600,"source":{"type":"sponsorship","ref":"01m5pefq9nvj291fxq2dvx08wk"},"description":null,"created_at":"2026-10-24T07:54:57+00:00"},{"id":"01m5pefq8zkqfmraeh0dktjz1t","type":"reserve","amount_minor":0,"available_after_minor":48700,"reserved_after_minor":1300,"source":{"type":"sponsorship","ref":"01m5pefq8db6ph9n4xkrrkxtmh"},"description":null,"created_at":"2026-10-24T07:54:57+00:00"},{"id":"01m5pefpsxy61qytej1vgzw4j7","type":"credit","amount_minor":50000,"available_after_minor":50000,"reserved_after_minor":0,"source":{"type":"top_up","ref":"01m5pefpna4hvspc2gxpg25yhk"},"description":"Mollie top-up","created_at":"2026-10-24T07:54:56+00:00"}],"meta":{"current_page":1,"last_page":1,"per_page":50,"total":4}}
```

`php artisan shopbonus:prepare-invoices 2026-10` → `Prepared 1 draft invoice(s) for 2026-10.`

**Statement draft**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/invoices`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-df47-73a1-9196-446c8a3b1ec5

{"data":[{"id":"01m5pefqrwgqpck880316vxzs9","period":"2026-10","reward_minor":1000,"fee_minor":300,"total_minor":1300,"lines":1,"status":"draft","moneybird":"not_connected"}]}
```

**Funding afterwards**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/funding`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ce7-df6c-7072-86bb-642bcd8b5257

{"data":{"currency":"EUR","available_minor":47400,"reserved_minor":1300,"settled_minor":1300,"released_minor":0,"topped_up_minor":50000,"obligations":{"reserved":1,"settled":1,"released":0},"fee_rate_bps":3000,"fee_basis":{"fixed":"reward","percentage":"maximum_discount"},"funding_required":true,"withdrawable":false}}
```

---

## Merchant dashboard and analytics (B13, Trello #8, #24 §6, #3 §17)

Captured 2026-10-24 against local MySQL, with Jan, owner of *Woon & Co* and *Fietsen Utrecht*, on the data left by the earlier captures. `php artisan shopbonus:merchant-notifications` → `Created 5 notification(s).`

**Overview**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/dashboard`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cf4-001b-7006-b2c0-89f9e8e1b542

{"data":{"webshop":{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","status":"approved"},"greeting":"good_morning","kpis":{"active_offers":3,"available_codes":0,"issued_vouchers":4,"claimed_vouchers":0,"redeemed_vouchers":2},"definitions":{"active_offers":"Offers with status active (draft, paused and ended excluded).","available_codes":"Codes with status available, i.e. free for allocation (reserved, issued, redeemed, invalid and expired excluded).","issued_vouchers":"Vouchers ever issued to a consumer (issued_at set), including those since redeemed or expired.","claimed_vouchers":"Answer24 claim links accepted by a verified consumer.","redeemed_vouchers":"Vouchers with a verified redemption (paid order containing the code, or merchant verification)."},"offers":[{"id":"01m5pefmgdjxy9q4fa1r7pk7cx","name":"\u20ac10 korting op je volgende bestelling","summary":"\u20ac10.00 from \u20ac50.00","type":"fixed","acquisition":"merchant_reward","min_order_minor":5000,"available_codes":0,"issued":1,"status":"active","attention":["low_inventory"]},{"id":"01m5pdr2s105nre4frgsby7w70","name":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","type":"fixed","acquisition":"wallet_purchase","min_order_minor":10000,"available_codes":0,"issued":0,"status":"active","attention":["low_inventory"]},{"id":"01m5pd8dnmr7zn0gp678fzfrs9","name":"\u20ac25 korting op meubels","summary":"\u20ac25.00 from \u20ac100.00","type":"fixed","acquisition":"wallet_purchase","min_order_minor":10000,"available_codes":0,"issued":3,"status":"active","attention":["low_inventory"]}],"notifications":{"unread":3,"latest":[{"id":"01m5pf7yycjq3ddb2mdp8jaxy6","type":"low_inventory","severity":"critical","title":"Out of codes","body":"\u20ac10 korting op je volgende bestelling: 0 codes remaining.","subject":{"type":"offer","id":"01m5pefmgdjxy9q4fa1r7pk7cx"},"read":false,"created_at":"2026-10-24T08:08:11+00:00"},{"id":"01m5pf7yy7f5qckh026fttxrvv","type":"low_inventory","severity":"critical","title":"Out of codes","body":"\u20ac25 korting op meubels: 0 codes remaining.","subject":{"type":"offer","id":"01m5pdr2s105nre4frgsby7w70"},"read":false,"created_at":"2026-10-24T08:08:11+00:00"},{"id":"01m5pf7yy2fkbtqn1a96kanhxd","type":"low_inventory","severity":"critical","title":"Out of codes","body":"\u20ac25 korting op meubels: 0 codes remaining.","subject":{"type":"offer","id":"01m5pd8dnmr7zn0gp678fzfrs9"},"read":false,"created_at":"2026-10-24T08:08:11+00:00"}]},"funding":{"available_minor":47400,"reserved_minor":1300},"generated_at":"2026-10-24T08:08:12+00:00"}}
```

**Last 7 days**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/analytics/claims?days=7`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cf4-010e-71a2-94e7-a3564ce30b3b

{"data":{"timezone":"Europe\/Amsterdam","from":"2026-10-18","to":"2026-10-24","days":[{"date":"2026-10-18","claims":0,"issued":0,"redeemed":0},{"date":"2026-10-19","claims":0,"issued":0,"redeemed":0},{"date":"2026-10-20","claims":0,"issued":0,"redeemed":0},{"date":"2026-10-21","claims":0,"issued":0,"redeemed":0},{"date":"2026-10-22","claims":0,"issued":0,"redeemed":0},{"date":"2026-10-23","claims":0,"issued":0,"redeemed":0},{"date":"2026-10-24","claims":0,"issued":4,"redeemed":2}],"totals":{"claims":0,"issued":4,"redeemed":2}}}
```

**Unsupported range → 422**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/analytics/claims?days=12`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16cf4-0186-73de-978d-64c9b14136df
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16cf4-0186-73de-978d-64c9b14136df","fields":{"days":["The selected days is invalid."]}}}
```

**Commercial overview (last 30 days)** (re-captured in B14 with banner metrics)

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/analytics/overview`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de3-2d87-730f-8c75-70d26759aaf3

{"data":{"from":"2026-09-24T22:00:00+00:00","to":"2026-10-24T21:59:59+00:00","orders":[],"vouchers":{"reserved":6,"issued":4,"redeemed":2,"redemption_rate":0.5},"reward_cost":{"currency":"EUR","reward_minor":0,"fee_minor":0,"total_minor":0},"answer24":{"opportunities":[],"claims":0},"funding":{"available_minor":47400,"reserved_minor":1300},"banner_metrics":{"status":"available","eligible":1,"viewable_impressions":2,"cta_clicks":1,"dismissals":1,"ctr":0.5},"timezone":"Europe\/Amsterdam"}}
```

**Unread notifications**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/notifications?unread=1`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cf4-0227-711f-88e5-957e1298b328

{"data":[{"id":"01m5pf7yycjq3ddb2mdp8jaxy6","type":"low_inventory","severity":"critical","title":"Out of codes","body":"\u20ac10 korting op je volgende bestelling: 0 codes remaining.","subject":{"type":"offer","id":"01m5pefmgdjxy9q4fa1r7pk7cx"},"read":false,"created_at":"2026-10-24T08:08:11+00:00"},{"id":"01m5pf7yy7f5qckh026fttxrvv","type":"low_inventory","severity":"critical","title":"Out of codes","body":"\u20ac25 korting op meubels: 0 codes remaining.","subject":{"type":"offer","id":"01m5pdr2s105nre4frgsby7w70"},"read":false,"created_at":"2026-10-24T08:08:11+00:00"},{"id":"01m5pf7yy2fkbtqn1a96kanhxd","type":"low_inventory","severity":"critical","title":"Out of codes","body":"\u20ac25 korting op meubels: 0 codes remaining.","subject":{"type":"offer","id":"01m5pd8dnmr7zn0gp678fzfrs9"},"read":false,"created_at":"2026-10-24T08:08:11+00:00"}],"meta":{"current_page":1,"last_page":1,"per_page":25,"total":3,"unread":3}}
```

**Mark one read**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/notifications/01m5pf7yy2fkbtqn1a96kanhxd/read`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cf4-0281-7361-826d-43c246dfd49b

{"data":{"id":"01m5pf7yy2fkbtqn1a96kanhxd","type":"low_inventory","severity":"critical","title":"Out of codes","body":"\u20ac25 korting op meubels: 0 codes remaining.","subject":{"type":"offer","id":"01m5pd8dnmr7zn0gp678fzfrs9"},"read":true,"created_at":"2026-10-24T08:08:11+00:00"}}
```

**Mark all read**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/notifications/read-all`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cf4-02c1-739f-a152-f61f7fba4df2

{"data":{"marked_read":2,"unread":0}}
```

**Another workspace's notification → 404**

`POST /api/v1/workspaces/01m5n6399tnc5h1vtfyf3gsgv4/notifications/01m5pf7yy2fkbtqn1a96kanhxd/read`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16cf4-02f7-71d4-8d3c-4c2638ef186e
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"Notification not found.","request_id":"01a16cf4-02f7-71d4-8d3c-4c2638ef186e"}}
```

**Owner's combined dashboard**

`GET /api/v1/owner/dashboard`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16cf4-032d-7205-a253-4c2413fc66f6

{"data":{"webshops":[{"id":"01m5n6399tnc5h1vtfyf3gsgv4","name":"Fietsen Utrecht","status":"approved","kpis":{"active_offers":1,"available_codes":0,"issued_vouchers":1,"claimed_vouchers":0,"redeemed_vouchers":0}},{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","status":"approved","kpis":{"active_offers":3,"available_codes":0,"issued_vouchers":4,"claimed_vouchers":0,"redeemed_vouchers":2}}],"totals":{"active_offers":4,"available_codes":0,"issued_vouchers":5,"claimed_vouchers":0,"redeemed_vouchers":2},"definitions":{"active_offers":"Offers with status active (draft, paused and ended excluded).","available_codes":"Codes with status available, i.e. free for allocation (reserved, issued, redeemed, invalid and expired excluded).","issued_vouchers":"Vouchers ever issued to a consumer (issued_at set), including those since redeemed or expired.","claimed_vouchers":"Answer24 claim links accepted by a verified consumer.","redeemed_vouchers":"Vouchers with a verified redemption (paid order containing the code, or merchant verification)."}}}
```

---

## Banner campaigns on the merchant's site (B14, Trello #25, #24 §1–2)

Captured 2026-10-24 against local MySQL. *Woon & Co* has the verified domain `woonenco.nl` and a funded €10 reward offer. The embed and editor were simulated with curl, sending an `Origin` header. The real embed script and editor UI are frontend. Pairing and editor tokens are redacted.

**Upload an image (multipart `image=@banner.png`)**

`POST /api/v1/workspaces/{workspace}/banners/assets (multipart: image=@banner.png;type=image/png)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16de2-ec0f-70c9-a090-91b63b6b3ba0

{"data":{"id":"01m5py5v603t7kwb89trg52nrq","width":600,"height":200,"url":"http:\/\/127.0.0.1:8796\/api\/v1\/banners\/assets\/01m5py5v603t7kwb89trg52nrq"}}
```

**Unsafe config → 422**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners {"name":"X","config":{"template":"ribbon","placement":{"anchor":"top"},"content":{"heading":"<b>x</b>","cta_label":"Go","cta_url":"http://woonenco.nl"},"style":{"background":"red","text":"#FFFFFF","font":"inter","animation":"none"},"devices":["desktop"],"frequency":{"per_session":1},"triggers":{"all":[{"type":"url_match","value":"/*"}]}}}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16de2-ece3-700b-802e-f418f92e4652
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16de2-ece3-700b-802e-f418f92e4652","fields":{"content.heading":["The content.heading field format is invalid."],"content.cta_url":["The content.cta url field must be a valid URL."],"style.background":["The style.background field format is invalid."]}}}
```

**Create a draft**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners {"name":"Najaar - €10 terug","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","config":{"template":"ribbon","placement":{"anchor":"top"},"content":{"heading":"Besteed €100, krijg €10","subtitle":"Voor je volgende aankoop","cta_label":"Meer info","cta_url":"https://www.woonenco.nl/shopbonus","terms":"Min. besteding €100","image_asset_id":"01m5py5v603t7kwb89trg52nrq"},"style":{"background":"#1D4ED8","text":"#FFFFFF","font":"inter","animation":"fade_in"},"devices":["desktop","mobile"],"frequency":{"per_session":1,"cooldown_hours":24,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"},{"type":"category","value":["fashion"]},{"type":"min_seconds","value":20}]}}}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16de2-ed71-720f-9dcc-3894d4a4e385

{"data":{"id":"01m5py5vc56hm2adbhwxvrfztw","name":"Najaar - \u20ac10 terug","status":"draft","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":1,"live_version":null,"scheduled_at":null,"updated_at":"2026-10-24T12:29:10+00:00"}}
```

**Installation (embed key, origins, health)**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/installation`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-edae-7214-92e1-50c06c97e039

{"data":{"public_key":"pk_4dhphng2rpstr7qw2wybjton","allowed_origins":["https:\/\/woonenco.nl","https:\/\/www.woonenco.nl"],"status":"not_seen","last_seen":null,"live_campaigns":0,"snippet":"<script async src=\"http:\/\/localhost:8000\/embed\/v1.js\" data-shopbonus-key=\"pk_4dhphng2rpstr7qw2wybjton\"><\/script>"}}
```

**Origin outside the verified domain → 422**

`PUT /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/installation {"origins":["https://evil.example"]}`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16de2-edd8-72ea-b2cc-57ad046392fb
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16de2-edd8-72ea-b2cc-57ad046392fb","fields":{"origins.0":["Use https origins on woonenco.nl or its subdomains."]}}}
```

**Allow a subdomain**

`PUT /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/installation {"origins":["https://woonenco.nl","https://www.woonenco.nl","https://shop.woonenco.nl"]}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-edfe-72fd-9b2d-ccc8dc99c826

{"data":{"public_key":"pk_4dhphng2rpstr7qw2wybjton","allowed_origins":["https:\/\/woonenco.nl","https:\/\/www.woonenco.nl","https:\/\/shop.woonenco.nl"],"status":"not_seen","last_seen":null,"live_campaigns":0,"snippet":"<script async src=\"http:\/\/localhost:8000\/embed\/v1.js\" data-shopbonus-key=\"pk_4dhphng2rpstr7qw2wybjton\"><\/script>"}}
```

**Simulation: shown, with reasons**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/simulate {"path":"/collections/jurken","category":"fashion","seconds":25,"device":"desktop"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-ee28-7201-9b43-dea2e3832500

{"data":{"shown":true,"reasons":["matched: url_match \/collections\/*","matched: category fashion","matched: min_seconds 20","shown"],"cart_progress":null,"fallback":null,"simulated":true}}
```

**Simulation: hidden (too early)**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/simulate {"path":"/collections/jurken","category":"fashion","seconds":5,"device":"desktop"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-ee52-7045-b4b3-09e02eb548d3

{"data":{"shown":false,"reasons":["matched: url_match \/collections\/*","matched: category fashion","not matched: min_seconds 20"],"cart_progress":null,"fallback":null,"simulated":true}}
```

**Public config before publishing (draft invisible)**

```http
HTTP/1.1 200 OK
Cache-Control: max-age=60, public
ETag: "9a6c952e7ee82f175c6320b7fa9179fe"
Content-Type: application/json
X-Request-Id: 01a16de2-ee7f-7127-a88b-a550586c4a1f

{"data":{"campaigns":[]}}
```

**Publish**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/publish`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-eea6-73c9-9d1d-d32ecdeca300

{"data":{"id":"01m5py5vc56hm2adbhwxvrfztw","name":"Najaar - \u20ac10 terug","status":"live","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":1,"live_version":1,"scheduled_at":null,"updated_at":"2026-10-24T12:29:11+00:00"}}
```

**Save a new draft (live version unchanged)**

`PUT /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw {"revision":1,"config":{"template":"ribbon","placement":{"anchor":"top"},"content":{"heading":"Nieuw concept: €10 terug","subtitle":"Voor je volgende aankoop","cta_label":"Meer info","cta_url":"https://www.woonenco.nl/shopbonus","terms":"Min. besteding €100","image_asset_id":"01m5py5v603t7kwb89trg52nrq"},"style":{"background":"#1D4ED8","text":"#FFFFFF","font":"inter","animation":"fade_in"},"devices":["desktop","mobile"],"frequency":{"per_session":1,"cooldown_hours":24,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"},{"type":"category","value":["fashion"]},{"type":"min_seconds","value":20}]}}}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-eeed-72ef-9c6f-9d42e123a058

{"data":{"id":"01m5py5vc56hm2adbhwxvrfztw","name":"Najaar - \u20ac10 terug","status":"live","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":2,"live_version":1,"scheduled_at":null,"updated_at":"2026-10-24T12:29:11+00:00"}}
```

**Stale revision → 409**

`PUT /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw {"revision":1,"config":{"template":"ribbon","placement":{"anchor":"top"},"content":{"heading":"Besteed €100, krijg €10","subtitle":"Voor je volgende aankoop","cta_label":"Meer info","cta_url":"https://www.woonenco.nl/shopbonus","terms":"Min. besteding €100","image_asset_id":"01m5py5v603t7kwb89trg52nrq"},"style":{"background":"#1D4ED8","text":"#FFFFFF","font":"inter","animation":"fade_in"},"devices":["desktop","mobile"],"frequency":{"per_session":1,"cooldown_hours":24,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"},{"type":"category","value":["fashion"]},{"type":"min_seconds","value":20}]}}}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16de2-ef19-7067-ad86-a4e4839a8a75
Content-Type: application/json

{"error":{"status":409,"code":"draft_revision_conflict","message":"Someone else saved this banner in the meantime. Reload to continue.","request_id":"01a16de2-ef19-7067-ad86-a4e4839a8a75","details":{"current_revision":2}}}
```

**Draft vs published**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-ef3f-7066-bc18-72d5e82d2e99

{"data":{"id":"01m5py5vc56hm2adbhwxvrfztw","name":"Najaar - \u20ac10 terug","status":"live","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":2,"live_version":1,"scheduled_at":null,"updated_at":"2026-10-24T12:29:11+00:00","draft_config":{"style":{"font":"inter","text":"#FFFFFF","animation":"fade_in","background":"#1D4ED8"},"content":{"terms":"Min. besteding \u20ac100","cta_url":"https:\/\/www.woonenco.nl\/shopbonus","heading":"Nieuw concept: \u20ac10 terug","subtitle":"Voor je volgende aankoop","cta_label":"Meer info","image_asset_id":"01m5py5v603t7kwb89trg52nrq"},"devices":["desktop","mobile"],"template":"ribbon","triggers":{"all":[{"type":"url_match","value":"\/collections\/*"},{"type":"category","value":["fashion"]},{"type":"min_seconds","value":20}]},"frequency":{"per_session":1,"cooldown_hours":24,"hide_after_dismiss":true},"placement":{"anchor":"top"}},"published_config":{"style":{"font":"inter","text":"#FFFFFF","animation":"fade_in","background":"#1D4ED8"},"content":{"terms":"Min. besteding \u20ac100","cta_url":"https:\/\/www.woonenco.nl\/shopbonus","heading":"Besteed \u20ac100, krijg \u20ac10","subtitle":"Voor je volgende aankoop","cta_label":"Meer info","image_asset_id":"01m5py5v603t7kwb89trg52nrq"},"devices":["desktop","mobile"],"template":"ribbon","triggers":{"all":[{"type":"url_match","value":"\/collections\/*"},{"type":"category","value":["fashion"]},{"type":"min_seconds","value":20}]},"frequency":{"per_session":1,"cooldown_hours":24,"hide_after_dismiss":true},"placement":{"anchor":"top"}},"notice":"Customers see the published version until you publish the draft."}}
```

**Public config (published v1 only; ETag + 60 s cache)**

`GET /api/v1/banners/config?key=pk_4dhphng2rpstr7qw2wybjton   (Origin: https://www.woonenco.nl)`

```http
HTTP/1.1 200 OK
Cache-Control: max-age=60, public
ETag: "011772b89ec300ca5226b43b5f582745"
Vary: Origin
Content-Type: application/json
X-Request-Id: 01a16de2-ef64-71a2-81ea-0973ad1e6e30

{"data":{"campaigns":[{"campaign":"01m5py5vc56hm2adbhwxvrfztw","version":1,"config":{"style":{"font":"inter","text":"#FFFFFF","animation":"fade_in","background":"#1D4ED8"},"content":{"terms":"Min. besteding \u20ac100","cta_url":"https:\/\/www.woonenco.nl\/shopbonus","heading":"Besteed \u20ac100, krijg \u20ac10","subtitle":"Voor je volgende aankoop","cta_label":"Meer info","image_url":"http:\/\/127.0.0.1:8796\/api\/v1\/banners\/assets\/01m5py5v603t7kwb89trg52nrq"},"devices":["desktop","mobile"],"template":"ribbon","triggers":{"all":[{"type":"url_match","value":"\/collections\/*"},{"type":"category","value":["fashion"]},{"type":"min_seconds","value":20}]},"frequency":{"per_session":1,"cooldown_hours":24,"hide_after_dismiss":true},"placement":{"anchor":"top"}}}]}}
```

**Origin not allowed → 403**

`GET /api/v1/banners/config?key=pk_4dhphng2rpstr7qw2wybjton   (Origin: https://evil.example)`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16de2-ef83-71e5-bc6a-de9690c35167
Content-Type: application/json

{"error":{"status":403,"code":"origin_not_allowed","message":"Unknown key or origin not allowed.","request_id":"01a16de2-ef83-71e5-bc6a-de9690c35167"}}
```

**Events: eligible, 2 viewable, a consented click (gets a click ID), a dismissal**

`POST /api/v1/banners/events {"key":"pk_4dhphng2rpstr7qw2wybjton","events":[{"id":"96875328-8eb8-4fcd-94eb-9c1deb4b1e73","campaign":"01m5py5vc56hm2adbhwxvrfztw","version":1,"type":"eligible","occurred_at":"2026-10-24T12:29:11Z","device":"desktop","path":"/collections/jurken?utm_source=x","category":"fashion","consent":true},{"id":"c0dceeb6-0574-432f-b57f-8561767f8d0e","campaign":"01m5py5vc56hm2adbhwxvrfztw","version":1,"type":"viewable_impression","occurred_at":"2026-10-24T12:29:11Z","device":"desktop","path":"/collections/jurken?utm_source=x","category":"fashion","consent":true},{"id":"e8fecc9c-399b-400e-87fd-1a4093e08d33","campaign":"01m5py5vc56hm2adbhwxvrfztw","version":1,"type":"viewable_impression","occurred_at":"2026-10-24T12:29:11Z","device":"mobile","path":"/collections/jurken?utm_source=x","category":"fashion","consent":false},{"id":"b51b04aa-93f8-4597-955a-36e4eead25b6","campaign":"01m5py5vc56hm2adbhwxvrfztw","version":1,"type":"cta_click","occurred_at":"2026-10-24T12:29:11Z","device":"desktop","path":"/collections/jurken?utm_source=x","category":"fashion","consent":true},{"id":"7d053be8-51fb-4ade-a083-fa58ef784f02","campaign":"01m5py5vc56hm2adbhwxvrfztw","version":1,"type":"dismiss","occurred_at":"2026-10-24T12:29:11Z","device":"mobile","path":"/collections/jurken?utm_source=x","category":"fashion","consent":false}]}   (Origin: https://www.woonenco.nl)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a16de2-efd4-70d0-af8f-d01bca5ee3ea

{"data":{"accepted":5,"duplicates":0,"rejected":0,"click_ids":{"b51b04aa-93f8-4597-955a-36e4eead25b6":"bc_QkPRc69KHHtHC595p7ZkITHV"}}}
```

**Same batch again: all duplicates**

`POST /api/v1/banners/events (same batch again)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a16de2-f015-70fa-ba0c-cbe28e3116bd

{"data":{"accepted":0,"duplicates":5,"rejected":0,"click_ids":[]}}
```

**Results: CTR = 1/2; the dismissal is not a click**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/results`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f048-7083-aa5b-07103ad5c442

{"data":{"from":"2026-09-25","to":"2026-10-24","device":null,"timezone":"Europe\/Amsterdam","eligible":1,"viewable_impressions":2,"cta_clicks":1,"dismissals":1,"ctr":0.5,"attributed_orders":0,"attributed_revenue_minor":0,"definitions":{"viewable_impression":"\u226550% visible for \u22651 second, deduplicated by the embed","ctr":"CTA clicks \/ viewable impressions; closing (X) is a dismissal, never a click","attributed_orders":"Paid orders carrying a consented click ID of this banner, within 30 days of the click; all other orders are unattributed"}}}
```

**Overview list**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners?status=live`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f089-737f-8443-e042f92ee975

{"data":[{"id":"01m5py5vc56hm2adbhwxvrfztw","name":"Najaar - \u20ac10 terug","status":"live","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":2,"live_version":1,"scheduled_at":null,"updated_at":"2026-10-24T12:29:11+00:00","stats_30d":{"viewable_impressions":2,"cta_clicks":1,"ctr":0.5,"attributed_orders":0}}]}
```

**Pause**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/pause`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f0cd-7319-bc76-41c50b694812

{"data":{"id":"01m5py5vc56hm2adbhwxvrfztw","name":"Najaar - \u20ac10 terug","status":"paused","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":2,"live_version":1,"scheduled_at":null,"updated_at":"2026-10-24T12:29:11+00:00"}}
```

**Pause again → 409**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/pause`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16de2-f10a-731a-b26a-42128deae628
Content-Type: application/json

{"error":{"status":409,"code":"banner_not_live","message":"Only a live banner can be paused.","request_id":"01a16de2-f10a-731a-b26a-42128deae628"}}
```

**Resume**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/resume`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f139-731b-8e19-82e7e542f023

{"data":{"id":"01m5py5vc56hm2adbhwxvrfztw","name":"Najaar - \u20ac10 terug","status":"live","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":2,"live_version":1,"scheduled_at":null,"updated_at":"2026-10-24T12:29:11+00:00"}}
```

**Roll back to v1 (as a new version)**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/rollback {"version":1}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f184-72c0-8685-7d0af3325913

{"data":{"id":"01m5py5vc56hm2adbhwxvrfztw","name":"Najaar - \u20ac10 terug","status":"live","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":3,"live_version":2,"scheduled_at":null,"updated_at":"2026-10-24T12:29:11+00:00"}}
```

**Versions**

`GET /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/versions`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f1d6-7241-a4b7-e0af9a578566

{"data":[{"version":2,"live":true,"published_at":"2026-10-24T12:29:11+00:00","published_by":"Jan"},{"version":1,"live":false,"published_at":"2026-10-24T12:29:11+00:00","published_by":"Jan"}]}
```

**Duplicate**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5vc56hm2adbhwxvrfztw/duplicate`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16de2-f215-7309-82d0-287a13704cb3

{"data":{"id":"01m5py5wh7e1wscf83vsk0z36t","name":"Najaar - \u20ac10 terug (copy)","status":"draft","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":1,"live_version":null,"scheduled_at":null,"updated_at":"2026-10-24T12:29:12+00:00"}}
```

**Schedule the copy (shop-local time)**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/01m5py5wh7e1wscf83vsk0z36t/schedule {"publish_at":"2026-10-25T10:00"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f2bc-72b2-8e7e-bf991b72e25b

{"data":{"id":"01m5py5wh7e1wscf83vsk0z36t","name":"Najaar - \u20ac10 terug (copy)","status":"scheduled","template":"ribbon","placement":"top","offer_id":"01m5pefmgdjxy9q4fa1r7pk7cx","draft_revision":1,"live_version":null,"scheduled_at":"2026-10-25T09:00:00+00:00","updated_at":"2026-10-24T12:29:12+00:00"}}
```

**Pairing token for the live editor**

`POST /api/v1/workspaces/01m5pd8djwvb5yyj12nw5esr56/banners/pairing {"origin":"https://www.woonenco.nl"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16de2-f30e-73d2-944c-3724951dc2ce

{"data":{"pairing_token":"<pairing token>","expires_in":600,"origin":"https:\/\/www.woonenco.nl"}}
```

**Exchange from the wrong origin → 401**

`POST /api/v1/banners/editor/pair {"pairing_token":"<pairing token>"}   (Origin: https://evil.example)`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a16de2-f35e-732e-bcc9-d9dc59e6f865
Content-Type: application/json

{"error":{"status":401,"code":"pairing_invalid","message":"This pairing link is not valid any more. Start the editor again from ShopBonus.","request_id":"01a16de2-f35e-732e-bcc9-d9dc59e6f865"}}
```

**Exchange from the shop origin**

`POST /api/v1/banners/editor/pair {"pairing_token":"<pairing token>"}   (Origin: https://www.woonenco.nl)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f397-731f-af4f-34bd14b722b6

{"data":{"editor_token":"<editor token>","expires_in":1800,"scope":"banner_drafts"}}
```

**Replay → 401**

`POST /api/v1/banners/editor/pair {"pairing_token":"<pairing token>"}   (Origin: https://www.woonenco.nl)`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a16de2-f3ce-720c-9977-4d02e7cc7193
Content-Type: application/json

{"error":{"status":401,"code":"pairing_invalid","message":"This pairing link is not valid any more. Start the editor again from ShopBonus.","request_id":"01a16de2-f3ce-720c-9977-4d02e7cc7193"}}
```

**Editor reads the draft**

`GET /api/v1/banners/editor/campaigns/01m5py5vc56hm2adbhwxvrfztw    (Origin: https://www.woonenco.nl, X-Banner-Editor-Token: <editor token>)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f400-7351-b379-af16e8079b45

{"data":{"id":"01m5py5vc56hm2adbhwxvrfztw","name":"Najaar - \u20ac10 terug","draft_revision":3,"draft_config":{"style":{"font":"inter","text":"#FFFFFF","animation":"fade_in","background":"#1D4ED8"},"content":{"terms":"Min. besteding \u20ac100","cta_url":"https:\/\/www.woonenco.nl\/shopbonus","heading":"Besteed \u20ac100, krijg \u20ac10","subtitle":"Voor je volgende aankoop","cta_label":"Meer info","image_asset_id":"01m5py5v603t7kwb89trg52nrq"},"devices":["desktop","mobile"],"template":"ribbon","triggers":{"all":[{"type":"url_match","value":"\/collections\/*"},{"type":"category","value":["fashion"]},{"type":"min_seconds","value":20}]},"frequency":{"per_session":1,"cooldown_hours":24,"hide_after_dismiss":true},"placement":{"anchor":"top"}}}}
```

**Editor saves the draft**

`PUT /api/v1/banners/editor/campaigns/01m5py5vc56hm2adbhwxvrfztw {"revision":3,"config":{"template":"ribbon","placement":{"anchor":"top"},"content":{"heading":"Besteed €100, krijg €10","subtitle":"Voor je volgende aankoop","cta_label":"Meer info","cta_url":"https://www.woonenco.nl/shopbonus","terms":"Min. besteding €100","image_asset_id":"01m5py5v603t7kwb89trg52nrq"},"style":{"background":"#1D4ED8","text":"#FFFFFF","font":"inter","animation":"fade_in"},"devices":["desktop","mobile"],"frequency":{"per_session":1,"cooldown_hours":24,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"},{"type":"category","value":["fashion"]},{"type":"min_seconds","value":20}]}}}   (Origin: https://www.woonenco.nl, X-Banner-Editor-Token: <editor token>)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16de2-f438-736c-9ab4-985e1cb33d59

{"data":{"id":"01m5py5vc56hm2adbhwxvrfztw","draft_revision":4}}
```

**Without editor token → 401**

`GET /api/v1/banners/editor/campaigns/01m5py5vc56hm2adbhwxvrfztw    (Origin: https://www.woonenco.nl)`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a16de2-f47c-72f4-b4ea-d23ba68befd3
Content-Type: application/json

{"error":{"status":401,"code":"editor_token_invalid","message":"Editor session expired or not valid for this site.","request_id":"01a16de2-f47c-72f4-b4ea-d23ba68befd3"}}
```

**Banner image** (headers only)

```http
HTTP/1.1 200 OK
Content-Type: image/png
Cache-Control: immutable, max-age=31536000, public
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'
```

---

## CMS: pages, legal documents, FAQ (B15a, Trello #5)

Captured 2026-10-24 against local MySQL. The Super Admin used a session with password confirmation (step-up), and Anna is a consumer. Rich text is a JSON document (Tiptap/ProseMirror-style) with an allowlist of nodes, marks and attributes. There is no HTML.

**Unsafe link → 422**

`POST /api/v1/admin/cms/pages {"key":"x","type":"info","locales":{"nl":{"slug":"x","content":{"title":"X","body":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"klik","marks":[{"type":"link","attrs":{"href":"javascript:alert(1)"}}]}]}]}}}}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16ded-adc5-732d-86de-835fd3e21bdf
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16ded-adc5-732d-86de-835fd3e21bdf","fields":{"content.body.content.0.content.0.marks.0":["Only bold, italic and https or relative links are allowed."]}}}
```

**Create a page (NL + EN drafts)**

`POST /api/v1/admin/cms/pages {"key":"hoe-het-werkt","type":"info","locales":{"nl":{"slug":"hoe-het-werkt","content":{"title":"Hoe ShopBonus werkt","seo_title":"Hoe ShopBonus werkt","seo_description":"Spaar en krijg korting bij webshops.","body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Sparen en korting"}]},{"type":"paragraph","content":[{"type":"text","text":"Koop bij deelnemende webshops en ontvang tegoed."},{"type":"text","text":" Lees meer","marks":[{"type":"link","attrs":{"href":"/hulp"}}]}]}]}}},"en":{"slug":"how-it-works","content":{"title":"How ShopBonus works","body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Save and get discounts"}]},{"type":"paragraph","content":[{"type":"text","text":"Shop at participating webshops and receive credit."},{"type":"text","text":" Lees meer","marks":[{"type":"link","attrs":{"href":"/hulp"}}]}]}]}}}}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16ded-af44-70f3-ab82-b399564bd01e

{"data":{"id":"01m5pyvbvf2apjnnekn18ghy0x","key":"hoe-het-werkt","type":"info","audience":"public","archived":false,"locales":{"en":{"slug":"how-it-works","published":false,"draft_revision":1,"draft_updated_at":"2026-10-24T12:40:55+00:00"},"nl":{"slug":"hoe-het-werkt","published":false,"draft_revision":1,"draft_updated_at":"2026-10-24T12:40:55+00:00"}}}}
```

**Not published yet → 404**

`GET /api/v1/public/pages/nl/hoe-het-werkt`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16ded-aff8-739a-bee1-77e19f04ec28
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"Page not found.","request_id":"01a16ded-aff8-739a-bee1-77e19f04ec28"}}
```

**Publish NL**

`POST /api/v1/admin/cms/pages/01m5pyvbvf2apjnnekn18ghy0x/locales/nl/publish    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-b0e7-707e-a0db-70c9c9226494

{"data":{"id":"01m5pyvc8qszq81r9grjgngg75","locale":"nl","number":1,"legal_version":null,"effective_date":null,"approved_by":null,"published_by":"Sanne","restored_from":null,"published_at":"2026-10-24T12:40:56+00:00"}}
```

**Public page**

`GET /api/v1/public/pages/nl/hoe-het-werkt`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-b172-7323-977f-67570e733efe

{"data":{"key":"hoe-het-werkt","type":"info","locale":"nl","slug":"hoe-het-werkt","content":{"body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Sparen en korting","type":"text"}]},{"type":"paragraph","content":[{"text":"Koop bij deelnemende webshops en ontvang tegoed.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"title":"Hoe ShopBonus werkt","seo_title":"Hoe ShopBonus werkt","seo_description":"Spaar en krijg korting bij webshops."},"legal":null,"revision":1,"published_at":"2026-10-24T12:40:56+00:00","alternates":{"nl":"hoe-het-werkt"}}}
```

**Save a new draft**

`PUT /api/v1/admin/cms/pages/01m5pyvbvf2apjnnekn18ghy0x/locales/nl {"revision":1,"content":{"title":"Zo werkt ShopBonus (concept)","body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Sparen"}]},{"type":"paragraph","content":[{"type":"text","text":"Nieuwe tekst."},{"type":"text","text":" Lees meer","marks":[{"type":"link","attrs":{"href":"/hulp"}}]}]}]}}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-b272-7168-b44d-507308b62fa8

{"data":{"locale":"nl","slug":"hoe-het-werkt","draft_revision":2}}
```

**Stale revision → 409**

`PUT /api/v1/admin/cms/pages/01m5pyvbvf2apjnnekn18ghy0x/locales/nl {"revision":1,"content":{"title":"Andere wijziging","body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"X"}]},{"type":"paragraph","content":[{"type":"text","text":"Y"},{"type":"text","text":" Lees meer","marks":[{"type":"link","attrs":{"href":"/hulp"}}]}]}]}}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16ded-b373-7381-a24b-447c5e87677f
Content-Type: application/json

{"error":{"status":409,"code":"draft_revision_conflict","message":"Someone else saved this page in the meantime. Reload to continue.","request_id":"01a16ded-b373-7381-a24b-447c5e87677f","details":{"current_revision":2}}}
```

**Preview (admin, no-store, noindex)**

`GET /api/v1/admin/cms/pages/01m5pyvbvf2apjnnekn18ghy0x/locales/nl/preview    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
X-Robots-Tag: noindex, nofollow
Content-Type: application/json
X-Request-Id: 01a16ded-b45f-73fb-a31c-1b60bf952e33

{"data":{"locale":"nl","slug":"hoe-het-werkt","content":{"body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Sparen","type":"text"}]},{"type":"paragraph","content":[{"text":"Nieuwe tekst.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"title":"Zo werkt ShopBonus (concept)"},"preview":true}}
```

**Preview as a consumer → 403**

`GET /api/v1/admin/cms/pages/01m5pyvbvf2apjnnekn18ghy0x/locales/nl/preview`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16ded-b4c4-70c5-9517-635ccae06f1f
Content-Type: application/json

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"01a16ded-b4c4-70c5-9517-635ccae06f1f"}}
```

**Publish again**

`POST /api/v1/admin/cms/pages/01m5pyvbvf2apjnnekn18ghy0x/locales/nl/publish    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-b5d9-708f-82fa-e6c6b37cb360

{"data":{"id":"01m5pyvdg7e09r8m0sschhpydv","locale":"nl","number":2,"legal_version":null,"effective_date":null,"approved_by":null,"published_by":"Sanne","restored_from":null,"published_at":"2026-10-24T12:40:57+00:00"}}
```

**Revisions**

`GET /api/v1/admin/cms/pages/01m5pyvbvf2apjnnekn18ghy0x/revisions    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-b7dd-717c-9b59-3d0fc8daf1aa

{"data":[{"id":"01m5pyvdg7e09r8m0sschhpydv","locale":"nl","number":2,"legal_version":null,"effective_date":null,"approved_by":null,"published_by":"Sanne","restored_from":null,"published_at":"2026-10-24T12:40:57+00:00"},{"id":"01m5pyvc8qszq81r9grjgngg75","locale":"nl","number":1,"legal_version":null,"effective_date":null,"approved_by":null,"published_by":"Sanne","restored_from":null,"published_at":"2026-10-24T12:40:56+00:00"}]}
```

**Restore revision 1 as a new revision**

`POST /api/v1/admin/cms/pages/01m5pyvbvf2apjnnekn18ghy0x/revisions/01m5pyvc8qszq81r9grjgngg75/restore    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-b9e8-7163-bb96-027de56f0231

{"data":{"id":"01m5pyvegtrct4x8x31b7qvq9e","locale":"nl","number":3,"legal_version":null,"effective_date":null,"approved_by":null,"published_by":"Sanne","restored_from":1,"published_at":"2026-10-24T12:40:58+00:00"}}
```

**Page detail**

`GET /api/v1/admin/cms/pages/01m5pyvbvf2apjnnekn18ghy0x    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-bb1b-73c7-b772-44131b2affdc

{"data":{"id":"01m5pyvbvf2apjnnekn18ghy0x","key":"hoe-het-werkt","type":"info","audience":"public","archived":false,"locales":{"en":{"slug":"how-it-works","published":false,"draft_revision":1,"draft_updated_at":"2026-10-24T12:40:55+00:00"},"nl":{"slug":"hoe-het-werkt","published":true,"draft_revision":3,"draft_updated_at":"2026-10-24T12:40:58+00:00"}},"locales_detail":{"en":{"slug":"how-it-works","draft_revision":1,"draft":{"body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Save and get discounts","type":"text"}]},{"type":"paragraph","content":[{"text":"Shop at participating webshops and receive credit.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"title":"How ShopBonus works"},"published":null},"nl":{"slug":"hoe-het-werkt","draft_revision":3,"draft":{"body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Sparen en korting","type":"text"}]},{"type":"paragraph","content":[{"text":"Koop bij deelnemende webshops en ontvang tegoed.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"title":"Hoe ShopBonus werkt","seo_title":"Hoe ShopBonus werkt","seo_description":"Spaar en krijg korting bij webshops."},"published":{"body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Sparen en korting","type":"text"}]},{"type":"paragraph","content":[{"text":"Koop bij deelnemende webshops en ontvang tegoed.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"title":"Hoe ShopBonus werkt","seo_title":"Hoe ShopBonus werkt","seo_description":"Spaar en krijg korting bij webshops."}}}}}
```

**Create the Terms**

`POST /api/v1/admin/cms/pages {"key":"voorwaarden","type":"terms","locales":{"nl":{"slug":"algemene-voorwaarden","content":{"title":"Algemene voorwaarden","body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Artikel 1"}]},{"type":"paragraph","content":[{"type":"text","text":"Definities."},{"type":"text","text":" Lees meer","marks":[{"type":"link","attrs":{"href":"/hulp"}}]}]}]}}}}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16ded-bc36-72dd-b31f-e9fae4eec830

{"data":{"id":"01m5pyvf2tq5wfm12tbmpp7ge2","key":"voorwaarden","type":"terms","audience":"public","archived":false,"locales":{"nl":{"slug":"algemene-voorwaarden","published":false,"draft_revision":1,"draft_updated_at":"2026-10-24T12:40:59+00:00"}}}}
```

**Legal publish without approval → 422**

`POST /api/v1/admin/cms/pages/01m5pyvf2tq5wfm12tbmpp7ge2/locales/nl/publish {"legal_version":"2026.11"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16ded-bd90-70ea-862c-334f8f611c32
Content-Type: application/json

{"error":{"status":422,"code":"legal_approval_required","message":"Legal pages need a version, an effective date and explicit approval to publish.","request_id":"01a16ded-bd90-70ea-862c-334f8f611c32"}}
```

**Legal publish (version, effective date, approval)**

`POST /api/v1/admin/cms/pages/01m5pyvf2tq5wfm12tbmpp7ge2/locales/nl/publish {"legal_version":"2026.11","effective_date":"2026-11-01","approve":true}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-be97-71bf-9a27-46c780ef4fa8

{"data":{"id":"01m5pyvfp6wz28e8aap1jq5fhy","locale":"nl","number":1,"legal_version":"2026.11","effective_date":"2026-11-01","approved_by":"Sanne","published_by":"Sanne","restored_from":null,"published_at":"2026-10-24T12:40:59+00:00"}}
```

**Public Terms**

`GET /api/v1/public/pages/nl/algemene-voorwaarden`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-bf2d-730f-b62c-3a3fa73000e3

{"data":{"key":"voorwaarden","type":"terms","locale":"nl","slug":"algemene-voorwaarden","content":{"body":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Artikel 1","type":"text"}]},{"type":"paragraph","content":[{"text":"Definities.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"title":"Algemene voorwaarden"},"legal":{"version":"2026.11","effective_date":"2026-11-01"},"revision":1,"published_at":"2026-10-24T12:40:59+00:00","alternates":{"nl":"algemene-voorwaarden"}}}
```

**Public page index**

`GET /api/v1/public/pages?locale=nl`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-bf95-7117-805e-163109de36cb

{"data":[{"key":"hoe-het-werkt","type":"info","slug":"hoe-het-werkt","title":"Hoe ShopBonus werkt"},{"key":"voorwaarden","type":"terms","slug":"algemene-voorwaarden","title":"Algemene voorwaarden"}]}
```

**Translation status**

`GET /api/v1/admin/cms/translations    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-c08d-71a2-bd04-fa5d9ea89a91

{"data":[{"page":"hoe-het-werkt","locale":"nl","status":"published"},{"page":"hoe-het-werkt","locale":"en","status":"unpublished"},{"page":"voorwaarden","locale":"nl","status":"published"},{"page":"voorwaarden","locale":"en","status":"missing"}]}
```

**Search pages**

`GET /api/v1/admin/cms/pages?search=voorwaarden    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-c20b-7209-8582-fa3628df4dce

{"data":[{"id":"01m5pyvf2tq5wfm12tbmpp7ge2","key":"voorwaarden","type":"terms","audience":"public","archived":false,"locales":{"nl":{"slug":"algemene-voorwaarden","published":true,"draft_revision":1,"draft_updated_at":"2026-10-24T12:40:59+00:00"}}}]}
```

**Upload an image (multipart)**

`POST /api/v1/admin/cms/assets (multipart: image=@hero.jpg;type=image/jpeg)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16ded-c307-71d9-a461-77d5050df2de

{"data":{"id":"01m5pyvgv6n5w84d2p0a8x63sc","width":800,"height":400,"url":"http:\/\/127.0.0.1:8798\/api\/v1\/public\/content-assets\/01m5pyvgv6n5w84d2p0a8x63sc"}}
```

**FAQ category**

`POST /api/v1/admin/cms/faq/categories {"key":"vouchers","names":{"nl":"Vouchers","en":"Vouchers"}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16ded-c465-722d-953a-4715091ba258

{"data":{"id":"01m5pyvh4nckwv3dg5qj5rb3j4","key":"vouchers","names":{"nl":"Vouchers","en":"Vouchers"},"position":1}}
```

**Public FAQ item**

`POST /api/v1/admin/cms/faq/items {"category_id":"01m5pyvh4nckwv3dg5qj5rb3j4","audience":"public","published":true,"translations":{"nl":{"question":"Hoe claim ik een voucher?","answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Claimen"}]},{"type":"paragraph","content":[{"type":"text","text":"Open de link en log in."},{"type":"text","text":" Lees meer","marks":[{"type":"link","attrs":{"href":"/hulp"}}]}]}]}},"en":{"question":"How do I claim a voucher?","answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Claiming"}]},{"type":"paragraph","content":[{"type":"text","text":"Open the link and sign in."},{"type":"text","text":" Lees meer","marks":[{"type":"link","attrs":{"href":"/hulp"}}]}]}]}}}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16ded-c599-701c-805f-77e236acd619

{"data":{"id":"01m5pyvhdybz2f4xm68g2whxen","audience":"public","published":true,"position":1,"revision":1,"translations":{"nl":{"question":"Hoe claim ik een voucher?","answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Claimen"}]},{"type":"paragraph","content":[{"type":"text","text":"Open de link en log in."},{"type":"text","text":"Lees meer","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]}},"en":{"question":"How do I claim a voucher?","answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Claiming"}]},{"type":"paragraph","content":[{"type":"text","text":"Open the link and sign in."},{"type":"text","text":"Lees meer","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]}}}}}
```

**Consumers-only FAQ item**

`POST /api/v1/admin/cms/faq/items {"category_id":"01m5pyvh4nckwv3dg5qj5rb3j4","audience":"consumers","published":true,"translations":{"nl":{"question":"Waar vind ik mijn code?","answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Code"}]},{"type":"paragraph","content":[{"type":"text","text":"In Mijn vouchers."},{"type":"text","text":" Lees meer","marks":[{"type":"link","attrs":{"href":"/hulp"}}]}]}]}}}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a16ded-c6e2-728a-b983-c4508da12294

{"data":{"id":"01m5pyvhqshtvd68zgcw6cz71k","audience":"consumers","published":true,"position":2,"revision":1,"translations":{"nl":{"question":"Waar vind ik mijn code?","answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Code"}]},{"type":"paragraph","content":[{"type":"text","text":"In Mijn vouchers."},{"type":"text","text":"Lees meer","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]}}}}}
```

**Update with the current revision**

`PUT /api/v1/admin/cms/faq/items/01m5pyvhdybz2f4xm68g2whxen {"revision":1,"translations":{"nl":{"question":"Hoe claim ik mijn voucher?","answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Claimen"}]},{"type":"paragraph","content":[{"type":"text","text":"Open de link en log in met je e-mailadres."},{"type":"text","text":" Lees meer","marks":[{"type":"link","attrs":{"href":"/hulp"}}]}]}]}}}}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-c7a2-71e9-926f-b50c41900e3d

{"data":{"id":"01m5pyvhdybz2f4xm68g2whxen","audience":"public","published":true,"position":1,"revision":2,"translations":{"nl":{"answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Claimen","type":"text"}]},{"type":"paragraph","content":[{"text":"Open de link en log in met je e-mailadres.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"question":"Hoe claim ik mijn voucher?"}}}}
```

**Stale → 409**

`PUT /api/v1/admin/cms/faq/items/01m5pyvhdybz2f4xm68g2whxen {"revision":1,"published":false}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16ded-c843-73f6-bd1d-e97ecc783c0c
Content-Type: application/json

{"error":{"status":409,"code":"draft_revision_conflict","message":"Someone else changed this item. Reload to continue.","request_id":"01a16ded-c843-73f6-bd1d-e97ecc783c0c"}}
```

**Reorder**

`POST /api/v1/admin/cms/faq/reorder {"category_id":"01m5pyvh4nckwv3dg5qj5rb3j4","items":["01m5pyvhqshtvd68zgcw6cz71k","01m5pyvhdybz2f4xm68g2whxen"]}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-c8d1-711d-b3eb-15cc10d77cee

{"data":[{"id":"01m5pyvh4nckwv3dg5qj5rb3j4","key":"vouchers","names":{"en":"Vouchers","nl":"Vouchers"},"position":1,"items":[{"id":"01m5pyvhqshtvd68zgcw6cz71k","audience":"consumers","published":true,"position":1,"revision":1,"translations":{"nl":{"answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Code","type":"text"}]},{"type":"paragraph","content":[{"text":"In Mijn vouchers.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"question":"Waar vind ik mijn code?"}}},{"id":"01m5pyvhdybz2f4xm68g2whxen","audience":"public","published":true,"position":2,"revision":2,"translations":{"nl":{"answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Claimen","type":"text"}]},{"type":"paragraph","content":[{"text":"Open de link en log in met je e-mailadres.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"question":"Hoe claim ik mijn voucher?"}}}]}]}
```

**Public FAQ, anonymous (no consumers-only items)**

`GET /api/v1/public/faq?locale=nl`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-c917-70c2-8c53-3abaefb63cc3

{"data":[{"key":"vouchers","name":"Vouchers","items":[{"id":"01m5pyvhdybz2f4xm68g2whxen","question":"Hoe claim ik mijn voucher?","answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Claimen","type":"text"}]},{"type":"paragraph","content":[{"text":"Open de link en log in met je e-mailadres.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"audience":"public","fallback":false}]}]}
```

**Public FAQ as Anna, searching "code"**

`GET /api/v1/public/faq?locale=nl&search=code`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-c94b-7368-a265-a395b34a0cd7

{"data":[{"key":"vouchers","name":"Vouchers","items":[{"id":"01m5pyvhqshtvd68zgcw6cz71k","question":"Waar vind ik mijn code?","answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Code","type":"text"}]},{"type":"paragraph","content":[{"text":"In Mijn vouchers.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"audience":"consumers","fallback":false}]}]}
```

**Admin FAQ**

`GET /api/v1/admin/cms/faq    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16ded-c9de-72d2-bceb-2b67a36403ca

{"data":[{"id":"01m5pyvh4nckwv3dg5qj5rb3j4","key":"vouchers","names":{"en":"Vouchers","nl":"Vouchers"},"position":1,"items":[{"id":"01m5pyvhqshtvd68zgcw6cz71k","audience":"consumers","published":true,"position":1,"revision":1,"translations":{"nl":{"answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Code","type":"text"}]},{"type":"paragraph","content":[{"text":"In Mijn vouchers.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"question":"Waar vind ik mijn code?"}}},{"id":"01m5pyvhdybz2f4xm68g2whxen","audience":"public","published":true,"position":2,"revision":2,"translations":{"nl":{"answer":{"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"text":"Claimen","type":"text"}]},{"type":"paragraph","content":[{"text":"Open de link en log in met je e-mailadres.","type":"text"},{"text":"Lees meer","type":"text","marks":[{"type":"link","attrs":{"href":"\/hulp"}}]}]}]},"question":"Hoe claim ik mijn voucher?"}}}]}]}
```

After the Terms were published as 2026.11, registration requires `terms_version: 2026.11` (`LegalConsents`). The Privacy version still comes from the env config until a Privacy page is published.

---

## Cookie consent and NL/EN API messages (B15b, Trello #5)

Captured 2026-10-24 against local MySQL. The admin used a step-up session, and the visitor was anonymous. Sending `Accept-Language` sets the language of validation and error messages. The response's `Content-Language` says which language was used; requests without a supported preference get English.

**No policy yet: necessary only**

`GET /api/v1/public/cookie-policy?locale=nl`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d708-72b5-970e-525445708bbe

{"data":{"version":null,"categories":[{"key":"necessary","required":true,"trackers":[]}],"texts":null,"requires_reconsent":false}}
```

**Create policy 2026.1**

`POST /api/v1/admin/cms/cookie-policies {"version":"2026.1","requires_reconsent":false,"texts":{"nl":{"title":"Cookies op ShopBonus","body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren","customize":"Aanpassen"},"en":{"title":"Cookies on ShopBonus","body":"We use necessary cookies and, with your consent, analytics and marketing cookies.","accept_all":"Accept all","reject_optional":"Reject optional","customize":"Customize"}},"categories":[{"key":"necessary","trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose_nl":"Ingelogd blijven","purpose_en":"Staying signed in","retention":"Sessie"}]},{"key":"preferences","trackers":[]},{"key":"analytics","trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose_nl":"Anonieme bezoekstatistieken","purpose_en":"Anonymous visit statistics","retention":"24 uur"}]},{"key":"marketing","trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose_nl":"Advertenties meten","purpose_en":"Measuring ads","retention":"90 dagen"}]}]}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d74f-70f2-b68a-8f657ddaac85

{"data":{"id":"01m5pzxnv6j8jd16cxmvwpnh96","status":"draft","version":"2026.1","requires_reconsent":false,"published_at":null,"texts":{"title":"Cookies op ShopBonus","body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren","customize":"Aanpassen"},"categories":[{"key":"necessary","required":true,"trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose":"Ingelogd blijven","retention":"Sessie"}]},{"key":"preferences","required":false,"trackers":[]},{"key":"analytics","required":false,"trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose":"Anonieme bezoekstatistieken","retention":"24 uur"}]},{"key":"marketing","required":false,"trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose":"Advertenties meten","retention":"90 dagen"}]}]}}
```

**Edit the draft**

`PUT /api/v1/admin/cms/cookie-policies/01m5pzxnv6j8jd16cxmvwpnh96 {"version":"2026.1","requires_reconsent":false,"texts":{"nl":{"title":"Cookies op ShopBonus","body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren","customize":"Aanpassen"},"en":{"title":"Cookies on ShopBonus","body":"We use necessary cookies and, with your consent, analytics and marketing cookies.","accept_all":"Accept all","reject_optional":"Reject optional","customize":"Customize"}},"categories":[{"key":"necessary","trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose_nl":"Ingelogd blijven","purpose_en":"Staying signed in","retention":"Sessie"}]},{"key":"preferences","trackers":[]},{"key":"analytics","trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose_nl":"Anonieme bezoekstatistieken","purpose_en":"Anonymous visit statistics","retention":"24 uur"}]},{"key":"marketing","trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose_nl":"Advertenties meten","purpose_en":"Measuring ads","retention":"90 dagen"}]}]}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d7b4-7200-bbe4-4af16b1d9aab

{"data":{"id":"01m5pzxnv6j8jd16cxmvwpnh96","status":"draft","version":"2026.1","requires_reconsent":false,"published_at":null,"texts":{"title":"Cookies op ShopBonus","body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren","customize":"Aanpassen"},"categories":[{"key":"necessary","required":true,"trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose":"Ingelogd blijven","retention":"Sessie"}]},{"key":"preferences","required":false,"trackers":[]},{"key":"analytics","required":false,"trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose":"Anonieme bezoekstatistieken","retention":"24 uur"}]},{"key":"marketing","required":false,"trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose":"Advertenties meten","retention":"90 dagen"}]}]}}
```

**Publish**

`POST /api/v1/admin/cms/cookie-policies/01m5pzxnv6j8jd16cxmvwpnh96/publish    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d803-7023-a03a-71fa17c04d9c

{"data":{"id":"01m5pzxnv6j8jd16cxmvwpnh96","status":"published","version":"2026.1","requires_reconsent":false,"published_at":"2026-10-24T12:59:40+00:00","texts":{"body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","title":"Cookies op ShopBonus","customize":"Aanpassen","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren"},"categories":[{"key":"necessary","required":true,"trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose":"Ingelogd blijven","retention":"Sessie"}]},{"key":"preferences","required":false,"trackers":[]},{"key":"analytics","required":false,"trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose":"Anonieme bezoekstatistieken","retention":"24 uur"}]},{"key":"marketing","required":false,"trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose":"Advertenties meten","retention":"90 dagen"}]}]}}
```

**Published policies are immutable → 409**

`PUT /api/v1/admin/cms/cookie-policies/01m5pzxnv6j8jd16cxmvwpnh96 {"version":"2026.1","requires_reconsent":false,"texts":{"nl":{"title":"Cookies op ShopBonus","body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren","customize":"Aanpassen"},"en":{"title":"Cookies on ShopBonus","body":"We use necessary cookies and, with your consent, analytics and marketing cookies.","accept_all":"Accept all","reject_optional":"Reject optional","customize":"Customize"}},"categories":[{"key":"necessary","trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose_nl":"Ingelogd blijven","purpose_en":"Staying signed in","retention":"Sessie"}]},{"key":"preferences","trackers":[]},{"key":"analytics","trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose_nl":"Anonieme bezoekstatistieken","purpose_en":"Anonymous visit statistics","retention":"24 uur"}]},{"key":"marketing","trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose_nl":"Advertenties meten","purpose_en":"Measuring ads","retention":"90 dagen"}]}]}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16dfe-d856-716c-aae9-08e5e67c5997
Content-Type: application/json
Content-Language: en

{"error":{"status":409,"code":"policy_not_draft","message":"Published policies cannot change; create a new version.","request_id":"01a16dfe-d856-716c-aae9-08e5e67c5997"}}
```

**Banner policy (NL)**

`GET /api/v1/public/cookie-policy?locale=nl`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d87b-73fe-b71b-57567decf83a

{"data":{"version":"2026.1","requires_reconsent":false,"published_at":"2026-10-24T12:59:40+00:00","texts":{"body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","title":"Cookies op ShopBonus","customize":"Aanpassen","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren"},"categories":[{"key":"necessary","required":true,"trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose":"Ingelogd blijven","retention":"Sessie"}]},{"key":"preferences","required":false,"trackers":[]},{"key":"analytics","required":false,"trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose":"Anonieme bezoekstatistieken","retention":"24 uur"}]},{"key":"marketing","required":false,"trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose":"Advertenties meten","retention":"90 dagen"}]}]}}
```

**Reject optional (new consent ID)**

`POST /api/v1/public/cookie-consents {"policy_version":"2026.1","action":"reject_optional"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d896-700b-a84e-8bb1e7c6eaa7

{"data":{"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.1","action":"reject_optional","choices":{"necessary":true,"preferences":false,"analytics":false,"marketing":false},"recorded_at":"2026-10-24T12:59:40+00:00","allowed_trackers":["session"]}}
```

**Customize: analytics only**

`POST /api/v1/public/cookie-consents {"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.1","action":"customize","choices":{"analytics":true}}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d8be-7041-8ba7-2e7d1000942f

{"data":{"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.1","action":"customize","choices":{"necessary":true,"preferences":false,"analytics":true,"marketing":false},"recorded_at":"2026-10-24T12:59:40+00:00","allowed_trackers":["session","plausible"]}}
```

**Status**

`GET /api/v1/public/cookie-consents/8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d8de-7215-8ab1-3d48ff470399

{"data":{"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.1","action":"customize","choices":{"analytics":true,"marketing":false,"necessary":true,"preferences":false},"recorded_at":"2026-10-24T12:59:40+00:00","current_policy_version":"2026.1","needs_renewal":false,"allowed_trackers":["session","plausible"]}}
```

**Withdraw**

`POST /api/v1/public/cookie-consents {"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.1","action":"withdraw"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d8fb-7277-8ff1-09d523b4b8b3

{"data":{"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.1","action":"withdraw","choices":{"necessary":true,"preferences":false,"analytics":false,"marketing":false},"recorded_at":"2026-10-24T12:59:40+00:00","allowed_trackers":["session"]}}
```

**Policy 2026.2 requiring renewed consent**

`POST /api/v1/admin/cms/cookie-policies {"version":"2026.2","requires_reconsent":true,"texts":{"nl":{"title":"Cookies op ShopBonus","body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren","customize":"Aanpassen"},"en":{"title":"Cookies on ShopBonus","body":"We use necessary cookies and, with your consent, analytics and marketing cookies.","accept_all":"Accept all","reject_optional":"Reject optional","customize":"Customize"}},"categories":[{"key":"necessary","trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose_nl":"Ingelogd blijven","purpose_en":"Staying signed in","retention":"Sessie"}]},{"key":"preferences","trackers":[]},{"key":"analytics","trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose_nl":"Anonieme bezoekstatistieken","purpose_en":"Anonymous visit statistics","retention":"24 uur"}]},{"key":"marketing","trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose_nl":"Advertenties meten","purpose_en":"Measuring ads","retention":"90 dagen"}]}]}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d945-7384-b1ee-b97e97d15479

{"data":{"id":"01m5pzxpagmfpbh19v6pmxbq4p","status":"draft","version":"2026.2","requires_reconsent":true,"published_at":null,"texts":{"title":"Cookies op ShopBonus","body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren","customize":"Aanpassen"},"categories":[{"key":"necessary","required":true,"trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose":"Ingelogd blijven","retention":"Sessie"}]},{"key":"preferences","required":false,"trackers":[]},{"key":"analytics","required":false,"trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose":"Anonieme bezoekstatistieken","retention":"24 uur"}]},{"key":"marketing","required":false,"trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose":"Advertenties meten","retention":"90 dagen"}]}]}}
```

**Publish it**

`POST /api/v1/admin/cms/cookie-policies/01m5pzxpagmfpbh19v6pmxbq4p/publish    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d99c-72b7-a0ab-c24c969d5165

{"data":{"id":"01m5pzxpagmfpbh19v6pmxbq4p","status":"published","version":"2026.2","requires_reconsent":true,"published_at":"2026-10-24T12:59:40+00:00","texts":{"body":"Wij gebruiken noodzakelijke cookies en, met jouw toestemming, statistiek- en marketingcookies.","title":"Cookies op ShopBonus","customize":"Aanpassen","accept_all":"Alles accepteren","reject_optional":"Optionele weigeren"},"categories":[{"key":"necessary","required":true,"trackers":[{"key":"session","name":"Sessie","provider":"ShopBonus","purpose":"Ingelogd blijven","retention":"Sessie"}]},{"key":"preferences","required":false,"trackers":[]},{"key":"analytics","required":false,"trackers":[{"key":"plausible","name":"Plausible Analytics","provider":"Plausible","purpose":"Anonieme bezoekstatistieken","retention":"24 uur"}]},{"key":"marketing","required":false,"trackers":[{"key":"meta_pixel","name":"Meta Pixel","provider":"Meta","purpose":"Advertenties meten","retention":"90 dagen"}]}]}}
```

**Old choice needs renewal; nothing may load**

`GET /api/v1/public/cookie-consents/8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d9bf-73cd-9951-5862d483bfb0

{"data":{"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.1","action":"withdraw","choices":{"analytics":false,"marketing":false,"necessary":true,"preferences":false},"recorded_at":"2026-10-24T12:59:40+00:00","current_policy_version":"2026.2","needs_renewal":true,"allowed_trackers":[]}}
```

**Choosing under the old version → 409**

`POST /api/v1/public/cookie-consents {"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.1","action":"accept_all"}`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16dfe-d9da-70ae-a369-609cf76c8dfe
Content-Type: application/json
Content-Language: en

{"error":{"status":409,"code":"policy_outdated","message":"The cookie policy changed. Reload to see the current choices.","request_id":"01a16dfe-d9da-70ae-a369-609cf76c8dfe","details":{"current_version":"2026.2"}}}
```

**Accept all under 2026.2**

`POST /api/v1/public/cookie-consents {"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.2","action":"accept_all"}`

```http
HTTP/1.1 201 Created
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-d9f6-70d2-9472-ec814f4de03e

{"data":{"consent_id":"8c8f1ceb-adad-46fd-ad27-1c6ff0418c5f","policy_version":"2026.2","action":"accept_all","choices":{"necessary":true,"preferences":true,"analytics":true,"marketing":true},"recorded_at":"2026-10-24T12:59:40+00:00","allowed_trackers":["session","plausible","meta_pixel"]}}
```

**Versions**

`GET /api/v1/admin/cms/cookie-policies    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-da3f-7090-9a4a-1c3568164789

{"data":[{"id":"01m5pzxpagmfpbh19v6pmxbq4p","version":"2026.2","status":"published","requires_reconsent":true,"published_at":"2026-10-24T12:59:40+00:00"},{"id":"01m5pzxnv6j8jd16cxmvwpnh96","version":"2026.1","status":"retired","requires_reconsent":false,"published_at":"2026-10-24T12:59:40+00:00"}]}
```

**Statistics**

`GET /api/v1/admin/cms/cookie-consents/stats    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16dfe-da8a-7399-8729-2215c6620a4a

{"data":{"version":"2026.2","by_action":{"accept_all":1}}}
```

**Validation in Dutch (Accept-Language: nl)**

`POST /api/v1/public/cookie-consents {}   (Accept-Language: nl-NL,nl;q=0.9)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16dfe-daac-7396-9c61-e2190c9eb7bd
Content-Type: application/json
Content-Language: nl

{"error":{"status":422,"code":"validation_failed","message":"De ingevulde gegevens zijn ongeldig.","request_id":"01a16dfe-daac-7396-9c61-e2190c9eb7bd","fields":{"policy_version":["Beleidsversie is verplicht."],"action":["Actie is verplicht."]}}}
```

**Validation in English (Accept-Language: en)**

`POST /api/v1/public/cookie-consents {}   (Accept-Language: en)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16dfe-dac7-7309-ac5d-749097c0466a
Content-Type: application/json
Content-Language: en

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16dfe-dac7-7309-ac5d-749097c0466a","fields":{"policy_version":["The policy version field is required."],"action":["The action field is required."]}}}
```

---

## Admin two-factor, step-up codes and support sessions (B16a, Trello #21, #4)

Captured 2026-10-24 against local MySQL with `ADMIN_MFA_REQUIRED=true`, `QUEUE_CONNECTION=sync` and `MAIL_MAILER=log`, so the step-up code was read from the mail log. The admin used a browser session (cookie + XSRF) and the support requests used the bearer token. Redacted: the TOTP secret, the QR code (it encodes the secret), the recovery codes and the plain support tokens. Request IDs and timestamps are real.

Flow for the admin SPA:
1. **2FA enrolment (once):** confirm the password, then POST /user/two-factor-authentication. Show the QR code or secret, then POST /user/confirmed-two-factor-authentication with a code. Show the recovery codes.
2. **Login:** POST /login answers `{"two_factor": true}`, then POST /two-factor-challenge with `code` or `recovery_code`.
3. **Sensitive action:** POST /user/confirm-password, then POST /api/v1/admin/step-up/code, then POST /api/v1/admin/step-up/verify, then the action.
4. **Support session:** the start response returns the token. Send it as Bearer without cookies, and show the banner from `GET /api/v1/me` → `support_session`. End the session with `POST /admin/support-sessions/{id}/end` or `DELETE /api/v1/support-session`.

**Admin without confirmed 2FA → 403 mfa_required**

`GET /api/v1/admin/support-sessions    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16e0f-13ee-70dd-b5e2-368d5d5ed165
Content-Type: application/json
Content-Language: en

{"error":{"status":403,"code":"mfa_required","message":"Enable two-factor authentication to use the admin area.","request_id":"01a16e0f-13ee-70dd-b5e2-368d5d5ed165"}}
```

**Enable 2FA (pending confirmation)**

`POST /user/two-factor-authentication    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16e0f-1455-734b-92af-b5e7fb5eb254

""
```

**QR code (redacted)**

`GET /user/two-factor-qr-code    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16e0f-14b7-7108-806b-4bc5b8d6e696

{"svg": "<svg xmlns=\"http://www.w3.org/2000/svg\" ...>(QR code, redacted: it encodes the secret)</svg>", "url": "otpauth://totp/Shopbonus:admin.affiliate%40example.nl?secret=<redacted>&issuer=Shopbonus&algorithm=SHA1&digits=6&period=30"}
```

**Secret for manual entry (redacted)**

`GET /user/two-factor-secret-key    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16e0f-19d5-7197-ac6c-cfbb047a55cc

{"secretKey":"<base32 TOTP secret, redacted>"}
```

**Confirm with a wrong code → 422**

`POST /user/confirmed-two-factor-authentication {"code":"000000"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16e0f-1a2c-703c-a807-c18dce4e08aa
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16e0f-1a2c-703c-a807-c18dce4e08aa","fields":{"code":["The provided two factor authentication code was invalid."]}}}
```

**Confirm with the TOTP code**

`POST /user/confirmed-two-factor-authentication {"code":"<6-digit code>"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16e0f-1af3-728b-a4d3-49c137c97b71

""
```

**Recovery codes (redacted)**

`GET /user/two-factor-recovery-codes    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16e0f-1b8a-72c1-a5b9-7072b8057b00

["<recovery code 1, redacted>", "<recovery code 2, redacted>", "<recovery code 3, redacted>", "<recovery code 4, redacted>", "<recovery code 5, redacted>", "<recovery code 6, redacted>", "<recovery code 7, redacted>", "<recovery code 8, redacted>"]
```

**Regenerate recovery codes**

`POST /user/two-factor-recovery-codes    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16e0f-1be2-7166-91aa-ac0b201a82fc

""
```

**/me shows two_factor_enabled**

`GET /api/v1/me    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-1c92-72ef-86de-3f1faad9e0bf

{"data":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":["super_admin"],"workspaces":[],"destination":"admin","two_factor_enabled":true,"support_session":null}}
```

**Login now stops at the second factor**

`POST /login {"email":"admin.affiliate@example.nl","password":"Admin-wachtwoord-2026"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16e0f-2640-7102-a899-d8c27955b743

{"two_factor":true}
```

**Wrong challenge code → 422**

`POST /two-factor-challenge {"code":"000000"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16e0f-2799-72ea-b600-a21cc8e9e3e1
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16e0f-2799-72ea-b600-a21cc8e9e3e1","fields":{"code":["The provided two factor authentication code was invalid."]}}}
```

**Challenge with a recovery code → signed in**

`POST /two-factor-challenge {"recovery_code":"<one recovery code>"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16e0f-27f2-71f1-a358-829eb103ef7a

{"data":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":["super_admin"],"workspaces":[],"destination":"admin","two_factor_enabled":true,"support_session":null}}
```

**Admin API reachable**

`GET /api/v1/admin/support-sessions    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-2852-722a-b7a9-31d1ddae6c03

{"data":[{"id":"01m5q0wrqmhwzb4v787rtnpc0k","status":"ended","admin":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl"},"target":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl"},"reason":"Ticket 4412: profiel controleren","started_at":"2026-10-24T13:16:39+00:00","expires_at":"2026-10-24T13:46:39+00:00","ended_at":"2026-10-24T13:16:39+00:00","ended_reason":"ended_by_session"},{"id":"01m5q0tvyn6d67mxww3zxsda54","status":"ended","admin":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl"},"target":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl"},"reason":"Ticket 4411: klant ziet voucher niet","started_at":"2026-10-24T13:15:36+00:00","expires_at":"2026-10-24T13:45:36+00:00","ended_at":"2026-10-24T13:15:37+00:00","ended_reason":"ended_by_admin"}]}
```

**Step-up code without recent password → 423**

`POST /api/v1/admin/step-up/code    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 423 Locked
X-Request-Id: 01a16e0f-28ca-72e8-b325-1804a1ac38b0
Content-Type: application/json
Content-Language: en

{"error":{"status":423,"code":"password_confirmation_required","message":"Confirm your password to continue.","request_id":"01a16e0f-28ca-72e8-b325-1804a1ac38b0"}}
```

**Start without emailed code → 423**

`POST /api/v1/admin/support-sessions {"user_id":"<target user id>","reason":"Ticket 4411: klant ziet voucher niet"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 423 Locked
X-Request-Id: 01a16e0f-2a64-72a5-b020-27eeae272006
Content-Type: application/json
Content-Language: en

{"error":{"status":423,"code":"step_up_code_required","message":"Confirm this action with the code sent to your email.","request_id":"01a16e0f-2a64-72a5-b020-27eeae272006"}}
```

**Request a step-up code**

`POST /api/v1/admin/step-up/code    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-2ad1-710f-9b3f-357497ec68e9

{"data":{"sent":true,"expires_at":"2026-10-24T13:27:30+00:00"}}
```

**Second request within 60 s → 429**

`POST /api/v1/admin/step-up/code    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 60
X-Request-Id: 01a16e0f-2c10-703f-b26f-ba43e5c52cf9
Content-Type: application/json
Content-Language: en

{"error":{"status":429,"code":"step_up_resend_too_soon","message":"Please wait before requesting a new code.","request_id":"01a16e0f-2c10-703f-b26f-ba43e5c52cf9","details":{"retry_after_seconds":60}}}
```

**Wrong code → 422**

`POST /api/v1/admin/step-up/verify {"code":"000000"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16e0f-2c5f-7272-9481-1a5a15e976dd
Content-Type: application/json
Content-Language: en

{"error":{"status":422,"code":"step_up_code_invalid","message":"This code is not valid.","request_id":"01a16e0f-2c5f-7272-9481-1a5a15e976dd"}}
```

**Correct code**

`POST /api/v1/admin/step-up/verify {"code":"<6-digit code>"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-2cbe-720a-8c13-3c9ee7bcfe86

{"data":{"confirmed":true,"valid_until":"2026-10-24T13:27:30+00:00"}}
```

**Start a support session (token redacted)**

`POST /api/v1/admin/support-sessions {"user_id":"<target user id>","reason":"Ticket 4411: klant ziet voucher niet"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-2d24-737a-8b03-fe664dc6015f

{"data":{"id":"01m5q0yba2j69fjv28qxfgvk2m","status":"active","admin":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl"},"target":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl"},"reason":"Ticket 4411: klant ziet voucher niet","started_at":"2026-10-24T13:17:30+00:00","expires_at":"2026-10-24T13:47:30+00:00","ended_at":null,"ended_reason":null,"token":"<plain support token, shown once; redacted>","token_type":"Bearer"}}
```

**/me with the support token: target + banner data**

`GET /api/v1/me`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-2d73-714b-84d8-9f4b94d07ea3

{"data":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":[],"workspaces":[],"destination":"consumer_dashboard","two_factor_enabled":false,"support_session":{"id":"01m5q0yba2j69fjv28qxfgvk2m","admin":{"first_name":"Sanne","email":"admin.affiliate@example.nl"},"reason":"Ticket 4411: klant ziet voucher niet","expires_at":"2026-10-24T13:47:30+00:00"}}}
```

**Allowed: profile update as the target (audited with the admin as real actor)**

`PATCH /api/v1/consumer/profile {"first_name":"Anna"}`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-2d9f-7184-b1e2-d07956db9da2

{"data":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl","email_verification":"verified","pending_email":null,"phone":null,"locale":"nl","avatar_url":null,"security":{"email_verified":true,"two_factor":"disabled"},"notifications":{"vouchers_discounts":true,"reminders":true,"offers_inspiration":false}}}
```

**Blocked: email change → 403**

`POST /api/v1/consumer/email-change {"email":"ander@example.nl"}`

```http
HTTP/1.1 403 Forbidden
X-Request-Id: 01a16e0f-2dcb-7067-9002-aec7a0a40550
Content-Type: application/json
Content-Language: en

{"error":{"status":403,"code":"blocked_during_impersonation","message":"This action is not available during a support session.","request_id":"01a16e0f-2dcb-7067-9002-aec7a0a40550"}}
```

**Session cookie and support token together → 400**

`GET /api/v1/me   (session cookie AND Authorization: Bearer <support token>)`

```http
HTTP/1.1 400 Bad Request
X-Request-Id: 01a16e0f-2df1-7140-b055-a94845d6bd4f
Content-Type: application/json
Content-Language: en

{"error":{"status":400,"code":"ambiguous_credentials","message":"Send either the session cookie or a bearer token, not both.","request_id":"01a16e0f-2df1-7140-b055-a94845d6bd4f"}}
```

**Sessions list**

`GET /api/v1/admin/support-sessions    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-2e39-712a-ad5f-a06b41fbe4e0

{"data":[{"id":"01m5q0yba2j69fjv28qxfgvk2m","status":"active","admin":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl"},"target":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl"},"reason":"Ticket 4411: klant ziet voucher niet","started_at":"2026-10-24T13:17:30+00:00","expires_at":"2026-10-24T13:47:30+00:00","ended_at":null,"ended_reason":null},{"id":"01m5q0wrqmhwzb4v787rtnpc0k","status":"ended","admin":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl"},"target":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl"},"reason":"Ticket 4412: profiel controleren","started_at":"2026-10-24T13:16:39+00:00","expires_at":"2026-10-24T13:46:39+00:00","ended_at":"2026-10-24T13:16:39+00:00","ended_reason":"ended_by_session"},{"id":"01m5q0tvyn6d67mxww3zxsda54","status":"ended","admin":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl"},"target":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl"},"reason":"Ticket 4411: klant ziet voucher niet","started_at":"2026-10-24T13:15:36+00:00","expires_at":"2026-10-24T13:45:36+00:00","ended_at":"2026-10-24T13:15:37+00:00","ended_reason":"ended_by_admin"}]}
```

**Session with action history**

`GET /api/v1/admin/support-sessions/<session id>    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-2e90-7264-96cf-21301507edd9

{"data":{"id":"01m5q0yba2j69fjv28qxfgvk2m","status":"active","admin":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl"},"target":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl"},"reason":"Ticket 4411: klant ziet voucher niet","started_at":"2026-10-24T13:17:30+00:00","expires_at":"2026-10-24T13:47:30+00:00","ended_at":null,"ended_reason":null,"actions":[{"occurred_at":"2026-10-24T13:17:30+00:00","action":"support_session.started","outcome":"success","http_method":"POST","route":"api\/v1\/admin\/support-sessions","route_name":null,"http_status":null,"request_id":"01a16e0f-2d24-737a-8b03-fe664dc6015f"},{"occurred_at":"2026-10-24T13:17:30+00:00","action":"support_session.request","outcome":"success","http_method":"GET","route":"api\/v1\/me","route_name":"api.v1.me","http_status":200,"request_id":"01a16e0f-2d73-714b-84d8-9f4b94d07ea3"},{"occurred_at":"2026-10-24T13:17:30+00:00","action":"support_session.request","outcome":"success","http_method":"PATCH","route":"api\/v1\/consumer\/profile","route_name":"api.v1.consumer.profile.update","http_status":200,"request_id":"01a16e0f-2d9f-7184-b1e2-d07956db9da2"},{"occurred_at":"2026-10-24T13:17:30+00:00","action":"support_session.request","outcome":"denied","http_method":"POST","route":"api\/v1\/consumer\/email-change","route_name":"api.v1.consumer.email-change.store","http_status":403,"request_id":"01a16e0f-2dcb-7067-9002-aec7a0a40550"}]}}
```

**Admin ends the session**

`POST /api/v1/admin/support-sessions/<session id>/end    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e0f-2eea-7020-8861-6fb175b43e8c

{"data":{"id":"01m5q0yba2j69fjv28qxfgvk2m","status":"ended","admin":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl"},"target":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl"},"reason":"Ticket 4411: klant ziet voucher niet","started_at":"2026-10-24T13:17:30+00:00","expires_at":"2026-10-24T13:47:30+00:00","ended_at":"2026-10-24T13:17:31+00:00","ended_reason":"ended_by_admin"}}
```

**Token after end → 401**

`GET /api/v1/me`

```http
HTTP/1.1 401 Unauthorized
X-Request-Id: 01a16e0f-2f25-7106-8d62-bee26754edf6
Content-Type: application/json
Content-Language: en

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a16e0f-2f25-7106-8d62-bee26754edf6"}}
```

**DELETE /support-session from the admin session → 404**

`DELETE /api/v1/support-session    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 404 Not Found
X-Request-Id: 01a16e0f-2f7a-718a-962c-62bbfea98b41
Content-Type: application/json
Content-Language: en

{"error":{"status":404,"code":"not_in_support_session","message":"This request is not part of a support session.","request_id":"01a16e0f-2f7a-718a-962c-62bbfea98b41"}}
```

**Self-end from the banner**

`DELETE /api/v1/support-session`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e10-2064-70f8-9fc8-82ed0d72bfc7

{"data":{"id":"01m5q1081t0zbh3nhvgjrffeb1","status":"ended","admin":{"id":"01m5pchsnh8j21gcjrtd7vvekg","first_name":"Sanne","email":"admin.affiliate@example.nl"},"target":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl"},"reason":"Ticket 4412: profiel controleren","started_at":"2026-10-24T13:18:33+00:00","expires_at":"2026-10-24T13:48:33+00:00","ended_at":"2026-10-24T13:18:33+00:00","ended_reason":"ended_by_session"}}
```

**Next start needs a new emailed code → 423**

`POST /api/v1/admin/support-sessions {"user_id":"<target user id>","reason":"Ticket 4413: nog een keer"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 423 Locked
X-Request-Id: 01a16e10-2121-7128-8e86-b24f77a49d7a
Content-Type: application/json
Content-Language: en

{"error":{"status":423,"code":"step_up_code_required","message":"Confirm this action with the code sent to your email.","request_id":"01a16e10-2121-7128-8e86-b24f77a49d7a"}}
```

**Turn 2FA off again (local reset)**

`DELETE /user/two-factor-authentication    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16e10-2179-718d-b356-b69772e29d55

""
```

---

## Super Admin cockpit and user administration (B16b, Trello #21)

Captured 2026-10-24 against local MySQL. The admin used a browser session with a recent password confirmation and the server ran with `ADMIN_MFA_REQUIRED=false`; in production the admin also needs confirmed 2FA (see B16a). The step-up code was read from the log mailer. The local data set is small: figures such as `access_denials_in_period` come from earlier captures and tests.

**Cockpit, last 7 days**

`GET /api/v1/admin/cockpit?period=7d    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-7c40-73d8-8c2c-b52d423a1c75

{"data":{"generated_at":"2026-10-24T13:38:36+00:00","cache_seconds":60,"period":{"from":"2026-10-17T22:00:00+00:00","to":"2026-10-24T21:59:59+00:00"},"kpis":{"webshops":{"total":2,"active":2,"pending":0,"pending_review":0,"suspended":0,"rejected":0},"consumers":{"registered":4,"new_in_period":4,"active_in_period":2},"offers":{"published":4},"vouchers":{"issued_in_period":5,"redeemed_in_period":2,"outstanding":3,"available_codes":0},"orders":{"received_in_period":0,"confirmed_paid_in_period":0},"answer24":{"requests_in_period":0,"by_status":[]},"affiliate_commissions":{"pending":{"count":0,"by_currency":[]},"approved":{"count":0,"by_currency":[]},"rejected":{"count":0,"by_currency":[]}},"wallets":{"eur_credit":{"issued_in_period":3100,"spent_in_period":3100,"expired_in_period":0,"outstanding":0,"pending":0}},"disputes":{"open":0},"incidents":{"application_errors_in_period":2,"failed_jobs":0,"failed_webhooks_in_period":0,"failed_outbox":0,"open_reconciliation_flags":0,"paid_unfulfilled_checkouts":0,"access_denials_in_period":27}},"trends":{"timezone":"Europe\/Amsterdam","days":[{"date":"2026-10-18","new_webshops":0,"new_consumers":0,"paid_orders":0,"vouchers_issued":0,"vouchers_redeemed":0},{"date":"2026-10-19","new_webshops":0,"new_consumers":0,"paid_orders":0,"vouchers_issued":0,"vouchers_redeemed":0},{"date":"2026-10-20","new_webshops":0,"new_consumers":0,"paid_orders":0,"vouchers_issued":0,"vouchers_redeemed":0},{"date":"2026-10-21","new_webshops":0,"new_consumers":0,"paid_orders":0,"vouchers_issued":0,"vouchers_redeemed":0},{"date":"2026-10-22","new_webshops":0,"new_consumers":0,"paid_orders":0,"vouchers_issued":0,"vouchers_redeemed":0},{"date":"2026-10-23","new_webshops":1,"new_consumers":3,"paid_orders":0,"vouchers_issued":1,"vouchers_redeemed":0},{"date":"2026-10-24","new_webshops":1,"new_consumers":1,"paid_orders":0,"vouchers_issued":4,"vouchers_redeemed":2}]},"attention":{"pending_approvals":[],"integration_failures":[],"failed_jobs":[],"reconciliation_flags":[],"paid_unfulfilled_checkouts":[],"affiliate_problems":0,"security_denials_24h":[{"route":"api\/v1\/banners\/editor\/pair","http_status":401,"count":6},{"route":"api\/v1\/consumer\/email-change","http_status":403,"count":4},{"route":"api\/v1\/admin\/affiliate\/connections","http_status":403,"count":3},{"route":"api\/v1\/banners\/config","http_status":403,"count":3},{"route":"api\/v1\/banners\/editor\/campaigns\/{banner}","http_status":401,"count":3},{"route":"api\/v1\/admin\/support-sessions","http_status":403,"count":2},{"route":"api\/v1\/me","http_status":401,"count":2},{"route":"api\/v1\/consumer\/profile","http_status":401,"count":1},{"route":"api\/v1\/consumer\/wallet","http_status":401,"count":1},{"route":"api\/v1\/admin\/disputes","http_status":403,"count":1}],"recent_admin_actions":[{"action":"admin.webshop_viewed","outcome":"success","occurred_at":"2026-10-24T13:36:54+00:00"},{"action":"admin.webshop_information_requested","outcome":"success","occurred_at":"2026-10-24T13:36:53+00:00"},{"action":"admin.membership_revoked_reason","outcome":"success","occurred_at":"2026-10-24T13:36:53+00:00"},{"action":"admin.user_security_reset","outcome":"success","occurred_at":"2026-10-24T13:36:53+00:00"},{"action":"admin.user_reactivated","outcome":"success","occurred_at":"2026-10-24T13:36:53+00:00"},{"action":"admin.user_suspended","outcome":"success","occurred_at":"2026-10-24T13:36:52+00:00"},{"action":"admin.user_viewed","outcome":"success","occurred_at":"2026-10-24T13:36:52+00:00"},{"action":"admin.webshop_viewed","outcome":"success","occurred_at":"2026-10-24T13:36:26+00:00"},{"action":"admin.webshop_information_requested","outcome":"success","occurred_at":"2026-10-24T13:36:26+00:00"},{"action":"admin.membership_revoked_reason","outcome":"success","occurred_at":"2026-10-24T13:36:26+00:00"}]},"definitions":{"webshops.active":"Status approved.","webshops.pending":"Status draft, awaiting_verification, verification_failed or pending_review; pending_review is waiting for an admin.","consumers.registered":"Accounts that signed up with the consumer intent and are not deleted.","consumers.active_in_period":"Consumers who signed in during the period.","offers.published":"Offers with status active.","vouchers.issued_in_period":"Vouchers whose issued_at is in the period (reserved claims are not issued).","vouchers.outstanding":"Issued, not yet redeemed, expired or cancelled.","vouchers.available_codes":"Codes in inventory that are not reserved, issued, redeemed or invalid.","orders.confirmed_paid_in_period":"Orders whose paid_at (provider-confirmed payment) is in the period.","affiliate_commissions":"Network commissions by our status, summed per currency; pending, approved and rejected are never added together.","wallets":"Consumer wallets per unit: credits issued, debits spent and expiries in the period; outstanding = available + reserved now; pending (not yet credited) is separate.","incidents":"Counts of recorded technical faults and failures; access denials are audited 401\/403 responses."},"period_name":"7d","timezone":"Europe\/Amsterdam"}}
```

**Custom range over a year → 422**

`GET /api/v1/admin/cockpit?period=custom&from=2025-01-01&to=2026-10-24    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16e22-7cd3-72c8-906f-8491102285b3
Content-Type: application/json
Content-Language: en

{"error":{"status":422,"code":"range_too_long","message":"Choose a range of at most one year.","request_id":"01a16e22-7cd3-72c8-906f-8491102285b3"}}
```

**Activity (admin category)**

`GET /api/v1/admin/activity?category=admin&per_page=3    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-7d40-71b4-8407-74dad65468ba

{"data":[{"occurred_at":"2026-10-24T13:36:54+00:00","category":"admin","action":"admin.webshop_viewed","outcome":"success","actor":{"id":"01m5pchsnh8j21gcjrtd7vvekg","email":"admin.affiliate@example.nl"},"real_actor":null,"webshop":{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co"},"http_status":null,"http_method":"GET","route":"api\/v1\/admin\/webshops\/{webshop}","request_id":"01a16e20-ed6e-70f9-a87e-0e8900499472"},{"occurred_at":"2026-10-24T13:36:53+00:00","category":"admin","action":"admin.webshop_information_requested","outcome":"success","actor":{"id":"01m5pchsnh8j21gcjrtd7vvekg","email":"admin.affiliate@example.nl"},"real_actor":null,"webshop":{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co"},"http_status":null,"http_method":"POST","route":"api\/v1\/admin\/webshops\/{webshop}\/request-information","request_id":"01a16e20-ecbc-73b4-9010-a60b8e9c1502"},{"occurred_at":"2026-10-24T13:36:53+00:00","category":"admin","action":"admin.membership_revoked_reason","outcome":"success","actor":{"id":"01m5pchsnh8j21gcjrtd7vvekg","email":"admin.affiliate@example.nl"},"real_actor":null,"webshop":{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co"},"http_status":null,"http_method":"POST","route":"api\/v1\/admin\/webshops\/{webshop}\/members\/{user}\/revoke","request_id":"01a16e20-ec1b-72b2-ade3-c0da498b7ca0"}],"meta":{"current_page":1,"per_page":3,"total":49,"last_page":17}}
```

**Users with an active membership**

`GET /api/v1/admin/users?role=merchant    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-7dac-7168-bcd3-6676e72291e8

{"data":[{"id":"01m5n6z6zsmygsqghb9z5qx0zc","first_name":"Tess","email":"bob.wallet@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","roles":[],"workspaces":[{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","role":"employee"}],"two_factor_enabled":false,"registered_at":"2026-10-23T20:24:21+00:00"},{"id":"01m5n6398s6hzh93gk32h1n1ye","first_name":"Jan","email":"jan@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","roles":[],"workspaces":[{"id":"01m5n6399tnc5h1vtfyf3gsgv4","name":"Fietsen Utrecht","role":"owner"},{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","role":"owner"}],"two_factor_enabled":false,"registered_at":"2026-10-23T20:09:06+00:00"}],"meta":{"current_page":1,"per_page":25,"total":2,"last_page":1}}
```

**Search**

`GET /api/v1/admin/users?search=bob&per_page=10    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-7e0f-7094-9720-d658f02b9cbd

{"data":[{"id":"01m5n6z6zsmygsqghb9z5qx0zc","first_name":"Tess","email":"bob.wallet@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","roles":[],"workspaces":[{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","role":"employee"}],"two_factor_enabled":false,"registered_at":"2026-10-23T20:24:21+00:00"}],"meta":{"current_page":1,"per_page":10,"total":1,"last_page":1}}
```

**User detail**

`GET /api/v1/admin/users/01m5n6z6zsmygsqghb9z5qx0zc    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-7e67-71a9-968c-c42f3e8d0ba0

{"data":{"id":"01m5n6z6zsmygsqghb9z5qx0zc","first_name":"Tess","email":"bob.wallet@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","roles":[],"workspaces":[{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","role":"employee"}],"two_factor_enabled":false,"registered_at":"2026-10-23T20:24:21+00:00","last_login_at":null,"locale":"nl","platform_roles":[],"memberships":[{"webshop":{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","status":"approved"},"role":"employee","status":"active","joined_at":"2026-10-24T14:38:33+00:00"}],"wallets":[{"id":"01m5pd8fy0qwvmzg42cfq5x20p","unit":"eur_credit","available":0,"reserved":0,"pending":0}],"api_tokens":0}}
```

**Suspend**

`POST /api/v1/admin/users/01m5n6z6zsmygsqghb9z5qx0zc/suspend {"reason":"Verdachte claims, onderzoek loopt"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-7ed3-7194-a936-2136cbb5677b

{"data":{"id":"01m5n6z6zsmygsqghb9z5qx0zc","first_name":"Tess","email":"bob.wallet@example.nl","email_verified":true,"status":"suspended","onboarding_intent":"consumer","roles":[],"workspaces":[{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","role":"employee"}],"two_factor_enabled":false,"registered_at":"2026-10-23T20:24:21+00:00"}}
```

**Suspend twice → 409**

`POST /api/v1/admin/users/01m5n6z6zsmygsqghb9z5qx0zc/suspend {"reason":"Verdachte claims, onderzoek loopt"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16e22-7f5e-7149-b4f5-e29fc764241d
Content-Type: application/json
Content-Language: en

{"error":{"status":409,"code":"invalid_status_transition","message":"Only active accounts can be suspended.","request_id":"01a16e22-7f5e-7149-b4f5-e29fc764241d"}}
```

**Reactivate**

`POST /api/v1/admin/users/01m5n6z6zsmygsqghb9z5qx0zc/reactivate {"reason":"Onderzoek afgerond, geen fraude"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-7fd2-73c9-819b-8547ea93c08e

{"data":{"id":"01m5n6z6zsmygsqghb9z5qx0zc","first_name":"Tess","email":"bob.wallet@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","roles":[],"workspaces":[{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","role":"employee"}],"two_factor_enabled":false,"registered_at":"2026-10-23T20:24:21+00:00"}}
```

**Suspend yourself → 409**

`POST /api/v1/admin/users/01m5pchsnh8j21gcjrtd7vvekg/suspend {"reason":"Test op mezelf"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16e22-8067-7110-92a1-fd186c262d01
Content-Type: application/json
Content-Language: en

{"error":{"status":409,"code":"cannot_manage_self","message":"You cannot suspend your own account.","request_id":"01a16e22-8067-7110-92a1-fd186c262d01"}}
```

**Reset security without the emailed code → 423**

`POST /api/v1/admin/users/01m5n63987h9jnx97myg2ycsny/reset-security {"reason":"Telefoon kwijt, identiteit gecontroleerd"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 423 Locked
X-Request-Id: 01a16e22-80d4-733e-bddc-43e462a75e84
Content-Type: application/json
Content-Language: en

{"error":{"status":423,"code":"step_up_code_required","message":"Confirm this action with the code sent to your email.","request_id":"01a16e22-80d4-733e-bddc-43e462a75e84"}}
```

**Step-up code**

`POST /api/v1/admin/step-up/code    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-8149-707e-b95b-d490f370106a

{"data":{"sent":true,"expires_at":"2026-10-24T13:48:37+00:00"}}
```

**Verify**

`POST /api/v1/admin/step-up/verify {"code":"<6-digit code>"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-828c-73a0-8a93-1662544c9645

{"data":{"confirmed":true,"valid_until":"2026-10-24T13:48:37+00:00"}}
```

**Reset security**

`POST /api/v1/admin/users/01m5n63987h9jnx97myg2ycsny/reset-security {"reason":"Telefoon kwijt, identiteit gecontroleerd"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-82e5-71ce-a9be-ecd2d4fd70ac

{"data":{"id":"01m5n63987h9jnx97myg2ycsny","first_name":"Anna","email":"anna.nieuw@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","roles":[],"workspaces":[],"two_factor_enabled":false,"registered_at":"2026-10-23T20:09:06+00:00"}}
```

**Revoke the last Super Admin → 409**

`POST /api/v1/admin/users/01m5pchsnh8j21gcjrtd7vvekg/revoke-super-admin {"reason":"Verlaat het team"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16e22-8344-7316-92cf-65015e50d22a
Content-Type: application/json
Content-Language: en

{"error":{"status":409,"code":"last_super_admin","message":"The last active Super Admin cannot be removed or suspended.","request_id":"01a16e22-8344-7316-92cf-65015e50d22a"}}
```

**Revoke another Super Admin**

`POST /api/v1/admin/users/01m5n6z6zsmygsqghb9z5qx0zc/revoke-super-admin {"reason":"Tijdelijke beheerder voor de capture"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-84cb-72dc-9280-fd4397f19995

{"data":{"id":"01m5n6z6zsmygsqghb9z5qx0zc","first_name":"Tess","email":"bob.wallet@example.nl","email_verified":true,"status":"active","onboarding_intent":"consumer","roles":[],"workspaces":[{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","role":"employee"}],"two_factor_enabled":false,"registered_at":"2026-10-23T20:24:21+00:00"}}
```

**Revoke a membership**

`POST /api/v1/admin/webshops/01m5pd8djwvb5yyj12nw5esr56/members/01m5n6z6zsmygsqghb9z5qx0zc/revoke {"reason":"Werkt niet meer bij Woon & Co"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-8528-710f-8ced-e1a03ee9b87a

{"data":{"webshop_id":"01m5pd8djwvb5yyj12nw5esr56","user_id":"01m5n6z6zsmygsqghb9z5qx0zc","role":"employee","status":"removed"}}
```

**Revoke the last owner → 409**

`POST /api/v1/admin/webshops/01m5pd8djwvb5yyj12nw5esr56/members/01m5n6398s6hzh93gk32h1n1ye/revoke {"reason":"Eigendomsgeschil"}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a16e22-858b-73c0-82fc-d84e90fa927c
Content-Type: application/json
Content-Language: en

{"error":{"status":409,"code":"final_owner","message":"The last owner cannot leave or be removed.","request_id":"01a16e22-858b-73c0-82fc-d84e90fa927c"}}
```

**Request more information**

`POST /api/v1/admin/webshops/01m5pd8djwvb5yyj12nw5esr56/request-information {"message":"Upload een recent KvK-uittreksel (niet ouder dan 3 maanden)."}   (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 201 Created
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-85da-7333-966e-0d35e86f83ae

{"data":{"notification_id":"01m5q251fcfvqgp97qxxe0wqqq","webshop_id":"01m5pd8djwvb5yyj12nw5esr56","status":"approved"}}
```

**Webshop list with the new columns**

`GET /api/v1/admin/webshops?per_page=2    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-8637-7141-a9ab-3271bc8ba99a

{"data":[{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","platform":"woocommerce","status":"approved","status_reason":null,"review_flags":[],"ownership_verified":false,"ownership_method":null,"verified_domain":"woonenco.nl","website_domain":null,"owners":[{"id":"01m5n6398s6hzh93gk32h1n1ye","first_name":"Jan","email":"jan@example.nl"}],"review_requested_at":null,"approved_at":"2026-10-24T07:33:29+00:00","created_at":"2026-10-24T07:33:29+00:00","integration_status":"not_connected","last_activity_at":"2026-10-24T12:28:25+00:00","active_offers":3},{"id":"01m5n6399tnc5h1vtfyf3gsgv4","name":"Fietsen Utrecht","platform":"shopify","status":"approved","status_reason":null,"review_flags":[],"ownership_verified":true,"ownership_method":"dns_txt","verified_domain":"fietsen-utrecht.nl","website_domain":null,"owners":[{"id":"01m5n6398s6hzh93gk32h1n1ye","first_name":"Jan","email":"jan@example.nl"}],"review_requested_at":null,"approved_at":"2026-10-23T20:09:06+00:00","created_at":"2026-10-23T20:09:06+00:00","integration_status":"not_connected","last_activity_at":"2026-10-24T07:21:08+00:00","active_offers":1}],"links":{"first":"http:\/\/127.0.0.1:8799\/api\/v1\/admin\/webshops?page=1","last":"http:\/\/127.0.0.1:8799\/api\/v1\/admin\/webshops?page=1","prev":null,"next":null},"meta":{"current_page":1,"from":1,"last_page":1,"links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http:\/\/127.0.0.1:8799\/api\/v1\/admin\/webshops?page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"path":"http:\/\/127.0.0.1:8799\/api\/v1\/admin\/webshops","per_page":2,"to":2,"total":2}}
```

**Webshop detail with the approval checklist**

`GET /api/v1/admin/webshops/01m5pd8djwvb5yyj12nw5esr56    (session cookie + X-XSRF-TOKEN)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Content-Language: en
X-Request-Id: 01a16e22-8690-72ec-8050-1e26bdf983ef

{"data":{"id":"01m5pd8djwvb5yyj12nw5esr56","name":"Woon & Co","platform":"woocommerce","status":"approved","status_reason":null,"review_flags":[],"ownership_verified":false,"ownership_method":null,"verified_domain":"woonenco.nl","website_domain":null,"owners":[{"id":"01m5n6398s6hzh93gk32h1n1ye","first_name":"Jan","email":"jan@example.nl"}],"review_requested_at":null,"approved_at":"2026-10-24T07:33:29+00:00","created_at":"2026-10-24T07:33:29+00:00","approval_checklist":{"business_identity":false,"ownership_verified":false,"company_information_confirmed":false,"agreement_accepted":true,"owner_account_active":true},"business_profile":null,"verifications":[],"agreement_acceptances":[{"id":"01m5pd8dnaqshg09zve9k2g1cb","version":"2026.1","document_hash":"2d711642b726b04401627ca9fbac32f5c8530fb1903cc4db02258717921a4881","signatory_name":"x","accepted_at":"2026-10-24T07:33:29+00:00"}]}}
```

---

## Shared error responses not yet produced by a real endpoint

Real per-endpoint captures for 401, 403, 409, 419, 422 and 429 now exist in the Auth section above. The generic 403 `forbidden` and the 500 below were rendered through the real application kernel on 2026-10-23 using temporary routes, because no endpoint produces them on purpose yet.

```text
401 {"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a168bd-f864-70ce-9573-0f6c6905e5fb"}}
403 {"error":{"status":403,"code":"forbidden","message":"You are not allowed to perform this action.","request_id":"01a168bd-f891-71fa-b745-e681283411ee"}}
404 {"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a168bc-4082-739c-a3cb-1995636d37a5"}}
422 {"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a168bd-f895-73d8-881b-0f4ac0620658","fields":{"email":["The email field must be a valid email address."]}}}
500 {"error":{"status":500,"code":"server_error","message":"An unexpected error occurred.","request_id":"01a168bd-f8b0-725d-912b-fc7c412e64d5"}}
```

## B16c financial and operational oversight

Captured 2026-10-25 from a real local Laravel server on port 8076, isolated SQLite + database sessions, QUEUE_CONNECTION=sync, MAIL_MAILER=log and ADMIN_MFA_REQUIRED=false. Fake fixtures only. Source: baseline 856244f plus B16c working changes. Both admins completed real password and emailed OTP verification; secrets omitted.

All operations require active verified Super Admin access and MFA when enabled. Mutations additionally require recent password and email OTP. Common 401/403/419/422/423/429/500 examples reuse earlier real captures in this reference. Domain conflicts below were captured from these operations. Per-page defaults to 25 (1–100); page starts at 1.

### `GET /api/v1/admin/wallets`

Wallet balances with explicit kind and unit. Filters: consumer_id, kind, unit. Audited inspection.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/wallets' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-cf1f-7046-bd02-4aa5ebe237d5

{"data":[{"id":"01m5shsgcw0s9p7gqjf6kkr8d9","kind":"consumer_credit","unit":"eur_credit","available":500,"reserved":0,"pending":0}],"meta":{"current_page":1,"per_page":25,"total":1,"last_page":1}}
```

### `GET /api/v1/admin/wallets/{wallet}/ledger`

Append-only ledger, newest first, with explicit wallet unit. Internal owner/actor IDs and idempotency keys omitted. Audited inspection.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/wallets/01m5shsgcw0s9p7gqjf6kkr8d9/ledger' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-cf3d-71b5-b3ed-2f329b54ff5b

{"data":[{"id":"01m5shsgd3kgj7e3qzbcv4rq9x","type":"credit","unit":"eur_credit","amount":500,"available_delta":500,"reserved_delta":0,"available_after":500,"reserved_after":0,"reason":null,"origin":"system","source_type":null,"created_at":"2026-10-25T12:50:26+00:00"}],"meta":{"current_page":1,"per_page":25,"total":1,"last_page":1}}
```

### `GET /api/v1/admin/wallet-reconciliation-flags`

Flags with stable public IDs. Filter status=open|resolved and wallet_id.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/wallet-reconciliation-flags' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-cf5c-718a-8abe-0a85a19001fd

{"data":[{"id":"01m5shsgd9twtss0t6vk46wr4c","wallet_id":"01m5shsgcw0s9p7gqjf6kkr8d9","unit":"eur_credit","reason":"balance_mismatch","amount":0,"status":"open","resolved_at":null,"resolution_reason":null,"resolved_by":null}],"meta":{"current_page":1,"per_page":25,"total":1,"last_page":1}}
```

### `GET /api/v1/admin/wallet-adjustments`

Dual-admin-v1 requires a different reviewer for EVERY amount/unit. Immutable request verifies a matching active verified consumer, wallet and source entry. request_key is idempotent per requester; different evidence returns 409. No credit posts when requesting. GET filters status=pending|approved|rejected.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/wallet-adjustments' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-cf77-73e1-af54-9b793882a996

{"data":[],"meta":{"current_page":1,"per_page":25,"total":0,"last_page":1}}
```

### `GET /api/v1/admin/integrations`

Per-connection measured status, business-timezone order count, last processed event, failed/incomplete events and oldest pending timestamp. auth_error is the latest recorded connection auth failure, not a count of all unsigned requests. Provider capabilities remain NOT VERIFIED.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/integrations' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-cf92-7278-8050-b236b37ea4e1

{"data":[{"id":"01m5shsgdhkgmywh2mvavphebh","webshop":{"id":"01m5shsgdcv6knc9h75a2ec5qw","name":"Capture Shop"},"platform":"prestashop","status":"connected","last_event_at":null,"last_successful_event_at":null,"orders_today":0,"failed_events":1,"incomplete_orders":0,"oldest_pending_event_at":null,"auth_error":null,"last_error_code":null,"limitations":null,"required_action":null}],"meta":{"current_page":1,"total":1,"per_page":25,"last_page":1},"definitions":{"orders_today":"Orders first received since the start of the current business-timezone day.","delay":"oldest_pending_event_at is the start of the unprocessed verified-event backlog; null means none.","auth_error":"Latest recorded connection authentication error, not a count of all rejected inbound requests."}}
```

### `GET /api/v1/admin/integrations/failed-events`

Failed verified events with retained-payload indicator. Omits raw customer payloads and credentials.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/integrations/failed-events' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-cfb1-722a-91a4-44e9fd6cacbc

{"data":[{"id":"01m5shsgdqk6rphg05cxxc52a4","connection_id":"01m5shsgdhkgmywh2mvavphebh","status":"failed","error_code":null,"received_at":"2026-10-25T12:50:26+00:00","payload_retained":true}],"meta":{"current_page":1,"total":1,"per_page":25,"last_page":1}}
```

### `GET /api/v1/admin/answer24`

Grouped contact states, current eligibility-failure reasons, failed dispatches and authenticated failed contact results. Reasons may overlap. Rejected unsigned callbacks remain in security audit. Dispatch configuration is reported, never enabled here.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/answer24' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-cfce-73c0-8a5b-92f31273e2fd

{"data":{"dispatch_enabled":false,"eligible_paid_orders":0,"contacts_by_status":[],"eligibility_failures":[],"failed_dispatches":1,"failed_contact_results":0,"failed_messages":[{"id":"01m5shsgdtde6gjab0wsvfdyw5","type":"answer24.contact_requested","status":"failed","attempts":0,"last_error":null}]},"definitions":{"eligibility_failures":"Current stored order eligibility reasons, counted per order/reason; may overlap.","failed_contact_results":"Authenticated Answer24 contact results reporting failure; rejected unsigned requests are security audit events.","provider_verification":"NOT VERIFIED; dispatch remains controlled by configuration."}}
```

### `GET /api/v1/admin/financial-oversight`

Required category: affiliate_mismatches|failed_issuance|payments|funding_payments|redemption_signals. Read-only evidence; existing affiliate, checkout and dispute APIs handle mutations. Failed checkouts include payment and inventory failures distinguished by failure_reason. Denied redemptions are investigation signals, not proven fraud. No payouts or provider calls.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/financial-oversight?category=payments' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-cfea-735e-b089-a943f2a2a17f

{"data":[],"meta":{"current_page":1,"total":0,"per_page":25,"last_page":1},"definitions":{"failed_issuance":"Failed or paid-unfulfilled voucher checkouts; failure_reason distinguishes payment and inventory failures.","redemption_signals":"Denied redemption transitions recorded since B16c; signals for investigation, not confirmed fraud.","related_workflows":"/admin/affiliate/transactions, /admin/checkouts, /admin/disputes; no payouts or new provider calls."}}
```

### `POST /api/v1/admin/wallet-reconciliation-flags/{flag}/resolve`

Recent password and email OTP required. Reasoned audited resolution only. A balance_mismatch cannot be resolved while cached balances disagree with ledger, lots, pending or active reservations. Never writes balances.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/wallet-reconciliation-flags/01m5shsgd9twtss0t6vk46wr4c/resolve' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"reason":"Ledger and cached balances verified"}'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-d07c-73ac-9bf1-0edb30a57095

{"data":{"id":"01m5shsgd9twtss0t6vk46wr4c","wallet_id":"01m5shsgcw0s9p7gqjf6kkr8d9","unit":"eur_credit","reason":"balance_mismatch","amount":0,"status":"resolved","resolved_at":"2026-10-25T12:50:30+00:00","resolution_reason":"Ledger and cached balances verified","resolved_by":"01m5shsg3jkn7wrp9pe1a2f539"}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a1731c-eb24-70c6-a8d2-2d8dbd0741eb
Content-Type: application/json

{"error":{"status":409,"code":"flag_already_resolved","message":"This reconciliation flag is already resolved.","request_id":"01a1731c-eb24-70c6-a8d2-2d8dbd0741eb"}}
```

### `POST /api/v1/admin/wallet-adjustments`

Dual-admin-v1 requires a different reviewer for EVERY amount/unit. Immutable request verifies a matching active verified consumer, wallet and source entry. request_key is idempotent per requester; different evidence returns 409. No credit posts when requesting. GET filters status=pending|approved|rejected.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/wallet-adjustments' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"wallet_id":"01m5shsgcw0s9p7gqjf6kkr8d9","consumer_id":"01m5shsgcjk4ccftfne5p57vq5","source_entry_id":"01m5shsgd3kgj7e3qzbcv4rq9x","amount":100,"reason":"Verified missing credit","verification_note":"Consumer identity and original transaction verified","request_key":"capture-adjustment-001"}'
```

**Response 201**

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: 01a1731c-d09e-709d-9b46-cf0f079ca1bf

{"data":{"id":"01m5shsm5bsyr3gwy06b7g4721","wallet_id":"01m5shsgcw0s9p7gqjf6kkr8d9","consumer_id":"01m5shsgcjk4ccftfne5p57vq5","source_entry_id":"01m5shsgd3kgj7e3qzbcv4rq9x","amount":100,"unit":"eur_credit","reason":"Verified missing credit","verification_note":"Consumer identity and original transaction verified","policy_version":"dual-admin-v1","status":"pending","requested_by":"01m5shsg3jkn7wrp9pe1a2f539","reviewed_by":null,"posted_entry_id":null,"review_note":null,"reviewed_at":null}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a1731c-eb02-70ba-b431-4112194c1af3
Content-Type: application/json

{"error":{"status":409,"code":"approval_key_conflict","message":"The request key was already used for different evidence.","request_id":"01a1731c-eb02-70ba-b431-4112194c1af3"}}
```

### `POST /api/v1/admin/wallet-adjustments/{approval}/review`

Recent password and email OTP required. Requester cannot review their own request. Review rechecks consumer/requester eligibility. Approved posts once through WalletService::adjust; rejected posts nothing. Negative balances refused. Same completed review by the same reviewer replays; conflicting decision/reviewer returns 409.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/wallet-adjustments/01m5shsm5bsyr3gwy06b7g4721/review' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"decision":"approved","note":"Independent verification completed"}'
```

**Response 403**

```http
HTTP/1.1 403
X-Request-Id: 01a1731c-d0c0-72f7-bd7d-d5af0dfc2f26
Content-Type: application/json

{"error":{"status":403,"code":"second_admin_required","message":"A different administrator must review this adjustment.","request_id":"01a1731c-d0c0-72f7-bd7d-d5af0dfc2f26"}}
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a1731c-dcdd-7290-8c63-7adc25076485

{"data":{"id":"01m5shsm5bsyr3gwy06b7g4721","wallet_id":"01m5shsgcw0s9p7gqjf6kkr8d9","consumer_id":"01m5shsgcjk4ccftfne5p57vq5","source_entry_id":"01m5shsgd3kgj7e3qzbcv4rq9x","amount":100,"unit":"eur_credit","reason":"Verified missing credit","verification_note":"Consumer identity and original transaction verified","policy_version":"dual-admin-v1","status":"approved","requested_by":"01m5shsg3jkn7wrp9pe1a2f539","reviewed_by":"01m5shsgc59xkqzakxaqf2yy05","posted_entry_id":"01m5shsq7bjewmre72b74qtzz8","review_note":"Independent verification completed","reviewed_at":"2026-10-25T12:50:33+00:00"}}
```

**Response 403**

```http
HTTP/1.1 403
X-Request-Id: 01a1731c-eb41-70f5-9c82-c52db7f21722
Content-Type: application/json

{"error":{"status":403,"code":"second_admin_required","message":"A different administrator must review this adjustment.","request_id":"01a1731c-eb41-70f5-9c82-c52db7f21722"}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a1731c-f770-73d0-abbc-ea82cddd62f7
Content-Type: application/json

{"error":{"status":409,"code":"approval_already_reviewed","message":"This adjustment has already been reviewed.","request_id":"01a1731c-f770-73d0-abbc-ea82cddd62f7"}}
```

### `POST /api/v1/admin/integrations/events/{event}/retry`

Recent password and email OTP required. Requeue an existing failed technical operation using the original identity/idempotency key. Webhooks require retained verified payload and non-disconnected connection. Outbox retries retain attempt history and never enable dispatch. Current consent/suppression/payment/eligibility rechecked before contact dispatch. Provider-side idempotency remains NOT VERIFIED.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/integrations/events/01m5shsgdqk6rphg05cxxc52a4/retry' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"reason":"Technical failure investigated and corrected"}'
```

**Response 202**

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: 01a1731c-dd07-701c-bbfb-d00f74bb232e

{"data":{"id":"01m5shsgdqk6rphg05cxxc52a4","status":"ignored"}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a1731c-dd2f-7154-bb5a-cc63bd8ae326
Content-Type: application/json

{"error":{"status":409,"code":"event_not_retryable","message":"Only failed events with a retained payload and active connection can be retried.","request_id":"01a1731c-dd2f-7154-bb5a-cc63bd8ae326"}}
```

### `POST /api/v1/admin/answer24/messages/{message}/retry`

Recent password and email OTP required. Requeue an existing failed technical operation using the original identity/idempotency key. Webhooks require retained verified payload and non-disconnected connection. Outbox retries retain attempt history and never enable dispatch. Current consent/suppression/payment/eligibility rechecked before contact dispatch. Provider-side idempotency remains NOT VERIFIED.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/answer24/messages/01m5shsgdtde6gjab0wsvfdyw5/retry' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"reason":"Technical failure investigated and corrected"}'
```

**Response 202**

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: 01a1731c-dd4b-72ac-9208-477135c70769

{"data":{"id":"01m5shsgdtde6gjab0wsvfdyw5","status":"pending"}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a1731c-dd6c-704d-a48b-d7e1ac8ba735
Content-Type: application/json

{"error":{"status":409,"code":"outbox_not_retryable","message":"Only failed Answer24 messages can be retried.","request_id":"01a1731c-dd6c-704d-a48b-d7e1ac8ba735"}}
```

## B16d incidents, audit integrity and platform settings

Captured 2026-10-25 from a real local Laravel server on port 8076, isolated SQLite + database sessions, QUEUE_CONNECTION=sync, MAIL_MAILER=log and ADMIN_MFA_REQUIRED=false. Fake fixtures only. Source: baseline 4786e65 plus B16d working changes. Both admins completed real password and emailed OTP verification; secrets omitted.

Public settings is anonymous. All admin operations require active verified Super Admin access and MFA when enabled. Mutations require recent password; export/publish/rollback additionally require email OTP. Common 401/403/419/422/423/429/500 examples reuse earlier real captures in this reference. Domain conflicts below were captured from these operations. Per-page defaults to 25 (1–100); page starts at 1.

### `GET /api/v1/admin/incidents`

Grouped incidents, newest occurrence first. Status new|investigating|resolved|ignored; filters severity, service, method, request_category read|write, webshop_id, request_id, from/to UTC dates. Every occurrence is retained with request ID and sanitized technical code.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/incidents' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17328-3cf0-71e2-befc-c83429abdbf0

{"data":[{"id":"01m5sjgf6epbacp2072ra89enz","category":"security","severity":"warning","service":"api","route":"api/v1/admin/incidents","method":"GET","http_status":401,"status":"new","assigned_to":null,"occurrences":1,"first_seen_at":"2026-10-25T13:02:59+00:00","last_seen_at":"2026-10-25T13:02:59+00:00"}],"meta":{"current_page":1,"total":1,"per_page":25,"last_page":1}}
```

### `PATCH /api/v1/admin/incidents/{incident}`

GET includes paginated occurrences and latest 100 audited status/assignment changes. PATCH requires recent password, a reason and explicit assigned_to (active Super Admin public ID or null); status new|investigating|resolved|ignored. No messages, headers, query strings or private payloads are stored in incidents.

```bash
curl -i -X PATCH 'http://localhost:8076/api/v1/admin/incidents/01m5sjgf6epbacp2072ra89enz' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"status":"investigating","assigned_to":"01m5shsg3jkn7wrp9pe1a2f539","reason":"Investigating repeated API denials"}'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17328-3d73-7131-8b3b-0325db8e6d4c

{"data":{"id":"01m5sjgf6epbacp2072ra89enz","category":"security","severity":"warning","service":"api","route":"api/v1/admin/incidents","method":"GET","http_status":401,"status":"investigating","assigned_to":"01m5shsg3jkn7wrp9pe1a2f539","occurrences":1,"first_seen_at":"2026-10-25T13:02:59+00:00","last_seen_at":"2026-10-25T13:02:59+00:00"}}
```

### `GET /api/v1/admin/incidents/{incident}`

GET includes paginated occurrences and latest 100 audited status/assignment changes. PATCH requires recent password, a reason and explicit assigned_to (active Super Admin public ID or null); status new|investigating|resolved|ignored. No messages, headers, query strings or private payloads are stored in incidents.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/incidents/01m5sjgf6epbacp2072ra89enz' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17328-3da8-72ab-8a58-991687b2f799

{"data":{"id":"01m5sjgf6epbacp2072ra89enz","category":"security","severity":"warning","service":"api","route":"api/v1/admin/incidents","method":"GET","http_status":401,"status":"investigating","assigned_to":"01m5shsg3jkn7wrp9pe1a2f539","occurrences":1,"first_seen_at":"2026-10-25T13:02:59+00:00","last_seen_at":"2026-10-25T13:02:59+00:00"},"history":[{"request_id":"01a17328-3d73-7131-8b3b-0325db8e6d4c","actor_id":"01m5shsg3jkn7wrp9pe1a2f539","occurred_at":"2026-10-25T13:02:59+00:00","change":{"before":{"status":"new","assigned_to":null},"after":{"status":"investigating","assigned_to":"01m5shsg3jkn7wrp9pe1a2f539"},"reason":"Investigating repeated API denials"}}],"occurrences":[{"id":"01m5sjgf6n73reeddz897wj6sx","request_id":"01a17328-3cb7-7024-8c92-e6bcf6910304","actor_id":null,"webshop_id":null,"scope_public_id":null,"error_code":"unauthenticated","occurred_at":"2026-10-25T13:02:59+00:00"}],"meta":{"current_page":1,"total":1,"per_page":25,"last_page":1}}
```

### `GET /api/v1/admin/api-routes`

Generated from runtime /api/v1 routes and OpenAPI x-access/x-permission. No mutable duplicate catalogue. Filter access/method. Includes rights, scope, audit/monitoring category, documented error statuses and password/OTP gates. CLI: php artisan shopbonus:api-routes exits nonzero on mismatches.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/api-routes?access=admin&per_page=1' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17328-3de1-707f-a22c-bd17a0649790

{"data":[{"method":"GET","route":"/api/v1/admin/platform-settings","name":"api.v1.admin.platform-settings.index","access":"admin","documented_access":"admin","permission":null,"contract_matches":true,"scope":"platform","roles":["super_admin"],"audit_category":"admin","monitoring_category":["api"],"monitoring_owner":"backend","error_statuses":[],"authentication_types":["session_cookie","bearer_token"],"password_recent":false,"email_otp":false}],"meta":{"current_page":1,"total":93,"per_page":1,"last_page":93}}
```

### `POST /api/v1/admin/audit/export`

Recent password and email OTP required. UTC from/to dates required, maximum 31 inclusive days; per_page 1–500, default 100. Bounded synchronous JSON pages; bulk unbounded export unsupported. Export itself is audited. Safe projection omits raw metadata, infrastructure details, resource integer IDs and private contact fields. SHA256-chain-v1 hashes refer to canonical raw events and are verified server-side with php artisan shopbonus:audit-verify. Retain exported heads off-host; a database administrator able to rewrite all hashes and the head requires an independent anchor. Historical rows were sealed as observed at migration time, not authenticated retrospectively.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/audit/export' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"from":"2026-10-25","to":"2026-10-25","per_page":1}'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17328-4088-7254-bb03-d31fd5e0ce0f

{"data":[{"id":"01m5sjg76ja9dbk90gc9hskkr6","occurred_at":"2026-10-25T12:50:27+00:00","request_id":"01a1731c-c35e-7276-ad6f-7dbfd038a780","category":"identity","action":"identity.login_succeeded","outcome":"success","actor_id":"01m5shsg3jkn7wrp9pe1a2f539","real_actor_id":"01m5shsg3jkn7wrp9pe1a2f539","webshop_id":null,"route":"login","method":"POST","http_status":null,"integrity":{"previous_hash":"0000000000000000000000000000000000000000000000000000000000000000","event_hash":"a0b481c12c1cfb8353f6f6c491887bc96af00ad6b48f681ab453f047ba50c1df","chain_hash":"c7835e270423bcafbec89c7a33aca416cbd73e474725f45be214a982989eeb2a"}}],"meta":{"current_page":1,"total":37,"per_page":1,"last_page":37},"integrity":{"algorithm":"sha256-chain-v1","head":"dcafccdc60755d2d2fb5812ba1634a0286585fe70b6601f3e69bec7dbe2b89fd","verification_command":"php artisan shopbonus:audit-verify","projection":"Safe projection; verify raw event digests on the server. Retain exported heads off-host for independent anchoring."}}
```

### `GET /api/v1/admin/platform-settings`

Versioned allowlisted public metadata only: public.brand_name (plain text 1–100), public.support_email (valid email), public.languages (unique nonempty subset of nl/en). POST creates immutable draft evidence; GET filters key. Provider credentials/activation, finance, legal, CMS, agreements and consent remain in their existing guarded systems. Drafts never affect public output.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/admin/platform-settings' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17328-411f-7248-83a2-4d34e205b3df

{"data":[],"published":{"values":{"public.brand_name":"Shopbonus","public.support_email":null,"public.languages":["nl","en"]},"versions":[]},"allowed_keys":["public.brand_name","public.support_email","public.languages"],"meta":{"current_page":1,"total":0,"per_page":25,"last_page":1}}
```

### `POST /api/v1/admin/platform-settings`

Versioned allowlisted public metadata only: public.brand_name (plain text 1–100), public.support_email (valid email), public.languages (unique nonempty subset of nl/en). POST creates immutable draft evidence; GET filters key. Provider credentials/activation, finance, legal, CMS, agreements and consent remain in their existing guarded systems. Drafts never affect public output.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/platform-settings' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"key":"public.brand_name","value":"Shopbonus NL","reason":"Reviewed public branding change"}'
```

**Response 201**

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: 01a17328-415e-7061-95bf-a3f85555a861

{"data":{"id":"01m5sjggbvkq9g48e7ha2dz3b2","key":"public.brand_name","version":1,"value":"Shopbonus NL","checksum":"02f472ce51e0ac3a9b8d0a48a252361a326b3b7a42c2dba2506e12f664c17160","status":"draft","reason":"Reviewed public branding change","created_by":"01m5shsg3jkn7wrp9pe1a2f539","reviewed_by":null,"review_note":null,"published_at":null,"is_current":false,"rollback_of":null}}
```

### `POST /api/v1/admin/platform-settings/{version}/review`

Recent password required. Submit draft to review; other states return 409. Evidence is immutable; revise by making another draft.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/platform-settings/01m5sjggbvkq9g48e7ha2dz3b2/review' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{}'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17328-41a1-718a-9a19-cd14fc3e7a02

{"data":{"id":"01m5sjggbvkq9g48e7ha2dz3b2","key":"public.brand_name","version":1,"value":"Shopbonus NL","checksum":"02f472ce51e0ac3a9b8d0a48a252361a326b3b7a42c2dba2506e12f664c17160","status":"review","reason":"Reviewed public branding change","created_by":"01m5shsg3jkn7wrp9pe1a2f539","reviewed_by":null,"review_note":null,"published_at":null,"is_current":false,"rollback_of":null}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a17328-41de-730a-92be-189dcf806721
Content-Type: application/json

{"error":{"status":409,"code":"setting_not_draft","message":"Only draft settings can be submitted for review.","request_id":"01a17328-41de-730a-92be-189dcf806721"}}
```

### `POST /api/v1/admin/platform-settings/{version}/publish`

Recent password and email OTP required. A different Super Admin reviews and publishes with a note. Only review state can publish; stale versions cannot overwrite a newer published version. Snapshot checksum is verified. Public output changes atomically; history is retained.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/platform-settings/01m5sjggbvkq9g48e7ha2dz3b2/publish' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"note":"Independent review completed"}'
```

**Response 403**

```http
HTTP/1.1 403
X-Request-Id: 01a17328-420e-726a-b9b1-ddc6d1c49bc2
Content-Type: application/json

{"error":{"status":403,"code":"second_admin_required","message":"A different administrator must publish this setting.","request_id":"01a17328-420e-726a-b9b1-ddc6d1c49bc2"}}
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17328-4251-704a-94c0-36b09990d782

{"data":{"id":"01m5sjggbvkq9g48e7ha2dz3b2","key":"public.brand_name","version":1,"value":"Shopbonus NL","checksum":"02f472ce51e0ac3a9b8d0a48a252361a326b3b7a42c2dba2506e12f664c17160","status":"published","reason":"Reviewed public branding change","created_by":"01m5shsg3jkn7wrp9pe1a2f539","reviewed_by":"01m5shsgc59xkqzakxaqf2yy05","review_note":"Independent review completed","published_at":"2026-10-25T13:03:00+00:00","is_current":true,"rollback_of":null}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a17328-428f-736c-854b-b83b2e9cb3c2
Content-Type: application/json

{"error":{"status":409,"code":"setting_not_in_review","message":"Only reviewed settings can be published.","request_id":"01a17328-428f-736c-854b-b83b2e9cb3c2"}}
```

### `POST /api/v1/admin/platform-settings/{version}/rollback`

Recent password and email OTP required. Source must be a historical published version. Creates a NEW draft with incremented version and rollback_of reference, preserving history/current output; it must separately pass review and independent publication.

```bash
curl -i -X POST 'http://localhost:8076/api/v1/admin/platform-settings/01m5sjggbvkq9g48e7ha2dz3b2/rollback' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"reason":"Restore previously reviewed public branding"}'
```

**Response 201**

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: 01a17328-42bf-730a-8f4f-444e639c60a8

{"data":{"id":"01m5sjggpmm3mztpvkmg31s1b5","key":"public.brand_name","version":2,"value":"Shopbonus NL","checksum":"02f472ce51e0ac3a9b8d0a48a252361a326b3b7a42c2dba2506e12f664c17160","status":"draft","reason":"Restore previously reviewed public branding","created_by":"01m5shsg3jkn7wrp9pe1a2f539","reviewed_by":null,"review_note":null,"published_at":null,"is_current":false,"rollback_of":"01m5sjggbvkq9g48e7ha2dz3b2"}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a17328-4311-7038-ae3c-c64546903713
Content-Type: application/json

{"error":{"status":409,"code":"setting_not_published","message":"Only published history can be used for a rollback draft.","request_id":"01a17328-4311-7038-ae3c-c64546903713"}}
```

### `GET /api/v1/public/settings`

Public published branding/support/display-language metadata plus version public IDs. Drafts are never exposed; supported server locales remain nl/en. Safe defaults are Shopbonus, null support email and [nl,en] before publication.

```bash
curl -i -X GET 'http://localhost:8076/api/v1/public/settings' -b cookies.txt -H 'Accept: application/json' -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17328-4352-715b-973b-80b90f052328

{"data":{"values":{"public.brand_name":"Shopbonus NL","public.support_email":null,"public.languages":["nl","en"]},"versions":{"public.brand_name":"01m5sjggbvkq9g48e7ha2dz3b2"}}}
```

## Card #21 incident diagnostics and operational budget

Captured 2026-10-25 against a real local Laravel server (port 8079), isolated SQLite + database sessions/queue/cache, MAIL_MAILER=log and ADMIN_MFA_REQUIRED=false. Baseline e523098 plus this working slice. Fake fixture data only. API login/password/email-OTP flows were real. Queued and completed responses were fetched over HTTP; completed analysis was generated through the real validator/service with an explicit Http::fake OpenAI response in an isolated fixture CLI. **Live OpenAI is NOT VERIFIED**; no real key/model/budget configured. Staging performance/recovery NOT RUN (owner confirms no staging).

OpenAI Responses integration uses structured output and store=false, with a fixed official API host, 20s timeout, one attempt and 256–4000 output-token bounds (default 1500). This does not promise zero provider retention. Provider/model credentials stay server-side. No tools are supplied; tool/refusal/incomplete/invalid outputs are refused. Sources: [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs) and [Responses API](https://developers.openai.com/api/reference/resources/responses/methods/create).

Operational setting operations.diagnostics_daily_request_limit reuses draft → review → independent publish → new-draft rollback. Integer 0–1000; 0 pauses requests; environment cap remains authoritative. Operational values/versions are never in GET /public/settings. Credentials/model/enable switch cannot be changed through generic settings.

### `POST /api/v1/admin/incidents/{incident}/diagnostics`

GET lists diagnostics and availability/effective global daily quota. POST requires recent password + emailed OTP and request_key (8–64 ASCII letters/numbers/._:-). The key is idempotent per requester; changed incident conflicts. Only unresolved/unignored HTTP 500 or background-job failures are eligible. One queued provider attempt, no automatic retry. Global UTC-day requests are reserved atomically, including failed runs. Effective daily limit is the lower of the environment cap (0–1000) and independently published operational limit. Disabled/unconfigured returns 503 without reserving quota. Per-admin throttle is 3 requests/minute. Snapshots contain only predefined evidence, no customer/actor/workspace/request IDs, raw logs, errors, private URLs or payloads.

```bash
curl -i -X POST 'http://localhost:8079/api/v1/admin/incidents/01m5snjygkt9cxdj2qpnbyd3my/diagnostics' -H 'Accept: application/json' -b cookies.txt -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"request_key":"capture-diagnostic"}'
```

**Response 503**

```http
HTTP/1.1 503
X-Request-Id: 01a17359-8b75-7219-a466-0d6e84800558
Content-Type: application/json

{"error":{"status":503,"code":"diagnostics_not_configured","message":"Incident diagnostics is not configured.","request_id":"01a17359-8b75-7219-a466-0d6e84800558"}}
```

**Response 422**

```http
HTTP/1.1 422
X-Request-Id: 01a17359-8f82-71cb-85b2-044ccd6d04e9
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a17359-8f82-71cb-85b2-044ccd6d04e9","fields":{"request_key":["The request key field is required."]}}}
```

**Response 202**

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: 01a17359-8ff9-734f-ab44-13478b74457a

{"data":{"id":"01m5snk4cr70jm64hj7h49e43c","incident_id":"01m5snjygkt9cxdj2qpnbyd3my","status":"queued","provider":"openai","model":"fixture-model","context":{"schema_version":"incident-context-v1","evidence":{"E1":{"fact":"http_status","value":500},"E2":{"fact":"service","value":"api"},"E3":{"fact":"registered_route","value":"/api/v1/consumer/wallet"},"E4":{"fact":"method","value":"GET"},"E5":{"fact":"occurrence_count","value":3}}},"context_hash":"3c1a6764838da5b763619f81b6947ba4b0fc474b40a6f1eea2f27689505f20c0","context_stale":false,"requested_by":"01m5snjy6qe86wqmn826dy0bdv","created_at":"2026-10-25T13:56:52+00:00","finished_at":null,"failure_code":null,"analysis":null,"human_review_required":true,"advisory_only":true,"reviews":[]}}
```

**Response 429**

```http
HTTP/1.1 429
X-Request-Id: 01a17359-9567-7070-be12-b56b3b63e06b
Content-Type: application/json

{"error":{"status":429,"code":"diagnostic_daily_limit","message":"The daily diagnostic request limit has been reached.","request_id":"01a17359-9567-7070-be12-b56b3b63e06b"}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a17359-9599-72f3-bbfb-79437fd7ff4c
Content-Type: application/json

{"error":{"status":409,"code":"diagnostic_key_conflict","message":"The diagnostic request key was already used for another incident.","request_id":"01a17359-9599-72f3-bbfb-79437fd7ff4c"}}
```

### `GET /api/v1/admin/incidents/{incident}/diagnostics`

GET lists diagnostics and availability/effective global daily quota. POST requires recent password + emailed OTP and request_key (8–64 ASCII letters/numbers/._:-). The key is idempotent per requester; changed incident conflicts. Only unresolved/unignored HTTP 500 or background-job failures are eligible. One queued provider attempt, no automatic retry. Global UTC-day requests are reserved atomically, including failed runs. Effective daily limit is the lower of the environment cap (0–1000) and independently published operational limit. Disabled/unconfigured returns 503 without reserving quota. Per-admin throttle is 3 requests/minute. Snapshots contain only predefined evidence, no customer/actor/workspace/request IDs, raw logs, errors, private URLs or payloads.

```bash
curl -i -X GET 'http://localhost:8079/api/v1/admin/incidents/01m5snjygkt9cxdj2qpnbyd3my/diagnostics' -H 'Accept: application/json' -b cookies.txt -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17359-91ee-7295-b482-fae6d5f7b6e3

{"data":[{"id":"01m5snk4cr70jm64hj7h49e43c","incident_id":"01m5snjygkt9cxdj2qpnbyd3my","status":"queued","provider":"openai","model":"fixture-model","context":{"schema_version":"incident-context-v1","evidence":{"E1":{"fact":"http_status","value":500},"E2":{"fact":"service","value":"api"},"E3":{"fact":"registered_route","value":"/api/v1/consumer/wallet"},"E4":{"fact":"method","value":"GET"},"E5":{"fact":"occurrence_count","value":3}}},"context_hash":"3c1a6764838da5b763619f81b6947ba4b0fc474b40a6f1eea2f27689505f20c0","context_stale":false,"requested_by":"01m5snjy6qe86wqmn826dy0bdv","created_at":"2026-10-25T13:56:52+00:00","finished_at":null,"failure_code":null,"analysis":null,"human_review_required":true,"advisory_only":true,"reviews":[]}],"availability":{"configured":true,"provider":"openai","effective_daily_request_limit":10},"meta":{"current_page":1,"total":1,"per_page":25,"last_page":1}}
```

### `GET /api/v1/admin/incident-diagnostics/{diagnostic}`

Admin-only polling endpoint. status queued|running|completed|failed. AI analysis is an unverified advisory hypothesis: likely_cause, evidence_refs (validated against snapshot E1–E5), impacted_component, suggested_investigation, proposed_fix, self-assessed confidence and uncertainty. context_stale flags changed occurrence counts. Results never execute tools, deploy, change permissions, refund, mutate ledgers or close incidents. No raw provider errors/refusals are stored or returned. Context hashes and immutable results preserve source evidence. Check reviews separately; human_review_required/advisory_only always remain true.

```bash
curl -i -X GET 'http://localhost:8079/api/v1/admin/incident-diagnostics/01m5snk4cr70jm64hj7h49e43c' -H 'Accept: application/json' -b cookies.txt -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17359-9214-7298-af47-bb314cdea7b7

{"data":{"id":"01m5snk4cr70jm64hj7h49e43c","incident_id":"01m5snjygkt9cxdj2qpnbyd3my","status":"queued","provider":"openai","model":"fixture-model","context":{"schema_version":"incident-context-v1","evidence":{"E1":{"fact":"http_status","value":500},"E2":{"fact":"service","value":"api"},"E3":{"fact":"registered_route","value":"/api/v1/consumer/wallet"},"E4":{"fact":"method","value":"GET"},"E5":{"fact":"occurrence_count","value":3}}},"context_hash":"3c1a6764838da5b763619f81b6947ba4b0fc474b40a6f1eea2f27689505f20c0","context_stale":false,"requested_by":"01m5snjy6qe86wqmn826dy0bdv","created_at":"2026-10-25T13:56:52+00:00","finished_at":null,"failure_code":null,"analysis":null,"human_review_required":true,"advisory_only":true,"reviews":[]}}
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17359-9428-738e-b486-5693921617b0

{"data":{"id":"01m5snk4cr70jm64hj7h49e43c","incident_id":"01m5snjygkt9cxdj2qpnbyd3my","status":"completed","provider":"openai","model":"fixture-model","context":{"schema_version":"incident-context-v1","evidence":{"E1":{"fact":"http_status","value":500},"E2":{"fact":"service","value":"api"},"E3":{"fact":"registered_route","value":"/api/v1/consumer/wallet"},"E4":{"fact":"method","value":"GET"},"E5":{"fact":"occurrence_count","value":3}}},"context_hash":"3c1a6764838da5b763619f81b6947ba4b0fc474b40a6f1eea2f27689505f20c0","context_stale":false,"requested_by":"01m5snjy6qe86wqmn826dy0bdv","created_at":"2026-10-25T13:56:52+00:00","finished_at":"2026-10-25T13:56:52+00:00","failure_code":null,"analysis":{"likely_cause":"A server failure needs investigation.","impacted_component":"api","proposed_fix":"Investigate before proposing a production change.","confidence":"low","uncertainty":"No stack trace or confirmed root cause is available.","evidence_refs":["E1","E5"],"suggested_investigation":["Inspect authorized server logs locally."]},"human_review_required":true,"advisory_only":true,"reviews":[]}}
```

### `POST /api/v1/admin/incident-diagnostics/{diagnostic}/review`

Recent password + emailed OTP required. Record accepted|dismissed with a 5–500 character note. Only completed diagnostics can be reviewed. One append-only review per admin/result; repeats return 409. Acceptance records human assessment only and performs no production or business action.

```bash
curl -i -X POST 'http://localhost:8079/api/v1/admin/incident-diagnostics/01m5snk4cr70jm64hj7h49e43c/review' -H 'Accept: application/json' -b cookies.txt -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"decision":"accepted","note":"Independent investigation review"}'
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a17359-923a-7397-ad82-bf969e9f9a53
Content-Type: application/json

{"error":{"status":409,"code":"diagnostic_not_completed","message":"Only completed diagnostics can be reviewed.","request_id":"01a17359-923a-7397-ad82-bf969e9f9a53"}}
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17359-944c-722b-a72c-76c7f97faebd

{"data":{"id":"01m5snk4cr70jm64hj7h49e43c","incident_id":"01m5snjygkt9cxdj2qpnbyd3my","status":"completed","provider":"openai","model":"fixture-model","context":{"schema_version":"incident-context-v1","evidence":{"E1":{"fact":"http_status","value":500},"E2":{"fact":"service","value":"api"},"E3":{"fact":"registered_route","value":"/api/v1/consumer/wallet"},"E4":{"fact":"method","value":"GET"},"E5":{"fact":"occurrence_count","value":3}}},"context_hash":"3c1a6764838da5b763619f81b6947ba4b0fc474b40a6f1eea2f27689505f20c0","context_stale":false,"requested_by":"01m5snjy6qe86wqmn826dy0bdv","created_at":"2026-10-25T13:56:52+00:00","finished_at":"2026-10-25T13:56:52+00:00","failure_code":null,"analysis":{"likely_cause":"A server failure needs investigation.","impacted_component":"api","proposed_fix":"Investigate before proposing a production change.","confidence":"low","uncertainty":"No stack trace or confirmed root cause is available.","evidence_refs":["E1","E5"],"suggested_investigation":["Inspect authorized server logs locally."]},"human_review_required":true,"advisory_only":true,"reviews":[{"id":"01m5snk52v1qh4bvr29893eq16","reviewed_by":"01m5snjy6qe86wqmn826dy0bdv","decision":"accepted","note":"Independent investigation review","created_at":"2026-10-25T13:56:52+00:00"}]}}
```

**Response 409**

```http
HTTP/1.1 409
X-Request-Id: 01a17359-9479-722a-a31c-b998938e1d27
Content-Type: application/json

{"error":{"status":409,"code":"diagnostic_already_reviewed","message":"You have already reviewed this diagnostic result.","request_id":"01a17359-9479-722a-a31c-b998938e1d27"}}
```

### `POST /api/v1/admin/platform-settings`

Supplemental live capture for the reviewed operational diagnostics budget. Existing authentication, draft/review/independent-publish guards remain unchanged; public settings exclude operational configuration.

```bash
curl -i -X POST 'http://localhost:8079/api/v1/admin/platform-settings' -H 'Accept: application/json' -b cookies.txt -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"key":"operations.diagnostics_daily_request_limit","value":0,"reason":"Pause diagnostic requests for investigation"}'
```

**Response 201**

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: 01a17359-949b-72ed-9775-b544da06f979

{"data":{"id":"01m5snk55bt6tt46x40jqcw89p","key":"operations.diagnostics_daily_request_limit","version":1,"value":0,"checksum":"5feceb66ffc86f38d952786c6d696c79c2dbc239dd4e91b46729d73a27fb57e9","status":"draft","reason":"Pause diagnostic requests for investigation","created_by":"01m5snjy6qe86wqmn826dy0bdv","reviewed_by":null,"review_note":null,"published_at":null,"is_current":false,"rollback_of":null}}
```

### `POST /api/v1/admin/platform-settings/{version}/review`

Supplemental live capture for the reviewed operational diagnostics budget. Existing authentication, draft/review/independent-publish guards remain unchanged; public settings exclude operational configuration.

```bash
curl -i -X POST 'http://localhost:8079/api/v1/admin/platform-settings/01m5snk55bt6tt46x40jqcw89p/review' -H 'Accept: application/json' -b cookies.txt -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{}'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17359-94c9-7179-b8da-df2419e7f83e

{"data":{"id":"01m5snk55bt6tt46x40jqcw89p","key":"operations.diagnostics_daily_request_limit","version":1,"value":0,"checksum":"5feceb66ffc86f38d952786c6d696c79c2dbc239dd4e91b46729d73a27fb57e9","status":"review","reason":"Pause diagnostic requests for investigation","created_by":"01m5snjy6qe86wqmn826dy0bdv","reviewed_by":null,"review_note":null,"published_at":null,"is_current":false,"rollback_of":null}}
```

### `POST /api/v1/admin/platform-settings/{version}/publish`

Supplemental live capture for the reviewed operational diagnostics budget. Existing authentication, draft/review/independent-publish guards remain unchanged; public settings exclude operational configuration.

```bash
curl -i -X POST 'http://localhost:8079/api/v1/admin/platform-settings/01m5snk55bt6tt46x40jqcw89p/publish' -H 'Accept: application/json' -b cookies.txt -H 'Origin: http://localhost:3000' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' --data '{"note":"Independent budget review completed"}'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17359-94f2-70fd-9347-d1e0c74ef869

{"data":{"id":"01m5snk55bt6tt46x40jqcw89p","key":"operations.diagnostics_daily_request_limit","version":1,"value":0,"checksum":"5feceb66ffc86f38d952786c6d696c79c2dbc239dd4e91b46729d73a27fb57e9","status":"published","reason":"Pause diagnostic requests for investigation","created_by":"01m5snjy6qe86wqmn826dy0bdv","reviewed_by":"01m5snjygdg9mdbqq1jw5677b4","review_note":"Independent budget review completed","published_at":"2026-10-25T13:56:53+00:00","is_current":true,"rollback_of":null}}
```

### `GET /api/v1/admin/platform-settings`

Supplemental live capture for the reviewed operational diagnostics budget. Existing authentication, draft/review/independent-publish guards remain unchanged; public settings exclude operational configuration.

```bash
curl -i -X GET 'http://localhost:8079/api/v1/admin/platform-settings' -H 'Accept: application/json' -b cookies.txt -H 'Origin: http://localhost:3000'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17359-9520-7170-8010-67b40fcf63ed

{"data":[{"id":"01m5snk55bt6tt46x40jqcw89p","key":"operations.diagnostics_daily_request_limit","version":1,"value":0,"checksum":"5feceb66ffc86f38d952786c6d696c79c2dbc239dd4e91b46729d73a27fb57e9","status":"published","reason":"Pause diagnostic requests for investigation","created_by":"01m5snjy6qe86wqmn826dy0bdv","reviewed_by":"01m5snjygdg9mdbqq1jw5677b4","review_note":"Independent budget review completed","published_at":"2026-10-25T13:56:53+00:00","is_current":true,"rollback_of":null}],"published":{"values":{"public.brand_name":"Shopbonus","public.support_email":null,"public.languages":["nl","en"],"operations.diagnostics_daily_request_limit":0},"versions":{"operations.diagnostics_daily_request_limit":"01m5snk55bt6tt46x40jqcw89p"}},"allowed_keys":["public.brand_name","public.support_email","public.languages","operations.diagnostics_daily_request_limit"],"meta":{"current_page":1,"total":1,"per_page":25,"last_page":1}}
```

### `GET /api/v1/public/settings`

Supplemental live capture for the reviewed operational diagnostics budget. Existing authentication, draft/review/independent-publish guards remain unchanged; public settings exclude operational configuration.

```bash
curl -i -X GET 'http://localhost:8079/api/v1/public/settings' -H 'Accept: application/json'
```

**Response 200**

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a17359-9543-73dd-aa00-6123e60454c2

{"data":{"values":{"public.brand_name":"Shopbonus","public.support_email":null,"public.languages":["nl","en"]},"versions":[]}}
```


## Affiliate transport update — B10b, 2026-10-25

TradeTracker SOAP transport is now implemented. Earlier examples above are historical B10 captures. `TRADETRACKER_API_ENABLED=false` keeps API calls paused (`limited`, safe code `transport_disabled`); imports remain available. The senior developer supplies encrypted connection credentials and enables it after deployment checks. See [TradeTracker setup](../integrations/TRADETRACKER.md). No new routes or migrations.

The following requests/responses were captured through a real local HTTP server, isolated SQLite database and actual login/password-confirmation/CSRF flow. **The upstream SOAP responses were synthetic WSDL-shaped fixtures, not a live TradeTracker account.** PHP 8.4 / Laravel 13, implementation based on `a9edfb3`. Capture-only MFA bypass; production MFA rules unchanged.

### `POST /api/v1/admin/affiliate/connections`

```bash
curl -i -X POST 'http://127.0.0.1:8078/api/v1/admin/affiliate/connections' -b cookies.txt \
  -H 'Accept: application/json' -H 'Origin: http://127.0.0.1:8078' \
  -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>' \
  -H 'Content-Type: application/json' \
  --data '{"network":"tradetracker","name":"TradeTracker fixture","credentials":{"customer_id":"123","passphrase":"<configured passphrase>","site_id":"456"}}'
```

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a1736c-cbcd-7393-8045-d0c7ca89f06c

{"data":{"id":"01m5spsjzasjnkpxrpaxrc2a4x","network":"tradetracker","name":"TradeTracker fixture","status":"connected","last_synced_at":null,"last_error":null,"verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES).","postback_url":"http://127.0.0.1:8078/api/v1/affiliate/postback/<postback token>","postback_url_notice":"Shown once. Postbacks can only record conversions as tracked; rewards need the network API or an import."}}
```

### `POST /api/v1/admin/affiliate/connections/01m5spsjzasjnkpxrpaxrc2a4x/test`

```bash
curl -i -X POST 'http://127.0.0.1:8078/api/v1/admin/affiliate/connections/01m5spsjzasjnkpxrpaxrc2a4x/test' -b cookies.txt \
  -H 'Accept: application/json' -H 'Origin: http://127.0.0.1:8078' \
  -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1736c-cc45-701e-aaf1-a74d9f249e59

{"data":{"id":"01m5spsjzasjnkpxrpaxrc2a4x","network":"tradetracker","name":"TradeTracker fixture","status":"connected","last_synced_at":null,"last_error":null,"verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES)."}}
```

### `POST /api/v1/admin/affiliate/connections/01m5spsjzasjnkpxrpaxrc2a4x/sync`

```bash
curl -i -X POST 'http://127.0.0.1:8078/api/v1/admin/affiliate/connections/01m5spsjzasjnkpxrpaxrc2a4x/sync' -b cookies.txt \
  -H 'Accept: application/json' -H 'Origin: http://127.0.0.1:8078' \
  -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1736c-cc67-70c0-9948-8d9f567b8414

{"data":{"programs":1,"transactions":0,"error":null,"connection":{"id":"01m5spsjzasjnkpxrpaxrc2a4x","network":"tradetracker","name":"TradeTracker fixture","status":"connected","last_synced_at":"2026-10-25T14:17:52+00:00","last_error":null,"verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES)."}}}
```

### `GET /api/v1/admin/affiliate/connections`

```bash
curl -i -X GET 'http://127.0.0.1:8078/api/v1/admin/affiliate/connections' -b cookies.txt \
  -H 'Accept: application/json' -H 'Origin: http://127.0.0.1:8078' \
  -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1736c-cc9d-72ed-ab42-54a83e6a255d

{"data":[{"id":"01m5spsjzasjnkpxrpaxrc2a4x","network":"tradetracker","name":"TradeTracker fixture","status":"connected","last_synced_at":"2026-10-25T14:17:52+00:00","last_error":null,"verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES)."}]}
```

### `POST /api/v1/admin/affiliate/connections/01m5spsjzasjnkpxrpaxrc2a4x/disable`

```bash
curl -i -X POST 'http://127.0.0.1:8078/api/v1/admin/affiliate/connections/01m5spsjzasjnkpxrpaxrc2a4x/disable' -b cookies.txt \
  -H 'Accept: application/json' -H 'Origin: http://127.0.0.1:8078' \
  -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1736c-ccba-723f-a806-077bda6408d6

{"data":{"id":"01m5spsjzasjnkpxrpaxrc2a4x","network":"tradetracker","name":"TradeTracker fixture","status":"disabled","last_synced_at":"2026-10-25T14:17:52+00:00","last_error":null,"verified":false,"verification_note":"Adapter not verified against a live account (OPEN-PROVIDER-CAPABILITIES)."}}
```

### `POST /api/v1/admin/affiliate/connections/01m5spsjzasjnkpxrpaxrc2a4x/sync`

```bash
curl -i -X POST 'http://127.0.0.1:8078/api/v1/admin/affiliate/connections/01m5spsjzasjnkpxrpaxrc2a4x/sync' -b cookies.txt \
  -H 'Accept: application/json' -H 'Origin: http://127.0.0.1:8078' \
  -H 'X-XSRF-TOKEN: <decoded XSRF-TOKEN cookie>'
```

```http
HTTP/1.1 409 Conflict
X-Request-Id: 01a1736c-ccdd-70bc-b779-2dc1c3eaae66
Content-Type: application/json

{"error":{"status":409,"code":"connection_disabled","message":"This connection is disabled.","request_id":"01a1736c-ccdd-70bc-b779-2dc1c3eaae66"}}
```


## #25 AI proposals and experiments — B14b, 2026-10-26

Backend-only extension; see [configuration and frontend handoff](../banners/AI_EXPERIMENTS.md). Real HTTP captures below use an isolated SQLite database, actual Sanctum bearer authentication, actual queued Laravel worker and explicitly **synthetic OpenAI Responses fixtures**. Base commit `5f0823d`. No live provider/storefront/staging acceptance is claimed. Merchant reward fixture cost is 1000 + 300 fee =1300 minor units, computed through MerchantFunding. Hypotheses are rendered from validated fields, not free-form AI claims.

All AI operations require offers.manage; insight reads/reviews additionally require orders.view. Support sessions cannot use AI or experiment operations. A reviewed proposal creates a private draft only. Publish is still an explicit existing action. Experiments use already published immutable versions. Public config accepts assignments[CAMPAIGN]=TOKEN and is private/no-store when assigned. Event assignment_token is optional outside experiments, required inside; holdout version0 accepts eligible only. Signed Shopbonus order format supports order.attribution.assignment_token; it is not proof of payment.

### ai_request — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"kind":"designs","request_key":"4d0f2dec-e09e-4a73-86aa-36f61d76d0be"}'
```

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a176e0-8e58-72cd-b1ef-f0a6b8fb9bcd

{"data":{"id":"01m5ve13kg3n3jh4gr2q7cnyzc","kind":"designs","status":"queued","failure_code":null,"source_revision":2,"evidence":{"E1":{"template":"ribbon","anchor":"bottom","style":{"background":"#123456","text":"#FFFFFF","font":"inter"}},"E2":{"reward_minor":1000,"fee_minor":300,"total_minor":1300,"basis":"fixed","min_order_minor":10000,"currency":"EUR"}},"proposals":[],"review":null,"human_review_required":true,"measured_uplift":null}}
```

### ai_show — `GET /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests/01m5ve13kg3n3jh4gr2q7cnyzc`

```bash
curl --globoff -i -X GET 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests/01m5ve13kg3n3jh4gr2q7cnyzc' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e0-9048-72a8-b26c-c8cf620b52de

{"data":{"id":"01m5ve13kg3n3jh4gr2q7cnyzc","kind":"designs","status":"completed","failure_code":null,"source_revision":2,"evidence":{"E1":{"template":"ribbon","anchor":"bottom","style":{"background":"#123456","text":"#FFFFFF","font":"inter"}},"E2":{"reward_minor":1000,"fee_minor":300,"total_minor":1300,"basis":"fixed","min_order_minor":10000,"currency":"EUR"}},"proposals":[{"index":0,"hypothesis":"Test placement bottom with 0 seconds delay. No measured uplift is established.","evidence_refs":["E1"],"editable_config":{"template":"ribbon","placement":{"anchor":"bottom"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}},{"index":1,"hypothesis":"Test placement top with 0 seconds delay. No measured uplift is established.","evidence_refs":["E1"],"editable_config":{"template":"ribbon","placement":{"anchor":"top"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}},{"index":2,"hypothesis":"Test placement bottom_right with 0 seconds delay. No measured uplift is established.","evidence_refs":["E1"],"editable_config":{"template":"ribbon","placement":{"anchor":"bottom_right"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}}],"review":null,"human_review_required":true,"measured_uplift":null}}
```

### ai_index — `GET /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests`

```bash
curl --globoff -i -X GET 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e0-9066-7374-918f-d4f10d72be23

{"current_page":1,"data":[{"id":"01m5ve13kg3n3jh4gr2q7cnyzc","kind":"designs","status":"completed","failure_code":null,"source_revision":2,"evidence":{"E1":{"template":"ribbon","anchor":"bottom","style":{"background":"#123456","text":"#FFFFFF","font":"inter"}},"E2":{"reward_minor":1000,"fee_minor":300,"total_minor":1300,"basis":"fixed","min_order_minor":10000,"currency":"EUR"}},"proposals":[{"index":0,"hypothesis":"Test placement bottom with 0 seconds delay. No measured uplift is established.","evidence_refs":["E1"],"editable_config":{"template":"ribbon","placement":{"anchor":"bottom"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}},{"index":1,"hypothesis":"Test placement top with 0 seconds delay. No measured uplift is established.","evidence_refs":["E1"],"editable_config":{"template":"ribbon","placement":{"anchor":"top"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}},{"index":2,"hypothesis":"Test placement bottom_right with 0 seconds delay. No measured uplift is established.","evidence_refs":["E1"],"editable_config":{"template":"ribbon","placement":{"anchor":"bottom_right"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}}],"review":null,"human_review_required":true,"measured_uplift":null}],"first_page_url":"http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests?page=1","from":1,"last_page":1,"last_page_url":"http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests?page=1","links":[{"url":null,"label":"&laquo; Previous","page":null,"active":false},{"url":"http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests?page=1","label":"1","page":1,"active":true},{"url":null,"label":"Next &raquo;","page":null,"active":false}],"next_page_url":null,"path":"http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests","per_page":20,"prev_page_url":null,"to":1,"total":1}
```

### ai_review — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests/01m5ve13kg3n3jh4gr2q7cnyzc/review`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests/01m5ve13kg3n3jh4gr2q7cnyzc/review' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"decision":"accept","proposal":0,"note":"Human review of synthetic provider layout for a private draft."}'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e0-9086-705e-b884-a2414a559455

{"data":{"request":{"id":"01m5ve13kg3n3jh4gr2q7cnyzc","kind":"designs","status":"completed","failure_code":null,"source_revision":2,"evidence":{"E1":{"template":"ribbon","anchor":"bottom","style":{"background":"#123456","text":"#FFFFFF","font":"inter"}},"E2":{"reward_minor":1000,"fee_minor":300,"total_minor":1300,"basis":"fixed","min_order_minor":10000,"currency":"EUR"}},"proposals":[{"index":0,"hypothesis":"Test placement bottom with 0 seconds delay. No measured uplift is established.","evidence_refs":["E1"],"editable_config":{"template":"ribbon","placement":{"anchor":"bottom"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}},{"index":1,"hypothesis":"Test placement top with 0 seconds delay. No measured uplift is established.","evidence_refs":["E1"],"editable_config":{"template":"ribbon","placement":{"anchor":"top"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}},{"index":2,"hypothesis":"Test placement bottom_right with 0 seconds delay. No measured uplift is established.","evidence_refs":["E1"],"editable_config":{"template":"ribbon","placement":{"anchor":"bottom_right"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}}],"review":{"decision":"accept","proposal":0},"human_review_required":true,"measured_uplift":null},"draft_campaign":"01m5ve144tgsp2w3nztt1qf879","published":false}}
```

### ai_review_conflict — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests/01m5ve13kg3n3jh4gr2q7cnyzc/review`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests/01m5ve13kg3n3jh4gr2q7cnyzc/review' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"decision":"accept","proposal":1,"note":"Repeated review"}'
```

```http
HTTP/1.1 409 Conflict
Content-Type: application/json
X-Request-Id: 01a176e0-90b3-73c9-bfdb-1e83d6193f4a

{"error":{"status":409,"code":"banner_ai_review_conflict","message":"Only an unreviewed completed request can be reviewed.","request_id":"01a176e0-90b3-73c9-bfdb-1e83d6193f4a"}}
```

### ai_insights_request — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"kind":"insights","request_key":"84f657e0-737d-446a-a4c4-ad7e295b027e"}'
```

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a176e0-90d5-7048-80dc-e65c981649af

{"data":{"id":"01m5ve1477w2z043r74s0ewjsq","kind":"insights","status":"queued","failure_code":null,"source_revision":2,"evidence":{"E1":{"template":"ribbon","anchor":"bottom","style":{"background":"#123456","text":"#FFFFFF","font":"inter"}},"E2":{"reward_minor":1000,"fee_minor":300,"total_minor":1300,"basis":"fixed","min_order_minor":10000,"currency":"EUR"},"E3":{"sample_size":10,"window_days":90,"currency":"EUR","net_revenue_minor":100000},"E4":{"viewable_impressions":0,"cta_clicks":0,"ctr":null,"attributed_orders":0,"attributed_revenue_minor":0}},"proposals":[],"review":null,"human_review_required":true,"measured_uplift":null}}
```

### ai_insights_show — `GET /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests/01m5ve1477w2z043r74s0ewjsq`

```bash
curl --globoff -i -X GET 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests/01m5ve1477w2z043r74s0ewjsq' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e0-92bc-7267-a104-ebc34b829c73

{"data":{"id":"01m5ve1477w2z043r74s0ewjsq","kind":"insights","status":"completed","failure_code":null,"source_revision":2,"evidence":{"E1":{"template":"ribbon","anchor":"bottom","style":{"background":"#123456","text":"#FFFFFF","font":"inter"}},"E2":{"reward_minor":1000,"fee_minor":300,"total_minor":1300,"basis":"fixed","min_order_minor":10000,"currency":"EUR"},"E3":{"sample_size":10,"window_days":90,"currency":"EUR","net_revenue_minor":100000},"E4":{"viewable_impressions":0,"cta_clicks":0,"ctr":null,"attributed_orders":0,"attributed_revenue_minor":0}},"proposals":[{"index":0,"hypothesis":"Test placement bottom with 20 seconds delay. No measured uplift is established.","evidence_refs":["E3"],"editable_config":{"template":"ribbon","placement":{"anchor":"bottom"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"},{"type":"min_seconds","value":20}]},"devices":["desktop","mobile"]}},{"index":1,"hypothesis":"Test placement top with 20 seconds delay. No measured uplift is established.","evidence_refs":["E3"],"editable_config":{"template":"ribbon","placement":{"anchor":"top"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"},{"type":"min_seconds","value":20}]},"devices":["desktop","mobile"]}},{"index":2,"hypothesis":"Test placement bottom_right with 20 seconds delay. No measured uplift is established.","evidence_refs":["E3"],"editable_config":{"template":"ribbon","placement":{"anchor":"bottom_right"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"},{"type":"min_seconds","value":20}]},"devices":["desktop","mobile"]}}],"review":null,"human_review_required":true,"measured_uplift":null}}
```

### ai_disabled — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"kind":"designs","request_key":"d5fdfd35-0a1d-4754-a059-7dce4f031ec0"}'
```

```http
HTTP/1.1 503 Service Unavailable
Content-Type: application/json
X-Request-Id: 01a176e0-92db-71f1-a4c8-41f61a56ee95

{"error":{"status":503,"code":"banner_ai_unavailable","message":"Banner AI is not configured.","request_id":"01a176e0-92db-71f1-a4c8-41f61a56ee95"}}
```

### ai_quota — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"kind":"designs","request_key":"349bfdd4-1a15-4866-a021-067e3d1cf350"}'
```

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/json
X-Request-Id: 01a176e0-92f9-71b0-81d2-71dc0dd35c7e

{"error":{"status":429,"code":"banner_ai_limit","message":"The banner AI daily request limit has been reached.","request_id":"01a176e0-92f9-71b0-81d2-71dc0dd35c7e"}}
```

### ai_401 — `GET /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests`

```bash
curl --globoff -i -X GET 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests' -H 'Accept: application/json'
```

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a176e0-931c-728d-86d7-4fa91defd308

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a176e0-931c-728d-86d7-4fa91defd308"}}
```

### ai_422 — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"kind":"invalid","request_key":"bad"}'
```

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json
X-Request-Id: 01a176e0-933c-7368-bfc1-dadddda26c2e

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a176e0-933c-7368-bfc1-dadddda26c2e","fields":{"kind":["The selected kind is invalid."],"request_key":["The request key field must be a valid UUID."]}}}
```

### experiment_start — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"control_version":null,"variant_version":2,"variant_bps":5000}'
```

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a176e0-935c-734c-ab2e-6220c3b3907f

{"data":{"experiment":"01m5ve14vbdpker3jk2vct6wd4","active":true,"holdout":true,"variant_bps":5000,"arms":{"control":{"assigned_visitors":0,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":null},"variant":{"assigned_visitors":0,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":null}},"currency":"EUR","causal_uplift":null,"definitions":{"orders":"Only signed-provider order data carrying a valid assignment and confirmed paid inside its 30-day window. Redirects and clicks are not payments.","limitations":"Consented assigned visitors only; provider/storefront must propagate assignment_token. Missing tokens remain unattributed. No statistical significance or uplift claimed."}}}
```

### assignment — `POST /api/v1/banners/assignments`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/banners/assignments' -H 'Accept: application/json' -H 'Origin: https://example.nl' -H 'Content-Type: application/json' --data '{"key":"pk_n1r4ixzensd4liy5ykwiazo0","visitor_key":"d47767ba-2df5-42af-86d3-4752961cec27","consent":true}'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e0-9383-72c9-b474-3ffb46e89766

{"data":[{"campaign":"01m5ve13bshvn74mecqq9bq5kc","assignment_token":"<ASSIGNMENT_TOKEN>","arm":"variant","version":2,"holdout":false,"expires_at":"2026-11-25T06:23:11+00:00"}]}
```

### experiment_config — `GET /api/v1/banners/config`

```bash
curl --globoff -i -X GET 'http://127.0.0.1:8078/api/v1/banners/config?key=pk_n1r4ixzensd4liy5ykwiazo0&assignments%5B01m5ve13bshvn74mecqq9bq5kc%5D=%3CASSIGNMENT_TOKEN%3E' -H 'Accept: application/json' -H 'Origin: https://example.nl'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e0-939d-7063-8f79-1e67d1be3f32

{"data":{"campaigns":[{"campaign":"01m5ve13bshvn74mecqq9bq5kc","version":2,"config":{"template":"ribbon","placement":{"anchor":"bottom"},"content":{"heading":"Next-purchase reward","subtitle":"Approved offer terms apply","cta_label":"View offer","cta_url":"https://example.nl/rewards","terms":"Original terms"},"style":{"background":"#123456","text":"#FFFFFF","font":"inter","animation":"none"},"frequency":{"per_session":1,"hide_after_dismiss":true},"triggers":{"all":[{"type":"url_match","value":"/collections/*"}]},"devices":["desktop","mobile"]}}]}}
```

### experiment_event — `POST /api/v1/banners/events`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/banners/events' -H 'Accept: application/json' -H 'Origin: https://example.nl' -H 'Content-Type: application/json' --data '{"key":"pk_n1r4ixzensd4liy5ykwiazo0","events":[{"id":"5bef92cc-b899-4591-ac69-29ec1573abb1","campaign":"01m5ve13bshvn74mecqq9bq5kc","version":2,"type":"eligible","consent":true,"assignment_token":"<ASSIGNMENT_TOKEN>","occurred_at":"2026-10-26T06:23:11Z","device":"desktop"}]}'
```

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a176e0-93b7-709b-8a90-36dca9cae0b5

{"data":{"accepted":1,"duplicates":0,"rejected":0,"click_ids":[]}}
```

### experiment_show — `GET /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment`

```bash
curl --globoff -i -X GET 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e0-93d2-7224-be6a-78a42be53837

{"data":{"experiment":"01m5ve14vbdpker3jk2vct6wd4","active":true,"holdout":true,"variant_bps":5000,"arms":{"control":{"assigned_visitors":0,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":null},"variant":{"assigned_visitors":1,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":0}},"currency":"EUR","causal_uplift":null,"definitions":{"orders":"Only signed-provider order data carrying a valid assignment and confirmed paid inside its 30-day window. Redirects and clicks are not payments.","limitations":"Consented assigned visitors only; provider/storefront must propagate assignment_token. Missing tokens remain unattributed. No statistical significance or uplift claimed."}}}
```

### assignment_revoke — `POST /api/v1/banners/assignments`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/banners/assignments' -H 'Accept: application/json' -H 'Origin: https://example.nl' -H 'Content-Type: application/json' --data '{"key":"pk_n1r4ixzensd4liy5ykwiazo0","visitor_key":"d47767ba-2df5-42af-86d3-4752961cec27","consent":false}'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e0-93ee-717c-8ddd-9c3a1f4bb738

{"data":[]}
```

### assignment_origin — `POST /api/v1/banners/assignments`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/banners/assignments' -H 'Accept: application/json' -H 'Origin: https://wrong.example' -H 'Content-Type: application/json' --data '{"key":"pk_n1r4ixzensd4liy5ykwiazo0","visitor_key":"5d8fb846-e27e-41d2-804b-cbd38dbd83eb","consent":true}'
```

```http
HTTP/1.1 403 Forbidden
Content-Type: application/json
X-Request-Id: 01a176e0-9406-7122-a26a-ef23e468f675

{"error":{"status":403,"code":"origin_not_allowed","message":"Unknown key or origin not allowed.","request_id":"01a176e0-9406-7122-a26a-ef23e468f675"}}
```

### experiment_stop — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment/stop`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment/stop' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e0-9426-727e-bace-ee4361ca3f32

{"data":{"experiment":"01m5ve14vbdpker3jk2vct6wd4","active":false,"holdout":true,"variant_bps":5000,"arms":{"control":{"assigned_visitors":0,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":null},"variant":{"assigned_visitors":1,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":0}},"currency":"EUR","causal_uplift":null,"definitions":{"orders":"Only signed-provider order data carrying a valid assignment and confirmed paid inside its 30-day window. Redirects and clicks are not payments.","limitations":"Consented assigned visitors only; provider/storefront must propagate assignment_token. Missing tokens remain unattributed. No statistical significance or uplift claimed."}}}
```

### ai_key_conflict — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"kind":"insights","request_key":"4d0f2dec-e09e-4a73-86aa-36f61d76d0be"}'
```

```http
HTTP/1.1 409 Conflict
Content-Type: application/json
X-Request-Id: 01a176e5-dba0-719d-84cb-2116d639676e

{"error":{"status":409,"code":"request_key_conflict","message":"The request key belongs to another AI request.","request_id":"01a176e5-dba0-719d-84cb-2116d639676e"}}
```

### experiment_restart — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"control_version":1,"variant_version":2,"variant_bps":5000}'
```

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a176e5-dbcd-7057-b146-d6109fe4a14e

{"data":{"experiment":"01m5vebpyy05yhvxd2rcrzjxqh","active":true,"holdout":false,"variant_bps":5000,"arms":{"control":{"assigned_visitors":0,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":null},"variant":{"assigned_visitors":0,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":null}},"currency":"EUR","causal_uplift":null,"definitions":{"orders":"Only signed-provider order data carrying a valid assignment and confirmed paid inside its 30-day window. Redirects and clicks are not payments.","limitations":"Consented assigned visitors only; provider/storefront must propagate assignment_token. Missing tokens remain unattributed. No statistical significance or uplift claimed."}}}
```

### experiment_conflict — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"control_version":1,"variant_version":2,"variant_bps":5000}'
```

```http
HTTP/1.1 409 Conflict
Content-Type: application/json
X-Request-Id: 01a176e5-dbf8-734e-86b2-0eca1b011dce

{"error":{"status":409,"code":"banner_experiment_conflict","message":"Start experiments on a live banner without another active experiment.","request_id":"01a176e5-dbf8-734e-86b2-0eca1b011dce"}}
```

### assignment_for_order — `POST /api/v1/banners/assignments`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/banners/assignments' -H 'Accept: application/json' -H 'Origin: https://example.nl' -H 'Content-Type: application/json' --data '{"key":"pk_n1r4ixzensd4liy5ykwiazo0","visitor_key":"b630f663-4a4b-428f-a060-f5aac0e4b947","consent":true}'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e5-dc13-7141-ad15-d51fb3c7667e

{"data":[{"campaign":"01m5ve13bshvn74mecqq9bq5kc","assignment_token":"<ASSIGNMENT_TOKEN>","arm":"variant","version":2,"holdout":false,"expires_at":"2026-11-25T06:28:58+00:00"}]}
```

### order_forged — `POST /api/v1/webhooks/prestashop/<WEBHOOK_TOKEN>`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/webhooks/prestashop/<WEBHOOK_TOKEN>' -H 'Accept: application/json' -H 'X-Shopbonus-Event-Id: fixture-experiment-capture' -H 'X-Shopbonus-Topic: order.paid' -H 'X-Shopbonus-Signature: <SIGNATURE_V1>' -H 'Content-Type: application/json' --data '{"order":{"id":"fixture-experiment-paid","reference":"FIXTURE-ORDER","customer":{"first_name":"Fixture","email":"fixture@example.test"},"total_minor":10000,"currency":"EUR","payment_status":"paid","created_at":"2026-10-26T06:28:58Z","paid_at":"2026-10-26T06:28:58Z","attribution":{"assignment_token":"<ASSIGNMENT_TOKEN>"}}}'
```

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a176e5-dc35-73b5-8690-f79e4e673e13

{"error":{"status":401,"code":"webhook_rejected","message":"The webhook could not be verified.","request_id":"01a176e5-dc35-73b5-8690-f79e4e673e13","details":{"reason":"invalid_signature"}}}
```

### order_signed — `POST /api/v1/webhooks/prestashop/<WEBHOOK_TOKEN>`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/webhooks/prestashop/<WEBHOOK_TOKEN>' -H 'Accept: application/json' -H 'X-Shopbonus-Event-Id: fixture-experiment-capture' -H 'X-Shopbonus-Topic: order.paid' -H 'X-Shopbonus-Signature: <SIGNATURE_V1>' -H 'Content-Type: application/json' --data '{"order":{"id":"fixture-experiment-paid","reference":"FIXTURE-ORDER","customer":{"first_name":"Fixture","email":"fixture@example.test"},"total_minor":10000,"currency":"EUR","payment_status":"paid","created_at":"2026-10-26T06:28:58Z","paid_at":"2026-10-26T06:28:58Z","attribution":{"assignment_token":"<ASSIGNMENT_TOKEN>"}}}'
```

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a176e5-dc5b-7313-bdd5-6dca31817fb1

{"data":{"received":true,"duplicate":false,"event_id":"01m5vebq32wapvxeb5jwwf77np"}}
```

### experiment_after_order — `GET /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment`

```bash
curl --globoff -i -X GET 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e5-ddf6-723b-8796-a8d40d07c4fb

{"data":{"experiment":"01m5vebpyy05yhvxd2rcrzjxqh","active":true,"holdout":false,"variant_bps":5000,"arms":{"control":{"assigned_visitors":0,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":null},"variant":{"assigned_visitors":1,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":1,"converted_visitors":1,"net_revenue_minor":10000,"conversion_rate":1}},"currency":"EUR","causal_uplift":null,"definitions":{"orders":"Only signed-provider order data carrying a valid assignment and confirmed paid inside its 30-day window. Redirects and clicks are not payments.","limitations":"Consented assigned visitors only; provider/storefront must propagate assignment_token. Missing tokens remain unattributed. No statistical significance or uplift claimed."}}}
```

### experiment_stop_final — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment/stop`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment/stop' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176e5-de12-7026-9bed-bcfb0b28af7b

{"data":{"experiment":"01m5vebpyy05yhvxd2rcrzjxqh","active":false,"holdout":false,"variant_bps":5000,"arms":{"control":{"assigned_visitors":0,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":0,"converted_visitors":0,"net_revenue_minor":0,"conversion_rate":null},"variant":{"assigned_visitors":1,"viewable_impressions":0,"cta_clicks":0,"verified_paid_orders":1,"converted_visitors":1,"net_revenue_minor":10000,"conversion_rate":1}},"currency":"EUR","causal_uplift":null,"definitions":{"orders":"Only signed-provider order data carrying a valid assignment and confirmed paid inside its 30-day window. Redirects and clicks are not payments.","limitations":"Consented assigned visitors only; provider/storefront must propagate assignment_token. Missing tokens remain unattributed. No statistical significance or uplift claimed."}}}
```

### experiment_stop_conflict — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment/stop`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/experiment/stop' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 409 Conflict
Content-Type: application/json
X-Request-Id: 01a176e5-de31-7260-81d7-e94cf579ee92

{"error":{"status":409,"code":"banner_experiment_missing","message":"This banner has no active experiment.","request_id":"01a176e5-de31-7260-81d7-e94cf579ee92"}}
```

### editor_pairing — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/pairing`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/pairing' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"origin":"https://example.nl"}'
```

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a176ef-7533-7126-8c76-37abeda69449

{"data":{"pairing_token":"<PAIRING_TOKEN>","expires_in":600,"origin":"https://example.nl"}}
```

### editor_exchange — `POST /api/v1/banners/editor/pair`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/banners/editor/pair' -H 'Accept: application/json' -H 'Origin: https://example.nl' -H 'Content-Type: application/json' --data '{"pairing_token":"<PAIRING_TOKEN>"}'
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a176ef-7569-71b2-bef0-2d238936d276

{"data":{"editor_token":"<EDITOR_TOKEN>","expires_in":1800,"scope":"banner_drafts"}}
```

### editor_pairing_pending — `POST /api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/pairing`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/pairing' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"origin":"https://example.nl"}'
```

```http
HTTP/1.1 201 Created
Content-Type: application/json
X-Request-Id: 01a176ef-7584-7334-93e0-fc94d258d6ab

{"data":{"pairing_token":"<PAIRING_TOKEN>","expires_in":600,"origin":"https://example.nl"}}
```

### editor_exchange_revoked — `POST /api/v1/banners/editor/pair`

```bash
curl --globoff -i -X POST 'http://127.0.0.1:8078/api/v1/banners/editor/pair' -H 'Accept: application/json' -H 'Origin: https://example.nl' -H 'Content-Type: application/json' --data '{"pairing_token":"<PAIRING_TOKEN>"}'
```

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a176ef-76cb-73be-abc6-534182a720c7

{"error":{"status":401,"code":"editor_access_revoked","message":"Editor access is no longer authorized.","request_id":"01a176ef-76cb-73be-abc6-534182a720c7"}}
```

### editor_get_revoked — `GET /api/v1/banners/editor/campaigns/01m5ve13bshvn74mecqq9bq5kc`

```bash
curl --globoff -i -X GET 'http://127.0.0.1:8078/api/v1/banners/editor/campaigns/01m5ve13bshvn74mecqq9bq5kc' -H 'Accept: application/json' -H 'X-Banner-Editor-Token: <EDITOR_TOKEN>' -H 'Origin: https://example.nl'
```

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a176ef-76f1-71f7-bd1f-17337630d90d

{"error":{"status":401,"code":"editor_access_revoked","message":"Editor access is no longer authorized.","request_id":"01a176ef-76f1-71f7-bd1f-17337630d90d"}}
```

### editor_put_revoked — `PUT /api/v1/banners/editor/campaigns/01m5ve13bshvn74mecqq9bq5kc`

```bash
curl --globoff -i -X PUT 'http://127.0.0.1:8078/api/v1/banners/editor/campaigns/01m5ve13bshvn74mecqq9bq5kc' -H 'Accept: application/json' -H 'X-Banner-Editor-Token: <EDITOR_TOKEN>' -H 'Origin: https://example.nl' -H 'Content-Type: application/json' --data '{"revision":2,"config":{"template":"ribbon"}}'
```

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a176ef-7711-738d-a236-5a2c0b6cd0b7

{"error":{"status":401,"code":"editor_access_revoked","message":"Editor access is no longer authorized.","request_id":"01a176ef-7711-738d-a236-5a2c0b6cd0b7"}}
```

### Banner AI quota — Retry-After capture

```bash
curl -i -X POST 'http://127.0.0.1:8078/api/v1/workspaces/01m5ve138qmb5dwat98jsz4dkx/banners/01m5ve13bshvn74mecqq9bq5kc/ai-requests' -H 'Accept: application/json' -H 'Authorization: Bearer <WORKSPACE_TOKEN>' -H 'Content-Type: application/json' --data '{"kind":"designs","request_key":"ff42db9e-26b5-4f72-b4ae-7a6f5d2463ab"}'
```

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/json
X-Request-Id: 01a176f1-ea1a-7167-9ad2-c5202a6a966c
Retry-After: 62271

{"error":{"status":429,"code":"banner_ai_limit","message":"The banner AI daily request limit has been reached.","request_id":"01a176f1-ea1a-7167-9ad2-c5202a6a966c"}}
```

## B17 — verified support chat (#26), 2026-10-26
Captured against real local Laravel13/PHP8.4 HTTP server8079, isolated synthetic SQLite fixtures, actual bearer or first-party browser authentication, recent password and a seeded OTP challenge verified through the real step-up endpoint. A real database queue worker processed each request. **OpenAI completions are synthetic HTTP fixtures; no live OpenAI/account/model evidence is claimed.** Broadcast driver was null for these HTTP captures; separate local Reverb evidence is in SUPPORT_CHAT.md. No customer/provider secrets appear below. Code base7c1657d plus B17 working changes.
Contracts/setup/limitations and all ten acceptance mappings: [SUPPORT_CHAT.md](../support/SUPPORT_CHAT.md). POST chat returns202 with a durable conversation/message ID; poll message or conversation for progress/result. Statuses PENDING/RESEARCHING/RETRYING/VERIFYING then VERIFIED/PARTIALLY_VERIFIED/NEEDS_CLARIFICATION/ESCALATED/UNAVAILABLE. Never display a question or intermediate provider output as a verified answer. `result.checked_at` denotes historical evidence verification; balances can subsequently change.
Consumers and workspaces use one chat API. Workspace/locale are pinned to the owned conversation; continuation infers them if omitted. Tool IDs/required evidence/permissions are server-owned. No financial writes or code disclosure. Knowledge search uses published audience-scoped FAQ; CMS publication/rollback and existing admin FAQ revision workflow remain authoritative. Feedback is immutable; downvotes open reviews. Human reply is labelled human_support. Approved errors add regressions and can link already-published corrections; votes never retrain/change behavior. Policies use existing /admin/platform-settings APIs with private key support.ai, a test endpoint, independent review/publication and rollback draft; all admin mutations need recent password+OTP. Existing step-up423/MFA403/CSRF419/security/error envelopes apply.

### Capture 1 — `POST /api/v1/admin/platform-settings`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"key":"support.ai","value":{"enabled":true,"model":null,"languages":["en","nl"],"daily_request_limit":100,"timeout_seconds":20,"max_retry":1,"restrictions":[]},"reason":"Enable reviewed support capture policy"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: capture-b17-93c7124d-afba-4c09-a741-09e4c630770d

{
  "data": {
    "id": "01m5vk0b8knw7zyfk8tkhjyjr1",
    "key": "support.ai",
    "version": 1,
    "value": {
      "enabled": true,
      "model": null,
      "languages": [
        "en",
        "nl"
      ],
      "daily_request_limit": 100,
      "timeout_seconds": 20,
      "max_retry": 1,
      "restrictions": []
    },
    "checksum": "1a913d84164719e1a6b17707aab995471b7bfdb9b65c44fbfe15b2003b5b7ac1",
    "status": "draft",
    "reason": "Enable reviewed support capture policy",
    "created_by": "01m5vk0a27asz682vehdtfk917",
    "reviewed_by": null,
    "review_note": null,
    "published_at": null,
    "is_current": false,
    "rollback_of": null
  }
}
```

### Capture 2 — `POST /api/v1/admin/platform-settings/01m5vk0b8knw7zyfk8tkhjyjr1/review`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5vk0b8knw7zyfk8tkhjyjr1/review -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-c06e332c-673e-4907-9b25-2ba8f9e551e9

{
  "data": {
    "id": "01m5vk0b8knw7zyfk8tkhjyjr1",
    "key": "support.ai",
    "version": 1,
    "value": {
      "enabled": true,
      "model": null,
      "languages": [
        "en",
        "nl"
      ],
      "daily_request_limit": 100,
      "timeout_seconds": 20,
      "max_retry": 1,
      "restrictions": []
    },
    "checksum": "1a913d84164719e1a6b17707aab995471b7bfdb9b65c44fbfe15b2003b5b7ac1",
    "status": "review",
    "reason": "Enable reviewed support capture policy",
    "created_by": "01m5vk0a27asz682vehdtfk917",
    "reviewed_by": null,
    "review_note": null,
    "published_at": null,
    "is_current": false,
    "rollback_of": null
  }
}
```

### Capture 3 — `POST /api/v1/admin/platform-settings/01m5vk0b8knw7zyfk8tkhjyjr1/publish`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5vk0b8knw7zyfk8tkhjyjr1/publish -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"note":"Independent review before required test"}'
```

```http
HTTP/1.1 409
X-Request-Id: capture-b17-8b0f3d71-7700-470b-8c57-729aa0c5673f
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "support_policy_test_required",
    "message": "Test the support policy before publication.",
    "request_id": "capture-b17-8b0f3d71-7700-470b-8c57-729aa0c5673f"
  }
}
```

### Capture 4 — `POST /api/v1/admin/ai/settings/01m5vk0b8knw7zyfk8tkhjyjr1/test`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/settings/01m5vk0b8knw7zyfk8tkhjyjr1/test -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-c1e34a54-69d3-4ede-8d16-1f74f50cb1f0

{
  "data": {
    "total": 100,
    "passed": 100,
    "failed": 0,
    "policy_checksum": "1a913d84164719e1a6b17707aab995471b7bfdb9b65c44fbfe15b2003b5b7ac1",
    "scope": "server_intent_and_immutable_safety_guards",
    "provider_verified": false
  }
}
```

### Capture 5 — `POST /api/v1/admin/platform-settings/01m5vk0b8knw7zyfk8tkhjyjr1/publish`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5vk0b8knw7zyfk8tkhjyjr1/publish -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"note":"Independent review after successful regression test"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-486f4ac4-cf26-46ad-a8b7-ea72b944d255

{
  "data": {
    "id": "01m5vk0b8knw7zyfk8tkhjyjr1",
    "key": "support.ai",
    "version": 1,
    "value": {
      "enabled": true,
      "model": null,
      "languages": [
        "en",
        "nl"
      ],
      "daily_request_limit": 100,
      "timeout_seconds": 20,
      "max_retry": 1,
      "restrictions": []
    },
    "checksum": "1a913d84164719e1a6b17707aab995471b7bfdb9b65c44fbfe15b2003b5b7ac1",
    "status": "published",
    "reason": "Enable reviewed support capture policy",
    "created_by": "01m5vk0a27asz682vehdtfk917",
    "reviewed_by": "01m5vk0a2bkpv948gbxczptnvf",
    "review_note": "Independent review after successful regression test",
    "published_at": "2026-10-26T07:50:08+00:00",
    "is_current": true,
    "rollback_of": null
  }
}
```

### Capture 6 — `GET /api/v1/admin/platform-settings?key=support.ai`

```bash
curl -i -X GET 'http://localhost:8079/api/v1/admin/platform-settings?key=support.ai' -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-baf8f1b6-ecef-4b2a-b515-080602b36dab

{
  "data": [
    {
      "id": "01m5vk0b8knw7zyfk8tkhjyjr1",
      "key": "support.ai",
      "version": 1,
      "value": {
        "enabled": true,
        "model": null,
        "languages": [
          "en",
          "nl"
        ],
        "daily_request_limit": 100,
        "timeout_seconds": 20,
        "max_retry": 1,
        "restrictions": []
      },
      "checksum": "1a913d84164719e1a6b17707aab995471b7bfdb9b65c44fbfe15b2003b5b7ac1",
      "status": "published",
      "reason": "Enable reviewed support capture policy",
      "created_by": "01m5vk0a27asz682vehdtfk917",
      "reviewed_by": "01m5vk0a2bkpv948gbxczptnvf",
      "review_note": "Independent review after successful regression test",
      "published_at": "2026-10-26T07:50:08+00:00",
      "is_current": true,
      "rollback_of": null
    }
  ],
  "published": {
    "values": {
      "public.brand_name": "Shopbonus",
      "public.support_email": null,
      "public.languages": [
        "nl",
        "en"
      ],
      "operations.diagnostics_daily_request_limit": 10,
      "support.ai": {
        "enabled": true,
        "model": null,
        "languages": [
          "en",
          "nl"
        ],
        "daily_request_limit": 100,
        "timeout_seconds": 20,
        "max_retry": 1,
        "restrictions": []
      }
    },
    "versions": {
      "support.ai": "01m5vk0b8knw7zyfk8tkhjyjr1"
    }
  },
  "allowed_keys": [
    "public.brand_name",
    "public.support_email",
    "public.languages",
    "operations.diagnostics_daily_request_limit",
    "support.ai"
  ],
  "meta": {
    "current_page": 1,
    "total": 1,
    "per_page": 25,
    "last_page": 1
  }
}
```

### Capture 7 — `GET /api/v1/admin/ai/reviews`

```bash
curl -i -X GET http://localhost:8079/api/v1/admin/ai/reviews -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 403
X-Request-Id: capture-b17-239863b5-8a33-4120-a416-cfb277b8c6aa
Content-Type: application/json

{
  "error": {
    "status": 403,
    "code": "platform_role_required",
    "message": "This action requires a platform administrator.",
    "request_id": "capture-b17-239863b5-8a33-4120-a416-cfb277b8c6aa"
  }
}
```

### Capture 8 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Idempotency-Key: 160a5d0d-9468-448c-a503-40040596291c' -H 'Content-Type: application/json' --data '{"message":"wallet"}'
```

```http
HTTP/1.1 401
X-Request-Id: capture-b17-c845a2da-5431-4be2-b223-3154c6f573e6
Content-Type: application/json

{
  "error": {
    "status": 401,
    "code": "unauthenticated",
    "message": "Authentication is required.",
    "request_id": "capture-b17-c845a2da-5431-4be2-b223-3154c6f573e6"
  }
}
```

### Capture 9 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: c16be3ac-b58f-4d03-b063-caa0f2a6341e' -H 'Content-Type: application/json' --data '{"message":"wallet","workspace_id":"01m5vk0a30gq47m00rtddg746q"}'
```

```http
HTTP/1.1 404
X-Request-Id: capture-b17-4d8338a3-eaa3-4930-ad5e-7ea643593c54
Content-Type: application/json

{
  "error": {
    "status": 404,
    "code": "not_found",
    "message": "Support evidence is unavailable.",
    "request_id": "capture-b17-4d8338a3-eaa3-4930-ad5e-7ea643593c54"
  }
}
```

### Capture 10 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: f6fb11b3-219a-48e1-b396-bcf4b10d0b1e' -H 'Content-Type: application/json' --data '{"message":"","role":"super_admin"}'
```

```http
HTTP/1.1 422
X-Request-Id: capture-b17-494af70e-ae9d-4212-a6fd-e3500b712874
Content-Type: application/json

{
  "error": {
    "status": 422,
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "request_id": "capture-b17-494af70e-ae9d-4212-a6fd-e3500b712874",
    "fields": {
      "message": [
        "The message field is required."
      ],
      "role": [
        "The role field is prohibited."
      ]
    }
  }
}
```

### Capture 11 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 5134753e-d996-4cd2-ad09-c43613000b97' -H 'Content-Type: application/json' --data '{"message":"What is my wallet balance?","locale":"en"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-f0859b8a-ac4f-4791-8ff5-83043f483546

{
  "data": {
    "id": "01m5vk0bmew1h6k705kgns5rmf",
    "conversation_id": "01m5vk0bmbrmc7dbq482bw9fvm",
    "status": "PENDING"
  }
}
```

### Capture 12 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 485e13de-372a-4869-b5c1-82fd92f994ea' -H 'Content-Type: application/json' --data '{"message":"wallet","conversation_id":"01m5vk0bmbrmc7dbq482bw9fvm"}'
```

```http
HTTP/1.1 409
X-Request-Id: capture-b17-f8199a3c-f15f-475e-b9b1-3bedb7876cc0
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "support_conversation_busy",
    "message": "Wait for the current support answer.",
    "request_id": "capture-b17-f8199a3c-f15f-475e-b9b1-3bedb7876cc0"
  }
}
```

### Capture 13 — `GET /api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-32bba6ae-7cb2-47fb-9578-09d0e2d0f1c2

{
  "data": {
    "id": "01m5vk0bmew1h6k705kgns5rmf",
    "conversation_id": "01m5vk0bmbrmc7dbq482bw9fvm",
    "question": "What is my wallet balance?",
    "status": "VERIFIED",
    "result": {
      "status": "VERIFIED",
      "answer": "Available ShopBonus credit: EUR 10.00; reserved: EUR 0.00; pending: EUR 5.00; spent: EUR 0.00. Cannot be withdrawn or transferred.",
      "evidence_ids": [
        "01m5vk0c2ec3695es6pwbcg0xe"
      ],
      "checked_at": "2026-10-26T07:50:09+00:00",
      "action": {
        "label": "View details",
        "route": "/app/wallet"
      }
    },
    "retry_used": false,
    "created_at": "2026-10-26T07:50:08+00:00",
    "completed_at": "2026-10-26T07:50:09+00:00"
  }
}
```

### Capture 14 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 5134753e-d996-4cd2-ad09-c43613000b97' -H 'Content-Type: application/json' --data '{"message":"Different question"}'
```

```http
HTTP/1.1 409
X-Request-Id: capture-b17-011a5e02-1ee7-409a-b7ab-c50d23703fd4
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "support_idempotency_conflict",
    "message": "This support request key was already used.",
    "request_id": "capture-b17-011a5e02-1ee7-409a-b7ab-c50d23703fd4"
  }
}
```

### Capture 15 — `GET /api/v1/ai/conversations`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/conversations -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-5eb21522-68ab-4688-88ee-f8080441cd11

{
  "data": [
    {
      "id": "01m5vk0bmbrmc7dbq482bw9fvm",
      "locale": "en",
      "workspace_id": null,
      "expires_at": "2026-11-25T07:50:08+00:00"
    }
  ]
}
```

### Capture 16 — `GET /api/v1/ai/conversations/01m5vk0bmbrmc7dbq482bw9fvm`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/conversations/01m5vk0bmbrmc7dbq482bw9fvm -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-30db6b79-1b73-44ab-8a94-a90ed3eab03e

{
  "data": {
    "id": "01m5vk0bmbrmc7dbq482bw9fvm",
    "messages": [
      {
        "id": "01m5vk0bmew1h6k705kgns5rmf",
        "conversation_id": "01m5vk0bmbrmc7dbq482bw9fvm",
        "question": "What is my wallet balance?",
        "status": "VERIFIED",
        "result": {
          "status": "VERIFIED",
          "answer": "Available ShopBonus credit: EUR 10.00; reserved: EUR 0.00; pending: EUR 5.00; spent: EUR 0.00. Cannot be withdrawn or transferred.",
          "evidence_ids": [
            "01m5vk0c2ec3695es6pwbcg0xe"
          ],
          "checked_at": "2026-10-26T07:50:09+00:00",
          "action": {
            "label": "View details",
            "route": "/app/wallet"
          }
        },
        "retry_used": false,
        "created_at": "2026-10-26T07:50:08+00:00",
        "completed_at": "2026-10-26T07:50:09+00:00"
      }
    ],
    "replies": [],
    "next_before": "01m5vk0bmew1h6k705kgns5rmf"
  }
}
```

### Capture 17 — `GET /api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 404
X-Request-Id: capture-b17-9aadd3bd-f8f8-4e2e-9015-60022f64257a
Content-Type: application/json

{
  "error": {
    "status": 404,
    "code": "not_found",
    "message": "Support message not found.",
    "request_id": "capture-b17-9aadd3bd-f8f8-4e2e-9015-60022f64257a"
  }
}
```

### Capture 18 — `POST /api/v1/ai/knowledge/search`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/knowledge/search -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Content-Type: application/json' --data '{"query":"wallet","locale":"en"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-2c70f502-552c-4f31-aaa4-294e3260aa47

{
  "data": [
    {
      "document_id": "01m5vk0a6s1dfzp90vt9na1c43",
      "scope": "consumers",
      "version": 1,
      "locale": "en",
      "content": "ShopBonus credit cannot be withdrawn or transferred.",
      "checked_at": "2026-10-26T07:50:09+00:00"
    }
  ]
}
```

### Capture 19 — `POST /api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf/feedback`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf/feedback -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Content-Type: application/json' --data '{"rating":"down","reason":"unclear","comment":"Please clarify the explanation."}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-fd6b64ed-53f0-4023-b1c9-b503ffcbffad

{
  "data": {
    "id": "01m5vk0cba8cd78qxjw7gxvn6e",
    "rating": "down",
    "review_required": true
  }
}
```

### Capture 20 — `POST /api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf/escalate`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf/escalate -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-9c3ef704-b803-4009-88f5-3ebca679106a

{
  "data": {
    "id": "01m5vk0ccg7jvw5sdqcs35c33w",
    "status": "ESCALATED",
    "notice": "A human support review has been requested."
  }
}
```

### Capture 21 — `GET /api/v1/admin/ai/reviews?outcome=open`

```bash
curl -i -X GET 'http://localhost:8079/api/v1/admin/ai/reviews?outcome=open' -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-d5fdf0ce-3d89-47e4-9beb-42dff63ac48d

{
  "data": [
    {
      "id": "01m5vk0ccg7jvw5sdqcs35c33w",
      "message_id": "01m5vk0bmew1h6k705kgns5rmf",
      "trigger": "customer_request",
      "outcome": null,
      "reviewed_at": null
    },
    {
      "id": "01m5vk0cbcyt07y2mxdh21ttx1",
      "message_id": "01m5vk0bmew1h6k705kgns5rmf",
      "trigger": "negative_feedback",
      "outcome": null,
      "reviewed_at": null
    }
  ],
  "meta": {
    "page": 1,
    "total": 2
  }
}
```

### Capture 22 — `GET /api/v1/admin/ai/reviews/01m5vk0ccg7jvw5sdqcs35c33w`

```bash
curl -i -X GET http://localhost:8079/api/v1/admin/ai/reviews/01m5vk0ccg7jvw5sdqcs35c33w -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-c10734ea-2814-4ee1-96b6-9e455d3a599e

{
  "data": {
    "id": "01m5vk0ccg7jvw5sdqcs35c33w",
    "conversation_id": "01m5vk0bmbrmc7dbq482bw9fvm",
    "message_id": "01m5vk0bmew1h6k705kgns5rmf",
    "question": "What is my wallet balance?",
    "result": {
      "status": "VERIFIED",
      "answer": "Available ShopBonus credit: EUR 10.00; reserved: EUR 0.00; pending: EUR 5.00; spent: EUR 0.00. Cannot be withdrawn or transferred.",
      "evidence_ids": [
        "01m5vk0c2ec3695es6pwbcg0xe"
      ],
      "checked_at": "2026-10-26T07:50:09+00:00",
      "action": {
        "label": "View details",
        "route": "/app/wallet"
      }
    },
    "evidence": {
      "snapshots": [
        {
          "id": "01m5vk0c2ec3695es6pwbcg0xe",
          "tool": "get_my_wallet",
          "arguments": {
            "unit": "eur_credit"
          },
          "source": "consumer_wallet:eur_credit",
          "facts": {
            "available": 1000,
            "reserved": 0,
            "pending": 500,
            "spent": 0,
            "unit": "eur_credit",
            "currency": "EUR"
          },
          "checked_at": "2026-10-26T07:50:09+00:00"
        }
      ],
      "trace": [
        {
          "tool": "get_my_wallet",
          "outcome": "retrieved"
        }
      ]
    },
    "research": {
      "intent": "wallet",
      "tool": "get_my_wallet",
      "arguments": {
        "unit": "eur_credit"
      },
      "reference": null,
      "unit": "eur_credit",
      "missing_reference": false,
      "wrong_scope": false
    },
    "retry_used": false,
    "metrics": {
      "policy_version": "01m5vk0b8knw7zyfk8tkhjyjr1",
      "restriction_action": null,
      "input_tokens": 20,
      "output_tokens": 10,
      "model": "fixture-model",
      "latency_ms": 63,
      "additional_retrieval_rounds": 0
    },
    "failure_code": null,
    "feedback": [
      {
        "rating": "down",
        "reason": "unclear",
        "comment": "Please clarify the explanation."
      }
    ],
    "outcome": null,
    "note": null,
    "regression_added": false
  }
}
```

### Capture 23 — `POST /api/v1/admin/ai/reviews/01m5vk0ccg7jvw5sdqcs35c33w/resolve`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/reviews/01m5vk0ccg7jvw5sdqcs35c33w/resolve -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"outcome":"CONFIRMED_ERROR","note":"Approved explanatory correction linked to published knowledge","expected_intent":"wallet","knowledge_id":"01m5vk0a6s1dfzp90vt9na1c43"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-73f4d38a-803e-4e84-8399-aa29c805bfa2

{
  "data": {
    "id": "01m5vk0ccg7jvw5sdqcs35c33w",
    "outcome": "CONFIRMED_ERROR",
    "regression_added": true
  }
}
```

### Capture 24 — `POST /api/v1/admin/ai/conversations/01m5vk0bmbrmc7dbq482bw9fvm/reply`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/conversations/01m5vk0bmbrmc7dbq482bw9fvm/reply -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"body":"A human reviewed your question. Wallet credit cannot be withdrawn or transferred."}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: capture-b17-280e32be-3c3b-4b83-a9aa-ab192d45d9d4

{
  "data": {
    "id": "01m5vk0cgwtrs2h2e0s6yekx4t",
    "sender": "human_support",
    "body": "A human reviewed your question. Wallet credit cannot be withdrawn or transferred.",
    "created_at": "2026-10-26T07:50:09+00:00"
  }
}
```

### Capture 25 — `GET /api/v1/ai/conversations/01m5vk0bmbrmc7dbq482bw9fvm`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/conversations/01m5vk0bmbrmc7dbq482bw9fvm -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-a38c967f-8f37-4861-9d3f-e4b01da75f01

{
  "data": {
    "id": "01m5vk0bmbrmc7dbq482bw9fvm",
    "messages": [
      {
        "id": "01m5vk0bmew1h6k705kgns5rmf",
        "conversation_id": "01m5vk0bmbrmc7dbq482bw9fvm",
        "question": "What is my wallet balance?",
        "status": "VERIFIED",
        "result": {
          "status": "VERIFIED",
          "answer": "Available ShopBonus credit: EUR 10.00; reserved: EUR 0.00; pending: EUR 5.00; spent: EUR 0.00. Cannot be withdrawn or transferred.",
          "evidence_ids": [
            "01m5vk0c2ec3695es6pwbcg0xe"
          ],
          "checked_at": "2026-10-26T07:50:09+00:00",
          "action": {
            "label": "View details",
            "route": "/app/wallet"
          }
        },
        "retry_used": false,
        "created_at": "2026-10-26T07:50:08+00:00",
        "completed_at": "2026-10-26T07:50:09+00:00"
      }
    ],
    "replies": [
      {
        "id": "01m5vk0cgwtrs2h2e0s6yekx4t",
        "sender": "human_support",
        "body": "A human reviewed your question. Wallet credit cannot be withdrawn or transferred.",
        "created_at": "2026-10-26T07:50:09+00:00"
      }
    ],
    "next_before": "01m5vk0bmew1h6k705kgns5rmf"
  }
}
```

### Capture 26 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: bf8568af-8e84-4037-8882-a784267d4170' -H 'Content-Type: application/json' --data '{"message":"Where is my reward?","locale":"en","reference":"01m5vk0a5hzfygtvhf3744mvg6"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-444b66c5-b92d-4d3b-be6c-15d77d4ddeb7

{
  "data": {
    "id": "01m5vk0ckb25mf0whts8eqvb3v",
    "conversation_id": "01m5vk0ck90cepsvccbs1z0phq",
    "status": "PENDING"
  }
}
```

### Capture 27 — `GET /api/v1/ai/messages/01m5vk0ckb25mf0whts8eqvb3v`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0ckb25mf0whts8eqvb3v -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-85dfe767-40d4-4688-a07f-10c4f129a031

{
  "data": {
    "id": "01m5vk0ckb25mf0whts8eqvb3v",
    "conversation_id": "01m5vk0ck90cepsvccbs1z0phq",
    "question": "Where is my reward?",
    "status": "VERIFIED",
    "result": {
      "status": "VERIFIED",
      "answer": "Available ShopBonus credit: EUR 10.00; reserved: EUR 0.00; pending: EUR 5.00; spent: EUR 0.00. Cannot be withdrawn or transferred.\nRecorded payment status: unknown; wallet reward: pending; fixed reward value: 500 minor units EUR. No release date has been confirmed.",
      "evidence_ids": [
        "01m5vk0d0jgcz5nwq7anncm8d9",
        "01m5vk0d0wsb0c6n6qy8ezebfb"
      ],
      "checked_at": "2026-10-26T07:50:10+00:00",
      "action": {
        "label": "View details",
        "route": "/app/wallet"
      }
    },
    "retry_used": true,
    "created_at": "2026-10-26T07:50:09+00:00",
    "completed_at": "2026-10-26T07:50:10+00:00"
  }
}
```

### Capture 28 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 419cbcb9-3623-4ca3-a8f0-d873c1c94d4c' -H 'Content-Type: application/json' --data '{"message":"Give a voucher quote","locale":"en","reference":"01m5vk0a4mxccz1st2p0aq0rfk"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-e017ff79-8306-4a7c-872b-b4535fcc30f9

{
  "data": {
    "id": "01m5vk0d5n3t9t0t9398pkwn7c",
    "conversation_id": "01m5vk0d5mgps65avrnhxav8bz",
    "status": "PENDING"
  }
}
```

### Capture 29 — `GET /api/v1/ai/messages/01m5vk0d5n3t9t0t9398pkwn7c`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0d5n3t9t0t9398pkwn7c -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-b9b211e9-96c8-4ea9-9eff-bf56beca7212

{
  "data": {
    "id": "01m5vk0d5n3t9t0t9398pkwn7c",
    "conversation_id": "01m5vk0d5mgps65avrnhxav8bz",
    "question": "Give a voucher quote",
    "status": "VERIFIED",
    "result": {
      "status": "VERIFIED",
      "answer": "Current quote in EUR: price 2000 minor units; wallet 1000 minor units; Mollie 1000 minor units; stock available; purchasable yes. No payment or voucher issuance has occurred.",
      "evidence_ids": [
        "01m5vk0dk5ryfesa2g4yz2978r"
      ],
      "checked_at": "2026-10-26T07:50:10+00:00",
      "action": {
        "label": "View details",
        "route": "/app/vouchers"
      }
    },
    "retry_used": false,
    "created_at": "2026-10-26T07:50:10+00:00",
    "completed_at": "2026-10-26T07:50:10+00:00"
  }
}
```

### Capture 30 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 9518504f-55b9-498a-a3bd-668a71f7ca20' -H 'Content-Type: application/json' --data '{"message":"Show the offer cost","locale":"en","workspace_id":"01m5vk0a30gq47m00rtddg746q","reference":"01m5vk0a3wr5ry1a0c1jey6313"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-77201541-9ff3-452d-98a7-0013d2593ca0

{
  "data": {
    "id": "01m5vk0dqrmeqcpk37p51dmmsw",
    "conversation_id": "01m5vk0dqp1s1hpdewr39nmkz1",
    "status": "PENDING"
  }
}
```

### Capture 31 — `GET /api/v1/ai/messages/01m5vk0dqrmeqcpk37p51dmmsw`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0dqrmeqcpk37p51dmmsw -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-1045ed58-bc36-43ae-9944-5a3db3a69f87

{
  "data": {
    "id": "01m5vk0dqrmeqcpk37p51dmmsw",
    "conversation_id": "01m5vk0dqp1s1hpdewr39nmkz1",
    "question": "Show the offer cost",
    "status": "VERIFIED",
    "result": {
      "status": "VERIFIED",
      "answer": "Cost preview per voucher in EUR: reward 1000 minor units; platform fee 300 minor units; total 1300 minor units; basis fixed_value. Preview only; nothing has been charged.",
      "evidence_ids": [
        "01m5vk0e545xnzv5r9nkz4wpyz"
      ],
      "checked_at": "2026-10-26T07:50:11+00:00",
      "action": {
        "label": "View details",
        "route": "/app/workspaces/01m5vk0a30gq47m00rtddg746q/offers"
      }
    },
    "retry_used": false,
    "created_at": "2026-10-26T07:50:10+00:00",
    "completed_at": "2026-10-26T07:50:11+00:00"
  }
}
```

### Capture 32 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 8a28ec94-7d5a-42f0-a643-d01bd5297b2a' -H 'Content-Type: application/json' --data '{"message":"Show campaign sales","locale":"en","workspace_id":"01m5vk0a30gq47m00rtddg746q","reference":"01m5vk0a7e004ympqmtxgms6nw"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-a6820ce0-0ab0-4e4f-80f2-6cba8dd416f2

{
  "data": {
    "id": "01m5vk0e9hzrgqbfd55y2zvwjt",
    "conversation_id": "01m5vk0e9fvzrsj0s1rdgmr146",
    "status": "PENDING"
  }
}
```

### Capture 33 — `GET /api/v1/ai/messages/01m5vk0e9hzrgqbfd55y2zvwjt`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0e9hzrgqbfd55y2zvwjt -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-416008e0-68af-494a-a391-20a00881a13d

{
  "data": {
    "id": "01m5vk0e9hzrgqbfd55y2zvwjt",
    "conversation_id": "01m5vk0e9fvzrsj0s1rdgmr146",
    "question": "Show campaign sales",
    "status": "VERIFIED",
    "result": {
      "status": "VERIFIED",
      "answer": "Campaign from 2026-09-27 to 2026-10-26: 10000 viewable impressions; 420 clicks; 0 attributed paid orders. Tracked order starts are unavailable; attribution does not establish causal uplift.",
      "evidence_ids": [
        "01m5vk0eq29pzj1ew18hncb9db"
      ],
      "checked_at": "2026-10-26T07:50:11+00:00",
      "action": {
        "label": "View details",
        "route": "/app/workspaces/01m5vk0a30gq47m00rtddg746q/banners"
      }
    },
    "retry_used": false,
    "created_at": "2026-10-26T07:50:11+00:00",
    "completed_at": "2026-10-26T07:50:11+00:00"
  }
}
```

### Capture 34 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 6a8c29f4-74e5-4841-8a24-2b71b71b2b82' -H 'Content-Type: application/json' --data '{"message":"Show integration health","locale":"en","workspace_id":"01m5vk0a30gq47m00rtddg746q","reference":"01m5vk0a5m6zcz0gpttndkr7c9"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-15d5f5d2-afb5-49e0-bc15-41192cc19e35

{
  "data": {
    "id": "01m5vk0evrk85bhqcfst0jrfa0",
    "conversation_id": "01m5vk0evntk7sr511hv4n8aer",
    "status": "PENDING"
  }
}
```

### Capture 35 — `GET /api/v1/ai/messages/01m5vk0evrk85bhqcfst0jrfa0`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0evrk85bhqcfst0jrfa0 -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-29026571-c189-412f-98e6-213a8df583a6

{
  "data": {
    "id": "01m5vk0evrk85bhqcfst0jrfa0",
    "conversation_id": "01m5vk0evntk7sr511hv4n8aer",
    "question": "Show integration health",
    "status": "VERIFIED",
    "result": {
      "status": "VERIFIED",
      "answer": "Integration woocommerce: testing; last received event: unknown; error none recorded. Capabilities: {\"order_ingestion_available\":true,\"verification\":\"unverified\",\"method\":\"webhook\"}.",
      "evidence_ids": [
        "01m5vk0f9tq4hvz99kjz9yzp33"
      ],
      "checked_at": "2026-10-26T07:50:12+00:00",
      "action": {
        "label": "View details",
        "route": "/app/workspaces/01m5vk0a30gq47m00rtddg746q/integrations"
      }
    },
    "retry_used": false,
    "created_at": "2026-10-26T07:50:12+00:00",
    "completed_at": "2026-10-26T07:50:12+00:00"
  }
}
```

### Capture 36 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 05c3828d-90fb-4a9e-bcc9-ae1d810a901b' -H 'Content-Type: application/json' --data '{"message":"Show webshop wallet","locale":"en","workspace_id":"01m5vk0a30gq47m00rtddg746q"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-914ea6b4-6ccb-435d-820d-fb291590d570

{
  "data": {
    "id": "01m5vk0fegp1sye38s8cc9g7zs",
    "conversation_id": "01m5vk0fee6vzvnef70275q1nr",
    "status": "PENDING"
  }
}
```

### Capture 37 — `GET /api/v1/ai/messages/01m5vk0fegp1sye38s8cc9g7zs`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0fegp1sye38s8cc9g7zs -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-2146fd17-faac-4909-b8f8-653c136ea7bc

{
  "data": {
    "id": "01m5vk0fegp1sye38s8cc9g7zs",
    "conversation_id": "01m5vk0fee6vzvnef70275q1nr",
    "question": "Show webshop wallet",
    "status": "UNAVAILABLE",
    "result": {
      "status": "UNAVAILABLE",
      "answer": null,
      "evidence_ids": [],
      "checked_at": "2026-10-26T07:50:13+00:00",
      "action": null
    },
    "retry_used": false,
    "created_at": "2026-10-26T07:50:12+00:00",
    "completed_at": "2026-10-26T07:50:13+00:00"
  }
}
```

### Capture 38 — `POST /api/v1/consumer/checkouts/quote`

```bash
curl -i -X POST http://localhost:8079/api/v1/consumer/checkouts/quote -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Content-Type: application/json' --data '{"offer":"capture-wallet-voucher-y2m0hf"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-73d12788-1d4f-4555-ab42-37e5cb7c2b1f

{
  "data": {
    "price_minor": 2000,
    "currency": "EUR",
    "wallet_available": 1000,
    "wallet_part_minor": 1000,
    "payment_part_minor": 1000,
    "blockers": []
  }
}
```

### Capture 39 — `GET /api/v1/workspaces/01m5vk0a30gq47m00rtddg746q/banners/01m5vk0a7e004ympqmtxgms6nw/results`

```bash
curl -i -X GET http://localhost:8079/api/v1/workspaces/01m5vk0a30gq47m00rtddg746q/banners/01m5vk0a7e004ympqmtxgms6nw/results -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-fcff08f1-21e9-47f9-ad93-0a1c0fa25331

{
  "data": {
    "from": "2026-09-27",
    "to": "2026-10-26",
    "device": null,
    "timezone": "Europe/Amsterdam",
    "eligible": 10000,
    "viewable_impressions": 10000,
    "cta_clicks": 420,
    "dismissals": 0,
    "ctr": 0.042,
    "attributed_orders": 0,
    "attributed_revenue_minor": 0,
    "definitions": {
      "viewable_impression": "≥50% visible for ≥1 second, deduplicated by the embed",
      "ctr": "CTA clicks / viewable impressions; closing (X) is a dismissal, never a click",
      "attributed_orders": "Paid orders carrying a consented click ID of this banner, within 30 days of the click; all other orders are unattributed"
    }
  }
}
```

### Capture 40 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 7fd48be2-e22c-4cd1-9064-87be158eec19' -H 'Content-Type: application/json' --data '{"message":"wallet","locale":"en"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-b0490613-75b0-4a5c-acb6-01fcec6c47bb

{
  "data": {
    "id": "01m5vk0g2caanpv98m436vydmk",
    "conversation_id": "01m5vk0g2azenx0e7s6d5k8s68",
    "status": "PENDING"
  }
}
```

### Capture 41 — `GET /api/v1/ai/messages/01m5vk0g2caanpv98m436vydmk`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0g2caanpv98m436vydmk -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-43807c04-0d7f-475e-a9d1-05dd836eae75

{
  "data": {
    "id": "01m5vk0g2caanpv98m436vydmk",
    "conversation_id": "01m5vk0g2azenx0e7s6d5k8s68",
    "question": "wallet",
    "status": "UNAVAILABLE",
    "result": {
      "status": "UNAVAILABLE",
      "answer": null,
      "evidence_ids": [],
      "checked_at": "2026-10-26T07:50:13+00:00",
      "action": null
    },
    "retry_used": false,
    "created_at": "2026-10-26T07:50:13+00:00",
    "completed_at": "2026-10-26T07:50:13+00:00"
  }
}
```

### Capture 42 — `DELETE /api/v1/ai/conversations/01m5vk0bmbrmc7dbq482bw9fvm`

```bash
curl -i -X DELETE http://localhost:8079/api/v1/ai/conversations/01m5vk0bmbrmc7dbq482bw9fvm -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 204
X-Request-Id: capture-b17-e3b85490-285a-4da3-a476-73fba5d76fb2


```

### Capture 43 — `GET /api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf`

```bash
curl -i -X GET http://localhost:8079/api/v1/ai/messages/01m5vk0bmew1h6k705kgns5rmf -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>'
```

```http
HTTP/1.1 404
X-Request-Id: capture-b17-e07d1992-dae3-4b05-a579-ca50592d9af2
Content-Type: application/json

{
  "error": {
    "status": 404,
    "code": "not_found",
    "message": "Support message not found.",
    "request_id": "capture-b17-e07d1992-dae3-4b05-a579-ca50592d9af2"
  }
}
```

### Capture 44 — `GET /api/v1/admin/ai/operations`

```bash
curl -i -X GET http://localhost:8079/api/v1/admin/ai/operations -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-164aa982-b35f-4db4-86ed-ea2ad69508f7

{
  "data": {
    "from": "2026-10-25T07:50:13+00:00",
    "checked_at": "2026-10-26T07:50:13+00:00",
    "sample_limit": 500,
    "sample_size": 7,
    "statuses": {
      "UNAVAILABLE": 2,
      "VERIFIED": 5
    },
    "failures": {
      "provider_unavailable": 1,
      "support_access_denied": 1
    },
    "retrieval_retries": 1,
    "latency_p95_ms": 82,
    "input_tokens": 100,
    "output_tokens": 50,
    "provider_cost": null,
    "cost_notice": "Token usage is measured; billed provider cost is not available here.",
    "open_reviews": 2,
    "approved_regressions": 0,
    "daily_requests": 7,
    "circuit_open": false,
    "broadcaster": null,
    "last_successful_broadcast_at": null,
    "broadcast_failures_last_24h": 0,
    "policy_version": "01m5vk0b8knw7zyfk8tkhjyjr1"
  }
}
```

### Capture 45 — `GET /api/v1/admin/ai/operations?fixture_fault=1`

```bash
curl -i -X GET 'http://localhost:8079/api/v1/admin/ai/operations?fixture_fault=1' -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>'
```

```http
HTTP/1.1 500
X-Request-Id: capture-b17-de6bec98-8ce8-446e-ac78-d41a8549ba3a
Content-Type: application/json

{
  "error": {
    "status": 500,
    "code": "server_error",
    "message": "An unexpected error occurred.",
    "request_id": "capture-b17-de6bec98-8ce8-446e-ac78-d41a8549ba3a"
  }
}
```

### Capture 46 — `POST /api/v1/admin/ai/settings/01m5vk0b8knw7zyfk8tkhjyjr1/test`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/settings/01m5vk0b8knw7zyfk8tkhjyjr1/test -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 419
X-Request-Id: capture-b17-e0961a5f-3b1f-4f93-be7e-d5415f36a101
Content-Type: application/json

{
  "error": {
    "status": 419,
    "code": "csrf_token_mismatch",
    "message": "The session or CSRF token has expired. Refresh and try again.",
    "request_id": "capture-b17-e0961a5f-3b1f-4f93-be7e-d5415f36a101"
  }
}
```

### Capture 47 — `POST /api/v1/admin/platform-settings/01m5vk0b8knw7zyfk8tkhjyjr1/rollback`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5vk0b8knw7zyfk8tkhjyjr1/rollback -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"reason":"Restore reviewed historical support policy"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: capture-b17-e9285f10-47a8-4e7f-a485-3d5e8c1dc7a3

{
  "data": {
    "id": "01m5vk0gtxv679w4j4h1bpzp3g",
    "key": "support.ai",
    "version": 2,
    "value": {
      "enabled": true,
      "model": null,
      "languages": [
        "en",
        "nl"
      ],
      "daily_request_limit": 100,
      "timeout_seconds": 20,
      "max_retry": 1,
      "restrictions": []
    },
    "checksum": "1a913d84164719e1a6b17707aab995471b7bfdb9b65c44fbfe15b2003b5b7ac1",
    "status": "draft",
    "reason": "Restore reviewed historical support policy",
    "created_by": "01m5vk0a2bkpv948gbxczptnvf",
    "reviewed_by": null,
    "review_note": null,
    "published_at": null,
    "is_current": false,
    "rollback_of": "01m5vk0b8knw7zyfk8tkhjyjr1"
  }
}
```

### Capture 48 — `POST /api/v1/admin/ai/settings/01m5vk0gtxv679w4j4h1bpzp3g/test`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/settings/01m5vk0gtxv679w4j4h1bpzp3g/test -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-995b3343-e8e9-43cb-87f5-068e611d2b03

{
  "data": {
    "total": 100,
    "passed": 100,
    "failed": 0,
    "policy_checksum": "1a913d84164719e1a6b17707aab995471b7bfdb9b65c44fbfe15b2003b5b7ac1",
    "scope": "server_intent_and_immutable_safety_guards",
    "provider_verified": false
  }
}
```

### Capture 49 — `POST /api/v1/admin/platform-settings/01m5vk0gtxv679w4j4h1bpzp3g/review`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5vk0gtxv679w4j4h1bpzp3g/review -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-82a996d1-4db5-4f17-92bf-6f48330ae3fd

{
  "data": {
    "id": "01m5vk0gtxv679w4j4h1bpzp3g",
    "key": "support.ai",
    "version": 2,
    "value": {
      "enabled": true,
      "model": null,
      "languages": [
        "en",
        "nl"
      ],
      "daily_request_limit": 100,
      "timeout_seconds": 20,
      "max_retry": 1,
      "restrictions": []
    },
    "checksum": "1a913d84164719e1a6b17707aab995471b7bfdb9b65c44fbfe15b2003b5b7ac1",
    "status": "review",
    "reason": "Restore reviewed historical support policy",
    "created_by": "01m5vk0a2bkpv948gbxczptnvf",
    "reviewed_by": null,
    "review_note": null,
    "published_at": null,
    "is_current": false,
    "rollback_of": "01m5vk0b8knw7zyfk8tkhjyjr1"
  }
}
```

### Capture 50 — `POST /api/v1/admin/platform-settings/01m5vk0gtxv679w4j4h1bpzp3g/publish`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5vk0gtxv679w4j4h1bpzp3g/publish -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"note":"Independent approval of rollback draft"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-7b1bb71e-1872-4593-822b-ed7e5593b605

{
  "data": {
    "id": "01m5vk0gtxv679w4j4h1bpzp3g",
    "key": "support.ai",
    "version": 2,
    "value": {
      "enabled": true,
      "model": null,
      "languages": [
        "en",
        "nl"
      ],
      "daily_request_limit": 100,
      "timeout_seconds": 20,
      "max_retry": 1,
      "restrictions": []
    },
    "checksum": "1a913d84164719e1a6b17707aab995471b7bfdb9b65c44fbfe15b2003b5b7ac1",
    "status": "published",
    "reason": "Restore reviewed historical support policy",
    "created_by": "01m5vk0a2bkpv948gbxczptnvf",
    "reviewed_by": "01m5vk0a27asz682vehdtfk917",
    "review_note": "Independent approval of rollback draft",
    "published_at": "2026-10-26T07:50:14+00:00",
    "is_current": true,
    "rollback_of": "01m5vk0b8knw7zyfk8tkhjyjr1"
  }
}
```

### Capture 51 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 0df0b60b-73c1-47b1-ac29-fda1773ca64c' -H 'Content-Type: application/json' --data '{"message":"wallet"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-43d19f52-289d-4e65-953e-d4044eb9c602

{
  "data": {
    "id": "01m5vk0h1ggkhvczw7a96h0q62",
    "conversation_id": "01m5vk0h1dbgpdk1s8jw8b4q6g",
    "status": "PENDING"
  }
}
```

### Capture 52 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Idempotency-Key: 2f7401f9-76c1-41fb-be51-173bd9896e83' -H 'Content-Type: application/json' --data '{"message":"wallet"}'
```

```http
HTTP/1.1 429
Retry-After: 54
X-Request-Id: capture-b17-3327442e-e60f-44c8-bc3f-fd3cac70e6cb
Content-Type: application/json

{
  "error": {
    "status": 429,
    "code": "too_many_requests",
    "message": "Too many requests. Please slow down.",
    "request_id": "capture-b17-3327442e-e60f-44c8-bc3f-fd3cac70e6cb"
  }
}
```

The500 operations capture uses a fixture-only injected exception in the temporary local router; the real application renderer produced the HTTP response. This injection is not shipped. The419 request intentionally omitted the CSRF header. The429 response came from the actual10/minute support limiter. Seeded OTP/authentication communication is not live email-delivery evidence.

## B17 final knowledge history and approved regression execution — 2026-10-26

These additional15 captures ran against a real local Laravel HTTP server8079 with isolated synthetic SQLite fixtures and actual browser login/password/seeded OTP verification. No external model request was made; support was disabled. Existing FAQ create/update now preserve immutable snapshots; rollback creates a new revision and verifies the expected current revision/checksum. `approved_at` is an actual recorded admin publication timestamp when known; backfilled legacy sources return null. Human-approved regression cases execute in future policy tests; this deliberately mismatched synthetic human expectation yields101total/1failure and blocks publication, without training/changing production. The earlier52 captures remain real historical examples from this implementation session.

### Knowledge capture 1 — `POST /api/v1/admin/cms/faq/categories`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/cms/faq/categories -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"key":"support-history","names":{"en":"Support","nl":"Hulp"}}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-c74ce12d-2196-42fe-90ce-fe74147c5c1d

{
  "data": {
    "id": "01m5wt1y8v8f43d09bjh795mk8",
    "key": "support-history",
    "names": {
      "en": "Support",
      "nl": "Hulp"
    },
    "position": 1
  }
}
```

### Knowledge capture 2 — `POST /api/v1/admin/cms/faq/items`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/cms/faq/items -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"category_id":"01m5wt1y8v8f43d09bjh795mk8","audience":"consumers","published":true,"translations":{"en":{"question":"How does credit work?","answer":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Credit cannot be withdrawn or transferred."}]}]}},"nl":{"question":"Hoe werkt tegoed?","answer":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Tegoed kan niet worden opgenomen of overgedragen."}]}]}}}}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-9b5a8e0c-98e9-4dc6-892b-7839b5385812

{
  "data": {
    "id": "01m5wt1y9yendawfrtec572emw",
    "audience": "consumers",
    "published": true,
    "position": 1,
    "revision": 1,
    "translations": {
      "en": {
        "question": "How does credit work?",
        "answer": {
          "type": "doc",
          "content": [
            {
              "type": "paragraph",
              "content": [
                {
                  "type": "text",
                  "text": "Credit cannot be withdrawn or transferred."
                }
              ]
            }
          ]
        }
      },
      "nl": {
        "question": "Hoe werkt tegoed?",
        "answer": {
          "type": "doc",
          "content": [
            {
              "type": "paragraph",
              "content": [
                {
                  "type": "text",
                  "text": "Tegoed kan niet worden opgenomen of overgedragen."
                }
              ]
            }
          ]
        }
      }
    }
  }
}
```

### Knowledge capture 3 — `GET /api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/versions`

```bash
curl -i -X GET http://localhost:8079/api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/versions -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-5149b847-c313-488d-8008-f677c09bec73

{
  "data": [
    {
      "id": "01m5wt1ya2ggrn14fk4edasvrg",
      "revision": 1,
      "snapshot": {
        "audience": "consumers",
        "published": true,
        "translations": {
          "en": {
            "question": "How does credit work?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Credit cannot be withdrawn or transferred."
                    }
                  ]
                }
              ]
            }
          },
          "nl": {
            "question": "Hoe werkt tegoed?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Tegoed kan niet worden opgenomen of overgedragen."
                    }
                  ]
                }
              ]
            }
          }
        }
      },
      "checksum": "b771ad5de3bf7e1150a09df55bdb05486ce24403fd24f4647baf53859d97b4f2",
      "origin": "published",
      "recorded_at": "2026-10-26T19:12:35+00:00"
    }
  ]
}
```

### Knowledge capture 4 — `PUT /api/v1/admin/cms/faq/items/01m5wt1y9yendawfrtec572emw`

```bash
curl -i -X PUT http://localhost:8079/api/v1/admin/cms/faq/items/01m5wt1y9yendawfrtec572emw -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"revision":1,"translations":{"en":{"question":"How does credit work?","answer":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Credit is reserved for voucher redemption and cannot be withdrawn."}]}]}},"nl":{"question":"Hoe werkt tegoed?","answer":{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Tegoed is bedoeld voor vouchers en kan niet worden opgenomen."}]}]}}}}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-7109002e-e8f7-47e6-aeed-d17103b82034

{
  "data": {
    "id": "01m5wt1y9yendawfrtec572emw",
    "audience": "consumers",
    "published": true,
    "position": 1,
    "revision": 2,
    "translations": {
      "en": {
        "question": "How does credit work?",
        "answer": {
          "type": "doc",
          "content": [
            {
              "type": "paragraph",
              "content": [
                {
                  "type": "text",
                  "text": "Credit is reserved for voucher redemption and cannot be withdrawn."
                }
              ]
            }
          ]
        }
      },
      "nl": {
        "question": "Hoe werkt tegoed?",
        "answer": {
          "type": "doc",
          "content": [
            {
              "type": "paragraph",
              "content": [
                {
                  "type": "text",
                  "text": "Tegoed is bedoeld voor vouchers en kan niet worden opgenomen."
                }
              ]
            }
          ]
        }
      }
    }
  }
}
```

### Knowledge capture 5 — `GET /api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/versions`

```bash
curl -i -X GET http://localhost:8079/api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/versions -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-0f026155-817d-41f1-873c-edd540afb7b5

{
  "data": [
    {
      "id": "01m5wt1yc1qgt5khyseet53zh6",
      "revision": 2,
      "snapshot": {
        "audience": "consumers",
        "published": true,
        "translations": {
          "en": {
            "question": "How does credit work?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Credit is reserved for voucher redemption and cannot be withdrawn."
                    }
                  ]
                }
              ]
            }
          },
          "nl": {
            "question": "Hoe werkt tegoed?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Tegoed is bedoeld voor vouchers en kan niet worden opgenomen."
                    }
                  ]
                }
              ]
            }
          }
        }
      },
      "checksum": "280115826f45ec18b9ddc8f66a34078bced0094dd438a16b59a75f794282d65f",
      "origin": "published",
      "recorded_at": "2026-10-26T19:12:35+00:00"
    },
    {
      "id": "01m5wt1ya2ggrn14fk4edasvrg",
      "revision": 1,
      "snapshot": {
        "audience": "consumers",
        "published": true,
        "translations": {
          "en": {
            "question": "How does credit work?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Credit cannot be withdrawn or transferred."
                    }
                  ]
                }
              ]
            }
          },
          "nl": {
            "question": "Hoe werkt tegoed?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Tegoed kan niet worden opgenomen of overgedragen."
                    }
                  ]
                }
              ]
            }
          }
        }
      },
      "checksum": "b771ad5de3bf7e1150a09df55bdb05486ce24403fd24f4647baf53859d97b4f2",
      "origin": "published",
      "recorded_at": "2026-10-26T19:12:35+00:00"
    }
  ]
}
```

### Knowledge capture 6 — `POST /api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/rollback`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/rollback -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"version_id":"01m5wt1ya2ggrn14fk4edasvrg","revision":1,"reason":"Reject stale expected revision"}'
```

```http
HTTP/1.1 409
X-Request-Id: capture-b17-knowledge-3ecea7ea-121c-4ef3-af8f-849cf90f9f49
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "draft_revision_conflict",
    "message": "Someone else changed this item. Reload to continue.",
    "request_id": "capture-b17-knowledge-3ecea7ea-121c-4ef3-af8f-849cf90f9f49"
  }
}
```

### Knowledge capture 7 — `POST /api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/rollback`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/rollback -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"version_id":"01m5wt1ya2ggrn14fk4edasvrg","revision":2,"reason":"Restore previously published approved explanation"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-7f85f5e4-f501-49ef-b611-5eadb034ab71

{
  "data": {
    "document_id": "01m5wt1y9yendawfrtec572emw",
    "revision": 3,
    "published": true,
    "audience": "consumers"
  }
}
```

### Knowledge capture 8 — `GET /api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/versions`

```bash
curl -i -X GET http://localhost:8079/api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/versions -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-13dc668a-e6d9-4f08-aeb0-edf063e5c23a

{
  "data": [
    {
      "id": "01m5wt1yexwtbkv23gekz4s7hg",
      "revision": 3,
      "snapshot": {
        "audience": "consumers",
        "published": true,
        "translations": {
          "en": {
            "question": "How does credit work?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Credit cannot be withdrawn or transferred."
                    }
                  ]
                }
              ]
            }
          },
          "nl": {
            "question": "Hoe werkt tegoed?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Tegoed kan niet worden opgenomen of overgedragen."
                    }
                  ]
                }
              ]
            }
          }
        }
      },
      "checksum": "b771ad5de3bf7e1150a09df55bdb05486ce24403fd24f4647baf53859d97b4f2",
      "origin": "rollback",
      "recorded_at": "2026-10-26T19:12:35+00:00"
    },
    {
      "id": "01m5wt1yc1qgt5khyseet53zh6",
      "revision": 2,
      "snapshot": {
        "audience": "consumers",
        "published": true,
        "translations": {
          "en": {
            "question": "How does credit work?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Credit is reserved for voucher redemption and cannot be withdrawn."
                    }
                  ]
                }
              ]
            }
          },
          "nl": {
            "question": "Hoe werkt tegoed?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Tegoed is bedoeld voor vouchers en kan niet worden opgenomen."
                    }
                  ]
                }
              ]
            }
          }
        }
      },
      "checksum": "280115826f45ec18b9ddc8f66a34078bced0094dd438a16b59a75f794282d65f",
      "origin": "published",
      "recorded_at": "2026-10-26T19:12:35+00:00"
    },
    {
      "id": "01m5wt1ya2ggrn14fk4edasvrg",
      "revision": 1,
      "snapshot": {
        "audience": "consumers",
        "published": true,
        "translations": {
          "en": {
            "question": "How does credit work?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Credit cannot be withdrawn or transferred."
                    }
                  ]
                }
              ]
            }
          },
          "nl": {
            "question": "Hoe werkt tegoed?",
            "answer": {
              "type": "doc",
              "content": [
                {
                  "type": "paragraph",
                  "content": [
                    {
                      "type": "text",
                      "text": "Tegoed kan niet worden opgenomen of overgedragen."
                    }
                  ]
                }
              ]
            }
          }
        }
      },
      "checksum": "b771ad5de3bf7e1150a09df55bdb05486ce24403fd24f4647baf53859d97b4f2",
      "origin": "published",
      "recorded_at": "2026-10-26T19:12:35+00:00"
    }
  ]
}
```

### Knowledge capture 9 — `POST /api/v1/ai/knowledge/search`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/knowledge/search -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"query":"credit","locale":"en"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-7cd9ae51-ecb4-428c-bcb7-58fff13c77e2

{
  "data": [
    {
      "document_id": "01m5wt1y9yendawfrtec572emw",
      "scope": "consumers",
      "version": 3,
      "approved_at": "2026-10-26T19:12:35+00:00",
      "locale": "en",
      "content": "Credit cannot be withdrawn or transferred.",
      "checked_at": "2026-10-26T19:12:35+00:00"
    }
  ]
}
```

### Knowledge capture 10 — `POST /api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/rollback`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/knowledge/01m5wt1y9yendawfrtec572emw/rollback -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"version_id":"01m5wt1ya2ggrn14fk4edasvrg","revision":3,"reason":"Sensitive rollback requires OTP"}'
```

```http
HTTP/1.1 423
X-Request-Id: capture-b17-knowledge-7717d1e2-bbeb-4b04-ba5a-915543e80de8
Content-Type: application/json

{
  "error": {
    "status": 423,
    "code": "step_up_code_required",
    "message": "Confirm this action with the code sent to your email.",
    "request_id": "capture-b17-knowledge-7717d1e2-bbeb-4b04-ba5a-915543e80de8"
  }
}
```

### Knowledge capture 11 — `POST /api/v1/ai/chat`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/chat -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Idempotency-Key: af340a5b-9ba3-4444-ab50-06149299cc21' -H 'Content-Type: application/json' --data '{"message":"wallet"}'
```

```http
HTTP/1.1 202
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-66f7b707-414b-443c-bc45-0359259b3e82

{
  "data": {
    "id": "01m5wt1yp2nmr2y1nb9j4kmwh1",
    "conversation_id": "01m5wt1yp0t47wz72eevakxpyy",
    "status": "UNAVAILABLE"
  }
}
```

### Knowledge capture 12 — `POST /api/v1/ai/messages/01m5wt1yp2nmr2y1nb9j4kmwh1/escalate`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/messages/01m5wt1yp2nmr2y1nb9j4kmwh1/escalate -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-ed84a2a9-af2f-477b-91e3-e8236bfccba3

{
  "data": {
    "id": "01m5wt1yqxnp04mtfcr88x79xm",
    "status": "ESCALATED",
    "notice": "A human support review has been requested."
  }
}
```

### Knowledge capture 13 — `POST /api/v1/admin/ai/reviews/01m5wt1yqxnp04mtfcr88x79xm/resolve`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/reviews/01m5wt1yqxnp04mtfcr88x79xm/resolve -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"outcome":"CONFIRMED_ERROR","note":"Human correction must participate in policy regressions","expected_intent":"reward","knowledge_id":"01m5wt1y9yendawfrtec572emw"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-a09013f3-b77b-4eb2-88cc-f92635ee8753

{
  "data": {
    "id": "01m5wt1yqxnp04mtfcr88x79xm",
    "outcome": "CONFIRMED_ERROR",
    "regression_added": true
  }
}
```

### Knowledge capture 14 — `POST /api/v1/admin/platform-settings`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"key":"support.ai","value":{"enabled":false,"model":null,"languages":["en","nl"],"daily_request_limit":10,"timeout_seconds":10,"max_retry":1,"restrictions":[]},"reason":"Run the approved human regression"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-12961100-92e7-4b73-a477-0931ae706a41

{
  "data": {
    "id": "01m5wt1yt3h3d18wvxb05mb3be",
    "key": "support.ai",
    "version": 1,
    "value": {
      "enabled": false,
      "model": null,
      "languages": [
        "en",
        "nl"
      ],
      "daily_request_limit": 10,
      "timeout_seconds": 10,
      "max_retry": 1,
      "restrictions": []
    },
    "checksum": "bc4a4fac3af7907173cdc3e4e1598f29e1de446acd2d0b33b0d2ace6bdcd6c67",
    "status": "draft",
    "reason": "Run the approved human regression",
    "created_by": "01m5wt1xgqgnc9e6sp8nw9anfk",
    "reviewed_by": null,
    "review_note": null,
    "published_at": null,
    "is_current": false,
    "rollback_of": null
  }
}
```

### Knowledge capture 15 — `POST /api/v1/admin/ai/settings/01m5wt1yt3h3d18wvxb05mb3be/test`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/settings/01m5wt1yt3h3d18wvxb05mb3be/test -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: capture-b17-knowledge-c1a26fc1-07e8-495b-908c-b88e0ffa2199

{
  "data": {
    "total": 101,
    "passed": 100,
    "failed": 1,
    "policy_checksum": "bc4a4fac3af7907173cdc3e4e1598f29e1de446acd2d0b33b0d2ace6bdcd6c67",
    "scope": "server_intent_and_immutable_safety_guards",
    "provider_verified": false
  }
}
```

## B17 final domain-conflict captures — 2026-10-26

Eight further real local HTTP requests exercised active-conversation deletion, immutable votes/reviews, non-draft policy testing, expired human replies and oversized approved knowledge. Synthetic isolated SQLite records; actual browser login/password/seeded OTP verification; no external provider call. These cover actual operation-specific409 codes rather than fabricated error examples.

### Domain capture 1 — `DELETE /api/v1/ai/conversations/01m5wth2r1h9pszbzz6pcghyg5`

```bash
curl -i -X DELETE http://localhost:8079/api/v1/ai/conversations/01m5wth2r1h9pszbzz6pcghyg5 -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>'
```

```http
HTTP/1.1 409
X-Request-Id: 01a179a8-8e8f-709d-a53d-c924336f8b78
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "support_conversation_busy",
    "message": "Wait for the current support answer.",
    "request_id": "01a179a8-8e8f-709d-a53d-c924336f8b78"
  }
}
```

### Domain capture 2 — `POST /api/v1/ai/messages/01m5wth2qzqyf1s1qdm3bn3hxe/feedback`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/messages/01m5wth2qzqyf1s1qdm3bn3hxe/feedback -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"rating":"up"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a179a8-8ebb-7160-9952-346e0e3414ff

{
  "data": {
    "id": "01m5wth3pbsnjazqexpb6hwvbj",
    "rating": "up",
    "review_required": false
  }
}
```

### Domain capture 3 — `POST /api/v1/ai/messages/01m5wth2qzqyf1s1qdm3bn3hxe/feedback`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/messages/01m5wth2qzqyf1s1qdm3bn3hxe/feedback -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"rating":"down","reason":"unclear"}'
```

```http
HTTP/1.1 409
X-Request-Id: 01a179a8-8eeb-7175-88de-e58e2dae92a9
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "support_feedback_conflict",
    "message": "Feedback is already recorded.",
    "request_id": "01a179a8-8eeb-7175-88de-e58e2dae92a9"
  }
}
```

### Domain capture 4 — `POST /api/v1/admin/ai/reviews/01m5wth2r8055dea4t9m45c7fy/resolve`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/reviews/01m5wth2r8055dea4t9m45c7fy/resolve -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"outcome":"ANSWER_CORRECT","note":"Reviewed synthetic unavailable answer"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: 01a179a8-8f08-7150-ab77-52ec249339f0

{
  "data": {
    "id": "01m5wth2r8055dea4t9m45c7fy",
    "outcome": "ANSWER_CORRECT",
    "regression_added": false
  }
}
```

### Domain capture 5 — `POST /api/v1/admin/ai/reviews/01m5wth2r8055dea4t9m45c7fy/resolve`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/reviews/01m5wth2r8055dea4t9m45c7fy/resolve -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"outcome":"ANSWER_CORRECT","note":"Cannot overwrite resolved history"}'
```

```http
HTTP/1.1 409
X-Request-Id: 01a179a8-8f34-71b8-9030-5ac4f1fa3f19
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "support_review_resolved",
    "message": "This support review is resolved.",
    "request_id": "01a179a8-8f34-71b8-9030-5ac4f1fa3f19"
  }
}
```

### Domain capture 6 — `POST /api/v1/admin/ai/settings/01m5wth2sc45z8v7dfnt277rxy/test`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/settings/01m5wth2sc45z8v7dfnt277rxy/test -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 409
X-Request-Id: 01a179a8-8f50-7096-98e1-7ea0aa5c2a2b
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "support_policy_not_draft",
    "message": "Only a support policy draft can be tested.",
    "request_id": "01a179a8-8f50-7096-98e1-7ea0aa5c2a2b"
  }
}
```

### Domain capture 7 — `POST /api/v1/admin/ai/conversations/01m5wth2r5yhbw20cc80e7ah1h/reply`

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/ai/conversations/01m5wth2r5yhbw20cc80e7ah1h/reply -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"body":"Expired conversation cannot receive a reply."}'
```

```http
HTTP/1.1 409
X-Request-Id: 01a179a8-8f6d-72a4-b11b-4f075040e12b
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "support_conversation_expired",
    "message": "This support conversation has expired.",
    "request_id": "01a179a8-8f6d-72a4-b11b-4f075040e12b"
  }
}
```

### Domain capture 8 — `POST /api/v1/ai/knowledge/search`

```bash
curl -i -X POST http://localhost:8079/api/v1/ai/knowledge/search -H 'Accept: application/json' -H 'Cookie: <SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"query":"oversized","locale":"en"}'
```

```http
HTTP/1.1 409
X-Request-Id: 01a179a8-8f88-70eb-a84e-be809780a556
Content-Type: application/json

{
  "error": {
    "status": 409,
    "code": "support_evidence_unavailable",
    "message": "Support evidence is unavailable.",
    "request_id": "01a179a8-8f88-70eb-a84e-be809780a556"
  }
}
```


## #29 central notifications — B20a support-reply inbox

2026-10-26 local actual Laravel HTTP server, isolated SQLite/database sessions/queue; main base d2fb5ed plus B20a working tree; synthetic users/reply and local Reverb auth configuration; actual owner200/other403/support403 channel authorization, no live mail/model/deployed WebSocket claim. All examples below were captured via actual HTTP. Email delivery, the remaining event catalogue, reminders and AI templates are not implemented in this slice. [Client contract and limits](../notifications/NOTIFICATIONS.md).

### `GET /api/v1/notifications?filter=unread&page=1&per_page=20` — inbox

```bash
curl -i -X GET 'http://localhost:8079/api/v1/notifications?filter=unread&page=1&per_page=20' -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-inbox' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 99
X-Request-Id: b20-capture-inbox

{"data":[{"id":"d87ef802-14ee-41ab-b06e-b1c49992df63","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Er staat een antwoord voor je klaar in je supportgesprek.","locale":"nl","action_label":"Bekijk gesprek","action_route":"/app/support?conversation=01m5wzcmpx0wspgkpzxxazqc8r","template_version":"support-reply-v1","delivery_channels":{"database":"delivered","email":"not_implemented"},"read_at":null,"created_at":"2026-10-26T20:45:48+00:00"},{"id":"c615ff79-9256-4ec5-bce9-f5ae9b83776c","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Er staat een antwoord voor je klaar in je supportgesprek.","locale":"nl","action_label":"Bekijk gesprek","action_route":"/app/support?conversation=01m5wzcmpx0wspgkpzxxazqc8r","template_version":"support-reply-v1","delivery_channels":{"database":"delivered","email":"not_implemented"},"read_at":null,"created_at":"2026-10-26T20:45:48+00:00"}],"meta":{"unread_count":2,"current_page":1,"per_page":20,"total":2}}
```

### `GET /api/v1/notification-preferences` — preferences

```bash
curl -i -X GET http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-preferences' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 98
X-Request-Id: b20-capture-preferences

{"data":{"locale":"nl","email_enabled":true,"preferences":{"support_reply":{"in_app":true,"email":false},"workspace_support_reply":{"in_app":true,"email":false}},"email_delivery_available":false}}
```

### `PUT /api/v1/notification-preferences` — update-preferences

```bash
curl -i -X PUT http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-update-preferences' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{"locale":"en","email_enabled":false,"preferences":{"support_reply":{"email":false,"in_app":true}}}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 97
X-Request-Id: b20-capture-update-preferences

{"data":{"locale":"en","email_enabled":false,"preferences":{"support_reply":{"in_app":true,"email":false},"workspace_support_reply":{"in_app":true,"email":false}},"email_delivery_available":false}}
```

### `PATCH /api/v1/notifications/d87ef802-14ee-41ab-b06e-b1c49992df63/read` — mark-read

```bash
curl -i -X PATCH http://localhost:8079/api/v1/notifications/d87ef802-14ee-41ab-b06e-b1c49992df63/read -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-mark-read' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 96
X-Request-Id: b20-capture-mark-read

{"data":{"id":"d87ef802-14ee-41ab-b06e-b1c49992df63","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Er staat een antwoord voor je klaar in je supportgesprek.","locale":"nl","action_label":"Bekijk gesprek","action_route":"/app/support?conversation=01m5wzcmpx0wspgkpzxxazqc8r","template_version":"support-reply-v1","delivery_channels":{"database":"delivered","email":"not_implemented"},"read_at":"2026-10-26T20:45:50+00:00","created_at":"2026-10-26T20:45:48+00:00"}}
```

### `PATCH /api/v1/notifications/d87ef802-14ee-41ab-b06e-b1c49992df63/read` — mark-read-idempotent

```bash
curl -i -X PATCH http://localhost:8079/api/v1/notifications/d87ef802-14ee-41ab-b06e-b1c49992df63/read -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-mark-read-idempotent' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-Request-Id: b20-capture-mark-read-idempotent

{"data":{"id":"d87ef802-14ee-41ab-b06e-b1c49992df63","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Er staat een antwoord voor je klaar in je supportgesprek.","locale":"nl","action_label":"Bekijk gesprek","action_route":"/app/support?conversation=01m5wzcmpx0wspgkpzxxazqc8r","template_version":"support-reply-v1","delivery_channels":{"database":"delivered","email":"not_implemented"},"read_at":"2026-10-26T20:45:50+00:00","created_at":"2026-10-26T20:45:48+00:00"}}
```

### `POST /api/v1/notifications/read-all` — read-all

```bash
curl -i -X POST http://localhost:8079/api/v1/notifications/read-all -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-read-all' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 94
X-Request-Id: b20-capture-read-all

{"data":{"marked_read":1}}
```

### `GET /api/v1/notifications` — reconnect

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-reconnect' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 93
X-Request-Id: b20-capture-reconnect

{"data":[{"id":"d87ef802-14ee-41ab-b06e-b1c49992df63","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Er staat een antwoord voor je klaar in je supportgesprek.","locale":"nl","action_label":"Bekijk gesprek","action_route":"/app/support?conversation=01m5wzcmpx0wspgkpzxxazqc8r","template_version":"support-reply-v1","delivery_channels":{"database":"delivered","email":"not_implemented"},"read_at":"2026-10-26T20:45:50+00:00","created_at":"2026-10-26T20:45:48+00:00"},{"id":"c615ff79-9256-4ec5-bce9-f5ae9b83776c","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Er staat een antwoord voor je klaar in je supportgesprek.","locale":"nl","action_label":"Bekijk gesprek","action_route":"/app/support?conversation=01m5wzcmpx0wspgkpzxxazqc8r","template_version":"support-reply-v1","delivery_channels":{"database":"delivered","email":"not_implemented"},"read_at":"2026-10-26T20:45:50+00:00","created_at":"2026-10-26T20:45:48+00:00"}],"meta":{"unread_count":0,"current_page":1,"per_page":20,"total":2}}
```

### `PATCH /api/v1/notifications/d87ef802-14ee-41ab-b06e-b1c49992df63/read` — foreign-notification

```bash
curl -i -X PATCH http://localhost:8079/api/v1/notifications/d87ef802-14ee-41ab-b06e-b1c49992df63/read -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-foreign-notification' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 404
X-Request-Id: b20-capture-foreign-notification
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 99

{"error":{"status":404,"code":"not_found","message":"Notification not found.","request_id":"b20-capture-foreign-notification"}}
```

### `GET /api/v1/notifications?filter=invalid` — invalid-filter

```bash
curl -i -X GET 'http://localhost:8079/api/v1/notifications?filter=invalid' -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-invalid-filter' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 422
X-Request-Id: b20-capture-invalid-filter
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 92

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"b20-capture-invalid-filter","fields":{"filter":["The selected filter is invalid."]}}}
```

### `PUT /api/v1/notification-preferences` — mandatory-in-app

```bash
curl -i -X PUT http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-mandatory-in-app' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{"preferences":{"support_reply":{"in_app":false}}}'
```

```http
HTTP/1.1 422
X-Request-Id: b20-capture-mandatory-in-app
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 91

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"b20-capture-mandatory-in-app","fields":{"preferences.support_reply.in_app":["The preferences.support_reply.in_app field must be accepted."]}}}
```

### `PUT /api/v1/notification-preferences` — unknown-event

```bash
curl -i -X PUT http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-unknown-event' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{"preferences":{"unknown":{"email":true}}}'
```

```http
HTTP/1.1 422
X-Request-Id: b20-capture-unknown-event
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 90

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"b20-capture-unknown-event","fields":{"preferences":["The preferences field must be an array."]}}}
```

### `GET /api/v1/notifications` — unauthorized-inbox

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-unauthorized-inbox'
```

```http
HTTP/1.1 401
X-Request-Id: b20-capture-unauthorized-inbox
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b20-capture-unauthorized-inbox"}}
```

### `PATCH /api/v1/notifications/d87ef802-14ee-41ab-b06e-b1c49992df63/read` — unauthorized-read

```bash
curl -i -X PATCH http://localhost:8079/api/v1/notifications/d87ef802-14ee-41ab-b06e-b1c49992df63/read -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-unauthorized-read' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b20-capture-unauthorized-read
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b20-capture-unauthorized-read"}}
```

### `POST /api/v1/notifications/read-all` — unauthorized-read-all

```bash
curl -i -X POST http://localhost:8079/api/v1/notifications/read-all -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-unauthorized-read-all' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b20-capture-unauthorized-read-all
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b20-capture-unauthorized-read-all"}}
```

### `GET /api/v1/notification-preferences` — unauthorized-preferences

```bash
curl -i -X GET http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-unauthorized-preferences'
```

```http
HTTP/1.1 401
X-Request-Id: b20-capture-unauthorized-preferences
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b20-capture-unauthorized-preferences"}}
```

### `PUT /api/v1/notification-preferences` — unauthorized-update

```bash
curl -i -X PUT http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-unauthorized-update' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b20-capture-unauthorized-update
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b20-capture-unauthorized-update"}}
```

### `GET /api/v1/notifications` — support-denied

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-support-denied' -H 'Authorization: Bearer <SUPPORT_TOKEN>'
```

```http
HTTP/1.1 403
X-Request-Id: b20-capture-support-denied
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 89

{"error":{"status":403,"code":"blocked_during_impersonation","message":"This action is not available during a support session.","request_id":"b20-capture-support-denied"}}
```

### `PUT /api/v1/notification-preferences` — support-preferences-denied

```bash
curl -i -X PUT http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-support-preferences-denied' -H 'Authorization: Bearer <SUPPORT_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b20-capture-support-preferences-denied
Content-Type: application/json
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 88

{"error":{"status":403,"code":"blocked_during_impersonation","message":"This action is not available during a support session.","request_id":"b20-capture-support-preferences-denied"}}
```

### `POST /api/v1/notifications/read-all` — browser-csrf

```bash
curl -i -X POST http://localhost:8079/api/v1/notifications/read-all -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-browser-csrf' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 419
X-Request-Id: b20-capture-browser-csrf
Content-Type: application/json

{"error":{"status":419,"code":"csrf_token_mismatch","message":"The session or CSRF token has expired. Refresh and try again.","request_id":"b20-capture-browser-csrf"}}
```

### `POST /api/broadcasting/auth` — broadcast-owner

Ephemeral channel authorization signature redacted.

```bash
curl -i -X POST http://localhost:8079/api/broadcasting/auth -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-broadcast-owner' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{"channel_name":"private-shopbonus.user.01m5wzcmpay9hef4sb3m4kadbq.notifications","socket_id":"123.456"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-Request-Id: b20-capture-broadcast-owner

{"auth":"<REDACTED_EPHEMERAL_CHANNEL_AUTH>"}
```

### `POST /api/broadcasting/auth` — broadcast-other

```bash
curl -i -X POST http://localhost:8079/api/broadcasting/auth -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-broadcast-other' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{"channel_name":"private-shopbonus.user.01m5wzcmpay9hef4sb3m4kadbq.notifications","socket_id":"123.456"}'
```

```http
HTTP/1.1 403
X-Request-Id: b20-capture-broadcast-other
Content-Type: application/json

{"error":{"status":403,"code":"forbidden","message":"You are not allowed to perform this action.","request_id":"b20-capture-broadcast-other"}}
```

### `POST /api/broadcasting/auth` — broadcast-support

```bash
curl -i -X POST http://localhost:8079/api/broadcasting/auth -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-broadcast-support' -H 'Authorization: Bearer <SUPPORT_TOKEN>' -H 'Content-Type: application/json' --data '{"channel_name":"private-shopbonus.user.01m5wzcmpay9hef4sb3m4kadbq.notifications","socket_id":"123.456"}'
```

```http
HTTP/1.1 403
X-Request-Id: b20-capture-broadcast-support
Content-Type: application/json

{"error":{"status":403,"code":"forbidden","message":"You are not allowed to perform this action.","request_id":"b20-capture-broadcast-support"}}
```

### `GET /api/v1/notifications` — rate-limit

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-rate-limit' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 429
X-RateLimit-Limit: 1
X-RateLimit-Remaining: 0
Retry-After: 57
X-Request-Id: b20-capture-rate-limit
Content-Type: application/json

{"error":{"status":429,"code":"too_many_requests","message":"Too many requests. Please slow down.","request_id":"b20-capture-rate-limit"}}
```

### `GET /api/v1/notifications` — storage-failure

Temporary local router injects a storage-service failure through the actual Laravel kernel; router is not shipped.

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'X-Request-Id: b20-capture-storage-failure' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 500
X-Request-Id: b20-capture-storage-failure
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 987

{"error":{"status":500,"code":"server_error","message":"An unexpected error occurred.","request_id":"b20-capture-storage-failure"}}
```

## #29 remaining notification backend — actual HTTP captures

2026-10-26 actual local Laravel HTTP/SQLite/database sessions and queue; main base deea998 plus #29 implementation. Synthetic users, seeded OTP verification through real sessions. Temporary router simulates strict OpenAI completion/malformed output and one storage failure; not shipped. Actual queue worker uses array mail only; no external customer/provider/deployed Reverb proof. All secrets/cookies/OTP omitted. Six extra requests use a second isolated fixture for updated read/legacy/deletion behavior. See [event/field/configuration handoff](../notifications/NOTIFICATIONS.md). The earlier B20a examples above are historical. These captures reflect the remaining main backend. All provider successes are synthetic; array-mail sent means local transport accepted, not customer delivery.

### `GET /api/v1/platform/notifications/events` — events

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/events -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998
X-Request-Id: b29-events

{"data":[{"event_type":"account_welcome","audience":"consumer","mandatory_in_app":true,"mandatory_email":true,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"email_verified","audience":"consumer","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"cashback_credited","audience":"consumer","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":["amount_formatted"],"locales":["nl","en"],"reminder_days":[]},{"event_type":"voucher_received","audience":"consumer","mandatory_in_app":true,"mandatory_email":true,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"voucher_expiring","audience":"consumer","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[30,7,1]},{"event_type":"voucher_used","audience":"consumer","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"voucher_cancelled","audience":"consumer","mandatory_in_app":true,"mandatory_email":true,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"cashback_adjusted","audience":"consumer","mandatory_in_app":true,"mandatory_email":true,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"support_reply","audience":"consumer","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"favorite_merchant_offer","audience":"consumer","mandatory_in_app":false,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"workspace_approved","audience":"workspace","mandatory_in_app":true,"mandatory_email":true,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"workspace_rejected","audience":"workspace","mandatory_in_app":true,"mandatory_email":true,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"offer_published","audience":"workspace","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"offer_expiring","audience":"workspace","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"commission_changed","audience":"workspace","mandatory_in_app":true,"mandatory_email":true,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"integration_attention","audience":"workspace","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"workspace_support_reply","audience":"workspace","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"workspace_review_required","audience":"admin","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"ai_support_escalated","audience":"admin","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"affiliate_import_failed","audience":"admin","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"notification_delivery_failed","audience":"admin","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"critical_notification_failure","audience":"admin","mandatory_in_app":true,"mandatory_email":true,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]},{"event_type":"merchant_attention","audience":"workspace","mandatory_in_app":true,"mandatory_email":false,"allowed_variables":[],"locales":["nl","en"],"reminder_days":[]}],"failures":[{"id":"01m5x2dxnmrmdstab31stbzggp","event_type":"email_verified","attempts":3,"error_code":"synthetic_inbox_failure"}]}
```

### `GET /api/v1/platform/notifications/deliveries` — deliveries

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/deliveries -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 997
X-Request-Id: b29-deliveries

{"data":[{"id":"01m5x2dxv818ry5cxgw5vnmcbh","notification_id":"45c0e627-f377-4595-bdf9-1c6a98745aba","channel":"mail","status":"uncertain","attempts":1,"error_code":null,"sent_at":null},{"id":"01m5x2dxryb5cpqmsdrp7j1zc7","notification_id":"083c78b7-aa66-4ee1-a38f-2f54c49c2419","channel":"mail","status":"pending","attempts":0,"error_code":null,"sent_at":null}],"meta":{"current_page":1,"total":2},"operations":{"failed_events":1,"locale_fallbacks":0}}
```

### `GET /api/v1/platform/notifications/settings` — settings

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 996
X-Request-Id: b29-settings

{"data":{"retention_days":90,"email_enabled":true},"email_delivery_available":true}
```

### `POST /api/v1/platform/notifications/events/01m5x2dxnmrmdstab31stbzggp/retry` — retry-event

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/events/01m5x2dxnmrmdstab31stbzggp/retry -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic source failure reviewed"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 995
X-Request-Id: b29-retry-event

{"data":{"id":"01m5x2dxnmrmdstab31stbzggp","status":"pending"}}
```

### `POST /api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/retry` — retry-no-ack

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/retry -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic transport evidence reviewed"}'
```

```http
HTTP/1.1 409
X-Request-Id: b29-retry-no-ack
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 994

{"error":{"status":409,"code":"notification_duplicate_risk","message":"Review the uncertain transport result before retrying.","request_id":"b29-retry-no-ack"}}
```

### `POST /api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/retry` — retry-delivery

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/retry -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic transport evidence reviewed","duplicate_risk_acknowledged":true}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 993
X-Request-Id: b29-retry-delivery

{"data":{"id":"01m5x2dxv818ry5cxgw5vnmcbh","notification_id":"45c0e627-f377-4595-bdf9-1c6a98745aba","channel":"mail","status":"retry","attempts":1,"error_code":null,"sent_at":null}}
```

### `POST /api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/resolve` — resolve-delivery

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/resolve -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic verified transport record","outcome":"do_not_resend"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 992
X-Request-Id: b29-resolve-delivery

{"data":{"id":"01m5x2dxv818ry5cxgw5vnmcbh","notification_id":"45c0e627-f377-4595-bdf9-1c6a98745aba","channel":"mail","status":"skipped","attempts":1,"error_code":"human_reviewed","sent_at":null}}
```

### `POST /api/v1/platform/notifications/templates/generate` — generate

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/generate -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: 61cc4fda-19f5-4a4c-b516-fb09c4443068' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"event_type":"support_reply"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 991
X-Request-Id: b29-generate

{"data":[{"id":"01m5x2eex18gkm57fz39b23sfv","key":"notifications.template.support_reply.nl","version":1,"status":"draft","value":{"title":"Supportnieuws","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"checksum":"b25832e6d5cbf6705823983795cf1138354e70a7f79a8ea9ee50b5e217a5d037","is_current":false,"published_at":null,"rollback_of":null},{"id":"01m5x2eexykppbe99z8jq9x4m5","key":"notifications.template.support_reply.en","version":1,"status":"draft","value":{"title":"Support update","body":"View current details in your account.","action_label":"View details"},"checksum":"2b0a8d1fc106bf62da245f00d40a86df5a25e5100f3c4095220ca8fca63c552a","is_current":false,"published_at":null,"rollback_of":null}]}
```

### `POST /api/v1/platform/notifications/templates/generate` — generate-replay

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/generate -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: 61cc4fda-19f5-4a4c-b516-fb09c4443068' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"event_type":"support_reply"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 990
X-Request-Id: b29-generate-replay

{"data":[{"id":"01m5x2eex18gkm57fz39b23sfv","key":"notifications.template.support_reply.nl","version":1,"status":"draft","value":{"title":"Supportnieuws","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"checksum":"b25832e6d5cbf6705823983795cf1138354e70a7f79a8ea9ee50b5e217a5d037","is_current":false,"published_at":null,"rollback_of":null},{"id":"01m5x2eexykppbe99z8jq9x4m5","key":"notifications.template.support_reply.en","version":1,"status":"draft","value":{"title":"Support update","body":"View current details in your account.","action_label":"View details"},"checksum":"2b0a8d1fc106bf62da245f00d40a86df5a25e5100f3c4095220ca8fca63c552a","is_current":false,"published_at":null,"rollback_of":null}]}
```

### `GET /api/v1/platform/notifications/templates` — template-list

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 989
X-Request-Id: b29-template-list

{"data":[{"id":"01m5x2eexykppbe99z8jq9x4m5","key":"notifications.template.support_reply.en","version":1,"status":"draft","value":{"title":"Support update","body":"View current details in your account.","action_label":"View details"},"checksum":"2b0a8d1fc106bf62da245f00d40a86df5a25e5100f3c4095220ca8fca63c552a","is_current":false,"published_at":null,"rollback_of":null},{"id":"01m5x2eex18gkm57fz39b23sfv","key":"notifications.template.support_reply.nl","version":1,"status":"draft","value":{"title":"Supportnieuws","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"checksum":"b25832e6d5cbf6705823983795cf1138354e70a7f79a8ea9ee50b5e217a5d037","is_current":false,"published_at":null,"rollback_of":null}],"meta":{"current_page":1,"total":2}}
```

### `GET /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv` — template-show

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 988
X-Request-Id: b29-template-show

{"data":{"id":"01m5x2eex18gkm57fz39b23sfv","key":"notifications.template.support_reply.nl","version":1,"status":"draft","value":{"title":"Supportnieuws","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"checksum":"b25832e6d5cbf6705823983795cf1138354e70a7f79a8ea9ee50b5e217a5d037","is_current":false,"published_at":null,"rollback_of":null}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish` — publish-needs-test

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic independent review"}'
```

```http
HTTP/1.1 409
X-Request-Id: b29-publish-needs-test
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998

{"error":{"status":409,"code":"notification_template_test_required","message":"Test the notification template before publication.","request_id":"b29-publish-needs-test"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/test` — template-test

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/test -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 987
X-Request-Id: b29-template-test

{"data":{"failed":0,"checks":["schema","variables","lengths","plain_text","html_render","text_render"],"preview":{"title":"Supportnieuws","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"synthetic":true,"render_sha256":"6f867f138e61fd657a4d73623bc8d4ae0a99bb1b587f4a0dd2588ee44890d227"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish` — self-publish

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic self review refused"}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-self-publish
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 986

{"error":{"status":403,"code":"second_admin_required","message":"A different administrator must publish this setting.","request_id":"b29-self-publish"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish` — publish

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic independent review"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 997
X-Request-Id: b29-publish

{"data":{"id":"01m5x2eex18gkm57fz39b23sfv","key":"notifications.template.support_reply.nl","version":1,"status":"published","value":{"title":"Supportnieuws","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"checksum":"b25832e6d5cbf6705823983795cf1138354e70a7f79a8ea9ee50b5e217a5d037","is_current":true,"published_at":"2026-10-26T21:39:15+00:00","rollback_of":null}}
```

### `PUT /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv` — edit

```bash
curl -i -X PUT http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"expected_checksum":"b25832e6d5cbf6705823983795cf1138354e70a7f79a8ea9ee50b5e217a5d037","value":{"title":"Supportbericht","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"reason":"Synthetic reviewed text update"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 985
X-Request-Id: b29-edit

{"data":{"id":"01m5x2egt83hzypkgr6jmd4b6e","key":"notifications.template.support_reply.nl","version":2,"status":"draft","value":{"title":"Supportbericht","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"checksum":"b42060b58718e16a8b6af35afd6528b5ade161891cc4072d141436378cf5f2ca","is_current":false,"published_at":null,"rollback_of":null}}
```

### `PUT /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv` — edit-stale

```bash
curl -i -X PUT http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"expected_checksum":"b25832e6d5cbf6705823983795cf1138354e70a7f79a8ea9ee50b5e217a5d037","value":{"title":"Supportbericht","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"reason":"Synthetic stale text update"}'
```

```http
HTTP/1.1 409
X-Request-Id: b29-edit-stale
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 984

{"error":{"status":409,"code":"notification_template_stale","message":"Template changed. Refresh before editing.","request_id":"b29-edit-stale"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2egt83hzypkgr6jmd4b6e/test` — edit-test

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2egt83hzypkgr6jmd4b6e/test -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 983
X-Request-Id: b29-edit-test

{"data":{"failed":0,"checks":["schema","variables","lengths","plain_text","html_render","text_render"],"preview":{"title":"Supportbericht","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"synthetic":true,"render_sha256":"1bc619952c86f410ede09e699baa6b763ff359e3c6261d81d0a9b6b1b66e3e0f"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2egt83hzypkgr6jmd4b6e/publish` — edit-publish

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2egt83hzypkgr6jmd4b6e/publish -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic independent review"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 996
X-Request-Id: b29-edit-publish

{"data":{"id":"01m5x2egt83hzypkgr6jmd4b6e","key":"notifications.template.support_reply.nl","version":2,"status":"published","value":{"title":"Supportbericht","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"checksum":"b42060b58718e16a8b6af35afd6528b5ade161891cc4072d141436378cf5f2ca","is_current":true,"published_at":"2026-10-26T21:39:17+00:00","rollback_of":null}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/rollback` — rollback

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/rollback -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"reason":"Synthetic restore historical template"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 982
X-Request-Id: b29-rollback

{"data":{"id":"01m5x2ej4jaaffhkebtys72hk1","key":"notifications.template.support_reply.nl","version":3,"status":"draft","value":{"title":"Supportnieuws","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"checksum":"b25832e6d5cbf6705823983795cf1138354e70a7f79a8ea9ee50b5e217a5d037","is_current":false,"published_at":null,"rollback_of":"01m5x2eex18gkm57fz39b23sfv"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2ej4jaaffhkebtys72hk1/test` — rollback-test

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2ej4jaaffhkebtys72hk1/test -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 981
X-Request-Id: b29-rollback-test

{"data":{"failed":0,"checks":["schema","variables","lengths","plain_text","html_render","text_render"],"preview":{"title":"Supportnieuws","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"synthetic":true,"render_sha256":"6f867f138e61fd657a4d73623bc8d4ae0a99bb1b587f4a0dd2588ee44890d227"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2ej4jaaffhkebtys72hk1/publish` — rollback-publish

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2ej4jaaffhkebtys72hk1/publish -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic rollback independent review"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 995
X-Request-Id: b29-rollback-publish

{"data":{"id":"01m5x2ej4jaaffhkebtys72hk1","key":"notifications.template.support_reply.nl","version":3,"status":"published","value":{"title":"Supportnieuws","body":"Bekijk de actuele gegevens in je account.","action_label":"Bekijk details"},"checksum":"b25832e6d5cbf6705823983795cf1138354e70a7f79a8ea9ee50b5e217a5d037","is_current":true,"published_at":"2026-10-26T21:39:17+00:00","rollback_of":"01m5x2eex18gkm57fz39b23sfv"}}
```

### `POST /api/v1/platform/notifications/templates` — store

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"event_type":"voucher_received","locale":"en","value":{"title":"Your voucher is ready","body":"View current details in your account.","action_label":"View details"},"reason":"Synthetic reviewed manual template"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 980
X-Request-Id: b29-store

{"data":{"id":"01m5x2ejythfveh5mdp5mfs5wa","key":"notifications.template.voucher_received.en","version":1,"status":"draft","value":{"title":"Your voucher is ready","body":"View current details in your account.","action_label":"View details"},"checksum":"3f253ef448b49c782fabe7b9ea202d23cf046d42178b4e3790c3807d0e6277d4","is_current":false,"published_at":null,"rollback_of":null}}
```

### `PUT /api/v1/platform/notifications/settings` — settings-draft

```bash
curl -i -X PUT http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"value":{"retention_days":90,"email_enabled":true},"reason":"Synthetic reviewed delivery policy"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 979
X-Request-Id: b29-settings-draft

{"data":{"id":"01m5x2ek7x0hp0zhmbdzc2ae89","status":"draft","value":{"retention_days":90,"email_enabled":true}},"publication":"Use existing platform-settings submit and independent publish endpoints."}
```

### `POST /api/v1/admin/platform-settings/01m5x2ek7x0hp0zhmbdzc2ae89/review` — settings-review

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5x2ek7x0hp0zhmbdzc2ae89/review -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 978
X-Request-Id: b29-settings-review

{"data":{"id":"01m5x2ek7x0hp0zhmbdzc2ae89","key":"notifications.policy","version":1,"value":{"retention_days":90,"email_enabled":true},"checksum":"484d7b4584374c945b7138effc8637ced380c3e3ed38b7616cdd67ff080a3e05","status":"review","reason":"Synthetic reviewed delivery policy","created_by":"01m5x2dwrtnza2hgde66tp93k1","reviewed_by":null,"review_note":null,"published_at":null,"is_current":false,"rollback_of":null}}
```

### `POST /api/v1/admin/platform-settings/01m5x2ek7x0hp0zhmbdzc2ae89/publish` — settings-publish

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5x2ek7x0hp0zhmbdzc2ae89/publish -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic independent policy review"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 994
X-Request-Id: b29-settings-publish

{"data":{"id":"01m5x2ek7x0hp0zhmbdzc2ae89","key":"notifications.policy","version":1,"value":{"retention_days":90,"email_enabled":true},"checksum":"484d7b4584374c945b7138effc8637ced380c3e3ed38b7616cdd67ff080a3e05","status":"published","reason":"Synthetic reviewed delivery policy","created_by":"01m5x2dwrtnza2hgde66tp93k1","reviewed_by":"01m5x2dxjsdgv7yn0w01q1jsas","review_note":"Synthetic independent policy review","published_at":"2026-10-26T21:39:18+00:00","is_current":true,"rollback_of":null}}
```

### `GET /api/v1/platform/notifications/settings` — settings-published

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 977
X-Request-Id: b29-settings-published

{"data":{"retention_days":90,"email_enabled":true},"email_delivery_available":true}
```

### `GET /api/v1/notifications` — current-inbox

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-Request-Id: b29-current-inbox

{"data":[{"id":"45c0e627-f377-4595-bdf9-1c6a98745aba","type":"voucher_received","recipient_type":"consumer","title":"Je voucher staat klaar","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-voucher_received-v1","locale":"nl","locale_fallback":false,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"skipped"},"read_at":null,"created_at":"2026-10-26T21:38:56+00:00"},{"id":"083c78b7-aa66-4ee1-a38f-2f54c49c2419","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-support_reply-v1","locale":"nl","locale_fallback":false,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"pending"},"read_at":null,"created_at":"2026-10-26T21:38:56+00:00"}],"meta":{"unread_count":2,"current_page":1,"per_page":20,"total":2}}
```

### `GET /api/v1/notification-preferences` — current-preferences

```bash
curl -i -X GET http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998
X-Request-Id: b29-current-preferences

{"data":{"locale":"nl","email_enabled":true,"preferences":{"account_welcome":{"in_app":true,"email":true},"email_verified":{"in_app":true,"email":false},"cashback_credited":{"in_app":true,"email":false},"voucher_received":{"in_app":true,"email":true},"voucher_expiring":{"in_app":true,"email":false},"voucher_used":{"in_app":true,"email":false},"voucher_cancelled":{"in_app":true,"email":true},"cashback_adjusted":{"in_app":true,"email":true},"support_reply":{"in_app":true,"email":false},"favorite_merchant_offer":{"in_app":false,"email":false},"workspace_approved":{"in_app":true,"email":true},"workspace_rejected":{"in_app":true,"email":true},"offer_published":{"in_app":true,"email":false},"offer_expiring":{"in_app":true,"email":false},"commission_changed":{"in_app":true,"email":true},"integration_attention":{"in_app":true,"email":false},"workspace_support_reply":{"in_app":true,"email":false},"workspace_review_required":{"in_app":true,"email":false},"ai_support_escalated":{"in_app":true,"email":false},"affiliate_import_failed":{"in_app":true,"email":false},"notification_delivery_failed":{"in_app":true,"email":false},"critical_notification_failure":{"in_app":true,"email":true},"merchant_attention":{"in_app":true,"email":false}},"email_delivery_available":true}}
```

### `PUT /api/v1/notification-preferences` — current-preferences-update

```bash
curl -i -X PUT http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{"email_enabled":false,"preferences":{"favorite_merchant_offer":{"in_app":true,"email":false}}}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 997
X-Request-Id: b29-current-preferences-update

{"data":{"locale":"nl","email_enabled":false,"preferences":{"account_welcome":{"in_app":true,"email":true},"email_verified":{"in_app":true,"email":false},"cashback_credited":{"in_app":true,"email":false},"voucher_received":{"in_app":true,"email":true},"voucher_expiring":{"in_app":true,"email":false},"voucher_used":{"in_app":true,"email":false},"voucher_cancelled":{"in_app":true,"email":true},"cashback_adjusted":{"in_app":true,"email":true},"support_reply":{"in_app":true,"email":false},"favorite_merchant_offer":{"in_app":true,"email":false},"workspace_approved":{"in_app":true,"email":true},"workspace_rejected":{"in_app":true,"email":true},"offer_published":{"in_app":true,"email":false},"offer_expiring":{"in_app":true,"email":false},"commission_changed":{"in_app":true,"email":true},"integration_attention":{"in_app":true,"email":false},"workspace_support_reply":{"in_app":true,"email":false},"workspace_review_required":{"in_app":true,"email":false},"ai_support_escalated":{"in_app":true,"email":false},"affiliate_import_failed":{"in_app":true,"email":false},"notification_delivery_failed":{"in_app":true,"email":false},"critical_notification_failure":{"in_app":true,"email":true},"merchant_attention":{"in_app":true,"email":false}},"email_delivery_available":true}}
```

### `PUT /api/v1/notification-preferences` — mandatory-mail

```bash
curl -i -X PUT http://localhost:8079/api/v1/notification-preferences -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{"preferences":{"voucher_received":{"email":false}}}'
```

```http
HTTP/1.1 422
X-Request-Id: b29-mandatory-mail
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 996

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"b29-mandatory-mail","fields":{"preferences.voucher_received":["Mandatory notification channels cannot be disabled."]}}}
```

### `DELETE /api/v1/notifications/083c78b7-aa66-4ee1-a38f-2f54c49c2419` — delete-foreign

```bash
curl -i -X DELETE http://localhost:8079/api/v1/notifications/083c78b7-aa66-4ee1-a38f-2f54c49c2419 -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 404
X-Request-Id: b29-delete-foreign
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999

{"error":{"status":404,"code":"not_found","message":"Notification not found.","request_id":"b29-delete-foreign"}}
```

### `DELETE /api/v1/notifications/083c78b7-aa66-4ee1-a38f-2f54c49c2419` — delete-owned

```bash
curl -i -X DELETE http://localhost:8079/api/v1/notifications/083c78b7-aa66-4ee1-a38f-2f54c49c2419 -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 995
X-Request-Id: b29-delete-owned

{"data":{"deleted":true}}
```

### `POST /api/v1/platform/notifications/templates` — invalid-copy

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"event_type":"support_reply","locale":"en","value":{"title":"Unsafe","body":"Pay €999 today","action_label":"View details"},"reason":"Synthetic invalid copy rejected"}'
```

```http
HTTP/1.1 422
X-Request-Id: b29-invalid-copy
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 976

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"b29-invalid-copy","fields":{"value":["Use approved placeholders and plain text without literal amounts, deadlines, links or provider references."]}}}
```

### `POST /api/v1/platform/notifications/templates/generate` — generation-failure

Temporary local fixture header; never shipped or accepted as a production behavior switch.

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/generate -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: bce0ffb7-7175-42bb-b06b-b93b73bc6cd6' -H 'X-B29-Provider-Mode: malformed' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"event_type":"support_reply"}'
```

```http
HTTP/1.1 503
X-Request-Id: b29-generation-failure
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 975

{"error":{"status":503,"code":"notification_generation_failed","message":"Template generation failed safely. No template was published.","request_id":"b29-generation-failure"}}
```

### `POST /api/v1/platform/notifications/templates/generate` — generation-failed-replay

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/generate -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: bce0ffb7-7175-42bb-b06b-b93b73bc6cd6' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"event_type":"support_reply"}'
```

```http
HTTP/1.1 409
X-Request-Id: b29-generation-failed-replay
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 974

{"error":{"status":409,"code":"notification_generation_pending","message":"This generation is pending or failed; no provider retry was made.","request_id":"b29-generation-failed-replay"}}
```

### `POST /api/v1/platform/notifications/templates/generate` — generation-cap

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/generate -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: ad559742-36cc-4fe9-aa4d-c9528cb29a50' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"event_type":"support_reply"}'
```

```http
HTTP/1.1 429
X-Request-Id: b29-generation-cap
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 973

{"error":{"status":429,"code":"notification_ai_daily_limit","message":"Daily template generation limit reached.","request_id":"b29-generation-cap"}}
```

### `POST /api/v1/platform/notifications/templates` — admin-unauth-0

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-0
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-0"}}
```

### `POST /api/v1/platform/notifications/templates` — admin-forbidden-0

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-0
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 994

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-0"}}
```

### `POST /api/v1/platform/notifications/templates/generate` — admin-unauth-1

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/generate -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-1
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-1"}}
```

### `POST /api/v1/platform/notifications/templates/generate` — admin-forbidden-1

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/generate -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-1
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 993

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-1"}}
```

### `PUT /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv` — admin-unauth-2

```bash
curl -i -X PUT http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-2
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-2"}}
```

### `PUT /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv` — admin-forbidden-2

```bash
curl -i -X PUT http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-2
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 992

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-2"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/test` — admin-unauth-3

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/test -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-3
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-3"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/test` — admin-forbidden-3

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/test -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-3
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 991

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-3"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish` — admin-unauth-4

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-4
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-4"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish` — admin-forbidden-4

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/publish -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-4
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 990

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-4"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/rollback` — admin-unauth-5

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/rollback -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-5
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-5"}}
```

### `POST /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/rollback` — admin-forbidden-5

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv/rollback -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-5
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 989

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-5"}}
```

### `POST /api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/retry` — admin-unauth-6

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/retry -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-6
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-6"}}
```

### `POST /api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/retry` — admin-forbidden-6

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/retry -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-6
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 988

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-6"}}
```

### `POST /api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/resolve` — admin-unauth-7

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/resolve -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-7
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-7"}}
```

### `POST /api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/resolve` — admin-forbidden-7

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x2dxv818ry5cxgw5vnmcbh/resolve -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-7
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 987

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-7"}}
```

### `PUT /api/v1/platform/notifications/settings` — admin-unauth-8

```bash
curl -i -X PUT http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-8
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-8"}}
```

### `PUT /api/v1/platform/notifications/settings` — admin-forbidden-8

```bash
curl -i -X PUT http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-8
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 986

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-8"}}
```

### `POST /api/v1/platform/notifications/events/01m5x2dxnmrmdstab31stbzggp/retry` — admin-unauth-9

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/events/01m5x2dxnmrmdstab31stbzggp/retry -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 401
X-Request-Id: b29-admin-unauth-9
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-admin-unauth-9"}}
```

### `POST /api/v1/platform/notifications/events/01m5x2dxnmrmdstab31stbzggp/retry` — admin-forbidden-9

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/events/01m5x2dxnmrmdstab31stbzggp/retry -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 403
X-Request-Id: b29-admin-forbidden-9
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 985

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-admin-forbidden-9"}}
```

### `GET /api/v1/platform/notifications/templates` — read-unauth-0

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json'
```

```http
HTTP/1.1 401
X-Request-Id: b29-read-unauth-0
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-read-unauth-0"}}
```

### `GET /api/v1/platform/notifications/templates` — read-forbidden-0

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 403
X-Request-Id: b29-read-forbidden-0
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 984

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-read-forbidden-0"}}
```

### `GET /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv` — read-unauth-1

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv -H 'Accept: application/json'
```

```http
HTTP/1.1 401
X-Request-Id: b29-read-unauth-1
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-read-unauth-1"}}
```

### `GET /api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv` — read-forbidden-1

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/templates/01m5x2eex18gkm57fz39b23sfv -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 403
X-Request-Id: b29-read-forbidden-1
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 983

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-read-forbidden-1"}}
```

### `GET /api/v1/platform/notifications/events` — read-unauth-2

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/events -H 'Accept: application/json'
```

```http
HTTP/1.1 401
X-Request-Id: b29-read-unauth-2
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-read-unauth-2"}}
```

### `GET /api/v1/platform/notifications/events` — read-forbidden-2

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/events -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 403
X-Request-Id: b29-read-forbidden-2
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 982

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-read-forbidden-2"}}
```

### `GET /api/v1/platform/notifications/deliveries` — read-unauth-3

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/deliveries -H 'Accept: application/json'
```

```http
HTTP/1.1 401
X-Request-Id: b29-read-unauth-3
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-read-unauth-3"}}
```

### `GET /api/v1/platform/notifications/deliveries` — read-forbidden-3

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/deliveries -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 403
X-Request-Id: b29-read-forbidden-3
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 981

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-read-forbidden-3"}}
```

### `GET /api/v1/platform/notifications/settings` — read-unauth-4

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json'
```

```http
HTTP/1.1 401
X-Request-Id: b29-read-unauth-4
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"b29-read-unauth-4"}}
```

### `GET /api/v1/platform/notifications/settings` — read-forbidden-4

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 403
X-Request-Id: b29-read-forbidden-4
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 980

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"b29-read-forbidden-4"}}
```

### `POST /api/v1/platform/notifications/templates` — password-required

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'Authorization: Bearer <API_TOKEN>' --data '{}'
```

```http
HTTP/1.1 423
X-Request-Id: b29-password-required
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 972

{"error":{"status":423,"code":"password_confirmation_required","message":"Confirm your password to continue.","request_id":"b29-password-required"}}
```

### `POST /api/v1/platform/notifications/templates` — browser-csrf

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{}'
```

```http
HTTP/1.1 419
X-Request-Id: b29-browser-csrf
Content-Type: application/json

{"error":{"status":419,"code":"csrf_token_mismatch","message":"The session or CSRF token has expired. Refresh and try again.","request_id":"b29-browser-csrf"}}
```

### `GET /api/v1/platform/notifications/templates/00000000000000000000000000` — template-missing

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/templates/00000000000000000000000000 -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 404
X-Request-Id: b29-template-missing
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 971

{"error":{"status":404,"code":"not_found","message":"Template not found.","request_id":"b29-template-missing"}}
```

### `GET /api/v1/platform/notifications/deliveries?status=unknown` — delivery-invalid-status

```bash
curl -i -X GET 'http://localhost:8079/api/v1/platform/notifications/deliveries?status=unknown' -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 422
X-Request-Id: b29-delivery-invalid-status
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 970

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"b29-delivery-invalid-status","fields":{"status":["The selected status is invalid."]}}}
```

### `GET /api/v1/notifications` — post-worker-inbox

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 979
X-Request-Id: b29-post-worker-inbox

{"data":[{"id":"eba4a2a2-4c81-417f-8860-c506dc5d7bb0","type":"email_verified","recipient_type":"consumer","title":"E-mailadres bevestigd","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-email_verified-v1","locale":"nl","locale_fallback":false,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"skipped"},"read_at":null,"created_at":"2026-10-26T21:39:32+00:00"},{"id":"45c0e627-f377-4595-bdf9-1c6a98745aba","type":"voucher_received","recipient_type":"consumer","title":"Je voucher staat klaar","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-voucher_received-v1","locale":"nl","locale_fallback":false,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"skipped"},"read_at":null,"created_at":"2026-10-26T21:38:56+00:00"}],"meta":{"unread_count":2,"current_page":1,"per_page":20,"total":2}}
```

### `GET /api/v1/platform/notifications/deliveries` — post-worker-deliveries

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/deliveries -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 969
X-Request-Id: b29-post-worker-deliveries

{"data":[{"id":"01m5x2f1ckdf73j2eggbg1r1pz","notification_id":"eba4a2a2-4c81-417f-8860-c506dc5d7bb0","channel":"mail","status":"skipped","attempts":0,"error_code":"recipient_or_preferences_changed","sent_at":null},{"id":"01m5x2dxv818ry5cxgw5vnmcbh","notification_id":"45c0e627-f377-4595-bdf9-1c6a98745aba","channel":"mail","status":"skipped","attempts":1,"error_code":"human_reviewed","sent_at":null}],"meta":{"current_page":1,"total":2},"operations":{"failed_events":0,"locale_fallbacks":0}}
```

### `GET /api/v1/notifications` — inbox-storage-failure

Temporary local fixture header; never shipped or accepted as a production behavior switch.

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'X-B29-Failure: storage'
```

```http
HTTP/1.1 500
X-Request-Id: b29-inbox-storage-failure
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 978

{"error":{"status":500,"code":"server_error","message":"An unexpected error occurred.","request_id":"b29-inbox-storage-failure"}}
```

### `PATCH /api/v1/notifications/5826b1f5-f2e3-4531-aa4f-16d5ad0865fd/read` — current-mark-read

```bash
curl -i -X PATCH http://localhost:8079/api/v1/notifications/5826b1f5-f2e3-4531-aa4f-16d5ad0865fd/read -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-Request-Id: b29-current-mark-read

{"data":{"id":"5826b1f5-f2e3-4531-aa4f-16d5ad0865fd","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-support_reply-v1","locale":"nl","locale_fallback":false,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"pending"},"read_at":"2026-10-26T21:43:28+00:00","created_at":"2026-10-26T21:43:19+00:00"}}
```

### `POST /api/v1/notifications/read-all` — current-read-all

```bash
curl -i -X POST http://localhost:8079/api/v1/notifications/read-all -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998
X-Request-Id: b29-current-read-all

{"data":{"marked_read":2}}
```

### `GET /api/v1/workspaces/01m5x2ny1rern462kbng81pjbk/notifications` — legacy-list

```bash
curl -i -X GET http://localhost:8079/api/v1/workspaces/01m5x2ny1rern462kbng81pjbk/notifications -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 997
X-Request-Id: b29-legacy-list

{"data":[{"id":"01m5x2ny4h06mwe77956je6251","type":"new_claims","severity":"info","title":"Synthetic legacy notice","body":"Synthetic legacy content","subject":null,"read":false,"created_at":"2026-10-26T21:43:18+00:00"}],"meta":{"current_page":1,"last_page":1,"per_page":25,"total":1,"unread":1}}
```

### `POST /api/v1/workspaces/01m5x2ny1rern462kbng81pjbk/notifications/01m5x2ny4h06mwe77956je6251/read` — legacy-read

```bash
curl -i -X POST http://localhost:8079/api/v1/workspaces/01m5x2ny1rern462kbng81pjbk/notifications/01m5x2ny4h06mwe77956je6251/read -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 996
X-Request-Id: b29-legacy-read

{"data":{"id":"01m5x2ny4h06mwe77956je6251","type":"new_claims","severity":"info","title":"Synthetic legacy notice","body":"Synthetic legacy content","subject":null,"read":true,"created_at":"2026-10-26T21:43:18+00:00"}}
```

### `POST /api/v1/workspaces/01m5x2ny1rern462kbng81pjbk/notifications/read-all` — legacy-read-all

```bash
curl -i -X POST http://localhost:8079/api/v1/workspaces/01m5x2ny1rern462kbng81pjbk/notifications/read-all -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 995
X-Request-Id: b29-legacy-read-all

{"data":{"marked_read":0,"unread":0}}
```

### `DELETE /api/v1/notifications/f3a81a7d-0ee4-4b1c-8549-71832b366bb4` — mandatory-delete-conflict

```bash
curl -i -X DELETE http://localhost:8079/api/v1/notifications/f3a81a7d-0ee4-4b1c-8549-71832b366bb4 -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 409
X-Request-Id: b29-mandatory-delete-conflict
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 994

{"error":{"status":409,"code":"notification_delivery_active","message":"Delivery outcome must be resolved before deletion.","request_id":"b29-mandatory-delete-conflict"}}
```


### Additional actual reminder-policy and workspace-context captures

Five requests in a separate isolated local fixture prove reviewed configurable windows and owned public workspace context. Same real session/password/seededOTP workflow; no live mail/model calls.

#### `PUT /api/v1/platform/notifications/settings` — custom-reminders-draft

```bash
curl -i -X PUT http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"value":{"retention_days":90,"email_enabled":true,"voucher_reminder_days":[14,3],"offer_expiry_days":5},"reason":"Synthetic reviewed custom reminder windows"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998
X-Request-Id: b29-custom-reminders-draft

{"data":{"id":"01m5x3y45z12xfz473zsgfg127","status":"draft","value":{"retention_days":90,"email_enabled":true,"voucher_reminder_days":[14,3],"offer_expiry_days":5}},"publication":"Use existing platform-settings submit and independent publish endpoints."}
```

#### `POST /api/v1/admin/platform-settings/01m5x3y45z12xfz473zsgfg127/review` — custom-reminders-review

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5x3y45z12xfz473zsgfg127/review -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 997
X-Request-Id: b29-custom-reminders-review

{"data":{"id":"01m5x3y45z12xfz473zsgfg127","key":"notifications.policy","version":1,"value":{"retention_days":90,"email_enabled":true,"voucher_reminder_days":[14,3],"offer_expiry_days":5},"checksum":"a9bc108970c1ff3d589558ac1cc0377dae5ad9bd4e23278a80120523b0a93c97","status":"review","reason":"Synthetic reviewed custom reminder windows","created_by":"01m5x3y03myc0beyb32r17c04r","reviewed_by":null,"review_note":null,"published_at":null,"is_current":false,"rollback_of":null}}
```

#### `POST /api/v1/admin/platform-settings/01m5x3y45z12xfz473zsgfg127/publish` — custom-reminders-publish

```bash
curl -i -X POST http://localhost:8079/api/v1/admin/platform-settings/01m5x3y45z12xfz473zsgfg127/publish -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic independent configuration review"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998
X-Request-Id: b29-custom-reminders-publish

{"data":{"id":"01m5x3y45z12xfz473zsgfg127","key":"notifications.policy","version":1,"value":{"retention_days":90,"email_enabled":true,"voucher_reminder_days":[14,3],"offer_expiry_days":5},"checksum":"a9bc108970c1ff3d589558ac1cc0377dae5ad9bd4e23278a80120523b0a93c97","status":"published","reason":"Synthetic reviewed custom reminder windows","created_by":"01m5x3y03myc0beyb32r17c04r","reviewed_by":"01m5x3y0dnpa3fygbc4ghfz0wk","review_note":"Synthetic independent configuration review","published_at":"2026-10-26T22:05:15+00:00","is_current":true,"rollback_of":null}}
```

#### `GET /api/v1/platform/notifications/settings` — custom-reminders-active

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 996
X-Request-Id: b29-custom-reminders-active

{"data":{"retention_days":90,"email_enabled":true,"voucher_reminder_days":[14,3],"offer_expiry_days":5},"email_delivery_available":true}
```

#### `GET /api/v1/notifications` — workspace-context

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-Request-Id: b29-workspace-context

{"data":[{"id":"4632e7fa-e8fd-4f76-858b-fc6ef476db22","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-support_reply-v1","locale":"nl","locale_fallback":false,"workspace_id":null,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"pending"},"read_at":null,"created_at":"2026-10-26T22:05:12+00:00"},{"id":"3faddfae-743f-4fd6-b8a8-bd5bf7de7cc2","type":"voucher_received","recipient_type":"consumer","title":"Je voucher staat klaar","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-voucher_received-v1","locale":"nl","locale_fallback":false,"workspace_id":null,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"uncertain"},"read_at":null,"created_at":"2026-10-26T22:05:12+00:00"},{"id":"3676a718-de5a-4900-b302-682c8e4e2e43","type":"merchant_attention","recipient_type":"workspace","title":"Webshopmelding","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-merchant_attention-v1","locale":"nl","locale_fallback":false,"workspace_id":"01m5x3y0e9g0rscg8bswbt1f31","action_route":"/app/workspaces?workspace=01m5x3y0e9g0rscg8bswbt1f31","delivery_channels":{"database":"delivered","email":"pending"},"read_at":null,"created_at":"2026-10-26T22:05:12+00:00"}],"meta":{"unread_count":3,"current_page":1,"per_page":20,"total":3}}
```


### Actual queue-worker mail recovery captures

Separate isolated fixture: an uncertain record with one synthetic prior attempt is explicitly reviewed through the actual stepped-up API. The real DB queue worker accepts one local array-transport mail (status sent, attempts2); this is not external customer delivery.

#### `GET /api/v1/notifications` — worker-inbox-before

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-Request-Id: b29-worker-inbox-before

{"data":[{"id":"c2821351-7aa0-4ce1-9a34-8387e51279e9","type":"voucher_received","recipient_type":"consumer","title":"Je voucher staat klaar","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-voucher_received-v1","locale":"nl","locale_fallback":false,"workspace_id":null,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"uncertain"},"read_at":null,"created_at":"2026-10-26T22:15:34+00:00"},{"id":"2e671058-7391-475c-b5cb-3c4be8002d20","type":"merchant_attention","recipient_type":"workspace","title":"Webshopmelding","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-merchant_attention-v1","locale":"nl","locale_fallback":false,"workspace_id":"01m5x4h0c79n1zvkb49n92cwnt","action_route":"/app/workspaces?workspace=01m5x4h0c79n1zvkb49n92cwnt","delivery_channels":{"database":"delivered","email":"pending"},"read_at":null,"created_at":"2026-10-26T22:15:34+00:00"},{"id":"1054241b-501d-47f5-8da6-b01efa98dd04","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-support_reply-v1","locale":"nl","locale_fallback":false,"workspace_id":null,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"pending"},"read_at":null,"created_at":"2026-10-26T22:15:34+00:00"}],"meta":{"unread_count":3,"current_page":1,"per_page":20,"total":3}}
```

#### `POST /api/v1/platform/notifications/deliveries/01m5x4h0jeg7qb0evss79ff13g/retry` — worker-approved-retry

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x4h0jeg7qb0evss79ff13g/retry -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic uncertain fixture reviewed before retry","duplicate_risk_acknowledged":true}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998
X-Request-Id: b29-worker-approved-retry

{"data":{"id":"01m5x4h0jeg7qb0evss79ff13g","notification_id":"c2821351-7aa0-4ce1-9a34-8387e51279e9","channel":"mail","status":"retry","attempts":1,"error_code":null,"sent_at":null}}
```

#### `GET /api/v1/platform/notifications/deliveries` — worker-deliveries-before

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/deliveries -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 997
X-Request-Id: b29-worker-deliveries-before

{"data":[{"id":"01m5x4h0jeg7qb0evss79ff13g","notification_id":"c2821351-7aa0-4ce1-9a34-8387e51279e9","channel":"mail","status":"retry","attempts":1,"error_code":null,"sent_at":null},{"id":"01m5x4h0j1gvene5hjv1c1znbt","notification_id":"1054241b-501d-47f5-8da6-b01efa98dd04","channel":"mail","status":"pending","attempts":0,"error_code":null,"sent_at":null},{"id":"01m5x4h0h25hwq9cwkny7f7rhn","notification_id":"2e671058-7391-475c-b5cb-3c4be8002d20","channel":"mail","status":"pending","attempts":0,"error_code":null,"sent_at":null}],"meta":{"current_page":1,"total":3},"operations":{"failed_events":1,"locale_fallbacks":0}}
```

#### `GET /api/v1/platform/notifications/deliveries` — worker-deliveries-sent

```bash
curl -i -X GET http://localhost:8079/api/v1/platform/notifications/deliveries -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 996
X-Request-Id: b29-worker-deliveries-sent

{"data":[{"id":"01m5x4h0jeg7qb0evss79ff13g","notification_id":"c2821351-7aa0-4ce1-9a34-8387e51279e9","channel":"mail","status":"sent","attempts":2,"error_code":null,"sent_at":"2026-10-26T22:15:41+00:00"},{"id":"01m5x4h0j1gvene5hjv1c1znbt","notification_id":"1054241b-501d-47f5-8da6-b01efa98dd04","channel":"mail","status":"skipped","attempts":0,"error_code":"recipient_or_preferences_changed","sent_at":null},{"id":"01m5x4h0h25hwq9cwkny7f7rhn","notification_id":"2e671058-7391-475c-b5cb-3c4be8002d20","channel":"mail","status":"skipped","attempts":0,"error_code":"recipient_or_preferences_changed","sent_at":null}],"meta":{"current_page":1,"total":3},"operations":{"failed_events":1,"locale_fallbacks":0}}
```

#### `GET /api/v1/notifications` — worker-inbox-after

```bash
curl -i -X GET http://localhost:8079/api/v1/notifications -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998
X-Request-Id: b29-worker-inbox-after

{"data":[{"id":"c2821351-7aa0-4ce1-9a34-8387e51279e9","type":"voucher_received","recipient_type":"consumer","title":"Je voucher staat klaar","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-voucher_received-v1","locale":"nl","locale_fallback":false,"workspace_id":null,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"sent"},"read_at":null,"created_at":"2026-10-26T22:15:34+00:00"},{"id":"2e671058-7391-475c-b5cb-3c4be8002d20","type":"merchant_attention","recipient_type":"workspace","title":"Webshopmelding","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-merchant_attention-v1","locale":"nl","locale_fallback":false,"workspace_id":"01m5x4h0c79n1zvkb49n92cwnt","action_route":"/app/workspaces?workspace=01m5x4h0c79n1zvkb49n92cwnt","delivery_channels":{"database":"delivered","email":"skipped"},"read_at":null,"created_at":"2026-10-26T22:15:34+00:00"},{"id":"1054241b-501d-47f5-8da6-b01efa98dd04","type":"support_reply","recipient_type":"consumer","title":"Nieuw antwoord van support","body":"Bekijk de actuele gegevens in je ShopBonus-account.","action_label":"Bekijk details","template_version":"builtin-support_reply-v1","locale":"nl","locale_fallback":false,"workspace_id":null,"action_route":"/app/benefits","delivery_channels":{"database":"delivered","email":"skipped"},"read_at":null,"created_at":"2026-10-26T22:15:34+00:00"}],"meta":{"unread_count":3,"current_page":1,"per_page":20,"total":3}}
```


### Additional actual route-specific conflict and validation captures

Fourteen real requests in an isolated synthetic fixture include the prerequisite draft/test/publication/retry steps. No production fixture switch or live provider/customer message.

#### `POST /api/v1/platform/notifications/templates` — error-fixture-draft

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"event_type":"support_reply","locale":"nl","value":{"title":"Support update","body":"View current details in your account.","action_label":"View details"},"reason":"Synthetic conflict capture fixture"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998
X-Request-Id: b29-error-fixture-draft

{"data":{"id":"01m5x52kdaqxdwxp2jc37844t2","key":"notifications.template.support_reply.nl","version":1,"status":"draft","value":{"title":"Support update","body":"View current details in your account.","action_label":"View details"},"checksum":"2b0a8d1fc106bf62da245f00d40a86df5a25e5100f3c4095220ca8fca63c552a","is_current":false,"published_at":null,"rollback_of":null}}
```

#### `POST /api/v1/platform/notifications/templates/01m5x52kdaqxdwxp2jc37844t2/test` — error-fixture-test

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x52kdaqxdwxp2jc37844t2/test -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 997
X-Request-Id: b29-error-fixture-test

{"data":{"failed":0,"checks":["schema","variables","lengths","plain_text","html_render","text_render"],"preview":{"title":"Support update","body":"View current details in your account.","action_label":"View details"},"synthetic":true,"render_sha256":"d96ed44a4a72498d2363a66593cf337bf386fb7030d639c6a852d5edb69eefe5"}}
```

#### `POST /api/v1/platform/notifications/templates/01m5x52kdaqxdwxp2jc37844t2/publish` — error-fixture-publish

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x52kdaqxdwxp2jc37844t2/publish -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic independent review"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 998
X-Request-Id: b29-error-fixture-publish

{"data":{"id":"01m5x52kdaqxdwxp2jc37844t2","key":"notifications.template.support_reply.nl","version":1,"status":"published","value":{"title":"Support update","body":"View current details in your account.","action_label":"View details"},"checksum":"2b0a8d1fc106bf62da245f00d40a86df5a25e5100f3c4095220ca8fca63c552a","is_current":true,"published_at":"2026-10-26T22:25:11+00:00","rollback_of":null}}
```

#### `POST /api/v1/platform/notifications/templates/01m5x52kdaqxdwxp2jc37844t2/test` — published-template-test-conflict

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x52kdaqxdwxp2jc37844t2/test -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{}'
```

```http
HTTP/1.1 409
X-Request-Id: b29-published-template-test-conflict
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 996

{"error":{"status":409,"code":"template_not_draft","message":"Only unpublished templates can be tested.","request_id":"b29-published-template-test-conflict"}}
```

#### `POST /api/v1/platform/notifications/templates` — error-fixture-unpublished

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"event_type":"support_reply","locale":"en","value":{"title":"Support update","body":"View current details in your account.","action_label":"View details"},"reason":"Synthetic unpublished conflict fixture"}'
```

```http
HTTP/1.1 201
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 995
X-Request-Id: b29-error-fixture-unpublished

{"data":{"id":"01m5x52knrx8z54ycpswqyjyzy","key":"notifications.template.support_reply.en","version":1,"status":"draft","value":{"title":"Support update","body":"View current details in your account.","action_label":"View details"},"checksum":"2b0a8d1fc106bf62da245f00d40a86df5a25e5100f3c4095220ca8fca63c552a","is_current":false,"published_at":null,"rollback_of":null}}
```

#### `POST /api/v1/platform/notifications/templates/01m5x52knrx8z54ycpswqyjyzy/rollback` — draft-rollback-conflict

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/templates/01m5x52knrx8z54ycpswqyjyzy/rollback -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"reason":"Synthetic draft cannot roll back"}'
```

```http
HTTP/1.1 409
X-Request-Id: b29-draft-rollback-conflict
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 994

{"error":{"status":409,"code":"setting_not_published","message":"Only published history can be used for a rollback draft.","request_id":"b29-draft-rollback-conflict"}}
```

#### `POST /api/v1/platform/notifications/events/01m5x52fv508e5vvbcgk6qc4rh/retry` — error-fixture-event-retry

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/events/01m5x52fv508e5vvbcgk6qc4rh/retry -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic failed event reviewed"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 993
X-Request-Id: b29-error-fixture-event-retry

{"data":{"id":"01m5x52fv508e5vvbcgk6qc4rh","status":"pending"}}
```

#### `POST /api/v1/platform/notifications/events/01m5x52fv508e5vvbcgk6qc4rh/retry` — event-retry-conflict

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/events/01m5x52fv508e5vvbcgk6qc4rh/retry -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic repeated retry rejected"}'
```

```http
HTTP/1.1 409
X-Request-Id: b29-event-retry-conflict
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 992

{"error":{"status":409,"code":"notification_event_not_retryable","message":"Event cannot be retried.","request_id":"b29-event-retry-conflict"}}
```

#### `POST /api/v1/platform/notifications/deliveries/01m5x52fwgdv8szqwwrg3xjese/resolve` — error-fixture-resolution

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x52fwgdv8szqwwrg3xjese/resolve -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic reviewed uncertain record","outcome":"do_not_resend"}'
```

```http
HTTP/1.1 200
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 991
X-Request-Id: b29-error-fixture-resolution

{"data":{"id":"01m5x52fwgdv8szqwwrg3xjese","notification_id":"be6aa3e3-f337-4c7c-abe7-54a4b7079d64","channel":"mail","status":"skipped","attempts":1,"error_code":"human_reviewed","sent_at":null}}
```

#### `POST /api/v1/platform/notifications/deliveries/01m5x52fwgdv8szqwwrg3xjese/resolve` — delivery-resolve-conflict

```bash
curl -i -X POST http://localhost:8079/api/v1/platform/notifications/deliveries/01m5x52fwgdv8szqwwrg3xjese/resolve -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"note":"Synthetic repeated resolution rejected","outcome":"do_not_resend"}'
```

```http
HTTP/1.1 409
X-Request-Id: b29-delivery-resolve-conflict
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 990

{"error":{"status":409,"code":"notification_delivery_not_uncertain","message":"Only uncertain deliveries require resolution.","request_id":"b29-delivery-resolve-conflict"}}
```

#### `PUT /api/v1/platform/notifications/settings` — settings-validation

```bash
curl -i -X PUT http://localhost:8079/api/v1/platform/notifications/settings -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' -H 'Cookie: <SPA_SESSION_COOKIES>' --data '{"value":{"retention_days":0,"email_enabled":true},"reason":"Synthetic invalid policy rejected"}'
```

```http
HTTP/1.1 422
X-Request-Id: b29-settings-validation
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 989

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"b29-settings-validation","fields":{"value.retention_days":["The value.retention days field must be between 1 and 365."]}}}
```

#### `GET /api/v1/platform/notifications/templates?per_page=101` — template-pagination-validation

```bash
curl -i -X GET 'http://localhost:8079/api/v1/platform/notifications/templates?per_page=101' -H 'Accept: application/json' -H 'Origin: http://localhost:3000' -H 'Referer: http://localhost:3000/' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Cookie: <SPA_SESSION_COOKIES>'
```

```http
HTTP/1.1 422
X-Request-Id: b29-template-pagination-validation
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 988

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"b29-template-pagination-validation","fields":{"per_page":["The per page field must be between 1 and 100."]}}}
```

#### `GET /api/v1/notifications?filter=invalid` — notification-filter-validation

```bash
curl -i -X GET 'http://localhost:8079/api/v1/notifications?filter=invalid' -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>'
```

```http
HTTP/1.1 422
X-Request-Id: b29-notification-filter-validation
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"b29-notification-filter-validation","fields":{"filter":["The selected filter is invalid."]}}}
```

#### `PATCH /api/v1/notifications/fa139eeb-6ef1-43a7-a69c-d27fbee137f0/read` — foreign-read-not-found

```bash
curl -i -X PATCH http://localhost:8079/api/v1/notifications/fa139eeb-6ef1-43a7-a69c-d27fbee137f0/read -H 'Accept: application/json' -H 'Authorization: Bearer <API_TOKEN>' -H 'Content-Type: application/json' --data '{}'
```

```http
HTTP/1.1 404
X-Request-Id: b29-foreign-read-not-found
Content-Type: application/json
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999

{"error":{"status":404,"code":"not_found","message":"Notification not found.","request_id":"b29-foreign-read-not-found"}}
```


## B21 frontend integration corrections — 2026-10-27

Captured from a real local Laravel server on the working tree based on `665bc1d`, isolated SQLite, file sessions and array mail. Payments used an explicitly synthetic local Mollie upstream; no actual provider or email delivery is claimed. Sanitized full captures: [B21_HTTP_CAPTURE.json](../integration/B21_HTTP_CAPTURE.json). Local fixtures and senior configuration: [LOCAL_INTEGRATION.md](../integration/LOCAL_INTEGRATION.md).

`GET /api/v1/public/legal-requirements?locale=nl|en` exposes exactly the legal versions required by signup, their CMS/configuration origin and links only to matching public published documents. Missing configuration/text is visible through availability flags; registration continues to fail closed when versions are missing. `registration_available` is not a statement of legal approval. A configuration fallback does not invent document text. Re-fetch versions before submitting `account_type`, `accept_terms`, `terms_version` and `privacy_version` to `/register`.

`POST /api/v1/consumer/offers/{slug}/claim` requires an active verified account. Only public approved-shop promotional_code offers qualify. Server-side stock/validity applies and one consumer+offer can allocate only one voucher. Replay returns the same reference; no code is exposed here. Read the owned `/api/v1/consumer/vouchers/{id}` endpoint to reveal the code. Support impersonation is blocked. Existing claims replay after pause/expiry; unpublished shops return404. Wallet-purchase and merchant-reward offers return409, and Answer24 retains its separate token flow.

Funding `POST /api/v1/workspaces/{workspace}/funding/top-ups` now accepts an optional UUID `Idempotency-Key`. Persist one UUID before the first request and reuse it with the same amount on every retry. Keys are scoped to the actor+workspace. Different amounts return409. Headerless requests remain independent for released v1 compatibility. The provider receives a distinct stable UUID, with only one create attempt per intent. Never infer payment from the redirect or checkout link.

Funding `creation_state` is separate from payment `state`: creating means the committed intent is being submitted or needs crash review; created means a provider reference was stored; uncertain means creation could not be confirmed. A502 includes top_up_id. Replaying either creating or uncertain returns201 with the same ID and no checkout URL and never resubmits, including after provider cache expiry/configuration changes. Poll the existing top-up; do not generate another key automatically. If no provider reference was stored, a senior must reconcile against provider evidence before deciding whether a genuinely new payment is appropriate. No wallet credit is posted without existing verified-paid settlement. [Mollie idempotency](https://docs.mollie.com/reference/api-idempotency) caches for one hour and ties keys to credentials; permanent replay protection is local.

### `GET /api/v1/public/legal-requirements`

```bash
curl -i -X GET -H 'Accept: application/json' 'http://localhost:8000/api/v1/public/legal-requirements'
```

```http
HTTP/1.1 200
Cache-Control: no-store, private
Content-Type: application/json
X-Request-Id: 01a17e90-223c-71ae-837a-af8ac8676c12

{"data":{"locale":"nl","registration_available":true,"documents_available":true,"documents":{"terms":{"version":"LOCAL-SAMPLE-1","source":"cms","document_url":"http://127.0.0.1:8079/api/v1/public/pages/nl/local-sample-terms"},"privacy":{"version":"LOCAL-SAMPLE-1","source":"cms","document_url":"http://127.0.0.1:8079/api/v1/public/pages/nl/local-sample-privacy"}}}}
```

### `GET /api/v1/public/legal-requirements?locale=en`

```bash
curl -i -X GET -H 'Accept: application/json' 'http://localhost:8000/api/v1/public/legal-requirements?locale=en'
```

```http
HTTP/1.1 200
Cache-Control: no-store, private
Content-Type: application/json
X-Request-Id: 01a17e90-2277-70e1-8cf2-4b492ae316da

{"data":{"locale":"en","registration_available":true,"documents_available":true,"documents":{"terms":{"version":"LOCAL-SAMPLE-1","source":"cms","document_url":"http://127.0.0.1:8079/api/v1/public/pages/en/local-sample-terms"},"privacy":{"version":"LOCAL-SAMPLE-1","source":"cms","document_url":"http://127.0.0.1:8079/api/v1/public/pages/en/local-sample-privacy"}}}}
```

### `Invalid legal locale`

```bash
curl -i -X GET -H 'Accept: application/json' 'http://localhost:8000/api/v1/public/legal-requirements?locale=fr'
```

```http
HTTP/1.1 422
X-Request-Id: 01a17e90-2288-728a-94be-7e9a40aa9977
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a17e90-2288-728a-94be-7e9a40aa9977","fields":{"locale":["The selected locale is invalid."]}}}
```

### `POST /api/v1/consumer/offers/{slug}/claim`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Content-Type: application/json' -d '{}' 'http://localhost:8000/api/v1/consumer/offers/local-sample-promotional-voucher-ibj2v9/claim'
```

```http
HTTP/1.1 201
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17e90-2311-706a-be87-a06ee1e76519

{"data":{"id":"01m5z908sg2jrn9rbg1ecbjkms","status":"issued","voucher_url":"http://127.0.0.1:8079/api/v1/consumer/vouchers/01m5z908sg2jrn9rbg1ecbjkms"}}
```

### `Promotional replay`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Content-Type: application/json' -d '{}' 'http://localhost:8000/api/v1/consumer/offers/local-sample-promotional-voucher-ibj2v9/claim'
```

```http
HTTP/1.1 201
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17e90-2343-7024-8670-3ac5bf9fe245

{"data":{"id":"01m5z908sg2jrn9rbg1ecbjkms","status":"issued","voucher_url":"http://127.0.0.1:8079/api/v1/consumer/vouchers/01m5z908sg2jrn9rbg1ecbjkms"}}
```

### `Unauthenticated claim`

```bash
curl -i -X POST -H 'Accept: application/json' 'http://localhost:8000/api/v1/consumer/offers/local-sample-promotional-voucher-ibj2v9/claim'
```

```http
HTTP/1.1 401
X-Request-Id: 01a17e90-22f7-706f-80f1-18a5bae9cd5e
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a17e90-22f7-706f-80f1-18a5bae9cd5e"}}
```

### `Unknown claim`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Content-Type: application/json' -d '{}' 'http://localhost:8000/api/v1/consumer/offers/missing/claim'
```

```http
HTTP/1.1 404
X-Request-Id: 01a17e90-2357-72ad-a651-b7f3f7686b5b
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":404,"code":"not_found","message":"Offer not found.","request_id":"01a17e90-2357-72ad-a651-b7f3f7686b5b"}}
```

### `Paused offer claim`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <MERCHANT_TOKEN>' -H 'Content-Type: application/json' -d '{}' 'http://localhost:8000/api/v1/consumer/offers/local-sample-promotional-voucher-ibj2v9/claim'
```

```http
HTTP/1.1 409
X-Request-Id: 01a17e90-245a-7141-b440-54a14428a847
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":409,"code":"offer_not_active","message":"This offer is not active.","request_id":"01a17e90-245a-7141-b440-54a14428a847","details":{"status":"paused"}}}
```

### `Missing browser CSRF token`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'Origin: http://127.0.0.1:8079' -H 'Referer: http://127.0.0.1:8079/' -d '{}' 'http://localhost:8000/api/v1/consumer/offers/local-sample-promotional-voucher-ibj2v9/claim'
```

```http
HTTP/1.1 419
X-Request-Id: 01a17e90-2883-702e-9b1e-a736e7668c02
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":419,"code":"csrf_token_mismatch","message":"The session or CSRF token has expired. Refresh and try again.","request_id":"01a17e90-2883-702e-9b1e-a736e7668c02"}}
```

### `Unverified claim`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Origin: http://127.0.0.1:8079' -H 'Referer: http://127.0.0.1:8079/' -d '{}' 'http://localhost:8000/api/v1/consumer/offers/local-sample-promotional-voucher-ibj2v9/claim'
```

```http
HTTP/1.1 403
X-Request-Id: 01a17e90-2899-720f-8046-a411b1abbcb3
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":403,"code":"email_not_verified","message":"Verify your email address to continue.","request_id":"01a17e90-2899-720f-8046-a411b1abbcb3"}}
```

### `Claim rate limit`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' -H 'Content-Type: application/json' -d '{}' 'http://localhost:8000/api/v1/consumer/offers/local-sample-promotional-voucher-ibj2v9/claim'
```

```http
HTTP/1.1 429
X-Request-Id: 01a17e90-29ac-70e2-90ae-efb5a7eb06dc
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":429,"code":"too_many_requests","message":"Too many requests. Please slow down.","request_id":"01a17e90-29ac-70e2-90ae-efb5a7eb06dc"}}
```

### `POST /api/v1/workspaces/{workspace}/funding/top-ups`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <MERCHANT_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: 2c1a1ed3-0f7f-487a-9768-dc3752f61533' -d '{"amount_minor":1000}' 'http://localhost:8000/api/v1/workspaces/01m5z907k07qxtaw1nbw0yacyc/funding/top-ups'
```

```http
HTTP/1.1 201
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17e90-2474-72dd-9c27-9a9d108276d7

{"data":{"id":"01m5z9094dwnh9w2mymph2kqv5","state":"pending","amount_minor":1000,"currency":"EUR","creation_state":"created","checkout_url":"https://example.com/synthetic-checkout","paid_at":null}}
```

### `Top-up replay`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <MERCHANT_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: 2c1a1ed3-0f7f-487a-9768-dc3752f61533' -d '{"amount_minor":1000}' 'http://localhost:8000/api/v1/workspaces/01m5z907k07qxtaw1nbw0yacyc/funding/top-ups'
```

```http
HTTP/1.1 201
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17e90-24c1-72be-a2fe-1edd2a875f29

{"data":{"id":"01m5z9094dwnh9w2mymph2kqv5","state":"pending","amount_minor":1000,"currency":"EUR","creation_state":"created","checkout_url":"https://example.com/synthetic-checkout","paid_at":null}}
```

### `Top-up amount conflict`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <MERCHANT_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: 2c1a1ed3-0f7f-487a-9768-dc3752f61533' -d '{"amount_minor":2000}' 'http://localhost:8000/api/v1/workspaces/01m5z907k07qxtaw1nbw0yacyc/funding/top-ups'
```

```http
HTTP/1.1 409
X-Request-Id: 01a17e90-24d8-7250-a04a-088d3824ebfa
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":409,"code":"funding_request_conflict","message":"This idempotency key belongs to a different top-up.","request_id":"01a17e90-24d8-7250-a04a-088d3824ebfa"}}
```

### `Invalid top-up key`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <MERCHANT_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: invalid' -d '{"amount_minor":1000}' 'http://localhost:8000/api/v1/workspaces/01m5z907k07qxtaw1nbw0yacyc/funding/top-ups'
```

```http
HTTP/1.1 422
X-Request-Id: 01a17e90-24ef-73ec-a30a-1e7f2dc83589
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a17e90-24ef-73ec-a30a-1e7f2dc83589","fields":{"key":["The key field must be a valid UUID."]}}}
```

### `Uncertain provider creation`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <MERCHANT_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: 7bf9cef9-0077-4cd1-8ca6-e34add8406d1' -d '{"amount_minor":1000}' 'http://localhost:8000/api/v1/workspaces/01m5z907k07qxtaw1nbw0yacyc/funding/top-ups'
```

```http
HTTP/1.1 502
X-Request-Id: 01a17e90-250b-7026-bee9-445f4175089d
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":502,"code":"funding_creation_uncertain","message":"Payment creation could not be confirmed. Reuse the same request key and review this top-up; do not start another payment automatically.","request_id":"01a17e90-250b-7026-bee9-445f4175089d","details":{"top_up_id":"01m5z9098qbspebggw41mrwgfc"}}}
```

### `Uncertain replay`

```bash
curl -i -X POST -H 'Accept: application/json' -H 'Authorization: Bearer <MERCHANT_TOKEN>' -H 'Content-Type: application/json' -H 'Idempotency-Key: 7bf9cef9-0077-4cd1-8ca6-e34add8406d1' -d '{"amount_minor":1000}' 'http://localhost:8000/api/v1/workspaces/01m5z907k07qxtaw1nbw0yacyc/funding/top-ups'
```

```http
HTTP/1.1 201
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17e90-252c-714c-ac33-fd70e700752f

{"data":{"id":"01m5z9098qbspebggw41mrwgfc","state":"pending","amount_minor":1000,"currency":"EUR","creation_state":"uncertain","checkout_url":null,"paid_at":null}}
```

### `GET /api/v1/workspaces/{workspace}/funding/top-ups/{topUp}`

```bash
curl -i -X GET -H 'Accept: application/json' -H 'Authorization: Bearer <MERCHANT_TOKEN>' 'http://localhost:8000/api/v1/workspaces/01m5z907k07qxtaw1nbw0yacyc/funding/top-ups/01m5z9094dwnh9w2mymph2kqv5'
```

```http
HTTP/1.1 200
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17e90-2544-7364-8196-035ecc981353

{"data":{"id":"01m5z9094dwnh9w2mymph2kqv5","state":"pending","amount_minor":1000,"currency":"EUR","creation_state":"created","checkout_url":"https://example.com/synthetic-checkout","paid_at":null}}
```


## B22a — observed API monitoring (Trello #30)

New `GET /api/v1/admin/monitoring?minutes=5` requires active verified Super Admin, normal MFA and no support impersonation. Returns only bounded aggregate operational metadata, with no request bodies, raw paths/SQL/bindings/headers, customer/workspace IDs or credentials. Window1–60minutes. Exact nearest-rank quantiles describe the selected observations, not estimated production uptime. Explicit sample/route truncation produces UNKNOWN. HEALTHY only describes sufficient observed HTTP traffic; unobserved dependencies remain UNKNOWN. Config, query-plan/benchmark evidence, retention, costs and remaining full-card work: [RELIABILITY.md](../reliability/RELIABILITY.md).

`GET /api/v1/admin/incidents/{incident}` now includes nullable immutable `monitoring_evidence` per occurrence. Scheduler-generated latency/5xx incidents retain the actual observation window, counts, thresholds and P95 without raw requests. Existing audited assignment/resolution APIs are reused. A recurring breach after300s reopens resolved/ignored incidents; unchanged breaches within cooldown do not duplicate occurrences. No automatic financial correction or external notification dispatch is introduced.

Captured2026-10-27 from real local Laravel HTTP, isolated SQLite + file cache/sessions + array mail. Admin MFA was disabled only for this isolated capture, never the main environment. The dataset contains10000 explicitly synthetic observations to exercise real SQL; no production traffic/provider evidence is claimed. [Full captures and local SQL benchmark](../reliability/B22_HTTP_CAPTURE.json).

### unauthenticated — `GET /api/v1/admin/monitoring`

```bash
curl -i -H 'Accept: application/json' 'http://localhost:8000/api/v1/admin/monitoring'
```

```http
HTTP/1.1 401
X-Request-Id: 01a17f0e-de38-7266-9788-1f4ee2096175
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a17f0e-de38-7266-9788-1f4ee2096175"}}
```

### forbidden — `GET /api/v1/admin/monitoring`

```bash
curl -i -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' 'http://localhost:8000/api/v1/admin/monitoring'
```

```http
HTTP/1.1 403
X-Request-Id: 01a17f0e-de90-71ba-b6d3-bebcea09ac49
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"01a17f0e-de90-71ba-b6d3-bebcea09ac49"}}
```

### invalid-window — `GET /api/v1/admin/monitoring?minutes=61`

```bash
curl -i -H 'Accept: application/json' -H 'Authorization: Bearer <ADMIN_TOKEN>' 'http://localhost:8000/api/v1/admin/monitoring?minutes=61'
```

```http
HTTP/1.1 422
X-Request-Id: 01a17f0e-deb9-7199-89a3-1b2de94aac2c
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a17f0e-deb9-7199-89a3-1b2de94aac2c","fields":{"minutes":["The minutes field must not be greater than 60."]}}}
```

### empty-report — `GET /api/v1/admin/monitoring`

```bash
curl -i -H 'Accept: application/json' -H 'Authorization: Bearer <ADMIN_TOKEN>' 'http://localhost:8000/api/v1/admin/monitoring'
```

```http
HTTP/1.1 200
Cache-Control: no-store, private
Content-Type: application/json
X-Request-Id: 01a17f0e-dee5-7055-8965-c99b54780ba9

{"data":{"enabled":true,"state":"UNKNOWN","from":"2026-10-27T20:25:43+00:00","to":"2026-10-27T20:30:43+00:00","sample_limit":50000,"sample_limit_exceeded":false,"routes_truncated":false,"thresholds":{"minimum_samples":20,"error_percent":2,"p95_ms":1000},"routes":[{"route":"api/v1/admin/monitoring","method":"GET","requests":3,"state":"UNKNOWN","breaches":[],"average_ms":43.84,"p50_ms":30.898,"p95_ms":71.519,"p99_ms":71.519,"client_errors":3,"server_errors":0,"client_error_percent":100,"server_error_percent":0,"observed_timeouts":0,"observed_timeout_percent":0,"database":{"queries":54,"total_ms":14.55,"slow_queries":0}}],"coverage":{"http":"observed_requests_only","timeouts":"rendered_408_504_only","queue_wait":"UNKNOWN","provider_failures":"UNKNOWN","dependency_availability":"UNKNOWN","backups":"UNKNOWN"}}}
```

### observed-report — `GET /api/v1/admin/monitoring?minutes=5`

```bash
curl -i -H 'Accept: application/json' -H 'Authorization: Bearer <ADMIN_TOKEN>' 'http://localhost:8000/api/v1/admin/monitoring?minutes=5'
```

```http
HTTP/1.1 200
Cache-Control: no-store, private
Content-Type: application/json
X-Request-Id: 01a17f0e-e04e-7179-8758-7b2e98176a7a

{"data":{"enabled":true,"state":"CRITICAL","from":"2026-10-27T20:25:43+00:00","to":"2026-10-27T20:30:43+00:00","sample_limit":50000,"sample_limit_exceeded":false,"routes_truncated":false,"thresholds":{"minimum_samples":20,"error_percent":2,"p95_ms":1000},"routes":[{"route":"api/v1/admin/monitoring","method":"GET","requests":4,"state":"UNKNOWN","breaches":[],"average_ms":36.453,"p50_ms":29.104,"p95_ms":71.519,"p99_ms":71.519,"client_errors":3,"server_errors":0,"client_error_percent":75,"server_error_percent":0,"observed_timeouts":0,"observed_timeout_percent":0,"database":{"queries":59,"total_ms":17.74,"slow_queries":0}},{"route":"api/v1/health","method":"GET","requests":10000,"state":"CRITICAL","breaches":["api_5xx_rate","api_latency"],"average_ms":404,"p50_ms":5,"p95_ms":2000,"p99_ms":2000,"client_errors":0,"server_errors":1000,"client_error_percent":0,"server_error_percent":10,"observed_timeouts":0,"observed_timeout_percent":0,"database":{"queries":20000,"total_ms":10000,"slow_queries":0}}],"coverage":{"http":"observed_requests_only","timeouts":"rendered_408_504_only","queue_wait":"UNKNOWN","provider_failures":"UNKNOWN","dependency_availability":"UNKNOWN","backups":"UNKNOWN"}}}
```

### incident-evidence — `GET /api/v1/admin/incidents/01m5zgxre50ncab1ddad3xqq0k`

```bash
curl -i -H 'Accept: application/json' -H 'Authorization: Bearer <ADMIN_TOKEN>' 'http://localhost:8000/api/v1/admin/incidents/01m5zgxre50ncab1ddad3xqq0k'
```

```http
HTTP/1.1 200
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17f0e-e3fe-71b3-9619-ef46235e5806

{"data":{"id":"01m5zgxre50ncab1ddad3xqq0k","category":"monitoring","severity":"error","service":"api_monitoring","route":"api/v1/health","method":"GET","http_status":null,"status":"new","assigned_to":null,"occurrences":1,"first_seen_at":"2026-10-27T20:30:44+00:00","last_seen_at":"2026-10-27T20:30:44+00:00"},"history":[],"occurrences":[{"id":"01m5zgxreajyknsajh10wg5jsc","request_id":null,"actor_id":null,"webshop_id":null,"scope_public_id":null,"error_code":"api_5xx_rate","monitoring_evidence":{"from":"2026-10-27T20:25:44+00:00","to":"2026-10-27T20:30:44+00:00","requests":10000,"server_errors":1000,"server_error_percent":10,"p95_ms":2000,"thresholds":{"minimum_samples":20,"error_percent":2,"p95_ms":1000}},"occurred_at":"2026-10-27T20:30:44+00:00"}],"meta":{"current_page":1,"total":1,"per_page":25,"last_page":1}}
```

### rate-limited — `GET /api/v1/admin/monitoring`

```bash
curl -i -H 'Accept: application/json' -H 'Authorization: Bearer <ADMIN_TOKEN>' 'http://localhost:8000/api/v1/admin/monitoring'
```

```http
HTTP/1.1 429
Retry-After: 57
X-Request-Id: 01a17f0e-e99b-709c-a639-2441fa5bc9d8
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":429,"code":"too_many_requests","message":"Too many requests. Please slow down.","request_id":"01a17f0e-e99b-709c-a639-2441fa5bc9d8"}}
```

### storage-failure — `GET /api/v1/admin/monitoring`

```bash
curl -i -H 'Accept: application/json' -H 'Authorization: Bearer <ADMIN_TOKEN>' 'http://localhost:8000/api/v1/admin/monitoring'
```

```http
HTTP/1.1 500
X-Request-Id: 01a17f0e-ed56-72ca-896a-bd4a437f7a2d
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":500,"code":"server_error","message":"An unexpected error occurred.","request_id":"01a17f0e-ed56-72ca-896a-bd4a437f7a2d"}}
```

### health-survives-observation-failure — `GET /api/v1/health`

```bash
curl -i -H 'Accept: application/json' 'http://localhost:8000/api/v1/health'
```

```http
HTTP/1.1 200
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17f0e-edff-723e-8048-9a5b708483b2

{"status":"ok","service":"shopbonus-api","time":"2026-10-27T20:30:47+00:00"}
```


## B22b prerequisite financial evidence — approved policy and repeat-safe wallet checks

Owner confirmed new affiliate rewards spendable only after network approval (D-089). New reward evaluations retain their timing and rule terms; later approval does not switch an existing pending reward to a new rate/regime. Private credit evidence and sponsorship fee-rate snapshots are immutable. Legacy missing evidence is not invented. Wallet reconciliation now locks the wallet and preserves one open mismatch flag with original evidence; it never repairs balances or posts balancing entries. Existing resolution/dual-admin correction controls remain. A whole-platform source reconciliation runner is still unfinished.

`POST /api/v1/admin/affiliate/transactions/{transaction}/resolve`: an approved commission increase beyond the original review threshold re-enters review before spendable credit; authorized acceptance can release the reviewed amount. Non-EUR conversion acceptance returns409 unsupported_reward_currency because no approved FX policy exists. A review acceptance cannot override that currency guard. Existing status/ownership/MFA/password/CSRF validations remain; no arbitrary EUR/points exchange is introduced.

Captured2026-10-27 on isolated SQLite/file-session+cache/array-mail Laravel HTTP, synthetic canonical imports and a separate deliberately corrupted wallet. Admin MFA disabled only for the isolated fixture; actual session/CSRF/password confirmation exercised.1000commission minor units→700pending/nonspendable before approval; a newer90% rule leaves the original credit700 and gives a new conversion900. These are virtual EUR credits, not confirmed cash collection or revenue recognition. [Source evidence/actual responses](../reliability/B22_FINANCIAL_EVIDENCE.json); [scope/legacy limits](../reliability/RELIABILITY.md); [financial findings/fixes](../reliability/FINANCIAL_FINDINGS.md).

### `GET /api/v1/admin/affiliate/transactions/01m5zkksjknh4mxkpp51ckw0p3` — old-transaction

```bash
curl -i -X GET -H 'Accept: application/json' -H 'Authorization: Bearer <ADMIN_TOKEN>' 'http://127.0.0.1:8079/api/v1/admin/affiliate/transactions/01m5zkksjknh4mxkpp51ckw0p3'
```

```http
HTTP/1.1 200
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17f39-e979-704d-896a-fc9c2ed873d5

{"data":{"id":"01m5zkksjknh4mxkpp51ckw0p3","network":"daisycon","external_id":"SYNTHETIC-1","status":"approved","network_status":"approved","review_reason":null,"webshop_id":"01m5zkksh8cjqfttxn2qrs8gr6","consumer_attributed":true,"order_amount_minor":10000,"commission_minor":1000,"currency":"EUR","reward":{"status":"credited","amount":700,"unit":"eur_credit","rule_version":1},"duplicate_of":null,"transaction_at":"2026-10-27T21:17:43+00:00","events":[{"source":"import","from":null,"to":"pending","network_status":"pending","note":null,"at":"2026-10-27T21:17:43+00:00"},{"source":"import","from":"pending","to":"approved","network_status":"approved","note":null,"at":"2026-10-27T21:17:43+00:00"}]}}
```

### `GET /api/v1/admin/wallets/01m5zkksmv1628es7mab5wap0t/ledger` — isolated-mismatch-ledger

```bash
curl -i -X GET -H 'Accept: application/json' -H 'Authorization: Bearer <ADMIN_TOKEN>' 'http://127.0.0.1:8079/api/v1/admin/wallets/01m5zkksmv1628es7mab5wap0t/ledger'
```

```http
HTTP/1.1 200
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17f39-e9dd-71ab-a4cc-3491dfa14e39

{"data":[{"id":"01m5zkksmxjj0dwd4tyw85pej8","type":"credit","unit":"eur_credit","amount":1000,"available_delta":1000,"reserved_delta":0,"available_after":1000,"reserved_after":0,"reason":null,"origin":"system","source_type":null,"created_at":"2026-10-27T21:17:43+00:00"}],"meta":{"current_page":1,"per_page":25,"total":1,"last_page":1}}
```

### `GET /api/v1/admin/wallet-reconciliation-flags?wallet_id=01m5zkksmv1628es7mab5wap0t` — single-open-flag

```bash
curl -i -X GET -H 'Accept: application/json' -H 'Authorization: Bearer <ADMIN_TOKEN>' 'http://127.0.0.1:8079/api/v1/admin/wallet-reconciliation-flags?wallet_id=01m5zkksmv1628es7mab5wap0t'
```

```http
HTTP/1.1 200
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17f39-ea06-70a0-b021-6e4933f83ce7

{"data":[{"id":"01m5zkksn4qftevcrxsk5jmt0p","wallet_id":"01m5zkksmv1628es7mab5wap0t","unit":"eur_credit","reason":"balance_mismatch","amount":0,"status":"open","resolved_at":null,"resolution_reason":null,"resolved_by":null}],"meta":{"current_page":1,"per_page":25,"total":1,"last_page":1}}
```

### `GET /api/v1/admin/wallet-reconciliation-flags` — forbidden-flags

```bash
curl -i -X GET -H 'Accept: application/json' -H 'Authorization: Bearer <CONSUMER_TOKEN>' 'http://127.0.0.1:8079/api/v1/admin/wallet-reconciliation-flags'
```

```http
HTTP/1.1 403
X-Request-Id: 01a17f39-ea1b-71a9-b788-67c63470897f
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":403,"code":"platform_role_required","message":"This action requires a platform administrator.","request_id":"01a17f39-ea1b-71a9-b788-67c63470897f"}}
```

### `POST /login` — fixture-login

```bash
curl -i -X POST -H 'Accept: application/json' -b '<SESSION_COOKIE>' -H 'Origin: http://127.0.0.1:8079' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"email":"admin@finance.example.test","password":"<LOCAL_PASSWORD>"}' 'http://127.0.0.1:8079/login'
```

```http
HTTP/1.1 200
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17f39-ea63-7358-a39a-9f200a99ec18

{"data":{"id":"01m5zkksg9v9qcempa7f5tfp22","first_name":"Synthetic admin","email":"admin@finance.example.test","email_verified":true,"status":"active","onboarding_intent":"consumer","locale":"nl","roles":["super_admin"],"workspaces":[],"destination":"admin","two_factor_enabled":false,"support_session":null}}
```

### `POST /user/confirm-password` — fixture-password-confirm

```bash
curl -i -X POST -H 'Accept: application/json' -b '<SESSION_COOKIE>' -H 'Origin: http://127.0.0.1:8079' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"password":"<LOCAL_PASSWORD>"}' 'http://127.0.0.1:8079/user/confirm-password'
```

```http
HTTP/1.1 201
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17f39-ec4a-72f0-90c2-2dc8418974e3

""
```

### `POST /api/v1/admin/affiliate/transactions/01m5zkkskwk7yzvtt3wabj49yv/resolve` — unsupported-currency-accept

```bash
curl -i -X POST -H 'Accept: application/json' -b '<SESSION_COOKIE>' -H 'Origin: http://127.0.0.1:8079' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"action":"accept","note":"Synthetic check: no FX policy configured"}' 'http://127.0.0.1:8079/api/v1/admin/affiliate/transactions/01m5zkkskwk7yzvtt3wabj49yv/resolve'
```

```http
HTTP/1.1 409
X-Request-Id: 01a17f39-ed39-7006-9c3d-b267805393ba
Cache-Control: no-cache, private
Content-Type: application/json

{"error":{"status":409,"code":"unsupported_reward_currency","message":"Non-EUR conversions need an approved currency conversion policy before acceptance.","request_id":"01a17f39-ed39-7006-9c3d-b267805393ba"}}
```

### `POST /api/v1/admin/affiliate/transactions/01m5zkksm7y1rqtwrk911ptmjk/resolve` — authorized-threshold-accept

```bash
curl -i -X POST -H 'Accept: application/json' -b '<SESSION_COOKIE>' -H 'Origin: http://127.0.0.1:8079' -H 'X-XSRF-TOKEN: <CSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"action":"accept","note":"Synthetic verified increased commission source"}' 'http://127.0.0.1:8079/api/v1/admin/affiliate/transactions/01m5zkksm7y1rqtwrk911ptmjk/resolve'
```

```http
HTTP/1.1 200
Cache-Control: no-cache, private
Content-Type: application/json
X-Request-Id: 01a17f39-ed5c-7071-8333-00ce93d71c35

{"data":{"id":"01m5zkksm7y1rqtwrk911ptmjk","network":"daisycon","external_id":"SYNTHETIC-REVIEW","status":"approved","network_status":"approved","review_reason":"resolved:accept","webshop_id":"01m5zkksh8cjqfttxn2qrs8gr6","consumer_attributed":true,"order_amount_minor":10000,"commission_minor":2000,"currency":"EUR","reward":{"status":"credited","amount":1400,"unit":"eur_credit","rule_version":3},"duplicate_of":null,"transaction_at":"2026-10-27T21:17:43+00:00"}}
```

## #30 reconciliation and operations — actual HTTP capture, 2026-10-28
Captured against a real local PHP server and isolated synthetic SQLite/cache/session database; array mail, providers off, MFA disabled only in this fixture. Normal browser login/password confirmation and seeded OTP verification ran. Synthetic mismatch/failed-run/missing-table injection and isolated cache reset are explicitly test-only. Full sanitized evidence: [B22_COMPLETION_HTTP_CAPTURE.json](../reliability/B22_COMPLETION_HTTP_CAPTURE.json). No frontend implementation or production claim.
Super Admin/MFA and support-session denial apply to every operation. Writes need a fresh SPA password and OTP, CSRF, and at most three requests/minute. Use stable UUID request_key per admin; replays return the original run, no new corrections. Scheduler advances bounded100-source batches; sources are observed separately at their checked_at timestamps. Legacy wallet-only runs keep scope_version1; new runs use2. Missing/empty/legacy proof stays explicit. Findings never repair balances or recognize cash/revenue. Read pagination defaults25/max100; page>=1; details optionally filter status=matched/discrepancy/unknown. Resume requires a failed run and a5–500-character reason; other states409. Operators configure the backup key/destination/separate restore server; no backup/restore HTTP mutation exists.

### `GET /api/v1/admin/reconciliation` — List reconciliation runs
```bash
curl -i -X GET -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' 'http://localhost:8000/api/v1/admin/reconciliation'
```
```http
HTTP/1.1 200
Content-Type: application/json

{"data": [{"run": "01m60xt37mxxr2cajczf579ma8", "scope_version": 2, "execution": "completed", "status": "CRITICAL", "requested_at": "2026-10-28T09:35:10+00:00", "finished_at": "2026-10-28T09:35:10+00:00", "elapsed_including_scheduling_ms": 0, "checked_wallets": 1, "discrepancies": 1, "unknown_checks": 1, "coverage": {"wallet_projection": "OBSERVED", "affiliate": "EMPTY", "checkout": "EMPTY", "funding": "EMPTY", "sponsorship": "EMPTY", "ledger": "OBSERVED"}, "scope": "Individually observed local financial invariants; not independent provider settlement, recognized revenue or a global point-in-time proof."}], "meta": {"page": 1, "total": 1}}
```

### `POST /api/v1/admin/reconciliation` — Request an idempotent reconciliation run
```bash
curl -i -X POST -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' --data '{"request_key":"90c078d6-1573-467e-9d36-860cc84b72d2"}' 'http://localhost:8000/api/v1/admin/reconciliation'
```
```http
HTTP/1.1 202
Content-Type: application/json

{"data": {"run": "01m60xt37mxxr2cajczf579ma8", "scope_version": 2, "execution": "pending", "status": "UNKNOWN", "requested_at": "2026-10-28T09:35:10+00:00", "finished_at": null, "elapsed_including_scheduling_ms": 72, "checked_wallets": 0, "discrepancies": 0, "unknown_checks": 0, "coverage": {"wallet_projection": "INCOMPLETE", "affiliate": "UNKNOWN", "checkout": "UNKNOWN", "funding": "UNKNOWN", "sponsorship": "UNKNOWN", "ledger": "UNKNOWN"}, "scope": "Individually observed local financial invariants; not independent provider settlement, recognized revenue or a global point-in-time proof."}}
```

### `GET /api/v1/admin/reconciliation/{run}` — Inspect immutable source checks
```bash
curl -i -X GET -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' 'http://localhost:8000/api/v1/admin/reconciliation/01m60xt37mxxr2cajczf579ma8?status=discrepancy'
```
```http
HTTP/1.1 200
Content-Type: application/json

{"data": {"run": "01m60xt37mxxr2cajczf579ma8", "scope_version": 2, "execution": "completed", "status": "CRITICAL", "requested_at": "2026-10-28T09:35:10+00:00", "finished_at": "2026-10-28T09:35:10+00:00", "elapsed_including_scheduling_ms": 0, "checked_wallets": 1, "discrepancies": 1, "unknown_checks": 1, "coverage": {"wallet_projection": "OBSERVED", "affiliate": "EMPTY", "checkout": "EMPTY", "funding": "EMPTY", "sponsorship": "EMPTY", "ledger": "OBSERVED"}, "scope": "Individually observed local financial invariants; not independent provider settlement, recognized revenue or a global point-in-time proof."}, "checks": [{"id": "01m60xt3gsh82cd9p6kzayj2nc", "source_type": "wallet", "source_ref": "01m60xt19ttzdj91yb9frh9v83", "status": "discrepancy", "evidence": {"version": 1, "unit": "eur_credit", "kind": "consumer_credit", "projection": {"available": 1400, "reserved": 0, "pending": 0}, "mismatches": {"available": [1000, 1400], "lots": [1000, 1400]}, "flag": "01m60xt3ge3km27rrc38xyygnf"}, "checked_at": "2026-10-28T09:35:10.000000Z"}], "meta": {"page": 1, "total": 1}, "corrections": "Use existing reviewed wallet adjustment, affiliate review and checkout/dispute workflows. This report never posts corrections."}
```

### `POST /api/v1/admin/reconciliation/{run}/resume` — Resume an investigated failed run
```bash
curl -i -X POST -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' --data '{"reason":"Synthetic failure investigated; fixture storage restored."}' 'http://localhost:8000/api/v1/admin/reconciliation/01m60xt37mxxr2cajczf579ma8/resume'
```
```http
HTTP/1.1 202
Content-Type: application/json

{"data": {"run": "01m60xt37mxxr2cajczf579ma8", "scope_version": 2, "execution": "running", "status": "CRITICAL", "requested_at": "2026-10-28T09:35:10+00:00", "finished_at": "2026-10-28T09:35:10+00:00", "elapsed_including_scheduling_ms": 0, "checked_wallets": 1, "discrepancies": 1, "unknown_checks": 1, "coverage": {"wallet_projection": "INCOMPLETE", "affiliate": "EMPTY", "checkout": "EMPTY", "funding": "EMPTY", "sponsorship": "EMPTY", "ledger": "OBSERVED"}, "scope": "Individually observed local financial invariants; not independent provider settlement, recognized revenue or a global point-in-time proof."}}
```

### `GET /api/v1/admin/operations-overview` — Observe platform operations
```bash
curl -i -X GET -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' 'http://localhost:8000/api/v1/admin/operations-overview'
```
```http
HTTP/1.1 200
Content-Type: application/json

{"data": {"observed_at": "2026-10-28T09:35:10+00:00", "components": {"database": {"status": "HEALTHY", "observed_at": "2026-10-28T09:35:10+00:00"}, "cache": {"status": "HEALTHY", "observed_at": "2026-10-28T09:35:10+00:00"}, "api": {"enabled": false, "state": "UNKNOWN", "from": "2026-10-28T09:30:10+00:00", "to": "2026-10-28T09:35:10+00:00", "sample_limit": 50000, "sample_limit_exceeded": false, "routes_truncated": false, "thresholds": {"minimum_samples": 20, "error_percent": 2, "p95_ms": 1000}, "routes": [], "coverage": {"http": "observed_requests_only", "timeouts": "rendered_408_504_only", "queue_wait": "UNKNOWN", "provider_failures": "UNKNOWN", "dependency_availability": "UNKNOWN", "backups": "UNKNOWN"}}, "queue": {"status": "UNKNOWN", "reason": "No observable database/redis background queue configured."}, "providers": {"status": "UNKNOWN", "failed_affiliate_connections": 0, "http_observations": {"http_status": "UNKNOWN", "http_requests": 0, "http_failures": 0, "http_sample_limit_exceeded": false, "by_provider": []}, "definition": "Persisted affiliate errors plus opt-in HTTP observations; no independent provider availability proof."}, "financial": {"run": "01m60xt37mxxr2cajczf579ma8", "scope_version": 2, "execution": "completed", "status": "CRITICAL", "requested_at": "2026-10-28T09:35:10+00:00", "finished_at": "2026-10-28T09:35:10+00:00", "elapsed_including_scheduling_ms": 0, "checked_wallets": 1, "discrepancies": 1, "unknown_checks": 1, "coverage": {"wallet_projection": "OBSERVED", "affiliate": "EMPTY", "checkout": "EMPTY", "funding": "EMPTY", "sponsorship": "EMPTY", "ledger": "OBSERVED"}, "scope": "Individually observed local financial invariants; not independent provider settlement, recognized revenue or a global point-in-time proof."}, "scheduler": {"status": "UNKNOWN", "last_observed_at": null}, "incidents": {"status": "WARNING", "open": 7}, "backups": {"status": "UNKNOWN", "backup_success_percent_30d": null, "enabled": false, "last_backup_at": null, "last_backup_result": null, "last_verified_restore_at": null, "offsite_verified": false, "definition": "Successful configured-disk write is not independent offsite durability or RPO/RTO proof."}}, "links": {"monitoring": "/api/v1/admin/monitoring", "incidents": "/api/v1/admin/incidents", "financial": "/api/v1/admin/reconciliation"}, "limits": "Local observations only. Missing data is UNKNOWN; a successful probe is not uptime, offsite durability or provider settlement proof."}}
```

Actual negative requests (synthetic fixture only):
- `401 GET /api/v1/admin/reconciliation`: `{"error": {"status": 401, "code": "unauthenticated", "message": "Authentication is required.", "request_id": "01a181dd-0846-71b4-bae4-1a75b1dcaef1"}}`
- `403 GET /api/v1/admin/reconciliation`: `{"error": {"status": 403, "code": "platform_role_required", "message": "This action requires a platform administrator.", "request_id": "01a181dd-087e-7306-a50b-2a4ecb926745"}}`
- `404 GET /api/v1/admin/reconciliation/missing`: `{"error": {"status": 404, "code": "not_found", "message": "The requested resource was not found.", "request_id": "01a181dd-0fe2-7304-8081-39985f1378dd"}}`
- `409 POST /api/v1/admin/reconciliation/01m60xt37mxxr2cajczf579ma8/resume`: `{"error": {"status": 409, "code": "reconciliation_not_failed", "message": "Only failed reconciliation runs can be resumed.", "request_id": "01a181dd-0fc3-706d-bdea-e6ee508ea01e"}}`
- `419 POST /api/v1/admin/reconciliation`: `{"error": {"status": 419, "code": "csrf_token_mismatch", "message": "The session or CSRF token has expired. Refresh and try again.", "request_id": "01a181dd-0aa5-7055-8b86-f59af0c22be1"}}`
- `422 GET /api/v1/admin/reconciliation/01m60xt37mxxr2cajczf579ma8?per_page=101`: `{"error": {"status": 422, "code": "validation_failed", "message": "The given data was invalid.", "request_id": "01a181dd-0e73-7195-a674-9fc3c9941023", "fields": {"per_page": ["The per page field must not be greater than 100."]}}}`
- `423 POST /api/v1/admin/reconciliation`: `{"error": {"status": 423, "code": "step_up_code_required", "message": "Confirm this action with the code sent to your email.", "request_id": "01a181dd-0ba8-7024-9dcc-009a82c816df"}}`
- `429 POST /api/v1/admin/reconciliation`: `{"error": {"status": 429, "code": "too_many_requests", "message": "Too many requests. Please slow down.", "request_id": "01a181dd-0d1e-72a7-8cb7-02498bb059e8"}}`
- `500 GET /api/v1/admin/reconciliation`: `{"error": {"status": 500, "code": "server_error", "message": "An unexpected error occurred.", "request_id": "01a181dd-10db-7217-b5c5-29e09fb28902"}}`

## #31 B23a — WooCommerce read API and capability profiles (2026-10-28)
Actual local PHP HTTP server, isolated synthetic SQLite/cache/session, array mail. Provider HTTP and DNS are temporary fixtures; no real WooCommerce call/store verification. Credentials/generated webhooks are redacted. [Capture artifact](../integrations/B23_WOO_HTTP_CAPTURE.json), [limits and handoff](../integrations/WOO_READ_API.md). Existing webhook-only connections remain compatible. API testing requires integrations.manage and does not change connection status or import an order.

### `GET /api/v1/integrations/platforms` — B23a capture
```bash
curl -i -X GET -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' 'http://localhost:8000/api/v1/integrations/platforms'
```
```http
HTTP/1.1 200
Content-Type: application/json

{"data": [{"platform": "woocommerce", "name": "WooCommerce", "priority": "production", "available": true, "method": "webhook", "auth": "shared secret (HMAC-SHA256, base64)", "reliability": "high", "limitations": [], "verification": "unverified", "profile": {"api_version": "wc/v3", "reviewed_at": "2026-10-28", "last_live_verified_at": null, "native_read_api_implemented": true, "auth_method": "HTTPS HTTP Basic consumer key/secret; read-only merchant key", "scopes": ["read"], "webhook_topics": ["order.created", "order.updated"], "order_api": "GET /wp-json/wc/v3/orders/{id}", "refund_api": "GET /wp-json/wc/v3/orders/{id}/refunds", "product_api": "GET /wp-json/wc/v3/products", "coupon_api": "GET /wp-json/wc/v3/coupons", "banner_method": "manual; native WordPress publication/module not implemented", "rate_limit_policy": "No redirects/retries;8second reads,1MiB body,100rows/page,5order pages;429cooldown/3failure circuit.", "sources": ["https://developer.woocommerce.com/docs/apis/rest-api/", "https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/", "https://developer.woocommerce.com/docs/apis/rest-api/v3/order-refunds/", "https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks/"]}, "blocked_reason": null}, {"platform": "shopify", "name": "Shopify", "priority": "production", "available": true, "method": "webhook", "auth": "webhook signing secret (HMAC-SHA256, base64)", "reliability": "high", "limitations": ["customer fields may be redacted without protected customer data access"], "verification": "unverified", "profile": {"api_version": "2026-10", "reviewed_at": "2026-10-28", "last_live_verified_at": null, "native_read_api_implemented": false, "auth_method": "Signing secret only in current implementation; OAuth/GraphQL not yet implemented", "scopes": [], "webhook_topics": ["orders/create", "orders/updated", "orders/paid", "orders/cancelled", "refunds/create"], "banner_method": "Theme App Extension requires separate implementation and merchant activation", "sources": ["https://shopify.dev/docs/api/admin-graphql/2026-10", "https://shopify.dev/docs/apps/build/webhooks"]}, "blocked_reason": null}, {"platform": "wix", "name": "Wix", "priority": "production", "available": false, "method": "automation HTTP POST", "auth": "to be determined (verify whether automations can sign or authenticate requests)", "reliability": "unknown", "limitations": [], "verification": "unverified", "profile": null, "blocked_reason": "Request authentication for Wix automations not yet verified."}, {"platform": "bigcommerce", "name": "BigCommerce", "priority": "production", "available": false, "method": "webhook + API lookup", "auth": "restricted API token", "reliability": "unknown", "limitations": [], "verification": "unverified", "profile": null, "blocked_reason": "Requires API credentials and order lookup (webhooks carry only IDs)."}, {"platform": "ecwid", "name": "Ecwid", "priority": "production", "available": false, "method": "webhook/polling + API", "auth": "store API token", "reliability": "unknown", "limitations": [], "verification": "unverified", "profile": null, "blocked_reason": "Requires store API token and app registration."}, {"platform": "lightspeed_c", "name": "Lightspeed C-Series", "priority": "production", "available": false, "method": "confirmation tracking where supported", "auth": "to be determined", "reliability": "lower", "limitations": ["lower reliability"], "verification": "unverified", "profile": null, "blocked_reason": "Supported mechanism not yet verified."}, {"platform": "lightspeed_e", "name": "Lightspeed E-Series", "priority": "additional", "available": false, "method": "API/confirmation (to be determined)", "auth": "to be determined", "reliability": "unknown", "limitations": [], "verification": "unverified", "profile": null, "blocked_reason": "Supported API method not yet verified."}, {"platform": "prestashop", "name": "PrestaShop", "priority": "additional", "available": true, "method": "private_module", "auth": "Shopbonus signed webhook v1", "reliability": "high (module not yet built)", "limitations": ["private ZIP module must be installed"], "verification": "unverified", "profile": null, "blocked_reason": null}, {"platform": "opencart", "name": "OpenCart", "priority": "additional", "available": true, "method": "private_module", "auth": "Shopbonus signed webhook v1", "reliability": "high (module not yet built)", "limitations": ["private OCMOD extension must be installed"], "verification": "unverified", "profile": null, "blocked_reason": null}, {"platform": "shopware6", "name": "Shopware 6", "priority": "additional", "available": true, "method": "private_module", "auth": "Shopbonus signed webhook v1", "reliability": "high (extension not yet built)", "limitations": ["private extension must be installed"], "verification": "unverified", "profile": null, "blocked_reason": null}, {"platform": "magento", "name": "Magento / Adobe Commerce", "priority": "additional", "available": true, "method": "private_module", "auth": "Shopbonus signed webhook v1", "reliability": "high (module not yet built)", "limitations": ["private module must be installed"], "verification": "unverified", "profile": null, "blocked_reason": null}, {"platform": "squarespace", "name": "Squarespace", "priority": "additional", "available": false, "method": "polling (read-only Orders API)", "auth": "API key", "reliability": "polling delay", "limitations": ["polling"], "verification": "unverified", "profile": null, "blocked_reason": "Polling adapter not built; requires API key."}, {"platform": "mijnwebwinkel", "name": "Mijnwebwinkel", "priority": "additional", "available": false, "method": "partner API", "auth": "partner authorisation", "reliability": "unknown", "limitations": [], "verification": "unverified", "profile": null, "blocked_reason": "Partner/API authorisation pending."}, {"platform": "ccv", "name": "CCV Shop", "priority": "additional", "available": false, "method": "to be investigated", "auth": "to be determined", "reliability": "unknown", "limitations": [], "verification": "unverified", "profile": null, "blocked_reason": "Merchant-accessible API options not yet investigated."}, {"platform": "other", "name": "Other / custom shop", "priority": "additional", "available": true, "method": "webhook", "auth": "Shopbonus signed webhook v1", "reliability": "depends on the custom integration", "limitations": [], "verification": "unverified", "profile": null, "blocked_reason": null}]}
```

### `POST /api/v1/workspaces/{workspace}/connections` — B23a capture
```bash
curl -i -X POST -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' --data '{"platform":"woocommerce","store_url":"https://fietsen-utrecht.nl","consumer_key":"<LOCAL_CONSUMER_KEY>","consumer_secret":"<LOCAL_CONSUMER_SECRET>"}' 'http://localhost:8000/api/v1/workspaces/01m6102yjss303ctz63gsbsd8e/connections'
```
```http
HTTP/1.1 201
Content-Type: application/json

{"data": {"id": "01m6102zv4nrrhpsnmvm4c8q2v", "platform": "woocommerce", "method": "webhook", "status": "setup_required", "external_shop_ref": "https://fietsen-utrecht.nl", "limitations": [], "last_event_at": null, "last_verified_order_at": null, "connected_at": null, "disconnected_at": null, "last_error": null, "created_at": "2026-10-28T10:14:58+00:00", "setup": {"format": "woocommerce-webhooks", "webhook_url": "http://127.0.0.1:8094/api/v1/webhooks/woocommerce/<WEBHOOK_TOKEN>", "read_api_configured": true, "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.", "steps": ["In WordPress go to WooCommerce → Settings → Advanced → Webhooks → Add webhook.", "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.", "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.", "Save, then place a real paid test order."]}, "secrets": {"webhook_secret": "<GENERATED_SECRET>"}, "secrets_notice": "Shown only once. Store it in your shop settings now; it cannot be retrieved later."}}
```

### `GET /api/v1/workspaces/{workspace}/connections/{connection}` — B23a capture
```bash
curl -i -X GET -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' 'http://localhost:8000/api/v1/workspaces/01m6102yjss303ctz63gsbsd8e/connections/01m6102zv4nrrhpsnmvm4c8q2v'
```
```http
HTTP/1.1 200
Content-Type: application/json

{"data": {"id": "01m6102zv4nrrhpsnmvm4c8q2v", "platform": "woocommerce", "method": "webhook", "status": "setup_required", "external_shop_ref": "https://fietsen-utrecht.nl", "limitations": [], "last_event_at": null, "last_verified_order_at": null, "connected_at": null, "disconnected_at": null, "last_error": null, "created_at": "2026-10-28T10:14:58+00:00", "setup": {"format": "woocommerce-webhooks", "webhook_url": "http://127.0.0.1:8094/api/v1/webhooks/woocommerce/<WEBHOOK_TOKEN>", "read_api_configured": true, "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.", "steps": ["In WordPress go to WooCommerce → Settings → Advanced → Webhooks → Add webhook.", "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.", "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.", "Save, then place a real paid test order."]}}}
```

### `POST /api/v1/workspaces/{workspace}/connections/{connection}/credentials` — B23a capture
```bash
curl -i -X POST -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' --data '{"store_url":"https://fietsen-utrecht.nl","consumer_key":"<LOCAL_CONSUMER_KEY>","consumer_secret":"<LOCAL_CONSUMER_SECRET>"}' 'http://localhost:8000/api/v1/workspaces/01m6102yjss303ctz63gsbsd8e/connections/01m6102zv4nrrhpsnmvm4c8q2v/credentials'
```
```http
HTTP/1.1 200
Content-Type: application/json

{"data": {"id": "01m6102zv4nrrhpsnmvm4c8q2v", "platform": "woocommerce", "method": "webhook", "status": "setup_required", "external_shop_ref": "https://fietsen-utrecht.nl", "limitations": [], "last_event_at": null, "last_verified_order_at": null, "connected_at": null, "disconnected_at": null, "last_error": null, "created_at": "2026-10-28T10:14:58+00:00", "setup": {"format": "woocommerce-webhooks", "webhook_url": "http://127.0.0.1:8094/api/v1/webhooks/woocommerce/<WEBHOOK_TOKEN>", "read_api_configured": true, "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.", "steps": ["In WordPress go to WooCommerce → Settings → Advanced → Webhooks → Add webhook.", "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.", "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.", "Save, then place a real paid test order."]}, "secrets": {"webhook_secret": "<GENERATED_SECRET>"}, "secrets_notice": "Shown only once. Store it in your shop settings now; it cannot be retrieved later."}}
```

### `POST /api/v1/workspaces/{workspace}/connections/{connection}/test-api` — B23a capture
```bash
curl -i -X POST -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' --data '{"order_id":"4711"}' 'http://localhost:8000/api/v1/workspaces/01m6102yjss303ctz63gsbsd8e/connections/01m6102zv4nrrhpsnmvm4c8q2v/test-api'
```
```http
HTTP/1.1 200
Content-Type: application/json

{"data": {"connection": "01m6102zv4nrrhpsnmvm4c8q2v", "platform": "woocommerce", "result": {"ok": true, "code": "api_order_and_refunds_read_observed"}, "order_summary": {"kind": "order", "complete": true, "missing_fields": [], "has_phone": false, "payment_status": "partially_refunded", "currency": "EUR", "amount_minor": 5995}, "state_changed": false, "production_verified": false}}
```

Active API test errors:401authentication;403manage permission;404other workspace/missing connection;409setup/auth/address/disconnect/capability/credential-change;419CSRF;422invalid ID/input;429Shopbonus throttle;502provider/redirect/schema/size failure;503provider rate/circuit pause;500local unexpected storage/audit failure. These actual captures never reproduce upstream bodies or credentials.
- `401`: `{"error": {"status": 401, "code": "unauthenticated", "message": "Authentication is required.", "request_id": "01a18201-7caf-70b1-99e6-e6c0e5f079de"}}`
- `419`: `{"error": {"status": 419, "code": "csrf_token_mismatch", "message": "The session or CSRF token has expired. Refresh and try again.", "request_id": "01a18201-7f90-70f8-9f6e-ff3a5cc0c076"}}`
- `422`: `{"error": {"status": 422, "code": "validation_failed", "message": "The given data was invalid.", "request_id": "01a18201-800f-7278-8b52-cc91f628ad0c", "fields": {"order_id": ["The order id field format is invalid."]}}}`
- `429`: `{"error": {"status": 429, "code": "too_many_requests", "message": "Too many requests. Please slow down.", "request_id": "01a18201-804d-7003-90d0-f1b63828729c"}}`
- `403`: `{"error": {"status": 403, "code": "workspace_permission_denied", "message": "You do not have permission to do this in this workspace.", "request_id": "01a18201-8068-72f4-96c7-d4b3c0e49cbb", "details": {"required_permission": "integrations.manage"}}}`
- `404`: `{"error": {"status": 404, "code": "not_found", "message": "The requested resource was not found.", "request_id": "01a18201-808e-71ff-a431-b9233c04b791"}}`
- `409`: `{"error": {"status": 409, "code": "capability_not_supported", "message": "This platform connection does not support an active connection test (send a real order instead).", "request_id": "01a18201-8190-7000-9c53-ca9e6941513d", "details": {"capability": "an active connection test (send a real order instead)"}}}`
- `409`: `{"error": {"status": 409, "code": "provider_authentication_failed", "message": "The provider rejected these credentials or read permissions.", "request_id": "01a18201-82ae-7356-982a-b0f02bd08d8e"}}`
- `503`: `{"error": {"status": 503, "code": "provider_rate_limited", "message": "The provider API rate limit was reached.", "request_id": "01a18201-83b2-70b8-b30b-bab337668ad7"}}`
- `502`: `{"error": {"status": 502, "code": "provider_response_invalid", "message": "The provider response could not be read.", "request_id": "01a18201-84b9-703f-9c56-0f7817c85f72"}}`
- `502`: `{"error": {"status": 502, "code": "provider_read_failed", "message": "The provider API did not return a successful read response.", "request_id": "01a18201-85bf-71f3-be9e-4c3700c10c6b"}}`
- `409`: `{"error": {"status": 409, "code": "provider_address_refused", "message": "The store domain must resolve only to public addresses.", "request_id": "01a18201-86c3-718b-a4a8-7a0051b2f5b1"}}`
- `500`: `{"error": {"status": 500, "code": "server_error", "message": "An unexpected error occurred.", "request_id": "01a18201-88a6-720c-8b89-2e7e0e6b0376"}}`


## B23b — Shopify native read API checkpoint

Captured against an actual local PHP HTTP server with isolated SQLite, database cache, file sessions and normal login/CSRF. Base `161051e` plus B23b working tree. Provider HTTP and public DNS were synthetic fixtures; these are application API observations, not live Shopify acceptance. Raw request/status/header/body evidence: [B23_SHOPIFY_HTTP_CAPTURE.json](../integrations/B23_SHOPIFY_HTTP_CAPTURE.json). [Scope and mapping](../integrations/SHOPIFY_READ_API.md). Existing B23a security/error examples remain applicable to the shared endpoint.

### POST `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections` — create

Request (normal SPA session and CSRF headers required):

```json
{
  "platform": "shopify",
  "shop_domain": "fietsen.myshopify.com",
  "signing_secret": "<REDACTED>",
  "access_token": "<REDACTED>"
}
```

Actual response 201:

```json
{
  "data": {
    "id": "01m61263rs4717r9f5zs21dc1c",
    "platform": "shopify",
    "method": "webhook",
    "status": "setup_required",
    "external_shop_ref": "fietsen.myshopify.com",
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T10:51:38+00:00",
    "setup": {
      "format": "shopify-webhooks",
      "webhook_url": "<REDACTED>",
      "shop_domain": "fietsen.myshopify.com",
      "read_api_configured": true,
      "read_api_notice": "Optional approved app access token enables pinned GraphQL reads. OAuth install/refresh and live readiness are separate capabilities.",
      "steps": [
        "In Shopify admin open Settings \u2192 Notifications \u2192 Webhooks and create webhooks (JSON) for \"Order creation\", \"Order payment\", \"Order update\" and \"Order cancellation\" to the webhook URL.",
        "Copy the signing secret shown on that page into this connection.",
        "Place a genuine paid test order. Shopify may hide customer details; if so the connection shows \"Connected with limitations\" until protected customer data access is arranged."
      ]
    }
  }
}
```

### GET `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections/01m61263rs4717r9f5zs21dc1c` — show

Request (normal SPA session and CSRF headers required):

```json
null
```

Actual response 200:

```json
{
  "data": {
    "id": "01m61263rs4717r9f5zs21dc1c",
    "platform": "shopify",
    "method": "webhook",
    "status": "setup_required",
    "external_shop_ref": "fietsen.myshopify.com",
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T10:51:38+00:00",
    "setup": {
      "format": "shopify-webhooks",
      "webhook_url": "<REDACTED>",
      "shop_domain": "fietsen.myshopify.com",
      "read_api_configured": true,
      "read_api_notice": "Optional approved app access token enables pinned GraphQL reads. OAuth install/refresh and live readiness are separate capabilities.",
      "steps": [
        "In Shopify admin open Settings \u2192 Notifications \u2192 Webhooks and create webhooks (JSON) for \"Order creation\", \"Order payment\", \"Order update\" and \"Order cancellation\" to the webhook URL.",
        "Copy the signing secret shown on that page into this connection.",
        "Place a genuine paid test order. Shopify may hide customer details; if so the connection shows \"Connected with limitations\" until protected customer data access is arranged."
      ]
    }
  }
}
```

### POST `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections/01m61263rs4717r9f5zs21dc1c/test-api` — identity

Request (normal SPA session and CSRF headers required):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "connection": "01m61263rs4717r9f5zs21dc1c",
    "platform": "shopify",
    "result": {
      "ok": true,
      "code": "api_shop_identity_and_order_scope_observed"
    },
    "order_summary": null,
    "state_changed": false,
    "production_verified": false
  }
}
```

### POST `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections/01m61263rs4717r9f5zs21dc1c/test-api` — order

Request (normal SPA session and CSRF headers required):

```json
{
  "order_id": "4711"
}
```

Actual response 200:

```json
{
  "data": {
    "connection": "01m61263rs4717r9f5zs21dc1c",
    "platform": "shopify",
    "result": {
      "ok": true,
      "code": "api_order_and_refunds_read_observed"
    },
    "order_summary": {
      "kind": "order",
      "complete": true,
      "missing_fields": [],
      "has_phone": false,
      "payment_status": "partially_refunded",
      "currency": "EUR",
      "amount_minor": 5995
    },
    "state_changed": false,
    "production_verified": false
  }
}
```

### POST `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections/01m61263rs4717r9f5zs21dc1c/test-api` — protected

Request (normal SPA session and CSRF headers required):

```json
{
  "order_id": "4711"
}
```

Actual response 200:

```json
{
  "data": {
    "connection": "01m61263rs4717r9f5zs21dc1c",
    "platform": "shopify",
    "result": {
      "ok": true,
      "code": "api_order_and_refunds_read_observed"
    },
    "order_summary": {
      "kind": "order",
      "complete": false,
      "missing_fields": [
        "email"
      ],
      "has_phone": false,
      "payment_status": "partially_refunded",
      "currency": "EUR",
      "amount_minor": 5995
    },
    "state_changed": false,
    "production_verified": false
  }
}
```

### POST `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections/01m61263rs4717r9f5zs21dc1c/test-api` — foreign

Request (normal SPA session and CSRF headers required):

```json
{}
```

Actual response 409:

```json
{
  "error": {
    "status": 409,
    "code": "store_identity_unverified",
    "message": "Verify ownership of the exact store domain before using its API.",
    "request_id": "01a18223-10c8-701d-a1c9-2d39b0fb20ec"
  }
}
```

### POST `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections/01m61263rs4717r9f5zs21dc1c/test-api` — scope

Request (normal SPA session and CSRF headers required):

```json
{}
```

Actual response 409:

```json
{
  "error": {
    "status": 409,
    "code": "provider_scope_missing",
    "message": "The provider has not proved the required read scopes.",
    "request_id": "01a18223-11dd-736f-8cd5-57fd9285edf6"
  }
}
```

### POST `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections/01m61263rs4717r9f5zs21dc1c/test-api` — version

Request (normal SPA session and CSRF headers required):

```json
{}
```

Actual response 409:

```json
{
  "error": {
    "status": 409,
    "code": "provider_version_mismatch",
    "message": "The provider API version differs from the reviewed version.",
    "request_id": "01a18223-12ed-73be-b76b-7b6f5b2fc49b"
  }
}
```

### POST `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections/01m61263rs4717r9f5zs21dc1c/test-api` — throttle

Request (normal SPA session and CSRF headers required):

```json
{}
```

Actual response 503:

```json
{
  "error": {
    "status": 503,
    "code": "provider_rate_limited",
    "message": "The provider API rate limit was reached.",
    "request_id": "01a18223-13f7-7209-a628-55215b095f80"
  }
}
```

### POST `/api/v1/workspaces/01m612620xm2kaaya5fd4xf895/connections/01m61263rs4717r9f5zs21dc1c/credentials` — credentials

Request (normal SPA session and CSRF headers required):

```json
{
  "shop_domain": "fietsen.myshopify.com",
  "signing_secret": "<REDACTED>",
  "access_token": "<REDACTED>"
}
```

Actual response 200:

```json
{
  "data": {
    "id": "01m61263rs4717r9f5zs21dc1c",
    "platform": "shopify",
    "method": "webhook",
    "status": "setup_required",
    "external_shop_ref": "fietsen.myshopify.com",
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T10:51:38+00:00",
    "setup": {
      "format": "shopify-webhooks",
      "webhook_url": "<REDACTED>",
      "shop_domain": "fietsen.myshopify.com",
      "read_api_configured": true,
      "read_api_notice": "Optional approved app access token enables pinned GraphQL reads. OAuth install/refresh and live readiness are separate capabilities.",
      "steps": [
        "In Shopify admin open Settings \u2192 Notifications \u2192 Webhooks and create webhooks (JSON) for \"Order creation\", \"Order payment\", \"Order update\" and \"Order cancellation\" to the webhook URL.",
        "Copy the signing secret shown on that page into this connection.",
        "Place a genuine paid test order. Shopify may hide customer details; if so the connection shows \"Connected with limitations\" until protected customer data access is arranged."
      ]
    }
  }
}
```

`GET /api/v1/integrations/platforms`: current full catalogue response is in the raw artifact and OpenAPI (including honest unsupported/live-unverified flags).


## B23c — credential-bound connection lifecycle

Captured on an actual local PHP HTTP server, isolated SQLite/database cache/file sessions, normal login + CSRF + password confirmation and seeded synthetic step-up OTP. Admin MFA disabled only for this isolated capture; normal configuration is unchanged. Queue uses isolated database; fixture worker calls the actual event processor. An explicit capture-only connector hook rotates credentials after verifying a real synthetic HMAC to reproduce intake409. No live provider evidence. Base `bc2f739` plus B23c worktree. Raw [16 HTTP captures](../integrations/B23_CREDENTIAL_HTTP_CAPTURE.json); [lifecycle and rollout guide](../integrations/CREDENTIAL_LIFECYCLE.md). Secrets/URLs are redacted.

Credential replacement returns `setup_required` with current readiness cleared. Old events remain in history, but cannot serve as current proof or be retried. The existing shared security/error examples still apply. Full seven-gate promotion remains subsequent work.

### POST `/api/v1/webhooks/woocommerce/<WEBHOOK_TOKEN>` — accepted

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
{
  "id": 4711,
  "number": "4711",
  "status": "processing",
  "currency": "EUR",
  "total": "59.95",
  "date_created_gmt": "2026-10-28T10:00:00",
  "date_modified_gmt": "2026-10-28T10:01:00",
  "date_paid_gmt": "2026-10-28T10:01:00",
  "billing": {
    "first_name": "Sample",
    "email": "sample@example.test",
    "phone": null
  },
  "refunds": []
}
```

Actual response 202:

```json
{
  "data": {
    "received": true,
    "duplicate": false,
    "event_id": "01m613rewzp0czd0jpb7eg8sd5"
  }
}
```

### POST `/api/v1/workspaces/01m613rcypqmyntqabv0e57c6q/connections/01m613rew0sgkb6m1svdjpawcx/verify` — verify_before

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "status": "connected",
    "checks": {
      "credentials_configured": true,
      "event_received": true,
      "order_received": true,
      "mandatory_fields_present": true,
      "phone_available": false,
      "webshop_ownership_verified": false
    },
    "missing_fields": [],
    "limitations": [],
    "next_action": "verify_ownership"
  }
}
```

### GET `/api/v1/workspaces/01m613rcypqmyntqabv0e57c6q/connections/01m613rew0sgkb6m1svdjpawcx` — show_before

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
null
```

Actual response 200:

```json
{
  "data": {
    "id": "01m613rew0sgkb6m1svdjpawcx",
    "platform": "woocommerce",
    "method": "webhook",
    "status": "connected",
    "external_shop_ref": null,
    "limitations": [],
    "last_event_at": "2026-10-28T11:19:07+00:00",
    "last_verified_order_at": "2026-10-28T11:19:08+00:00",
    "connected_at": "2026-10-28T11:19:08+00:00",
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T11:19:07+00:00",
    "setup": {
      "format": "woocommerce-webhooks",
      "webhook_url": "<REDACTED>",
      "read_api_configured": false,
      "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.",
      "steps": [
        "In WordPress go to WooCommerce \u2192 Settings \u2192 Advanced \u2192 Webhooks \u2192 Add webhook.",
        "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.",
        "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.",
        "Save, then place a real paid test order."
      ]
    }
  }
}
```

### POST `/api/v1/workspaces/01m613rcypqmyntqabv0e57c6q/connections/01m613rew0sgkb6m1svdjpawcx/credentials` — rotate

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "id": "01m613rew0sgkb6m1svdjpawcx",
    "platform": "woocommerce",
    "method": "webhook",
    "status": "setup_required",
    "external_shop_ref": null,
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T11:19:07+00:00",
    "setup": {
      "format": "woocommerce-webhooks",
      "webhook_url": "<REDACTED>",
      "read_api_configured": false,
      "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.",
      "steps": [
        "In WordPress go to WooCommerce \u2192 Settings \u2192 Advanced \u2192 Webhooks \u2192 Add webhook.",
        "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.",
        "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.",
        "Save, then place a real paid test order."
      ]
    },
    "secrets": {
      "webhook_secret": "<REDACTED>"
    },
    "secrets_notice": "Shown only once. Store it in your shop settings now; it cannot be retrieved later."
  }
}
```

### POST `/api/v1/workspaces/01m613rcypqmyntqabv0e57c6q/connections/01m613rew0sgkb6m1svdjpawcx/verify` — verify_after

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "status": "setup_required",
    "checks": {
      "credentials_configured": true,
      "event_received": false,
      "order_received": false,
      "mandatory_fields_present": false,
      "phone_available": false,
      "webshop_ownership_verified": false
    },
    "missing_fields": [],
    "limitations": [],
    "next_action": "configure_webhook"
  }
}
```

### POST `/api/v1/workspaces/01m613rcypqmyntqabv0e57c6q/connections/01m613rew0sgkb6m1svdjpawcx/events/01m613rewzp0czd0jpb7eg8sd5/retry` — merchant_stale_retry

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
{}
```

Actual response 409:

```json
{
  "error": {
    "status": 409,
    "code": "event_not_retryable",
    "message": "Only failed events with a retained payload can be retried.",
    "request_id": "01a1823c-3e4c-71b0-b6db-63da5aaaa990"
  }
}
```

### POST `/api/v1/admin/integrations/events/01m613rewzp0czd0jpb7eg8sd5/retry` — admin_stale_retry

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
{
  "reason": "Synthetic stale credential retry"
}
```

Actual response 409:

```json
{
  "error": {
    "status": 409,
    "code": "event_not_retryable",
    "message": "Only failed events with a retained payload and active connection can be retried.",
    "request_id": "01a1823c-3e69-73ab-a56d-7950d6a9a19c"
  }
}
```

### GET `/api/v1/workspaces/01m613rcypqmyntqabv0e57c6q/connections/01m613rew0sgkb6m1svdjpawcx/events` — history

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
null
```

Actual response 200:

```json
{
  "data": [
    {
      "id": "01m613rf8jfsq4jp7byqr19c2b",
      "topic": "order.created",
      "status": "ignored",
      "delivery_count": 1,
      "result": {
        "kind": "order"
      },
      "error_code": "credential_generation_stale",
      "received_at": "2026-10-28T11:19:08+00:00",
      "processed_at": "2026-10-28T11:19:08+00:00"
    },
    {
      "id": "01m613rewzp0czd0jpb7eg8sd5",
      "topic": "order.created",
      "status": "failed",
      "delivery_count": 1,
      "result": {
        "kind": "order",
        "complete": true,
        "missing_fields": [],
        "has_phone": false,
        "payment_status": "paid",
        "currency": "EUR",
        "amount_minor": 5995,
        "ingested": true,
        "order_id": "01m613rf5gvqy9sxa6v98dvxmt",
        "change": "created"
      },
      "error_code": null,
      "received_at": "2026-10-28T11:19:07+00:00",
      "processed_at": "2026-10-28T11:19:08+00:00"
    }
  ]
}
```

### POST `/api/v1/webhooks/woocommerce/<WEBHOOK_TOKEN>` — rotation_during_intake

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
{
  "id": 4711,
  "number": "4711",
  "status": "processing",
  "currency": "EUR",
  "total": "59.95",
  "date_created_gmt": "2026-10-28T10:00:00",
  "date_modified_gmt": "2026-10-28T10:01:00",
  "date_paid_gmt": "2026-10-28T10:01:00",
  "billing": {
    "first_name": "Sample",
    "email": "sample@example.test",
    "phone": null
  },
  "refunds": []
}
```

Actual response 409:

```json
{
  "error": {
    "status": 409,
    "code": "connection_changed",
    "message": "The connection changed while accepting the event. Retry with its current configuration.",
    "request_id": "01a1823c-3fb3-719f-82ef-c92c63d78d60"
  }
}
```

### DELETE `/api/v1/workspaces/01m613rcypqmyntqabv0e57c6q/connections/01m613rew0sgkb6m1svdjpawcx` — disconnect

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
null
```

Actual response 200:

```json
{
  "data": {
    "id": "01m613rew0sgkb6m1svdjpawcx",
    "platform": "woocommerce",
    "method": "webhook",
    "status": "disconnected",
    "external_shop_ref": null,
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": "2026-10-28T11:19:09+00:00",
    "last_error": null,
    "created_at": "2026-10-28T11:19:07+00:00"
  }
}
```

### GET `/api/v1/workspaces/01m613rcypqmyntqabv0e57c6q/connections/01m613rew0sgkb6m1svdjpawcx` — show_disconnected

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
null
```

Actual response 200:

```json
{
  "data": {
    "id": "01m613rew0sgkb6m1svdjpawcx",
    "platform": "woocommerce",
    "method": "webhook",
    "status": "disconnected",
    "external_shop_ref": null,
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": "2026-10-28T11:19:09+00:00",
    "last_error": null,
    "created_at": "2026-10-28T11:19:07+00:00"
  }
}
```

### POST `/api/v1/webhooks/woocommerce/<WEBHOOK_TOKEN>` — disconnected_webhook

Request body (normal session/CSRF required, or provider HMAC for webhooks):

```json
{
  "id": 4711,
  "number": "4711",
  "status": "processing",
  "currency": "EUR",
  "total": "59.95",
  "date_created_gmt": "2026-10-28T10:00:00",
  "date_modified_gmt": "2026-10-28T10:01:00",
  "date_paid_gmt": "2026-10-28T10:01:00",
  "billing": {
    "first_name": "Sample",
    "email": "sample@example.test",
    "phone": null
  },
  "refunds": []
}
```

Actual response 404:

```json
{
  "error": {
    "status": 404,
    "code": "not_found",
    "message": "The requested resource was not found.",
    "request_id": "01a1823c-4147-70c6-a293-ffd8ea2d0312"
  }
}
```


## B23d — current seven-gate readiness and native single-order recovery

Captured from an actual PHP HTTP server, isolated SQLite/database cache/file sessions/normal login+CSRF; array mail/database queue. Provider HTTP/DNS are synthetic. The actual operator command and canonical processor were invoked by the fixture worker. No live provider/production acceptance. Base `63d963c` plus B23d worktree. [Raw captures and operator outcomes](../integrations/B23_READINESS_HTTP_CAPTURE.json); [gate definitions/command/rollout](../integrations/READINESS_AND_RECOVERY.md). Schema500 is an isolated table-rename fault. These current examples supersede prior connected-from-webhook examples.

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/verify` — webhook_is_limited

Request body (normal SPA session/CSRF):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "status": "limited",
    "quality_gates": {
      "authorization_verified": false,
      "store_identity_matches": false,
      "genuine_order_observed": false,
      "mandatory_fields_mapped": false,
      "payment_verified": false,
      "duplicate_processing_prevented": false,
      "changes_refunds_recoverable": false
    },
    "missing_gates": [
      "authorization_verified",
      "store_identity_matches",
      "genuine_order_observed",
      "mandatory_fields_mapped",
      "payment_verified",
      "duplicate_processing_prevented",
      "changes_refunds_recoverable"
    ],
    "checks": {
      "credentials_configured": true,
      "event_received": true,
      "order_received": true,
      "mandatory_fields_present": true,
      "phone_available": false,
      "webshop_ownership_verified": true
    },
    "missing_fields": [],
    "limitations": [
      "connection_quality_gates_pending"
    ],
    "next_action": "complete_connection_verification"
  }
}
```

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/verify` — native_first

Request body (normal SPA session/CSRF):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "status": "limited",
    "quality_gates": {
      "authorization_verified": true,
      "store_identity_matches": true,
      "genuine_order_observed": true,
      "mandatory_fields_mapped": true,
      "payment_verified": true,
      "duplicate_processing_prevented": false,
      "changes_refunds_recoverable": false
    },
    "missing_gates": [
      "duplicate_processing_prevented",
      "changes_refunds_recoverable"
    ],
    "checks": {
      "credentials_configured": true,
      "event_received": true,
      "order_received": true,
      "mandatory_fields_present": true,
      "phone_available": false,
      "webshop_ownership_verified": true
    },
    "missing_fields": [],
    "limitations": [
      "connection_quality_gates_pending"
    ],
    "next_action": "complete_connection_verification"
  }
}
```

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/verify` — native_duplicate

Request body (normal SPA session/CSRF):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "status": "limited",
    "quality_gates": {
      "authorization_verified": true,
      "store_identity_matches": true,
      "genuine_order_observed": true,
      "mandatory_fields_mapped": true,
      "payment_verified": true,
      "duplicate_processing_prevented": true,
      "changes_refunds_recoverable": false
    },
    "missing_gates": [
      "changes_refunds_recoverable"
    ],
    "checks": {
      "credentials_configured": true,
      "event_received": true,
      "order_received": true,
      "mandatory_fields_present": true,
      "phone_available": false,
      "webshop_ownership_verified": true
    },
    "missing_fields": [],
    "limitations": [
      "connection_quality_gates_pending"
    ],
    "next_action": "complete_connection_verification"
  }
}
```

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/verify` — refund_recovered

Request body (normal SPA session/CSRF):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "status": "limited",
    "quality_gates": {
      "authorization_verified": true,
      "store_identity_matches": true,
      "genuine_order_observed": true,
      "mandatory_fields_mapped": true,
      "payment_verified": true,
      "duplicate_processing_prevented": false,
      "changes_refunds_recoverable": true
    },
    "missing_gates": [
      "duplicate_processing_prevented"
    ],
    "checks": {
      "credentials_configured": true,
      "event_received": true,
      "order_received": true,
      "mandatory_fields_present": true,
      "phone_available": false,
      "webshop_ownership_verified": true
    },
    "missing_fields": [],
    "limitations": [
      "connection_quality_gates_pending"
    ],
    "next_action": "complete_connection_verification"
  }
}
```

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/verify` — all_gates

Request body (normal SPA session/CSRF):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "status": "connected",
    "quality_gates": {
      "authorization_verified": true,
      "store_identity_matches": true,
      "genuine_order_observed": true,
      "mandatory_fields_mapped": true,
      "payment_verified": true,
      "duplicate_processing_prevented": true,
      "changes_refunds_recoverable": true
    },
    "missing_gates": [],
    "checks": {
      "credentials_configured": true,
      "event_received": true,
      "order_received": true,
      "mandatory_fields_present": true,
      "phone_available": false,
      "webshop_ownership_verified": true
    },
    "missing_fields": [],
    "limitations": [],
    "next_action": null
  }
}
```

### GET `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc` — connected_show

Request body (normal SPA session/CSRF):

```json
null
```

Actual response 200:

```json
{
  "data": {
    "id": "01m6238cmfns7j6w5cshgj5nfc",
    "platform": "woocommerce",
    "method": "webhook",
    "status": "connected",
    "external_shop_ref": "https://fietsen-utrecht.nl",
    "limitations": [],
    "last_event_at": "2026-10-28T20:29:35+00:00",
    "last_verified_order_at": "2026-10-28T20:29:38+00:00",
    "connected_at": "2026-10-28T20:29:39+00:00",
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T20:29:35+00:00",
    "setup": {
      "format": "woocommerce-webhooks",
      "webhook_url": "<REDACTED>",
      "read_api_configured": true,
      "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.",
      "steps": [
        "In WordPress go to WooCommerce \u2192 Settings \u2192 Advanced \u2192 Webhooks \u2192 Add webhook.",
        "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.",
        "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.",
        "Save, then place a real paid test order. The connection remains Limited until all seven provider verification checks pass."
      ]
    }
  }
}
```

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/credentials` — store_switch_rejected

Request body (normal SPA session/CSRF):

```json
{
  "store_url": "https://fietsen-utrecht.nl/other-store",
  "consumer_key": "<REDACTED>",
  "consumer_secret": "<REDACTED>"
}
```

Actual response 409:

```json
{
  "error": {
    "status": 409,
    "code": "provider_store_change_requires_review",
    "message": "Changing the provider store after orders exist requires a reviewed migration.",
    "request_id": "01a18434-3fed-729d-9d05-45e7e2c7e9e1"
  }
}
```

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/verify` — auth_failure

Request body (normal SPA session/CSRF):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "status": "limited",
    "quality_gates": {
      "authorization_verified": false,
      "store_identity_matches": false,
      "genuine_order_observed": false,
      "mandatory_fields_mapped": false,
      "payment_verified": false,
      "duplicate_processing_prevented": false,
      "changes_refunds_recoverable": false
    },
    "missing_gates": [
      "authorization_verified",
      "store_identity_matches",
      "genuine_order_observed",
      "mandatory_fields_mapped",
      "payment_verified",
      "duplicate_processing_prevented",
      "changes_refunds_recoverable"
    ],
    "checks": {
      "credentials_configured": true,
      "event_received": true,
      "order_received": true,
      "mandatory_fields_present": true,
      "phone_available": false,
      "webshop_ownership_verified": true
    },
    "missing_fields": [],
    "limitations": [
      "connection_quality_gates_pending"
    ],
    "next_action": "complete_connection_verification"
  }
}
```

### GET `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc` — failed_show

Request body (normal SPA session/CSRF):

```json
null
```

Actual response 200:

```json
{
  "data": {
    "id": "01m6238cmfns7j6w5cshgj5nfc",
    "platform": "woocommerce",
    "method": "webhook",
    "status": "limited",
    "external_shop_ref": "https://fietsen-utrecht.nl",
    "limitations": [
      "connection_quality_gates_pending"
    ],
    "last_event_at": "2026-10-28T20:29:35+00:00",
    "last_verified_order_at": "2026-10-28T20:29:38+00:00",
    "connected_at": null,
    "disconnected_at": null,
    "last_error": {
      "code": "provider_authentication_failed",
      "at": "2026-10-28T20:29:39+00:00"
    },
    "created_at": "2026-10-28T20:29:35+00:00",
    "setup": {
      "format": "woocommerce-webhooks",
      "webhook_url": "<REDACTED>",
      "read_api_configured": true,
      "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.",
      "steps": [
        "In WordPress go to WooCommerce \u2192 Settings \u2192 Advanced \u2192 Webhooks \u2192 Add webhook.",
        "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.",
        "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.",
        "Save, then place a real paid test order. The connection remains Limited until all seven provider verification checks pass."
      ]
    }
  }
}
```

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/credentials` — rotate

Request body (normal SPA session/CSRF):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "id": "01m6238cmfns7j6w5cshgj5nfc",
    "platform": "woocommerce",
    "method": "webhook",
    "status": "setup_required",
    "external_shop_ref": "https://fietsen-utrecht.nl",
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T20:29:35+00:00",
    "setup": {
      "format": "woocommerce-webhooks",
      "webhook_url": "<REDACTED>",
      "read_api_configured": false,
      "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.",
      "steps": [
        "In WordPress go to WooCommerce \u2192 Settings \u2192 Advanced \u2192 Webhooks \u2192 Add webhook.",
        "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.",
        "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.",
        "Save, then place a real paid test order. The connection remains Limited until all seven provider verification checks pass."
      ]
    },
    "secrets": {
      "webhook_secret": "<REDACTED>"
    },
    "secrets_notice": "Shown only once. Store it in your shop settings now; it cannot be retrieved later."
  }
}
```

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/verify` — rotation_clears_gates

Request body (normal SPA session/CSRF):

```json
{}
```

Actual response 200:

```json
{
  "data": {
    "status": "setup_required",
    "quality_gates": {
      "authorization_verified": false,
      "store_identity_matches": false,
      "genuine_order_observed": false,
      "mandatory_fields_mapped": false,
      "payment_verified": false,
      "duplicate_processing_prevented": false,
      "changes_refunds_recoverable": false
    },
    "missing_gates": [
      "authorization_verified",
      "store_identity_matches",
      "genuine_order_observed",
      "mandatory_fields_mapped",
      "payment_verified",
      "duplicate_processing_prevented",
      "changes_refunds_recoverable"
    ],
    "checks": {
      "credentials_configured": true,
      "event_received": false,
      "order_received": false,
      "mandatory_fields_present": false,
      "phone_available": false,
      "webshop_ownership_verified": true
    },
    "missing_fields": [],
    "limitations": [],
    "next_action": "configure_webhook"
  }
}
```

### POST `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc/verify` — storage_error

Request body (normal SPA session/CSRF):

```json
{}
```

Actual response 500:

```json
{
  "error": {
    "status": 500,
    "code": "server_error",
    "message": "An unexpected error occurred.",
    "request_id": "01a18434-447e-71d9-9222-d0a5011e4b6a"
  }
}
```

### GET `/api/v1/workspaces/01m6238a726tfp7ft7x687m4qg/connections/01m6238cmfns7j6w5cshgj5nfc` — unauthenticated

Request body (normal SPA session/CSRF):

```json
null
```

Actual response 401:

```json
{
  "error": {
    "status": 401,
    "code": "unauthenticated",
    "message": "Authentication is required.",
    "request_id": "01a18434-4678-714d-badf-b133c7940883"
  }
}
```


## Shopify authorization and current order snapshots (2026-10-28)

Captured against actual PHP HTTP server, isolated SQLite, normal browser login/CSRF/file sessions, working tree based78642de. Upstream provider HTTP/DNS is explicitly synthetic. Provider installs and real payment/refund acceptance are NOT RUN. Full redacted captures: [B23_REMAINING_HTTP_CAPTURE.json](../integrations/B23_REMAINING_HTTP_CAPTURE.json). [Implementation and limits](../integrations/REMAINING_BACKEND.md). These current captures supersede historical no-OAuth/catalogue availability descriptions above.

### POST /api/v1/workspaces/{workspace}/integrations/shopify/oauth

```sh
curl -i -X POST -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' -H 'Content-Type: application/json' --data '{"shop_domain":"fietsen.myshopify.com"}' 'http://localhost:8000/api/v1/workspaces/{workspace}/integrations/shopify/oauth'
```

Actual response, 200:

```json
{
  "data": {
    "connection": "01m6280qphaya1py4jfg8gz1wz",
    "authorization_url": "<REDACTED>",
    "expires_in": 600
  }
}
```

### GET /api/v1/integrations/shopify/oauth/callback

```sh
curl -i -X GET -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' 'http://localhost:8000/api/v1/integrations/shopify/oauth/callback?shop=fietsen.myshopify.com&state=<STATE>&code=<AUTHORIZATION_CODE>&timestamp=<TIMESTAMP>&hmac=<HMAC>'
```

Actual response, 200:

```json
{
  "data": {
    "id": "01m6280qphaya1py4jfg8gz1wz",
    "platform": "shopify",
    "method": "webhook",
    "status": "setup_required",
    "external_shop_ref": "fietsen.myshopify.com",
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T21:52:47+00:00"
  }
}
```

### GET /api/v1/integrations/platforms

```sh
curl -i -X GET -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' 'http://localhost:8000/api/v1/integrations/platforms'
```

Actual response, 200:

```json
{
  "data": [
    {
      "platform": "woocommerce",
      "name": "WooCommerce",
      "priority": "production",
      "available": true,
      "method": "webhook",
      "auth": "shared secret (HMAC-SHA256, base64)",
      "reliability": "high",
      "limitations": [],
      "verification": "unverified",
      "profile": {
        "api_version": "wc/v3",
        "reviewed_at": "2026-10-28",
        "last_live_verified_at": null,
        "native_read_api_implemented": true,
        "auth_method": "HTTPS HTTP Basic consumer key/secret; read-only merchant key",
        "scopes": [
          "read"
        ],
        "webhook_topics": [
          "order.created",
          "order.updated"
        ],
        "order_api": "GET /wp-json/wc/v3/orders/{id}",
        "refund_api": "GET /wp-json/wc/v3/orders/{id}/refunds",
        "product_api": "GET /wp-json/wc/v3/products",
        "coupon_api": "GET /wp-json/wc/v3/coupons",
        "banner_method": "manual; native WordPress publication/module not implemented",
        "rate_limit_policy": "No redirects/retries;8second reads,1MiB body,100rows/page,5order pages;429cooldown/3failure circuit.",
        "sources": [
          "https://developer.woocommerce.com/docs/apis/rest-api/",
          "https://developer.woocommerce.com/docs/apis/rest-api/v3/orders/",
          "https://developer.woocommerce.com/docs/apis/rest-api/v3/order-refunds/",
          "https://developer.woocommerce.com/docs/best-practices/urls-and-routing/webhooks/"
        ]
      },
      "blocked_reason": null
    },
    {
      "platform": "shopify",
      "name": "Shopify",
      "priority": "production",
      "available": true,
      "method": "webhook",
      "auth": "webhook signing secret (HMAC-SHA256, base64)",
      "reliability": "high",
      "limitations": [
        "customer fields may be redacted without protected customer data access"
      ],
      "verification": "unverified",
      "profile": {
        "api_version": "2026-10",
        "reviewed_at": "2026-10-28",
        "last_live_verified_at": null,
        "native_read_api_implemented": true,
        "auth_method": "Encrypted approved app token for pinned GraphQL; manual signing-secret compatibility. optional disabled-by-default browser OAuth and expiring offline token renewal implemented",
        "scopes": [
          "read_orders",
          "read_products (catalog only)"
        ],
        "order_api": "POST /admin/api/2026-10/graphql.json: order/orders queries",
        "refund_api": "Order.totalRefundedSet and successful REFUND transactions; no refund mutation",
        "product_api": "products query; bounded core catalog metadata only",
        "coupon_api": "UNSUPPORTED in this boundary",
        "rate_limit_policy": "Pinned version,8second/1MiB reads,25orders/page,max5pages; no retries, HTTP200GraphQLerrors checked; throttling pauses reads.",
        "webhook_topics": [
          "orders/create",
          "orders/updated",
          "orders/paid",
          "orders/cancelled",
          "refunds/create"
        ],
        "banner_method": "Theme App Extension requires separate implementation and merchant activation",
        "sources": [
          "https://shopify.dev/docs/api/admin-graphql/2026-10",
          "https://shopify.dev/docs/apps/build/webhooks"
        ]
      },
      "blocked_reason": null
    },
    {
      "platform": "wix",
      "name": "Wix",
      "priority": "production",
      "available": true,
      "method": "webhook",
      "auth": "App RSA-signed JWT + merchant-authorized API token lookup",
      "reliability": "unknown",
      "limitations": [],
      "verification": "unverified",
      "profile": {
        "api_version": "eCommerce Orders v1 / App Instance v1",
        "reviewed_at": "2026-10-28",
        "last_live_verified_at": null,
        "native_read_api_implemented": true,
        "scopes": [
          "Read Orders",
          "Read Order Transactions",
          "Manage Your App"
        ],
        "sources": [
          "https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/orders/get-order",
          "https://dev.wix.com/docs/api-reference/business-solutions/e-commerce/orders/order-transactions/list-transactions-for-single-order",
          "https://dev.wix.com/docs/build-apps/develop-your-app/api-integrations/events-and-webhooks/about-webhooks"
        ]
      },
      "blocked_reason": null
    },
    {
      "platform": "bigcommerce",
      "name": "BigCommerce",
      "priority": "production",
      "available": true,
      "method": "webhook",
      "auth": "Standard Webhooks app-secret HMAC + restricted API token lookup",
      "reliability": "unknown",
      "limitations": [],
      "verification": "unverified",
      "profile": {
        "api_version": "Orders v2 / Transactions and Refunds v3",
        "reviewed_at": "2026-10-28",
        "last_live_verified_at": null,
        "native_read_api_implemented": true,
        "scopes": [
          "store_v2_orders_read_only",
          "store_v2_transactions_read_only",
          "store_v2_information_read_only"
        ],
        "sources": [
          "https://docs.bigcommerce.com/developer/docs/integrations/webhooks/https",
          "https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/orders/get-order",
          "https://docs.bigcommerce.com/developer/api-reference/rest/admin/management/order-operations/get-order-transactions"
        ]
      },
      "blocked_reason": null
    },
    {
      "platform": "ecwid",
      "name": "Ecwid",
      "priority": "production",
      "available": true,
      "method": "webhook",
      "auth": "app client-secret HMAC notification + store API token lookup",
      "reliability": "limited",
      "limitations": [
        "Reviewed order API has no immutable order currency or settled payment time; monetary mapping remains incomplete. Native reads and plan checks implemented; live verification pending."
      ],
      "verification": "unverified",
      "profile": {
        "api_version": "v3",
        "reviewed_at": "2026-10-28",
        "last_live_verified_at": null,
        "native_read_api_implemented": true,
        "scopes": [
          "read_orders",
          "read_store_profile"
        ],
        "order_api": "GET https://app.ecwid.com/api/v3/{storeId}/orders/{orderId}",
        "webhook_topics": [
          "order.created",
          "order.updated"
        ],
        "sources": [
          "https://docs.ecwid.com/api-reference/rest-api/orders/get-order",
          "https://docs.ecwid.com/api-reference/rest-api/store-profile/get-store-profile",
          "https://github.com/Ecwid/ecwid-api-docs/blob/master/includes/_webhooks.md"
        ]
      },
      "blocked_reason": null
    },
    {
      "platform": "lightspeed_c",
      "name": "Lightspeed C-Series",
      "priority": "production",
      "available": false,
      "method": "confirmation tracking where supported",
      "auth": "to be determined",
      "reliability": "lower",
      "limitations": [
        "lower reliability"
      ],
      "verification": "unverified",
      "profile": null,
      "blocked_reason": "C-Series requires an eligible Advanced/Professional plan and API access. Current C-Series order/payment/refund field contracts could not be verified from accessible official documentation; no tracking-page payment proof or Retail/E-Series endpoints are substituted."
    },
    {
      "platform": "lightspeed_e",
      "name": "Lightspeed E-Series",
      "priority": "additional",
      "available": false,
      "method": "API/confirmation (to be determined)",
      "auth": "to be determined",
      "reliability": "unknown",
      "limitations": [],
      "verification": "unverified",
      "profile": null,
      "blocked_reason": "Supported API method not yet verified."
    },
    {
      "platform": "prestashop",
      "name": "PrestaShop",
      "priority": "additional",
      "available": true,
      "method": "private_module",
      "auth": "Shopbonus signed webhook v1",
      "reliability": "high (module not yet built)",
      "limitations": [
        "private ZIP module must be installed"
      ],
      "verification": "unverified",
      "profile": {
        "api_version": "PrestaShop9 Webservice JSON",
        "reviewed_at": "2026-10-28",
        "last_live_verified_at": null,
        "native_read_api_implemented": true,
        "sources": [
          "https://devdocs.prestashop-project.org/9/webservice/resources/orders/",
          "https://devdocs.prestashop-project.org/9/webservice/resources/order_payments/",
          "https://devdocs.prestashop-project.org/9/webservice/resources/order_slip/"
        ]
      },
      "blocked_reason": null
    },
    {
      "platform": "opencart",
      "name": "OpenCart",
      "priority": "additional",
      "available": true,
      "method": "private_module",
      "auth": "Shopbonus signed webhook v1",
      "reliability": "high (module not yet built)",
      "limitations": [
        "private OCMOD extension must be installed"
      ],
      "verification": "unverified",
      "profile": null,
      "blocked_reason": null
    },
    {
      "platform": "shopware6",
      "name": "Shopware 6",
      "priority": "additional",
      "available": true,
      "method": "private_module",
      "auth": "Shopbonus signed webhook v1",
      "reliability": "high (extension not yet built)",
      "limitations": [
        "private extension must be installed"
      ],
      "verification": "unverified",
      "profile": {
        "api_version": "Shopware6 Admin API",
        "reviewed_at": "2026-10-28",
        "last_live_verified_at": null,
        "native_read_api_implemented": true,
        "sources": [
          "https://github.com/shopware/admin-api-reference/blob/main/docs/concepts/authentication-authorisation.md",
          "https://github.com/shopware/admin-api-reference/blob/main/docs/guides/quick-start/05-order-management.md"
        ]
      },
      "blocked_reason": null
    },
    {
      "platform": "magento",
      "name": "Magento / Adobe Commerce",
      "priority": "additional",
      "available": true,
      "method": "private_module",
      "auth": "Shopbonus signed webhook v1",
      "reliability": "high (module not yet built)",
      "limitations": [
        "private module must be installed"
      ],
      "verification": "unverified",
      "profile": {
        "api_version": "Magento/Adobe Commerce2.4 self-hosted/PaaS REST",
        "reviewed_at": "2026-10-28",
        "last_live_verified_at": null,
        "native_read_api_implemented": true,
        "sources": [
          "https://developer.adobe.com/commerce/webapi/rest/attributes/",
          "https://developer.adobe.com/commerce/webapi/rest/use-rest/performing-searches/",
          "https://github.com/magento/magento2"
        ]
      },
      "blocked_reason": null
    },
    {
      "platform": "squarespace",
      "name": "Squarespace",
      "priority": "additional",
      "available": false,
      "method": "polling (read-only Orders API)",
      "auth": "API key",
      "reliability": "polling delay",
      "limitations": [
        "polling"
      ],
      "verification": "unverified",
      "profile": null,
      "blocked_reason": "Polling adapter not built; requires API key."
    },
    {
      "platform": "mijnwebwinkel",
      "name": "Mijnwebwinkel",
      "priority": "additional",
      "available": false,
      "method": "partner API",
      "auth": "partner authorisation",
      "reliability": "unknown",
      "limitations": [],
      "verification": "unverified",
      "profile": null,
      "blocked_reason": "Partner/API authorisation pending."
    },
    {
      "platform": "ccv",
      "name": "CCV Shop",
      "priority": "additional",
      "available": false,
      "method": "to be investigated",
      "auth": "to be determined",
      "reliability": "unknown",
      "limitations": [],
      "verification": "unverified",
      "profile": null,
      "blocked_reason": "Merchant-accessible API options not yet investigated."
    },
    {
      "platform": "other",
      "name": "Other / custom shop",
      "priority": "additional",
      "available": true,
      "method": "webhook",
      "auth": "Shopbonus signed webhook v1",
      "reliability": "depends on the custom integration",
      "limitations": [],
      "verification": "unverified",
      "profile": null,
      "blocked_reason": null
    }
  ]
}
```

### POST /api/v1/workspaces/{workspace}/connections

```sh
curl -i -X POST -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <XSRF_TOKEN>' --data '{"platform":"woocommerce","store_url":"https://fietsen-utrecht.nl","consumer_key":"<LOCAL_CONSUMER_KEY>","consumer_secret":"<LOCAL_CONSUMER_SECRET>"}' 'http://localhost:8000/api/v1/workspaces/01m6102yjss303ctz63gsbsd8e/connections'
```

Actual response, 201:

```json
{
  "data": {
    "id": "01m6280r328v6jr3vm9a4zwqcg",
    "platform": "woocommerce",
    "method": "webhook",
    "status": "setup_required",
    "external_shop_ref": "https://fietsen-utrecht.nl",
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T21:52:48+00:00",
    "setup": {
      "format": "woocommerce-webhooks",
      "webhook_url": "http://127.0.0.1:8917/api/v1/webhooks/woocommerce/<WEBHOOK_TOKEN>",
      "read_api_configured": true,
      "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.",
      "steps": [
        "In WordPress go to WooCommerce → Settings → Advanced → Webhooks → Add webhook.",
        "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.",
        "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.",
        "Save, then place a real paid test order. The connection remains Limited until all seven provider verification checks pass."
      ]
    },
    "secrets": {
      "webhook_secret": "<REDACTED>"
    },
    "secrets_notice": "Shown only once. Store it in your shop settings now; it cannot be retrieved later."
  }
}
```

### GET /api/v1/workspaces/{workspace}/connections/{connection}

```sh
curl -i -X GET -H 'Accept: application/json' -H 'Cookie: <SPA_SESSION_COOKIE>' 'http://localhost:8000/api/v1/workspaces/01m6102yjss303ctz63gsbsd8e/connections/01m6102zv4nrrhpsnmvm4c8q2v'
```

Actual response, 200:

```json
{
  "data": {
    "id": "01m6280r328v6jr3vm9a4zwqcg",
    "platform": "woocommerce",
    "method": "webhook",
    "status": "setup_required",
    "external_shop_ref": "https://fietsen-utrecht.nl",
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": null,
    "last_error": null,
    "created_at": "2026-10-28T21:52:48+00:00",
    "setup": {
      "format": "woocommerce-webhooks",
      "webhook_url": "http://127.0.0.1:8917/api/v1/webhooks/woocommerce/<WEBHOOK_TOKEN>",
      "read_api_configured": true,
      "read_api_notice": "Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.",
      "steps": [
        "In WordPress go to WooCommerce → Settings → Advanced → Webhooks → Add webhook.",
        "Create two webhooks, topics \"Order created\" and \"Order updated\", status Active, API version WP REST API Integration v3.",
        "Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.",
        "Save, then place a real paid test order. The connection remains Limited until all seven provider verification checks pass."
      ]
    }
  }
}
```

### GET /api/v1/workspaces/{workspace}/orders

```sh
curl -i -X GET 'http://localhost:8000/api/v1/workspaces/current/orders?payment_status=paid&sort=-amount_minor' \
  -H 'Accept: application/json' -H 'Authorization: Bearer <token>'

```

Actual response, 200:

```json
{
  "data": [
    {
      "id": "01m6280r73a3ymqzbfkx83vdaj",
      "reference": "SYNTHETIC-4711",
      "external_order_id": "4711",
      "platform": "woocommerce",
      "customer": {
        "first_name": "Synthetic",
        "email": "synthetic-customer@example.test",
        "phone": null
      },
      "amount_minor": 2500,
      "refunded_minor": 0,
      "currency": "EUR",
      "line_items": [
        {
          "id": "7",
          "product_id": "55",
          "sku": "SYNTHETIC-SKU",
          "quantity": 2,
          "amount_minor": 1999,
          "amount_basis": "discounted_ex_tax",
          "currency": "EUR",
          "eligible_amount_minor": null
        }
      ],
      "payment_status": "paid",
      "provider_status": "processing",
      "ingestion_status": "complete",
      "missing_fields": [],
      "callable": false,
      "eligibility_status": "ineligible",
      "eligibility_reasons": [
        "webshop_not_active",
        "no_active_reward_offer",
        "answer24_disabled",
        "no_lawful_basis"
      ],
      "contact_status": "blocked",
      "placed_at": "2026-10-28T10:00:00+00:00",
      "paid_at": "2026-10-28T10:10:00+00:00",
      "cancelled_at": null,
      "refunded_at": null,
      "updated_at": "2026-10-28T21:52:48+00:00"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8917/api/v1/workspaces/01m6280pz13debqmwwysg0sesr/orders?page=1",
    "last": "http://127.0.0.1:8917/api/v1/workspaces/01m6280pz13debqmwwysg0sesr/orders?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [
      {
        "url": null,
        "label": "&laquo; Previous",
        "page": null,
        "active": false
      },
      {
        "url": "http://127.0.0.1:8917/api/v1/workspaces/01m6280pz13debqmwwysg0sesr/orders?page=1",
        "label": "1",
        "page": 1,
        "active": true
      },
      {
        "url": null,
        "label": "Next &raquo;",
        "page": null,
        "active": false
      }
    ],
    "path": "http://127.0.0.1:8917/api/v1/workspaces/01m6280pz13debqmwwysg0sesr/orders",
    "per_page": 25,
    "to": 1,
    "total": 1,
    "today": {
      "timezone": "Europe/Amsterdam",
      "orders": 1,
      "paid_orders": 1,
      "incomplete_orders": 0,
      "paid_revenue_minor": {
        "EUR": 2500
      }
    }
  }
}
```

### GET /api/v1/workspaces/{workspace}/orders/{order}

```sh
curl -i -X GET 'http://localhost:8000/api/v1/workspaces/current/orders/<order id>' \
  -H 'Accept: application/json' -H 'Authorization: Bearer <token>'

```

Actual response, 200:

```json
{
  "data": {
    "id": "01m6280r73a3ymqzbfkx83vdaj",
    "reference": "SYNTHETIC-4711",
    "external_order_id": "4711",
    "platform": "woocommerce",
    "customer": {
      "first_name": "Synthetic",
      "email": "synthetic-customer@example.test",
      "phone": null
    },
    "amount_minor": 2500,
    "refunded_minor": 0,
    "currency": "EUR",
    "line_items": [
      {
        "id": "7",
        "product_id": "55",
        "sku": "SYNTHETIC-SKU",
        "quantity": 2,
        "amount_minor": 1999,
        "amount_basis": "discounted_ex_tax",
        "currency": "EUR",
        "eligible_amount_minor": null
      }
    ],
    "payment_status": "paid",
    "provider_status": "processing",
    "ingestion_status": "complete",
    "missing_fields": [],
    "callable": false,
    "eligibility_status": "ineligible",
    "eligibility_reasons": [
      "webshop_not_active",
      "no_active_reward_offer",
      "answer24_disabled",
      "no_lawful_basis"
    ],
    "contact_status": "blocked",
    "placed_at": "2026-10-28T10:00:00+00:00",
    "paid_at": "2026-10-28T10:10:00+00:00",
    "cancelled_at": null,
    "refunded_at": null,
    "updated_at": "2026-10-28T21:52:48+00:00"
  },
  "history": [
    {
      "type": "created",
      "from_payment_status": null,
      "to_payment_status": "paid",
      "summary": {
        "complete": true,
        "topic": "order.updated"
      },
      "provider_updated_at": null,
      "recorded_at": "2026-10-28T21:52:48+00:00"
    }
  ]
}
```

OAuth start requires integrations.manage and CSRF; callback requires the same normal browser session and fresh membership permission. Captured401/409/419/422/429/503 examples are in the capture file/OpenAPI. 403/500 use the existing real-kernel common envelopes. Ordinary token renewal preserves the approved grant generation; changed scopes/app credentials, expired refresh authority and reconnect require fresh authorization. No token or authorization code belongs in logs or API output.


### Shopify uninstall through POST /api/v1/webhooks/{platform}/{token}

Current supported topic: app/uninstalled. Standard Shopify raw-body HMAC and bound shop domain required; native revocation is checked outside database locks before removing credentials. Replayed old uninstall cannot revoke a still-valid current grant. App privacy callbacks and auto-registration remain incomplete. Actual running-server synthetic upstream401 capture:

```json
{
  "data": {
    "received": true,
    "duplicate": false,
    "event_id": "01m628jpkx9fyf36c36a0zxc47"
  }
}
```

Subsequent authenticated connection GET (same store, actual response):

```json
{
  "data": {
    "id": "01m6280qphaya1py4jfg8gz1wz",
    "platform": "shopify",
    "method": "webhook",
    "status": "disconnected",
    "external_shop_ref": "fietsen.myshopify.com",
    "limitations": [],
    "last_event_at": null,
    "last_verified_order_at": null,
    "connected_at": null,
    "disconnected_at": "2026-10-28T22:02:36+00:00",
    "last_error": null,
    "created_at": "2026-10-28T21:52:47+00:00"
  }
}
```


## Connection catalog, native coupons and capability report (B25, Trello #1)

Read-only. Captured on a real local PHP HTTP server (Laravel kernel), isolated synthetic SQLite, sync queue; the WooCommerce REST responses and DNS resolver are synthetic capture stubs (no real store). Full capture: `docs/integrations/B25_CATALOG_HTTP_CAPTURE.json`. `capabilities`, `products` and `catalog-syncs` (GET) need `integrations.view`; `coupons` (codes decrypted) and starting a sync need `integrations.manage`. A complete pass marks items it did not see as `removed_at`; a `partial` pass (page cap) never removes. ShopBonus never creates or changes native coupons.

`GET /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/capabilities    (no session)`

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json
X-Request-Id: 01a186ff-7efe-7254-83e4-5b3736f56d5e

{"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a186ff-7efe-7254-83e4-5b3736f56d5e"}}
```

`GET /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/capabilities    (owner session)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186ff-8302-7077-8e46-0ad5f25537b4

{"data":[{"capability":"orders","status":"LIMITED","reason":"The connection has not passed all native verification checks (status setup_required).","last_verified_at":null},{"capability":"payments","status":"LIMITED","reason":"The connection has not passed all native verification checks (status setup_required).","last_verified_at":null},{"capability":"refunds","status":"LIMITED","reason":"The connection has not passed all native verification checks (status setup_required).","last_verified_at":null},{"capability":"products","status":"LIMITED","reason":"Implemented; no complete sync with the current credentials yet.","last_verified_at":null},{"capability":"categories","status":"LIMITED","reason":"Implemented; no complete sync with the current credentials yet.","last_verified_at":null},{"capability":"coupons_read","status":"LIMITED","reason":"Implemented; no complete sync with the current credentials yet.","last_verified_at":null},{"capability":"coupons_write","status":"UNSUPPORTED","reason":"ShopBonus integrations are read-only; native coupons are never created or changed.","last_verified_at":null},{"capability":"banner_embed","status":"LIMITED","reason":"Manual ShopBonus embed only; native storefront publication is not implemented for this platform.","last_verified_at":null},{"capability":"webhooks","status":"LIMITED","reason":"No authenticated provider event received yet.","last_verified_at":null},{"capability":"backfill","status":"LIMITED","reason":"Bounded native order discovery is implemented; not proven for this connection.","last_verified_at":null}]}
```

`POST /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/catalog-syncs  {"kind":"orders"}    (owner session)`

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json
X-Request-Id: 01a186ff-8344-71ac-a9a2-27d460842e40

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a186ff-8344-71ac-a9a2-27d460842e40","fields":{"kind":["The selected kind is invalid."]}}}
```

`POST /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/catalog-syncs  {"kind":"products"}    (owner session)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a186ff-8372-72c2-8416-7cd8582c56bb

{"data":{"id":"01m63fz0w2fm6verhz96v4xd8x","kind":"products","status":"completed","pages":1,"items":1,"removed":0,"error_code":null,"started_at":"2026-10-29T09:30:54+00:00","finished_at":"2026-10-29T09:30:54+00:00"}}
```

`POST /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/catalog-syncs  {"kind":"coupons"}    (owner session)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a186ff-841a-72a5-bec9-3cef167c7d64

{"data":{"id":"01m63fz119w7t1frb23yp4y5et","kind":"coupons","status":"completed","pages":1,"items":1,"removed":0,"error_code":null,"started_at":"2026-10-29T09:30:54+00:00","finished_at":"2026-10-29T09:30:54+00:00"}}
```

`GET /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/catalog-syncs    (owner session)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186ff-8456-710a-8ad6-4792f9b426ca

{"data":[{"id":"01m63fz119w7t1frb23yp4y5et","kind":"coupons","status":"completed","pages":1,"items":1,"removed":0,"error_code":null,"started_at":"2026-10-29T09:30:54+00:00","finished_at":"2026-10-29T09:30:54+00:00"},{"id":"01m63fz0w2fm6verhz96v4xd8x","kind":"products","status":"completed","pages":1,"items":1,"removed":0,"error_code":null,"started_at":"2026-10-29T09:30:54+00:00","finished_at":"2026-10-29T09:30:54+00:00"}]}
```

`GET /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/products    (owner session)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186ff-8478-70e0-b8bf-df82873d9af6

{"data":[{"external_id":"11","sku":"SF-11","name":"Stadsfiets Comfort","description":"Comfortabele stadsfiets met 7 versnellingen.","url":"https://fietsen-utrecht.nl/product/stadsfiets-comfort","image_url":"https://fietsen-utrecht.nl/img/sf-11.jpg","categories":[{"id":"5","name":"Stadsfietsen"}],"price_minor":49995,"regular_price_minor":54995,"sale_price_minor":49995,"currency":"EUR","type":"simple","status":"publish","visibility":"visible","stock_status":"instock","stock_quantity":4,"provider_updated_at":"2026-10-20T09:00:00+00:00","removed_at":null}],"meta":{"current_page":1,"last_page":1,"total":1}}
```

`GET /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/products?search=SF-11    (owner session)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186ff-849c-71a5-926d-b685756e223a

{"data":[{"external_id":"11","sku":"SF-11","name":"Stadsfiets Comfort","description":"Comfortabele stadsfiets met 7 versnellingen.","url":"https://fietsen-utrecht.nl/product/stadsfiets-comfort","image_url":"https://fietsen-utrecht.nl/img/sf-11.jpg","categories":[{"id":"5","name":"Stadsfietsen"}],"price_minor":49995,"regular_price_minor":54995,"sale_price_minor":49995,"currency":"EUR","type":"simple","status":"publish","visibility":"visible","stock_status":"instock","stock_quantity":4,"provider_updated_at":"2026-10-20T09:00:00+00:00","removed_at":null}],"meta":{"current_page":1,"last_page":1,"total":1}}
```

`GET /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/coupons    (owner session)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186ff-84c6-7090-9ac8-62e786acd45d

{"data":[{"external_id":"21","code":"welkom10","discount_type":"percent","amount":"10.00","amount_minor":null,"percent_bps":1000,"currency":"EUR","minimum_spend_minor":5000,"maximum_spend_minor":null,"usage_limit":100,"usage_limit_per_customer":1,"usage_count":3,"stackable":false,"exclude_sale_items":true,"free_shipping":false,"customer_restricted":false,"scope":{"product_ids":["11"]},"status":"publish","expires_at":"2026-12-31T23:00:00+00:00","active":true,"provider_updated_at":"2026-10-21T10:00:00+00:00","removed_at":null}],"meta":{"current_page":1,"last_page":1,"total":1}}
```

`GET /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/capabilities    (owner session)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a186ff-84e9-7357-ab68-ab80ba954093

{"data":[{"capability":"orders","status":"LIMITED","reason":"The connection has not passed all native verification checks (status setup_required).","last_verified_at":null},{"capability":"payments","status":"LIMITED","reason":"The connection has not passed all native verification checks (status setup_required).","last_verified_at":null},{"capability":"refunds","status":"LIMITED","reason":"The connection has not passed all native verification checks (status setup_required).","last_verified_at":null},{"capability":"products","status":"AVAILABLE","reason":null,"last_verified_at":"2026-10-29T09:30:54+00:00"},{"capability":"categories","status":"AVAILABLE","reason":"Categories as assigned to synced products.","last_verified_at":"2026-10-29T09:30:54+00:00"},{"capability":"coupons_read","status":"AVAILABLE","reason":null,"last_verified_at":"2026-10-29T09:30:54+00:00"},{"capability":"coupons_write","status":"UNSUPPORTED","reason":"ShopBonus integrations are read-only; native coupons are never created or changed.","last_verified_at":null},{"capability":"banner_embed","status":"LIMITED","reason":"Manual ShopBonus embed only; native storefront publication is not implemented for this platform.","last_verified_at":null},{"capability":"webhooks","status":"LIMITED","reason":"No authenticated provider event received yet.","last_verified_at":null},{"capability":"backfill","status":"LIMITED","reason":"Bounded native order discovery is implemented; not proven for this connection.","last_verified_at":null}]}
```

`GET /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt00/products    (owner session)`

```http
HTTP/1.1 404 Not Found
Content-Type: application/json
X-Request-Id: 01a186ff-850d-735c-a8a7-a4a1d39e29c6

{"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a186ff-850d-735c-a8a7-a4a1d39e29c6"}}
```

`GET /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/coupons    (employee with integrations.view only)`

```http
HTTP/1.1 403 Forbidden
Content-Type: application/json
X-Request-Id: 01a186ff-8836-7063-a70a-40219a9e8595

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a186ff-8836-7063-a70a-40219a9e8595","details":{"required_permission":"integrations.manage"}}}
```

`POST /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/catalog-syncs  {"kind":"products"}    (employee with integrations.view only)`

```http
HTTP/1.1 403 Forbidden
Content-Type: application/json
X-Request-Id: 01a186ff-8866-73de-823e-38f464edd89e

{"error":{"status":403,"code":"workspace_permission_denied","message":"You do not have permission to do this in this workspace.","request_id":"01a186ff-8866-73de-823e-38f464edd89e","details":{"required_permission":"integrations.manage"}}}
```

`POST /api/v1/workspaces/01m63fy0mr035f76ntds4qnty2/connections/01m63fy0qvw9b4k5szts0ppt39/catalog-syncs  {"kind":"products"}    (owner session; connection set to disconnected in the synthetic DB)`

```http
HTTP/1.1 409 Conflict
Content-Type: application/json
X-Request-Id: 01a186ff-88a0-71a0-8408-e45e9bcfe3ee

{"error":{"status":409,"code":"connection_disconnected","message":"This connection is disconnected. Create a new one.","request_id":"01a186ff-88a0-71a0-8408-e45e9bcfe3ee"}}
```


### Shopify catalog and code discounts (B26, Trello #32)

Same endpoints; a Shopify connection now has a native reader. Fixed Admin GraphQL 2026-10 read queries only (products with `read_products`; `discountNodes(query: "method:code")` with `read_discounts`; `shop { currencyCode }`), after the existing store identity, verified-domain and scope gate. Single-code `DiscountCodeBasic` and `DiscountCodeFreeShipping` become coupons; BXGY, app and bulk-code discounts are skipped. Percentages are read as exact decimals (0.00–1.00). Captured on a real local PHP HTTP server with synthetic Shopify GraphQL stubs (no real store): `docs/integrations/B26_SHOPIFY_CATALOG_HTTP_CAPTURE.json`. The stub also returned a BXGY discount, which is why the coupon pass reports 1 item.

`POST /api/v1/workspaces/01m63hyhz7bcezz0y276pnf17n/connections/01m63hyj0va8zb8t54kxasrnfw/catalog-syncs  {"kind":"products"}    (owner session)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a1871f-5417-716c-993c-5b31f560794a

{"data":{"id":"01m63hyn1v4z92f029ps3x6eaa","kind":"products","status":"completed","pages":1,"items":1,"removed":0,"error_code":null,"started_at":"2026-10-29T10:05:39+00:00","finished_at":"2026-10-29T10:05:39+00:00"}}
```

`POST /api/v1/workspaces/01m63hyhz7bcezz0y276pnf17n/connections/01m63hyj0va8zb8t54kxasrnfw/catalog-syncs  {"kind":"coupons"}    (owner session)`

```http
HTTP/1.1 202 Accepted
Content-Type: application/json
X-Request-Id: 01a1871f-54cf-7268-a019-565b82379e17

{"data":{"id":"01m63hyn6s39tmwt1etxzjpn14","kind":"coupons","status":"completed","pages":1,"items":1,"removed":0,"error_code":null,"started_at":"2026-10-29T10:05:39+00:00","finished_at":"2026-10-29T10:05:39+00:00"}}
```

`GET /api/v1/workspaces/01m63hyhz7bcezz0y276pnf17n/connections/01m63hyj0va8zb8t54kxasrnfw/products    (owner session)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1871f-54ff-7042-b94c-5138abdcefe6

{"data":[{"external_id":"632910392","sku":"SF-11","name":"Stadsfiets Comfort","description":"Comfortabele stadsfiets met 7 versnellingen.","url":"https://fietsen-utrecht.nl/products/stadsfiets-comfort","image_url":"https://cdn.shopify.com/s/files/stadsfiets.jpg","categories":[{"id":"77","name":"Stadsfietsen"}],"price_minor":49995,"regular_price_minor":null,"sale_price_minor":null,"currency":"EUR","type":"Fietsen","status":"active","visibility":null,"stock_status":"instock","stock_quantity":4,"provider_updated_at":"2026-10-20T09:00:00+00:00","removed_at":null}],"meta":{"current_page":1,"last_page":1,"total":1}}
```

`GET /api/v1/workspaces/01m63hyhz7bcezz0y276pnf17n/connections/01m63hyj0va8zb8t54kxasrnfw/coupons    (owner session)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1871f-5519-72a5-b47a-86efbeaf2790

{"data":[{"external_id":"1057371343","code":"WELKOM15","discount_type":"percent","amount":"15","amount_minor":null,"percent_bps":1500,"currency":"EUR","minimum_spend_minor":5000,"maximum_spend_minor":null,"usage_limit":100,"usage_limit_per_customer":1,"usage_count":3,"stackable":true,"exclude_sale_items":false,"free_shipping":false,"customer_restricted":false,"scope":null,"status":"active","expires_at":"2026-12-31T23:00:00+00:00","active":true,"provider_updated_at":"2026-10-21T10:00:00+00:00","removed_at":null}],"meta":{"current_page":1,"last_page":1,"total":1}}
```

`GET /api/v1/workspaces/01m63hyhz7bcezz0y276pnf17n/connections/01m63hyj0va8zb8t54kxasrnfw/capabilities    (owner session)`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a1871f-552f-70eb-b64c-631b6ce286b0

{"data":[{"capability":"orders","status":"LIMITED","reason":"The connection has not passed all native verification checks (status setup_required).","last_verified_at":null},{"capability":"payments","status":"LIMITED","reason":"The connection has not passed all native verification checks (status setup_required).","last_verified_at":null},{"capability":"refunds","status":"LIMITED","reason":"The connection has not passed all native verification checks (status setup_required).","last_verified_at":null},{"capability":"products","status":"AVAILABLE","reason":null,"last_verified_at":"2026-10-29T10:05:39+00:00"},{"capability":"categories","status":"AVAILABLE","reason":"Categories as assigned to synced products.","last_verified_at":"2026-10-29T10:05:39+00:00"},{"capability":"coupons_read","status":"AVAILABLE","reason":null,"last_verified_at":"2026-10-29T10:05:39+00:00"},{"capability":"coupons_write","status":"UNSUPPORTED","reason":"ShopBonus integrations are read-only; native coupons are never created or changed.","last_verified_at":null},{"capability":"banner_embed","status":"LIMITED","reason":"Manual ShopBonus embed only; native storefront publication is not implemented for this platform.","last_verified_at":null},{"capability":"webhooks","status":"LIMITED","reason":"No authenticated provider event received yet.","last_verified_at":null},{"capability":"backfill","status":"LIMITED","reason":"Bounded native order discovery is implemented; not proven for this connection.","last_verified_at":null}]}
```
