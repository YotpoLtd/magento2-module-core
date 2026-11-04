# TS-179: Defer the payment-save real-time order sync past the checkout commit

- Ticket: https://yotpoent.atlassian.net/browse/TS-179
- Source: the confirmed spec plan on the ticket
  (https://yotpoent.atlassian.net/browse/TS-179?focusedCommentId=796540), confirmed by
  Penko Krasimirov Penev ("Plan confirmed as written, go ahead and open the PR").
- The plan below is transcribed verbatim from that comment. Line numbers refer to `master`
  at `4c6e969` and may be off by about one.

## Confirmed spec plan

Repository: YotpoLtd/magento2-module-core

Goal: stop the real-time order sync from running inside the checkout's order-save
transaction. A MySQL deadlock (1213) in Yotpo bookkeeping must no longer roll back an order
whose PayPal payment has already been taken. Also make Processor::processOrder always stop the
store emulation it starts.

### What changed since the last findings (re-traced on master 4c6e969)

Root cause is unchanged. Confidence is medium: the code is clear, but there are no logs (the
module runs on merchant servers) and no local reproduction.

Observer/Order/SalesOrderPaymentSaveAfter.php:54-72 does all its work synchronously. It writes
the guest SMS consent (UPDATE sales_order), runs reQueueOrder (OrderMain.php:67-75, UPDATE
sales_order and yotpo_orders_sync), then syncOrderNow (OrderMain.php:83-88) →
Processor::processOrder (Processor.php:168-186) → processSingleEntity. processSingleEntity makes
the API call, runs insertOnDuplicate on yotpo_orders_sync (AbstractJobs.php:90), and calls
saveConfig for orders_last_sync_time and orders_total_synced, which also runs a COUNT.

Error signals: the 1213 deadlock comes from MySQL on those writes, not from module code. It
propagates because processSingleEntity catches only NoSuchEntityException and
LocalizedException. PayPal 10415 is raised by PayPal on the retry and is not in this code.

Processor.php:170 starts emulation. The isSyncResetInProgress return at 172-174 never stops it,
and neither does an exception thrown from processSingleEntity. Only line 185 stops it.

Repositories: magento2-module-core matches (it holds the defect). magento2-module-combined is
related: it is a composer-only metapackage at 4.3.14, and no code change is needed. No other
repository was wanted.

Logs and data: Coralogix was not queried this round because the module logs to the merchant's
own file logger. Databricks was not queried because the bug does not depend on stored data.
Nothing needs repair: cron re-syncs failed orders, because synced_to_yotpo_order stays 0.

Data gaps: the merchant's InnoDB deadlock trace and order timestamps. Which lock cycle fires
(the yotpo_orders_sync unique key or the core_config_data saveConfig row). The unit tests
cannot run in this sandbox, because there is no phpunit config and magento/framework is not
installed. No .claude/protected-files.json exists in the repo.

### In scope

SalesOrderPaymentSaveAfter::execute() does no DB write and no sync. It captures the area code,
the guest flag and the checkout-session SMS value, dedupes by order id, and registers one
commit callback per order on the payment/order resource.

The callback does these steps in order:

1. Clear the order's dedupe entry.
2. Write the guest SMS consent first (yotpo_accepts_sms_marketing).
3. Run reQueueOrder, even when real-time sync is off.
4. Run syncOrderNow, which syncs only when real-time sync is on for the store.
5. Catch \Throwable and log it through Yotpo\Core\Model\Sync\Orders\Logger::errorLog. Nothing
   is rethrown.

Processor::processOrder wraps everything after emulateFrontendArea in try/finally, so
stopEnvironmentEmulation always runs: on success, on the isSyncResetInProgress return, and when
the sync throws.

### Out of scope

- A deadlock retry in AbstractJobs::insertOnDuplicate (Q3).
- The shipment observer's synchronous reQueueOrder, and AdminSalesOrderAddressUpdate. These are
  a follow-up (Q4).
- The release version bump in core composer.json and in magento2-module-combined. This is a
  release step, not part of this fix.

### Decisions

- Q1: defer all the observer's work into a commit callback that catches \Throwable, logs,
  dedupes by order id, and captures the area code, guest flag and SMS value first. Answer: yes
  (Penko Krasimirov Penev). His notes, also adopted: callbacks run only when the transaction
  level reaches 0 and are cleared on rollBack(). commit() catches only \Exception, so the
  callback must catch \Throwable itself. The SMS consent is written first, because
  prepareGuestUsersCustomAttributes() (Model/Sync/Orders/AbstractData.php:575) reads it back
  from sales_order. The order is re-queued even when real-time sync is off. The dedupe entry is
  cleared when the callback runs, because the observer is a shared instance.
- Q2: a try/finally around emulation in Processor::processOrder, required, also covering the
  isSyncResetInProgress return. Answer: yes (Penko Krasimirov Penev).
- Q3: keep the deadlock retry out of scope. Answer: yes (Penko Krasimirov Penev).
- Q4: keep the shipment observer and AdminSalesOrderAddressUpdate out of scope. Answer: yes
  (Penko Krasimirov Penev).
- Q5: the acceptance criteria are unit tests plus a manual check. Answer: yes (Penko Krasimirov
  Penev), with the extra test cases listed below.

Reference pattern: SalesOrderShipmentSaveAfter already uses addCommitCallback with a \Throwable
catch and Logger::errorLog, but never clears its dedupe entry (found in
Observer/Order/SalesOrderShipmentSaveAfter.php).

### Acceptance criteria

- During execute(), nothing is written to the database and processOrder is not called.
- The callback runs only after the outermost commit and is dropped on rollback. The test uses a
  real AbstractResource subclass on a fake connection, so Magento's own CallbackPool logic runs.
- Inside the callback, the SMS consent is written before the re-queue and the sync. The
  re-queue also happens when real-time sync is off, and processOrder is called only when it is
  on.
- Two payment saves of one order in one request register one callback. After the callback
  runs, a later save of the same order registers a new one.
- An \Exception and an \Error thrown in the callback are both logged and not rethrown.
- ProcessorTest: emulation is stopped when the sync throws, and when a sync reset is in
  progress.
- Manual check on Magento 2.4.8 and PHP 8.3 with PayPal Express and real-time order sync on at
  store-view scope: checkout completes and the order syncs after commit.
- Tests that cannot run without magento/framework are reported as not run, never as passing.

### Areas and files

- Observer/Order/SalesOrderPaymentSaveAfter.php: add the Logger to the constructor, a dedupe
  array, and the commit callback.
- Model/Sync/Orders/Processor.php: the try/finally in processOrder.
- New Test/Unit/Observer/Order/SalesOrderPaymentSaveAfterTest.php, modelled on
  Test/Unit/Observer/Order/SalesOrderShipmentSaveAfterTest.php.
- Test/Unit/Model/Sync/Orders/ProcessorTest.php: add the two emulation cases.

## Known limit (from the ticket thread)

A rolled-back order save leaves the order's duplicate-guard entry set for the rest of that PHP
process, same as the shipment observer: Magento clears commit callbacks on rollBack(), so the
callback that would have cleared the entry never runs.
