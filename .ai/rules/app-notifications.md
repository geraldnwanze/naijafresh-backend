---
paths:
  - 'app/Notifications/**'
---

# App Notifications

## All email is queued: notifications only, ShouldQueue + QueuesEmail
Email leaves the app only through Notification classes that implement ShouldQueue and use Concerns\QueuesEmail (bell = database on sync, mail = queue.default, tries 3, backoff). No Mail:: facade or Mailable sends; tests/Unit/NotificationsArchTest.php enforces it.
QUEUE_CONNECTION=database locally (composer run dev runs queue:listen) and in production (run queue:work). phpunit.xml keeps sync; tests that need real queueing set queue.default=database and run queue:work --stop-when-empty.
Order notifications go to the customer's contact_email via OrderNotification::mailRecipient(); staff variants (NewOrderNotification) override it to the admin's account email. Do not route staff mail through contact_email.
Low stock: StockNotifier (extends Notifier, like OrderNotifier) sends ONE LowStockNotification per sale listing every product that crossed a StockLevel (ok → low → out). Alerts fire on crossings only, not on every sale of an already-low product. Thresholds: config naijafresh.inventory (units, grams) via Product::lowStockThresholdFor(); the lowStock scope and the inventory API meta use the same values.
Notification payloads hold scalar snapshots, not models (queued mail must show stock as it was at the sale).

## Super admins get staff alerts in-app only, never by email
Staff alerts (NewOrderNotification, LowStockNotification) go to admins AND super admins, but via() drops the mail channel unless User::receivesStaffEmails() (ordinary admins only). Super admins keep the bell entries. Any new staff-facing notification must use the same check in via(). Customer-facing order emails are unaffected (they go to the order's contact email).
