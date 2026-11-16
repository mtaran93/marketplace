<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Failures are injected by pointing a real connection at a closed port
 * (connection refused) instead of stopping containers or mocking facades,
 * so the production code path - real driver, real exception - is exercised.
 */
final class HealthCheckTest extends TestCase
{
    public function test_reports_ok_when_database_and_redis_are_reachable(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok', 'redis' => 'ok'],
            ]);
    }

    public function test_reports_503_when_redis_is_unreachable(): void
    {
        $this->breakRedis();

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'fail',
                'checks' => ['database' => 'ok', 'redis' => 'fail'],
            ]);
    }

    public function test_reports_503_when_database_is_unreachable(): void
    {
        $this->breakDatabase();

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'fail',
                'checks' => ['database' => 'fail', 'redis' => 'ok'],
            ]);
    }

    public function test_reports_503_when_both_dependencies_are_unreachable(): void
    {
        $this->breakDatabase();
        $this->breakRedis();

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'fail',
                'checks' => ['database' => 'fail', 'redis' => 'fail'],
            ]);
    }

    public function test_failure_response_does_not_leak_exception_details(): void
    {
        config(['app.debug' => true]); // even with debug on
        $this->breakDatabase();

        $body = $this->getJson('/api/health')->assertStatus(503)->getContent();

        $this->assertStringNotContainsStringIgnoringCase('exception', $body);
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $body);
        $this->assertStringNotContainsString('trace', $body);
    }

    private function breakRedis(): void
    {
        config([
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
            'database.redis.default.max_retries' => 0,
        ]);
        Redis::purge('default');
    }

    private function breakDatabase(): void
    {
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');
    }
}
