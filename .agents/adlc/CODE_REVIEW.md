# Code Review instructions

## Also read

- `ARCHITECTURE.md` -- areas, the observer → cron → API flow, the order sync states, the boundaries and
  the classes the sibling modules depend on.
- `docs/conventions.md` -- patterns, Do's and Don'ts, and the Anti-Patterns table.
- `docs/troubleshooting.md` -- known failure modes, the security notes and the known doc drift.
- `docs/adr/` -- when the change touches `composer.json` packaging (0001) or order sync from observers or
  the real-time path (0002).
- `.claude/protected-files.json` -- the criticality tiers; `human-required` is authoritative.

## Risk areas

Where a mistake here is expensive, and why:

- **It runs on merchants' stores.** There is no staging for a released version: a fatal error, a
  schema change or a slow observer lands on every store that upgrades. There is also no CI here, so
  nothing but the reviewer and the unit tests catches it before release.
- **Sibling modules.** `magento2-module-reviews` and `magento2-module-messaging` extend or call
  `AbstractJobs`, `Config`, `Api\Request`, `Api\Sync`, `Api\Logger`, `Logger\Main`, `Sync\Data\Main`,
  `Sync\Catalog\Processor`, `Sync\Customers\Processor`, `Sync\Reset`, `Helper\Data` and admin field blocks.
  A changed constructor or method signature fails their `setup:di:compile`. None of their code is in this
  repository.
- **Release coupling.** `composer.json` `version` and `etc/module.xml` `setup_version` must match, and the
  sibling modules pin an exact core version (`README.md:68-87`). `name` / `replace` / `conflict` decide what
  Composer installs (ADR-0001, commit `3373ed8`).
- **Observers run inside the merchant's save.** An exception fails the save. API work inside the open
  transaction sees unwritten child rows; the shipment case sent empty `fulfilled_items` and got a permanent
  400 (commit `48559fd`, ADR-0002).
- **Singletons across a batch.** Processors and `Orders\Data` are DI singletons serving every order in a cron
  run. State appended across orders leaked products between orders and made the sync quadratic
  (commit `a3fe1da`).
- **Resync rules.** `Config::canResync` / `canUpdateCustomAttribute` (`Model/Config.php:464-510`) decide
  whether an entity is retried or given up. The real-time path must not record a non-retryable code
  (commit `82e6ebd`).
- **HTTP error handling.** Recovery flows branch on the real HTTP status, such as 409 → GET → PATCH for
  products. Losing the status (commit `a4883e8`) silently broke order sync.
- **Schema and data patches.** `etc/db_schema.xml` runs on every merchant database on `setup:upgrade`;
  applied `Setup/Patch/Data` classes never run again.
- **PHP range.** `composer.json` allows PHP `^7.1|^8.0`; syntax newer than the oldest supported PHP is a
  fatal error there (commits `052f291`, `57eadee`).

## Always check

| Condition | Severity |
|---|---|
| A committed Yotpo app key, secret or token, Adobe Marketplace or Magento repo credential, or any other secret in code, config, MFTF data or docs. Report the file and line only; never quote the value | critical |
| A removed or renamed column or table in `etc/db_schema.xml`, a schema change without the matching `etc/db_schema_whitelist.json` entry, or an edit to an already released `Setup/Patch/Data/*.php` | critical |
| A change to `composer.json` `name`, `replace`, `conflict` or `require`, or `version` changed without `etc/module.xml` `setup_version` (or the reverse) | critical |
| A changed public or protected signature of a class the sibling modules use (see Risk areas) with no note of the matching change in `magento2-module-reviews` / `magento2-module-messaging` | critical |
| New logging of the app key, secret or `X-Yotpo-Token`, or new credential fields added to the request options that `Http/Yclient.php` logs | critical |
| An observer that can throw on a sync or API problem, or that calls the Yotpo API while the save transaction is open (not through a commit callback) | critical |
| The real-time order path writing a non-retryable response code or setting `synced_to_yotpo_order = 1` on failure (ADR-0002) | major |
| State on a DI-shared processor or `Data` class that is appended per entity and not reset per entity | major |
| An HTTP call that bypasses `Model/Api/Sync` / `Model/Api/Request` (a new Guzzle client, `curl`, `file_get_contents`) | major |
| Store config read outside `emulateFrontendArea` / `stopEnvironmentEmulation` in a cron or CLI path, or emulation started and not stopped on every branch | major |
| Config read with a hard-coded path instead of a `Model/Config.php` key | major |
| A hard-coded batch size, schedule or API URL that belongs in `etc/config.xml` / `Model/Config.php` | major |
| Syntax or functions that need a newer PHP than `composer.json` allows, in non-test code | major |
| A new admin controller without `ADMIN_RESOURCE`, or an ACL change in `etc/acl.xml` | major |
| An MFTF suite whose `<include>` group has no tests in this package (commit `12b0aff`) | major |
| New or changed logic in `Model/Sync/**` or `Observer/**` with no unit test under `Test/Unit/**` | major |
| A caught exception with no log line naming the entity and store | minor |
| `echo` outside the `isCommandLineSync` CLI branches | minor |

## Do not report

- **The `"123"` secret in `Test/Mftf/Test/EnableYotpoWithIncorrectAppKeyTest.xml`.** It is a deliberate
  wrong value; real MFTF credentials come from `{{_CREDS.*}}`.
- **The existing logging of request options in `Http/Yclient.php`, and the missing `ADMIN_RESOURCE` on the
  existing admin controllers.** Both are known (`docs/troubleshooting.md` Security notes); fixing them is an
  owner decision. Flag only a PR that adds to them.
- **`// phpcs:ignore` and `@phpstan-ignore-next-line` markers**, and the `echo` calls they cover in the CLI
  branches of processors. They are the existing CLI progress output.
- **Formatting and PHPDoc style.** There is no formatter or CLI linter in this repository.
- **The known documentation drift in `README.md` and `etc/di.xml:59`** listed in `docs/troubleshooting.md`,
  unless the PR touches those lines.
- **`Model/Sync/Customers/Processor.php` doing nothing.** It is a placeholder the Messaging module replaces.
- **References to `Yotpo\SmsBump` classes** in `Controller/Adminhtml/Index/DownloadLogs.php` and
  `etc/di.xml`; they are only used when the Messaging module is installed.
- **Missing CI.** The repository has none; adding it is an owner decision, not a review finding.
- **`AGENTS.md`**, unless a PR replaces the symlink to `CLAUDE.md` with a real file.
