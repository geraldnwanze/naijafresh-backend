---
paths:
  - Dockerfile
---

# Dockerfile

## Render/Docker deploy: how the container works and what not to break
One image (Dockerfile + docker/*, render.yaml) runs nginx+php-fpm, queue:work and schedule:work under supervisor; RUN_MIGRATIONS / RUN_QUEUE_WORKER / RUN_SCHEDULER toggle them. Without the worker, queued emails never send.
docker/entrypoint.sh must run at START, not build: it caches config from the real environment, migrates, runs `naijafresh:setup`, and runs artisan as www-data (so logs/caches stay writable). It prefixes Render's plain-base64 APP_KEY with "base64:".
php-fpm needs `clear_env = no` (docker/php/zz-render.conf) or Laravel can't see DB_URL/APP_KEY. composer install uses --no-scripts then dump-autoload + package:discover (artisan isn't copied yet at that layer). .dockerignore must exclude tooling dirs at any depth (`**/.claude`).
Never read env() outside config files (config is cached in production): new settings go through config/*.php. `naijafresh:setup` is idempotent and only fills EMPTY tables; the first super admin comes from SUPER_ADMIN_EMAIL/PASSWORD and is ignored once any super admin exists.
Production safety: DatabaseSeeder skips sample products and demo accounts (password "password") in production; the mock payment gateway is refused in production unless NAIJAFRESH_ALLOW_MOCK_PAYMENTS=true (card payments are hidden until PAYSTACK_SECRET_KEY is set); trustProxies is '*' so audit/activity IPs are the real client IP. Email on Render: SMTP provider on port 2525 (25/465/587 are blocked on some plans).
