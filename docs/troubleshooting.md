---
type: Troubleshooting
title: magento2-module-core Troubleshooting
timestamp: 2026-09-28T07:02:17Z
---

# Troubleshooting

Sync logs are in the Magento root under `var/log/yotpo/`: `orders.log`, `catalog.log`, `general.log` (`Model/Sync/Orders/Logger/Handler.php:13`, `Model/Sync/Catalog/Logger/Handler.php:13`, `Model/Logger/General/Handler.php:36`). They are written only while `yotpo/settings/debug_mode_active` is on (default 1, `etc/config.xml`). Admins can download them from the Yotpo config page (`Controller/Adminhtml/Index/DownloadLogs.php`).

## Common Issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| Nothing syncs for a store | Yotpo disabled, orders or catalog sync disabled, or a reset in progress for that store (`reset_sync_in_progress_<entity>`) | Check `yotpo_core/settings/active` and the `sync_settings/*/enable` flags; a finished `yotpo:resetsync` clears the reset flag (`Model/Config.php:723-726`, `Model/Sync/Reset/Main.php:186`) |
| An order never syncs again after one failure | Cron got a non-retryable code (not 2xx, 429 or 5xx) and set `synced_to_yotpo_order = 1` (`Model/Config.php:464-490`) | Fix the cause, then change the order again or run `bin/magento yotpo:resync --entity=order` (retry runs ignore the code, `Model/Config.php:466-468`) |
| Order skipped with "Products not exist for order" | Its products are not in Yotpo yet; the order is marked done with the missing-products code (`Model/Sync/Orders/Processor.php:225-231`) | Sync the catalog, then resync the order |
| Fulfillment missing or `fulfilled_items` empty; Yotpo 400 on a real-time sync | Shipment items not yet written when the API was called | Fixed in 4.3.9: the sync runs from the shipment commit callback (commit `48559fd`) |
| Fulfillment follows the old shipments setting after the merchant changed it | Per-order pin `is_fulfillment_based_on_shipment` | Saving the setting clears the pins for that store (`Model/Sync/Reset/Orders.php:119`, commit `704ef13`); older orders with NULL fall back to config (commit `a8b50eb`) |
| A whole cron batch of orders stalls behind one bad product; multi-hour order sync | `Orders\Data` is a singleton and the line-item product ids leaked across orders | Fixed in 4.3.9 (commit `a3fe1da`); keep the reset at `Model/Sync/Orders/Data.php:143` |
| Logs show `response code = 0` and "Reason phrase must not contain CR or LF characters"; the product 409 recovery stops | guzzlehttp/psr7 ≥ 2.12 rejects multi-line reason phrases | Fixed in 4.3.8: `Yclient::sanitizeReasonPhrase` (`Http/Yclient.php:153`, commit `a4883e8`) |
| App key / secret "invalid" on save | The token request failed for that scope | `Observer/Config/Save.php:139` requests a token on save and resets the store's credentials when it fails; check `general.log` |
| `composer require yotpo/module-yotpo:<v>` installs only core | An old core with `replace: yotpo/module-yotpo` | Fixed in 4.3.10 by `conflict` ([ADR-0001](adr/0001-conflict-not-replace-for-legacy-package.md)) |
| Composer cannot resolve a release | Core, reviews, messaging and combined pin exact versions of each other; one repo was not bumped | Bump every repo named in `README.md:68-79` |

## CI Failures

There is no CI in this repository (no `.github/workflows/`). The checks that exist outside it:

| Error Message | Meaning | Resolution |
|--------------|---------|-----------|
| `FAILED` + "Dev commit regex" from the `commit-msg` hook | Local hook from `magento-environment-artifacts/scripts/git` rejects the message | Use `type(scope):message` with a lowercase message, no space after `:`, type one of `clean enhance doc feat fix refactor style upgrade update` |
| `FAILED` + "Branch name should match" from the `pre-commit` hook | Same hook set, branch name check | Name the branch `<KEY>-<n>-desc`, `<KEY>-FIX-desc`, `hotfix-desc` or `revert-<n>-desc` |
| Adobe Marketplace MFTF "Vendor Supplied" test cannot find `Test/Mftf/Test/*.xml` | Composer installed a package that replaces the submitted one | See [ADR-0001](adr/0001-conflict-not-replace-for-legacy-package.md) |
| Adobe MFTF "Adobe Commerce Supplied" run fails every Magento test inside a Yotpo suite | A suite in this package includes a group with no tests here | Keep suites next to their tests (commit `12b0aff`) |

## Security notes

Observed while writing these docs, not changed:

- `Http/Yclient.php:100-105` logs the request `$options` at info level, including `headers` (`X-Yotpo-Token`) and, for the token request, the JSON body with the store `secret` (`Model/Api/Token.php:68-73`). Debug logging is on by default, and the logs can be downloaded from the admin.
- The admin controllers `Controller/Adminhtml/Index/DownloadLogs.php` and `Controller/Adminhtml/ResetOrdersSync/Index.php` define no `ADMIN_RESOURCE`, so Magento's default applies (any admin user) instead of `Yotpo_Core::config` (`etc/acl.xml`).
- No credentials are committed: MFTF tests read `{{_CREDS.yotpo_app_key}}` / `{{_CREDS.yotpo_secret}}` (`Test/Mftf/ActionGroup/EnableYotpoPluginActionGroup.xml:20-21`); the `"123"` secret in `Test/Mftf/Test/EnableYotpoWithIncorrectAppKeyTest.xml` is a deliberate wrong value.

## Known documentation drift

Not fixed here:

- `README.md:30` says `composer require yotpo/magento2-module-yotpo-core`; the package is `yotpo/module-yotpo-core` (`composer.json:2`).
- `README.md:39-41` (manual install) links `YotpoLtd/magento2-module-yotpo-core` and says to put "this repository" under `app/code/Yotpo/Yotpo`; it was copied from the Reviews README. This module goes to `app/code/Yotpo/Core`.
- `etc/di.xml:59` configures `Yotpo\SmsBump\Model\Sync\Orders\Logger\Handler`, a class of the Messaging module.
