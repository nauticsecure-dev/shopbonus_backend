# Website image import and review (B75)

Website research URLs remain candidates only. `shopbonus:website-asset` connects an existing run/evidence key to a private import, visual/rights review and the existing workspace-scoped banner asset library. It never automatically replaces a logo, publishes a profile/campaign, or assumes that a publicly accessible image is licensed for reuse.

Only a **current active Super Admin** may run import/show/approve/reject. Trusted shell access is also privileged. The source must be a recorded image/logo candidate on the exact currently verified merchant domain, with intact immutable research and a run no older than 30 days. The command accepts no arbitrary download URL.

```sh
php artisan shopbonus:website-asset import RUN_ULID --evidence=E4 --actor=ADMIN_ULID
php artisan shopbonus:website-asset show IMPORT_ULID --actor=ADMIN_ULID --preview-file=/absolute/new-private-preview.webp
php artisan shopbonus:website-asset approve IMPORT_ULID --actor=ADMIN_ULID --note='Reviewed merchant identity and image content' --rights='Reference to actual merchant licence or permission'
php artisan shopbonus:website-asset reject IMPORT_ULID --actor=ADMIN_ULID --note='No verified usage rights'
```

`show` prints source/hash/dimensions/state and, after approval, the reusable asset public ID. Its optional preview export creates a new local file with mode0600 and refuses to overwrite an existing file. The reviewer must inspect that normalized preview and supply an actual usage-rights reference; download alone is not approval.

Downloads reuse `SafeUrlFetcher` public DNS validation and connection pinning, HTTPS/TLS, no credentials/query/fragment/nonstandard ports, no redirects/proxy/retries and 3/8-second timeouts. All resolved addresses must be public. Image responses must declare JPEG/PNG/WebP and fit a strict 2MiB read bound, including chunked/unknown-length bodies. Actual image bytes must decode as JPEG/PNG/WebP, dimensions16–4000 and at most12million pixels. The server re-encodes to bounded WebP, stripping source metadata and appended content; SVG/GIF/HTML are not imported. The HTML research fetch retains its existing text-only contract.

Normalized bytes live on the existing private local disk. Pending/rejected records have no `BannerAsset`, so they cannot be referenced through the existing asset API or banner configuration. Approval rechecks active admin, current domain/ownership/research and stored content checksum, records the explicit rights decision and creates one same-workspace `BannerAsset`. The existing public asset route then serves those approved bytes with stored image MIME, nosniff/CSP and immutable caching. Existing campaign validation prevents cross-workspace reuse. No automatic campaign/profile publication occurs.

One immutable import exists per run/evidence key. Duplicate imports and repeated final decisions reuse it; contrary decisions conflict. Rejected bytes remain private with review provenance (not silently deleted or made public); use existing operational storage retention procedures rather than removing evidence behind an approved asset. Changed source/rights requires new research/review. Failed persistence or in-flight permission revocation cleans attempted private files. Missing/tampered private content blocks approval.

Migration: `2026_11_04_090000_create_merchant_asset_imports`. No new API route or frontend work. Run the normal migration workflow before using the operator command. This table does not replace existing uploads or AI covers.

Evidence: `WebsiteAssetImportsTest`; `docs/qa/B75_TEST_RESULTS.json`; two actual private Laravel TCP captures in `docs/qa/B75_ASSET_HTTP_CAPTURE.json`, reproduced with `python3 scripts/capture-website-asset.py`. The latter uses a temporary SQLite/storage/key and an isolated router that explicitly passes its storage path into the actual public Laravel entry point. Candidate image/DNS/download transport are synthetic; actual decode/re-encode/storage/review and asset HTTP serving execute. Binary captures record actual bytes as base64, length and SHA256; they are not invented JSON response bodies. Real merchant imagery, ownership/licence review and deployment/storage acceptance remain **NOT RUN**.
