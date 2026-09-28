# Code Review Fix instructions

Check commands come only from `.agents/adlc/REPO_CHECKS.md`.

## Convention pointers

The canonical examples to copy (`docs/conventions.md` has the full tables and the Patterns Library):

- **Dependency injection:** constructor injection into `protected` properties, with a PHPDoc `@param` per
  argument; processors extend `Model/AbstractJobs.php` and pass its arguments to `parent::__construct`. See
  `Model/Sync/Orders/Processor.php:89-108`.
- **Config:** `$this->config->getConfig('<key>')` with a key from `Model/Config.php:93`. Add a key there, a
  default in `etc/config.xml` and a field in `etc/adminhtml/system.xml`; never a hard-coded path.
- **API calls:** `Model/Api/Sync::sync($method, $endpoint, $data)` with an `entityLog` key and an endpoint
  from `Model/Config.php:76`. Branch on the response's `is_success` / `status` through `Config::canResync`
  and `canUpdateCustomAttribute`.
- **Queue for sync:** set the `synced_to_yotpo_*` flag to 0 (`Observer/Order/OrderMain.php:67-104`). Real-time
  work in an observer goes through a commit callback (`Observer/Order/SalesOrderShipmentSaveAfter.php:60-95`).
- **Per-store work:** `emulateFrontendArea($storeId)` and `stopEnvironmentEmulation()` on every branch,
  including `continue` (`Model/Sync/Orders/Processor.php:142-156`).
- **Errors and logging:** catch per entity, log with the entity id and store through the entity's logger
  (`infoLog` for handled cases, `errorLog` for failures only a log will show), and keep the batch going
  (`Model/Sync/Orders/Processor.php:293-303`).
- **Unit tests:** PHPUnit under `Test/Unit/` mirroring the class path, `<Class>Test`, `test<Behaviour>(): void`,
  partial mocks with `disableOriginalConstructor()->onlyMethods([...])` and collaborators set through
  `ReflectionProperty`. See `Test/Unit/Model/Sync/Orders/DataTest.php` and
  `Test/Unit/Observer/Order/SalesOrderShipmentSaveAfterTest.php`.

## Stack specifics

- **No Magento in the repository.** This is a module, not an application: nothing compiles or runs from this
  directory alone. Every check in `REPO_CHECKS.md` needs a Magento root with this checkout installed; without
  one, the checks cannot run. Then say so in the report. Never claim a fix verified.
- **PHP range:** `composer.json` allows PHP `^7.1|^8.0`. Keep production code within PHP 7.1 syntax (no
  constructor promotion, `match`, enums, `readonly`, nullsafe operator, union types). Test code may use PHP 8.1
  features; the existing tests already do.
- **No formatter, no CLI linter.** Match the surrounding style by hand; never reformat lines the finding does
  not cover.
- **XML wiring:** a new class used by DI, events, cron or admin config is referenced by its FQCN in `etc/*.xml`.
  A rename must update the XML too, and the sibling modules may reference it by name (`ARCHITECTURE.md`
  Boundaries). Keep renames out of fixes.
- **Singletons:** processors and `Orders\Data` are shared across a whole cron batch; reset per-entity state
  at the start of the per-entity method.

## Protected paths

`.claude/protected-files.json` is the source of truth. For this repo in particular:

- **Never write** (human-required): `etc/db_schema.xml`, `etc/db_schema_whitelist.json`, `Setup/**`,
  `composer.json`, `etc/module.xml`, `registration.php`, `etc/acl.xml`, `Model/Api/Token.php`,
  `Model/Api/Request.php`, `LICENSE.txt`, `LICENSE_AFL.txt`, `.github/**`, `.agents/adlc/**`, `CODEOWNERS`,
  `.claude/protected-files.json`. A finding that needs one of these (a schema column, a data patch, a version
  bump, a Composer constraint, an ACL resource, a change to token handling) is out of scope. Report it;
  don't work around it.
- **Review-required:** `Api/**`, `Block/**`, `Console/**`, `Controller/**`, `Helper/**`, `Http/**`, `Model/**`,
  `Observer/**`, `Services/**`, `view/**`, `etc/**`, `Test/Mftf/**`, `CLAUDE.md`, `AGENTS.md`, `.claude/**`.
- **Public signatures:** do not change a constructor or public/protected method signature of a class the
  sibling modules use (`ARCHITECTURE.md` Boundaries), even in a review-required path. Report it instead.
- **Credentials:** never print, log, copy or move an app key, secret or token. Never add a credential to a
  file; a fix that seems to need one is out of scope.

## On-demand CI run

There is none. The repository has no CI workflow, so there is no `ci-on-demand.yaml` and no
`ready-for-merge` CI run. Nothing remote can verify a fix, so the report must list every check that did not
run.
