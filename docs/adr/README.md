# Architectural Decision Records
| ADR | Title | Status |
|-----|-------|--------|
| [0001](0001-conflict-not-replace-for-legacy-package.md) | Conflict with the legacy `yotpo/module-yotpo` package instead of replacing it | Accepted |
| [0002](0002-cron-is-authoritative-for-order-sync.md) | Cron owns terminal order-sync results; real-time sync is best-effort and runs after commit | Accepted |

New ADRs: copy [0000-template.md](0000-template.md) to `NNNN-<slug>.md`, number sequentially, and never edit an accepted ADR. Supersede it with a new one instead.
