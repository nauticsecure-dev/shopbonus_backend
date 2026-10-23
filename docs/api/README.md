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

## Shared error responses not yet produced by a real endpoint

Real per-endpoint captures for 401, 403, 409, 419, 422 and 429 now exist in the Auth section above. The generic 403 `forbidden` and the 500 below were rendered through the real application kernel on 2026-10-23 using temporary routes, because no endpoint produces them on purpose yet.

```text
401 {"error":{"status":401,"code":"unauthenticated","message":"Authentication is required.","request_id":"01a168bd-f864-70ce-9573-0f6c6905e5fb"}}
403 {"error":{"status":403,"code":"forbidden","message":"You are not allowed to perform this action.","request_id":"01a168bd-f891-71fa-b745-e681283411ee"}}
404 {"error":{"status":404,"code":"not_found","message":"The requested resource was not found.","request_id":"01a168bc-4082-739c-a3cb-1995636d37a5"}}
422 {"error":{"status":422,"code":"validation_failed","message":"The given data was invalid.","request_id":"01a168bd-f895-73d8-881b-0f4ac0620658","fields":{"email":["The email field must be a valid email address."]}}}
500 {"error":{"status":500,"code":"server_error","message":"An unexpected error occurred.","request_id":"01a168bd-f8b0-725d-912b-fc7c412e64d5"}}
```
