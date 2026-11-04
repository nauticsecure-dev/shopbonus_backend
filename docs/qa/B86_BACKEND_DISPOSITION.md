# Quality Center backend review — B86

Scope: Laravel/backend automation only. No frontend/UI/portal renderer, original-scope invention or genuine provider execution. No endpoint/schema/migration change; existing protected OpenAPI and Quality Center APIs remain available. Existing execution statuses describe regression assertions; separate independent evidence/release requirements remain mandatory.

| Finding | Current disposition |
| --- | --- |
| Exhaustive frontend discovery | Excluded from this backend assignment. Existing usage ingestion and independently reviewed frontend-inventory evidence requirements remain; exhaustive discovery is not proven. |
|175-check execution matrix | The gate already requires175 unique check definitions,60 distinct provider subruns, assertions, executor/environment/instructions, exact case versions and evidence/review references. Original approved source is absent; `QA_ACCEPTANCE.json` remains UNMAPPED. No invented check IDs or mappings. |
| Staging/deployment automation | Nightly/weekly staging schedules and exact-Git controlled runner/hook already existed (B84). B86 adds enforced backend impacted-case planning, remembered same-build impacts, exact approved executable versions/assertion minima, report reconciliation and an opt-in reusable deployment workflow. Actual host/promotion wiring and journeys remain NOT RUN. |
| Quality Center/Swagger portal | Existing authenticated QA APIs and protected OpenAPI JSON endpoint supply the backend. Dedicated UI/portal shell is frontend work and is unchanged. |
| Provider-backed PASS | Existing independent acceptance requires result-bound HTTP,DB,request IDs,screenshots and provider confirmations plus current independent authorized review. Ordinary imported JUnit remains execution evidence only. B86 additionally blocks wrong/missing/duplicate/under-asserted runner reports at import, so unrelated green assertions cannot satisfy the reviewed execution plan. Genuine provider facts still need independent evidence. |
| AI test execution | AI outputs remain draft proposals. Only reviewed, Git-bound scripts mapped to approved phpunit/postman/browser case versions execute; manual/AI proposal metadata is rejected as automatic executable coverage. Automatic generation/approval/execution of model-supplied code is not implemented or claimed. |
| Independent staging/provider execution | NOT RUN. Existing gates remain closed; no fixture or local regression substitutes for exact-deployment independent review. |

Deployment requirements: senior developer configures the existing staging origin, deployment SHA, distributed cache/scheduler, trusted self-hosted runner/root and actual reviewed executable/provider fixtures. Call the supplied reusable workflow from the real promotion pipeline and require success. This change supplies backend control code, not an installed staging environment or complete release certification.
