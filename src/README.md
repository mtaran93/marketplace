# Marketplace backend

Multi-vendor marketplace API — Laravel 13 on PHP 8.4, PostgreSQL 18, Redis 8, Nginx.
**Everything runs in Docker.** The host needs only Docker Engine + Compose v2 and Git.
All commands below run from the **repository root** (the directory above `src/`). Its `compose.yaml` includes `src/compose.yaml`, so paths and `src/.env` resolve as before.

## Quick start

```bash
cp src/.env.example src/.env         # then set UID/GID to `id -u`/`id -g` if not 1000
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
curl -i http://localhost:8083/api/health
```

## Everyday commands

```bash
docker compose ps
docker compose exec app php -v
docker compose exec app php artisan about
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test
docker compose exec db sh -lc 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
docker compose exec redis redis-cli ping      # REDISCLI_AUTH is set in the container
docker compose logs -f app web
docker compose down                           # keeps data (named volumes)
docker compose down -v                        # DESTROYS Postgres + Redis data
```

## Stack and pinned versions

| Service | Image | Notes |
| --- | --- | --- |
| `app` | `php:8.4.27-fpm-alpine3.24` + Composer 2.11.2 (`.docker/php/Dockerfile`, target `dev`) | PHP-FPM + CLI, runs as non-root `app` user with host UID/GID |
| `web` | `nginx:1.30.6-alpine` | Published on loopback: `127.0.0.1:${WEB_PORT:-8083}` → 80. Only `/index.php` is passed to FastCGI (`app:9000`); any other `*.php` returns 404 |
| `db` | `postgres:18.7-alpine3.24` | Named volume `pgdata`; `pg_isready` healthcheck; published on loopback only: `127.0.0.1:${DB_FORWARD_PORT:-5435}` → 5432 (local GUI clients) |
| `redis` | `redis:8.10.3-alpine` | Named volume `redisdata`, AOF on, password required; `redis-cli ping` healthcheck; not published |

Laravel **13.42** was chosen because it is the current supported major and requires PHP `^8.3`, so 8.4 is supported.
`composer.lock` was resolved inside the PHP 8.4 app image.

### PHP extensions (beyond the base image)

| Extension | Why |
| --- | --- |
| `pdo_pgsql` | PostgreSQL driver. |
| `redis` (phpredis 6.3.0, PECL) | Native C client for cache/session/queue: faster than Predis and supports timeouts and retries. Predis was rejected because it adds a userland dependency with nothing to show for it here. |
| `intl` | Locale-aware number/currency formatting and validation (prices, merchant names). |
| `bcmath` | Arbitrary-precision decimal math for money; floats are never used for prices. |
| `opcache` | Bytecode cache. It is not enabled by default in the 8.4 image. Dev uses `validate_timestamps=1`. |
| `pcntl` | Signal handling so `queue:work` finishes the current job on SIGTERM (Days 21–30). |

Not added: `zip` (Composer uses `unzip`), `gd` (no images yet), `pdo_mysql`.

## Health endpoints

- `GET /up` is Laravel's **liveness** check (the process serves HTTP). It never touches dependencies.
- `GET /api/health` is the **readiness** check. It returns `200` only if both PostgreSQL and Redis answer, and `503` otherwise:

```json
{ "status": "ok", "checks": { "database": "ok", "redis": "ok" } }
```

What bounds response time, and what does not:

- Postgres connect: `DB_CONNECT_TIMEOUT=2` (seconds), passed as `PDO::ATTR_TIMEOUT` to libpq's `connect_timeout`.
- Postgres queries: `DB_STATEMENT_TIMEOUT=5s` and `DB_LOCK_TIMEOUT=3s`, set per session through libpq `options` (`server_options` in `config/database.php`). The **server** cancels the statement (SQLSTATE `57014` / `55P03`). This applies to every query in the app, not only the health check. For a long migration, override it per run: `docker compose exec -e DB_STATEMENT_TIMEOUT=0 app php artisan migrate`.
- Redis: `REDIS_TIMEOUT=1s` and `REDIS_READ_TIMEOUT=1s`.
- **Known gap:** a network partition *after* a query was sent is not bounded by any of the above, because the client waits on the socket. Nginx gives up after `fastcgi_read_timeout 30s` (504), but the FPM worker stays blocked. A worker-side bound (FPM `request_terminate_timeout`) is still to do.

Measured results:

- A stopped container fails in under 0.2s, because Docker DNS no longer resolves the host.
- A paused (blackholed) Redis fails in about 1.0s.
- A paused (blackholed) Postgres fails in about 2.0s.

Failure details go to the log (`health check failed`, with the exception class and message), never to the response.

## Design decisions (Day 01)

- **Readiness (F1):** `app` waits for `db`/`redis` to report `service_healthy`. A bare `depends_on` only orders container *start*, not "accepting connections". Healthchecks only help at startup, though. At runtime the app reconnects on the next request, using Laravel's lost-connection retry and Redis `max_retries`.
- **File ownership (E3):** the image builds with `UID`/`GID` args (from `.env`). It creates a matching `app` user and sets `USER app`. Composer and Artisan therefore write host-owned files through the bind mount. The FPM `user`/`group` pool directives are removed because the master already runs non-root.
- **Least exposure (S2):** everything published is bound to `127.0.0.1`: `web` on `WEB_PORT` (8083), and `db` on `DB_FORWARD_PORT` (5435) for DataGrip. `redis` is not published.
- **Secrets (S1/S3):** `compose.yaml` uses `${VAR:?}` and fails fast if `.env` is missing. `.env.example` holds only obvious local-only values. `.env` is excluded by `.gitignore` and `.dockerignore`. `APP_DEBUG=true` is a local-only setting; production will set `APP_ENV=production` and `APP_DEBUG=false` in the multi-stage build (later day).
- **Two stacks on one host (C1):** set a different `COMPOSE_PROJECT_NAME` and `WEB_PORT` per checkout. Container names, networks, and volumes are then all prefixed per project.
- **Tests:** PHPUnit (see `../docs/architecture/0001-test-framework.md`). Tests run against **real PostgreSQL** in a separate `marketplace_test` database, created by `.docker/postgres/init/01-create-test-db.sql` on first volume init. Redis uses logical DBs 14/15. If your `pgdata` volume predates that script, create the database once:
  `docker compose exec db sh -lc 'createdb -U "$POSTGRES_USER" marketplace_test'`

## How it was scaffolded (E1/E2)

`src/` already contained `.docker/` and `compose.yaml`, and `composer create-project` refuses non-empty targets. So Laravel was created in a temp directory **inside the PHP 8.4 app image**, which keeps the lock file resolved for the right platform. Files were then moved in without overwriting anything:

```bash
docker compose build app
docker compose run --rm --no-deps app sh -c '
  set -e
  composer create-project --prefer-dist --no-interaction --no-scripts --quiet laravel/laravel /tmp/scaffold
  cd /tmp/scaffold
  for f in $(ls -A); do [ -e "/var/www/html/$f" ] && echo "SKIP $f" || mv "$f" /var/www/html/; done'
```

The skeleton's `AGENTS.md`/`CLAUDE.md` (Laravel Boost) were deleted, because they instruct agents to install PHP on the host.

## Production build

`compose.prod.yaml` runs the same app as sealed images. One multi-stage `.docker/php/Dockerfile` builds both environments:

| Stage | What it is |
| --- | --- |
| `base` | PHP and the extensions, shared by dev and prod so they can't drift apart |
| `dev` | Composer, host UID/GID; used by `compose.yaml` |
| `vendor` | Build-only: installs production dependencies and builds the optimized autoloader; never shipped |
| `prod` | Runtime image: code only, runs as `www-data` |

Nginx gets its own `.docker/nginx/Dockerfile`, which bakes `public/` into a non-root nginx image.

Always pass **both** `-f src/compose.prod.yaml` and `--env-file src/.env.production`. That keeps the root `compose.yaml` and `compose.override.yaml` from being merged in.

```bash
cp src/.env.production.example src/.env.production   # replace every CHANGE_ME
P="docker compose -f src/compose.prod.yaml --env-file src/.env.production"

# One-time: generate APP_KEY with the dev container and paste it into .env.production
docker compose exec app php artisan key:generate --show

$P up -d --build
$P run --rm app php artisan migrate --force      # explicit step, never on container start
$P exec web wget -qSO- http://127.0.0.1:8080/api/health   # no host port in prod
$P logs -f app web                               # Laravel logs go to stderr
$P down                                          # keep volumes; `down -v` destroys prod data
```

### Dev vs prod

| | Dev (`compose.yaml`) | Prod (`compose.prod.yaml`) |
| --- | --- | --- |
| Source | bind mount `./` | copied into the image |
| PHP user | `app` with host UID/GID (owns the files) | `www-data`; code is root-owned and read-only, only `storage/` and `bootstrap/cache/` are writable |
| Dependencies | dev dependencies plus Composer | `--no-dev`, classmap-authoritative autoloader, **no Composer** |
| `APP_DEBUG` / `APP_ENV` | `true` / `local` | `false` / `production` (S1) |
| OPcache | `validate_timestamps=1` | `validate_timestamps=0`, `display_errors=Off` |
| Config/routes/views | read on every request | cached by the entrypoint at container start, from the **runtime** env (never baked into the image) |
| Logs | `storage/logs/laravel.log` | `stderr` → `docker compose logs` |
| Nginx | stock image, config and `public/` bind-mounted, port 80 | `nginx-unprivileged`, baked in, port 8080, security headers |
| Published port | `127.0.0.1:8083` | **none**; a TLS reverse proxy on the same Docker network will reach `web:8080` |
| DB access from outside | `127.0.0.1:${DB_FORWARD_PORT:-5435}`, local GUI clients only | `db` on the server's `127.0.0.1:${DB_FORWARD_PORT:-5433}` only, reached via an SSH tunnel |
| Test DB init | `marketplace_test` created | none |
| Secrets | local-only defaults in `.env` | `.env.production` (git- and docker-ignored), required via `${VAR:?}` |

Connecting from DataGrip: create a Postgres connection with host `127.0.0.1` and port `DB_FORWARD_PORT` (5433). On the **SSH/SSL** tab, enable an SSH tunnel to the server. From a terminal, the equivalent is `ssh -N -L 5433:127.0.0.1:5433 user@server`, then connect to `localhost:5433`. Use a separate read-only role for this, not the app's `DB_USERNAME`.

Why migrations aren't in the entrypoint: with more than one replica, every container would race to migrate on start, and a failed migration would crash-loop the web tier. Run them once as a deploy step.

## Troubleshooting

**Composer: `curl error 28 ... Resolving timed out`.** Some home routers time out on DNS queries from Alpine's libcurl (c-ares), even though `getent hosts` works. Put a local, git-ignored `compose.override.yaml` in the **repository root**, next to the root `compose.yaml` (Compose only auto-merges an override there):

```yaml
services:
  app:
    dns: [1.1.1.1, 9.9.9.9]
```

**502 from nginx:** check `docker compose ps app` and `docker compose logs app`. A 502 means nginx could not reach `app:9000`.
