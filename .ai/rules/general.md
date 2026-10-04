---
paths:
  - '**'
---

# General

## Local dev: Postgres in Docker + sandbox needs bypass for DB/docker
- DB is Postgres 16 via docker-compose.yml (service `postgres`, db/user/pass all `naijafresh`, port 5432). Start with `docker compose up -d`.
- Tests run against a separate `naijafresh_test` Postgres DB (see phpunit.xml); create it once with: docker exec naijafresh_postgres psql -U naijafresh -d naijafresh -c "CREATE DATABASE naijafresh_test;"
- The Claude Code bash sandbox blocks the Docker unix socket and TCP to 127.0.0.1:5432. Run `docker ...`, `php artisan migrate`, `php artisan test`, `php artisan serve` and anything touching the DB with dangerouslyDisableSandbox:true.
- Seeded demo logins: admin@naijafresh.test / customer@naijafresh.test, password `password`.
