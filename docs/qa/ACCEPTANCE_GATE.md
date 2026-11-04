# Source-mapped acceptance evidence gate — B66

`shopbonus:qa-acceptance-check` evaluates reviewed evidence. It does not run tests, generate tests, certify frontend completeness, contact a provider or approve a release. Exit 1 means blocked, invalid input or unavailable evidence; exit 0 means only that the selected mapping's reviewed evidence is complete. `release_approved` is always false; Martijn retains final approval.

The checked-in `requirements/QA_ACCEPTANCE.json` is deliberately UNMAPPED. The approved original 175-check PDF v2 is still unavailable locally. The Trello export contains scenario summaries and inconsistent older check totals; it cannot substitute for individual source check IDs. No fictitious checks have been filled in. Tests use clearly synthetic IDs exclusively in disposable fixtures.

## Mapping contract

The reviewed JSON requires:

- `schema_version: 1`, `state: reviewed`.
- `source`: `version`, `path` relative to `requirements/`, and exact `sha256`. The source must exist under that directory, including after symlink resolution; mismatched/missing files block evaluation. Keep confidential source material private; do not commit restricted attachments without authorization.
- `checks`: exactly 175 original uniquely identified checks. Preserve original IDs and text, and record any approved policy deviation beside the assertions. All E2E-01–25 packages must be represented; no seven-checks-per-package assumption.
- `provider_subruns`: 60 additional uniquely identified definitions: installation, authorization, order, refund, storefront and uninstall for each of Shopify, WooCommerce, Wix, BigCommerce, Ecwid, Lightspeed C, PrestaShop, OpenCart, Shopware6 and Magento/Adobe. Unsupported/missing delivery remains a blocker; changing release scope requires an owner-reviewed contract change, not deletion of a row.
- `frontend_discovery`: a separate definition with an exact `frontend_git_sha` and required `frontend_inventory` artifact. This consumes independently reviewed discovery output; the Laravel gate does not discover React routes/actions/components/UI states itself.

Every definition has `id`, `package` (E2E-01–25), unique `test_key` (the exact imported JUnit class::test key), `case_version`, `assertions` (nonempty expected outcomes), `min_assertions`, `environment: staging`, named accountable `executor`, reproducible `instructions`, `required_evidence` and `review_evidence_id`. Provider definitions additionally require `provider` and `stage` matching the lists above. Instructions are descriptions, never shell commands executed by this service. Review an executable test in Git before using its key; AI proposals alone cannot satisfy this mapping.

Each check requires `http`, `database`, `request_ids` and `screenshot` evidence. Provider subruns additionally require `provider_confirmation`. Frontend discovery additionally requires `frontend_inventory`. Mapping IDs and test keys cannot be reused to make one assertion appear to cover many checks.

## Record the actual execution and independent review

Use existing protected Quality Center case, run and evidence APIs. Cases must be approved, at the mapped version. Runs must have an authenticated recorded executor, exact full staging deployment SHA, positive unmocked assertions meeting the mapped minimum, valid start/finish times and execution within `qa.stale_after_days`. Automated CI imports without a recorded human executor remain valid regression records but cannot pass independent acceptance. Only the latest result for each case on that exact staging build is eligible; a later failure cannot be hidden by selecting an older PASS.

Upload artifacts bound to their exact run/result. Existing run-level checksum deduplication means identical bytes uploaded again retain their original result binding; include actual result-specific identifiers in the evidence and never reuse a different result's evidence ID. Every artifact must remain unexpired, readable, decryptable and checksum-valid at evaluation time.

Machine-readable evidence contracts (JSON, except the screenshot):

| Kind | Required contents |
| --- | --- |
| http | `request.method`, `request.url`, `response.status`, `request_id`; include masked actual request/response and expected/actual assertions in the retained transcript |
| database | `before`, `after` arrays, nonempty `source_references`; empty snapshots are permitted for an explicitly reviewed no-change assertion |
| request_ids | nonempty `request_ids` array containing the evidence row's actual `request_id`; correlate logs/queues/audit using those IDs |
| screenshot | Actual PNG/JPEG/WebP artifact, with its result-specific UI state reviewed by the independent tester |
| provider_confirmation | `provider`, `store_reference`, `source_reference`, `observed_state`, `observed_at`, `genuine_provider: true`; retain authentic masked provider confirmation, not a fabricated assertion of genuineness |
| frontend_inventory | Exact mapped `git_sha`, nonempty `routes`, `actions`, `components`, `ui_states` arrays; retain the exhaustive discovery method, mappings and independent review alongside the inventory |

These structural checks cannot establish that a screenshot is the right screen or that a provider assertion is truthful. An authorized independent reviewer must inspect the actual artifacts and downstream effects; the gate never claims that JSON fields establish factual provider state or “zero forgotten functions.” One JSON evidence package can contain multiple evidence kinds if all content requirements are satisfied, but it must belong to this result.

The reviewer uploads an additional private JSON evidence file bound to the same run/result, then puts its public ID in the definition's `review_evidence_id`. Its fields are:

`check_id`, `definition_sha256`, `source_sha256`, `source_version`, `deployment_sha`, `environment`, `run_id`, `result_id`, `executor_user_id`, `reviewer_user_id`, `verdict: accepted`, `reviewed_at`, `evidence`.

Each `evidence` entry contains `kind`, `evidence_id`, `sha256`; exactly one reference per required kind. The review artifact cannot stand in for its own underlying proof. `definition_sha256` is generated by `QaAcceptance::definitionHash()` over the parsed definition excluding `review_evidence_id` (top-level keys sorted, JSON_UNESCAPED_SLASHES). Changing assertions, instructions, version, provider/stage or frontend build invalidates the review. Do not hand-calculate this hash using a different JSON serialization.

The uploader must currently be active and have QA Tester or Super Admin authority, and differ from the executor and original case author. The record's executor/reviewer IDs must agree with server-recorded identities; assigning another reviewer in JSON does not bypass this. Review time must follow execution and not be in the future. Open/fixed-awaiting-retest/retest-failed P0/P1 findings block the gate. Findings must be independently closed through the existing workflow.

## Deployment integration

Run in the existing QA database with private evidence storage available. Example commands describe operator execution, not recorded results:

```bash
php artisan shopbonus:qa-discover --sha="$APP_RELEASE_SHA"
# Independent runners execute reviewed staging/browser/provider suites and import their real reports/evidence.
php artisan shopbonus:qa-acceptance-check --sha="$APP_RELEASE_SHA" --discover
```

The final `--discover` mode requires Laravel environment `staging` and a full SHA exactly matching configured `qa.release_sha` (`APP_RELEASE_SHA`). It scans the deployed backend again, invalidates changed fingerprints and additionally requires every active route to be documented and have current unmocked approved coverage according to existing QaStatus. The caller must preserve its nonzero exit code before promotion; never use `|| true` or a success-only artifact check. Discovery itself does not run impacted tests. Keep credentials in deployment configuration, not arguments/artifacts.

This is an invocable hook, not an installed deployment pipeline. Existing CI still performs backend regression/discovery/JUnit import; no external staging environment, workflow secret, nightly/weekly full journey runner or release branch protection was configured here. Production five-minute probes remain read-only. Full staging scheduling/controlled runner integration and impacted-test execution remain OPEN and must use reviewed version-controlled tests, distributed locks, bounded limits and independent real evidence. AI commands are never executed.

Quality Center UI, self-hosted Swagger/Stoplight shell, frontend discovery implementation, genuine provider/staging execution and final release approval remain separate incomplete deliverables. Existing protected API/OpenAPI endpoints are retained; this checkpoint adds no `/api/v1` endpoint or response changes and requires no migration.

B84 adds a [controlled staging runner](STAGING_RUNNER.md), actual nightly/weekly command schedules and a supplied after-deploy hook. Script registration remains deliberately unmapped/disabled until reviewed executable journeys are committed. The external deployment pipeline and staging/provider execution remain NOT RUN. The runner always calls this independent gate after successful execution and cannot turn automatic JUnit imports into independent acceptance.
