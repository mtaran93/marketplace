<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the test stack itself: real PostgreSQL + Redis, never the dev database.
 * Days 11-20 rely on Postgres-only behaviour (FOR UPDATE, isolation levels,
 * partial indexes, JSONB) that SQLite would silently fake or reject.
 */
final class TestEnvironmentConfigTest extends TestCase
{
    public function test_default_database_connection_is_postgres(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_tests_run_against_the_dedicated_test_database(): void
    {
        $this->assertSame('marketplace_test', DB::scalar('select current_database()'));
    }

    public function test_cache_store_is_redis(): void
    {
        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('cache.stores.redis.driver'));
    }
}
