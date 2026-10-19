<?php

declare(strict_types=1);

namespace App\Modules\Donor\Infrastructure;

use Illuminate\Support\Facades\Redis;

/**
 * Indeks GEO Redis untuk koordinat donor. CACHE, bukan sumber kebenaran:
 * kolom donors.location di PostGIS tetap otoritatif (lihat DonorLocationQuery).
 * Dipakai kartu 400/410 hanya saat matching.redis_geo_enabled bernilai true.
 *
 * Member-nya donors.id (integer internal), bukan public_id: kalau Redis bocor,
 * yang bocor adalah angka tanpa arti di luar sistem. Tidak ada data level kantong.
 *
 * URUTAN ARGUMEN: GEOADD dan GEOSEARCH menerima LONGITUDE dulu, baru latitude --
 * sama dengan ST_MakePoint. Tertukar, Jakarta pindah ke Samudra Hindia tanpa error.
 */
final class DonorGeoIndex
{
    public function enabled(): bool
    {
        return (bool) config('matching.redis_geo_enabled');
    }

    /** Tidak menulis apa pun selama kill-switch mati. */
    public function put(int $donorId, float $latitude, float $longitude): void
    {
        if (! $this->enabled()) {
            return;
        }

        Redis::geoadd($this->key(), $longitude, $latitude, (string) $donorId);
    }

    public function forget(int $donorId): void
    {
        if (! $this->enabled()) {
            return;
        }

        // GEO disimpan sebagai sorted set: penghapusannya memang ZREM.
        Redis::zrem($this->key(), (string) $donorId);
    }

    /** @return list<int> id donor di dalam radius, terurut dari terdekat */
    public function within(float $latitude, float $longitude, int $radiusMeters): array
    {
        // Lewat command(): stub phpredis salah mengetik $position/$options, padahal
        // phpredis 6.x menerima [lon, lat] dan daftar flag ini. Tetap LONGITUDE dulu.
        /** @var list<string> $members */
        $members = Redis::connection()->command('geosearch', [$this->key(), [$longitude, $latitude], $radiusMeters, 'm', ['ASC']]);

        return array_map(intval(...), $members);
    }

    /** @return list<int> semua id donor di indeks -- dipakai rekonsiliasi untuk membuang yang basi */
    public function members(): array
    {
        /** @var list<string> $members */
        $members = Redis::zrange($this->key(), 0, -1);

        return array_map(intval(...), $members);
    }

    public function count(): int
    {
        return (int) Redis::zcard($this->key());
    }

    private function key(): string
    {
        return (string) config('matching.redis_geo_key');
    }
}
