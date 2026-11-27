<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness and dependency check used by the web/mobile apps, load balancers and uptime monitors.
 * Returns 200 when all dependencies respond, 503 when any is down.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn () => DB::select('select 1')),
            'cache' => $this->check(static function (): void {
                Cache::put('health:ping', 'pong', 5);
                if (Cache::get('health:ping') !== 'pong') {
                    throw new \RuntimeException('cache read-back failed');
                }
            }),
        ];

        $ok = ! in_array('fail', $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'app' => (string) config('app.name'),
            'version' => (string) config('app.version'),
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    /**
     * @param  callable(): mixed  $probe
     * @return 'ok'|'fail'
     */
    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
