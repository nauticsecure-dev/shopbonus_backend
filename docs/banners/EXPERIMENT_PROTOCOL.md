# Registered fixed-horizon experiment readouts (B70)

Backend implementation is available; actual provider measurement acceptance is **NOT RUN**. Ordinary experiments remain exploratory. Neither a configuration flag nor a synthetic PASS activates causal reporting. No publication, payment, wallet, or reward policy changes occur.

## Primary outcome and assumptions

A protocol selects one primary endpoint: `eur_captured_purchase_30d`. Its denominator is consented pseudonymous visitor assignments in the fixed enrollment interval; its numerator is assignments with at least one positive EUR native-confirmed captured purchase within 30 days of that assignment. Later partial/full refunds **do not remove a captured-purchase conversion**. This endpoint measures capture conversion, not retained revenue, profit, incremental margin, or final net-paid conversion. Cross-device identity and unobserved consent withdrawals are not inferred.

`winner_evaluation` and nullable numeric `causal_uplift` describe this endpoint. `causal_uplift` is variant minus control conversion probability (0.01 = one percentage point), not a monetary amount or a relative uplift. Existing arms, sales/revenue fields and `conversion_comparison` retain their exploratory, current net-paid definitions and can disagree with the frozen endpoint after refunds. Never combine their denominators/numerators or present the exploratory statistical favored arm as a rollout winner.

Randomized assignment, valid consent follow-up, complete order discovery, metadata persistence and absence of interference are necessary assumptions. The code checks observable evidence and rejects a revoked cohort rather than silently dropping withdrawals. An authorized reviewer must still establish the study design, sample/power rationale, eligibility and privacy handling for the actual deployment. Repeated studies, secondary metrics and cross-study selection need their own reviewed analysis; this one-endpoint implementation does not correct a portfolio of experiments.

## Before launch

Apply migration `2026_11_03_190000_create_banner_experiment_protocols.php`. It adds encrypted immutable protocol and readout snapshots plus a nullable experiment FK. Existing experiments are unchanged. Register before starting a new experiment; a past experiment cannot be upgraded retrospectively.

Run `php artisan shopbonus:banner-study fingerprint` on the deployment. Set `APP_RELEASE_SHA` to the actual complete deployed SHA (used by `qa.release_sha`). Obtain independently executed staging measurement evidence for **each non-disconnected source connection**, with current credentials/domain/native-store binding. A paused or unsupported source blocks registration. The evidence must be an available, checksum-valid, unexpired JSON artifact attached to an approved browser/Postman/manual result with passing assertions and `mocked=false`, uploaded by an active QA Tester or Super Admin. The run must have finished and started within seven days on that exact build.

Its JSON fields are:

- `purpose`: `banner_experiment_measurement`; `webshop` and `connection`: exact public IDs.
- `credential_generation`: integer; `provider_store_ref`, `verified_domain`: exact current bindings.
- `pipeline_hash`: output of the fingerprint command.
- `control_verified`, `variant_verified`, `holdout_verified`, `consent_withdrawal_verified`, `no_consent_verified`, `hidden_cart_persistence_verified`: true, supported by the reviewed execution rather than a guessed assertion.
- `control_order_id`, `variant_order_id`: different genuine native order IDs from calibration. Each must have an in-window assignment of the corresponding arm, complete normalized EUR order, positive native captured-payment evidence and token-hash provenance from successful current-credential API recovery. Source order/payment/processing times must fit the executed calibration run. Run calibration before the actual study and outside its follow-up period.

Do not manufacture these records to enable reporting. `Tests\Support\ExperimentStudyFixture` is explicitly synthetic test infrastructure, not an operational evidence generator.

Create a reviewed protocol JSON with explicit values for `control_version` (published version integer or null holdout), `variant_version`, `variant_bps` (1000–9000), `enrollment_days` (1–30), `min_visitors_per_arm` (1000–10000000), `minimum_effect_bps` (1–1000;50 means0.005 probability), `ingestion_grace_hours` (24–168), `review_reference`, and `measurement_evidence_ids` (one artifact public ID per source). Follow-up is fixed at30 days, two-sided alpha0.05, and sample-ratio alpha0.001. These bounds are guardrails, not a sample-size recommendation or automatic scientific approval.

```sh
php artisan shopbonus:banner-study register CAMPAIGN_ULID --file=/private/reviewed-protocol.json --actor=ACTIVE_SUPER_ADMIN_ULID
```

Registration requires current Super Admin authority. Launch still uses the existing authorized workspace experiment endpoint. Versions/allocation must exactly match the immutable plan and measurement must still validate. The creator's authority is rechecked. A prepared plan may be superseded before launch; its history remains. Planned observation periods cannot overlap another workspace experiment. Legacy launches are still allowed without a plan, with explicit blocked causal reporting.

New visitors stop enrolling at the enrollment cutoff; existing unrevoked assignments remain stable until their normal expiry. Do not stop the experiment before the last assignment's follow-up finishes. A stopped-early study cannot be rescued by waiting or switching its primary metric.

## One fixed readout

The planned readout time is start + enrollment days +30days + ingestion grace. Finalize in the following24hours; missing this window blocks retrospective winner selection. Obtain **fresh closing calibration artifacts after follow-up** for the same source bindings and pipeline. Complete native bulk discovery/recovery from study start through the readout cutoff first. Its discovery must be complete, with no pending/failed/incomplete source observations.

```sh
php artisan shopbonus:banner-study finalize EXPERIMENT_ULID --file=/private/closing-evidence.json --actor=ACTIVE_SUPER_ADMIN_ULID
```

The closing file contains `measurement_evidence_ids`. All mutation commands require active Super Admin authority. Exact source metadata, payment provenance, EUR currency, consent/cohort integrity and matching pipeline snapshots are checked again. A positive linked order without authenticated native evidence blocks the readout; it is not classified as a non-conversion. An allocation chi-square above10.827566170662733 (1df, alpha0.001) blocks for sample-ratio mismatch.

The existing two-proportion z-test/Wald95% interval is used only with at least ten successes and ten failures in each arm and the registered minimum sample per arm. A winner requires two-sided p<0.05 and the entire95% interval beyond the registered minimum absolute effect in that direction. Otherwise the result is inconclusive or insufficient_data with no winner. An inadequate study has null causal effect. This fixed frequentist method does not support repeated interim winner selection, adaptive allocation, multiple primary metrics or automatic rollout.

Readouts are encrypted, immutable and unique per experiment. Repeating finalize returns the same record; refunds or later arrivals do not rewrite that fixed observation. Existing GET experiment returns `winner_evaluation`, readout public ID/time and conditional `causal_uplift`. Normal exploratory metrics remain current. Sources, cohort/order digests and reviewed proof references are retained in the private snapshot. No raw assignment token or customer PII is copied into it.

## Executed evidence and limits

[Six actual local HTTP captures](../qa/B70_EXPERIMENT_PROTOCOL_HTTP_CAPTURE.json) include legacy launch/read/stop, frozen readout serialization, foreign404 and unauthenticated401. Reproduce with `python3 scripts/capture-experiment-protocol.py`: temporary private SQLite/storage/key, real Laravel TCP HTTP, cleanup, no shared database/Redis or provider effects. The frozen-result example is generated by the real validator/finalizer from **synthetic QA/native records**, not a measured real-world effect. The test suite separately exercises state/proof rejection and source reconciliation. Checkpoint results live in `docs/qa/B70_TEST_RESULTS.json`.

Actual installed cart persistence, consent behavior, real provider purchases/refunds and independent measurement review remain NOT RUN. A code deployment or fixture result cannot satisfy these prerequisites. Unsupported native bridges remain unknown; see [assignment mappings and limits](ASSIGNMENT_BRIDGES.md).

Primary statistical references: [Microsoft sample-ratio mismatch guidance](https://www.microsoft.com/en-us/research/articles/diagnosing-sample-ratio-mismatch-in-a-b-testing/) and [NIST chi-square critical values](https://www.itl.nist.gov/div898/handbook/eda/section3/eda3674.htm). These explain the quality gate; they do not certify a particular study.
