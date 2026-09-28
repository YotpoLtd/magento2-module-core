# Repository check instructions

## Environment

- **This is a Magento 2 module, not an application.** Nothing builds, compiles or tests from this directory
  alone. Every command below runs in a **Magento 2 root** (`$MAGENTO_ROOT`) that has this checkout installed
  as `Yotpo_Core`, either at `vendor/yotpo/module-yotpo-core` (Composer, `README.md:26-37`) or at
  `app/code/Yotpo/Core` (manual install, `README.md:39-50`). If the installed copy is not this checkout, the
  commands test other code.
- **Toolchain:** PHP `^7.1|^8.0` and `magento/framework >=102.0.0` (`composer.json:8-11`), on a Magento version
  from `README.md:15-24`. There is no version pin file; the Magento root decides the PHP and the dependency
  versions. The unit tests use PHP 8.1 features (`Test/Unit/Model/Sync/Orders/DataTest.php:131`).
- **An installed Magento is required** (`app/etc/env.php` and a database) for `setup:upgrade` and
  `setup:di:compile`. Without it they fail with installation or database errors. That is a missing
  environment, not broken code.
- TODO(ai-dlc): the agent image, and that it is not the runtime image. There is none: no Dockerfile and no
  Magento environment in this repository. `YotpoLtd/magento-environment-artifacts` has Docker images for
  local Magento, but nothing here names one for agents.

## Always, on every changed file

**There is no formatter in this repository** -- no PHP-CS-Fixer, no phpcbf config, no EditorConfig. Do not
add one, and do not reformat lines as a side effect of a fix.

The documented linter is the PhpStorm plugin "Php Inspections (EA Extended)", run by hand from the IDE
(`docs/Maintenance.md:7-17`). It has no command line, and there is no CI, so nothing fails a build on a
violation. The code carries `// phpcs:ignore` and `@phpstan-ignore-next-line` markers, but no phpcs or phpstan
config or command exists in the repository.

```
TODO(ai-dlc): a command-line lint command -- none is documented in CI, scripts or docs
```

TODO(ai-dlc): whether the linter can fix violations itself, and which repo-specific rules an agent is most
likely to trip. Until a linter is named: keep production code valid on PHP 7.1 (`composer.json:9`) and match
the surrounding PHPDoc style (`docs/conventions.md`).

TODO(ai-dlc): where the linter config and its suppression list live. There is none in the repository.

- **A violation your own edit introduced is part of the finding you are fixing** --
  iterate until it is clean. Never report a finding fixed while its checks are red, and
  never weaken or silence a check to get a commit through.
- **Pre-existing violations in code you did not touch are out of scope.** Leave them;
  fixing them widens the diff past the finding.
- **Reverting is the last resort, not the first move.** Only when the retry budget in
  the `code-review-fixer` skill is spent -- the rule is unclear, or satisfying it would
  change behaviour -- back that finding's edit out and report it unfixed, with the
  check's own message as the reason.

## By kind of change

Run the narrowest thing that covers what you touched. All commands run from `$MAGENTO_ROOT`; with Composer
installs the module path is `vendor/yotpo/module-yotpo-core`, with manual installs `app/code/Yotpo/Core`.

| Change touches | Run |
|---|---|
| `Model/**`, `Observer/**`, `Http/**`, `Helper/**`, `Services/**`, `Console/**`, `Controller/**`, `Block/**`, `Api/**`, `Test/Unit/**` | `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist <module path>/Test/Unit` -- Magento's unit config already collects `vendor/*/module-*/Test/Unit` (commit `a3fe1da`) |
| `etc/di.xml`, `etc/**/di.xml`, a constructor, a new or renamed class | `php bin/magento setup:di:compile` (`README.md:33`), then the unit tests above |
| `etc/db_schema.xml`, `Setup/**`, `etc/module.xml` (human-required; only when a human asked for it) | `php bin/magento setup:upgrade` then `php bin/magento setup:di:compile` (`README.md:32-33`) |
| `etc/config.xml`, `etc/crontab.xml`, `etc/cron_groups.xml`, `etc/events.xml`, `etc/adminhtml/**`, `view/**` | `php bin/magento setup:di:compile` (`README.md:33`) -- TODO(ai-dlc): nothing documented checks XML or templates beyond compiling |
| `Test/Mftf/**` | nothing locally -- see "Do not run" |
| `composer.json`, `registration.php` (human-required) | `php bin/magento setup:upgrade` then `php bin/magento setup:di:compile` -- a packaging change can break any area |
| Markdown, `docs/**` only | nothing |

A change spanning several areas runs each area's row, not the full build.

## Full validation

```
bash .agents/adlc/repo-validation.sh
```

The canonical pre-merge run, and the single definition of "everything passed" -- see the
script's header for the exit-code contract. Not part of the fix loop: run it once at the
end of a pass.

## Do not run

- **Anything needing infrastructure this environment does not have** --
  integration or component suites that boot databases, brokers or other services. They
  belong to CI, not to a fix loop. Here: the MFTF tests under `Test/Mftf/` (suite `yotpoSuite`), which need a
  full Magento install, Selenium and real Yotpo `_CREDS`; Adobe runs them on every Marketplace submission
  (commit `12b0aff`). Also `php bin/magento yotpo:resync` and `yotpo:resetsync`
  (`Console/Command/RetryYotpoSync.php:116`, `ResetYotpoSync.php:101`): they call the live Yotpo API for every
  configured store, and reset deletes sync tables.
- **Dependency re-resolution** unless a dependency actually changed -- it
  re-fetches the whole graph over the network every time. Here: `composer require` / `composer update` in the
  Magento root (`README.md:30`).
- **Anything that writes to the default branch**, publishes an artifact, pushes an image,
  or triggers a deploy. Here: `git tag {VERSION}` / `git push origin {VERSION}` (publishes to Packagist,
  `README.md:82-87`), and the Adobe Commerce Marketplace submission (`README.md:90-96`).
- **`git push --force`**, and any rebase to sync the branch.
