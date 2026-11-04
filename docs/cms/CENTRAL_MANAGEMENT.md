# Central content and media management — B83

Scope is backend-only and remains PARTIAL until the remaining sections below are implemented and actual staging/frontend evidence exists. No card-level completion is inferred from a passing fixture.

## FAQ taxonomy (B83a)

Migration `2026_11_04_130000_add_faq_category_hierarchy` adds parent links and optimistic revision counters to existing FAQ categories. Existing create accepts optional parent public ID and NL/EN/DE labels; existing admin/public lists retain their flat shape, with additive parent/revision/fallback metadata. PATCH category metadata requires its exact revision. Keys remain immutable. NL/EN names are required when replacing the names object; DE is optional but must be a bounded plain string. Invalid German arrays and empty name maps are rejected.

Hierarchy is limited to root + one subcategory level. Mutations lock involved rows in ID order, reject self/cyclic/deeper parents and refuse moving a root with children under another category. Explicitly detach/reparent children first. Stale updates return409. Creation/update is audited with before/after taxonomy snapshots. Publication/audience restrictions remain on individual items; category labels themselves are global taxonomy metadata. Public child responses include parent labels even if the parent has no visible items. They never include otherwise hidden questions/answers.

[30 actual isolated HTTP captures](../qa/B83_FAQ_HTTP_CAPTURE.json), reproduced with `CAPTURE_CMS_FAQ=1 python3 scripts/capture-cms-media.py`, include create/update/admin/public DE and401/403/404/409/422/423. Synthetic labels/items/accounts and confirmed-password session setup are explicit; main application data, real browser/CSRF and staging acceptance are untouched/NOT RUN. Existing CMS/API tests remain the compatibility baseline. This does not translate the frontend German dictionary.

## Remaining B83 boundaries

Soft media archive/reactivation, source-aware selected-draft replacement and immutable reusable block versions/locale pins/overrides are the next implementation boundaries. Existing B79 private originals/recipes/derivatives/restore and B80 pinned-page usage/preview APIs remain available. No physical media deletion or unreviewed global publication is authorized by this checkpoint. Universal discovery and the28 recommended content evidence definitions remain unmapped until independently executed/reviewed.
