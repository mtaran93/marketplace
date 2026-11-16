# ADR 0001 — Test framework: PHPUnit

- **Status:** Accepted (2026-11-16)
- **Context:** Laravel 13 ships with PHPUnit 12 and supports Pest 4 equally well. The suite will grow
  into concurrency, transaction, and integration tests (Days 11–30) that need explicit setup and
  teardown, data providers, and sometimes multiple processes or connections. The project is also
  interview preparation, where PHPUnit is the lingua franca of PHP codebases.
- **Decision:** Use **PHPUnit** (`phpunit/phpunit ^12.5`), run via `php artisan test` in the `app`
  container. Feature tests hit **real PostgreSQL** (`marketplace_test`) and **real Redis** (logical
  DBs 14/15), never SQLite, because Postgres-only behaviour (`SELECT … FOR UPDATE`, isolation levels,
  partial indexes, JSONB) is exactly what later days test.
- **Consequences:**
  - (+) No extra dependency or DSL; plain classes and methods are easy to read in code review and familiar to every PHP team.
  - (+) Same engine as dev and production, so dialect bugs surface in tests.
  - (−) Slower than in-memory SQLite. Mitigated with `RefreshDatabase` (wraps each test in a
    transaction) and `php artisan test --parallel` once the suite is large enough.
  - (−) Tests need the `db`/`redis` containers running. CI must start them as service containers.
  - (−) More verbose than Pest. Accepted.
