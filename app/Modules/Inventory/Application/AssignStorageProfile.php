<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Models\ComponentStorageProfile;
use App\Models\ComponentType;
use App\Modules\Inventory\Application\Exceptions\BatchExpiryUndeterminable;
use App\Modules\Inventory\Domain\ShelfLifeWindow;

/**
 * expires_at diturunkan dari profil penyimpanan yang benar-benar ditempati unit,
 * bukan dari konstanta per komponen: FFP di -22 °C hidup 3 bulan, di -35 °C 12 bulan.
 */
final class AssignStorageProfile
{
    private const string JURISDICTION = 'WHO';

    /** Mengisi storage_profile_id dan expires_at; tidak menyimpan. */
    public function handle(BloodBatch $batch, float $temperatureC): BloodBatch
    {
        $profile = $this->resolveProfile($batch->component, $temperatureC);

        return $batch->forceFill([
            'storage_profile_id' => $profile->id,
            'expires_at' => (new ShelfLifeWindow(
                $batch->collected_at->toDateTimeImmutable(),
                $profile->shelf_life_value,
                $profile->shelf_life_unit,
            ))->expiresAt(),
        ]);
    }

    /**
     * Tanpa fallback: suhu yang tidak dicakup profil mana pun selalu melempar.
     * Memilih profil "terdekat" atau default akan mengarang masa simpan.
     */
    private function resolveProfile(string $component, float $temperatureC): ComponentStorageProfile
    {
        $type = ComponentType::query()
            ->where('jurisdiction', self::JURISDICTION)
            ->where('code', $component)
            ->where('is_active', true)
            ->first();

        // Perbandingan di SQL, bukan di PHP: cast decimal:1 mengembalikan string.
        $profile = $type?->storageProfiles()
            ->where('storage_temp_min_celsius', '<=', $temperatureC)
            ->where('storage_temp_max_celsius', '>=', $temperatureC)
            ->first();

        if ($profile === null) {
            throw BatchExpiryUndeterminable::noProfileFor($component, $temperatureC);
        }

        return $profile;
    }
}
