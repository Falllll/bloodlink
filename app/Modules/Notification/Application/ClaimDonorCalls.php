<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Shared\Matching\DonorCallGuard;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Penjaga "satu panggilan per donor per permintaan" (kartu 480). Penjaganya adalah
 * UNIQUE INDEX donor_call_claims_one_per_donor_per_request, bukan pemeriksaan di PHP:
 * dua jalur kampanye (kartu 400) bisa berjalan paralel dan sama-sama lolos SELECT
 * sebelum salah satunya INSERT. Test satu proses tidak pernah berlomba, jadi `if`
 * di PHP akan hijau di test dan bocor di produksi -- lihat DonorCallClaimRaceTest.
 *
 * BUKAN idempotensi HTTP -- itu EnsureIdempotency + tabel idempotency_keys,
 * lapisan dan masalah yang berbeda. Jangan disatukan.
 *
 * Tidak menentukan kelayakan: donor yang sudah dipanggil tetap layak secara medis.
 */
final class ClaimDonorCalls implements DonorCallGuard
{
    public function claim(int $bloodRequestId, array $donorIds, int $radiusMeters, DateTimeImmutable $now): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $donorIds)));

        if ($ids === []) {
            return [];
        }

        $at = $now->format('Y-m-d H:i:s');

        // SATU query untuk seluruh batch, sekaligus penjaga dan jawaban:
        //  - RETURNING pada ON CONFLICT DO NOTHING hanya mengembalikan baris yang
        //    BENAR-BENAR disisipkan -- yang kalah lomba tidak muncul.
        //  - unnest(?::bigint[]) mengirim ratusan id sebagai SATU parameter berformat
        //    {1,2,3}; isinya sudah intval, dan tetap terikat sebagai binding, bukan
        //    dirangkai ke SQL.
        //  - ON CONFLICT menyebut KOLOM, bukan nama index: nama index lebih sering
        //    berubah daripada daftar kolomnya.
        //  - Cast eksplisit: parameter di daftar SELECT tidak mewarisi tipe kolom tujuan.
        //  - donor_id yang tidak ada gagal di foreign key, tidak menyisip baris menggantung.
        $rows = DB::select(
            'INSERT INTO donor_call_claims
                 (blood_request_id, donor_id, radius_meters, claimed_at, created_at, updated_at)
             SELECT ?::bigint, d, ?::integer, ?::timestamp, ?::timestamp, ?::timestamp
             FROM unnest(?::bigint[]) AS d
             ON CONFLICT (blood_request_id, donor_id) DO NOTHING
             RETURNING donor_id',
            [$bloodRequestId, $radiusMeters, $at, $at, $at, '{'.implode(',', $ids).'}'],
        );

        return array_map(fn (object $row): int => (int) $row->donor_id, $rows);
    }

    public function alreadyClaimed(int $bloodRequestId): array
    {
        return DB::table('donor_call_claims')
            ->where('blood_request_id', $bloodRequestId)
            ->pluck('donor_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
