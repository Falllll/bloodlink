<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain\Events;

/**
 * Reaksi pasca-transfusi dilaporkan (kartu 370). Belum ada listener: notifikasi ke
 * petugas milik kartu 450/460. SENGAJA hanya public_id dan waktu kejadian -- tanpa
 * patient_reference dan tanpa objek model, supaya payload antrean kelak tidak
 * membawa data pasien.
 */
final class TransfusionReactionReported
{
    public function __construct(
        public readonly string $reactionId,
        public readonly string $transfusionId,
        public readonly string $observedAt,
    ) {}
}
