# Local integration setup (B21)

The owner approved opt-in sample fixtures on 2026-10-27 in response to `BACKEND_API_ISSUES.md`. Run only on a local development database. This command is never called by default seeding, migrations or deployments.

```bash
php artisan migrate
php artisan shopbonus:setup-local-integration
php artisan serve
```

Requirements: `APP_ENV=local` (or testing), `MAIL_MAILER=log` or `array`, `MOLLIE_ALLOW_LIVE=false`, `ANSWER24_DISPATCH_ENABLED=false`. The command refuses production and outbound email. It does not configure or call any external provider, enqueue verification mail, top up wallets, create paid orders, or change affiliate credit timing. The default DatabaseSeeder intentionally creates nothing.

Read credentials privately from `storage/app/private/local-integration/credentials.json` (directory0700/file0600). The command prints the file path, never passwords. This ignored storage file contains generated random passwords for verified consumer, merchant and two Super Admin accounts, workspace public ID and promotional offer slug. Do not share or commit it. The Super Admin API still requires normal MFA enrollment and password/step-up confirmations; fixtures do not weaken these guards. Local CLI grants and synthetic manual-review approval are audited.

Run the command again to validate the existing manifest and preserve accounts, passwords, content and stock. It does not replenish claimed stock, undo edits or repair/reset existing identities. Conflicting fixture emails/content without the matching manifest cause rollback. A manifest for another database or changed passwords is rejected. Use a separate database and storage path for an independent fixture installation. There is no destructive reset option.

Fixtures include labelled LOCAL SAMPLE NL/EN Terms, Privacy and information pages, a FAQ, approved synthetic merchant/public profile, active promotional offer and ten unique codes. Existing Terms/Privacy pages of any status are preserved; existing published merchant agreement templates are reused. Sample legal pages are only created when that document type has no existing page. All synthetic legal titles, body text, version (`LOCAL-SAMPLE-1`) and agreement state clearly identify sample content. They are not approved production policies. If existing unpublished legal drafts are present, publish owner-approved text through existing CMS APIs; the command deliberately leaves registration unavailable rather than replacing those drafts.

Client integration:

1. Browse `/api/v1/public/legal-requirements?locale=nl` or `en`. Show only matching document links and echo both required versions when registering. Signup needs `account_type` (`consumer`/`merchant`), `first_name`, `email`, `password`, `password_confirmation`, `accept_terms=true`, `terms_version`, `privacy_version`, and optional locale/marketing consent. Missing required versions still produce503; stale versions produce422.
2. Use Sanctum's normal CSRF/session login flow and a generated account. Seeded users are verified so API work can proceed without a mail provider. New registrations still use the real verification flow. Log/array mail does not deliver inbox messages; use the local log privately or configure a local mail catcher separately. Never expose OTP/reset tokens in shared logs/screenshots.
3. Browse public webshops/offers/FAQ/pages. The sample profile slug is `local-sample-shop`; the offer slug is in the private manifest. An empty preexisting database legitimately returns empty lists until content is published.
4. As the verified consumer, `POST /api/v1/consumer/offers/{slug}/claim` issues one promotional voucher. Repeating the request returns the same reference. Read the owned voucher endpoint to reveal its code; issuance does not prove redemption. Sample codes are synthetic and will not redeem at a real merchant.
5. Merchant funding clients should persist a UUID `Idempotency-Key` before the first top-up POST and reuse it on all retries with the same amount. Creating/uncertain intents need review, not a fresh automatic request/key. Fixture setup does not enable payments. The code handles unconfigured Mollie with409; actual provider sandbox acceptance remains senior-owned.

The senior developer configures real mail transport, approved published legal text/agreement, provider keys/contracts/webhooks, model/budget and deployed queue/scheduler/TLS. Do not send credentials through chat. Live payment, inbox delivery, platform integration and staging checks remain unverified. CSV import is supported; XLSX415 is the documented contract and was not changed by this report.

Evidence: [B21_HTTP_CAPTURE.json](B21_HTTP_CAPTURE.json) contains real local server responses using an isolated SQLite database/file sessions/array mail and a synthetic payment upstream. Browser signup/login, catalog/FAQ/legal lookup, claim+replay, funding+replay/conflict/invalid key/uncertain replay were actually called. Focused automated tests additionally cover missing/config fallback legal metadata, stale translated versions, authorization, hidden catalog, wallet purchase refusal, stock exhaustion, safe fixture reruns and real MySQL process races. Real provider success is not inferred from synthetic responses.

Migration: `2026_10_27_100000_add_funding_request_identity.php` adds funding request/provider UUIDs and creation status; legacy rows without a provider reference become uncertain. Rollback removes request identity/history. Quiesce funding writes and preserve those records before any production rollback; rollback is not payment recovery. InnoDB rollback restores the original foreign-key supporting index before dropping the new composite index.
