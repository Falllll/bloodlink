<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Penjaga query lambat (kartu 445): satu-satunya pengukuran performa yang berdiri
 * sendiri di repo ini.
 *
 * Yang diukur TOTAL waktu query dalam satu siklus hidup -- satu request HTTP, atau
 * satu job (queue worker me-reset hitungannya per job) -- bukan satu query: N+1
 * yang terdiri dari seratus query cepat juga tertangkap. Terpicu paling banyak
 * sekali per siklus.
 *
 * Hanya MENCATAT, tidak pernah melempar: satu query lambat di jam sibuk tidak boleh
 * menggagalkan permintaan darurat. Binding sengaja tidak ikut dicatat; isinya bisa
 * data donor.
 */
final class SlowQueryLog
{
    public static function register(int $thresholdMs): void
    {
        DB::whenQueryingForLongerThan($thresholdMs, function (Connection $connection, QueryExecuted $event) use ($thresholdMs): void {
            Log::warning('Database time exceeded the slow query threshold.', [
                'connection' => $connection->getName(),
                'total_ms' => round($connection->totalQueryDuration(), 2),
                'threshold_ms' => $thresholdMs,
                'last_query_ms' => $event->time,
                'last_sql' => $event->sql,
            ]);
        });
    }
}
