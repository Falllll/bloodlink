<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Donor;
use Illuminate\Console\Command;

class PurgeExpiredHealthData extends Command
{
    protected $signature = 'privacy:purge-expired {--force : benar-benar tulis perubahan}';

    protected $description = 'Kosongkan data kesehatan donor yang melewati batas retensi';

    public function handle(): int
    {
        $years = config('privacy.retention.health_years');

        if (! is_int($years) || $years < 1) {
            $this->error('PRIVACY_RETENTION_HEALTH_YEARS belum diisi (atau bukan bilangan bulat positif). Masa retensi harus diverifikasi ke sumber resmi dulu; tidak ada nilai default.');

            return self::FAILURE;
        }

        $query = Donor::withTrashed()
            ->where('last_donation_date', '<', now()->subYears($years)->toDateString())
            ->where(function ($q): void {
                $q->whereNotNull('weight_kg')
                    ->orWhereNotNull('blood_group')
                    ->orWhereNotNull('rh_factor');
            })
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('donor_consents')
                    ->whereColumn('donor_consents.donor_id', 'donors.id')
                    ->whereNull('donor_consents.revoked_at')
                    ->whereRaw('donor_consents.purposes @> ?::jsonb', [json_encode(['data_retention'])]);
            });

        $count = (clone $query)->count();

        if (! $this->option('force')) {
            $this->info("Dry run: {$count} donor melewati retensi {$years} tahun. Jalankan dengan --force untuk mengosongkan.");

            return self::SUCCESS;
        }

        // Per model, bukan update massal, supaya perubahan tercatat di audit log.
        $query->chunkById(200, function ($donors): void {
            foreach ($donors as $donor) {
                $donor->forceFill([
                    'weight_kg' => null,
                    'blood_group' => null,
                    'rh_factor' => null,
                ])->save();
            }
        });

        $this->info("Mengosongkan data kesehatan {$count} donor.");

        return self::SUCCESS;
    }
}
