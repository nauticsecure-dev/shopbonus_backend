B61 merge `be4a3e5` pushed; parents15e58dc andb04ea65. GitHub PR#5 confirmed MERGEABLE (checks pending/UNSTABLE, not a conflict). CI41650765241 in progress at verification. Backend Pint(app/tests/config/routes/database), PHPStan and diff PASS; focused SQLite162/2001 plus68/8445 and MySQL99/883 PASS. Local broad image-codec failures remain documented below; no full green claim. Working tree clean before this documentation closeout. Next: inspect CI results; do not merge/deploy automatically.

# B61 — Resolve PR#5 conflicts with current main (2026-11-03, Codex)

Branch frank/review-fixes; merge parents review15e58dcbc172d6d158beeda6b64d958743050e21 and mainb04ea65740d0ca680d9d3888e5815119de8a2c0a (PR#6). Clean working tree before merge; user explicitly requested conflict repair. Preserved incoming backend features, candidate native package assets and both agents' history/captured evidence. No main merge, force push, deployment or frontend feature work. Combined API merge retains main's new operations plus review examples; inventory regenerated470 route entries /471 non-HEAD operations /412 paths, zero documented access drift.

Conflict decisions: retain strict order-bound Shopware/Magento flat product snapshots and main's monetary breakdowns; update incoming fixture rows to include explicit order/type/parent evidence. Keep improved Wix/Ecwid limitations and current native package delivery status; installation acceptance remains NOT RUN. Later explicit D-109 approval+recorded-settlement policy governs NEW rewards, superseding main's older2026-11-02 immediate-credit notes; financial behavior and frozen historical snapshots preserved. Incoming default-immediate test expectations/journey now follow D-109; 100% EUR4.75 fixture retains exact reward and reversal checks. Keep main's current client-path defaults and align stale support test to configured wallet route. Add missing structured return annotation to incoming historical voucher verification method; no runtime change there.

Validation: initial full SQLite1348total/1310pass/27skip/10fail+1error,22191assertions; NOT green. Stale inventory/policy/route assertions repaired and rerun: SQLite162pass/2001assertions. Merged connector/pause/domain/About/cockpit/API focused68pass/8445assertions; MySQL connector/pause/domain/About/cockpit/affiliate99pass/883assertions. PHPStan512MiB PASS. Full-suite enrichment failures reproduce independently: local PHP8.4/GD imagescale(...IMG_BICUBIC_FIXED) returnsfalse even for a valid1536x1024 truecolor image. Isolated enrichment12total/7pass/4fail+1error; no provider call or production image behavior changed to mask the environment limitation. Alternate installed PHP cannot start due missing libnetsnmp. Full suite not rerun after assertion repairs; current full CI unverified. Backend Pint/diff result recorded in closeout; imported external package formatting left as main shipped it, preserving source/archive consistency.

Incoming migrations:2026_11_02_190000_add_dispute_compensation_status.php and2026_11_03_200000_add_connection_pause.php, exercised on disposable test DBs only. Existing support execution-guard migration retained. Main shopbonus DB and sharedRedis untouched. Coupon writer/deployed provider/browser/staging/recovery evidence remain open; candidate package source is not acceptance proof. Exact next: commit merge, push review branch, verify PR mergeability and report final SHA/checks. At checkpoint merge staged plus scoped resolution/docs fixes; no unrelated user changes.

---

B60 implementing SHA `5cf9707`; focused checks and nine actual HTTP exchanges passed. No migrations. Coupon generation and deployed/client acceptance remain open as detailed below.

# B60 — Verified-domain reset and Step2 backend review (2026-11-03, Codex)

Branch frank/review-fixes, parent0a9ac29; draft PR#5. Added explicit privileged `shopbonus:change-verified-domain` console workflow with current verified active Super Admin, expected-domain guard, authorized change case and reset-verification confirmation. Ordinary profile PUT remains409 domain_locked; service freshly locks/rechecks webshop, preventing stale-model bypass. Target normalized to canonical HTTPS public hostname. Suspended/rejected shops cannot bypass lifecycle restrictions.

One transaction clears business confirmation, ownership/current verified domain and approval, returns awaiting_verification, supersedes pending/submitted challenges and disconnects every current webshop connection using existing ConnectionManager. Credentials wiped, credential generations advanced, current readiness reset. Historical verified attempts, signed agreements, orders and wallet evidence preserved. Review flags preserved; existing agreement/lifecycle rules still govern reapproval. Old expected-domain replay fails. New challenge creation locks/rechecks current domain and completion compares proof against current profile, closing stale-domain verification races. Remote provider uninstall/revocation is a separate operator action, not falsely claimed by local disconnect.

Executed SQLite domain/onboarding/Step2/agreement48pass/894assertions; MySQL domain/onboarding/Step234pass/305assertions. API coverage/inventory5pass/8269assertions; PHPStan512MiB/Pint/diff PASS. Tests include explicit confirmation/options, unauthorized/revoked actors, stale expected-domain, lifecycle restrictions, credential generation reset, historical agreement/verified-proof preservation, stale profile ownership and domain change during DNS check. Nine actual local HTTP exchanges after actual console reset: profile read, ordinary-change409, foreign404, reset profile/connections, stale proof409, confirmation-required409, reconfirmation200 and new challenge201. Isolated SQLite/array cache, synthetic accounts, MFA/owner step-up disabled locally for capture only. Private fixture DB/storage/keys/tokens/server removed; mainDB/sharedRedis untouched. No migrations or API routes.

API README/OpenAPI updated with real captures; procedure docs/admin/DOMAIN_CHANGE.md. Status domain-change backend boundary DONE after focused evidence. Whole Step2 acceptance PARTIAL: coupon generation remains UNSUPPORTED because no connector implements an authorized coupon writer; the conditional Trello requirement does not turn coupon reads into creation. No new provider write scopes, creation or capability promotion. Future backend writer needs durable idempotency/uncertain-result reconciliation and immutable offer-term mapping before live create/checkout proof. Existing inventory/import stays supported without claiming checkout acceptance.

Frontend NL/EN/screenshots/navigation remain outside backend-only scope. Actual deployed browser/autosave/queued-worker/import-recovery/activation acceptance and approved visual comparison remain NOT RUN/unverified. Foreign-tenant404 concealment follows recorded policy; do not change to403. D-109 financial timing unchanged. Exact next: commit/push domain boundary and inspect CI; senior uses authorized domain procedure, removes old remote provider installation, reconfirms/reverifies newdomain and reconnects fresh integrations. A separate coupon-write backend boundary and genuine provider acceptance remain open. Uncommitted checkpoint scope B60 service/command/tests/API/capture/docs only; no frontend/main merge/deploy/Trello changes.

---

B59 implementing SHA `f115f44`; focused tests and actual local HTTP/console evidence passed. No migrations; deployment acceptance remains open as detailed below.

# B59 — Consumer trends and explicit acquisition workflow (2026-11-03, Codex)

Branch frank/review-fixes, parentee1f2b6; draft PR#5. Daily new_consumers now uses consumerAccounts, matching consumer intent/staff/test exclusions independently of webshop membership. Current active-account KPI status filtering remains unchanged; trend counts registrations in the selected period.

Added privileged deployment-console `shopbonus:voucher-acquisition` workflow, reusing VoucherInventory.recordAcquisition. Requires voucher/transaction public IDs, independently reviewed case reference, explicit verified-link attestation and verified active Super Admin actor. Service freshly reads/locks actor, voucher and transaction; approved/nonduplicate/same consumer/same webshop/pre-issuance checks remain enforced. Link stays immutable/idempotent; one append-only acquisition event and audit capture actor/source/reference atomically. No wallet-spend inference, provider lookup, financial ledger/spendability/settlement change, API route, migration or frontend implementation. Console access remains required; actor is attribution, not console authentication. Evidence references are case IDs only; actual source must independently demonstrate the exact acquisition link.

Executed SQLite cockpit/API/inventory28pass/8546assertions; final API coverage/inventory5pass/8269assertions after capture update. MySQL cockpit23pass/277assertions. PHPStan512MiB/Pint/diff PASS. Tests include member consumer inclusion, merchant/test exclusion and out-of-period registration, missing attestation/reference, unverified/revoked/suspended/nonadmin actor, pending/duplicate/future/foreign sources, invalid reference, immutable conflicting link, idempotent event/audit. No full current-head suite run.

Three actual HTTP exchanges in docs/qa/B59_COCKPIT_HTTP_CAPTURE.json: isolated local SQLite/array cache, synthetic consumer with active shop membership plus merchant-only account; custom period before/after actual console command and acquisition-network voucher drill-down. Source is synthetic, not genuine provider evidence. Authenticated real local backend only; deployment/browser/custom-period acceptance, resolved actions and automatic refresh remain NOT RUN. Existing60-second cockpit cache preserved; never flush sharedRedis. Local fixture DB/storage/keys/tokens/server removed; mainDB/sharedRedis untouched.

Procedure docs/admin/ACQUISITION_RECONCILIATION.md and actual API README/OpenAPI examples updated. Status backend fixes complete after focused evidence; deployment acceptance still OPEN. Exact next: commit/push this boundary, inspect CI, and senior runs the documented reconciliation only where independently verified evidence exists; otherwise leave Other/Unknown. Client/deployment owner verifies configured real-service periods/drill-down/actions/refresh. No main merge/deploy/Trello updates. Uncommitted checkpoint scope B59 source/command/tests/API/capture/docs only.

---

B58 implementing SHA `6ed1395`; focused checks and14 authenticated local HTTP exchanges passed. No migrations. Remaining external acceptance and exact next action are recorded below.

# B58 — Require the reviewed About publication revision (2026-11-03, Codex)

Branch frank/review-fixes, parentf57e6e6; draft PR#5. Both About publication aliases now require a positive integer revision and always pass it to CmsService's existing transactional locked comparison. Missing/null/invalid422, stale409 draft_changed. No auto-reread/retry or change to restore/scheduler semantics. Generic CMS publication already required revision and remains unchanged.

Tests now submit the draft revision actually reviewed. New negative coverage checks both aliases, missing/null/zero/noninteger/future revisions, no publication revision/audit side effects and successful reviewed publication. Existing stale-after-save public-content preservation remains covered. SQLite full CMS plus API/inventory71pass/8904assertions; MySQL About15pass/135assertions; PHPStan512MiB/Pint/diff PASS. Full current-head CI NOT RUN.

Fourteen real HTTP exchanges in docs/qa/B58_ABOUT_PUBLICATION_HTTP_CAPTURE.json: actual local Laravel server, isolated SQLite/file session, synthetic Super Admin login/password confirmation, edit/save/preview/publish/restore, missing/stale rejection on both aliases. MFA disabled locally for capture only; no auth middleware bypass. API README/OpenAPI publish schemas require revision and include actual200/409/422 responses. Private fixtures, sessions, keys and local server removed; mainDB/sharedRedis untouched. No migrations/config/frontend changes.

Status: backend revision bypass FIXED. Approved authentic imagery/company records, untranslated frontend controls, approved-design comparison, complete browser acceptance and deployed scheduler execution remain OPEN/NOT RUN. Synthetic content is not approved company evidence. Local real-service requests do not establish deployment acceptance. Exact next: commit/push this boundary and inspect CI; senior/client owner supplies approved content and runs browser/scheduler acceptance against configured deployment. No main merge/deploy/Trello updates. Uncommitted checkpoint scope: B58 controller/tests/API/capture/checklist/handoff only.

---

B57 implementation `ffa436d` committed and pushed to draft PR#5. Focused results and four HTTP captures are recorded below; working tree clean before this SHA closeout. Next: inspect current-head CI and perform the documented external provider acceptance after senior configuration.

# B57 — Native connector mapping review (2026-11-03, Codex)

Branch `frank/review-fixes`, parent `8fb782e5513e1b2f9800be4dcb50199c2a028a97`, draft PR#5. B56 inventory implementation is committed8fb782e. B57 implements bounded, order-bound flat Shopware product/Magento simple-root line snapshots with exact row money, explicit provider amount bases and unknown eligibility. Mixed/composite, duplicate, foreign-order, excessive and malformed snapshots fail as a whole. Shopware association is bounded101 to detect over100 and requires order_line_item read permission; denied provider reads stop processing. Existing ingestion last-known fallback remains unchanged.

Ecwid explicit refundedAmount maps as exact reported refund total with status/bounds checks; absent stays unknown, contradictions become unknown status. Authenticated current profile currency supports amounts but is not immutable historical currency. Settlement times remain null. Wix already clears transaction-created timestamps; capability now correctly reports LIMITED payment evidence. Lightspeed C cash refund totals/time remain unknown; returns/credit invoices are not reimbursement proof. D-109 approval PLUS independently recorded settlement is unchanged. No financial activation, migrations or frontend/native module source.

Executed SQLite connector/ingestion/recovery49pass/343assertions; final added transport failure test NativeStoreBinding2pass/16assertions. MySQL initial focused17pass/129assertions, final normalizer wiring3pass/13assertions and transport2pass/16assertions (overlapping selections, not additive coverage). API coverage/inventory5pass/8261assertions. PHPStan512MiB PASS, Pint PASS. Four actual local HTTP captures in docs/qa/B57_CONNECTOR_HTTP_CAPTURE.json, isolated SQLite with synthetic normalized fixtures; serialization evidence, NOT provider/ingestion acceptance. Existing database refunded_minor zero defaults do not establish missing cash evidence. API README/OpenAPI include captured responses. Fixture DB/storage/keys/tokens removed; mainDB/sharedRedis untouched.

Status: backend mapping boundary complete after focused checks; whole provider cards remain PARTIAL. PrestaShop ZIP, OpenCart extension, Shopify native theme/banner publication and Adobe async app remain missing/pending; these native deliverables exceed Laravel-only scope. All real authorization/order/refund/disconnect/downstream runs, staging/worker/infrastructure proof, independent E2E packages and original175-check PDF mapping remain NOT RUN/unverified. No claim of production readiness. Baseline b3016cd full CI remains the latest recorded green evidence; gh pr checks reported no checks for8fb782e at review time, so current full suite is NOT RUN here.

Exact next: commit/push this B57 boundary, inspect new CI, then senior configures provider scopes/test stores and executes recorded provider subruns. Resolve native deliverables separately within authorized scope; retain release NO-GO until independent evidence exists. Uncommitted scope at checkpoint: B57 connector/config/tests/API/spec/checklist/docs only. No main merge/deploy/Trello changes.

---

# B56 — Release inventory and acceptance evidence (2026-11-03, Codex)

Branch frank/review-fixes, baseline b3016cd; PR#5. Runtime466 route entries /467 non-HEAD operations /408 unique paths, zero access/permission drift. Added repeatable `shopbonus:api-routes --markdown` without changing default JSON/API response. Generated inventory now lists accepted platform roles, authentication classification, workspace scope, step-up and expanded rate middleware; a checked-in snapshot test catches stale routes/access/roles. Markdown bypasses agent output cleanup to preserve paragraphs.

Executed SQLite API/access/journey/registry38pass/10982assertions; MySQL inventory/registry4pass/502assertions. PHPStan512MiB/Pint/diff PASS; composer audit0advisories/0abandoned. Full baseline CI41603012663 SUCCESS all3jobs: SQLite1252pass+27skip/21145assertions; MySQL1275pass+4skip/21236assertions. Downloaded sanitized artifacts and recorded hashes/provenance in release/BACKEND_CI_2026-11-03.json. Tested merge e44ca9a409fd54759b2adc921a94283fb7355816 and b3016cd share tree4086f225f86eca1043e6df98e4826c03ecbb3c85. This baseline does not cover the new renderer change; replacement CI pending.

Release wording now follows D-109 approval+recorded settlement; runtime/historical reward behavior unchanged. INDEPENDENT_ACCEPTANCE.md retains25 UNMAPPED/NOT RUN/MISSING package slots, not fabricated175-check mapping. Original PDF rechecked HTTP401; native packages/storefront delivery, genuine provider install/order/refund/disconnect/downstream runs, deployed workers/staging and independent release approval remain open. NO-GO persists. Native modules/storefront code is outside AGENTS.md Laravel-only scope; no frontend implementation or provider activation. No API/schema/migration/mainDB/sharedRedis changes.

Next exact: commit B56; continue new connector review by auditing Shopware/Magento line items, Lightspeed/Ecwid refund/time limitations and stale Wix/Ecwid docs against verified contracts. One ticket boundary at a time. Current uncommitted B56 source/tests/docs only; no main merge/deploy/Trello edits.

---

# B54/B55 — Backend impersonation and cockpit drill-down (2026-11-03, Codex)

B54/B55 implementation `29c02b2` committed/pushed to draft PR#5. Focused checks and41 real HTTP captures passed; no frontend files or migrations changed. Full new CI remains pending. Exact next: inspect PR#5 CI and use docs/admin/COCKPIT_BACKEND_HANDOFF.md for client integration. Working tree was clean before this documentation closeout.

Branch `frank/review-fixes`, base5a84660 (full CI41597287992 SUCCESS all3jobs); draft PR#5. User explicitly restricts work to backend. No frontend files changed.

B54: current initiator active/verified/Super Admin/required-MFA authority is re-read on every support-token request; cached model relations cannot grant access. Invalid authority closes the session/token with generic401 and attributed denied-request audit; restored authority cannot revive it. Target/token binding checked; start re-reads/locks and authorizes initiator before target inspection/token creation. Existing target restrictions/expiry preserved. Ownership-transfer write family (including cancellation) added to impersonation denylist. Model lifecycle status remains distinct from live authorization; invalid authority closes/audits on use.

B55: existing full data.kpis and period=today verified with nonzero fixture orders/Answer24/inventory/points/disputes. No new duplicate KPI endpoint/cards. Added voucher webshop_id scoping and webshop alias for vouchers/activity; malformed/conflicting filters422, valid unknown shop200empty, same normalized aliases accepted. Filter precedes pagination. Positive page validation on users/webshops/vouchers/activity; webshop pagination links preserve filters. Existing metadata/payload/permissions retained.

Executed SQLite admin/security/cockpit/users/API46passed/8417assertions (5.634s); MySQL admin/security/cockpit/users43passed/637assertions (22.853s); API3/7780 PASS after refreshed examples. Final start authorization refinement: SQLite14/295 and MySQL14/295 PASS. Final deterministic revocation-after-token-resolution race: SQLite15/299 PASS; MySQL15/299 PASS (19.012s). PHPStan L6/512MiB, Pint and diff checks PASS. Security tests include direct role/status/verification/MFA mutations bypassing cleanup, stale relations, binding mismatch, ordinary tokens, denied auditing and non-revival.41 real local HTTP exchanges plus database revocation/audit observations in docs/qa/B54_B55_ADMIN_BACKEND_HTTP_CAPTURE.json. Isolated SQLite/storage/keys/tokens removed and server stopped; MFA disabled for captures only, enabled-removal behavior tested separately. No provider requests, shared Redis flush or main DB changes. No migration/config/package changes needed.

OpenAPI and README contain actual new examples and parameter contract. docs/admin/COCKPIT_BACKEND_HANDOFF.md maps complete KPI fields, Today, pagination/filter rules and remaining client work. Parent exact CI green does NOT cover B54/B55; full new CI pending push. D-109 settlement and all earlier financial decisions unchanged. Status backend boundary DONE after focused evidence; client selectors/cards/page controls/filter forwarding remain Hassan work, not implemented or claimed accepted. Exact next: commit/push review branch and inspect CI; integrate client using handoff. Current uncommitted scope B54/B55 backend/tests/docs only, no deploy/main merge/Trello edits.

---

# B53 — Full-CI dashboard contract repair (2026-11-03, Codex)

Branch `frank/review-fixes`; integrated implementation b484cdf (support40e0e0c, main313dfad); draft PR#5 https://github.com/nauticsecure-dev/shopbonus_backend/pull/5. Conflicts resolved, pushed and full CI41596390972 started. Lint/static PASS; SQLite FAILED solely on stale exact MerchantDashboardTest KPI array: merged API legitimately adds reserved_codes/expired_vouchers/expired_offers. SQLite1268total1240pass+27skip+1fail/20972assertions. All support/canary/budget tests passed. Do not label that run green. Failed JUnit remains retained by the existing workflow.

Corrected only the test's exact expected KPI keys/values; no API behavior removed or response example invented. Local SQLite MerchantDashboardTest11pass/85assertions; MySQL11pass/85assertions (46.014s). No runtime changes or new migrations in B53. Pint targeted/diff checks PASS. B51/B52 final integrated focused evidence remains in the previous checkpoint. Clarified current fixed support policy UI value versus lower environment execution cap; no additional monetary activation or pricing assumption.

D-109 owner explicitly requires approval plus recorded settlement for NEW rewards, superseding D-107; historical snapshots remain. Support templates stay D-076; no free-form generation implementation or≥95% live-quality proof. New support guards need senior migration/profile/keys and actual budgeted canary. Main DB NOT migrated. Offsite/key/infrastructure/queue/native-provider acceptance remains NOT RUN/open. Exact next: commit/push this assertion/docs closeout and inspect replacement full CI. No main merge/deploy/customer messages/Trello changes; uncommitted scope B53 test/docs only.

---

# Integrated B51/B52 checkpoint — support guards and D-109 (2026-11-03, Codex)

Branch `frank/review-fixes`; support implementation40e0e0c; integrating main313dfaddba4a622aa4e0675cd8cb6215f4ba21a4 into draft PR#5. Preserved newer merged provider estimates/last-success telemetry, stricter support setting controls, wallet contract, recovery flags and all other main work. Resolved only overlapping implementation/docs/test expectations. Minor Pint normalization of six merged files is formatting only. No frontend implementation authored.

Owner explicitly selected **approval plus recorded settlement** after the main/D-107 conflict was surfaced. D-109 now supersedes approval-alone policy for new rewards; existing snapshots retained. The475minor/10000bps regression proves pending→approved-but-still-pending→independently recorded settlement→single credit/replay and frozen rate; allocation remainder0 and cash_settlement_verified true only after settlement. B49's older approval-alone wording was superseded in current acceptance addendum.

Support: aggregate pending EN/NL wording, separate order evidence, exact model/key/context/pricing/output-ceiling/version-bound canary (default24h expiry), omitted optional parameters, immutable required tools/storefalse/parallelfalse. Conservative UTC-day monetary reservation happens before HTTP, includes canary and retains exposure after timeout/crash. Proof changes/expiry, missing pricing, policy output above the canary-tested ceiling and lower budget all fail closed. Full-context pricing upper bounds are operator-verified; this is not provider invoice/account-wide cap or a live conversational-quality guarantee. Legacy unknown-spend days closed; rollback/reapply cannot reset allowance. Migration2026_11_03_100000 NOT applied to main DB. Stop old workers before deployment; senior securely configures profile and runs budgeted live canary.

Executed integrated SQLite256total252passed+4DB-only skips/9737assertions (30.250s); MySQL support+affiliate+operations214passed/1739assertions (79.495s), including4-process monetary race. Final output-ceiling refinement: SQLite provider/guards/chat/API138passed/8895assertions; MySQL guards/concurrency9passed/40assertions. Independent migration replay test passed in the integrated SQLite suite. PHPStan L6/512MiB PASS; Pint/diff PASS. Six real HTTP exchanges recaptured after main integration, synthetic two-entry wallet/no provider call; API examples updated. Temporary capture DB/storage/secrets/server cleaned. No production/provider credentials or customer delivery.

Status: main-code B51/B52 DONE against focused executed evidence; live-model canary and≥95% representative quality NOT RUN, template conversational acceptance remains a product acceptance gap under existing D-076. Documentation states free-form model explanations are not implemented. B50 offsite/key/infrastructure/production-queue proof and missing native provider deliveries remain open. Exact next: finish merge commit, push PR#5, inspect newly unblocked exact-code CI. Full local suites NOT RUN; CI not claimed green before execution. Checkpoint uncommitted scope is integrated merge/refinements/docs only; no deploy/merge to main/Trello changes.

---

# B51/B52 — Support aggregate wording and execution guards (2026-11-03, Codex)

Branch `frank/review-fixes`, parent9a548d8, draft PR#5. Added aggregate EN/NL pending-credit wording, actual two-entry regression and six actual HTTP owner200/foreign404/unauthenticated401 captures. Implemented exact-model/key/profile/version-bound expiring strict-tool canary via operator CLI and conservative micro-EUR reservation before each provider attempt. Missing contract/prices/proof or exhausted budget blocks egress; retained reservations cover failures/crashes. Immutable store/parallel/tool controls, optional model parameters omitted. New migration2026_11_03_100000 adds spend counter/proof table, preserves rollback/reapply and closes historical unknown-spend days. Main DB NOT migrated. Senior configures limits/rates and runs live canary; no live call made by agent.

Executed before main integration: SQLite support+API171total168pass+3skip/8926assertions; final focused guards/migration/evidence/provider/API46pass/7798assertions. MySQL final guards/evidence/concurrency32pass/162assertions, including4-process monetary race. PHPStan512MiB/Pint/diff PASS. Earlier MySQL suite167pass/1failure was a stale test expectation after failed canary invalidation; corrected focused checks pass. docs/support/SUPPORT_CHAT.md documents setup, conservative exposure semantics, D-076 templates and actual-provider≥95% quality rubric. Actual quality and brief acceptance remain NOT RUN; no free-form generated explanation implemented.

Checkpoint scope B51/B52 implementation; test fixtures explicitly synthetic. Found remote main has newer merged changes and PR#5 DIRTY, preventing CI. Exact next: integrate origin/main preserving newer work, resolve overlaps with guards, rerun affected tests, update PR. This checkpoint is not final integrated/CI acceptance. No deploy/Trello move/external customer message. Decisions D-076/D-077 remain; new monetary guard enforces existing policy cap rather than inventing pricing/model support.

---

# B50 — Recovery cadence and deployment acceptance (2026-11-03, Codex)

B49 acceptance implementation `7027e2c`; B50 recovery implementation `fd27a24`, both pushed. Previous PR#3 is merged; new draft PR#5: https://github.com/nauticsecure-dev/shopbonus_backend/pull/5. Full CI triggered by the new PR; result not yet verified. Working tree clean before this documentation closeout.

Branch `frank/review-fixes`, parent `7027e2c` (B49). User supplies four recovery/queue acceptance findings. Implemented five-minute backup attempts (was hourly), preserving opt-in and60-minute overlap lock. Default overdue15minutes (was120), minimum1 (was30), so a15-minute threshold is honored. No RPO/RTO approval or actual infrastructure guarantee inferred from this schedule. Existing deployments must update overrides/config cache and capacity-plan dump duration/retention before enabling.

Added docs/reliability/DEPLOYMENT_RECOVERY_ACCEPTANCE.md: independent disk/failure-domain retrieval, separate APP_KEY/backup-key escrow and recovery, measured data cutoff/durable availability, full infrastructure rebuild timeline and inventory of actual producer/worker queue connections with pause/backlog/failure/recovery acceptance. Updated stale hourly runbook statements and remaining-work inventory. offsite_verified remains false; no deployment or positive verification attestation invented. Default queue coverage stays default+affiliate; additional connections and Redis worker/ready-age proof require deployment telemetry.

Executed SQLite recovery+operations+API14passed/7660assertions (19.012s); focused MySQL operations4passed/28assertions (54.574s). Recovery tests intentionally use isolated SQLite, not MySQL restore proof. Schedule test verifies cron expression, overlap lock and enabled/disabled gates; freshness test verifies14minuteWARNING→16minuteCRITICAL with15minute threshold. Initial schedule test needed console kernel boot via schedule:list; corrected final suite passed. Pint dirty check PASS, diff check PASS. PHPStan L6 PASS with512MiB (initial128MiB attempt exhausted memory). No route/response schema changes, no migrations, no credentials or primary DB writes.

Status PARTIAL overall: code cadence/freshness DONE after tests; offsite durability/key recovery/full infrastructure drill and production queue acceptance NOT RUN without deployed infrastructure. Proposed RPO≤15minutes/RTO≤4hours remain UNAPPROVED. Exact next: push review checkpoint and have senior execute the documented isolated deployment acceptance against the actual topology; do not mark #30 complete. Full local suites NOT RUN; exact commit CI pending. Uncommitted scope at checkpoint B50 only; no merge/deploy/Trello edits.

---

# B49 — Review acceptance alignment (2026-11-03, Codex)

Branch `frank/review-fixes`, parent `e3b25c8dc1b5a0dbe653269a329b34314aec295d` (CI41295450472 SUCCESS). User asks to fix five review findings. D-089/D-107 already resolve timing: approval, not settlement or instant attribution. D-105 allocation-only remains. No replacement financial policy assumed.

Added docs/qa/REVIEW_ACCEPTANCE_ALIGNMENT.md, linked from consolidated spec/provider verification. Defines pending/approval versus settlement, explicit475minor/10000bps scenario,404 foreign-workspace concealment versus403 member permissions, ShopBonus eligibility versus Answer24 final execution, and open native-install/provider acceptance deliverables with responsibilities. Corrected misleading legacy/immediate test names/comments. Added executable475minor pending→approval/replay/frozen-rule regression without altering the7000bps default. No runtime behavior/routes/schema/API payload changes, no migration required, no fabricated HTTP examples.

Executed SQLite: AffiliateTest + ContactEligibilityTest + WorkspaceTest61passed/491assertions; Answer24ChannelTest + OpenApiCoverageTest17passed/7827assertions. Pint targeted PASS; git diff check PASS. Focused MySQL475minor regression1passed/12assertions (24.934s). Existing safeguards tests cover cross-tenant audit, missing consent, suppression and cancellation. Main DB untouched. No provider calls or install/acceptance claims.

Status: acceptance alignment DONE after recorded tests; provider packages/connectors/live journeys remain OPEN, not fixed by documentation. Exact next action: push this review checkpoint; provider/native implementation remains the next separate scoped engineering task using verified contracts. Full CI for this commit pending; full local suites NOT RUN (focused boundary only). Changes at checkpoint are B49 docs/test only; implementing SHA recorded in commit history/follow-up. No deployment/merge/Trello move.
## Merchant landing revision review — 2026-11-03

PARTIAL acceptance; HassanDev b92f46a plus uncommitted work. Added opt-in include_content=1 to both merchant history route aliases, exposing immutable stored publication content for the frontend comparison while default history stays metadata-only. Existing Super Admin/MFA authorization remains in force. No migration.

Executed: MerchantLandingCmsTest 14 passed / 81 assertions, targeted Pint passed; real Laravel HTTP journey 1 passed (2.8 minutes), including cookie/CSRF/TOTP/password confirmation, draft isolation, filesystem upload/retrieval, publish, exact restore and actual due-time scheduler execution. Captured response: docs/api/MERCHANT_HISTORY_HTTP_CAPTURE.json. Frontend comparison/localization tests passed, TypeScript and targeted ESLint passed. Full report: ../shopbonus_frontend/docs/merchant-landing-acceptance.md.

Next action: complete the authenticated staging/browser onboarding and editing journeys, and compare against the original reference image once provided. Browser execution was blocked by Windows memory/paging exhaustion; HTTP evidence is not browser/provider evidence. Reproduce isolated HTTP checks using php scripts/merchant-review.php serve (or resume), then the sibling frontend playwright.merchant-http.config.ts. Existing unrelated changes preserved; no commit/push/deployment. Checklist remains unticked until a tested commit SHA exists.

---

## Consumer acceptance environment — 2026-11-03

PARTIAL. User requested environment setup. Added guarded
`scripts/consumer-acceptance-fixtures.php`, invoked by the sibling frontend's
local launcher. Existing migrations/demo seed run against dedicated SQLite;
points credit/pending and a points-priced voucher use existing services.
No endpoint, migration or reward-policy change. Setup and service evidence,
commands and remaining release gates: sibling
`shopbonus_frontend/docs/consumer-acceptance-environment.md`.

## Workspace/team UI integration checkpoint — 2026-11-03

PARTIAL. Branch HassanDev, base b92f46a; uncommitted. Added GET /api/v1/workspaces/{workspace}/access-history, authorized with effective team.manage, tenant-scoped to existing audit events, 25/page, validated page and private/no-store. Safe projection contains only action, timestamp and outcome; no migration or legal/ownership policy change. Frontend consumes the existing effective-permission and password-protected ownership-transfer APIs, adds invite review/permission editing and a shared workspace remount/revalidation boundary. Full acceptance report is in sibling shopbonus_frontend/docs/workspace-access-review.md.

Executed: focused backend 53 tests passed / 8092 assertions (AccessHistoryTest, TeamTest, OwnershipTransferTest, RealtimeRevocationTest, OpenApiCoverageTest and mail tests); targeted Pint/PHPStan passed. Real local HTTP 200/401/422 captures: docs/api/WORKSPACE_ACCESS_HTTP_CAPTURE.json, reproduced by php scripts/capture-workspace-access.php. Frontend TypeScript/targeted ESLint passed; 7 controlled Chrome browser journeys passed, including mobile acceptance, switch state clearing and revoked-access fallback. Browser APIs are mocked; do not claim live sessions/socket revocation/provider acceptance.

Composer installed symfony/mime was stale at 8.1.7; lockfile already selected 8.1.10. Targeted install aligned vendor to the locked patched version. Subsequent audit has zero advisories/abandoned packages (docs/workspace-access-composer-audit.json); lockfile unchanged. Frontend production audit zero; full audit has 22 moderate development-tool findings without automatic fixes.

Next action: execute real authenticated staging/provider and cross-screen matrix in the sibling acceptance report. Live socket termination/reconnect denial, broadcaster outage recovery, all merchant screens' isolation, Google Places, mail delivery and staging P95 <300 ms remain unverified. RealtimeAccess logs broadcaster termination exceptions without a durable retry; no recovery claim is made. Existing unrelated uncommitted work is preserved; no deployment/push/commit. Checklist boxes stay unticked until a tested commit SHA is available.

---

# Consumer profile review follow-up — 2026-10-31

PARTIAL acceptance. Verified the existing uncommitted OTP log removal and
authenticated password confirmation enforcement; added explicit absent-confirmation
coverage alongside mismatch/throttle coverage. Registration/reset retain D-014.
Focused SQLite integration run: 136 tests, 133 passed, 3 skipped, 6125 assertions;
ConsumerProfile, Identity, Answer24Channel, Notifications and OpenApiCoverage.
No endpoint, schema or migration added. API v1 contracts unchanged.

Frontend recovery/concurrency checks are recorded in the sibling frontend
`docs/consumer-profile-acceptance.md`. Ten real-service browser journeys and
approved screenshot matching remain outstanding; do not mark #10 complete.
Verification wording for email changes is six-digit **codes**, per D-050.

# Continuation checkpoint — B38 explicit points earning verified (2026-10-31, Codex)

Branch `frank/review-fixes`, parent `130fd24056bf1166052feaee26e79ff03136be84`, draft PR https://github.com/nauticsecure-dev/shopbonus_backend/pull/3. Parent exact CI41184941391 SUCCESS across quality, SQLite and MySQL. B38 implementation commit follows this checkpoint; no claim that parent CI covers these changes.

D-104 implemented: immutable per-program points rate, multiplier and rounding on existing reward rules. Incomplete/unmapped rules stay inactive; scope/source/currency mismatches rejected. New awards select only configured points rules and freeze complete terms; legacy pending snapshots retain their original calculation. Exact integer arithmetic avoids float/overflow errors. Public discovery labels points without a EUR cashback percentage. D-105 remains allocation reporting; points monetary valuation remains explicitly unknown, not invented. No dependencies/new endpoints.

Executed: focused SQLite66 total/65passed+1skipped/7959assertions; MySQL63 total/62passed+1skipped/446assertions. Includes arithmetic boundaries, immutable/legacy terms, program isolation, approval timing, source/currency negatives and non-destructive migration replay. PHPStan L6, Pint and diff checks PASS. Sixteen real HTTP exchanges in docs/qa/B38_POINTS_EARNING_HTTP_CAPTURE.json; OpenAPI/README updated. Capture server stopped and isolated DB/storage/credentials removed. Migration120000 adds earning terms; migration110000 now guards reapplication; both preserve contractual data on rollback. Main shopbonus DB NOT migrated.

Next exact: commit/push B38, inspect its CI; then audit existing funding intents and implement durable checkout creation/uncertain-payment recovery (#20). Current uncommitted changes are B38 only. No deploy/merge/Trello changes. Whole Frank cards remain PARTIAL pending remaining code and independent/staging/provider/UI acceptance; see docs/qa/FRANK_BACKEND_REMAINING.md.

---

# Continuation checkpoint — B37 locale CI repair verified (2026-10-31, Codex)

Branch `frank/review-fixes`, PR https://github.com/nauticsecure-dev/shopbonus_backend/pull/3 (draft). B36 `05104b0` is pushed. First exact PR merge-build CI41184307319: quality PASS; both test jobs failed on the same locale regression. SQLite1142total/1116pass+25skip+1fail/19699assertions. Failure: English validation used Dutch “beleidsversie” under shipped APP_FALLBACK_LOCALE=nl. New QA pipeline correctly retained the failure and uploaded sanitized artifacts; tested merge SHA74f638a99d88b38af5e80120c69cd363f9b958d2. Never call that run green.

B37 adds explicit English attribute labels (Laravel messages retained) and restores the configured default after each request, including worker reuse. Regression tests force NL fallback and alternate NL/EN/unsupported/no-preference plus configurable EN default. Symfony test requests inject English unless explicitly blank; the absence fixture now accounts for that.

Executed: SQLite CookieConsentTest3/58 PASS with NL config; MySQL same3/58 PASS (14.048s). API3/7505 PASS, PHPStan L6 and Pint checks PASS. Four real HTTP422 captures with NL defaults in docs/qa/B37_LOCALE_HTTP_CAPTURE.json; OpenAPI/README updated. Capture server stopped; isolated DB/storage removed. No migrations/provider calls/packages.

Owner also resolved D-104: require explicit per-program points earning rate before new points rules activate, preserving existing reward snapshots. D-105: keep commission remainder allocation reporting, no formal revenue posting. Next: commit/push B37 and implement D-104 as the next backend boundary. Other remaining work stays in docs/qa/FRANK_BACKEND_REMAINING.md. Native APIs were researched only: E-Series uses Ecwid officially, but source-namespace/duplicate-effect guards must be audited before enabling that alias. Mijnwebwinkel needs partner API access/contract, CCV current webshop docs and Squarespace schemas need further review. No connector was activated from a name alone. Main DB migrations still not applied.

---

# Continuation checkpoint — B36 independent retest proof verified (2026-10-31, Codex)

Branch `frank/review-fixes`; B32 `1a3eee0`, points B33 `fe699ad`, affiliate B34 `40d8e83`, CI B35 `1284bc1`. B36 corrects remaining #37/#36 false-positive proof paths: read-only bounded Git ancestry distinguishes descendants/unrelated builds; later scan chronology is never positive build proof. Unknown endpoint ancestry remains time_only/STALE even when freshly imported. Unknown finding builds require explicit independent attestation, separately audited. Both execution and import must follow the recorded fix. Unlinked/manual PASS requires executed unmocked assertions plus unexpired, decryptable, checksum-verified evidence attached to a passing result. Linked browser/manual results and endpoint PASS use the same valid-evidence requirement; expired/corrupt files cannot certify success. Existing independent-role restrictions retained. Added Dutch error translations, including earlier points/schedule errors. No routes, migrations, provider calls or packages.

Executed: final focused SQLite QA/build-proof/API 26 tests/7835 assertions PASS (32.057s), latest standalone API 3/7505 PASS after documentation changes. MySQL QA/build-proof 23 tests/330 assertions PASS (55.280s). Real temporary Git repository test covers descendants and unrelated branches. Negative cases cover old execution, missing/expired/corrupt proof, mocked/unlinked manual results, and unknown-build freshness. PHPStan L6 and Pint --test PASS. Seven actual HTTP captures in docs/qa/B36_RETEST_PROOF_HTTP_CAPTURE.json use real cookie/CSRF/password flow with explicitly synthetic report/finding/evidence fixtures; these are API guard demonstrations, not staging/provider acceptance. Capture server stopped and isolated DB/storage/credentials removed. Full remote workflow still pending PR. No local main DB migrations applied.

Next: commit B36, push review branch and create PR to main; inspect exact-code CI. Remaining Frank work is tracked in docs/qa/FRANK_BACKEND_REMAINING.md: policy-dependent points earning configuration/formal revenue, native connector gaps, real staging/provider/browser/offsite/restore acceptance and inaccessible175-check PDF. Do not call all cards Done or automatically transfer them based on this checkpoint. Current uncommitted B36 only; no external messages/deployment/Trello edits.

---

# Continuation checkpoint — B35 Quality Center CI/inventory verified locally (2026-10-31, Codex)

Branch `frank/review-fixes`; B32 `1a3eee0`, B33 `fe699ad`, B34 `40d8e83`. CI now emits actual execution start/checked-out SHA/JUnit, preserves failed-test exit codes, discovers/imports into a separate ephemeral QA database and retains only sanitized JSON/normalized JUnit/current routes/provenance artifacts. No raw report, DB or credentials uploaded. Run environment `ci` is explicitly distinct and always mocked; schedules remain staging/production and must match imported suite/environment. CLI --output refuses overwrites and records real times/hash/inventory. General backend services/models/controllers/requests/resources/rules/enums/providers/support/logging/integrations are inventoried. Conservative backend source digest invalidates route evidence even for transitive dependencies. No new routes/migrations/packages.

Executed SQLite QA 17/297 PASS (31.496s); MySQL same 17/297 PASS (48.581s). API coverage 3/7505 PASS. PHPStan L6 and Pint --test PASS. Workflow YAML parses. Actual CLI replay of the executed MySQL report produced private sanitized JSON/JUnit with 17 mocked results and current inventory. Eight actual HTTP captures include cookie/CSRF login + recent password, CI import/list, service/model inventory, 401/403 and schedule mismatch409; docs/qa/B35_CI_HTTP_CAPTURE.json and API docs. Capture base is40d8e83+WIP, not exact released-build proof. See docs/qa/CI_AND_STAGING.md. Remote workflow execution pending PR, not claimed passed.

Remaining QA review: scan chronology alone still does not prove Git ancestry on different branches; unlinked/manual finding PASS needs stricter attached-evidence enforcement. Address next coherent B36 boundary. Missing native credentials/staging, inaccessible175-check PDF and all UI/provider acceptance stay explicit. Main DB not migrated for B32/B33. Current uncommitted B35 only; no push/PR yet.

---

# Continuation checkpoint — B34 affiliate review fixes verified (2026-10-31, Codex)

Branch `frank/review-fixes`; B32 `1a3eee0`, points checkout B33 `fe699ad`. B34 repairs #19's remaining verified duplicate/selection/consent defects. Credible distinct order references can share a click; repeated order references and ambiguous legacy click-only reports remain deduped. ProgramSelector recomputes eligible candidates and stores a new decision only when inputs/outcome/rule version change, including recovery from an empty candidate set. No-policy tracking now fails closed in every environment, as do missing/unknown/withdrawn marketing consent. Regression journeys now explicitly publish synthetic policy and grant consent.

Executed: focused SQLite + API coverage 62 total /61 passed +1 skipped /8060 assertions /10.285s. Same business selection MySQL 59 total /58 passed +1 skipped /555 assertions /39.368s. PHPStan L6 and Pint --test PASS. MySQL first exposed an overlong synthetic consent ID in the new test helper; shortened to fit the existing UUID-width column, then rerun passed. No migration or public response shape change; four actual HTTP redirect captures in docs/qa/B34_AFFILIATE_CONSENT_HTTP_CAPTURE.json. API coverage now checks examples for every declared media type, allowing actual HTML redirects instead of fake JSON redirect bodies. Server stopped and isolated capture DB removed. No native network calls.

Next: commit B34; #37 CI JUnit/discovery/import artifacts with honest CI provenance, then complete further verifiable main-code gaps from current Frank inventory. Whole cards retain UI, independent staging/provider acceptance and policy boundaries. Points earning-rate/multiplier/rounding policy remains separate from approved D-103 voucher prices. Main DB NOT migrated for B32/B33. Current uncommitted work: B34 only. No push/PR yet.

---

# Continuation checkpoint — B33 separate points checkout verified (2026-10-31, Codex)

Branch `frank/review-fixes`; B32 commit `1a3eee0` preserves and completes Claude's pending CI fixes. B33 follows owner D-103: optional versioned `price_points`, explicit consumer `wallet_unit=points`, separate checkout points fields. Points purchases never convert units or call Mollie; insufficient/pending/reserved points cannot fund them. Existing EUR-credit behavior remains D-053. Checkout revalidates offer/version under locks, freezes price/unit/identity, and captures through WalletService + VoucherInventory atomically. Financial reconciliation verifies the actual unit and immutable voucher price. Same key with another offer/unit returns 409. No new routes/packages.

Migration `2026_10_31_110000_add_separate_points_checkout_prices` adds nullable offer price_points and checkout wallet_unit (legacy default eur_credit), price_points and wallet_part_points. Deliberately non-destructive down() retains contractual prices/units. Applied only in isolated test/capture DBs; main local shopbonus NOT migrated.

Executed focused SQLite: 55 total /52 passed +3 skipped /5605 assertions /5.969s. Same selection MySQL: 55 passed /5617 assertions /35.248s, including real four-process points replay and existing checkout races. Includes API coverage, offers and financial reconciliation. PHPStan L6 PASS. Pint --test PASS. No repeated full suite for this boundary; full B32 SQLite/MySQL evidence is recorded below and does not cover B33. 18 actual HTTP exchanges in docs/qa/B33_POINTS_HTTP_CAPTURE.json; OpenAPI/README updated. Capture server stopped and task-owned DB/credentials removed; no real provider call. Protected PDF download returned401; 175 individual checks remain unverified.

Next: commit B33; repair remaining verified #19 selection/deduplication/missing-cookie-policy defects, then #37 CI report/discovery wiring. Current uncommitted files are B33 only. Whole Frank cards are not Done; docs/qa/FRANK_BACKEND_REMAINING.md tracks boundaries. Senior configures credentials/deployment; Hassan owns UI and independent provider/staging acceptance. No push/PR yet.

---

# Continuation checkpoint — B32 CI repair verified (2026-10-31, Codex)

Branch `frank/review-fixes`, starting HEAD `b607592`. Preserved Claude's uncommitted code/migration/formatter cleanup and staged deletion of three stray tools scripts. Current export has ten active Frank cards (#19/#20/#21/#22/#23/#26/#29/#30/#36/#37). See `docs/qa/FRANK_BACKEND_REMAINING.md`; reviewers' policy requests do not override D-089/D-053.

Finished remaining 45 PHPStan findings without baselines/suppression/config relaxation, correcting relation and collection types and redundant branches. Retained Claude's MySQL column-width and canonical JSON evidence-hash fixes; added hash ordering/type/list/tampering regression coverage. No route or intended response contract change at this checkpoint. D-103 owner approved separate points pricing; checklist expanded, implementation follows B32.

Executed: full SQLite 1117 total /1093 passed +24 skipped /17205 assertions /139.406s (before three added hash/tampering tests); focused enrichment +hash 8 passed /31 assertions. PHPStan L6 0 errors. Pint --test PASS; Composer audit PASS. Full MySQL: 1120 total /1117 passed +3 skipped /17295 assertions /473.678s against isolated `shopbonus_testing`, including all three added tests. Main DB migrations not applied. No credentials/provider-live/deployment acceptance. API coverage 3 tests/5140 assertions PASS; final PHPStan L6 PASS. B32 is committed in `fix: finish Frank review CI repair on SQLite and MySQL`; no push/PR yet. Next: implement D-103 through existing offer/checkout/wallet architecture. Whole cards remain partial.

---

# Active handoff — #31 B23o Wix webhook envelope fix + AppRemoved, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `daecc72` (B23n, [CI 40842608297](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40842608297) SUCCESS on all 3 jobs). Session commits: `f23f3a9` B23i/j, `c478831` B23k, `0274a77` B23l, `9f0d7fa` B23m, `daecc72` B23n; all CI green. D-089 unchanged. Credentials senior-owned.

B23o fixes a real defect from B23e–h: `WixConnector::signedData` required the JWT `data` claim to be an object, but the official `@wix/sdk` parseJWT shows it is a JSON string (inner `data` also a JSON string). Every genuine Wix webhook would have been rejected (401 `store_mismatch`). The connector now parses the string envelope only, and the test fixture uses it. `AppRemoved` → `KIND_DISCONNECT`, processed by `ShopifyUninstall` (now Shopify + Wix): native Get App Instance outside locks, disconnect only on a definite 401/403, replay with a valid grant ignored. `AppInstalled` is stored without effect. App-instance events dedupe on a content hash (no event id). No migration, no endpoint or response change.

Executed evidence for this commit's tree:
- WixConnectorTest 2/9 and WixAppRemovedTest 3/13 PASS; Integrations + Api folders 162 tests PASS.
- Full SQLite: 889 tests, 866 passed + 23 skipped, 10584 assertions, 68.5s.
- Full MySQL 8: 889 tests, 887 passed + 2 skipped, 10666 assertions, 311.9s.
- Pint --test PASS; PHPStan L6 0 errors.
- CI for f14afc8: [run 40843782709](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40843782709) SUCCESS on all 3 jobs (SQLite, MySQL, lint/static analysis).

Main local `shopbonus` DB: migrations 2026_10_29_100000 (B23k) and 110000 (B23m) are NOT applied there.

Exact next (remaining #31 Laravel gaps): remaining provider line/catalog/coupon/payment/refund mappings, per provider and only from official schemas (Wix lineItems from the Order object; BigCommerce products; Shopware lineItems; Magento items). Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). Live provider verification for every platform is NOT RUN. The B23n handoff is below.

---
# Active handoff — #31 B23n Shopware app webhooks, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `9f0d7fa` (B23m, [CI 40841263271](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40841263271) SUCCESS on all 3 jobs). Session commits: `f23f3a9` B23i/j, `c478831` B23k, `0274a77` B23l, `9f0d7fa` B23m; all CI green. D-089 unchanged. Credentials senior-owned.

B23n: `ShopwareAppWebhookController` at public `POST /api/v1/integrations/shopware/app/webhook` (limiter `webhooks-shopware-app` 600/min per IP). It verifies the shop-secret signature via `ShopwareAppRegistration::signedByShop` (previous secret within 60s of rotation), requires a confirmed `source.shopId` and a ±600s timestamp, and dedupes on eventId. `order.written` stores one derived `order_lookup` WebhookEvent per order ID (max 25) on the bound, generation-current connection; `ShopwareConnector` now implements `OrderNotification`, reading only that derived hint. `app.deleted` → `ShopwareAppRegistration::revoke` (unbind, strip credentials, generation bump; delete the shop unless keepUserData). Activation/deactivation are audited; other events are ignored. No migration.

Executed evidence for this commit's tree:
- ShopwareAppWebhookTest: 5 tests, 58 assertions; with ShopwareAppRegistrationTest 12/118 PASS. OpenApiCoverageTest PASS.
- Full SQLite: 886 tests, 863 passed + 23 skipped, 10569 assertions, 61.3s.
- Full MySQL 8: 886 tests, 884 passed + 2 skipped, 10654 assertions, 305.6s.
- Pint --test PASS; PHPStan L6 0 errors.
- Actual HTTP captures: docs/integrations/B23_SHOPWARE_APP_WEBHOOK_HTTP_CAPTURE.json, OpenAPI, README (database queue; no outbound reads).
- CI for daecc72: run 40842608297 SUCCESS on all 3 jobs.

Main local `shopbonus` DB: migrations 2026_10_29_100000 (B23k) and 110000 (B23m) are NOT applied there; run `php artisan migrate` when the senior is ready.

Exact next (remaining #31 Laravel gaps): Wix App Instance Installed/Removed webhooks (verify the current Wix contract and its app-level JWT payload first); remaining provider line/catalog/coupon/payment/refund mappings (per provider, only from official schemas); Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). Live provider verification for every platform remains NOT RUN. The B23m handoff is below.

---
# Active handoff — #31 B23m Shopware app registration, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `0274a77` (B23l, [CI 40839539332](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40839539332) SUCCESS on all 3 jobs). Session commits so far: `f23f3a9` B23i/j, `c478831` B23k, `0274a77` B23l; all CI green. D-089 unchanged. Credentials senior-owned.

B23m: `App\Integrations\ShopwareAppRegistration`, `ShopwareAppController`, model `ShopwareAppShop`, public routes `GET /api/v1/integrations/shopware/app/registration` and `POST …/registration/confirm` (limiter `shopware-app` 60/min per IP). Opt-in `SHOPWARE_APP_ENABLED/NAME/SECRET`. The signature/proof/rotation algorithms match the official shopware/app-php-sdk source (downloaded and read on 2026-10-29); re-registration always requires the dual signature. Shopware connections accept `app_registration: true` (+ `store_url`, `sales_channel_id`; client credentials prohibited). The confirmed handshake's Admin API credentials are bound into exactly one such connection on the verified domain, whichever comes first (`ConnectionManager::create/replaceCredentials` call `attach()`), bumping the generation and resetting readiness. Lock order: connections, then the app shop row. Details: docs/integrations/REMAINING_BACKEND.md.

Migration: `2026_10_29_110000_create_shopware_app_shops` (new table, FK to webshop_connections nullOnDelete). Applied only by test suites and the isolated capture DB; **not to the main local `shopbonus` DB or production.** The B23k migration `2026_10_29_100000` is likewise not applied to the main DB.

Executed evidence for this commit's tree:
- ShopwareAppRegistrationTest: 7 tests, 60 assertions PASS. Integrations folder plus OpenApiCoverageTest PASS.
- Full SQLite: 881 tests, 858 passed + 23 skipped, 10505 assertions, 61.3s.
- Full MySQL 8: 881 tests, 879 passed + 2 skipped, 10585 assertions, 300.3s.
- Pint --test PASS; PHPStan L6 0 errors.
- Actual HTTP captures: docs/integrations/B23_SHOPWARE_APP_HTTP_CAPTURE.json, OpenAPI, README (proof re-derived with openssl and matched).
- CI for 9f0d7fa: run 40841263271 SUCCESS on all 3 jobs.

Exact next (remaining #31 Laravel gaps): (1) Shopware app webhooks: app-level endpoint, `shopware-shop-signature` with the current secret (previous within 60s of `secrets_rotated_at`), route by `source.shopId` to the bound connection into the existing WebhookEvent pipeline, `app.deleted`/`app.deactivated` lifecycle → revoke the binding and credentials; (2) Wix App Instance Installed/Removed webhooks; (3) remaining provider line/catalog/coupon/payment/refund mappings; (4) Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). The B23l handoff is below.

---
# Active handoff — #31 B23l Wix app-instance tokens, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `c478831` (B23k, [CI 40838253435](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40838253435) SUCCESS on all 3 jobs). Earlier `f23f3a9` CI 40836390758 also passed all 3. D-089 unchanged. Credentials senior-owned.

B23l: new `App\Integrations\WixAppTokens`; opt-in `WIX_APP_ENABLED/WIX_APP_ID/WIX_APP_SECRET` (`config('integrations.wix_app')`). With app mode on, Wix connect/replace prohibits `access_token` and stores `wix_app_id`. `WixConnector::read` gets its Authorization from `WixAppTokens::accessToken()`: a manual token when not app-bound, otherwise a token minted via `POST https://www.wixapis.com/oauth2/token` (client_credentials, 4h). It re-mints when less than 5 minutes are left, under a cache lock and transaction guard, stores the token encrypted with `access_token_expires_at`, and audits it. `WebshopConnection::hasSameCredentialAuthority` treats app-bound Wix renewals as the same authority (same app ID, instance, site, public key). Errors: `wix_app_changed` 409, `wix_app_not_configured` 503, `provider_response_invalid`/`provider_authentication_failed` with credentials untouched. No migration, no new endpoint; OpenAPI create/replace descriptions updated.

Executed evidence for this commit's tree:
- WixAppTokensTest: 5 tests, 22 assertions PASS. WixDiscoveryTest still passes.
- Full SQLite: 874 tests, 851 passed + 23 skipped, 10422 assertions, 64.0s.
- Full MySQL 8: 874 tests, 872 passed + 2 skipped, 10505 assertions, 307.5s.
- Pint --test PASS; PHPStan L6 0 errors.
- CI for 0274a77: run 40839539332 SUCCESS on all 3 jobs.

Exact next (remaining #31 Laravel gaps): Shopware 6 native app registration handshake/signatures/lifecycle (verify the current official app-system docs: registration GET with shopware-app-signature, confirmation POST, app-secret HMAC on webhooks and action buttons). Then Wix App Instance Installed/Removed webhooks; remaining provider line/catalog/coupon/payment/refund mappings; Lightspeed C only with a verified current official contract. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION (D-099). The B23k handoff is below.

---
# Active handoff — #31 B23k Shopify privacy compliance intake, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`. Base `f23f3a96d4199ccf0a7aa75b62881d96d3645ea4` (B23i/B23j, [CI 40836390758](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40836390758) SUCCESS on all 3 jobs: SQLite, MySQL, lint/static). This commit adds B23k. D-089 is unchanged. Credentials are senior-owned.

B23k: public `POST /api/v1/integrations/shopify/compliance` (route `api.v1.integrations.shopify.compliance`, limiter `shopify-compliance` 300/min per IP) for `customers/data_request`, `customers/redact` and `shop/redact`. Order of checks: app client secret configured (else 503 `shopify_app_not_configured`), body ≤1 MiB (else 413), base64 HMAC-SHA256 of the raw body with `SHOPIFY_CLIENT_SECRET` (else 401 `webhook_rejected`/`signature_invalid`), topic is one of the three and webhook ID is present (else 401 `invalid_event_headers`). The request is stored once per `X-Shopify-Webhook-Id` in the new `shopify_compliance_requests` table (encrypted raw body, payload hash, event ID, topic, shop domain/ID when the shape is valid, `payload_valid`, status `pending`, `due_at` = received + 30 days). Redelivery returns 200 `duplicate:true` and increments `delivery_count`. An authentic body that doesn't fit its topic is kept with `payload_valid=false`. Audit events record receipt, resolution and rate-limited rejections, with no customer data. Operator command `shopbonus:shopify-compliance` lists pending requests (with the matching Shopify connection count and an overdue flag) and `--resolve=<ID> --outcome=completed|retained` records the outcome once.

**Nothing is erased or exported automatically.** See D-099 and OPEN-SHOPIFY-PRIVACY-EXECUTION. Legal/owner must decide which fields may be erased before this is automated, and someone must work the queue within 30 days. The senior must add the URI under `compliance_topics` in `shopify.app.toml`.

Migration: `2026_10_29_100000_create_shopify_compliance_requests` (additive, new table only). Applied by the test suites and the isolated capture DB only. **Not applied to the main local `shopbonus` DB or production.**

Executed evidence for this commit's tree:
- ShopifyComplianceTest: 7 tests, 46 assertions PASS. OpenApiCoverageTest PASS.
- Full SQLite: 869 tests, 846 passed + 23 skipped, 10401 assertions, 56.8s.
- Full MySQL 8: 869 tests, 867 passed + 2 skipped, 10483 assertions, 520.1s.
- Pint --test PASS; PHPStan L6 0 errors.
- Actual HTTP captures (php artisan serve, isolated SQLite, openssl-signed synthetic bodies): 200 for each of the three topics, duplicate 200, 401, 413, 503. Stored in docs/integrations/B23_COMPLIANCE_HTTP_CAPTURE.json, docs/api/openapi.yaml and docs/api/README.md. Rate-limit headers omitted (array cache). No live Shopify store or App Store review.
- CI for c478831: run 40838253435 SUCCESS on all 3 jobs.

Limitations: no dual-secret window during client-secret rotation (Shopify can take up to 1h to switch digests; its 8 retries over 4h should cover that). `X-Shopify-Shop-Domain` is not trusted; the signed body's `shop_domain` is used.

Exact next (remaining #31 Laravel gaps, in order): Wix app token install/renewal (verify the current official Wix OAuth/app-instance token contract first); Shopware native app registration handshake/signatures/lifecycle; remaining provider line/catalog/coupon/payment/refund mappings; Lightspeed C only once its current official contract is verified. Shopify privacy execution waits on OPEN-SHOPIFY-PRIVACY-EXECUTION. Previous B23i/B23j handoff is below.

---
# Active handoff — #31 B23i/B23j committed checkpoint, whole card IN_PROGRESS (2026-10-29, Claude)

Branch `main`, base `8f76727b9ee9463963c89da9fd8f0b4bb6e97531`. This commit finishes the Codex WIP for B23i (Shopify webhook provisioning) and B23j (Wix durable order discovery); nothing was reset or rebuilt. No new migration, public route, package or live provider action. Entire #31 IN_PROGRESS. D-089 affiliate timing unchanged. Credentials senior-owned.

Review fixes made by Claude on top of the WIP:
- **Wix pagination bug (fixed):** the WIP sent filter+sort together with `cursorPaging.cursor` on page 2+. The official Search Orders doc states "`cursor` can not be used together with `filter` or `sort`" and its 2nd-request example sends only `cursorPaging`. Continuation pages now send only `{cursorPaging:{limit:25,cursor}}`; every returned row is still status/window/UUID validated. Test asserts both request shapes.
- Wix cursor maxLength is 16000 natively; the existing journal column is 2048 chars, so a longer cursor fails closed (`provider_pagination_invalid`) rather than truncating. The doc example cursor for a comparable filter is ~720 chars. Widening the column needs a migration and was not done.
- Command `shopbonus:provision-shopify-webhooks` now prints an error for an unknown connection; added tests for the command (invalid ULID/topic/review flags → exit 2, sync queue without --inline refused, inline create, async 7-job dispatch, disabled refusal) and for a review ID with no outstanding intent (`webhook_intent_review_invalid`, no mutation).
- Reviewed and kept: after-commit OAuth dispatch (audited failure never relabels the grant), durable create intent before mutation with list-first recovery, generation/authority/store rechecks under row lock before intent and observation, cache lock owner-safe release, dedicated committing OAuth fixture resets `RefreshDatabaseState` (verified under both drivers with RefreshDatabase tests running after it).

Executed evidence (this machine, exact working tree committed here):
- Focused SQLite: ShopifyWebhookSetupTest + WixDiscoveryTest 13 tests/56 assertions PASS; with ShopifyOAuthProvisionDispatchTest + BulkSynchronizationTest 20 tests PASS.
- Focused MySQL 8 (`shopbonus_testing`): same 4 files 20 tests/83 assertions PASS/44.0s.
- Full SQLite: 862 tests, 839 passed + 23 skipped, 10343 assertions, 61.1s.
- Full MySQL: 862 tests, 860 passed + 2 skipped, 10426 assertions, 301.9s.
- Pint --test PASS; PHPStan L6 0 errors. OpenAPI coverage test included in full suites (no endpoint change, no new HTTP captures needed).
- CI for f23f3a9: run 40836390758 SUCCESS on all 3 jobs (SQLite, MySQL, lint/static analysis).

Known limitations (documented in docs/integrations/REMAINING_BACKEND.md): Wix Search never returns INITIALIZED orders (webhook/Get Order only); Shopify provisioning not verified against a live store and does not inspect TOML-declared app subscriptions; `--review-intent` must only follow manual confirmation in Shopify. Historical reconciliation migration down-index failure observed under DatabaseMigrations teardown remains unfixed and unrelated; do not claim historical rollback works.

Exact next: Shopify mandatory privacy compliance webhooks (customers/data_request, customers/redact, shop/redact). These are app-level (single configured URI, HMAC with app client secret, shop/redact ~48h after uninstall when the connection may be disconnected), so they need a new public route + OpenAPI entry with real captured examples per AGENTS rule 11, and mapping onto existing PrivacyRequest/retention rules (D-013) without deleting accounting evidence. Then Wix app token install/renewal, Shopware native app lifecycle/signatures, remaining provider line/catalog/coupon/payment/refund mappings, Lightspeed C only with a verified current official contract.

---

# Active handoff — #31 B23e–h backend checkpoint, whole card IN_PROGRESS (2026-10-28)

Branch `main`, base `78642dedf9ccfd02b544f7460f85ab989b189577`. Application `74608718c15afd96a045c5ee2017a85556548381` and timestamp follow-up `f90860ff5f91d720285b3978f352812190f27a18` committed/pushed. [Final CI40778138872](https://github.com/nauticsecure-dev/shopbonus_backend/actions/runs/40778138872) SUCCESS all3jobs for exactf90860f: quality/audit, SQLite824pass+23skip/10278assertions/52.29s and MySQL845pass+2skip/10361assertions/218.01s (847tests/database). Prior7460871 CI40777263930 also all3SUCCESS/846each. This is a docs-only closeout; no uncommitted application/test changes. Timestamp follow-up rejects ambiguous PrestaShop DST times and leaves Wix payment/refund record-created timestamps null, preserving authoritative current status/amount without inventing settlement-time proof. No pre-existing user changes overwritten. Owner requests all main code and focused testing. **OAuth/bulk/Woo+Shopify line boundary implemented; remaining provider/lifecycle/catalog gaps are still PARTIAL, not whole-card Done.** D-089/D-098 apply.

Delivered disabled-by-default browser-bound Shopify OAuth/expiring offline pair renewal, immediate invalidation on changed app authority, same-grant generation continuity, and signed uninstall with native revocation proof outside locks (valid current grant survives old uninstall replay). Durable fixed-window page journal/leases/UUID-bound native recovery, separate reconciliation vs verification purpose and opt-in allowlisted automatic scans. Added authenticated native read boundaries for Wix, BigCommerce, Ecwid, PrestaShop9, Shopware6 and Magento2.4 self-hosted/PaaS through existing connectors/pinned transport/canonical ingestion. ID notifications never supply payment values. Exact JSON decimal lexemes and bounded encrypted immutable Woo/Shopify line snapshots; existing order list/show additive line_items field. No provider/financial policy/mail activation, package change, storefront/plugin implementation or production deploy.

Executed local full SQLite842total819pass+23skip/10247assertions/57.509s, then MySQL843total841pass+2skip/10344assertions/269.076s sequentially. One native binding test was added between driver runs; final config-rotation/uninstall changes additionally verified with latest MySQL integrations90tests609assertions/14.502s, SQLite lifecycle23tests139assertions/1.503s, contract3tests3720assertions/1.003s. Pint/PHPStanL6/composer validate/audit (0advisories)/diff PASS. No repeated local full suites per edit. Final exactf90860f CI40778138872 passed all3jobs/847tests each; no additional local broad rerun for this docs-only closeout.17 actual running-server captures: normal login/CSRF/file sessions, isolated SQLite, explicitly synthetic provider HTTP/DNS, successful auth/exchange/replay rejection and401/409/419/422/429/503, current catalogue/order snapshots and uninstall revocation. Generated secrets/state/code/signatures redacted. Common403/500 envelopes reuse prior real-kernel examples; provider-live/multi-process acceptance NOT RUN.

Migrations180000/190000/200000 applied successfully to local main MySQL. Isolated SQLite exact latest3 down/up PASS, canonical order retained, removed line evidence remains null on reapply; MySQL full suite exercises schema creation. No main/production rollback. Quiesce old workers/intake for rollout/rollback and preserve encrypted source history/keys. Main DB and private B21 fixture credentials preserved; approximately36GiB free. Task capture helper/server/temp DB/sessions and downloaded task source HTML removed; no shared Redis flush or unrelated cleanup.

Exact next: normal docs-closeout commit/push/check clean; then continue remaining **Laravel** gaps listed in docs/integrations/REMAINING_BACKEND.md: Shopify privacy callbacks/automatic hook registration, Wix token install/renewal/backfill, Shopware native app registration/signatures/lifecycle, full native catalog/coupon/detailed line/capture/refund mappings. Lightspeed C current official contract/plan/API verification is unresolved; do not invent it. OpenCart merchant extension and provider storefront/app activation are outside Laravel scope. Senior configures real credentials locally; no credentials needed in chat. Whole #31 cannot be marked Done or represented as backend-complete for Hassan yet.

---

# Backend Task Ledger — Shopbonus

## Windows local installation — 2026-10-27

**DONE locally, uncommitted:** user requested backend installation. Installed locked dependencies,
generated local environment/key, created MySQL database and applied all 46 migrations.
Explicit InnoDB resolves Wamp's MyISAM default. API/identity tests: 86 passed/4,063 assertions;
HTTP health/readiness/docs/catalog 200, private me 401; queue-once and launcher passed.
See `LOCAL_SETUP.md`. No frontend connection or live-provider acceptance claimed.

This is the proposed dependency-ordered **backend-only** work queue derived from the attached Trello snapshot. Trello lists are PEOPLE/WORKSTREAM labels (Howto, Frank, Hassan), not a verified status workflow. **Every task starts `UNVERIFIED`** pending repo audit. Exact acceptance details are in corresponding Trello cards in `requirements/TRELLO_CARDS.md`.

Statuses allowed: `UNVERIFIED`, `READY`, `IN_PROGRESS`, `BLOCKED`, `PARTIAL`, `VERIFIED_WORKING`, `DONE`. Only mark DONE with actual tests/commit/evidence. Keep one active writer per overlapping code area.

| Task | Phase | Relevant cards | Depends on | Backend deliverable/acceptance | Status |
| --- | --- | --- | --- | --- | --- |
| B00 | Repository audit | #2 #22 #23 | — | Inventory of routes/models/migrations/services/providers/tests, working/partial/missing/broken; git/runtime; no rewrites | **DONE** 2026-10-23: no prior code existed, so every module was MISSING. Greenfield chosen (D-001). See PROGRESS. |
| B01 | Foundation & quality | #2 #3 #22 | B00 | Versioned API, consistent JSON errors/request IDs, roles/permissions patterns, migration review, Redis jobs, env, CI skeleton, OpenAPI | **DONE** (B01a + B01b). Roles/permissions pattern and workspace resolution moved to B03, which needs users and webshops. |
| B01a | Foundation: API skeleton | #2 §7–10 | B00 | `/api/v1`, error envelope 400–503 + request ID, health live/ready, log redaction, CORS allowlist, rate limiter, Pint/Larastan/CI, OpenAPI with real examples | **DONE** `a51a9b6`: 28 tests pass on SQLite and MySQL; Pint, PHPStan L6 and composer audit clean. CI workflow written but NOT RUN on GitHub (no remote). |
| B01b | Foundation: audit & errors store | #2 §8, #22 | B01a | `audit_events` (security audit: actor, real actor, webshop_id, action, resource, outcome, HTTP status, request_id) kept separate from `application_errors`; reusable `AuditLogger` service; automatic audit of 401/403 responses; failed-job listener and alert hook; append-only (no update/delete) guarantees and tests | **DONE** `088fb20`: 47 tests pass on SQLite and MySQL; Pint and PHPStan clean. |
| B02 | Identity & consent | #4 #5 #10 | B00 B01 | Secure signup/login/logout, verification OTP, recovery, account state, legal version and notification consent, access tests | **DONE** `c95e1e4`: 107 tests pass on SQLite and MySQL. Card E2E 1, 2, 4, 5, 6, 7, 8, 10 automated; E2E 3 moves to B03 (memberships); E2E 9 is frontend-owned. Staging p95 NOT RUN. MFA moved to B16; email change to B08. |
| B03 | Workspaces & onboarding | #2 #3 #16 #9 | B02 | Multi-webshop membership, ownership verification, agreement snapshots, invite roles, audit and isolation tests | **DONE** `5acdfbd`: B03a `2fe8db6`, B03b `ec20038`, B03c `b230455`, B03d (`5acdfbd`). 213 tests. Card #16 E2E 1–9 automated; E2E 10 backend journey automated (UI/staging parts NOT RUN). Owner dashboard moved to B13 (D-020); MFA/support sessions to B16. |
| B04 | Ecommerce provider contracts | #1 #3 #16 #23 | B01 B03 | Capability matrix, encrypted connection registry, verification/health, authenticated adapters, provider test fixtures | **DONE (framework)** (`820c6b5`): 255 tests. Adapters NOT VERIFIED live (no provider access); 7 platforms blocked pending mechanism/credentials. |
| B05 | Orders & state transitions | #1 #3 #22 #23 | B04 | Normalized orders, webhook signature/idempotency/out-of-order changes, paid/refunded/cancelled rules, order event feeds | **DONE** (`7e391be`): 270 tests. Live provider orders NOT VERIFIED (B04). Broadcaster not deployed (contract only). |
| B06 | Offers & code inventory | #7 #9 #17 #8 | B03 | Offer CRUD/state/conditions, CSV import, unique/reusable code controls, atomic reservation/issuance, snapshots | **DONE**: B06a `c6485dc`, B06b `c07f778`; inventory lifecycle/API and real MySQL reservation races remain covered by the executed full suites. |
| B07 | Answer24 handoff/claim | #1 #3 #23 #10 | B05 B06 | Lawful paid eligibility, one-hour grouping, cancellation suppression, signed outbound/callbacks, 90-day claim, reminder callback contracts | **DONE** (Shopbonus side): B07a `5c24240`, B07b `0c6c035`. Outbound dispatch OFF by default; real Answer24 NOT VERIFIED (OPEN-ANSWER24-CONTRACT, OPEN-MARKETING-CONSENT). |
| B08 | Consumer profile/discovery | #18 #10 #5 | B02 B03 | Public browse APIs, favorites/categories, profile/upload/preference contracts, owned dashboard, data privacy | **DONE**: B08a `4c4e26c`, B08b `c2dfb15`. Wallet balance in dashboard waits for B09 |
| B09 | Wallet ledger core | #2 #19 #20 #23 | B02 B01 | One central virtual consumer ledger, separate merchant ledger types, locks/reservations/no negative balances, tests | **DONE** (`862d3d0`) |
| B10 | Affiliate integrations | #19 #23 #24 | B04 B08 B09 | Daisycon/TradeTracker adapters, network selection, conversion evidence, duplicates/reversals, approved rule engine. Credit timing: safe default, switchable (D-052) | **PARTIAL** (`37edebf`): fixture-verified; live networks NOT VERIFIED |
| B11 | Voucher checkout/Mollie | #20 #23 #24 | B06 B09 | Typed quotes, wallet/stock reserve, provider callbacks/verified settlement, exactly-once fulfillment, refunds/reconcile | **DONE** (Shopbonus side): B11a `5fca11a`, B11b `cd48d26`; real Mollie sandbox NOT RUN |
| B12 | Merchant wallet/funding | #24 #7 #6 | B03 B05 B06 B09 | Sponsor obligation + 30%-on-reward fees, merchant top-up via Mollie, Moneybird invoices, settlement/reconciliations | **DONE** (Shopbonus side, `1edd8d9`): Mollie stubbed; Moneybird BLOCKED |
| B13 | Merchant analytics | #8 #17 #24 #6 | B05 B06 B07 B10 B11 | Scoped KPI/claims/order reports, real paid-order metrics, no cross-workspace leakage, accurate rollups | **DONE** (`2bfa953`); banner metrics wait for B14 |
| B14 | Campaign/banner backend | #25 #24 | B03 B06 B05 | Versioned banner configs, publish/pause/rollback, signed pairing API, consent-aware events, attribution/holdouts, public configs only | **DONE** (backend, `e5df771`); AI and A/B holdout open |
| B15 | CMS/legal/cookie API | #5 #4 #18 | B02 | NL/EN page/FAQ revisions, secure publication/rollback, cookie categories/consent, content access controls | **DONE**: B15a `4604215`, B15b `b2abb82` |
| B16 | Super Admin APIs | #21 #6 #23 #24 | B03 B05 B06 B09 | Audited support access, oversight, search, financial/provider health, alerts, content/settings, permission and PII isolation | **PARTIAL**: B16a/b committed; B16c committed/CI-green (`4786e65`); B16d committed/tested (`7b2c8d1`), implementation CI green; restricted public-settings scope documented below. Full Trello #21 still has AI/frontend/staging requirements. |
| B17 | Customer support backend | #26 #10 #21 | B08 B09 B11 B13 | Verified-answer tool allowlist, evidence checks, 1 retry, safe Reverb, feedback/human review, admin settings, 100 regression questions | **DONE (main backend)**: B17a `9caa4fd`, B17b–f `d63f616` + `7616e88`; final CI `40388179684` SUCCESS across all3jobs.16new operations, tested history/provider/retry/Reverb/review/settings/knowledge. Senior configures model/key/live deployment; Hassan frontend and whole-card acceptance remain. |
| B18 | E2E/security/load/release | #22 #23 plus all cards | B01–B17 | Evidence-backed E2E/backups/staging, permission matrix, race/replay/load, provider sandbox, rollback/runbooks | **BACKEND SLICE DONE** 2026-10-29 (B28): BusinessJourneyTest (card steps 1–17 via the real API), RoleAccessMatrixTest (every route × 8 identities), SecurityHeaders + TRUSTED_PROXIES, `shopbonus:launch-check`, API_INVENTORY.md, LAUNCH_READINESS.md runbook, composer audit clean. Staging load test, live provider runs, frontend QA and deployment remain (not Done). |
| B19 | Integration marketplace listing | #27 | B04, owner clarification | **UNSPECIFIED — empty Trello card**. Do not implement until a description exists | BLOCKED |
| B20 | Central notifications | #29 | B02 B03 B13 B17 | Owned central inbox, complete event catalogue, queued email/outbox, reminders, reviewed NL/EN AI templates and operational administration | **DONE (main backend)**: B20a `5c1d8cc`; remaining B20b–f `911e053`, CI `40412989638` SUCCESS all3jobs.719tests/database; real MySQL publication/quota/mail-claim races PASS. Hassan frontend/senior configuration/live verification remain. |
| B21 | Frontend integration issues | Report 2026-10-27; #5 #7 #18 #24 | B02 B06 B08 B12 B15 | Funding request identity; public legal metadata; promotional claims; opt-in local sample setup | **DONE (main backend)**: application `7991841` + regression `a7ebb0e`; CI `40559448895` SUCCESS across all three jobs (734 tests/database). Local fixtures provisioned and rerun verified. Approved production legal text, mail/providers and live/staging acceptance remain senior-owned. |
| B22 | Platform reliability | #30 | Existing B01–B21 services | Observation/alerts, cross-source finance reconciliation, audit/security/query evidence, backup/recovery and operations overview | **MAIN BACKEND DONE; whole #30 external acceptance pending**: monitoring/prerequisites/wallet foundation plus `b2ad156`, bounded traversal `64b1848`, isolated env config `e832cca`. Final CI40666899671 SUCCESS all3jobs:768tests/database. Five protected admin APIs,19real HTTPcaptures, source/race/security/query-plan evidence and actual isolated SQLite/two-server MySQL encrypted restore verified. Hassan can integrate; senior hosting/offsite/provider/accounting/production recovery/RPO-RTO acceptance remains explicitly NOT RUN. |
| B23 | Provider engineering | #31 (overlap #32) | B04 B05 B03 B22 | Ten accurate native profiles/adapters, scope/identity gates, durable sync/refund recovery and real provider acceptance | **IN_PROGRESS**: B23a–d DONE within documented scope. Woo/Shopify pinned native reads, credential lifecycle, seven-gate readiness and durable operator-requested known-order recovery delivered. Main `27faa2f` + byte-exact guard `03e69a9`; final CI40768013858 SUCCESS all3jobs,815tests/database.20actual HTTP captures and dual-driver migration/quality/audit proof. Next Shopify OAuth lifecycle, bulk missed-new-order discovery/cursors, detailed item mapping and remaining native providers. Real platform/storefront/provider acceptance remains unverified; whole card not Done or ready to move remaining backend to Hassan. |
| B24 | Voucher-problem screenshot evidence | #23 | B11 B16 | Optional encrypted screenshots on consumer disputes; Super Admin-only audited download; merchant count only | **DONE (backend slice)** 2026-10-29: tests, real-server captures, OpenAPI. AI screenshot analysis not built (optional on card). Whole #23 not Done (live providers/frontend/production). |
| B25 | Catalog/coupon sync + capability report | #1 (#32 §5) | B04 B23a | Read-only WooCommerce products/coupons sync (pinned GET transport, generation-guarded, removal on complete pass only, encrypted coupon codes) and per-connection capability report | **DONE (backend slice)** 2026-10-29: tests, real-server captures, OpenAPI/README. Other platforms report UNSUPPORTED/LIMITED honestly. Whole #1 not Done (live providers, storefront banner publication, frontend). |
| B26 | Shopify catalog/discount reader | #32 (#1) | B25 B23b | Second `CatalogReader`: fixed Admin GraphQL 2026-10 products (read_products) and code discounts (discountNodes method:code, read_discounts) verified against the official reference; cursor-based catalog contract | **DONE (backend slice)** 2026-10-29: tests, real-server captures (synthetic upstream), OpenAPI/README. BXGY/app/bulk discounts skipped by design. Whole #32 not Done (other platforms, live stores). |
| B27 | Remaining native catalog/coupon readers | #32 (#1) | B26 | BigCommerce, Ecwid, Magento 2.4, PrestaShop, Shopware 6 and Wix (V1+V3) readers, each field verified against the official reference or core source; 204-empty list support in the pinned reader | **DONE (backend slice)** 2026-10-29: PlatformCatalogTest (6 platforms), real-server capture (synthetic upstream), OpenAPI/README. 8 of 13 platforms now read catalog/coupons; Lightspeed C/E, CCV, OpenCart, Squarespace, Mijnwebwinkel have no native read connector (UNSUPPORTED). Whole #32 needs live installed stores and storefront publication (merchant-side). |
| B29 | CMS scheduled publishing + "Improve with AI" | #33 #34 #35 (#24) | B15 | Scheduled publish/archive pinned to the reviewed draft revision (stale instead of publishing unreviewed edits; scheduler re-checks Super Admin at run time); AI improve/translate suggestions via Responses API (store:false, strict schema, segment-preserving, no invented numbers/links/emails, daily quota, idempotent) that a human must apply to the draft and publish | **DONE (backend slice)** 2026-10-29: CmsScheduleAndSuggestionTest, real-server captures, OpenAPI/README, 8 routes. AI is off until the senior sets CMS_AI_*. Frontend (Hassan) and live model verification remain. |
| B30 | Quality Center backend | #37 | B16 B22 B28 | qa_tester/developer roles; automatic inventory per Git SHA (routes, OpenAPI, jobs, events, listeners, commands, schedules, migrations, connectors) with new/changed/removed/undocumented/unreferenced; approved test cases and coverage; JUnit import with strict PASS/FAIL/BLOCKED/NOT_IMPLEMENTED/STALE/UNTESTED rules; encrypted evidence; findings with developer fix and independent evidence-based retest; advisory GPT analysis; schedules; read-only production probe; protected OpenAPI JSON | **DONE (backend slice)** 2026-10-29: QualityCenterTest, real-server captures, OpenAPI/README, 28 routes. UI/portal hosting (Hassan/infra), executing the 25 PDF scenarios and provider subruns on staging, CI wiring and live model remain; card not Done. |
| B31 | Trello review fixes (Frank list) | #19 #20 #21 #22 #23 #26 #29 #30 #36 #37 | B30 | Fix the verified reviewer findings and the HassanDev merge regressions | **DONE (backend slice)** 2026-10-31 on `frank/review-fixes`: see HANDOFF. Decision items (D-089 timing, D-053 shortfall, reward units, admin direct credit), frontend paths, backup RPO/offsite, 4 connectors, CI/Postman and frontend work remain. |


## Work ticket procedure

For EACH task, append an entry to `docs/PROGRESS.md` or a subtask: Trello link, dependency confirmation, actual code paths, test names, negative tests, integration fixtures, performance/cost outcomes, commit SHA and state. Split large tasks into small slices by route/adapter/transaction. Mark BLOCKED on missing owner decisions or credentials, not fabricated PASS. Frontend screenshot/manual UI E2Es are outside backend scope; document associated backend tests/contracts and hand over to frontend owner.

## B16c checkpoint — 2026-10-25

**DONE (backend slice), `4786e65`, CI run `40195059904` passed.** Trello #21 E2E 5/6: wallet/ledger and flag review; dual-admin adjustment requests (D-064), original source evidence, idempotent compensating posting; financial exception/payment/refund/redemption-denial oversight; connection/Answer24 monitoring; original-identity webhook/outbox retries. Existing affiliate/dispute/checkout APIs reused. No provider activation, consent override or payout.

Evidence: `tests/Feature/Admin/{WalletOversightTest,WalletAdjustmentTest,OperationsTest,ConcurrentAdminAdjustmentTest}.php`, consent-revocation test in `Answer24ChannelTest`, denied redemption signal assertion in `VoucherDisputeTest`. SQLite 445 passed + 8 skipped; MySQL 453 passed; Pint/PHPStan clean, composer audit clear. Both migrations applied/rolled back/reapplied locally. Nine guard mutation checks detected failures and were restored. Thirteen new routes have real HTTP captures (isolated SQLite + database sessions). Live providers and staging remain NOT VERIFIED/NOT RUN. B16d has not started.

## B16d checkpoint — 2026-10-25

**DONE (documented backend slice), `7b2c8d1`, implementation CI `40196742388` passed.** Full suites: SQLite 459 passed + 10 skipped; MySQL 467 passed + 2 skipped; Pint/PHPStan and composer audit clean. Trello #21 E2E 7/8 and audit portion of 9: request-correlated grouped incidents/assignment/history, generated runtime/OpenAPI route registry (256 matching operations), transactional append-only audit seals/hash-chain verification/backfill, bounded password+OTP audit export, safe public setting versions with independent publication/new-draft rollback (D-065–067). No AI diagnostics or new sensitive policy activation. Eleven new routes have real-server captures. Local MySQL migration apply/rollback/reapply passed; historical audit verification passed (230 entries at migration check). Focused MySQL tests and three real-process races passed, including eight concurrent chain appends after fixing lock-upgrade deadlocks. Two destructive trigger-bypass simulations run only on transactional SQLite; MySQL enforces triggers and tests unsealed/head-corruption detection without destructive DDL.

## New downloaded Trello export — owner audit request, 2026-10-25

Read `~/Downloads/MS0DLCAX - shopbonus (1).json`. Fourteen active Frank cards, none with Trello checklists. **No full Frank card meets its complete acceptance criteria yet.** Completed backend slices and exact remaining work are mapped in `docs/TRELLO_FRANK_AUDIT_2026-10-25.md`. Export adds #29 central notifications, #30 reliability/DR, #31 provider engineering and #32 real integrations beyond the original requirements snapshot; #1 was revised. These are **UNVERIFIED/PARTIAL new scope**, not silently implemented or added as completed B-tasks. Existing affiliate timing conflict remains OPEN. Do not modify Trello from this audit request.

## B16e — #21 AI-assisted diagnostics, 2026-10-25

**PARTIAL (implementation verified; real provider/staging verification unavailable).** Owner selected "chatgpt" (OpenAI) and confirms no staging. Implemented a disabled-by-default OpenAI Responses adapter with strict structured-output validation, minimal technical evidence, fixed host/no tools/no automatic retry, immutable context hash/result and append-only review; queued idempotency and serialized daily quota with race proof; rechecks admin/incident eligibility; safe failure codes and privacy filtering. Reuses versioned settings for private `operations.diagnostics_daily_request_limit`, independently published and capped by environment. D-068/D-069. Four new admin routes plus the changed settings contract have real-server captures; OpenAI completion was explicitly simulated, not live.

Tests: full SQLite **469 passed + 11 skipped** (6841 assertions); MySQL **478 passed + 2 skipped** (6880 assertions); Pint/PHPStan/composer audit pass; MySQL migration `150000` rollback/reapply pass; 8 guard mutations detected/restored. `IncidentDiagnosticsTest` (10 cases) + new diagnostic-quota MySQL race in `ConcurrentAdminAdjustmentTest`; OpenAPI/registry coverage pass. WIP `dea03b5` pushed; CI `40199851559` passed. Final strict JSON-integer budget validation/regression and refreshed domain-conflict captures passed; final checkpoint follows this implementation SHA. Hassan handoff/configuration and remaining acceptance: `docs/admin/INCIDENT_DIAGNOSTICS.md`.

Remaining: real OpenAI API project/key, account-supported configured model and owner budget; live successful/failure/usage evidence; Hassan's UI; staging p95/monitoring/backup recovery. **NOT RUN**, not guessed passes. Existing financial/legal/marketing decisions remain open; B17 support chat is not implemented by this work.

## B10b — #19 main-code continuation, 2026-10-25

**DONE (TradeTracker main-code slice; deployment verification separate).** Owner explicitly directs credential-independent implementation; senior developer configures accounts. Pinned-WSDL SOAP transport, authentication/cookie isolation, full program paging, registration/assessment conversion polling, safe error codes and atomic guarded sync. No new routes/schema. SQLite **483 passed + 11 skipped** / MySQL **492 passed + 2 skipped** (494 total each), Pint/PHPStan/composer audit + validation pass. API examples captured through a real local server with synthetic upstream SOAP. Full #19 remains PARTIAL for genuine network/attribution and staging acceptance. See `docs/integrations/TRADETRACKER.md`. Implementation `50957b4` pushed; GitHub CI `40202694616` passed all three jobs. Final documentation checkpoint records this evidence.

## B14b — #25 AI/experiments, 2026-10-26

**DONE (documented backend slice), `d561f8d`; CI `40281914885` SUCCESS.** Owner authorized main code and disk override; senior developer configures credentials. Queued globally capped OpenAI layout/insight requests, immutable source/revision/offer-version/evidence, strict3 editable proposals, verified tenant aggregates and authoritative merchant costs, human-reviewed private drafts only. Published-version A/B/holdout, stable consented assignment/withdrawal, event binding and verified payment linkage through generic/WooCommerce/Shopify metadata. Existing editor capability revocation gap fixed. Eight new operations and changed endpoints documented with35 actual local HTTP captures (provider completions explicitly synthetic). Local full suites before final focused metadata/editor additions: SQLite500 pass+13 skip; MySQL511 pass+2 skip (513 total). Final targeted SQLite/MySQL/quality/coverage checks recorded in PROGRESS; Final full CI: SQLite503 pass+13 skip/7230 assertions, MySQL514 pass+2 skip/7276 assertions (516 total each); all quality jobs passed. No new financial/affiliate/legal activation. Whole #25 remains PARTIAL for renderer/editor/cart bridges, genuine provider/model/storefront and staging acceptance. See `docs/banners/AI_EXPERIMENTS.md`.

## B17a — #26 authorized evidence/verification foundation, 2026-10-26

**DONE (B17a internal boundary), `9caa4fd`, CI `40284066219` SUCCESS; whole card PARTIAL.** Closed read-only registry reuses consumer wallet summary, owned voucher facts, merchant funding Wallet, FeeCalculator offer previews and published audience/locale/revision-scoped FAQ. Fresh identity/membership/permissions on retrieval and verification, generic audited404 for nonmembers/nonowners, no Super Admin bypass, support impersonation denied. Minimal opaque evidence projection; no codes/customer/contact/payment IDs; no business writes or provider calls. Model selection accepts evidence IDs only; exact fresh source recheck,30s freshness,5-item bound, conflict detection, server-rendered EN/NL facts and honest missing/unknown states. D-074/D-075; `docs/support/EVIDENCE.md` inventories what remains. No routes/migrations/config changes; existing API docs/coverage remain applicable.

Validation:23 focused SQLite tests pass/106 assertions after final explicit Super Admin negative assertion. Full sequential suites and quality results finalized in PROGRESS/HANDOFF before commit. Whole #26 remains open; do not mark Done/move as finished. B17b next: persisted owned conversations and server research/intent planning, one targeted retry, then configured Responses transport, private progress, human feedback/review/settings and100-question/full E2E acceptance.

Exact committed CI: SQLite526 pass+13 skip/7336 assertions/29.11s; MySQL537 pass+2 skip/7382 assertions/72.25s (539 total each). Quality job passed. Final docs closeout follows; next implementation boundary B17b.

## B17b–f — complete #26 main backend, 2026-10-26

**DONE (main backend), `d63f616` + `7616e88`; final CI `40388179684` SUCCESS.**16 new authenticated support/admin operations; encrypted scoped owned history/evidence and idempotent single-attempt queued research/strict Responses evidence selection, one targeted distinct read, current auth/permission checks, serial global/per-user daily quotas and circuit breaker. Internal links/server EN/NL renderer; no financial actions. Published FAQ search and existing CMS workflows; immutable feedback/escalation, audited human review/reply and approved regression cases. Private Reverb progress plus HTTP restore/poll; local actual websocket/subscription/publish test passed. Private support.ai settings reuse test-before-independent-publish/rollback.100 distinct EN/NL real submit/job regression paths pass; source unknowns remain null. D-076–079, `docs/support/SUPPORT_CHAT.md`.

Evidence so far:153 support tests on SQLite151pass+2real-DB skips; previous focused MySQL151pass before final owned-pending/campaign/NL refinements, including2 real-process races.52 real local HTTP captures, actual browser/bearer authentication and queue worker, synthetic upstream explicitly labelled; OpenAPI coverage3pass/3261 assertions. Full SQLite669 total:654pass+15skip/8616assertions before final NL-only refinement; final MySQL and focused/quality results recorded in PROGRESS. Apply/rollback/reapply support migration passed local MySQL. No live OpenAI/account/deployed Reverb/staging/frontend/production-monitoring claim.

Final local complete suites: SQLite672total657pass+15skip/8690assertions; MySQL672total670pass+2skip/8752assertions. Both new migrations apply/rollback/reapply PASS. Latest provider privacy13tests/39assertions PASS; exact final CI will include673 tests. FAQ immutable history/rollback, executed human correction cases/corpus fingerprint and75 real HTTP captures are complete. Main backend implementation finished; commit/CI closeout pending. Whole card still needs senior live-provider/deployment and Hassan frontend acceptance.

Implementation `d63f616` CI40387311496 SUCCESS across quality/SQLite/MySQL (673total each;658+15skip and671+2skip). Final corpus-change-during-test guard has5admin tests/68assertions passing on each DB; follow-up CI pending. No other feature scope remains.

Final exact code checkpoint `7616e88`:673tests/database, SQLite658pass+15skip/8704assertions/31.67s; MySQL671pass+2skip/8755assertions/94.99s. All3CIjobs passed.16new APIs,75 actual HTTP captures,2local migrations applied/rolled-back/reapplied, actual local Reverb transport/auth,100static+executed human regressions,2real-process races. No B17 main-code work remains. Senior live provider/deployment/monitoring and Hassan frontend/staging acceptance remain NOT RUN, so whole Trello card is not declared fully Done.


## B20a — #29 first central inbox boundary, 2026-10-26

Owner says “start with the next” and explicitly authorizes “Continue #29 below the limit” (D-081). Audited existing merchant-only notifications and appended the full downloaded #29 requirements before implementation. Scope expanded in CHECKLIST before coding. Reuses Laravel database notifications with encrypted generic payloads, owned persistent read state,5 authenticated/verified APIs and private minimal Reverb invalidations. Actual human replies create same-transaction notifications; per-user lock plus recipient/event uniqueness handles concurrency. Revoked workspace membership/admin role hides history and counts; support sessions denied. NL/EN templates use shared locale; optional preference switches never grant consent; email explicitly unavailable, not silently reported sent. Legacy merchant APIs remain compatible pending B20b bridge.

Executed focused SQLite10pass+1skip/96assertions and MySQL11pass/98assertions before four additional negative tests; actual4-worker MySQL replay creates one notification. Full final SQLite688total672pass+16skip/8873assertions/71.284s. Final MySQL full suite running. Pint/PHPStan L6/composer audit pass; OpenAPI coverage3pass/3358assertions. Migration140000 apply/rollback/reapply PASS local MySQL.24 actual local HTTP captures include success/401/403/404/419/422/429/500 and owner200/other403/support403 broadcasting authorization;500 uses temporary kernel-bound service failure and channel signature is explicitly redacted. See notifications/NOTIFICATIONS.md and B20A_HTTP_CAPTURE.json. No live mail/model/deployed WebSocket/frontend proof. Remaining event/bridge/email/reminder/template/admin/retention work keeps whole #29 PARTIAL.

B20a final local verification: SQLite688total672passed+16skipped/8873assertions/71.284s; MySQL688total686passed+2skipped/8927assertions/273.128s. Includes all15 new tests (MySQL4-process replay), actual after-commit and queue-outage tests. Pint full, PHPStan L6, composer audit and git diff check passed. B20a implementation is DONE locally; implementation commit/push/CI closeout follows. Whole #29 remains PARTIAL and B20b–f remain unchecked. Disk lowest observed~430MiB; no ENOSPC or unrelated cleanup.

B20a implementation `5c1d8cc` committed/pushed; CI `40399876856` SUCCESS all3jobs. Exact688tests: SQLite672pass+16skip/8877assertions/39.76s; MySQL686pass+2skip/8927assertions/126.47s. Pint/PHPStan/composer audit green. This docs-only closeout records SHA/CI and ticks checkpoint evidence; no further B20a code remains. Next B20b merchant compatibility bridge/full event wiring. Whole #29 PARTIAL, no frontend-only handoff yet.


## B20b–f continuation — 2026-10-26

Owner says “do it”: finish remaining #29 main backend. Base deea998 clean, D-081 persists. Implemented uncommitted source outbox/merchant bridge, full catalogue/preferences, durable mail records/claims and ambiguity review, reminders/retention/deletion, reviewed template/settings/admin routes, strict capped/idempotent OpenAI drafts. Current focused37tests34pass+3MySQL skips/248assertions; later support-review/deactivation source refinements await final checks. Three new migrations150000/160000/170000 applied/rolled back/reapplied MySQL PASS after fixing an overlong fallback unique index; only empty task tables from that failed attempt were removed.74 actual local HTTP captures include real SPA login/password/seededOTP, all new endpoints, synthetic upstream success/failure, database queue worker/array mail,401/403/404/409/419/422/423/429/500/503. Credentials/temporary router/fixtureDB cleaned. OpenAPI/README regeneration and full verification/CI still pending; no whole-card Done claimed.

B20b–f final local checkpoint: complete main implementation, main branch base deea998. Full SQLite718total700passed+18skipped/9262assertions/75.523s; final added outbox-exhaustion/admin-retry regression then passed in9-source-test suite/45assertions (719total now expected in CI). Final API+diagnostics13passed/3669assertions after104 actual HTTP captures and schema regeneration. Full Pint/PHPStan L6/composer audit/diff check PASS. All3 new migrations (including read receipts/email-only visibility/short explicit indexes) local MySQL apply/rollback/reapply PASS; SQLite migration rollback/reapply test PASS. Local full MySQL/races NOT RUN due low disk; CI is the required final acceptance before parent backend DONE. Main-code boxes ticked against executed local evidence in this implementation commit; final CI/E2E aggregate remains unchecked. See notification handoff and D-083–085.


B20 final implementation `911e053` committed/pushed; CI `40412989638` SUCCESS all3jobs. Exact719tests/database: SQLite701passed+18skipped/9278assertions/40.77s; MySQL717passed+2skipped/9340assertions/144.63s. Three real MySQL process races and migration rollback/reapply PASS; Pint/PHPStan/composer audit green. Follow-up strengthens the existing actual password/OTP workspace approval journey with owner/admin notification scope and public workspace reference; all14 tests375assertions pass locally, no application-code change. This docs/test closeout records final verified main backend Done. No remaining #29 main-code scope; Hassan UI and senior real model/mail/deployed Reverb/staging acceptance are separate NOT RUN live work.

Final approval-scope follow-up `5a8c36d`, CI `40414186822` SUCCESS all3jobs. Exact719tests/database: SQLite701pass+18skip/9281assertions/43.05s; MySQL717pass+2skip/9341assertions/101.94s. Actual password/OTP approval→owner notice/admin-only review assertions pass on both DBs. All #29 main backend acceptance complete; final docs-only closeout records these results.


## Discovery integration accuracy checkpoint — 2026-10-31

Branch HassanDev, backend base 59916e9, frontend base 3d0b0ad; uncommitted.
Reuse/fixes and exact evidence: [Discovery review](discovery/INTEGRATION_ACCURACY_REVIEW.md).
Unified obtainability sort/filter/count, actual batching (including null/zero cases),
stock/import/allocation and after-commit invalidation, <=30s cache with scheduled
expiry, shared voucher list/status, URL Back/Forward synchronization. No migration
or new endpoint. Existing unrelated edits preserved; no commit/push/deployment.
Validation: Discovery+Vouchers+OpenAPI 47 tests / 43 passed / 4 skipped / 5490 assertions;
benchmark separately 1 passed / 201 assertions; 10 Chromium directory fixture
journeys passed; frontend typecheck/targeted ESLint and backend targeted Pint passed.
Broader Affiliate+Vouchers+OpenAPI: 67 passed, 4 skipped, 2 unrelated pending-activity
failures (existing WalletController/AffiliateActivityController edits); not green.
Local 200-shop/1000-offer/2000-code HTTP-kernel benchmark: 100 cold + 100 warm
samples, P95 431.16ms cold / 62.97ms warm. Not staging/network/load evidence.
Status PARTIAL: missing design reference, full real-service platform browser
journeys and staging P95 remain open. Next: obtain reference, run full live
journeys and MySQL/Redis staging search load measurements; investigate cold P95.


## Merchant overview review follow-up — 2026-10-31

Implementation checkpoint, PARTIAL acceptance. Backend branch HassanDev, base 59916e9; frontend HassanDev, base 3d0b0ad. Changes remain uncommitted; unrelated existing changes were preserved. No production migration or deployment performed.

Implemented overview pagination (10/page), lifecycle filters before pagination, four primary KPI cards, secondary operational details, unavailable/error states, request cancellation, 30-second visible-tab polling, focus refresh and generated_at freshness text. KPI/chart/claim notices open existing voucher records using claimed=1 and business-timezone claim-day filters, including subsequent redemption; list adds claimed_at. No new route or duplicate claim store.

Recovered stock alerts resolve on generator runs and dashboard/workspace-notification reads; resolved legacy records remain stored but are excluded from dashboard, workspace list and central inbox. Count-change signals refresh central badges; pending outbox/mail delivery skips resolved entries. Invalid import batches and explicit provider mismatch/sync_error results now produce deduplicated offer alerts. Normal unverified/manual-required codes are not treated as verification failures.

Effective lifecycle is shared by SQL filters/KPIs and displayed rows: archived, expired, scheduled, draft, paused, sold_out and active, including allocation limits. Active excludes sold-out/future/expired offers. Existing platform access policy retained: nonmember 404 (existence concealed), member lacking permission 403. API contract and real HTTP captures are in docs/api/MERCHANT_OVERVIEW_CAPTURE.json and docs/api/README.md.

Executed: focused Analytics/Notifications/OpenApiCoverage run 70 tests, 67 passed, 3 skipped, 5587 assertions (SQLite); targeted Pint and PHPStan pass (0 errors); frontend typecheck and targeted ESLint pass; ten Playwright Chrome browser contract journeys pass. Browser responses are controlled fixtures, not ten real-service E2E journeys. Desktop/mobile screenshots were visually inspected. Backend historical E2E naming now explicitly describes API integration evidence; sequential claims no longer described as concurrency.

Volume evidence: 10,000 available codes + 10,000 accepted claims/issued vouchers, 30 days, three SQL aggregate queries, 463.7 ms on local SQLite. See docs/analytics/MERCHANT_OVERVIEW_BENCHMARK.json. Daily SQL grouping uses UTC boundaries of each local day (DST tested), returning at most one row/day/source. Added workspace/timestamp indexes. This is not a production P95 or MySQL/Redis load result.

Migrations: 2026_10_31_160000_add_resolution_to_merchant_notifications and 2026_10_31_160100_index_merchant_lifecycle_analytics. Applied only to isolated test/capture SQLite databases. Apply both before running this code against an existing database. The synthetic HTTP capture server and temporary credentials/database were removed afterward.

Exact next acceptance work: supply the original screenshot for a reference comparison; exercise all ten journeys with real sessions/services and a real concurrent claim workload; benchmark representative MySQL/Redis deployment and inspect plans. Full repository test/build suite and production provider verification NOT RUN. Checklist items stay unchecked until a commit records evidence/SHA, per AGENTS.md rule 12.


## AI support repair — 2026-10-31

Status: implementation verified locally; live-service acceptance PARTIAL. Backend branch HassanDev/base 59916e9; frontend HassanDev/base 3d0b0ad. Uncommitted; unrelated existing changes preserved. No commit, push, migration or deployment.

Removed unsupported reserved-reward/release claims from both sidebar and verified NL template. Wallet request state separates loading/error/verified empty; missing categories stay unavailable. Planner now normalizes unambiguous one-edit typos, preserves subject/reference/unit for bounded follow-ups in the owned conversation, extracts FS-20482-style references, and exposes ORDER_STATUS/PAYMENT_QUESTION through the existing authorized order evidence tool. This is conservative contextual routing, not a general semantic assistant; ambiguous requests clarify.

Pending history renders immediately and HTTP sync recovers answers/human replies on refresh, focus, browser online and authorized Reverb resubscription (3s pending / 15s idle while visible). Transport acknowledgements are labelled live updates, never provider/worker availability. Private event payloads and evidence rechecks remain unchanged.

Settings use Behavior/OpenAI/Rules/Quality/Monitoring. Published progress/badge/action controls execute; request/time/token/retry controls remain bounded. Unsupported prose/word-limit/deadline/cost-ceiling/fallback/model-specific controls are locked in UI and reject non-default API writes. Store/parallel execution remain false and verification tool choice required. Language selection is available; human escalation remains enabled. Restrictions respect scope. No guessed model capability list or default provider pricing.

Workspace action routes preserve destination/workspace. A destination gate validates the accessible workspace list and awaits the existing authorized selection API before mounting page queries. Financial actions remain read-only navigation. Funding opens the existing overview; offers/campaigns/integrations retain their pages.

Monitoring supports dates, scope, locale, status, model, retry and review outcome filters; bounded sample percentages and matching totals; timestamped provider-success/worker-execution/dispatch evidence. Reverb live health explicitly not measured. EUR cost estimates require deployment-owned exact-model rates in config/support_ai.php; null and priced-request coverage distinguish missing pricing from zero. No billed cost or enforced spend ceiling is claimed.

API contract/examples: docs/api/SUPPORT_REPAIR_HTTP_CAPTURE.json (five real local HTTP requests on isolated SQLite with synthetic actor), docs/api/README.md and docs/api/openapi.yaml. No route or migration added. Capture server and generated token/database are temporary and removed after capture.

Validation: full Support + OpenApiCoverageTest: 185 tests, 183 passed, 2 database-specific skips, 6653 assertions at prior checkpoint. Added final context/unit checks and final frontend checks recorded in the root docs/ai-support-frontend-repair.md. Targeted PHPStan zero errors; Pint, frontend typecheck/targeted ESLint pass. Twelve controlled browser regressions passed before the settings case was added. Final results recorded in root repair report.

Exact next release action: run original ten real-service support journeys with verified consumer/workspace/admin sessions, real worker, Reverb disconnect/resubscribe and configured provider; verify real model compatibility, supply verified pricing, and verify deployed connectivity. Credentials/services not supplied; controlled browser tests are not proof of production connectivity. No AI financial activation or cashback-timing decision changed.


## Super Admin cockpit reporting correction — 2026-10-31

Implementation complete locally; production/provider acceptance remains PARTIAL. Backend HassanDev base 59916e9; sibling frontend HassanDev base 3d0b0ad. Uncommitted; no commit/push/deployment. Existing unrelated edits preserved.

Current-state cards omit unsupported comparisons; the previous-period query now reads only commission events and uses equal business-calendar days across DST. Voucher distribution counts issued vouchers using explicit immutable acquisition_transaction_id evidence, approved/nonduplicate transactions and matching consumer/webshop; additional networks are dynamic. Missing evidence remains Other/Unknown, including direct/legacy issuance. No wallet-funding or selected-network inference. VoucherInventory::recordAcquisition is a trusted internal evidence writer, with no public mutation endpoint; no existing affiliate conversion flow issues vouchers automatically. Do not backfill links without evidence.

Voucher search uses normalized webshop-scoped exact hashes with a code_hash index. Shared usable scope and consumer code-copy eligibility exclude invalidated/invalid/mismatched codes, expired vouchers and invalid unique-code states. Consumer intent is independent of merchant memberships; active platform staff and explicit ADMIN_TEST_ACCOUNT_PUBLIC_IDS are excluded consistently. Failed webhook imports deduplicate with connector errors per connection; failed affiliate connections include reauthentication. Source status controls resolution; incident occurrences and failed jobs are diagnostic, not additive. Capped source-item links and attention=1 operations preserve exact affected connections.

Commission and voucher drill-downs preserve inclusive business-timezone from/to dates, status/network and tab; transactions totals use the same query as rows. Missing transaction boundary returns 422. UI supports custom dates with validation, visible-tab 60-second polling and focus refresh. Existing cache TTL remains 60 seconds.

Executed: CockpitTest + OpenApiCoverageTest **20 passed / 5270 assertions** (17 cockpit + 3 contract tests; SQLite). Includes encrypted-code search, invalid code/provider mismatch, mixed memberships/test exclusions, source deduplication/resolution, period dates and DST. **5 Chromium browser contract tests passed** for custom range validation, donut/commission drill-downs, usable-card filters and automatic polling; these use controlled responses from recorded HTTP fixtures, not real-service E2E. Frontend TypeScript and targeted ESLint passed. Targeted backend Pint and PHPStan (0 errors, 512M worker limit) passed.

11 actual local HTTP captures include 200/401/403/422, generated by scripts/capture-cockpit.php using an isolated SQLite database and temporary PHP HTTP server. Task migration 170000 apply/rollback/reapply passed there. It was also selectively applied to the configured existing local MySQL shopbonus database at 127.0.0.1; other pending migrations were not applied by this task. Real stored local aggregates reconcile: active webshops 8, consumers 3, usable vouchers 0, approved period commission EUR 0, wallet liability EUR 0, open actions 1. All sources report ok, no degraded warnings. No production/provider numbers are claimed.

Evidence: docs/api/COCKPIT_HTTP_CAPTURE.json, docs/api/COCKPIT_LOCAL_MYSQL.json, docs/api/README.md and sibling frontend docs/admin-cockpit-reporting.md. New migration: 2026_10_31_170000_add_voucher_acquisition_evidence.php. Frontend tests carry their own captured fixture in e2e/fixtures/admin-cockpit.json and do not depend on a sibling checkout.

Next release action: review the uncommitted patch, apply the task migration in the target environment before deployment, configure explicit test-account IDs, verify real authenticated browser/provider journeys and record acquisition links only from independently verified voucher-source relationships. Full repository suites, production load and live provider attribution NOT RUN. Checklist boxes remain unchecked pending an implementation commit/SHA per AGENTS.md.


AI support final local evidence (2026-10-31): Support + OpenApiCoverageTest 185 tests, 183 passed, 2 database-specific skips, 6659 assertions. Final focused policy/context/admin regression 10 passed/130 assertions (overlapping subset). All 13 controlled Chrome browser regressions passed. Frontend typecheck/targeted ESLint, targeted Pint/PHPStan and diff whitespace checks passed. Historical actions now respect current suggestion policy. Temporary capture server/credentials/database removed. No live E2E credentials supplied; real provider/worker/Reverb/MySQL acceptance remains open. Uncommitted on HassanDev; no commit SHA or deployment. Exact next release action remains the real-service acceptance documented above and in ../docs/ai-support-frontend-repair.md.

## Merchant landing / inline CMS review — 2026-10-31

Implementation checkpoint on HassanDev (backend base 59916e9, frontend base 3d0b0ad); changes remain uncommitted alongside pre-existing and concurrent work. No migration or deployment.

Implemented separate published/draft snapshots, failure-gated serialized saves, debounced autosave with unload protection, stable preview transitions, and atomic expected-revision checking at Publish. Cancel restores the approved publication while saved drafts remain on the server. NL/EN public content follows the shared locale and a persisted cookie; server metadata refreshes with that cookie. Public fetches use no-store; failures retain an already loaded approved snapshot or show an unavailable state, never fallback marketing defaults.

The on-page settings panel provides section drag/drop and keyboard ordering, visibility, structured collection addition/removal/reordering, platform descriptions, benefit/step icons, navigation/footer links, FAQ authoring, SEO text, schedule controls and an explicit historical publication picker. Shared CMS images have an authenticated paginated library, uploads, crop ratios/focal positions, alt text and desktop/mobile variants. Main imagery uses approved media slots with labeled code-native illustrative fallbacks; external stock photographs were removed. The integration query is retained through onboarding and validated against the live catalogue when opening App Store.

Schedule publication uses the existing CmsScheduler API and pins the saved revision, keeping the previous publication live until execution. Further draft saves invalidate that pinned schedule; pending schedule cancellation remains in Content Studio. The existing visibility-window fields can also be applied immediately through Publish. The existing shopbonus:publish-scheduled-content scheduler command must run in the deployment. Schedule visibility checks now run outside the publication cache.

Onboarding diagnosis: BusinessProfile already defaults country_code to NL and explicitly requires KvK for Dutch businesses. Updated the stale missing_fields assertion to kvk_number; no business-verification or activation rule relaxed.

API captures: docs/api/MERCHANT_LANDING_CAPTURE.json, from a local HTTP server with isolated SQLite and synthetic actor/session context (not browser-auth proof). New media GET aliases and optional Publish revision are documented in OpenAPI/README. No production credentials used. Temporary capture router is removed after capture.

Validation results are recorded in frontend docs/merchant-landing-acceptance.md. Checklist items remain unticked until a commit records a SHA, per AGENTS.md. Exact next action: review these uncommitted changes separately from other work, run deployment acceptance with real sessions/media and confirm the existing scheduled-content command is running. Full repository backend suite, production build, MySQL/load and deployment/provider acceptance NOT RUN.


Final verification note: a later full frontend typecheck reported TS2307 in src/components/over-ons/AboutUsInlineCms.tsx:27 (missing ./AboutUsCmsControls). This is outside the support changes; earlier full typechecks passed. Targeted support lint and browser checks are separate evidence. No unrelated About Us edit was made.


## Sales attribution review follow-up — 2026-10-31

Local implementation, acceptance PARTIAL. Backend HassanDev base 59916e9; frontend HassanDev base 3d0b0ad. All changes remain uncommitted alongside pre-existing work; no commit, push, migration or deployment.

API-driven merchant analytics replaces sample figures and toast-only actions, with 7/30/90-day business-timezone sales series, currency selection, scoped order drill-down and actual voucher/banner navigation. Embed tracking now requires the latest recorded analytics+marketing CMP decision; storage/assignment/measurement fail closed and withdrawal clears metadata and revokes assignments. Assignment tokens (including holdout) and retry-safe click IDs reach the cart metadata bridge. Unknown viewability emits no impression; bounded queues preserve UUIDs across retries. Registered storefront origins get credential-free embed CORS.

Voucher attribution matches per-shop hashes. First linked evidence and the order label/click source stay consistent inside an order-row lock; unattributed orders may gain first evidence. Network totals read authoritative order refunds and separate currencies, wallet kinds and units, including available consumer credit. Moneybird is explicitly provider_not_verified; internal drafts/references are not promoted into issued invoices.

Final affected PHP run: 55 tests, 53 passed, 2 skipped, 5638 assertions / 70.749s (SalesAttributionE2E + Banners + OpenApiCoverage, SQLite). The 2000-event timing assertion passed with its original 60-second budget after batch-local consent/campaign/version/assignment lookup caching and once-per-batch daily-stat initialization. Earlier runs failed the timing budget (80.97s, 111.00s, 60.66s); no sample/threshold was weakened. The broader Analytics/Banners/CMP/OpenAPI run earlier passed all 75 application tests with 2 skips, but had 2 YAML errors; those were corrected and final OpenAPI tests passed in the 55-test run.

Public embed lifecycle harness: 8/8 passed. Frontend Chromium contract fixtures: 2/2 passed (API totals, date requests, currencies, drill-down, links, error/retry); these are fixtures, not provider evidence. Frontend typecheck passed. Targeted Pint and PHPStan passed before the final batch-stat refinement; its final check is recorded below. git diff --check passed on touched files. Six existing API contracts contain actual isolated local HTTP captures in docs/api/SALES_ATTRIBUTION_CAPTURE.json; temporary loopback fixture router/server removed.

Details and storefront contracts: docs/analytics/SALES_ATTRIBUTION_REVIEW.md. No new route or migration. Checklist intentionally remains unchecked until a commit provides the required SHA. Remaining: actual host CMP/storefront adapters must implement getConsentId and setAttribution and be verified through signed paid orders; live randomized holdout completeness, MySQL and one-million-event staging acceptance NOT RUN. Moneybird has no delivery/reconciliation client or verified credentials/account/legal acceptance; provider integration remains open. Exact next action: wire and verify each supported storefront bridge, then implement and authenticate Moneybird issuance/delivery/status reconciliation under OPEN-FINANCIAL-LEGAL and execute staging/provider acceptance.

Verified backend checkpoint: focused MerchantLandingCmsTest / OnboardingTest / OpenApiCoverageTest: 36 passed, 5,358 assertions (SQLite). Targeted PHPStan: 0 errors. Targeted Pint passed after formatting the added CMS tests. Earlier OpenAPI parse failures were resolved before the successful final run; concurrent unrelated changes are preserved.

Final quality closeout: targeted Pint and PHPStan passed after the final batch-stat refinement; frontend typecheck passed. Temporary capture router is absent and its server was stopped. Local source changes are ready for review; the explicitly listed storefront and Moneybird implementation/acceptance work remains open.

Final API documentation recheck after request-example updates: 3 passed / 5123 assertions / 16.608s.


Final rerun resolution: the unrelated AboutUsCmsControls module subsequently appeared and the full frontend typecheck passed again. Targeted support ESLint passed. After the final stale-history generation guard, human-reply and reconnect browser checks passed; the refresh check first timed out in page.goto (ERR_ABORTED before support assertions), then passed alone on retry. The earlier complete 13-case browser suite passed. Shared local test-results also contained concurrent unrelated homepage/CMS runs; no unrelated files or processes were changed. Final backend PHPStan remains zero errors, and the final policy-focused regression passed. Current release limitation is live-service acceptance, not a known support test failure.


## Homepage + inline CMS review correction — 2026-10-31

Backend HassanDev/base 59916e9; frontend HassanDev/base 3d0b0ad. Uncommitted, unrelated edits preserved. No migration, commit/push or deployment. Frontend report: sibling frontend docs/homepage-cms-review.md.

Backend validates the rendered benefits/CTA/FAQ sections, their plain text/safe links, extended order/visibility keys and existing responsive image asset references. Admin history includes resolved revision content for inspect/select/confirm restoration. Homepage public reads check schedules every request; homepage/coupon HTTP responses use no-store, matching uncached Next.js/client requests. Existing service publication/restore audit and authorization paths retained.

Executed: HomepageCmsTest 10 passed/66 assertions; expanded HomepageCmsTest + CmsScheduleAndSuggestionTest + OpenApiCoverageTest 22 passed/5293 assertions (SQLite). Five real local HTTP captures in docs/api/HOMEPAGE_HTTP_CAPTURE.json (public EN homepage/coupons, admin history 200/401/403), isolated fixture DB and synthetic actors; temporary server/database/tokens removed. TypeScript passed at checkpoint. Targeted Pint formatted two task-owned files; frontend browser/lint final results are recorded in the frontend report. No production/provider acceptance is claimed.

Next: finish controlled browser regression verification, review uncommitted patch, then run real authenticated Super Admin/public NL/EN journeys and compare the approved design reference when available. Checklist stays unchecked without implementation commit/SHA.

Homepage final verification: TypeScript PASS; targeted frontend ESLint PASS (zero errors, five unused suppression warnings); targeted backend Pint PASS; 22 backend tests/5293 assertions PASS after API documentation changes. Eleven core Chrome browser regressions and responsive layout PASS; coupon destination/expiry isolated rerun PASS, timing now controlled by the browser clock. Desktop/mobile screenshots inspected. Real-service acceptance remains unverified; full report and final deterministic-test result are in sibling frontend docs/homepage-cms-review.md.

Final deterministic coupon clock check PASS (1 test, 11.8s); isolated desktop/mobile rerun PASS (1 test, 6.4s). The earlier parallel layout retry timed out awaiting navigation load; fresh preview and explicit DOM/content readiness resolved the verification run. All 13 browser scenarios have passing evidence across the main run and these isolated reruns.

Merchant inline CMS final browser evidence: 14 cases have passing focused executions (controlled APIs), including save-before-navigation/failure retention. Targeted ESLint and PHPStan pass; 36 focused backend tests/5,358 assertions pass. Desktop/mobile fixtures were visually reviewed; see frontend docs/merchant-landing-acceptance.md. Full typecheck has reported unrelated concurrent About Us/discovery errors; no production acceptance claim. Changes remain uncommitted, no migrations.

Final typecheck limitation update: the last whole-frontend run reports missing generated .next-about-cms/dev/types files during concurrent test cleanup (TS6053). Earlier About Us/discovery source errors were also outside this task. Do not report whole-repository typecheck/build as green.
# About Us inline CMS review follow-up — 2026-10-31

Code repair implemented; release acceptance remains PARTIAL pending authentic
approved photography/company records and deployed scheduler/browser acceptance.
Branch HassanDev, base 59916e9, uncommitted. No migration/new endpoint.
Responsive media validation/resolution, comparison content in versions,
locale-bound restore, optional reviewed revision guard on Publish, and uncached
public publication/window evaluation. Five real isolated HTTP captures recorded.
AboutUsCmsTest + OpenApiCoverageTest: 17 passed, 5207 assertions. Targeted Pint
passed. Sibling frontend has the controls and controlled browser regressions.



## Live storefront studio review correction — 2026-10-31 / 2026-11-01

PARTIAL whole-card acceptance; implementation and focused validation are being finalized. Backend HassanDev base 59916e9, frontend HassanDev base 3d0b0ad; all changes uncommitted alongside existing work. No migration, commit/push or deployment. Actual storefront frame plus opener fallback, strict authenticated messaging, failure-gated Save/Publish, timestamped currency-aware native cart contract, bounded publication refresh, functional size handles/corner snapping/inline missing-target warning, explicit unsupported viewability and editable AI text/image/CTA layers. Details and exact next acceptance actions: docs/banners/STOREFRONT_REPAIR.md. Real storefront adapters/themes and genuine model/merchant acceptance NOT RUN. API examples captured on a real isolated local HTTP server in docs/api/BANNER_STUDIO_HTTP_CAPTURE.json; fixture server/database removed by the script. Final test results follow below.


Final local verification — 2026-11-01: 35 focused PHP tests, 33 passed and 2 existing database-specific skips, 5,452 assertions. Twelve embed lifecycle tests passed. All eight Chromium fixture regressions passed (1.0m): Save 409/500 failure gates, real iframe embed/live edits/publish and mobile frame, resized dimensions/layers including frame-exit completion, inline target recovery, forged origin/source rejection, native popup/opener pairing and stable corner snapping. Whole frontend TypeScript and targeted ESLint passed. Targeted PHPStan: zero errors; targeted Pint and diff whitespace checks passed. Five real HTTP captures include public config, authorized/unauthorized editor draft and completed AI show/index (explicitly synthetic provider; no live model claim). Capture database/server removed; dedicated frontend test server stopped. Whole-repository suites/build/MySQL/real storefront and provider acceptance NOT RUN. Uncommitted on HassanDev, backend base 59916e9/frontend base 3d0b0ad; no migration or deployment. Exact next action: review the isolated patch, deploy/configure FRONTEND_URL and verify actual merchant themes/opener policy, platform cart adapters and genuine model/human design acceptance.

## Affiliate detail review — 2026-11-01

PARTIAL whole-card acceptance; local implementation verified on HassanDev, base 59916e94c26cada8696939845e40deca6d1d4697. Existing uncommitted edits preserved, no commit/push/deployment or application-database migration. Settlement gate, shared policy, bounded pagination, PHP 8.5 SOAP, image validation/variants and enrichment refresh were already in the working tree and verified. This continuation fixed research evidence-ID collisions, added audited/versioned Super Admin advertiser-permission recording and invalidation on merchant edits/duplication, and corrected detail consent/state lifecycle.

Executed PHP 8.5 SQLite: 81 tests, 80 passed, 1 skipped, 5721 assertions; final OpenAPI 3 passed/5123 assertions. Targeted Pint/PHPStan (zero errors), frontend TypeScript/ESLint and 3 Chromium fixture journeys pass. Four real local HTTP captures in docs/api/AFFILIATE_DETAIL_HTTP_CAPTURE.json; temporary server/database removed. Broader Offers run has an unrelated voucher claimed_at failure (44 tests, 42 passed, 1 skipped, 1 failed); full repository green is not claimed.

Exact next action: review patch and the two pending affiliate migrations, verify provider endpoints/response fields and promotion/exclusion mappings with authorized accounts, then execute authenticated browser/MySQL/staging and reference-image acceptance. New operator commands and all limitations: docs/discovery/AFFILIATE_DETAIL_REVIEW.md. Checklist remains unchecked pending a commit SHA; provider and full-card acceptance remain open.

## Multilingual CMS review repairs — 2026-11-02
Implementation checkpoint, uncommitted on HassanDev (backend base 59916e9; frontend base 3d0b0ad). Extensive pre-existing edits preserved. No migration, deployment or commit.
Manual Publish requires the reviewed revision, checked under the existing row lock; preview returns the same snapshot's draft_revision. Existing legal approval, scheduled publication and history flows remain. Mutable public CMS/page-index/FAQ/consent configuration uses no-store; frontend CMS requests explicitly bypass caching. Role chrome/notification-management/checkout labels use localized interface keys. Rich document controls preserve backend-allowlisted nodes/marks/media. Explicit visitor selection is distinguished from automatic locale storage; published alternates drive page language navigation. Tracker lifecycle hooks stop controlled SDKs, delete optional cookies/storage, and replace the document for a loaded SDK without shutdown support.
Executed: 20 focused CMS/scheduling/consent tests PASS (278 assertions); API coverage 3 PASS (5123 assertions); targeted Pint PASS; frontend TypeScript + targeted ESLint PASS; six frontend regressions PASS; nine real HTTP captures (isolated SQLite + synthetic session) in docs/api/CMS_REVIEW_HTTP_CAPTURE.json. Browser/production/real-provider acceptance NOT RUN; no claim of an exhaustive all-screen language audit. Checklist remains unchecked pending implementation commit/SHA.

Final multilingual CMS validation checkpoint: combined backend CMS/scheduling/consent/OpenAPI run 23 PASS, 5,401 assertions; frontend six node regressions PASS, two real Chrome consent lifecycle regressions PASS (including unmanaged SDK document replacement and repeated re-consent), TypeScript and targeted ESLint PASS; diff whitespace checks PASS. This supersedes the earlier browser NOT RUN note for the focused consent fixtures only. Full authenticated app journeys, exhaustive role-wide translation coverage and real tracker providers remain unverified. No new commits or migrations; unrelated working-tree changes preserved. Exact next action remains review scoped changes and run authenticated NL/EN acceptance before coordinated client/API release.

## Notification email destinations and template UI — 2026-11-02
PARTIAL release acceptance. HassanDev, backend base 59916e94c26cada8696939845e40deca6d1d4697; frontend base 3d0b0adb3c72abc8d1680bc2aa062cae00349e29. Uncommitted; unrelated working-tree edits preserved. No migration, API contract change, commit, push, live configuration change, template publication or customer email.
Email rendering now maps legacy action roots through config/notifications.php to real Next.js pages; environment overrides are documented in .env.example. Workspace support opens /dashboard/support with conversation and workspace context; configured paths reject external destinations and injected queries. Existing FRONTEND_URL supplies the origin. Template UI adds versioned checksum-aware editing, field limits/approved variables/audit reason, readable synthetic preview and existing password/OTP recovery. Independent publication remains enforced by existing backend APIs.
Executed: 18 focused notification action-link/delivery/template PHP tests PASS, 119 assertions; targeted Pint PASS; frontend TypeScript and targeted ESLint PASS; three real Chrome browser tests with intercepted API fixtures PASS (versioned save + preview without publish, stale edit preservation, password/OTP retry). Screenshot visually inspected: sibling frontend docs/notification-template-preview.png. Browser fixtures are not real-provider/authentication acceptance. Initial browser runs failed because the bundled Chromium executable was absent and fixture CSRF interception was missing; final run used installed Chrome and corrected fixtures. Full ten business journeys and real mail transport NOT RUN.
Exact next action: Hassan confirms the five destination defaults and deployed FRONTEND_URL listed in sibling frontend docs/notification-template-review.md, applies approved deployment configuration/cache rebuild, and verifies authenticated email navigation including multiple workspaces. Live provider and full-card acceptance remain pending. Checklist remains unchecked until a commit SHA exists.
Browser harness note: all three cases reported ok, but Playwright remained waiting during Windows webServer teardown after port 3327 had closed. The wrapper was interrupted after completion; no clean aggregate runner exit is claimed. Test assertions passed; server teardown needs follow-up if this dedicated config is used in CI.

## Backend merge resolution — 2026-11-02
Resolved all 33 unmerged paths on HassanDev, HEAD d8a85f9d7b3653133f190fbee3c1bf7c881863c3, incoming MERGE_HEAD ad1166b9783d90f3902aa6fc4adae098c4ad77d8. Existing merge additions and local notification configuration retained; no merge commit, push, migration or deployment performed. The merge remains ready for final review/commit after staging the verification checkpoint.
Preserved independent additions on both sides: notification path environment variables, points pricing/program-specific reward rules, canonical research hashes and same-origin research helpers, support reference parsing/conversation follow-up/FAQ matching, both support cost estimate fields, bounded program-selection refresh, mixed-currency reporting, and queued voucher exports with the claim-date filters shared by list/detail/export. Current HassanDev settlement gating and historical snapshot behavior remain; resolving this merge does not authorize instant credit or relax financial eligibility. Current-state Cockpit cards retain unavailable historical comparisons, and consumer signup identity remains independent of workspace membership. Old fixtures were reconciled with those existing invariants rather than changing production gates to satisfy older assertions. QA inventory now produces repository-relative component names on Windows and Unix.
OpenAPI merged structurally: 419 current paths + 418 incoming paths -> 423 distinct paths. Captured example responses remain intact; independent incoming README capture sections retained. Original YAML formatting preserved for unchanged paths. API coverage: 3 passed, 7,780 assertions. PHP syntax checks on conflicted PHP files passed; full application PHPStan passed with zero errors; targeted Pint and staged whitespace checks passed.
Initial whole-suite run: 1,230 tests; 1,164 passed, 14 failed, 25 errored, 27 skipped (20,561 assertions). It preceded the final compatibility repairs; it is not a final green-suite claim. Focused reruns use OPENSSL_CONF pointing to the installed PHP 8.5 extras/ssl/openssl.cnf, without changing system environment or production config. A Windows POSIX-filemode assertion remains an environment-specific validation limit. Final focused results are appended below.
Exact next action: review the staged merge and final test evidence, run Linux CI/MySQL checks, and create the merge commit when desired. No new schema was authored by the resolution; incoming migrations remain part of the pending merge and were exercised only in isolated test databases. Checklist remains unchecked without a commit SHA.
Final focused rerun: 200 tests, 196 passed, 1 failed, 3 skipped, no errors; 1,832 assertions. The sole failure is QaCiImportTest::test_ci_import_retains_failures_sha_execution_time_and_redacts_artifacts: Windows fileperms returns decimal 438 (0666), while the test requires decimal 384 (0600). Import/redaction assertions pass before that assertion. No test was disabled and production permission checks were not relaxed. Evidence: docs/qa/MERGE_RESOLUTION_2026-11-02.json. Initial full-suite failures are historical; full 1,230-test suite has not been rerun on the final state, so whole-suite green is not claimed. Final API coverage 3 PASS/7,780 assertions and PHPStan zero errors. Follow-up: execute Linux CI (including the POSIX permission assertion) and MySQL before release.
Supplemental conflicted-module regressions: 68 PASS, 565 assertions (support evidence, capabilities/catalog, merchant overview, public discovery, onboarding, research hashing). Combined focused regressions: 268 tests, 264 passed, 1 Windows POSIX-permission assertion failed, 3 skipped, no errors; 2,397 assertions. API coverage is an additional 3 PASS/7,780 assertions. Final staged whitespace check PASS and zero unmerged entries. All resolution changes staged; merge commit and push not performed.

## One CMS NL/EN/DE translation-quality checkpoint — 2026-11-02

PARTIAL / release NO-GO. Backend HassanDev base 926ab34; frontend HassanDev base 5abab50; all implementation changes remain uncommitted. No push, deployment or persistent application DB migration. Owner brief and detailed source findings: docs/cms/ONE_CMS_ACCEPTANCE.md.

Reused the existing CMS suggestion/revision/quota/audit flow: bounded database queue, configurable gpt-6.1-sol Responses JSON contract, source/target freshness and legacy-proposal rejection, protected facts/placeholders/negation/noncash/glossary rejection, model/token/hash provenance (including rejected billed output), NL-save EN/DE enqueue with save-preserving deferral, German CMS/FAQ acceptance, and immutable NL master revision references for stale translation reporting and restore. Frontend change is limited to the translation-status type. AI remains disabled by default and never publishes.

Executed local SQLite CMS/OpenAPI/unit suite: 81 passed, 8,378 assertions; JUnit docs/cms/translation-regression.junit.xml. Targeted PHPStan/Pint and frontend npm run typecheck passed at checkpoint (final status in evidence manifest). Eleven isolated real loopback HTTP captures in docs/cms/TRANSLATION_HTTP_CAPTURE.json; synthetic Sanctum actor/recent-password sessions, not real browser/CSRF or staging proof. Temporary router/server/SQLite fixture removed. No live provider request. Checklist stays unticked without an implementation commit, per AGENTS.md.

Pending migration: 2026_11_02_120000_add_cms_suggestion_provenance (suggestion source hash/model/tokens, immutable revision source_master_revision). Worker: php artisan queue:work database --queue=cms-translations --tries=1 --timeout=45; monitor that queue and keep retry_after >45 seconds. Do not apply to an unspecified deployment.

Exact next action: extend CmsSuggestions segment extraction/replacement with canonical structured field paths for HomepageSchema, MerchantLandingSchema and AboutUsSchema; validate against the original page type/key; route legacy landing AI callers through this existing review queue. Then finish German frontend/editor/email/SEO, the existing media pipeline and Quality Center field/usage executors. No complete discovery or five exhaustive coverage denominators exist yet. All mandatory T01–T23 staging cases remain UNTESTED, and 1,000-record automation, monetary budgets, semantic/language review, real image editing/WebP/restore and release approval remain open. Wallet/voucher/order/affiliate/Answer24/Banner Studio engines untouched.

## Platform launch acceptance follow-up — 2026-11-02
PARTIAL / production NO-GO. Backend HassanDev base 926ab34068e18cca081b7b168cc2ed6f7622fe29; frontend HassanDev base 5abab5083ce7036177d7cbef27688f449d6b0f35. Uncommitted; pre-existing CMS edits preserved. Current code already enforces new affiliate settlement timing and documents public webshop cover; merchant analytics already calls scoped APIs. Updated BusinessJourney to verify pending/approved credit stays unavailable until synthetic settlement and replay credits once. Frontend retires sample transactions/vouchers endpoints with 410/no-store and keys analytics responses by current selection, resolving React effect lint errors.
Executed PHP 8.5 SQLite: 109 PASS / 8,767 assertions (Journey, Affiliate, OpenAPI, Analytics, Wallet, Checkout); targeted Pint, TypeScript and ESLint PASS. Four Chrome assertion cases PASS on rerun; initial cold-compilation navigation timeout recorded. Read-only LOCAL launch check: 9 PASS, 12 FAIL, 4 WARN, 2 MANUAL; docs/release/LAUNCH_CHECK_2026-11-02.json. No deployment, persistent DB migration, provider activation, commit or push. Checklist remains unchecked without implementation commit/SHA.
Exact next action: finish production build verification and review the patch; then run Linux/full CI, MySQL and authentic staging/provider/payment/restore acceptance against a clean release SHA before production rollout. Production target/access and acceptance evidence remain outstanding. Full details: sibling frontend docs/platform-launch-acceptance.md. Historical GO_NO_GO evidence is preserved, not rewritten as a current full audit.

Final launch follow-up validation: frontend production webpack build PASS (exit 0, 65 pages); Chrome aggregate 4 PASS in 1.0 minute (exit 0). Existing metadataBase warning uses localhost for social image URLs and remains a deployment configuration review item. Dedicated port-3331 dev server stopped; generated test/build tsconfig includes removed. No production deployment. Next action is patch review and Linux/MySQL/authentic staging/provider acceptance against a clean SHA; local launch-check failures remain open.

## Merchant dashboard and voucher review — 2026-11-02

PARTIAL release acceptance; local implementation verified. HassanDev backend base 926ab34/frontend base 5abab50; scoped changes remain uncommitted alongside pre-existing CMS and launch edits. No deployment, provider activation, new package or migration. Owner confirmed reserve at explicit payment start; existing quote/Confirm flow already follows that boundary. D-106 no-per-consumer-cap preserved. Reused existing reusable voucher resolution, paginated disputes, protected queued exports and historical snapshots; connected missing UI and added scoped stock/expiry KPIs plus adjacent-period lifecycle comparisons.

Executed: PHP 8.5 SQLite 75 tests, 72 PASS/3 SKIP, 8,462 assertions; PHPStan zero errors; targeted Pint; frontend TypeScript/ESLint; five Chrome fixture journeys PASS (50.5s, exit 0). Three real HTTP captures in docs/api/VOUCHER_DASHBOARD_HTTP_CAPTURE.json. Composer audit clean; npm audit 25 vulnerabilities (22 moderate, 2 high, 1 critical). Full findings, commands/evidence and validation history: docs/merchant-voucher-review.md; JUnit docs/merchant-voucher-review.junit.xml.

Exact next action: review scoped patch and npm advisories, remediate dependencies, run the three pcntl/database race cases on Linux and genuine staging merchant/mobile/worker flows; measure staging P95 below 300 ms on a clean SHA. Do not report concurrency or production readiness as fully passing. Checklist remains unticked without an implementation commit/SHA.

Final review checkpoint: OpenAPI coverage rerun 3 PASS/7,780 assertions; targeted Pint PASS; final TypeScript/ESLint PASS. Five final Chrome cases PASS with clean exit. Dedicated port-3332 dev server stopped; only task-added tsconfig include entries removed. No fixture SQLite database/server/log remains. No commit or deployment.

## Local CSRF endpoint restored — 2026-11-02

Branch `HassanDev`, HEAD `926ab34`; existing uncommitted changes preserved. The reported `http://localhost:8010/sanctum/csrf-cookie` failure was reproduced as connection refused: no backend listener on port 8010. Started PHP 8.4.15 in a hidden background process using `artisan serve --host=127.0.0.1 --port=8010 --no-reload` (launcher PID 16200). No application/configuration changes or migrations.

Executed real HTTP verification with `Origin: http://localhost:3000`: **204 No Content**, both `XSRF-TOKEN` and `shopbonus-session` cookies, `Access-Control-Allow-Origin: http://localhost:3000`, and credentials allowed. Cookie values excluded from recorded output. Runtime repair complete; full login/browser journey NOT RUN. Next action: retry the frontend request; after a backend stop/reboot, restart the same command from the backend directory. Runtime logs: `storage/logs/csrf-server.out.log` and `storage/logs/csrf-server.err.log`. No commit made; documentation checkpoint uncommitted alongside pre-existing work.

## Platform reliability review — 2026-11-02

PARTIAL production acceptance. Backend HassanDev base 926ab34068e18cca081b7b168cc2ed6f7622fe29;
frontend HassanDev base 5abab5083ce7036177d7cbef27688f449d6b0f35. Uncommitted;
existing unrelated edits preserved. No commit, push, deployment or application DB migration.
Full finding dispositions and exact next release actions: docs/reliability/REVIEW_2026-11-02.md.

Verified already-present OTP logging removal, PHP 8.5 SOAP signature, private
backup roots and cover OpenAPI path. Added restored encrypted dispute/QA
attachment integrity/reference regression; it exposed and fixed Windows nested
archive separators. Extended existing observations to configured connection/queue
pairs, worker polling, Redis ready counts and explicitly labelled enqueue age.
Exact Redis ready wait remains UNKNOWN. Native Redis interface mocked; real
Redis infrastructure acceptance NOT RUN. Added admin cockpit operations panel,
missing/error/refresh states and removed the old inferred Online indicators.
Owner explicitly confirmed management allocation reporting; formal revenue
recognition remains unverified. API recovery targets are explicitly UNAPPROVED;
offsite/key recovery/infrastructure drill remain false/unverified. Hourly logical
backups do not establish a 15-minute RPO.

Executed: PHP 8.4 combined Reliability/SOAP/OTP/OpenAPI 76 tests: 72 passed,
4 skipped, 8188 assertions. Skips: three MySQL concurrency cases and Windows
symlink privileges. Final operations/OpenAPI rerun: 10 passed, 7831 assertions
(includes final worker-event/throttle and recovery-target guards). PHP 8.5.0
SOAP: 14 passed, 98 assertions; exact PHP 8.5.11 unavailable/NOT RUN; existing
WAMP Xdebug DLL startup warning. Targeted Pint passed; targeted application
PHPStan zero errors. Frontend TypeScript/targeted ESLint passed; real Chrome
fixture regression passed, screenshot visually inspected. Three actual local
HTTP captures in docs/api/RELIABILITY_REVIEW_HTTP_CAPTURE.json; fixture DB,
server and log removed. JUnit evidence: docs/reliability/review-*.junit.xml.
Full repository, production-volume restore, live Redis/provider and deployed
browser acceptance NOT RUN. No migration needed. Checklist stays unticked until
an implementation commit/SHA exists, per AGENTS.md.

Exact next action: review scoped patch, configure/restart real workers with the
JSON target map, verify the authenticated combined UI and alert delivery, then
independently verify offsite copies, secure APP_KEY/backup-key recovery and a
timed production-version/volume infrastructure recovery drill. Establish costs
and feasible data-loss/recovery evidence before requesting RPO/RTO approval.
Do not mark whole Trello #30 DONE.

## Revised platform brief review — 2026-11-02

PARTIAL / production NO-GO. Branch HassanDev, base cb53b8453da51fbd376b09dc33ae234407988772; scoped changes remain uncommitted. No commit, push, deployment, provider activation or persistent application database migration. Full finding dispositions and acceptance corrections: docs/release/REVISED_BRIEF_REVIEW_2026-11-02.md.

Owner now explicitly authorizes immediate spendable affiliate credit, superseding D-089/D-107 for new evaluations. Reused existing instant-credit/rejection/expiry behavior; changed default and froze selected timing with the rule. Historical obligations remain unchanged. EUR 4.75 acceptance explicitly configures a 100% share with commission 475 cents; the 70% default share remains. Replacement inventory keys now use the original voucher public ID under the existing unique inventory constraint; a second report cannot allocate another replacement. Existing OTP log removal, PHP 8.5 SOAP signature, original-voucher compensation guard and cover OpenAPI path were already present and verified. Current onboarding/catalog/capability fixtures pass with ownership/currency gates retained. Acceptance retains audited 404 for non-members, 403 for denied actions within membership; Answer24 checks are platform disclosure safeguards while Answer24 owns final outreach decisions/execution.

Executed PHP 8.4.25: final combined regression 172 tests, 171 PASS / 1 existing image-cover cross-reference SKIP, 9197 assertions; additional wallet/checkout 55 tests, 50 PASS / 5 MySQL concurrency SKIP, 455 assertions. Additional OpenAPI rerun 3 PASS / 7780 assertions. PHP 8.5.0 SOAP 14 PASS / 98 assertions; exact 8.5.11 unavailable / NOT RUN. Targeted Pint and application PHPStan pass. Four actual loopback HTTP captures prove one immediate credit after replay, retained balance after rejection, audited non-member 404 and missing-cover 404. Evidence: docs/api/REVISED_BRIEF_HTTP_CAPTURE.json and docs/release/revised-brief-*.junit.xml. Synthetic SQLite fixtures do not prove provider installation or staging acceptance. Initial expectation/setup failures are recorded in the review report; only successful final runs count. Checklist remains unticked pending implementation commit/SHA.

Exact next action: review/commit scoped patch; deploy through the existing process with config-cache rebuild and verify consumer UI wording. Run Linux/full CI and MySQL concurrent resolution against the release SHA. Complete authentic provider/payment/recovery/performance acceptance. Lightspeed E-Series, Squarespace, Mijnwebwinkel and CCV connectors remain missing; PrestaShop module ZIP remains unshipped; every ecommerce platform remains live unverified. No whole-platform readiness claim.

## Backend merge completion — 2026-11-02

Completed the pending main -> HassanDev merge. Parents: local 0e0a6da and incoming 313dfad (313dfaddba4a622aa4e0675cd8cb6215f4ba21a4). The index already matched HEAD with no unresolved entries; no application conflict edits were needed. Preserved the latest immediate affiliate-credit policy, historical reward snapshots and original-voucher replacement allocation safeguard.

Executed PHP 8.4.15 with Xdebug disabled: AffiliateTest, AffiliateCashbackOffersE2ETest, MyBenefitsE2ETest, VoucherDisputeTest, BusinessJourneyTest and OpenApiCoverageTest: 71 tests, 70 passed, 1 skipped, 8480 assertions, exit 0. The initial broad-filter invocation was stopped after slow startup and replaced with explicit test files. Conflict-marker scan and staged/unstaged whitespace checks passed. Full suite, Linux CI and MySQL concurrency checks NOT RUN.

This checkpoint is included in the local merge commit; identify its SHA with git log -1 after completion. No migrations, deployment or push. Exact next action: review the local merge commit and run normal CI/MySQL checks before publishing. Only the four merge checkpoint documentation files were changed; all existing application code was retained.

## Merchant voucher local acceptance follow-up — 2026-11-02

PARTIAL release acceptance. Backend HassanDev base bd76ff4; frontend HassanDev base b92f46a. Changes uncommitted; no commit, deployment, provider activation or persistent application database migration. Owner requested local tests with dummy data. Reservation-on-view is already excluded by the owner's payment-start clarification; D-106 deliberately excludes per-consumer caps.

Executed PHP 8.5 SQLite regressions: 75 tests, 72 PASS/3 SKIP, 8,468 assertions; docs/merchant-voucher-followup.junit.xml. Three real-process race cases remain skipped (Windows has no pcntl_fork; WSL inventory has no distro). Added scripts/capture-voucher-inventory.php: final independent run PASS/exit 0, 23 real HTTP captures, actual 2,001-code database queue import and queued CSV ready/not-ready/expiry checks, inventory reservation/replay/release counters. Temporary database/server/log/export cleaned up. Actual responses: docs/api/VOUCHER_INVENTORY_HTTP_CAPTURE.json.

Real Chrome desktop/390px browser assertions passed against the isolated running API with dummy merchant login/CSRF/session and no intercepted requests; inventory changed over HTTP and refreshed on focus. Browser helper cleanup completed after a bounded-cleanup repair. The subsequent queue extension initially failed on the Windows temporary-directory notice and an incorrect expected 200; fixed harness writable temp and existing 202 expectation, then independent final backend run passed. Frontend local-acceptance JSON and screenshots preserve browser evidence. Final local sequential P95: dashboard 583.82 ms, offers 206.89 ms (30 samples each, five warmups, one shop/offer, three codes). No representative sub-300-ms staging claim.

Next.js/eslint-config-next upgraded to 16.4.4; PostCSS 8.5.32; sharp locked at 0.35.5. Production npm audit zero vulnerabilities; full audit 20 moderate/zero high or critical. Remaining development-tool chains need compatible remediation; npm major downgrade proposals were not applied. TypeScript, targeted ESLint, 65-page webpack production build, script syntax and Pint passed; existing metadataBase and PHP Xdebug startup warnings remain. Next generated frontend AGENTS.md. Task-added tsconfig includes and npm cache removed. Full brief mapping and exact limits: docs/merchant-voucher-acceptance-followup.md; API handbook links real captures. Structural OpenAPI coverage passes, exhaustive historical-example provenance remains open.

Exact next action: review the uncommitted dependency/evidence changes, remediate the remaining moderate toolchain advisories compatibly, complete the remaining merchant/employee/consumer UI mutation/error journeys locally, run the three races on isolated Linux/MySQL, investigate dashboard latency and then collect representative staging performance/release evidence. Checklist remains unticked until a tested implementation commit provides its SHA, per AGENTS.md. No migrations or engine policy changes introduced by this follow-up.

## Banner storefront dummy-data follow-up — 2026-11-02

PARTIAL genuine merchant/provider acceptance; owner requested local dummy data. Backend HassanDev base b92f46ab2b471fc4796310e059568bdddde276a4; frontend HassanDev base bd76ff420cda1aa180e45e44a521ff0707670b3f; scoped changes uncommitted alongside preserved voucher/dependency work. Added public/embed/shopify-cart-v1.js: opt-in same-origin Shopify native cart reads, explicit two-decimal currency support, bounded polling/timeout, stale/error fallback, post-mutation invalidation, in-flight result rejection and hidden/bfcache lifecycle. Existing bridges are preserved; no checkout attribution write or platform activation claim. Fixed late pairing/draft completion after disconnect in the existing public embed.

Executed: 21 Node lifecycle regressions passed; PHP 8.4 BannerTest/BannerAiTest/LiveBannerStudioE2ETest 30 passed, 329 assertions; two Chrome dummy native-cart cases passed (1280px/390px); frontend TypeScript and targeted ESLint passed. AI provider output remains synthetic. Initial new regression exposed the disconnect bug; initial adapter harness CustomEvent stub and currency fixture assertions were corrected before final passing runs. No endpoint/schema change, new API response capture, migration, deployment, live AI request or commit. Full suites and genuine merchant/provider acceptance NOT RUN. Checklist remains unticked pending implementation commit/SHA per AGENTS.md.

Setup, limits, JUnit evidence and exact next action: docs/banners/LOCAL_DUMMY_FOLLOWUP.md. Review patch, install through an authorized Shopify Theme App Embed in a test store, wire post-mutation notification and collect genuine theme/frame/opener/cart/AI review-and-publish acceptance. Other platform cart adapters remain incomplete.

## Voucher checkout review follow-up — 2026-11-02 (uncommitted)

Implemented locally; PARTIAL genuine-provider acceptance. Backend HassanDev parent b92f46a; frontend HassanDev parent bd76ff4. Points checkout UI is connected; dispute refunds preserve requested/pending/failed/completed with durable intent, stable replay and failed-refund recovery. D-053 EUR shortfall retained. Migration 2026_11_02_190000 not applied to the application database. Executed: checkout/OpenAPI 40 passed + 3 skipped (8174 assertions), final dispute recovery 13 passed (194 assertions), browser 3 passed, TypeScript/targeted ESLint/PHPStan passed. Full implementation, evidence, uncommitted status, migration and exact sandbox next action: docs/release/VOUCHER_CHECKOUT_REVIEW_2026-11-02.md.

## CMS structured bindings and German locale follow-up — 2026-11-03

PARTIAL / release NO-GO. Backend HassanDev base b92f46ab2b471fc4796310e059568bdddde276a4; frontend HassanDev base bd76ff420cda1aa180e45e44a521ff0707670b3f. Scoped changes uncommitted alongside other ongoing work. Reused revision-backed queue with canonical structured text paths and page-schema validation; added shared German UI/auth/role copy, locale selectors, FAQ DE inputs, empty German fallback handling and German identity mail/account preference. No new migration file, commit, push, deployment or live AI call.

Executed CMS 84 PASS / 939 assertions; identity/profile/settings/onboarding/OpenAPI 126 PASS / 9204 assertions; frontend Node 10 PASS; TypeScript, targeted Pint/PHPStan and locale/helper ESLint pass. Broader touched UI ESLint retains three existing set-state-in-effect errors and two image-rule warnings. Real local demo Super Admin login and German Content Studio observed. Initial 3025 CORS failure fixed by isolated frontend on allowed localhost:3000. Existing local CMS schedules/suggestions migration and provenance migration applied in order after first provenance attempt failed on missing prerequisite table. No historical content rewritten. Unrelated notification schema drift remains. Full evidence/limits: docs/cms/STRUCTURED_BINDINGS_FOLLOWUP.md.

Exact next action: consolidate legacy Homepage/AboutUs AI callers and editor review on the queue, finish German page-specific journeys, then implement media transforms/previews/bindings and per-field executors. T01–T23 staging remain UNTESTED. Monetary caps, semantic/language validation, 1000-record pipeline, universal inventory and deployment gates remain incomplete. Checklist remains unchecked pending implementation commit/SHA.

## Notification review follow-up — 2026-11-03

Implemented locally / PARTIAL deployed acceptance. Backend HassanDev base b92f46ab2b471fc4796310e059568bdddde276a4; frontend HassanDev base bd76ff420cda1aa180e45e44a521ff0707670b3f. Changes remain uncommitted alongside preserved unrelated work. Shared identity frontend paths now use existing consumer/dashboard/admin routes with tab/workspace context. Existing central mail legacy-route mapping retained. Frontend shares notification subscriptions by user, catches up after each successful private subscription (including reconnect), paginates templates/deliveries independently, and connects both uncertain-delivery resolution outcomes. Retry requires a real administrator note and explicit unchecked duplicate-risk consent for uncertain deliveries; existing step-up, backend validation and audit APIs are reused. NL/EN/DE controls added.

Executed PHP 8.4: notifications plus IdentityMailTest 57 tests, 54 PASS / 3 database-concurrency SKIP, 349 assertions. Targeted Pint passed. Frontend TypeScript and targeted ESLint passed; 3 Echo lifecycle regressions passed; 7 Chrome/API-fixture browser regressions passed (pagination, explicit retry consent, both resolution outcomes through password/OTP, existing template edit/test/conflict/step-up); final management-only rerun 4 PASS after strengthening success/terminal-state assertions. Earlier syntax/lint corrections were made before the successful final checks. Detailed behavior and test scope: ../shopbonus_frontend/docs/notification-review-followup.md. No endpoint/schema changes; existing APIs reused, so no new HTTP capture. No migration, commit, push, live mail/AI call or deployment. Checklist remains unticked pending implementation commit/SHA.

Exact next action: review/commit scoped changes, rebuild deployment config cache, verify effective FRONTEND_PATH_* and NOTIFICATION_* destination overrides, then exercise actual email links and focused-tab Reverb disconnect/resubscribe recovery with two mounted inbox consumers. Full production/provider acceptance and full repository suites NOT RUN.

## Universal webshop adapter review — 2026-11-03

PARTIAL / release NO-GO. Backend HassanDev parent b92f46ab2b471fc4796310e059568bdddde276a4; frontend HassanDev parent bd76ff420cda1aa180e45e44a521ff0707670b3f. Scoped changes uncommitted alongside preserved unrelated work; no Laravel migration, live activation, reward/spendability policy change, commit/push or deployment. Added E-Series/Ecwid and Squarespace native connector paths, candidate PS9/OC4.1 installation packages, Woo banner plugin and Shopify theme-extension source; connected corresponding merchant credential inputs and actual consumer pending balances.

Executed integration/unit/OpenAPI 208 PASS / 9523 assertions (Windows OpenSSL configuration supplied for RSA fixtures); final package/PrestaShop 7 PASS / 67 assertions; Node theme loader 2 PASS; Chrome API-fixture UI 4 PASS; TypeScript, changed-connector PHPStan and targeted Pint pass. Existing frontend ESLint: five benefits-page hook-effect errors, two App Store loader hook-effect errors, one suppression warning. Three real isolated HTTP catalogue/setup captures recorded. Candidate package syntax/archive checks do not establish native installation/runtime acceptance.

Full evidence, package links, precise limitations and next actions: docs/integrations/ADAPTER_REVIEW_2026-11-03.md. Next: obtain approved Mijnwebwinkel partner schema, implement CCV store-bound auth/update/refund adapter, then run genuine PS9/OC4.1/Woo/Shopify installation/theme/order/refund/disconnect/downstream acceptance. OC4.1 generic order status does not prove capture/refund: payment stays Unknown. All platform verification remains unverified and live ledger NOT RUN. Checklist remains unchecked pending implementation SHA and acceptance.

Adapter review final checkpoint: signed PrestaShop lookup rejects malformed IDs and ping lookups; subsequent package/PrestaShop/connection regression run 26 PASS / 197 assertions, Pint and changed-connector PHPStan PASS. Merchant UI now includes native credential fields plus candidate package download action; final PS Chrome rerun PASS with exact download URL asserted. Shared wallet/types/new browser tests pass targeted ESLint. Earlier full integration/OpenAPI run remains 208 PASS / 9523 assertions; no new whole-provider acceptance claim. All changes remain uncommitted and release PARTIAL/NO-GO as detailed in docs/integrations/ADAPTER_REVIEW_2026-11-03.md.

## Affiliate tracking and central wallet findings recheck — 2026-11-03

Current code findings resolved; provider acceptance PARTIAL. Both repositories HassanDev at bd76ff420cda1aa180e45e44a521ff0707670b3f; existing fixes and unrelated work remain uncommitted. Documentation-only recheck; no new runtime change, commit, migration, deployment or provider activation. Consumer wording supports frozen reward timing and separates actual pending/available/reserved balances. New rewards currently default to immediate validated credit per the 2026-11-02 owner decision; historical settlement policies remain intact. Points checkout and platform_allocation_minor test expectation were already corrected.

Executed: affiliate directory on PHP 8.4.15 and 8.5.0 each 62 PASS/1 existing cover cross-reference SKIP, 478 assertions; exact reported regression 1 PASS/17 assertions; Chrome API-fixture journeys 4 PASS; TypeScript PASS; targeted checkout/library/context/spec ESLint PASS. Benefits-page lint still has five existing hook-effect errors and one suppression warning. Full suites/MySQL concurrency/genuine provider acceptance NOT RUN. Final JUnit and complete disposition: docs/release/AFFILIATE_WALLET_RECHECK_2026-11-03.md and docs/release/affiliate-review-2026-11-03.junit.xml.

Exact next action: review existing uncommitted fixes, choose acceptance release SHA, then collect genuine Daisycon/TradeTracker imports, historical settlement proof, explicit configured points-rule snapshots and duplicate/reversal ledger reconciliation as specified in the report. Revenue recognition remains unverified; checklist remains unticked pending implementation SHA and acceptance.
## Registration and CSRF review — 2026-11-03

PARTIAL release acceptance; implementation changes uncommitted on HassanDev (backend base b92f46a, frontend base bd76ff4). Fixed frontend persistent-419 logout handling, shared/validated CSRF initialization for initial reads and writes, multipart recovery, session retention on transient refresh failure, and registration/session/legal readiness. Backend adds failed-CSRF logout regression, complete example-env catalogue/checker and isolated real-HTTP/browser fixture script. No API contract/schema change or application DB migration.

Executed: identity 63 PASS / 351 assertions; client regressions 16 PASS; TypeScript, targeted ESLint/Pint and 334-key env coverage PASS. Composer and production npm audit clean; full npm audit retains 20 moderate development advisories. Live www/app probes establish trusted CORS/secure cookie flags and empty registration 419 without token versus 422 after real handshake, without creating an account. Full evidence/disposition: sibling frontend docs/auth-registration-review.md; browser reproduction: php scripts/auth-browser-review.php.

Still required: actual provider email, Redis queue/throttle/retry behavior, representative staging P95, approved screenshot matching and deployed full journeys on chosen release SHA. No deployment/commit/push. Checklist stays unticked pending tested implementation SHA and remaining acceptance.

Final auth browser checkpoint: 9 real local Chrome checks PASS, including recovery-code consumption/focus and merchant signup without membership. JSON and visually inspected desktop/mobile captures are in sibling frontend docs/auth-browser-review.json and docs/auth-registration-review.md. Temporary SQLite fixture removed and test servers stopped. Final client 16 PASS; identity 63 PASS/351 assertions; TypeScript/targeted ESLint/Pint/env coverage passed. Production build/runtime, real provider mail, Redis, staging P95 and approved reference matching remain unverified.

## Homepage publication revision follow-up — 2026-11-03

PARTIAL acceptance; implementation uncommitted on HassanDev. Backend base b92f46ab2b471fc4796310e059568bdddde276a4; frontend base bd76ff420cda1aa180e45e44a521ff0707670b3f. Homepage publish requires reviewed revision and reuses CmsService publication lock/check. Frontend saves return the exact revision used by publish/queue. Inline queue, status and cancellation use existing generic CMS scheduling; old homepage visibility-window API retained. No migrations, deployment or commits. Existing unrelated edits preserved.

Initial focused PHP run 34 passed / 8107 assertions; TypeScript and targeted production-source ESLint passed. Follow-up OpenAPI parsed, but shared-tree coverage fails for unrelated workspace offer code-batches route. Live reference and desktop/mobile navigation/30 public HTML samples captured from shopbonus.nl; final results and acceptance limits: frontend docs/homepage-publication-review-2026-11-03.md. Browser retries are tracked there; no completion inferred from fixture tests.

Exact next action: review final scoped evidence, complete genuine authenticated browser acceptance where local startup/readiness failed, align equal-content guest screenshots to approve exact fidelity, measure representative deployed server/API performance, and ensure deployed every-minute CMS scheduler runs. No new decision/migration. Checklist stays unticked without implementation commit SHA.

## Offers and discount code findings — 2026-11-03

Local implementation complete; provider release acceptance PARTIAL. Backend HassanDev at b92f46ab2b471fc4796310e059568bdddde276a4; sibling frontend HassanDev at bd76ff420cda1aa180e45e44a521ff0707670b3f. Scoped changes remain uncommitted alongside preserved pre-existing work. No migration, commit, deployment or provider activation.

Added offer-scoped paginated code-batches read API (vouchers.view), frontend fetch of every batch page, explicit exact-code search, connected sorting/inclusive local dates, matching full filtered export scope, and ordered CSV chunks. Backend normalizes ISO filter instants to UTC for SQL boundaries. Bulk invalidation retains failed selections and distinguishes permission/conflict/session/network/server/rate/validation failures. Historical platform checks accept scoped voucher_id and use frozen terms_snapshot; results append voucher events without overwriting shared inventory state. Manual confirmation is now manually_confirmed, distinct from provider proof, and available on desktop/mobile. Automated adapters remain WooCommerce-only; unsupported/missing integration states are explicit. Current acceptance requires audited concealed 404 for foreign resources and 403 for denied permissions inside visible workspaces; the imported brief's foreign-resource 403 expectation is superseded.

Executed PHP 8.4 SQLite: OffersCodesE2ETest + VoucherInventoryTest + OpenApiCoverageTest **25/25 passed, 8092 assertions**, no skips; docs/offers-code-review.junit.xml. Includes 501-code sorted export, exact offset/date boundaries, historical/current comparison, foreign references and employee denials. All **3 controlled Chrome scenarios passed**, including mobile historical/manual requests, older batch pagination, export filter parity and mixed failure selection retention. Final standalone TypeScript and targeted ESLint passed after temporary Next server shutdown; targeted Pint/whitespace checks passed. Real local HTTP capture **22 exchanges**, including actual new-route 200/401/403/404/422, against isolated migrated SQLite and synthetic credentials; temporary database/server cleaned. No provider requests. Details and validation history: docs/offers-code-review-2026-11-03.md; API contracts and real examples updated.

Exact next action: review scoped uncommitted diffs with the pre-existing work before creating a tested implementation commit; then collect genuine authorized provider coupon/checkout acceptance evidence. Additional automated platform adapters require verified contracts and credentials. Do not infer provider acceptance from fixture tests or mark unsupported platforms verified. Full repository suites, production build, deployed performance and real provider journeys NOT RUN. Checklist entries remain unticked pending implementation commit/SHA per AGENTS.md. No policy decision is needed to expose foreign-resource existence.

After refreshing examples from the final 22-exchange capture, OpenApiCoverageTest passed again: 3/3 tests, 7,848 assertions. Final capture-script Pint passed.

## App Store findings checkpoint — 2026-11-03

PARTIAL / release NO-GO. Both repositories HassanDev, backend base b92f46ab2b471fc4796310e059568bdddde276a4 and frontend base bd76ff420cda1aa180e45e44a521ff0707670b3f. Changes uncommitted; unrelated work preserved. Fixed static service verification claims, backend-status catalog feedback/polling, scoped merchant pause/resume and supported Shopware/Magento order snapshots; aligned the existing optional Shopify discount reads with the registry. App Store-owned NL/EN copy added; provider-authored instruction/error localization remains partial.

Added migration 2026_11_03_200000_add_connection_pause.php; only isolated test SQLite migrated, no application DB migration/deployment/feature activation/commit/push. API contract updated with six actual loopback HTTP captures in docs/api/APP_STORE_PAUSE_HTTP_CAPTURE.json, reproducible by scripts/capture-app-store-pause.php. Full integration/unit/OpenAPI 222 PASS / 9,681 assertions; changed-file PHPStan zero errors; Pint pass. Ten controlled App Store browser checks passed; final combined browser/type/lint results are in docs/integrations/APP_STORE_FINDINGS_2026-11-03.md.

Exact next action: configure authorized staging app keys/per-workspace credentials from docs/integrations/ENVIRONMENT.md; finish Lightspeed C/OpenCart catalog, Shopify writes, native publishers and Adobe async/SaaS engineering, then execute the ten genuine platform runs and five cross-platform journeys from the report. Native cart, remote revocation, banner removal, reinstall isolation and actual provider retry retention remain NOT RUN. No verification flag promoted. Checklist boxes remain unticked pending tested implementation commit SHA and required acceptance.

App Store final verification checkpoint: combined Chrome API-fixture journeys 13 PASS; frontend TypeScript and targeted ESLint PASS; client state tests 4 PASS. Backend full integration/unit/OpenAPI remains 222 PASS / 9,681 assertions, changed-file PHPStan zero errors, Pint PASS. Detailed evidence/limitations: docs/integrations/APP_STORE_FINDINGS_2026-11-03.md. No deployment, production feature activation, main DB migration, commit or push; provider acceptance remains NOT RUN.

## Homepage publication review final checkpoint — 2026-11-03

Implementation verified locally; release acceptance PARTIAL for exact visual parity and representative deployed performance. Uncommitted on HassanDev: backend b92f46ab2b471fc4796310e059568bdddde276a4, frontend bd76ff420cda1aa180e45e44a521ff0707670b3f. Reviewed revision is mandatory at homepage publish; frontend uses its own save response revision. Inline queue/status/cancel use the existing CMS scheduler; loading drafts cannot be edited or published. Legacy visibility-window endpoint retained. No new migrations, application-database migration, commit or deployment. Existing unrelated edits preserved.

Executed: CMS 31 passed / 315 assertions; distinct-admin conflict regression independently passed; six named real-service journeys passed across separate runs (wallet 500/recovery, delayed draft/concurrent publication, queue/cancel, HTTP revision contract, three-variant media/publication and selected history restore). Genuine cookie login/CSRF/TOTP/recent-password; isolated SQLite and dummy actors; no response fixtures. TypeScript, targeted ESLint, Pint, PHP/Node syntax and scoped whitespace passed. OpenAPI syntax passed; full coverage remains blocked by unrelated undocumented connection-pause route. Real publication response examples captured in docs/api/HOMEPAGE_PUBLICATION_REVIEW_HTTP_CAPTURE.json.

Supplied shopbonus.nl desktop/mobile reference captured and inspected; 30 public HTML requests returned 200, local end-to-end P95 646 ms (not server/API P95, field metrics or this unshipped patch). Exact equal-state comparison remains open. Earlier local readiness/HMR/harness failures retained; featured preview needed an explicit UI retry after its existing 8-second timeout under local queued requests. No full 14-case single green run or genuine production/provider acceptance claim.

Cleanup: own test services stopped; nine fixture databases and six hash-verified dummy crop images removed; original fixture DB restored unchanged. PHP environment propagation probe passed after a harness-only storage-environment flag adjustment; full journeys were not repeated after that flag change. Checklist remains unticked without implementation commit SHA.

Exact next action: review the scoped uncommitted patch and frontend docs/homepage-publication-review-2026-11-03.md plus homepage-review-verification-summary.json, complete unrelated route coverage, approve an equal-content guest visual baseline, collect representative deployed performance/provider acceptance, and verify the every-minute CMS scheduler in deployment. Decisions: reuse existing revision-pinned scheduler; no new migration or reward-policy changes.

## Sales attribution statistics follow-up — 2026-11-03

PARTIAL genuine-provider acceptance. Backend HassanDev base b92f46ab2b471fc4796310e059568bdddde276a4; frontend HassanDev base bd76ff420cda1aa180e45e44a521ff0707670b3f. Changes uncommitted alongside preserved unrelated work. Added backward-compatible experiment conversion_comparison: visitor-based absolute/relative differences, guarded two-sided z-test, 95% interval and statistical favored arm. Sparse and inconsistent observations suppress inference; causal_uplift/winner remain null pending verified measurement and an analysis plan. Existing consent/payment/refund/tenant rules preserved. No migration, deployment, provider activation, invoice issuance or financial policy change.

Executed PHP 8.4 with Xdebug off: final focused comparison/experiment/attribution/overview/funding/OpenAPI 50 PASS, 8306 assertions; docs/analytics/conversion-comparison.junit.xml. Pint/PHPStan passed. Real loopback HTTP launch/read/stop/401 captures passed (4), generated by scripts/capture-experiment-comparison.php on isolated synthetic SQLite, with temporary database/process/log cleanup; OpenAPI examples refreshed. Frontend TypeScript and targeted ESLint passed; 4 controlled Chrome analytics journeys passed after fixing their missing CSRF-cookie fixture. Existing NL/EN/DE analytics localization preserved; browser runs cover NL/EN, not DE. Initial local comparison run exposed a zero-baseline division guard and unsupported connection factory in the new test; fixed before final checks. A broader backend run was 59 PASS / 1 FAIL (8377 assertions): existing MerchantDashboardTest exact KPI-array expectation omits reserved_codes, expired_vouchers and expired_offers already returned by the working tree; unrelated code/test preserved. Initial browser runs failed session initialization before the fixture fix. Full suites and MySQL NOT RUN.

Exact next action: review/commit scoped patch, run genuine provider test-store acceptance in docs/analytics/SALES_ATTRIBUTION_FOLLOWUP_2026-11-03.md, including no-consent/grant/withdrawal, retry UUID dedupe, both holdout/variant cart-to-authenticated-order propagation, actual provider payment/refund, replay and report reconciliation. Moneybird issuance/delivery/reconciliation remains BLOCKED by OPEN-FINANCIAL-LEGAL and absent verified administration/contact configuration; internal drafts are not issued invoices. Other platform assignment mappings remain incomplete. A rollout winner requires complete propagation, equal completed follow-up and a fixed analysis plan, not merely p < .05. No commit/push; checklist remains unchecked pending implementation commit/SHA.

## Affiliate detail cover review follow-up — 2026-11-03

PARTIAL. Branch HassanDev, base b92f46ab2b471fc4796310e059568bdddde276a4; uncommitted. Report: docs/discovery/AFFILIATE_DETAIL_FOLLOWUP_2026-11-03.md. The two reported cover failures did not reproduce unchanged on PHP 8.4.25/8.5.0; no historical root cause asserted. Fixed thrown storage writes escaping the cover fallback and cleanup masking the original failure; cleanup includes the attempted partial path and remains best effort. Isolated all enrichment storage. Added pipeline false-write/write-exception/cleanup-exception coverage plus a positive raw permission snapshot guard that cannot enable cashback or create offers/rules.

Executed: PHP 8.4.25 and 8.5.0 enrichment each 12 passed/72 assertions. PHP 8.5.0 Enrichment+Affiliate+WebshopDiscoveryE2E+OpenAPI 93 total/92 passed/1 skipped/8558 assertions; subsequently added raw-snapshot guard, DaisyconCompleteness 5 passed/16 assertions. Targeted Pint and scoped whitespace PASS. Targeted PHPStan initially exhausted 128M; retry at 512M passed with zero errors. JUnit files are in docs/discovery. Existing PHP 8.5 Xdebug startup mismatch remains. Full suite/MySQL/live AI/provider/browser acceptance NOT RUN.

Next exact action: collect authorized account-specific provider payload/schema and advertiser-rights evidence for reviewed condition/exclusion/permission mapping, then implement/test that boundary and execute release-SHA acceptance. Website candidate-to-approved-media workflow, genuine sync/settlement/wallet evidence and reference fidelity/real-service NL/EN desktop/mobile journeys remain OPEN. Raw snapshots/candidates remain evidence only. New rewards retain the November 2 immediate-credit policy; frozen historical timing is unchanged. No endpoint/schema/package changes, migrations, deployment, commit or push. Preserved substantial unrelated uncommitted edits. Checklist remains unticked pending tested commit SHA and applicable acceptance proof.

## Consumer platform: owner-selected local acceptance — 2026-11-03

PARTIAL release acceptance. Backend HassanDev base b92f46ab2b471fc4796310e059568bdddde276a4; frontend HassanDev base bd76ff420cda1aa180e45e44a521ff0707670b3f. Existing uncommitted changes preserved; no commit, deployment, application-database migration, provider activation or reward-policy change. User selected local services. Reproduction and exact coverage: sibling frontend docs/consumer-platform-local-acceptance.md; harness scripts/consumer-platform-local.php and .cjs there use actual Laravel HTTP, Next and Chrome with isolated synthetic SQLite, queued notification capture and no fabricated API responses.

Executed: focused local homepage/discovery/detail/offer/registration/activation/token/points checks passed; targeted profile persistence, language/preferences and new-email correct/incorrect/reused code plus new-address login passed after fixing the harness decoder for mixed ordinary/encrypted queue jobs. Actual 250-point browser purchase produced one debit, left 50 available / 100 reserved / 1000 pending points and unchanged EUR credit. Owned test database/processes cleaned up. Serial ConsumerPlatformE2ETest + PointsCheckoutTest + OpenApiCoverageTest: 21 PASS, 8035 assertions, docs/release/consumer-platform-local-tests.junit.xml. TypeScript and scoped harness ESLint PASS. Fresh Composer audit: zero advisories/abandoned, docs/release/consumer-platform-local-composer-audit.json.

Final real loopback HTTP, 200 shops / 1001 offers / 2008 codes, SQLite/database cache, bundled OPcache enabled only for the isolated server, Xdebug off, browser page closed, 100 raw samples each: discovery cold P95 257.74 ms; repeated directory P95 106.28 ms; dashboard P95 146.01 ms. Raw samples and earlier unoptimized/resource-affected attempts are retained in sibling frontend docs/consumer-platform-local-*-results.json. These are local results, not staging/MySQL/Redis/concurrent-load or deployed-cache proof. No global PHP settings changed.

Exact next action: review the uncommitted evidence/harness and choose a release SHA; obtain full accessibility, approved visual comparisons, external mail/Answer24 and staging evidence. Fresh npm audit is blocked by automatic approval review because it sends dependency metadata externally; separate user approval is pending. Do not mark all ten release journeys or operational approval complete. Checklist remains unticked pending implementation SHA and remaining acceptance. No new financial decision or migration.
