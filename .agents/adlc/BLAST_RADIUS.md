# Blast Radius profile

## entryPoints

This module runs inside a merchant's Magento store; everything outside code calls is wired in `etc/*.xml`:

- Cron jobs: `etc/crontab.xml` -- `Model/Sync/Orders/Cron/OrdersSync.php`, `Model/Sync/Catalog/Cron/CatalogSync.php`, `Model/Sync/Catalog/Cron/CategorySync.php`, `Model/Sync/CollectionsProducts/Cron/CollectionsProductsSync.php`, `Model/Sync/Metadata/Cron/MetadataSync.php`
- Observers: `etc/events.xml` and `etc/adminhtml/events.xml` -- `Observer/**/*.php` (product, category, order, payment, shipment saves and the Yotpo admin config save). They run inside the merchant's own save requests
- CLI commands: `etc/di.xml` (`CommandList`) -- `Console/Command/RetryYotpoSync.php` (`yotpo:resync`), `Console/Command/ResetYotpoSync.php` (`yotpo:resetsync`)
- Admin controllers: `etc/adminhtml/routes.xml` (`yotpoadmin`) -- `Controller/Adminhtml/**/*.php`
- Admin config UI: `etc/adminhtml/system.xml` -- `Block/Adminhtml/**/*.php`, `view/adminhtml/templates/**`
- Admin notifications: `etc/adminhtml/di.xml` -- `Model/System/Message/CustomSystemMessage.php`
- Install/upgrade: `etc/db_schema.xml` and `Setup/Patch/Data/*.php`, run on every merchant's `setup:upgrade`
- Other Composer packages: `magento2-module-reviews` and `magento2-module-messaging` extend or call `Model/AbstractJobs.php`, `Model/Config.php`, `Model/Api/Request.php`, `Model/Api/Sync.php`, `Model/Api/Logger.php`, `Model/Logger/**`, `Model/Sync/Data/Main.php`, `Model/Sync/Catalog/Processor.php`, `Model/Sync/Customers/Processor.php`, `Model/Sync/Reset/**`, `Helper/Data.php`, `Observer/Config/CronFrequency.php` and admin field blocks

## areas

- orders: `Model/Sync/Orders/`, `Observer/Order/`, `Model/OrdersSync*.php`, `Model/ResourceModel/OrdersSync*`, `Api/OrdersSyncRepositoryInterface.php`, `Api/Data/OrdersSyncInterface.php`
- catalog: `Model/Sync/Catalog/`, `Observer/Product/`, `Model/ProductSync*.php`, `Model/ResourceModel/ProductSync*`, `Api/ProductSyncRepositoryInterface.php`
- collections: `Model/Sync/Category/`, `Model/Sync/CollectionsProducts/`, `Observer/Category/`, `Services/`, `Model/CategorySync*.php`, `Model/ResourceModel/CategorySync*`, `Api/CategorySyncRepositoryInterface.php`
- metadata: `Model/Sync/Metadata/`
- reset: `Model/Sync/Reset/`, `Model/Sync/ResetEntitiesSync.php`, `Model/Sync/Orders/ResetSync.php`, `Console/`
- admin: `Block/`, `Controller/`, `view/`, `etc/adminhtml/`, `Model/System/`, `Model/Config/`
- setup: `Setup/`, `etc/db_schema.xml`, `etc/db_schema_whitelist.json`
- tests: `Test/`
- core: `Model/Config.php`, `Model/AbstractJobs.php`, `Model/Api/`, `Http/`, `Model/Logger/`, `Model/Sync/Data/`, `Helper/`, `etc/di.xml`, `etc/config.xml`, `etc/module.xml`, `composer.json`, `registration.php` -- every processor and both sibling modules depend on these, so a change here is cross-cutting by construction

## imports

No path aliases. PSR-4 maps `Yotpo\Core\` to the repository root (`composer.json:25-27`), so the class `Yotpo\Core\Model\Sync\Orders\Data` is the file `Model/Sync/Orders/Data.php`. Classes are referenced by fully-qualified name in `use` lines and in XML.

Three things a text search for `use` misses:
- Magento DI and XML wire classes by name: `etc/di.xml` (preferences, logger handlers, CLI commands, `\Proxy` classes), `etc/events.xml`, `etc/crontab.xml`, `etc/adminhtml/system.xml` (`frontend_model`, `backend_model`, `source_model`) and `etc/adminhtml/di.xml`. Search XML for the FQCN too.
- Config is read by key, not by class: `Model/Config.php:93` maps keys such as `orders_sync_limit` to paths also named in `etc/config.xml` and `etc/adminhtml/system.xml`.
- The sibling modules (`magento2-module-reviews`, `magento2-module-messaging`) are separate repositories and use these classes through Composer; their importers never appear in this repository.

## hotspots

- `Model/Config.php` -- injected by nearly every class here and in both sibling modules; holds the config key map, endpoints and the resync rules
- `Model/AbstractJobs.php` -- base class of every processor, including the sibling modules'
- `Model/Api/Request.php` -- every Yotpo API call, token and headers
- `Http/Yclient.php` -- every HTTP call and the API log
- `Model/Logger/Main.php` -- base of every logger
- `etc/di.xml` -- object wiring for the whole module
- `etc/config.xml` -- defaults for every setting, including the API base URLs
- `etc/module.xml`, `composer.json`, `registration.php` -- module identity, version and install constraints

## generatedFiles

[]

## tables

### criticality

- 1.0: runs inside the merchant's own request with no retry budget -- `Observer/**` (an exception fails the merchant's product, category, order or shipment save), `Observer/Config/**` on admin config save, install/upgrade code (`etc/db_schema.xml`, `Setup/**`), and `Model/Api/Token.php` / `Model/Api/Request.php` credential handling
- 0.7: shared code every sync path relies on -- `Model/Config.php`, `Model/AbstractJobs.php`, `Model/Api/**`, `Http/**`, `Model/Logger/**`, `Model/Sync/Data/**`, `etc/di.xml`, `etc/config.xml`; admin pages (`Block/**`, `Controller/**`, `view/**`, `etc/adminhtml/**`)
- 0.4: cron sync logic that recovers on the next run -- `Model/Sync/Orders/**`, `Model/Sync/Catalog/**`, `Model/Sync/Category/**`, `Model/Sync/CollectionsProducts/**`, `Services/**`
- 0.2: re-runnable or operator-triggered work -- `Model/Sync/Metadata/**`, `Model/Sync/Reset/**`, `Console/**`
- 0.1: never on a live path -- `Test/**`, `docs/**`, `*.md`, `.gitignore`

### change-type

- 0.95: irreversible or trust-breaking -- `etc/db_schema.xml` / `etc/db_schema_whitelist.json` changes (run on every merchant database; a removed column is dropped), an edit to an applied `Setup/Patch/Data/*.php`; `composer.json` `name` / `replace` / `conflict` / `require` / `version` and `etc/module.xml` (ADR-0001, release coupling with the sibling modules); credential or token handling (`Model/Api/Token.php`, `Model/Api/Request.php`, the `secret` / `auth_token` keys in `Model/Config.php`); `etc/acl.xml`; a changed signature of a class the sibling modules use; `.github/**`, `.agents/adlc/**`, `CODEOWNERS`, `.claude/protected-files.json`
- 0.75: shared/core infrastructure -- the hotspots above, `etc/crontab.xml`, `etc/cron_groups.xml`, `etc/events.xml`, `etc/adminhtml/system.xml`, a new observer, a new dependency on a Magento module or library
- 0.50: non-trivial sync logic without a matching unit test -- `Model/Sync/Orders/**` payload or resync rules, catalog create/update/409 recovery, real-time sync paths
- 0.30: contained feature work with unit tests under `Test/Unit/**`
- 0.15: config defaults in `etc/config.xml` (batch sizes, schedules), admin labels, log messages
- 0.05: comments, docs, formatting, dead-code removal -- `docs/**`, `*.md`
