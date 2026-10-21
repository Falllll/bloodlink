<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Modules\Notification\Domain\DeliveryStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Laporan dead-letter notifikasi (kartu 470). HANYA membaca, karena itu tanpa
 * --force. Menjawab "siapa yang tidak pernah dihubungi" per failure_code dan kanal;
 * pemantauan dan alerting atasnya adalah kartu 697.
 */
class ReportFailedDeliveries extends Command
{
    protected $signature = 'notification:failed-report {--since=24h : Rentang ke belakang: angka + m/h/d, mis. 30m, 24h, 7d}';

    protected $description = 'Laporkan delivery notifikasi berstatus failed, per failure_code dan kanal';

    public function handle(): int
    {
        $since = $this->parseSince((string) $this->option('since'));

        if ($since === null) {
            $this->error('--since must be a number followed by m, h, or d (e.g. 30m, 24h, 7d).');

            return self::INVALID;
        }

        $rows = NotificationDelivery::query()
            ->where('status', DeliveryStatus::FAILED->value)
            ->where('settled_at', '>=', $since)
            ->selectRaw('failure_code, channel, count(*) as total')
            ->groupBy('failure_code', 'channel')
            ->orderByDesc('total')
            ->orderBy('failure_code')
            ->toBase()
            ->get();

        $total = (int) $rows->sum('total');
        $this->info("{$total} failed notification deliveries since {$since->toIso8601String()}.");

        if ($total > 0) {
            $this->table(
                ['failure_code', 'channel', 'total'],
                $rows->map(fn (object $row): array => [(string) $row->failure_code, (string) $row->channel, (int) $row->total])->all(),
            );
        }

        return self::SUCCESS;
    }

    private function parseSince(string $value): ?Carbon
    {
        if (preg_match('/^([1-9]\d{0,4})([mhd])$/', $value, $m) !== 1) {
            return null;
        }

        return match ($m[2]) {
            'm' => now()->subMinutes((int) $m[1]),
            'h' => now()->subHours((int) $m[1]),
            default => now()->subDays((int) $m[1]),
        };
    }
}
