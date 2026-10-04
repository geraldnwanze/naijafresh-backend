---
paths:
  - 'app/Services/Audit/**'
---

# Audit

## Super admin area: audit trail vs activity log vs app logs
Roles: customer < admin < super_admin. User::isAdmin() is TRUE for super admins (they do everything an admin can); use isSuperAdmin() / the `super_admin` middleware only for the /admin/system/* area (audit, activity, app logs, user roles). Demo login: superadmin@naijafresh.test.
Audit trail (audit_logs, append-only, kept forever): who changed which record, before → after. Add the Models\Concerns\Auditable trait to a model to audit it (override auditedEvents()/auditExcludedAttributes(); never audit secrets: password, payment meta/authorization_url are excluded). AuditLogger records only STAFF actors or no-actor (System: webhooks/CLI); customer-driven side effects (checkout taking stock) are intentionally skipped.
Activity log (activity_logs, pruned after naijafresh.logs.activity_retention_days via scheduled model:prune): what people did (sign-in/out, failed sign-in, register, order placed/status, payments). Write via ActivityLogger::log(ActivityEvent::X, ...). Never put passwords/tokens in properties.
Both loggers swallow+report failures (inside a DB::transaction savepoint, so Postgres transactions aren't poisoned) and are paused by AuditLogger::withoutAuditing() — wrap seeders/bulk loaders in it (DatabaseSeeder and DemoDataSeeder do).
Application logs: ApplicationLogReader reads only the newest naijafresh.logs.max_read_bytes of a file in naijafresh.logs.directory; file names are whitelisted against the directory listing (no path input). phpunit.xml sets LOG_CHANNEL=null so test runs don't pollute storage/logs/laravel.log (it feeds the dashboard error count).
