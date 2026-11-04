# AI support settings and connectivity review — 2026-11-04

Local implementation verified. Deployment acceptance remains partial.
Backend branch HassanDev, base 9546163; frontend branch HassanDev, base 1939a6b.
Scoped changes are uncommitted; unrelated working-tree changes were preserved. No migration, commit, push or deployment.

## Changes

- Draft saves send the current policy without replacing advanced fields with defaults. Tone, empathy, answer detail, word limits, deadline, cost limit and fallback model are editable. Options and numeric limits match SupportPolicy validation; decimal EUR limits are supported.
- Fixed server/provider controls remain disabled explicitly, independently of their displayed labels. Saving preserves their loaded values. Their fixed-control explanations, tabs, form labels/options, monitoring labels, notices and errors use NL/EN/DE dictionaries through the existing LocaleContext. Protocol identifiers and diagnostic JSON remain diagnostic data. Server errors map to localized authorization/session/validation/conflict/model-verification feedback rather than displaying English server prose.
- `shopbonus:probe-support-connectivity` opens a WebSocket using the deployed Reverb connection, validates the upgrade, subscribes to a random private channel with a server-signed authorization, publishes a synthetic nonce using the Laravel Pusher broadcaster and requires receipt of that exact nonce/channel/event. Only the nonce is broadcast; no customer data. Existing Ratchet/PSR7 dependencies handle framing/handshake; no package added. TLS peer validation remains enabled. Connection/read/publish timeouts and a five-second receive deadline bound the probe; the socket always closes.
- The existing scheduler runs the probe every minute without overlapping. Monitoring reads cached observations rather than performing network calls on GET. Evidence expires after 90 seconds and is invalidated when broadcaster credentials/options/origin change. The latest failed probe replaces success; errors are sanitized. States: connected, failed, stale, not_measured, not_configured. Only a fresh connected result reports `synthetic_round_trip_received`; older dispatch evidence is never upgraded from publishing alone. Additive checked-at/latency/scope fields and visible status metrics make evidence reviewable.

## Executed verification

- PHP 8.4: SupportAdminTest + SupportAdvancedControlTest + SupportConnectivityTest + OpenApiCoverageTest: 21 PASS, 8,032 assertions.
- Actual isolated local Reverb: matching private-channel receipt PASS; invalid credentials rejected PASS; recovery PASS. Evidence: [SUPPORT_REVERB_LOCAL_PROBE.json](api/SUPPORT_REVERB_LOCAL_PROBE.json). Reproduce: `php -d xdebug.mode=off scripts/verify-support-connectivity.php`.
- Actual loopback HTTP with isolated synthetic SQLite: monitoring 200, unauthenticated 401, ordinary-user 403 and invalid-filter 422 PASS. Evidence: [SUPPORT_MONITORING_REVIEW_HTTP_CAPTURE.json](api/SUPPORT_MONITORING_REVIEW_HTTP_CAPTURE.json). Reproduce: `php -d xdebug.mode=off scripts/capture-support-monitoring-review.php`. Temporary databases, servers and logs cleaned up.
- Frontend TypeScript and targeted ESLint PASS. `node scripts/test-support-settings.cjs` PASS: rendered form edits/save payload, preservation of untouched policy values, supported/fixed controls, NL/EN/DE presentation and localized validation errors. This is a controlled component harness with stubbed React hooks/API exports, not a browser journey or genuine provider test.
- Changed application PHPStan: zero errors. Scoped Pint fixed formatting; final scoped check recorded below.

## Deployment and evidence limits

Run the existing Laravel scheduler and use a cache shared by scheduler and web processes (for example the deployment's Redis store); array cache cannot carry evidence between processes. Set `BROADCAST_CONNECTION=reverb` and the existing Reverb connection credentials/options. The optional `SUPPORT_AI_REVERB_PROBE_ORIGIN` must be permitted by Reverb; when absent, the probe uses APP_URL. An empty Reverb origin allowlist intentionally fails. After changing deployment configuration, rebuild its configuration cache using the established deployment workflow.

The probe establishes server-to-Reverb private-channel subscription/publication/delivery. It does not establish a user's browser connectivity, Laravel conversation-channel authorization, queue-worker health, live OpenAI availability, model quality or uptime. Deployment TLS/reverse-proxy/scaling, browser reconnect and representative provider acceptance were NOT RUN. Existing worker/provider timestamps remain separately labeled observations. Full application suites and MySQL were NOT RUN.

Next action: review the scoped diff, choose a tested implementation SHA, deploy using the existing scheduler/shared cache and confirm a fresh connected observation through the monitoring UI. Checklist remains unchecked until an implementation commit supplies its SHA, per AGENTS.md.

Final scoped Pint and scoped whitespace checks: PASS. No production/deployment changes were made.
