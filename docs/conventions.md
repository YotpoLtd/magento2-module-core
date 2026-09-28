---
type: Conventions
title: magento2-module-core Conventions
timestamp: 2026-09-28T07:02:17Z
---

# Coding Conventions

## File Organization
- Standard Magento 2 module layout at the repository root; PSR-4 root `Yotpo\Core\` → `./` (`composer.json:21-29`). The namespace follows the directory.
- One processor per synced entity under `Model/Sync/<Entity>/`, with a thin cron class in `Model/Sync/<Entity>/Cron/` that only calls `process()`. Canonical example: `Model/Sync/Orders/Cron/OrdersSync.php` → `Model/Sync/Orders/Processor.php`.
- Observers live in `Observer/<Area>/` and are registered in `etc/events.xml` (storefront + admin) or `etc/adminhtml/events.xml` (admin only).
- Wiring is in XML, not code: DI preferences, logger handlers and CLI commands in `etc/di.xml`; cron in `etc/crontab.xml` and `etc/cron_groups.xml`; tables in `etc/db_schema.xml`.
- Unit tests mirror the class path under `Test/Unit/` (`Test/Unit/Model/Sync/Orders/DataTest.php` covers `Model/Sync/Orders/Data.php`).

## Naming
| Thing | Convention | Example |
|-------|-----------|---------|
| Properties / variables | camelCase | `$yotpoOrdersLogger` (`Model/Sync/Orders/Processor.php`) |
| Methods | camelCase, verb first | `prepareFulfillments`, `isRealTimeOrdersSyncActive` |
| Classes | PascalCase; `Main` for a shared base in a folder | `Model/Sync/Orders/Main.php`, `Model/Sync/Catalog/Processor/Main.php` |
| Constants | UPPER_SNAKE_CASE | `SYNCED_TO_YOTPO_ORDER` (`Observer/Order/OrderMain.php:15`) |
| Config keys | snake_case key in `Model/Config::$config` | `orders_realtime_sync_active` (`Model/Config.php:93`) |
| Tables / columns | `yotpo_<entity>_sync`, snake_case | `yotpo_orders_sync.response_code` (`etc/db_schema.xml`) |
| Cron jobs | `yotpo_cron_core_<entity>_sync` in group `yotpo_core_<area>_sync` | `etc/crontab.xml` |

## Error Handling
- Pattern: a processor catches `\Exception` per entity, logs it with the entity id and store, marks the entity so the batch goes on, and continues (`Model/Sync/Orders/Processor.php:293-303`). A per-store `try` keeps one store's failure from stopping the others (`Model/Sync/Catalog/Processor.php:218-230`).
- API failures are not exceptions: `Yclient` turns them into a response object with the real `status` (`Http/Yclient.php:118-140`); callers branch on `is_success` and the code through `Config::canResync` / `canUpdateCustomAttribute` (`Model/Config.php:464-510`).
- Deferred work (commit callbacks) catches `\Throwable` and logs, so nothing escapes into Magento's commit machinery (`Observer/Order/SalesOrderShipmentSaveAfter.php:84-93`).
- Never: throw from an observer on a sync problem. That fails the merchant's save of an order, product or shipment.

## Logging
- What to log: store id and name, entity id, API URL and response code. Use the logger for the entity: `Sync\Orders\Logger`, `Sync\Catalog\Logger`, `Logger\General` (`etc/di.xml`).
- Levels: `infoLog` for flow and failures that the sync handles (written only when `debug_mode_active` is on, `Model/Logger/Main.php:49-57`); `errorLog` for failures nobody else will see, always written (`:66-71`).
- Format: free text with `__('... %1 ...', $arg)` placeholders and the `Yotpo :: ` prefix added by the logger.
- Canonical example: `Model/Sync/Orders/Processor.php:176-183`.

## Testing
- Unit tests: PHPUnit `TestCase` under `Test/Unit/`, class `<Class>Test`, methods `test<Behaviour>` with a `: void` return type. They run from a Magento root with `dev/tests/unit/phpunit.xml.dist`, which already collects `vendor/*/module-*/Test/Unit` (commit `a3fe1da`).
- Mocking strategy: `getMockBuilder(<Class>::class)->disableOriginalConstructor()->onlyMethods([...])` on the class under test, keeping the method being tested real. Collaborators are injected into protected properties with `new \ReflectionProperty(...)->setValue(...)` (`Test/Unit/Model/Sync/Orders/DataTest.php:30-59,129-132`). Without `setAccessible`, that needs PHP 8.1 or later.
- Functional tests: MFTF under `Test/Mftf/` (group `Yotpo`, suite `Test/Mftf/Suite/yotpoSuite.xml`). They need a full Magento install, a browser and `_CREDS` values; Adobe runs them on each Marketplace submission (commit `12b0aff`).
- Canonical example: `Test/Unit/Model/Sync/Orders/ProcessorTest.php`.

## Do's and Don'ts
| Do (with file:line ref) | Don't | Why |
|--------------------------|-------|-----|
| Read config with `$this->config->getConfig('<key>')` (`Model/Config.php:249`) | Call `ScopeConfigInterface` with a hard-coded path | Encryption, `read_from_db` and scope handling live in `Config` |
| Call Yotpo through `Api\Sync::sync($method, $endpoint, $data + ['entityLog' => 'orders'])` (`Model/Api/Sync.php:26`) | Create a Guzzle client in a processor | Token, headers, invalid-token retry and logging are in `Request` / `Yclient` |
| Re-queue in the observer and sync after commit (`Observer/Order/SalesOrderShipmentSaveAfter.php:71-93`) | Call the Yotpo API from `*_save_after` while the transaction is open | Child rows are not written yet; Yotpo got empty `fulfilled_items` and returned a permanent 400 (commit `48559fd`) |
| Reset per-order state at the start of `prepareData` (`Model/Sync/Orders/Data.php:143`) | Append to a property of a DI singleton across orders | `Orders\Data` is shared by the whole batch; one bad product blocked every later order (commit `a3fe1da`) |
| Check `canResync` before a real-time path writes a response code (`Model/Sync/Orders/Processor.php:393`) | Persist a non-retryable code from the real-time path | It stops cron from retrying with complete data ([ADR-0002](adr/0002-cron-is-authoritative-for-order-sync.md)) |
| Wrap per-store work in `emulateFrontendArea` / `stopEnvironmentEmulation` (`Model/AbstractJobs.php:64-81`) | Read store config outside emulation in cron | Cron runs in the global scope; config and URLs resolve to the wrong store |
| Add schema through `etc/db_schema.xml` plus `etc/db_schema_whitelist.json`, data through a new `Setup/Patch/Data` class | Edit an applied data patch, or use `InstallSchema` / `UpgradeData` scripts | Applied patches never run again; whitelist-less column drops are refused |
| Keep code valid on PHP 7.1+ syntax unless the supported range changes (`composer.json:9`) | Use syntax only newer PHP accepts in production code | Merchants on older Magento/PHP get a fatal error (commits `052f291`, `57eadee`) |

## Anti-Patterns

What agents should NEVER do in this repo:
| Anti-Pattern | Why It's Dangerous | See Also |
|--------------|-------------------|----------|
| Change `composer.json` `name`, `replace` or `conflict` without checking what Composer then installs | `replace: yotpo/module-yotpo` made Composer skip the Marketplace package and broke the Adobe MFTF run | [ADR-0001](adr/0001-conflict-not-replace-for-legacy-package.md), commit `3373ed8` |
| Bump the version in only one place | `composer.json:4` and `etc/module.xml:3` must match, and Reviews / Messaging / combined pin the exact core version | `README.md:68-87`, commit `259d8a7` |
| Change the signature of a public class the sibling modules extend or call (`AbstractJobs`, `Config`, `Api\Request`, `Api\Sync`, `Logger\Main`, `Sync\Data\Main`, `Sync\Catalog\Processor`) | Reviews or Messaging fail `setup:di:compile` on the merchant's store | [ARCHITECTURE.md Boundaries](../ARCHITECTURE.md#boundaries) |
| Build the PSR-7 reason phrase from a raw exception message | guzzlehttp/psr7 ≥ 2.12 throws on CR/LF; the real status was lost and the 409 recovery stopped | `Http/Yclient.php:144-156`, commit `a4883e8` |
| Log the full request options, or add new credential fields to them | `Yclient` already logs `$options` (headers with the token; the token request body with the secret) into `var/log/yotpo/*.log`, which admins can download | [troubleshooting](troubleshooting.md#security-notes) |
| An MFTF suite whose `<include>` matches no tests in this package | MFTF then runs the whole Magento suite inside it | commit `12b0aff` |
| Hard-coded batch sizes or cron schedules | They are merchant settings with defaults in `etc/config.xml` | `Model/Config.php:93` |

## Patterns Library

Common patterns. Reference these BEFORE inventing your own:

### Queue an entity for sync
- **When to use:** a Magento change must reach Yotpo.
- **Canonical implementation:** `Observer/Order/OrderMain.php:67-104`, `Observer/Product/SaveAfter.php:155-170`
- **How it works:** set the entity's `synced_to_yotpo_*` flag to 0 (and the sync row's `response_code` to `000`); the next cron run picks it up.

### Real-time sync after commit
- **When to use:** an observer on `*_save_after` wants to sync immediately.
- **Canonical implementation:** `Observer/Order/SalesOrderShipmentSaveAfter.php:60-95`
- **How it works:** re-queue synchronously, check the real-time setting, de-duplicate per request, then `addCommitCallback` the sync inside a `try/catch (\Throwable)`.

### Record an API result
- **When to use:** after every sync call.
- **Canonical implementation:** `Model/Sync/Orders/Processor.php:278-292`
- **How it works:** build the table row from the response (`prepareYotpoTableData`), keep the old `yotpo_id` when the response has none, `insertOnDuplicate`, and set the synced flag only when `canUpdateCustomAttribute` allows.

### Unit test a method of a DI-heavy class
- **When to use:** any new logic in `Model/` or `Observer/`.
- **Canonical implementation:** `Test/Unit/Model/Sync/Orders/DataTest.php:30-59`
- **How it works:** partial mock with `disableOriginalConstructor()` and `onlyMethods()`, collaborators set through `ReflectionProperty`.

# Citations

[1] Commit a3fe1da "fix(orders):scope line item product ids to the order being prepared", 2026-08-27 — the singleton `Orders\Data` bug and the first unit tests
[2] Commit 48559fd "fix(orders):defer real-time shipment sync until after commit", 2026-08-28
[3] Commit 82e6ebd "fix(orders):don't let a real-time sync failure disable the cron fallback", 2026-08-28
[4] Commit a4883e8 "fix(http): preserve real API status when Guzzle error message contains CR/LF", 2026-08-25
[5] Commit 3373ed8 "Stop replacing yotpo/module-yotpo; conflict with the legacy versions instead", 2026-09-15
[6] Commit 12b0aff "Move yotpoDisableSuite to the reviews module", 2026-09-15
[7] Commits 052f291, 4b2806f, bafe5ed (ORB-4210, 2025-08-22) and 57eadee (ORB-3609) — PHP compatibility fixes
[8] README.md "Publish new version" (`README.md:68-96`)
