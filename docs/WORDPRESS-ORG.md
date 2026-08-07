# Publishing to WordPress.org

Serviceware is allowed: the plugin is free GPL; merchants need a VEXPay account (guideline 6). No trial locks. Integration testing uses the hosted VEXPay API and dashboard keys — not a local API checkout.

## Pre-submit

1. Run `composer test` and Plugin Check ([wordpress.org/plugins/plugin-check](https://wordpress.org/plugins/plugin-check/)).
2. Smoke-test with a Test API key against the fixed hosted API ([E2E checklist](E2E-CHECKLIST.md)).
3. Confirm sanitize / escape / nonces on all forms (OTP uses `wp_nonce_field`).
4. Validate `readme.txt` in the [readme validator](https://wordpress.org/plugins/developers/readme-validator/).
5. Prepare `.wordpress-org/` assets (banner 1544×500, icon 256×256, screenshots) — not shipped in the runtime zip.

## Submit

1. Create a wordpress.org account.
2. Plugin slug: `vexpay-gateway-for-woocommerce` (Text Domain and zip root folder must match).
3. Upload a zip whose root folder is `vexpay-gateway-for-woocommerce/` for review.
4. Address review feedback (typically escaping, sanitizing, nonces).

## After approval (SVN)

```bash
svn co https://plugins.svn.wordpress.org/vexpay-gateway-for-woocommerce svn-wp
# Copy trunk from a clean build (respect .distignore)
rsync -a --delete --exclude-from=.distignore ./ svn-wp/trunk/
cd svn-wp
svn add --force trunk/*
svn ci -m "Initial trunk 1.0.0"
# Tag release
svn cp trunk tags/1.0.0
svn ci -m "Tag 1.0.0"
```

GitHub remains source of truth. Tag `v1.0.0` to build a GitHub Release zip via `.github/workflows/release.yml`, then sync that tree to SVN `tags/1.0.0`.

## Do not

- Ship API keys or secrets in the zip
- Gate features behind a license / trial
- Put `.wordpress-org` assets inside the plugin PHP tree used at runtime
