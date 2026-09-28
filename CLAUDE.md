# magento2-module-core

## Purpose
`Yotpo_Core` is the shared core of Yotpo's Magento 2 extension (`registration.php:4`, `composer.json:2`, package `yotpo/module-yotpo-core`). It holds the Yotpo API client and auth token handling, the store configuration, and the cron/observer-driven sync of Magento products, categories (Yotpo collections), collection membership and orders to the Yotpo Core v3 API (`etc/config.xml:14`). The Reviews module (`magento2-module-reviews`) and the SMS/Messaging module (`magento2-module-messaging`, `Yotpo_SmsBump`) both depend on it and pin an exact version of it (their `composer.json` `require`). Merchants install it from Packagist or the Adobe Commerce Marketplace (`README.md:26-50,68-96`). Owned by team Orbits (`CODEOWNERS`).

## Stack
- Language: PHP `^7.1|^8.0` (`composer.json:9`). No version pin file
- Framework: Magento 2 module (`magento/framework >=102.0.0`, `composer.json:10`); supported Magento versions are listed in `README.md:15-24`
- Runtime: inside a merchant's Magento installation. There is nothing to run on its own: it needs a Magento root to compile, test or execute
- Package manager: Composer (`composer.json`), published through git tags to Packagist (`README.md:82-87`)
- Key dependencies (from Magento, not declared here): Guzzle (`Http/Yclient.php:5-9`), Monolog (`Model/Logger/Main.php:10`), Magento cron, EAV, declarative schema

## Directory Structure
Top-level only (run `ls` to verify if stale):
- `Api/`: repository and data interfaces for the sync tables
- `Block/`, `view/adminhtml/`: admin configuration fields and templates
- `Console/Command/`: `yotpo:resync` and `yotpo:resetsync`
- `Controller/Adminhtml/`: log download and reset-orders-sync admin actions
- `Helper/`: date formatting, phone codes
- `Http/`: `Yclient` (Guzzle wrapper) and `YotpoRetry`
- `Model/`: config, API request/token/logger, sync processors (`Model/Sync/*`), resource models
- `Observer/`: product, category, order and config-save observers that queue entities for sync
- `Services/`: category-product lookups
- `Setup/Patch/Data/`: data patches (EAV attributes, config migrations)
- `Test/Unit/`: PHPUnit tests; `Test/Mftf/`: Magento functional tests
- `etc/`: module, DI, events, cron, DB schema, admin config, ACL
- `docs/`: maintenance notes and harness docs

## Key Files
- `Model/Config.php:93`: config key → path map, API endpoints (`:76`), resync rules (`canResync`, `:464`)
- `Model/Api/Request.php:70`: every Yotpo API call; adds the token header and retries once on an invalid token
- `Model/Api/Token.php:64`: creates the access token from app key + secret
- `Http/Yclient.php:84`: HTTP call, response wrapping, API logging
- `Model/Sync/Orders/Processor.php:118`: order sync (cron `processOrders` `:196`, real-time `processSingleEntity` `:322`)
- `Model/Sync/Orders/Data.php:141`: order payload builder (a DI singleton, see conventions)
- `Model/Sync/Catalog/Processor.php:132`: product sync
- `Observer/Order/OrderMain.php:54`: re-queue + optional real-time order sync
- `etc/crontab.xml`, `etc/events.xml`, `etc/db_schema.xml`: cron jobs, observers, sync tables

## Don't Touch
- `etc/db_schema.xml` / `etc/db_schema_whitelist.json`: fragile. Declarative schema runs on every merchant's database on `setup:upgrade`; a removed column is dropped there
- Applied `Setup/Patch/Data/*.php`: fragile. A patch runs once per install and is recorded in `patch_list`; add a new patch instead of changing an applied one
- `composer.json` `name` / `replace` / `conflict` / `version`: fragile. They decide what Composer installs for merchants (commit `3373ed8`, [ADR-0001](docs/adr/0001-conflict-not-replace-for-legacy-package.md))

## Quick Start
There is no build and no CI in this repository. Everything runs from a Magento 2 root that has this module installed (`README.md:26-50`):
- **Install/compile:** `php bin/magento setup:upgrade` then `php bin/magento setup:di:compile` (`README.md:32-33`)
- **Unit tests:** `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/yotpo/module-yotpo-core/Test/Unit` (Magento's unit config already collects `vendor/*/module-*/Test/Unit`, commit `a3fe1da`)
- **Lint:** no CLI linter. `docs/Maintenance.md` describes the PhpStorm "Php Inspections (EA Extended)" plugin. Commands per kind of change: `.agents/adlc/REPO_CHECKS.md`
- **Run a sync by hand:** `php bin/magento yotpo:resync --entity=<order|catalog|...>` (`Console/Command/RetryYotpoSync.php:116`)
- **Release:** bump `composer.json` and `etc/module.xml`, merge, tag (`README.md:68-96`)

## Development Workflow (Spec-Driven)

> Requires: `superpowers` plugin. Install with `/plugin install superpowers@claude-plugins-official`

All feature work, bug fixes, and refactors MUST start with `/superpowers:brainstorming`.
Do NOT write code without first brainstorming and planning.
The Superpowers pipeline guides you through the rest (planning, execution, finishing).

Specs and plans MUST be committed to the feature branch and included in the PR.

## Common Patterns
- Queue an entity for sync: set its `synced_to_yotpo_*` flag to 0 in an observer; cron picks it up (`Observer/Order/OrderMain.php:67`, `Observer/Product/SaveAfter.php:160`)
- Call Yotpo through `Model/Api/Sync::sync()` with an `entityLog` key, never Guzzle directly (`Model/Api/Sync.php:26`)
- Per-store work runs inside store emulation: `emulateFrontendArea` / `stopEnvironmentEmulation` (`Model/AbstractJobs.php:77`)
- Response codes decide resync: `Config::canResync` / `canUpdateCustomAttribute` (`Model/Config.php:464-510`)
- Commits: `type(scope):message`, no space after the colon, types `clean|enhance|doc|feat|fix|refactor|style|upgrade|update`; branches `<KEY>-<n>-desc` or `<KEY>-FIX-desc` (local git hooks from `magento-environment-artifacts/scripts/git`). PRs merge with a merge commit

Full details: [docs/conventions.md](docs/conventions.md).

## Anti-Patterns
See [conventions.md](docs/conventions.md#anti-patterns) for the full list.

## Reference Docs
Read BEFORE starting work on the relevant area:
- [Architecture](ARCHITECTURE.md): system map, modules, boundaries, data flow, architectural decisions
- [Docs Index](docs/index.md): entry point for the docs/ folder (conventions, troubleshooting, and any other deep docs)
- Protected Files (`.claude/protected-files.json`): criticality tiers (human-required / review-required / auto-safe); CODEOWNERS mirrors the human-required tier
- [Spec template](docs/specs/TEMPLATE.md): copy per feature. Specs are committed to the PR
