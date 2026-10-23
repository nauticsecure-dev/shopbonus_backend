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

## Shared error responses not yet produced by a real endpoint

Real per-endpoint captures for 401, 403, 409, 419, 422 and 429 now exist in the Auth section above. The generic 403 `forbidden` and the 500 below were rendered through the real application kernel on 2026-10-23 using temporary routes, because no endpoint produces them on purpose yet.

```text
401 {"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a168bd-f864-70ce-9573-0f6c6905e5fb"}}
403 {"error":{"status":403,"code":"forbidden","message":"You are not allowed to perform this action.","request_id":"01a168bd-f891-71fa-b745-e681283411ee"}}
404 {"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a168bc-4082-739c-a3cb-1995636d37a5"}}
422 {"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a168bd-f895-73d8-881b-0f4ac0620658","fields":{"email":["The email field must be a valid email address."]}}}
500 {"error":{"status":500,"code":"server_error","message":"An unexpected error occurred.","request_id":"01a168bd-f8b0-725d-912b-fc7c412e64d5"}}
```
