# Day 01 — Evidence (awaiting review)

Collected 2026-11-16. The verdict has not been issued yet: this file holds execution evidence only.
Rows marked *(re-run)* were re-collected from the **repository root** after the review fixes (nginx front-controller restriction, Postgres statement/lock timeouts, root `compose.yaml` include shim).

| Acceptance criterion | Evidence |
| --- | --- |
| `up -d --build` brings all services up | `db` and `redis` reach `(healthy)`; `app` and `web` are `Up` |
| PHP 8.4 | `PHP 8.4.27 (cli) ... with Zend OPcache v8.4.27` |
| `artisan about` drivers | `Database: pgsql`, `Cache: redis`, `Session: redis`, `Queue: redis`; Laravel 13.42.0, Composer 2.11.2 |
| `artisan migrate` on Postgres | 3 skeleton migrations `DONE`; still `Ran` after `down` + `up` (F4) |
| `/api/health` happy path | `{"status":"ok","checks":{"database":"ok","redis":"ok"}}` → HTTP 200 in 0.015s |
| Redis stopped | `{"status":"fail","checks":{"database":"ok","redis":"fail"}}` → HTTP 503 in 0.05s |
| DB stopped | `{"status":"fail","checks":{"database":"fail","redis":"ok"}}` → HTTP 503 in 0.13s |
| Redis paused (timeout path) | 503 in 1.02s (REDIS_TIMEOUT=1) |
| DB paused (timeout path) *(re-run)* | 503 in 2.01s (DB_CONNECT_TIMEOUT=2); 200 again after `unpause` |
| Query timeout *(re-run)* | `show statement_timeout` / `show lock_timeout` → `5s` / `3s`; `pg_sleep(5)` with 200ms timeout → `QueryException` SQLSTATE `57014` in 0.21s; `-e DB_STATEMENT_TIMEOUT=0` → `0` |
| Only front controller executes *(re-run)* | Before fix: `public/__probe.php` → `exec` 200. After: `/__probe.php` 404, `/index.php/x` 404, `/index.php` 200, `/` 200, `/up` 200, `/api/health` 200. Probe deleted. Prod `prod.conf`: `nginx -t` OK |
| Recovery after restart | 200 with no app restart |
| Loopback-only host ports *(re-run)* | `web: 127.0.0.1:8083->80/tcp`, `db: 127.0.0.1:5435->5432/tcp` (local GUI clients), `redis: 6379/tcp` (unpublished), `app: 9000/tcp` (unpublished) |
| Commands from repo root *(re-run)* | `docker compose config -q` OK; `up -d --build` reuses the existing `marketplace-*` containers; override (DNS) merged from root `compose.override.yaml`; prod `-f src/compose.prod.yaml --env-file src/.env.production config` resolves to `src/` with no dev override |
| Host-owned files | `ls -ln` shows UID/GID 1000; `find ! -user 1000` returns nothing |
| Non-root FPM | `ps`: master and pool workers run as `app` |
| Separate test DB | `marketplace` and `marketplace_test` both exist; test asserts `current_database() = marketplace_test` |
| Tests *(re-run)* | `php artisan test`: **10 passed (23 assertions)**: health ok, redis down, db down, both down, no detail leak; session timeouts applied, slow query cancelled; pgsql default, test DB, redis cache |
| Style *(re-run)* | `vendor/bin/pint --test`: PASS (29 files) |
| ADR / roadmap | `docs/architecture/0001-test-framework.md`, `docs/ROADMAP.md` |
