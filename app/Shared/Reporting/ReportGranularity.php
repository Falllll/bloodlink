<?php

declare(strict_types=1);

namespace App\Shared\Reporting;

use DateTimeImmutable;

/**
 * Lebar bucket laporan (kartu 440).
 *
 * KEAMANAN: date_trunc() tidak menerima parameter terikat untuk unitnya, jadi
 * sqlUnit() DIINTERPOLASI ke SQL. Enum ini satu-satunya yang berdiri di antara
 * klien dan SQL itu. Nilai yang diinterpolasi selalu datang dari literal di
 * match(), tidak pernah dari ->value atau input -- jangan "sederhanakan" jadi
 * "date_trunc('{$granularity->value}', ...)" dan jangan pernah menerima string.
 */
enum ReportGranularity: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';

    /** Unit date_trunc(). Literal, sengaja tidak memakai $this->value. */
    public function sqlUnit(): string
    {
        return match ($this) {
            self::DAY => 'day',
            self::WEEK => 'week',
            self::MONTH => 'month',
        };
    }

    /**
     * Awal bucket tempat $moment jatuh, sama persis dengan date_trunc() PostgreSQL:
     * minggu ISO dimulai Senin. Pemanggil wajib memberi waktu UTC, zona yang
     * sama dengan kolom timestamp.
     */
    public function bucketStart(DateTimeImmutable $moment): DateTimeImmutable
    {
        $midnight = $moment->setTime(0, 0);

        return match ($this) {
            self::DAY => $midnight,
            self::WEEK => $midnight->modify('-'.((int) $midnight->format('N') - 1).' days'),
            self::MONTH => $midnight->setDate((int) $midnight->format('Y'), (int) $midnight->format('n'), 1),
        };
    }

    /** Hanya sah untuk awal bucket: '+1 month' dari tanggal 31 meluap, dari tanggal 1 tidak. */
    public function next(DateTimeImmutable $bucketStart): DateTimeImmutable
    {
        return match ($this) {
            self::DAY => $bucketStart->modify('+1 day'),
            self::WEEK => $bucketStart->modify('+7 days'),
            self::MONTH => $bucketStart->modify('+1 month'),
        };
    }
}
