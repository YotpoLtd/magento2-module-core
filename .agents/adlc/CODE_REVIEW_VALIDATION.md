# Code Review Validation instructions

## Known false positives

Each of these looks like a finding but is deliberate or accepted in this repo:

- **"Hard-coded secret" on `value="123"`** in `Test/Mftf/Test/EnableYotpoWithIncorrectAppKeyTest.xml`. It is
  the wrong secret the test needs. Real MFTF credentials come from `{{_CREDS.*}}`. Never quote a credential
  value when verifying.
- **"Sensitive data logged" / "admin controller without ACL" on unchanged code.** The request-options
  logging in `Http/Yclient.php` and the missing `ADMIN_RESOURCE` on the existing admin controllers are known
  (`docs/troubleshooting.md` Security notes). Drop the finding unless the diff adds to them.
- **"Customers processor does nothing."** `Model/Sync/Customers/Processor.php` is a placeholder; the Messaging
  module supplies the real one.
- **"Class `Yotpo\SmsBump\...` does not exist."** It belongs to the Messaging module. The references in
  `Controller/Adminhtml/Index/DownloadLogs.php` and `etc/di.xml` only matter when that module is installed.
- **"`echo` in library code"** in the `isCommandLineSync` branches of processors, marked `// phpcs:ignore`.
  It is the CLI progress output of `yotpo:resync`.
- **"Exception swallowed"** in a processor's per-entity or per-store `catch` that logs and marks the entity.
  Keeping the batch going is the pattern (`docs/conventions.md` Error Handling). Keep the finding only when
  nothing is logged or the entity is left in a state cron will not pick up again.
- **"Real-time sync result ignored"** when `processSingleEntity` skips writing a non-retryable code. That is
  ADR-0002.
- **"Order marked synced although it failed"** in cron `processOrders`. Setting `synced_to_yotpo_order = 1`
  on a non-retryable code or missing products is how cron gives up on an order; it is re-queued when the order
  changes or by `yotpo:resync`.
- **"No CI / tests not run by CI."** The repository has no CI. A missing-test finding stands on its own; a
  claim that a PR fails a CI check is wrong.
- **"PHP 8 syntax in tests."** `Test/Unit/**` may use PHP 8.1 features (the existing tests call
  `ReflectionProperty::setValue` without `setAccessible`). The PHP 7.1 floor applies to production code only.
- **Documentation drift in `README.md`** listed in `docs/troubleshooting.md`, when the PR does not touch it.

## Always keep

Never filter these out, even if they look minor or cosmetic:

- a new committed credential (Yotpo app key, secret, token, Marketplace or Magento repo keys) or any
  production secret;
- a removed column or table in `etc/db_schema.xml`, a schema change without its `etc/db_schema_whitelist.json`
  entry, or an edit to a released `Setup/Patch/Data` class;
- a change to `composer.json` `name` / `replace` / `conflict` / `require`, or a `version` out of step with
  `etc/module.xml`;
- a changed signature of a class the sibling modules use (`ARCHITECTURE.md` Boundaries);
- an observer that can throw, or that calls the Yotpo API inside the open save transaction;
- the real-time order path recording a terminal failure;
- per-entity state on a DI singleton that is never reset;
- new logging of the secret or token;
- production code that needs a newer PHP than `composer.json` allows.

## Domain notes

- Every processor extends `Model/AbstractJobs.php` and reads config through `Model/Config.php`; so do the
  Reviews and Messaging modules. A finding in those two files, or in `Model/Api/**` / `Http/**`, reaches every
  sync and both sibling modules.
- Magento wires classes by name in `etc/*.xml`. A finding like "unused class" or "missing caller" must be
  checked against `etc/di.xml`, `etc/events.xml`, `etc/crontab.xml`, `etc/adminhtml/*.xml` and the sibling
  modules before it is kept.
- Processors and `Orders\Data` are DI singletons for the whole cron run; state findings must consider every
  entity in the batch, not one call.
- Resync behaviour is decided only by `Config::canResync` / `canUpdateCustomAttribute` /
  `canUpdateCustomAttributeForProducts` (`Model/Config.php:464-520`). Check a finding about retries against
  them.
- Nothing can be verified by running code here without a Magento root. A verifier reads code and the unit
  tests; it cannot run them.
