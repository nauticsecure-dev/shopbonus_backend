# Durable saved-page AI backlog and recommended content gate — B82

This checkpoint adds backend orchestration and a separately versioned recommended coverage contract under owner delegation D-112. It does not recover the original175-check source or implement frontend journeys. No API endpoint is added.

## Backlog

Apply `2026_11_04_120000_create_cms_ai_bulk_runs`; B81 migration110000 and its pricing/review configuration also remain required. The private command `shopbonus:cms-ai-backlog` accepts plan/advance/submit/show, existing active Super Admin `--actor` numeric ID and owner-bound `--run` ULID. Console access is a trusted deployment/operator boundary, not a browser impersonation mechanism. Run source identity is immutable. No main-application DB is used by automated tests.

Planning freezes a source-row ceiling, source/target locales, kind and at most100,000 saved-page records. Each chunk scans at most200 source rows and records exact source revision/hash plus target revision. Legal, archived, missing/empty sources are excluded. New pages beyond the ceiling need another explicit plan. A source changing after its individual snapshot is not silently substituted. Examples are operator instructions, not claims of execution:

```sh
php artisan shopbonus:cms-ai-backlog plan --actor=<id> --kind=translate --source=nl --target=de --limit=1001 --batch=200 --steps=6
php artisan shopbonus:cms-ai-backlog advance --actor=<id> --run=<ULID> --batch=200 --steps=6
php artisan shopbonus:cms-ai-backlog submit --actor=<id> --run=<ULID> --batch=50
php artisan shopbonus:cms-ai-backlog show --actor=<id> --run=<ULID>
```

Submission requires an asynchronous queue, a shared lock-capable cache and current creator authority. A bounded batch has a20-second loop allowance; it enqueues existing canonical jobs rather than waiting for model calls. The canonical request independently checks the expected snapshot, closing the check/submission race; its request identity includes full source hash. Source hashes are shared with the B80 inventory. Stale/legal/archived/changed-target items are marked stale, never sent as a new source under the old plan. Canonical coalescing and exact request keys prevent duplicate execution. Request quota/configuration blocks leave pending work resumable; no provider-executed failure receives an automatic new attempt key. Monetary reservations remain enforced by B81 at execution time, not waived by bulk mode.

Reconciliation rotates through queued/review-required items so early pending reviews cannot hide later jobs. Result states distinguish queued, review_required, applied, rejected, failed and stale. Run status awaiting_review is not completion; completed means terminal processing states and may include rejections. It never certifies translation quality, approval or publication. Independent review and normal revision-protected publication remain mandatory. Failed ambiguous/provider-billed items require operator inspection/new explicit intent, not automatic retry. Resume pending work after fixing configuration or the UTC-day quota, with the same run/item request key. A large plan may take multiple days under the configured request/monetary caps.

The backlog covers saved CMS pages only. FAQ/email/shared-block/other model automation and SEO/GEO content generation are not implied. The1,001-source regression plans all records, then submits two bounded10-item batches with fake job dispatch; it does not claim1,001 genuine model completions or a staging throughput benchmark. Other tests cover stale snapshots, request quota/day resume, asynchronous-only mode, revoked creator, reconciliation and no automatic publication.

## Five recommended denominators and23 staging checks

`requirements/CMS_ACCEPTANCE_SOURCE.md` defines the new recommended contract and exact scope. `requirements/CMS_ACCEPTANCE.json` holds all five denominators and CMS-T01–CMS-T23, with assertions, required evidence, environment and reproducible executor instructions. Test keys, named executors, reviewed runner IDs, frontend build and evidence IDs deliberately remain unmapped. This is an execution-mapping ledger, not23 implemented browser executors or a passing original checklist. Fill those fields only from real reviewed executable journeys and actual execution.

The denominators are saved editable fields, locale bindings, media breakpoints, rendered component contexts and staging executors. Each requires independently reviewed nonempty explicit unique inventory IDs and the identical covered-ID set, current build and source references. Counts/percentages, unknown inventories, duplicate IDs and a self-selected subset cannot establish complete coverage. D05 is fixed to all23 executor IDs. D01–D04 additionally require exact-build frontend discovery; T09/T22 require genuine OpenAI confirmation. Structural contracts cannot prove truthful/exhaustive discovery or human/model quality; independent reviewers must inspect factual completeness and provider/browser effects.

The same Quality Center result/evidence engine enforces approved versioned test cases, positive unmocked assertions, latest exact fresh staging build, HTTP/DB/request-ID/screenshot artifacts, checksums/private storage/retention and independent active reviewer identity. Definition/source hashes invalidate old review when mappings change. Registry/contract/script bytes must match deployed Git source once mappings are reviewed. P0/P1 unresolved findings block. These rules add to the existing original175/60-provider-subrun gate; they do not replace it or map made-up original check IDs.

`php artisan shopbonus:cms-content-check --sha=<full deployed SHA>` is staging-only and fails closed. The controlled nightly/weekly/after-deploy runner now always evaluates **both** general and content gates after successful execution. The supplied after-deploy hook retains that nonzero exit code. Actual deployment-pipeline wiring, branch protections, staging server/scheduler, reviewed journey mappings and genuine independent executions remain NOT RUN. Production health probes are not promoted to staging journeys.

Synthetic tests exercise complete structural evidence for one denominator and reject partial/unknown/duplicate/fixture inventories, missing model evidence and missing source/executor mappings. They do not provide actual content acceptance. Frontend DE corrections, real responsive rendering/portal/UI, shared content/media management and original acceptance execution remain separate incomplete work.
