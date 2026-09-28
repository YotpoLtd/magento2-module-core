---
type: ADR
title: ADR-0002 Cron owns terminal order-sync results; real-time sync is best-effort and runs after commit
timestamp: 2026-09-28T07:02:17Z
---

# ADR-0002: Cron owns terminal order-sync results; real-time sync is best-effort and runs after commit
- **Status:** Accepted
- **Date:** 2026-08-28 (commits `ab9251c`, `48559fd`, `82e6ebd`; recorded here on 2026-09-28)

## Context
Orders reach Yotpo two ways: the `yotpo_cron_core_orders_sync` cron (`Model/Sync/Orders/Processor.php:196`) and, when `enable_real_time_sync` is on, directly from order observers (`processSingleEntity`, `:322`). The real-time path runs mid-request. On `sales_order_shipment_save_after` the shipment's transaction is still open and `sales_shipment_item` rows are not written, so Yotpo received an empty `fulfilled_items` list and answered with a permanent 400. The real-time path also wrote that response code unconditionally, so the order was marked terminal and cron never retried it.

## Decision
- Observers split re-queueing from syncing: `reQueueOrder()` touches only sync bookkeeping and is safe inside any transaction; `syncOrderNow()` makes the API call (`Observer/Order/OrderMain.php:54-89`).
- The shipment observer re-queues synchronously and defers the API call to the shipment resource's commit callback. It de-duplicates per order within a request and logs, rather than throws, a failure (`Observer/Order/SalesOrderShipmentSaveAfter.php:60-95`).
- The real-time path checks `canResync()` before writing. On a non-retryable response it writes nothing and leaves the order queued (`synced_to_yotpo_order = 0`, `response_code = '000'`) for cron. Success and retryable failures (429, 5xx) are written as before (`Model/Sync/Orders/Processor.php:383-400`).
- Cron keeps full authority to record a terminal failure.

## Alternatives Considered
- **Sync synchronously in the observer** — rejected: child rows do not exist yet, and an exception there fails the merchant's save.
- **Let real-time record terminal failures too** — rejected: it runs with partial context, and one bad moment disabled the cron fallback for good.

## Consequences
- A real-time failure costs at most one cron interval of delay, never a lost order.
- New observers that sync in real time must follow the same split and defer to commit.
- Unit tests cover both rules (`Test/Unit/Observer/Order/SalesOrderShipmentSaveAfterTest.php`, `Test/Unit/Model/Sync/Orders/ProcessorTest.php`).
