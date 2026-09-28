#!/usr/bin/env bash
#
# Canonical full validation for this repository. Scaffold from
# `yotpo-common:generate-harness-docs`; the owning team writes the commands.
#
# Contract -- do not change it, the pipeline depends on it:
#   - run from the repository root, no arguments
#   - echo each command before running it
#   - exit 0  every check passed
#   - exit 1  a check failed
#   - exit 2  not configured / cannot validate -- NOT a pass
#   - never commit, never push, never mutate git state
#
# Exit 2 exists so "cannot validate" is never read as "failed" (hides a broken
# environment) or as "passed" (an unconfigured repo would earn ready-for-merge).
#
# This repository is a Magento 2 module: it cannot compile or test on its own.
# The script needs MAGENTO_ROOT, an installed Magento 2 root (app/etc/env.php,
# a database) in which Yotpo_Core IS THIS CHECKOUT -- either
# vendor/yotpo/module-yotpo-core or app/code/Yotpo/Core resolving to this
# directory (a Composer path repository or a symlink). Anything else exits 2.
# setup:di:compile writes to MAGENTO_ROOT/generated, never to this repository.
#
# KNOWN DIVERGENCE FROM CI -- there is no CI in this repository (no
# .github/workflows). What exists outside this script and is not run here:
#
# - MFTF (Test/Mftf/, suite yotpoSuite): Adobe runs it on every Marketplace
#   submission (commit 12b0aff). It needs Selenium and real Yotpo credentials.
#   A PR can pass this script and still fail the Marketplace run.
# - Linting: the only documented linter is the PhpStorm "Php Inspections (EA
#   Extended)" plugin (docs/Maintenance.md). It has no command line.
# - TODO(ai-dlc): a command-line lint (or PHP-version compatibility) check --
#   none is documented in CI, scripts or docs, so none runs here.
# - Compatibility: only the Magento/PHP version of MAGENTO_ROOT is checked, not
#   the whole range in composer.json / README.md:15-24.

set -euo pipefail

if [ ! -f "./registration.php" ] || [ ! -f "./etc/module.xml" ] || [ ! -f "./composer.json" ]; then
  echo "repo-validation: not at repo root ($(pwd)) -- run from the repository root" >&2
  exit 2
fi

if [ -z "${MAGENTO_ROOT:-}" ]; then
  echo "repo-validation: MAGENTO_ROOT not set -- environment not configured." >&2
  echo "  This module compiles and tests only inside an installed Magento 2 root." >&2
  exit 2
fi

for f in bin/magento vendor/bin/phpunit dev/tests/unit/phpunit.xml.dist app/etc/env.php; do
  if [ ! -e "$MAGENTO_ROOT/$f" ]; then
    echo "repo-validation: $MAGENTO_ROOT/$f missing -- MAGENTO_ROOT is not an installed Magento 2 root" >&2
    exit 2
  fi
done

repo_dir="$(pwd -P)"
module_dir=""
for candidate in "$MAGENTO_ROOT/vendor/yotpo/module-yotpo-core" "$MAGENTO_ROOT/app/code/Yotpo/Core"; do
  if [ -d "$candidate" ] && [ "$(cd "$candidate" && pwd -P)" = "$repo_dir" ]; then
    module_dir="$candidate"
    break
  fi
done
if [ -z "$module_dir" ]; then
  echo "repo-validation: Yotpo_Core in $MAGENTO_ROOT is not this checkout ($repo_dir) --" >&2
  echo "  link vendor/yotpo/module-yotpo-core or app/code/Yotpo/Core to it first." >&2
  exit 2
fi

# Any failing check exits 1, even when the tool itself exits 2 (PHPUnit does
# on errors), so a real failure is never read as "not configured".
run() {
  echo "+ $*"
  "$@" || exit 1
}

cd "$MAGENTO_ROOT"

# Unit tests: Magento's unit config collects vendor/*/module-*/Test/Unit
# (commit a3fe1da); the path limits the run to this module. Fastest check first.
run vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist "$module_dir/Test/Unit"

# DI compile (README.md:33): catches broken constructors, DI config and class
# references across the module, including the XML wiring.
run php bin/magento setup:di:compile

echo "repo-validation: all checks passed"
