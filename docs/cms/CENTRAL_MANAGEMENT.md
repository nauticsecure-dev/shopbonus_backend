# Central content and media management — B83

Scope is backend-only and remains PARTIAL until the remaining sections below are implemented and actual staging/frontend evidence exists. No card-level completion is inferred from a passing fixture.

## FAQ taxonomy (B83a)

Migration `2026_11_04_130000_add_faq_category_hierarchy` adds parent links and optimistic revision counters to existing FAQ categories. Existing create accepts optional parent public ID and NL/EN/DE labels; existing admin/public lists retain their flat shape, with additive parent/revision/fallback metadata. PATCH category metadata requires its exact revision. Keys remain immutable. NL/EN names are required when replacing the names object; DE is optional but must be a bounded plain string. Invalid German arrays and empty name maps are rejected.

Hierarchy is limited to root + one subcategory level. Mutations lock involved rows in ID order, reject self/cyclic/deeper parents and refuse moving a root with children under another category. Explicitly detach/reparent children first. Stale updates return409. Creation/update is audited with before/after taxonomy snapshots. Publication/audience restrictions remain on individual items; category labels themselves are global taxonomy metadata. Public child responses include parent labels even if the parent has no visible items. They never include otherwise hidden questions/answers.

[30 actual isolated HTTP captures](../qa/B83_FAQ_HTTP_CAPTURE.json), reproduced with `CAPTURE_CMS_FAQ=1 python3 scripts/capture-cms-media.py`, include create/update/admin/public DE and401/403/404/409/422/423. Synthetic labels/items/accounts and confirmed-password session setup are explicit; main application data, real browser/CSRF and staging acceptance are untouched/NOT RUN. Existing CMS/API tests remain the compatibility baseline. This does not translate the frontend German dictionary.

## Media lifecycle (B83b)

Migration `2026_11_04_140000_add_cms_media_archive_state` adds soft archive state. PATCH asset state requires current media revision, a reason, current Super Admin and recent password confirmation. It increments the media revision and audits the change. Libraries exclude archived assets by default; `include_archived=1` explicitly lists them. Originals, derivatives, public URLs and immutable published snapshots are retained. Existing draft references may remain at the same field path; new archived references are rejected inside the content transaction. Archived assets cannot receive new recipes; historical restoration remains available.

POST asset `/replace` takes at most50 explicit page/locale/revision/path selections and an active replacement asset with an optional ready same-asset recipe. All selected drafts lock before assets; any stale revision, changed source field, duplicate path or invalid pin rolls back the whole batch. Each selected locale increments once. Neither publication nor historical revisions are changed. This uses the saved-page usage inventory and does not claim all external/frontend usages are known. No physical deletion is provided.

Canonical AI dispatch now waits for the outermost content transaction to commit, including synchronous configurations. A rolled-back multi-draft edit cannot call the provider while its budget reservation/intent is rolled back. The regression checks both rollback/no-call and commit/one-call.

[40 actual isolated HTTP captures](../qa/B83_MEDIA_MANAGEMENT_HTTP_CAPTURE.json), reproduced with `CAPTURE_CMS_MANAGEMENT=1 python3 scripts/capture-cms-media.py`, include archive/reactivate, both libraries, rejected new archived usage, selected replacement, preserved published preview, and401/403/404/409/422/423. Synthetic confirmed-session limitations are the same as B83a. Real browser/CSRF/staging remains NOT RUN.

## Remaining B83 boundaries

Immutable reusable text-block versions, locale pins and safe overrides are next. Existing B79 originals/recipes/derivatives/restore and B80 pinned-page usage/preview APIs remain available. Universal discovery and the28 recommended content evidence definitions remain unmapped until independently executed/reviewed.
