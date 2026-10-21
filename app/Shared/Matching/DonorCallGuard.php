<?php

declare(strict_types=1);

namespace App\Shared\Matching;

use DateTimeImmutable;

/**
 * Port penjaga "satu panggilan per donor per permintaan" (kartu 480). Modul Request
 * (kampanye, kartu 400) memanggilnya; implementasinya ClaimDonorCalls di modul
 * Notification, diikat di AppServiceProvider -- preseden DonorDirectory, jadi
 * ruleset deptrac tidak bertambah.
 *
 * BUKAN idempotensi HTTP (EnsureIdempotency + idempotency_keys): itu retry klien
 * atas satu request; ini dua jalur server yang sah sama-sama memilih donor yang sama.
 *
 * Semua masukan dan keluaran primitif (id internal); tidak ada model yang menyeberang.
 */
interface DonorCallGuard
{
    /**
     * Klaim sebanyak mungkin dari $donorIds untuk satu permintaan.
     *
     * @param  list<int>  $donorIds
     * @return list<int> donor yang BARU diklaim panggilan ini; yang sudah pernah
     *                   dipanggil tidak muncul. Urutannya tidak dijamin.
     */
    public function claim(int $bloodRequestId, array $donorIds, int $radiusMeters, DateTimeImmutable $now): array;

    /** @return list<int> donor yang sudah pernah dipanggil untuk permintaan ini */
    public function alreadyClaimed(int $bloodRequestId): array;
}
