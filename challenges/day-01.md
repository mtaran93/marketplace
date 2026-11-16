# Day 01 — Dockerized Laravel foundation (PHP 8.4, PostgreSQL, Redis)

| | |
| --- | --- |
| **Day** | 01 / 60 |
| **Block** | 01–10: PHP 8.4, OOP, types, DI, modular architecture |
| **Subject** | Reproducible, Docker-only development environment and Laravel scaffold |
| **Estimated time** | 120 min (20 design / 80 build & test / 20 review & defend) |
| **Prerequisites** | Docker Engine + Compose v2 on the host, an editor. **No host PHP, Composer, psql, redis-cli, or Node.** |

---

## 1. User story

> The marketplace's first two engineers are about to start on merchants and products.
> Last time, “works on my machine” cost a week: one dev had PHP 8.2 with a different
> `pdo_pgsql`, another ran Postgres 14 locally, and nobody could reproduce the CI failures.
>
> **As** a backend engineer joining the marketplace team,
> **I want** to clone the repo and run one command to get the API, database, and cache running with identical versions,
> **so that** every later feature (inventory locks, queues, webhooks) is developed and tested against the same stack production will use.

The output of today is the foundation for all 59 remaining days. Shortcuts here will cost you in Days 11–30 (transactions, locks, queues).

---

## 2. Requirements

### Functional

1. `compose.yaml` at the repo root defines at least these services:
   - `app` — PHP **8.4** FPM image built from `.docker/php/Dockerfile`, with Composer and the extensions Laravel + PostgreSQL + Redis need (decide: `pdo_pgsql`, `redis` via PECL or Predis? `intl`, `bcmath`, `opcache`, `pcntl`?). Justify every extension.
   - `web` — Nginx using `.docker/nginx/default.conf`, forwarding PHP to `app:9000`, document root `public/`.
   - `db` — PostgreSQL (pick a currently supported major version and pin it).
   - `redis` — Redis (pin a supported version).
2. A Laravel application is scaffolded **through a container** (verify which Laravel major is currently supported and compatible with PHP 8.4 before choosing; write down the version you chose and why).
3. Laravel is configured to use PostgreSQL as the default DB connection and Redis for cache (and session/queue config prepared, even though queues come later).
4. A health endpoint, e.g. `GET /up` (Laravel’s built-in) **and** a custom `GET /api/health` that reports `db` and `redis` connectivity separately, returning `200` only when both are reachable and `503` otherwise, with a JSON body such as:
   ```json
   { "status": "ok", "checks": { "database": "ok", "redis": "ok" } }
   ```
   (Keep it thin — this is infrastructure, not a domain feature.)
5. `.env.example` is committed with all variables needed for Docker development (`DB_HOST=db`, `REDIS_HOST=redis`, etc.); `.env` is ignored.
6. Choose **PHPUnit or Pest** deliberately and record the decision in `docs/architecture/0001-test-framework.md` (short ADR: context, decision, consequences).
7. Initialize `docs/ROADMAP.md` (the 60-day table from `AGENTS.md`, one line per block is enough) — `docs/PROGRESS.md` already exists.

### Nonfunctional

- **Reproducibility:** all image tags pinned (no `latest`). Rebuilding from scratch on a clean machine yields the same PHP/Postgres/Redis versions.
- **File ownership:** files created by `composer`/`artisan` inside the container are owned by your host user, not `root`. Decide how (build args `UID`/`GID`, `user:` in Compose, …) and explain.
- **Least exposure:** only `web` publishes a host port. `db` and `redis` are reachable only on the Compose network. If you publish the DB port for a GUI client, bind it to `127.0.0.1` and document it as optional.
- **Readiness:** `db` and `redis` have `healthcheck`s; `app` uses `depends_on: condition: service_healthy`.
- **Persistence:** named volumes for Postgres and Redis data; source is bind-mounted for development only.
- **Non-root runtime:** PHP-FPM workers do not run as root.
- **No secrets** in `compose.yaml`, the Dockerfile, or committed files beyond obvious local-only defaults in `.env.example`.

---

## 3. Failure, edge-case, security, and concurrency scenarios

Think about each; you must be able to explain what happens and what you did about it.

| # | Scenario | What you must handle / explain |
| --- | --- | --- |
| F1 | `app` starts before Postgres accepts connections | Readiness via healthcheck; why `depends_on` alone is not enough; why the app should still tolerate a DB restart later. |
| F2 | `redis` is stopped while the stack runs | `/api/health` returns `503` with `redis: "fail"` — not a 500 stack trace, and not a hang (set a short connect timeout). |
| F3 | `db` is stopped | Same as F2 for the database. Response time stays bounded (state your timeout). |
| F4 | `docker compose down` then `up` | Data survives. `docker compose down -v` destroys it — know the difference. |
| E1 | Repo directory is not empty (`AGENTS.md`, `docs/`, `challenges/` exist) | `composer create-project` refuses non-empty targets. Plan how to scaffold without losing or overwriting these files. |
| E2 | Scaffolding container’s PHP version ≠ 8.4 | If you use a throwaway `composer` image, its PHP may differ from your app image and resolve incompatible dependencies. How do you avoid a lock file resolved for the wrong platform? |
| E3 | Files appear as `root`-owned on the host | Explain cause and your fix. |
| S1 | `APP_DEBUG=true` leaking in a non-dev environment | Show where dev vs prod config differs (even if prod build comes later). |
| S2 | Postgres/Redis ports on `0.0.0.0` | Must not happen by default. |
| S3 | `.env` accidentally tracked | `.gitignore`/`.dockerignore` cover it; `.dockerignore` also excludes `vendor/`, `node_modules/`, `.env`. |
| C1 | Two developers/CI jobs on one host | Compose project name/ports collide? Explain how `COMPOSE_PROJECT_NAME` or a configurable `WEB_PORT` avoids it. |

---

## 4. Acceptance criteria and required tests

### Acceptance criteria

- [ ] `docker compose up -d --build` from a clean checkout brings all services to `healthy`/`running`.
- [ ] `docker compose exec app php -v` prints PHP **8.4.x**.
- [ ] `docker compose exec app php artisan about` shows driver `pgsql` for DB and `redis` for cache.
- [ ] `docker compose exec app php artisan migrate` succeeds against PostgreSQL.
- [ ] `curl -i http://localhost:<WEB_PORT>/api/health` → `200` with both checks `ok`.
- [ ] With `redis` stopped, the same call returns `503` with `redis` failing, within your stated timeout.
- [ ] `docker compose ps` shows no host port published for `db` or `redis` (or `127.0.0.1` only, documented).
- [ ] Files in `app/`, `vendor/`, `storage/` are owned by your host user.
- [ ] ADR for the test framework exists; `docs/ROADMAP.md` exists.

### Required tests (feature tests, run in Docker)

1. **Health — happy path:** `GET /api/health` returns `200` and the exact JSON shape.
2. **Health — Redis down:** Redis failure produces `503`, `redis: "fail"`, `database: "ok"`. (Hint: don’t stop containers inside a test — make the check injectable/fakeable or bind a failing implementation in the container. Be ready to justify the seam you chose without overengineering.)
3. **Health — DB down:** symmetric to test 2.
4. **Config test:** the default DB connection is `pgsql` and the cache store is `redis` in the testing environment you chose — and explain whether tests hit real Postgres (separate test DB?) or SQLite. *Strong recommendation:* real Postgres, because Days 11–20 depend on Postgres-specific behavior.

The test database must not be your development database (e.g. `marketplace_test`, created by an init script or documented command).

---

## 5. Deliverables and commands

### Files

```text
compose.yaml
.env.example
.dockerignore
.docker/php/Dockerfile
.docker/nginx/default.conf
(optional) .docker/postgres/init/*.sql   # e.g. create test DB
app/ bootstrap/ config/ database/ routes/ tests/ …   # Laravel scaffold
docs/ROADMAP.md
docs/architecture/0001-test-framework.md
README.md (short: “how to run”, copy-pastable)
```

### Commands (all from repo root, all in containers)

```bash
# Build and start
docker compose up -d --build
docker compose ps

# Versions
docker compose exec app php -v
docker compose exec app php -m
docker compose exec app composer --version

# Laravel
docker compose exec app php artisan about
docker compose exec app php artisan migrate

# Tests
docker compose exec app php artisan test

# Diagnostics
docker compose exec db sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "select version();"'
docker compose exec redis redis-cli ping

# Health endpoint (replace 8080 with your WEB_PORT)
curl -i http://localhost:8080/api/health

# Failure check: Redis down
docker compose stop redis
curl -i http://localhost:8080/api/health
docker compose start redis

# Persistence check
docker compose down && docker compose up -d
docker compose exec app php artisan migrate:status

# Ownership check (host side, read-only)
ls -ln app storage vendor | head

# Logs and shutdown
docker compose logs -f app web
docker compose down
```

For scaffolding, design your own approach for E1/E2 (temporary container, scaffold into a subdirectory, then move files in). Write the exact commands you used into `README.md`.

---

## 6. Senior interview questions

1. **“Why not just `depends_on: [db]`? And once you have healthchecks, is your application now resilient to the database going away?”** — Expect to distinguish startup ordering from runtime resilience, and talk about connection timeouts, retries, and what a health endpoint should and should not check (liveness vs readiness; why a liveness probe that checks the DB can cause cascading restarts).
2. **“Your tests run against PostgreSQL in Docker instead of SQLite in memory. Defend that against a teammate who says the suite is now slower.”** — Dialect differences, `SELECT … FOR UPDATE`, partial indexes, JSONB, transaction isolation; mitigation via `RefreshDatabase`/transactions, parallel testing.
3. **“Walk me through what happens between `curl localhost:8080/api/health` and your controller running.”** — Host port → Nginx → FastCGI to `app:9000` over the Compose network, DNS resolution of service names, PHP-FPM pool/worker, Laravel kernel & middleware. Where would you look first if it returned a 502?

---

## 7. Review checklist

- [ ] All image tags pinned; versions match what is written in README/ADR.
- [ ] Dockerfile: layer order caches dependencies sensibly; no secrets; no unnecessary packages; non-root runtime user.
- [ ] Only needed PHP extensions, each justified.
- [ ] `db`/`redis` not publicly exposed; healthchecks present and actually meaningful (`pg_isready`, `redis-cli ping`).
- [ ] Named volumes for data; bind mount only for source.
- [ ] `.env.example` complete; `.env` and `vendor/` excluded from the build context.
- [ ] Health endpoint: bounded timeouts, no exception details leaked, correct status codes, thin implementation (no premature “HealthCheckManagerFactory”).
- [ ] Tests cover happy path + each dependency failing; tests use a separate DB.
- [ ] Generated files owned by the host user.
- [ ] README commands are copy-pastable and were actually executed.
- [ ] No host-installed PHP/Composer/Node used at any step.

## 8. Definition of done

Day 01 is **done** when every acceptance criterion is checked with execution evidence (paste outputs of `php -v`, `artisan about`, `artisan test`, `docker compose ps`, and the two `curl` calls into `reviews/day-01.md`), you can answer the three interview questions without notes, and a review has issued **PASS** or **PASS WITH ISSUES**. A missing failure-path test (Redis/DB down) is an automatic **FAIL**.
