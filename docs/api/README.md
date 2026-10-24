# Shopbonus API reference

Machine-readable contract: [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1). This page is the readable companion: every endpoint shows an example request and a **real response captured from a running server**.

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

**My Vouchers**

`GET /api/v1/consumer/vouchers`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a61-cf5b-7126-a4ec-2167e223c746

{"data":[{"id":"01m5n639gjt0r0h2rbvj3aegm3","status":"issued","origin":"answer24_claim","code":"FIETS-7Q2K","webshop":{"name":"Fietsen Utrecht","slug":"fietsen-utrecht"},"title":"\u20ac10 korting op je volgende fiets","summary":"\u20ac10.00 from \u20ac50.00","min_order_minor":5000,"issued_at":"2026-10-23T20:09:06+00:00","expires_at":"2027-01-21T20:09:06+00:00","redeemed_at":null}]}
```

**Reserved is not a consumer status → 422**

`GET /api/v1/consumer/vouchers?status=reserved`

```http
HTTP/1.1 422 Unprocessable Content
X-Request-Id: 01a16a61-d013-70a3-805f-4210b7800dc5
Content-Type: application/json

{"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a16a61-d013-70a3-805f-4210b7800dc5","fields":{"status":["The selected status is invalid."]}}}
```

**Dashboard** (re-captured in B09 with the real wallet)

`GET /api/v1/consumer/dashboard`

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01a16a6f-b071-70d6-b1ce-5ff3c15e55f1

{"data":{"first_name":"Anna","email_verified":true,"wallet":{"eur_credit":{"unit":"eur_credit","available":220,"pending":300,"reserved":0,"total_earned":1220,"total_spent":1000,"total_expired":0,"next_expiry":{"amount":220,"at":"2027-10-23T20:24:21+00:00"}},"points":{"unit":"points","available":0,"pending":0,"reserved":0,"total_earned":0,"total_spent":0,"total_expired":0,"next_expiry":null}},"vouchers":{"issued":1,"redeemed":0,"expiring_within_30_days":0},"recent_vouchers":[{"id":"01m5n639gjt0r0h2rbvj3aegm3","status":"issued","origin":"answer24_claim","code":"FIETS-7Q2K","webshop":{"name":"Fietsen Utrecht","slug":"fietsen-utrecht"},"title":"\u20ac10 korting op je volgende fiets","summary":"\u20ac10.00 from \u20ac50.00","min_order_minor":5000,"issued_at":"2026-10-23T20:09:06+00:00","expires_at":"2027-01-21T20:09:06+00:00","redeemed_at":null}],"favorites":[]}}
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

## Shared error responses not yet produced by a real endpoint

Real per-endpoint captures for 401, 403, 409, 419, 422 and 429 now exist in the Auth section above. The generic 403 `forbidden` and the 500 below were rendered through the real application kernel on 2026-10-23 using temporary routes, because no endpoint produces them on purpose yet.

```text
401 {"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a168bd-f864-70ce-9573-0f6c6905e5fb"}}
403 {"error":{"status":403,"code":"forbidden","message":"You are not allowed to perform this action.","request_id":"01a168bd-f891-71fa-b745-e681283411ee"}}
404 {"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a168bc-4082-739c-a3cb-1995636d37a5"}}
422 {"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a168bd-f895-73d8-881b-0f4ac0620658","fields":{"email":["The email field must be a valid email address."]}}}
500 {"error":{"status":500,"code":"server_error","message":"An unexpected error occurred.","request_id":"01a168bd-f8b0-725d-912b-fc7c412e64d5"}}
```
