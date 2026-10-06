<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use DateTimeImmutable;

/**
 * Kontrak kedaluwarsa dari Kartu 200, dipakai pemisahan komponen (Kartu 220).
 * Acuannya SELALU waktu pengambilan asli: komponen turunan tidak pernah lebih
 * segar dari darah asalnya.
 */
interface ComponentExpiryPolicy
{
    /**
     * Profil penyimpanan yang mencakup suhu itu, dan kedaluwarsa yang diturunkan
     * darinya. Melempar kalau tidak ada profil -- tanpa fallback.
     *
     * @return array{storage_profile_id: int, expires_at: DateTimeImmutable}
     */
    public function resolve(string $component, float $storageTemperatureC, DateTimeImmutable $collectedAt): array;
}
