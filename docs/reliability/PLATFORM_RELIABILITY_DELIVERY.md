# Platform reliability delivery — inventory, gap closure, evidence

Date: 2026-10-30. Scope: inspect existing B22 stack, close the remaining **direct-merchant reconciliation** gap, tighten Super Admin ops overview, and map the ten acceptance scenarios to executable tests. This does **not** claim production offsite backup, live provider settlement, or adopted RPO/RTO.

## Infrastructure inventory (reuse, do not duplicate)

| Area | Existing surface | Status |
| --- | --- | --- |
| Wallet / ledger | `WalletService` (sole writer), append-only `wallet_ledger_entries`, lots/reservations/pending | Complete |
| Affiliate credits | Daisycon/TradeTracker adapters → `AffiliateProcessor`; unique `(network, external_id)` | Logic complete; adapters not live-verified |
| Direct merchant | `MerchantCommissionAgreement` + `DirectMerchantCashback` + `MerchantCommissionConversion` | Complete; now first-class in reconciliation |
| Reconciliation APIs | `GET/POST /api/v1/admin/reconciliation`, `GET …/{run}`, `POST …/resume` | Complete (no `platform/finance` alias — reuse admin) |
| Corrections | Dual-admin wallet adjustments; no silent balancing entries | Complete |
| API monitoring | Custom observations + `GET /api/v1/admin/monitoring` | Opt-in (`API_MONITORING_ENABLED`) |
| Pulse / Horizon / Telescope | **Not installed** — intentional; custom stack + Horizon-compatible queues via Redis/database | Do not add duplicates |
| Audit | Append-only `audit_events` + hash chain; `AuditLogger`; 401/403 middleware | Complete for critical financial actions |
| Ops overview | `GET /api/v1/admin/operations-overview` | Extended: `security`, `acceptance_metrics`, audit/cockpit links |
| Backups / DR | `shopbonus:backup`, `restore-isolated`, `verify-latest-backup`; sodium secretstream | Implemented; **disabled by default** |
| Policies | Middleware `platform:super_admin` + `workspace.can:*` (no Laravel Policy classes) | Reuse as-is |
| API docs | `docs/api/openapi.yaml` + `/docs` when enabled | Existing routes documented |
| Health | `/up`, `GET /api/v1/health`, `GET /api/v1/health/ready` | Complete |

Proposed contracts in the card (`/api/v1/platform/finance/reconciliation`) map to the existing **admin** reconciliation APIs. Do not add a second route tree.

## Gap closed in this pass

1. **`FinancialSources`** now includes `direct_merchant` → `MerchantCommissionConversion`.
2. Invariants: split totals 100%, historical agreement snapshot math, credit identity/owner/unit, duplicate credit, exception-without-credit, reversal compensation.
3. Ledger inspector treats `direct_merchant` as a typed source (orphan check); no longer UNKNOWN `untyped_credit_source` for valid credits.
4. **`DirectMerchantCashback`** credits with `source_ref = conversion.public_id` (affiliate-compatible). Legacy `external_id` refs still reconcile.
5. New runs use **`scope_version` 3**. Older runs omit unknown source keys mid-flight (never invent watermarks).
6. Index/unique on `merchant_commission_conversions.wallet_entry_id` + status/converted index.
7. Operations overview: `security` + `acceptance_metrics`; links to monitoring, incidents, reconciliation, activity, cockpit. Missing data stays **UNKNOWN**, never HEALTHY.

## Findings (scoped)

| ID | Severity | Component | Fix / evidence |
| --- | --- | --- | --- |
| REL-DM-001 | High (control gap) | Reconciliation omitted direct-merchant conversions | Added source inspector + ledger typing; regression in `PlatformReliabilityE2ETest` |
| Prior B22-F001–F005 | See `FINANCIAL_FINDINGS.md` | Affiliate/wallet | Already fixed with regressions |

No assumption of production compromise. Synthetic fixtures only.

## Security testing

Reuse existing suites — do not rebuild auth:

- `tests/Feature/Admin/AdminSecurityTest.php` — MFA/step-up/support
- `tests/Feature/Audit/*` — immutability, denials, integrity
- `tests/Feature/Workspaces/*` — tenant isolation
- `tests/Feature/Reliability/PlatformReliabilityE2ETest::test_08_*` — wallet isolation
- Enrichment SSRF: `SafeUrlFetcher` + enrichment E2E
- Voucher secrecy: masked lists + audited reveal

Critical findings require evidence + regression (existing B22-F* pattern).

## Backup & DR

| Item | Value |
| --- | --- |
| Enable | `BACKUPS_ENABLED=true` + `BACKUP_ENCRYPTION_KEY` (independent 32-byte base64) |
| Schedule | Hourly backup; weekly `verify-latest-backup` when enabled |
| Proposed targets (unapproved) | Financial RPO ≤15m needs PITR/binlog; logical hourly dump does not meet that. RTO ≤4h needs drill on target host |
| Proof | `docs/reliability/B22_MYSQL_RECOVERY_PROOF.json` + `tests/Feature/Reliability/RecoveryTest.php` |
| Secrets | Never in archives; recover via credential escrow |

## Acceptance metrics (ops overview)

Exposed under `components.acceptance_metrics`:

- Ledger discrepancy count (latest run)
- Reconciliation elapsed including scheduling
- Critical API monitoring state
- Failed queue jobs
- Open critical security findings
- Backup status / 30d success % / last verified restore

Thresholds remain in `config/reliability.php` and `config/recovery.php`.

## Ten E2E mappings

| # | Scenario | Primary evidence |
| --- | --- | --- |
| 1 | €10 / 70-30 | `PlatformReliabilityE2ETest::test_01_*`, `MyBenefitsE2ETest::test_02_*` |
| 2 | Duplicate conversion | `test_02_*`, `MyBenefitsE2ETest::test_05_*` |
| 3 | Commission change | `test_03_*` (direct merchant freeze) + Affiliate frozen-rule tests |
| 4 | Ledger mismatch | `test_04_*`, `FinancialReconciliationTest` |
| 5 | API monitoring | `ApiMonitoringTest`, ops overview UNKNOWN when disabled |
| 6 | Audit trail | `test_06_*`, existing Audit suites |
| 7 | DB integrity / coverage | `test_07_*` (direct_merchant OBSERVED), concurrent wallet tests |
| 8 | Security isolation | `test_08_*`, AdminSecurity / workspace suites |
| 9 | Encrypted restore | `RecoveryTest`, MySQL proof JSON |
| 10 | Disaster / resume | `test_10_*`, failed-run resume in `FinancialReconciliationTest` |

## Senior rollout (unchanged)

1. Deploy recorded SHA; apply migrations including `2026_10_30_160000_index_direct_merchant_wallet_entry`.
2. Enable API monitoring / backups only after storage and alert-delivery review.
3. Keep affiliate timing policy as configured; no fictional revenue posting.
4. Escrow APP_KEY and backup key separately; configure private offsite disk + isolated restore DB.
5. Adopt RPO/RTO only with infrastructure cost evidence.

## Commands

```bash
php artisan shopbonus:reconcile-financials --key=operator:YYYY-MM-DD --limit=100
php artisan shopbonus:monitor-api
php artisan shopbonus:observe-operations
php artisan shopbonus:backup
php artisan shopbonus:restore-isolated <ARCHIVE_ULID>
php artisan shopbonus:audit-verify
php artisan test --filter=PlatformReliabilityE2ETest
```
