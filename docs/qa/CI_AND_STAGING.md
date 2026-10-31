# CI and independent QA evidence

The existing CI workflow tests SQLite and MySQL on PHP 8.4. It now records the actual execution start time and checked-out commit, emits JUnit, and imports that report through the existing Quality Center services into a separate ephemeral SQLite database. It discovers current routes and backend source components there. No production/staging database or provider credentials are needed for this regression pipeline.

The test step retains its failure exit code. The evidence step runs after a failed test step when a report exists; it never makes the failed job green. Imported CI runs use environment `ci` and all results are conservatively labelled mocked. New test cases remain draft and no coverage is auto-approved. A CI pass does not certify a native installation, real settlement, browser journey or the inaccessible 175-check PDF.

Each database job retains a 14-day artifact named with its PHP version, database and exact tested SHA:

- `results.json`: sanitized run/results, original report SHA-256, real execution time, inventory snapshot and limitations.
- `results.junit.xml`: normalized JUnit exported through the existing redacting service; original failure statuses survive.
- `routes.json`: actual current route inventory.
- `executed-at.txt` and `tested-sha.txt`: execution/build provenance.

The original raw report, generated application key, `.env`, QA database and credentials are excluded from uploads. Both jobs use full Git checkout history for later build checks. Workflow execution itself is not verified until the branch PR has run successfully.

Local runner example (against an explicitly selected disposable database):

```bash
php artisan shopbonus:qa-discover --sha="$TESTED_SHA"
php artisan shopbonus:qa-import "$REPORT_FILE" --suite=full --environment=ci \
  --sha="$TESTED_SHA" --executed-at="$EXECUTED_AT" --mocked \
  --output=/private/qa-artifacts/results.json
```

`--output` creates a new `.json` and sibling `.junit.xml` with private permissions. Existing files/symlinks are refused. An export failure returns failure while retaining the imported run. Reports cannot be attached to a schedule with a different suite/environment; this is enforced by CLI and API. CI is not a schedulable production environment.

Source inventory now includes services, models, controllers, requests, resources, rules, enums, providers, support/logging and integration classes alongside the existing jobs, events, listeners, policies, middleware, commands, schedules and migrations. Endpoint fingerprints include a digest of all backend PHP source/config/routes/migrations, excluding generated bootstrap caches. This deliberately invalidates all endpoint evidence after a backend source change; a transitive dependency change cannot silently leave old evidence current. Finer dependency selection can be introduced later with equivalent negative coverage.

For deployment, the senior developer runs `shopbonus:qa-discover --sha=<actual deployed SHA>` against that deployment after migrations. Staging runners execute independently configured browser/Postman/provider suites and import their actual JUnit with `--environment=staging`, real execution time and the matching schedule ID. Production schedules remain read-only `safe` probes. Deploy hooks, native credentials, scheduled staging runners and portal hosting are not configured by this CI change. Keep missing runs UNTESTED/BLOCKED and provider claims NOT RUN.

Hassan must independently execute and attach the PDF v2 evidence and native subreports; Martijn retains release approval. The supplied PDF attachment currently returns HTTP 401, so no 175-row mapping was invented.

Implementation references: [workflow](../../.github/workflows/ci.yml), [real HTTP captures](B35_CI_HTTP_CAPTURE.json). GitHub's [workflow expressions](https://docs.github.com/en/actions/reference/workflows-and-actions/expressions) and [artifact action](https://github.com/actions/upload-artifact) document the failure-preserving status checks and artifact options used here.
