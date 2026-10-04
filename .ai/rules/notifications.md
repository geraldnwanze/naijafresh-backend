---
paths:
  - 'app/Services/Notifications/**'
---

# Notifications

## Order notifications go through OrderNotifier only
Never call ->notify() for order events directly; use OrderNotifier. It sends inside DB::afterCommit (a rolled-back order sends nothing) and swallows+report()s failures (SMTP down must never break an order, payment or status change).
Order notifications are OrderNotification subclasses: database + mail. The bell gets every event; email only for key ones (placed, payment received/failed, and status Confirmed/OutForDelivery/Delivered/Cancelled). Database sends on `sync`, mail on queue.default.
Mail goes to order->contact_email via User::routeNotificationForMail (not the account email). MailMessage has no to().
Bell payload shape (kind, title, body, url, order_id, order_reference) is consumed by the frontend NotificationBell; admin items use url /admin/orders/{id}.
Seeders that place historical orders must call Notification::fake() (DemoDataSeeder does), otherwise they spam emails and bell entries.
OrderStatusService::transition accepts context ['notify' => false] when another message already covers the change.
