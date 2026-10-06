<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Modules\Inventory\Domain\BatchStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegisterBloodBatch
{
    public function __construct(
        private AssignStorageProfile $assignStorageProfile,
        private GenerateUnitNumber $unitNumbers,
    ) {}

    /**
     * Unit baru selalu lahir quarantined: belum diuji lab (Kartu 230) dan belum
     * melewati gerbang rilis (Kartu 240), apa pun yang dikirim klien.
     *
     * @param  array{component: string, blood_group: ?string, rh_factor: ?string, volume_ml: int, hemoglobin_g_dl: ?float, collected_at: DateTimeImmutable, storage_temperature_c: float, donor_id: ?int}  $data
     */
    public function handle(int $facilityId, array $data): BloodBatch
    {
        return DB::transaction(function () use ($facilityId, $data): BloodBatch {
            $batch = new BloodBatch([
                'component' => $data['component'],
                'blood_group' => $data['blood_group'],
                'rh_factor' => $data['rh_factor'],
                'volume_ml' => $data['volume_ml'],
                'hemoglobin_g_dl' => $data['hemoglobin_g_dl'],
                'donor_id' => $data['donor_id'],
                'collected_at' => $data['collected_at'],
            ]);

            $batch->assignFacility($facilityId);

            $batch->forceFill([
                'public_id' => (string) Str::uuid(),
                'status' => BatchStatus::QUARANTINED,
            ]);

            $this->assignStorageProfile->handle($batch, $data['storage_temperature_c']);

            $this->unitNumbers->retrying(
                (string) Facility::query()->whereKey($facilityId)->value('code'),
                $data['collected_at'],
                fn (string $number): bool => $batch->forceFill(['batch_number' => $number])->save(),
            );

            return $batch;
        });
    }
}
