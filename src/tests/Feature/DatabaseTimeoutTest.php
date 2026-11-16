<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DB_CONNECT_TIMEOUT only bounds connection setup. These guard the server-side
 * bounds on work done after connecting, against the real Postgres server.
 */
final class DatabaseTimeoutTest extends TestCase
{
    public function test_session_timeouts_are_applied_from_config(): void
    {
        $this->assertSame(config('database.connections.pgsql.server_options.statement_timeout'), DB::scalar('show statement_timeout'));
        $this->assertSame(config('database.connections.pgsql.server_options.lock_timeout'), DB::scalar('show lock_timeout'));
    }

    public function test_slow_query_is_cancelled_by_statement_timeout(): void
    {
        config(['database.connections.pgsql.server_options.statement_timeout' => '200ms']);
        DB::purge('pgsql');

        $started = microtime(true);

        try {
            DB::select('select pg_sleep(5)');
            $this->fail('pg_sleep(5) was not cancelled by statement_timeout');
        } catch (QueryException $e) {
            $this->assertSame('57014', $e->getCode()); // query_canceled
        }

        $this->assertLessThan(2.0, microtime(true) - $started);
    }
}
