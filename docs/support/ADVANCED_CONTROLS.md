# Support controls and proof boundaries — B63/B64

Laravel backend only. The evidence-ID selection and independent re-read/template renderer (D-076) remain. No arbitrary model prose, mutation tools, financial promises or model-supplied actions are accepted. No frontend labels or localization were changed.

## Effective controls

| Setting | Accepted values | Backend behavior |
| --- | --- | --- |
| tone | friendly_professional / direct / warm | Server-owned introduction; no model prompt interpolation |
| empathy | minimal / balanced / high | Distinct localized server wording in NL/EN |
| answer_detail | concise / detailed | Compact eligible wallet presentation or additional checked-source context |
| target_answer_words | integer 20–200 | Soft preference, clamped to hard cap; concise wallet targets below45 select the compact variant. No padding or dropping facts to hit a count |
| max_answer_words | integer 25–300 | Overflow requests human review and replaces the entire answer with a bounded escalation notice, never truncating facts or safeguards |
| response_deadline_ms | integer 1000–20000 | Execution-start deadline, remaining-time HTTP timeout and late-result rejection; not queue-wait/staging P95 evidence or an infrastructure SLA |
| timeout_seconds | integer 5–20 | Per-attempt ceiling, additionally limited by deployment config and remaining execution deadline |
| daily_cost_limit_eur | 0–1000, up to 6 decimals | Lower policy/deployment cap, shared UTC-day conservative exposure reserve; zero blocks provider requests |
| fallback_model | null or bounded exact model ID | At most one deployment-configured, independently probed fallback for transient provider failures |

Hard word overflow records answer_word_limit, clears actions/evidence selection from the displayed result, marks policy checks failed and creates an automatic review. Underlying authorized snapshots remain encrypted for review. The actual SupportChat path is tested, not just validation. Existing completed answers remain immutable.

The budget reserves full configured context exposure plus requested output exposure **before every attempt**, including canaries and fallbacks. Failed/timed-out attempts are not refunded, as usage may still be billable. This is a feature execution cap, not an OpenAI account-wide billing cap. Reporting shows retained reserved_micro_eur separately from observed usage/cost estimates. Failed-attempt usage may be unknown; missing observed cost is not zero and estimates are not invoices.

Safety invariants remain fixed: required strict tool choice, no parallel tools, store=false, no streaming, human escalation and bounded research rounds. Optional reasoning/verbosity parameters remain omitted until separately verified; do not show those fixed placeholders as functioning model controls. Default-language/progress-delay fields are not newly configurable in this boundary. The API operations settings_capabilities map describes the supported surface.

## Deployment model setup

Senior/operator configures the existing primary model/key/profile and optional SUPPORT_AI_FALLBACK_MODEL plus its FALLBACK_CONTEXT_TOKENS, FALLBACK_OUTPUT_TOKENS, FALLBACK_INPUT_MICRO_EUR_PER_TOKEN, FALLBACK_OUTPUT_MICRO_EUR_PER_TOKEN and FALLBACK_PRICING_REFERENCE. All fallback defaults are empty/zero, disabling it. Rates/limits/references must be independently verified; no model ID or price is guessed by this implementation. Both models use the same deployment-held API key.

Run explicit budgeted synthetic canaries on the intended deployment:

```bash
php artisan shopbonus:verify-support-model
php artisan shopbonus:verify-support-model --model='<EXACT_CONFIGURED_FALLBACK_MODEL_ID>'
```

Each proof binds the exact model, API-key fingerprint, context/output/rate profile and strict schema contract; it expires at the configured TTL. Unknown model IDs cannot trigger arbitrary model egress. Rotation/profile changes invalidate prior proof. The canary requests a strict function call and validates the returned evidence-ID contract, not conversational quality or pricing truth. Reviewed [official OpenAI function-calling documentation](https://developers.openai.com/api/docs/guides/function-calling): the strict schema retains additionalProperties=false and required fields. This is not a reason to enable unverified optional model parameters.

Drafts may retain an unverified model choice for configuration. Enabled publication requires fresh proof for **both** selected models and an output limit within both verified profiles; otherwise409 support_model_contract_required / support_model_output_limit. Identical primary/fallback choices fail409 support_fallback_invalid. Existing policy regression, independent administrator, password/OTP and immutable version requirements remain. Disabled configurations can be published without provider credentials. Runtime checks still fail closed if proof expires after publication.

Fallback is attempted once only for transient transport/408/5xx/429 failures. Authentication, other rejected requests, invalid output, budget exhaustion and deadline expiry do not trigger fallback. Both attempts share the request deadline and daily monetary exposure. All fallback output is independently reauthorized/re-read/rendered. Metrics record the actual successful model, fallback flag/attempt count and primary failure; usage from the failed attempt remains unknown rather than invented.

## Monitoring and client handoff

All matching messages are traversed in 250-row batches up to an observed ID watermark. This is a full filtered observation, not the latest500. Counts, tokens, cost coverage and nearest-rank observed-latency P95 include older records; missing metric coverage is explicit. Concurrent updates are observations, not a transactional historical snapshot.

Use execution_status_percentages for message states and reviewed_outcome_percentages for resolved human reviews. The latter denominator is resolved review records; several triggers may exist for one message, so distinct reviewed/unreviewed message coverage is also returned. An outcome filter applies to both selected messages and counted review records. These reviews are not a representative provider/model evaluation. Legacy outcome_percentages remains an explicitly deprecated execution-status alias for existing clients; do not label it answer quality. sample_limit=null and sample_size equals the full observed count. open_reviews/approved_regressions remain global counters; matching_open_reviews is filtered.

The legacy provider_cost_estimate uses one configured blended rate pair, not per-model billing. provider_cost sums available per-message estimates with coverage. Failed/timeout/fallback-attempt usage can be missing; retained daily_reserved_micro_eur is conservative budget exposure, not billed spend.

Reverb live probe remains not_measured; broadcasts remain dispatch_attempt_only. Historical provider/worker timestamps and an unexpired model canary do not establish current end-to-end availability. No automatic paid probe runs on GET. Real deployed provider/queue/Reverb subscriber delivery and the representative ≥95% quality/experience target remain NOT RUN here. Frontend controls must consume backend capabilities and activation errors; frontend NL/EN labels and template-experience acceptance remain separate.

## Executed evidence

B63: SQLite7/112, MySQL7/112, API5/8349, PHPStan/Pint passed; four actual HTTP responses over501 synthetic messages in [B63 capture](../qa/B63_SUPPORT_OPERATIONS_HTTP_CAPTURE.json).

B64: SQLite180 passed +3 skipped /183 total /1470 assertions; selected MySQL154 passed /1324 assertions; API5 passed /8349 assertions; PHPStan/Pint/diff passed. [Captured results](../qa/B64_SUPPORT_TEST_RESULTS.json). Fixtures test bounded rendered answers, reviews, the live publication path, canary/fallback contracts, failed-attempt exposure, deadline/invalid-output/auth guards and filtered/multiple human outcomes. These are local/synthetic tests, not real provider or quality acceptance.

[Eleven B64 actual HTTP exchanges](../qa/B64_SUPPORT_CONTROLS_HTTP_CAPTURE.json): isolated local Laravel/SQLite/file sessions, two synthetic admins with password confirmation and OTP delivery to a localhost SMTP sink. Self-publish403, unverified-model publication409, and independently approved **disabled** bounded controls200. The consumer result is a seeded server-presentation-only escalation fixture with a corresponding review, not provider/evidence acceptance. MFA disabled for capture only; all private fixtures, cookies, OTP mailbox, keys and server removed. No external mail/provider request, mainDB/sharedRedis mutation, new migration or deployment.
