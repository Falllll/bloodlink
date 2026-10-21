<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Database\SlowQueryLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Penjaga query lambat (kartu 445). Ambang di environment test sengaja tinggi
 * (phpunit.xml), jadi di sini didaftarkan ulang dengan ambang yang terlampaui.
 */
final class SlowQueryLogTest extends TestCase
{
    public function test_crossing_the_threshold_logs_one_warning_without_bindings(): void
    {
        Log::spy();
        SlowQueryLog::register(0);

        $rows = DB::select('SELECT ? AS secret_value', ['3201010101010001']);
        DB::select('SELECT 1');

        // Hanya mencatat, tidak melempar: query-nya tetap mengembalikan hasil.
        $this->assertSame('3201010101010001', $rows[0]->secret_value);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $context['threshold_ms'] === 0
                && $context['last_sql'] === 'SELECT ? AS secret_value'
                && ! str_contains((string) json_encode($context), '3201010101010001');
        });
    }

    public function test_staying_under_the_threshold_logs_nothing(): void
    {
        Log::spy();
        SlowQueryLog::register(60_000);

        DB::select('SELECT 1');

        Log::shouldNotHaveReceived('warning');
    }
}
