<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Application\Exceptions\UnitNumberAllocationFailed;
use App\Modules\Inventory\Domain\UnitNumberFormat;
use Closure;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class GenerateUnitNumber
{
    private const int ATTEMPTS = 3;

    /**
     * Alokasi atomik: satu INSERT ... ON CONFLICT ... RETURNING menaikkan
     * pencacah (prefix, tanggal) tanpa celah balapan antar-request.
     */
    public function next(string $facilityCode, DateTimeInterface $at): string
    {
        $prefix = UnitNumberFormat::facilityPrefix($facilityCode);
        $period = UnitNumberFormat::period($at);

        $row = DB::selectOne(<<<'SQL'
            INSERT INTO blood_unit_number_sequences (prefix, period, last_value, created_at, updated_at)
            VALUES (?, ?, 1, now(), now())
            ON CONFLICT ON CONSTRAINT blood_unit_number_sequences_prefix_period_unique
            DO UPDATE SET last_value = blood_unit_number_sequences.last_value + 1, updated_at = now()
            RETURNING last_value
        SQL, [$prefix, $period]);

        $sequence = (int) $row->last_value;

        if ($sequence > UnitNumberFormat::MAX_SEQUENCE) {
            throw UnitNumberAllocationFailed::sequenceExhausted($prefix, $period);
        }

        return UnitNumberFormat::compose($prefix, $period, $sequence, $this->randomPart());
    }

    /**
     * Membangkitkan nomor lalu menyerahkannya ke $persist. Kalau database menolak
     * karena blood_batches_batch_number_unique (mis. nomor lama hasil impor atau
     * backfill), coba lagi dengan nomor baru. Pelanggaran unique lain dilempar
     * apa adanya -- itu bukan urusan penomoran.
     *
     * @param  Closure(string): mixed  $persist
     */
    public function retrying(string $facilityCode, DateTimeInterface $at, Closure $persist): string
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $number = $this->next($facilityCode, $at);

            try {
                // Savepoint: di PostgreSQL pelanggaran unique membatalkan transaksi
                // yang sedang berjalan; tanpa ini percobaan berikutnya ikut gagal.
                DB::transaction(fn () => $persist($number));

                return $number;
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'blood_batches_batch_number_unique')) {
                    throw $e;
                }
            }
        }

        throw UnitNumberAllocationFailed::retriesExhausted(self::ATTEMPTS);
    }

    private function randomPart(): string
    {
        $alphabet = UnitNumberFormat::ALPHABET;
        $last = strlen($alphabet) - 1;

        return $alphabet[random_int(0, $last)].$alphabet[random_int(0, $last)];
    }
}
