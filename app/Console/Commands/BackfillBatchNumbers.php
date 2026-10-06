<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Modules\Inventory\Application\GenerateUnitNumber;
use App\Modules\Inventory\Domain\UnitNumberFormat;
use Illuminate\Console\Command;

class BackfillBatchNumbers extends Command
{
    protected $signature = 'inventory:backfill-batch-numbers {--force : benar-benar tulis nomor baru}';

    protected $description = 'Ganti batch_number yang belum berformat nomor unit (penampung lama) dengan nomor unit sungguhan';

    public function handle(GenerateUnitNumber $unitNumbers): int
    {
        // Disaring dengan format BARU, jadi tidak perlu tahu bentuk penampung lama:
        // apa pun yang tidak lolos isValid() (termasuk cek digit) dinomori ulang.
        $stale = BloodBatch::query()
            ->orderBy('id')
            ->lazyById(200)
            ->reject(fn (BloodBatch $batch): bool => UnitNumberFormat::isValid($batch->batch_number));

        if (! $this->option('force')) {
            $this->info('Dry run: '.$stale->count().' unit belum berformat nomor unit. Jalankan dengan --force untuk menulis.');

            return self::SUCCESS;
        }

        // Termasuk fasilitas yang sudah dihapus lunak: unitnya tetap butuh nomor.
        $facilityCodes = Facility::withTrashed()->pluck('code', 'id');
        $count = 0;

        foreach ($stale as $batch) {
            // Per model, bukan update massal, supaya perubahannya tercatat di audit log.
            $unitNumbers->retrying(
                (string) $facilityCodes[$batch->facility_id],
                $batch->collected_at,
                fn (string $number): bool => $batch->forceFill(['batch_number' => $number])->save(),
            );
            $count++;
        }

        $this->info("Menomori ulang {$count} unit.");

        return self::SUCCESS;
    }
}
