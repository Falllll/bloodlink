<?php

declare(strict_types=1);

namespace App\Shared\Reporting;

use DateTimeImmutable;

/**
 * Jendela laporan yang sudah DIRATAKAN ke batas bucket: [start, end).
 *
 * Bucket pertama dan terakhir selalu penuh. Tanpa perataan, from=2026-09-15
 * dengan granularity=month menghasilkan bucket "September" yang hanya berisi
 * separuh bulan -- di grafik tren ia terlihat seperti penurunan yang tidak pernah
 * terjadi.
 */
final readonly class ReportWindow
{
    /** @param list<DateTimeImmutable> $buckets */
    private function __construct(
        public ReportGranularity $granularity,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public array $buckets,
    ) {}

    /**
     * @param  DateTimeImmutable  $from  Hari pertama yang diminta (inklusif)
     * @param  DateTimeImmutable  $to  Hari terakhir yang diminta (inklusif)
     * @param  int  $maxBuckets  Batas atas; null kalau terlampaui
     */
    public static function between(DateTimeImmutable $from, DateTimeImmutable $to, ReportGranularity $granularity, int $maxBuckets): ?self
    {
        $start = $granularity->bucketStart($from);
        $lastBucket = $granularity->bucketStart($to);

        $buckets = [];
        for ($cursor = $start; $cursor <= $lastBucket; $cursor = $granularity->next($cursor)) {
            // Berhenti SEBELUM membangun seluruh deret: from=2000-01-01 dengan
            // granularity=day tidak boleh sempat mengalokasikan 9.000 objek dulu.
            if (count($buckets) === $maxBuckets) {
                return null;
            }

            $buckets[] = $cursor;
        }

        return new self($granularity, $start, $granularity->next($lastBucket), $buckets);
    }

    /**
     * Isi bucket kosong dengan nol, berurutan dari awal jendela.
     *
     * @param  iterable<object>  $rows  Baris hasil GROUP BY dengan kolom period_start (date)
     * @param  list<string>  $columns  Kolom hitungan
     * @return list<array<string, mixed>>
     */
    public function fill(iterable $rows, array $columns): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $counts[(string) $values['period_start']] = array_map(
                intval(...),
                array_intersect_key($values, array_flip($columns)),
            );
        }

        $zero = array_fill_keys($columns, 0);

        return array_map(function (DateTimeImmutable $bucket) use ($counts, $zero): array {
            $key = $bucket->format('Y-m-d');

            return ['period_start' => $key, ...array_merge($zero, $counts[$key] ?? [])];
        }, $this->buckets);
    }

    /** @return array<string, string> */
    public function toMeta(): array
    {
        return [
            'granularity' => $this->granularity->value,
            'from' => $this->start->format('Y-m-d'),
            // Eksklusif: awal bucket SETELAH bucket terakhir.
            'to_exclusive' => $this->end->format('Y-m-d'),
            'timezone' => 'UTC',
        ];
    }
}
