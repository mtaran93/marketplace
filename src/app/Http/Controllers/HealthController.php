<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness-style check: can this instance reach its hard dependencies?
 *
 * Deliberately NOT a liveness probe. An orchestrator must not restart the app
 * because Postgres is down - that only adds restarts on top of the outage.
 * Laravel's built-in GET /up stays the cheap liveness endpoint.
 *
 * Latency is bounded by DB_CONNECT_TIMEOUT and REDIS_TIMEOUT/REDIS_READ_TIMEOUT
 * (see config/database.php). Failure details go to the log, never the response.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', static fn () => DB::select('select 1')),
            'redis' => $this->check('redis', static fn () => Redis::connection()->ping()),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return response()->json(
            ['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks],
            $healthy ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * @param  callable(): mixed  $probe
     * @return 'ok'|'fail'
     */
    private function check(string $name, callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            Log::warning('health check failed', [
                'check' => $name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return 'fail';
        }
    }
}
