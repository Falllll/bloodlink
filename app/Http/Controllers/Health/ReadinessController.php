<?php

namespace App\Http\Controllers\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ReadinessController
{
    private const WORKER_STATE_AFTER = 120;
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('SELECT 1')),
            'redis' => $this->probe(fn () => Redis::ping()),
            'workers' => $this->workerAlive(),
        ];

        $ready = ! in_array(false, $check, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'degraded',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    /**
     * Tiap probe dibungkus sendiri supaya satu kegagalan
     * tidak menjatuhkan seluruh response.
     */
    private function probe(callable $callback): bool
    {
        try {
            $callback();
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function workerAlive(): bool
    {
        try {
            $beat = Cache::get('worker:heartbeat');
        } catch (\Throwable $e) {
            report($e);
            return false;
        }

        return $beat !== null && (time() - (int) $beat) < self::WORKER_STATE_AFTER;
    }
}