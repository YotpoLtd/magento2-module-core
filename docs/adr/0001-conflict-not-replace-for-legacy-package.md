---
type: ADR
title: ADR-0001 Conflict with the legacy yotpo/module-yotpo package instead of replacing it
timestamp: 2026-09-28T07:02:17Z
---

# ADR-0001: Conflict with the legacy `yotpo/module-yotpo` package instead of replacing it
- **Status:** Accepted
- **Date:** 2026-09-15 (commit `3373ed8`, recorded here on 2026-09-28)

## Context
Before 4.0, Yotpo shipped one monolithic Magento extension. This package must never be installed next to it. It said so with `"replace": {"yotpo/module-yotpo": "*"}`. But the Adobe Commerce Marketplace package is also named `yotpo/module-yotpo`. So `composer require yotpo/module-yotpo:4.3.9` was satisfied by installing this package alone, and the submitted extension was never installed. `vendor/yotpo/module-yotpo/` was missing, and the MFTF "Vendor Supplied" test failed looking for `Test/Mftf/Test/*.xml`.

## Decision
`composer.json` declares `"conflict": {"yotpo/module-yotpo": "<4.0"}` and no longer replaces `yotpo/module-yotpo`. It still replaces `yotpo/module-review` and `yotpo/magento2-module-yotpo-reviews` (`composer.json:13-19`).

## Alternatives Considered
- **`replace` with a version bound** — rejected: Composer refuses coexistence by package name, whatever the constraint.

## Consequences
- The Marketplace package installs alongside core, and its MFTF tests are found.
- Installing core next to a pre-4.0 monolithic extension still fails, as intended.
- `composer.json` `name`, `replace` and `conflict` are human-required: a change there decides what merchants get from Composer.
